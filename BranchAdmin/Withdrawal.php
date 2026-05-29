<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!file_exists('../config/database.php')) {
    die('Database configuration file not found. Please ensure ../config/database.php exists.');
}
require_once '../config/database.php';

if (!isset($conn) || !$conn || $conn->connect_error) {
    die('Database connection failed: ' . (isset($conn) ? $conn->connect_error : 'Connection variable not set'));
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim(($_SESSION['first_name'] ?? 'Branch') . ' ' . ($_SESSION['last_name'] ?? 'Admin'));
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = (bool)($_SESSION['view_all_branches'] ?? false);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $table) {
    if (!$conn) return false;
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function columnExists($conn, $table, $column) {
    if (!$conn || !tableExists($conn, $table)) return false;
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
        `status` enum('active','inactive') NOT NULL DEFAULT 'active',
        `parent_bank_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`bank_id`),
        KEY `branch_id` (`branch_id`),
        KEY `status` (`status`),
        KEY `parent_bank_id` (`parent_bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    
    if (columnExists($conn, 'banks', 'payment_method')) {
        $conn->query("ALTER TABLE banks DROP COLUMN payment_method");
    }
}

function createBankPaymentMethodsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_payment_methods` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_id` int(11) NOT NULL,
        `payment_method` enum('check','online_transfer','cash') NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_bank_method` (`bank_id`,`payment_method`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    
    if (columnExists($conn, 'banks', 'payment_method')) {
        $res = $conn->query("SELECT bank_id, payment_method FROM banks WHERE payment_method IS NOT NULL");
        while ($row = $res->fetch_assoc()) {
            $method = $row['payment_method'] === 'petty_cash' ? 'cash' : $row['payment_method'];
            $stmt = $conn->prepare("INSERT IGNORE INTO bank_payment_methods (bank_id, payment_method) VALUES (?, ?)");
            $stmt->bind_param('is', $row['bank_id'], $method);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function getBankPaymentMethods($conn, $bank_id) {
    createBankPaymentMethodsTable($conn);
    $bank_id = (int)$bank_id;
    $methods = [];

    $stmt = $conn->prepare("SELECT payment_method FROM bank_payment_methods WHERE bank_id = ? ORDER BY payment_method");
    $stmt->bind_param('i', $bank_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $methods[] = $row['payment_method'];
    $stmt->close();

    if (empty($methods)) {
        $parent_stmt = $conn->prepare("SELECT parent_bank_id FROM banks WHERE bank_id = ? AND parent_bank_id IS NOT NULL LIMIT 1");
        if ($parent_stmt) {
            $parent_stmt->bind_param('i', $bank_id);
            $parent_stmt->execute();
            $parent_row = $parent_stmt->get_result()->fetch_assoc();
            $parent_stmt->close();
            $parent_bank_id = (int)($parent_row['parent_bank_id'] ?? 0);
            if ($parent_bank_id > 0) {
                $stmt = $conn->prepare("SELECT payment_method FROM bank_payment_methods WHERE bank_id = ? ORDER BY payment_method");
                $stmt->bind_param('i', $parent_bank_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $methods[] = $row['payment_method'];
                $stmt->close();
            }
        }
    }

    return $methods;
}
function getBanks($conn, $view_all_branches, $branch_id, $active_only = true, $include_sub_accounts = true) {
    createBanksTable($conn);
    createBankPaymentMethodsTable($conn);

    $sql = "SELECT b.*, pb.bank_name as parent_bank_name
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
    foreach ($rows as &$bank) {
        $bank['payment_methods'] = getBankPaymentMethods($conn, $bank['bank_id']);
    }
    unset($bank);
    return $rows;
}
function getAllBanksForMapping($conn, $view_all_branches, $branch_id) {
    createBanksTable($conn);
    $sql = "SELECT bank_id, bank_name, bank_branch, parent_bank_id FROM banks WHERE status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    foreach ($rows as &$bank) {
        $bank['payment_methods'] = getBankPaymentMethods($conn, $bank['bank_id']);
    }
    return $rows;
}

function getSubAccounts($conn, $parent_bank_id) {
    $stmt = $conn->prepare("SELECT bank_id, bank_name, account_number FROM banks WHERE parent_bank_id = ? AND status = 'active' ORDER BY bank_name");
    $stmt->bind_param('i', $parent_bank_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $subs = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $subs;
}

function getSupplierPayables($conn, $view_all_branches, $branch_id, $po_branch_column_exists, $po_supplier_id_exists) {
    if (!tableExists($conn, 'purchase_orders')) return [];

    $supplier_id_select = $po_supplier_id_exists ? 'po.supplier_id' : 'NULL AS supplier_id';
    $supplier_join = ($po_supplier_id_exists && tableExists($conn, 'suppliers'))
        ? 'LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id'
        : 'LEFT JOIN suppliers s ON 1=0';

    $sql = "SELECT po.po_id, po.po_number, po.order_date, po.expected_delivery, po.total_amount, po.po_status, po.supplier_name,
                   {$supplier_id_select},
                   COALESCE(NULLIF(TRIM(s.supplier_name), ''), NULLIF(TRIM(po.supplier_name), ''), 'Unknown Supplier') AS display_supplier_name,
                   COALESCE(SUM(CASE WHEN sp.status = 'completed' THEN sp.amount ELSE 0 END), 0) AS paid_amount,
                   (COALESCE(po.total_amount, 0) - COALESCE(SUM(CASE WHEN sp.status = 'completed' THEN sp.amount ELSE 0 END), 0)) AS balance
            FROM purchase_orders po
            {$supplier_join}
            LEFT JOIN supplier_payments sp ON sp.po_id = po.po_id
            WHERE LOWER(TRIM(COALESCE(po.po_status, ''))) = 'received'
              AND COALESCE(po.total_amount, 0) > 0";
    if (!$view_all_branches && $branch_id > 0 && $po_branch_column_exists) $sql .= " AND po.branch_id = ?";
    $sql .= " GROUP BY po.po_id HAVING balance > 0.009 ORDER BY po.order_date DESC, po.po_id DESC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && $po_branch_column_exists) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getSupplierPayableByPoId($conn, $po_id, $view_all_branches, $branch_id, $po_branch_column_exists, $po_supplier_id_exists) {
    $payables = getSupplierPayables($conn, $view_all_branches, $branch_id, $po_branch_column_exists, $po_supplier_id_exists);
    foreach ($payables as $row) {
        if ((int)$row['po_id'] === (int)$po_id) return $row;
    }
    return null;
}

function getUserInitials($user_name) {
    $parts = preg_split('/\s+/', trim($user_name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials !== '' ? substr($initials, 0, 2) : 'BA';
}

function getBranchName($conn, $branch_id, $view_all_branches) {
    if ($view_all_branches || $branch_id <= 0) return 'All Branches';
    $name = 'Branch';
    if (tableExists($conn, 'branches')) {
        $stmt = $conn->prepare('SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $branch_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) $name = $row['branch_name'];
            $stmt->close();
        }
    }
    return $name;
}

function getBankTransactions($conn, $view_all_branches, $branch_id) {
    if (!columnExists($conn, 'bank_transactions', 'expense_account')) {
        $conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `expense_account` varchar(150) DEFAULT NULL AFTER `description`");
    }
    if (!columnExists($conn, 'bank_transactions', 'payee')) {
        $conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `payee` varchar(150) DEFAULT NULL AFTER `expense_account`");
    }

    $sql = "SELECT bt.transaction_id, bt.branch_id, bt.transaction_type, bt.transaction_date,
                   bt.reference_number, bt.bank_name, bt.bank_id, bt.description, bt.amount,
                   bt.expense_account, bt.payee,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
            FROM bank_transactions bt
            LEFT JOIN users u ON bt.created_by = u.user_id
            WHERE 1=1";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND bt.branch_id = ?";
    $sql .= " ORDER BY bt.transaction_date ASC, bt.transaction_id ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $running_balance = 0.0;
    foreach ($rows as &$transaction) {
        $amount = (float)$transaction['amount'];
        if ($transaction['transaction_type'] === 'deposit') {
            $running_balance += $amount;
            $transaction['deposit'] = $amount;
            $transaction['withdrawal'] = 0.0;
        } else {
            $running_balance -= $amount;
            $transaction['deposit'] = 0.0;
            $transaction['withdrawal'] = $amount;
        }
        $transaction['balance'] = $running_balance;
    }
    unset($transaction);
    return $rows;
}

function getSupplierPayments($conn, $view_all_branches, $branch_id, $po_branch_column_exists, $limit = 80) {
    if (!tableExists($conn, 'supplier_payments')) return [];
    $sql = "SELECT sp.*, po.po_number, po.total_amount, bb.document_no AS beginning_balance_document_no,
                   COALESCE(NULLIF(TRIM(s.supplier_name), ''), NULLIF(TRIM(bb.supplier_name), ''), NULLIF(TRIM(po.supplier_name), ''), 'Unknown Supplier') AS supplier_name,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS encoded_by_name
            FROM supplier_payments sp
            LEFT JOIN purchase_orders po ON po.po_id = sp.po_id
            LEFT JOIN supplier_beginning_balances bb ON bb.bb_id = sp.beginning_balance_id
            LEFT JOIN suppliers s ON s.supplier_id = sp.supplier_id
            LEFT JOIN users u ON u.user_id = sp.created_by
            WHERE 1=1";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND sp.branch_id = ?";
    $sql .= " ORDER BY sp.payment_date DESC, sp.supplier_payment_id DESC LIMIT " . (int)$limit;

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
        `expense_account` varchar(150) DEFAULT NULL,
        `payee` varchar(150) DEFAULT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transaction_id`),
        KEY `branch_id` (`branch_id`),
        KEY `transaction_type` (`transaction_type`),
        KEY `transaction_date` (`transaction_date`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    
    if (!columnExists($conn, 'bank_transactions', 'expense_account')) {
        $conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `expense_account` varchar(150) DEFAULT NULL AFTER `description`");
    }
    if (!columnExists($conn, 'bank_transactions', 'payee')) {
        $conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `payee` varchar(150) DEFAULT NULL AFTER `expense_account`");
    }

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
        UNIQUE KEY `uniq_transaction_payment` (`transaction_id`,`payment_id`),
        KEY `payment_id` (`payment_id`),
        KEY `transaction_id` (`transaction_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function createSupplierPaymentTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `supplier_payments` (
        `supplier_payment_id` int(11) NOT NULL AUTO_INCREMENT,
        `po_id` int(11) NOT NULL,
        `supplier_id` int(11) DEFAULT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `payment_method` enum('cash','check','online_transfer') NOT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `payment_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `check_date` date DEFAULT NULL,
        `bank_name` varchar(150) DEFAULT NULL,
        `bank_branch` varchar(150) DEFAULT NULL,
        `check_number` varchar(100) DEFAULT NULL,
        `cash_tendered` decimal(12,2) DEFAULT NULL,
        `cash_change` decimal(12,2) DEFAULT NULL,
        `status` enum('completed','pending','failed') DEFAULT 'completed',
        `bank_transaction_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`supplier_payment_id`),
        KEY `po_id` (`po_id`),
        KEY `supplier_id` (`supplier_id`),
        KEY `branch_id` (`branch_id`),
        KEY `bank_transaction_id` (`bank_transaction_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}


function ensureExpenseAccountsTableForWithdrawal($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `expense_accounts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_name` varchar(150) NOT NULL,
        `is_sub_account` tinyint(1) NOT NULL DEFAULT 0,
        `parent_bank_name` varchar(150) DEFAULT NULL,
        `description` text NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function getExpenseAccountOptions($conn, $view_all_branches, $branch_id) {
    ensureExpenseAccountsTableForWithdrawal($conn);

    $sql = "SELECT id, bank_name, is_sub_account, parent_bank_name, description, branch_id
            FROM expense_accounts
            WHERE TRIM(COALESCE(bank_name, '')) <> ''";
    if (!$view_all_branches && $branch_id > 0) {
        $sql .= " AND (branch_id = ? OR branch_id = 0)";
    }
    $sql .= " ORDER BY COALESCE(NULLIF(parent_bank_name, ''), bank_name) ASC, is_sub_account ASC, bank_name ASC";

    $rows = [];
    $seen = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) {
            $stmt->bind_param('i', $branch_id);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $bank_name = trim($row['bank_name'] ?? '');
            $parent_bank_name = trim($row['parent_bank_name'] ?? '');
            $is_sub_account = (int)($row['is_sub_account'] ?? 0) === 1;
            $label = ($is_sub_account && $parent_bank_name !== '') ? ($parent_bank_name . ' / ' . $bank_name) : $bank_name;
            if ($label === '') continue;
            $key = strtolower($label);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'label' => $label,
                'bank_name' => $bank_name,
                'is_sub_account' => $is_sub_account ? 1 : 0,
                'parent_bank_name' => $parent_bank_name,
                'description' => trim($row['description'] ?? '')
            ];
        }
        $stmt->close();
    }
    return $rows;
}

