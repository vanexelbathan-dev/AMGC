<?php
// Start session and include database connection
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Driver User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'delivery';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

function fetch_online_transfer_sub_accounts_for_delivery($conn, $branch_id, $view_all_branches = false) {
    $rows = [];
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_payment_methods` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_id` int(11) NOT NULL,
        `payment_method` enum('check','online_transfer','cash') NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_bank_method` (`bank_id`,`payment_method`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $parent_col = $conn->query("SHOW COLUMNS FROM banks LIKE 'parent_bank_id'");
    if (!$parent_col || $parent_col->num_rows === 0) return $rows;

    $sql = "SELECT b.bank_id, b.bank_name, COALESCE(b.account_number, '') AS account_number, COALESCE(b.bank_branch, '') AS bank_branch, COALESCE(pb.bank_name, '') AS parent_bank_name
            FROM banks b
            LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
            INNER JOIN bank_payment_methods bpm ON bpm.bank_id = b.bank_id AND bpm.payment_method = 'online_transfer'
            WHERE b.status = 'active' AND b.parent_bank_id IS NOT NULL";
    if (!$view_all_branches && (int)$branch_id > 0) $sql .= " AND (b.branch_id = ? OR b.branch_id = 0)";
    $sql .= " ORDER BY pb.bank_name ASC, b.bank_name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    if (!$view_all_branches && (int)$branch_id > 0) $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
$online_transfer_accounts = fetch_online_transfer_sub_accounts_for_delivery($conn, $branch_id, $view_all_branches);


// ========== DIVERT DELIVERY HELPER TABLE / FUNCTIONS ==========
function fdColumnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function fdEnsureDivertLogTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `delivery_divert_logs` (
        `divert_id` int(11) NOT NULL AUTO_INCREMENT,
        `delivery_id` int(11) NOT NULL,
        `so_id` int(11) DEFAULT NULL,
        `from_driver_id` int(11) NOT NULL,
        `to_driver_id` int(11) NOT NULL,
        `status` enum('pending','received','cancelled') NOT NULL DEFAULT 'pending',
        `remarks` text DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `received_by` int(11) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `received_at` datetime DEFAULT NULL,
        PRIMARY KEY (`divert_id`),
        KEY `idx_divert_delivery` (`delivery_id`),
        KEY `idx_divert_to_status` (`to_driver_id`,`status`),
        KEY `idx_divert_from_status` (`from_driver_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

fdEnsureDivertLogTable($conn);

// ========== DELIVERY COLLECTION PENDING REMIT HELPERS ==========
function fdTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function fdEnsureDeliveryCollectionTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_records` (
        `record_id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL DEFAULT 0,
        `customer_id` INT NOT NULL DEFAULT 0,
        `branch_id` INT NOT NULL DEFAULT 0,
        `collector_user_id` INT NOT NULL DEFAULT 0,
        `payment_method` VARCHAR(30) NOT NULL DEFAULT 'cash',
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `collection_date` DATETIME NOT NULL,
        `reference_number` VARCHAR(100) DEFAULT NULL,
        `check_date` DATE DEFAULT NULL,
        `bank_name` VARCHAR(150) DEFAULT NULL,
        `bank_branch` VARCHAR(150) DEFAULT NULL,
        `check_number` VARCHAR(100) DEFAULT NULL,
        `cash_tendered` DECIMAL(12,2) DEFAULT NULL,
        `cash_change` DECIMAL(12,2) DEFAULT NULL,
        `attachment_path` VARCHAR(500) DEFAULT NULL,
        `attachment_name` VARCHAR(255) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'collected',
        `remitted_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `invoice_id` (`invoice_id`),
        KEY `collector_user_id` (`collector_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $cols = [
        'customer_id' => "ALTER TABLE collection_records ADD COLUMN customer_id INT NOT NULL DEFAULT 0 AFTER invoice_id",
        'branch_id' => "ALTER TABLE collection_records ADD COLUMN branch_id INT NOT NULL DEFAULT 0 AFTER customer_id",
        'collector_user_id' => "ALTER TABLE collection_records ADD COLUMN collector_user_id INT NOT NULL DEFAULT 0 AFTER branch_id",
        'payment_method' => "ALTER TABLE collection_records ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER collector_user_id",
        'amount' => "ALTER TABLE collection_records ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER payment_method",
        'collection_date' => "ALTER TABLE collection_records ADD COLUMN collection_date DATETIME NOT NULL AFTER amount",
        'reference_number' => "ALTER TABLE collection_records ADD COLUMN reference_number VARCHAR(100) DEFAULT NULL AFTER collection_date",
        'check_date' => "ALTER TABLE collection_records ADD COLUMN check_date DATE DEFAULT NULL AFTER reference_number",
        'bank_name' => "ALTER TABLE collection_records ADD COLUMN bank_name VARCHAR(150) DEFAULT NULL AFTER check_date",
        'bank_branch' => "ALTER TABLE collection_records ADD COLUMN bank_branch VARCHAR(150) DEFAULT NULL AFTER bank_name",
        'check_number' => "ALTER TABLE collection_records ADD COLUMN check_number VARCHAR(100) DEFAULT NULL AFTER bank_branch",
        'cash_tendered' => "ALTER TABLE collection_records ADD COLUMN cash_tendered DECIMAL(12,2) DEFAULT NULL AFTER check_number",
        'cash_change' => "ALTER TABLE collection_records ADD COLUMN cash_change DECIMAL(12,2) DEFAULT NULL AFTER cash_tendered",
        'attachment_path' => "ALTER TABLE collection_records ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER cash_change",
        'attachment_name' => "ALTER TABLE collection_records ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path",
        'notes' => "ALTER TABLE collection_records ADD COLUMN notes TEXT DEFAULT NULL AFTER attachment_name",
        'status' => "ALTER TABLE collection_records ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'collected' AFTER notes",
        'remitted_at' => "ALTER TABLE collection_records ADD COLUMN remitted_at DATETIME DEFAULT NULL AFTER status",
        'created_at' => "ALTER TABLE collection_records ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
    ];
    foreach ($cols as $col => $sql) {
        if (!fdColumnExists($conn, 'collection_records', $col)) {
            @$conn->query($sql);
        }
    }
    @$conn->query("ALTER TABLE collection_records MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'collected'");
}

function fdUploadSingleFile($fieldName, $folderName, $prefix, $allowPdf = false) {
    if (empty($_FILES[$fieldName]) || !isset($_FILES[$fieldName]['error'])) return [null, null];

    $isMultiple = is_array($_FILES[$fieldName]['error']);
    $error = $isMultiple ? ($_FILES[$fieldName]['error'][0] ?? UPLOAD_ERR_NO_FILE) : $_FILES[$fieldName]['error'];
    if ($error === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($error !== UPLOAD_ERR_OK) throw new Exception('Failed to upload attachment.');

    $tmpName = $isMultiple ? ($_FILES[$fieldName]['tmp_name'][0] ?? '') : $_FILES[$fieldName]['tmp_name'];
    $originalName = basename((string)($isMultiple ? ($_FILES[$fieldName]['name'][0] ?? '') : $_FILES[$fieldName]['name']));
    $size = (int)($isMultiple ? ($_FILES[$fieldName]['size'][0] ?? 0) : $_FILES[$fieldName]['size']);

    if ($tmpName === '' || $originalName === '') return [null, null];
    if ($size > 8 * 1024 * 1024) throw new Exception('Attachment must not exceed 8MB.');

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = $allowPdf ? ['jpg','jpeg','png','webp','gif','pdf'] : ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed, true)) {
        throw new Exception($allowPdf ? 'Only image or PDF files are allowed.' : 'Only image files are allowed.');
    }

    $uploadDir = __DIR__ . '/../uploads/' . $folderName . '/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new Exception('Upload folder is not writable: uploads/' . $folderName);
    }

    $safeName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $safeName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new Exception('Unable to save uploaded attachment.');
    }

    return ['../uploads/' . $folderName . '/' . $safeName, $originalName];
}

