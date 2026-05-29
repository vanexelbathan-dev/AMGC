<?php
ob_start();

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Rolling Account role can access
requireLogin();
<<<<<<< HEAD
requireRole(['rolling']);
=======
requireRole(['rolling_account']);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Rolling Account';
$user_role = $_SESSION['role'] ?? 'rolling_account';
<<<<<<< HEAD
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

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

// Re-get user ID for filtering (if needed), but keep Rolling assigned branch.
if (function_exists('getUserId')) {
    $user_id = getUserId();
}
$branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? $branch_id;
$view_all_branches = false;

// Check if branch_id column exists in collection_assignments table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM collection_assignments LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Helper functions
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
=======
$branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0;
$view_all_branches = false; // Rolling accounts are restricted to their assigned branch

// Helper: ensure banks table exists and fetch active ONLINE TRANSFER sub accounts for dropdown
function createBanksTableIfNeeded($conn) {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
    if (!col_exists_safe($conn, 'banks', 'parent_bank_id')) {
        @$conn->query("ALTER TABLE banks ADD COLUMN parent_bank_id int(11) DEFAULT NULL AFTER status");
    }
    $idx_parent = $conn->query("SHOW INDEX FROM banks WHERE Key_name = 'parent_bank_id'");
    if (!$idx_parent || $idx_parent->num_rows === 0) {
        @$conn->query("ALTER TABLE banks ADD INDEX parent_bank_id (parent_bank_id)");
    }
}

function ensure_bank_payment_methods_table_safe($conn) {
    ensure_banks_table_safe($conn);
=======
    $col = $conn->query("SHOW COLUMNS FROM `banks` LIKE 'parent_bank_id'");
    if (!$col || $col->num_rows === 0) {
        @$conn->query("ALTER TABLE `banks` ADD COLUMN `parent_bank_id` int(11) DEFAULT NULL AFTER `status`");
        @$conn->query("ALTER TABLE `banks` ADD INDEX `parent_bank_id` (`parent_bank_id`)");
    }
}