function findExpenseAccountOptionByLabel($conn, $view_all_branches, $branch_id, $label) {
    $label = trim((string)$label);
    if ($label === '') return null;
    foreach (getExpenseAccountOptions($conn, $view_all_branches, $branch_id) as $option) {
        if (strcasecmp($option['label'], $label) === 0) return $option;
    }
    return null;
}

function ensureSupplierBeginningBalanceTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `supplier_beginning_balances` (
        `bb_id` int(11) NOT NULL AUTO_INCREMENT,
        `supplier_id` int(11) DEFAULT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `supplier_name` varchar(255) NOT NULL,
        `document_no` varchar(150) NOT NULL,
        `document_date` date NOT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `remarks` text DEFAULT NULL,
        `status` varchar(30) NOT NULL DEFAULT 'active',
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`bb_id`),
        KEY `supplier_id` (`supplier_id`),
        KEY `branch_id` (`branch_id`),
        KEY `document_no` (`document_no`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `supplier_beginning_balance_attachments` (
        `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
        `bb_id` int(11) NOT NULL,
        `supplier_id` int(11) DEFAULT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `file_name` varchar(255) NOT NULL,
        `stored_name` varchar(255) NOT NULL,
        `file_path` varchar(500) NOT NULL,
        `file_type` varchar(120) DEFAULT NULL,
        `file_size` int(11) NOT NULL DEFAULT 0,
        `uploaded_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`attachment_id`),
        KEY `bb_id` (`bb_id`),
        KEY `supplier_id` (`supplier_id`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (tableExists($conn, 'supplier_payments') && !columnExists($conn, 'supplier_payments', 'beginning_balance_id')) {
        $conn->query("ALTER TABLE `supplier_payments` ADD COLUMN `beginning_balance_id` int(11) DEFAULT NULL AFTER `po_id`");
        $conn->query("ALTER TABLE `supplier_payments` ADD INDEX `beginning_balance_id` (`beginning_balance_id`)");
    }
}

function getSuppliersForBeginningBalance($conn, $view_all_branches, $branch_id) {
    if (!tableExists($conn, 'suppliers')) return [];
    $nameColumn = columnExists($conn, 'suppliers', 'supplier_name') ? 'supplier_name' : (columnExists($conn, 'suppliers', 'name') ? 'name' : '');
    if ($nameColumn === '') return [];

    $sql = "SELECT supplier_id, {$nameColumn} AS supplier_name FROM suppliers WHERE TRIM(COALESCE({$nameColumn}, '')) <> ''";
    if (columnExists($conn, 'suppliers', 'status')) {
        $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
    }
    if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'suppliers', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0)";
    }
    $sql .= " ORDER BY {$nameColumn} ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'suppliers', 'branch_id')) {
            $stmt->bind_param('i', $branch_id);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function saveSupplierBeginningBalanceAttachments($conn, $bb_id, $supplier_id, $branch_id, $uploaded_by) {
    ensureSupplierBeginningBalanceTables($conn);

    if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'])) return 0;

    $project_root = realpath(dirname(__DIR__));
    if ($project_root === false) $project_root = dirname(__DIR__);

    $upload_dir = rtrim($project_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'supplier_beginning_balances' . DIRECTORY_SEPARATOR;
    $public_dir = '../uploads/supplier_beginning_balances/';

    if (!is_dir($upload_dir)) {
        if (!@mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
            throw new Exception('Unable to create attachment upload folder: uploads/supplier_beginning_balances');
        }
    }
    @chmod($upload_dir, 0775);
    if (!is_writable($upload_dir)) {
        throw new Exception('Attachment upload folder is not writable: uploads/supplier_beginning_balances');
    }

    $allowed_ext = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt'];
    $saved_count = 0;

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

    for ($i = 0; $i < count($names); $i++) {
        $upload_error = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
        $original_name = basename((string)($names[$i] ?? ''));
        if ($upload_error === UPLOAD_ERR_NO_FILE || $original_name === '') continue;
        if ($upload_error !== UPLOAD_ERR_OK) {
            throw new Exception('Failed to upload attachment: ' . $original_name . ' (error code: ' . $upload_error . ')');
        }

        $tmp_file = $tmp_names[$i] ?? '';
        if ($tmp_file === '' || !is_uploaded_file($tmp_file)) {
            throw new Exception('Invalid uploaded attachment: ' . $original_name);
        }

        $file_size = (int)($sizes[$i] ?? 0);
        if ($file_size > 10 * 1024 * 1024) {
            throw new Exception('Attachment is too large. Maximum allowed size is 10MB per file: ' . $original_name);
        }

        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed_ext, true)) {
            throw new Exception('Invalid attachment type: ' . $original_name);
        }

        $safe_original_name = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $original_name);
        if ($safe_original_name === '' || $safe_original_name === null) $safe_original_name = 'attachment.' . $ext;

        $stored_name = 'supplier_bb_' . (int)$bb_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target_path = $upload_dir . $stored_name;

        if (!move_uploaded_file($tmp_file, $target_path)) {
            throw new Exception('Unable to save attachment to uploads/supplier_beginning_balances: ' . $original_name);
        }
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
        $stmt = $conn->prepare("INSERT INTO supplier_beginning_balance_attachments
            (bb_id, supplier_id, branch_id, file_name, stored_name, file_path, file_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            @unlink($target_path);
            throw new Exception('Failed to prepare attachment insert: ' . $conn->error);
        }
        $stmt->bind_param('iiissssii', $bb_id, $supplier_id, $branch_id, $safe_original_name, $stored_name, $public_path, $file_type, $file_size, $uploaded_by);
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

function getSupplierBeginningBalancePayables($conn, $view_all_branches, $branch_id) {
    ensureSupplierBeginningBalanceTables($conn);
    $sql = "SELECT bb.*,
                   COALESCE(SUM(CASE WHEN sp.status = 'completed' THEN sp.amount ELSE 0 END), 0) AS paid_amount,
                   (COALESCE(bb.amount, 0) - COALESCE(SUM(CASE WHEN sp.status = 'completed' THEN sp.amount ELSE 0 END), 0)) AS balance
            FROM supplier_beginning_balances bb
            LEFT JOIN supplier_payments sp ON sp.beginning_balance_id = bb.bb_id
            WHERE COALESCE(bb.amount, 0) > 0
              AND LOWER(COALESCE(bb.status, 'active')) <> 'cancelled'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND bb.branch_id = ?";
    $sql .= " GROUP BY bb.bb_id HAVING balance > 0.009 ORDER BY bb.document_date DESC, bb.bb_id DESC";

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

function getSupplierBeginningBalanceById($conn, $bb_id, $view_all_branches, $branch_id) {
    $payables = getSupplierBeginningBalancePayables($conn, $view_all_branches, $branch_id);
    foreach ($payables as $row) {
        if ((int)$row['bb_id'] === (int)$bb_id) return $row;
    }
    return null;
}

function getSupplierBeginningBalanceAttachments($conn, $bb_id) {
    ensureSupplierBeginningBalanceTables($conn);
    $bb_id = (int)$bb_id;
    if ($bb_id <= 0) return [];

    $rows = [];
    $stmt = $conn->prepare("SELECT attachment_id, file_name, file_path, file_type, file_size, created_at
                            FROM supplier_beginning_balance_attachments
                            WHERE bb_id = ?
                            ORDER BY attachment_id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $bb_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}


createBankingTables($conn);
createBanksTable($conn);
createBankPaymentMethodsTable($conn);
createSupplierPaymentTable($conn);
ensureExpenseAccountsTableForWithdrawal($conn);
ensureSupplierBeginningBalanceTables($conn);

$user_initials = getUserInitials($user_name);
$branch_name = getBranchName($conn, $branch_id, $view_all_branches);
$so_branch_column_exists = columnExists($conn, 'sales_orders', 'branch_id');
$invoices_has_so_id = columnExists($conn, 'invoices', 'so_id');
$po_branch_column_exists = columnExists($conn, 'purchase_orders', 'branch_id');
$po_supplier_id_exists = columnExists($conn, 'purchase_orders', 'supplier_id');

function getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    if (!tableExists($conn, 'payments')) return [];
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

function getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    if (!tableExists($conn, 'payments')) return [];
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

$flash_success = $_SESSION['success_message'] ?? '';
$flash_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_withdrawal') {
            $transaction_date_input = trim($_POST['transaction_date'] ?? '');
            if (empty($transaction_date_input)) throw new Exception('Transaction date is required.');
            $transaction_date = date('Y-m-d 00:00:00', strtotime($transaction_date_input));
            $reference_number = trim($_POST['reference_number'] ?? '');
            $bank_id = (int)($_POST['bank_id'] ?? 0);
            $description = trim($_POST['description'] ?? 'Bank withdrawal');
            $amount = (float)($_POST['amount'] ?? 0);
            $expense_account = trim($_POST['expense_account'] ?? '');
            $payee = trim($_POST['payee'] ?? '');
            
            if ($bank_id <= 0) throw new Exception('Please select a bank account.');
            if ($amount <= 0) throw new Exception('Withdrawal amount must be greater than zero.');
            if ($expense_account === '') throw new Exception('Expense account is required.');
            if ($payee === '') throw new Exception('Payee is required.');

            $selected_expense_account = findExpenseAccountOptionByLabel($conn, $view_all_branches, $branch_id, $expense_account);
            if (!$selected_expense_account) {
                throw new Exception('Please select a recorded expense account from Expenses.');
            }
            $expense_account = $selected_expense_account['label'];
            if ($description === '' || $description === 'Bank withdrawal') {
                $description = $selected_expense_account['description'] ?: $description;
            }

            $bank_stmt = $conn->prepare("SELECT bank_name, parent_bank_id FROM banks WHERE bank_id = ? AND status = 'active' AND parent_bank_id IS NOT NULL");
            $bank_stmt->bind_param('i', $bank_id);
            $bank_stmt->execute();
            $bank_row = $bank_stmt->get_result()->fetch_assoc();
            $bank_stmt->close();
            if (!$bank_row) throw new Exception('Please select a registered sub account. Parent banks are folders only and cannot receive transactions.');
            $bank_name = trim($bank_row['bank_name'] ?? '');

            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;
            $stmt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, bank_id, description, expense_account, payee, amount, created_by) VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Failed to prepare withdrawal transaction: ' . $conn->error);
            $stmt->bind_param('isssisssdi', $effective_branch_id, $transaction_date, $reference_number, $bank_name, $bank_id, $description, $expense_account, $payee, $amount, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to save withdrawal transaction: ' . $stmt->error);
            $stmt->close();
            $_SESSION['success_message'] = 'Withdrawal transaction saved successfully.';
        }


        if ($action === 'add_supplier_beginning_balance') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            $document_no = trim($_POST['document_no'] ?? '');
            $document_date_input = trim($_POST['document_date'] ?? '');
            $amount = (float)str_replace(',', '', trim($_POST['amount'] ?? '0'));
            $remarks = trim($_POST['remarks'] ?? '');

            if ($supplier_id <= 0) throw new Exception('Please select a supplier.');
            if ($document_no === '') throw new Exception('Document No. is required.');
            if ($document_date_input === '') throw new Exception('Date is required.');
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');

            $has_attachment = false;
            if (!empty($_FILES['attachments']) && !empty($_FILES['attachments']['name'])) {
                $attachment_names = $_FILES['attachments']['name'];
                if (!is_array($attachment_names)) $attachment_names = [$attachment_names];
                foreach ($attachment_names as $attachment_name) {
                    if (trim((string)$attachment_name) !== '') {
                        $has_attachment = true;
                        break;
                    }
                }
            }
            if (!$has_attachment) throw new Exception('Please upload at least one attachment.');

            if (!tableExists($conn, 'suppliers')) throw new Exception('Suppliers table not found.');
            $supplierNameColumn = columnExists($conn, 'suppliers', 'supplier_name') ? 'supplier_name' : (columnExists($conn, 'suppliers', 'name') ? 'name' : '');
            if ($supplierNameColumn === '') throw new Exception('Supplier name column not found.');

            $supplier_sql = "SELECT supplier_id, {$supplierNameColumn} AS supplier_name FROM suppliers WHERE supplier_id = ?";
            if (columnExists($conn, 'suppliers', 'status')) {
                $supplier_sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
            }
            if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'suppliers', 'branch_id')) {
                $supplier_sql .= " AND (branch_id = ? OR branch_id = 0)";
            }
            $supplier_sql .= " LIMIT 1";

            $supplier_stmt = $conn->prepare($supplier_sql);
            if (!$supplier_stmt) throw new Exception('Failed to prepare supplier lookup: ' . $conn->error);
            if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'suppliers', 'branch_id')) {
                $supplier_stmt->bind_param('ii', $supplier_id, $branch_id);
            } else {
                $supplier_stmt->bind_param('i', $supplier_id);
            }
            $supplier_stmt->execute();
            $supplier = $supplier_stmt->get_result()->fetch_assoc();
            $supplier_stmt->close();

            if (!$supplier) throw new Exception('Supplier not found in this branch.');
            $supplier_name = trim($supplier['supplier_name'] ?? '');
            if ($supplier_name === '') throw new Exception('Supplier name is empty.');

            $document_date = date('Y-m-d', strtotime($document_date_input));
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;

            $dup_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM supplier_beginning_balances WHERE supplier_id = ? AND document_no = ? AND LOWER(COALESCE(status, 'active')) <> 'cancelled'");
            if ($dup_stmt) {
                $dup_stmt->bind_param('is', $supplier_id, $document_no);
                $dup_stmt->execute();
                $dup_row = $dup_stmt->get_result()->fetch_assoc();
                $dup_stmt->close();
                if ((int)($dup_row['cnt'] ?? 0) > 0) {
                    throw new Exception('Document No. already exists for this supplier.');
                }
            }

            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO supplier_beginning_balances (supplier_id, branch_id, supplier_name, document_no, document_date, amount, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Failed to prepare beginning balance: ' . $conn->error);
            $stmt->bind_param('iisssdsi', $supplier_id, $effective_branch_id, $supplier_name, $document_no, $document_date, $amount, $remarks, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to save beginning balance: ' . $stmt->error);
            $bb_id = (int)$conn->insert_id;
            $stmt->close();

            saveSupplierBeginningBalanceAttachments($conn, $bb_id, $supplier_id, $effective_branch_id, $user_id);

            $conn->commit();
            $_SESSION['success_message'] = 'Supplier beginning balance saved successfully.';
        }

        if ($action === 'record_supplier_payment') {
            $payable_type = trim($_POST['payable_type'] ?? 'po');
            $po_id = (int)($_POST['po_id'] ?? 0);
            $beginning_balance_id = (int)($_POST['beginning_balance_id'] ?? 0);
            $selected_bank_id = (int)($_POST['supplier_bank_id'] ?? 0);
            $selected_sub_account_id = isset($_POST['sub_account_id']) && $_POST['sub_account_id'] !== '' ? (int)$_POST['sub_account_id'] : null;
            $selected_payment_method = $_POST['payment_method'] ?? '';
            $amount = (float)($_POST['amount'] ?? 0);
            $payment_date_input = trim($_POST['payment_date'] ?? '');
            if (empty($payment_date_input)) throw new Exception('Payment date is required.');
            $payment_date = date('Y-m-d 00:00:00', strtotime($payment_date_input));

            if ($selected_bank_id <= 0) throw new Exception('Please select a bank account.');
            if (!in_array($selected_payment_method, ['check','online_transfer','cash'], true)) throw new Exception('Please select a valid payment method.');
            if ($amount <= 0) throw new Exception('Payment amount must be greater than zero.');

            $actual_bank_id = $selected_bank_id;

            $bank_stmt = $conn->prepare("SELECT bank_name, bank_branch, parent_bank_id FROM banks WHERE bank_id = ? AND status = 'active' AND parent_bank_id IS NOT NULL");
            $bank_stmt->bind_param('i', $actual_bank_id);
            $bank_stmt->execute();
            $bank = $bank_stmt->get_result()->fetch_assoc();
            $bank_stmt->close();
            if (!$bank) throw new Exception('Please select a registered sub account. Parent banks are folders only and cannot receive transactions.');
            $bank_name = $bank['bank_name'];
            $bank_branch = $bank['bank_branch'] ?? '';

            $methods = getBankPaymentMethods($conn, $actual_bank_id);
            if (!in_array($selected_payment_method, $methods, true)) throw new Exception('This bank does not support the selected payment method.');

            $reference_number = trim($_POST['reference_number'] ?? '');
            $check_date = null;
            $check_number = null;
            $cash_tendered = null;
            $cash_change = null;

            if ($selected_payment_method === 'check') {
                $check_date = trim($_POST['check_date'] ?? '');
                $check_number = trim($_POST['check_number'] ?? '');
                if ($check_date === '' || $check_number === '') throw new Exception('Check date and check number are required.');
                $reference_number = $check_number;
            } elseif ($selected_payment_method === 'online_transfer') {
                if ($reference_number === '') throw new Exception('Reference number is required for online transfer.');
                $check_date = null;
                $check_number = null;
            } else {
                $reference_number = null;
                $check_date = null;
                $check_number = null;
            }

            $payment_method_mapped = ($selected_payment_method === 'check') ? 'check' : (($selected_payment_method === 'online_transfer') ? 'online_transfer' : 'cash');
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;

            if ($payable_type === 'beginning_balance') {
                if ($beginning_balance_id <= 0) throw new Exception('Invalid supplier beginning balance selected.');
                $payable = getSupplierBeginningBalanceById($conn, $beginning_balance_id, $view_all_branches, $branch_id);
                if (!$payable) throw new Exception('Supplier beginning balance not found or already fully paid.');

                $balance = (float)$payable['balance'];
                if ($amount > ($balance + 0.009)) throw new Exception('Payment amount cannot be greater than the remaining payable balance.');

                $conn->begin_transaction();

                $description = 'Supplier beginning balance payment - ' . ($payable['supplier_name'] ?? 'Supplier') . ' / ' . ($payable['document_no'] ?? ('BB-' . $beginning_balance_id));

                $bt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, bank_id, description, expense_account, payee, amount, created_by) VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, 'Supplier Beginning Balance', ?, ?, ?)");
                if (!$bt) throw new Exception('Failed to prepare bank transaction: ' . $conn->error);
                $bt->bind_param('isssissdi', $effective_branch_id, $payment_date, $reference_number, $bank_name, $actual_bank_id, $description, $payable['supplier_name'], $amount, $user_id);
                if (!$bt->execute()) throw new Exception('Failed to save bank transaction: ' . $bt->error);
                $bank_transaction_id = (int)$conn->insert_id;
                $bt->close();

                $supplier_id = isset($payable['supplier_id']) && $payable['supplier_id'] !== null ? (int)$payable['supplier_id'] : null;
                $sp = $conn->prepare("INSERT INTO supplier_payments (po_id, beginning_balance_id, supplier_id, branch_id, payment_method, amount, payment_date, reference_number, check_date, bank_name, bank_branch, check_number, cash_tendered, cash_change, bank_transaction_id, created_by) VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$sp) throw new Exception('Failed to prepare supplier beginning balance payment: ' . $conn->error);
                $sp->bind_param('iiisdssssssddii', $beginning_balance_id, $supplier_id, $effective_branch_id, $payment_method_mapped, $amount, $payment_date, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change, $bank_transaction_id, $user_id);
                if (!$sp->execute()) throw new Exception('Failed to save supplier beginning balance payment: ' . $sp->error);
                $sp->close();

                $new_balance = $balance - $amount;
                if ($new_balance <= 0.009) {
                    $update_bb = $conn->prepare("UPDATE supplier_beginning_balances SET status = 'paid', updated_at = NOW() WHERE bb_id = ?");
                    if ($update_bb) {
                        $update_bb->bind_param('i', $beginning_balance_id);
                        $update_bb->execute();
                        $update_bb->close();
                    }
                }

                $conn->commit();
                $_SESSION['success_message'] = 'Supplier beginning balance payment recorded successfully.';
            } else {
                if ($po_id <= 0) throw new Exception('Invalid purchase order selected.');

                $payable = getSupplierPayableByPoId($conn, $po_id, $view_all_branches, $branch_id, $po_branch_column_exists, $po_supplier_id_exists);
                if (!$payable) throw new Exception('Supplier payable not found or already fully paid.');

                $balance = (float)$payable['balance'];
                if ($amount > ($balance + 0.009)) throw new Exception('Payment amount cannot be greater than the remaining payable balance.');

                $conn->begin_transaction();
                $description = 'Supplier payment - ' . ($payable['display_supplier_name'] ?? 'Supplier') . ' / ' . ($payable['po_number'] ?? ('PO-' . $po_id));

                $bt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, bank_id, description, expense_account, payee, amount, created_by) VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, 'Supplier Payment', ?, ?, ?)");
                if (!$bt) throw new Exception('Failed to prepare bank transaction: ' . $conn->error);
                $bt->bind_param('isssissdi', $effective_branch_id, $payment_date, $reference_number, $bank_name, $actual_bank_id, $description, $payable['display_supplier_name'], $amount, $user_id);
                if (!$bt->execute()) throw new Exception('Failed to save bank transaction: ' . $bt->error);
                $bank_transaction_id = (int)$conn->insert_id;
                $bt->close();

                $supplier_id = isset($payable['supplier_id']) && $payable['supplier_id'] !== null ? (int)$payable['supplier_id'] : null;
                $sp = $conn->prepare("INSERT INTO supplier_payments (po_id, supplier_id, branch_id, payment_method, amount, payment_date, reference_number, check_date, bank_name, bank_branch, check_number, cash_tendered, cash_change, bank_transaction_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$sp) throw new Exception('Failed to prepare supplier payment: ' . $conn->error);
                $sp->bind_param('iiisdssssssddii', $po_id, $supplier_id, $effective_branch_id, $payment_method_mapped, $amount, $payment_date, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change, $bank_transaction_id, $user_id);
                if (!$sp->execute()) throw new Exception('Failed to save supplier payment: ' . $sp->error);
                $sp->close();

                $new_balance = $balance - $amount;
                if ($new_balance <= 0.009 && tableExists($conn, 'purchase_orders')) {
                    $update_po = $conn->prepare("UPDATE purchase_orders SET po_status = 'paid', updated_at = NOW() WHERE po_id = ?");
                    if ($update_po) {
                        $update_po->bind_param('i', $po_id);
                        $update_po->execute();
                        $update_po->close();
                    }
                }

                $conn->commit();
                $_SESSION['success_message'] = 'Supplier payment recorded successfully.';
            }
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            @$conn->rollback();
        }
        $_SESSION['error_message'] = $e->getMessage();
    }
    header('Location: Withdrawal.php');
    exit();
}