function fdFindOrCreateDeliveryInvoice($conn, $so_id, $branch_id, $user_id, $total_amount, $customer_id) {
    $so_id = (int)$so_id;
    $branch_id = (int)$branch_id;
    $user_id = (int)$user_id;
    $total_amount = (float)$total_amount;
    $customer_id = (int)$customer_id;

    if (!fdTableExists($conn, 'invoices')) {
        $conn->query("CREATE TABLE IF NOT EXISTS `invoices` (
            `invoice_id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_number` VARCHAR(100) DEFAULT NULL,
            `so_id` INT DEFAULT NULL,
            `customer_id` INT NOT NULL DEFAULT 0,
            `invoice_date` DATE DEFAULT NULL,
            `due_date` DATE DEFAULT NULL,
            `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `so_id` (`so_id`),
            KEY `customer_id` (`customer_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    $invoice_id = 0;
    if (fdColumnExists($conn, 'invoices', 'so_id')) {
        $find = $conn->prepare("SELECT invoice_id FROM invoices WHERE so_id = ? ORDER BY invoice_id DESC LIMIT 1");
        if ($find) {
            $find->bind_param('i', $so_id);
            $find->execute();
            $row = $find->get_result()->fetch_assoc();
            if ($row) $invoice_id = (int)$row['invoice_id'];
            $find->close();
        }
    }
    if ($invoice_id > 0) return $invoice_id;

    $invoice_number = 'INV-SO-' . $so_id . '-' . date('YmdHis');

    $cols = [];
    $placeholders = [];
    $types = '';
    $values = [];

    foreach ([
        'invoice_number' => ['s', $invoice_number],
        'so_id' => ['i', $so_id],
        'customer_id' => ['i', $customer_id],
        'invoice_date' => ['s', date('Y-m-d')],
        'due_date' => ['s', date('Y-m-d')],
        'total_amount' => ['d', $total_amount],
        'status' => ['s', 'pending'],
        'created_by' => ['i', $user_id],
    ] as $col => $info) {
        if (fdColumnExists($conn, 'invoices', $col)) {
            $cols[] = $col;
            $placeholders[] = '?';
            $types .= $info[0];
            $values[] = $info[1];
        }
    }

    if (fdColumnExists($conn, 'invoices', 'created_at')) {
        $cols[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (empty($cols)) throw new Exception('Invoice table has no supported columns.');

    $sql = "INSERT INTO invoices (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Failed to create invoice for delivery collection: ' . $conn->error);
    if (!empty($values)) $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) throw new Exception('Failed to create invoice for delivery collection: ' . $stmt->error);
    $invoice_id = (int)$conn->insert_id;
    $stmt->close();

    return $invoice_id;
}

function fdInsertPendingRemitCollectionRecord($conn, $invoice_id, $customer_id, $branch_id, $collector_user_id, $payment_method, $amount, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change, $attachment_path, $attachment_name, $notes) {
    fdEnsureDeliveryCollectionTables($conn);

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $fieldMap = [
        'invoice_id' => ['i', (int)$invoice_id],
        'customer_id' => ['i', (int)$customer_id],
        'branch_id' => ['i', (int)$branch_id],
        'collector_user_id' => ['i', (int)$collector_user_id],
        'payment_method' => ['s', $payment_method],
        'amount' => ['d', (float)$amount],
        'collection_date' => ['s', date('Y-m-d H:i:s')],
        'reference_number' => ['s', $reference_number],
        'check_date' => ['s', $check_date],
        'bank_name' => ['s', $bank_name],
        'bank_branch' => ['s', $bank_branch],
        'check_number' => ['s', $check_number],
        'cash_tendered' => ['d', $cash_tendered],
        'cash_change' => ['d', $cash_change],
        'attachment_path' => ['s', $attachment_path],
        'attachment_name' => ['s', $attachment_name],
        'notes' => ['s', $notes],
        'status' => ['s', 'collected'],
    ];

    foreach ($fieldMap as $col => $info) {
        if (fdColumnExists($conn, 'collection_records', $col)) {
            $columns[] = $col;
            $placeholders[] = '?';
            $types .= $info[0];
            $values[] = $info[1];
        }
    }

    if (fdColumnExists($conn, 'collection_records', 'created_at')) {
        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    $sql = "INSERT INTO collection_records (`" . implode('`,`', $columns) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Failed to prepare pending remit collection: ' . $conn->error);
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) throw new Exception('Failed to save pending remit collection: ' . $stmt->error);
    $record_id = (int)$conn->insert_id;
    $stmt->close();

    return $record_id;
}



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

// AUTO-FIX: Kung ang user ay delivery role at walang branch_id, i-set sa 1 (Main Branch)
if ($user_role == 'delivery' && $branch_id == 0) {
    $branch_id = 1;
    $_SESSION['branch_id'] = 1;
}

// Check if driver_id exists in session or get from users table
$driver_id = $_SESSION['driver_id'] ?? 0;
if ($driver_id == 0 && $user_role == 'delivery') {
    // Try to get driver_id from users table
    $driver_query = "SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL";
    $driver_stmt = $conn->prepare($driver_query);
    $driver_stmt->bind_param("i", $user_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    if ($driver_row = $driver_result->fetch_assoc()) {
        $driver_id = $driver_row['driver_id'];
        $_SESSION['driver_id'] = $driver_id;
    }
    $driver_stmt->close();
}

// Check if branch_id column exists in deliveries table
$delivery_branch_column_exists = false;
$check_delivery_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'branch_id'");
if ($check_delivery_column && $check_delivery_column->num_rows > 0) {
    $delivery_branch_column_exists = true;
}

// Check if driver_id column exists in deliveries table
$delivery_driver_column_exists = false;
$check_delivery_driver_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'driver_id'");
if ($check_delivery_driver_column && $check_delivery_driver_column->num_rows > 0) {
    $delivery_driver_column_exists = true;
}

// Determine filter conditions
$delivery_branch_condition = "";
$delivery_driver_condition = "";

if ($delivery_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $delivery_branch_condition = "AND d.branch_id = $branch_id";
}

// For delivery role, filter by driver_id
if ($user_role == 'delivery' && $driver_id > 0 && $delivery_driver_column_exists) {
    $delivery_driver_condition = "AND d.driver_id = $driver_id";
} elseif ($user_role == 'delivery' && !$delivery_driver_column_exists) {
    // If driver_id column doesn't exist, show a warning but still show orders
    $driver_column_warning = true;
}

// GET PENDING WAREHOUSE PICKUP TASKS - Using existing data only (status = pending)
$pending_pickup_tasks = [];
$has_pending_tasks = false;

if ($user_role == 'delivery' && $driver_id > 0) {
    // Get pending deliveries that are ready for pickup (status = pending)
    $pending_pickup_query = "
        SELECT 
            d.delivery_id,
            d.so_id,
            d.trip_id,
            so.so_number,
            c.customer_name,
            c.address,
            GROUP_CONCAT(CONCAT(i.item_name, ' (', soi.quantity_ordered, ' pcs)') SEPARATOR ', ') as items
        FROM deliveries d
        INNER JOIN sales_orders so ON d.so_id = so.so_id
<<<<<<< HEAD
        LEFT JOIN (
            SELECT so_id, MAX(NULLIF(si_number, '')) AS si_number
            FROM invoices
            WHERE NULLIF(si_number, '') IS NOT NULL
            GROUP BY so_id
        ) inv_si ON inv_si.so_id = so.so_id
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        INNER JOIN customers c ON d.customer_id = c.customer_id
        LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
        LEFT JOIN items i ON soi.item_id = i.item_id
        WHERE d.delivery_status = 'pending'
        AND d.driver_id = $driver_id
        GROUP BY d.delivery_id
        ORDER BY d.delivery_id ASC
    ";
    
    $pending_result = $conn->query($pending_pickup_query);
    if ($pending_result && $pending_result->num_rows > 0) {
        $pending_pickup_tasks = $pending_result->fetch_all(MYSQLI_ASSOC);
        $has_pending_tasks = true;
        // Show modal on every page load if there are pending tasks
        $show_pickup_modal = true;
    } else {
        $show_pickup_modal = false;
    }
}

// AUTO-CREATE DELIVERIES FROM WAREHOUSE READY ORDERS
try {
    // For delivery role, filter by driver
    if ($user_role == 'delivery' && $driver_id > 0) {
        // Get trip tickets assigned to this driver
        $trip_ids_query = "SELECT trip_id FROM trip_tickets WHERE driver_id = ?";
        $trip_stmt = $conn->prepare($trip_ids_query);
        $trip_stmt->bind_param("i", $driver_id);
        $trip_stmt->execute();
        $trip_result = $trip_stmt->get_result();
        
        $trip_ids = [];
        while ($trip_row = $trip_result->fetch_assoc()) {
            $trip_ids[] = $trip_row['trip_id'];
        }
        $trip_stmt->close();
        
        // Create deliveries for trips without deliveries yet
        if (!empty($trip_ids)) {
            $trip_ids_str = implode(',', $trip_ids);
            
            $create_deliveries_query = "
                INSERT INTO deliveries (trip_id, so_id, customer_id, stop_sequence, delivery_status, branch_id, driver_id, created_at)
                SELECT DISTINCT
                    tt.trip_id,
                    tt.so_id,
                    so.customer_id,
                    1 as stop_sequence,
                    'pending' as delivery_status,
                    tt.branch_id,
                    tt.driver_id,
                    NOW()
                FROM trip_tickets tt
                INNER JOIN sales_orders so ON tt.so_id = so.so_id
                LEFT JOIN deliveries d ON tt.trip_id = d.trip_id AND tt.so_id = d.so_id
                WHERE tt.trip_id IN ($trip_ids_str)
                AND tt.trip_status IN ('planned', 'pending', 'in-progress')
                AND d.delivery_id IS NULL
            ";
            $conn->query($create_deliveries_query);
        }
    }
} catch (Exception $e) {
    error_log("Error auto-creating deliveries: " . $e->getMessage());
}

// Build the WHERE clause for the main query (exclude rejected)
$where_clause = "WHERE d.delivery_status IN ('pending', 'in-transit', 'partial', 'delivered')";
$where_clause .= $delivery_branch_condition;

if ($user_role == 'delivery' && $driver_id > 0 && $delivery_driver_column_exists) {
    $where_clause .= " AND d.driver_id = $driver_id";
} elseif ($user_role == 'delivery' && $delivery_driver_column_exists) {
    // If driver_id exists but no driver_id assigned, show nothing
    $where_clause .= " AND 1=0"; // No results
}

// Get delivery statistics including delivered
try {
    $stats_query = "
        SELECT 
            SUM(CASE WHEN delivery_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN delivery_status IN ('in-transit', 'partial') THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN delivery_status = 'delivered' AND DATE(delivery_date) = CURDATE() THEN 1 ELSE 0 END) as completed_today,
            SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as total_completed
        FROM deliveries d
        $where_clause
    ";
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch_assoc();
    
    // Get delivery orders data from deliveries table
    $query = "
        SELECT 
            d.delivery_id,
            d.so_id,
            d.trip_id,
            d.stop_sequence,
            d.delivery_date,
            d.delivery_status,
            d.signed_by,
            d.remarks,
            d.branch_id,
            d.driver_id,
            so.so_number,
            COALESCE(NULLIF(so.si_number, ''), NULLIF(inv_si.si_number, ''), '') AS si_number,
            so.total_amount,
            so.created_at,
            c.customer_id,
            c.customer_name,
            c.contact_person,
            c.phone_number,
            c.address,
            c.city,
            c.longitude,
            c.latitude,
            dr.driver_name,
            dr.vehicle_plate_number,
            dvl.divert_id,
            dvl.status AS divert_status,
            dvl.from_driver_id AS diverted_from_driver_id,
            src_dr.driver_name AS diverted_from_driver_name,
            dst_dr.driver_name AS diverted_to_driver_name,
            dvl.created_at AS diverted_at,
            dvl.received_at AS divert_received_at,
            GROUP_CONCAT(CONCAT(IFNULL(i.item_name, 'Unknown'), ' (', soi.quantity_ordered, ')') SEPARATOR '; ') as items,
            GROUP_CONCAT(CONCAT(soi.quantity_ordered, ' x ', IFNULL(i.item_name, 'Unknown'), ' - ₱', soi.unit_price) SEPARATOR '||') as items_receipt
        FROM deliveries d
        INNER JOIN sales_orders so ON d.so_id = so.so_id
        LEFT JOIN (
            SELECT so_id, MAX(NULLIF(si_number, '')) AS si_number
            FROM invoices
            WHERE NULLIF(si_number, '') IS NOT NULL
            GROUP BY so_id
        ) inv_si ON inv_si.so_id = so.so_id
        INNER JOIN customers c ON d.customer_id = c.customer_id
        LEFT JOIN drivers dr ON d.driver_id = dr.driver_id
        LEFT JOIN delivery_divert_logs dvl ON dvl.delivery_id = d.delivery_id AND dvl.divert_id = (
            SELECT MAX(dvl2.divert_id)
            FROM delivery_divert_logs dvl2
            WHERE dvl2.delivery_id = d.delivery_id
              AND dvl2.status <> 'cancelled'
        )
        LEFT JOIN drivers src_dr ON src_dr.driver_id = dvl.from_driver_id
        LEFT JOIN drivers dst_dr ON dst_dr.driver_id = dvl.to_driver_id
        LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
        LEFT JOIN items i ON soi.item_id = i.item_id
        $where_clause
        GROUP BY d.delivery_id
        ORDER BY 
            CASE 
                WHEN d.delivery_status = 'pending' THEN 1
                WHEN d.delivery_status = 'in-transit' THEN 2
                WHEN d.delivery_status = 'partial' THEN 3
                WHEN d.delivery_status = 'delivered' THEN 4
                ELSE 5
            END,
            d.delivery_date DESC
    ";
    
    $result = $conn->query($query);
    $delivery_orders = [];
    
    if ($result) {
        $delivery_orders = $result->fetch_all(MYSQLI_ASSOC);
    }
    
} catch (Exception $e) {
    error_log("Database error in fordelivery.php: " . $e->getMessage());
    $delivery_orders = [];
    $stats = ['pending_count' => 0, 'active_count' => 0, 'completed_today' => 0, 'total_completed' => 0];
    $driver_column_warning = true;
}


function getDivertDeliveryItemIds($conn, $delivery_id) {
    $delivery_id = (int)$delivery_id;
    $item_ids = [];
    $stmt = $conn->prepare("SELECT DISTINCT soi.item_id FROM deliveries d INNER JOIN sales_order_items soi ON soi.so_id = d.so_id WHERE d.delivery_id = ? AND soi.item_id IS NOT NULL");
    if ($stmt) {
        $stmt->bind_param('i', $delivery_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $id = (int)($row['item_id'] ?? 0);
            if ($id > 0) $item_ids[] = $id;
        }
        $stmt->close();
    }
    return array_values(array_unique($item_ids));
}

function targetDriverHasMatchingProductsForDivert($conn, $target_driver_id, $delivery_id, $item_ids) {
    $target_driver_id = (int)$target_driver_id;
    $delivery_id = (int)$delivery_id;
    $item_ids = array_values(array_filter(array_map('intval', (array)$item_ids), function($id) { return $id > 0; }));
    if ($target_driver_id <= 0 || $delivery_id <= 0 || empty($item_ids)) return false;

    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
    $types = 'ii' . str_repeat('i', count($item_ids)) . 'ii' . str_repeat('i', count($item_ids));
    $params = [];
    $params[] = $target_driver_id;
    $params[] = $delivery_id;
    foreach ($item_ids as $id) $params[] = $id;
    $params[] = $target_driver_id;
    $params[] = $delivery_id;
    foreach ($item_ids as $id) $params[] = $id;

    $sql = "
        SELECT COUNT(*) AS match_count FROM (
            SELECT d.delivery_id AS ref_id
            FROM deliveries d
            INNER JOIN sales_order_items soi ON soi.so_id = d.so_id
            WHERE d.driver_id = ?
              AND d.delivery_id <> ?
              AND LOWER(TRIM(COALESCE(d.delivery_status, ''))) NOT IN ('delivered','cancelled','canceled','rejected')
              AND soi.item_id IN ($placeholders)
            UNION
            SELECT tt.trip_id AS ref_id
            FROM trip_tickets tt
            INNER JOIN sales_order_items soi2 ON soi2.so_id = tt.so_id
            WHERE tt.driver_id = ?
              AND COALESCE(tt.so_id, 0) <> (SELECT COALESCE(so_id, 0) FROM deliveries WHERE delivery_id = ? LIMIT 1)
              AND LOWER(TRIM(COALESCE(tt.trip_status, ''))) NOT IN ('completed','delivered','cancelled','canceled','rejected')
              AND soi2.item_id IN ($placeholders)
        ) matched_sources
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('targetDriverHasMatchingProductsForDivert prepare error: ' . $conn->error);
        return false;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return ((int)($row['match_count'] ?? 0)) > 0;
}


// ========== HANDLE COMPLETE DELIVERY WITH COLLECTION AS PENDING REMIT ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_delivery_pending_remit') {
    try {
        fdEnsureDeliveryCollectionTables($conn);

        $delivery_id = (int)($_POST['delivery_id'] ?? 0);
        $so_id = (int)($_POST['so_id'] ?? 0);
        $delivery_date_raw = trim((string)($_POST['delivery_date'] ?? ''));
        $signed_by = trim((string)($_POST['signed_by'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        if ($delivery_id <= 0) throw new Exception('Invalid delivery ID.');
        if ($so_id <= 0) throw new Exception('Invalid sales order.');
        if ($signed_by === '') throw new Exception('Signed by is required.');

        $delivery_date = $delivery_date_raw !== '' ? date('Y-m-d H:i:s', strtotime($delivery_date_raw)) : date('Y-m-d H:i:s');

        $info_sql = "SELECT 
                        d.delivery_id,
                        d.so_id,
                        d.driver_id,
                        d.customer_id AS delivery_customer_id,
                        d.branch_id AS delivery_branch_id,
                        d.delivery_status,
                        so.total_amount,
                        so.customer_id AS so_customer_id,
                        so.branch_id AS so_branch_id,
                        so.so_number
                    FROM deliveries d
                    INNER JOIN sales_orders so ON so.so_id = d.so_id
                    WHERE d.delivery_id = ? AND d.so_id = ?
                    LIMIT 1";
        $info_stmt = $conn->prepare($info_sql);
        if (!$info_stmt) throw new Exception('Failed to load delivery information: ' . $conn->error);
        $info_stmt->bind_param('ii', $delivery_id, $so_id);
        $info_stmt->execute();
        $delivery_info = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        if (!$delivery_info) throw new Exception('Delivery not found.');
        if ($user_role === 'delivery' && $driver_id > 0 && (int)$delivery_info['driver_id'] !== (int)$driver_id) {
            throw new Exception('You are not authorized to complete this delivery.');
        }

        [$proof_path, $proof_name] = fdUploadSingleFile('proof_photo', 'delivery_proofs', 'delivery_proof', false);

        $conn->begin_transaction();

        $update_parts = [
            "delivery_status = 'delivered'",
            "signed_by = ?",
            "delivery_date = ?",
            "remarks = ?"
        ];
        $types = 'sss';
        $values = [$signed_by, $delivery_date, $remarks];

        if (fdColumnExists($conn, 'deliveries', 'proof_delivery_photo')) {
            $update_parts[] = "proof_delivery_photo = ?";
            $types .= 's';
            $values[] = $proof_path;
        } elseif (fdColumnExists($conn, 'deliveries', 'proof_photo')) {
            $update_parts[] = "proof_photo = ?";
            $types .= 's';
            $values[] = $proof_path;
        }

        $types .= 'i';
        $values[] = $delivery_id;

        $update_delivery_sql = "UPDATE deliveries SET " . implode(', ', $update_parts) . " WHERE delivery_id = ?";
        $update_delivery_stmt = $conn->prepare($update_delivery_sql);
        if (!$update_delivery_stmt) throw new Exception('Failed to prepare delivery update: ' . $conn->error);
        $update_delivery_stmt->bind_param($types, ...$values);
        if (!$update_delivery_stmt->execute()) throw new Exception('Failed to update delivery: ' . $update_delivery_stmt->error);
        $update_delivery_stmt->close();

        if (fdTableExists($conn, 'sales_orders')) {
            $so_update_parts = [];
            if (fdColumnExists($conn, 'sales_orders', 'order_status')) $so_update_parts[] = "order_status = 'delivered'";
            if (fdColumnExists($conn, 'sales_orders', 'delivery_status')) $so_update_parts[] = "delivery_status = 'delivered'";
            if (fdColumnExists($conn, 'sales_orders', 'updated_at')) $so_update_parts[] = "updated_at = NOW()";
            if (!empty($so_update_parts)) {
                $so_update = $conn->prepare("UPDATE sales_orders SET " . implode(', ', $so_update_parts) . " WHERE so_id = ?");
                if ($so_update) {
                    $so_update->bind_param('i', $so_id);
                    $so_update->execute();
                    $so_update->close();
                }
            }
        }

        $collection_saved = false;
        if (!empty($_POST['collect_payment']) && $_POST['collect_payment'] == '1') {
            $payment_method = trim((string)($_POST['payment_method'] ?? 'cash'));
            if (!in_array($payment_method, ['cash', 'check', 'online_transfer'], true)) {
                throw new Exception('Invalid payment method.');
            }

            $amount = (float)($_POST['payment_amount'] ?? 0);
            if ($amount <= 0) throw new Exception('Invalid payment amount.');

            $reference_number = null;
            $check_date = null;
            $bank_name = null;
            $bank_branch = null;
            $check_number = null;
            $cash_tendered = null;
            $cash_change = null;

            if ($payment_method === 'cash') {
                $cash_tendered_input = (float)($_POST['cash_tendered'] ?? 0);
                if ($cash_tendered_input <= 0) throw new Exception('Cash tendered is required.');
                if ($cash_tendered_input + 0.009 < $amount) throw new Exception('Cash tendered cannot be lower than amount to pay.');
                $cash_tendered = $cash_tendered_input;
                $cash_change = max($cash_tendered_input - $amount, 0);
            } elseif ($payment_method === 'check') {
                $check_date = trim((string)($_POST['check_date'] ?? ''));
                $bank_name = trim((string)($_POST['bank_name'] ?? ''));
                $bank_branch = trim((string)($_POST['bank_branch'] ?? ''));
                $check_number = trim((string)($_POST['check_number'] ?? ''));
                if ($check_date === '' || $bank_name === '' || $bank_branch === '' || $check_number === '') {
                    throw new Exception('All check details are required.');
                }
                $reference_number = $check_number;
            } else {
                $reference_number = trim((string)($_POST['reference_number'] ?? ''));
                $bank_wallet_id = (int)($_POST['bank_wallet_id'] ?? 0);
                if ($reference_number === '' || $bank_wallet_id <= 0) {
                    throw new Exception('Reference number and Bank/Wallet are required.');
                }

                $online_stmt = $conn->prepare("SELECT b.bank_name, COALESCE(b.bank_branch, '') AS bank_branch, COALESCE(pb.bank_name, '') AS parent_bank_name
                                            FROM banks b
                                            LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
                                            INNER JOIN bank_payment_methods bpm ON bpm.bank_id = b.bank_id AND bpm.payment_method = 'online_transfer'
                                            WHERE b.bank_id = ? AND b.status = 'active' AND b.parent_bank_id IS NOT NULL LIMIT 1");
                if (!$online_stmt) throw new Exception('Failed to validate online transfer account.');
                $online_stmt->bind_param('i', $bank_wallet_id);
                $online_stmt->execute();
                $online_bank = $online_stmt->get_result()->fetch_assoc();
                $online_stmt->close();

                if (!$online_bank) throw new Exception('Please select a registered online transfer sub account.');
                $bank_name = trim(($online_bank['parent_bank_name'] ? $online_bank['parent_bank_name'] . ' / ' : '') . $online_bank['bank_name']);
                $bank_branch = trim((string)($online_bank['bank_branch'] ?? ''));
            }

            [$payment_attachment_path, $payment_attachment_name] = fdUploadSingleFile('payment_attachments', 'collection_attachments', 'delivery_collection', true);

            if (in_array($payment_method, ['check', 'online_transfer'], true) && empty($payment_attachment_path)) {
                throw new Exception('Payment attachment is required for Check and Online Transfer.');
            }

            $customer_id = (int)($delivery_info['so_customer_id'] ?: $delivery_info['delivery_customer_id']);
            $record_branch_id = (int)($delivery_info['so_branch_id'] ?: $delivery_info['delivery_branch_id'] ?: $branch_id);
            $invoice_id = fdFindOrCreateDeliveryInvoice($conn, $so_id, $record_branch_id, $user_id, (float)$delivery_info['total_amount'], $customer_id);

            $duplicate_check = $conn->prepare("SELECT record_id, status FROM collection_records WHERE invoice_id = ? AND collector_user_id = ? AND status IN ('collected','remitted','approved','completed') ORDER BY record_id DESC LIMIT 1");
            if ($duplicate_check) {
                $duplicate_check->bind_param('ii', $invoice_id, $user_id);
                $duplicate_check->execute();
                $duplicate_record = $duplicate_check->get_result()->fetch_assoc();
                $duplicate_check->close();
                if ($duplicate_record) {
                    throw new Exception('This delivery payment already has a collection/remittance record.');
                }
            }

            $collection_notes = 'Payment collected during delivery for SO ' . ($delivery_info['so_number'] ?? $so_id) . '. This must be remitted by the driver before Branch Admin approval.';
            if ($remarks !== '') $collection_notes .= "\nDelivery remarks: " . $remarks;

            fdInsertPendingRemitCollectionRecord(
                $conn,
                $invoice_id,
                $customer_id,
                $record_branch_id,
                $user_id,
                $payment_method,
                $amount,
                $reference_number,
                $check_date,
                $bank_name,
                $bank_branch,
                $check_number,
                $cash_tendered,
                $cash_change,
                $payment_attachment_path,
                $payment_attachment_name,
                $collection_notes
            );

            $collection_saved = true;
        }

        $conn->commit();

        $success_message = $collection_saved
            ? 'Delivery completed. Payment was saved under Pending Remit. Please go to Collections and click REMIT ALL before Branch Admin can approve it.'
            : 'Delivery completed successfully.';

        echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><script src=\"https://cdn.jsdelivr.net/npm/sweetalert2@11\"></script></head><body>";
        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: " . json_encode($success_message) . ",
                confirmButtonColor: '#047857'
            }).then(function(){ window.location.href = 'fordelivery.php'; });
        </script>";
        echo "</body></html>";
        exit();

    } catch (Throwable $e) {
        if ($conn && method_exists($conn, 'rollback')) { @$conn->rollback(); }

        $error_message = $e->getMessage();
        echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><script src=\"https://cdn.jsdelivr.net/npm/sweetalert2@11\"></script></head><body>";
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Delivery Failed',
                text: " . json_encode($error_message) . ",
                confirmButtonColor: '#dc3545'
            }).then(function(){ window.history.back(); });
        </script>";
        echo "</body></html>";
        exit();
    }
}


// ========== HANDLE DIVERT / RECEIVE REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'accept_diverted_delivery') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', 0);

    try {
        fdEnsureDivertLogTable($conn);
        $delivery_id = intval($_POST['delivery_id'] ?? 0);
        if ($delivery_id <= 0) throw new Exception('Invalid delivery ID.');

        $check_query = "SELECT d.delivery_id, d.driver_id, d.delivery_status, dvl.divert_id
                        FROM deliveries d
                        INNER JOIN delivery_divert_logs dvl ON dvl.delivery_id = d.delivery_id AND dvl.status = 'pending'
                        WHERE d.delivery_id = ?
                        LIMIT 1";
        $check_stmt = $conn->prepare($check_query);
        if (!$check_stmt) throw new Exception('Cannot check diverted delivery: ' . $conn->error);
        $check_stmt->bind_param('i', $delivery_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $delivery_data = $check_result ? $check_result->fetch_assoc() : null;
        $check_stmt->close();

        if (!$delivery_data) throw new Exception('No pending diverted item found for this delivery.');
        if ((int)$delivery_data['driver_id'] !== (int)$driver_id) throw new Exception('This diverted item is not assigned to you.');

        $conn->begin_transaction();

        $divert_id = (int)$delivery_data['divert_id'];
        $receive_stmt = $conn->prepare("UPDATE delivery_divert_logs SET status = 'received', received_by = ?, received_at = NOW() WHERE divert_id = ? AND status = 'pending'");
        if (!$receive_stmt) throw new Exception('Cannot update divert confirmation: ' . $conn->error);
        $receive_stmt->bind_param('ii', $user_id, $divert_id);
        if (!$receive_stmt->execute()) throw new Exception('Failed to confirm received diverted item: ' . $receive_stmt->error);
        $receive_stmt->close();

        $receiver_driver_name = 'Driver';
        $receiver_stmt = $conn->prepare("SELECT driver_name FROM drivers WHERE driver_id = ? LIMIT 1");
        if ($receiver_stmt) {
            $receiver_stmt->bind_param('i', $driver_id);
            $receiver_stmt->execute();
            $receiver_result = $receiver_stmt->get_result();
            if ($receiver_row = $receiver_result->fetch_assoc()) {
                $receiver_driver_name = $receiver_row['driver_name'];
            }
            $receiver_stmt->close();
        }
        $note = "\n[Divert Received] " . $receiver_driver_name . " confirmed receiving this diverted delivery on " . date('Y-m-d H:i:s') . ".";
        $delivery_stmt = $conn->prepare("UPDATE deliveries SET remarks = CONCAT(IFNULL(remarks, ''), ?) WHERE delivery_id = ?");
        if ($delivery_stmt) {
            $delivery_stmt->bind_param('si', $note, $delivery_id);
            $delivery_stmt->execute();
            $delivery_stmt->close();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Diverted item received successfully. You can now start this delivery.']);
        exit();
    } catch (Throwable $e) {
        if ($conn && method_exists($conn, 'rollback')) { @$conn->rollback(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'divert_delivery') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', 0);

    try {
        fdEnsureDivertLogTable($conn);
        $delivery_id = intval($_POST['delivery_id'] ?? 0);
        $target_driver_id = intval($_POST['target_driver_id'] ?? 0);
        
        if (!$delivery_id || !$target_driver_id) {
            throw new Exception("Invalid delivery ID or target driver.");
        }
        if ($target_driver_id === (int)$driver_id) {
            throw new Exception("You cannot divert this delivery to yourself.");
        }
        
        $check_query = "SELECT delivery_id, so_id, driver_id, delivery_status, remarks FROM deliveries WHERE delivery_id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_query);
        if (!$check_stmt) throw new Exception('Cannot check delivery: ' . $conn->error);
        $check_stmt->bind_param("i", $delivery_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $delivery_data = $check_result ? $check_result->fetch_assoc() : null;
        $check_stmt->close();
        
        if (!$delivery_data) throw new Exception("Delivery not found.");
        if ((int)$delivery_data['driver_id'] !== (int)$driver_id) throw new Exception("You are not authorized to divert this delivery.");
        if (strtolower((string)$delivery_data['delivery_status']) !== 'partial') throw new Exception("Only partial deliveries can be diverted.");
        
        $target_check = $conn->prepare("SELECT driver_id FROM drivers WHERE driver_id = ?" . (fdColumnExists($conn, 'drivers', 'status') ? " AND (status IS NULL OR status = '' OR LOWER(TRIM(status)) = 'active')" : "") . " LIMIT 1");
        if (!$target_check) throw new Exception('Cannot check target driver: ' . $conn->error);
        $target_check->bind_param("i", $target_driver_id);
        $target_check->execute();
        if ($target_check->get_result()->num_rows == 0) throw new Exception("Target driver not found or inactive.");
        $target_check->close();
        
        $partial_item_ids = getDivertDeliveryItemIds($conn, $delivery_id);
        if (empty($partial_item_ids)) throw new Exception("No items found in this partial delivery.");

        if (!targetDriverHasMatchingProductsForDivert($conn, $target_driver_id, $delivery_id, $partial_item_ids)) {
            throw new Exception("Target driver does not have any active delivery/trip ticket containing the same product(s) as this partial delivery.");
        }

        $pending_check = $conn->prepare("SELECT divert_id FROM delivery_divert_logs WHERE delivery_id = ? AND status = 'pending' LIMIT 1");
        if ($pending_check) {
            $pending_check->bind_param('i', $delivery_id);
            $pending_check->execute();
            $pending_result = $pending_check->get_result();
            if ($pending_result && $pending_result->num_rows > 0) {
                throw new Exception('This delivery already has a pending divert confirmation.');
            }
            $pending_check->close();
        }
        
        $conn->begin_transaction();

        $from_driver_name = 'Current Driver';
        $to_driver_name = 'Target Driver';
        $driver_names_stmt = $conn->prepare("SELECT driver_id, driver_name FROM drivers WHERE driver_id IN (?, ?)");
        if ($driver_names_stmt) {
            $driver_names_stmt->bind_param('ii', $driver_id, $target_driver_id);
            $driver_names_stmt->execute();
            $driver_names_result = $driver_names_stmt->get_result();
            while ($driver_name_row = $driver_names_result->fetch_assoc()) {
                if ((int)$driver_name_row['driver_id'] === (int)$driver_id) {
                    $from_driver_name = $driver_name_row['driver_name'];
                }
                if ((int)$driver_name_row['driver_id'] === (int)$target_driver_id) {
                    $to_driver_name = $driver_name_row['driver_name'];
                }
            }
            $driver_names_stmt->close();
        }

        $new_remarks = "\n[Diverted] Partial delivery diverted from " . $from_driver_name . " to " . $to_driver_name . " on " . date('Y-m-d H:i:s') . ". Waiting for target driver confirmation.";

        $set_parts = ["driver_id = ?", "delivery_status = 'pending'", "signed_by = NULL", "delivery_date = NULL", "remarks = CONCAT(IFNULL(remarks, ''), ?)"];
        if (fdColumnExists($conn, 'deliveries', 'proof_delivery_photo')) {
            $set_parts[] = "proof_delivery_photo = NULL";
        }
        $update_query = "UPDATE deliveries SET " . implode(', ', $set_parts) . " WHERE delivery_id = ?";
        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) throw new Exception("Failed to prepare divert update: " . $conn->error);
        $update_stmt->bind_param("isi", $target_driver_id, $new_remarks, $delivery_id);
        if (!$update_stmt->execute()) throw new Exception("Failed to divert delivery: " . $update_stmt->error);
        $update_stmt->close();

        $log_stmt = $conn->prepare("INSERT INTO delivery_divert_logs (delivery_id, so_id, from_driver_id, to_driver_id, status, remarks, created_by) VALUES (?, ?, ?, ?, 'pending', ?, ?)");
        if (!$log_stmt) throw new Exception('Failed to prepare divert log: ' . $conn->error);
        $so_id = (int)($delivery_data['so_id'] ?? 0);
        $log_stmt->bind_param('iiiisi', $delivery_id, $so_id, $driver_id, $target_driver_id, $new_remarks, $user_id);
        if (!$log_stmt->execute()) throw new Exception('Failed to save divert log: ' . $log_stmt->error);
        $log_stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Partial delivery successfully diverted. It will appear on the target driver page and must be confirmed as received.']);
        exit();
        
    } catch (Throwable $e) {
        if ($conn && method_exists($conn, 'rollback')) { @$conn->rollback(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}



// ========== HANDLE AVAILABLE DRIVERS FOR DIVERT (SINGLE-FILE AJAX) ==========
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_available_drivers_for_divert') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', 0);

    $sendJson = function($success, $message = '', $extra = []) {
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit();
    };

    $colExists = function($table, $column) use ($conn) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && $res->num_rows > 0);
    };

    try {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            $sendJson(false, 'Session expired. Please log in again.', ['drivers' => []]);
        }

        $delivery_id = (int)($_GET['delivery_id'] ?? 0);
        if ($delivery_id <= 0) $sendJson(false, 'Invalid delivery ID.', ['drivers' => []]);

        $delivery_stmt = $conn->prepare("SELECT delivery_id, so_id, driver_id, delivery_status FROM deliveries WHERE delivery_id = ? LIMIT 1");
        if (!$delivery_stmt) throw new Exception('Cannot load delivery: ' . $conn->error);
        $delivery_stmt->bind_param('i', $delivery_id);
        $delivery_stmt->execute();
        $delivery_result = $delivery_stmt->get_result();
        $delivery = $delivery_result ? $delivery_result->fetch_assoc() : null;
        $delivery_stmt->close();

        if (!$delivery) $sendJson(false, 'Delivery not found.', ['drivers' => []]);
        if (strtolower((string)$delivery['delivery_status']) !== 'partial') {
            $sendJson(false, 'Only partial deliveries can be diverted.', ['drivers' => []]);
        }

        $current_driver_id = (int)($_SESSION['driver_id'] ?? 0);
        if ($current_driver_id <= 0 && ($_SESSION['role'] ?? '') === 'delivery') {
            $uid = (int)$_SESSION['user_id'];
            $driver_lookup = $conn->prepare("SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL LIMIT 1");
            if ($driver_lookup) {
                $driver_lookup->bind_param('i', $uid);
                $driver_lookup->execute();
                $driver_lookup_result = $driver_lookup->get_result();
                if ($driver_row = $driver_lookup_result->fetch_assoc()) {
                    $current_driver_id = (int)$driver_row['driver_id'];
                    $_SESSION['driver_id'] = $current_driver_id;
                }
                $driver_lookup->close();
            }
        }

        if (($_SESSION['role'] ?? '') === 'delivery' && $current_driver_id > 0 && (int)$delivery['driver_id'] !== $current_driver_id) {
            $sendJson(false, 'You are not authorized to divert this delivery.', ['drivers' => []]);
        }

        $item_ids = getDivertDeliveryItemIds($conn, $delivery_id);
        if (empty($item_ids)) $sendJson(false, 'No products found for this delivery.', ['drivers' => []]);

        $drivers_status_exists = $colExists('drivers', 'status');
        $drivers_contact_exists = $colExists('drivers', 'contact_number');
        $drivers_plate_exists = $colExists('drivers', 'vehicle_plate_number');

        $item_placeholders_1 = implode(',', array_fill(0, count($item_ids), '?'));
        $item_placeholders_2 = implode(',', array_fill(0, count($item_ids), '?'));
        $select_plate = $drivers_plate_exists ? "COALESCE(dr.vehicle_plate_number, '') AS vehicle_plate_number" : "'' AS vehicle_plate_number";
        $select_contact = $drivers_contact_exists ? "COALESCE(dr.contact_number, '') AS contact_number" : "'' AS contact_number";

        $sql = "
            SELECT
                dr.driver_id,
                dr.driver_name,
                $select_plate,
                $select_contact,
                COUNT(DISTINCT matched.item_id) AS matching_items,
                COUNT(DISTINCT matched.ref_type, matched.ref_id) AS pending_deliveries
            FROM drivers dr
            INNER JOIN (
                SELECT d.driver_id, soi.item_id, 'delivery' AS ref_type, d.delivery_id AS ref_id
                FROM deliveries d
                INNER JOIN sales_order_items soi ON soi.so_id = d.so_id
                WHERE d.delivery_id <> ?
                  AND LOWER(TRIM(COALESCE(d.delivery_status, ''))) NOT IN ('delivered','cancelled','canceled','rejected')
                  AND soi.item_id IN ($item_placeholders_1)
                UNION ALL
                SELECT tt.driver_id, soi2.item_id, 'trip' AS ref_type, tt.trip_id AS ref_id
                FROM trip_tickets tt
                INNER JOIN sales_order_items soi2 ON soi2.so_id = tt.so_id
                WHERE COALESCE(tt.so_id, 0) <> ?
                  AND LOWER(TRIM(COALESCE(tt.trip_status, ''))) NOT IN ('completed','delivered','cancelled','canceled','rejected')
                  AND soi2.item_id IN ($item_placeholders_2)
            ) matched ON matched.driver_id = dr.driver_id
            WHERE dr.driver_id <> ?
        ";

        $types = 'i' . str_repeat('i', count($item_ids)) . 'i' . str_repeat('i', count($item_ids)) . 'i';
        $params = [];
        $params[] = (int)$delivery_id;
        foreach ($item_ids as $id) $params[] = (int)$id;
        $params[] = (int)$delivery['so_id'];
        foreach ($item_ids as $id) $params[] = (int)$id;
        $params[] = (int)$delivery['driver_id'];

        if ($drivers_status_exists) {
            $sql .= " AND (dr.status IS NULL OR dr.status = '' OR LOWER(TRIM(dr.status)) = 'active')";
        }

        $sql .= " GROUP BY dr.driver_id, dr.driver_name ORDER BY matching_items DESC, pending_deliveries DESC, dr.driver_name ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Cannot load available drivers: ' . $conn->error);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $drivers = [];
        while ($row = $res->fetch_assoc()) {
            $drivers[] = [
                'driver_id' => (int)$row['driver_id'],
                'driver_name' => $row['driver_name'] ?: 'Unnamed Driver',
                'vehicle_plate_number' => $row['vehicle_plate_number'] ?? '',
                'contact_number' => $row['contact_number'] ?? '',
                'matching_items' => (int)$row['matching_items'],
                'pending_deliveries' => (int)$row['pending_deliveries']
            ];
        }
        $stmt->close();

        $sendJson(true, empty($drivers) ? 'No available drivers with matching products found.' : 'Available drivers loaded.', ['drivers' => $drivers]);
    } catch (Throwable $e) {
        error_log('fordelivery.php get_available_drivers_for_divert error: ' . $e->getMessage());
        $sendJson(false, 'Error loading drivers: ' . $e->getMessage(), ['drivers' => []]);
    }
}

// Get driver info if applicable
$driver_info = null;
if ($user_role == 'delivery' && $driver_id > 0) {
    $driver_query = "SELECT * FROM drivers WHERE driver_id = ?";
    $driver_stmt = $conn->prepare($driver_query);
    $driver_stmt->bind_param("i", $driver_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    $driver_info = $driver_result->fetch_assoc();
    $driver_stmt->close();
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
    $user_initials = 'DV';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>For Delivery - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/fordelivery.css">
    <link rel="stylesheet" href="../css/delivery.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Leaflet Routing Machine for directions -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Pickup Modal Styles */
        .pickup-task-card {
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            background: #fff;
            transition: all 0.3s ease;
        }
        .pickup-task-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .pickup-task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        .pickup-order-number {
            font-weight: 600;
            color: #047857;
            font-size: 1.1rem;
        }
        .pickup-badge {
            background-color: #ffc107;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .pickup-customer-name {
            font-weight: 500;
            margin-bottom: 5px;
        }
        .pickup-address {
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        .pickup-items {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 15px;
            max-height: 100px;
            overflow-y: auto;
        }
        .btn-claim {
            background: linear-gradient(135deg, #047857, #44D34E);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            width: 100%;
        }
        .btn-claim:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(4, 120, 87, 0.3);
        }
        .btn-claim:disabled {
            background: #6c757d;
            transform: none;
            cursor: not-allowed;
        }
        .empty-pickup {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .empty-pickup i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .pickup-modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
        }
        .pickup-modal-header .modal-title {
            color: white;
        }
        .pickup-modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        /* Route and Map Styles - BLUE ROUTE */
        .leaflet-routing-container {
            display: none !important;
        }
        
        /* Style for route lines - ensure bright blue color */
        .leaflet-routing-line {
            stroke: #007bff !important;
            stroke-width: 6px !important;
            stroke-opacity: 0.9 !important;
        }
        
        /* Modern Green Truck Icon Styles */
        .modern-truck-icon {
            background: transparent !important;
            border: none !important;
            transition: transform 0.15s ease, filter 0.2s;
            filter: drop-shadow(2px 4px 6px rgba(0,0,0,0.2));
        }
        .modern-truck-icon:hover {
            transform: scale(1.05);
            filter: drop-shadow(3px 6px 8px rgba(0,0,0,0.25));
        }
        
        /* Truck animation */
        @keyframes truck-pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .modern-truck-icon svg {
            animation: truck-pulse 1.5s ease-in-out infinite;
        }
        
        /* Custom destination icon style */
        .custom-destination-icon {
            background-color: #dc3545;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        
        /* Existing styles (keep all original styles) */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        .driver-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        .driver-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .driver-info-card h5 {
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 10px;
        }
        .driver-info-card .info-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
        }
        .driver-info-card .info-value {
            color: white;
            font-weight: 600;
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
        .btn-group .btn {
            margin-right: 2px;
        }
        .modal-xl {
            max-width: 800px;
        }
        .map-icon-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .map-icon-btn:hover {
            background-color: #218838;
            color: white;
        }
        .map-icon-btn i {
            font-size: 0.9rem;
        }
        .status-badge-delivered {
            background-color: #28a745;
            color: white;
        }
        .delivered-row {
            background-color: #f8f9fa;
        }
        .location-map {
            height: 400px;
            width: 100%;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .location-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        .location-info p {
            margin-bottom: 8px;
        }
        .location-info i {
            color: #dc3545;
            margin-right: 8px;
        }
        .coordinates-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .coordinates-badge i {
            font-size: 1rem;
        }
        .photo-modal-img {
            max-width: 100%;
            max-height: 70vh;
            display: block;
            margin: 0 auto;
        }
        .thermal-receipt {
            font-family: 'Courier New', monospace;
            width: 72mm;
            margin: 0 auto;
            padding: 3mm;
            background: white;
            color: black;
            font-size: 11px;
            line-height: 1.3;
            box-sizing: border-box;
            border: 1px solid #ddd;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 4px;
            padding-bottom: 2px;
            border-bottom: 1px dashed #333;
        }
        .receipt-header .company-name {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .receipt-header .receipt-title {
            font-size: 12px;
            font-weight: bold;
        }
        .receipt-header .receipt-no {
            font-size: 10px;
        }
        .receipt-info {
            margin: 4px 0;
            padding: 4px;
            background: #f5f5f5;
            font-size: 10px;
        }
        .info-line {
            display: flex;
            margin: 2px 0;
        }
        .info-label {
            font-weight: bold;
            width: 70px;
            color: #333;
        }
        .info-value {
            flex: 1;
            text-align: left;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 4px 0;
            font-size: 10px;
        }
        .items-table th {
            text-align: left;
            border-bottom: 1px solid #333;
            padding: 2px 0;
        }
        .items-table td {
            padding: 2px 0;
            border-bottom: 1px dotted #999;
            vertical-align: top;
        }
        .items-table .item-name {
            max-width: 100px;
            word-wrap: break-word;
        }
        .items-table .text-right {
            text-align: right;
        }
        .items-table .text-center {
            text-align: center;
        }
        .receipt-total {
            margin-top: 4px;
            padding-top: 2px;
            border-top: 2px solid #333;
            text-align: right;
            font-weight: bold;
            font-size: 12px;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 4px;
            padding-top: 2px;
            border-top: 1px dashed #333;
            font-size: 9px;
            color: #666;
        }
        #receiptModal .modal-dialog {
            max-width: 500px;
            margin: 20px auto;
        }
        #receiptModal .modal-content {
            border-radius: 10px;
            overflow: hidden;
        }
        #receiptModal .modal-header {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        #receiptModal .modal-body {
            padding: 20px;
            background: #fff;
            min-height: 500px;
            max-height: 700px;
            overflow-y: auto;
            display: flex;
            justify-content: center;
        }
        #receiptModal .modal-footer {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
        }
        @media print {
<<<<<<< HEAD
    @page {
    size: 80mm auto;
    margin: 3mm;
}

html,
body {
    width: 100%;
    margin: 0;
    padding: 0;
    background: #fff;
    color: #000;
    font-family: "Courier New", monospace;
    display: flex;
    justify-content: center;
}

.thermal-receipt {
    width: 72mm;
    margin: auto;
    padding: 4mm;
    box-sizing: border-box;
    border: none;
    box-shadow: none;

    font-size: 14px;
    line-height: 1.5;
}

/* Header */
.company-name{
    font-size:13px;
    font-weight:bold;
}

.receipt-title{
    font-size:13px;
    font-weight:bold;
}

.receipt-no{
    font-size:13px;
}

.receipt-info{
    font-size:13px;
}

.info-line{
    margin:4px 0;
}

/* Table */
table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
}

th{
    font-size:13px;
    padding:5px 0;
    border-bottom:2px solid #000;
}

td{
    font-size:13px;
    padding:5px 0;
    border-bottom:1px dotted #999;
}

.text-center{
    text-align:center;
}

.text-right{
    text-align:right;
}

/* Total */
.receipt-total{
    text-align:right;
    font-size:13px;
    font-weight:bold;
    border-top:2px solid #000;
    padding-top:6px;
    margin-top:8px;
}

/* Footer */
.receipt-footer{
    text-align:center;
    font-size:12px;
    margin-top:10px;
}

    #thermalReceipt .thermal-receipt {
        width: 76mm !important;
        margin: 0 auto !important;
        padding: 2mm !important;
        border: none !important;
        box-shadow: none !important;
        font-family: 'Roboto Mono', monospace !important;
        font-size: 11px !important;
        line-height: 1.25 !important;
    }

    .modal,
    .modal-backdrop,
    .btn,
    .bottom-nav,
    header,
    nav {
        display: none !important;
    }
}
=======
            body * {
                visibility: hidden;
            }
            #thermalReceipt, #thermalReceipt * {
                visibility: visible;
            }
            #thermalReceipt {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                display: flex;
                justify-content: center;
                align-items: center;
                background: white;
                margin: 0;
                padding: 0;
                z-index: 9999;
            }
            .thermal-receipt {
                width: 72mm;
                margin: 0 auto;
                padding: 2mm;
                background: white;
                font-family: 'Courier New', monospace;
                font-size: 11px;
                line-height: 1.3;
                box-sizing: border-box;
                border: none;
                box-shadow: none;
            }
            @page {
                margin: 0;
            }
        }
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        .tracking-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .tracking-active {
            background-color: #28a745;
            animation: pulse 1.5s infinite;
        }
        .tracking-inactive {
            background-color: #6c757d;
        }
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.1); }
            100% { opacity: 1; transform: scale(1); }
        }
        #locationIndicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        #locationIndicator.bg-success {
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        .update-counter {
            font-size: 0.75rem;
            margin-left: 5px;
            opacity: 0.8;
        }
        #trackingModal .modal-dialog {
            max-width: 1200px;
            margin: 10px auto;
            height: 95vh;
        }
        #trackingModal .modal-content {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        #trackingModal .modal-body {
            flex: 1;
            padding: 0;
            position: relative;
            overflow: hidden;
        }
        #trackingMap {
            height: 100%;
            width: 100%;
        }
        .status-panel {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 320px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(5px);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 1px solid rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            max-height: calc(100% - 40px);
            overflow-y: auto;
        }
        .status-panel.collapsed {
            padding: 10px 20px;
            width: auto;
            min-width: 200px;
        }
        .status-panel.collapsed .panel-content {
            display: none;
        }
        .status-panel h6 {
            color: #333;
            font-weight: 600;
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: move;
        }
        .status-panel.collapsed h6 {
            margin-bottom: 0;
        }
        .status-panel h6 i:first-child {
            color: #0d6efd;
        }
        .toggle-panel-btn {
            background: transparent;
            border: none;
            color: #6c757d;
            font-size: 1.2rem;
            padding: 0 5px;
            margin-left: auto;
            cursor: pointer;
            transition: color 0.2s;
        }
        .toggle-panel-btn:hover {
            color: #0d6efd;
        }
        .panel-content {
            margin-top: 15px;
        }
        .info-row {
            margin-bottom: 12px;
        }
        .info-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .info-value {
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }
        .coordinates-text {
            font-family: monospace;
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 2px;
        }
        .progress {
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            height: 8px;
        }
        .progress-bar {
            transition: width 0.3s ease;
        }
        .progress-bar.bg-success { background-color: #28a745 !important; }
        .progress-bar.bg-info { background-color: #17a2b8 !important; }
        .progress-bar.bg-warning { background-color: #ffc107 !important; }
        .progress-bar.bg-danger { background-color: #dc3545 !important; }
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid #d1fae5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        #profileModal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }
        #profileModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }
        #profileModal .modal-header .modal-title {
            color: white;
            font-weight: 600;
        }
        #profileModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }
        #profileModal .modal-header .btn-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }
        #profileModal .modal-body {
            padding: 2rem;
            background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%);
        }
        #profileModal .branch-info {
            background: #d1fae5;
            color: #047857;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }
        #profileModal .btn-danger {
            background: linear-gradient(135deg, #dc3545, #f87171);
            border: none;
            padding: 1rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        #profileModal .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }
        .mobile-nav .nav-link.logout-btn {
            color: #dc3545;
        }
        .mobile-nav .nav-link.logout-btn i {
            color: #dc3545;
        }
        .mobile-nav .nav-link.logout-btn.active,
        .mobile-nav .nav-link.logout-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        .mobile-nav .nav-link.logout-btn.active i,
        .mobile-nav .nav-link.logout-btn:hover i {
            color: #dc3545;
        }
<<<<<<< HEAD
        
        /* CLICKABLE ROW STYLES */
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .clickable-row:hover {
            background-color: rgba(4, 120, 87, 0.05);
        }
        
        .clickable-row:active {
            background-color: rgba(4, 120, 87, 0.1);
        }
        
        /* Action buttons container - no changes to existing styling */
        .action-buttons {
            display: inline-flex;
            gap: 8px;
            align-items: center;
        }
        
        /* Desktop action buttons container */
        .desktop-actions {
            white-space: nowrap;
        }
        
        /* Make sure action buttons are clickable even when row is clickable */
        .action-buttons button {
            position: relative;
            z-index: 2;
        }
        
        /* Prevent row click when clicking on buttons */
        .action-buttons button,
        .btn-action {
            cursor: pointer;
        }
        
        /* Mobile action buttons positioning */
        @media (max-width: 768px) {
            .action-buttons {
                display: inline-flex !important;
                gap: 5px;
                margin-left: 8px;
                flex-shrink: 0;
            }
            
            .action-buttons .btn-action {
                width: clamp(30px, 7vw, 36px) !important;
                height: clamp(30px, 7vw, 36px) !important;
                border-radius: 8px !important;
                font-size: clamp(0.85rem, 3.5vw, 1rem) !important;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0;
            }
        }
        
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        @media (max-width: 768px) {
            .custom-table thead {
                display: none;
            }
            .custom-table,
            .custom-table tbody,
            .custom-table tr,
            .custom-table td {
                display: block;
                width: 100%;
            }
            .custom-table tbody tr {
                background: white;
                border-radius: 12px;
                margin-bottom: 10px;
                padding: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.06);
                border: 1px solid #e9ecef;
            }
            .custom-table td:first-child {
                font-size: clamp(0.85rem, 3.5vw, 1rem);
                font-weight: 600;
                color: #047857;
                margin-bottom: 4px;
                padding: 0 !important;
                border: none !important;
                line-height: 1.2;
            }
            .custom-table td:first-child .badge {
                font-size: inherit;
                padding: 0;
                background: transparent !important;
                color: #047857 !important;
                font-weight: 600;
            }
            .custom-table td:nth-child(2) {
                display: flex !important;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                margin-bottom: 4px;
                padding: 0 !important;
                border: none !important;
                width: 100%;
            }
            .custom-table td:nth-child(2) .customer-name-text {
                font-size: clamp(0.95rem, 4.5vw, 1.2rem);
                font-weight: 600;
                color: #212529;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                flex: 1;
                min-width: 0;
            }
            .custom-table td:nth-child(2) .action-buttons {
                display: flex !important;
                gap: 5px;
                flex-shrink: 0;
            }
            .custom-table td:nth-child(2) .btn-action {
                width: clamp(30px, 7vw, 36px) !important;
                height: clamp(30px, 7vw, 36px) !important;
                border-radius: 8px !important;
                font-size: clamp(0.85rem, 3.5vw, 1rem) !important;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0;
            }
            .custom-table td:last-child {
                display: none !important;
            }
            .custom-table td:nth-child(6) {
                display: block !important;
                font-size: clamp(0.8rem, 3.2vw, 0.95rem);
                font-weight: 500;
                color: #f59e0b;
                padding: 0 !important;
                border: none !important;
                margin-top: 2px;
                line-height: 1.2;
            }
            .custom-table td:nth-child(6) .badge {
                all: unset;
                font-size: inherit;
                font-weight: 500;
                padding: 0;
                background: transparent !important;
            }
            .custom-table td:nth-child(6) .badge.bg-warning {
                color: #f59e0b;
            }
            .custom-table td:nth-child(6) .badge.bg-primary {
                color: #0d6efd;
            }
            .custom-table td:nth-child(6) .badge.bg-info {
                color: #0dcaf0;
            }
            .custom-table td:nth-child(6) .badge.bg-success {
                color: #198754;
            }
            .custom-table td:nth-child(6) .badge.bg-secondary {
                color: #6c757d;
            }
            .custom-table td:nth-child(3),
            .custom-table td:nth-child(4),
            .custom-table td:nth-child(5) {
                display: none;
            }
            .custom-table td .driver-badge {
                font-size: 0.65rem;
                margin-top: 2px;
                display: inline-block;
                padding: 2px 6px;
            }
        }
        @media (min-width: 400px) and (max-width: 568px) {
            .custom-table tbody tr {
                padding: 10px;
                margin-bottom: 8px;
            }
            .custom-table td:first-child {
                font-size: 0.9rem;
                margin-bottom: 3px;
            }
            .custom-table td:nth-child(2) .customer-name-text {
                font-size: 1rem;
            }
            .custom-table td:nth-child(2) .btn-action {
                width: 32px !important;
                height: 32px !important;
                font-size: 0.9rem !important;
            }
            .custom-table td:nth-child(6) {
                font-size: 0.85rem;
            }
        }
        @media (max-width: 399px) {
            .custom-table tbody tr {
                padding: 8px;
                margin-bottom: 6px;
            }
            .custom-table td:first-child {
                font-size: 0.8rem;
                margin-bottom: 2px;
            }
            .custom-table td:nth-child(2) .customer-name-text {
                font-size: 0.9rem;
            }
            .custom-table td:nth-child(2) .btn-action {
                width: 28px !important;
                height: 28px !important;
                font-size: 0.8rem !important;
            }
            .custom-table td:nth-child(2) .action-buttons {
                gap: 4px;
            }
            .custom-table td:nth-child(6) {
                font-size: 0.75rem;
            }
        }
        @media (max-width: 320px) {
            .custom-table td:nth-child(2) .btn-action {
                width: 26px !important;
                height: 26px !important;
                font-size: 0.75rem !important;
            }
        }
        @media (min-width: 769px) {
            .custom-table td:nth-child(2) .action-buttons.d-flex.d-md-none {
                display: none !important;
            }
            .custom-table td:nth-child(2) .action-buttons {
                display: none !important;
            }
            .custom-table td:last-child .action-buttons.d-none.d-md-inline-flex {
                display: inline-flex !important;
            }
            .custom-table td:last-child {
                white-space: nowrap;
                text-align: center;
                vertical-align: middle;
            }
            .custom-table td:last-child .action-buttons {
                justify-content: center !important;
                gap: 8px;
            }
            .custom-table td:last-child .btn-action {
                width: 36px;
                height: 36px;
                border-radius: 8px;
                font-size: 1.1rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin: 0 2px;
            }
        }
        .stat-card-row {
            margin-bottom: 1.5rem !important;
        }
        @media (max-width: 768px) {
            .stat-card-row {
                margin-bottom: 1rem !important;
            }
            .form-card {
                margin-top: 0.5rem;
            }
        }
        @media (min-width: 769px) {
            .custom-table td:nth-child(2) .action-buttons,
            .custom-table td:nth-child(2) div[class*="action-buttons"],
            .custom-table td:nth-child(2) .d-flex.d-md-none,
            .custom-table td:nth-child(2) [class*="d-md-none"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                position: absolute !important;
                z-index: -9999 !important;
            }
            .custom-table td:last-child .action-buttons,
            .custom-table td:last-child .d-none.d-md-inline-flex,
            .custom-table td:last-child [class*="d-md-inline-flex"] {
                display: inline-flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                pointer-events: auto !important;
            }
        }
        #receiptModal .modal-dialog {
            max-width: 500px;
            margin: 1.75rem auto;
        }
        #receiptModal .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        #receiptModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding: 1rem 1.5rem;
        }
        #receiptModal .modal-header .modal-title {
            color: white !important;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #receiptModal .modal-header .modal-title i {
            color: white !important;
            font-size: 1.2rem;
        }
        #receiptModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }
        #receiptModal .modal-body {
            padding: 20px;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: auto;
            max-height: 80vh;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        #receiptModal .modal-body::-webkit-scrollbar {
            display: none;
        }
        #receiptModal .thermal-receipt {
            font-family: 'Courier New', monospace;
            width: 72mm !important;
            max-width: 72mm !important;
            min-width: 72mm !important;
            margin: 0 auto;
            padding: 3mm;
            background: white;
            color: black;
            font-size: 11px;
            line-height: 1.3;
            box-sizing: border-box;
            border: 1px solid #ddd;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        @media (max-width: 768px) {
            #receiptModal .modal-dialog {
                max-width: 500px;
                margin: 1rem auto;
            }
            #receiptModal .modal-body {
                padding: 15px;
                max-height: 85vh;
            }
            #receiptModal .thermal-receipt {
                width: 72mm !important;
                max-width: 72mm !important;
                min-width: 72mm !important;
                font-size: 11px !important;
                padding: 3mm !important;
            }
        }
        @media (max-width: 480px) {
            #receiptModal .modal-dialog {
                max-width: 95%;
                margin: 0.5rem auto;
            }
            #receiptModal .modal-body {
                padding: 10px;
                max-height: 90vh;
            }
            #receiptModal .thermal-receipt {
                width: 72mm !important;
                max-width: 72mm !important;
                min-width: 72mm !important;
                font-size: 11px !important;
                padding: 3mm !important;
            }
        }
        @media (max-width: 360px) {
            #receiptModal .modal-body {
                padding: 5px;
                max-height: 95vh;
            }
            #receiptModal .thermal-receipt {
                width: 72mm !important;
                max-width: 72mm !important;
                min-width: 72mm !important;
            }
        }
        #receiptModal .modal-dialog {
            max-width: 500px;
            margin: 1.75rem auto;
        }
        #receiptModal .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
            min-height: 400px;
        }
        #receiptModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding: 1rem 1.5rem;
        }
        #receiptModal .modal-header .modal-title {
            color: white !important;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #receiptModal .modal-header .modal-title i {
            color: white !important;
            font-size: 1.2rem;
        }
        #receiptModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }
        #receiptModal .modal-body {
            padding: 20px;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 350px;
            max-height: 70vh;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        #receiptModal .modal-body::-webkit-scrollbar {
            display: none;
        }
        #receiptModal .thermal-receipt {
            font-family: 'Courier New', monospace;
            width: 72mm !important;
            max-width: 72mm !important;
            min-width: 72mm !important;
            margin: 0 auto;
            padding: 3mm;
            background: white;
            color: black;
            font-size: 11px;
            line-height: 1.3;
            box-sizing: border-box;
            border: 1px solid #ddd;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        @media (max-width: 768px) {
            #receiptModal .modal-dialog {
                max-width: 500px;
                margin: 1rem auto;
            }
            #receiptModal .modal-content {
                min-height: 450px;
            }
            #receiptModal .modal-body {
                padding: 15px;
                min-height: 400px;
                max-height: 85vh;
            }
            #receiptModal .thermal-receipt {
                width: 72mm !important;
                max-width: 72mm !important;
                min-width: 72mm !important;
                font-size: 11px !important;
                padding: 3mm !important;
            }
        }
        @media (max-width: 480px) {
            #receiptModal .modal-dialog {
                max-width: 95%;
                margin: 0.5rem auto;
            }
            #receiptModal .modal-content {
                min-height: 500px;
            }
            #receiptModal .modal-body {
                padding: 10px;
                min-height: 450px;
                max-height: 90vh;
            }
            #receiptModal .thermal-receipt {
                width: 72mm !important;
                max-width: 72mm !important;
                min-width: 72mm !important;
                font-size: 11px !important;
                padding: 3mm !important;
            }
        }
        @media (max-width: 360px) {
            #receiptModal .modal-content {
                min-height: 550px;
            }
            #receiptModal .modal-body {
                min-height: 500px;
                padding: 5px;
                max-height: 95vh;
            }
            #receiptModal .thermal-receipt {
                width: 72mm !important;
                max-width: 72mm !important;
                min-width: 72mm !important;
            }
        }
        
        /* Contact buttons styles - Right Aligned */
        .contact-buttons {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
            text-align: right;
        }
        .contact-buttons .btn-contact {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .contact-buttons .btn-contact i {
            margin-right: 8px;
        }
        .contact-buttons .btn-call {
            background-color: #28a745;
            border: none;
            color: white;
        }
        .contact-buttons .btn-call:hover {
            background-color: #218838;
            transform: translateY(-2px);
        }
        .contact-buttons .btn-message {
            background-color: #17a2b8;
            border: none;
            color: white;
        }
        .contact-buttons .btn-message:hover {
            background-color: #138496;
            transform: translateY(-2px);
        }
        .contact-buttons .phone-number-display {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 8px;
            display: inline-block;
        }
        .justify-content-end {
            justify-content: flex-end !important;
        }
        .d-flex {
            display: flex !important;
        }
        .gap-2 {
            gap: 0.5rem !important;
        }
<<<<<<< HEAD
        
        /* Toggle Switch Styles for Collect Payment */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.3s;
            border-radius: 34px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
        }
        input:checked + .toggle-slider {
            background-color: #047857;
        }
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        .toggle-label {
            margin-left: 12px;
            font-size: 0.9rem;
            color: #333;
            display: inline-block;
            vertical-align: middle;
            line-height: 1.4;
        }
        .payment-toggle-wrapper {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0.25rem 0;
        }
        .confirm-checkbox-wrapper {
            display: flex;
            align-items: center;
            margin: 1rem 0 0.5rem 0;
        }
        .confirm-checkbox-wrapper .form-check-input {
            margin-top: 0;
            margin-right: 8px;
        }
        .confirm-checkbox-wrapper .form-check-label {
            margin-bottom: 0;
            line-height: 1.4;
        }
        
        /* Divert button styles */
        .btn-divert {
            background-color: #ffc107;
            color: #856404;
            border: none;
            transition: all 0.3s ease;
        }
        .btn-divert:hover {
            background-color: #e0a800;
            color: #856404;
            transform: translateY(-2px);
        }
        .btn-divert:active {
            transform: translateY(0);
        }
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
                    <span class="nav-text">Delivery</span>
                </h3>
            </div>
            
           <div class="sidebar-menu">
                <ul class="nav flex-column">
                     <li class="nav-item">
                        <a class="nav-link active" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_collections.php">
                            <i class="bi bi-cash-stack"></i>
                            <span class="nav-text">Collections</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vehicle.php">
                            <i class="bi bi-car-front"></i>
                            <span class="nav-text">Vehicle</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="rejecteddelivery.php">
                            <i class="bi bi-exclamation-circle"></i>
                            <span class="nav-text">Rejected Delivery</span>
                        </a>
                    </li>
                </ul>
            </div>
            <hr class="sidebar-divider">
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
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
                    <h2>For Delivery</h2>
                    <p>Manage and track deliveries in progress</p>
                </div>
                <!-- GPS Tracking Button with Shift Management -->
                <div style="margin-left: auto; display: flex; gap: 10px; align-items: center;">
                    <div id="locationIndicator" class="badge bg-secondary" style="padding: 8px 12px;">
                        <span class="tracking-indicator tracking-inactive"></span>
                        <span id="locationStatus">Offline</span>
                        <span id="updateCount" class="update-counter"></span>
                    </div>
                </div>
            </div>

       <!-- Delivery Stats -->