function createBankPaymentMethodsTableIfNeeded($conn) {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_payment_methods` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_id` int(11) NOT NULL,
        `payment_method` enum('check','online_transfer','cash') NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_bank_method` (`bank_id`,`payment_method`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

<<<<<<< HEAD
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
    ensure_bank_payment_methods_table_safe($conn);
    $rows = [];
    $sql = "SELECT b.bank_id,
                   b.bank_name,
                   COALESCE(b.bank_branch, '') AS bank_branch,
                   COALESCE(b.account_name, '') AS account_name,
                   COALESCE(b.account_number, '') AS account_number,
                   COALESCE(pb.bank_name, '') AS parent_bank_name
            FROM banks b
            LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
            INNER JOIN bank_payment_methods bpm ON bpm.bank_id = b.bank_id AND bpm.payment_method = 'online_transfer'
            WHERE b.status = 'active'
              AND b.parent_bank_id IS NOT NULL";
    if ((int)$branch_id > 0) $sql .= " AND (b.branch_id = ? OR b.branch_id = 0)";
    $sql .= " ORDER BY COALESCE(pb.bank_name, b.bank_name) ASC, b.bank_name ASC, b.account_number ASC";
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
=======
function getOnlineTransferSubAccountsForDropdown($conn, $view_all_branches, $branch_id) {
    createBanksTableIfNeeded($conn);
    createBankPaymentMethodsTableIfNeeded($conn);

    $sql = "SELECT DISTINCT
                b.bank_id,
                b.bank_name,
                b.account_name,
                b.account_number,
                b.bank_branch,
                b.parent_bank_id,
                pb.bank_name AS parent_bank_name
            FROM banks b
            LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
            INNER JOIN bank_payment_methods bpm
                ON bpm.bank_id = b.bank_id
               AND bpm.payment_method = 'online_transfer'
            WHERE b.status = 'active'
              AND b.parent_bank_id IS NOT NULL";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (b.branch_id = ? OR b.branch_id = 0)";
    $sql .= " ORDER BY COALESCE(pb.bank_name, b.bank_name) ASC, b.bank_name ASC, b.account_number ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($rows as &$row) {
        $label_parts = [];
        if (!empty($row['parent_bank_name'])) $label_parts[] = $row['parent_bank_name'];
        if (!empty($row['bank_name'])) $label_parts[] = $row['bank_name'];
        $label = implode(' / ', $label_parts);
        if (!empty($row['account_number'])) $label .= ' - ' . $row['account_number'];
        $row['display_name'] = trim($label) !== '' ? $label : ($row['bank_name'] ?? '');
    }
    unset($row);

    return $rows;
}

$registered_banks = getOnlineTransferSubAccountsForDropdown($conn, $view_all_branches, $branch_id);
$banks_json = json_encode($registered_banks);


// ========== COLLECTION ASSIGNMENT HELPERS ==========
function collectionTableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function collectionColumnExists($conn, $table, $column) {
    if (!collectionTableExists($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function ensureCollectionAssignmentsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_assignments` (
        `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `assigned_user_id` int(11) NOT NULL,
        `assigned_by` int(11) NOT NULL DEFAULT 0,
        `collection_date` date DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`assignment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `customer_id` (`customer_id`),
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        KEY `assigned_user_id` (`assigned_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

<<<<<<< HEAD
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
=======
    $safe_alters = [
        'invoice_id' => "ALTER TABLE collection_assignments ADD COLUMN invoice_id int(11) NOT NULL DEFAULT 0 AFTER assignment_id",
        'customer_id' => "ALTER TABLE collection_assignments ADD COLUMN customer_id int(11) NOT NULL DEFAULT 0 AFTER invoice_id",
        'branch_id' => "ALTER TABLE collection_assignments ADD COLUMN branch_id int(11) NOT NULL DEFAULT 0 AFTER customer_id",
        'assigned_user_id' => "ALTER TABLE collection_assignments ADD COLUMN assigned_user_id int(11) NOT NULL DEFAULT 0 AFTER branch_id",
        'assigned_by' => "ALTER TABLE collection_assignments ADD COLUMN assigned_by int(11) NOT NULL DEFAULT 0 AFTER assigned_user_id",
        'collection_date' => "ALTER TABLE collection_assignments ADD COLUMN collection_date date DEFAULT NULL AFTER assigned_by",
        'notes' => "ALTER TABLE collection_assignments ADD COLUMN notes text DEFAULT NULL AFTER collection_date",
        'status' => "ALTER TABLE collection_assignments ADD COLUMN status enum('active','completed','cancelled') NOT NULL DEFAULT 'active' AFTER notes",
        'created_at' => "ALTER TABLE collection_assignments ADD COLUMN created_at timestamp NOT NULL DEFAULT current_timestamp() AFTER status",
        'updated_at' => "ALTER TABLE collection_assignments ADD COLUMN updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER created_at"
    ];

    foreach ($safe_alters as $col => $sql) {
        if (!collectionColumnExists($conn, 'collection_assignments', $col)) {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            @$conn->query($sql);
        }
    }

<<<<<<< HEAD
    $status = $conn->query("SHOW COLUMNS FROM collection_assignments LIKE 'status'");
    if ($status && $status->num_rows > 0) {
        $row = $status->fetch_assoc();
        if (stripos($row['Type'] ?? '', 'enum') !== false) {
            @$conn->query("ALTER TABLE collection_assignments MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'");
        }
    }

    // COLLECTION RECORDS table - for collected but not yet remitted
=======
    @$conn->query("ALTER TABLE collection_assignments MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'");
}

// ========== COLLECTION REMITTANCE TABLE ==========
function ensureCollectionRemittancesTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_remittances` (
        `remittance_id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `collector_user_id` int(11) NOT NULL,
        `payment_method` enum('cash','check','online_transfer') NOT NULL,
        `amount` decimal(12,2) NOT NULL,
        `collection_date` datetime NOT NULL,
        `remittance_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `check_date` date DEFAULT NULL,
        `bank_name` varchar(100) DEFAULT NULL,
        `bank_branch` varchar(100) DEFAULT NULL,
        `check_number` varchar(50) DEFAULT NULL,
        `cash_tendered` decimal(12,2) DEFAULT NULL,
        `cash_change` decimal(12,2) DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `approved_by` int(11) DEFAULT NULL,
        `approved_at` datetime DEFAULT NULL,
        `rejection_reason` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`remittance_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `customer_id` (`customer_id`),
        KEY `collector_user_id` (`collector_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}


// ========== COLLECTION RECORDS COMPATIBILITY TABLE ==========
// Sales/sales_collections.php saves collections here first, then changes status to remitted.
// Branch Admin must read those remitted records and approve them into payments.
function ensureCollectionRecordsTable($conn) {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    $conn->query("CREATE TABLE IF NOT EXISTS `collection_records` (
        `record_id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL,
        `customer_id` INT NOT NULL,
        `branch_id` INT NOT NULL DEFAULT 0,
        `collector_user_id` INT NOT NULL,
<<<<<<< HEAD
        `payment_method` ENUM('cash','check','online_transfer') NOT NULL,
=======
        `payment_method` VARCHAR(30) NOT NULL,
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
        `status` ENUM('collected','remitted','cancelled') NOT NULL DEFAULT 'collected',
        `remitted_at` DATETIME DEFAULT NULL,
=======
        `status` VARCHAR(30) NOT NULL DEFAULT 'collected',
        `remitted_at` DATETIME DEFAULT NULL,
        `approved_by` INT DEFAULT NULL,
        `approved_at` DATETIME DEFAULT NULL,
        `rejection_reason` TEXT DEFAULT NULL,
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `invoice_id` (`invoice_id`),
        KEY `collector_user_id` (`collector_user_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

<<<<<<< HEAD

    if (!col_exists_safe($conn, 'collection_records', 'attachment_path')) {
        @$conn->query("ALTER TABLE collection_records ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER cash_change");
    }
    if (!col_exists_safe($conn, 'collection_records', 'attachment_name')) {
        @$conn->query("ALTER TABLE collection_records ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path");
    }
    // Keep status flexible so report can include collected, remitted, approved, and completed.
    @$conn->query("ALTER TABLE collection_records MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'collected'");

    // RETURNED INVOICE TICKETS table - for agents returning assigned tickets back to admin
=======
    $safe_alters = [
        'approved_by' => "ALTER TABLE collection_records ADD COLUMN approved_by INT DEFAULT NULL AFTER remitted_at",
        'approved_at' => "ALTER TABLE collection_records ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by",
        'rejection_reason' => "ALTER TABLE collection_records ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER approved_at",
        'attachment_path' => "ALTER TABLE collection_records ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER cash_change",
        'attachment_name' => "ALTER TABLE collection_records ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path",
        'notes' => "ALTER TABLE collection_records ADD COLUMN notes TEXT DEFAULT NULL AFTER attachment_name",
        'remitted_at' => "ALTER TABLE collection_records ADD COLUMN remitted_at DATETIME DEFAULT NULL AFTER status"
    ];
    foreach ($safe_alters as $col => $sql) {
        if (!collectionColumnExists($conn, 'collection_records', $col)) {
            @$conn->query($sql);
        }
    }

    // Convert ENUM to VARCHAR so approved/rejected statuses from Branch Admin will not fail.
    @$conn->query("ALTER TABLE collection_records MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'collected'");
}


function ensureCollectionInvoiceReturnsTable($conn) {
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
=======
        `reviewed_by` INT DEFAULT NULL,
        `reviewed_at` DATETIME DEFAULT NULL,
        `rejection_reason` TEXT DEFAULT NULL,
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `assignment_id` (`assignment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `returned_by` (`returned_by`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

<<<<<<< HEAD
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

    // SOURCE 1: collection_records. This is the main and most accurate source for Sales/Driver/Rolling collections.
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
                       cr.bank_name,
                       cr.bank_branch,
                       cr.check_number,
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
    // BranchAdmin sees these too, so Sales/Driver/Rolling print report must include them when created_by is the logged-in user.
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
                       p.bank_name,
                       p.bank_branch,
                       p.check_number,
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
$registered_banks = fetch_active_banks_safe($conn, $branch_id);
$online_transfer_sub_accounts = fetch_online_transfer_sub_accounts_safe($conn, $branch_id);
$registered_banks_json = json_encode($registered_banks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$online_transfer_sub_accounts_json = json_encode($online_transfer_sub_accounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

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
                save_new_bank_if_needed($conn, $branch_id, $user_id, $bank_name, $bank_branch);
            } else {
                $reference_number = trim($_POST['reference_number'] ?? '');
                $bank_name = trim($_POST['bank_wallet'] ?? '');
                $bank_branch = trim($_POST['bank_branch'] ?? '');
                if ($reference_number === '' || $bank_name === '') {
                    throw new Exception('Reference number and bank/wallet are required');
                }
                $valid_online_accounts = fetch_online_transfer_sub_accounts_safe($conn, $branch_id);
                $valid_online = false;
                foreach ($valid_online_accounts as $online_account) {
                    if (trim((string)$online_account['bank_name']) === $bank_name) {
                        $valid_online = true;
                        if ($bank_branch === '') $bank_branch = trim((string)($online_account['bank_branch'] ?? ''));
                        break;
                    }
                }
                if (!$valid_online) {
                    throw new Exception('Please select a saved online transfer sub account.');
                }
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
=======
    $safe_alters = [
        'attachment_path' => "ALTER TABLE collection_invoice_returns ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER return_reason",
        'attachment_name' => "ALTER TABLE collection_invoice_returns ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_path",
        'status' => "ALTER TABLE collection_invoice_returns ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'returned'",
        'reviewed_by' => "ALTER TABLE collection_invoice_returns ADD COLUMN reviewed_by INT DEFAULT NULL AFTER status",
        'reviewed_at' => "ALTER TABLE collection_invoice_returns ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by",
        'rejection_reason' => "ALTER TABLE collection_invoice_returns ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER reviewed_at",
        'created_at' => "ALTER TABLE collection_invoice_returns ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($safe_alters as $col => $sql) {
        if (!collectionColumnExists($conn, 'collection_invoice_returns', $col)) {
            @$conn->query($sql);
        }
    }
}

function getAssignableCollectorsForCollections($conn, $view_all_branches, $branch_id) {
    $branch_id = (int)$branch_id;
    $has_user_branch = collectionColumnExists($conn, 'users', 'branch_id');
    $has_user_driver_id = collectionColumnExists($conn, 'users', 'driver_id');
    $has_drivers_table = collectionTableExists($conn, 'drivers');
    $has_driver_branch = $has_drivers_table && collectionColumnExists($conn, 'drivers', 'branch_id');

    $driver_join = ($has_user_driver_id && $has_driver_branch)
        ? "LEFT JOIN drivers d ON d.driver_id = u.driver_id"
        : "";

    $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.role
            FROM users u
            $driver_join
            WHERE u.status = 'active'
              AND u.role IN ('sales', 'delivery')";

    $needBranchParam = false;
    if ($branch_id > 0 && ($has_user_branch || $has_driver_branch)) {
        $branchParts = [];
        if ($has_user_branch) {
            $branchParts[] = "u.branch_id = ?";
        }
        if ($has_driver_branch && $has_user_driver_id) {
            $branchParts[] = "d.branch_id = ?";
        }
        if (!empty($branchParts)) {
            $sql .= " AND (" . implode(' OR ', $branchParts) . ")";
            $needBranchParam = true;
        }
    }

    $sql .= " ORDER BY FIELD(u.role, 'sales', 'delivery'), u.first_name ASC, u.last_name ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($needBranchParam) {
            if ($has_user_branch && $has_driver_branch && $has_user_driver_id) {
                $stmt->bind_param('ii', $branch_id, $branch_id);
            } else {
                $stmt->bind_param('i', $branch_id);
            }
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function enrichInvoicesWithCollectorAssignments($conn, &$invoices) {
    ensureCollectionAssignmentsTable($conn);
    if (!is_array($invoices) || count($invoices) === 0) return;

    foreach ($invoices as &$invoice) {
        $invoice['assigned_to_name'] = '';
        $invoice['assigned_to_role'] = '';
        $invoice['assigned_user_id'] = '';
        $invoice['collection_date'] = '';
        $invoice_id = (int)($invoice['invoice_id'] ?? 0);
        if ($invoice_id <= 0) continue;

        $sql = "SELECT ca.assigned_user_id, ca.collection_date,
                       u.first_name, u.last_name, u.role
                FROM collection_assignments ca
                LEFT JOIN users u ON u.user_id = ca.assigned_user_id
                WHERE ca.invoice_id = ?
                  AND ca.status IN ('active','assigned')
                ORDER BY ca.assignment_id DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $invoice['assigned_to_name'] = $name;
            $invoice['assigned_to_role'] = $row['role'] ?? '';
            $invoice['assigned_user_id'] = $row['assigned_user_id'] ?? '';
            $invoice['collection_date'] = $row['collection_date'] ?? '';
        }
    }
    unset($invoice);
}

function saveCollectionAssignment($conn, $invoice_id, $customer_id, $branch_id, $assigned_user_id, $assigned_by, $collection_date = '') {
    ensureCollectionAssignmentsTable($conn);

    $invoice_id = (int)$invoice_id;
    $customer_id = (int)$customer_id;
    $branch_id = (int)$branch_id;
    $assigned_user_id = (int)$assigned_user_id;
    $assigned_by = (int)$assigned_by;
    $collection_date = !empty($collection_date) ? $collection_date : date('Y-m-d');

    if ($invoice_id <= 0 || $customer_id <= 0 || $assigned_user_id <= 0) {
        throw new Exception('Invalid collection assignment data');
    }

    $cancel = $conn->prepare("UPDATE collection_assignments SET status = 'cancelled', updated_at = NOW() WHERE invoice_id = ? AND status IN ('active','assigned')");
    if ($cancel) {
        $cancel->bind_param('i', $invoice_id);
        $cancel->execute();
        $cancel->close();
    }

    $stmt = $conn->prepare("INSERT INTO collection_assignments (invoice_id, customer_id, branch_id, assigned_user_id, assigned_by, collection_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    if (!$stmt) throw new Exception('Failed to prepare collection assignment: ' . $conn->error);
    $stmt->bind_param('iiiiis', $invoice_id, $customer_id, $branch_id, $assigned_user_id, $assigned_by, $collection_date);
    if (!$stmt->execute()) throw new Exception('Failed to save collection assignment: ' . $stmt->error);
    $stmt->close();
}

function saveMultipleCollectionAssignments($conn, $invoice_ids, $assigned_user_id, $assigned_by, $collection_date = '') {
    ensureCollectionAssignmentsTable($conn);
    
    $assigned_user_id = (int)$assigned_user_id;
    $assigned_by = (int)$assigned_by;
    $collection_date = !empty($collection_date) ? $collection_date : date('Y-m-d');
    
    if ($assigned_user_id <= 0) {
        throw new Exception('Please select a collector');
    }
    
    $conn->begin_transaction();
    
    try {
        foreach ($invoice_ids as $invoice_data) {
            $invoice_id = (int)$invoice_data['invoice_id'];
            $customer_id = (int)$invoice_data['customer_id'];
            $branch_id = (int)$invoice_data['branch_id'];
            
            if ($invoice_id <= 0) continue;
            
            $cancel = $conn->prepare("UPDATE collection_assignments SET status = 'cancelled', updated_at = NOW() WHERE invoice_id = ? AND status IN ('active','assigned')");
            if ($cancel) {
                $cancel->bind_param('i', $invoice_id);
                $cancel->execute();
                $cancel->close();
            }
            
            $stmt = $conn->prepare("INSERT INTO collection_assignments (invoice_id, customer_id, branch_id, assigned_user_id, assigned_by, collection_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            if (!$stmt) throw new Exception('Failed to prepare collection assignment: ' . $conn->error);
            $stmt->bind_param('iiiiis', $invoice_id, $customer_id, $branch_id, $assigned_user_id, $assigned_by, $collection_date);
            if (!$stmt->execute()) throw new Exception('Failed to save collection assignment: ' . $stmt->error);
            $stmt->close();
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

ensureCollectionAssignmentsTable($conn);
ensureCollectionRemittancesTable($conn);
ensureCollectionRecordsTable($conn);
ensureCollectionInvoiceReturnsTable($conn);
$assignable_collectors = getAssignableCollectorsForCollections($conn, $view_all_branches, $branch_id);

// Disable error output to prevent HTML in JSON
error_reporting(0);
ini_set('display_errors', 0);

// Check if payments table exists, if not create it
$payments_table_exists = false;
$check_payments = $conn->query("SHOW TABLES LIKE 'payments'");
if ($check_payments && $check_payments->num_rows > 0) {
    $payments_table_exists = true;
} else {
    $create_sql = "CREATE TABLE IF NOT EXISTS `payments` (
        `payment_id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `payment_method` enum('cash','check','online_transfer') NOT NULL,
        `amount` decimal(12,2) NOT NULL,
        `payment_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `check_date` date DEFAULT NULL,
        `bank_name` varchar(100) DEFAULT NULL,
        `bank_branch` varchar(100) DEFAULT NULL,
        `check_number` varchar(50) DEFAULT NULL,
        `cash_tendered` decimal(12,2) DEFAULT NULL,
        `cash_change` decimal(12,2) DEFAULT NULL,
        `status` enum('completed','pending','failed') DEFAULT 'completed',
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`payment_id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `customer_id` (`customer_id`),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    if ($conn->query($create_sql)) $payments_table_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

$customers_branch_condition = "";
if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $customers_branch_condition = "AND branch_id = $branch_id";
}

// Get customers with pending/overdue invoices
$all_customers_query = "SELECT DISTINCT c.customer_id, c.customer_name, c.credit_limit, c.credit_used
                        FROM customers c
                        WHERE c.status = 'active' 
                        AND EXISTS (
                            SELECT 1 FROM invoices i 
                            WHERE i.customer_id = c.customer_id 
                            AND i.status IN ('pending', 'overdue')
                        )
                        $customers_branch_condition
                        ORDER BY c.customer_name";
$customers_result = $conn->query($all_customers_query);
$all_customers = $customers_result ? $customers_result->fetch_all(MYSQLI_ASSOC) : [];

function recalcCustomerCreditUsed($conn, $customer_id) {
    $sql = "SELECT COALESCE(SUM(GREATEST(i.total_amount - COALESCE(p.total_paid, 0), 0)), 0) AS total_unpaid
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                FROM payments
                WHERE status IS NULL OR status = 'completed'
                GROUP BY invoice_id
            ) p ON i.invoice_id = p.invoice_id
            WHERE i.customer_id = ?
            AND (
                i.status IS NULL
                OR TRIM(i.status) = ''
                OR i.status IN ('pending', 'overdue')
            )";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $unpaid = floatval($row['total_unpaid'] ?? 0);
    $stmt->close();
    $update = "UPDATE customers SET credit_used = ? WHERE customer_id = ?";
    $upd_stmt = $conn->prepare($update);
    if ($upd_stmt) {
        $upd_stmt->bind_param("di", $unpaid, $customer_id);
        $upd_stmt->execute();
        $upd_stmt->close();
    }
    return $unpaid;
}

function enrichInvoicesWithPaymentBalances($conn, &$invoices) {
    if (!is_array($invoices) || count($invoices) === 0) return;

    foreach ($invoices as &$invoice) {
        $invoice_id = (int)($invoice['invoice_id'] ?? 0);
        $original_total = (float)($invoice['total_amount'] ?? 0);
        $paid_amount = 0.0;

        if ($invoice_id > 0) {
            $paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE invoice_id = ? AND (status IS NULL OR status = 'completed')");
            if ($paid_stmt) {
                $paid_stmt->bind_param("i", $invoice_id);
                $paid_stmt->execute();
                $paid_row = $paid_stmt->get_result()->fetch_assoc();
                $paid_amount = (float)($paid_row['total_paid'] ?? 0);
                $paid_stmt->close();
            }
        }

        $balance_amount = max($original_total - $paid_amount, 0);
        $invoice['original_total_amount'] = $original_total;
        $invoice['paid_amount'] = $paid_amount;
        $invoice['balance_amount'] = $balance_amount;

        $invoice['total_amount'] = $balance_amount;
    }
    unset($invoice);
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $raw_input = file_get_contents('php://input');
    $json = json_decode($raw_input, true);
    $action = $_POST['action'] ?? ($json['action'] ?? null);

    if (!$action) {
        echo json_encode(['success' => false, 'message' => 'Invalid request: action missing']);
        exit;
    }

    try {
        // ADMIN: Get pending remittances (collections waiting for approval)
        if ($action === 'get_pending_remittances') {
            $sql = "SELECT cr.record_id AS remittance_id,
                           cr.invoice_id, cr.customer_id, cr.branch_id, cr.collector_user_id,
                           cr.payment_method, cr.amount, cr.collection_date,
                           COALESCE(cr.remitted_at, cr.created_at) AS remittance_date,
                           cr.reference_number, cr.check_date, cr.bank_name, cr.bank_branch,
                           cr.check_number, cr.cash_tendered, cr.cash_change, cr.attachment_path, cr.attachment_name, cr.notes,
                           cr.status,
                           i.invoice_number, i.invoice_date, i.due_date, i.total_amount,
                           c.customer_name,
                           u.first_name as collector_first, u.last_name as collector_last
                    FROM collection_records cr
                    LEFT JOIN invoices i ON cr.invoice_id = i.invoice_id
                    LEFT JOIN customers c ON cr.customer_id = c.customer_id
                    LEFT JOIN users u ON cr.collector_user_id = u.user_id
                    WHERE cr.status = 'remitted'";
            
            if (!$view_all_branches && $branch_id > 0) {
                $sql .= " AND cr.branch_id = " . intval($branch_id);
            }
            
            $sql .= " ORDER BY COALESCE(cr.remitted_at, cr.created_at) DESC";
            
            $result = $conn->query($sql);
            $remittances = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode(['success' => true, 'remittances' => $remittances]);
            exit;
        }
        
        // ADMIN: Approve remittance from Sales Collection remitted records
        elseif ($action === 'approve_remittance') {
            $remittance_id = (int)($json['remittance_id'] ?? ($_POST['remittance_id'] ?? 0));
            if ($remittance_id <= 0) {
                throw new Exception('Invalid remittance ID');
            }

            $remit_sql = "SELECT cr.record_id AS remittance_id,
                                 cr.invoice_id, cr.customer_id, cr.branch_id, cr.collector_user_id,
                                 cr.payment_method, cr.amount, cr.collection_date,
                                 cr.reference_number, cr.check_date, cr.bank_name, cr.bank_branch,
                                 cr.check_number, cr.cash_tendered, cr.cash_change, cr.notes,
                                 i.total_amount AS invoice_total, i.status AS invoice_status
                          FROM collection_records cr
                          LEFT JOIN invoices i ON i.invoice_id = cr.invoice_id
                          WHERE cr.record_id = ? AND cr.status = 'remitted'
                          LIMIT 1";
            $remit_stmt = $conn->prepare($remit_sql);
            if (!$remit_stmt) throw new Exception('Failed to prepare remittance lookup: ' . $conn->error);
            $remit_stmt->bind_param('i', $remittance_id);
            $remit_stmt->execute();
            $remittance = $remit_stmt->get_result()->fetch_assoc();
            $remit_stmt->close();

            if (!$remittance) {
                throw new Exception('Remittance not found or already processed');
            }

            if (!$view_all_branches && $branch_id > 0 && (int)$remittance['branch_id'] > 0 && (int)$remittance['branch_id'] !== (int)$branch_id) {
                throw new Exception('This remittance does not belong to your branch');
            }

            $invoice_id = (int)$remittance['invoice_id'];
            $customer_id = (int)$remittance['customer_id'];
            $amount = (float)$remittance['amount'];
            if ($invoice_id <= 0 || $customer_id <= 0 || $amount <= 0) {
                throw new Exception('Invalid remittance data');
            }

            $conn->begin_transaction();

            // Prevent double-posting the same approved collection record.
            $dup_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM payments WHERE invoice_id = ? AND customer_id = ? AND amount = ? AND payment_method = ? AND created_by = ? AND DATE(payment_date) = CURDATE()");
            if ($dup_stmt) {
                $dup_stmt->bind_param('iidsi', $invoice_id, $customer_id, $amount, $remittance['payment_method'], $user_id);
                $dup_stmt->execute();
                $dup_row = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ((int)($dup_row['cnt'] ?? 0) > 0) {
                    throw new Exception('Possible duplicate payment detected for this remittance today. Please refresh and check payments.');
                }
            }

            $insert_payment = "INSERT INTO payments
                               (invoice_id, customer_id, payment_method, amount, payment_date,
                                reference_number, check_date, bank_name, bank_branch, check_number,
                                cash_tendered, cash_change, status, created_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)";
            $stmt = $conn->prepare($insert_payment);
            if (!$stmt) throw new Exception('Failed to prepare payment insert: ' . $conn->error);
            $payment_date = date('Y-m-d H:i:s');
            $stmt->bind_param(
                "iisdssssssddi",
                $invoice_id,
                $customer_id,
                $remittance['payment_method'],
                $amount,
                $payment_date,
                $remittance['reference_number'],
                $remittance['check_date'],
                $remittance['bank_name'],
                $remittance['bank_branch'],
                $remittance['check_number'],
                $remittance['cash_tendered'],
                $remittance['cash_change'],
                $user_id
            );
            if (!$stmt->execute()) throw new Exception('Failed to save payment: ' . $stmt->error);
            $stmt->close();

            $paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE invoice_id = ? AND (status IS NULL OR status = 'completed')");
            $paid_stmt->bind_param('i', $invoice_id);
            $paid_stmt->execute();
            $paid_row = $paid_stmt->get_result()->fetch_assoc();
            $paid_stmt->close();

            $invoice_total = (float)($remittance['invoice_total'] ?? 0);
            $total_paid = (float)($paid_row['total_paid'] ?? 0);
            $old_status = $remittance['invoice_status'] ?? 'pending';
            $new_status = ($total_paid >= ($invoice_total - 0.009)) ? 'paid' : (($old_status === 'overdue') ? 'overdue' : 'pending');

            $update_inv = $conn->prepare("UPDATE invoices SET status = ? WHERE invoice_id = ?");
            if (!$update_inv) throw new Exception('Failed to prepare invoice update: ' . $conn->error);
            $update_inv->bind_param('si', $new_status, $invoice_id);
            $update_inv->execute();
            $update_inv->close();

            $update_record = $conn->prepare("UPDATE collection_records SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE record_id = ? AND status = 'remitted'");
            if (!$update_record) throw new Exception('Failed to prepare remittance update: ' . $conn->error);
            $update_record->bind_param('ii', $user_id, $remittance_id);
            $update_record->execute();
            if ($update_record->affected_rows <= 0) throw new Exception('Remittance was already processed. Please refresh.');
            $update_record->close();

            if ($new_status === 'paid') {
                $complete_assign = $conn->prepare("UPDATE collection_assignments SET status = 'completed', updated_at = NOW() WHERE invoice_id = ? AND status IN ('active','assigned')");
                if ($complete_assign) {
                    $complete_assign->bind_param('i', $invoice_id);
                    $complete_assign->execute();
                    $complete_assign->close();
                }
            }

            recalcCustomerCreditUsed($conn, $customer_id);
            $conn->commit();

            $remaining_balance = max($invoice_total - $total_paid, 0);
            echo json_encode([
                'success' => true,
                'message' => $new_status === 'paid'
                    ? 'Remittance approved. Invoice is now fully paid.'
                    : 'Remittance approved as partial payment. Remaining balance: ₱' . number_format($remaining_balance, 2)
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            ]);
            exit;
        }
        
<<<<<<< HEAD
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
=======
        // ADMIN: Reject remittance from Sales Collection remitted records
        elseif ($action === 'reject_remittance') {
            $remittance_id = (int)($json['remittance_id'] ?? ($_POST['remittance_id'] ?? 0));
            $rejection_reason = trim($json['rejection_reason'] ?? ($_POST['rejection_reason'] ?? ''));
            if ($remittance_id <= 0) {
                throw new Exception('Invalid remittance ID');
            }
            if ($rejection_reason === '') {
                throw new Exception('Please provide a reason for rejection');
            }

            $update_stmt = $conn->prepare("UPDATE collection_records SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE record_id = ? AND status = 'remitted'");
            if (!$update_stmt) throw new Exception('Failed to prepare rejection: ' . $conn->error);
            $update_stmt->bind_param('sii', $rejection_reason, $user_id, $remittance_id);
            if (!$update_stmt->execute()) throw new Exception('Failed to reject remittance: ' . $update_stmt->error);
            if ($update_stmt->affected_rows <= 0) throw new Exception('Remittance not found or already processed');
            $update_stmt->close();

            echo json_encode(['success' => true, 'message' => 'Remittance rejected']);
            exit;
        }
        
        // ADMIN: Approve returned invoice ticket
        elseif ($action === 'approve_return_ticket') {
            $return_id = (int)($json['return_id'] ?? ($_POST['return_id'] ?? 0));
            if ($return_id <= 0) throw new Exception('Invalid return ticket ID');

            $ret_stmt = $conn->prepare("SELECT * FROM collection_invoice_returns WHERE return_id = ? AND status IN ('returned','pending') LIMIT 1");
            if (!$ret_stmt) throw new Exception('Failed to prepare return lookup: ' . $conn->error);
            $ret_stmt->bind_param('i', $return_id);
            $ret_stmt->execute();
            $return_ticket = $ret_stmt->get_result()->fetch_assoc();
            $ret_stmt->close();

            if (!$return_ticket) throw new Exception('Return ticket not found or already processed');
            if (!$view_all_branches && $branch_id > 0 && (int)$return_ticket['branch_id'] > 0 && (int)$return_ticket['branch_id'] !== (int)$branch_id) {
                throw new Exception('This return ticket does not belong to your branch');
            }

            $conn->begin_transaction();
            $upd_ret = $conn->prepare("UPDATE collection_invoice_returns SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE return_id = ? AND status IN ('returned','pending')");
            if (!$upd_ret) throw new Exception('Failed to prepare approve return: ' . $conn->error);
            $upd_ret->bind_param('ii', $user_id, $return_id);
            $upd_ret->execute();
            if ($upd_ret->affected_rows <= 0) throw new Exception('Return ticket was already processed. Please refresh.');
            $upd_ret->close();

            $assignment_id = (int)($return_ticket['assignment_id'] ?? 0);
            if ($assignment_id > 0) {
                $cancel_assign = $conn->prepare("UPDATE collection_assignments SET status = 'cancelled', updated_at = NOW() WHERE assignment_id = ? AND status = 'returned'");
                if ($cancel_assign) {
                    $cancel_assign->bind_param('i', $assignment_id);
                    $cancel_assign->execute();
                    $cancel_assign->close();
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Return ticket approved. Invoice is now available for reassignment.']);
            exit;
        }

        // ADMIN: Reject returned invoice ticket
        elseif ($action === 'reject_return_ticket') {
            $return_id = (int)($json['return_id'] ?? ($_POST['return_id'] ?? 0));
            $rejection_reason = trim($json['rejection_reason'] ?? ($_POST['rejection_reason'] ?? ''));
            if ($return_id <= 0) throw new Exception('Invalid return ticket ID');
            if ($rejection_reason === '') throw new Exception('Please provide a reason for rejection');

            $ret_stmt = $conn->prepare("SELECT * FROM collection_invoice_returns WHERE return_id = ? AND status IN ('returned','pending') LIMIT 1");
            if (!$ret_stmt) throw new Exception('Failed to prepare return lookup: ' . $conn->error);
            $ret_stmt->bind_param('i', $return_id);
            $ret_stmt->execute();
            $return_ticket = $ret_stmt->get_result()->fetch_assoc();
            $ret_stmt->close();

            if (!$return_ticket) throw new Exception('Return ticket not found or already processed');
            if (!$view_all_branches && $branch_id > 0 && (int)$return_ticket['branch_id'] > 0 && (int)$return_ticket['branch_id'] !== (int)$branch_id) {
                throw new Exception('This return ticket does not belong to your branch');
            }

            $conn->begin_transaction();
            $upd_ret = $conn->prepare("UPDATE collection_invoice_returns SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE return_id = ? AND status IN ('returned','pending')");
            if (!$upd_ret) throw new Exception('Failed to prepare reject return: ' . $conn->error);
            $upd_ret->bind_param('sii', $rejection_reason, $user_id, $return_id);
            $upd_ret->execute();
            if ($upd_ret->affected_rows <= 0) throw new Exception('Return ticket was already processed. Please refresh.');
            $upd_ret->close();

            $assignment_id = (int)($return_ticket['assignment_id'] ?? 0);
            $invoice_id = (int)($return_ticket['invoice_id'] ?? 0);
            $customer_id = (int)($return_ticket['customer_id'] ?? 0);
            $return_branch_id = (int)($return_ticket['branch_id'] ?? 0);
            $returned_by = (int)($return_ticket['returned_by'] ?? 0);
            $reactivated_rows = 0;

            // Reject means the ticket is NOT accepted by admin, so the invoice must go back
            // to the exact collector who returned it.
            if ($assignment_id > 0) {
                $reactivate = $conn->prepare("UPDATE collection_assignments
                                             SET status = 'active', updated_at = NOW()
                                             WHERE assignment_id = ?");
                if (!$reactivate) throw new Exception('Failed to prepare assignment reactivation: ' . $conn->error);
                $reactivate->bind_param('i', $assignment_id);
                if (!$reactivate->execute()) throw new Exception('Failed to reactivate assignment: ' . $reactivate->error);
                $reactivated_rows = $reactivate->affected_rows;
                $reactivate->close();
            }

            // Fallback for older records where assignment_id was not saved correctly.
            if ($reactivated_rows <= 0 && $invoice_id > 0 && $returned_by > 0) {
                $reactivate2 = $conn->prepare("UPDATE collection_assignments
                                              SET status = 'active', updated_at = NOW()
                                              WHERE invoice_id = ?
                                                AND assigned_user_id = ?
                                                AND status IN ('returned','cancelled','','inactive')");
                if ($reactivate2) {
                    $reactivate2->bind_param('ii', $invoice_id, $returned_by);
                    if (!$reactivate2->execute()) throw new Exception('Failed to reactivate collector assignment: ' . $reactivate2->error);
                    $reactivated_rows = $reactivate2->affected_rows;
                    $reactivate2->close();
                }
            }

            // Last fallback: recreate assignment so it always appears again in collector collections.
            if ($reactivated_rows <= 0 && $invoice_id > 0 && $customer_id > 0 && $returned_by > 0) {
                $insert_assign = $conn->prepare("INSERT INTO collection_assignments
                    (invoice_id, customer_id, branch_id, assigned_user_id, assigned_by, collection_date, notes, status)
                    VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'active')");
                if (!$insert_assign) throw new Exception('Failed to recreate assignment: ' . $conn->error);
                $note = 'Return ticket rejected by Branch Admin; invoice returned to collector automatically.';
                $insert_assign->bind_param('iiiiis', $invoice_id, $customer_id, $return_branch_id, $returned_by, $user_id, $note);
                if (!$insert_assign->execute()) throw new Exception('Failed to return invoice to collector: ' . $insert_assign->error);
                $insert_assign->close();
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Return ticket rejected. Invoice is now back to the assigned collector.']);
            exit;
        }

        // COLLECTOR / BRANCH ADMIN: Direct payment collection (no approval needed)
        elseif ($action === 'submit_remittance') {
            $data = is_array($json) ? $json : $_POST;
            if (!$data || !is_array($data)) throw new Exception('Invalid payment data received');

            $remittances_data = $data['remittances'] ?? [];
            if (empty($remittances_data) || !is_array($remittances_data)) {
                throw new Exception('No payment data submitted');
            }

            $conn->begin_transaction();
            $success_count = 0;
            $total_collected_amount = 0.0;

            foreach ($remittances_data as $remit) {
                $invoice_id = (int)($remit['invoice_id'] ?? 0);
                $customer_id = (int)($remit['customer_id'] ?? 0);
                $payment_method = trim($remit['payment_method'] ?? '');
                $amount = (float)($remit['amount'] ?? 0);
                $collection_date = trim($remit['collection_date'] ?? date('Y-m-d H:i:s'));
                $payment_date = date('Y-m-d H:i:s', strtotime($collection_date ?: date('Y-m-d H:i:s')));

                if ($invoice_id <= 0) throw new Exception('Invalid invoice selected');
                if (!in_array($payment_method, ['cash', 'check', 'online_transfer'], true)) throw new Exception('Invalid payment method selected');
                if ($amount <= 0) throw new Exception('Payment amount must be greater than zero');

                $invoice_stmt = $conn->prepare("SELECT i.invoice_id, i.customer_id, i.total_amount, i.status
                                               FROM invoices i
                                               WHERE i.invoice_id = ?
                                               LIMIT 1");
                if (!$invoice_stmt) throw new Exception('Failed to prepare invoice lookup: ' . $conn->error);
                $invoice_stmt->bind_param('i', $invoice_id);
                $invoice_stmt->execute();
                $invoice_row = $invoice_stmt->get_result()->fetch_assoc();
                $invoice_stmt->close();

                if (!$invoice_row) throw new Exception('Invoice not found');
                if (($invoice_row['status'] ?? '') === 'paid') throw new Exception('This invoice is already fully paid');

                if ($customer_id <= 0) $customer_id = (int)($invoice_row['customer_id'] ?? 0);
                if ($customer_id <= 0) throw new Exception('Invalid customer selected');

                $paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid
                                             FROM payments
                                             WHERE invoice_id = ?
                                               AND (status IS NULL OR status = 'completed')");
                if (!$paid_stmt) throw new Exception('Failed to prepare payment balance lookup: ' . $conn->error);
                $paid_stmt->bind_param('i', $invoice_id);
                $paid_stmt->execute();
                $paid_row = $paid_stmt->get_result()->fetch_assoc();
                $paid_stmt->close();

                $invoice_total = (float)($invoice_row['total_amount'] ?? 0);
                $already_paid = (float)($paid_row['total_paid'] ?? 0);
                $remaining_balance = max($invoice_total - $already_paid, 0);
                if ($remaining_balance <= 0.009) throw new Exception('This invoice is already fully paid');
                if ($amount > ($remaining_balance + 0.009)) {
                    throw new Exception('Payment amount cannot be greater than the remaining balance. Remaining balance: ₱' . number_format($remaining_balance, 2));
                }

                $reference_number = !empty($remit['reference_number']) ? trim($remit['reference_number']) : null;
                $check_date = !empty($remit['check_date']) ? trim($remit['check_date']) : null;
                $bank_name = !empty($remit['bank_name']) ? trim($remit['bank_name']) : null;
                $bank_branch = !empty($remit['bank_branch']) ? trim($remit['bank_branch']) : null;
                $check_number = !empty($remit['check_number']) ? trim($remit['check_number']) : null;
                $cash_tendered = isset($remit['cash_tendered']) && $remit['cash_tendered'] !== '' ? (float)$remit['cash_tendered'] : null;
                $cash_change = isset($remit['cash_change']) && $remit['cash_change'] !== '' ? (float)$remit['cash_change'] : null;

                if ($payment_method === 'check') {
                    if ($check_date === null || $bank_name === null || $bank_branch === null || $check_number === null) {
                        throw new Exception('Please fill all check details');
                    }
                    $reference_number = $check_number;
                } elseif ($payment_method === 'online_transfer') {
                    if ($reference_number === null || $bank_name === null) {
                        throw new Exception('Please select Bank/Wallet and enter reference number for online transfer');
                    }
                    $check_date = null;
                    $bank_branch = null;
                    $check_number = null;
                    $cash_tendered = null;
                    $cash_change = null;
                } else {
                    $reference_number = null;
                    $check_date = null;
                    $bank_name = null;
                    $bank_branch = null;
                    $check_number = null;
                    $cash_tendered = null;
                    $cash_change = null;
                }

                $insert_payment = "INSERT INTO payments
                                   (invoice_id, customer_id, payment_method, amount, payment_date,
                                    reference_number, check_date, bank_name, bank_branch, check_number,
                                    cash_tendered, cash_change, status, created_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)";
                $stmt = $conn->prepare($insert_payment);
                if (!$stmt) throw new Exception('Failed to prepare payment insert: ' . $conn->error);
                $stmt->bind_param(
                    'iisdssssssddi',
                    $invoice_id,
                    $customer_id,
                    $payment_method,
                    $amount,
                    $payment_date,
                    $reference_number,
                    $check_date,
                    $bank_name,
                    $bank_branch,
                    $check_number,
                    $cash_tendered,
                    $cash_change,
                    $user_id
                );
                if (!$stmt->execute()) throw new Exception('Failed to save payment: ' . $stmt->error);
                $stmt->close();

                $new_total_paid = $already_paid + $amount;
                $old_status = $invoice_row['status'] ?? 'pending';
                $new_status = ($new_total_paid >= ($invoice_total - 0.009)) ? 'paid' : (($old_status === 'overdue') ? 'overdue' : 'pending');

                $update_inv = $conn->prepare("UPDATE invoices SET status = ? WHERE invoice_id = ?");
                if (!$update_inv) throw new Exception('Failed to prepare invoice update: ' . $conn->error);
                $update_inv->bind_param('si', $new_status, $invoice_id);
                if (!$update_inv->execute()) throw new Exception('Failed to update invoice status: ' . $update_inv->error);
                $update_inv->close();

                if ($new_status === 'paid') {
                    $complete_assign = $conn->prepare("UPDATE collection_assignments
                                                       SET status = 'completed', updated_at = NOW()
                                                       WHERE invoice_id = ?
                                                         AND status IN ('active','assigned')");
                    if ($complete_assign) {
                        $complete_assign->bind_param('i', $invoice_id);
                        $complete_assign->execute();
                        $complete_assign->close();
                    }
                }

                recalcCustomerCreditUsed($conn, $customer_id);
                $success_count++;
                $total_collected_amount += $amount;
            }

            if ($success_count <= 0) {
                throw new Exception('No payment was collected');
            }

            $conn->commit();

            echo json_encode([
                'success' => true,
                'message' => $success_count . ' payment(s) collected successfully. Total collected: ₱' . number_format($total_collected_amount, 2)
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            ]);
            exit;
        }
        
<<<<<<< HEAD
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
=======
        // Get all pending invoices (with balance info)
        elseif ($action === 'get_all_pending_invoices') {
            $start_date = trim($_POST['start_date'] ?? ($json['start_date'] ?? ''));
            $end_date = trim($_POST['end_date'] ?? ($json['end_date'] ?? ''));

            $sql = "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.status, i.customer_id,
                           COALESCE(so.order_status, '') AS order_status, so.so_number, COALESCE(so.branch_id, c.branch_id, 0) AS branch_id,
                           c.customer_name, c.credit_limit, c.credit_used
                    FROM invoices i
                    LEFT JOIN sales_orders so ON i.so_id = so.so_id
                    LEFT JOIN customers c ON i.customer_id = c.customer_id
                    WHERE i.status IN ('pending', 'overdue')
                    AND c.status = 'active'";
            if (!empty($start_date) && !empty($end_date)) $sql .= " AND DATE(i.invoice_date) BETWEEN ? AND ?";
            if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) $sql .= " AND c.branch_id = " . intval($branch_id);
            $sql .= " ORDER BY i.invoice_date DESC";

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Failed to prepare invoice query: ' . $conn->error);
            if (!empty($start_date) && !empty($end_date)) $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Check for pending remittances to exclude them from being collected again
            foreach ($invoices as &$invoice) {
                $pending_remit_sql = "SELECT COUNT(*) as cnt FROM collection_records WHERE invoice_id = ? AND status = 'remitted'";
                $pending_stmt = $conn->prepare($pending_remit_sql);
                $pending_stmt->bind_param('i', $invoice['invoice_id']);
                $pending_stmt->execute();
                $pending_result = $pending_stmt->get_result()->fetch_assoc();
                $invoice['has_pending_remittance'] = ($pending_result['cnt'] > 0);
                $pending_stmt->close();
                
                $payment_sql = "SELECT p.*, u.first_name, u.last_name 
                                FROM payments p
                                LEFT JOIN users u ON p.created_by = u.user_id
                                WHERE p.invoice_id = ?
                                ORDER BY p.payment_date DESC LIMIT 1";
                $payment_stmt = $conn->prepare($payment_sql);
                if ($payment_stmt) {
                    $payment_stmt->bind_param("i", $invoice['invoice_id']);
                    $payment_stmt->execute();
                    $invoice['payment'] = $payment_stmt->get_result()->fetch_assoc();
                    $payment_stmt->close();
                } else $invoice['payment'] = null;
            }

            enrichInvoicesWithPaymentBalances($conn, $invoices);
            enrichInvoicesWithCollectorAssignments($conn, $invoices);

            echo json_encode(['success' => true, 'invoices' => $invoices]);
            exit;
        }
        
        // Get specific customer invoices
        elseif ($action === 'get_all_invoices') {
            $customer_id = (int)($_POST['customer_id'] ?? ($json['customer_id'] ?? 0));
            $start_date = trim($_POST['start_date'] ?? ($json['start_date'] ?? ''));
            $end_date = trim($_POST['end_date'] ?? ($json['end_date'] ?? ''));

            if (!$customer_id) throw new Exception('Invalid customer');

            $sql = "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.status,
                           COALESCE(so.order_status, '') AS order_status, so.so_number,
                           c.customer_name, c.customer_id
                    FROM invoices i
                    LEFT JOIN sales_orders so ON i.so_id = so.so_id
                    LEFT JOIN customers c ON i.customer_id = c.customer_id
                    WHERE i.customer_id = ?
                    AND i.status IN ('pending', 'overdue')";
            if (!empty($start_date) && !empty($end_date)) $sql .= " AND DATE(i.invoice_date) BETWEEN ? AND ?";
            $sql .= " ORDER BY i.invoice_date DESC";

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Failed to prepare invoice query: ' . $conn->error);
            if (!empty($start_date) && !empty($end_date)) $stmt->bind_param("iss", $customer_id, $start_date, $end_date);
            else $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $credit_sql = "SELECT credit_limit, credit_used FROM customers WHERE customer_id = ?";
            $credit_stmt = $conn->prepare($credit_sql);
            $credit_stmt->bind_param("i", $customer_id);
            $credit_stmt->execute();
            $credit_data = $credit_stmt->get_result()->fetch_assoc() ?: [];
            $credit_stmt->close();
            $credit_limit = (float)($credit_data['credit_limit'] ?? 0);
            $credit_used = recalcCustomerCreditUsed($conn, $customer_id);

            foreach ($invoices as &$invoice) {
                // Check for pending remittance
                $pending_remit_sql = "SELECT COUNT(*) as cnt FROM collection_records WHERE invoice_id = ? AND status = 'remitted'";
                $pending_stmt = $conn->prepare($pending_remit_sql);
                $pending_stmt->bind_param('i', $invoice['invoice_id']);
                $pending_stmt->execute();
                $pending_result = $pending_stmt->get_result()->fetch_assoc();
                $invoice['has_pending_remittance'] = ($pending_result['cnt'] > 0);
                $pending_stmt->close();
                
                $payment_sql = "SELECT p.*, u.first_name, u.last_name 
                                FROM payments p
                                LEFT JOIN users u ON p.created_by = u.user_id
                                WHERE p.invoice_id = ?
                                ORDER BY p.payment_date DESC LIMIT 1";
                $payment_stmt = $conn->prepare($payment_sql);
                if ($payment_stmt) {
                    $payment_stmt->bind_param("i", $invoice['invoice_id']);
                    $payment_stmt->execute();
                    $invoice['payment'] = $payment_stmt->get_result()->fetch_assoc();
                    $payment_stmt->close();
                } else $invoice['payment'] = null;
            }

            enrichInvoicesWithPaymentBalances($conn, $invoices);
            enrichInvoicesWithCollectorAssignments($conn, $invoices);

            echo json_encode([
                'success' => true,
                'invoices' => $invoices,
                'credit_limit' => $credit_limit,
                'credit_used' => $credit_used,
                'available_credit' => $credit_limit - $credit_used
            ]);
            exit;
        }
        
        // Assign collector to multiple invoices
        elseif ($action === 'assign_multiple_collectors') {
            $data = is_array($json) ? $json : $_POST;
            if (!$data || !is_array($data)) throw new Exception('Invalid assignment data received');
            
            $assigned_user_id = (int)($data['assigned_user_id'] ?? ($data['collector_id'] ?? 0));
            $collection_date = trim($data['collection_date'] ?? date('Y-m-d'));
            $selected_invoices = $data['selected_invoices'] ?? [];
            
            if (empty($selected_invoices) || !is_array($selected_invoices)) {
                throw new Exception('No invoices selected for assignment');
            }
            
            if ($assigned_user_id <= 0) {
                throw new Exception('Please select a collector');
            }
            
            // Validate collector
            $has_user_branch = collectionColumnExists($conn, 'users', 'branch_id');
            $has_user_driver_id = collectionColumnExists($conn, 'users', 'driver_id');
            $has_driver_branch = collectionTableExists($conn, 'drivers') && collectionColumnExists($conn, 'drivers', 'branch_id');
            $driver_join = ($has_user_driver_id && $has_driver_branch) ? "LEFT JOIN drivers d ON d.driver_id = u.driver_id" : "";
            $collector_sql = "SELECT DISTINCT u.user_id
                              FROM users u
                              $driver_join
                              WHERE u.user_id = ?
                                AND u.status = 'active'
                                AND u.role IN ('sales','delivery')";
            $branchParams = [];
            if ($branch_id > 0 && ($has_user_branch || ($has_driver_branch && $has_user_driver_id))) {
                $branchParts = [];
                if ($has_user_branch) {
                    $branchParts[] = "u.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if ($has_driver_branch && $has_user_driver_id) {
                    $branchParts[] = "d.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if (!empty($branchParts)) $collector_sql .= " AND (" . implode(' OR ', $branchParts) . ")";
            }
            
            $collector_stmt = $conn->prepare($collector_sql);
            if (!$collector_stmt) throw new Exception('Failed to prepare collector lookup: ' . $conn->error);
            if (count($branchParams) === 2) {
                $collector_stmt->bind_param('iii', $assigned_user_id, $branchParams[0], $branchParams[1]);
            } elseif (count($branchParams) === 1) {
                $collector_stmt->bind_param('ii', $assigned_user_id, $branchParams[0]);
            } else {
                $collector_stmt->bind_param('i', $assigned_user_id);
            }
            $collector_stmt->execute();
            $collector_exists = $collector_stmt->get_result()->fetch_assoc();
            $collector_stmt->close();
            if (!$collector_exists) throw new Exception('Selected collector must be an active Sales Agent or Driver registered in your branch.');
            
            $invoice_ids_for_assignment = [];
            foreach ($selected_invoices as $inv) {
                $invoice_ids_for_assignment[] = [
                    'invoice_id' => $inv['invoice_id'],
                    'customer_id' => $inv['customer_id'],
                    'branch_id' => $inv['branch_id']
                ];
            }
            
            saveMultipleCollectionAssignments($conn, $invoice_ids_for_assignment, $assigned_user_id, (int)$user_id, $collection_date);
            
            echo json_encode(['success' => true, 'message' => count($selected_invoices) . ' invoice(s) assigned to collector successfully.']);
            exit;
        }
        
        // Assign collector to single invoice
        elseif ($action === 'assign_collector') {
            $data = is_array($json) ? $json : $_POST;
            if (!$data || !is_array($data)) throw new Exception('Invalid assignment data received');

            $invoice_id = (int)($data['invoice_id'] ?? 0);
            $assigned_user_id = (int)($data['assigned_user_id'] ?? ($data['collector_id'] ?? 0));
            $collection_date = trim($data['collection_date'] ?? '');

            if ($invoice_id <= 0) throw new Exception('Invalid invoice selected');
            if ($assigned_user_id <= 0) throw new Exception('Please select a collector');

            $inv_sql = "SELECT i.invoice_id, i.customer_id, i.status, COALESCE(i.total_amount, 0) AS total_amount, COALESCE(so.branch_id, c.branch_id, 0) AS source_branch_id
                        FROM invoices i
                        LEFT JOIN sales_orders so ON i.so_id = so.so_id
                        LEFT JOIN customers c ON i.customer_id = c.customer_id
                        WHERE i.invoice_id = ?
                        LIMIT 1";
            $inv_stmt = $conn->prepare($inv_sql);
            if (!$inv_stmt) throw new Exception('Failed to prepare invoice lookup: ' . $conn->error);
            $inv_stmt->bind_param('i', $invoice_id);
            $inv_stmt->execute();
            $invoice = $inv_stmt->get_result()->fetch_assoc();
            $inv_stmt->close();

            if (!$invoice) throw new Exception('Invoice not found');
            if (($invoice['status'] ?? '') === 'paid') throw new Exception('This invoice is already paid');

            if (!$view_all_branches && $branch_id > 0) {
                $invoice_branch_id = (int)($invoice['source_branch_id'] ?? 0);
                if ($invoice_branch_id > 0 && $invoice_branch_id !== (int)$branch_id) {
                    throw new Exception('Invoice does not belong to your branch');
                }
            }

            $has_user_branch = collectionColumnExists($conn, 'users', 'branch_id');
            $has_user_driver_id = collectionColumnExists($conn, 'users', 'driver_id');
            $has_driver_branch = collectionTableExists($conn, 'drivers') && collectionColumnExists($conn, 'drivers', 'branch_id');
            $driver_join = ($has_user_driver_id && $has_driver_branch) ? "LEFT JOIN drivers d ON d.driver_id = u.driver_id" : "";
            $collector_sql = "SELECT DISTINCT u.user_id
                              FROM users u
                              $driver_join
                              WHERE u.user_id = ?
                                AND u.status = 'active'
                                AND u.role IN ('sales','delivery')";
            $branchParams = [];
            if ($branch_id > 0 && ($has_user_branch || ($has_driver_branch && $has_user_driver_id))) {
                $branchParts = [];
                if ($has_user_branch) {
                    $branchParts[] = "u.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if ($has_driver_branch && $has_user_driver_id) {
                    $branchParts[] = "d.branch_id = ?";
                    $branchParams[] = $branch_id;
                }
                if (!empty($branchParts)) $collector_sql .= " AND (" . implode(' OR ', $branchParts) . ")";
            }

            $collector_stmt = $conn->prepare($collector_sql);
            if (!$collector_stmt) throw new Exception('Failed to prepare collector lookup: ' . $conn->error);
            if (count($branchParams) === 2) {
                $collector_stmt->bind_param('iii', $assigned_user_id, $branchParams[0], $branchParams[1]);
            } elseif (count($branchParams) === 1) {
                $collector_stmt->bind_param('ii', $assigned_user_id, $branchParams[0]);
            } else {
                $collector_stmt->bind_param('i', $assigned_user_id);
            }
            $collector_stmt->execute();
            $collector_exists = $collector_stmt->get_result()->fetch_assoc();
            $collector_stmt->close();
            if (!$collector_exists) throw new Exception('Selected collector must be an active Sales Agent or Driver registered in your branch.');

            $conn->begin_transaction();
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? (int)$branch_id : (int)($invoice['source_branch_id'] ?? 0);
            saveCollectionAssignment($conn, $invoice_id, (int)$invoice['customer_id'], $effective_branch_id, $assigned_user_id, (int)$user_id, $collection_date);
            $conn->commit();

            echo json_encode(['success' => true, 'message' => 'Collector assigned successfully.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

<<<<<<< HEAD
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
=======
// Load default pending invoices for initial view
$default_invoices_query = "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.status, i.customer_id,
                           COALESCE(so.order_status, '') AS order_status, so.so_number, COALESCE(so.branch_id, c.branch_id, 0) AS branch_id,
                           c.customer_name
                    FROM invoices i
                    LEFT JOIN sales_orders so ON i.so_id = so.so_id
                    LEFT JOIN customers c ON i.customer_id = c.customer_id
                    WHERE i.status IN ('pending', 'overdue')
                    AND c.status = 'active'
                    AND NOT EXISTS (
                        SELECT 1 FROM collection_records cr
                        WHERE cr.invoice_id = i.invoice_id AND cr.status = 'remitted'
                    )";
if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $default_invoices_query .= " AND c.branch_id = " . intval($branch_id);
}
$default_invoices_query .= " ORDER BY i.invoice_date DESC";
$default_invoices_result = $conn->query($default_invoices_query);
$default_invoices = $default_invoices_result ? $default_invoices_result->fetch_all(MYSQLI_ASSOC) : [];
enrichInvoicesWithPaymentBalances($conn, $default_invoices);
enrichInvoicesWithCollectorAssignments($conn, $default_invoices);

// Get pending remittances for display
$pending_remittances_query = "SELECT cr.record_id AS remittance_id,
                              cr.invoice_id, cr.customer_id, cr.branch_id, cr.collector_user_id,
                              cr.payment_method, cr.amount, cr.collection_date,
                              COALESCE(cr.remitted_at, cr.created_at) AS remittance_date,
                              cr.reference_number, cr.check_date, cr.bank_name, cr.bank_branch,
                              cr.check_number, cr.cash_tendered, cr.cash_change, cr.attachment_path, cr.attachment_name, cr.notes,
                              cr.status,
                              i.invoice_number, i.invoice_date, i.due_date, i.total_amount,
                              c.customer_name,
                              u.first_name as collector_first, u.last_name as collector_last
                       FROM collection_records cr
                       LEFT JOIN invoices i ON cr.invoice_id = i.invoice_id
                       LEFT JOIN customers c ON cr.customer_id = c.customer_id
                       LEFT JOIN users u ON cr.collector_user_id = u.user_id
                       WHERE cr.status = 'remitted'";
if (!$view_all_branches && $branch_id > 0) {
    $pending_remittances_query .= " AND cr.branch_id = " . intval($branch_id);
}
$pending_remittances_query .= " ORDER BY COALESCE(cr.remitted_at, cr.created_at) DESC";
$pending_remittances_result = $conn->query($pending_remittances_query);
$pending_remittances = $pending_remittances_result ? $pending_remittances_result->fetch_all(MYSQLI_ASSOC) : [];

// Returned invoice tickets from collectors
$returned_invoices_query = "SELECT cir.return_id, cir.assignment_id, cir.invoice_id, cir.customer_id, cir.branch_id,
                                   cir.returned_by, cir.returned_to, cir.return_reason, cir.attachment_path, cir.attachment_name,
                                   cir.status, cir.created_at,
                                   i.invoice_number, i.total_amount, i.status AS invoice_status,
                                   COALESCE(pay.total_paid, 0) AS paid_amount,
                                   GREATEST(COALESCE(i.total_amount, 0) - COALESCE(pay.total_paid, 0), 0) AS balance_amount,
                                   c.customer_name,
                                   u.first_name AS returned_first, u.last_name AS returned_last
                            FROM collection_invoice_returns cir
                            LEFT JOIN invoices i ON i.invoice_id = cir.invoice_id
                            LEFT JOIN (
                                SELECT invoice_id, COALESCE(SUM(amount), 0) AS total_paid
                                FROM payments
                                WHERE status IS NULL OR status = 'completed'
                                GROUP BY invoice_id
                            ) pay ON pay.invoice_id = cir.invoice_id
                            LEFT JOIN customers c ON c.customer_id = cir.customer_id
                            LEFT JOIN users u ON u.user_id = cir.returned_by
                            WHERE cir.status IN ('returned','pending')";
if (!$view_all_branches && $branch_id > 0) {
    $returned_invoices_query .= " AND cir.branch_id = " . intval($branch_id);
}
$returned_invoices_query .= " ORDER BY cir.created_at DESC";
$returned_invoices_result = $conn->query($returned_invoices_query);
$returned_invoices = $returned_invoices_result ? $returned_invoices_result->fetch_all(MYSQLI_ASSOC) : [];

// Statistics calculations
$receivables_query = "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.status, i.paid_at,
                             COALESCE(so.order_status, '') AS order_status, so.delivery_date,
                             p.payment_date as actual_payment_date
                      FROM invoices i
                      LEFT JOIN sales_orders so ON i.so_id = so.so_id
                      LEFT JOIN payments p ON i.invoice_id = p.invoice_id
                      WHERE i.status IN ('pending', 'overdue')
                      AND i.customer_id IN (SELECT customer_id FROM customers WHERE status = 'active')
                      AND NOT EXISTS (
                          SELECT 1 FROM collection_records cr
                          WHERE cr.invoice_id = i.invoice_id AND cr.status = 'remitted'
                      )";
if ($customers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $receivables_query .= " AND EXISTS (SELECT 1 FROM customers c WHERE c.customer_id = i.customer_id AND c.branch_id = $branch_id)";
}
$receivables_query .= " ORDER BY i.invoice_date DESC";
$receivables_result = $conn->query($receivables_query);
$receivables = $receivables_result ? $receivables_result->fetch_all(MYSQLI_ASSOC) : [];
enrichInvoicesWithPaymentBalances($conn, $receivables);

$total_receivables = 0;
$overdue_receivables = 0;
$aging_1_7 = 0;
$aging_8_14 = 0;
$aging_15_21 = 0;
$aging_22_28 = 0;
$aging_beyond_28 = 0;
$total_days_outstanding = 0;
$count_unpaid = 0;

$current_date = new DateTime();
foreach ($receivables as $inv) {
    $amount = floatval($inv['total_amount'] ?? 0);
    $total_receivables += $amount;
    $invoice_date = $inv['invoice_date'] ? new DateTime($inv['invoice_date']) : null;
    if ($invoice_date && $amount > 0) {
        $days_outstanding = $current_date->diff($invoice_date)->days;
        $total_days_outstanding += $days_outstanding;
        $count_unpaid++;
    }
    $due_date = $inv['due_date'] ? new DateTime($inv['due_date']) : null;
    if ($due_date && $due_date < $current_date) {
        $overdue_receivables += $amount;
        $days_overdue = $current_date->diff($due_date)->days;
        if ($days_overdue <= 7) $aging_1_7 += $amount;
        elseif ($days_overdue <= 14) $aging_8_14 += $amount;
        elseif ($days_overdue <= 21) $aging_15_21 += $amount;
        elseif ($days_overdue <= 28) $aging_22_28 += $amount;
        else $aging_beyond_28 += $amount;
    }
}
$avg_collection_days = $count_unpaid > 0 ? round($total_days_outstanding / $count_unpaid) : 0;

$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) if (!empty($part)) $user_initials .= strtoupper(substr($part, 0, 1));
}
if (empty($user_initials)) $user_initials = 'BA';

$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    if ($branch_stmt) {
        $branch_stmt->bind_param("i", $branch_id);
        $branch_stmt->execute();
        $branch_result = $branch_stmt->get_result();
        if ($branch_row = $branch_result->fetch_assoc()) $branch_name = $branch_row['branch_name'];
        $branch_stmt->close();
    }
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
<<<<<<< HEAD
    <title>My Collections - Rolling Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
=======
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collections - Branch Admin</title>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
<<<<<<< HEAD
    <link rel="stylesheet" href="../css/sales.css">
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
        :root {
    --green: #047857;
    --bright: #44D34E;
    --dark: #052A47;
}

body {
    background: #f4f6f9;
    font-family: Segoe UI, sans-serif;
}

.main-content {
    margin-left: 260px;
    padding: 20px;
}

.navbar-top {
    display: flex;
    align-items: center;
    gap: 1rem;
    justify-content: space-between;
    margin-bottom: 24px;
    background: #fff;
    padding: 14px 20px;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
}

.mobile-toggle-btn {
    display: none;
    border: none;
    background: transparent;
    color: var(--dark);
    font-size: 1.6rem;
}

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
.stat-card.total {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.sales {
    background: linear-gradient(135deg, #047857, #059669) !important;
}

.stat-card.complete {
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
    background: linear-gradient(135deg, #f59e0b, #d97706);
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
    background: #fff8e1;
    border-bottom: 2px solid #f59e0b;
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
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .amount-item {
        flex-direction: row;
        justify-content: space-between;
    }
    
    .amount-label {
        font-size: 0.65rem;
    }
    
    .amount-value {
        font-size: 0.75rem;
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

        .btn-print-report{background:linear-gradient(135deg,#047857,#44D34E);color:#fff;border:none;border-radius:999px;padding:.6rem 1.25rem;font-weight:800;font-size:.85rem;box-shadow:0 3px 8px rgba(4,120,87,.18);white-space:nowrap}
        .btn-print-report:hover{color:#fff;opacity:.95}

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


/* ===== CUSTOMER-STYLE COLLAPSIBLE FILTER FOR COLLECTIONS ===== */

.filter-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #edf2f7;
    background: #fff;
}



.filter-header h5 i {
    color: #047857;
}

.filter-toggle-btn {
    width: 38px;
    height: 38px;
    border: none;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .2s ease;
}

.filter-toggle-btn:hover {
    background: #d1fae5;
    transform: translateY(-1px);
}

.filter-toggle-btn[aria-expanded="true"] i {
    transform: rotate(180deg);
}

.filter-toggle-btn i {
    transition: transform .2s ease;
}

.filter-content {
    padding: 1rem 1.1rem;
    border-top: 1px solid #f3f4f6;
    transition: max-height .25s ease, opacity .2s ease, padding .2s ease;
    max-height: 260px;
    opacity: 1;
    overflow: hidden;
}

.filter-content.collapsed {
    max-height: 0;
    opacity: 0;
    padding-top: 0;
    padding-bottom: 0;
    border-top: 0;
}

.filter-content .form-label {
    font-size: .78rem;
    font-weight: 800;
    color: #334155;
    margin-bottom: .35rem;
}

.filter-active-badge {
    display: none;
    align-items: center;
    gap: .35rem;
    font-size: .7rem;
    font-weight: 800;
    padding: .25rem .55rem;
    border-radius: 999px;
    background: #fff7ed;
    color: #c2410c;
    border: 1px solid #fed7aa;
}

.filter-active-badge.show {
    display: inline-flex;
}

.btn-clear-filter {
    background: #f8fafc;
    color: #052A47;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-weight: 700;
}

.btn-clear-filter:hover {
    background: #ecfdf5;
    color: #047857;
    border-color: #047857;
}

@media (max-width: 768px) {
    .form-card.collection-filter-card {
        top: 8px;
    }
    .filter-header {
        padding: .85rem;
    }
    .filter-header h5 {
        font-size: .9rem;
    }
    .filter-content {
        padding: .85rem;
    }
}

    
/* ===== MOBILE MORE NAV + RESPONSIVE COLLECTIONS ===== */
.mobile-nav .nav-link.active::after{content:'';position:absolute;bottom:-2px;left:50%;transform:translateX(-50%);width:30px;height:2px;background:#047857;border-radius:2px}
.mobile-nav .nav-link{position:relative;gap:2px}
.mobile-nav .nav-link span{font-size:.65rem;white-space:nowrap}
.more-dropdown{min-width:220px;overflow:hidden}
.more-dropdown .dropdown-item i{font-size:1rem;color:#047857;margin:0;width:20px;text-align:center}
.more-dropdown .logout-item i{color:#dc2626}
.more-dropdown .logout-item{color:#dc2626;font-weight:700}
@media (max-width: 992px){
    body{padding-bottom:78px!important;overflow-x:hidden}
    .main-content{margin-left:0!important;padding:12px!important;width:100%!important;max-width:100%!important}
    .navbar-top{align-items:flex-start!important;gap:.75rem!important;padding:12px!important;border-radius:14px!important}
    .navbar-top .page-title h2{font-size:1.2rem!important;line-height:1.2!important}
    .navbar-top .page-title p{font-size:.78rem!important;line-height:1.25!important}
    .card-box,.filter-card,.report-card,.section-card{border-radius:14px!important}
    .table-responsive{overflow-x:auto!important;-webkit-overflow-scrolling:touch!important}
    .assigned-cards-container{grid-template-columns:1fr!important;gap:.7rem!important}
    .collection-card{padding:.8rem!important;border-radius:12px!important}
    .card-actions{display:flex!important;gap:.45rem!important;flex-wrap:wrap!important}
    .card-actions button{flex:1 1 120px!important}
    .modal-dialog{margin:.5rem!important;max-width:calc(100% - 1rem)!important}
    .modal-content{border-radius:16px!important}
    .mobile-nav{display:block!important}
    .mobile-nav .nav{gap:0!important}
    .mobile-nav .nav-item{min-width:0!important}
}
@media (max-width: 420px){
    .mobile-nav{padding:7px 6px!important}
    .mobile-nav .nav-link{font-size:.62rem!important;padding:5px 2px!important}
    .mobile-nav .nav-link i{font-size:1.1rem!important}
    .mobile-nav .nav-link span{font-size:.58rem!important}
    .more-dropdown{right:-6px!important;min-width:205px!important}
}

</style>
</head>
<body>
<div id="appPage">
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>
                <button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list" id="toggleIcon"></i></button>
                <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon">
                <span class="nav-text">Rolling Account</span>
            </h3>
        </div>
        <div class="sidebar-content">
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link" href="current_inventory.php"><i class="bi bi-box-seam"></i><span class="nav-text">Current Inventory</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="customer_orderproduct.php"><i class="bi bi-people"></i><span class="nav-text">Orders</span></a></li>
                    <li class="nav-item"><a class="nav-link active" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span class="nav-text">Sales Orders</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="purchase_order.php"><i class="bi bi-truck"></i><span class="nav-text">Receive Inventory</span></a></li>
                    <li class="nav-item">
                    <a class="nav-link" href="expenses.php">
                        <i class="bi bi-receipt-cutoff"></i>
                        <span class="nav-text">Expenses</span>
                    </a>
                </li>
                <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                            <span class="nav-text">Reports</span>
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
                    <span class="user-role-sidebar"><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></span>
                </div>
            </div>
            <button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button>
        </div>
    </div>

    <div class="main-content">
        <div class="navbar-top">
            <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>My Collections</h2>
                <p class="dashboardSubtitle">Step 1: Record Collection | Step 2: Click REMIT ALL to submit</p>
            </div>
            <button type="button" class="btn-print-report" onclick="openMyCollectionReportPrintModal()">
                <i class="bi bi-printer me-1"></i> Print Report
            </button>
        </div>

        <!-- Stats Cards -->
<div class="row stat-card-row g-2 mb-4 no-print">
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-clipboard-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($rows); ?></div>
                <div class="stat-label">Assigned</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-wallet2 stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo money($total_due); ?></div>
                <div class="stat-label">To Collect</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-exclamation-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $overdue_count; ?></div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card complete">
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
            <h6 class="mb-0"><i class="bi bi-clock-history me-2 text-warning"></i>Collected - Ready to Remit</h6>
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
                        <div class="detail-value amount"><?php echo money($cr['amount']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Collection Date</div>
                        <div class="detail-value"><?php echo date('M d, Y h:i A', strtotime($cr['collection_date'])); ?></div>
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
    
    <div class="alert alert-warning m-3 small">
        <i class="bi bi-info-circle me-1"></i>
        Click <strong>REMIT ALL</strong> to submit all collected records to Branch Admin for approval.
    </div>
</div>
<?php endif; ?>

        <!-- Search and Filter - Customer-style collapsible -->
        <div class="form-card collection-filter-card mb-4">
            <div class="filter-header">
                <h5>
                    <i class="bi bi-search"></i> Search & Filter Collections
                    <span class="filter-active-badge" id="filterActiveBadge">
                        <i class="bi bi-funnel-fill"></i> Active
                    </span>
                </h5>
                <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false" title="Show/Hide Filter">
                    <i class="bi bi-chevron-down" id="filterIcon"></i>
                </button>
            </div>
            
            <div class="filter-content collapsed" id="filterContent">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-7">
                        <label class="form-label">
                            <i class="bi bi-search"></i> Search Collection
                        </label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Invoice, SO number, customer, phone...">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-funnel"></i> Status
                        </label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="overdue">Overdue</option>
                            <option value="due_today">Due Today</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="button" class="btn btn-clear-filter w-100" id="clearFilterBtn">
                            <i class="bi bi-x-circle me-1"></i> Clear
                        </button>
=======
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
        /* All original CSS remains */
        :root {
            --green: #2E7D32;
            --green-haze: #1B5E20;
            --deep-sea: #0D4C14;
            --forest-green: #1B4D1F;
            --yellow: #FFC107;
            --white: #FFFFFF;
            --light-gray: #F5F5F5;
            --black: #212121;
        }

        body {
            background-color: #f4f6f9;
            font-family: 'Alice', 'Segoe UI', sans-serif;
        }

        .main-content {
            margin-left: 260px;
            padding: 20px;
            transition: all 0.3s;
        }

        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            background: white;
            padding: 12px 20px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--green);
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
            }
            .mobile-menu-btn {
                display: block;
            }
        }

        .form-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .filter-header h5 {
            margin: 0;
            font-weight: 600;
        }

        .data-table {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .credit-summary {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .credit-item {
            background: white;
            padding: 8px 16px;
            border-radius: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .btn-pay {
            background: var(--green);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .btn-pay:hover {
            background: var(--green-haze);
        }

        .btn-approve {
            background: #28a745;
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.75rem;
            transition: all 0.2s;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.75rem;
            transition: all 0.2s;
            margin-left: 5px;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .badge-pending-remit {
            background: #ffc107;
            color: #212121;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-overdue {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-pending {
            background: #ffc107;
            color: #212121;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-paid {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .payment-method-option {
            cursor: pointer;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            text-align: center;
            transition: all 0.2s;
        }
        .payment-method-option:hover {
            border-color: var(--green);
            background: #f8f9fa;
        }
        .payment-method-option.active {
            border-color: var(--green);
            background: #e8f5e9;
        }

        .payment-method-option.active i,
        .payment-method-option.active span {
            color: var(--green) !important;
        }

        .payment-method-option i,
        .payment-method-option span {
            transition: color 0.2s ease;
        }
        .payment-method-option i {
            font-size: 1.8rem;
            display: block;
            margin-bottom: 8px;
        }
        .payment-detail-group {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .invoice-row {
            cursor: pointer;
            transition: all 0.2s;
        }

        .invoice-row:hover {
            background-color: #f5f5f5;
        }

        .payment-details-modal .modal-body {
            padding: 20px;
        }

        .payment-detail-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .payment-detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
            margin-bottom: 5px;
        }

        .payment-detail-value {
            font-size: 1rem;
            color: #212121;
        }

        @media (max-width: 768px) {
            .credit-summary {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
        }

        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding: 8px 12px;
            z-index: 1000;
            display: none;
        }

        @media (max-width: 992px) {
            .mobile-nav {
                display: block;
            }
            body {
                padding-bottom: 70px;
            }
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
            font-size: 0.75rem;
            position: relative;
        }

        .mobile-nav .nav-link i {
            font-size: 1.3rem;
            margin-bottom: 4px;
        }

        .mobile-nav .nav-link.active {
            color: #2E7D32;
        }

        .mobile-nav .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 2px;
            background-color: #2E7D32;
            border-radius: 2px;
        }

        .dropdown-more {
            position: relative;
        }

        .more-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
        }

        .more-dropdown {
            position: absolute;
            bottom: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            min-width: 180px;
            display: none;
            margin-bottom: 8px;
            border: 1px solid rgba(0,0,0,0.08);
            z-index: 1000;
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
        }

        .more-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }

        .more-dropdown .dropdown-item:hover {
            background: #f5f5f5;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
            display: none;
        }

        .sidebar-overlay.active {
            display: block;
        }

        .customer-selector {
            margin-bottom: 20px;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
        }
        
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 8px;
        }
        
        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            border: 1px solid #ced4da;
            border-radius: 8px;
            height: 42px;
            padding: 5px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 30px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        
        .select2-dropdown {
            border-radius: 8px;
            border-color: #ced4da;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #2E7D32;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Filter Section Styles */
        .supplier-filter-card {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .supplier-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .supplier-filter-header h5 {
            font-size: 0.9rem;
            font-weight: 600;
            margin: 0;
            color: #333;
        }

        .supplier-filter-header h5 i {
            margin-right: 8px;
            color: #047857;
        }

        .supplier-filter-toggle-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 4px 8px;
            transition: transform 0.3s ease;
        }

        .supplier-filter-toggle-btn i {
            font-size: 1rem;
        }

        .supplier-filter-content {
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .supplier-filter-content.collapsed {
            display: none;
        }

        .supplier-filter-one-line {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .filter-item {
            flex: 1;
            min-width: 160px;
        }

        .filter-item.search-item {
            min-width: 200px;
        }

        .supplier-filter-label {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            margin-bottom: 4px;
            display: block;
        }

        .supplier-filter-select,
        .supplier-filter-input {
            width: 100%;
            padding: 8px 12px;
            font-size: 0.85rem;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            transition: all 0.2s;
        }

        .supplier-filter-select:focus,
        .supplier-filter-input:focus {
            outline: none;
            border-color: #047857;
            box-shadow: 0 0 0 2px rgba(4,120,87,0.1);
        }

        .supplier-search-wrapper {
            position: relative;
        }

        .supplier-search-wrapper .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 0.9rem;
        }

        .supplier-search-wrapper .supplier-filter-input {
            padding-left: 32px;
        }

        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            height: 38px !important;
            padding: 0 8px !important;
            border: 1px solid #e0e0e0 !important;
            border-radius: 8px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px !important;
            font-size: 0.85rem !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px !important;
        }

        .select2-dropdown {
            border-radius: 8px !important;
            border-color: #e0e0e0 !important;
        }

        @media (max-width: 768px) {
            .supplier-filter-one-line {
                flex-direction: column;
                gap: 12px;
            }
            
            .filter-item {
                width: 100%;
            }
        }
        
        /* Table Styles */
        .data-table {
            background: transparent !important;
            border-radius: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
        }

        .table {
            background: white !important;
            border-radius: 0 !important;
            overflow: hidden !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03) !important;
        }

        .table thead th {
            background: #047857 !important;
            border-bottom: 1px solid #e9ecef !important;
            font-weight: 600 !important;
            font-size: 0.75rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            color: #ffffff !important;
            padding: 10px 12px !important;
        }

        .table td {
            padding: 10px 12px !important;
            vertical-align: middle !important;
            border-bottom: 1px solid #f0f2f4 !important;
            font-size: 0.8rem !important;
        }

        .table tr:hover {
            background-color: #f8f9fa !important;
        }

        /* Checkbox column */
        .checkbox-column {
            width: 40px !important;
            text-align: center !important;
        }
        
        .select-all-checkbox {
            cursor: pointer;
        }
        
        .row-checkbox {
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        
        /* Batch assign bar */
        .batch-assign-bar {
            background: linear-gradient(135deg, #047857, #059669);
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .batch-assign-bar .selected-count {
            font-weight: 600;
        }
        
        .batch-assign-bar .btn-assign-batch {
            background: white;
            color: #047857;
            border: none;
            border-radius: 30px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .batch-assign-bar .btn-assign-batch:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .batch-assign-bar .btn-clear-selection {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 30px;
            padding: 6px 16px;
            transition: all 0.2s;
        }
        
        .batch-assign-bar .btn-clear-selection:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Pending Remittances Section */
        .pending-remittances-section {
            background: white;
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .section-header {
            background: linear-gradient(135deg, #ff9800, #fb8c00);
            color: white;
            padding: 12px 20px;
            font-weight: 600;
        }
        
        .section-header i {
            margin-right: 8px;
        }
        
        .remittance-row {
            border-bottom: 1px solid #f0f2f4;
            transition: background 0.2s;
        }
        
        .remittance-row:hover {
            background: #f8f9fa;
        }
        
        .remittance-actions {
            white-space: nowrap;
        }
        
        /* Assigned collector badge */
        .assigned-collector-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e8f5e9;
            color: #047857;
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .assigned-date-small {
            display: block;
            font-size: 0.65rem;
            color: #6c757d;
            margin-top: 2px;
        }
        
        /* Remittance card for mobile */
        .remittance-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 4px solid #ff9800;
        }
        
        .remittance-card .remittance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        /* Quick Stats Cards */
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

        .stat-card.total {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card.pending {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card.overdue {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card.aging {
            background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
        }

        .stat-card .stat-value,
        .stat-card .stat-label,
        .stat-card .stat-content,
        .stat-card small,
        .stat-card small i,
        .stat-card .badge {
            color: white !important;
        }

        .stat-card .stat-content,
        .stat-card .stat-icon {
            background: transparent !important;
        }
        
        /* Aging Modal Styles */
        #agingModal .modal-dialog {
            margin: 1rem auto !important;
            max-width: 550px !important;
        }

        @media (max-width: 768px) {
            #agingModal .modal-dialog {
                margin: 0.75rem auto !important;
                max-width: calc(100% - 1.5rem) !important;
                width: calc(100% - 1.5rem) !important;
            }
        }

        #agingModal .modal-content {
            border: none !important;
            border-radius: 24px !important;
            overflow: hidden !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
            max-height: 90vh !important;
            display: flex !important;
            flex-direction: column !important;
        }

        #agingModal .modal-header {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
            color: white !important;
            border-bottom: none !important;
            padding: 1rem 1.25rem !important;
            flex-shrink: 0 !important;
        }

        #agingModal .modal-header .modal-title {
            font-weight: 600 !important;
            font-size: 1.1rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            color: white !important;
        }

        #agingModal .modal-header .btn-close {
            background: rgba(255, 255, 255, 0.25) !important;
            border-radius: 50% !important;
            width: 34px !important;
            height: 34px !important;
            padding: 0 !important;
            margin: -0.5rem -0.5rem -0.5rem auto !important;
            opacity: 1 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.2s ease !important;
            background-image: none !important;
        }

        #agingModal .modal-body {
            padding: 1.25rem !important;
            overflow-y: auto !important;
            flex: 1 !important;
            background: #f8fafc !important;
        }

        .aging-item {
            background: white !important;
            border-radius: 12px !important;
            padding: 0.875rem 1rem !important;
            margin-bottom: 0.75rem !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03) !important;
            cursor: pointer;
        }

        .aging-item:hover {
            background: #f1f5f9 !important;
            transform: translateX(2px) !important;
        }
        
        .range-badge {
            display: inline-block !important;
            padding: 0.25rem 0.6rem !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            border-radius: 20px !important;
            color: white !important;
        }
        
        .bg-warning {
            background-color: #2dc937 !important;
        }

        .bg-orange {
            background-color: #99c140 !important;
        }

        .bg-info {
            background-color: #e7b416 !important;
        }

        .bg-danger {
            background-color: #db7b2b !important;
        }

        .bg-dark {
            background-color: #cc3232 !important;
        }
        
        .invoice-detail-item {
            background: white;
            border-radius: 12px;
            padding: 12px 15px;
            margin-bottom: 10px;
            border-left: 4px solid;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        @media (max-width: 576px) {
            .stat-card {
                aspect-ratio: 1 / 1 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                text-align: center !important;
                padding: 0.5rem !important;
            }
            
            .stat-card i,
            .stat-card .stat-icon {
                display: block !important;
                text-align: center !important;
                margin: 0 auto 0.3rem auto !important;
                font-size: 1.6rem !important;
                width: auto !important;
                float: none !important;
            }
            
            .stat-card .stat-value {
                display: block !important;
                text-align: center !important;
                font-size: 1.2rem !important;
                font-weight: bold !important;
                line-height: 1.2 !important;
                margin: 0.2rem 0 !important;
            }
            
            .stat-card .stat-label {
                display: block !important;
                text-align: center !important;
                font-size: 0.7rem !important;
                font-weight: 500 !important;
            }
            
            .stat-card small {
                display: none !important;
            }
        }
        
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
    </style>
</head>
<body>
<div id="appPage">
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>
            <button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list"></i></button>
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
                    <a class="nav-link" href="customer_orderproduct.php">
                        <i class="bi bi-person-plus"></i>
                        <span class="nav-text">Orders</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="collections.php">
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
        <button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button>
    </div>
</div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="navbar-top no-print">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title"><h2>Collections</h2><p>Record customer payments and manage receivables</p></div>
        </div>

        <!-- Quick Stats Cards -->
        <div class="row stat-card-row g-2 g-md-3 mb-4">
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card total h-100"><i class="bi bi-cash-stack stat-icon"></i><div class="stat-content"><div class="stat-value">₱<?= number_format($total_receivables, 2) ?></div><div class="stat-label">Total Receivables</div><small class="d-block">Pending & Overdue Invoices</small></div></div></div>
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card pending h-100"><i class="bi bi-clock-history stat-icon"></i><div class="stat-content"><div class="stat-value"><?= $avg_collection_days ?> days</div><div class="stat-label">Average Collection Period</div><small class="d-block">Days invoice to today (unpaid)</small></div></div></div>
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card overdue h-100"><i class="bi bi-exclamation-triangle stat-icon"></i><div class="stat-content"><div class="stat-value">₱<?= number_format($overdue_receivables, 2) ?></div><div class="stat-label">Overdue Receivables</div><small class="d-block">Past due date</small></div></div></div>
            <div class="col-12 col-sm-6 col-lg-3"><div class="stat-card aging h-100" id="agingCardBtn" style="cursor: pointer;"><i class="bi bi-pie-chart stat-icon"></i><div class="stat-content"><div class="stat-value">₱<?= number_format(($aging_1_7 + $aging_8_14 + $aging_15_21 + $aging_22_28 + $aging_beyond_28), 2) ?></div><div class="stat-label">Aging Breakdown</div><small class="d-block">Click to view details</small></div></div></div>
        </div>

        <!-- PENDING REMITTANCES SECTION (FOR ADMIN APPROVAL) -->
        <?php if (!empty($pending_remittances)): ?>
        <div class="pending-remittances-section mb-4">
            <div class="section-header">
                <i class="bi bi-clock-history"></i> Pending Remittances (Awaiting Your Approval)
                <span class="badge bg-light text-dark ms-2"><?= count($pending_remittances) ?></span>
            </div>
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Collector</th>
                            <th>Customer</th>
                            <th>Invoice #</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Collection Date</th>
                            <th>Attachment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_remittances as $remit): ?>
                        <tr class="remittance-row" data-remittance-id="<?= $remit['remittance_id'] ?>" data-photo="<?= htmlspecialchars($remit['attachment_path'] ?? '') ?>" data-title="Remittance - <?= htmlspecialchars($remit['invoice_number'] ?? '') ?>">
                            <td><?= htmlspecialchars(($remit['collector_first'] ?? '') . ' ' . ($remit['collector_last'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($remit['customer_name'] ?? 'Unknown') ?></td>
                            <td><?= htmlspecialchars($remit['invoice_number'] ?? '') ?></td>
                            <td class="text-end fw-bold text-success">₱<?= number_format($remit['amount'], 2) ?></td>
                            <td><span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $remit['payment_method'])) ?></span></td>
                            <td><?= date('M d, Y', strtotime($remit['collection_date'])) ?></td>
                            <td>
                                <?php if (!empty($remit['attachment_path'])): ?>
                                    <span class="badge bg-primary"><i class="bi bi-image"></i> Click row</span>
                                <?php else: ?>
                                    <span class="text-muted small">No photo</span>
                                <?php endif; ?>
                            </td>
                            <td class="remittance-actions">
                                <button class="btn-approve" onclick="approveRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                                <button class="btn-reject" onclick="rejectRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- Mobile view for pending remittances -->
            <div class="d-md-none p-3">
                <?php foreach ($pending_remittances as $remit): ?>
                <div class="remittance-card remittance-row" data-remittance-id="<?= $remit['remittance_id'] ?>" data-photo="<?= htmlspecialchars($remit['attachment_path'] ?? '') ?>" data-title="Remittance - <?= htmlspecialchars($remit['invoice_number'] ?? '') ?>">
                    <div class="remittance-header">
                        <strong><i class="bi bi-person-badge"></i> <?= htmlspecialchars(($remit['collector_first'] ?? '') . ' ' . ($remit['collector_last'] ?? '')) ?></strong>
                        <span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $remit['payment_method'])) ?></span>
                    </div>
                    <div><i class="bi bi-building"></i> <?= htmlspecialchars($remit['customer_name'] ?? 'Unknown') ?></div>
                    <div><i class="bi bi-receipt"></i> Invoice: <?= htmlspecialchars($remit['invoice_number'] ?? '') ?></div>
                    <div class="fw-bold text-success mt-1">₱<?= number_format($remit['amount'], 2) ?></div>
                    <div class="text-muted small"><i class="bi bi-calendar"></i> Collected: <?= date('M d, Y', strtotime($remit['collection_date'])) ?></div>
                    <div class="mt-1">
                        <?php if (!empty($remit['attachment_path'])): ?>
                            <span class="badge bg-primary"><i class="bi bi-image"></i> Tap card to view photo</span>
                        <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-image"></i> No attachment</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 d-flex gap-2">
                        <button class="btn-approve btn-sm" onclick="approveRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                        <button class="btn-reject btn-sm" onclick="rejectRemittance(<?= $remit['remittance_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- RETURNED INVOICE TICKETS SECTION -->
        <?php if (!empty($returned_invoices)): ?>
        <div class="pending-remittances-section mb-4">
            <div class="section-header" style="background:#052A47;">
                <i class="bi bi-arrow-return-left"></i> Returned Invoice Tickets
                <span class="badge bg-light text-dark ms-2"><?= count($returned_invoices) ?></span>
            </div>
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Returned By</th>
                            <th>Customer</th>
                            <th>Invoice #</th>
                            <th>Amount</th>
                            <th>Reason</th>
                            <th>Returned Date</th>
                            <th>Photo</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returned_invoices as $ret): ?>
                        <tr class="return-row" data-photo="<?= htmlspecialchars($ret['attachment_path'] ?? '') ?>" data-title="Return Ticket - <?= htmlspecialchars($ret['invoice_number'] ?? '') ?>">
                            <td><?= htmlspecialchars(trim(($ret['returned_first'] ?? '') . ' ' . ($ret['returned_last'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars($ret['customer_name'] ?? 'Unknown') ?></td>
                            <td><?= htmlspecialchars($ret['invoice_number'] ?? '') ?></td>
                            <td class="text-end"><strong class="text-danger">₱<?= number_format((float)($ret["balance_amount"] ?? 0), 2) ?></strong><div class="small text-muted">Paid: ₱<?= number_format((float)($ret["paid_amount"] ?? 0), 2) ?></div></td>
                            <td><?= htmlspecialchars($ret['return_reason'] ?? '') ?></td>
                            <td><?= date('M d, Y', strtotime($ret['created_at'])) ?></td>
                            <td>
                                <?php if (!empty($ret['attachment_path'])): ?>
                                    <span class="badge bg-primary"><i class="bi bi-image"></i> Click row</span>
                                <?php else: ?>
                                    <span class="text-muted small">No photo</span>
                                <?php endif; ?>
                            </td>
                            <td class="remittance-actions" onclick="event.stopPropagation();">
                                <button class="btn-approve" onclick="approveReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                                <button class="btn-reject" onclick="rejectReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-md-none p-3">
                <?php foreach ($returned_invoices as $ret): ?>
                <div class="remittance-card return-row" data-photo="<?= htmlspecialchars($ret['attachment_path'] ?? '') ?>" data-title="Return Ticket - <?= htmlspecialchars($ret['invoice_number'] ?? '') ?>">
                    <div class="remittance-header">
                        <strong><i class="bi bi-person-badge"></i> <?= htmlspecialchars(trim(($ret['returned_first'] ?? '') . ' ' . ($ret['returned_last'] ?? ''))) ?></strong>
                        <span class="badge bg-dark">Returned</span>
                    </div>
                    <div><i class="bi bi-building"></i> <?= htmlspecialchars($ret['customer_name'] ?? 'Unknown') ?></div>
                    <div><i class="bi bi-receipt"></i> Invoice: <?= htmlspecialchars($ret['invoice_number'] ?? '') ?></div>
                    <div class="fw-bold mt-1 text-danger">Remaining: ₱<?= number_format((float)($ret["balance_amount"] ?? 0), 2) ?></div><div class="text-muted small">Paid: ₱<?= number_format((float)($ret["paid_amount"] ?? 0), 2) ?></div>
                    <div class="text-muted small mt-1"><i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($ret['return_reason'] ?? '') ?></div>
                    <div class="text-muted small"><i class="bi bi-calendar"></i> Returned: <?= date('M d, Y', strtotime($ret['created_at'])) ?></div>
                    <div class="mt-1">
                        <?php if (!empty($ret['attachment_path'])): ?>
                            <span class="badge bg-primary"><i class="bi bi-image"></i> Tap card to view photo</span>
                        <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-image"></i> No attachment</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 d-flex gap-2" onclick="event.stopPropagation();">
                        <button class="btn-approve btn-sm" onclick="approveReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-check-lg"></i> Approve</button>
                        <button class="btn-reject btn-sm" onclick="rejectReturnTicket(<?= $ret['return_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <!-- Batch Assign Bar (shown when invoices are selected) -->
        <div id="batchAssignBar" class="batch-assign-bar" style="display: none;">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-check2-square fs-5"></i>
                <span class="selected-count"><span id="selectedCount">0</span> invoice(s) selected</span>
            </div>
            <div class="d-flex gap-2">
                <button class="btn-clear-selection" id="clearSelectionBtn"><i class="bi bi-x-lg"></i> Clear</button>
                <button class="btn-assign-batch" id="batchAssignBtn"><i class="bi bi-person-plus"></i> Assign to Collector</button>
            </div>
        </div>

        <!-- Customer Selection and Filters -->
        <div class="supplier-filter-card mb-4">
            <div class="supplier-filter-header"><h5><i class="bi bi-funnel"></i> Filter Invoices</h5><button class="supplier-filter-toggle-btn" type="button" id="invoiceFilterToggleBtn" aria-expanded="false"><i class="bi bi-chevron-down" id="invoiceFilterIcon"></i></button></div>
            <div class="supplier-filter-content collapsed" id="invoiceFilterContent">
                <div class="supplier-filter-one-line">
                    <div class="filter-item" style="flex: 2;"><label class="supplier-filter-label">SEARCH CUSTOMER</label><select class="supplier-filter-select" id="customerSelect"><option value="">-- All Customers with Pending Invoices --</option><?php foreach ($all_customers as $customer): ?><option value="<?= $customer['customer_id'] ?>"><?= htmlspecialchars($customer['customer_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="filter-item"><label class="supplier-filter-label">DATE FROM</label><input type="date" class="supplier-filter-input" id="dateFrom"></div>
                    <div class="filter-item"><label class="supplier-filter-label">DATE TO</label><input type="date" class="supplier-filter-input" id="dateTo"></div>
                </div>
            </div>
        </div>

        <!-- Credit Summary (shown only when a specific customer is selected) -->
        <div id="creditSummary" class="credit-summary" style="display: none;"><div class="credit-item"><strong>Credit Limit:</strong> <span id="creditLimit">0.00</span></div><div class="credit-item"><strong>Outstanding Balance:</strong> <span id="outstandingBalance">0.00</span></div><div class="credit-item"><strong>Available Credit:</strong> <span id="availableCredit">0.00</span></div></div>

        <!-- Invoices Table -->
        <div class="data-table">
            <div class="table-header">
                <h5 class="mb-0">
                    <i class="bi bi-receipt"></i> Customer Invoices (Ready for Collection)
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="invoicesTable">
                    <thead>
                        <tr>
                            <th class="checkbox-column"><input type="checkbox" id="selectAllCheckbox" class="select-all-checkbox"></th>
                            <th>Customer</th>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>SO #</th>
                            <th>Amount Due</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTableBody">
                        <?php if (empty($default_invoices)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No pending invoices found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($default_invoices as $invoice): 
                                $invoiceDate = $invoice['invoice_date'] ? date('Y-m-d', strtotime($invoice['invoice_date'])) : '-';
                                $amountDue = number_format($invoice['total_amount'] ?? 0, 2);
                                $statusClass = $invoice['status'] === 'overdue' ? 'badge-overdue' : 'badge-pending';
                                $statusText = $invoice['status'] === 'overdue' ? 'Overdue' : 'Pending';
                                $orderStatusText = $invoice['order_status'] ?? 'Unknown';
                                $customerName = htmlspecialchars($invoice['customer_name'] ?? 'Unknown');
                                $orderStatusLower = strtolower($orderStatusText);
                                $paymentButton = $orderStatusLower === 'delivered' ? '<button class="btn-pay" onclick="event.stopPropagation(); openPaymentModal(' . $invoice['invoice_id'] . ', \'' . addslashes($invoice['invoice_number']) . '\', ' . ($invoice['total_amount'] ?? 0) . ')"><i class="bi bi-cash-stack"></i> Record Payment</button>' : ($orderStatusLower === 'confirmed' ? '<span class="text-muted small">Await Delivery</span>' : '<span class="text-muted small">Not Ready</span>');
                                $assignedName = trim($invoice['assigned_to_name'] ?? '');
                                if ($assignedName !== '') {
                                    $paymentButton = '<span class="text-muted small"><i class="bi bi-person-check me-1"></i>Assigned</span>';
                                }
                                $assignedRole = ($invoice['assigned_to_role'] ?? '') === 'delivery' ? 'Driver' : (($invoice['assigned_to_role'] ?? '') === 'sales' ? 'Sales Agent' : '');
                                $assignedDate = !empty($invoice['collection_date']) ? date('M d, Y', strtotime($invoice['collection_date'])) : '';
                                $assignedCell = $assignedName !== ''
                                    ? '<span class="assigned-collector-badge"><i class="bi bi-person-check"></i>' . htmlspecialchars($assignedName) . '</span><span class="assigned-date-small">' . htmlspecialchars($assignedRole . ($assignedDate ? ' • ' . $assignedDate : '')) . '</span>'
                                    : '<span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Unassigned</span>';
                            ?>
                                <tr class="invoice-row" data-invoice-id="<?= $invoice['invoice_id'] ?>" data-customer-id="<?= $invoice['customer_id'] ?>" data-branch-id="<?= $invoice['branch_id'] ?? 0 ?>">
                                    <td class="checkbox-column"><input type="checkbox" class="row-checkbox" data-invoice-id="<?= $invoice['invoice_id'] ?>" data-customer-id="<?= $invoice['customer_id'] ?>" data-branch-id="<?= $invoice['branch_id'] ?? 0 ?>"></td>
                                    <td><strong><?= $customerName ?></strong></td>
                                    <td><?= htmlspecialchars($invoice['invoice_number'] ?? '') ?></td>
                                    <td><?= $invoiceDate ?></td>
                                    <td><?= htmlspecialchars($invoice['so_number'] ?? '-') ?></td>
                                    <td class="text-end fw-bold">₱<?= $amountDue ?></td>
                                    <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                                    <td><?= $assignedCell ?></td>
                                    <td><?= $paymentButton ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment Modal (For recording payment which creates remittance for approval) -->
        <div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Record Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" id="payInvoiceId"><input type="hidden" id="payInvoiceAmount"><div class="row mb-4"><div class="col-md-6"><div class="invoice-summary-card"><div class="invoice-summary-label">Invoice Number</div><div class="invoice-summary-value" id="payInvoiceNumber">-</div></div></div><div class="col-md-6"><div class="invoice-summary-card"><div class="invoice-summary-label">Amount Due</div><div class="invoice-summary-value text-success" id="payAmountDue">₱0.00</div></div></div></div>
        <div class="alert alert-warning mb-3">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Note:</strong> Recording a payment will submit it for your approval. Once approved, the invoice will be marked as paid.
        </div>
        <div class="form-card p-3 mb-3" style="background:#f8fafc;border-radius:12px;border:1px solid #e9ecef;">
            <label class="fw-bold mb-2"><i class="bi bi-person-check me-1"></i>Assign Collector (if not assigned yet)</label>
            <select class="form-select" id="payAssignCollectorSelect">
                <option value="">No change / keep current</option>
                <?php foreach ($assignable_collectors as $collector): ?>
                    <?php $collectorRole = ($collector['role'] ?? '') === 'delivery' ? 'Driver' : 'Sales Agent'; ?>
                    <option value="<?= (int)$collector['user_id'] ?>">
                        <?= htmlspecialchars(trim(($collector['first_name'] ?? '') . ' ' . ($collector['last_name'] ?? ''))) ?> - <?= $collectorRole ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="paymentFormSection">
        <label class="fw-bold mb-2">Payment Method</label><div class="row g-3 mb-4"><div class="col-md-4"><div class="payment-method-option" data-method="cash"><i class="bi bi-cash-stack"></i><span>Cash</span></div></div><div class="col-md-4"><div class="payment-method-option" data-method="check"><i class="bi bi-check2-circle"></i><span>Check</span></div></div><div class="col-md-4"><div class="payment-method-option" data-method="online_transfer"><i class="bi bi-globe2"></i><span>Online Transfer</span></div></div></div><div id="paymentDetailsContainer"></div><div id="cashFields" style="display: none;"><div class="mb-3"><label class="form-label">Amount Received / Payment Amount (₱)</label><input type="text" class="form-control" id="cashTendered" placeholder="Enter partial or full cash payment" inputmode="decimal"><div class="form-text">Change: <span id="cashChangeDisplay">₱0.00</span></div></div></div><div id="otherAmountFields" style="display: none;"><div class="mb-3"><label class="form-label">Payment Amount (₱)</label><input type="text" class="form-control format-number" id="paymentAmount" placeholder="Enter partial or full payment amount"></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-warning" id="submitPaymentBtn"><i class="bi bi-send"></i> Submit for Approval</button></div></div></div></div>

        <!-- Batch Assign Modal -->
        <div class="modal fade" id="batchAssignModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Assign Multiple Invoices</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-list-check me-1"></i>Selected Invoices</label>
                            <div id="selectedInvoicesList" class="selected-invoices-list"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person-badge me-1"></i>Assign to Collector</label>
                            <select class="form-select" id="batchCollectorSelect">
                                <option value="">-- Select Collector --</option>
                                <?php foreach ($assignable_collectors as $collector): ?>
                                    <?php $collectorRole = ($collector['role'] ?? '') === 'delivery' ? 'Driver' : 'Sales Agent'; ?>
                                    <option value="<?= (int)$collector['user_id'] ?>">
                                        <?= htmlspecialchars(trim(($collector['first_name'] ?? '') . ' ' . ($collector['last_name'] ?? ''))) ?> - <?= $collectorRole ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-calendar me-1"></i>Collection Date</label>
                            <input type="date" class="form-control" id="batchCollectionDate" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success" id="confirmBatchAssignBtn"><i class="bi bi-check-circle"></i> Assign</button>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    </div>
                </div>
            </div>
        </div>

<<<<<<< HEAD
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

<!-- Collect Modal (Step 1) -->
<div class="modal fade" id="collectModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#047857,#44D34E);color:#fff">
                <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Step 1: Record Collection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                        <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" class="form-control" id="checkBankName" placeholder="Type check bank name manually"></div>
                        <div class="col-md-6"><label class="form-label">Bank Branch</label><input type="text" class="form-control" id="checkBankBranch"></div>
                    </div>
                </div>

                <div class="mt-3 d-none" id="onlineFields">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Reference Number</label><input type="text" class="form-control" id="referenceNumber"></div>
                        <div class="col-md-6"><label class="form-label">Bank / Wallet</label><select class="form-select" id="bankWallet"><option value="">Select online transfer account</option><?php foreach ($online_transfer_sub_accounts as $account): ?><?php $label = trim((!empty($account['parent_bank_name']) ? $account['parent_bank_name'] . ' / ' : '') . $account['bank_name'] . (!empty($account['account_number']) ? ' - ' . $account['account_number'] : '')); ?><option value="<?php echo esc($account['bank_name']); ?>" data-bank-branch="<?php echo esc($account['bank_branch'] ?? ''); ?>"><?php echo esc($label); ?></option><?php endforeach; ?></select><small class="text-muted">Only saved sub accounts with Online Transfer enabled are shown.</small></div>
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
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-return-left me-2"></i>Return Invoice Ticket
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="returnInvoiceId">
                
                <!-- Warning Alert -->
                <div class="alert alert-warning">
                    <i class="bi bi-info-circle me-1"></i>
                    This will return the assigned invoice/ticket to Branch Admin. No payment will be recorded.
                </div>

                <!-- Order Information Card -->
                <div class="order-info-card mb-3">
                    <h6><i class="bi bi-receipt me-1"></i> Ticket Information</h6>
                    <div class="row">
                        <div class="col-12 mb-2">
                            <div class="info-row">
                                <div class="info-label">Invoice Number</div>
                                <div class="info-value" id="returnInvoiceNo">—</div>
                            </div>
                        </div>
                        <div class="col-12 mb-2">
                            <div class="info-row">
                                <div class="info-label">Customer Name</div>
                                <div class="info-value" id="returnCustomerName">—</div>
                            </div>
                        </div>
                        <div class="col-12 mb-2">
                            <div class="info-row">
                                <div class="info-label">Remaining Balance</div>
                                <div class="info-value text-danger" id="returnBalance">₱0.00</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Return Reason Form -->
                <div class="form-group mb-3">
                    <label class="form-label">
                        <i class="bi bi-chat-left-text"></i> Reason for Return
                    </label>
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
                </div>

                <!-- Photo Attachment -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-camera"></i> Photo Attachment
                    </label>
                    <input type="file" class="form-control" id="returnPhoto" accept="image/*">
                    <small class="text-muted">Optional photo/proof for returning this invoice ticket.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button class="btn btn-primary" onclick="submitReturnInvoice()">
                    <i class="bi bi-send me-1"></i>Return to Admin
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Assigned Ticket Details Modal -->
<div class="modal fade" id="assignedTicketDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;">
                <h5 class="modal-title">
                    <i class="bi bi-ticket-detailed me-2"></i>Collection Ticket Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Order Information Card -->
                <div class="order-info-card mb-3">
                    <h6><i class="bi bi-receipt me-1"></i> Ticket Information</h6>
                    <div class="row">
                        <div class="col-12 mb-2">
                            <div class="ticket-detail-card">
                                <div class="ticket-detail-label">Invoice Number</div>
                                <div class="ticket-detail-value" id="detailInvoiceNo">—</div>
                            </div>
                        </div>
                        <div class="col-12 mb-2">
                            <div class="ticket-detail-card">
                                <div class="ticket-detail-label">Customer Name</div>
                                <div class="ticket-detail-value" id="detailCustomerName">—</div>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <div class="ticket-detail-card">
                                <div class="ticket-detail-label">Paid Amount</div>
                                <div class="ticket-detail-value" id="detailPaidAmount">₱0.00</div>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <div class="ticket-detail-card">
                                <div class="ticket-detail-label">Remaining Balance</div>
                                <div class="ticket-detail-value text-danger" id="detailBalanceAmount">₱0.00</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assigned By Info -->
                <div id="newAssignedInfo" class="order-info-card">
                    <h6><i class="bi bi-person-badge me-1"></i> Assignment Details</h6>
                    <div class="ticket-detail-label">Assigned By</div>
                    <div class="ticket-detail-value" id="detailAssignedBy">Branch Admin</div>
                    <div class="small text-muted mt-2" id="detailAssignedDate"></div>
                </div>

                <!-- Rejection Info (initially hidden) -->
                <div id="rejectedReturnInfo" style="display:none">
                    <div class="order-info-card" style="border-left-color: #dc2626;">
                        <h6 style="color: #991b1b;"><i class="bi bi-exclamation-triangle me-1"></i> Rejection Details</h6>
                        <div class="ticket-detail-label mb-2">Reason for Rejection</div>
                        <div class="ticket-rejection-box" id="detailRejectionReason">—</div>
                        <div class="small text-muted mt-2" id="detailRejectedDate"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Mobile Bottom Navigation -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <li class="nav-item"><a class="nav-link" href="current_inventory.php"><i class="bi bi-box-seam"></i><span>Inventory</span></a></li>
        <li class="nav-item"><a class="nav-link" href="customer_orderproduct.php"><i class="bi bi-person-plus"></i><span>Orders</span></a></li>
        <li class="nav-item"><a class="nav-link active" href="collections.php"><i class="bi bi-cash-stack"></i><span>Collect</span></a></li>
        <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-receipt"></i><span>Sales</span></a></li>
        <li class="nav-item dropdown-more" id="moreDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMoreDropdown(event)"><i class="bi bi-three-dots"></i><span>More</span></a>
            <div class="more-dropdown" id="moreDropdownMenu">
                <a href="purchase_order.php" class="dropdown-item"><i class="bi bi-truck"></i><span>Receive Inventory</span></a>
                <a href="expenses.php" class="dropdown-item"><i class="bi bi-wallet2"></i><span>Expenses</span></a>
                <a href="reports.php" class="dropdown-item"><i class="bi bi-file-earmark-bar-graph"></i><span>Reports</span></a>
                <a href="#" class="dropdown-item logout-item" onclick="confirmLogout(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
            </div>
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
                        <span class="badge bg-success"><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></span>
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
const onlineTransferSubAccounts = <?php echo $online_transfer_sub_accounts_json ?: '[]'; ?>;


const myCollectionReportRows = <?php echo json_encode($my_collection_report_rows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const myCollectionReportUser = <?php echo json_encode($user_name); ?>;
const myCollectionReportBranch = <?php echo json_encode($branch_name ?? ''); ?>;
const myCollectionReportTitle = "Rolling Collection Report";
const myCollectionReportRole = "Rolling Account";

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

function buildMyCollectionReportPrintContent(filteredRows, periodText, totalAmount){
    const rowsHtml = filteredRows.length
        ? filteredRows.map((row, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${reportText(normalizeReportDate(row.collection_date))}</td>
                <td>${reportText(row.invoice_number || '-')}</td>
                <td>${reportText(row.customer_name || '-')}</td>
                <td>${reportText(String(row.payment_method || '').replace('_', ' ').toUpperCase())}</td>
                <td>${reportText(row.reference_number || row.check_number || '-')}</td>
                <td>${reportText(String(row.status || '').toUpperCase())}</td>
                <td style="text-align:right;white-space:nowrap;">${reportPeso(row.amount)}</td>
            </tr>
        `).join('')
        : `<tr><td colspan="8" style="text-align:center;padding:14px;">No collection records found.</td></tr>`;

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
                    <th>Status</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
            <tfoot>
                <tr>
                    <th colspan="7" style="text-align:right;">TOTAL</th>
                    <th style="text-align:right;white-space:nowrap;">${reportPeso(totalAmount)}</th>
                </tr>
            </tfoot>
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

    const periodText = dateFrom || dateTo
        ? `${dateFrom || 'Beginning'} to ${dateTo || 'Today'}`
        : 'All Dates';

    const printArea = document.getElementById('collectionReportPreviewContent');
    if (!printArea) {
        Swal.fire('Print Error', 'Print area was not found.', 'error');
        return;
    }

    printArea.innerHTML = buildMyCollectionReportPrintContent(filteredRows, periodText, totalAmount);

    const modalInstance = bootstrap.Modal.getInstance(document.getElementById('collectionReportPrintModal'));
    if (modalInstance) modalInstance.hide();

    setTimeout(function(){
        window.print();
    }, 450);
}

function formatPeso(amount){return '₱'+(parseFloat(amount||0)).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function logout(){window.location.href='../logout.php';}
=======
        <!-- Reject Remittance Modal -->
        <div class="modal fade" id="rejectRemittanceModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Reject Remittance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="rejectRemittanceId">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Reason for Rejection</label>
                            <textarea class="form-control" id="rejectionReason" rows="3" placeholder="Please provide a reason for rejecting this remittance..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmRejectBtn"><i class="bi bi-x-lg"></i> Confirm Rejection</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aging Breakdown Modal -->
        <div class="modal fade" id="agingModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-pie-chart me-2"></i>Receivables Aging Analysis
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body" style="min-height: 450px; max-height: 70vh; overflow-y: auto;">
                        <div id="agingMainView" style="display: block;">
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <div class="summary-card total-summary">
                                        <div class="summary-icon"><i class="bi bi-cash-stack"></i></div>
                                        <div class="summary-content">
                                            <div class="summary-label">Total Receivables</div>
                                            <div class="summary-value">₱<?= number_format($total_receivables, 2) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="summary-card overdue-summary">
                                        <div class="summary-icon"><i class="bi bi-exclamation-triangle"></i></div>
                                        <div class="summary-content">
                                            <div class="summary-label">Overdue Amount</div>
                                            <div class="summary-value" id="agingOverdueAmount">₱<?= number_format($overdue_receivables, 2) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="section-title">
                                <i class="bi bi-calendar-range"></i>
                                <span>Aging Breakdown</span>
                            </div>

                            <div class="aging-item clickable" data-days-range="1-7" data-min-days="1" data-max-days="7">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-warning text-dark">1 - 7 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_1_7 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_1_7, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-warning" style="width: <?= $total_receivables > 0 ? ($aging_1_7 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="8-14" data-min-days="8" data-max-days="14">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-orange">8 - 14 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_8_14 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_8_14, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-orange" style="width: <?= $total_receivables > 0 ? ($aging_8_14 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="15-21" data-min-days="15" data-max-days="21">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-info">15 - 21 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_15_21 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_15_21, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-info" style="width: <?= $total_receivables > 0 ? ($aging_15_21 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="22-28" data-min-days="22" data-max-days="28">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-danger">22 - 28 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_22_28 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_22_28, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-danger" style="width: <?= $total_receivables > 0 ? ($aging_22_28 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="aging-item clickable" data-days-range="28+" data-min-days="29" data-max-days="999">
                                <div class="aging-header">
                                    <div class="aging-range">
                                        <span class="range-badge bg-dark">Beyond 28 days</span>
                                        <span class="percentage"><?= $total_receivables > 0 ? round(($aging_beyond_28 / $total_receivables) * 100) : 0 ?>%</span>
                                    </div>
                                    <div class="aging-amount">₱<?= number_format($aging_beyond_28, 2) ?></div>
                                </div>
                                <div class="progress"><div class="progress-bar bg-dark" style="width: <?= $total_receivables > 0 ? ($aging_beyond_28 / $total_receivables * 100) : 0 ?>%"></div></div>
                            </div>

                            <div class="legend-container">
                                <div class="legend-title"><i class="bi bi-info-circle-fill"></i><span>Aging based on overdue days from due date</span></div>
                                <div class="legend-badges">
                                    <span class="legend-badge bg-warning text-dark">1-7d</span>
                                    <span class="legend-badge bg-orange">8-14d</span>
                                    <span class="legend-badge bg-info">15-21d</span>
                                    <span class="legend-badge bg-danger">22-28d</span>
                                    <span class="legend-badge bg-dark">&gt;28d</span>
                                </div>
                            </div>
                        </div>

                        <div id="agingDetailView" style="display: none;">
                            <div class="d-flex align-items-center mb-3 sticky-top bg-light p-2 rounded" style="background: #f8fafc; position: sticky; top: -1.25rem; margin-top: -1rem; padding-top: 1rem; z-index: 10;">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-3" id="backToAgingBtn" style="border-radius: 30px;">
                                    <i class="bi bi-arrow-left"></i> Back
                                </button>
                                <h6 class="mb-0" id="detailViewTitle">Invoices (1-7 days overdue)</h6>
                            </div>
                            <div id="detailInvoicesList">
                                <div class="text-center text-muted py-4">Loading...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Details Modal -->
        <div class="modal fade" id="paymentDetailsModal" tabindex="-1"><div class="modal-dialog modal-md"><div class="modal-content"><div class="modal-header bg-info text-white"><h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Payment Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body payment-details-modal"><div class="payment-detail-card"><div class="row mb-2"><div class="col-5 payment-detail-label">Payment Method:</div><div class="col-7 payment-detail-value" id="detailPaymentMethod">-</div></div><div class="row mb-2" id="detailCheckDateRow" style="display: none;"><div class="col-5 payment-detail-label">Check Date:</div><div class="col-7 payment-detail-value" id="detailCheckDate">-</div></div><div class="row mb-2" id="detailBankNameRow" style="display: none;"><div class="col-5 payment-detail-label">Bank:</div><div class="col-7 payment-detail-value" id="detailBankName">-</div></div><div class="row mb-2" id="detailBankBranchRow" style="display: none;"><div class="col-5 payment-detail-label">Branch:</div><div class="col-7 payment-detail-value" id="detailBankBranch">-</div></div><div class="row mb-2" id="detailCheckNumberRow" style="display: none;"><div class="col-5 payment-detail-label">Check No.:</div><div class="col-7 payment-detail-value" id="detailCheckNumber">-</div></div><div class="row mb-2"><div class="col-5 payment-detail-label">Amount:</div><div class="col-7 payment-detail-value fw-bold text-success" id="detailAmount">-</div></div><div class="row mb-2" id="detailRefNoRow" style="display: none;"><div class="col-5 payment-detail-label">Ref. No.:</div><div class="col-7 payment-detail-value" id="detailRefNo">-</div></div><div class="row mb-2"><div class="col-5 payment-detail-label">Payment Date:</div><div class="col-7 payment-detail-value" id="detailPaymentDate">-</div></div><div class="row mb-2"><div class="col-5 payment-detail-label">Received By:</div><div class="col-7 payment-detail-value" id="detailReceivedBy">-</div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>

        <!-- Mobile Bottom Navigation -->
        <div class="mobile-nav" id="mobileNav"><ul class="nav"><li class="nav-item dropdown-more" id="inventoryDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'inventoryDropdownMenu')"><i class="bi bi-box-seam"></i><span>Inventory</span></a><div class="more-dropdown" id="inventoryDropdownMenu"><a href="current_inventory.php" class="dropdown-item"><i class="bi bi-bar-chart-line"></i><span>Current Inventory</span></a><a href="bad_orders.php" class="dropdown-item"><i class="bi bi-recycle"></i><span>Bad Orders</span></a></div></li><li class="nav-item dropdown-more" id="salesDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'salesDropdownMenu')"><i class="bi bi-cart"></i><span>Sales</span></a><div class="more-dropdown" id="salesDropdownMenu"><a href="sales_order.php" class="dropdown-item"><i class="bi bi-cart"></i><span>Sales Orders</span></a><a href="pick_list_items.php" class="dropdown-item"><i class="bi bi-list-check"></i><span>Pick Lists</span></a></div></li><li class="nav-item dropdown-more" id="purchaseDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'purchaseDropdownMenu')"><i class="bi bi-truck"></i><span>Purchase</span></a><div class="more-dropdown" id="purchaseDropdownMenu"><a href="purchase_order.php" class="dropdown-item"><i class="bi bi-box"></i><span>Purchase Orders</span></a><a href="supplier.php" class="dropdown-item"><i class="bi bi-building"></i><span>Suppliers</span></a></div></li><li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span>Trips</span></a></li><li class="nav-item dropdown-more" id="moreDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'moreDropdownMenu')"><i class="bi bi-three-dots-vertical"></i><span>More</span></a><div class="more-dropdown" id="moreDropdownMenu"><a href="drivers.php" class="dropdown-item"><i class="bi bi-people"></i><span>Users</span></a><a href="approve_credit_requests.php" class="dropdown-item"><i class="bi bi-pencil-square"></i><span>Approve Requests</span></a><div class="dropdown-divider"></div><a href="#" class="dropdown-item logout-item" onclick="showProfileModal(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div></li></ul></div>

        <!-- Mobile Profile Modal -->
        <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div><h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4><p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p><?php if (!$view_all_branches && $branch_id > 0): ?><div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div><?php endif; ?><div class="user-id text-muted small mb-4"></div><button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></div></div></div></div>


        <!-- Attachment Photo Modal -->
        <div class="modal fade" id="attachmentPhotoModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" style="border-radius:18px;overflow:hidden;">
                    <div class="modal-header" style="background:#052A47;color:#fff;">
                        <h5 class="modal-title" id="attachmentPhotoTitle"><i class="bi bi-image me-2"></i>Attachment Photo</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center bg-light">
                        <img id="attachmentPhotoImg" src="" alt="Attachment" class="img-fluid rounded shadow-sm" style="max-height:70vh;object-fit:contain;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject Return Ticket Modal -->
        <div class="modal fade" id="rejectReturnTicketModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:18px;overflow:hidden;">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Return Ticket</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="rejectReturnTicketId">
                        <label class="form-label fw-bold">Reason for rejection</label>
                        <textarea class="form-control" id="returnRejectionReason" rows="4" placeholder="Enter reason why this return ticket is rejected..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmRejectReturnBtn"><i class="bi bi-x-lg me-1"></i>Reject Return</button>
                    </div>
                </div>
            </div>
        </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Pass online transfer sub accounts to JS
var registeredBanks = <?php echo $banks_json; ?>;

// Helper to generate ONLINE TRANSFER Bank/Wallet dropdown.
// Only registered sub accounts tagged as online_transfer are shown here.
function getOnlineTransferAccountSelectHtml(nameAttr, required = true) {
    var req = required ? 'required' : '';
    var html = '<select class="form-control" id="' + nameAttr + '" name="' + nameAttr + '" ' + req + '>';
    if (!registeredBanks || registeredBanks.length === 0) {
        html += '<option value="">-- No online transfer sub accounts --</option>';
    } else {
        html += '<option value="">-- Select Bank/Wallet --</option>';
        for (var i = 0; i < registeredBanks.length; i++) {
            var displayName = registeredBanks[i].display_name || registeredBanks[i].bank_name || '';
            html += '<option value="' + escapeHtml(displayName) + '" data-bank-id="' + escapeHtml(String(registeredBanks[i].bank_id || '')) + '" data-bank-branch="' + escapeHtml(registeredBanks[i].bank_branch || '') + '">' + escapeHtml(displayName) + '</option>';
        }
    }
    html += '</select>';
    return html;
}

// ========== GLOBAL VARIABLES ==========
let selectedPaymentMethod = 'cash';
let currentInvoices = [];
let selectedInvoices = new Map();

// ========== REMITTANCE FUNCTIONS ==========
async function approveRemittance(remittanceId) {
    const result = await Swal.fire({
        title: 'Approve Remittance?',
        text: 'This will record the remitted amount as payment. Partial payment will keep the invoice pending; full payment will mark it paid.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    });
    
    if (result.isConfirmed) {
        Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'approve_remittance',
                    remittance_id: remittanceId
                })
            });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                console.error('Raw response:', text);
                Swal.close();
                Swal.fire('Error', 'Server returned invalid response.', 'error');
                return;
            }
            Swal.close();
            if (data.success) {
                Swal.fire('Success', data.message, 'success').then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message || 'Approval failed', 'error');
            }
        } catch (error) {
            Swal.close();
            console.error(error);
            Swal.fire('Error', 'Approval failed: ' + error.message, 'error');
        }
    }
}

function rejectRemittance(remittanceId) {
    document.getElementById('rejectRemittanceId').value = remittanceId;
    document.getElementById('rejectionReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectRemittanceModal')).show();
}

document.getElementById('confirmRejectBtn')?.addEventListener('click', async function() {
    const remittanceId = document.getElementById('rejectRemittanceId').value;
    const rejectionReason = document.getElementById('rejectionReason').value;
    
    if (!rejectionReason.trim()) {
        Swal.fire('Error', 'Please provide a reason for rejection', 'error');
        return;
    }
    
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'reject_remittance',
                remittance_id: remittanceId,
                rejection_reason: rejectionReason
            })
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) {
            console.error('Raw response:', text);
            Swal.close();
            Swal.fire('Error', 'Server returned invalid response.', 'error');
            return;
        }
        Swal.close();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('rejectRemittanceModal'))?.hide();
            Swal.fire('Success', data.message, 'success').then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Rejection failed', 'error');
        }
    } catch (error) {
        Swal.close();
        console.error(error);
        Swal.fire('Error', 'Rejection failed: ' + error.message, 'error');
    }
});

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
function cleanupBootstrapModals(){
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    if (!document.querySelector('.modal.show')) {
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }
}

<<<<<<< HEAD
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
        confirmButtonText: 'Yes, Remit All',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#047857', // solid color muna
        customClass: {
        confirmButton: 'btn-remit-all-swal'
        }
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

function toggleDropdown(event, id){
    event.preventDefault();
    const menu = document.getElementById(id || 'moreDropdownMenu');
    if(menu) menu.classList.toggle('show');
}

function toggleMoreDropdown(event){
    event.preventDefault();
    const menu = document.getElementById('moreDropdownMenu');
    if(menu) menu.classList.toggle('show');
}

function filterRows() {
    const searchEl = document.getElementById('searchInput');
    const statusEl = document.getElementById('statusFilter');
    const badge = document.getElementById('filterActiveBadge');
    const q = (searchEl ? searchEl.value : '').toLowerCase().trim();
    const s = statusEl ? statusEl.value : '';
    const cards = document.querySelectorAll('.collection-card');
    let visibleCount = 0;
    
    if (badge) {
        badge.classList.toggle('show', !!q || !!s);
    }
    
    cards.forEach(card => {
        let show = true;
        const cardText = card.textContent.toLowerCase();
        const cardStatus = card.dataset.status || '';
        
        if (q && !cardText.includes(q)) show = false;
        if (s && cardStatus !== s) show = false;
        
        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    
    const container = document.getElementById('assignedCardsContainer');
    if (!container) return;
    const existingEmpty = container.querySelector('.empty-state.filter-empty');
    if (visibleCount === 0 && !existingEmpty && <?php echo count($rows); ?> > 0) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'empty-state filter-empty';
        emptyDiv.innerHTML = '<i class="bi bi-search"></i><p>No matching collections found</p>';
        container.appendChild(emptyDiv);
    } else if (visibleCount > 0 && existingEmpty) {
        existingEmpty.remove();
    }
}

function setupCollectionFilterToggle() {
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterContent = document.getElementById('filterContent');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const clearFilterBtn = document.getElementById('clearFilterBtn');

    if (filterToggleBtn && filterContent) {
        filterContent.classList.add('collapsed');
        filterToggleBtn.setAttribute('aria-expanded', 'false');
        filterToggleBtn.addEventListener('click', function() {
            const isExpanded = filterToggleBtn.getAttribute('aria-expanded') === 'true';
            if (isExpanded) {
                filterContent.classList.add('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                filterContent.classList.remove('collapsed');
                filterToggleBtn.setAttribute('aria-expanded', 'true');
                setTimeout(() => { if (searchInput) searchInput.focus(); }, 180);
            }
        });
    }

    if (clearFilterBtn) {
        clearFilterBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            filterRows();
        });
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
    setupCollectionFilterToggle();
    const collectionSearchInput = document.getElementById('searchInput');
    const collectionStatusFilter = document.getElementById('statusFilter');
    if (collectionSearchInput) collectionSearchInput.addEventListener('input',filterRows);
    if (collectionStatusFilter) collectionStatusFilter.addEventListener('change',filterRows);

    document.addEventListener('click', function(e){
        const menu = document.getElementById('moreDropdownMenu');
        if(menu && !e.target.closest('.dropdown-more')){
            menu.classList.remove('show');
        }
    });
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
</script>
</body>
</html>
<?php ob_end_flush(); ?>
=======
function openAttachmentPhotoModal(photoPath, titleText) {
    if (!photoPath || String(photoPath).trim() === '') {
        Swal.fire('No Photo', 'No attachment photo uploaded for this record.', 'info');
        return;
    }
    Swal.close();
    cleanupBootstrapModals();
    const modalEl = document.getElementById('attachmentPhotoModal');
    document.getElementById('attachmentPhotoTitle').innerHTML = '<i class="bi bi-image me-2"></i>' + escapeHtml(titleText || 'Attachment Photo');
    document.getElementById('attachmentPhotoImg').src = photoPath;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

async function approveReturnTicket(returnId) {
    const result = await Swal.fire({
        title: 'Approve Return Ticket?',
        text: 'This will accept the returned invoice ticket and make the invoice available for reassignment.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    });
    if (!result.isConfirmed) return;
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'approve_return_ticket', return_id: returnId })
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { console.error('Raw response:', text); throw new Error('Invalid server response'); }
        Swal.close();
        if (data.success) Swal.fire('Success', data.message, 'success').then(() => location.reload());
        else Swal.fire('Error', data.message || 'Approval failed', 'error');
    } catch (error) {
        Swal.close();
        Swal.fire('Error', error.message || 'Approval failed', 'error');
    }
}

function rejectReturnTicket(returnId) {
    document.getElementById('rejectReturnTicketId').value = returnId;
    document.getElementById('returnRejectionReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectReturnTicketModal')).show();
}

document.getElementById('confirmRejectReturnBtn')?.addEventListener('click', async function() {
    const returnId = document.getElementById('rejectReturnTicketId').value;
    const reason = document.getElementById('returnRejectionReason').value.trim();
    if (!reason) { Swal.fire('Error', 'Please provide a reason for rejection', 'error'); return; }
    bootstrap.Modal.getInstance(document.getElementById('rejectReturnTicketModal'))?.hide();
    cleanupBootstrapModals();
    Swal.fire({ title: 'Processing...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reject_return_ticket', return_id: returnId, rejection_reason: reason })
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { console.error('Raw response:', text); throw new Error('Invalid server response'); }
        Swal.close();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('rejectReturnTicketModal'))?.hide();
            Swal.fire('Success', data.message, 'success').then(() => location.reload());
        } else Swal.fire('Error', data.message || 'Rejection failed', 'error');
    } catch (error) {
        Swal.close();
        Swal.fire('Error', error.message || 'Rejection failed', 'error');
    }
});
$(document).ready(function() {
    $(document).on('click', '.remittance-row, .return-row', function(e) {
        if ($(e.target).closest('button, a, input, select, textarea').length) return;
        openAttachmentPhotoModal($(this).data('photo') || '', $(this).data('title') || 'Attachment Photo');
    });
    // Initialize Select2 for customer dropdown
    $('#customerSelect').select2({ placeholder: "Type to search customer...", allowClear: true, width: '100%', minimumResultsForSearch: 1 });

    // Sidebar toggle
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', function() { const sidebar = document.getElementById('sidebar'); if (sidebar) { if (window.innerWidth <= 992) { sidebar.classList.toggle('active'); if (!document.querySelector('.sidebar-overlay')) { const overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.appendChild(overlay); overlay.addEventListener('click', function() { sidebar.classList.remove('active'); overlay.classList.remove('active'); setTimeout(() => overlay.remove(), 300); }); setTimeout(() => overlay.classList.add('active'), 10); } } else toggleSidebar(); } });
    const desktopToggleBtn = document.getElementById('desktopToggleBtn'); if (desktopToggleBtn) desktopToggleBtn.addEventListener('click', function(e) { e.stopPropagation(); toggleSidebar(); });
    const sidebar = document.getElementById('sidebar'); if (sidebar && window.innerWidth > 992) { if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed'); else sidebar.classList.remove('collapsed'); }
    setActiveSidebarItem();

    // Customer select change
    $('#customerSelect').on('change', function() { const customerId = $(this).val(); const dateFrom = $('#dateFrom').val(); const dateTo = $('#dateTo').val(); clearAllSelections(); if (customerId) loadSpecificCustomerInvoices(customerId, dateFrom, dateTo); else loadAllPendingInvoices(dateFrom, dateTo); });
    $('#dateFrom, #dateTo').on('change', function() { const customerId = $('#customerSelect').val(); const dateFrom = $('#dateFrom').val(); const dateTo = $('#dateTo').val(); clearAllSelections(); if (customerId) loadSpecificCustomerInvoices(customerId, dateFrom, dateTo); else loadAllPendingInvoices(dateFrom, dateTo); });

    // Select All checkbox
    $('#selectAllCheckbox').on('change', function() {
        const isChecked = $(this).prop('checked');
        $('.row-checkbox').each(function() {
            $(this).prop('checked', isChecked);
            const invoiceId = $(this).data('invoice-id');
            const customerId = $(this).data('customer-id');
            const branchId = $(this).data('branch-id');
            if (isChecked) {
                if (!selectedInvoices.has(invoiceId)) {
                    selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: branchId });
                }
            } else {
                selectedInvoices.delete(invoiceId);
            }
        });
        updateBatchAssignBar();
    });

    // Row checkbox change
    $(document).on('change', '.row-checkbox', function() {
        const invoiceId = $(this).data('invoice-id');
        const customerId = $(this).data('customer-id');
        const branchId = $(this).data('branch-id');
        if ($(this).prop('checked')) {
            if (!selectedInvoices.has(invoiceId)) {
                selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: branchId });
            }
        } else {
            selectedInvoices.delete(invoiceId);
        }
        const totalCheckboxes = $('.row-checkbox').length;
        const checkedCheckboxes = $('.row-checkbox:checked').length;
        $('#selectAllCheckbox').prop('checked', totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes);
        updateBatchAssignBar();
    });

    // Clear selection button
    $('#clearSelectionBtn').on('click', function() {
        clearAllSelections();
    });

    // Batch assign button
    $('#batchAssignBtn').on('click', function() {
        if (selectedInvoices.size === 0) {
            Swal.fire('Info', 'No invoices selected', 'info');
            return;
        }
        
        const listContainer = $('#selectedInvoicesList');
        listContainer.empty();
        selectedInvoices.forEach((invoice, id) => {
            const row = $(`.row-checkbox[data-invoice-id="${id}"]`).closest('tr');
            const invoiceData = currentInvoices.find(inv => String(inv.invoice_id) === String(id)) || {};

            const invoiceNumber = row.find('td:eq(2)').text().trim() || invoiceData.invoice_number || ('Invoice #' + id);
            const amountText = row.find('td:eq(5)').text().trim() || ('₱' + formatMoney(invoiceData.total_amount || invoiceData.balance_amount || 0));
            const customerName = row.find('td:eq(1)').text().trim() || invoiceData.customer_name || 'Unknown Customer';

            listContainer.append(`
                <div class="selected-invoice-item d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <i class="bi bi-receipt me-2"></i>
                        <strong>${escapeHtml(invoiceNumber)}</strong>
                        <span class="text-muted mx-1">-</span>
                        <span class="fw-bold text-success">${escapeHtml(amountText)}</span>
                        <span class="text-muted mx-1">-</span>
                        <span>${escapeHtml(customerName)}</span>
                    </div>
                </div>
            `);
        });
        
        $('#batchCollectorSelect').val('');
        $('#batchCollectionDate').val(new Date().toISOString().slice(0, 10));
        
        const batchModal = new bootstrap.Modal(document.getElementById('batchAssignModal'));
        batchModal.show();
    });

    // Confirm batch assign
    $('#confirmBatchAssignBtn').on('click', async function() {
        const collectorId = $('#batchCollectorSelect').val();
        const collectionDate = $('#batchCollectionDate').val();
        
        if (!collectorId) {
            Swal.fire('Error', 'Please select a collector', 'error');
            return;
        }
        
        const selectedInvoicesArray = Array.from(selectedInvoices.values());
        
        Swal.fire({ title: 'Assigning collectors...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'assign_multiple_collectors',
                    assigned_user_id: collectorId,
                    collector_id: collectorId,
                    collection_date: collectionDate,
                    selected_invoices: selectedInvoicesArray
                })
            });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                console.error('Raw response:', text);
                Swal.close();
                Swal.fire('Error', 'Server returned invalid response.', 'error');
                return;
            }
            Swal.close();
            if (data.success) {
                Swal.fire('Success', data.message, 'success').then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('batchAssignModal'))?.hide();
                    clearAllSelections();
                    const customerId = $('#customerSelect').val();
                    const dateFrom = $('#dateFrom').val();
                    const dateTo = $('#dateTo').val();
                    if (customerId) loadSpecificCustomerInvoices(customerId, dateFrom, dateTo);
                    else loadAllPendingInvoices(dateFrom, dateTo);
                });
            } else {
                Swal.fire('Error', data.message || 'Assignment failed', 'error');
            }
        } catch (error) {
            Swal.close();
            console.error(error);
            Swal.fire('Error', 'Assignment failed: ' + error.message, 'error');
        }
    });

    // Payment method selection
    document.querySelectorAll('.payment-method-option').forEach(opt => opt.addEventListener('click', function() { document.querySelectorAll('.payment-method-option').forEach(o => o.classList.remove('active')); this.classList.add('active'); selectedPaymentMethod = this.dataset.method; updatePaymentDetailsForm(); }));

    // Cash tendered events
    const cashTendered = document.getElementById('cashTendered');
    if (cashTendered) { 
        cashTendered.addEventListener('input', function(e) { 
            let value = this.value; 
            let cleanValue = value.replace(/[^\d.]/g, ''); 
            let parts = cleanValue.split('.'); 
            if (parts.length > 2) cleanValue = parts[0] + '.' + parts.slice(1).join(''); 
            if (parts.length === 2 && parts[1].length > 2) cleanValue = parts[0] + '.' + parts[1].substring(0, 2); 
            this.setAttribute('data-raw', cleanValue); 
            if (this.value !== cleanValue) this.value = cleanValue; 
            const amountDue = parseFloat(document.getElementById('payInvoiceAmount')?.value) || 0; 
            const tendered = parseFloat(cleanValue) || 0; 
            const change = tendered > amountDue ? tendered - amountDue : 0; 
            const changeDisplay = document.getElementById('cashChangeDisplay'); 
            if (changeDisplay) { 
                changeDisplay.innerText = '₱' + change.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); 
                changeDisplay.style.color = tendered <= 0 ? '#6c757d' : '#28a745'; 
            } 
        }); 
        cashTendered.addEventListener('blur', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) { 
                let num = parseFloat(rawValue); 
                this.value = num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); 
                this.setAttribute('data-raw', num.toString()); 
            } else if (!rawValue || rawValue === '') { 
                this.value = ''; 
                this.setAttribute('data-raw', ''); 
                const changeDisplay = document.getElementById('cashChangeDisplay'); 
                if (changeDisplay) { 
                    changeDisplay.innerText = '₱0.00'; 
                    changeDisplay.style.color = '#6c757d'; 
                } 
            } 
        }); 
        cashTendered.addEventListener('focus', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) this.value = parseFloat(rawValue).toString(); 
            else this.value = ''; 
            this.setSelectionRange(this.value.length, this.value.length); 
        }); 
    }

    // Payment amount input
    const paymentAmount = document.getElementById('paymentAmount');
    if (paymentAmount) { 
        paymentAmount.addEventListener('input', function(e) { 
            let value = this.value; 
            let cleanValue = value.replace(/[^\d.]/g, ''); 
            let parts = cleanValue.split('.'); 
            if (parts.length > 2) cleanValue = parts[0] + '.' + parts.slice(1).join(''); 
            if (parts.length === 2 && parts[1].length > 2) cleanValue = parts[0] + '.' + parts[1].substring(0, 2); 
            this.setAttribute('data-raw', cleanValue); 
            if (this.value !== cleanValue) this.value = cleanValue; 
        }); 
        paymentAmount.addEventListener('blur', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) { 
                let num = parseFloat(rawValue); 
                this.value = num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); 
                this.setAttribute('data-raw', num.toString()); 
            } else if (!rawValue || rawValue === '') { 
                this.value = ''; 
                this.setAttribute('data-raw', ''); 
            } 
        }); 
        paymentAmount.addEventListener('focus', function() { 
            let rawValue = this.getAttribute('data-raw') || this.value.replace(/[^\d.]/g, ''); 
            if (rawValue && !isNaN(parseFloat(rawValue))) this.value = parseFloat(rawValue).toString(); 
            else this.value = ''; 
            this.setSelectionRange(this.value.length, this.value.length); 
        }); 
    }

    document.getElementById('submitPaymentBtn').addEventListener('click', submitRemittance);
    const payAssignCollectorSelect = document.getElementById('payAssignCollectorSelect');
    if (payAssignCollectorSelect) {
        payAssignCollectorSelect.addEventListener('change', togglePaymentOrAssignMode);
    }
    loadAllPendingInvoices();
});

function clearAllSelections() {
    selectedInvoices.clear();
    $('.row-checkbox').prop('checked', false);
    $('#selectAllCheckbox').prop('checked', false);
    updateBatchAssignBar();
}

function updateBatchAssignBar() {
    const count = selectedInvoices.size;
    const batchBar = $('#batchAssignBar');
    if (count > 0) {
        batchBar.show();
        $('#selectedCount').text(count);
    } else {
        batchBar.hide();
    }
}

// ========== SIDEBAR FUNCTIONS ==========
function toggleSidebar() { const sidebar = document.getElementById('sidebar'); if (!sidebar) return; if (window.innerWidth > 992) { sidebar.classList.toggle('collapsed'); localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed')); if (!sidebar.classList.contains('collapsed')) setTimeout(() => expandActiveDropdownContainers(), 150); } else { sidebar.classList.toggle('active'); let overlay = document.querySelector('.sidebar-overlay'); if (!overlay && sidebar.classList.contains('active')) { overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.appendChild(overlay); overlay.addEventListener('click', function() { sidebar.classList.remove('active'); overlay.classList.remove('active'); setTimeout(() => overlay.remove(), 300); }); setTimeout(() => overlay.classList.add('active'), 10); } else if (overlay && !sidebar.classList.contains('active')) { overlay.classList.remove('active'); setTimeout(() => overlay.remove(), 300); } } }
function toggleSidebarDropdown(event, targetId) { event.preventDefault(); event.stopPropagation(); const target = document.getElementById(targetId); const btn = event.currentTarget; const arrow = btn ? btn.querySelector('.dropdown-arrow') : null; const sidebar = document.getElementById('sidebar'); if (!target || !sidebar) return; if (sidebar.classList.contains('collapsed') && window.innerWidth > 992) { sidebar.classList.remove('collapsed'); localStorage.setItem('sidebarCollapsed', 'false'); setTimeout(() => { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => { if (collapse.id !== targetId) collapse.classList.remove('show'); }); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }, 50); return; } if (target.classList.contains('show')) { target.classList.remove('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)'; } else { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => collapse.classList.remove('show')); document.querySelectorAll('.sidebar .dropdown-nav > .nav-link .dropdown-arrow').forEach(arrowIcon => { arrowIcon.style.transform = 'translateY(-50%) rotate(0deg)'; }); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; } }
function toggleDropdown(event, dropdownId) { event.preventDefault(); event.stopPropagation(); const dropdown = document.getElementById(dropdownId); const btn = event.currentTarget; if (!dropdown || !btn) return; if (dropdown.classList.contains('show')) { dropdown.classList.remove('show'); btn.classList.remove('active'); } else { ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => { const d = document.getElementById(id); if (d && d !== dropdown) d.classList.remove('show'); }); document.querySelectorAll('.more-btn').forEach(b => b.classList.remove('active')); dropdown.classList.add('show'); btn.classList.add('active'); setTimeout(() => { const closeHandler = function(e) { if (!dropdown.contains(e.target) && !btn.contains(e.target)) { dropdown.classList.remove('show'); btn.classList.remove('active'); document.removeEventListener('click', closeHandler); } }; document.addEventListener('click', closeHandler); }, 100); } }
function setActiveSidebarItem() { const currentPage = window.location.pathname.split('/').pop(); const sidebarLinks = document.querySelectorAll('.sidebar .nav-link'); sidebarLinks.forEach(link => link.classList.remove('active')); sidebarLinks.forEach(link => { const href = link.getAttribute('href'); if (href === currentPage) { link.classList.add('active'); const collapseDiv = link.closest('.collapse'); if (collapseDiv) { collapseDiv.classList.add('show'); const parentNav = collapseDiv.closest('.dropdown-nav'); if (parentNav) { const parentLink = parentNav.querySelector(':scope > .nav-link'); if (parentLink) { const arrow = parentLink.querySelector('.dropdown-arrow'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; } } } } }); }
function expandActiveDropdownContainers() { document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => { const activeLink = dropdownNav.querySelector('.nav-link.active'); if (activeLink) { const collapseDiv = dropdownNav.querySelector('.collapse'); if (collapseDiv && !collapseDiv.classList.contains('show')) { collapseDiv.classList.add('show'); const parentLink = dropdownNav.querySelector(':scope > .nav-link'); if (parentLink) { const arrow = parentLink.querySelector('.dropdown-arrow'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; } } } }); }
function showProfileModal() { const modalElement = document.getElementById('profileModal'); if (modalElement) new bootstrap.Modal(modalElement).show(); }
function confirmLogout() { const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal')); if (modal) modal.hide(); Swal.fire({ title: 'Are you sure?', text: 'You will be logged out', icon: 'question', showCancelButton: true, confirmButtonColor: '#2E7D32', confirmButtonText: 'Yes, logout', cancelButtonText: 'Cancel' }).then((result) => { if (result.isConfirmed) { localStorage.removeItem('sidebarCollapsed'); window.location.href = '../logout.php'; } }); }
function logout() { confirmLogout(); }
window.addEventListener('scroll', function() { ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => { const d = document.getElementById(id); if (d) d.classList.remove('show'); }); document.querySelectorAll('.more-btn').forEach(btn => btn.classList.remove('active')); });

// ========== COLLECTIONS FUNCTIONS ==========
function loadAllPendingInvoices(dateFrom = '', dateTo = '') {
    Swal.fire({ title: 'Loading...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const formData = new FormData(); formData.append('action', 'get_all_pending_invoices'); if (dateFrom) formData.append('start_date', dateFrom); if (dateTo) formData.append('end_date', dateTo);
    fetch(window.location.href, { method: 'POST', body: formData }).then(async response => { const text = await response.text(); try { return JSON.parse(text); } catch (e) { console.error('Invalid JSON response:', text.substring(0, 500)); throw new Error('Invalid server response'); } }).then(data => { Swal.close(); if (data.success) { currentInvoices = data.invoices; renderPendingInvoicesTable(data.invoices); const summaryDiv = document.getElementById('creditSummary'); if (summaryDiv) summaryDiv.style.display = 'none'; clearAllSelections(); } else Swal.fire('Error', data.message || 'Failed to load invoices', 'error'); }).catch(error => { Swal.close(); console.error(error); Swal.fire('Error', error.message || 'Failed to load invoices', 'error'); });
}
function loadSpecificCustomerInvoices(customerId, dateFrom = '', dateTo = '') {
    Swal.fire({ title: 'Loading...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const formData = new FormData(); formData.append('action', 'get_all_invoices'); formData.append('customer_id', customerId); if (dateFrom) formData.append('start_date', dateFrom); if (dateTo) formData.append('end_date', dateTo);
    fetch(window.location.href, { method: 'POST', body: formData }).then(async response => { const text = await response.text(); try { return JSON.parse(text); } catch (e) { console.error('Invalid JSON response:', text.substring(0, 500)); throw new Error('Invalid server response'); } }).then(data => { Swal.close(); if (data.success) { currentInvoices = data.invoices; renderCustomerInvoicesTable(data.invoices); updateCreditSummary(data.credit_limit, data.credit_used); clearAllSelections(); } else Swal.fire('Error', data.message || 'Failed to load invoices', 'error'); }).catch(error => { Swal.close(); console.error(error); Swal.fire('Error', error.message || 'Failed to load invoices', 'error'); });
}
function updateCreditSummary(limit, used) { const summaryDiv = document.getElementById('creditSummary'); if (summaryDiv) { limit = parseFloat(limit) || 0; used = parseFloat(used) || 0; const available = limit - used; document.getElementById('creditLimit').innerHTML = '₱' + limit.toLocaleString(undefined, { minimumFractionDigits: 2 }); document.getElementById('outstandingBalance').innerHTML = '₱' + used.toLocaleString(undefined, { minimumFractionDigits: 2 }); document.getElementById('availableCredit').innerHTML = '₱' + available.toLocaleString(undefined, { minimumFractionDigits: 2 }); summaryDiv.style.display = 'flex'; } }

function renderAssignedCollector(inv) {
    if (inv && inv.assigned_to_name) {
        const role = inv.assigned_to_role === 'delivery' ? 'Driver' : (inv.assigned_to_role === 'sales' ? 'Sales Agent' : '');
        const date = inv.collection_date ? new Date(inv.collection_date).toLocaleDateString() : '';
        return `<span class="assigned-collector-badge"><i class="bi bi-person-check"></i>${escapeHtml(inv.assigned_to_name)}</span><span class="assigned-date-small">${escapeHtml(role + (date ? ' • ' + date : ''))}</span>`;
    }
    return '<span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Unassigned</span>';
}

function renderPendingInvoicesTable(invoices) { 
    const tbody = document.getElementById('invoicesTableBody'); 
    if (!tbody) return; 
    if (!invoices || invoices.length === 0) { 
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No pending invoices found</td></tr>'; 
        return; 
    } 
    let html = ''; 
    invoices.forEach(inv => { 
        const invoiceDate = inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : '-'; 
        const amountDue = parseFloat(inv.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }); 
        const statusClass = inv.status === 'overdue' ? 'badge-overdue' : 'badge-pending'; 
        const statusText = inv.status === 'overdue' ? 'Overdue' : 'Pending'; 
        const customerName = escapeHtml(inv.customer_name || 'Unknown'); 
        const assignedCell = renderAssignedCollector(inv);
        let actionButton = ''; 
        const orderStatusLower = (inv.order_status || '').toLowerCase();
        if (inv.assigned_user_id || inv.assigned_to_name) {
            actionButton = '<span class="text-muted small"><i class="bi bi-person-check me-1"></i>Assigned</span>';
        } else if (orderStatusLower === 'delivered') {
            actionButton = `<button class="btn-pay" onclick="event.stopPropagation(); openPaymentModal(${inv.invoice_id}, '${escapeJsString(inv.invoice_number)}', ${parseFloat(inv.total_amount || 0)})"><i class="bi bi-cash-stack"></i> Record Payment</button>`;
        } else if (orderStatusLower === 'confirmed') {
            actionButton = '<span class="text-muted small">Await Delivery</span>';
        } else {
            actionButton = '<span class="text-muted small">Not Ready</span>';
        } 
        const isChecked = selectedInvoices.has(inv.invoice_id);
        html += `<tr class="invoice-row" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}" data-branch-id="${inv.branch_id || 0}">
            <td class="checkbox-column"><input type="checkbox" class="row-checkbox" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}" data-branch-id="${inv.branch_id || 0}" ${isChecked ? 'checked' : ''}></td>
            <td><strong>${customerName}</strong></td>
            <td>${escapeHtml(inv.invoice_number || '')}</td>
            <td>${invoiceDate}</td>
            <td>${escapeHtml(inv.so_number || '-')}</td>
            <td class="text-end fw-bold">₱${amountDue}</td>
            <td><span class="${statusClass}">${statusText}</span></td>
            <td>${assignedCell}</td>
            <td>${actionButton}</td>
        </tr>`; 
    }); 
    tbody.innerHTML = html; 
    
    $('.row-checkbox').off('change').on('change', function() {
        const invoiceId = $(this).data('invoice-id');
        const customerId = $(this).data('customer-id');
        const branchId = $(this).data('branch-id');
        if ($(this).prop('checked')) {
            if (!selectedInvoices.has(invoiceId)) {
                selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: branchId });
            }
        } else {
            selectedInvoices.delete(invoiceId);
        }
        const totalCheckboxes = $('.row-checkbox').length;
        const checkedCheckboxes = $('.row-checkbox:checked').length;
        $('#selectAllCheckbox').prop('checked', totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes);
        updateBatchAssignBar();
    });
    
    $('.invoice-row').off('click').on('click', function(e) { 
        if ($(e.target).hasClass('btn-pay') || $(e.target).closest('.btn-pay').length || $(e.target).hasClass('row-checkbox') || $(e.target).closest('.row-checkbox').length) return; 
        const invoiceId = $(this).data('invoice-id'); 
        const invoice = currentInvoices.find(inv => inv.invoice_id == invoiceId); 
        if (invoice && invoice.payment) 
            showPaymentDetails(invoice.payment); 
        else 
            Swal.fire('Info', 'No payment record found for this invoice yet', 'info'); 
    }); 
}

function renderCustomerInvoicesTable(invoices) { 
    const tbody = document.getElementById('invoicesTableBody'); 
    if (!tbody) return; 
    if (!invoices || invoices.length === 0) { 
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No pending invoices found for this customer</td></tr>'; 
        return; 
    } 
    let html = ''; 
    invoices.forEach(inv => { 
        const invoiceDate = inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : '-'; 
        const amountDue = parseFloat(inv.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }); 
        const statusClass = inv.status === 'overdue' ? 'badge-overdue' : 'badge-pending'; 
        const statusText = inv.status === 'overdue' ? 'Overdue' : 'Pending'; 
        const assignedCell = renderAssignedCollector(inv);
        let actionButton = ''; 
        const orderStatusLower = (inv.order_status || '').toLowerCase();
        if (inv.assigned_user_id || inv.assigned_to_name) {
            actionButton = '<span class="text-muted small"><i class="bi bi-person-check me-1"></i>Assigned</span>';
        } else if (orderStatusLower === 'delivered') {
            actionButton = `<button class="btn-pay" onclick="event.stopPropagation(); openPaymentModal(${inv.invoice_id}, '${escapeJsString(inv.invoice_number)}', ${parseFloat(inv.total_amount || 0)})"><i class="bi bi-cash-stack"></i> Record Payment</button>`;
        } else if (orderStatusLower === 'confirmed') {
            actionButton = '<span class="text-muted small">Await Delivery</span>';
        } else {
            actionButton = '<span class="text-muted small">Not Ready</span>';
        } 
        const isChecked = selectedInvoices.has(inv.invoice_id);
        html += `<tr class="invoice-row" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}">
            <td class="checkbox-column"><input type="checkbox" class="row-checkbox" data-invoice-id="${inv.invoice_id}" data-customer-id="${inv.customer_id}" ${isChecked ? 'checked' : ''}></td>
            <td>${escapeHtml(inv.customer_name || '-')}</td>
            <td>${escapeHtml(inv.invoice_number || '')}</td>
            <td>${invoiceDate}</td>
            <td>${escapeHtml(inv.so_number || '-')}</td>
            <td class="text-end fw-bold">₱${amountDue}</td>
            <td><span class="${statusClass}">${statusText}</span></td>
            <td>${assignedCell}</td>
            <td>${actionButton}</td>
        </tr>`; 
    }); 
    tbody.innerHTML = html; 
    
    $('.row-checkbox').off('change').on('change', function() {
        const invoiceId = $(this).data('invoice-id');
        const customerId = $(this).data('customer-id');
        if ($(this).prop('checked')) {
            if (!selectedInvoices.has(invoiceId)) {
                selectedInvoices.set(invoiceId, { invoice_id: invoiceId, customer_id: customerId, branch_id: 0 });
            }
        } else {
            selectedInvoices.delete(invoiceId);
        }
        const totalCheckboxes = $('.row-checkbox').length;
        const checkedCheckboxes = $('.row-checkbox:checked').length;
        $('#selectAllCheckbox').prop('checked', totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes);
        updateBatchAssignBar();
    });
    
    $('.invoice-row').off('click').on('click', function(e) { 
        if ($(e.target).hasClass('btn-pay') || $(e.target).closest('.btn-pay').length || $(e.target).hasClass('row-checkbox') || $(e.target).closest('.row-checkbox').length) return; 
        const invoiceId = $(this).data('invoice-id'); 
        const invoice = currentInvoices.find(inv => inv.invoice_id == invoiceId); 
        if (invoice && invoice.payment) 
            showPaymentDetails(invoice.payment); 
        else 
            Swal.fire('Info', 'No payment record found for this invoice', 'info'); 
    }); 
}

function showPaymentDetails(payment) { if (!payment) { Swal.fire('Info', 'No payment details available', 'info'); return; } document.getElementById('detailPaymentMethod').textContent = payment.payment_method ? payment.payment_method.toUpperCase() : '-'; document.getElementById('detailAmount').textContent = '₱' + parseFloat(payment.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }); document.getElementById('detailPaymentDate').textContent = payment.payment_date ? new Date(payment.payment_date).toLocaleString() : '-'; const receivedBy = payment.first_name ? payment.first_name + ' ' + (payment.last_name || '') : (payment.created_by || '-'); document.getElementById('detailReceivedBy').textContent = receivedBy; const detailCheckDateRow = document.getElementById('detailCheckDateRow'); const detailBankNameRow = document.getElementById('detailBankNameRow'); const detailBankBranchRow = document.getElementById('detailBankBranchRow'); const detailCheckNumberRow = document.getElementById('detailCheckNumberRow'); const detailRefNoRow = document.getElementById('detailRefNoRow'); if (payment.payment_method === 'check') { if (detailCheckDateRow) detailCheckDateRow.style.display = ''; if (detailBankNameRow) detailBankNameRow.style.display = ''; if (detailBankBranchRow) detailBankBranchRow.style.display = ''; if (detailCheckNumberRow) detailCheckNumberRow.style.display = ''; if (detailRefNoRow) detailRefNoRow.style.display = 'none'; document.getElementById('detailCheckDate').textContent = payment.check_date || '-'; document.getElementById('detailBankName').textContent = payment.bank_name || '-'; document.getElementById('detailBankBranch').textContent = payment.bank_branch || '-'; document.getElementById('detailCheckNumber').textContent = payment.check_number || '-'; } else if (payment.payment_method === 'online_transfer') { if (detailCheckDateRow) detailCheckDateRow.style.display = 'none'; if (detailBankNameRow) detailBankNameRow.style.display = ''; if (detailBankBranchRow) detailBankBranchRow.style.display = 'none'; if (detailCheckNumberRow) detailCheckNumberRow.style.display = 'none'; if (detailRefNoRow) detailRefNoRow.style.display = ''; document.getElementById('detailBankName').textContent = payment.bank_name || '-'; document.getElementById('detailRefNo').textContent = payment.reference_number || '-'; } else { if (detailCheckDateRow) detailCheckDateRow.style.display = 'none'; if (detailBankNameRow) detailBankNameRow.style.display = 'none'; if (detailBankBranchRow) detailBankBranchRow.style.display = 'none'; if (detailCheckNumberRow) detailCheckNumberRow.style.display = 'none'; if (detailRefNoRow) detailRefNoRow.style.display = 'none'; } new bootstrap.Modal(document.getElementById('paymentDetailsModal')).show(); }

function updatePaymentDetailsForm() {
    const container = document.getElementById('paymentDetailsContainer');
    const cashFields = document.getElementById('cashFields');
    const otherAmountFields = document.getElementById('otherAmountFields');
    const paymentAmountField = document.getElementById('paymentAmount');
    if (cashFields) cashFields.style.display = 'none';
    if (otherAmountFields) otherAmountFields.style.display = 'none';
    if (container) container.innerHTML = '';
    if (selectedPaymentMethod === 'cash') {
        if (cashFields) cashFields.style.display = 'block';
        if (paymentAmountField) { paymentAmountField.removeAttribute('required'); paymentAmountField.value = ''; }
        const cashTenderedInput = document.getElementById('cashTendered');
        if (cashTenderedInput) { cashTenderedInput.value = ''; cashTenderedInput.setAttribute('data-raw', ''); }
        const changeDisplay = document.getElementById('cashChangeDisplay');
        if (changeDisplay) { changeDisplay.innerText = '₱0.00'; changeDisplay.style.color = '#6c757d'; }
    } else if (selectedPaymentMethod === 'check') {
        if (otherAmountFields) otherAmountFields.style.display = 'block';
        if (container) {
            container.innerHTML = `<div class="payment-detail-group"><div class="row g-3">
                <div class="col-md-6"><label class="form-label">Check Date *</label><input type="date" class="form-control" id="checkDate" required></div>
                <div class="col-md-6"><label class="form-label">Bank Name *</label><input type="text" class="form-control" id="bankName" placeholder="Type bank name manually" required></div>
                <div class="col-md-6"><label class="form-label">Branch *</label><input type="text" class="form-control" id="bankBranch" placeholder="Type bank branch manually" required></div>
                <div class="col-md-6"><label class="form-label">Check No. *</label><input type="text" class="form-control" id="checkNumber" required></div>
            </div></div>`;
        }
        if (paymentAmountField) {
            paymentAmountField.setAttribute('required', 'required');
            const payInvoiceAmount = document.getElementById('payInvoiceAmount');
            if (payInvoiceAmount) { const amount = parseFloat(payInvoiceAmount.value) || 0; paymentAmountField.value = amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); paymentAmountField.setAttribute('data-raw', amount.toString()); }
        }
    } else if (selectedPaymentMethod === 'online_transfer') {
        if (otherAmountFields) otherAmountFields.style.display = 'block';
        if (container) {
            var onlineTransferSelectHtml = getOnlineTransferAccountSelectHtml('bankWallet', true);
            container.innerHTML = `<div class="payment-detail-group"><div class="row g-3">
                <div class="col-md-6"><label class="form-label">Bank/Wallet *</label>${onlineTransferSelectHtml}</div>
                <div class="col-md-6"><label class="form-label">Reference No. *</label><input type="text" class="form-control" id="referenceNumber" required></div>
            </div></div>`;
        }
        if (paymentAmountField) {
            paymentAmountField.setAttribute('required', 'required');
            const payInvoiceAmount = document.getElementById('payInvoiceAmount');
            if (payInvoiceAmount) { const amount = parseFloat(payInvoiceAmount.value) || 0; paymentAmountField.value = amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); paymentAmountField.setAttribute('data-raw', amount.toString()); }
        }
    }
}

function togglePaymentOrAssignMode() {
    const collectorSelect = document.getElementById('payAssignCollectorSelect');
    const paymentFormSection = document.getElementById('paymentFormSection');
    const submitBtn = document.getElementById('submitPaymentBtn');

    if (!collectorSelect || !paymentFormSection || !submitBtn) return;

    if (collectorSelect.value) {
        paymentFormSection.style.display = 'none';
        submitBtn.classList.remove('btn-warning');
        submitBtn.classList.add('btn-success');
        submitBtn.innerHTML = '<i class="bi bi-person-check"></i> Assign Collector';
    } else {
        paymentFormSection.style.display = '';
        submitBtn.classList.remove('btn-success');
        submitBtn.classList.add('btn-warning');
        submitBtn.innerHTML = '<i class="bi bi-send"></i> Submit for Approval';
    }
}
async function submitRemittance() {
    const invoiceId = document.getElementById('payInvoiceId').value;
    const amountDue = parseFloat(document.getElementById('payInvoiceAmount').value);

    if (!invoiceId || isNaN(amountDue) || amountDue <= 0) {
        Swal.fire('Error', 'Invalid invoice data', 'error');
        return;
    }

    const collectorSelect = document.getElementById('payAssignCollectorSelect');

    // If Branch Admin selected a collector, this is ASSIGN ONLY.
    // Do not record payment/remittance here. The assigned Sales/Driver will collect and remit later.
    if (collectorSelect && collectorSelect.value) {
        const collectionDate = new Date().toISOString().slice(0, 10);
        const assignData = {
            action: 'assign_collector',
            invoice_id: invoiceId,
            assigned_user_id: collectorSelect.value,
            collector_id: collectorSelect.value,
            collection_date: collectionDate
        };

        Swal.fire({ title: 'Assigning collector...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(assignData)
            });
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Raw response:', text);
                Swal.close();
                Swal.fire('Error', 'Server returned invalid response.', 'error');
                return;
            }

            Swal.close();
            if (data.success) {
                Swal.fire('Success', data.message || 'Collector assigned successfully.', 'success').then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('paymentModal'))?.hide();
                    const customerId = $('#customerSelect').val();
                    const dateFrom = $('#dateFrom').val();
                    const dateTo = $('#dateTo').val();
                    clearAllSelections();
                    if (customerId) loadSpecificCustomerInvoices(customerId, dateFrom, dateTo);
                    else loadAllPendingInvoices(dateFrom, dateTo);
                });
            } else {
                Swal.fire('Error', data.message || 'Failed to assign collector', 'error');
            }
        } catch (error) {
            Swal.close();
            console.error(error);
            Swal.fire('Error', 'Failed to assign collector: ' + error.message, 'error');
        }
        return;
    }

    let amount = 0;

    if (selectedPaymentMethod === 'cash') {
        const cashTenderedInput = document.getElementById('cashTendered');
        let rawValue = cashTenderedInput.getAttribute('data-raw');
        if (!rawValue) rawValue = cashTenderedInput.value.replace(/[^\d.]/g, '');
        const cashAmount = parseFloat(rawValue);

        if (isNaN(cashAmount) || cashAmount <= 0) {
            Swal.fire('Error', 'Please enter a valid cash payment amount', 'error');
            return;
        }
        if (cashAmount > amountDue) {
            Swal.fire('Error', 'Payment amount cannot be greater than the remaining balance', 'error');
            return;
        }
        amount = cashAmount;
    } else {
        const paymentAmountInput = document.getElementById('paymentAmount');
        let rawValue = paymentAmountInput.getAttribute('data-raw');
        if (!rawValue) rawValue = paymentAmountInput.value.replace(/[^\d.]/g, '');
        const paymentAmountValue = parseFloat(rawValue);

        if (isNaN(paymentAmountValue) || paymentAmountValue <= 0) {
            Swal.fire('Error', 'Please enter a valid payment amount', 'error');
            return;
        }
        if (paymentAmountValue > amountDue) {
            Swal.fire('Error', 'Payment amount cannot be greater than the remaining balance', 'error');
            return;
        }
        amount = paymentAmountValue;
    }

    let remittanceData = {
        action: 'submit_remittance',
        remittances: [{
            invoice_id: invoiceId,
            customer_id: 0,
            payment_method: selectedPaymentMethod,
            amount: amount,
            collection_date: new Date().toISOString().slice(0, 19).replace('T', ' ')
        }]
    };

    const currentInvoice = currentInvoices.find(inv => inv.invoice_id == invoiceId);
    if (currentInvoice) {
        remittanceData.remittances[0].customer_id = currentInvoice.customer_id;
    }

    if (selectedPaymentMethod === 'cash') {
        remittanceData.remittances[0].cash_tendered = null;
        remittanceData.remittances[0].cash_change = null;
    } else if (selectedPaymentMethod === 'check') {
        remittanceData.remittances[0].check_date = document.getElementById('checkDate').value;
        remittanceData.remittances[0].bank_name = document.getElementById('bankName').value;
        remittanceData.remittances[0].bank_branch = document.getElementById('bankBranch').value;
        remittanceData.remittances[0].check_number = document.getElementById('checkNumber').value;
        remittanceData.remittances[0].reference_number = document.getElementById('checkNumber').value;
        if (!remittanceData.remittances[0].check_date || !remittanceData.remittances[0].bank_name || !remittanceData.remittances[0].bank_branch || !remittanceData.remittances[0].check_number) {
            Swal.fire('Error', 'Please fill all check details', 'error');
            return;
        }
    } else if (selectedPaymentMethod === 'online_transfer') {
        remittanceData.remittances[0].reference_number = document.getElementById('referenceNumber').value;
        remittanceData.remittances[0].bank_name = document.getElementById('bankWallet').value;
        if (!remittanceData.remittances[0].reference_number || !remittanceData.remittances[0].bank_name) {
            Swal.fire('Error', 'Please fill all transfer details', 'error');
            return;
        }
    }

    Swal.fire({ title: 'Collecting payment...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(remittanceData)
        });
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Raw response:', text);
            Swal.close();
            Swal.fire('Error', 'Server returned invalid response.', 'error');
            return;
        }
        Swal.close();
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Collected!', text: data.message || 'Payment collected successfully.', confirmButtonColor: '#2E7D32' }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('paymentModal'))?.hide();
                const customerId = $('#customerSelect').val();
                const dateFrom = $('#dateFrom').val();
                const dateTo = $('#dateTo').val();
                clearAllSelections();
                if (customerId) loadSpecificCustomerInvoices(customerId, dateFrom, dateTo);
                else loadAllPendingInvoices(dateFrom, dateTo);
                setTimeout(() => location.reload(), 1500);
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to collect payment', 'error');
        }
    } catch (error) {
        Swal.close();
        console.error(error);
        Swal.fire('Error', 'Failed to collect payment: ' + error.message, 'error');
    }
}
function openPaymentModal(invoiceId, invoiceNumber, amountDue) {
    document.getElementById('payInvoiceId').value = invoiceId;
    document.getElementById('payInvoiceNumber').innerText = invoiceNumber;
    document.getElementById('payInvoiceAmount').value = amountDue;
    document.getElementById('payAmountDue').innerHTML = '₱' + Number(amountDue).toLocaleString(undefined, { minimumFractionDigits: 2 });
    selectedPaymentMethod = 'cash';
    const currentInvoice = (typeof currentInvoices !== 'undefined' && Array.isArray(currentInvoices)) ? currentInvoices.find(inv => String(inv.invoice_id) === String(invoiceId)) : null;
    const collectorSelect = document.getElementById('payAssignCollectorSelect');
    if (collectorSelect) collectorSelect.value = currentInvoice && currentInvoice.assigned_user_id ? currentInvoice.assigned_user_id : '';
    document.querySelectorAll('.payment-method-option').forEach(opt => { if (opt.dataset.method === 'cash') opt.classList.add('active'); else opt.classList.remove('active'); });
    updatePaymentDetailsForm();
    togglePaymentOrAssignMode();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

function escapeHtml(str) { if (!str) return ''; return String(str).replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }
function escapeJsString(str) { if (!str) return ''; return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\r/g, '\\r').replace(/\n/g, '\\n'); }

// Filter section toggle
$('#invoiceFilterToggleBtn').on('click', function() { const content = $('#invoiceFilterContent'); const icon = $('#invoiceFilterIcon'); if (content.hasClass('collapsed')) { content.removeClass('collapsed'); icon.removeClass('bi-chevron-down').addClass('bi-chevron-up'); } else { content.addClass('collapsed'); icon.removeClass('bi-chevron-up').addClass('bi-chevron-down'); } });

// Aging card click
$('#agingCardBtn').on('click', function() { $('#agingModal').modal('show'); });

// Aging Modal Functionality
$(document).ready(function() {
    $(document).on('click', '.remittance-row, .return-row', function(e) {
        if ($(e.target).closest('button, a, input, select, textarea').length) return;
        openAttachmentPhotoModal($(this).data('photo') || '', $(this).data('title') || 'Attachment Photo');
    });
    let overdueInvoicesData = [];
    
    $('#agingModal').on('show.bs.modal', function() {
        fetchOverdueInvoices();
    });
    
    $('#agingModal').on('hidden.bs.modal', function() {
        $('#agingMainView').show();
        $('#agingDetailView').hide();
        $('#detailInvoicesList').html('<div class="text-center text-muted py-4">Loading...</div>');
    });
    
    function fetchOverdueInvoices() {
        const formData = new FormData();
        formData.append('action', 'get_all_pending_invoices');
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text.substring(0, 500));
                    throw new Error('Invalid server response');
                }
            })
            .then(data => {
                if (data.success && data.invoices) {
                    overdueInvoicesData = data.invoices;
                }
            })
            .catch(error => {
                console.error('Error fetching invoices:', error);
            });
    }
    
    $('.aging-item.clickable').on('click', function() {
        const minDays = $(this).data('min-days');
        const maxDays = $(this).data('max-days');
        const rangeText = $(this).find('.aging-range .range-badge').text().trim();
        
        $('#detailViewTitle').text('Invoices (' + rangeText + ' overdue)');
        
        const filteredInvoices = overdueInvoicesData.filter(inv => {
            if (!inv.due_date) return false;
            const dueDate = new Date(inv.due_date);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            dueDate.setHours(0, 0, 0, 0);
            const daysOverdue = Math.floor((today - dueDate) / (1000 * 60 * 60 * 24));
            
            if (maxDays === 999) {
                return daysOverdue >= minDays;
            }
            return daysOverdue >= minDays && daysOverdue <= maxDays;
        });
        
        renderDetailInvoices(filteredInvoices);
        
        $('#agingMainView').hide();
        $('#agingDetailView').show();
        
        const modalBody = $('#agingModal .modal-body');
        if (modalBody.length) {
            modalBody.scrollTop(0);
        }
    });
    
    $('#backToAgingBtn').on('click', function() {
        $('#agingMainView').show();
        $('#agingDetailView').hide();
        
        const modalBody = $('#agingModal .modal-body');
        if (modalBody.length) {
            modalBody.scrollTop(0);
        }
    });
    
    function renderDetailInvoices(invoices) {
        const container = $('#detailInvoicesList');
        if (!invoices || invoices.length === 0) {
            container.html('<div class="text-center text-muted py-4"><i class="bi bi-inbox fs-1 d-block mb-2"></i>No invoices in this range</div>');
            return;
        }
        
        let html = '';
        invoices.forEach(inv => {
            const dueDate = inv.due_date ? new Date(inv.due_date).toLocaleDateString() : '-';
            const invoiceDate = inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString() : '-';
            const today = new Date();
            const dueDateObj = inv.due_date ? new Date(inv.due_date) : null;
            let daysOverdue = 0;
            if (dueDateObj) {
                today.setHours(0, 0, 0, 0);
                dueDateObj.setHours(0, 0, 0, 0);
                if (dueDateObj < today) {
                    daysOverdue = Math.floor((today - dueDateObj) / (1000 * 60 * 60 * 24));
                }
            }
            
            let borderColor = '';
            let statusBadge = '';
            if (daysOverdue <= 7) {
                borderColor = '#2dc937';
                statusBadge = '<span class="badge" style="background:#2dc937; color:white;">1-7 days</span>';
            } else if (daysOverdue <= 14) {
                borderColor = '#99c140';
                statusBadge = '<span class="badge" style="background:#99c140; color:white;">8-14 days</span>';
            } else if (daysOverdue <= 21) {
                borderColor = '#e7b416';
                statusBadge = '<span class="badge" style="background:#e7b416; color:white;">15-21 days</span>';
            } else if (daysOverdue <= 28) {
                borderColor = '#db7b2b';
                statusBadge = '<span class="badge" style="background:#db7b2b; color:white;">22-28 days</span>';
            } else {
                borderColor = '#cc3232';
                statusBadge = '<span class="badge" style="background:#cc3232; color:white;">Beyond 28 days</span>';
            }
            
            html += `
                <div class="invoice-detail-item" style="border-left-color: ${borderColor};">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="invoice-detail-customer">${escapeHtml(inv.customer_name || 'Unknown')}</span>
                                ${statusBadge}
                            </div>
                            <div class="invoice-detail-number mt-1">
                                <i class="bi bi-receipt me-1"></i>${escapeHtml(inv.invoice_number || '')}
                                ${inv.so_number ? '<span class="ms-2"><i class="bi bi-truck me-1"></i>SO: ' + escapeHtml(inv.so_number) + '</span>' : ''}
                            </div>
                            <div class="invoice-detail-date mt-1">
                                <i class="bi bi-calendar me-1"></i>Invoice: ${invoiceDate} | Due: ${dueDate} | <strong class="text-danger">${daysOverdue} days overdue</strong>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="invoice-detail-amount">₱${parseFloat(inv.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.html(html);
    }
});
document.addEventListener('DOMContentLoaded', function(){
    ['attachmentPhotoModal','rejectReturnTicketModal'].forEach(function(id){
        const modalEl = document.getElementById(id);
        if (modalEl) modalEl.addEventListener('hidden.bs.modal', function(){
            if (id === 'attachmentPhotoModal') { const img = document.getElementById('attachmentPhotoImg'); if (img) img.removeAttribute('src'); }
            cleanupBootstrapModals();
        });
    });
});
</script>
</body>
</html>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