$banks = getBanks($conn, $view_all_branches, $branch_id, true, true);
$all_banks_for_mapping = getAllBanksForMapping($conn, $view_all_branches, $branch_id);

$available_payments = getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$recent_payments = getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$bank_transactions = getBankTransactions($conn, $view_all_branches, $branch_id);
$supplier_payables = getSupplierPayables($conn, $view_all_branches, $branch_id, $po_branch_column_exists, $po_supplier_id_exists);
$supplier_beginning_balance_payables = getSupplierBeginningBalancePayables($conn, $view_all_branches, $branch_id);
$supplier_payment_history = getSupplierPayments($conn, $view_all_branches, $branch_id, $po_branch_column_exists, 80);

// Fetch recorded expense accounts from Expenses module for searchable dropdown
$expense_accounts = getExpenseAccountOptions($conn, $view_all_branches, $branch_id);
$supplier_beginning_balance_options = getSuppliersForBeginningBalance($conn, $view_all_branches, $branch_id);
$expense_account_labels = array_map(function($row) {
    return $row['label'];
}, $expense_accounts);

$payment_history_combined = [];

// Add supplier payments
foreach ($supplier_payment_history as $sp) {
    $isBeginningBalancePayment = !empty($sp['beginning_balance_id']);
    $documentLabel = $isBeginningBalancePayment ? ($sp['beginning_balance_document_no'] ?? '') : ($sp['po_number'] ?? '');
    $paymentTypeLabel = $isBeginningBalancePayment ? 'Supplier Beginning Balance Payment' : 'Supplier Payment';
    $paymentDescription = ($isBeginningBalancePayment ? 'Document No.: ' : 'PO: ') . h($documentLabel ?: '—') . ' | Method: ' . ucwords(str_replace('_', ' ', h($sp['payment_method'])));
    $payment_history_combined[] = [
        'sort_date' => strtotime($sp['payment_date']),
        'sort_id' => $sp['supplier_payment_id'],
        'date' => $sp['payment_date'],
        'type' => $paymentTypeLabel,
        'partner' => h($sp['supplier_name']),
        'reference' => !empty($sp['reference_number']) ? h($sp['reference_number']) : ( !empty($sp['check_number']) ? h($sp['check_number']) : '—' ),
        'description' => $paymentDescription,
        'expense_account' => '',
        'payee' => '',
        'amount' => (float)$sp['amount'],
        'encoded_by' => h(trim($sp['encoded_by_name']) ?: 'Unknown User'),
        'po_number' => h($documentLabel),
        'payment_method' => ucwords(str_replace('_', ' ', h($sp['payment_method']))),
        'reference_number' => !empty($sp['reference_number']) ? h($sp['reference_number']) : '',
        'check_number' => !empty($sp['check_number']) ? h($sp['check_number']) : '',
        'check_date' => !empty($sp['check_date']) ? date('M d, Y', strtotime($sp['check_date'])) : '',
        'bank_name' => h($sp['bank_name'] ?? ''),
    ];
}