<div class="row stat-card-row g-1 g-sm-2">
    <!-- Card 1 - Pending -->
    <div class="col">
        <div class="stat-card inventory">
            <i class="bi bi-clock"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['pending_count'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>

    <!-- Card 2 - Active -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-truck"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['active_count'] ?? 0; ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
    </div>

    <!-- Card 3 - Completed Today -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-check-circle"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['completed_today'] ?? 0; ?></div>
                <div class="stat-label">Completed Today</div>
            </div>
        </div>
    </div>

    <!-- Card 4 - Total Completed -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-archive"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_completed'] ?? 0; ?></div>
                <div class="stat-label">Total Completed</div>
            </div>
        </div>
    </div>
</div>

            <!-- FILTER SECTION - SALES ORDERS -->
<div class="form-card mb-4">
    <div class="filter-header">
        <h5>
            <i class="bi bi-funnel"></i> Filter Sales Orders
        </h5>
        <button class="filter-toggle-btn" type="button" id="salesFilterToggle" aria-expanded="false">
            <i class="bi bi-chevron-down" id="salesFilterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="salesFilterContent">
        <div class="row g-3">
            <!-- Search Field -->
            <div class="col-12 col-md-6">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <div class="input-group">
                    <input type="text" class="form-control" id="searchInput" placeholder="Search by order ID, customer name...">
                </div>
            </div>
            
            <!-- Status Filter -->
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status
                </label>
                <select class="form-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="in-transit">In Transit</option>
                    <option value="partial">Partial</option>
                    <option value="delivered">Delivered</option>
                </select>
            </div>
        </div>
    </div>
</div>

            <!-- No Orders Message -->
            <?php if (empty($delivery_orders)): ?>
                <div class="alert alert-info text-center py-4">
                    <i class="bi bi-truck" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0">
                        No deliveries found.
                        <?php if ($user_role == 'delivery'): ?>
                            <br><small>You don't have any deliveries assigned yet.</small>
                        <?php elseif ($delivery_branch_column_exists && !$view_all_branches): ?>
                            <br><small>No deliveries found for your branch.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

             <!-- Delivery Orders Table - RESPONSIVE LAYOUT -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead class="table-light">
<<<<<<< HEAD
                            <tr>
=======
                                <tr>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                    <th>Order ID</th>
                                    <th>Customer Name</th>
                                    <th>Address</th>
                                    <th>Contact</th>
                                    <th>Items</th>
                                    <th>Status</th>
                                    <th class="text-center align-middle">Actions</th>
                                </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($delivery_orders as $order): ?>
                            <?php 
                                $has_coordinates = !empty($order['latitude']) && !empty($order['longitude']) && 
                                                   $order['latitude'] != 0 && $order['longitude'] != 0;
                            ?>
                            <tr class="<?php echo $order['delivery_status'] == 'delivered' ? 'delivered-row' : ''; ?> clickable-row" 
                                onclick="viewDeliveryDetails(<?php echo $order['delivery_id']; ?>)">
                                <td>
                                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($order['so_number']); ?></span>
                                    <?php if (!empty($order['divert_id'])): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-share-fill"></i> Diverted item
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($user_role != 'delivery' && isset($order['driver_name']) && $order['driver_name']): ?>
                                        <br><small class="driver-badge"><?php echo htmlspecialchars($order['driver_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
<<<<<<< HEAD
                                    <span class="customer-name-text"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                    <!-- Action buttons for MOBILE only (hidden on desktop) -->
                                    <div class="action-buttons d-flex d-md-none" onclick="event.stopPropagation()">
=======
                                    <?php echo htmlspecialchars($order['customer_name']); ?>
                                    <!-- Action buttons for MOBILE only (hidden on desktop) -->
                                    <div class="action-buttons d-flex d-md-none" style="display: inline-flex !important; margin-left: 8px;">
                                        <button class="btn-action btn-view" title="View Details" onclick="viewDeliveryDetails(<?php echo $order['delivery_id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                        
                                        
                                        <?php if ($has_coordinates): ?>
                                            <button class="btn-action btn-map" title="Navigate to Customer" onclick="openLiveNavigation(
                                                <?php echo $order['latitude']; ?>, 
                                                <?php echo $order['longitude']; ?>, 
                                                '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', 
                                                '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>'
                                            )">
                                                <i class="bi bi-geo-alt-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'pending'): ?>
                                            <?php if (!empty($order['divert_id']) && ($order['divert_status'] ?? '') === 'pending'): ?>
                                                <button class="btn-action btn-success" title="Receive Diverted Item" onclick="acceptDivertedDelivery(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                    <i class="bi bi-box-arrow-in-down"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-action btn-truck" title="Start Delivery" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'in-transit')">
                                                    <i class="bi bi-truck"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php elseif ($order['delivery_status'] == 'in-transit'): ?>
                                            <button class="btn-action btn-success" title="Mark as Delivered" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', <?php echo (float)($order['total_amount'] ?? 0); ?>)">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn-action btn-warning" title="Mark as Partial" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'partial')">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'partial'): ?>
                                            <button class="btn-action btn-success" title="Complete Remaining Items" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', <?php echo (float)($order['total_amount'] ?? 0); ?>)">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn-action btn-divert" title="Divert to Another Driver" onclick="openDivertModal(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>')">
                                                <i class="bi bi-share-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'delivered'): ?>
                                            <button class="btn-action btn-print" title="Print Receipt" onclick="showReceiptModal(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', '<?php echo htmlspecialchars(addslashes($order['si_number'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>', '<?php echo htmlspecialchars(addslashes($order['signed_by'])); ?>', '<?php echo $order['delivery_date']; ?>', '<?php echo htmlspecialchars(addslashes($order['items_receipt'])); ?>')">
                                                <i class="bi bi-receipt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                 </td>
                                <td><?php echo htmlspecialchars($order['address'] . ', ' . $order['city']); ?></td>
                                <td><?php echo htmlspecialchars($order['phone_number']); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($order['items'])) {
                                        $items = explode('; ', $order['items']);
                                        $display_items = array_slice($items, 0, 2);
                                        foreach ($display_items as $index => $item):
                                    ?>
                                        <small class="d-block"><?php echo htmlspecialchars($item); ?></small>
                                    <?php 
                                        endforeach;
                                        if (count($items) > 2) {
                                            echo '<small class="text-muted">+' . (count($items) - 2) . ' more</small>';
                                        }
                                    } else {
                                        echo '<small class="text-muted">No items listed</small>';
                                    }
                                    ?>
                                 </td>
                                <td>
                                    <?php
                                    $status_badge = '';
                                    $status_text = '';
                                    switch ($order['delivery_status']) {
                                        case 'pending':
                                            $status_badge = 'bg-warning';
                                            $status_text = 'Pending';
                                            break;
                                        case 'in-transit':
                                            $status_badge = 'bg-primary';
                                            $status_text = 'In Transit';
                                            break;
                                        case 'partial':
                                            $status_badge = 'bg-info';
                                            $status_text = 'Partial';
                                            break;
                                        case 'delivered':
                                            $status_badge = 'bg-success';
                                            $status_text = 'Delivered';
                                            break;
                                        default:
                                            $status_badge = 'bg-secondary';
                                            $status_text = ucfirst($order['delivery_status']);
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_badge; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
<<<<<<< HEAD
                                 </td>
                                <td class="text-center align-middle desktop-actions">
                                    <!-- Desktop action buttons (hidden on mobile) -->
                                    <div class="action-buttons d-none d-md-inline-flex" onclick="event.stopPropagation()">
=======
                                </td>
                                <td class="text-center align-middle">
                                    <!-- Desktop action buttons (hidden on mobile) -->
                                    <div class="action-buttons d-none d-md-inline-flex" style="justify-content: center; gap: 8px;">
                                        <button class="btn-action btn-view" title="View Details" onclick="viewDeliveryDetails(<?php echo $order['delivery_id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                        
                                        <?php if ($has_coordinates): ?>
                                            <button class="btn-action btn-map" title="Navigate to Customer" onclick="openLiveNavigation(
                                                <?php echo $order['latitude']; ?>, 
                                                <?php echo $order['longitude']; ?>, 
                                                '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', 
                                                '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>'
                                            )">
                                                <i class="bi bi-geo-alt-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'pending'): ?>
                                            <?php if (!empty($order['divert_id']) && ($order['divert_status'] ?? '') === 'pending'): ?>
                                                <button class="btn-action btn-success" title="Receive Diverted Item" onclick="acceptDivertedDelivery(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                    <i class="bi bi-box-arrow-in-down"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-action btn-truck" title="Start Delivery" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'in-transit')">
                                                    <i class="bi bi-truck"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php elseif ($order['delivery_status'] == 'in-transit'): ?>
                                            <button class="btn-action btn-success" title="Mark as Delivered" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', <?php echo (float)($order['total_amount'] ?? 0); ?>)">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn-action btn-warning" title="Mark as Partial" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'partial')">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'partial'): ?>
                                            <button class="btn-action btn-success" title="Complete Remaining Items" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', <?php echo (float)($order['total_amount'] ?? 0); ?>)">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn-action btn-divert" title="Divert to Another Driver" onclick="openDivertModal(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>')">
                                                <i class="bi bi-share-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'delivered'): ?>
                                            <button class="btn-action btn-print" title="Print Receipt" onclick="showReceiptModal(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', '<?php echo htmlspecialchars(addslashes($order['si_number'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>', '<?php echo htmlspecialchars(addslashes($order['signed_by'])); ?>', '<?php echo $order['delivery_date']; ?>', '<?php echo htmlspecialchars(addslashes($order['items_receipt'])); ?>')">
                                                <i class="bi bi-receipt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link active" href="fordelivery.php">
                    <i class="bi bi-truck"></i>
                    <span>Delivery</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="rejecteddelivery.php">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>Rejected</span>
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
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo $user_initials; ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden thermal receipt container -->
    <div id="thermalReceipt" style="display: none;"></div>

    <!-- Live Tracking Modal -->
    <div class="modal fade" id="trackingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-navigation me-2"></i>
                        Live Navigation to Customer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 position-relative">
                    <div id="trackingMap" class="location-map"></div>
                    
                    <!-- Navigation Status Panel -->
                    <div class="status-panel" id="navigationStatusPanel">
                        <h6>
                            <i class="bi bi-info-circle-fill text-primary me-2"></i>
                            Navigation Status
                            <button class="toggle-panel-btn" id="toggleStatusPanel" title="Expand/Collapse">
                                <i class="bi bi-chevron-up"></i>
                            </button>
                        </h6>
                        
                        <div class="panel-content">
                            <!-- Your Location -->
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-truck text-primary"></i>
                                    Driver Location:
                                </div>
                                <div class="info-value" id="yourLocationText">Acquiring GPS...</div>
                                <div class="coordinates-text" id="yourCoordinates">--</div>
                            </div>
                            
                            <!-- Destination -->
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-pin-map-fill text-danger"></i>
                                    Destination:
                                </div>
                                <div class="info-value" id="destinationText">Customer Location</div>
                                <div class="coordinates-text" id="destinationCoordinates">--</div>
                            </div>
                            
                            <!-- Distance & Time -->
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="bg-light p-2 rounded text-center">
                                        <div class="info-label">Distance</div>
                                        <div class="info-value" style="font-size: 1.2rem;">
                                            <span id="distanceText">--</span> <small>km</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light p-2 rounded text-center">
                                        <div class="info-label">Est. Time</div>
                                        <div class="info-value" style="font-size: 1.2rem;">
                                            <span id="timeText">--</span> <small>min</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- GPS Accuracy -->
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-satellite me-1"></i>
                                    GPS Accuracy:
                                </div>
                                <div class="progress mb-1" style="height: 8px;">
                                    <div id="accuracyBar" class="progress-bar bg-success" style="width: 100%"></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small id="accuracyText" class="text-muted">High accuracy</small>
                                    <button class="retry-btn" onclick="retryGPSTracking()" title="Retry GPS">
                                        <i class="bi bi-arrow-repeat"></i> Retry
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Navigation Actions -->
                            <div class="d-grid gap-2 mt-3">
                                <button class="btn btn-sm btn-success" onclick="centerOnYourLocation()">
                                    <i class="bi bi-crosshair me-2"></i>Center on Me
                                </button>
                                <button class="btn btn-sm btn-outline-primary" onclick="openGoogleMaps()">
                                    <i class="bi bi-google me-2"></i>Open in Google Maps
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" onclick="confirmStopLiveNavigation()">
                        <i class="bi bi-stop-circle me-2"></i>Stop Navigation
                    </button>
                </div>
            </div>
        </div>
    </div>

  <!-- View Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-labelledby="viewDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="viewDetailsModalLabel">
                    <i class="bi bi-truck text-custom me-2"></i>
                    Delivery Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3" id="viewDetailsModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Loading delivery details...</p>
                </div>
            </div>
            <!-- Modal footer removed -->
        </div>
    </div>
