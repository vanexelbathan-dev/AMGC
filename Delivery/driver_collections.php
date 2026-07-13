<?php
ob_start();

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (function_exists('requireLogin')) {
    requireLogin();
}
if (function_exists('requireRole')) {
    requireRole(['delivery']);
}

$user_id = (int)($_SESSION['user_id'] ?? (function_exists('getUserId') ? getUserId() : 0));
$branch_id = (int)($_SESSION['branch_id'] ?? (function_exists('getUserBranchId') ? getUserBranchId() : 0));
$user_name = trim(($_SESSION['first_name'] ?? 'Driver') . ' ' . ($_SESSION['last_name'] ?? 'User'));
if ($user_name === '') $user_name = 'Driver User';

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

function esc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v) {
    return '₱' . number_format((float)$v, 2);
}

function table_exists_safe($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function col_exists_safe($conn, $table, $col) {
    if (!table_exists_safe($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $col = $conn->real_escape_string($col);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $res && $res->num_rows > 0;
}

function ensure_banks_table_safe($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `banks` (
        `bank_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `bank_name` varchar(150) NOT NULL,
        `account_name` varchar(150) DEFAULT NULL,
        `account_number` varchar(100) DEFAULT NULL,
        `bank_branch` varchar(150) DEFAULT NULL,
        `status` enum('active','inactive') NOT NULL DEFAULT 'active',
        `parent_bank_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`bank_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`),
        KEY `parent_bank_id` (`parent_bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if (!col_exists_safe($conn, 'banks', 'parent_bank_id')) {
        $conn->query("ALTER TABLE `banks` ADD COLUMN `parent_bank_id` int(11) DEFAULT NULL AFTER `status`");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_payment_methods` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_id` int(11) NOT NULL,
        `payment_method` enum('check','online_transfer','cash') NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_bank_method` (`bank_id`,`payment_method`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function fetch_active_banks_safe($conn, $branch_id) {
    ensure_banks_table_safe($conn);
    $rows = [];
    $sql = "SELECT bank_id, bank_name, COALESCE(bank_branch, '') AS bank_branch FROM banks WHERE status = 'active'";
    if ((int)$branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $sql .= " ORDER BY bank_name ASC, bank_branch ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    if ((int)$branch_id > 0) $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fetch_online_transfer_sub_accounts_safe($conn, $branch_id) {
    ensure_banks_table_safe($conn);
    $rows = [];
    $sql = "SELECT b.bank_id, b.bank_name, COALESCE(b.account_number, '') AS account_number, COALESCE(b.bank_branch, '') AS bank_branch, COALESCE(pb.bank_name, '') AS parent_bank_name
            FROM banks b
            LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
            INNER JOIN bank_payment_methods bpm ON bpm.bank_id = b.bank_id AND bpm.payment_method = 'online_transfer'
            WHERE b.status = 'active' AND b.parent_bank_id IS NOT NULL";
    if ((int)$branch_id > 0) $sql .= " AND (b.branch_id = ? OR b.branch_id = 0)";
    $sql .= " ORDER BY pb.bank_name ASC, b.bank_name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    if ((int)$branch_id > 0) $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function save_new_bank_if_needed($conn, $branch_id, $user_id, $bank_name, $bank_branch = '') {
    $bank_name = trim((string)$bank_name);
    $bank_branch = trim((string)$bank_branch);
    if ($bank_name === '') return;
    ensure_banks_table_safe($conn);

    $bid = (int)$branch_id;
    $stmt = $conn->prepare("SELECT bank_id FROM banks WHERE LOWER(bank_name) = LOWER(?) AND LOWER(COALESCE(bank_branch, '')) = LOWER(?) AND (branch_id = ? OR branch_id = 0) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ssi', $bank_name, $bank_branch, $bid);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) return;
    }

    $user_id = (int)$user_id;
    $insert = $conn->prepare("INSERT INTO banks (branch_id, bank_name, bank_branch, status, created_by) VALUES (?, ?, ?, 'active', ?)");
    if ($insert) {
        $insert->bind_param('issi', $bid, $bank_name, $bank_branch, $user_id);
        @$insert->execute();
        $insert->close();
    }
}


function upload_collection_photo_safe($field_name, $prefix = 'collection') {
    if (empty($_FILES[$field_name]) || !isset($_FILES[$field_name]['error']) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($_FILES[$field_name]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Failed to upload attachment');
    }

    $max_size = 5 * 1024 * 1024;
    if ((int)$_FILES[$field_name]['size'] > $max_size) {
        throw new Exception('Attachment must not exceed 5MB');
    }

    $allowed = ['jpg','jpeg','png','webp','gif'];
    $original_name = basename((string)$_FILES[$field_name]['name']);
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Only JPG, PNG, WEBP, and GIF photos are allowed');
    }

    $upload_dir = __DIR__ . '/../uploads/collection_attachments/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0775, true);
    }
    if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
        throw new Exception('Upload folder is not writable: uploads/collection_attachments');
    }

    $safe_name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $upload_dir . $safe_name;
    if (!move_uploaded_file($_FILES[$field_name]['tmp_name'], $target)) {
        throw new Exception('Unable to save attachment');
    }

    return ['../uploads/collection_attachments/' . $safe_name, $original_name];
}

function ensure_collection_tables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_assignments` (
        `assignment_id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL,
        `customer_id` INT NOT NULL DEFAULT 0,
        `branch_id` INT NOT NULL DEFAULT 0,
        `assigned_user_id` INT NOT NULL,
        `assigned_by` INT NOT NULL DEFAULT 0,
        `collection_date` DATE DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `invoice_id` (`invoice_id`),
        KEY `assigned_user_id` (`assigned_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $cols = [
        'customer_id' => "ALTER TABLE collection_assignments ADD COLUMN customer_id INT NOT NULL DEFAULT 0 AFTER invoice_id",
        'branch_id' => "ALTER TABLE collection_assignments ADD COLUMN branch_id INT NOT NULL DEFAULT 0 AFTER customer_id",
        'assigned_user_id' => "ALTER TABLE collection_assignments ADD COLUMN assigned_user_id INT NOT NULL DEFAULT 0 AFTER branch_id",
        'assigned_by' => "ALTER TABLE collection_assignments ADD COLUMN assigned_by INT NOT NULL DEFAULT 0 AFTER assigned_user_id",
        'collection_date' => "ALTER TABLE collection_assignments ADD COLUMN collection_date DATE DEFAULT NULL AFTER assigned_by",
        'notes' => "ALTER TABLE collection_assignments ADD COLUMN notes TEXT DEFAULT NULL AFTER collection_date",
        'status' => "ALTER TABLE collection_assignments ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'",
        'created_at' => "ALTER TABLE collection_assignments ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE collection_assignments ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];

    foreach ($cols as $col => $sql) {
        if (!col_exists_safe($conn, 'collection_assignments', $col)) {
            @$conn->query($sql);
        }
    }

    $status = $conn->query("SHOW COLUMNS FROM collection_assignments LIKE 'status'");
    if ($status && $status->num_rows > 0) {
        $row = $status->fetch_assoc();
        if (stripos($row['Type'] ?? '', 'enum') !== false) {
            @$conn->query("ALTER TABLE collection_assignments MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'");
        }
    }

    // COLLECTION RECORDS table - for collected but not yet remitted
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_records` (
        `record_id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL,
        `customer_id` INT NOT NULL,
        `branch_id` INT NOT NULL DEFAULT 0,
        `collector_user_id` INT NOT NULL,
        `payment_method` ENUM('cash','check','online_transfer') NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
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
        `status` ENUM('collected','remitted','cancelled') NOT NULL DEFAULT 'collected',
        `remitted_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `invoice_id` (`invoice_id`),
        KEY `collector_user_id` (`collector_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");


    if (!col_exists_safe($conn, 'collection_records', 'attachment_path')) {
        @$conn->query("ALTER TABLE collection_records ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER cash_change");
    }
    if (!col_exists_safe($conn, 'collection_records', 'attachment_name')) {
        @$conn->query("ALTER TABLE collection_records ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path");
    }
    // Keep status flexible so report can include collected, remitted, approved, and completed.
    @$conn->query("ALTER TABLE collection_records MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'collected'");

    // RETURNED INVOICE TICKETS table - for agents returning assigned tickets back to admin
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_invoice_returns` (
        `return_id` INT AUTO_INCREMENT PRIMARY KEY,
        `assignment_id` INT NOT NULL DEFAULT 0,
        `invoice_id` INT NOT NULL,
        `customer_id` INT NOT NULL DEFAULT 0,
        `branch_id` INT NOT NULL DEFAULT 0,
        `returned_by` INT NOT NULL,
        `returned_to` INT DEFAULT NULL,
        `return_reason` TEXT DEFAULT NULL,
        `attachment_path` VARCHAR(500) DEFAULT NULL,
        `attachment_name` VARCHAR(255) DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'returned',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `assignment_id` (`assignment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `returned_by` (`returned_by`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (!col_exists_safe($conn, 'collection_invoice_returns', 'attachment_path')) {
        @$conn->query("ALTER TABLE collection_invoice_returns ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER return_reason");
    }
    if (!col_exists_safe($conn, 'collection_invoice_returns', 'attachment_name')) {
        @$conn->query("ALTER TABLE collection_invoice_returns ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path");
    }
}

function paid_amount($conn, $invoice_id) {
    if (!table_exists_safe($conn, 'payments')) return 0;
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE invoice_id = ? AND (status IS NULL OR status = 'completed')");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $invoice_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['total_paid'] ?? 0);
}

function recalc_credit_used($conn, $customer_id) {
    if (!table_exists_safe($conn, 'invoices') || !table_exists_safe($conn, 'payments') || !table_exists_safe($conn, 'customers')) return;
    $sql = "SELECT COALESCE(SUM(GREATEST(i.total_amount - COALESCE(p.total_paid, 0), 0)),0) AS total_unpaid
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, COALESCE(SUM(amount),0) AS total_paid
                FROM payments
                WHERE status = 'completed'
                GROUP BY invoice_id
            ) p ON p.invoice_id = i.invoice_id
            WHERE i.customer_id = ?
            AND (i.status IS NULL OR i.status IN ('pending','overdue') OR TRIM(i.status) = '')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $used = (float)($row['total_unpaid'] ?? 0);
    $up = $conn->prepare("UPDATE customers SET credit_used = ? WHERE customer_id = ?");
    if ($up) {
        $up->bind_param('di', $used, $customer_id);
        $up->execute();
        $up->close();
    }
}

function fetch_assigned_collections($conn, $user_id, $branch_id) {
    if (!table_exists_safe($conn, 'collection_assignments') || !table_exists_safe($conn, 'invoices')) {
        return [];
    }

    $customer_name_sql = table_exists_safe($conn, 'customers') ? "COALESCE(c.customer_name, 'Unknown Customer')" : "'Unknown Customer'";
    $phone_sql = (table_exists_safe($conn, 'customers') && col_exists_safe($conn, 'customers', 'phone_number')) ? "c.phone_number" : "''";
    $address_sql = (table_exists_safe($conn, 'customers') && col_exists_safe($conn, 'customers', 'address')) ? "c.address" : "''";

    $customer_join = table_exists_safe($conn, 'customers') ? "LEFT JOIN customers c ON c.customer_id = i.customer_id" : "";
    $so_select = "'' AS so_number";
    $so_join = "";
    if (table_exists_safe($conn, 'sales_orders') && col_exists_safe($conn, 'invoices', 'so_id')) {
        $so_select = "COALESCE(so.so_number, '') AS so_number";
        $so_join = "LEFT JOIN sales_orders so ON so.so_id = i.so_id";
    }

    $sql = "SELECT
                ca.assignment_id,
                ca.invoice_id,
                ca.customer_id,
                ca.branch_id,
                ca.collection_date,
                ca.status AS assignment_status,
                ca.assigned_by,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(assigner.first_name, ''), ' ', COALESCE(assigner.last_name, ''))), ''), 'Branch Admin') AS assigned_by_name,
                (SELECT cir.return_reason FROM collection_invoice_returns cir WHERE cir.invoice_id = i.invoice_id AND cir.returned_by = ? AND cir.status = 'rejected' ORDER BY COALESCE(cir.reviewed_at, cir.created_at) DESC, cir.return_id DESC LIMIT 1) AS rejected_return_reason,
                (SELECT cir.rejection_reason FROM collection_invoice_returns cir WHERE cir.invoice_id = i.invoice_id AND cir.returned_by = ? AND cir.status = 'rejected' ORDER BY COALESCE(cir.reviewed_at, cir.created_at) DESC, cir.return_id DESC LIMIT 1) AS admin_rejection_reason,
                (SELECT COALESCE(cir.reviewed_at, cir.created_at) FROM collection_invoice_returns cir WHERE cir.invoice_id = i.invoice_id AND cir.returned_by = ? AND cir.status = 'rejected' ORDER BY COALESCE(cir.reviewed_at, cir.created_at) DESC, cir.return_id DESC LIMIT 1) AS rejected_return_date,
                i.invoice_number,
                i.invoice_date,
                i.due_date,
                i.total_amount,
                i.status AS invoice_status,
                i.customer_id AS invoice_customer_id,
                $customer_name_sql AS customer_name,
                $phone_sql AS phone_number,
                $address_sql AS address,
                $so_select,
                (SELECT COUNT(*) FROM collection_records cr WHERE cr.invoice_id = i.invoice_id AND cr.collector_user_id = ? AND cr.status = 'collected') AS has_collected_record,
                (SELECT COUNT(*) FROM collection_records cr WHERE cr.invoice_id = i.invoice_id AND cr.collector_user_id = ? AND cr.status = 'remitted') AS has_remitted_record,
                (SELECT SUM(amount) FROM collection_records cr WHERE cr.invoice_id = i.invoice_id AND cr.collector_user_id = ? AND cr.status IN ('collected','remitted')) AS collected_amount
            FROM collection_assignments ca
            JOIN invoices i ON i.invoice_id = ca.invoice_id
            $customer_join
            $so_join
            LEFT JOIN users assigner ON assigner.user_id = ca.assigned_by
            WHERE ca.assigned_user_id = ?
              AND ca.status IN ('active','assigned')
              AND i.status IN ('pending','overdue')";

    $params = [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id];
    $types = 'iiiiiii';

    if ($branch_id > 0) {
        $sql .= " AND (ca.branch_id = ? OR ca.branch_id = 0)";
        $params[] = $branch_id;
        $types .= 'i';
    }

    $sql .= " ORDER BY COALESCE(ca.collection_date, i.due_date, i.invoice_date) ASC, ca.assignment_id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types === 'iiiiiiii') {
        $stmt->bind_param('iiiiiiii', $params[0], $params[1], $params[2], $params[3], $params[4], $params[5], $params[6], $params[7]);
    } else {
        $stmt->bind_param('iiiiiii', $params[0], $params[1], $params[2], $params[3], $params[4], $params[5], $params[6]);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $paid = paid_amount($conn, (int)$row['invoice_id']);
        $row['paid_amount'] = $paid;
        $row['balance_amount'] = max((float)$row['total_amount'] - $paid, 0);
    }
    unset($row);

    return $rows;
}

function fetch_collected_records($conn, $user_id) {
    if (!table_exists_safe($conn, 'collection_records')) {
        return [];
    }
    
    $sql = "SELECT cr.*, i.invoice_number, i.customer_id, i.total_amount,
                   COALESCE(c.customer_name, 'Unknown') AS customer_name
            FROM collection_records cr
            JOIN invoices i ON i.invoice_id = cr.invoice_id
            LEFT JOIN customers c ON c.customer_id = i.customer_id
            WHERE cr.collector_user_id = ? AND cr.status = 'collected'
            ORDER BY cr.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $rows;
}


function fetch_my_collection_report($conn, $user_id, $branch_id = 0) {
    $user_id = (int)$user_id;
    $rows = [];

    // SOURCE 1: collection_records. This is the main and most accurate source for Sales/Driver collections.
    // It includes collected, remitted, approved, and completed records made by the logged-in collector.
    if (table_exists_safe($conn, 'collection_records')) {
        $approved_select = col_exists_safe($conn, 'collection_records', 'approved_at') ? "cr.approved_at" : "NULL";
        $sql = "SELECT cr.record_id AS source_id,
                       'collection_records' AS source_table,
                       cr.record_id,
                       cr.invoice_id,
                       COALESCE(NULLIF(cr.customer_id, 0), i.customer_id, 0) AS customer_id,
                       cr.branch_id,
                       cr.collector_user_id,
                       cr.payment_method,
                       COALESCE(cr.amount, 0) AS amount,
                       cr.collection_date,
                       cr.reference_number,
                       cr.check_date,
                       cr.bank_name,
                       cr.bank_branch,
                       cr.check_number,
                       cr.notes,
                       COALESCE(NULLIF(TRIM(cr.status), ''), 'collected') AS status,
                       cr.remitted_at,
                       $approved_select AS approved_at,
                       COALESCE(NULLIF(i.invoice_number, ''), CONCAT('INV-', cr.invoice_id)) AS invoice_number,
                       COALESCE(i.total_amount, 0) AS invoice_total,
                       COALESCE(c.customer_name, 'Unknown') AS customer_name
                FROM collection_records cr
                LEFT JOIN invoices i ON i.invoice_id = cr.invoice_id
                LEFT JOIN customers c ON c.customer_id = COALESCE(NULLIF(cr.customer_id, 0), i.customer_id)
                WHERE cr.collector_user_id = ?
                  AND COALESCE(cr.amount, 0) > 0
                  AND LOWER(COALESCE(NULLIF(TRIM(cr.status), ''), 'collected')) IN ('collected','remitted','approved','completed')";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) $rows = array_merge($rows, $res->fetch_all(MYSQLI_ASSOC));
            $stmt->close();
        }
    }

    // SOURCE 2: payments. Some older/direct collection flows save straight to payments.
    // BranchAdmin sees these too, so Sales/Driver print report must include them when created_by is the logged-in user.
    if (table_exists_safe($conn, 'payments')) {
        $sql = "SELECT p.payment_id AS source_id,
                       'payments' AS source_table,
                       p.payment_id AS record_id,
                       p.invoice_id,
                       COALESCE(NULLIF(p.customer_id, 0), i.customer_id, 0) AS customer_id,
                       COALESCE(so.branch_id, c.branch_id, 0) AS branch_id,
                       p.created_by AS collector_user_id,
                       p.payment_method,
                       COALESCE(p.amount, 0) AS amount,
                       p.payment_date AS collection_date,
                       p.reference_number,
                       p.check_date,
                       p.bank_name,
                       p.bank_branch,
                       p.check_number,
                       NULL AS notes,
                       COALESCE(NULLIF(TRIM(p.status), ''), 'completed') AS status,
                       NULL AS remitted_at,
                       NULL AS approved_at,
                       COALESCE(NULLIF(i.invoice_number, ''), CONCAT('INV-', p.invoice_id)) AS invoice_number,
                       COALESCE(i.total_amount, 0) AS invoice_total,
                       COALESCE(c.customer_name, 'Unknown') AS customer_name
                FROM payments p
                LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
                LEFT JOIN sales_orders so ON so.so_id = i.so_id
                LEFT JOIN customers c ON c.customer_id = COALESCE(NULLIF(p.customer_id, 0), i.customer_id)
                WHERE p.created_by = ?
                  AND COALESCE(p.amount, 0) > 0
                  AND LOWER(COALESCE(NULLIF(TRIM(p.status), ''), 'completed')) IN ('completed','approved','paid','collected')";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $payment_rows = $res->fetch_all(MYSQLI_ASSOC);
                foreach ($payment_rows as $pay_row) {
                    $duplicate = false;
                    foreach ($rows as $cr_row) {
                        $same_invoice = (int)($cr_row['invoice_id'] ?? 0) === (int)($pay_row['invoice_id'] ?? 0);
                        $same_amount = abs((float)($cr_row['amount'] ?? 0) - (float)($pay_row['amount'] ?? 0)) < 0.01;
                        $same_date = substr((string)($cr_row['collection_date'] ?? ''), 0, 10) === substr((string)($pay_row['collection_date'] ?? ''), 0, 10);
                        if ($same_invoice && $same_amount && $same_date) {
                            $duplicate = true;
                            break;
                        }
                    }
                    if (!$duplicate) $rows[] = $pay_row;
                }
            }
            $stmt->close();
        }
    }

    usort($rows, function($a, $b) {
        $ad = strtotime((string)($a['collection_date'] ?? '')) ?: 0;
        $bd = strtotime((string)($b['collection_date'] ?? '')) ?: 0;
        if ($ad === $bd) return (int)($b['record_id'] ?? 0) <=> (int)($a['record_id'] ?? 0);
        return $bd <=> $ad;
    });

    return $rows;
}

ensure_collection_tables($conn);
$branch_name = 'All Branches';
if ($branch_id > 0 && table_exists_safe($conn, 'branches')) {
    $branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1");
    if ($branch_stmt) {
        $branch_stmt->bind_param('i', $branch_id);
        $branch_stmt->execute();
        $branch_row = $branch_stmt->get_result()->fetch_assoc();
        if ($branch_row && !empty($branch_row['branch_name'])) {
            $branch_name = $branch_row['branch_name'];
        }
        $branch_stmt->close();
    }
}
$registered_banks = fetch_active_banks_safe($conn, $branch_id);
$online_transfer_accounts = fetch_online_transfer_sub_accounts_safe($conn, $branch_id);
$registered_banks_json = json_encode($registered_banks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$online_transfer_accounts_json = json_encode($online_transfer_accounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        // Step 1: Record collection (not yet remitted)
        if ($action === 'record_collection') {
            $invoice_id = (int)($_POST['invoice_id'] ?? 0);
            $payment_method = trim($_POST['payment_method'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);

            if ($invoice_id <= 0) throw new Exception('Invalid invoice');
            if (!in_array($payment_method, ['cash','check','online_transfer'], true)) throw new Exception('Invalid payment method');
            if ($amount <= 0) throw new Exception('Invalid amount');

            // Check if assigned to this collector
            $assigned_sql = "SELECT ca.*, i.total_amount, i.customer_id AS invoice_customer_id, i.status AS invoice_status
                             FROM collection_assignments ca
                             JOIN invoices i ON i.invoice_id = ca.invoice_id
                             WHERE ca.invoice_id = ?
                               AND ca.assigned_user_id = ?
                               AND ca.status IN ('active','assigned')
                               AND i.status IN ('pending','overdue')
                             LIMIT 1";
            $assigned_stmt = $conn->prepare($assigned_sql);
            if (!$assigned_stmt) throw new Exception('Failed to check assignment: ' . $conn->error);
            $assigned_stmt->bind_param('ii', $invoice_id, $user_id);
            $assigned_stmt->execute();
            $assignment = $assigned_stmt->get_result()->fetch_assoc();
            $assigned_stmt->close();

            if (!$assignment) throw new Exception('This collection is not assigned to you');

            if ($branch_id > 0 && (int)$assignment['branch_id'] > 0 && (int)$assignment['branch_id'] !== $branch_id) {
                throw new Exception('This collection is not from your branch');
            }

            $invoice_total = (float)$assignment['total_amount'];
            $paid_before = paid_amount($conn, $invoice_id);
            $remaining = max($invoice_total - $paid_before, 0);

            if ($remaining <= 0.009) throw new Exception('Invoice already fully paid');

            $amount_collected_input = $amount;
            if ($payment_method !== 'cash' && $amount > ($remaining + 0.009)) {
                throw new Exception('Amount cannot be greater than remaining balance');
            }
            if ($payment_method === 'cash' && $amount > $remaining) {
                $amount = $remaining;
            }

            // Check if already has a collected record
            $check_record = $conn->prepare("SELECT status FROM collection_records WHERE invoice_id = ? AND collector_user_id = ? AND status IN ('collected','remitted') ORDER BY record_id DESC LIMIT 1");
            $check_record->bind_param('ii', $invoice_id, $user_id);
            $check_record->execute();
            $existing_record = $check_record->get_result()->fetch_assoc();
            $check_record->close();

            if ($existing_record) {
                if (($existing_record['status'] ?? '') === 'remitted') {
                    throw new Exception('This collection is already remitted and waiting for approval.');
                }
                throw new Exception('You already have a collected record for this invoice.');
            }

            $reference_number = null;
            $check_date = null;
            $bank_name = null;
            $bank_branch = null;
            $check_number = null;
            $cash_tendered = null;
            $cash_change = null;

            if ($payment_method === 'cash') {
                if ($amount_collected_input <= 0) throw new Exception('Invalid amount collected');
                $cash_tendered = null;
                $cash_change = null;
            } elseif ($payment_method === 'check') {
                $check_date = trim($_POST['check_date'] ?? '');
                $bank_name = trim($_POST['bank_name'] ?? '');
                $bank_branch = trim($_POST['bank_branch'] ?? '');
                $check_number = trim($_POST['check_number'] ?? '');
                $reference_number = $check_number;
                if ($check_date === '' || $bank_name === '' || $bank_branch === '' || $check_number === '') {
                    throw new Exception('All check details are required');
                }
                /* Check bank name is manual only; it is not added to the online-transfer sub account list. */
            } else {
                $reference_number = trim($_POST['reference_number'] ?? '');
                $bank_wallet_id = (int)($_POST['bank_wallet_id'] ?? 0);
                if ($reference_number === '' || $bank_wallet_id <= 0) {
                    throw new Exception('Reference number and Bank/Wallet sub account are required');
                }
                $online_stmt = $conn->prepare("SELECT b.bank_name, COALESCE(b.bank_branch, '') AS bank_branch, COALESCE(pb.bank_name, '') AS parent_bank_name
                                            FROM banks b
                                            LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
                                            INNER JOIN bank_payment_methods bpm ON bpm.bank_id = b.bank_id AND bpm.payment_method = 'online_transfer'
                                            WHERE b.bank_id = ? AND b.status = 'active' AND b.parent_bank_id IS NOT NULL LIMIT 1");
                if (!$online_stmt) throw new Exception('Failed to validate online transfer account');
                $online_stmt->bind_param('i', $bank_wallet_id);
                $online_stmt->execute();
                $online_bank = $online_stmt->get_result()->fetch_assoc();
                $online_stmt->close();
                if (!$online_bank) throw new Exception('Please select a registered online transfer sub account.');
                $bank_name = trim(($online_bank['parent_bank_name'] ? $online_bank['parent_bank_name'] . ' / ' : '') . $online_bank['bank_name']);
                $bank_branch = trim($online_bank['bank_branch'] ?? '');
            }

            $customer_id = (int)$assignment['invoice_customer_id'];
            $collection_date = date('Y-m-d H:i:s');
            [$attachment_path, $attachment_name] = upload_collection_photo_safe('collection_photo', 'collection');

            $insert = "INSERT INTO collection_records
                       (invoice_id, customer_id, branch_id, collector_user_id, payment_method, amount, collection_date, 
                        reference_number, check_date, bank_name, bank_branch, check_number, cash_tendered, cash_change, attachment_path, attachment_name, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'collected')";
            $stmt = $conn->prepare($insert);
            if (!$stmt) throw new Exception('Failed to prepare record: ' . $conn->error);
            $stmt->bind_param(
                'iiiisdsissssddss',
                $invoice_id,
                $customer_id,
                $branch_id,
                $user_id,
                $payment_method,
                $amount,
                $collection_date,
                $reference_number,
                $check_date,
                $bank_name,
                $bank_branch,
                $check_number,
                $cash_tendered,
                $cash_change,
                $attachment_path,
                $attachment_name
            );
            if (!$stmt->execute()) throw new Exception('Failed to save collection record: ' . $stmt->error);
            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Collection recorded! Click REMIT ALL to submit all collections for approval.'
            ]);
            exit;
        }
        
        // Return invoice ticket back to Branch Admin without collecting payment
        elseif ($action === 'return_invoice_ticket') {
            $invoice_id = (int)($_POST['invoice_id'] ?? 0);
            $return_reason = trim($_POST['return_reason'] ?? '');

            if ($invoice_id <= 0) throw new Exception('Invalid invoice');
            if ($return_reason === '') throw new Exception('Please enter reason for returning this invoice');

            $assigned_sql = "SELECT ca.*, i.customer_id AS invoice_customer_id, i.status AS invoice_status
                             FROM collection_assignments ca
                             JOIN invoices i ON i.invoice_id = ca.invoice_id
                             WHERE ca.invoice_id = ?
                               AND ca.assigned_user_id = ?
                               AND ca.status IN ('active','assigned')
                               AND i.status IN ('pending','overdue')
                             ORDER BY ca.assignment_id DESC
                             LIMIT 1";
            $assigned_stmt = $conn->prepare($assigned_sql);
            if (!$assigned_stmt) throw new Exception('Failed to check assignment: ' . $conn->error);
            $assigned_stmt->bind_param('ii', $invoice_id, $user_id);
            $assigned_stmt->execute();
            $assignment = $assigned_stmt->get_result()->fetch_assoc();
            $assigned_stmt->close();

            if (!$assignment) throw new Exception('This invoice is not assigned to you or already processed');

            if ($branch_id > 0 && (int)$assignment['branch_id'] > 0 && (int)$assignment['branch_id'] !== $branch_id) {
                throw new Exception('This invoice is not from your branch');
            }

            $check_record = $conn->prepare("SELECT status FROM collection_records WHERE invoice_id = ? AND collector_user_id = ? AND status IN ('collected','remitted') ORDER BY record_id DESC LIMIT 1");
            if ($check_record) {
                $check_record->bind_param('ii', $invoice_id, $user_id);
                $check_record->execute();
                $existing_record = $check_record->get_result()->fetch_assoc();
                $check_record->close();
                if ($existing_record) {
                    throw new Exception('This invoice already has a collection/remittance record and cannot be returned as ticket.');
                }
            }

            $conn->begin_transaction();

            $assignment_id = (int)$assignment['assignment_id'];
            $customer_id = (int)$assignment['invoice_customer_id'];
            $assigned_by = (int)($assignment['assigned_by'] ?? 0);
            $assign_branch_id = (int)($assignment['branch_id'] ?? $branch_id);

            [$return_attachment_path, $return_attachment_name] = upload_collection_photo_safe('return_photo', 'return_invoice');

            $insert_return = $conn->prepare("INSERT INTO collection_invoice_returns (assignment_id, invoice_id, customer_id, branch_id, returned_by, returned_to, return_reason, attachment_path, attachment_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'returned')");
            if (!$insert_return) throw new Exception('Failed to prepare returned invoice record: ' . $conn->error);
            $insert_return->bind_param('iiiiiisss', $assignment_id, $invoice_id, $customer_id, $assign_branch_id, $user_id, $assigned_by, $return_reason, $return_attachment_path, $return_attachment_name);
            if (!$insert_return->execute()) throw new Exception('Failed to save returned invoice record: ' . $insert_return->error);
            $insert_return->close();

            $note_text = 'Returned by collector: ' . $return_reason;
            $update_assignment = $conn->prepare("UPDATE collection_assignments SET status = 'returned', notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE CHAR(10) END, ?), updated_at = NOW() WHERE assignment_id = ? AND assigned_user_id = ? AND status IN ('active','assigned')");
            if (!$update_assignment) throw new Exception('Failed to prepare assignment update: ' . $conn->error);
            $update_assignment->bind_param('sii', $note_text, $assignment_id, $user_id);
            if (!$update_assignment->execute()) throw new Exception('Failed to return invoice ticket: ' . $update_assignment->error);
            if ($update_assignment->affected_rows <= 0) throw new Exception('Invoice ticket was already processed. Please refresh.');
            $update_assignment->close();

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Invoice ticket returned to Branch Admin successfully.'
            ]);
            exit;
        }

        // Step 2: Remit ALL collected records (submit to admin for approval)
        elseif ($action === 'remit_all_collections') {
            // Get all collected records for this collector
            $get_records = $conn->prepare("SELECT record_id FROM collection_records WHERE collector_user_id = ? AND status = 'collected'");
            $get_records->bind_param('i', $user_id);
            $get_records->execute();
            $records = $get_records->get_result()->fetch_all(MYSQLI_ASSOC);
            $get_records->close();
            
            if (count($records) == 0) {
                throw new Exception('No collected records to remit');
            }
            
            $conn->begin_transaction();
            $success_count = 0;
            
            foreach ($records as $record) {
                $update = $conn->prepare("UPDATE collection_records SET status = 'remitted', remitted_at = NOW() WHERE record_id = ? AND status = 'collected'");
                $update->bind_param('i', $record['record_id']);
                if ($update->execute()) {
                    $success_count++;
                }
                $update->close();
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => $success_count . ' collection(s) remitted successfully! Branch admin will review them.'
            ]);
            exit;
        }
        
        // Delete a collected record (if collector made a mistake)
        elseif ($action === 'delete_collection_record') {
            $record_id = (int)($_POST['record_id'] ?? 0);
            
            if ($record_id <= 0) throw new Exception('Invalid record');
            
            $delete = $conn->prepare("DELETE FROM collection_records WHERE record_id = ? AND collector_user_id = ? AND status = 'collected'");
            $delete->bind_param('ii', $record_id, $user_id);
            if (!$delete->execute()) throw new Exception('Failed to delete record');
            
            echo json_encode(['success' => true, 'message' => 'Collection record deleted.']);
            exit;
        }
        
        // Get collected records (for remittance)
        elseif ($action === 'get_collected_records') {
            $records = fetch_collected_records($conn, $user_id);
            echo json_encode(['success' => true, 'records' => $records]);
            exit;
        }

        throw new Exception('Invalid action');
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) @$conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$rows = fetch_assigned_collections($conn, $user_id, $branch_id);
$collected_records = fetch_collected_records($conn, $user_id);
$my_collection_report_rows = fetch_my_collection_report($conn, $user_id, 0);
$total_due = 0;
$overdue_count = 0;
$today_count = 0;
$today = date('Y-m-d');

foreach ($rows as $r) {
    $total_due += (float)$r['balance_amount'];
    $due = !empty($r['due_date']) ? date('Y-m-d', strtotime($r['due_date'])) : '';
    if ($due && $due < $today) $overdue_count++;
    if ($due && $due === $today) $today_count++;
}

$pending_remit_total = 0;
foreach ($collected_records as $cr) {
    $pending_remit_total += (float)$cr['amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Driver Collections - Delivery</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/delivery.css">
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root{--green: #047857;--bright: #44D34E;--dark: #052A47}
        .main-content{margin-left:260px;padding:20px}
        .mobile-toggle-btn{display:none;border:none;background:transparent;color:var(--dark);font-size:1.6rem}

        /* Remove old conflicting styles if any */
.stat-card {
    background: transparent !important;
    border: none !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    min-height: auto !important;
    height: auto !important;
    padding: 0.8rem !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    cursor: default !important;
}

/* Gradient backgrounds for each stat type */
.stat-card.assigned {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.tocollect {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.overdue {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.pending-remit {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

/* Force text colors to white */
.stat-card .stat-value,
.stat-card .stat-label,
.stat-card .stat-content,
.stat-card small,
.stat-card small i,
.stat-card .badge {
    color: white !important;
}

/* Remove any white background from stat-content or other children */
.stat-card .stat-content,
.stat-card .stat-icon {
    background: transparent !important;
}

/* ===== MOBILE: SQUARE CARDS WITH CENTERED ICON ===== */
@media (max-width: 991px) {
    .stat-card {
        aspect-ratio: 1 / 1 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        text-align: center !important;
        padding: 0.5rem !important;
    }
    
    /* Force icon to be centered */
    .stat-card i,
    .stat-card .stat-icon {
        display: block !important;
        text-align: center !important;
        margin: 0 auto 0.3rem auto !important;
        font-size: 1.6rem !important;
        width: auto !important;
        float: none !important;
        position: static !important;
        left: auto !important;
        right: auto !important;
        top: auto !important;
        bottom: auto !important;
    }
    
    .stat-card .stat-value {
        display: block !important;
        text-align: center !important;
        font-size: 1.2rem !important;
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
    }
    
    .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.7rem !important;
        font-weight: 500 !important;
        width: 100% !important;
    }
    
    .stat-card small {
        display: none !important;
    }
    
    /* Badge styling for mobile */
    .stat-card .badge {
        display: inline-block !important;
        font-size: 0.5rem !important;
        padding: 0.2rem 0.4rem !important;
        margin-top: 0.2rem !important;
        text-align: center !important;
    }
}

/* ===== DESKTOP: HORIZONTAL LAYOUT ===== */
@media (min-width: 992px) {
    .stat-card {
        align-items: flex-start !important;
        text-align: left !important;
        padding: 1rem !important;
        aspect-ratio: auto !important;
        min-height: 120px !important;
        max-height: 130px !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: flex-start !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        align-self: flex-start !important;
        margin: 0 0.75rem 0 0 !important;
        font-size: 1.6rem !important;
        display: inline-block !important;
        text-align: left !important;
    }
    
    .stat-card .stat-content {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        text-align: left !important;
        flex: 1 !important;
    }
    
    .stat-card .stat-value {
        align-self: flex-start !important;
        margin: 0 0 0.05rem 0 !important;
        font-size: 1.4rem !important;
        line-height: 1.2 !important;
        text-align: left !important;
    }
    
    .stat-card .stat-label {
        align-self: flex-start !important;
        margin-top: 0.1rem !important;
        font-size: 0.75rem !important;
        font-weight: 500 !important;
        text-align: left !important;
    }
    
    .stat-card small {
        align-self: flex-start !important;
        margin-top: 0.2rem !important;
        display: block !important;
        font-size: 0.65rem !important;
        opacity: 0.9 !important;
        text-align: left !important;
    }
}

/* ===== TABLET (768px - 991px) ===== */
@media (min-width: 768px) and (max-width: 991px) {
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.4rem !important;
        margin-bottom: 0.25rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 1rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.6rem !important;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 399px) {
    .stat-card {
        padding: 0.3rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.2rem !important;
        margin-bottom: 0.2rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.9rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.55rem !important;
    }
}

/* ===== LANDSCAPE MODE ===== */
@media (max-height: 500px) and (orientation: landscape) {
    .stat-card {
        aspect-ratio: auto !important;
        min-height: 55px !important;
        max-height: 70px !important;
        padding: 0.3rem !important;
        flex-direction: row !important;
        align-items: center !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1rem !important;
        margin: 0 0.5rem 0 0 !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.75rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.5rem !important;
    }
    
    .stat-card small {
        display: none !important;
    }
}

/* Row styling for stat cards */
.stat-card-row {
    margin-bottom: 1.5rem;
}

/* Hover effect for stat cards */
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}
/* ===== IMPROVED MOBILE TEXT RESPONSIVENESS ===== */
@media (max-width: 991px) {
    .stat-card {
        aspect-ratio: 1 / 1 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        text-align: center !important;
        padding: 0.5rem !important;
    }
    
    /* Force icon to be centered */
    .stat-card i,
    .stat-card .stat-icon {
        display: block !important;
        text-align: center !important;
        margin: 0 auto 0.3rem auto !important;
        font-size: 1.6rem !important;
        width: auto !important;
        float: none !important;
        position: static !important;
        left: auto !important;
        right: auto !important;
        top: auto !important;
        bottom: auto !important;
    }
    
    .stat-card .stat-value {
        display: block !important;
        text-align: center !important;
        font-size: 1rem !important; /* Reduced from 1.2rem */
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
        word-break: break-word !important; /* Para mag-break ang mahabang numbers */
        overflow-wrap: break-word !important;
    }
    
    .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.65rem !important; /* Reduced from 0.7rem */
        font-weight: 500 !important;
        width: 100% !important;
        word-break: break-word !important;
        white-space: normal !important; /* Para mag-wrap ang text */
        line-height: 1.3 !important;
    }
    
    /* Hide the branch name on mobile to save space */
    .stat-card small {
        display: none !important;
    }
}

/* For extra small devices (phones below 576px) */
@media (max-width: 576px) {
    .stat-card {
        padding: 0.3rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.3rem !important;
        margin-bottom: 0.2rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.85rem !important; /* Smaller font for very small screens */
    }
    
    .stat-card .stat-label {
        font-size: 0.55rem !important;
    }
}

/* For very small devices (below 400px) */
@media (max-width: 399px) {
    .stat-card {
        padding: 0.25rem !important;
    }
    
    .stat-card i,
    .stat-card .stat-icon {
        font-size: 1.1rem !important;
        margin-bottom: 0.15rem !important;
    }
    
    .stat-card .stat-value {
        font-size: 0.75rem !important;
    }
    
    .stat-card .stat-label {
        font-size: 0.5rem !important;
    }
}

/* Para sa 2-line text sa label (e.g., "Total Orders" -> pwedeng mag-break) */
.stat-card .stat-label {
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

        .card-box {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
    border: 1px solid #edf2f7;
    overflow: hidden;
}
.invoice-pill {
    display: inline-block;
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #047857;
    border-radius: 999px;
    padding: .25rem .6rem;
    font-family:monospace;
    font-weight: 600;
    font-size: .76rem;
}

.btn-collect {
    background: linear-gradient(135deg, #047857, #44D34E);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: .45rem .95rem;
    font-weight: 700;
    font-size: .78rem;
}

.btn-collect:hover {
    color: #fff;
    opacity: .95;
}

.btn-return-invoice {
    background: #052A47;
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: .45rem .95rem;
    font-weight: 700;
    font-size: .78rem;
}

.btn-return-invoice:hover {
    color: #fff;
    opacity: .95;
}

.action-buttons {
    display: flex;
    align-items: center;
    gap: .35rem;
    flex-wrap: nowrap;
    white-space: nowrap;
}

.action-buttons .btn-collect,
.action-buttons .btn-return-invoice {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 92px;
}

.btn-remit-all {
    background: linear-gradient(135deg, #047857, #44D34E);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: .6rem 1.5rem;
    font-weight: 700;
    font-size: .85rem;
}

.btn-remit-all:hover {
    color: #fff;
    opacity: .95;
}

.btn-delete {
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: .25rem .7rem;
    font-size: .7rem;
}

.btn-delete:hover {
    background: #b91c1c;
}

.badge-collected {
    background: #fff7ed;
    color: #c2410c;
    border: 1px solid #fed7aa;
}

.badge-soft-danger {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

.badge-soft-warning {
    background: #fff7ed;
    color: #c2410c;
    border: 1px solid #fed7aa;
}

.badge-soft-success {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.payment-method-option {
    cursor: pointer;
    padding: 12px;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    text-align: center;
    height: 100%;
}

.payment-method-option.active,
.payment-method-option:hover {
    border-color: #047857;
    background: #e8f5e9;
}

.payment-method-option i {
    font-size: 1.5rem;
    display: block;
    margin-bottom: .4rem;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #64748b;
}

.empty-state i {
    font-size: 2.5rem;
    color: #cbd5e1;
}

.collected-header {
    background: #f8fafc;
    border-bottom: 2px solid rgb(214, 214, 214);
}

.collected-card {
    background: #fff8e1;
    transition: all 0.2s;
}

.collected-card:hover {
    background: #fffbeb;
}

.clickable-assigned-row {
    cursor: pointer;
}

.clickable-assigned-row:hover {
    background: #f8fafc;
}

.ticket-detail-card {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 12px;
}

.ticket-detail-label {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #64748b;
    font-weight: 800;
    margin-bottom: 4px;
}

.ticket-detail-value {
    font-size: .95rem;
    color: #0f172a;
    font-weight: 700;
}

.ticket-rejection-box {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #9f1239;
    border-radius: 14px;
    padding: 14px;
    white-space: pre-wrap;
}

@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
    }
    .sidebar.active {
        transform: translateX(0);
    }
    .main-content {
        margin-left: 0;
        padding: 14px;
    }
    .mobile-toggle-btn {
        display: block;
    }
}

@media (max-width: 768px) {
    .table {
        min-width: 880px;
    }
    .stat-card {
        aspect-ratio: 1/1;
        min-height: auto;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: .55rem;
    }
    .stat-value {
        font-size: 1rem;
    }
    .stat-label {
        font-size: .62rem;
    }
    .btn-remit-all {
        width: 100%;
        margin-bottom: 10px;
    }
}

.mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #fff;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
    padding: 8px 12px;
    z-index: 1000;
    display: none;
}

.mobile-nav .nav {
    display: flex;
    justify-content: space-around;
    align-items: center;
    margin: 0;
    padding: 0;
    list-style: none;
}

.mobile-nav .nav-item {
    flex: 1;
    text-align: center;
}

.mobile-nav .nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 6px 4px;
    color: #6c757d;
    text-decoration: none;
    font-size: 0.72rem;
}

.mobile-nav .nav-link i {
    font-size: 1.25rem;
    margin-bottom: 4px;
}

.mobile-nav .nav-link.active {
    color: #047857;
}

.dropdown-more {
    position: relative;
}

.more-dropdown {
    position: absolute;
    bottom: 100%;
    right: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    min-width: 190px;
    display: none;
    margin-bottom: 8px;
    z-index: 1100;
}

.more-dropdown.show {
    display: block;
}

.more-dropdown .dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #333;
    text-decoration: none;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.85rem;
}

.more-dropdown .dropdown-item:last-child {
    border-bottom: none;
}

.more-dropdown .dropdown-item:hover {
    background: #f5f5f5;
}

@media (max-width: 992px) {
    .mobile-nav {
        display: block;
    }
    body {
        padding-bottom: 76px;
    }
}
/* ===== ASSIGNED COLLECTIONS CARDS (katulad ng customer.php) ===== */
.assigned-cards-container {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
    padding: 0.5rem 0;
}

/* Desktop: 2-3 columns */
@media (min-width: 992px) {
    .assigned-cards-container {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
}

@media (min-width: 1200px) {
    .assigned-cards-container {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Tablet: 2 columns */
@media (min-width: 768px) and (max-width: 991px) {
    .assigned-cards-container {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.875rem;
    }
}

.collected-header {
    background: #f8fafc !important;
    border-bottom: 2px solid #e5e7eb !important;
    padding: 0.75rem 1rem !important;
}

.collected-header h6 {
    color: #1f2937 !important;
    font-size: 1rem !important;
    font-weight: 600 !important;
    margin-bottom: 0 !important;
}

.collected-header h6 i {
    color: #047857 !important;
    font-size: 1rem !important;
}

.collected-header .text-muted {
    color: #6b7280 !important;
    font-size: 0.75rem !important;
}

.card-header {
    background: #048964 !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    border-radius: 12px 12px 0 0 !important;
    border: none !important;
}

.card-header h5 {
    color: white !important;
    font-weight: 600 !important;
    margin: 0 !important;
    display: flex;
    align-items: center;
}

.card-header i {
    color: white !important;
    margin-right: 8px;
}

/* Collection Card styling */
.collection-card {
    background: white;
    border-radius: 12px;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    cursor: pointer;
}

.collection-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

/* Card top row */
.card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.invoice-code {
    font-size: 0.7rem;
    font-weight: 800;
    color: #059669;
    font-family: monospace;
    background: #ecfdf5;
    padding: 0.2rem 0.5rem;
    border-radius: 9px;
    letter-spacing: 0.3px;
    border: 1px solid #059669;
}

.status-badge {
    padding: 0.2rem 0.5rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

.status-pending {
    background: #fed7aa;
    color: #92400e;
}

.status-overdue {
    background: #fee2e2;
    color: #991b1b;
}

.status-due-today {
    background: #d1fae5;
    color: #065f46;
}

/* Customer name */
.customer-name {
    font-size: 1rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 0.5rem;
}

.customer-phone {
    display: block;
    font-size: 0.7rem;
    font-weight: normal;
    color: #6c757d;
    margin-top: 0.2rem;
}

/* Invoice details */
.invoice-details {
    display: flex;
    gap: 1rem;
    margin-bottom: 0.75rem;
    font-size: 0.7rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-label {
    color: #9ca3af;
    font-size: 0.6rem;
    text-transform: uppercase;
}

.detail-value {
    font-weight: 500;
    color: #4b5563;
}

/* Amount section */
.amount-section {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    padding: 0.5rem 0;
    border-top: 1px solid #f0f0f0;
    border-bottom: 1px solid #f0f0f0;
}

.amount-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.amount-label {
    font-size: 0.6rem;
    color: #9ca3af;
    text-transform: uppercase;
}

.amount-value {
    font-size: 0.8rem;
    font-weight: 600;
}

/* Card action buttons */
.card-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}

.btn-collect-card {
    background: linear-gradient(135deg, #047857, #44D34E);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 0.3rem 0.8rem;
    font-weight: 600;
    font-size: 0.7rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-collect-card:hover {
    opacity: 0.95;
    transform: translateY(-1px);
}

.btn-return-card {
    background: #052A47;
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 0.3rem 0.8rem;
    font-weight: 600;
    font-size: 0.7rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-return-card:hover {
    opacity: 0.95;
    transform: translateY(-1px);
}

/* Mobile adjustments */
@media (max-width: 576px) {
    .collection-card {
        padding: 0.75rem;
    }
    
    .amount-section {
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 0 !important;
    }

    .amount-item {
        flex: 1 !important;
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
        min-width: 0 !important;
    }

    .amount-label {
        font-size: 0.55rem !important;
        white-space: nowrap !important;
    }

    .amount-value {
        font-size: 0.72rem !important;
        white-space: nowrap !important;
    }
    
    .card-actions {
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid #f0f0f0;
    }
    
    .btn-collect-card, .btn-return-card {
        flex: 1;
        text-align: center;
        padding: 0.4rem;
    }
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 2rem;
    color: #6b7280;
    grid-column: 1 / -1;
}

.empty-state i {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    display: block;
}



/* ===== DRIVER COLLECTIONS CARD FORMAT FIX - MATCH SALES COLLECTIONS ===== */
.assigned-cards-container {
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 0.75rem !important;
    padding: 0.5rem 0 !important;
}
@media (min-width: 992px) {
    .assigned-cards-container { grid-template-columns: repeat(2, 1fr) !important; gap: 1rem !important; }
}
@media (min-width: 1200px) {
    .assigned-cards-container { grid-template-columns: repeat(3, 1fr) !important; }
}
@media (min-width: 768px) and (max-width: 991px) {
    .assigned-cards-container { grid-template-columns: repeat(2, 1fr) !important; gap: 0.875rem !important; }
}
.collection-card {
    background: #fff !important;
    border-radius: 12px !important;
    padding: 0.875rem 1rem !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08) !important;
    border: 1px solid #e5e7eb !important;
    transition: all .2s ease !important;
    cursor: pointer !important;
}
.collection-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
    transform: translateY(-1px) !important;
}
.collection-card .card-top {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    margin-bottom: .5rem !important;
}
.collection-card .invoice-code {
    font-size: .7rem !important;
    font-weight: 800 !important;
    color: #059669 !important;
    font-family: monospace !important;
    background: #ecfdf5 !important;
    padding: .2rem .5rem !important;
    border-radius: 9px !important;
    letter-spacing: .3px !important;
}
.collection-card .customer-name {
    font-size: 1rem !important;
    font-weight: 700 !important;
    color: #1f2937 !important;
    margin-bottom: .5rem !important;
}
.collection-card .customer-phone {
    display: block !important;
    font-size: .7rem !important;
    font-weight: normal !important;
    color: #6c757d !important;
    margin-top: .2rem !important;
}
.collection-card .invoice-details {
    display: flex !important;
    gap: 1rem !important;
    margin-bottom: .75rem !important;
    font-size: .7rem !important;
}
.collection-card .detail-item {
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
    border: none !important;
    border-radius: 0 !important;
    padding: 0 !important;
    box-shadow: none !important;
    min-height: 0 !important;
    flex: unset !important;
    align-items: flex-start !important;
}
.collection-card .detail-label {
    color: #9ca3af !important;
    font-size: .6rem !important;
    text-transform: uppercase !important;
    font-weight: 700 !important;
    letter-spacing: 0 !important;
    margin: 0 !important;
}
.collection-card .detail-value {
    font-weight: 500 !important;
    color: #4b5563 !important;
    font-size: inherit !important;
    background: transparent !important;
    border-left: none !important;
    padding: 0 !important;
    box-shadow: none !important;
}
.collection-card .amount-section {
    display: flex !important;
    justify-content: space-between !important;
    margin-bottom: .75rem !important;
    padding: .5rem 0 !important;
    border-top: 1px solid #f0f0f0 !important;
    border-bottom: 1px solid #f0f0f0 !important;
    gap: 0 !important;
}
.collection-card .amount-item {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    box-shadow: none !important;
}
.collection-card .amount-label {
    font-size: .6rem !important;
    color: #9ca3af !important;
    text-transform: uppercase !important;
}
.collection-card .amount-value {
    font-size: .8rem !important;
    font-weight: 600 !important;
}
.collection-card .card-actions {
    display: flex !important;
    gap: .5rem !important;
    justify-content: flex-end !important;
    border: none !important;
    padding: 0 !important;
    margin: 0 !important;
}

.collected-cards-container {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.5rem;
    max-height: 380px;
    overflow-y: auto;
    padding: 0.2rem;
}

/* Desktop: 2 columns */
@media (min-width: 768px) {
    .collected-cards-container {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Desktop large: 3 columns */
@media (min-width: 1200px) {
    .collected-cards-container {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Scrollbar */
.collected-cards-container::-webkit-scrollbar {
    width: 4px;
}

.collected-cards-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.collected-cards-container::-webkit-scrollbar-thumb {
    background: #047857;
    border-radius: 3px;
}

.collected-record-card .collected-value {
    font-size: 0.65rem;
    color: #1f2937;
    font-weight: 500;
    text-align: right;
    background: transparent;
    border: none;
    box-shadow: none;
    padding: 0;
    margin: 0;
}

.collected-record-card .collected-amount {
    color: #047857;
    font-weight: 700;
    font-size: 0.7rem;
}

/* Collected Record Card - EXTRA COMPACT */
.collected-record-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 0.35rem;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
}

.collected-record-card:hover {
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    transform: translateY(-1px);
    border-color: #d1fae5;
}

/* Card Header - EXTRA COMPACT */
.collected-record-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.3rem;
    padding-bottom: 0.2rem;
    border-bottom: 1px solid #e5e7eb;
    background: transparent;
}

.collected-record-card .invoice-info {
    display: flex;
    gap: 0.3rem;
    align-items: center;
    flex-wrap: wrap;
}

.collected-record-card .invoice-pill {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #047857;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    font-size: 0.6rem;
    font-weight: 600;
    font-family: monospace;
}

/* Payment Badges - EXTRA COMPACT */
.collected-record-card .payment-badge {
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    font-size: 0.55rem;
    font-weight: 600;
    display: inline-block;
}

.collected-record-card .payment-cash {
    background: #d1fae5 !important;
    color: #065f46 !important;
    border: 1px solid #a7f3d0 !important;
}

.collected-record-card .payment-check {
    background: #e0e7ff !important;
    color: #3730a3 !important;
    border: 1px solid #c7d2fe !important;
}

.collected-record-card .payment-online_transfer {
    background: #fce7f3 !important;
    color: #be185d !important;
    border: 1px solid #fbcfe8 !important;
}

/* Card Body */
.collected-record-card .card-body {
    margin-top: 0;
    padding: 0;
    flex: 1;
}

/* Customer Info - EXTRA COMPACT */
.collected-record-card .customer-info {
    margin-bottom: 0.3rem;
}

.collected-record-card .customer-name {
    font-size: 0.75rem;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.collected-record-card .customer-name i {
    color: #047857;
    font-size: 0.7rem;
}

/* Collection Details - EXTRA COMPACT */
.collected-record-card .collection-details {
    background: #f8fafc;
    border-radius: 6px;
    padding: 0.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.collected-record-card .detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
}

.collected-record-card .detail-label {
    font-size: 0.5rem;
    text-transform: uppercase;
    color: #6b7280;
    font-weight: 600;
    letter-spacing: 0.3px;
    margin: 0;
    padding: 0;
}

.collected-record-card .detail-value {
    font-size: 0.65rem;
    color: #1f2937;
    font-weight: 500;
    text-align: right;
    margin: 0;
    padding: 0;
}

.collected-record-card .detail-value.amount {
    font-weight: 700;
    color: #047857;
    font-size: 0.7rem;
}

/* Remove divider lines to save height */
.collected-record-card .detail-row:not(:last-child) {
    padding-bottom: 0.1rem;
    border-bottom: none;
}

/* Card Footer - EXTRA COMPACT */
.collected-record-card .card-footer {
    margin-top: 0.3rem;
    padding-top: 0.2rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    background: white;
}

/* Delete Button - EXTRA COMPACT */
.collected-record-card .btn-delete-card {
    background: transparent;
    border: 1px solid #fecaca;
    border-radius: 5px;
    padding: 0.15rem 0.5rem;
    font-size: 0.6rem;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: #dc2626;
    cursor: pointer;
    transition: all 0.2s ease;
    width: auto;
    min-width: 55px;
}

.collected-record-card .btn-delete-card:hover {
    background: #dc2626;
    border-color: #dc2626;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.3);
}

.collected-record-card .btn-delete-card:hover i {
    color: white;
}

.collected-record-card .btn-delete-card i {
    font-size: 0.6rem;
    color: #dc2626;
    transition: color 0.2s ease;
}

/* Mobile Responsive - Cards only */
@media (max-width: 576px) {
    .collected-cards-container {
        max-height: 350px;
        gap: 0.4rem;
        padding: 0.2rem;
    }
    
    .collected-record-card {
        padding: 0.3rem;
    }
    
    .collected-record-card .customer-name {
        font-size: 0.7rem;
    }
    
    .collected-record-card .detail-label {
        font-size: 0.45rem;
    }
    
    .collected-record-card .detail-value {
        font-size: 0.6rem;
    }
    
    .collected-record-card .detail-value.amount {
        font-size: 0.65rem;
    }
    
    .collected-record-card .card-footer {
        margin-top: 0.25rem;
        padding-top: 0.15rem;
    }
    
    .collected-record-card .btn-delete-card {
        min-width: 50px;
        padding: 0.12rem 0.4rem;
        font-size: 0.55rem;
    }
    
    .collected-record-card .btn-delete-card i {
        font-size: 0.55rem;
    }
}

/* Extra small devices (below 400px) - Cards only */
@media (max-width: 400px) {
    .collected-record-card .btn-delete-card {
        min-width: 45px;
        padding: 0.1rem 0.35rem;
        font-size: 0.5rem;
    }
    
    .collected-record-card .btn-delete-card i {
        font-size: 0.5rem;
    }
}

/* Empty state */
.collected-cards-container:empty::before {
    content: "No collected records to display";
    display: block;
    grid-column: 1 / -1;
    text-align: center;
    padding: 2rem;
    color: #6b7280;
}

.btn-print-report {
    background: #047857;
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: .6rem 1.25rem;
    font-size: .85rem;
    box-shadow: 0 3px 8px rgba(4, 120, 87, .18);
    white-space: nowrap;
}

.btn-print-report:hover {
    color: #fff;
    opacity: .95;
}

/* Simple in-page print report area */
.print-only-area{display:none;}
@media print{
    body *{visibility:hidden!important;}
    #collectionReportPrintable,#collectionReportPrintable *{visibility:visible!important;}
    #collectionReportPrintable{display:block!important;position:absolute;left:0;top:0;width:100%;padding:0;background:#fff!important;color:#000!important;font-family:Arial,sans-serif!important;}
    .plain-report-header{text-align:center;margin-bottom:16px;}
    .plain-report-header h4{font-size:18px;margin:0 0 4px 0;font-weight:700;text-transform:uppercase;color:#000;}
    .plain-report-header .report-title{font-size:14px;font-weight:700;color:#000;}
    .plain-report-meta{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:12px;color:#000;}
    .plain-report-meta td{padding:3px 4px;border:none;}
    .plain-report-summary{font-size:12px;margin:8px 0 10px 0;color:#000;}
    .plain-report-table{width:100%;border-collapse:collapse;font-size:11px;color:#000;}
    .plain-report-table th,.plain-report-table td{border:1px solid #000;padding:6px 7px;text-align:left;vertical-align:top;}
    .plain-report-table th{background:#fff!important;color:#000!important;font-weight:700;}
    .plain-report-table tfoot th{font-weight:700;}
    @page{size:auto;margin:16mm;}
}
.custom-alert-success {
    background: rgba(16, 185, 129, 0.15) !important;
    border: 1px solid rgba(16, 185, 129, 0.35);
    border-radius: 8px;
    padding: 0.65rem 1rem;
    margin: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    box-shadow: none;
}

.custom-alert-success .alert-icon-wrapper i {
    font-size: 0.95rem;
    color: #047857;
}

.custom-alert-success .alert-message {
    flex: 1;
    min-width: 0;
}

.custom-alert-success .alert-text {
    font-size: 0.82rem;
    color: #047857 !important;
    line-height: 1.3;
    margin: 0;
}

.custom-alert-success .alert-text strong {
    color: #047857;
    font-weight: 800;
    background: transparent;
    padding: 0;
    border-radius: 0;
    font-size: 0.82rem;
}

.custom-alert-success .alert-remit-hint {
    display: none;
}

@media (max-width: 768px) {
    .custom-alert-success {
        padding: 0.6rem 0.85rem;
        margin: 0.6rem 0.5rem;
        gap: 0.55rem;
        border-radius: 8px;
    }

    .custom-alert-success .alert-text {
        font-size: 0.78rem;
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
                    <span class="nav-text">Delivery</span>
                </h3>
            </div>
            
           <div class="sidebar-menu">
                <ul class="nav flex-column">
                     <li class="nav-item">
                        <a class="nav-link" href="fordelivery.php">
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
                        <a class="nav-link active" href="driver_collections.php">
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

    <div class="main-content">
        <div class="navbar-top">
            <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
            <div class="page-title flex-grow-1">
                <h2>Driver Collections</h2>
                <p>Record customer collections assigned to you, then remit them for Branch Admin approval</p>
            </div>
            <button type="button" class="btn-print-report" onclick="openMyCollectionReportPrintModal()">
                <i class="bi bi-printer me-1"></i> Print Report
            </button>
        </div>

        <!-- Stats Cards -->
<div class="row stat-card-row g-2 mb-4 no-print">
    <div class="col">
        <div class="stat-card assigned">
            <i class="bi bi-clipboard-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($rows); ?></div>
                <div class="stat-label">Assigned</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card tocollect">
            <i class="bi bi-wallet2 stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($total_due); ?></div>
                <div class="stat-label">To Collect</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card overdue">
            <i class="bi bi-exclamation-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $overdue_count; ?></div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card pending-remit">
            <i class="bi bi-send-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($pending_remit_total); ?></div>
                <div class="stat-label">Pending Remit</div>
            </div>
        </div>
    </div>
</div>

        <!-- Collected Records Section (Need to Remit) - CARD DESIGN -->
<?php if (!empty($collected_records)): ?>
<div class="card-box mb-4">
    <div class="collected-header p-3 d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h6 class="mb-0"><i class="bi bi-clock-history me-2 remit-icon"></i>Collected - Ready to Remit</h6>
            <small class="text-muted"><?php echo count($collected_records); ?> record(s) | Total: <?php echo money($pending_remit_total); ?></small>
        </div>
        <button class="btn-remit-all mt-2 mt-sm-0" id="remitAllBtn" onclick="remitAllCollections()">
            <i class="bi bi-send me-2"></i>REMIT ALL (<?php echo count($collected_records); ?>)
        </button>
    </div>
    
    <!-- Collected Records Cards Container -->
    <div class="collected-cards-container p-3">
        <?php foreach ($collected_records as $cr): ?>
        <div class="collected-record-card" data-record-id="<?php echo $cr['record_id']; ?>">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div class="invoice-info">
                    <span class="invoice-pill"><?php echo esc($cr['invoice_number']); ?></span>
                </div>
                <!-- Payment badge nasa pwesto ng delete button -->
                <span class="payment-badge payment-<?php echo $cr['payment_method']; ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $cr['payment_method'])); ?>
                </span>
            </div>
            
            <div class="card-body">
                <div class="customer-info">
                    <div class="customer-name">
                        <i class="bi bi-person-circle"></i>
                        <?php echo esc($cr['customer_name']); ?>
                    </div>
                </div>
                
                <div class="collection-details">
                    <div class="detail-row">
                        <div class="detail-label">Amount Collected</div>
                        <div class="collected-value collected-amount"><?php echo money($cr['amount']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Collection Date</div>
                        <div class="collected-value"><?php echo date('M d, Y h:i A', strtotime($cr['collection_date'])); ?></div>
                    </div>
                    <?php if (!empty($cr['reference_number'])): ?>
                    <div class="detail-row">
                        <div class="detail-label">Reference No.</div>
                        <div class="detail-value"><?php echo esc($cr['reference_number']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Delete button sa right bottom -->
            <div class="card-footer">
                <button class="btn-delete-card" onclick="deleteCollectionRecord(<?php echo $cr['record_id']; ?>)">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- IMPROVED RESPONSIVE ALERT - HINDI NAKA-FIT -->
<div class="custom-alert-success">
    <div class="alert-icon-wrapper">
        <i class="bi bi-info-circle-fill"></i>
    </div>
    <div class="alert-message">
        <span class="alert-text">Click <strong>REMIT ALL</strong> to submit all collected records to Branch Admin for approval.</span>
    </div>
</div>
</div>
<?php endif; ?>

        <!-- Filters -->
        <div class="card-box p-3 mb-4">
            <div class="row g-2">
                <div class="col-md-8"><input type="text" class="form-control" id="searchInput" placeholder="Search invoice, SO number, customer."></div>
                <div class="col-md-4">
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="overdue">Overdue</option>
                        <option value="due_today">Due Today</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Assigned Collections Cards (katulad ng customer.php) -->
<div class="assigned-cards-container" id="assignedCardsContainer">
    <?php if (count($rows) > 0): ?>
        <?php foreach ($rows as $row): ?>
            <?php
                $due = !empty($row['due_date']) ? date('Y-m-d', strtotime($row['due_date'])) : '';
                $statusKey = 'pending';
                $badgeClass = 'status-pending';
                $statusText = 'Pending';
                if ($due && $due < $today) { 
                    $statusKey = 'overdue'; 
                    $badgeClass = 'status-overdue'; 
                    $statusText = 'Overdue'; 
                } elseif ($due && $due === $today) { 
                    $statusKey = 'due_today'; 
                    $badgeClass = 'status-due-today'; 
                    $statusText = 'Due Today'; 
                }
                $hasCollectedRecord = ($row['has_collected_record'] ?? 0) > 0;
                $hasRemittedRecord = ($row['has_remitted_record'] ?? 0) > 0;
                $payload = [
                    'invoice_id' => (int)$row['invoice_id'],
                    'invoice_number' => $row['invoice_number'] ?? '',
                    'customer_name' => $row['customer_name'] ?? '',
                    'total_amount' => (float)$row['total_amount'],
                    'paid_amount' => (float)$row['paid_amount'],
                    'balance_amount' => (float)$row['balance_amount'],
                    'assigned_by_name' => $row['assigned_by_name'] ?? 'Branch Admin',
                    'collection_date' => $row['collection_date'] ?? '',
                    'rejected_return_reason' => $row['rejected_return_reason'] ?? '',
                    'admin_rejection_reason' => $row['admin_rejection_reason'] ?? '',
                    'rejected_return_date' => $row['rejected_return_date'] ?? ''
                ];
            ?>
            <div class="collection-card" data-status="<?php echo esc($statusKey); ?>" onclick='openAssignedTicketDetails(<?php echo json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                <div class="card-top">
                    <span class="invoice-code"><?php echo esc($row['invoice_number']); ?></span>
                    <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span>
                </div>
                
                <div class="customer-name">
                    <?php echo esc($row['customer_name']); ?>
                    <?php if(!empty($row['phone_number'])): ?>
                        <small class="customer-phone"><?php echo esc($row['phone_number']); ?></small>
                    <?php endif; ?>
                </div>
                
                <div class="invoice-details">
                    <div class="detail-item">
                        <span class="detail-label">SO No.</span>
                        <span class="detail-value"><?php echo !empty($row['so_number']) ? esc($row['so_number']) : '—'; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Due Date</span>
                        <span class="detail-value"><?php echo $due ? esc(date('M d, Y', strtotime($due))) : '—'; ?></span>
                    </div>
                </div>
                
                <div class="amount-section">
                    <div class="amount-item">
                        <span class="amount-label">Original</span>
                        <span class="amount-value"><?php echo money($row['total_amount']); ?></span>
                    </div>
                    <div class="amount-item">
                        <span class="amount-label">Paid</span>
                        <span class="amount-value"><?php echo money($row['paid_amount']); ?></span>
                    </div>
                    <div class="amount-item">
                        <span class="amount-label">To Pay</span>
                        <span class="amount-value text-danger fw-bold"><?php echo money($row['balance_amount']); ?></span>
                    </div>
                </div>
                
                <div class="card-actions" onclick="event.stopPropagation()">
                    <?php if ($hasRemittedRecord): ?>
                        <button class="btn-collect-card" disabled style="background:#94a3b8; cursor:not-allowed;" title="Remitted and waiting for Branch Admin approval">
                            <i class="bi bi-check-circle me-1"></i>Remitted
                        </button>
                    <?php elseif ($hasCollectedRecord): ?>
                        <button class="btn-collect-card" disabled style="background:#94a3b8; cursor:not-allowed;" title="Already collected, click REMIT ALL above">
                            <i class="bi bi-check-circle me-1"></i>Collected
                        </button>
                    <?php else: ?>
                        <button class="btn-collect-card" onclick='openCollectModal(<?php echo json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="bi bi-cash me-1"></i>Collect
                        </button>
                        <button class="btn-return-card" onclick='openReturnInvoiceModal(<?php echo json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Return this invoice ticket to Branch Admin">
                            <i class="bi bi-arrow-return-left me-1"></i>Return
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h6>No assigned collections</h6>
            <p class="mb-0">Assignments will appear here once Branch Admin assigns collections to you.</p>
        </div>
    <?php endif; ?>
</div>
    </div>
</div>



<!-- Hidden printable area for Collection Report -->
<div class="print-only-area" id="collectionReportPrintable">
    <div id="collectionReportPreviewContent"></div>
</div>

<!-- Collection Report Print Filter Modal -->
<div class="modal fade" id="collectionReportPrintModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content" style="border-radius:18px;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#047857,#44D34E);color:#fff">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>Print Collection Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    This report is limited to your own account. You can filter by collection date only.
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Date From</label>
                        <input type="date" class="form-control" id="collectionReportDateFrom">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Date To</label>
                        <input type="date" class="form-control" id="collectionReportDateTo">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-print-report" onclick="printMyCollectionReport()">
                    <i class="bi bi-printer me-1"></i> Print Preview
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden simple print area for Collection Report -->
<div class="print-only-area" id="collectionReportPrintable">
    <div id="collectionReportPreviewContent"></div>
</div>

<!-- Collect Modal (Step 1) -->
<div class="modal fade" id="collectModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#047857,#44D34E);color:#fff">
                <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Step 1: Record Collection</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="collectInvoiceId">
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><div class="p-3 bg-light rounded-3"><small class="text-muted d-block">Invoice</small><strong id="modalInvoiceNo">—</strong></div></div>
                    <div class="col-md-6"><div class="p-3 bg-light rounded-3"><small class="text-muted d-block">Customer</small><strong id="modalCustomer">—</strong></div></div>
                    <div class="col-md-4"><div class="p-3 bg-light rounded-3"><small class="text-muted d-block">Original</small><strong id="modalOriginal">₱0.00</strong></div></div>
                    <div class="col-md-4"><div class="p-3 bg-light rounded-3"><small class="text-muted d-block">Paid</small><strong id="modalPaid">₱0.00</strong></div></div>
                    <div class="col-md-4"><div class="p-3 rounded-3" style="background:#fff7ed"><small class="text-muted d-block">Remaining</small><strong class="text-danger" id="modalBalance">₱0.00</strong></div></div>
                </div>

                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Note:</strong> After recording collection, click <strong>REMIT ALL</strong> button above to submit to Branch Admin.
                </div>

                <label class="form-label fw-bold">Payment Method</label>
                <div class="row g-2 mb-3">
                    <div class="col-4"><div class="payment-method-option active" data-method="cash"><i class="bi bi-cash"></i><span>Cash</span></div></div>
                    <div class="col-4"><div class="payment-method-option" data-method="check"><i class="bi bi-receipt"></i><span>Check</span></div></div>
                    <div class="col-4"><div class="payment-method-option" data-method="online_transfer"><i class="bi bi-phone"></i><span>Online</span></div></div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Amount Collected</label><input type="number" class="form-control" id="collectionAmount" min="0.01" step="0.01"></div>
                    <div class="col-md-6 cash-field"><label class="form-label">Change</label><input type="text" class="form-control" id="cashChange" value="₱0.00" readonly></div>
                    <div class="col-12"><label class="form-label">Photo Attachment</label><input type="file" class="form-control" id="collectionPhoto" accept="image/*"><small class="text-muted">Optional proof photo for this collection.</small></div>
                </div>

                <div class="mt-3 d-none" id="checkFields">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Check Date</label><input type="date" class="form-control" id="checkDate"></div>
                        <div class="col-md-6"><label class="form-label">Check Number</label><input type="text" class="form-control" id="checkNumber"></div>
                        <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" class="form-control" id="checkBankName" placeholder="Type bank name manually"></div>
                        <div class="col-md-6"><label class="form-label">Bank Branch</label><input type="text" class="form-control" id="checkBankBranch"></div>
                    </div>
                </div>

                <div class="mt-3 d-none" id="onlineFields">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Reference Number</label><input type="text" class="form-control" id="referenceNumber"></div>
                        <div class="col-md-6"><label class="form-label">Bank / Wallet</label><select class="form-select" id="bankWallet"><option value="">Select online transfer sub account</option><?php foreach ($online_transfer_accounts as $acct): ?><option value="<?php echo (int)$acct['bank_id']; ?>" data-bank-name="<?php echo esc((!empty($acct['parent_bank_name']) ? $acct['parent_bank_name'] . ' / ' : '') . $acct['bank_name']); ?>" data-bank-branch="<?php echo esc($acct['bank_branch'] ?? ''); ?>"><?php echo esc((!empty($acct['parent_bank_name']) ? $acct['parent_bank_name'] . ' / ' : '') . $acct['bank_name'] . (!empty($acct['account_number']) ? ' - ' . $acct['account_number'] : '')); ?></option><?php endforeach; ?></select></div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-success" onclick="submitCollection()">
                    <i class="bi bi-save me-1"></i>Record Collection
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Return Invoice Ticket Modal -->
<div class="modal fade" id="returnInvoiceModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px;overflow:hidden">
            <div class="modal-header" style="background:#052A47;color:#fff">
                <h5 class="modal-title"><i class="bi bi-arrow-return-left me-2"></i>Return Invoice Ticket</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="returnInvoiceId">
                <div class="alert alert-warning small">
                    <i class="bi bi-info-circle me-1"></i>
                    This will return the assigned invoice/ticket to Branch Admin. No payment will be recorded.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-12"><div class="p-3 bg-light rounded-3"><small class="text-muted d-block">Invoice</small><strong id="returnInvoiceNo">—</strong></div></div>
                    <div class="col-12"><div class="p-3 bg-light rounded-3"><small class="text-muted d-block">Customer</small><strong id="returnCustomerName">—</strong></div></div>
                    <div class="col-12"><div class="p-3 rounded-3" style="background:#fff7ed"><small class="text-muted d-block">Remaining Balance</small><strong class="text-danger" id="returnBalance">₱0.00</strong></div></div>
                </div>
                <label class="form-label fw-bold">Reason for Return</label>
                <input type="text" class="form-control" id="returnReason" list="returnReasonList" placeholder="Select or type custom reason" required>
                <datalist id="returnReasonList">
                    <option value="Customer unavailable">
                    <option value="Customer refused to pay">
                    <option value="Customer requested reschedule">
                    <option value="Wrong assignment">
                    <option value="Wrong customer details">
                    <option value="Wrong address">
                    <option value="Duplicate ticket">
                    <option value="Invoice needs admin review">
                    <option value="Customer already paid">
                    <option value="Unable to contact customer">
                    <option value="Other">
                </datalist>
                <small class="text-muted">You can select a reason or type a new one.</small>
                <div class="mt-3">
                    <label class="form-label fw-bold">Photo Attachment</label>
                    <input type="file" class="form-control" id="returnPhoto" accept="image/*">
                    <small class="text-muted">Optional photo/proof for returning this invoice ticket.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-dark" onclick="submitReturnInvoice()">
                    <i class="bi bi-send me-1"></i>Return to Admin
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Assigned Ticket Details Modal -->
<div class="modal fade" id="assignedTicketDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px;overflow:hidden">
            <div class="modal-header" style="background:#052A47;color:#fff">
                <h5 class="modal-title"><i class="bi bi-ticket-detailed me-2"></i>Collection Ticket Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-12"><div class="ticket-detail-card"><div class="ticket-detail-label">Invoice</div><div class="ticket-detail-value" id="detailInvoiceNo">—</div></div></div>
                    <div class="col-12"><div class="ticket-detail-card"><div class="ticket-detail-label">Customer</div><div class="ticket-detail-value" id="detailCustomerName">—</div></div></div>
                    <div class="col-6"><div class="ticket-detail-card"><div class="ticket-detail-label">Paid</div><div class="ticket-detail-value" id="detailPaidAmount">₱0.00</div></div></div>
                    <div class="col-6"><div class="ticket-detail-card"><div class="ticket-detail-label">Remaining</div><div class="ticket-detail-value text-danger" id="detailBalanceAmount">₱0.00</div></div></div>
                </div>
                <div id="newAssignedInfo" class="ticket-detail-card">
                    <div class="ticket-detail-label">Assigned By</div>
                    <div class="ticket-detail-value" id="detailAssignedBy">Branch Admin</div>
                    <div class="small text-muted mt-1" id="detailAssignedDate"></div>
                </div>
                <div id="rejectedReturnInfo" style="display:none">
                    <div class="ticket-detail-label mb-2">Reason for Rejection</div>
                    <div class="ticket-rejection-box" id="detailRejectionReason">—</div>
                    <div class="small text-muted mt-2" id="detailRejectedDate"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<!-- Mobile Bottom Navigation -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <li class="nav-item"><a class="nav-link" href="fordelivery.php"><i class="bi bi-truck"></i><span>Delivery</span></a></li>
        <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span>Tickets</span></a></li>
        <li class="nav-item"><a class="nav-link active" href="driver_collections.php"><i class="bi bi-cash-stack"></i><span>Collect</span></a></li>
                <li class="nav-item">
                <a class="nav-link" href="vehicle.php">
                    <i class="bi bi-car-front"></i>
                    <span>Vehicle</span>
                </a>
            </li>
        <li class="nav-item"><a class="nav-link" href="rejecteddelivery.php"><i class="bi bi-exclamation-circle"></i><span>Rejected</span></a></li>
        <li class="nav-item"><a class="nav-link logout-btn" href="#" onclick="logout(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></li>
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

<div class="sidebar-overlay" id="sidebarOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1040;display:none"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let selectedPaymentMethod = 'cash';
let currentCollectionData = null;
let currentReturnInvoiceData = null;
const registeredBanks = <?php echo $registered_banks_json ?: '[]'; ?>;
const onlineTransferAccounts = <?php echo $online_transfer_accounts_json ?: '[]'; ?>;


const myCollectionReportRows = <?php echo json_encode($my_collection_report_rows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const myCollectionReportUser = <?php echo json_encode($user_name); ?>;
const myCollectionReportBranch = <?php echo json_encode($branch_name ?? ''); ?>;
const myCollectionReportTitle = "Driver Collection Report";
const myCollectionReportRole = "Driver";

function openMyCollectionReportPrintModal(){
    const modalEl = document.getElementById('collectionReportPrintModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function normalizeReportDate(value){
    if (!value) return '';
    return String(value).slice(0, 10);
}

function reportPeso(amount){
    return '₱' + (parseFloat(amount || 0)).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function reportText(value){
    return String(value ?? '').replace(/[&<>'"]/g, function(ch){
        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch];
    });
}

function buildReportTransactionDetails(row){
    const method = String(row.payment_method || '').toLowerCase();
    const parts = [];

    if (method === 'check') {
        parts.push('Check No.: ' + (row.check_number || row.reference_number || '-'));
        parts.push('Check Date: ' + (row.check_date || '-'));
        parts.push('Bank: ' + (row.bank_name || '-'));
        parts.push('Branch: ' + (row.bank_branch || '-'));
        if (row.notes) parts.push('Notes: ' + row.notes);
    } else if (method === 'online_transfer') {
        parts.push('Bank/Wallet: ' + (row.bank_name || '-'));
        if (row.bank_branch) parts.push('Branch/Account: ' + row.bank_branch);
        parts.push('Reference No.: ' + (row.reference_number || '-'));
        if (row.notes) parts.push('Notes: ' + row.notes);
    } else {
        parts.push('Cash payment');
        if (row.notes) parts.push('Notes: ' + row.notes);
    }

    return parts.join(' | ');
}

function computePaymentMethodTotals(rows){
    return (rows || []).reduce((totals, row) => {
        const method = String(row.payment_method || '').toLowerCase();
        const amount = parseFloat(row.amount || 0) || 0;
        if (method === 'cash') totals.cash += amount;
        else if (method === 'online_transfer') totals.online_transfer += amount;
        else if (method === 'check') totals.check += amount;
        return totals;
    }, { cash: 0, online_transfer: 0, check: 0 });
}

function buildMyCollectionReportPrintContent(filteredRows, periodText, totalAmount, methodTotals){
    methodTotals = methodTotals || computePaymentMethodTotals(filteredRows);

    const rowsHtml = filteredRows.length
        ? filteredRows.map((row, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${reportText(normalizeReportDate(row.collection_date))}</td>
                <td>${reportText(row.invoice_number || '-')}</td>
                <td>${reportText(row.customer_name || '-')}</td>
                <td>${reportText(String(row.payment_method || '').replace('_', ' ').toUpperCase())}</td>
                <td>${reportText(row.reference_number || row.check_number || '-')}</td>
                <td>${reportText(buildReportTransactionDetails(row))}</td>
                <td>${reportText(String(row.status || '').toUpperCase())}</td>
                <td style="text-align:right;white-space:nowrap;">${reportPeso(row.amount)}</td>
            </tr>
        `).join('')
        : `<tr><td colspan="9" style="text-align:center;padding:14px;">No collection records found.</td></tr>`;

    return `
        <div class="plain-report-header">
            <h4>A. MACALINDONG DEVELOPMENT CORP.</h4>
            <div class="report-title">${reportText(myCollectionReportTitle)}</div>
        </div>

        <table class="plain-report-meta">
            <tr>
                <td><strong>Collector:</strong> ${reportText(myCollectionReportUser)}</td>
                <td><strong>Role:</strong> ${reportText(myCollectionReportRole)}</td>
            </tr>
            <tr>
                <td><strong>Branch:</strong> ${reportText(myCollectionReportBranch)}</td>
                <td><strong>Period:</strong> ${reportText(periodText)}</td>
            </tr>
            <tr>
                <td><strong>Printed:</strong> ${new Date().toLocaleString('en-PH')}</td>
                <td><strong>Total Records:</strong> ${filteredRows.length}</td>
            </tr>
        </table>

        <div class="plain-report-summary">
            <strong>Cash:</strong> ${reportPeso(methodTotals.cash)} &nbsp; | &nbsp;
            <strong>Online Transfer:</strong> ${reportPeso(methodTotals.online_transfer)} &nbsp; | &nbsp;
            <strong>Check:</strong> ${reportPeso(methodTotals.check)}
        </div>

        <div class="plain-report-summary">
            <strong>Total Collection:</strong> ${reportPeso(totalAmount)}
        </div>

        <table class="plain-report-table">
            <thead>
                <tr>
                    <th style="width:38px">#</th>
                    <th>Date</th>
                    <th>Invoice No.</th>
                    <th>Customer</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Transaction Details</th>
                    <th>Status</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
        </table>

        <table class="plain-report-table" style="margin-top:10px; page-break-inside:avoid; break-inside:avoid;">
            <tbody>
                <tr>
                    <th style="text-align:right;">TOTAL</th>
                    <th style="width:160px;text-align:right;white-space:nowrap;">${reportPeso(totalAmount)}</th>
                </tr>
            </tbody>
        </table>
    `;
}

function printMyCollectionReport(){
    const dateFrom = document.getElementById('collectionReportDateFrom')?.value || '';
    const dateTo = document.getElementById('collectionReportDateTo')?.value || '';

    if (dateFrom && dateTo && dateFrom > dateTo) {
        Swal.fire('Invalid Date', 'Date From cannot be later than Date To.', 'warning');
        return;
    }

    const filteredRows = (myCollectionReportRows || []).filter(row => {
        const rowDate = normalizeReportDate(row.collection_date);
        if (dateFrom && rowDate < dateFrom) return false;
        if (dateTo && rowDate > dateTo) return false;
        return true;
    });

    let totalAmount = 0;
    filteredRows.forEach(row => totalAmount += parseFloat(row.amount || 0));
    const methodTotals = computePaymentMethodTotals(filteredRows);

    const periodText = dateFrom || dateTo
        ? `${dateFrom || 'Beginning'} to ${dateTo || 'Today'}`
        : 'All Dates';

    const printArea = document.getElementById('collectionReportPreviewContent');
    if (!printArea) {
        Swal.fire('Print Error', 'Print area was not found.', 'error');
        return;
    }

    printArea.innerHTML = buildMyCollectionReportPrintContent(filteredRows, periodText, totalAmount, methodTotals);

    const modalInstance = bootstrap.Modal.getInstance(document.getElementById('collectionReportPrintModal'));
    if (modalInstance) modalInstance.hide();

    setTimeout(function(){
        window.print();
    }, 450);
}

function formatPeso(amount){return '₱'+(parseFloat(amount||0)).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function logout(){window.location.href='../logout.php';}
function cleanupBootstrapModals(){
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    if (!document.querySelector('.modal.show')) {
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }
}

function findRegisteredBank(bankName){
    const name=(bankName||'').trim().toLowerCase();
    if(!name)return null;
    return registeredBanks.find(bank => (bank.bank_name||'').trim().toLowerCase() === name) || null;
}

function autofillBankBranch(bankInputId, branchInputId){
    const bankInput=document.getElementById(bankInputId);
    const branchInput=document.getElementById(branchInputId);
    if(!bankInput || !branchInput)return;
    const bank=findRegisteredBank(bankInput.value);
    if(bank && (bank.bank_branch||'').trim() !== ''){
        branchInput.value=bank.bank_branch;
    }else if(!bank){
        branchInput.value='';
    }
}

function toggleSidebar(){
    const sidebar=document.getElementById('sidebar');
    const overlay=document.getElementById('sidebarOverlay');
    if(window.innerWidth<=992){
        sidebar.classList.toggle('active');
        overlay.style.display=sidebar.classList.contains('active')?'block':'none';
    }else{
        sidebar.classList.toggle('collapsed');
    }
}

function formatDateTimeForDetails(value){
    if(!value)return '';
    const dt=new Date(String(value).replace(' ', 'T'));
    if(isNaN(dt.getTime()))return value;
    return dt.toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});
}

function openAssignedTicketDetails(data){
    document.getElementById('detailInvoiceNo').textContent=data.invoice_number||'—';
    document.getElementById('detailCustomerName').textContent=data.customer_name||'—';
    document.getElementById('detailPaidAmount').textContent=formatPeso(data.paid_amount||0);
    document.getElementById('detailBalanceAmount').textContent=formatPeso(data.balance_amount||0);
    document.getElementById('detailAssignedBy').textContent=data.assigned_by_name||'Branch Admin';
    document.getElementById('detailAssignedDate').textContent=data.collection_date ? ('Collection date: '+formatDateTimeForDetails(data.collection_date)) : '';

    const rejectedReason=(data.admin_rejection_reason||data.rejected_return_reason||'').trim();
    const rejectedBox=document.getElementById('rejectedReturnInfo');
    const assignedBox=document.getElementById('newAssignedInfo');
    if(rejectedReason){
        assignedBox.style.display='none';
        rejectedBox.style.display='block';
        document.getElementById('detailRejectionReason').textContent=rejectedReason;
        document.getElementById('detailRejectedDate').textContent=data.rejected_return_date ? ('Rejected on: '+formatDateTimeForDetails(data.rejected_return_date)) : '';
    }else{
        rejectedBox.style.display='none';
        assignedBox.style.display='block';
    }
    new bootstrap.Modal(document.getElementById('assignedTicketDetailsModal')).show();
}
function openCollectModal(data){
    currentCollectionData=data;
    selectedPaymentMethod='cash';
    document.getElementById('collectInvoiceId').value=data.invoice_id;
    document.getElementById('modalInvoiceNo').textContent=data.invoice_number||'—';
    document.getElementById('modalCustomer').textContent=data.customer_name||'—';
    document.getElementById('modalOriginal').textContent=formatPeso(data.total_amount);
    document.getElementById('modalPaid').textContent=formatPeso(data.paid_amount);
    document.getElementById('modalBalance').textContent=formatPeso(data.balance_amount);
    document.getElementById('collectionAmount').value=parseFloat(data.balance_amount||0).toFixed(2);
    document.getElementById('cashChange').value=formatPeso(0);
    ['checkDate','checkNumber','checkBankName','checkBankBranch','referenceNumber','bankWallet','onlineBankBranch','collectionPhoto'].forEach(id=>{const el=document.getElementById(id); if(el) el.value='';});
    document.querySelectorAll('.payment-method-option').forEach(el=>el.classList.toggle('active',el.dataset.method==='cash'));
    updatePaymentFields();
    new bootstrap.Modal(document.getElementById('collectModal')).show();
}

function updatePaymentFields(){
    document.querySelectorAll('.cash-field').forEach(el=>el.classList.toggle('d-none',selectedPaymentMethod!=='cash'));
    document.getElementById('checkFields').classList.toggle('d-none',selectedPaymentMethod!=='check');
    document.getElementById('onlineFields').classList.toggle('d-none',selectedPaymentMethod!=='online_transfer');
}

function recalcChange(){
    const amount=parseFloat(document.getElementById('collectionAmount').value||0);
    const balance=parseFloat(currentCollectionData?.balance_amount||0);
    const change=(selectedPaymentMethod==='cash' && amount>balance) ? (amount-balance) : 0;
    document.getElementById('cashChange').value=formatPeso(change);
}

function openReturnInvoiceModal(data){
    currentReturnInvoiceData=data;
    document.getElementById('returnInvoiceId').value=data.invoice_id;
    document.getElementById('returnInvoiceNo').textContent=data.invoice_number||'—';
    document.getElementById('returnCustomerName').textContent=data.customer_name||'—';
    document.getElementById('returnBalance').textContent=formatPeso(data.balance_amount);
    document.getElementById('returnReason').value='';
    const rp=document.getElementById('returnPhoto'); if(rp) rp.value='';
    new bootstrap.Modal(document.getElementById('returnInvoiceModal')).show();
}

function submitReturnInvoice(){
    if(!currentReturnInvoiceData)return;
    const reason=(document.getElementById('returnReason').value||'').trim();
    if(!reason){Swal.fire('Error','Please enter reason for returning this invoice','error');return;}

    Swal.fire({
        title:'Return Invoice Ticket?',
        html:`This will return <strong>${currentReturnInvoiceData.invoice_number}</strong> to Branch Admin without recording payment.`,
        icon:'warning',
        showCancelButton:true,
        confirmButtonColor:'#052A47',
        confirmButtonText:'Yes, Return',
        cancelButtonText:'Cancel'
    }).then((result)=>{
        if(!result.isConfirmed)return;

        Swal.fire({title:'Returning...',text:'Please wait',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
        const fd=new FormData();
        fd.append('action','return_invoice_ticket');
        fd.append('invoice_id',currentReturnInvoiceData.invoice_id);
        fd.append('return_reason',reason);
        const returnPhoto=document.getElementById('returnPhoto');
        if(returnPhoto && returnPhoto.files && returnPhoto.files[0]) fd.append('return_photo', returnPhoto.files[0]);

        fetch(window.location.href,{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.success){
                Swal.fire('Returned',d.message,'success').then(()=>location.reload());
            }else{
                Swal.fire('Error',d.message||'Failed to return invoice','error');
            }
        })
        .catch(()=>Swal.fire('Error','Server returned invalid response.','error'));
    });
}

function submitCollection(){
    if(!currentCollectionData)return;
    const amount=parseFloat(document.getElementById('collectionAmount').value||0);
    const balance=parseFloat(currentCollectionData.balance_amount||0);
    if(amount<=0){Swal.fire('Error','Enter valid amount','error');return;}
    if(selectedPaymentMethod!=='cash' && amount>balance+0.009){Swal.fire('Error','Amount cannot be greater than remaining balance','error');return;}

    const fd=new FormData();
    fd.append('action','record_collection');
    fd.append('invoice_id',currentCollectionData.invoice_id);
    fd.append('payment_method',selectedPaymentMethod);
    fd.append('amount',amount.toFixed(2));
    const collectionPhoto=document.getElementById('collectionPhoto');
    if(collectionPhoto && collectionPhoto.files && collectionPhoto.files[0]) fd.append('collection_photo', collectionPhoto.files[0]);

    if(selectedPaymentMethod==='cash'){
        recalcChange();
    }else if(selectedPaymentMethod==='check'){
        fd.append('check_date',document.getElementById('checkDate').value);
        fd.append('bank_name',document.getElementById('checkBankName').value);
        fd.append('bank_branch',document.getElementById('checkBankBranch').value);
        fd.append('check_number',document.getElementById('checkNumber').value);
        
        if(!fd.get('check_date') || !fd.get('bank_name') || !fd.get('bank_branch') || !fd.get('check_number')){
            Swal.fire('Error','All check details are required','error');
            return;
        }
    }else{
        fd.append('reference_number',document.getElementById('referenceNumber').value);
        fd.append('bank_wallet',document.getElementById('bankWallet').value);
        
        if(!fd.get('reference_number') || !fd.get('bank_wallet')){
            Swal.fire('Error','Reference number and bank/wallet are required','error');
            return;
        }
    }

    Swal.fire({title:'Recording...',text:'Please wait',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    fetch(window.location.href,{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
        if(d.success){
            Swal.fire('Success',d.message,'success').then(()=>location.reload());
        }else{
            Swal.fire('Error',d.message||'Failed to record','error');
        }
    })
    .catch(()=>Swal.fire('Error','Server returned invalid response.','error'));
}

function remitAllCollections(){
    const recordCount = <?php echo count($collected_records); ?>;
    const totalAmount = '<?php echo money($pending_remit_total); ?>';
    
    if(recordCount === 0){
        Swal.fire('Info','No collections to remit','info');
        return;
    }
    
    Swal.fire({
        title: 'Remit All Collections?',
        html: `You are about to remit <strong>${recordCount}</strong> collection(s) totaling <strong>${totalAmount}</strong>.<br><br>This will submit all collected payments to Branch Admin for approval.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        confirmButtonText: 'Yes, Remit All',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if(result.isConfirmed){
            Swal.fire({title:'Submitting...',text:'Please wait',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
            
            const fd=new FormData();
            fd.append('action','remit_all_collections');
            
            fetch(window.location.href,{method:'POST',body:fd})
            .then(r=>r.json())
            .then(d=>{
                if(d.success){
                    Swal.fire('Submitted',d.message,'success').then(()=>location.reload());
                }else{
                    Swal.fire('Error',d.message||'Failed to submit','error');
                }
            })
            .catch(()=>Swal.fire('Error','Server returned invalid response.','error'));
        }
    });
}

function deleteCollectionRecord(recordId){
    Swal.fire({
        title: 'Delete Record?',
        text: 'This will remove the collection record. The invoice will become available for collection again.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if(result.isConfirmed){
            Swal.fire({title:'Deleting...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
            
            const fd=new FormData();
            fd.append('action','delete_collection_record');
            fd.append('record_id',recordId);
            
            fetch(window.location.href,{method:'POST',body:fd})
            .then(r=>r.json())
            .then(d=>{
                if(d.success){
                    Swal.fire('Deleted',d.message,'success').then(()=>location.reload());
                }else{
                    Swal.fire('Error',d.message||'Failed to delete','error');
                }
            })
            .catch(()=>Swal.fire('Error','Server returned invalid response.','error'));
        }
    });
}

function toggleMoreDropdown(event){
    event.preventDefault();
    const menu = document.getElementById('moreDropdownMenu');
    if(menu) menu.classList.toggle('show');
}

function filterRows(){
    const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const s = document.getElementById('statusFilter').value;
    const cards = document.querySelectorAll('.collection-card');
    let visibleCount = 0;
    
    cards.forEach(card => {
        let show = true;
        const cardText = card.dataset.search || '';
        const cardStatus = card.dataset.status || '';
        
        if (q && !cardText.includes(q)) show = false;
        if (s && cardStatus !== s) show = false;
        
        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    
    // Show empty state if no results
    const container = document.getElementById('assignedCardsContainer');
    const existingEmpty = container.querySelector('.empty-state:not(.permanent)');
    if (visibleCount === 0 && !existingEmpty && <?php echo count($rows); ?> > 0) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'empty-state';
        emptyDiv.innerHTML = '<i class="bi bi-search"></i><p>No matching collections found</p>';
        container.appendChild(emptyDiv);
    } else if (visibleCount > 0 && existingEmpty) {
        existingEmpty.remove();
    }
}

document.addEventListener('DOMContentLoaded',()=>{
    ['collectModal','returnInvoiceModal'].forEach(id => { const modalEl = document.getElementById(id); if (modalEl) modalEl.addEventListener('hidden.bs.modal', cleanupBootstrapModals); });
    const mobile=document.getElementById('mobileToggleBtn');
    if(mobile)mobile.addEventListener('click',toggleSidebar);
    const desktop=document.getElementById('desktopToggleBtn');
    if(desktop)desktop.addEventListener('click',toggleSidebar);
    document.getElementById('sidebarOverlay').addEventListener('click',toggleSidebar);

    document.querySelectorAll('.payment-method-option').forEach(opt=>{
        opt.addEventListener('click',function(){
            selectedPaymentMethod=this.dataset.method;
            document.querySelectorAll('.payment-method-option').forEach(el=>el.classList.remove('active'));
            this.classList.add('active');
            updatePaymentFields();
        });
    });
    document.getElementById('collectionAmount').addEventListener('input',recalcChange);
    const bankWalletSelect = document.getElementById('bankWallet');
    if (bankWalletSelect) {
        bankWalletSelect.addEventListener('change', function(){
            const opt = this.options[this.selectedIndex];
            const branchEl = document.getElementById('onlineBankBranch');
            if (branchEl && opt) branchEl.value = opt.dataset.bankBranch || '';
        });
    }
    document.getElementById('searchInput').addEventListener('input',filterRows);
    document.getElementById('statusFilter').addEventListener('change',filterRows);

    document.addEventListener('click', function(e){
        const menu = document.getElementById('moreDropdownMenu');
        if(menu && !e.target.closest('.dropdown-more')){
            menu.classList.remove('show');
        }
    });
});

// Show profile modal function
function showProfileModal() {
    const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
    profileModal.show();
}

// Confirm logout before proceeding
function confirmLogout() {
    Swal.fire({
        title: 'Logout?',
        text: 'Are you sure you want to logout?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#047857',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, logout'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '../logout.php';
        }
    });
}

// Close profile modal (optional)
function closeProfileModal() {
    const profileModal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
    if (profileModal) {
        profileModal.hide();
    }
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>