// Add withdrawals
foreach ($bank_transactions as $tx) {
    if ($tx['transaction_type'] !== 'withdrawal') continue;
    $payment_history_combined[] = [
        'sort_date' => strtotime($tx['transaction_date']),
        'sort_id' => $tx['transaction_id'],
        'date' => $tx['transaction_date'],
        'type' => 'Withdrawal',
        'partner' => h($tx['bank_name'] ?: '—'),
        'reference' => h($tx['reference_number'] ?: '—'),
        'description' => h($tx['description'] ?: '—'),
        'expense_account' => h($tx['expense_account'] ?: '—'),
        'payee' => h($tx['payee'] ?: '—'),
        'amount' => (float)$tx['amount'],
        'encoded_by' => h(trim($tx['created_by_name']) !== '' ? $tx['created_by_name'] : 'Unknown User'),
        'transaction_id' => $tx['transaction_id'],
        'bank_id' => $tx['bank_id'] ?? 0,
        'bank_name_full' => h($tx['bank_name'] ?: '—'),
        'expense_account_full' => h($tx['expense_account'] ?: '—'),
        'payee_full' => h($tx['payee'] ?: '—'),
    ];
}

// Sort by date DESC, then by secondary ID DESC
usort($payment_history_combined, function($a, $b) {
    if ($a['sort_date'] == $b['sort_date']) {
        return $b['sort_id'] - $a['sort_id'];
    }
    return $b['sort_date'] - $a['sort_date'];
});

$bank_balance_by_id = [];
$bank_balances_by_name = [];
foreach ($all_banks_for_mapping as $bank) {
    $bank_balance_by_id[(int)$bank['bank_id']] = 0.0;
}
foreach ($bank_transactions as $tx) {
    $amount = (float)$tx['amount'];
    $signed_amount = ($tx['transaction_type'] === 'deposit') ? $amount : -$amount;
    $tx_bank_id = isset($tx['bank_id']) ? (int)$tx['bank_id'] : 0;
    if ($tx_bank_id > 0) {
        if (!isset($bank_balance_by_id[$tx_bank_id])) $bank_balance_by_id[$tx_bank_id] = 0.0;
        $bank_balance_by_id[$tx_bank_id] += $signed_amount;
    } else {
        $bname = trim($tx['bank_name'] ?? '');
        if ($bname !== '') {
            if (!isset($bank_balances_by_name[$bname])) $bank_balances_by_name[$bname] = 0.0;
            $bank_balances_by_name[$bname] += $signed_amount;
        }
    }
}
foreach ($all_banks_for_mapping as $bank) {
    $bank_id_key = (int)$bank['bank_id'];
    $bname = trim($bank['bank_name'] ?? '');
    if (isset($bank_balance_by_id[$bank_id_key]) && abs($bank_balance_by_id[$bank_id_key]) > 0.0001) continue;
    if ($bname !== '' && isset($bank_balances_by_name[$bname])) {
        $bank_balance_by_id[$bank_id_key] = (float)$bank_balances_by_name[$bname];
    }
}
$undeposited_total = 0.0;
foreach ($available_payments as $row) $undeposited_total += (float)$row['amount'];

$total_collections = 0.0;
foreach ($recent_payments as $row) $total_collections += (float)$row['amount'];

$total_deposits = 0.0;
$total_withdrawals = 0.0;
foreach ($bank_transactions as $tx) {
    if ($tx['transaction_type'] === 'deposit') $total_deposits += (float)$tx['amount'];
    else $total_withdrawals += (float)$tx['amount'];
}
$bank_balance = array_sum($bank_balance_by_id);

$total_supplier_payables = 0.0;
foreach ($supplier_payables as $row) $total_supplier_payables += (float)$row['balance'];
foreach ($supplier_beginning_balance_payables as $row) $total_supplier_payables += (float)$row['balance'];

$page_title = 'Withdrawal';
$page_subtitle = 'Payables, Payment History & Withdrawal';
$active_page = 'withdrawal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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
.badge-soft-green{background:rgba(68,211,78,.16);color:#047857}.badge-soft-blue{background:rgba(34,211,238,.15);color:#0f766e}.badge-soft-red{background:rgba(248,113,113,.14);color:#b91c1c}.badge-soft-yellow{background:rgba(251,191,36,.18);color:#92400e}
.table thead th{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);color:#fff!important;border:none;white-space:nowrap;font-size:.84rem}
.table tbody td{vertical-align:middle;font-size:.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
.collector-name{font-weight:600;color:#052A47}.amount-positive{color:#047857;font-weight:700}.amount-negative{color:#dc2626;font-weight:700}.amount-neutral{color:#052A47;font-weight:700}
.payment-select-box{max-height:500px;overflow:auto;border:1px solid #e5e7eb;border-radius:14px;padding:.75rem;background:#f9fafb}.payment-option{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:.75rem;margin-bottom:.6rem}.form-control,.form-select{border-radius:10px;min-height:44px}
.btn-amgc-primary{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);color:#fff;border:none;border-radius:999px;padding:8px 18px;min-height:36px;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 10px rgba(0,0,0,.15);transition:all .2s ease}.btn-amgc-primary:hover{color:#fff;transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.2);opacity:.95}.btn-amgc-dark{background:#047857;color:#fff;border:none;border-radius:999px;padding:8px 18px;min-height:36px;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 4px 10px rgba(0,0,0,.15);transition:all .2s ease}.btn-amgc-dark:hover{color:#fff;transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.2);opacity:.96}.nav-tabs .nav-link{font-weight:700;color:#052A47}.nav-tabs .nav-link.active{color:#047857}.navbar-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}.mobile-menu-btn{display:none;background:transparent;border:none;font-size:1.5rem;color:#052A47}
@media(max-width:992px){.sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}.main-content{margin-left:0!important}.mobile-menu-btn{display:block}body{padding-bottom:70px}}@media(max-width:768px){.table-responsive{overflow-x:auto}.section-header{display:block}.stat-value{font-size:1.2rem}}
.clickable-row,.clickable-payable-row{cursor:pointer;transition:background .15s}.clickable-row:hover,.clickable-payable-row:hover{background:#f1f5f9!important}
.transaction-summary-card{background:#e8f5e9;border:1px solid rgba(68,211,78,.25);border-radius:16px;padding:1rem;margin-bottom:1rem}
.transaction-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
.transaction-detail-item{border:1px solid #eef2f7;border-radius:12px;padding:.75rem;background:#fff}
.transaction-detail-label{font-size:.75rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.02em;margin-bottom:.25rem}
.transaction-detail-value{font-size:.95rem;color:#052A47;font-weight:700;word-break:break-word}
@media(max-width:768px){.transaction-detail-grid{grid-template-columns:1fr}}
/* Withdrawal Modal Styles - same style as other modals */
#withdrawalModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 900px !important;
}

@media (max-width: 768px) {
    #withdrawalModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #withdrawalModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#withdrawalModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#withdrawalModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

@media (max-width: 768px) {
    #withdrawalModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#withdrawalModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#withdrawalModal .modal-header .modal-title i {
    color: white !important;
}

@media (max-width: 576px) {
    #withdrawalModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button */
#withdrawalModal .modal-header .btn-close {
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

@media (max-width: 576px) {
    #withdrawalModal .modal-header .btn-close {
        width: 30px !important;
        height: 30px !important;
    }
}

#withdrawalModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
    font-family: system-ui, -apple-system, sans-serif !important;
}

@media (max-width: 576px) {
    #withdrawalModal .modal-header .btn-close::before {
        font-size: 0.9rem !important;
    }
}

#withdrawalModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#withdrawalModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    background: #f8fafc !important;
    max-height: calc(90vh - 130px) !important;
}

@media (max-width: 576px) {
    #withdrawalModal .modal-body {
        padding: 1rem !important;
        max-height: calc(90vh - 110px) !important;
    }
}

/* Form Styles */
#withdrawalModal .form-label {
    font-weight: 600 !important;
    font-size: 0.8rem !important;
    margin-bottom: 0.5rem !important;
    color: #1f2937 !important;
}

#withdrawalModal .form-control,
#withdrawalModal .form-select {
    border-radius: 10px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 0.6rem 0.75rem !important;
    font-size: 0.85rem !important;
    transition: all 0.2s ease !important;
}

#withdrawalModal .form-control:focus,
#withdrawalModal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1) !important;
    outline: none !important;
}

#withdrawalModal .form-control:disabled,
#withdrawalModal .form-control[readonly] {
    background-color: #f1f5f9 !important;
    cursor: not-allowed !important;
}

#withdrawalModal .form-text,
#withdrawalModal .small.text-muted {
    font-size: 0.7rem !important;
    color: #6c757d !important;
    margin-top: 0.25rem !important;
}

/* Textarea */
#withdrawalModal textarea.form-control {
    resize: vertical !important;
}

/* Row gaps */
#withdrawalModal .row.g-3 {
    --bs-gutter-y: 1rem;
    --bs-gutter-x: 1rem;
}

/* Modal Footer */
#withdrawalModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    position: relative !important;
    z-index: 1 !important;
}

@media (max-width: 576px) {
    #withdrawalModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

#withdrawalModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #withdrawalModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
}

#withdrawalModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#withdrawalModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#withdrawalModal .modal-footer .btn-amgc-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    color: white !important;
}

#withdrawalModal .modal-footer .btn-amgc-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* Expense Account dropdown follows Bank Name dropdown style */
#withdrawalExpenseAccountSelect {
    background-color:#ffffff !important;
    border:1px solid #e2e8f0 !important;
    color:#052A47 !important;
    border-radius:10px !important;
    min-height:44px !important;
}
#withdrawalExpenseAccountSelect:focus {
    border-color:#44D34E !important;
    box-shadow:0 0 0 3px rgba(68,211,78,.12) !important;
}
#withdrawalExpenseAccountSelect option {
    background:#ffffff !important;
    color:#052A47 !important;
}

