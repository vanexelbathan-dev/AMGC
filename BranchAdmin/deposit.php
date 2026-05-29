<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!file_exists('../config/database.php')) { die('Database configuration file not found. Please ensure ../config/database.php exists.'); }
require_once '../config/database.php';
if (!isset($conn) || !$conn || $conn->connect_error) {
    die('Database connection failed: ' . (isset($conn) ? $conn->connect_error : 'Connection variable not set'));
}
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php"); exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim(($_SESSION['first_name'] ?? 'Branch') . ' ' . ($_SESSION['last_name'] ?? 'Admin'));
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = (bool)($_SESSION['view_all_branches'] ?? false);

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}
function columnExists($conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}
function createBanksTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `banks` (
        `bank_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `bank_name` varchar(150) NOT NULL,
        `account_name` varchar(150) DEFAULT NULL,
        `account_number` varchar(100) DEFAULT NULL,
        `bank_branch` varchar(150) DEFAULT NULL,
        `parent_bank_id` int(11) DEFAULT NULL,
        `status` enum('active','inactive') NOT NULL DEFAULT 'active',
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`bank_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`),
        KEY `parent_bank_id` (`parent_bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
function getBanks($conn, $view_all_branches, $branch_id, $active_only = true) {
    createBanksTable($conn);
    $sql = "SELECT b.*, pb.bank_name AS parent_bank_name
            FROM banks b
            LEFT JOIN banks pb ON b.parent_bank_id = pb.bank_id
            WHERE b.parent_bank_id IS NOT NULL";
    if ($active_only) $sql .= " AND b.status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (b.branch_id = ? OR b.branch_id = 0)";
    $sql .= " ORDER BY COALESCE(pb.bank_name, b.bank_name) ASC, b.bank_name ASC, b.bank_id ASC";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}
function getRegisteredBankName($conn, $bank_id, $view_all_branches, $branch_id) {
    createBanksTable($conn);
    $bank_id = (int)$bank_id;
    if ($bank_id <= 0) return '';
    $sql = "SELECT bank_name FROM banks WHERE bank_id = ? AND status = 'active' AND parent_bank_id IS NOT NULL";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return '';
    if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('ii', $bank_id, $branch_id); else $stmt->bind_param('i', $bank_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim($row['bank_name'] ?? '');
}
function getUserInitials($user_name) {
    $parts = preg_split('/\s+/', trim($user_name));
    $initials = '';
    foreach ($parts as $part) { if ($part !== '') $initials .= strtoupper(substr($part, 0, 1)); }
    return $initials !== '' ? substr($initials, 0, 2) : 'BA';
}
function createBankingTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_transactions` (
        `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `transaction_type` enum('deposit','withdrawal') NOT NULL,
        `transaction_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `bank_name` varchar(150) DEFAULT NULL,
        `bank_id` int(11) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transaction_id`),
        KEY `branch_id` (`branch_id`),
        KEY `transaction_type` (`transaction_type`),
        KEY `transaction_date` (`transaction_date`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if (!columnExists($conn, 'bank_transactions', 'bank_id')) {
        $conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `bank_id` int(11) DEFAULT NULL AFTER `bank_name`");
    }
    $idxBankId = $conn->query("SHOW INDEX FROM `bank_transactions` WHERE Key_name = 'bank_id'");
    if (!$idxBankId || $idxBankId->num_rows === 0) {
        $conn->query("ALTER TABLE `bank_transactions` ADD INDEX `bank_id` (`bank_id`)");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_transaction_payments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` int(11) NOT NULL,
        `payment_id` int(11) NOT NULL,
        `amount_applied` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_transaction_payment` (`transaction_id`, `payment_id`),
        KEY `payment_id` (`payment_id`),
        KEY `transaction_id` (`transaction_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    
    // Credit Memos tables
    $conn->query("CREATE TABLE IF NOT EXISTS `credit_memos` (
        `credit_memo_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `customer_id` int(11) NOT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `credit_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `status` enum('unapplied','applied') NOT NULL DEFAULT 'unapplied',
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`credit_memo_id`),
        KEY `branch_id` (`branch_id`),
        KEY `customer_id` (`customer_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    
    $conn->query("CREATE TABLE IF NOT EXISTS `credit_memo_attachments` (
        `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
        `credit_memo_id` int(11) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `file_name` varchar(255) NOT NULL,
        `stored_name` varchar(255) NOT NULL,
        `file_path` varchar(500) NOT NULL,
        `file_type` varchar(120) DEFAULT NULL,
        `file_size` int(11) NOT NULL DEFAULT 0,
        `uploaded_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`attachment_id`),
        KEY `credit_memo_id` (`credit_memo_id`),
        KEY `customer_id` (`customer_id`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `bank_transaction_credit_memos` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` int(11) NOT NULL,
        `credit_memo_id` int(11) NOT NULL,
        `amount_applied` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_transaction_credit` (`transaction_id`, `credit_memo_id`),
        KEY `credit_memo_id` (`credit_memo_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
createBankingTables($conn);
createBanksTable($conn);

$user_initials = getUserInitials($user_name);
$so_branch_column_exists = columnExists($conn, 'sales_orders', 'branch_id');
$invoices_has_so_id = columnExists($conn, 'invoices', 'so_id');

function getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    $sql = "SELECT p.payment_id, p.invoice_id, p.customer_id, p.payment_method, p.amount, p.payment_date,
                   p.reference_number, p.bank_name, c.customer_name, i.invoice_number,
                   COALESCE(so.so_number, '') AS so_number,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            " . ($invoices_has_so_id ? "LEFT JOIN sales_orders so ON i.so_id = so.so_id" : "LEFT JOIN sales_orders so ON 1=0") . "
            LEFT JOIN users u ON p.created_by = u.user_id
            LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
            WHERE btp.payment_id IS NULL";
    if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) $sql .= " AND so.branch_id = ?";
    $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getAvailableCreditMemos($conn, $view_all_branches, $branch_id) {
    $sql = "SELECT cm.*, c.customer_name,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
            FROM credit_memos cm
            LEFT JOIN customers c ON cm.customer_id = c.customer_id
            LEFT JOIN users u ON cm.created_by = u.user_id
            LEFT JOIN bank_transaction_credit_memos btcm ON cm.credit_memo_id = btcm.credit_memo_id
            WHERE btcm.credit_memo_id IS NULL AND cm.status = 'unapplied'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND cm.branch_id = ?";
    $sql .= " ORDER BY cm.credit_date DESC, cm.credit_memo_id DESC";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}


function enrichCreditMemosWithAttachments($conn, &$credit_memos) {
    if (!is_array($credit_memos) || count($credit_memos) === 0) return;

    $ids = [];
    foreach ($credit_memos as $cm) {
        $id = (int)($cm['credit_memo_id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) return;

    $attachments_by_memo = [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT attachment_id, credit_memo_id, file_name, stored_name, file_path, file_type, file_size, uploaded_by, created_at
            FROM credit_memo_attachments
            WHERE credit_memo_id IN ($placeholders)
            ORDER BY attachment_id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;

    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $memo_id = (int)$row['credit_memo_id'];
        if (!isset($attachments_by_memo[$memo_id])) $attachments_by_memo[$memo_id] = [];
        $attachments_by_memo[$memo_id][] = $row;
    }
    $stmt->close();

    foreach ($credit_memos as &$cm) {
        $memo_id = (int)($cm['credit_memo_id'] ?? 0);
        $cm['attachments'] = $attachments_by_memo[$memo_id] ?? [];
        $cm['attachment_count'] = count($cm['attachments']);
    }
    unset($cm);
}

function getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    $sql = "SELECT p.payment_id, p.payment_method, p.amount, p.payment_date, p.reference_number, p.bank_name,
                   c.customer_name, i.invoice_number, COALESCE(so.so_number, '') AS so_number,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name,
                   CASE WHEN btp.payment_id IS NULL THEN 'Undeposited' ELSE 'Deposited' END AS deposit_status
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            " . ($invoices_has_so_id ? "LEFT JOIN sales_orders so ON i.so_id = so.so_id" : "LEFT JOIN sales_orders so ON 1=0") . "
            LEFT JOIN users u ON p.created_by = u.user_id
            LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
            WHERE 1=1";
    if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) $sql .= " AND so.branch_id = ?";
    $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 50";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getBankTransactions($conn, $view_all_branches, $branch_id) {
    $sql = "SELECT bt.transaction_id, bt.branch_id, bt.transaction_type, bt.transaction_date,
                   bt.reference_number, bt.bank_name, bt.bank_id, bt.description, bt.amount,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name,
                   GROUP_CONCAT(DISTINCT 
                       CONCAT('payment||', COALESCE(c.customer_name, 'Unknown'), '||', COALESCE(i.invoice_number, CONCAT('INV-', p.invoice_id)), '||', FORMAT(btp.amount_applied, 2))
                       ORDER BY p.payment_id SEPARATOR ';;') AS payment_links,
                   GROUP_CONCAT(DISTINCT 
                       CONCAT('credit||', COALESCE(c2.customer_name, 'Unknown'), '||CM-', cm.credit_memo_id, '||', FORMAT(btcm.amount_applied, 2))
                       ORDER BY cm.credit_memo_id SEPARATOR ';;') AS credit_links
            FROM bank_transactions bt
            LEFT JOIN users u ON bt.created_by = u.user_id
            LEFT JOIN bank_transaction_payments btp ON bt.transaction_id = btp.transaction_id
            LEFT JOIN payments p ON btp.payment_id = p.payment_id
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            LEFT JOIN bank_transaction_credit_memos btcm ON bt.transaction_id = btcm.transaction_id
            LEFT JOIN credit_memos cm ON btcm.credit_memo_id = cm.credit_memo_id
            LEFT JOIN customers c2 ON cm.customer_id = c2.customer_id
            WHERE bt.transaction_type = 'deposit'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND bt.branch_id = ?";
    $sql .= " GROUP BY bt.transaction_id ORDER BY bt.transaction_date DESC, bt.transaction_id DESC";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getValidDepositItems($conn, $payment_ids, $credit_memo_ids, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    // Validate payments
    $valid_payments = [];
    if (!empty($payment_ids)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$payment_ids), function($id) { return $id > 0; })));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT p.payment_id FROM payments p
                    LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
                    LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
                    " . ($invoices_has_so_id ? "LEFT JOIN sales_orders so ON i.so_id = so.so_id" : "LEFT JOIN sales_orders so ON 1=0") . "
                    WHERE p.payment_id IN ($placeholders) AND btp.payment_id IS NULL";
            if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) $sql .= " AND so.branch_id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($ids));
                $params = $ids;
                if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) { $types .= 'i'; $params[] = $branch_id; }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $valid_payments[] = (int)$row['payment_id'];
                $stmt->close();
            }
        }
    }
    
    // Validate credit memos
    $valid_credits = [];
    if (!empty($credit_memo_ids)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$credit_memo_ids), function($id) { return $id > 0; })));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT credit_memos.credit_memo_id FROM credit_memos
                    LEFT JOIN bank_transaction_credit_memos btcm ON credit_memos.credit_memo_id = btcm.credit_memo_id
                    WHERE credit_memos.credit_memo_id IN ($placeholders) AND btcm.credit_memo_id IS NULL AND credit_memos.status = 'unapplied'";
            if (!$view_all_branches && $branch_id > 0) $sql .= " AND credit_memos.branch_id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($ids));
                $params = $ids;
                if (!$view_all_branches && $branch_id > 0) { $types .= 'i'; $params[] = $branch_id; }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $valid_credits[] = (int)$row['credit_memo_id'];
                $stmt->close();
            }
        }
    }
    return ['payments' => $valid_payments, 'credits' => $valid_credits];
}

function getCreditMemoCustomers($conn, $view_all_branches, $branch_id) {
    $has_branch = columnExists($conn, 'customers', 'branch_id');
    $sql = "SELECT MIN(customer_id) AS customer_id, TRIM(customer_name) AS customer_name
            FROM customers
            WHERE status = 'active' AND TRIM(customer_name) <> ''";
    if (!$view_all_branches && $branch_id > 0 && $has_branch) {
        $sql .= " AND branch_id = ?";
    }
    $sql .= " GROUP BY LOWER(TRIM(customer_name)) ORDER BY customer_name ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && $has_branch) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
    }
    return $rows;
}

function isValidCreditMemoCustomer($conn, $customer_id, $view_all_branches, $branch_id) {
    $customer_id = (int)$customer_id;
    if ($customer_id <= 0) return false;
    $has_branch = columnExists($conn, 'customers', 'branch_id');
    $sql = "SELECT customer_id FROM customers WHERE customer_id = ? AND status = 'active'";
    if (!$view_all_branches && $branch_id > 0 && $has_branch) {
        $sql .= " AND branch_id = ?";
    }
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if (!$view_all_branches && $branch_id > 0 && $has_branch) $stmt->bind_param('ii', $customer_id, $branch_id);
    else $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

function saveCreditMemoAttachments($conn, $credit_memo_id, $customer_id, $branch_id, $uploaded_by) {
    if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'])) return 0;

    $project_root = realpath(dirname(__DIR__));
    if ($project_root === false) $project_root = dirname(__DIR__);

    $upload_dir = rtrim($project_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'credit_memos' . DIRECTORY_SEPARATOR;
    $public_dir = '../uploads/credit_memos/';

    if (!is_dir($upload_dir)) {
        if (!@mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
            throw new Exception('Unable to create attachment upload folder: uploads/credit_memos');
        }
    }
    @chmod($upload_dir, 0775);
    if (!is_writable($upload_dir)) {
        throw new Exception('Attachment upload folder is not writable: uploads/credit_memos');
    }

    $allowed_ext = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt'];
    $names = $_FILES['attachments']['name'];
    $tmp_names = $_FILES['attachments']['tmp_name'];
    $errors = $_FILES['attachments']['error'];
    $sizes = $_FILES['attachments']['size'];
    $types = $_FILES['attachments']['type'];

    if (!is_array($names)) {
        $names = [$names];
        $tmp_names = [$tmp_names];
        $errors = [$errors];
        $sizes = [$sizes];
        $types = [$types];
    }

    $saved_count = 0;
    for ($i = 0; $i < count($names); $i++) {
        $upload_error = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
        $original_name = basename((string)($names[$i] ?? ''));
        if ($upload_error === UPLOAD_ERR_NO_FILE || $original_name === '') continue;
        if ($upload_error !== UPLOAD_ERR_OK) throw new Exception('Failed to upload attachment: ' . $original_name . ' (error code: ' . $upload_error . ')');

        $tmp_file = $tmp_names[$i] ?? '';
        if ($tmp_file === '' || !is_uploaded_file($tmp_file)) throw new Exception('Invalid uploaded attachment: ' . $original_name);

        $file_size = (int)($sizes[$i] ?? 0);
        if ($file_size > 10 * 1024 * 1024) throw new Exception('Attachment is too large. Maximum allowed size is 10MB per file: ' . $original_name);

        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed_ext, true)) throw new Exception('Invalid attachment type: ' . $original_name);

        $safe_original_name = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $original_name);
        if ($safe_original_name === '' || $safe_original_name === null) $safe_original_name = 'attachment.' . $ext;

        $stored_name = 'cm_' . (int)$credit_memo_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target_path = $upload_dir . $stored_name;
        if (!move_uploaded_file($tmp_file, $target_path)) throw new Exception('Unable to save attachment to uploads/credit_memos: ' . $original_name);
        @chmod($target_path, 0664);

        $file_type = $types[$i] ?? null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected_type = finfo_file($finfo, $target_path);
                if (!empty($detected_type)) $file_type = $detected_type;
                finfo_close($finfo);
            }
        }

        $public_path = $public_dir . $stored_name;
        $stmt = $conn->prepare("INSERT INTO credit_memo_attachments
            (credit_memo_id, customer_id, branch_id, file_name, stored_name, file_path, file_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            @unlink($target_path);
            throw new Exception('Failed to prepare attachment insert: ' . $conn->error);
        }
        $stmt->bind_param('iiissssii', $credit_memo_id, $customer_id, $branch_id, $safe_original_name, $stored_name, $public_path, $file_type, $file_size, $uploaded_by);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            @unlink($target_path);
            throw new Exception('Failed to save attachment record: ' . $error);
        }
        $stmt->close();
        $saved_count++;
    }
    return $saved_count;
}

// Handle Credit Memo Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_credit_memo') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $credit_date = date('Y-m-d H:i:s', strtotime($_POST['credit_date'] ?? 'now'));
    $reference_number = trim($_POST['reference_number'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if ($customer_id <= 0 || !isValidCreditMemoCustomer($conn, $customer_id, $view_all_branches, $branch_id)) {
        $_SESSION['error_message'] = 'Please select a valid customer from your branch.';
    } elseif ($amount <= 0) {
        $_SESSION['error_message'] = 'Amount must be greater than zero.';
    } else {
        $branch_id_db = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO credit_memos (branch_id, customer_id, amount, credit_date, reference_number, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'unapplied', ?)");
            if (!$stmt) throw new Exception('Failed to prepare credit memo: ' . $conn->error);
            $stmt->bind_param('iidsssi', $branch_id_db, $customer_id, $amount, $credit_date, $reference_number, $description, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to create credit memo: ' . $stmt->error);
            $credit_memo_id = (int)$conn->insert_id;
            $stmt->close();

            $saved_attachments = saveCreditMemoAttachments($conn, $credit_memo_id, $customer_id, $branch_id_db, $user_id);
            $conn->commit();
            $_SESSION['success_message'] = 'Credit memo created successfully' . ($saved_attachments > 0 ? ' with ' . $saved_attachments . ' attachment(s).' : '.');
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = $e->getMessage();
        }
    }
    header('Location: deposit.php');
    exit();
}

// Handle Deposit Creation
$flash_success = $_SESSION['success_message'] ?? '';
$flash_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_deposit') {
    try {
        $payment_ids_raw = $_POST['payment_ids'] ?? [];
        $credit_memo_ids_raw = $_POST['credit_memo_ids'] ?? [];
        $valid = getValidDepositItems($conn, $payment_ids_raw, $credit_memo_ids_raw, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
        $payment_ids = $valid['payments'];
        $credit_memo_ids = $valid['credits'];
        
        if (empty($payment_ids) && empty($credit_memo_ids)) throw new Exception('Please select at least one undeposited payment or credit memo.');
        
        $transaction_date = date('Y-m-d 00:00:00', strtotime($_POST['transaction_date'] ?? 'now'));
        $reference_number = trim($_POST['reference_number'] ?? '');
        $bank_id = (int)($_POST['bank_id'] ?? 0);
        $bank_name = getRegisteredBankName($conn, $bank_id, $view_all_branches, $branch_id);
        if ($bank_name === '') throw new Exception('Please select a registered sub account. Parent banks are folders only and cannot receive transactions.');
        $description = trim($_POST['description'] ?? 'Collections deposit');
        
        // Calculate total amount: sum(payments) - sum(credit_memos)
        $total_payments = 0;
        if (!empty($payment_ids)) {
            $placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE payment_id IN ($placeholders)");
            $stmt->bind_param(str_repeat('i', count($payment_ids)), ...$payment_ids);
            $stmt->execute();
            $total_payments = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }
        
        $total_credits = 0;
        if (!empty($credit_memo_ids)) {
            $placeholders = implode(',', array_fill(0, count($credit_memo_ids), '?'));
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM credit_memos WHERE credit_memo_id IN ($placeholders)");
            $stmt->bind_param(str_repeat('i', count($credit_memo_ids)), ...$credit_memo_ids);
            $stmt->execute();
            $total_credits = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }
        
        $deposit_amount = $total_payments - $total_credits;
        if ($deposit_amount <= 0) throw new Exception('Net deposit amount must be greater than zero.');
        
        $conn->begin_transaction();
        $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;
        $insert = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, bank_id, description, amount, created_by) VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param('isssisdi', $effective_branch_id, $transaction_date, $reference_number, $bank_name, $bank_id, $description, $deposit_amount, $user_id);
        if (!$insert->execute()) throw new Exception('Failed to save deposit transaction: ' . $insert->error);
        $transaction_id = (int)$conn->insert_id;
        $insert->close();
        
        // Link payments
        if (!empty($payment_ids)) {
            $link_stmt = $conn->prepare("INSERT INTO bank_transaction_payments (transaction_id, payment_id, amount_applied) VALUES (?, ?, ?)");
            $amt_stmt = $conn->prepare("SELECT amount FROM payments WHERE payment_id = ? LIMIT 1");
            foreach ($payment_ids as $pid) {
                $amt_stmt->bind_param('i', $pid);
                $amt_stmt->execute();
                $applied = (float)($amt_stmt->get_result()->fetch_assoc()['amount'] ?? 0);
                $link_stmt->bind_param('iid', $transaction_id, $pid, $applied);
                if (!$link_stmt->execute()) throw new Exception('Failed to link payment: ' . $link_stmt->error);
            }
            $amt_stmt->close(); $link_stmt->close();
        }
        
        // Link credit memos
        if (!empty($credit_memo_ids)) {
            $link_credit_stmt = $conn->prepare("INSERT INTO bank_transaction_credit_memos (transaction_id, credit_memo_id, amount_applied) VALUES (?, ?, ?)");
            $amt_credit_stmt = $conn->prepare("SELECT amount FROM credit_memos WHERE credit_memo_id = ? LIMIT 1");
            foreach ($credit_memo_ids as $cid) {
                $amt_credit_stmt->bind_param('i', $cid);
                $amt_credit_stmt->execute();
                $applied = (float)($amt_credit_stmt->get_result()->fetch_assoc()['amount'] ?? 0);
                $link_credit_stmt->bind_param('iid', $transaction_id, $cid, $applied);
                if (!$link_credit_stmt->execute()) throw new Exception('Failed to link credit memo: ' . $link_credit_stmt->error);
                // Update credit memo status to applied
                $upd = $conn->prepare("UPDATE credit_memos SET status = 'applied' WHERE credit_memo_id = ?");
                $upd->bind_param('i', $cid);
                $upd->execute();
                $upd->close();
            }
            $amt_credit_stmt->close(); $link_credit_stmt->close();
        }
        
        $conn->commit();
        $_SESSION['success_message'] = 'Deposit transaction saved successfully.';
    } catch (Exception $e) {
        if (isset($conn) && $conn) @$conn->rollback();
        $_SESSION['error_message'] = $e->getMessage();
    }
    header('Location: deposit.php'); exit();
}

$available_payments = getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$available_credits = getAvailableCreditMemos($conn, $view_all_branches, $branch_id);
enrichCreditMemosWithAttachments($conn, $available_credits);
$recent_payments = getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$bank_transactions = getBankTransactions($conn, $view_all_branches, $branch_id);
$banks = getBanks($conn, $view_all_branches, $branch_id);

// Get active customers for credit memo dropdown (current branch only, no duplicate names)
$customers = getCreditMemoCustomers($conn, $view_all_branches, $branch_id);

$undeposited_payments_total = array_sum(array_column($available_payments, 'amount'));
$undeposited_credits_total = array_sum(array_column($available_credits, 'amount'));
$undeposited_net = $undeposited_payments_total - $undeposited_credits_total;
$total_collections = array_sum(array_column($recent_payments, 'amount'));
$total_deposits = array_sum(array_column($bank_transactions, 'amount'));
$bank_balance = $total_deposits;

// Distinct filters
$distinct_collectors = [];
$distinct_methods = [];
foreach ($available_payments as $p) {
    $collector = trim($p['collected_by_name'] ?? '');
    if ($collector !== '') $distinct_collectors[$collector] = true;
    $method = trim($p['payment_method']);
    if ($method !== '') $distinct_methods[$method] = true;
}
$distinct_collectors = array_keys($distinct_collectors);
$distinct_methods = array_keys($distinct_methods);
sort($distinct_collectors);
sort($distinct_methods);

$distinct_credit_customers = [];
foreach ($available_credits as $c) {
    $cust = trim($c['customer_name'] ?? '');
    if ($cust !== '') $distinct_credit_customers[$cust] = true;
}
$distinct_credit_customers = array_keys($distinct_credit_customers);
sort($distinct_credit_customers);

?>
<?php $page_title = 'Deposit'; $page_subtitle = 'Undeposited Funds and Deposit History'; $active_page = 'deposit'; ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?> - AMGC</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
<link rel="shortcut icon" href="../Pictures/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
<link rel="manifest" href="../Pictures/site.webmanifest" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--primary-green:#44D34E;--secondary-green:#44D34E;--light-green:#d1fae5;--dark-green:#047857;--dark-color:#052A47;--light-color:#f9fafb}
.stat-card-banking{
    background: linear-gradient(135deg, #047857, #059669) !important;
            border: none !important;
            border-radius: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
            min-height: 120px;
            height: 100%;
            padding: 1rem !important;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
            color: #fff;
}
.stat-label{
    color: #fff;
    font-size:.88rem;
}
.stat-value{
    color: #fff;
    font-weight:700;
    font-size:1.45rem;
}
.stat-icon{
    width:48px;
    height:48px;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(68, 211, 77, 0.24);
    color:#fff;
    font-size:1.25rem
}
.section-card{background:#fff;border-radius:18px;border:1px solid rgba(68,211,78,.12);box-shadow:0 8px 20px rgba(15,23,42,.05);margin-bottom:1rem;overflow:hidden}.section-header{padding:1rem 1.25rem;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center;gap:1rem}.section-body{padding:1rem 1.25rem}
.badge-soft-green{background:rgba(68,211,78,.16);color:#047857}.badge-soft-blue{background:rgba(34,211,238,.15);color:#0f766e}.badge-soft-red{background:rgba(248,113,113,.14);color:#b91c1c}
.table thead th{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);color:#fff!important;border:none;white-space:nowrap;font-size:.84rem}
.table-nowrap th, .table-nowrap td { white-space: nowrap !important; vertical-align: middle; }
.table tbody td{vertical-align:middle;font-size:.92rem}
.table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
#undepositedTable, #creditMemoTable{min-width:650px;width:100%}
#undepositedTable th,#undepositedTable td, #creditMemoTable th,#creditMemoTable td{white-space:nowrap;word-break:normal;padding:0.6rem 0.5rem}
.clickable-row{cursor:pointer;transition:background 0.2s}
.clickable-row:hover{background-color:#f0fdf4!important}
.deposit-row-clickable{cursor:pointer}
.deposit-row-clickable:hover{background-color:#f0fdf4!important}
.amount-positive{color:#047857;font-weight:700}
.amount-negative{color:#dc3545;font-weight:700}
.filter-bar{background:#f8fafc;border-radius:16px;padding:0.8rem 1rem;margin-bottom:1rem;border:1px solid #e2e8f0}
.filter-bar .form-select,.filter-bar .form-control{min-height:38px;font-size:0.85rem}
.btn-amgc-primary{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);color:#fff;border:none;border-radius:999px;padding:8px 18px;min-height:36px;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 10px rgba(0,0,0,.15);transition:all .2s ease}.btn-amgc-primary:hover{color:#fff;transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.2);opacity:.95}.btn-amgc-dark{background:#052A47;color:#fff;border:none;border-radius:999px;padding:8px 18px;min-height:36px;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 4px 10px rgba(0,0,0,.15);transition:all .2s ease}.btn-amgc-dark:hover{color:#fff;transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.2);opacity:.96}.nav-tabs .nav-link{font-weight:700;color:#052A47}.nav-tabs .nav-link.active{color:#047857}.navbar-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}.mobile-menu-btn{display:none;background:transparent;border:none;font-size:1.5rem;color:#052A47}
.deposit-form-wrapper{transition:all 0.3s ease}
.deposit-form-wrapper.collapsed{display:none}
.toggle-form-btn{background:#f0fdf4;border:1px solid #44D34E;color:#047857;border-radius:40px;padding:0.3rem 1rem;font-size:0.85rem;font-weight:600}
.undeposited-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem}
@media(max-width:992px){.sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}.main-content{margin-left:0!important}.mobile-menu-btn{display:block}body{padding-bottom:70px}}
@media(max-width:768px){.section-header{display:block}.stat-value{font-size:1.2rem}.filter-bar .row > div{margin-bottom:0.5rem}}

.swal2-popup.amgc-swal-popup{
    border-radius:20px !important;
    border:1px solid rgba(4,120,87,.15) !important;
    box-shadow:0 18px 45px rgba(5,42,71,.18) !important;
}
.swal2-title.amgc-swal-title{
    color:#052A47 !important;
    font-weight:600 !important;
}
.swal2-confirm.amgc-swal-confirm{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%) !important;
    border:0 !important;
    border-radius:12px !important;
    box-shadow:0 6px 14px rgba(4,120,87,.25) !important;
}
.swal2-cancel.amgc-swal-cancel{
    background:#6c757d !important;
    border:0 !important;
    border-radius:12px !important;
}
.creditmemo-attachment-open{
    border:0;
    background:#fff;
    width:100%;
    text-align:left;
}
.creditmemo-attachment-open:hover{
    background:#f0fdf4;
}
.creditmemo-preview-frame{
    width:100%;
    min-height:70vh;
    border:1px solid #d1fae5;
    border-radius:14px;
}
.creditmemo-preview-image{
    max-width:100%;
    max-height:70vh;
    object-fit:contain;
    border-radius:14px;
    border:1px solid #d1fae5;
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
<script>
function toggleDepositForm() {
    const formDiv = document.getElementById('depositFormWrapper');
    const btnIcon = document.getElementById('toggleFormIcon');
    if (formDiv.classList.contains('collapsed')) {
        formDiv.classList.remove('collapsed');
        if (btnIcon) btnIcon.classList.remove('bi-chevron-down');
        if (btnIcon) btnIcon.classList.add('bi-chevron-up');
    } else {
        formDiv.classList.add('collapsed');
        if (btnIcon) btnIcon.classList.remove('bi-chevron-up');
        if (btnIcon) btnIcon.classList.add('bi-chevron-down');
    }
}
</script>
</head><body><div id="appPage">
<div class="sidebar" id="sidebar">
<div class="sidebar-header"><h3><button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list"></i></button><img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"><span class="nav-text">Branch Admin</span></h3></div>
<div class="sidebar-content"><div class="sidebar-menu"><ul class="nav flex-column">
    <li class="nav-item"><a class="nav-link" href="branchdashboard.php"><i class="bi bi-speedometer2"></i><span class="nav-text">Dashboard</span></a></li>
    <li class="nav-item dropdown-nav"><a class="nav-link" href="#" onclick="toggleSidebarDropdown(event,'warehouseMenu')"><i class="bi bi-shop"></i><span class="nav-text">Warehouse</span><i class="bi bi-chevron-down dropdown-arrow"></i></a><div class="collapse" id="warehouseMenu"><ul class="nav flex-column ps-4"><li><a class="nav-link" href="current_inventory.php"><i class="bi bi-bar-chart-line"></i><span class="nav-text">Current Inventory</span></a></li><li><a class="nav-link" href="bad_orders.php"><i class="bi bi-recycle"></i><span class="nav-text">Bad Orders</span></a></li><li><a class="nav-link" href="pick_list_items.php"><i class="bi bi-list-check"></i><span class="nav-text">Pick List Items</span></a></li><li><a class="nav-link" href="warehouses.php"><i class="bi bi-shop"></i><span class="nav-text">Warehouses</span></a></li></ul></div></li>
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
    <!-- Customer Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Customer</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>

                        <div class="collapse" id="customerMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="customer_list.php">
                                        <i class="bi bi-person-badge"></i>
                                        <span class="nav-text">Customer List</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="approve_credit_requests.php">
                                        <i class="bi bi-pencil-square"></i>
                                        <span class="nav-text">Approve Credit Request</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="sales_order.php">
                                        <i class="bi bi-cart"></i>
                                        <span class="nav-text">Sales Order</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="collections.php">
                                        <i class="bi bi-cash-stack"></i>
                                        <span class="nav-text">Collections</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
    <li class="nav-item dropdown-nav"><a class="nav-link" href="#" onclick="toggleSidebarDropdown(event,'deliveryMenu')"><i class="bi bi-truck"></i><span class="nav-text">Delivery</span><i class="bi bi-chevron-down dropdown-arrow"></i></a><div class="collapse" id="deliveryMenu"><ul class="nav flex-column ps-4"><li><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span class="nav-text">Trip Tickets</span></a></li></ul></div></li>
    <li class="nav-item dropdown-nav"><a class="nav-link" href="#" onclick="toggleSidebarDropdown(event,'bankingMenu')"><i class="bi bi-bank2"></i><span class="nav-text">Banking</span><i class="bi bi-chevron-down dropdown-arrow"></i></a><div class="collapse" id="bankingMenu"><ul class="nav flex-column ps-4"><li><a class="nav-link active" href="deposit.php"><i class="bi bi-arrow-down-circle"></i><span class="nav-text">Deposit</span></a></li><li><a class="nav-link" href="Withdrawal.php"><i class="bi bi-arrow-up-circle"></i><span class="nav-text">Withdrawal</span></a></li><li><a class="nav-link" href="bank_statement.php"><i class="bi bi-receipt"></i><span class="nav-text">Banks</span></a></li><li><a class="nav-link" href="expenses.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Expenses</span></a></li></ul></div></li>
    
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
    
    <li><a class="nav-link" href="drivers.php"><i class="bi bi-people-fill"></i><span class="nav-text">Users</span></a></li>
    
    
</ul></div></div>
<div class="sidebar-footer"><div class="user-profile-sidebar"><div class="user-avatar-sidebar"><?php echo h($user_initials); ?></div><div class="user-details-sidebar"><span class="user-name-sidebar"><?php echo h($user_name); ?></span><span class="user-role-sidebar"><?php echo h(ucfirst($user_role)); ?></span></div></div><button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button></div></div>
<div class="main-content" id="mainContent"><div id="dashboardContent" class="page-content active">
<div class="navbar-top no-print"><button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button><div class="page-title"><h2><?php echo h($page_title); ?></h2><p><?php echo h($page_subtitle); ?></p></div></div>

<div class="row g-3 mb-3">
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Undeposited Payments</div><div class="stat-value">₱<?php echo number_format($undeposited_payments_total, 2); ?></div><div class="page-note"><?php echo count($available_payments); ?> payment(s)</div></div><div class="stat-icon"><i class="bi bi-wallet2"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Undeposited Credits</div><div class="stat-value">-₱<?php echo number_format($undeposited_credits_total, 2); ?></div><div class="page-note"><?php echo count($available_credits); ?> credit memo(s)</div></div><div class="stat-icon"><i class="bi bi-pencil-square"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Net Undeposited</div><div class="stat-value">₱<?php echo number_format($undeposited_net, 2); ?></div><div class="page-note">Payments - Credits</div></div><div class="stat-icon"><i class="bi bi-calculator"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Bank Balance</div><div class="stat-value">₱<?php echo number_format($bank_balance, 2); ?></div><div class="page-note">From deposits only</div></div><div class="stat-icon"><i class="bi bi-bank"></i></div></div></div>
</div>

<!-- Deposit Form (collapsible) -->
<div class="section-card">
    <div class="section-header">
        <div><h5 class="mb-1">Create Bank Deposit</h5><div class="page-note">Fill in deposit details and select payments / credit memos below</div></div>
        <button type="button" class="btn toggle-form-btn" onclick="toggleDepositForm()">
            <i class="bi bi-chevron-up" id="toggleFormIcon"></i>
        </button>
    </div>
    <div id="depositFormWrapper" class="deposit-form-wrapper">
        <div class="section-body">
            <form method="POST" action="deposit.php" id="depositForm">
                <input type="hidden" name="action" value="create_deposit">
                <div class="row g-3">
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-bold">Deposit Date</label>
                        <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-bold">Bank Name</label>
                        <select name="bank_id" class="form-select" required>
                            <option value="">Select account</option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?php echo (int)$bank['bank_id']; ?>"><?php echo h((!empty($bank['parent_bank_name']) ? $bank['parent_bank_name'] . ' / ' : '') . $bank['bank_name'] . (!empty($bank['account_number']) ? ' - ' . $bank['account_number'] : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-bold">Reference No.</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="Deposit slip / ref no.">
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-bold">Description</label>
                        <input type="text" name="description" class="form-control" value="Collections deposit">
                    </div>
                    <div class="col-12 text-end">
                        <div class="alert d-inline-flex align-items-center gap-3 mb-0 me-3">
                            <span class="fw-bold">Selected Net Total:</span>
                            <span class="amount-positive fs-5" id="selectedDepositTotal">₱0.00</span>
                        </div>
                        <button type="submit" class="btn btn-amgc-primary px-4" <?php echo (empty($available_payments) && empty($available_credits)) ? 'disabled' : ''; ?>>
                            <i class="bi bi-bank2 me-2"></i> Create Deposit
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tabs for Undeposited Funds, Credit Memos, and Deposit History -->
<div class="section-card">
    <div class="section-header">
        <div><h5 class="mb-1">Banking Transactions</h5></div>
    </div>
    <div class="section-body">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#undepositedTab" type="button">Undeposited Payments</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#creditTab" type="button">Credit Memos</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#historyTab" type="button">Deposit History</button></li>
        </ul>
        <div class="tab-content">
            <!-- Undeposited Payments Tab -->
            <div class="tab-pane fade show active" id="undepositedTab">
                <div class="undeposited-header">
                    <h6 class="mb-0">Undeposited Payments</h6>
                    <span class="badge rounded-pill badge-soft-green px-3 py-2">₱<?php echo number_format($undeposited_payments_total, 2); ?></span>
                </div>
                <div class="filter-bar">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <label class="form-label small fw-bold mb-1">Collected By</label>
                            <select id="filterCollectedBy" class="form-select form-select-sm">
                                <option value="">All Collectors</option>
                                <?php foreach ($distinct_collectors as $collector): ?>
                                    <option value="<?php echo h($collector); ?>"><?php echo h($collector); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-bold mb-1">Payment Method</label>
                            <select id="filterMethod" class="form-select form-select-sm">
                                <option value="">All Methods</option>
                                <?php foreach ($distinct_methods as $method): ?>
                                    <option value="<?php echo h($method); ?>"><?php echo ucwords(str_replace('_', ' ', h($method))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-bold mb-1">Date Range</label>
                            <div class="d-flex gap-2">
                                <input type="date" id="filterDateFrom" class="form-control form-control-sm">
                                <input type="date" id="filterDateTo" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-nowrap" id="undepositedTable">
                        <thead><tr><th style="width:40px"><input type="checkbox" id="selectAllCheckbox"></th><th>Customer / Invoice</th><th>Amount</th><th>Payment Date</th><th>Collected By</th><th>Method</th><th>Reference</th></tr></thead>
                        <tbody>
                            <?php if (empty($available_payments)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No undeposited payments available.<?php echo h($payment['collected_by_name'] ?? ''); ?></td></tr>
                            <?php else: foreach ($available_payments as $payment): 
                                $payment_date = date('Y-m-d', strtotime($payment['payment_date']));
                                $collected_by_trimmed = trim($payment['collected_by_name'] ?? '');
                                $method_trimmed = trim($payment['payment_method']);
                            ?>
                            <tr class="clickable-row" data-payment-id="<?php echo (int)$payment['payment_id']; ?>" 
                                data-collected-by="<?php echo h($collected_by_trimmed); ?>"
                                data-payment-method="<?php echo h($method_trimmed); ?>"
                                data-payment-date="<?php echo $payment_date; ?>"
                                data-amount="<?php echo (float)$payment['amount']; ?>"
                                data-type="payment">
                                <td><input type="checkbox" class="form-check-input deposit-item-checkbox" data-type="payment" value="<?php echo (int)$payment['payment_id']; ?>" data-amount="<?php echo (float)$payment['amount']; ?>" onclick="event.stopPropagation()"></td>
                                <td><div class="fw-semibold"><?php echo h($payment['customer_name'] ?: 'Unknown Customer'); ?></div><small class="text-muted"><?php echo h($payment['invoice_number'] ?: 'No Invoice'); ?><?php echo !empty($payment['so_number']) ? ' • ' . h($payment['so_number']) : ''; ?></small></td>
                                <td class="amount-positive">₱<?php echo number_format((float)$payment['amount'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo h($collected_by_trimmed !== '' ? $collected_by_trimmed : '-'); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', h($method_trimmed))); ?></td>
                                <td style="max-width:130px;"><?php echo h($payment['reference_number'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Credit Memos Tab -->
            <div class="tab-pane fade" id="creditTab">
                <div class="undeposited-header">
                    <h6 class="mb-0">Undeposited Credit Memos (Negative Amounts)</h6>
                    <div>
                        <button type="button" class="btn btn-sm btn-amgc-primary me-2" data-bs-toggle="modal" data-bs-target="#createCreditMemoModal">
                            <i class="bi bi-plus-circle"></i> New Credit Memo
                        </button>
                        <span class="badge rounded-pill badge-soft-red px-3 py-2">-₱<?php echo number_format($undeposited_credits_total, 2); ?></span>
                    </div>
                </div>
                <div class="filter-bar">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold mb-1">Customer</label>
                            <select id="filterCreditCustomer" class="form-select form-select-sm">
                                <option value="">All Customers</option>
                                <?php foreach ($distinct_credit_customers as $cust): ?>
                                    <option value="<?php echo h($cust); ?>"><?php echo h($cust); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold mb-1">Date Range</label>
                            <div class="d-flex gap-2">
                                <input type="date" id="creditDateFrom" class="form-control form-control-sm">
                                <input type="date" id="creditDateTo" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-nowrap" id="creditMemoTable">
                        <thead><tr><th style="width:40px"><input type="checkbox" id="selectAllCreditsCheckbox"></th><th>Customer</th><th>Amount</th><th>Credit Date</th><th>Reference</th><th>Description</th><th>Created By</th></tr></thead>
                        <tbody>
                            <?php if (empty($available_credits)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No undeposited credit memos available. Click "New Credit Memo" to create one.<?php echo h($payment['collected_by_name'] ?? ''); ?></td></tr>
                            <?php else: foreach ($available_credits as $credit): 
                                $credit_date = date('Y-m-d', strtotime($credit['credit_date']));
                            ?>
                            <tr class="clickable-row credit-memo-clickable-row" onclick="handleCreditMemoRowClick(event, this)" data-credit-id="<?php echo (int)$credit['credit_memo_id']; ?>"
                                data-customer-name="<?php echo h($credit['customer_name'] ?? ''); ?>"
                                data-credit-date="<?php echo $credit_date; ?>"
                                data-amount="<?php echo (float)$credit['amount']; ?>"
                                data-type="credit">
                                <td><input type="checkbox" class="form-check-input deposit-item-checkbox" data-type="credit" value="<?php echo (int)$credit['credit_memo_id']; ?>" data-amount="<?php echo (float)$credit['amount']; ?>" onclick="event.stopPropagation()"></td>
                                <td><?php echo h($credit['customer_name'] ?? 'Unknown Customer'); ?></td>
                                <td class="amount-negative">-₱<?php echo number_format((float)$credit['amount'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($credit['credit_date'])); ?></td>
                                <td><?php echo h($credit['reference_number'] ?: '-'); ?></td>
                                <td><?php echo h($credit['description'] ?: '-'); ?></td>
                                <td><?php echo h($credit['created_by_name'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Deposit History Tab -->
            <div class="tab-pane fade" id="historyTab">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-nowrap">
                        <thead><tr><th>Date</th><th>Reference</th><th>Bank</th><th>Description</th><th>Amount</th></tr></thead>
                        <tbody>
                            <?php if (empty($bank_transactions)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No deposit history yet.<?php echo h($payment['collected_by_name'] ?? ''); ?></td></tr>
                            <?php else: foreach ($bank_transactions as $tx): 
                                $payment_list = [];
                                if (!empty($tx['payment_links'])) {
                                    $raw_links = explode(';;', $tx['payment_links']);
                                    foreach ($raw_links as $link) {
                                        $parts = explode('||', $link);
                                        if (count($parts) >= 4 && $parts[0] === 'payment') {
                                            $payment_list[] = ['type'=>'payment','customer'=>$parts[1],'invoice'=>$parts[2],'amount'=>$parts[3]];
                                        }
                                    }
                                }
                                if (!empty($tx['credit_links'])) {
                                    $raw_credits = explode(';;', $tx['credit_links']);
                                    foreach ($raw_credits as $link) {
                                        $parts = explode('||', $link);
                                        if (count($parts) >= 4 && $parts[0] === 'credit') {
                                            $payment_list[] = ['type'=>'credit','customer'=>$parts[1],'ref'=>$parts[2],'amount'=>$parts[3]];
                                        }
                                    }
                                }
                            ?>
                                <tr class="deposit-row-clickable" data-transaction='<?php echo htmlspecialchars(json_encode([
                                    'date' => date('M d, Y', strtotime($tx['transaction_date'])),
                                    'reference' => $tx['reference_number'] ?: '-',
                                    'bank' => $tx['bank_name'] ?: '-',
                                    'description' => $tx['description'] ?: '-',
                                    'amount' => number_format((float)$tx['amount'], 2),
                                    'items' => $payment_list,
                                    'encoded_by' => trim($tx['created_by_name']) !== '' ? $tx['created_by_name'] : 'Unknown User'
                                ]), ENT_QUOTES, 'UTF-8'); ?>'>
                                    <td><?php echo date('M d, Y', strtotime($tx['transaction_date'])); ?></td>
                                    <td><?php echo h($tx['reference_number'] ?: '-'); ?></td>
                                    <td><?php echo h($tx['bank_name'] ?: '-'); ?></td>
                                    <td><?php echo h($tx['description'] ?: '-'); ?></td>
                                    <td class="amount-positive">₱<?php echo number_format((float)$tx['amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div></div></div>

<!-- Modal for Create Credit Memo -->
<div class="modal fade" id="createCreditMemoModal" tabindex="-1" aria-labelledby="createCreditMemoModalLabel" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:20px; border:none;">
<form method="POST" action="deposit.php" enctype="multipart/form-data">
<input type="hidden" name="action" value="create_credit_memo">
<div class="modal-header" style="background:linear-gradient(135deg,#047857 0%,#44D34E 100%); color:white; border-radius:20px 20px 0 0;">
<h5 class="modal-title" id="createCreditMemoModalLabel"><i class="bi bi-pencil-square me-2"></i>Create Credit Memo</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-4">
    <div class="mb-3">
        <label class="form-label fw-bold">Customer *</label>
        <select name="customer_id" class="form-select" required>
            <option value="">Select Customer</option>
            <?php foreach ($customers as $cust): ?>
                <option value="<?php echo (int)$cust['customer_id']; ?>"><?php echo h($cust['customer_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Amount *</label>
        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
        <small class="text-muted">Positive amount, will be deducted from deposit</small>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Credit Date</label>
        <input type="date" name="credit_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Reference Number</label>
        <input type="text" name="reference_number" class="form-control" placeholder="Optional">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Description</label>
        <textarea name="description" class="form-control" rows="2" placeholder="Reason for credit memo"></textarea>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Attachments</label>
        <div id="creditMemoAttachmentsContainer" class="d-flex flex-column gap-2">
            <div class="input-group creditmemo-attachment-row">
                <input type="file"
                       name="attachments[]"
                       class="form-control creditmemo-attachment-input"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                <button type="button"
                        class="btn btn-outline-danger remove-creditmemo-attachment"
                        style="display:none;">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <button type="button" class="btn btn-outline-success btn-sm mt-2" id="addCreditMemoAttachmentBtn">
            <i class="bi bi-plus-circle me-1"></i>Add Attachment
        </button>
        <small class="text-muted d-block mt-1">Allowed: images, PDF, Word, Excel, CSV, TXT. Max 10MB each file.</small>
    </div>
</div>
<div class="modal-footer border-0">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-amgc-primary">Save Credit Memo</button>
</div>
</form>
</div>
</div>
</div>

<!-- Modal for Deposit Details (updated to show both payments and credit memos) -->
<div class="modal fade" id="depositDetailsModal" tabindex="-1" aria-labelledby="depositDetailsModalLabel" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg">
<div class="modal-content" style="border-radius:20px; border:none;">
<div class="modal-header" style="background:linear-gradient(135deg,#047857 0%,#44D34E 100%); color:white; border-radius:20px 20px 0 0;">
<h5 class="modal-title" id="depositDetailsModalLabel"><i class="bi bi-bank2 me-2"></i>Deposit Details</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-4">
    <div class="row mb-3">
        <div class="col-md-6"><strong>Date:</strong> <span id="modalDate"></span></div>
        <div class="col-md-6"><strong>Reference No.:</strong> <span id="modalReference"></span></div>
        <div class="col-md-6"><strong>Bank:</strong> <span id="modalBank"></span></div>
        <div class="col-md-6"><strong>Net Amount:</strong> <span id="modalAmount" class="amount-positive"></span></div>
        <div class="col-12 mt-2"><strong>Description:</strong> <span id="modalDescription"></span></div>
    </div>
    <hr>
    <h6 class="fw-bold">Items Included</h6>
    <div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead class="table-light"><tr><th>Type</th><th>Customer</th><th>Invoice/Reference</th><th>Amount</th></tr></thead>
            <tbody id="modalItemsList"></tbody>
        </table>
    </div>
    <hr>
    <div><strong>Encoded By:</strong> <span id="modalEncodedBy"></span></div>
</div>
<div class="modal-footer border-0"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div>
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


<!-- Modal for Payment Details -->
<div class="modal fade" id="paymentDetailsModal" tabindex="-1" aria-labelledby="paymentDetailsModalLabel" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:20px; border:none;">
<div class="modal-header" style="background:linear-gradient(135deg,#047857 0%,#44D34E 100%); color:white; border-radius:20px 20px 0 0;">
<h5 class="modal-title" id="paymentDetailsModalLabel"><i class="bi bi-receipt me-2"></i>Payment Details</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-4"><div id="modalPaymentContent">Loading...</div></div>
<div class="modal-footer border-0"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const undepositedPayments = <?php echo json_encode($available_payments); ?>;
const undepositedCredits = <?php echo json_encode($available_credits); ?>;
const flashSuccessMessage = <?php echo json_encode($flash_success); ?>;
const flashErrorMessage = <?php echo json_encode($flash_error); ?>;

const amgcSwalDefaults = {
    confirmButtonColor: '#047857',
    cancelButtonColor: '#6c757d',
    buttonsStyling: true,
    customClass: {
        popup: 'amgc-swal-popup',
        title: 'amgc-swal-title',
        confirmButton: 'amgc-swal-confirm',
        cancelButton: 'amgc-swal-cancel'
    }
};

function amgcSwalFire(options) {
    return Swal.fire(Object.assign({}, amgcSwalDefaults, options || {}));
}


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


function formatDateOnly(value) {
    if (!value) return '-';
    const raw = String(value).trim();
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (match) {
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        if (!isNaN(date.getTime())) {
            return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });
        }
    }
    const fallback = new Date(raw.replace(' ', 'T'));
    return isNaN(fallback.getTime()) ? raw : fallback.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });
}

function formatDateTime(value) {
    if (!value) return '-';
    const date = new Date(String(value).replace(' ', 'T'));
    return isNaN(date.getTime()) ? String(value) : date.toLocaleString('en-PH');
}

function showSuccessMessage(message, title = 'Success') {
    if (!message) return Promise.resolve();
    return amgcSwalFire({
        icon: 'success',
        title: title,
        text: message,
        confirmButtonText: 'OK'
    });
}

function showErrorMessage(message, title = 'Error') {
    if (!message) return Promise.resolve();
    return amgcSwalFire({
        icon: 'error',
        title: title,
        text: message,
        confirmButtonText: 'OK'
    });
}

function showWarningMessage(message, title = 'Warning') {
    if (!message) return Promise.resolve();
    return amgcSwalFire({
        icon: 'warning',
        title: title,
        text: message,
        confirmButtonText: 'OK'
    });
}

function showFlashMessages() {
    if (flashSuccessMessage) {
        showSuccessMessage(flashSuccessMessage);
        return;
    }

    if (flashErrorMessage) {
        showErrorMessage(flashErrorMessage);
    }
}


function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();
    
    // Remove active class from all nav links
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Find and highlight the active link
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'branchdashboard.php')) {
            link.classList.add('active');
            
            // Find parent dropdown and expand it
            const collapseDiv = link.closest('.collapse');
            if (collapseDiv) {
                collapseDiv.classList.add('show');
                // Find the parent button and rotate its arrow
                const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                if (parentBtn) {
                    const arrow = parentBtn.querySelector('.dropdown-arrow');
                    if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }
            }
        }
    });
}

function expandActiveDropdownContainers() {
    const sidebarEl = document.getElementById('sidebar');
    if (!sidebarEl || sidebarEl.classList.contains('collapsed')) return;
    
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

function toggleSidebar(){const s=document.getElementById('sidebar');if(window.innerWidth<=992){s.classList.toggle('active')}else{s.classList.toggle('collapsed');localStorage.setItem('sidebarCollapsed',s.classList.contains('collapsed'))}}

function toggleSidebarDropdown(event, targetId) {
    event.preventDefault();
    event.stopPropagation();
    
    const target = document.getElementById(targetId);
    const btn = event.currentTarget;
    const arrow = btn.querySelector('.dropdown-arrow');
    const sidebar = document.getElementById('sidebar');
    
    // If sidebar is collapsed, expand it first then open dropdown
    if (sidebar && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
        
        setTimeout(() => {
            // Close all other dropdowns
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
            
            if (target) target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }, 50);
        return;
    }
    
    // Normal behavior - close others when opening one
    if (target && target.classList.contains('show')) {
        target.classList.remove('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
    } else {
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
        
        if (target) target.classList.add('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
    }
}

function logout(){amgcSwalFire({title:'Are you sure?',text:'You will be logged out of the system',icon:'question',showCancelButton:true,confirmButtonText:'Yes, logout'}).then((r)=>{if(r.isConfirmed)window.location.href='../logout.php';})}

function updateDepositTotal(){
    let total = 0;
    document.querySelectorAll('.deposit-item-checkbox:checked').forEach(cb => {
        let amount = parseFloat(cb.dataset.amount || '0');
        if (cb.dataset.type === 'credit') amount = -amount;
        total += amount;
    });
    const el = document.getElementById('selectedDepositTotal');
    if(el) el.textContent = '₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function applyPaymentFilters(){
    const collectedBy = (document.getElementById('filterCollectedBy').value || '').trim().toLowerCase();
    const method = (document.getElementById('filterMethod').value || '').trim().toLowerCase();
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const rows = document.querySelectorAll('#undepositedTable tbody tr');
    rows.forEach(row=>{
        if(row.getAttribute('data-payment-id')===null) return;
        const rowCollected = (row.getAttribute('data-collected-by')||'').trim().toLowerCase();
        const rowMethod = (row.getAttribute('data-payment-method')||'').trim().toLowerCase();
        const rowDate = row.getAttribute('data-payment-date')||'';
        let show=true;
        if(collectedBy && rowCollected!==collectedBy) show=false;
        if(method && rowMethod!==method) show=false;
        if(dateFrom && rowDate<dateFrom) show=false;
        if(dateTo && rowDate>dateTo) show=false;
        if(show){row.style.display='';}else{const cb=row.querySelector('.deposit-item-checkbox'); if(cb&&cb.checked)cb.checked=false; row.style.display='none';}
    });
    updateDepositTotal();
    updateSelectAllCheckbox();
}

function applyCreditFilters(){
    const customer = (document.getElementById('filterCreditCustomer').value || '').trim().toLowerCase();
    const dateFrom = document.getElementById('creditDateFrom').value;
    const dateTo = document.getElementById('creditDateTo').value;
    const rows = document.querySelectorAll('#creditMemoTable tbody tr');
    rows.forEach(row=>{
        if(row.getAttribute('data-credit-id')===null) return;
        const rowCustomer = (row.getAttribute('data-customer-name')||'').trim().toLowerCase();
        const rowDate = row.getAttribute('data-credit-date')||'';
        let show=true;
        if(customer && rowCustomer!==customer) show=false;
        if(dateFrom && rowDate<dateFrom) show=false;
        if(dateTo && rowDate>dateTo) show=false;
        if(show){row.style.display='';}else{const cb=row.querySelector('.deposit-item-checkbox'); if(cb&&cb.checked)cb.checked=false; row.style.display='none';}
    });
    updateDepositTotal();
    updateSelectAllCreditsCheckbox();
}

function updateSelectAllCheckbox(){
    const selectAll=document.getElementById('selectAllCheckbox');
    if(!selectAll) return;
    const visibleCheckboxes=Array.from(document.querySelectorAll('#undepositedTable tbody tr:not([style*="display: none"]) .deposit-item-checkbox')).filter(cb=>cb.offsetParent!==null);
    const checkedVisible=visibleCheckboxes.filter(cb=>cb.checked);
    if(visibleCheckboxes.length===0){selectAll.checked=false;selectAll.indeterminate=false;}
    else if(checkedVisible.length===visibleCheckboxes.length){selectAll.checked=true;selectAll.indeterminate=false;}
    else if(checkedVisible.length>0){selectAll.checked=false;selectAll.indeterminate=true;}
    else{selectAll.checked=false;selectAll.indeterminate=false;}
}

function updateSelectAllCreditsCheckbox(){
    const selectAll=document.getElementById('selectAllCreditsCheckbox');
    if(!selectAll) return;
    const visibleCheckboxes=Array.from(document.querySelectorAll('#creditMemoTable tbody tr:not([style*="display: none"]) .deposit-item-checkbox')).filter(cb=>cb.offsetParent!==null);
    const checkedVisible=visibleCheckboxes.filter(cb=>cb.checked);
    if(visibleCheckboxes.length===0){selectAll.checked=false;selectAll.indeterminate=false;}
    else if(checkedVisible.length===visibleCheckboxes.length){selectAll.checked=true;selectAll.indeterminate=false;}
    else if(checkedVisible.length>0){selectAll.checked=false;selectAll.indeterminate=true;}
    else{selectAll.checked=false;selectAll.indeterminate=false;}
}

function selectAllVisible(){
    const s=document.getElementById('selectAllCheckbox');
    const isChecked=s.checked;
    document.querySelectorAll('#undepositedTable tbody tr:not([style*="display: none"]) .deposit-item-checkbox').forEach(cb=>cb.checked=isChecked);
    updateDepositTotal();
}

function selectAllVisibleCredits(){
    const s=document.getElementById('selectAllCreditsCheckbox');
    const isChecked=s.checked;
    document.querySelectorAll('#creditMemoTable tbody tr:not([style*="display: none"]) .deposit-item-checkbox').forEach(cb=>cb.checked=isChecked);
    updateDepositTotal();
}

function showPaymentDetails(paymentId){
    const p=undepositedPayments.find(p=>p.payment_id==paymentId);
    const modalBody=document.getElementById('modalPaymentContent');
    if(p){
        modalBody.innerHTML=`
            <div class="row mb-2"><div class="col-5 text-muted">Customer Name:</div><div class="col-7 fw-semibold">${escapeHtml(p.customer_name||'Unknown Customer')}</div>
            <div class="col-5 text-muted">Invoice Number:</div><div class="col-7">${escapeHtml(p.invoice_number||'No Invoice')}</div>
            <div class="col-5 text-muted">SO Number:</div><div class="col-7">${escapeHtml(p.so_number||'-')}</div>
            <div class="col-5 text-muted">Amount:</div><div class="col-7 amount-positive">₱${parseFloat(p.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
            <div class="col-5 text-muted">Payment Date:</div><div class="col-7">${new Date(p.payment_date).toLocaleString()}</div>
            <div class="col-5 text-muted">Payment Method:</div><div class="col-7">${escapeHtml(p.payment_method?p.payment_method.replace(/_/g,' '):'-')}</div>
            <div class="col-5 text-muted">Reference Number:</div><div class="col-7">${escapeHtml(p.reference_number||'-')}</div>
            <div class="col-5 text-muted">Bank Name:</div><div class="col-7">${escapeHtml(p.bank_name||'-')}</div>
            <div class="col-5 text-muted">Collected By:</div><div class="col-7">${escapeHtml(p.collected_by_name||'Unknown User')}</div></div>
        `;
    } else { modalBody.innerHTML='<div class="alert alert-danger">Payment details not found.</div>'; }
    new bootstrap.Modal(document.getElementById('paymentDetailsModal')).show();
}


function formatFileSize(bytes) {
    bytes = parseInt(bytes || 0, 10);
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function escapeAttr(str) {
    return escapeHtml(String(str || '')).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function handleCreditMemoRowClick(event, row) {
    const target = event.target;
    if (target.closest('input, button, a, label, select, textarea')) {
        return;
    }
    const creditId = row.getAttribute('data-credit-id');
    if (creditId) {
        showCreditMemoDetails(creditId, row);
    }
}

function showCreditMemoDetails(creditMemoId, fallbackRow = null) {
    let cm = undepositedCredits.find(c => parseInt(c.credit_memo_id) === parseInt(creditMemoId));

    if (!cm && fallbackRow) {
        const cells = fallbackRow.querySelectorAll('td');
        cm = {
            credit_memo_id: creditMemoId,
            customer_name: cells[1] ? cells[1].innerText.trim() : 'Unknown Customer',
            amount: (fallbackRow.getAttribute('data-amount') || '').replace(/[^0-9.]/g, ''),
            credit_date: fallbackRow.getAttribute('data-credit-date') || (cells[3] ? cells[3].innerText.trim() : ''),
            reference_number: cells[4] ? cells[4].innerText.trim() : '-',
            description: cells[5] ? cells[5].innerText.trim() : '-',
            created_by_name: cells[6] ? cells[6].innerText.trim() : '-',
            status: 'unapplied',
            attachments: []
        };
    }

    if (!cm) {
        showErrorMessage('Credit memo details not found.', 'Credit Memo');
        return;
    }

    const amount = parseFloat(cm.amount || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const creditDate = formatDateOnly(cm.credit_date);
    const createdAt = formatDateTime(cm.created_at);
    const attachments = Array.isArray(cm.attachments) ? cm.attachments : [];

    let attachmentHtml = '<div class="text-muted">No attachments uploaded.</div>';
    if (attachments.length > 0) {
        attachmentHtml = '<div class="list-group list-group-flush border rounded">' + attachments.map((file, index) => {
            const fileName = escapeHtml(file.file_name || ('Attachment ' + (index + 1)));
            const fileSize = formatFileSize(file.file_size);
            const payload = escapeAttr(JSON.stringify({
                file_name: file.file_name || ('Attachment ' + (index + 1)),
                file_path: file.file_path || '',
                file_type: file.file_type || '',
                file_size: file.file_size || 0
            }));
            return `
                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-3 py-2 creditmemo-attachment-open" data-file="${payload}" onclick="showCreditMemoAttachmentPreview(this)">
                    <span><i class="bi bi-paperclip me-2 text-success"></i>${fileName}</span>
                    <small class="text-muted">${escapeHtml(fileSize)}</small>
                </button>
            `;
        }).join('') + '</div>';
    }

    amgcSwalFire({
        title: 'Credit Memo Details',
        width: 720,
        html: `
            <div class="text-start">
                <div class="row g-2 mb-3">
                    <div class="col-sm-5 text-muted">Credit Memo No.:</div>
                    <div class="col-sm-7 fw-semibold">CM-${escapeHtml(cm.credit_memo_id)}</div>

                    <div class="col-sm-5 text-muted">Customer:</div>
                    <div class="col-sm-7 fw-semibold">${escapeHtml(cm.customer_name || 'Unknown Customer')}</div>

                    <div class="col-sm-5 text-muted">Amount:</div>
                    <div class="col-sm-7 amount-negative fw-semibold">-₱${amount}</div>

                    <div class="col-sm-5 text-muted">Credit Date:</div>
                    <div class="col-sm-7">${escapeHtml(creditDate)}</div>

                    <div class="col-sm-5 text-muted">Reference Number:</div>
                    <div class="col-sm-7">${escapeHtml(cm.reference_number || '-')}</div>

                    <div class="col-sm-5 text-muted">Status:</div>
                    <div class="col-sm-7"><span class="badge bg-warning text-dark">${escapeHtml(cm.status || 'unapplied')}</span></div>

                    <div class="col-sm-5 text-muted">Created By:</div>
                    <div class="col-sm-7">${escapeHtml(cm.created_by_name || '-')}</div>

                    <div class="col-sm-5 text-muted">Created At:</div>
                    <div class="col-sm-7">${escapeHtml(createdAt)}</div>

                    <div class="col-sm-5 text-muted">Description:</div>
                    <div class="col-sm-7" style="white-space:normal; word-break:break-word;">${escapeHtml(cm.description || '-')}</div>
                </div>

                <div class="fw-bold mb-2"><i class="bi bi-paperclip me-1"></i>Attachments</div>
                ${attachmentHtml}
            </div>
        `
    });
}


function showCreditMemoAttachmentPreview(button) {
    let file = {};
    try {
        file = JSON.parse(button.getAttribute('data-file') || '{}');
    } catch (e) {
        file = {};
    }

    const fileName = file.file_name || 'Attachment';
    const filePath = file.file_path || '';
    const fileType = String(file.file_type || '').toLowerCase();
    const ext = fileName.split('.').pop().toLowerCase();

    if (!filePath) {
        showErrorMessage('Attachment file path not found.', 'Attachment');
        return;
    }

    let previewHtml = '';
    if (fileType.startsWith('image/') || ['jpg','jpeg','png','gif','webp'].includes(ext)) {
        previewHtml = `<div class="text-center"><img src="${escapeAttr(filePath)}" alt="${escapeAttr(fileName)}" class="creditmemo-preview-image"></div>`;
    } else if (fileType === 'application/pdf' || ext === 'pdf') {
        previewHtml = `<iframe src="${escapeAttr(filePath)}" class="creditmemo-preview-frame"></iframe>`;
    } else {
        previewHtml = `
            <div class="text-center p-4">
                <i class="bi bi-file-earmark-text text-success" style="font-size:4rem;"></i>
                <div class="fw-bold mt-3 mb-2">${escapeHtml(fileName)}</div>
                <div class="text-muted mb-3">Preview is not available for this file type.</div>
                <a href="${escapeAttr(filePath)}" download class="btn btn-amgc-primary">
                    <i class="bi bi-download me-1"></i> Download Attachment
                </a>
            </div>
        `;
    }

    amgcSwalFire({
        title: escapeHtml(fileName),
        width: 900,
        html: previewHtml,
        confirmButtonText: 'Close'
    });
}

function showDepositDetails(transactionJson) {
    const data = JSON.parse(transactionJson);
    document.getElementById('modalDate').innerText = data.date;
    document.getElementById('modalReference').innerText = data.reference;
    document.getElementById('modalBank').innerText = data.bank;
    document.getElementById('modalDescription').innerText = data.description;
    document.getElementById('modalAmount').innerHTML = '₱' + data.amount;
    document.getElementById('modalEncodedBy').innerText = data.encoded_by;
    const tbody = document.getElementById('modalItemsList');
    tbody.innerHTML = '';
    if (data.items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center">No items available</td></tr>';
    } else {
        data.items.forEach(item => {
            const tr = document.createElement('tr');
            const typeLabel = item.type === 'payment' ? 'Payment' : 'Credit Memo';
            const refOrInvoice = item.type === 'payment' ? (item.invoice || '-') : (item.ref || '-');
            const amountClass = item.type === 'payment' ? 'amount-positive' : 'amount-negative';
            const amountPrefix = item.type === 'payment' ? '₱' : '-₱';
            tr.innerHTML = `<td>${typeLabel}</td><td>${escapeHtml(item.customer)}</td><td>${escapeHtml(refOrInvoice)}</td><td class="${amountClass}">${amountPrefix}${item.amount}</td>`;
            tbody.appendChild(tr);
        });
    }
    new bootstrap.Modal(document.getElementById('depositDetailsModal')).show();
}

function escapeHtml(str){if(str===null||str===undefined)return '';return String(str).replace(/[&<>]/g,function(m){if(m==='&')return '&amp;';if(m==='<')return '&lt;';if(m==='>')return '&gt;';return m;});}

document.addEventListener('DOMContentLoaded',function(){
    showFlashMessages();

    // Restore sidebar state
    if(localStorage.getItem('sidebarCollapsed')==='true'&&window.innerWidth>992) {
        document.getElementById('sidebar')?.classList.add('collapsed');
    }
    
    // Set active sidebar item based on current page
    setActiveSidebarItem();
    
    // Expand any dropdown that contains an active link
    const sidebar = document.getElementById('sidebar');
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        expandActiveDropdownContainers();
    }
    
    // Scroll to active sidebar item so it's visible without manual scrolling
    setTimeout(() => {
        scrollToActiveSidebarItem();
    }, 150);
    
    // Prevent dropdown from closing when clicking inside
    document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
        collapse.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
            !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
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
        }
    });
    
    document.getElementById('mobileMenuBtn')?.addEventListener('click', toggleSidebar);
    document.getElementById('desktopToggleBtn')?.addEventListener('click', toggleSidebar);
    
    document.querySelectorAll('#historyTab .deposit-row-clickable').forEach(row => {
        row.addEventListener('click', function() {
            const transactionData = this.getAttribute('data-transaction');
            if (transactionData) showDepositDetails(transactionData);
        });
    });
    
    document.querySelectorAll('#undepositedTable tbody tr.clickable-row').forEach(row=>{
        row.addEventListener('click',function(e){
            if(e.target.type!=='checkbox' && !e.target.classList.contains('form-check-input')){
                const pid=parseInt(this.getAttribute('data-payment-id'));
                if(pid) showPaymentDetails(pid);
            }
        });
    });
    
    document.querySelectorAll('#creditMemoTable tbody tr.clickable-row').forEach(row=>{
        row.addEventListener('click',function(e){
            handleCreditMemoRowClick(e, this);
        });
    });
    
    document.querySelectorAll('.deposit-item-checkbox').forEach(cb=>cb.addEventListener('change',function(){updateDepositTotal(); updateSelectAllCheckbox(); updateSelectAllCreditsCheckbox();}));
    updateDepositTotal();
    
    const depositForm = document.getElementById('depositForm');
    if(depositForm) {
        depositForm.removeEventListener('submit', handleDepositSubmit);
        depositForm.addEventListener('submit', handleDepositSubmit);
    }
    
    function handleDepositSubmit(e) {
        const checkedPayments = document.querySelectorAll('#undepositedTable .deposit-item-checkbox:checked');
        const checkedCredits = document.querySelectorAll('#creditMemoTable .deposit-item-checkbox:checked');
        if(checkedPayments.length === 0 && checkedCredits.length === 0) {
            e.preventDefault();
            showWarningMessage('Please select at least one payment or credit memo.', 'No item selected');
            return false;
        }
        const existingHidden = depositForm.querySelectorAll('input[name="payment_ids[]"], input[name="credit_memo_ids[]"]');
        existingHidden.forEach(h => h.remove());
        checkedPayments.forEach(cb => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'payment_ids[]';
            hidden.value = cb.value;
            depositForm.appendChild(hidden);
        });
        checkedCredits.forEach(cb => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'credit_memo_ids[]';
            hidden.value = cb.value;
            depositForm.appendChild(hidden);
        });
        return true;
    }
    

    const addCreditMemoAttachmentBtn = document.getElementById('addCreditMemoAttachmentBtn');
    const creditMemoAttachmentsContainer = document.getElementById('creditMemoAttachmentsContainer');

    function refreshCreditMemoAttachmentRemoveButtons() {
        const rows = document.querySelectorAll('.creditmemo-attachment-row');
        rows.forEach((row, index) => {
            const removeBtn = row.querySelector('.remove-creditmemo-attachment');
            if (removeBtn) {
                removeBtn.style.display = rows.length > 1 ? '' : 'none';
            }
        });
    }

    if (addCreditMemoAttachmentBtn && creditMemoAttachmentsContainer) {
        addCreditMemoAttachmentBtn.addEventListener('click', function() {
            const row = document.createElement('div');
            row.className = 'input-group creditmemo-attachment-row';
            row.innerHTML = `
                <input type="file"
                       name="attachments[]"
                       class="form-control creditmemo-attachment-input"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                <button type="button" class="btn btn-outline-danger remove-creditmemo-attachment">
                    <i class="bi bi-x-lg"></i>
                </button>
            `;
            creditMemoAttachmentsContainer.appendChild(row);
            refreshCreditMemoAttachmentRemoveButtons();
        });
    }

    document.addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.remove-creditmemo-attachment');
        if (!removeBtn) return;

        const row = removeBtn.closest('.creditmemo-attachment-row');
        const rows = document.querySelectorAll('.creditmemo-attachment-row');

        if (row && rows.length > 1) {
            row.remove();
            refreshCreditMemoAttachmentRemoveButtons();
        }
    });

    document.getElementById('createCreditMemoModal')?.addEventListener('hidden.bs.modal', function() {
        if (!creditMemoAttachmentsContainer) return;
        creditMemoAttachmentsContainer.innerHTML = `
            <div class="input-group creditmemo-attachment-row">
                <input type="file"
                       name="attachments[]"
                       class="form-control creditmemo-attachment-input"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                <button type="button"
                        class="btn btn-outline-danger remove-creditmemo-attachment"
                        style="display:none;">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;
        refreshCreditMemoAttachmentRemoveButtons();
    });

    refreshCreditMemoAttachmentRemoveButtons();

        document.getElementById('filterCollectedBy')?.addEventListener('change',applyPaymentFilters);
    document.getElementById('filterMethod')?.addEventListener('change',applyPaymentFilters);
    document.getElementById('filterDateFrom')?.addEventListener('change',applyPaymentFilters);
    document.getElementById('filterDateTo')?.addEventListener('change',applyPaymentFilters);
    document.getElementById('selectAllCheckbox')?.addEventListener('change',selectAllVisible);
    
    document.getElementById('filterCreditCustomer')?.addEventListener('change',applyCreditFilters);
    document.getElementById('creditDateFrom')?.addEventListener('change',applyCreditFilters);
    document.getElementById('creditDateTo')?.addEventListener('change',applyCreditFilters);
    document.getElementById('selectAllCreditsCheckbox')?.addEventListener('change',selectAllVisibleCredits);
    
    applyPaymentFilters();
    applyCreditFilters();
});
</script>
</body></html>