</div>

<!-- Location Map Modal -->
<div class="modal fade" id="locationMapModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-geo-alt-fill text-custom me-2"></i>
                    Customer Location
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="customerLocationMap" class="location-map"></div>
                
                <div class="location-info">
                    <h6 id="modalCustomerName" class="mb-3"></h6>
                    <p>
                        <i class="bi bi-geo-alt"></i>
                        <strong>Address:</strong> <span id="modalCustomerAddress"></span>
                    </p>
                    <p>
                        <i class="bi bi-geo"></i>
                        <strong>Coordinates:</strong>
                        <span id="modalCoordinates" class="coordinates-badge">
                            <i class="bi bi-crosshair"></i>
                            <span id="modalLat"></span>, <span id="modalLng"></span>
                        </span>
                    </p>
                </div>
            </div>
            <!-- Modal footer removed -->
        </div>
    </div>
</div>

<!-- Photo Modal -->
<div class="modal fade" id="photoModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-image text-primary me-2"></i>
                    Proof of Delivery Photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="" alt="Proof of Delivery" class="photo-modal-img" id="photoModalImg">
            </div>
            <!-- Modal footer removed -->
        </div>
    </div>
</div>

<!-- Delivery Modal -->
<div class="modal fade" id="deliveryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title">Complete Delivery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <form id="deliveryForm" enctype="multipart/form-data" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                    <input type="hidden" name="action" value="complete_delivery_pending_remit">
                    <input type="hidden" name="delivery_id" id="modalDeliveryId">
                    <input type="hidden" name="so_id" id="modalSoId">
                    <input type="hidden" name="so_number" id="modalSoNumber">
                    <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                    
                    <div class="alert alert-info py-2">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div><strong id="orderIdDisplay"></strong> - Delivery Confirmation Required</div>
                            <div class="fw-bold text-dark">Amount Due: <span id="deliveryAmountDueDisplay">₱0.00</span></div>
                        </div>
                    </div>

                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label class="form-label small">Delivery Date</label>
                            <input type="datetime-local" class="form-control form-control-sm" name="delivery_date" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Signed By</label>
                            <input type="text" class="form-control form-control-sm" name="signed_by" placeholder="Customer name" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Proof of Delivery Photo</label>
                        <input type="file" class="form-control form-control-sm" name="proof_photo" accept="image/*" required>
                        <small class="text-muted">Upload photo of delivered package</small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Remarks</label>
                        <textarea class="form-control form-control-sm" name="remarks" rows="2" placeholder="Any notes..."></textarea>
                    </div>

                    <!-- TOGGLE SWITCH for Collect Payment with proper margin -->
                    <div class="payment-toggle-wrapper">
                        <label class="toggle-switch">
                            <input type="checkbox" id="collectPaymentToggle" name="collect_payment" value="1">
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Collect payment now (optional)</span>
                    </div>

                    <div id="deliveryPaymentSection" class="border rounded p-3 mb-3" style="display:none; background:#f8f9fa;">
                        <div class="mb-2 fw-bold text-success">Collection Details</div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small">Amount to Pay</label>
                                <input type="number" class="form-control form-control-sm" name="payment_amount" id="deliveryPaymentAmount" step="0.01" min="0" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Payment Method</label>
                                <select class="form-select form-select-sm" name="payment_method" id="deliveryPaymentMethod">
                                    <option value="cash">Cash</option>
                                    <option value="check">Check</option>
                                    <option value="online_transfer">Online Transfer</option>
                                </select>
                            </div>
                        </div>

                        <div id="deliveryCashFields" class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small">Cash Tendered</label>
                                <input type="number" class="form-control form-control-sm" name="cash_tendered" id="deliveryCashTendered" step="0.01" min="0" placeholder="Amount received">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Change</label>
                                <input type="text" class="form-control form-control-sm" id="deliveryCashChange" readonly value="₱0.00">
                            </div>
                        </div>

                        <div id="deliveryCheckFields" style="display:none;">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Check Date</label>
                                    <input type="date" class="form-control form-control-sm" name="check_date" id="deliveryCheckDate">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Check Number</label>
                                    <input type="text" class="form-control form-control-sm" name="check_number" id="deliveryCheckNumber">
                                </div>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Bank Name</label>
                                    <input type="text" class="form-control form-control-sm" name="bank_name" id="deliveryBankName">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Bank Branch</label>
                                    <input type="text" class="form-control form-control-sm" name="bank_branch" id="deliveryBankBranch">
                                </div>
                            </div>
                        </div>

                        <div id="deliveryTransferFields" style="display:none;">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Reference Number</label>
                                    <input type="text" class="form-control form-control-sm" name="reference_number" id="deliveryReferenceNumber">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Bank / Wallet</label>
                                    <select class="form-select form-select-sm" name="bank_wallet_id" id="deliveryBankWallet"><option value="">Select online transfer sub account</option><?php foreach ($online_transfer_accounts as $acct): ?><option value="<?php echo (int)$acct['bank_id']; ?>"><?php echo htmlspecialchars((!empty($acct['parent_bank_name']) ? $acct['parent_bank_name'] . ' / ' : '') . $acct['bank_name'] . (!empty($acct['account_number']) ? ' - ' . $acct['account_number'] : ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                                </div>
                            </div>
                        </div>

                        <div id="deliveryAttachmentFields" class="mt-2" style="display:none;">
                            <label class="form-label small">Payment Attachment(s)</label>
                            <input type="file" class="form-control form-control-sm" name="payment_attachments[]" id="deliveryPaymentAttachments" accept="image/*,.pdf" multiple>
                            <small class="text-muted">Required for Check and Online Transfer. You may upload multiple images/PDF files.</small>
                        </div>
                    </div>

                    <!-- Confirm checkbox with proper margin -->
                    <div class="confirm-checkbox-wrapper">
                        <input class="form-check-input" type="checkbox" name="confirm_delivery" required id="confirmDeliveryCheckbox">
                        <label class="form-check-label small" for="confirmDeliveryCheckbox">
                            I confirm this delivery is complete
                        </label>
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Confirm Delivery</button>
                    </div>
                </form>
            </div>
            <!-- Modal footer removed from modal level -->
        </div>
    </div>
</div>

<!-- Thermal Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 500px;">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #047857, #44D34E);">
                <h5 class="modal-title" style="color: white;">
                    <i class="bi bi-receipt me-2" style="color: white;"></i>
                    Receipt Preview
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body" id="receiptContent" style="padding: 20px; min-height: auto;">
                <!-- Receipt preview will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printThermalReceipt()">
                    <i class="bi bi-printer"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Partial Delivery Modal -->
<div class="modal fade" id="partialModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Partial Delivery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="partialForm">
                    <div class="mb-3">
                        <label class="form-label">Reason for Partial Delivery</label>
                        <select class="form-select" id="partialReason" required>
                            <option value="">Select reason</option>
                            <option value="Out of stock">Out of stock</option>
                            <option value="Damaged items">Damaged items</option>
                            <option value="Customer refused some items">Customer refused some items</option>
                            <option value="Wrong items">Wrong items</option>
                            <option value="Quantity mismatch">Quantity mismatch</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3" id="otherReasonDiv" style="display: none;">
                        <label class="form-label">Please specify</label>
                        <input type="text" class="form-control" id="otherReason" placeholder="Enter reason">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Items Delivered</label>
                        <div id="itemsList" class="border p-2 rounded mb-2" style="max-height: 200px; overflow-y: auto;">
                            <!-- Items will be loaded here -->
                        </div>
                        <small class="text-muted">Check the items that were successfully delivered</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Additional Details</label>
                        <textarea class="form-control" id="partialDetails" rows="3" placeholder="Provide more details about the partial delivery..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="submitPartialDelivery()">Submit Partial</button>
            </div>
        </div>
    </div>
</div>

<<<<<<< HEAD
<!-- ========== DIVERT MODAL ========== -->
<div class="modal fade" id="divertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #ffc107, #e0a800); color: #856404;">
                <h5 class="modal-title">
                    <i class="bi bi-share-fill me-2"></i>Divert Partial Delivery to Another Driver
=======
<!-- PICKUP TASKS MODAL - Driver's first login modal -->
<?php if ($has_pending_tasks && $show_pickup_modal): ?>
<div class="modal fade" id="pickupTasksModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header pickup-modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-box-seam me-2"></i>
                    Warehouse Pickup Required
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
<<<<<<< HEAD
            <div class="modal-body">
                <div id="divertLoading" class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                    <p>Loading available drivers...</p>
                </div>
                <div id="divertContent" style="display: none;">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Delivery to divert:</strong> <span id="divertOrderNumber"></span> - <span id="divertCustomerName"></span>
                    </div>
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Only drivers with matching products in their pending deliveries will be shown.
                    </div>
                    <div id="driversList" class="list-group mb-3">
                        <!-- Drivers will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- PICKUP TASKS MODAL - Driver's first login modal -->
<?php if ($has_pending_tasks && $show_pickup_modal): ?>
<div class="modal fade" id="pickupTasksModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header pickup-modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-box-seam me-2"></i>
                    Warehouse Pickup Required
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="background: #f8f9fa;">
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Attention:</strong> Please claim the items below from the warehouse before starting your deliveries.
                </div>
                
                <div id="pickupTasksContainer">
                    <?php foreach ($pending_pickup_tasks as $task): ?>
                    <div class="pickup-task-card" id="task_<?php echo $task['delivery_id']; ?>">
                        <div class="pickup-task-header">
                            <span class="pickup-order-number"><?php echo htmlspecialchars($task['so_number']); ?></span>
                            <span class="pickup-badge">Ready for Pickup</span>
                        </div>
                        <div class="pickup-customer-name">
                            <i class="bi bi-person"></i> <?php echo htmlspecialchars($task['customer_name']); ?>
                        </div>
                        <div class="pickup-address">
                            <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($task['address']); ?>
                        </div>
                        <div class="pickup-items">
                            <i class="bi bi-box"></i> <strong>Items:</strong><br>
                            <?php echo htmlspecialchars($task['items']); ?>
                        </div>
                        <button class="btn-claim" onclick="claimWarehousePickup(<?php echo $task['delivery_id']; ?>, this)">
                            <i class="bi bi-check2-circle"></i> Claim Items from Warehouse
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
=======
            <div class="modal-body" style="background: #f8f9fa;">
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Attention:</strong> Please claim the items below from the warehouse before starting your deliveries.
                </div>
                
                <div id="pickupTasksContainer">
                    <?php foreach ($pending_pickup_tasks as $task): ?>
                    <div class="pickup-task-card" id="task_<?php echo $task['delivery_id']; ?>">
                        <div class="pickup-task-header">
                            <span class="pickup-order-number"><?php echo htmlspecialchars($task['so_number']); ?></span>
                            <span class="pickup-badge">Ready for Pickup</span>
                        </div>
                        <div class="pickup-customer-name">
                            <i class="bi bi-person"></i> <?php echo htmlspecialchars($task['customer_name']); ?>
                        </div>
                        <div class="pickup-address">
                            <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($task['address']); ?>
                        </div>
                        <div class="pickup-items">
                            <i class="bi bi-box"></i> <strong>Items:</strong><br>
                            <?php echo htmlspecialchars($task['items']); ?>
                        </div>
                        <button class="btn-claim" onclick="claimWarehousePickup(<?php echo $task['delivery_id']; ?>, this)">
                            <i class="bi bi-check2-circle"></i> Claim Items from Warehouse
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
<?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    <script>
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const userRole = '<?php echo $user_role; ?>';
        const driverId = <?php echo $driver_id ?: 0; ?>;
        const deliveryDivertInfo = <?php
            $delivery_divert_info = [];
            foreach ($delivery_orders as $divert_order) {
                if (!empty($divert_order['divert_id'])) {
                    $delivery_divert_info[(int)$divert_order['delivery_id']] = [
                        'divert_id' => (int)$divert_order['divert_id'],
                        'status' => $divert_order['divert_status'] ?? '',
                        'from' => $divert_order['diverted_from_driver_name'] ?: 'Unknown Driver',
                        'to' => $divert_order['diverted_to_driver_name'] ?: ($divert_order['driver_name'] ?? 'Unknown Driver'),
                        'date' => $divert_order['diverted_at'] ?? '',
                        'received_at' => $divert_order['divert_received_at'] ?? ''
                    ];
                }
            }
            echo json_encode($delivery_divert_info, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        ?>;

        let currentDeliveryId = null;
        let currentSoId = null;
        let currentOrderNumber = null;
        let map = null;
        let marker = null;
        let currentLat = null;
        let currentLng = null;
        let currentCustomerName = null;
        let currentAddress = null;
        let currentPartialDeliveryId = null;
        let currentItems = [];
        let currentThermalReceipt = '';

        // ================= GPS TRACKING VARIABLES =================
        let watchId = null;
        let trackingActive = false;
        let updateCount = 0;
        let retryCount = 0;
        let currentDriverId = <?php echo $driver_id ?: 0; ?>;

        // ================= LIVE TRACKING VARIABLES =================
        let liveTrackingMap = null;
        let routingControl = null;
        let userMarker = null;
        let destinationMarker = null;
        let watchPositionId = null;
        let currentPosition = null;
        let destinationPosition = null;
        let gpsRetryTimeout = null;
        let glowLine = null; // For route glow effect

        // Cache ng huling posisyon para sa mabilis na initial load
        let lastKnownPosition = null;
        
<<<<<<< HEAD

        // Keep user on the same page after successful actions.
        // This replaces full page reloads so SweetAlert stays on the current screen.
        function keepCurrentPageAfterSuccess(options = {}) {
            const modalIds = options.modalIds || [];
            modalIds.forEach(id => {
                const el = document.getElementById(id);
                if (el && typeof bootstrap !== 'undefined') {
                    const instance = bootstrap.Modal.getInstance(el);
                    if (instance) instance.hide();
                }
            });

            if (options.removeRowSelector) {
                const row = document.querySelector(options.removeRowSelector);
                if (row) {
                    row.style.transition = 'all 0.25s ease';
                    row.style.opacity = '0';
                    row.style.transform = 'translateY(-6px)';
                    setTimeout(() => row.remove(), 250);
                }
            }

            if (options.updateStatus && options.deliveryId) {
                const deliveryRow = document.querySelector(`tr[onclick*="viewDeliveryDetails(${options.deliveryId})"]`);
                if (deliveryRow) {
                    const statusCell = deliveryRow.querySelector('td:nth-child(6)');
                    if (statusCell) statusCell.innerHTML = options.updateStatus;
                }
            }

            window.history.replaceState({}, '', window.location.pathname + window.location.search);
        }

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        // ================= WAREHOUSE PICKUP FUNCTIONS =================
        function claimWarehousePickup(deliveryId, button) {
            Swal.fire({
                title: 'Confirm Pickup',
                text: 'Have you collected all items from the warehouse?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#047857',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Claimed Items',
                cancelButtonText: 'Not Yet'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Disable button and show loading
                    const btn = button;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                    
                    // Send request to update delivery status to in-transit (claimed from warehouse)
                    const formData = new FormData();
                    formData.append('delivery_id', deliveryId);
                    formData.append('status', 'in-transit');
                    formData.append('branch_id', branchId);
                    formData.append('remarks', 'Items claimed from warehouse at ' + new Date().toLocaleString());
                    
                    fetch('update_delivery_status.php', {
                        method: 'POST',
                        body: formData
                    })
<<<<<<< HEAD
                    .then(async response => {
                        const text = await response.text();
                        try { return JSON.parse(text); }
                        catch (e) { throw new Error(text.substring(0, 300) || 'Invalid server response'); }
                    })
=======
                    .then(response => response.json())
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    .then(data => {
                        if (data.success) {
                            // Remove the task card with animation
                            const taskCard = document.getElementById('task_' + deliveryId);
                            if (taskCard) {
                                taskCard.style.transition = 'all 0.3s ease';
                                taskCard.style.opacity = '0';
                                taskCard.style.transform = 'translateX(-20px)';
                                setTimeout(() => {
                                    taskCard.remove();
                                    
                                    // Check if there are no more tasks
                                    const container = document.getElementById('pickupTasksContainer');
                                    if (container && container.children.length === 0) {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'All Items Claimed!',
                                            text: 'You have claimed all warehouse items. You can now start your deliveries.',
                                            confirmButtonColor: '#047857'
                                        }).then(() => {
                                            // Close the modal
                                            const modal = bootstrap.Modal.getInstance(document.getElementById('pickupTasksModal'));
                                            if (modal) {
                                                modal.hide();
                                            }
<<<<<<< HEAD
                                            // Keep the user on this page instead of full reload.
                                            keepCurrentPageAfterSuccess({ modalIds: ['pickupTasksModal'] });
=======
                                            // Reload page to update delivery list
                                            location.reload();
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                        });
                                    } else {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'Items Claimed!',
                                            text: 'You have successfully claimed the items from warehouse.',
                                            timer: 1500,
                                            showConfirmButton: false
                                        });
                                    }
                                }, 300);
                            }
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to claim items. Please try again.',
                                confirmButtonColor: '#dc3545'
                            });
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-check2-circle"></i> Claim Items from Warehouse';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error. Please try again.',
                            confirmButtonColor: '#dc3545'
                        });
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-check2-circle"></i> Claim Items from Warehouse';
                    });
                }
            });
        }