/* Scrollbar */
#withdrawalModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#withdrawalModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#withdrawalModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #withdrawalModal .modal-content {
        max-height: 95vh !important;
    }
    
    #withdrawalModal .modal-body {
        padding: 0.75rem !important;
        max-height: calc(95vh - 100px) !important;
    }
    
    #withdrawalModal .row.g-3 {
        --bs-gutter-y: 0.5rem;
    }
    
    #withdrawalModal .mb-3 {
        margin-bottom: 0.5rem !important;
    }
}
/* Supplier Payment Modal Styles - same style as other modals */
#supplierPaymentModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 900px !important;
}

@media (max-width: 768px) {
    #supplierPaymentModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #supplierPaymentModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#supplierPaymentModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#supplierPaymentModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

@media (max-width: 768px) {
    #supplierPaymentModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#supplierPaymentModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#supplierPaymentModal .modal-header .modal-title i {
    color: white !important;
}

@media (max-width: 576px) {
    #supplierPaymentModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button */
#supplierPaymentModal .modal-header .btn-close {
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

@media (max-width: 576px) {
    #supplierPaymentModal .modal-header .btn-close {
        width: 30px !important;
        height: 30px !important;
    }
}

#supplierPaymentModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
    font-family: system-ui, -apple-system, sans-serif !important;
}

@media (max-width: 576px) {
    #supplierPaymentModal .modal-header .btn-close::before {
        font-size: 0.9rem !important;
    }
}

#supplierPaymentModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#supplierPaymentModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    background: #f8fafc !important;
    max-height: calc(90vh - 130px) !important;
}

@media (max-width: 576px) {
    #supplierPaymentModal .modal-body {
        padding: 1rem !important;
        max-height: calc(90vh - 110px) !important;
    }
}

/* Alert Info Box */
#supplierPaymentModal .alert-light.border {
    background: #f0fdf4 !important;
    border: 1px solid #bbf7d0 !important;
    border-radius: 16px !important;
    color: #047857 !important;
    margin-bottom: 1.25rem !important;
}

#supplierPaymentModal .alert-light.border .small.text-muted {
    color: #047857 !important;
    opacity: 0.8 !important;
}

#supplierPaymentModal .alert-light.border .fw-bold {
    font-size: 0.9rem !important;
}

#supplierPaymentModal .alert-light.border .amount-positive {
    color: #059669 !important;
}

#supplierPaymentModal .alert-light.border .amount-negative {
    color: #dc2626 !important;
}

/* Row inside alert */
#supplierPaymentModal .row-cols-md-5 .col {
    padding: 0.5rem !important;
}

/* Form Styles */
#supplierPaymentModal .form-label {
    font-weight: 600 !important;
    font-size: 0.8rem !important;
    margin-bottom: 0.5rem !important;
    color: #1f2937 !important;
}

#supplierPaymentModal .form-control,
#supplierPaymentModal .form-select {
    border-radius: 10px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 0.6rem 0.75rem !important;
    font-size: 0.85rem !important;
    transition: all 0.2s ease !important;
}

#supplierPaymentModal .form-control:focus,
#supplierPaymentModal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1) !important;
    outline: none !important;
}

#supplierPaymentModal .form-control:disabled,
#supplierPaymentModal .form-control[readonly] {
    background-color: #f1f5f9 !important;
    cursor: not-allowed !important;
}

#supplierPaymentModal .form-text,
#supplierPaymentModal .small.text-muted {
    font-size: 0.7rem !important;
    color: #6c757d !important;
    margin-top: 0.25rem !important;
}

/* Row gaps */
#supplierPaymentModal .row.g-3 {
    --bs-gutter-y: 1rem;
    --bs-gutter-x: 1rem;
}

/* Modal Footer */
#supplierPaymentModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    position: relative !important;
    z-index: 1 !important;
}

@media (max-width: 576px) {
    #supplierPaymentModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

#supplierPaymentModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #supplierPaymentModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
}

#supplierPaymentModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#supplierPaymentModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#supplierPaymentModal .modal-footer .btn-amgc-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    color: white !important;
}

#supplierPaymentModal .modal-footer .btn-amgc-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* Hide/show classes */
#supplierPaymentModal .d-none {
    display: none !important;
}

/* Sub account wrapper */
#subAccountWrapper {
    transition: all 0.3s ease !important;
}

/* Scrollbar */
#supplierPaymentModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#supplierPaymentModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#supplierPaymentModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #supplierPaymentModal .modal-content {
        max-height: 95vh !important;
    }
    
    #supplierPaymentModal .modal-body {
        padding: 0.75rem !important;
        max-height: calc(95vh - 100px) !important;
    }
    
    #supplierPaymentModal .row.g-3 {
        --bs-gutter-y: 0.5rem;
    }
    
    #supplierPaymentModal .alert-light.border {
        margin-bottom: 0.75rem !important;
        padding: 0.5rem !important;
    }
    
    #supplierPaymentModal .row-cols-md-5 .col {
        padding: 0.25rem !important;
    }
}