<<<<<<< HEAD

        // ================= DIVERT FUNCTIONALITY =================
        let currentDivertDeliveryId = null;
        
        function openDivertModal(deliveryId, orderNumber, customerName) {
            currentDivertDeliveryId = deliveryId;
            document.getElementById('divertOrderNumber').innerText = orderNumber;
            document.getElementById('divertCustomerName').innerText = customerName;
            
            // Show loading, hide content
            document.getElementById('divertLoading').style.display = 'block';
            document.getElementById('divertContent').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('divertModal'));
            modal.show();
            
            // Fetch available drivers for this delivery
            fetch(window.location.pathname + '?action=get_available_drivers_for_divert&delivery_id=' + encodeURIComponent(deliveryId))
                .then(response => response.json())
                .then(data => {
                    document.getElementById('divertLoading').style.display = 'none';
                    if (data.success && data.drivers.length > 0) {
                        const driversList = document.getElementById('driversList');
                        driversList.innerHTML = '';
                        data.drivers.forEach(driver => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                            btn.innerHTML = `
                                <div>
                                    <strong>${escapeHtml(driver.driver_name)}</strong><br>
                                    <small class="text-muted">${driver.vehicle_plate_number || 'No vehicle'} | ${driver.contact_number || 'No contact'}</small>
                                    <div><small class="text-success"><i class="bi bi-check-circle"></i> ${driver.matching_items} matching product(s)</small></div>
                                </div>
                                <i class="bi bi-arrow-right-circle fs-4 text-primary"></i>
                            `;
                            btn.onclick = () => confirmDivert(deliveryId, driver.driver_id, driver.driver_name);
                            driversList.appendChild(btn);
                        });
                        document.getElementById('divertContent').style.display = 'block';
                    } else {
                        document.getElementById('driversList').innerHTML = `
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                ${data.message || 'No available drivers with matching products found.'}
                            </div>
                        `;
                        document.getElementById('divertContent').style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error fetching drivers:', error);
                    document.getElementById('divertLoading').style.display = 'none';
                    document.getElementById('driversList').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Error loading drivers. Please try again.
                        </div>
                    `;
                    document.getElementById('divertContent').style.display = 'block';
                });
        }
        
        function confirmDivert(deliveryId, targetDriverId, driverName) {
            Swal.fire({
                title: 'Confirm Divert',
                text: `Are you sure you want to divert this partial delivery to ${driverName}? The delivery will appear on that driver page and they need to confirm that they received the diverted item.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Divert'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    const formData = new FormData();
                    formData.append('action', 'divert_delivery');
                    formData.append('delivery_id', deliveryId);
                    formData.append('target_driver_id', targetDriverId);
                    
                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData
                    })
                    .then(async response => {
                        const text = await response.text();
                        try { return JSON.parse(text); }
                        catch (e) { throw new Error(text.substring(0, 300) || 'Invalid server response'); }
                    })
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Diverted Successfully',
                                text: data.message,
                                confirmButtonColor: '#047857'
                            });
                            keepCurrentPageAfterSuccess({ modalIds: ['divertModal'], removeRowSelector: `tr[onclick*="viewDeliveryDetails(${deliveryId})"]` });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Divert Failed',
                                text: data.message,
                                confirmButtonColor: '#dc3545'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error. Please try again.',
                            confirmButtonColor: '#dc3545'
                        });
                    });
                }
            });
        }
        
        function acceptDivertedDelivery(deliveryId, orderNumber) {
            Swal.fire({
                title: 'Receive Diverted Item?',
                text: `Confirm that you received the diverted item for ${orderNumber}.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#047857',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Received'
            }).then((result) => {
                if (!result.isConfirmed) return;

                Swal.fire({
                    title: 'Saving...',
                    text: 'Please wait',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const formData = new FormData();
                formData.append('action', 'accept_diverted_delivery');
                formData.append('delivery_id', deliveryId);

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    const text = await response.text();
                    try { return JSON.parse(text); }
                    catch (e) { throw new Error(text.substring(0, 300) || 'Invalid server response'); }
                })
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Received',
                            text: data.message,
                            confirmButtonColor: '#047857'
                        });
                        keepCurrentPageAfterSuccess({ modalIds: ['viewDetailsModal'], updateStatus: '<span class="badge bg-warning">Pending</span>', deliveryId: deliveryId });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Failed',
                            text: data.message,
                            confirmButtonColor: '#dc3545'
                        });
                    }
                })
                .catch(error => {
                    Swal.close();
                    console.error('Accept diverted error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'Network error. Please try again.',
                        confirmButtonColor: '#dc3545'
                    });
                });
            });
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
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

        // ================= MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            if (!mobileNav) return;
            
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                // Set active state based on current page (excluding logout)
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ================= PROFILE/LOGOUT FUNCTIONS =================
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
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

        // ================= GPS TRACKING FUNCTIONS (Main Shift) =================
        function toggleTracking() {
            if (!navigator.geolocation) {
                Swal.fire('Error', 'Geolocation is not supported by your browser.', 'error');
                return;
            }

            if (trackingActive) {
                // Check for active deliveries before stopping
                const rows = document.querySelectorAll('tbody tr');
                let hasActiveDelivery = false;
                
                rows.forEach(row => {
                    const statusCell = row.cells[5];
                    if (statusCell) {
                        const badge = statusCell.querySelector('.badge');
                        if (badge) {
                            const badgeClass = badge.className;
                            if (badgeClass.includes('bg-primary') || badgeClass.includes('bg-info')) {
                                hasActiveDelivery = true;
                            }
                        }
                    }
                });
                
                if (hasActiveDelivery) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cannot Stop Tracking',
                        text: 'You have active deliveries. Please complete all deliveries first.',
                        confirmButtonColor: '#28a745'
                    });
                    return;
                }
                
                stopTracking();
            } else {
                startTracking();
            }
        }

        function startTracking() {
            updateUI('requesting', 'Starting shift...');
            startShift();
        }

        function startShift() {
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'start_shift',
                    driver_id: currentDriverId,
                    force: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Shift started:', data);
                    updateUI('success', 'Shift started');
                    startGPSTracking();
                } else {
                    console.error('Failed to start shift:', data.error);
                    updateUI('error', 'Shift failed: ' + data.error);
                    
                    if (data.error && data.error.includes('active shift')) {
                        Swal.fire({
                            title: 'Active Shift Found',
                            text: 'You have an active shift. Do you want to end it?',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#dc3545',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: 'Yes, End Shift'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                endExistingShift();
                            }
                        });
                    }
                }
            })
            .catch(error => {
                console.error('Error starting shift:', error);
                updateUI('error', 'Connection error');
            });
        }

        function endExistingShift() {
            updateUI('requesting', 'Ending previous shift...');
            
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'force_end_shift',
                    driver_id: currentDriverId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Previous shift ended:', data);
                    startShift();
                } else {
                    updateUI('error', 'Failed to end shift');
                }
            });
        }

        function startGPSTracking() {
            updateUI('requesting', 'Getting GPS location...');
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    sendLocation(position.coords);
                    startWatching();
                },
                function(error) {
                    console.log('GPS Error:', error.code, error.message);
                    retryCount++;
                    
                    if (retryCount <= 3) {
                        updateUI('retry', 'Retrying GPS... (' + retryCount + '/3)');
                        
                        setTimeout(function() {
                            startGPSTracking();
                        }, 2000);
                    } else {
                        updateUI('error', 'GPS Error: ' + getErrorMessage(error));
                        retryCount = 0;
                    }
                },
                {
                    enableHighAccuracy: false,
                    timeout: 10000,
                    maximumAge: 60000
                }
            );
        }

        function getErrorMessage(error) {
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    return 'Please enable location permissions';
                case error.POSITION_UNAVAILABLE:
                    return 'Location unavailable - check GPS';
                case error.TIMEOUT:
                    return 'GPS timeout - try again';
                default:
                    return error.message;
            }
        }

        function updateUI(status, message) {
            let indicator = document.getElementById('locationIndicator');
            let statusSpan = document.getElementById('locationStatus');
            let updateSpan = document.getElementById('updateCount');
            
            switch(status) {
                case 'requesting':
                    indicator.className = 'badge bg-warning';
                    statusSpan.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + message;
                    break;
                case 'retry':
                    indicator.className = 'badge bg-info';
                    statusSpan.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>' + message;
                    break;
                case 'success':
                    indicator.className = 'badge bg-success';
                    statusSpan.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + message;
                    break;
                case 'error':
                    indicator.className = 'badge bg-danger';
                    statusSpan.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' + message;
                    break;
                default:
                    indicator.className = 'badge bg-secondary';
                    statusSpan.innerHTML = message || 'Offline';
            }
            
            if (updateSpan) {
                updateSpan.innerHTML = updateCount > 0 ? '(' + updateCount + ')' : '';
            }
        }

        function startWatching() {
            if (watchId) return;

            watchId = navigator.geolocation.watchPosition(
                function(position) {
                    sendLocation(position.coords);
                    updateCount++;
                    
                    updateUI('success', 'LIVE');
                },
                function(error) {
                    console.log('Watch error:', error.message);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 10000
                }
            );

            trackingActive = true;
            
        }

        function stopTracking() {
            if (watchId) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'end_shift',
                    driver_id: currentDriverId
                })
            })
            .then(response => response.json())
            .then(data => {
                console.log('Shift ended:', data);
            })
            .catch(error => {
                console.error('Error ending shift:', error);
            });
            
            trackingActive = false;
            updateCount = 0;
            retryCount = 0;
            
            let btn = document.getElementById('trackingBtn');
            if (btn) {
                btn.innerHTML = '<i class="bi bi-play-circle"></i> Start Tracking';
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-success');
            }
            
            updateUI('offline', 'Offline');
        }

        function sendLocation(coords) {
            let data = {
                action: 'update_location',
                driver_id: currentDriverId,
                latitude: coords.latitude,
                longitude: coords.longitude,
                accuracy: coords.accuracy,
                speed: coords.speed || 0,
                heading: coords.heading || 0,
                timestamp: new Date().toISOString()
            };

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(result => {
                if (!result.success) {
                    console.log('Location update failed:', result.error);
                }
            })
            .catch(error => {
                if (error.name === 'AbortError') {
                    console.log('Location update timeout');
                } else {
                    console.log('Location update error:', error.message);
                }
            });
        }

        // ================= LIVE NAVIGATION FUNCTIONS WITH MODERN GREEN TRUCK ICON =================
        function openLiveNavigation(destLat, destLng, customerName, address) {
            // Check if browser supports geolocation
            if (!navigator.geolocation) {
                Swal.fire('Error', 'Geolocation is not supported by your browser.', 'error');
                return;
            }

            // Store destination
            destinationPosition = { lat: parseFloat(destLat), lng: parseFloat(destLng) };

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('trackingModal'));
            modal.show();

            // Update destination info immediately
            document.getElementById('destinationText').textContent = customerName || 'Customer Location';
            document.getElementById('destinationCoordinates').textContent = 
                `${destinationPosition.lat.toFixed(6)}, ${destinationPosition.lng.toFixed(6)}`;

            // Initialize map immediately
            setTimeout(() => {
                initLiveTrackingMap(destinationPosition.lat, destinationPosition.lng, customerName, address);
                // Start GPS acquisition
                startFastGPSAcquisition();
            }, 300);
        }

        function initLiveTrackingMap(destLat, destLng, customerName, address) {
            // Remove existing map if any
            if (liveTrackingMap) {
                liveTrackingMap.remove();
                if (glowLine) {
                    glowLine = null;
                }
            }

            // Create map with mobile-friendly options
            liveTrackingMap = L.map('trackingMap', {
                zoomControl: false
            }).setView([destLat, destLng], 13);

            // Add zoom control in top-right for better mobile access
            L.control.zoom({
                position: 'topright'
            }).addTo(liveTrackingMap);

            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(liveTrackingMap);

            // Add destination marker (red) with location icon
            const destIconSize = window.innerWidth <= 768 ? 40 : 32;
            destinationMarker = L.marker([destLat, destLng], {
                icon: L.divIcon({
                    className: 'custom-destination-icon',
                    html: '<div style="background-color: #dc3545; width: 28px; height: 28px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="bi bi-geo-alt-fill" style="color: white; font-size: 14px;"></i></div>',
                    iconSize: [destIconSize, destIconSize],
                    iconAnchor: [destIconSize/2, destIconSize/2]
                })
            }).addTo(liveTrackingMap);
            
            destinationMarker.bindPopup(`
                <b>${customerName || 'Customer'}</b><br>
                ${address || 'Destination'}<br>
                <small>${destLat.toFixed(6)}, ${destLng.toFixed(6)}</small>
            `).openPopup();

            // ========== DELIVERY TRUCK ICON (Bootstrap Icons SVG) ==========
            const truckIconSize = window.innerWidth <= 768 ? 64 : 60;
            
            // Use divIcon with inline SVG from Bootstrap Icons
            const truckIcon = L.divIcon({
                className: 'modern-truck-icon',
                html: `
                    <div style="display: flex; align-items: center; justify-content: center; width: ${truckIconSize}px; height: ${truckIconSize}px; filter: drop-shadow(2px 2px 3px rgba(0,0,0,0.3));">
                        <svg width="${truckIconSize}" height="${truckIconSize}" viewBox="0 0 100 80" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="truckGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                    <stop offset="0%" style="stop-color:#4ade80;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#22c55e;stop-opacity:1" />
                                </linearGradient>
                                <linearGradient id="cabGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                    <stop offset="0%" style="stop-color:#16a34a;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#15803d;stop-opacity:1" />
                                </linearGradient>
                            </defs>
                            
                            <!-- Shadow on ground -->
                            <ellipse cx="50" cy="75" rx="35" ry="5" fill="rgba(0,0,0,0.1)"/>
                            
                            <!-- Main cargo body (3D effect) -->
                            <g>
                                <!-- Right side darker for 3D -->
                                <polygon points="35,30 60,25 60,50 35,55" fill="#1b7f3d" opacity="0.8"/>
                                <!-- Top of cargo -->
                                <polygon points="35,30 60,25 75,35 50,40" fill="#22c55e"/>
                                <!-- Front of cargo -->
                                <rect x="35" y="30" width="25" height="25" fill="url(#truckGrad)"/>
                                <!-- Back with shading -->
                                <polygon points="50,40 75,35 75,60 50,55" fill="#1b7f3d" opacity="0.6"/>
                            </g>
                            
                            <!-- Cabin (3D cube-like) -->
                            <g>
                                <!-- Cabin top (3D perspective) -->
                                <polygon points="15,35 30,32 30,48 15,50" fill="#0d6435"/>
                                <!-- Cabin front -->
                                <rect x="15" y="35" width="15" height="20" fill="url(#cabGrad)"/>
                                <!-- Window with reflection -->
                                <rect x="18" y="38" width="9" height="10" fill="#60a5fa" opacity="0.8"/>
                                <line x1="18" y1="40" x2="27" y2="40" stroke="#93c5fd" stroke-width="0.8" opacity="0.6"/>
                                <!-- Cabin right side -->
                                <polygon points="30,32 30,48 35,50 35,35" fill="#0d6435" opacity="0.7"/>
                            </g>
                            
                            <!-- Wheels -->
                            <!-- Front wheel -->
                            <circle cx="25" cy="60" r="8" fill="#1f2937"/>
                            <circle cx="25" cy="60" r="5" fill="#4b5563"/>
                            <circle cx="25" cy="60" r="2" fill="#9ca3af"/>
                            
                            <!-- Back wheels -->
                            <circle cx="55" cy="62" r="8" fill="#1f2937"/>
                            <circle cx="55" cy="62" r="5" fill="#4b5563"/>
                            <circle cx="55" cy="62" r="2" fill="#9ca3af"/>
                            
                            <circle cx="65" cy="62" r="8" fill="#1f2937"/>
                            <circle cx="65" cy="62" r="5" fill="#4b5563"/>
                            <circle cx="65" cy="62" r="2" fill="#9ca3af"/>
                            
                            <!-- Bumper detail -->
                            <rect x="12" y="54" width="3" height="6" fill="#374151"/>
                        </svg>
                    </div>
                `,
                iconSize: [truckIconSize, truckIconSize],
                iconAnchor: [truckIconSize/2, truckIconSize/2],
                popupAnchor: [0, -truckIconSize/2]
            });
            
            // Add user marker with modern green truck icon (no blue circle)
            userMarker = L.marker([destLat, destLng], {
                icon: truckIcon
            }).addTo(liveTrackingMap);
            
            userMarker.bindPopup('<b>Delivery Vehicle</b><br>Waiting for GPS...');

            // NO ACCURACY CIRCLE - REMOVED
            
            // On mobile, ensure panel starts expanded and properly positioned
            if (window.innerWidth <= 768) {
                const panel = document.getElementById('navigationStatusPanel');
                if (panel) {
                    panel.classList.remove('collapsed');
                    
                    const toggleBtn = document.getElementById('toggleStatusPanel');
                    if (toggleBtn) {
                        const icon = toggleBtn.querySelector('i');
                        icon.classList.remove('bi-chevron-down');
                        icon.classList.add('bi-chevron-up');
                    }
                    
                    panel.style.top = 'auto';
                    panel.style.bottom = '0';
                    panel.style.left = '0';
                    panel.style.right = '0';
                    panel.style.width = '100%';
                    panel.style.maxHeight = '60vh';
                }
            }
            
            // Force map resize after panel is positioned
            setTimeout(() => {
                if (liveTrackingMap) {
                    liveTrackingMap.invalidateSize();
                }
            }, 300);
        }

        // Fast GPS acquisition
        function startFastGPSAcquisition() {
            // Use cached position if fresh (<= 30 seconds)
            if (lastKnownPosition && (Date.now() - lastKnownPosition.timestamp < 30000)) {
                console.log('Using cached position');
                livePositionSuccess({
                    coords: {
                        latitude: lastKnownPosition.lat,
                        longitude: lastKnownPosition.lng,
                        accuracy: lastKnownPosition.accuracy || 50
                    }
                });
            }

            // Try to get fast low-accuracy fix
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Save to cache
                    lastKnownPosition = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        timestamp: Date.now()
                    };
                    livePositionSuccess(position);
                    // Start high accuracy watching
                    startLiveWatching();
                },
                function(error) {
                    console.log('Fast GPS error:', error);
                    // If no fix, try high accuracy watch
                    startLiveWatching();
                },
                {
                    enableHighAccuracy: false,
                    timeout: 5000,
                    maximumAge: 30000
                }
            );
        }

        function startLiveWatching() {
            // Clear any existing watch
            if (watchPositionId) {
                navigator.geolocation.clearWatch(watchPositionId);
            }
            
            // Options for high accuracy (continuous tracking)
            const options = {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 5000
            };

            watchPositionId = navigator.geolocation.watchPosition(
                livePositionSuccess,
                livePositionError,
                options
            );
        }

        function livePositionSuccess(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const accuracy = position.coords.accuracy;

            currentPosition = { lat, lng, accuracy };

            // Update cache
            lastKnownPosition = {
                lat: lat,
                lng: lng,
                accuracy: accuracy,
                timestamp: Date.now()
            };

            // Update user marker position (green truck)
            if (userMarker) {
                userMarker.setLatLng([lat, lng]);
                
                // Get speed if available
                const speed = position.coords.speed ? (position.coords.speed * 3.6).toFixed(1) : null;
                const speedText = speed ? `<br><small>🚚 Speed: ${speed} km/h</small>` : '';
                
                if (window.innerWidth <= 768) {
                    userMarker.setPopupContent(`
                        <b>Delivery Vehicle</b><br>
                        <small>${accuracy.toFixed(0)}m accuracy</small>
                        ${speedText}
                    `);
                } else {
                    userMarker.setPopupContent(`
                        <b>Delivery Vehicle</b><br>
                        <small>${lat.toFixed(6)}, ${lng.toFixed(6)}</small><br>
                        ${speedText}
                    `);
                }
            }

            // Update UI
            const isMobile = window.innerWidth <= 768;
            const locationText = isMobile ? '🚛 Driver' : 'Driver Location:';
            document.getElementById('yourLocationText').innerHTML = `<i class="bi bi-check-circle-fill text-success me-1"></i> ${locationText}`;
            document.getElementById('yourCoordinates').innerHTML = isMobile ? 
                `${lat.toFixed(5)}, ${lng.toFixed(5)}` : 
                `${lat.toFixed(6)}, ${lng.toFixed(6)}`;

            // Update or create route with BRIGHT BLUE color
            if (destinationPosition) {
                if (!routingControl) {
                    // Create route for the first time with BRIGHT BLUE color
                    routingControl = L.Routing.control({
                        waypoints: [
                            L.latLng(lat, lng),
                            L.latLng(destinationPosition.lat, destinationPosition.lng)
                        ],
                        routeWhileDragging: false,
                        showAlternatives: false,
                        fitSelectedRoutes: false,
                        lineOptions: {
                            styles: [
                                { 
                                    color: '#007bff',
                                    opacity: 0.95, 
                                    weight: 7,
                                    lineJoin: 'round',
                                    lineCap: 'round'
                                }
                            ],
                            extendToWaypoints: true,
                            missingRouteTolerance: 0
                        },
                        createMarker: function() { return null; },
                        show: false,
                        addWaypoints: false,
                        draggableWaypoints: false,
                        fitSelectedRoutes: false,
                        showAlternatives: false,
                        collapsible: false,
                        zoomToBounds: false
                    }).addTo(liveTrackingMap);
                    
                    // Add glow effect
                    routingControl.on('routesfound', function(e) {
                        const routes = e.routes;
                        const summary = routes[0].summary;
                        
                        // Add a glow effect (thicker semi-transparent line underneath)
                        const coordinates = routes[0].coordinates;
                        if (coordinates && coordinates.length > 0) {
                            if (glowLine && liveTrackingMap) {
                                liveTrackingMap.removeLayer(glowLine);
                            }
                            glowLine = L.polyline(coordinates, {
                                color: '#80bfff',
                                opacity: 0.4,
                                weight: 14,
                                lineJoin: 'round',
                                lineCap: 'round'
                            }).addTo(liveTrackingMap);
                        }

                        // Update distance and time
                        const distance = (summary.totalDistance / 1000).toFixed(2);
                        const time = Math.round(summary.totalTime / 60);

                        document.getElementById('distanceText').textContent = distance;
                        document.getElementById('timeText').textContent = time;
                    });
                } else {
                    // Update waypoints for existing route
                    routingControl.setWaypoints([
                        L.latLng(lat, lng),
                        L.latLng(destinationPosition.lat, destinationPosition.lng)
                    ]);
                }
            }

            // Update accuracy bar and text (for GPS accuracy panel)
            const accuracyPercent = Math.max(0, Math.min(100, 100 - (accuracy / 10)));
            document.getElementById('accuracyBar').style.width = accuracyPercent + '%';

            let accuracyClass = 'bg-success';
            let accuracyText = 'Excellent';
            
            if (accuracy < 10) {
                accuracyClass = 'bg-success';
                accuracyText = 'Excellent';
            } else if (accuracy < 30) {
                accuracyClass = 'bg-info';
                accuracyText = 'Good';
            } else if (accuracy < 100) {
                accuracyClass = 'bg-warning';
                accuracyText = 'Fair';
            } else {
                accuracyClass = 'bg-danger';
                accuracyText = 'Poor';
            }
            
            document.getElementById('accuracyBar').className = 'progress-bar ' + accuracyClass;
            document.getElementById('accuracyText').innerHTML = `<i class="bi bi-${accuracyClass === 'bg-success' ? 'check-circle' : accuracyClass === 'bg-info' ? 'info-circle' : accuracyClass === 'bg-warning' ? 'exclamation-triangle' : 'x-circle'} me-1"></i> ${accuracyText}`;
        }

        function livePositionError(error) {
            // Update UI with retry option
            document.getElementById('yourLocationText').innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Acquiring GPS...';
            document.getElementById('yourCoordinates').innerHTML = '--';
            document.getElementById('accuracyBar').className = 'progress-bar bg-warning';
            document.getElementById('accuracyText').innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Acquiring GPS signal...';
        }

        function retryGPSTracking() {
            if (watchPositionId) {
                navigator.geolocation.clearWatch(watchPositionId);
            }
            
            document.getElementById('yourLocationText').innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Retrying GPS...';
            document.getElementById('yourCoordinates').innerHTML = '--';
            document.getElementById('accuracyBar').className = 'progress-bar bg-info';
            document.getElementById('accuracyText').innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Retrying...';
            
            // Restart fast acquisition
            startFastGPSAcquisition();
        }

        function stopLiveNavigation() {
            // Check for active deliveries before stopping navigation
            const rows = document.querySelectorAll('tbody tr');
            let hasActiveDelivery = false;
            rows.forEach(row => {
                const statusCell = row.cells[5];
                if (statusCell) {
                    const badge = statusCell.querySelector('.badge');
                    if (badge) {
                        const badgeClass = badge.className;
                        if (badgeClass.includes('bg-primary') || badgeClass.includes('bg-info')) {
                            hasActiveDelivery = true;
                        }
                    }
                }
            });

            if (hasActiveDelivery) {
                Swal.fire({
                    icon: 'error',
                    title: 'Cannot Stop Navigation',
                    text: 'You have active deliveries. Please complete them before stopping navigation.',
                    confirmButtonColor: '#28a745'
                });
                return;
            }

            // Clear watch position
            if (watchPositionId) {
                navigator.geolocation.clearWatch(watchPositionId);
                watchPositionId = null;
            }

            // Clear any pending retry timeout
            if (gpsRetryTimeout) {
                clearTimeout(gpsRetryTimeout);
                gpsRetryTimeout = null;
            }

            // Remove routing control
            if (routingControl && liveTrackingMap) {
                liveTrackingMap.removeControl(routingControl);
                routingControl = null;
            }
            
            // Remove glow line
            if (glowLine && liveTrackingMap) {
                liveTrackingMap.removeLayer(glowLine);
                glowLine = null;
            }

            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('trackingModal'));
            if (modal) {
                modal.hide();
            }
        }

        function confirmStopLiveNavigation() {
            Swal.fire({
                title: 'Stop Navigation?',
                text: 'Are you sure you want to stop navigating?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Stop'
            }).then((result) => {
                if (result.isConfirmed) {
                    stopLiveNavigation();
                }
            });
        }

        function centerOnYourLocation() {
            if (currentPosition && liveTrackingMap) {
                liveTrackingMap.setView([currentPosition.lat, currentPosition.lng], 16);
            } else {
                retryGPSTracking();
            }
        }

        function openGoogleMaps() {
            if (currentPosition && destinationPosition) {
                const url = `https://www.google.com/maps/dir/${currentPosition.lat},${currentPosition.lng}/${destinationPosition.lat},${destinationPosition.lng}`;
                window.open(url, '_blank');
            } else if (destinationPosition) {
                const url = `https://www.google.com/maps?q=${destinationPosition.lat},${destinationPosition.lng}`;
                window.open(url, '_blank');
            }
        }

        // ================= TOUCH-FRIENDLY PANEL TOGGLE FOR MOBILE =================
        function initMobilePanelToggle() {
            const panel = document.getElementById('navigationStatusPanel');
            const header = panel ? panel.querySelector('h6') : null;
            
            if (panel && header && window.innerWidth <= 768) {
                header.removeEventListener('click', handlePanelHeaderClick);
                header.addEventListener('click', handlePanelHeaderClick);
            }
        }

        function handlePanelHeaderClick(e) {
            if (window.innerWidth <= 768 && !e.target.closest('.toggle-panel-btn')) {
                const toggleBtn = document.getElementById('toggleStatusPanel');
                if (toggleBtn) {
                    toggleBtn.click();
                }
            }
        }

        // ================= DELIVERY FUNCTIONS WITH GPS VALIDATION =================

        function updateDeliveryStatus(deliveryId, newStatus) {
            if (newStatus === 'in-transit') {
                // Check if GPS tracking is active
                if (!trackingActive) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'GPS Tracking Required',
                        text: 'Please start GPS tracking before starting delivery.',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'Start Tracking Now'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            toggleTracking();
                        }
                    });
                    return;
                }
                
                Swal.fire({
                    title: 'Start Delivery?',
                    text: 'Make sure you have loaded all items for this delivery.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Start Delivery'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData();
                        formData.append('delivery_id', deliveryId);
                        formData.append('status', newStatus);
                        formData.append('branch_id', branchId);
                        
                        Swal.fire({
                            title: 'Starting Delivery...',
                            text: 'Please wait',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        fetch('update_delivery_status.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            Swal.close();
                            
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Delivery Started!',
                                    text: 'You are now on your way.',
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                                keepCurrentPageAfterSuccess({ updateStatus: '<span class="badge bg-primary">In Transit</span>', deliveryId: deliveryId });
                            } else {
                                Swal.fire('Error', data.message || 'Failed to start delivery', 'error');
                            }
                        })
                        .catch(error => {
                            Swal.close();
                            console.error('Error:', error);
                            Swal.fire('Error', 'Error updating status', 'error');
                        });
                    }
                });
            } else if (newStatus === 'partial') {
                // Check if GPS tracking is active
                if (!trackingActive) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'GPS Tracking Required',
                        text: 'Please enable GPS tracking to record partial delivery.',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'Start Tracking Now'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            toggleTracking();
                        }
                    });
                    return;
                }
                
                // Load items for partial delivery
                loadItemsForPartial(deliveryId);
            }
        }

        function showDeliveryModal(deliveryId, soId, orderNumber, totalAmount) {
            // Check if GPS tracking is active
            if (!trackingActive) {
                Swal.fire({
                    icon: 'warning',
                    title: 'GPS Tracking Required',
                    text: 'Please keep GPS tracking active to complete delivery.',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Start Tracking Now'
                }).then((result) => {
                    if (result.isConfirmed) {
                        toggleTracking();
                    }
                });
                return;
            }
            
            currentDeliveryId = deliveryId;
            currentSoId = soId;
            currentOrderNumber = orderNumber;
            
            document.getElementById('orderIdDisplay').textContent = orderNumber;
            document.getElementById('modalDeliveryId').value = deliveryId;
            document.getElementById('modalSoId').value = soId;
            document.getElementById('modalSoNumber').value = orderNumber;
            document.getElementById('deliveryForm').reset();
            const safeAmount = parseFloat(totalAmount || 0);
            document.getElementById('deliveryAmountDueDisplay').textContent = '₱' + safeAmount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('deliveryPaymentAmount').value = safeAmount.toFixed(2);
            document.getElementById('deliveryPaymentSection').style.display = 'none';
            document.getElementById('deliveryCashFields').style.display = 'flex';
            document.getElementById('deliveryCheckFields').style.display = 'none';
            document.getElementById('deliveryTransferFields').style.display = 'none';
            const attachmentFields = document.getElementById('deliveryAttachmentFields');
            const paymentAttachments = document.getElementById('deliveryPaymentAttachments');
            if (attachmentFields) attachmentFields.style.display = 'none';
            if (paymentAttachments) { paymentAttachments.value = ''; paymentAttachments.required = false; }
            document.getElementById('deliveryCashChange').value = '₱0.00';
            
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            document.querySelector('input[name="delivery_date"]').value = `${year}-${month}-${day}T${hours}:${minutes}`;
            
            const modal = new bootstrap.Modal(document.getElementById('deliveryModal'));
            modal.show();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const collectToggle = document.getElementById('collectPaymentToggle');
            const paymentSection = document.getElementById('deliveryPaymentSection');
            const paymentMethod = document.getElementById('deliveryPaymentMethod');
            const cashFields = document.getElementById('deliveryCashFields');
            const checkFields = document.getElementById('deliveryCheckFields');
            const transferFields = document.getElementById('deliveryTransferFields');
            const paymentAmount = document.getElementById('deliveryPaymentAmount');
            const cashTendered = document.getElementById('deliveryCashTendered');
            const cashChange = document.getElementById('deliveryCashChange');
            const attachmentFields = document.getElementById('deliveryAttachmentFields');
            const paymentAttachments = document.getElementById('deliveryPaymentAttachments');

            function updatePaymentVisibility() {
                if (!paymentSection) return;
                paymentSection.style.display = collectToggle && collectToggle.checked ? 'block' : 'none';
                const method = paymentMethod ? paymentMethod.value : 'cash';
                if (cashFields) cashFields.style.display = method === 'cash' ? 'flex' : 'none';
                if (checkFields) checkFields.style.display = method === 'check' ? 'block' : 'none';
                if (transferFields) transferFields.style.display = method === 'online_transfer' ? 'block' : 'none';
                if (attachmentFields) attachmentFields.style.display = (method === 'check' || method === 'online_transfer') ? 'block' : 'none';
                if (paymentAttachments) paymentAttachments.required = collectToggle && collectToggle.checked && (method === 'check' || method === 'online_transfer');
            }

            function updateCashChange() {
                if (!cashTendered || !paymentAmount || !cashChange) return;
                const tendered = parseFloat(cashTendered.value || 0);
                const amount = parseFloat(paymentAmount.value || 0);
                const change = Math.max(0, tendered - amount);
                cashChange.value = '₱' + change.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }

            if (collectToggle) collectToggle.addEventListener('change', updatePaymentVisibility);
            if (paymentMethod) paymentMethod.addEventListener('change', updatePaymentVisibility);
            if (cashTendered) cashTendered.addEventListener('input', updateCashChange);
            updatePaymentVisibility();
            updateCashChange();
        });

        function loadItemsForPartial(deliveryId) {
            currentPartialDeliveryId = deliveryId;
            
            fetch('get_delivery_items.php?delivery_id=' + deliveryId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentItems = data.items;
                        displayItemsForPartial();
                        
                        const modal = new bootstrap.Modal(document.getElementById('partialModal'));
                        modal.show();
                    } else {
                        Swal.fire('Error', 'Error loading items: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error loading items', 'error');
                });
        }

        function displayItemsForPartial() {
            const itemsDiv = document.getElementById('itemsList');
            let html = '';
            
            currentItems.forEach((item, index) => {
                html += `
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="${item.item_id}" id="item_${index}" checked>
                        <label class="form-check-label" for="item_${index}">
                            ${item.item_name} - Qty: ${item.quantity} - ₱${item.price}
                        </label>
                    </div>
                `;
            });
            
            itemsDiv.innerHTML = html;
        }

        function submitPartialDelivery() {
            // Check if GPS tracking is still active
            if (!trackingActive) {
                Swal.fire({
                    icon: 'error',
                    title: 'GPS Tracking Stopped',
                    text: 'GPS tracking was turned off. Please restart tracking to record partial delivery.',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Restart Tracking'
                }).then((result) => {
                    if (result.isConfirmed) {
                        toggleTracking();
                    }
                });
                return;
            }
            
            const reason = document.getElementById('partialReason').value;
            let details = document.getElementById('partialDetails').value;
            
            if (!reason) {
                Swal.fire('Warning', 'Please select a reason', 'warning');
                return;
            }
            
            let finalReason = reason;
            if (reason === 'Other') {
                const otherReason = document.getElementById('otherReason').value;
                if (!otherReason) {
                    Swal.fire('Warning', 'Please specify the reason', 'warning');
                    return;
                }
                finalReason = otherReason;
            }
            
            // Get selected items
            const checkboxes = document.querySelectorAll('#itemsList input[type="checkbox"]:checked');
            if (checkboxes.length === 0) {
                Swal.fire('Warning', 'Please select at least one item that was delivered', 'warning');
                return;
            }
            
            const deliveredItems = Array.from(checkboxes).map(cb => cb.value);
            
            if (details) {
                finalReason += ' - ' + details;
            }
            
            finalReason += ` [Delivered items: ${checkboxes.length} of ${currentItems.length}]`;
            
            Swal.fire({
                title: 'Submitting Partial Delivery...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('delivery_id', currentPartialDeliveryId);
            formData.append('status', 'partial');
            formData.append('remarks', finalReason);
            formData.append('branch_id', branchId);
            
            fetch('update_delivery_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('partialModal'));
                    modal.hide();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Partial Delivery Recorded',
                        text: 'You can complete the remaining items later.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    keepCurrentPageAfterSuccess({ modalIds: ['partialModal'], updateStatus: '<span class="badge bg-info">Partial</span>', deliveryId: currentPartialDeliveryId });
                } else {
                    Swal.fire('Error', data.message || 'Failed to update status', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                console.error('Error:', error);
                Swal.fire('Error', 'Error updating status', 'error');
            });
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDivertStatus(status) {
            if (status === 'received') return 'Received by target driver';
            if (status === 'pending') return 'Waiting for target driver confirmation';
            return status || 'Pending';
        }

        function viewDeliveryDetails(deliveryId) {
            const modalBody = document.getElementById('viewDetailsModalBody');
            modalBody.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Loading delivery details...</p>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
            modal.show();
            
            fetch('get_delivery_details.php?delivery_id=' + deliveryId)
                .then(response => response.text())
                .then(data => {
                    modalBody.innerHTML = data;

                    const divertInfo = deliveryDivertInfo[deliveryId];
                    if (divertInfo) {
                        const existingDivertBox = modalBody.querySelector('.divert-details-box');
                        if (!existingDivertBox) {
                            const remarksTitle = Array.from(modalBody.querySelectorAll('h1,h2,h3,h4,h5,h6,strong,label')).find(el =>
                                (el.textContent || '').toLowerCase().includes('remarks')
                            );
                            const divertBox = document.createElement('div');
                            divertBox.className = 'alert alert-warning divert-details-box mt-3';
                            const receiveStatusText = divertInfo.status === 'received'
                                ? `Received${divertInfo.received_at ? ' on ' + escapeHtml(divertInfo.received_at) : ''}`
                                : 'Waiting for receiving driver confirmation';

                            divertBox.innerHTML = `
                                <div class="fw-bold mb-2"><i class="bi bi-share-fill"></i> Diverted Item Details</div>
                                <div><strong>Diverted from:</strong> ${escapeHtml(divertInfo.from)}</div>
                                <div><strong>Diverted to:</strong> ${escapeHtml(divertInfo.to)}</div>
                                <div><strong>Date diverted:</strong> ${escapeHtml(divertInfo.date || 'Not recorded')}</div>
                                <div><strong>Receive status / confirmation:</strong> ${receiveStatusText}</div>
                            `;
                            if (remarksTitle && remarksTitle.parentElement) {
                                remarksTitle.parentElement.appendChild(divertBox);
                            } else {
                                modalBody.appendChild(divertBox);
                            }
                        }
                    }
                    
                    document.querySelectorAll('.view-photo-btn').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            const photoUrl = this.getAttribute('data-photo');
                            showPhotoModal(photoUrl);
                        });
                    });
                    
                    // Extract customer phone number from the loaded content
                    setTimeout(() => {
                        addContactButtonsToModal();
                    }, 100);
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `
                        <div class="alert alert-danger m-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            Error loading delivery details. Please try again.
                        </div>
                    `;
                });
        }
        
        // Function to add contact buttons to the delivery details modal (RIGHT ALIGNED)
        function addContactButtonsToModal() {
            // Check if contact buttons already exist
            if (document.querySelector('#viewDetailsModalBody .contact-buttons')) {
                return;
            }
            
            // Find the phone number in the modal content
            let phoneNumber = null;
            let customerName = null;
            
            // Try to find phone number in various places
            const modalContent = document.getElementById('viewDetailsModalBody');
            if (modalContent) {
                // Look for phone number in the content
                const phoneRegex = /(0|\+63)[0-9]{10,11}/;
                const text = modalContent.innerText;
                const match = text.match(phoneRegex);
                if (match) {
                    phoneNumber = match[0];
                }
                
                // Look for customer name (usually in a heading or strong tag)
                const nameElement = modalContent.querySelector('h4, h5, strong, .customer-name');
                if (nameElement) {
                    customerName = nameElement.innerText.trim();
                } else {
                    // Try to get from the first line
                    const firstLine = modalContent.innerText.split('\n')[0];
                    if (firstLine && !firstLine.includes('Delivery ID')) {
                        customerName = firstLine.substring(0, 50).trim();
                    }
                }
            }
            
            // If no phone number found, don't add buttons
            if (!phoneNumber) {
                console.log('No phone number found in delivery details');
                return;
            }
            
            // Clean up phone number
            phoneNumber = phoneNumber.replace(/\D/g, '');
            if (phoneNumber.length === 10 && phoneNumber.startsWith('0')) {
                phoneNumber = '+63' + phoneNumber.substring(1);
            } else if (phoneNumber.length === 10) {
                phoneNumber = '+63' + phoneNumber;
            } else if (phoneNumber.length === 11 && phoneNumber.startsWith('0')) {
                phoneNumber = '+63' + phoneNumber.substring(1);
            }
            
            // Create contact buttons section with right alignment
            const contactSection = document.createElement('div');
            contactSection.className = 'contact-buttons';
            contactSection.innerHTML = `
                <div class="d-flex gap-2 justify-content-end">
                    <button class="btn btn-call btn-contact" onclick="makePhoneCall('${phoneNumber}')">
                        <i class="bi bi-telephone-fill"></i> Call
                    </button>
                    <button class="btn btn-message btn-contact" onclick="sendTextMessage('${phoneNumber}', '${customerName ? customerName.replace(/'/g, "\\'") : 'Customer'}')">
                        <i class="bi bi-chat-dots-fill"></i> Message
                    </button>
                </div>
            `;
            
            // Find the best position to insert the buttons (after the delivery info, before the footer)
            const modalBody = document.getElementById('viewDetailsModalBody');
            const hrElements = modalBody.querySelectorAll('hr');
            
            if (hrElements.length > 0) {
                // Insert after the last hr
                hrElements[hrElements.length - 1].after(contactSection);
            } else {
                // Find a good position (after customer info)
                const addressElement = modalBody.querySelector('p i.bi-geo-alt, .address, .customer-address');
                if (addressElement) {
                    const parent = addressElement.closest('p, div');
                    if (parent && parent.parentNode) {
                        parent.after(contactSection);
                    } else {
                        modalBody.appendChild(contactSection);
                    }
                } else {
                    modalBody.appendChild(contactSection);
                }
            }
        }
        
        // Function to make a phone call
        function makePhoneCall(phoneNumber) {
            if (!phoneNumber || phoneNumber === 'N/A') {
                Swal.fire('Info', 'No phone number available for this customer.', 'info');
                return;
            }
            
            Swal.fire({
                title: 'Call Customer',
                text: `Call ${phoneNumber}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Call',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `tel:${phoneNumber}`;
                }
            });
        }
        
        // Function to send a text message
        function sendTextMessage(phoneNumber, customerName) {
            if (!phoneNumber || phoneNumber === 'N/A') {
                Swal.fire('Info', 'No phone number available for this customer.', 'info');
                return;
            }
            
            Swal.fire({
                title: 'Send Message',
                text: `Send SMS to ${phoneNumber}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#17a2b8',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Send',
                cancelButtonText: 'Cancel',
                input: 'textarea',
                inputLabel: 'Message',
                inputPlaceholder: 'Type your message here...',
                inputValue: `Hello ${customerName}, this is your delivery driver. I'm on my way with your order.`,
                preConfirm: (message) => {
                    if (!message || message.trim() === '') {
                        Swal.showValidationMessage('Please enter a message');
                        return false;
                    }
                    return message;
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    // For mobile devices, open SMS app with pre-filled message
                    const encodedMessage = encodeURIComponent(result.value);
                    window.location.href = `sms:${phoneNumber}?body=${encodedMessage}`;
                    
                    // Also log to console for debugging
                    console.log(`Sending SMS to ${phoneNumber}: ${result.value}`);
                }
            });
        }

        function showPhotoModal(photoUrl) {
            const modalImg = document.getElementById('photoModalImg');
            const downloadBtn = document.getElementById('downloadPhotoBtn');
            
            modalImg.src = photoUrl;
            if (downloadBtn) downloadBtn.href = photoUrl;
            
            const modal = new bootstrap.Modal(document.getElementById('photoModal'));
            modal.show();
        }

        function generateThermalReceipt(deliveryId, soNumber, siNumber, customerName, address, signedBy, deliveryDate, itemsRaw) {
            const date = new Date(deliveryDate);
            const formattedDate = date.toLocaleString('en-PH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });

<<<<<<< HEAD
            const cleanSINumber = (siNumber || '').toString().trim();
            const formattedSINumber = cleanSINumber ? (cleanSINumber.toUpperCase().startsWith('SI:') ? cleanSINumber : 'SI:' + cleanSINumber) : '';
            const siReceiptLine = formattedSINumber ? `<div class="info-line"><span class="info-label"></span><span class="info-value"> ${formattedSINumber}</span></div>` : '';

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            // Use delivery date for receipt header
            const receiptNumber = 'DR' + date.getFullYear() +
                                 String(date.getMonth() + 1).padStart(2, '0') +
                                 String(date.getDate()).padStart(2, '0') +
                                 String(deliveryId).padStart(4, '0');

            // Parse items from itemsRaw (format: "qty x item - ₱price||...")
            const items = itemsRaw ? itemsRaw.split('||') : [];
            let itemsHtml = '';
            let total = 0;

            if (items.length === 0) {
                itemsHtml = '<tr><td colspan="4" style="text-align: center; padding: 8px;">No items</td> </tr>';
            } else {
                items.forEach(item => {
                    const parts = item.split(' x ');
                    if (parts.length === 2) {
                        const qtyPrice = parts[1].split(' - ₱');
                        if (qtyPrice.length === 2) {
                            const qty = parseInt(parts[0]);
                            const itemName = qtyPrice[0];
                            const price = parseFloat(qtyPrice[1]);
                            const subtotal = qty * price;
                            total += subtotal;

                            itemsHtml += `
<<<<<<< HEAD
<tr>
    <td colspan="4" class="item-name">
        ${itemName}
    </td>
</tr>

<tr class="item-details">

    <td></td>

    <td class="text-center">
        ${qty}
    </td>

    <td class="text-right">
        ₱${price.toFixed(2)}
    </td>

    <td class="text-right">
        ₱${subtotal.toFixed(2)}
    </td>

</tr>
`;
=======
                                 <tr>
                                    <td class="item-name">${itemName}</td>
                                    <td class="text-center">${qty}</td>
                                    <td class="text-right">₱${price.toFixed(2)}</td>
                                    <td class="text-right">₱${subtotal.toFixed(2)}</td>
                                 </tr>
                            `;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                        }
                    }
                });
            }

            return `
                <div class="thermal-receipt">
                    <div class="receipt-header">
                        <div class="company-name">AMGC</div>
                        <div class="receipt-title">DELIVERY RECEIPT</div>
                        <div class="receipt-no">#${receiptNumber}</div>
                        <div>${formattedDate}</div>
                    </div>
                    
                    <div class="receipt-info">
<<<<<<< HEAD
                        <div class="info-line"><span class="info-value">${soNumber || '-'}</span></div>
                        ${siReceiptLine}
=======
                        <div class="info-line"><span class="info-label">Order:</span><span class="info-value">${soNumber}</span></div>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                        <div class="info-line"><span class="info-label">Received:</span><span class="info-value">${signedBy}</span></div>
                    </div>
                    
                    <table class="items-table">
                        <thead>
                             <tr>
                                <th>Item</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">Total</th>
<<<<<<< HEAD
                              </tr>
=======
                             </tr>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                    
                    <div class="receipt-total">
                        TOTAL: ₱${total.toFixed(2)}
                    </div>
                    
                    <div class="receipt-footer">
                        *** Thank you! ***
                    </div>
                </div>
            `;
        }

        function showReceiptModal(deliveryId, soNumber, siNumber, customerName, address, signedBy, deliveryDate, itemsRaw) {
            const receiptContent = document.getElementById('receiptContent');
            
            currentThermalReceipt = generateThermalReceipt(deliveryId, soNumber, siNumber, customerName, address, signedBy, deliveryDate, itemsRaw);
            receiptContent.innerHTML = currentThermalReceipt;
            
            const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
            modal.show();
        }

        function printThermalReceipt() {
<<<<<<< HEAD
    if (!currentThermalReceipt || currentThermalReceipt.trim() === '') {
        alert('Walang receipt content na ipi-print.');
        return;
    }

    const printWindow = window.open('', '_blank');

    if (!printWindow) {
        alert('Please allow pop-ups para ma-open ang print preview.');
        return;
    }

    printWindow.document.open();
    printWindow.document.write(`
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Print Receipt</title>

<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
@page {
    size: 80mm auto;
    margin: 0;
}

html,
body {
    width: 80mm;
    margin: 0;
    padding: 0;
    background: #fff;
    color: #000;
    font-family: "Roboto Mono", monospace;
}

.print-wrapper {
    width: 80mm;
    margin: 0 auto;
    padding: 0;
    text-align: center;
}

.thermal-receipt {
    display: inline-block;
    width: 72mm;
    margin: 0 auto;
    padding: 3mm;
    box-sizing: border-box;
    background: #fff;
    color: #000;
    font-family: "Roboto Mono", monospace;
    font-size: 10px;
    line-height: 1.35;
    text-align: left;
    border: none !important;
    box-shadow: none !important;
}

.receipt-header {
    text-align: center;
    margin-bottom: 5px;
    padding-bottom: 5px;
    border-bottom: 1px dashed #000;
}

.company-name {
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.receipt-title {
    font-size: 12px;
    font-weight: 700;
}

.receipt-no {
    font-size: 9px;
}

.receipt-info {
    margin: 5px 0;
    padding: 0;
    background: transparent;
    font-size: 9px;
}

.info-line {
    display: block;
    margin: 2px 0;
}

.info-label {
    font-weight: 700;
}

.info-value {
    font-weight: 400;
}

.items-table {
    width: 100%;
    margin: 5px 0;
    border-collapse: collapse;
    font-size: 9px;
}

.items-table th {
    padding: 3px 0;
    border-bottom: 1px solid #000;
    font-weight: 700;
}

.items-table td {

    padding: 2px 0;

    vertical-align: top;

}


.item-name{

    font-size:9px;

    font-weight:500;

    word-break:break-word;

    padding-top:5px;

}


.item-details td{

    border-bottom:
    1px dotted #999;

    padding-bottom:
    5px;

    font-size:9px;

}

.text-center {
    text-align: center;
}

.text-right {
    text-align: right;
}

.receipt-total {
    margin-top: 6px;
    padding-top: 5px;
    border-top: 1px solid #000;
    text-align: right;
    font-size: 12px;
    font-weight: 700;
}

.receipt-footer {
    margin-top: 10px;
    text-align: center;
    font-size: 9px;
    padding-bottom: 25px;
}
</style>
</head>

<body>
    <div class="print-wrapper">
        ${currentThermalReceipt}
    </div>

<script>
window.onload = function () {
    setTimeout(function () {
        window.focus();
        window.print();
    }, 800);
};
<\/script>

</body>
</html>
`);
    printWindow.document.close();
}
=======
            const thermalDiv = document.getElementById('thermalReceipt');
            thermalDiv.style.display = 'block';
            thermalDiv.innerHTML = currentThermalReceipt;

            setTimeout(() => {
                window.print();

                setTimeout(() => {
                    thermalDiv.style.display = 'none';
                    thermalDiv.innerHTML = '';
                }, 100);
            }, 100);
        }
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

        function showLocation(lat, lng, customerName, address) {
            currentLat = parseFloat(lat);
            currentLng = parseFloat(lng);
            currentCustomerName = customerName;
            currentAddress = address;
            
            document.getElementById('modalCustomerName').textContent = customerName;
            document.getElementById('modalCustomerAddress').textContent = address;
            document.getElementById('modalLat').textContent = currentLat.toFixed(6);
            document.getElementById('modalLng').textContent = currentLng.toFixed(6);
            
            const modal = new bootstrap.Modal(document.getElementById('locationMapModal'));
            modal.show();
            
            setTimeout(() => {
                initMap(currentLat, currentLng, customerName);
            }, 500);
        }

        function initMap(lat, lng, customerName) {
            const mapElement = document.getElementById('customerLocationMap');
            
            if (map) {
                map.remove();
                map = null;
            }
            
            if (!lat || !lng || isNaN(lat) || isNaN(lng)) {
                mapElement.innerHTML = '<div class="alert alert-danger p-2">Invalid coordinates</div>';
                return;
            }
            
            try {
                map = L.map('customerLocationMap').setView([lat, lng], 15);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap'
                }).addTo(map);
                
                marker = L.marker([lat, lng]).addTo(map);
                marker.bindPopup(`<b>${customerName}</b><br>Delivery Location`).openPopup();
                
            } catch (error) {
                console.error('Map error:', error);
                mapElement.innerHTML = '<div class="alert alert-danger p-2">Map unavailable</div>';
            }
        }

        function openInGoogleMaps() {
            if (currentLat && currentLng) {
                const url = `https://www.google.com/maps/search/?api=1&query=${currentLat},${currentLng}`;
                window.open(url, '_blank');
            }
        }

        // Handle reason change in partial modal
        document.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'partialReason') {
                const otherDiv = document.getElementById('otherReasonDiv');
                if (e.target.value === 'Other') {
                    otherDiv.style.display = 'block';
                    document.getElementById('otherReason').required = true;
                } else {
                    otherDiv.style.display = 'none';
                    document.getElementById('otherReason').required = false;
                }
            }
        });

        function copySQL(table) {
            let sql = '';
            if (table === 'deliveries') {
                sql = "ALTER TABLE deliveries ADD COLUMN branch_id INT NULL;\nALTER TABLE deliveries ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'deliveries_driver') {
                sql = "ALTER TABLE deliveries ADD COLUMN driver_id INT NULL;\nALTER TABLE deliveries ADD FOREIGN KEY (driver_id) REFERENCES drivers(driver_id);";
            }
            
            navigator.clipboard.writeText(sql).then(() => {
                alert('SQL copied to clipboard!');
            });
        }

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

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }

        // Status filter
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const statusCell = row.cells[5];
                    if (statusCell) {
                        const status = statusCell.textContent.toLowerCase().trim();
                        row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
                    }
                });
            });
        }

        // ================= IMPROVED COLLAPSIBLE PANEL & DRAGGABLE FOR MOBILE =================
        function initCollapsiblePanel() {
            const panel = document.getElementById('navigationStatusPanel');
            const toggleBtn = document.getElementById('toggleStatusPanel');
            if (!panel || !toggleBtn) return;

            let isCollapsed = false;

            toggleBtn.addEventListener('mousedown', function(e) {
                e.stopPropagation();
            });
            toggleBtn.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            });

            toggleBtn.addEventListener('click', function() {
                isCollapsed = !isCollapsed;
                panel.classList.toggle('collapsed', isCollapsed);
                const icon = toggleBtn.querySelector('i');
                if (isCollapsed) {
                    icon.classList.remove('bi-chevron-up');
                    icon.classList.add('bi-chevron-down');
                    
                    if (window.innerWidth <= 768) {
                        panel.style.maxHeight = '60px';
                    }
                } else {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-up');
                    
                    if (window.innerWidth <= 768) {
                        panel.style.maxHeight = '60vh';
                    }
                }
                
                if (liveTrackingMap) {
                    setTimeout(() => liveTrackingMap.invalidateSize(), 200);
                }
            });
            
            const header = panel.querySelector('h6');
            if (header) {
                header.addEventListener('click', function(e) {
                    if (window.innerWidth <= 768 && !e.target.closest('.toggle-panel-btn')) {
                        toggleBtn.click();
                    }
                });
            }
        }

        function makePanelDraggable() {
            const panel = document.getElementById('navigationStatusPanel');
            if (!panel) return;

            const handle = panel.querySelector('h6');
            const container = panel.parentElement;

            function resetPosition() {
                const containerRect = container.getBoundingClientRect();
                const panelWidth = panel.offsetWidth;
                const isMobile = window.innerWidth <= 768;

                if (isMobile) {
                    panel.style.left = '0';
                    panel.style.top = 'auto';
                    panel.style.bottom = '0';
                    panel.style.right = '0';
                    panel.style.width = '100%';
                    panel.style.maxWidth = '100%';
                    panel.style.borderRadius = '20px 20px 0 0';
                    panel.style.transform = 'none';
                    
                    if (panel.classList.contains('collapsed')) {
                        panel.style.maxHeight = '60px';
                    } else {
                        panel.style.maxHeight = '60vh';
                        panel.style.overflowY = 'auto';
                    }
                } else {
                    panel.style.width = '320px';
                    panel.style.maxWidth = '320px';
                    panel.style.left = (containerRect.width - panelWidth - 20) + 'px';
                    panel.style.top = '20px';
                    panel.style.bottom = 'auto';
                    panel.style.right = 'auto';
                    panel.style.borderRadius = '12px';
                    panel.style.maxHeight = 'calc(100% - 40px)';
                    panel.style.overflowY = 'auto';
                    panel.style.transform = 'none';
                }
            }

            const trackingModal = document.getElementById('trackingModal');
            trackingModal.addEventListener('shown.bs.modal', resetPosition);
            
            window.addEventListener('resize', resetPosition);

            function enableDragging() {
                if (window.innerWidth <= 768) return;

                let isDragging = false;
                let startX, startY, startLeft, startTop;

                function startDrag(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    isDragging = true;

                    const panelRect = panel.getBoundingClientRect();
                    startLeft = panelRect.left;
                    startTop = panelRect.top;

                    if (e.type === 'mousedown') {
                        startX = e.clientX;
                        startY = e.clientY;
                    } else {
                        startX = e.touches[0].clientX;
                        startY = e.touches[0].clientY;
                    }

                    document.addEventListener('mousemove', drag);
                    document.addEventListener('mouseup', stopDrag);
                    document.addEventListener('touchmove', drag, { passive: false });
                    document.addEventListener('touchend', stopDrag);
                    document.addEventListener('touchcancel', stopDrag);
                }

                function drag(e) {
                    if (!isDragging) return;
                    e.preventDefault();

                    let clientX, clientY;
                    if (e.type === 'mousemove') {
                        clientX = e.clientX;
                        clientY = e.clientY;
                    } else {
                        clientX = e.touches[0].clientX;
                        clientY = e.touches[0].clientY;
                    }

                    const dx = clientX - startX;
                    const dy = clientY - startY;
                    let newLeft = startLeft + dx;
                    let newTop = startTop + dy;

                    const containerRect = container.getBoundingClientRect();
                    const panelWidth = panel.offsetWidth;
                    const panelHeight = panel.offsetHeight;

                    const minLeft = containerRect.left;
                    const maxLeft = containerRect.right - panelWidth;
                    const minTop = containerRect.top;
                    const maxTop = containerRect.bottom - panelHeight;

                    newLeft = Math.max(minLeft, Math.min(newLeft, maxLeft));
                    newTop = Math.max(minTop, Math.min(newTop, maxTop));

                    const relativeLeft = newLeft - containerRect.left;
                    const relativeTop = newTop - containerRect.top;

                    panel.style.left = relativeLeft + 'px';
                    panel.style.top = relativeTop + 'px';
                    panel.style.right = 'auto';
                    panel.style.bottom = 'auto';
                }

                function stopDrag() {
                    isDragging = false;
                    document.removeEventListener('mousemove', drag);
                    document.removeEventListener('mouseup', stopDrag);
                    document.removeEventListener('touchmove', drag);
                    document.removeEventListener('touchend', stopDrag);
                    document.removeEventListener('touchcancel', stopDrag);
                }

                handle.addEventListener('mousedown', startDrag);
                handle.addEventListener('touchstart', startDrag, { passive: false });
            }

            enableDragging();

            window.addEventListener('resize', function() {
                resetPosition();
                if (window.innerWidth <= 768) {
                    handle.removeEventListener('mousedown', enableDragging);
                    handle.removeEventListener('touchstart', enableDragging);
                } else {
                    enableDragging();
                }
            });
        }

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            initMobileNav();
            
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
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
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

            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
                initMobilePanelToggle();
                
                if (liveTrackingMap) {
                    setTimeout(() => liveTrackingMap.invalidateSize(), 200);
                }
            });
            
            const locationModal = document.getElementById('locationMapModal');
            if (locationModal) {
                locationModal.addEventListener('hidden.bs.modal', function () {
                    if (map) {
                        map.remove();
                        map = null;
                    }
                });
            }

            const trackingModal = document.getElementById('trackingModal');
            if (trackingModal) {
                trackingModal.addEventListener('hidden.bs.modal', function () {
                    if (watchPositionId) {
                        navigator.geolocation.clearWatch(watchPositionId);
                        watchPositionId = null;
                    }
                    
                    if (liveTrackingMap) {
                        liveTrackingMap.remove();
                        liveTrackingMap = null;
                    }
                    userMarker = null;
                    destinationMarker = null;
                    routingControl = null;
                    currentPosition = null;
                    if (glowLine) {
                        glowLine = null;
                    }
                });
            }

            // Auto-start for delivery drivers
            <?php if ($user_role == 'delivery' && $driver_id > 0): ?>
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_shift_status',
                    driver_id: <?php echo $driver_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.has_active_shift) {
                    console.log('May active shift:', data);
                    startGPSTracking();
                } else {
                    console.log('Walang active shift, mag-start ng bago');
                    setTimeout(function() {
                        toggleTracking();
                    }, 2000);
                }
            });
            <?php endif; ?>

            if (document.getElementById('trackingModal')) {
                initCollapsiblePanel();
                makePanelDraggable();
                initMobilePanelToggle();
            }
            
            // Show pickup tasks modal if there are pending tasks
            <?php if ($has_pending_tasks && $show_pickup_modal): ?>
            setTimeout(function() {
                const pickupModal = new bootstrap.Modal(document.getElementById('pickupTasksModal'));
                pickupModal.show();
            }, 500);
            <?php endif; ?>
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
            }
            else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });
       
       // ================= FOR DELIVERY FILTER FUNCTIONS =================

        // Toggle filter visibility
        function toggleDeliveryFilter() {
            const content = document.getElementById('salesFilterContent');
            const icon = document.getElementById('salesFilterIcon');
            const toggleBtn = document.getElementById('salesFilterToggle');
            
            if (content && icon && toggleBtn) {
                const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
                
                if (isExpanded) {
                    content.classList.add('collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'false');
                    icon.style.transform = 'rotate(0deg)';
                    localStorage.setItem('deliveryFilterHidden', 'true');
                } else {
                    content.classList.remove('collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'true');
                    icon.style.transform = 'rotate(180deg)';
                    localStorage.setItem('deliveryFilterHidden', 'false');
                }
            }
        }

        // Apply filters
        function applyDeliveryFilters() {
            const search = document.getElementById('searchInput')?.value?.toLowerCase() || '';
            const status = document.getElementById('statusFilter')?.value?.toLowerCase() || '';
            
            const rows = document.querySelectorAll('tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const orderId = row.cells[0]?.textContent?.toLowerCase() || '';
                const customerName = row.cells[1]?.textContent?.toLowerCase() || '';
                const address = row.cells[2]?.textContent?.toLowerCase() || '';
                const contact = row.cells[3]?.textContent?.toLowerCase() || '';
                const items = row.cells[4]?.textContent?.toLowerCase() || '';
                const rowStatus = row.cells[5]?.textContent?.toLowerCase().trim() || '';
                
                const searchableText = orderId + ' ' + customerName + ' ' + address + ' ' + contact + ' ' + items;
                
                const matchesSearch = search === '' || searchableText.includes(search);
                const matchesStatus = status === '' || rowStatus.includes(status);
                
                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            const table = document.querySelector('tbody');
            const noResultsRow = document.getElementById('noResultsRow');
            
            if (visibleCount === 0) {
                if (!noResultsRow) {
                    const newRow = document.createElement('tr');
                    newRow.id = 'noResultsRow';
                    newRow.innerHTML = '<td colspan="7" class="text-center py-4"><i class="bi bi-search"></i> No matching deliveries found</td>';
                    table.appendChild(newRow);
                }
            } else if (noResultsRow) {
                noResultsRow.remove();
            }
        }

        // Clear filters
        function clearDeliveryFilters() {
            document.getElementById('searchInput') && (document.getElementById('searchInput').value = '');
            document.getElementById('statusFilter') && (document.getElementById('statusFilter').value = '');
            applyDeliveryFilters();
        }

        // Initialize filter state - DEFAULT CLOSED
        function initDeliveryFilterState() {
            const content = document.getElementById('salesFilterContent');
            const icon = document.getElementById('salesFilterIcon');
            const toggleBtn = document.getElementById('salesFilterToggle');
            
            if (content && icon && toggleBtn) {
                content.classList.add('collapsed');
                toggleBtn.setAttribute('aria-expanded', 'false');
                icon.style.transform = 'rotate(0deg)';
                localStorage.setItem('deliveryFilterHidden', 'true');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            initDeliveryFilterState();
            
            document.getElementById('salesFilterToggle')?.addEventListener('click', toggleDeliveryFilter);
            document.getElementById('searchInput')?.addEventListener('input', applyDeliveryFilters);
            document.getElementById('statusFilter')?.addEventListener('change', applyDeliveryFilters);
            
            document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyDeliveryFilters();
                }
            });
        });
    </script>
</body>
</html>