#supplierBeginningBalanceModal .modal-dialog{margin:1rem auto!important;max-width:900px!important}
#supplierBeginningBalanceModal .modal-content{border:none!important;border-radius:24px!important;overflow:hidden!important;box-shadow:0 20px 40px rgba(0,0,0,.2)!important;max-height:90vh!important;display:flex!important;flex-direction:column!important}
#supplierBeginningBalanceModal .modal-header{background:linear-gradient(135deg,#047857 0%,#44D34E 100%)!important;color:white!important;border-bottom:none!important;padding:1rem 1.25rem!important}
#supplierBeginningBalanceModal .modal-title{font-weight:600!important;font-size:1.1rem!important;display:flex!important;align-items:center!important;gap:8px!important;color:white!important}
#supplierBeginningBalanceModal .btn-close{filter:invert(1);opacity:1}
#supplierBeginningBalanceModal .modal-body{padding:1.5rem!important;overflow-y:auto!important;background:#f8fafc!important}
#supplierBeginningBalanceModal .form-label{font-weight:600!important;font-size:.8rem!important;color:#1f2937!important}
#supplierBeginningBalanceModal .form-control,#supplierBeginningBalanceModal .form-select{border-radius:10px!important;border:1px solid #e2e8f0!important;padding:.6rem .75rem!important;font-size:.85rem!important}
#supplierBeginningBalanceModal .form-control:focus,#supplierBeginningBalanceModal .form-select:focus{border-color:#44D34E!important;box-shadow:0 0 0 3px rgba(68,211,78,.1)!important}
@media(max-width:768px){#supplierBeginningBalanceModal .modal-dialog{margin:.75rem auto!important;max-width:calc(100% - 1.5rem)!important;width:calc(100% - 1.5rem)!important}}


.swal2-popup.amgc-swal-popup{
    border-radius:20px !important;
    border:1px solid rgba(4,120,87,.15) !important;
    box-shadow:0 18px 45px rgba(5,42,71,.18) !important;
}
.swal2-title.amgc-swal-title{
    color:#052A47 !important;
    font-weight:600 !important;
}
.swal2-html-container{
    font-weight:400 !important;
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



/* AMGC searchable dropdown for New Withdrawal */
.amgc-searchable-dropdown{
    position:relative;
}
.amgc-searchable-dropdown .amgc-dropdown-input{
    padding-right:42px !important;
    background:#ffffff !important;
    border:1px solid #d1d5db !important;
    color:#052A47 !important;
}
.amgc-searchable-dropdown .amgc-dropdown-input:focus{
    border-color:#44D34E !important;
    box-shadow:0 0 0 3px rgba(68,211,78,.16) !important;
}
.amgc-dropdown-toggle{
    position:absolute;
    top:50%;
    right:8px;
    transform:translateY(-50%);
    border:0;
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
    color:#fff;
    width:30px;
    height:30px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:.8rem;
    box-shadow:0 3px 8px rgba(4,120,87,.22);
}
.amgc-dropdown-toggle:hover{
    opacity:.95;
}
.amgc-dropdown-menu{
    position:absolute;
    left:0;
    right:0;
    top:calc(100% + 6px);
    z-index:1065;
    display:none;
    max-height:230px;
    overflow-y:auto;
    background:#ffffff;
    border:1px solid rgba(68,211,78,.35);
    border-radius:14px;
    box-shadow:0 16px 32px rgba(5,42,71,.14);
    padding:6px;
}
.amgc-dropdown-menu.show{
    display:block;
}
.amgc-dropdown-item{
    width:100%;
    border:0;
    background:#ffffff;
    color:#052A47;
    text-align:left;
    padding:10px 12px;
    border-radius:10px;
    display:flex;
    flex-direction:column;
    gap:2px;
}
.amgc-dropdown-item:hover,
.amgc-dropdown-item.active{
    background:#e8f5e9;
    color:#047857;
}
.amgc-dropdown-item-title{
    font-weight:700;
    font-size:.88rem;
}
.amgc-dropdown-item small{
    color:#6b7280;
    font-size:.72rem;
}
.amgc-dropdown-empty{
    padding:12px;
    color:#6b7280;
    font-size:.85rem;
    text-align:center;
}
.amgc-dropdown-menu::-webkit-scrollbar{
    width:6px;
}
.amgc-dropdown-menu::-webkit-scrollbar-track{
    background:#f1f5f9;
    border-radius:10px;
}
.amgc-dropdown-menu::-webkit-scrollbar-thumb{
    background:#44D34E;
    border-radius:10px;
}

/* Remove up/down spinner from number inputs */
input[type=number]::-webkit-outer-spin-button,
input[type=number]::-webkit-inner-spin-button{
    -webkit-appearance:none;
    margin:0;
}
input[type=number]{
    -moz-appearance:textfield;
    appearance:textfield;
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
<div class="sidebar-header"><h3><button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list"></i></button><img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"><span class="nav-text">Branch Admin</span></h3></div>
<div class="sidebar-content"><div class="sidebar-menu"><ul class="nav flex-column">
    <li class="nav-item">
                <a class="nav-link" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span class="nav-text">Dashboard</span></a>
            </li>
<li class="nav-item dropdown-nav"><a class="nav-link" href="#" onclick="toggleSidebarDropdown(event,'warehouseMenu')"><i class="bi bi-shop"></i><span class="nav-text">Warehouse</span><i class="bi bi-chevron-down dropdown-arrow"></i></a><div class="collapse" id="warehouseMenu"><ul class="nav flex-column ps-4">
    
    <li><a class="nav-link" href="current_inventory.php"><i class="bi bi-bar-chart-line"></i><span class="nav-text">Current Inventory</span></a></li><li><a class="nav-link" href="bad_orders.php"><i class="bi bi-recycle"></i><span class="nav-text">Bad Orders</span></a></li><li><a class="nav-link" href="pick_list_items.php"><i class="bi bi-list-check"></i><span class="nav-text">Pick List Items</span></a></li>                                <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li></ul></div></li>
<li class="nav-item dropdown-nav"><a class="nav-link" href="#" onclick="toggleSidebarDropdown(event,'supplierMenu')"><i class="bi bi-building"></i><span class="nav-text">Supplier</span><i class="bi bi-chevron-down dropdown-arrow"></i></a><div class="collapse" id="supplierMenu"><ul class="nav flex-column ps-4"><li><a class="nav-link" href="purchase_order.php"><i class="bi bi-box"></i><span class="nav-text">Receive Inventory</span></a></li><li><a class="nav-link" href="supplier.php"><i class="bi bi-people"></i><span class="nav-text">Supplier List</span></a></li></ul></div></li>
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
<li class="nav-item dropdown-nav"><a class="nav-link" href="#" onclick="toggleSidebarDropdown(event,'bankingMenu')"><i class="bi bi-bank2"></i><span class="nav-text">Banking</span><i class="bi bi-chevron-down dropdown-arrow"></i></a><div class="collapse" id="bankingMenu"><ul class="nav flex-column ps-4"><li><a class="nav-link" href="deposit.php"><i class="bi bi-arrow-down-circle"></i><span class="nav-text">Deposit</span></a></li><li><a class="nav-link active" href="Withdrawal.php"><i class="bi bi-arrow-up-circle"></i><span class="nav-text">Withdrawal</span></a></li><li><a class="nav-link" href="bank_statement.php"><i class="bi bi-receipt"></i><span class="nav-text">Banks</span></a></li><li><a class="nav-link" href="expenses.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Expenses</span></a></li></ul></div></li>

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
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Undeposited Funds</div><div class="stat-value">₱<?php echo number_format($undeposited_total, 2); ?></div><div class="page-note"><?php echo count($available_payments); ?> payment(s) waiting</div></div><div class="stat-icon"><i class="bi bi-wallet2"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Total Collections</div><div class="stat-value">₱<?php echo number_format($total_collections, 2); ?></div><div class="page-note">Latest recorded payments</div></div><div class="stat-icon"><i class="bi bi-cash-coin"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Supplier Payables</div><div class="stat-value">₱<?php echo number_format($total_supplier_payables, 2); ?></div><div class="page-note"><?php echo count($supplier_payables); ?> received PO(s) unpaid</div></div><div class="stat-icon"><i class="bi bi-building"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Bank Balance</div><div class="stat-value">₱<?php echo number_format($bank_balance, 2); ?></div><div class="page-note">From actual bank accounts</div></div><div class="stat-icon"><i class="bi bi-bank"></i></div></div></div>
</div>

<div class="section-card"><div class="section-header">
    <div><h5 class="mb-1">Transactions</h5><div class="page-note">Manage payables and view all payment activities.</div></div>
    <div>
        <button type="button" class="btn btn-amgc-dark btn-sm me-2" data-bs-toggle="modal" data-bs-target="#supplierBeginningBalanceModal" id="addSupplierBeginningBalanceBtn">
            <i class="bi bi-journal-plus me-1"></i> Add Beginning Balance
        </button>
        <button type="button" class="btn btn-amgc-dark btn-sm" data-bs-toggle="modal" data-bs-target="#withdrawalModal" id="newWithdrawalBtn">
            <i class="bi bi-plus-circle me-1"></i> New Withdrawal
        </button>
    </div>
</div><div class="section-body">
<ul class="nav nav-tabs mb-3">
   <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#payablesTab" type="button">Payables</button></li>
   <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#paymentHistoryTab" type="button">Payment History</button></li>
</ul>
<div class="tab-content">

<div class="tab-pane fade show active" id="payablesTab">
   <div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="mb-1">Supplier Payables</h5><div class="page-note">Payables are generated from received purchase orders.</div></div></div>
   <div class="table-responsive">
       <table class="table table-hover align-middle">
           <thead>
                <tr><th>Supplier</th><th>PO Number</th><th>Received Date</th><th>Total Amount</th><th>Paid</th><th>Balance</th><th>Action</th></tr>
           </thead>
           <tbody>
           <?php if (empty($supplier_payables) && empty($supplier_beginning_balance_payables)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No supplier payables found.</td></tr>
           <?php else: foreach ($supplier_payables as $p): 
                $payableDetails = [
                    'date' => !empty($p['order_date']) ? date('F d, Y', strtotime($p['order_date'])) : '—',
                    'type' => 'Supplier Payable',
                    'supplier' => $p['display_supplier_name'] ?? '—',
                    'partner' => $p['display_supplier_name'] ?? '—',
                    'po_number' => $p['po_number'] ?? '—',
                    'received_date' => !empty($p['order_date']) ? date('F d, Y', strtotime($p['order_date'])) : '—',
                    'expected_delivery' => !empty($p['expected_delivery']) ? date('F d, Y', strtotime($p['expected_delivery'])) : '—',
                    'po_status' => $p['po_status'] ?? '—',
                    'supplier_id' => $p['supplier_id'] ?? '—',
                    'total_amount' => number_format((float)$p['total_amount'], 2),
                    'paid_amount' => number_format((float)$p['paid_amount'], 2),
                    'balance' => number_format((float)$p['balance'], 2),
                    'status' => 'Payable'
                ];
                $payableDetailsB64 = base64_encode(json_encode($payableDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
              <tr class="clickable-payable-row" role="button" tabindex="0" title="Click to view payable details" data-details-b64="<?php echo h($payableDetailsB64); ?>">
                   <td><b><?php echo h($p['display_supplier_name']); ?></b></td>
                   <td><?php echo h($p['po_number']); ?></td>
                   <td><?php echo !empty($p['order_date']) ? date('M d, Y', strtotime($p['order_date'])) : '-'; ?></td>
                   <td class="amount-neutral">₱<?php echo number_format((float)$p['total_amount'], 2); ?></td>
                   <td class="amount-positive">₱<?php echo number_format((float)$p['paid_amount'], 2); ?></td>
                   <td class="amount-negative">₱<?php echo number_format((float)$p['balance'], 2); ?></td>
                   <td><button type="button" class="btn btn-sm btn-amgc-primary pay-btn" onclick='event.stopPropagation(); openSupplierPaymentModal(<?php echo json_encode(["po_id" => (int)$p["po_id"], "po_number" => $p["po_number"], "supplier_name" => $p["display_supplier_name"], "invoice_amount" => (float)$p["total_amount"], "payments_made" => (float)$p["paid_amount"], "balance" => (float)$p["balance"]], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="bi bi-cash-coin"></i> Pay</button></td>
              </tr>
           <?php endforeach; ?>
           <?php foreach ($supplier_beginning_balance_payables as $bb): 
                $bbAttachments = getSupplierBeginningBalanceAttachments($conn, (int)$bb['bb_id']);
                $bbAttachmentDetails = [];
                foreach ($bbAttachments as $att) {
                    $bbAttachmentDetails[] = [
                        'name' => $att['file_name'] ?? 'Attachment',
                        'path' => $att['file_path'] ?? '',
                        'type' => $att['file_type'] ?? '',
                        'size' => (int)($att['file_size'] ?? 0)
                    ];
                }
                $bbDetails = [
                    'source' => 'beginning_balance',
                    'date' => !empty($bb['document_date']) ? date('F d, Y', strtotime($bb['document_date'])) : '—',
                    'type' => 'Supplier Beginning Balance',
                    'supplier' => $bb['supplier_name'] ?? '—',
                    'document_no' => $bb['document_no'] ?? '—',
                    'document_date' => !empty($bb['document_date']) ? date('F d, Y', strtotime($bb['document_date'])) : '—',
                    'amount' => number_format((float)$bb['amount'], 2),
                    'remarks' => trim($bb['remarks'] ?? '') !== '' ? $bb['remarks'] : '—',
                    'attachments' => $bbAttachmentDetails
                ];
                $bbDetailsB64 = base64_encode(json_encode($bbDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
              <tr class="clickable-payable-row" role="button" tabindex="0" title="Click to view payable details" data-details-b64="<?php echo h($bbDetailsB64); ?>">
                   <td><b><?php echo h($bb['supplier_name']); ?></b></td>
                   <td><?php echo h($bb['document_no']); ?></td>
                   <td><?php echo !empty($bb['document_date']) ? date('M d, Y', strtotime($bb['document_date'])) : '-'; ?></td>
                   <td class="amount-neutral">₱<?php echo number_format((float)$bb['amount'], 2); ?></td>
                   <td class="amount-positive">₱<?php echo number_format((float)$bb['paid_amount'], 2); ?></td>
                   <td class="amount-negative">₱<?php echo number_format((float)$bb['balance'], 2); ?></td>
                   <td><button type="button" class="btn btn-sm btn-amgc-primary pay-btn" onclick='event.stopPropagation(); openSupplierPaymentModal(<?php echo json_encode(["payable_type" => "beginning_balance", "beginning_balance_id" => (int)$bb["bb_id"], "po_id" => 0, "po_number" => $bb["document_no"], "supplier_name" => $bb["supplier_name"], "invoice_amount" => (float)$bb["amount"], "payments_made" => (float)$bb["paid_amount"], "balance" => (float)$bb["balance"]], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="bi bi-cash-coin"></i> Pay</button></td>
              </tr>
           <?php endforeach; endif; ?>
           </tbody>
        </table>
   </div>
</div>

<div class="tab-pane fade" id="paymentHistoryTab">
   <h5 class="mb-3">Complete Payment History</h5>
   <div class="table-responsive">
       <table class="table table-hover align-middle">
           <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Partner / Bank</th>
                    <th>Reference</th>
                    <th>Expense Account</th>
                    <th>Payee</th>
                    <th>Amount</th>
                   </tr>
           </thead>
           <tbody>
           <?php if (empty($payment_history_combined)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No payment history found.瑞</td></tr>
           <?php else: 
                foreach ($payment_history_combined as $entry): 
                    $modalDetails = [
                        'date' => date('F d, Y', strtotime($entry['date'])),
                        'type' => $entry['type'],
                        'partner' => $entry['partner'],
                        'reference' => $entry['reference'],
                        'description' => $entry['description'],
                        'expense_account' => $entry['expense_account'],
                        'payee' => $entry['payee'],
                        'amount' => number_format($entry['amount'], 2),
                        'encoded_by' => $entry['encoded_by']
                    ];
                    if (strpos($entry['type'], 'Supplier') === 0) {
                        $modalDetails['po_number'] = $entry['po_number'] ?? '';
                        $modalDetails['payment_method'] = $entry['payment_method'] ?? '';
                        $modalDetails['reference_number'] = $entry['reference_number'] ?? '';
                        $modalDetails['check_number'] = $entry['check_number'] ?? '';
                        $modalDetails['check_date'] = $entry['check_date'] ?? '';
                        $modalDetails['bank_name'] = $entry['bank_name'] ?? '';
                    } else {
                        $modalDetails['bank_name_full'] = $entry['bank_name_full'] ?? $entry['partner'];
                        $modalDetails['expense_account_full'] = $entry['expense_account_full'] ?? $entry['expense_account'];
                        $modalDetails['payee_full'] = $entry['payee_full'] ?? $entry['payee'];
                        $modalDetails['transaction_id'] = $entry['transaction_id'] ?? '';
                    }
                    $jsonDetails = base64_encode(json_encode($modalDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                ?>
                   <tr class="clickable-row clickable-transaction-row" role="button" tabindex="0" title="Click to view full transaction details" data-details-b64="<?php echo h($jsonDetails); ?>">
                        <td><?php echo date('M d, Y', strtotime($entry['date'])); ?></td>
                        <td>
                           <?php if (strpos($entry['type'], 'Supplier') === 0): ?>
                               <span class="badge badge-soft-green px-2 py-1"><?php echo h($entry['type']); ?></span>
                           <?php else: ?>
                               <span class="badge badge-soft-red px-2 py-1"><?php echo h($entry['type']); ?></span>
                           <?php endif; ?>
                        </span>
                        </td>
                        <td><?php echo h($entry['partner']); ?></td>
                        <td><?php echo h($entry['reference']); ?></td>
                        <td><?php echo h($entry['expense_account']); ?></td>
                        <td><?php echo h($entry['payee']); ?></td>
                        <td class="amount-negative">₱<?php echo number_format($entry['amount'], 2); ?></td>
                     </tr>
           <?php endforeach; endif; ?>
           </tbody>
        </table>
   </div>
</div>

</div></div></div>
</div></div></div>

<!-- Modal NEW WITHDRAWAL -->
<div class="modal fade" id="withdrawalModal" tabindex="-1" aria-hidden="true">
   <div class="modal-dialog modal-dialog-centered modal-lg">
       <form method="POST" action="Withdrawal.php" class="modal-content" id="withdrawalForm">
           <input type="hidden" name="action" value="create_withdrawal">
           <div class="modal-header"><h5 class="modal-title"><i class="bi bi-arrow-up-circle me-2"></i>New Withdrawal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
           <div class="modal-body">
               <div class="row g-3">
                   <div class="col-md-6">
                       <div class="mb-3"><label class="form-label fw-bold">Date</label><input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                       <div class="mb-3"><label class="form-label fw-bold">Bank Name</label>
                           <select name="bank_id" id="withdrawalBankSelect" class="form-select" required>
                               <option value="">Select account</option>
                               <?php foreach ($banks as $bank): ?>
                                   <option value="<?php echo (int)$bank['bank_id']; ?>" data-balance="<?php echo isset($bank_balance_by_id[$bank['bank_id']]) ? (float)$bank_balance_by_id[$bank['bank_id']] : 0; ?>">
                                       <?php echo h((!empty($bank['parent_bank_name']) ? $bank['parent_bank_name'] . ' / ' : '') . $bank['bank_name'] . (!empty($bank['account_number']) ? ' - ' . $bank['account_number'] : '')); ?>
                                   </option>
                               <?php endforeach; ?>
                           </select>
                           <div class="small text-muted mt-1" id="withdrawalBankBalance">Current Balance: ₱0.00</div>
                       </div>
                       <div class="mb-3">
                           <label class="form-label fw-bold">Expense Account</label>
                           <select name="expense_account" id="withdrawalExpenseAccountSelect" class="form-select" required>
                               <option value="">Select expense account</option>
                               <?php foreach ($expense_accounts as $exp): ?>
                                   <option value="<?php echo h($exp['label']); ?>" data-description="<?php echo h($exp['description']); ?>">
                                       <?php echo h($exp['label']); ?>
                                   </option>
                               <?php endforeach; ?>
                           </select>
                           <div class="small text-muted mt-1">Only accounts recorded in Expenses are allowed.</div>
                       </div>
                       <div class="mb-3"><label class="form-label fw-bold">Amount</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required></div>
                   </div>
                   <div class="col-md-6">
                       <div class="mb-3"><label class="form-label fw-bold">Reference No.</label><input type="text" name="reference_number" class="form-control" placeholder="Check / transfer reference"></div>
                       <div class="mb-3"><label class="form-label fw-bold">Paying (Payee)</label><input type="text" name="payee" class="form-control" placeholder="Name of recipient" required></div>
                       <div class="mb-3"><label class="form-label fw-bold">Description</label><textarea name="description" id="withdrawalDescription" class="form-control" rows="4" placeholder="Auto-filled from selected expense account" required></textarea></div>
                   </div>
               </div>
           </div>
           <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-amgc-primary"><i class="bi bi-check-circle me-1"></i>Save Withdrawal</button></div>
       </form>
   </div>
</div>

<!-- MODAL FOR SUPPLIER PAYMENT -->

<div class="modal fade" id="supplierBeginningBalanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST" action="Withdrawal.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_supplier_beginning_balance">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-journal-plus me-2"></i>Add Supplier Beginning Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Supplier Name</label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">Select supplier</option>
                            <?php foreach ($supplier_beginning_balance_options as $supplier): ?>
                                <option value="<?php echo (int)$supplier['supplier_id']; ?>"><?php echo h($supplier['supplier_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Document No.</label>
                        <input type="text" name="document_no" class="form-control" placeholder="Enter document number" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Date</label>
                        <input type="date" name="document_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Attachments</label>
                        <input type="file" name="attachments[]" class="form-control" multiple required accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                        <div class="small text-muted mt-1">You can upload multiple files. Maximum 10MB per file.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Enter remarks"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-amgc-primary"><i class="bi bi-check-circle me-1"></i>Save Beginning Balance</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="supplierPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST" action="Withdrawal.php">
            <input type="hidden" name="action" value="record_supplier_payment">
            <input type="hidden" name="payable_type" id="supplier_payable_type" value="po">
            <input type="hidden" name="po_id" id="supplier_po_id">
            <input type="hidden" name="beginning_balance_id" id="supplier_beginning_balance_id" value="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Record Supplier Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-5 g-3">
                        <div class="col"><div class="small text-muted fw-bold">Supplier</div><div class="fw-bold" id="supplier_name_label">-</div></div>
                        <div class="col"><div class="small text-muted fw-bold">Document / PO</div><div class="fw-bold" id="supplier_po_label">-</div></div>
                        <div class="col"><div class="small text-muted fw-bold">Invoice Amount</div><div class="fw-bold" id="supplier_invoice_amount_label">₱0.00</div></div>
                        <div class="col"><div class="small text-muted fw-bold">Payments Made</div><div class="fw-bold amount-positive" id="supplier_payments_made_label">₱0.00</div></div>
                        <div class="col"><div class="small text-muted fw-bold">Outstanding Balance</div><div class="fw-bold amount-negative" id="supplier_outstanding_balance_label">₱0.00</div></div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Select Bank Account</label>
                        <select name="supplier_bank_id" id="supplierBankSelect" class="form-select" required>
                            <option value="">Select account</option>
                            <?php foreach ($banks as $bank): ?>
                            <option value="<?php echo (int)$bank['bank_id']; ?>" 
                                    data-bank-name="<?php echo h((!empty($bank['parent_bank_name']) ? $bank['parent_bank_name'] . ' / ' : '') . $bank['bank_name'] . (!empty($bank['account_number']) ? ' - ' . $bank['account_number'] : '')); ?>"
                                    data-bank-branch="<?php echo h($bank['bank_branch'] ?? ''); ?>"
                                    data-balance="<?php echo isset($bank_balance_by_id[$bank['bank_id']]) ? (float)$bank_balance_by_id[$bank['bank_id']] : 0; ?>">
                                <?php echo h((!empty($bank['parent_bank_name']) ? $bank['parent_bank_name'] . ' / ' : '') . $bank['bank_name'] . (!empty($bank['account_number']) ? ' - ' . $bank['account_number'] : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1" id="bankBalanceDisplay">Current Balance: ₱0.00</div>
                    </div>
                    <div class="col-md-6" id="subAccountWrapper" style="display:none;">
                        <label class="form-label fw-bold">Sub Account</label>
                        <select name="sub_account_id" id="subAccountSelect" class="form-select">
                            <option value="">-- No sub account needed --</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Payment Method</label>
                        <select name="payment_method" id="paymentMethodSelect" class="form-select" required disabled>
                            <option value="">-- Select bank first --</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="supplier_payment_amount" class="form-control" required>
                    </div>
                    
                    <div class="col-md-6 supplier-cash-fields d-none">
                        <label class="form-label fw-bold">Cash Source</label>
                        <input type="text" class="form-control" value="Cash on Hand" readonly>
                    </div>
                    
                    <div class="col-md-6 supplier-check-fields d-none">
                        <label class="form-label fw-bold">Check Date</label>
                        <input type="date" name="check_date" class="form-control">
                    </div>
                    <div class="col-md-6 supplier-check-fields d-none">
                        <label class="form-label fw-bold">Check Number</label>
                        <input type="text" name="check_number" class="form-control">
                    </div>
                    <div class="col-md-6 supplier-check-fields d-none">
                        <label class="form-label fw-bold">Bank Branch</label>
                        <input type="text" id="bankBranchAutoFill" class="form-control" readonly>
                    </div>
                    
                    <div class="col-md-6 supplier-online-fields d-none">
                        <label class="form-label fw-bold">Reference Number</label>
                        <input type="text" name="reference_number" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-amgc-primary"><i class="bi bi-check-circle me-1"></i>Save Payment</button>
            </div>
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


<!-- MODAL FOR PAYMENT DETAILS -->
<div class="modal fade" id="paymentDetailsModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:20px; border:none;">
<div class="modal-header" style="background:linear-gradient(135deg,#047857 0%,#44D34E 100%); color:white; border-radius:20px 20px 0 0;">
<h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Payment Details</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-4">
    <div id="modalPaymentContent">Loading...</div>
</div>
<div class="modal-footer border-0">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const bankBalances = <?php echo json_encode($bank_balance_by_id); ?>;
const bankSubAccounts = <?php 
    $subMap = [];
    foreach ($banks as $bank) {
        $subs = getSubAccounts($conn, $bank['bank_id']);
        $subMap[$bank['bank_id']] = $subs;
    }
    echo json_encode($subMap);
?>;
const bankPaymentMethods = <?php 
    $pmMap = [];
    foreach ($all_banks_for_mapping as $bank) {
        $pmMap[$bank['bank_id']] = $bank['payment_methods'];
    }
    echo json_encode($pmMap);
?>;
const bankBranchMap = <?php 
    $branchMap = [];
    foreach ($all_banks_for_mapping as $bank) {
        $branchMap[$bank['bank_id']] = $bank['bank_branch'] ?? '';
    }
    echo json_encode($branchMap);
?>;

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


function populateSubAccounts(bankId) {
    const subWrapper = document.getElementById('subAccountWrapper');
    const subSelect = document.getElementById('subAccountSelect');
    if (!subWrapper || !subSelect) return;
    const subs = bankSubAccounts[bankId] || [];
    subSelect.innerHTML = '<option value="">-- No sub account needed --</option>';
    subs.forEach(sub => {
        const option = document.createElement('option');
        option.value = sub.bank_id;
        option.textContent = sub.bank_name + (sub.account_number ? ' - ' + sub.account_number : '');
        subSelect.appendChild(option);
    });
    subWrapper.style.display = subs.length ? 'block' : 'none';
}

function populatePaymentMethods(bankId) {
    const paymentSelect = document.getElementById('paymentMethodSelect');
    const methods = bankPaymentMethods[bankId] || [];
    paymentSelect.innerHTML = '<option value="">-- Select payment method --</option>';
    if (methods.length) {
        paymentSelect.disabled = false;
        methods.forEach(method => {
            let label = '';
            if (method === 'check') label = 'Check';
            else if (method === 'online_transfer') label = 'Online Transfer';
            else label = 'Cash';
            const option = document.createElement('option');
            option.value = method;
            option.textContent = label;
            paymentSelect.appendChild(option);
        });
    } else {
        paymentSelect.disabled = true;
        paymentSelect.innerHTML = '<option value="">No payment methods defined</option>';
    }
}

function updateFieldsAfterBankSelection() {
    const bankSelect = document.getElementById('supplierBankSelect');
    const selectedOption = bankSelect.options[bankSelect.selectedIndex];
    const bankId = selectedOption ? parseInt(selectedOption.value) : null;
    if (!bankId) {
        document.getElementById('subAccountWrapper').style.display = 'none';
        document.getElementById('paymentMethodSelect').innerHTML = '<option value="">-- Select bank first --</option>';
        document.getElementById('paymentMethodSelect').disabled = true;
        document.getElementById('bankBalanceDisplay').textContent = 'Current Balance: ₱0.00';
        return;
    }
    const balance = selectedOption.getAttribute('data-balance') ? parseFloat(selectedOption.getAttribute('data-balance')) : 0;
    document.getElementById('bankBalanceDisplay').textContent = 'Current Balance: ₱' + balance.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    populateSubAccounts(bankId);
    populatePaymentMethods(bankId);
    document.querySelectorAll('.supplier-cash-fields, .supplier-check-fields, .supplier-online-fields').forEach(el => el.classList.add('d-none'));
    document.getElementById('bankBranchAutoFill').value = bankBranchMap[bankId] || '';
}

function updateFieldsAfterSubAccountChange() {
    const bankSelect = document.getElementById('supplierBankSelect');
    const subSelect = document.getElementById('subAccountSelect');
    const selectedBankId = bankSelect.value ? parseInt(bankSelect.value) : null;
    const selectedSubId = subSelect.value ? parseInt(subSelect.value) : null;
    const actualBankId = selectedSubId ? selectedSubId : selectedBankId;
    if (actualBankId) {
        populatePaymentMethods(actualBankId);
        const branch = bankBranchMap[actualBankId] || '';
        const balance = bankBalances[actualBankId] ? parseFloat(bankBalances[actualBankId]) : 0;
        document.getElementById('bankBalanceDisplay').textContent = 'Current Balance: ₱' + balance.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById('bankBranchAutoFill').value = branch;
    } else {
        document.getElementById('bankBalanceDisplay').textContent = 'Current Balance: ₱0.00';
    }
    document.querySelectorAll('.supplier-cash-fields, .supplier-check-fields, .supplier-online-fields').forEach(el => el.classList.add('d-none'));
}

function attachPaymentMethodListener() {
    const paymentSelect = document.getElementById('paymentMethodSelect');
    if (!paymentSelect) return;
    paymentSelect.addEventListener('change', function() {
        const selectedMethod = this.value;
        document.querySelectorAll('.supplier-cash-fields, .supplier-check-fields, .supplier-online-fields').forEach(el => el.classList.add('d-none'));
        if (selectedMethod === 'check') {
            document.querySelectorAll('.supplier-check-fields').forEach(el => el.classList.remove('d-none'));
        } else if (selectedMethod === 'online_transfer') {
            document.querySelectorAll('.supplier-online-fields').forEach(el => el.classList.remove('d-none'));
        } else if (selectedMethod === 'cash') {
            document.querySelectorAll('.supplier-cash-fields').forEach(el => el.classList.remove('d-none'));
        }
    });
}


const WITHDRAWAL_EXPENSE_ACCOUNTS = <?php echo json_encode($expense_accounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function getSelectedExpenseAccountOption() {
    const select = document.getElementById('withdrawalExpenseAccountSelect');
    if (!select || !select.value) return null;
    const selectedValue = String(select.value || '').trim().toLowerCase();
    return WITHDRAWAL_EXPENSE_ACCOUNTS.find(item => String(item.label || '').trim().toLowerCase() === selectedValue) || null;
}

function autoFillWithdrawalDescription() {
    const select = document.getElementById('withdrawalExpenseAccountSelect');
    const descriptionInput = document.getElementById('withdrawalDescription');
    if (!select || !descriptionInput) return;

    const selectedOption = select.options[select.selectedIndex];
    const description = selectedOption ? (selectedOption.getAttribute('data-description') || '') : '';
    descriptionInput.value = description;
}

function validateWithdrawalExpenseAccount(e) {
    const select = document.getElementById('withdrawalExpenseAccountSelect');
    if (!select) return true;

    const selected = getSelectedExpenseAccountOption();
    if (!selected) {
        e.preventDefault();
        amgcSwalFire({
            icon: 'error',
            title: 'Invalid Expense Account',
            text: 'Please select a recorded expense account from Expenses.'
        });
        select.focus();
        return false;
    }

    select.value = selected.label;
    const descriptionInput = document.getElementById('withdrawalDescription');
    if (descriptionInput && !descriptionInput.value.trim()) {
        descriptionInput.value = selected.description || '';
    }
    return true;
}

function updateWithdrawalBankBalance() {
    const bankSelect = document.getElementById('withdrawalBankSelect');
    const selectedOption = bankSelect.options[bankSelect.selectedIndex];
    const balance = selectedOption && selectedOption.value ? (parseFloat(selectedOption.getAttribute('data-balance')) || 0) : 0;
    document.getElementById('withdrawalBankBalance').textContent = 'Current Balance: ₱' + balance.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
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

function peso(n){return '₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}

function openSupplierPaymentModal(data){
    document.getElementById('supplier_payable_type').value = data.payable_type || 'po';
    document.getElementById('supplier_po_id').value = data.po_id || '';
    document.getElementById('supplier_beginning_balance_id').value = data.beginning_balance_id || '';
    document.getElementById('supplier_name_label').textContent=data.supplier_name;
    document.getElementById('supplier_po_label').textContent=data.po_number;
    document.getElementById('supplier_invoice_amount_label').textContent=peso(data.invoice_amount||0);
    document.getElementById('supplier_payments_made_label').textContent=peso(data.payments_made||0);
    document.getElementById('supplier_outstanding_balance_label').textContent=peso(data.balance||0);
    document.getElementById('supplier_payment_amount').value=Number(data.balance||0).toFixed(2);
    
    const bankSelect = document.getElementById('supplierBankSelect');
    if (bankSelect) bankSelect.value = '';
    updateFieldsAfterBankSelection();
    const subSelect = document.getElementById('subAccountSelect');
    if (subSelect) subSelect.innerHTML = '<option value="">-- No sub account needed --</option>';
    document.getElementById('subAccountWrapper').style.display = 'none';
    
    new bootstrap.Modal(document.getElementById('supplierPaymentModal')).show();
}

function escapeHtml(str) {
    if (str === null || str === undefined || str === '') return '—';
    return String(str).replace(/[&<>"']/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        if (m === '"') return '&quot;';
        if (m === "'") return '&#039;';
        return m;
    });
}

function decodeRowDetails(row) {
    if (!row) return null;
    const encoded = row.getAttribute('data-details-b64');
    if (encoded) {
        try {
            const json = decodeURIComponent(Array.prototype.map.call(atob(encoded), function(c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            return JSON.parse(json);
        } catch (err) {
            try { return JSON.parse(atob(encoded)); } catch (err2) { console.error('Invalid row details:', err2); }
        }
    }

    const raw = row.getAttribute('data-details');
    if (raw) {
        try { return JSON.parse(raw); } catch (err) { console.error('Invalid row details:', err); }
    }
    return null;
}

function detailItem(label, value, extraClass = '') {
    return `<div class="transaction-detail-item">
        <div class="transaction-detail-label">${escapeHtml(label)}</div>
        <div class="transaction-detail-value ${extraClass}">${escapeHtml(value)}</div>
    </div>`;
}

function formatFileSize(bytes) {
    const size = Number(bytes || 0);
    if (!size) return '';
    if (size < 1024) return size + ' B';
    if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB';
    return (size / (1024 * 1024)).toFixed(1) + ' MB';
}

function attachmentsDetailItem(attachments) {
    let files = Array.isArray(attachments) ? attachments : [];
    let content = '<span class="text-muted">No attachments</span>';
    if (files.length > 0) {
        content = files.map(function(file, index) {
            const name = escapeHtml(file.name || ('Attachment ' + (index + 1)));
            const path = escapeHtml(file.path || '#');
            const size = formatFileSize(file.size);
            const meta = size ? `<small class="text-muted ms-1">(${escapeHtml(size)})</small>` : '';
            return `<div class="mb-1"><a href="${path}" target="_blank" class="text-decoration-none fw-bold" onclick="event.stopPropagation();"><i class="bi bi-paperclip me-1"></i>${name}</a>${meta}</div>`;
        }).join('');
    }
    return `<div class="transaction-detail-item" style="grid-column:1/-1">
        <div class="transaction-detail-label">Attachments</div>
        <div class="transaction-detail-value">${content}</div>
    </div>`;
}

function showPaymentDetails(details) {
    const container = document.getElementById('modalPaymentContent');
    const modalElement = document.getElementById('paymentDetailsModal');
    if (!container || !modalElement || !details) return;

    let titleText = 'Transaction Details';
    let mainName = details.partner || details.supplier || details.bank_name_full || details.bank_name || 'Transaction';
    let amountText = '';

    if (details.source === 'beginning_balance' || details.type === 'Supplier Beginning Balance') {
        titleText = 'Supplier Beginning Balance Details';
        amountText = '₱' + (details.amount || '0.00');
    } else if (details.type === 'Supplier Payable') {
        titleText = 'Supplier Payable Details';
        amountText = '₱' + (details.balance || '0.00');
    } else {
        titleText = 'Payment Details';
        amountText = '₱' + (details.amount || '0.00');
    }

    const modalTitle = modalElement.querySelector('.modal-title');
    if (modalTitle) modalTitle.innerHTML = `<i class="bi bi-receipt me-2"></i>${escapeHtml(titleText)}`;

    let html = `
        <div class="transaction-summary-card">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div class="text-muted fw-bold small">${escapeHtml(details.type)}</div>
                    <h5 class="mb-1" style="color:#052A47">${escapeHtml(mainName)}</h5>
                    <div class="text-muted">${escapeHtml(details.date || details.received_date)}</div>
                </div>
                <div class="text-end">
                    <div class="text-muted fw-bold small">${details.type === 'Supplier Payable' ? 'Balance' : 'Amount'}</div>
                    <div class="amount-negative fw-bold fs-5">${escapeHtml(amountText)}</div>
                </div>
            </div>
        </div>
        <div class="transaction-detail-grid">
    `;

    if (details.source === 'beginning_balance' || details.type === 'Supplier Beginning Balance') {
        html += `
            ${detailItem('Supplier Name', details.supplier)}
            ${detailItem('Document No.', details.document_no)}
            ${detailItem('Date', details.document_date || details.date)}
            ${detailItem('Amount', '₱' + (details.amount || '0.00'), 'amount-negative')}
            ${detailItem('Remarks', details.remarks)}
            ${attachmentsDetailItem(details.attachments)}
        `;
    } else if (details.type === 'Supplier Payable') {
        html += `
            ${detailItem('Supplier', details.supplier)}
            ${detailItem('PO Number', details.po_number)}
            ${detailItem('Received Date', details.received_date)}
            ${detailItem('Expected Delivery', details.expected_delivery)}
            ${detailItem('Total Amount', '₱' + (details.total_amount || '0.00'), 'amount-neutral')}
            ${detailItem('Paid', '₱' + (details.paid_amount || '0.00'), 'amount-positive')}
            ${detailItem('Balance', '₱' + (details.balance || '0.00'), 'amount-negative')}
            ${detailItem('Status', details.status)}
            ${detailItem('PO Status', details.po_status)}
            ${detailItem('Supplier ID', details.supplier_id)}
        `;
    } else {
        html += `
            ${detailItem('Date', details.date)}
            ${detailItem('Type', details.type)}
            ${detailItem('Partner / Bank', details.partner)}
            ${detailItem('Reference Number', details.reference)}
            ${detailItem('Description', details.description)}
            ${detailItem('Expense Account', details.expense_account || details.expense_account_full)}
            ${detailItem('Payee', details.payee || details.payee_full)}
            ${detailItem('Amount', '₱' + (details.amount || '0.00'), 'amount-negative')}
            ${detailItem('Encoded By', details.encoded_by)}
        `;

        if (details.type === 'Supplier Payment') {
            html += `
                ${detailItem('PO Number', details.po_number)}
                ${detailItem('Payment Method', details.payment_method)}
                ${detailItem('Reference #', details.reference_number)}
                ${detailItem('Check Number', details.check_number)}
                ${detailItem('Check Date', details.check_date)}
                ${detailItem('Bank Name', details.bank_name)}
            `;
        } else {
            html += `
                ${detailItem('Transaction ID', details.transaction_id)}
                ${detailItem('Bank Name', details.bank_name_full || details.partner)}
                ${detailItem('Expense Account Full', details.expense_account_full || details.expense_account)}
                ${detailItem('Payee Full', details.payee_full || details.payee)}
            `;
        }
    }

    html += '</div>';
    container.innerHTML = html;
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function openDetailsRow(row) {
    const details = decodeRowDetails(row);
    if (details) showPaymentDetails(details);
}

function attachWithdrawalDetailsModalHandlers() {
    document.addEventListener('click', function(e) {
        if (e.target.closest('button, a, input, select, textarea, label')) return;
        const row = e.target.closest('.clickable-row[data-details-b64], .clickable-row[data-details], .clickable-payable-row[data-details-b64]');
        if (row) openDetailsRow(row);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const row = e.target.closest('.clickable-row[data-details-b64], .clickable-row[data-details], .clickable-payable-row[data-details-b64]');
        if (row) {
            e.preventDefault();
            openDetailsRow(row);
        }
    });
}

attachWithdrawalDetailsModalHandlers();

document.addEventListener('DOMContentLoaded',function(){
    if (flashSuccessMessage) {
        amgcSwalFire({
            icon: 'success',
            title: 'Success',
            text: flashSuccessMessage,
            confirmButtonText: 'OK'
        });
    }

    if (flashErrorMessage) {
        amgcSwalFire({
            icon: 'error',
            title: 'Error',
            text: flashErrorMessage,
            confirmButtonText: 'OK'
        });
    }

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
    
    const bankSelect = document.getElementById('supplierBankSelect');
    if (bankSelect) {
        bankSelect.addEventListener('change', updateFieldsAfterBankSelection);
    }
    const subSelect = document.getElementById('subAccountSelect');
    if (subSelect) {
        subSelect.addEventListener('change', updateFieldsAfterSubAccountChange);
    }
    attachPaymentMethodListener();

    const withdrawalBankSelect = document.getElementById('withdrawalBankSelect');
    if (withdrawalBankSelect) {
        withdrawalBankSelect.addEventListener('change', updateWithdrawalBankBalance);
        updateWithdrawalBankBalance();
    }

    const withdrawalExpenseSelect = document.getElementById('withdrawalExpenseAccountSelect');
    if (withdrawalExpenseSelect) {
        withdrawalExpenseSelect.addEventListener('change', autoFillWithdrawalDescription);
        autoFillWithdrawalDescription();
    }

    const withdrawalForm = document.getElementById('withdrawalForm');
    if (withdrawalForm) {
        withdrawalForm.addEventListener('submit', validateWithdrawalExpenseAccount);
    }
});
</script>
</body>
</html>