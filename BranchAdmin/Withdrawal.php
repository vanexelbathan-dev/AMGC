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

$task_badge_count = 0;

if (isset($conn) && !empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];

    $taskBadgeStmt = $conn->prepare("
        SELECT COUNT(DISTINCT t.task_id) AS total
        FROM user_tasks t
        INNER JOIN user_task_assignees a
            ON a.task_id = t.task_id
        WHERE a.user_id = ?
          AND a.assignee_status NOT IN ('completed', 'cancelled')
          AND NOW() >= DATE_SUB(
              t.due_datetime,
              INTERVAL COALESCE(t.reminder_days, 0) DAY
          )
    ");

    if ($taskBadgeStmt) {
        $taskBadgeStmt->bind_param('i', $uid);
        $taskBadgeStmt->execute();

        $taskBadgeResult = $taskBadgeStmt->get_result();
        $taskBadgeRow = $taskBadgeResult->fetch_assoc();

        $task_badge_count = (int) ($taskBadgeRow['total'] ?? 0);

        $taskBadgeStmt->close();
    }
}

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

function buildWithdrawalCoaDropdownTree(array $accounts): array {
    $byId = [];
    $children = [];
    $rootAccounts = [];

    foreach ($accounts as $account) {
        $id = (int)($account['account_id'] ?? $account['bank_id'] ?? $account['id'] ?? 0);
        if ($id <= 0) continue;
        $account['_id'] = $id;
        $account['_parent_id'] = (int)($account['parent_account_id'] ?? 0);
        $byId[$id] = $account;
    }

    foreach ($byId as $id => $account) {
        $parentId = (int)($account['_parent_id'] ?? 0);
        if ($parentId > 0 && isset($byId[$parentId])) {
            if (!isset($children[$parentId])) $children[$parentId] = [];
            $children[$parentId][] = $id;
        } else {
            $rootAccounts[] = $id;
        }
    }

    $sortIds = function (&$ids) use (&$byId) {
        usort($ids, function ($a, $b) use (&$byId) {
            $codeA = trim((string)($byId[$a]['account_code'] ?? $byId[$a]['account_number'] ?? ''));
            $codeB = trim((string)($byId[$b]['account_code'] ?? $byId[$b]['account_number'] ?? ''));
            $cmp = strnatcasecmp($codeA, $codeB);
            if ($cmp !== 0) return $cmp;
            $titleA = trim((string)($byId[$a]['account_title'] ?? $byId[$a]['bank_name'] ?? ''));
            $titleB = trim((string)($byId[$b]['account_title'] ?? $byId[$b]['bank_name'] ?? ''));
            return strcasecmp($titleA, $titleB);
        });
    };

    $sortIds($rootAccounts);
    foreach ($children as &$ids) $sortIds($ids);
    unset($ids);

    $tree = [];
    $walk = function ($id, $level) use (&$walk, &$tree, &$byId, &$children) {
        if (!isset($byId[$id])) return;
        $account = $byId[$id];
        $account['level'] = (int)$level;
        $account['has_children'] = !empty($children[$id]);
        $tree[] = $account;

        foreach (($children[$id] ?? []) as $childId) {
            $walk($childId, $level + 1);
        }
    };

    foreach ($rootAccounts as $rootId) {
        $walk($rootId, 0);
    }

    return $tree;
}

function withdrawalCoaAccountHasChildren($conn, $accountId, $branchId = 0) {
    if (!tableExists($conn, 'chart_of_accounts')) return false;
    $accountId = (int)$accountId;
    if ($accountId <= 0) return false;
    $sql = "SELECT account_id FROM chart_of_accounts WHERE status = 'active' AND parent_account_id = ?";
    if ((int)$branchId > 0 && columnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
    }
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if ((int)$branchId > 0 && columnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $bid = (int)$branchId;
        $stmt->bind_param('ii', $accountId, $bid);
    } else {
        $stmt->bind_param('i', $accountId);
    }
    $stmt->execute();
    $hasChildren = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $hasChildren;
}

function assertWithdrawalSelectableCoaAccount($conn, $accountId, $branchId = 0, $fieldLabel = 'Account') {
    $accountId = (int)$accountId;
    if ($accountId <= 0) {
        throw new Exception($fieldLabel . ' is required.');
    }
    if (withdrawalCoaAccountHasChildren($conn, $accountId, $branchId)) {
        throw new Exception($fieldLabel . ' cannot be a parent account. Please select a sub account or an account without sub accounts.');
    }
}

function getBanks($conn, $view_all_branches, $branch_id, $active_only = true, $include_sub_accounts = true) {
    // Withdrawal bank list now comes from Chart of Accounts.
    // Only active accounts with Account Type = Bank are shown here.
    // Parent and sub accounts remain visible; sub accounts are indented without arrows or bold styling.
    if (!tableExists($conn, 'chart_of_accounts')) return [];

    $sql = "SELECT account_id,
                   account_id AS bank_id,
                   account_title,
                   account_title AS bank_name,
                   account_code,
                   account_code AS account_number,
                   description AS bank_branch,
                   balance,
                   branch_id,
                   parent_account_id,
                   '' AS parent_bank_name
            FROM chart_of_accounts
            WHERE account_type = 'Bank'";
    if ($active_only) $sql .= " AND status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $sql .= " ORDER BY account_code ASC, account_title ASC, account_id ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $rows = buildWithdrawalCoaDropdownTree($rows);

    foreach ($rows as &$bank) {
        $bank['payment_methods'] = [];
        $level = (int)($bank['level'] ?? 0);
        $indentText = str_repeat('    ', $level);
        $bank['display_name'] = $indentText . trim((string)($bank['bank_name'] ?? ''));
        $bank['is_sub_account'] = $level > 0 ? 1 : 0;
        $bank['has_children'] = !empty($bank['has_children']) ? 1 : 0;
        $bank['selectable'] = !empty($bank['has_children']) ? 0 : 1;
    }
    unset($bank);

    return $rows;
}
function getAllBanksForMapping($conn, $view_all_branches, $branch_id) {
    // Keep mapping compatible with the old Bank selector, but source it from Chart of Accounts.
    return getBanks($conn, $view_all_branches, $branch_id, true, true);
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

    $po_transaction_type_exists = columnExists($conn, 'purchase_orders', 'transaction_type');
    $po_payable_account_exists = columnExists($conn, 'purchase_orders', 'payable_account');
    $payable_account_select = $po_payable_account_exists ? 'po.payable_account' : "'Accounts Payable' AS payable_account";
    $sql = "SELECT po.po_id, po.po_number, po.order_date, po.expected_delivery, po.total_amount, po.po_status, po.supplier_name,
                   {$supplier_id_select},
                   {$payable_account_select},
                   COALESCE(NULLIF(TRIM(s.supplier_name), ''), NULLIF(TRIM(po.supplier_name), ''), 'Unknown Supplier') AS display_supplier_name,
                   COALESCE(SUM(CASE WHEN sp.status = 'completed' THEN sp.amount ELSE 0 END), 0) AS paid_amount,
                   (COALESCE(po.total_amount, 0) - COALESCE(SUM(CASE WHEN sp.status = 'completed' THEN sp.amount ELSE 0 END), 0)) AS balance
            FROM purchase_orders po
            {$supplier_join}
            LEFT JOIN supplier_payments sp ON sp.po_id = po.po_id
            WHERE LOWER(TRIM(COALESCE(po.po_status, ''))) = 'received'
              AND COALESCE(po.total_amount, 0) > 0";
    if ($po_transaction_type_exists) {
        $sql .= " AND LOWER(COALESCE(po.transaction_type, 'bill')) = 'bill'";
    }
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

function ensureBillExpensesTablesForWithdrawal($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `billexpenses` (
        `expense_id` int(11) NOT NULL AUTO_INCREMENT,
        `expense_no` varchar(50) DEFAULT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `expense_date` date DEFAULT NULL,
        `transaction_type` enum('bill','credit') NOT NULL DEFAULT 'bill',
        `account` varchar(255) DEFAULT NULL,
        `payable_account` varchar(255) DEFAULT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
        `memo` text DEFAULT NULL,
        `status` enum('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`expense_id`),
        KEY `branch_id` (`branch_id`),
        KEY `expense_no` (`expense_no`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $requiredColumns = [
        'expense_no' => "ALTER TABLE `billexpenses` ADD COLUMN `expense_no` varchar(50) DEFAULT NULL AFTER `expense_id`",
        'expense_date' => "ALTER TABLE `billexpenses` ADD COLUMN `expense_date` date DEFAULT NULL AFTER `branch_id`",
        'transaction_type' => "ALTER TABLE `billexpenses` ADD COLUMN `transaction_type` enum('bill','credit') NOT NULL DEFAULT 'bill' AFTER `expense_date`",
        'account' => "ALTER TABLE `billexpenses` ADD COLUMN `account` varchar(255) DEFAULT NULL AFTER `transaction_type`",
        'payable_account' => "ALTER TABLE `billexpenses` ADD COLUMN `payable_account` varchar(255) DEFAULT NULL AFTER `account`",
        'amount' => "ALTER TABLE `billexpenses` ADD COLUMN `amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `account`",
        'total_amount' => "ALTER TABLE `billexpenses` ADD COLUMN `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `amount`",
        'balance' => "ALTER TABLE `billexpenses` ADD COLUMN `balance` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`",
        'memo' => "ALTER TABLE `billexpenses` ADD COLUMN `memo` text DEFAULT NULL AFTER `balance`"
    ];
    foreach ($requiredColumns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM `billexpenses` LIKE '" . $conn->real_escape_string($column) . "'");
        if (!$check || $check->num_rows == 0) { @$conn->query($alterSql); }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `billexpense_items` (
        `item_id` int(11) NOT NULL AUTO_INCREMENT,
        `expense_id` int(11) NOT NULL,
        `account` varchar(255) NOT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `memo` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`item_id`),
        KEY `expense_id` (`expense_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `billexpense_payments` (
        `expense_payment_id` int(11) NOT NULL AUTO_INCREMENT,
        `expense_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `payment_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `check_number` varchar(100) DEFAULT NULL,
        `bank_name` varchar(150) DEFAULT NULL,
        `bank_id` int(11) DEFAULT NULL,
        `bank_transaction_id` int(11) DEFAULT NULL,
        `memo` text DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`expense_payment_id`),
        KEY `expense_id` (`expense_id`),
        KEY `branch_id` (`branch_id`),
        KEY `bank_transaction_id` (`bank_transaction_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (!columnExists($conn, 'billexpense_payments', 'check_number')) {
        $conn->query("ALTER TABLE `billexpense_payments` ADD COLUMN `check_number` varchar(100) DEFAULT NULL AFTER `reference_number`");
    }

    @$conn->query("UPDATE billexpenses SET total_amount = amount WHERE COALESCE(total_amount,0) = 0 AND COALESCE(amount,0) > 0");
    @$conn->query("UPDATE billexpenses SET balance = total_amount WHERE COALESCE(balance,0) = 0 AND LOWER(COALESCE(status,'unpaid')) = 'unpaid'");
    @$conn->query("UPDATE billexpenses SET expense_date = DATE(created_at) WHERE expense_date IS NULL AND created_at IS NOT NULL");
    @$conn->query("UPDATE billexpenses SET expense_no = CONCAT('EXP-', LPAD(expense_id, 5, '0')) WHERE expense_no IS NULL OR expense_no = ''");
    @$conn->query("INSERT INTO billexpense_items (expense_id, account, amount, memo)
                   SELECT e.expense_id, e.account, e.amount, e.memo
                   FROM billexpenses e
                   LEFT JOIN billexpense_items i ON i.expense_id = e.expense_id
                   WHERE i.expense_id IS NULL AND COALESCE(e.account,'') <> '' AND COALESCE(e.amount,0) > 0");
}

function getExpenseItemsByExpenseId($conn, $expense_id) {
    ensureBillExpensesTablesForWithdrawal($conn);
    $items = [];
    $stmt = $conn->prepare("SELECT account, amount, memo FROM billexpense_items WHERE expense_id = ? ORDER BY item_id ASC");
    if ($stmt) {
        $eid = (int)$expense_id;
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $items;
}

function getExpensePayables($conn, $view_all_branches, $branch_id) {
    ensureBillExpensesTablesForWithdrawal($conn);
    $sql = "SELECT e.expense_id, e.expense_no, e.branch_id, e.expense_date, e.account, e.payable_account, e.amount, e.total_amount, e.balance, e.memo, e.status, e.created_at,
                   COALESCE(SUM(ep.amount), 0) AS paid_amount,
                   (COALESCE(e.total_amount, e.amount, 0) - COALESCE(SUM(ep.amount), 0)) AS balance
            FROM billexpenses e
            LEFT JOIN billexpense_payments ep ON ep.expense_id = e.expense_id
            WHERE LOWER(COALESCE(e.status, 'unpaid')) <> 'cancelled'
              AND LOWER(COALESCE(e.transaction_type, 'bill')) = 'bill'
              AND COALESCE(e.total_amount, e.amount, 0) > 0";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND e.branch_id = ?";
    $sql .= " GROUP BY e.expense_id HAVING balance > 0.009 ORDER BY e.created_at DESC, e.expense_id DESC";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    foreach ($rows as &$row) {
        $row['items'] = getExpenseItemsByExpenseId($conn, (int)$row['expense_id']);
        $row['account_summary'] = !empty($row['items']) ? implode(', ', array_unique(array_map(fn($i) => $i['account'], $row['items']))) : ($row['account'] ?? 'Expense');
    }
    unset($row);
    return $rows;
}

function getExpensePayableById($conn, $expense_id, $view_all_branches, $branch_id) {
    foreach (getExpensePayables($conn, $view_all_branches, $branch_id) as $row) {
        if ((int)$row['expense_id'] === (int)$expense_id) return $row;
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
                   bt.reference_number, bt.check_number, bt.bank_name, bt.bank_id, bt.description, bt.amount,
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
        `check_number` varchar(100) DEFAULT NULL,
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
    if (!columnExists($conn, 'bank_transactions', 'check_number')) {
        $conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `check_number` varchar(100) DEFAULT NULL AFTER `reference_number`");
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

    if (!columnExists($conn, 'supplier_payments', 'check_number')) {
        $conn->query("ALTER TABLE `supplier_payments` ADD COLUMN `check_number` varchar(100) DEFAULT NULL AFTER `bank_branch`");
    }
    if (!columnExists($conn, 'bank_transactions', 'check_number')) {
        $conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `check_number` varchar(100) DEFAULT NULL AFTER `reference_number`");
    }
}



// ========== CHART OF ACCOUNTS POSTING HELPERS FOR WITHDRAWAL PAYMENTS ==========
function ensureChartAccountPostingTablesForWithdrawal($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `chart_account_transactions` (
        `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
        `account_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `transaction_date` date DEFAULT NULL,
        `transaction_type` varchar(80) NOT NULL,
        `transaction_no` varchar(100) DEFAULT NULL,
        `memo` text DEFAULT NULL,
        `source_table` varchar(100) DEFAULT NULL,
        `source_id` int(11) DEFAULT NULL,
        `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
        `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
        `balance_after` decimal(15,2) NOT NULL DEFAULT 0.00,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transaction_id`),
        KEY `idx_cat_account` (`account_id`),
        KEY `idx_cat_source` (`source_table`, `source_id`),
        KEY `idx_cat_date` (`transaction_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $needed = [
        'transaction_date' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_date` date DEFAULT NULL AFTER `branch_id`",
        'transaction_type' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_type` varchar(80) NOT NULL DEFAULT '' AFTER `transaction_date`",
        'transaction_no' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_no` varchar(100) DEFAULT NULL AFTER `transaction_type`",
        'memo' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `memo` text DEFAULT NULL AFTER `transaction_no`",
        'account_name' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `account_name` varchar(255) DEFAULT NULL AFTER `memo`",
        'counterparty' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `counterparty` varchar(255) DEFAULT NULL AFTER `account_name`",
        'attachment_path' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `attachment_path` text DEFAULT NULL AFTER `counterparty`",
        'reference_no' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `reference_no` varchar(100) DEFAULT NULL AFTER `transaction_no`",
        'source_table' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_table` varchar(100) DEFAULT NULL AFTER `memo`",
        'source_id' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_id` int(11) DEFAULT NULL AFTER `source_table`",
        'debit' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `debit` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `source_id`",
        'credit' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `credit` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `debit`",
        'balance_after' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `balance_after` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `credit`",
        'created_by' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `created_by` int(11) NOT NULL DEFAULT 0 AFTER `balance_after`"
    ];
    foreach ($needed as $col => $sql) {
        if (!columnExists($conn, 'chart_account_transactions', $col)) {
            @$conn->query($sql);
        }
    }
}

function normalizeCoaAccountLabelForWithdrawal($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (strpos($value, ' · ') !== false) $value = trim(substr($value, strpos($value, ' · ') + 3));
    if (strpos($value, ' - ') !== false) {
        $parts = explode(' - ', $value, 2);
        if (count($parts) === 2 && trim($parts[1]) !== '') $value = trim($parts[1]);
    }
    return $value;
}

function resolveChartAccountByTitleForWithdrawal($conn, $accountTitle, $branchId = 0) {
    if (!tableExists($conn, 'chart_of_accounts')) return null;
    $accountTitle = normalizeCoaAccountLabelForWithdrawal($accountTitle);
    if ($accountTitle === '') return null;

    $sql = "SELECT account_id, account_title, account_type, balance
            FROM chart_of_accounts
            WHERE status = 'active'
              AND LOWER(TRIM(account_title)) = LOWER(TRIM(?))";
    if ((int)$branchId > 0 && columnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL) ORDER BY CASE WHEN branch_id = ? THEN 0 ELSE 1 END, account_title ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $bid = (int)$branchId;
        $stmt->bind_param('sii', $accountTitle, $bid, $bid);
    } else {
        $sql .= " ORDER BY account_title ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('s', $accountTitle);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function resolveDefaultPayableAccountForWithdrawal($conn, $branchId = 0) {
    $preferred = ['Accounts Payable'];
    foreach ($preferred as $title) {
        $account = resolveChartAccountByTitleForWithdrawal($conn, $title, $branchId);
        if ($account) return $account;
    }
    return null;
}

function resolveBankChartAccountForWithdrawal($conn, $bankId, $bankName, $branchId = 0) {
    $candidates = [];
    $bankName = trim((string)$bankName);
    if ($bankName !== '') $candidates[] = $bankName;

    if ((int)$bankId > 0 && tableExists($conn, 'banks')) {
        $stmt = $conn->prepare("SELECT b.bank_name, pb.bank_name AS parent_bank_name
                                FROM banks b
                                LEFT JOIN banks pb ON pb.bank_id = b.parent_bank_id
                                WHERE b.bank_id = ? LIMIT 1");
        if ($stmt) {
            $bid = (int)$bankId;
            $stmt->bind_param('i', $bid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['parent_bank_name'])) $candidates[] = trim((string)$row['parent_bank_name']);
            if (!empty($row['bank_name'])) $candidates[] = trim((string)$row['bank_name']);
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates, fn($v) => trim((string)$v) !== '')));
    foreach ($candidates as $candidate) {
        $account = resolveChartAccountByTitleForWithdrawal($conn, $candidate, $branchId);
        if ($account) return $account;
    }
    foreach (['Cash and Cash Equivalents', 'Cash', 'Petty Cash'] as $fallbackTitle) {
        $account = resolveChartAccountByTitleForWithdrawal($conn, $fallbackTitle, $branchId);
        if ($account) return $account;
    }
    return null;
}

function chartAccountDebitIncreasesForWithdrawal($accountType) {
    $type = strtolower(trim((string)$accountType));
    $creditNormal = ['accounts payable', 'credit card', 'other current liability', 'long term liability', 'equity', 'income', 'other income'];
    return !in_array($type, $creditNormal, true);
}

function postChartAccountEntryForWithdrawal($conn, $account, $branchId, $transactionDate, $transactionType, $transactionNo, $memo, $sourceTable, $sourceId, $debit, $credit, $createdBy, $counterparty = '', $attachmentPath = '', $referenceNo = null) {
    ensureChartAccountPostingTablesForWithdrawal($conn);
    $accountId = (int)($account['account_id'] ?? 0);
    if ($accountId <= 0) return;

    $debit = round((float)$debit, 2);
    $credit = round((float)$credit, 2);
    if ($debit <= 0 && $credit <= 0) return;

    $debitIncreases = chartAccountDebitIncreasesForWithdrawal($account['account_type'] ?? '');
    $delta = $debitIncreases ? ($debit - $credit) : ($credit - $debit);

    $update = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance,0) + ? WHERE account_id = ?");
    if (!$update) throw new Exception('Failed to update Chart of Accounts balance: ' . $conn->error);
    $update->bind_param('di', $delta, $accountId);
    if (!$update->execute()) throw new Exception('Failed to update Chart of Accounts balance: ' . $update->error);
    $update->close();

    $balanceAfter = 0.00;
    $balStmt = $conn->prepare("SELECT balance FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if ($balStmt) {
        $balStmt->bind_param('i', $accountId);
        $balStmt->execute();
        $balRow = $balStmt->get_result()->fetch_assoc();
        $balanceAfter = (float)($balRow['balance'] ?? 0);
        $balStmt->close();
    }

    $transactionDateOnly = date('Y-m-d', strtotime((string)$transactionDate));
    $sourceTable = trim((string)$sourceTable);
    $accountName = (string)($account['account_title'] ?? '');
    $counterparty = trim((string)$counterparty);
    $attachmentPath = trim((string)$attachmentPath);
    $referenceNo = $referenceNo === null ? $transactionNo : trim((string)$referenceNo);
    $insert = $conn->prepare("INSERT INTO chart_account_transactions
        (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, counterparty, attachment_path, source_table, source_id, debit, credit, balance_after, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$insert) throw new Exception('Failed to save Chart of Accounts transaction: ' . $conn->error);
    $branchId = (int)$branchId;
    $sourceId = (int)$sourceId;
    $createdBy = (int)$createdBy;
    $insert->bind_param('iisssssssssiddii', $accountId, $branchId, $transactionDateOnly, $transactionType, $transactionNo, $referenceNo, $memo, $accountName, $counterparty, $attachmentPath, $sourceTable, $sourceId, $debit, $credit, $balanceAfter, $createdBy);
    if (!$insert->execute()) throw new Exception('Failed to save Chart of Accounts transaction: ' . $insert->error);
    $insert->close();
}

function postPayablePaymentToChartOfAccounts($conn, $payableAccountTitle, $bankId, $bankName, $branchId, $paymentDate, $transactionNo, $memo, $sourceTable, $sourceId, $amount, $createdBy) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) return;

    $payableAccount = resolveChartAccountByTitleForWithdrawal($conn, $payableAccountTitle, $branchId);
    if (!$payableAccount) {
        $payableAccount = resolveDefaultPayableAccountForWithdrawal($conn, $branchId);
    }
    if (!$payableAccount) {
        throw new Exception('Accounts Payable account was not found in Chart of Accounts. Please create an active Accounts Payable account first.');
    }

    $bankAccount = resolveBankChartAccountForWithdrawal($conn, $bankId, $bankName, $branchId);
    if (!$bankAccount) {
        throw new Exception('Bank/Cash account was not found in Chart of Accounts. Please create an active bank account title that matches the selected bank, Cash, Petty Cash, or Cash and Cash Equivalents.');
    }

    // Payment of payable: Debit Accounts Payable, Credit Bank/Cash.
    postChartAccountEntryForWithdrawal($conn, $payableAccount, $branchId, $paymentDate, 'Bill Payment', $transactionNo, $memo, $sourceTable, $sourceId, $amount, 0.00, $createdBy);
    postChartAccountEntryForWithdrawal($conn, $bankAccount, $branchId, $paymentDate, 'Bill Payment', $transactionNo, $memo, $sourceTable, $sourceId, 0.00, $amount, $createdBy);
}


function postWithdrawalToChartOfAccounts($conn, $account_id, $branch_id_db, $transaction_date, $reference_number, $memo, $amount, $transaction_id, $user_id, $counterparty = '', $attachmentPath = '') {
    ensureChartAccountPostingTablesForWithdrawal($conn);
    if (!tableExists($conn, 'chart_of_accounts') || !tableExists($conn, 'chart_account_transactions')) return;
    $account_id = (int)$account_id;
    $amount = (float)$amount;
    if ($account_id <= 0 || $amount <= 0) return;

    $stmt = $conn->prepare("SELECT balance, account_title FROM chart_of_accounts WHERE account_id = ? AND account_type = 'Bank' AND status = 'active' LIMIT 1");
    if (!$stmt) throw new Exception('Unable to verify selected bank account.');
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account) throw new Exception('Selected bank account was not found in Chart of Accounts.');

    $old_balance = (float)($account['balance'] ?? 0);
    $new_balance = $old_balance - $amount;
    $txn_date = date('Y-m-d', strtotime($transaction_date));
    $transaction_no = $reference_number !== '' ? $reference_number : 'WDL-' . str_pad((string)$transaction_id, 6, '0', STR_PAD_LEFT);
    $account_name = (string)($account['account_title'] ?? '');

    $upd = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
    if (!$upd) throw new Exception('Unable to update bank account balance.');
    $upd->bind_param('di', $new_balance, $account_id);
    if (!$upd->execute()) throw new Exception('Failed to update Chart of Accounts bank balance: ' . $upd->error);
    $upd->close();

    $counterparty = trim((string)$counterparty);
    $attachmentPath = trim((string)$attachmentPath);
    $ins = $conn->prepare("INSERT INTO chart_account_transactions
        (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, counterparty, attachment_path, debit, credit, balance_after, source_table, source_id, created_by)
        VALUES (?, ?, ?, 'Check', ?, ?, ?, ?, ?, ?, 0.00, ?, ?, 'bank_transactions', ?, ?)");
    if (!$ins) throw new Exception('Unable to save Chart of Accounts transaction.');
    $ins->bind_param('iisssssssddii', $account_id, $branch_id_db, $txn_date, $transaction_no, $reference_number, $memo, $account_name, $counterparty, $attachmentPath, $amount, $new_balance, $transaction_id, $user_id);
    if (!$ins->execute()) throw new Exception('Failed to save withdrawal in Chart of Accounts quick report: ' . $ins->error);
    $ins->close();
}


function resolveChartAccountByIdForWithdrawal($conn, $accountId, $branchId = 0) {
    if (!tableExists($conn, 'chart_of_accounts')) return null;
    $accountId = (int)$accountId;
    if ($accountId <= 0) return null;

    $sql = "SELECT account_id, account_title, account_type, balance
            FROM chart_of_accounts
            WHERE account_id = ? AND status = 'active'";
    if ((int)$branchId > 0 && columnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL) LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $bid = (int)$branchId;
        $stmt->bind_param('ii', $accountId, $bid);
    } else {
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $accountId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function postWithdrawalExpenseAccountToChartOfAccounts($conn, $expenseAccountId, $branchId, $transactionDate, $referenceNumber, $memo, $amount, $transactionId, $createdBy, $counterparty = '', $attachmentPath = '') {
    $amount = round((float)$amount, 2);
    $expenseAccount = resolveChartAccountByIdForWithdrawal($conn, $expenseAccountId, $branchId);
    if (!$expenseAccount) {
        throw new Exception('Selected expense account was not found in Chart of Accounts.');
    }

    $transactionNo = trim((string)$referenceNumber) !== ''
        ? trim((string)$referenceNumber)
        : 'WDL-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT);

    // Withdrawal expense entry: Debit the selected account so it appears in its Quick Report.
    postChartAccountEntryForWithdrawal(
        $conn,
        $expenseAccount,
        $branchId,
        $transactionDate,
        'Check',
        $transactionNo,
        $memo,
        'bank_transactions',
        $transactionId,
        $amount,
        0.00,
        $createdBy,
        $counterparty,
        $attachmentPath,
        $referenceNumber
    );
}

function resolveItemAssetAccountForWithdrawal($conn, $itemId, $branchId = 0) {
    if (!tableExists($conn, 'items') || !tableExists($conn, 'chart_of_accounts')) return null;

    $itemId = (int)$itemId;
    if ($itemId <= 0) return null;

    $columns = [];
    $res = $conn->query("SHOW COLUMNS FROM `items`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower($row['Field'])] = $row['Field'];
        }
    }

    $idCol = $columns['item_id'] ?? 'item_id';
    $candidateIdColumns = [
        'asset_account_id',
        'inventory_asset_account_id',
        'inventory_account_id',
        'coa_asset_account_id',
        'chart_account_id',
        'item_asset_account_id',
        'account_id'
    ];
    $candidateTitleColumns = [
        'asset_account',
        'asset_account_name',
        'asset_account_title',
        'inventory_asset_account',
        'inventory_account',
        'inventory_account_name',
        'chart_account',
        'account_title',
        'account'
    ];

    $selectParts = ["`{$idCol}` AS item_id"];
    foreach ($candidateIdColumns as $colKey) {
        if (isset($columns[$colKey])) $selectParts[] = "`{$columns[$colKey]}` AS `{$colKey}`";
    }
    foreach ($candidateTitleColumns as $colKey) {
        if (isset($columns[$colKey])) $selectParts[] = "`{$columns[$colKey]}` AS `{$colKey}`";
    }

    $sql = "SELECT " . implode(", ", $selectParts) . " FROM `items` WHERE `{$idCol}` = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) return null;

    foreach ($candidateIdColumns as $colKey) {
        $accountId = (int)($item[$colKey] ?? 0);
        if ($accountId > 0) {
            $account = resolveChartAccountByIdForWithdrawal($conn, $accountId, $branchId);
            if ($account) return $account;
        }
    }

    foreach ($candidateTitleColumns as $colKey) {
        $accountTitle = trim((string)($item[$colKey] ?? ''));
        if ($accountTitle !== '') {
            $account = resolveChartAccountByTitleForWithdrawal($conn, $accountTitle, $branchId);
            if ($account) return $account;
        }
    }

    foreach (['Inventory', 'Inventory Asset', 'Merchandise Inventory', 'Other Current Asset'] as $fallbackTitle) {
        $account = resolveChartAccountByTitleForWithdrawal($conn, $fallbackTitle, $branchId);
        if ($account) return $account;
    }

    return null;
}

function getWithdrawalItemNameForPosting($conn, $itemId) {
    if (!tableExists($conn, 'items')) return 'Item';
    $itemId = (int)$itemId;
    if ($itemId <= 0) return 'Item';

    $columns = [];
    $res = $conn->query("SHOW COLUMNS FROM `items`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower($row['Field'])] = $row['Field'];
        }
    }

    $idCol = $columns['item_id'] ?? 'item_id';
    $nameCol = $columns['item_name'] ?? ($columns['name'] ?? null);
    if (!$nameCol) return 'Item';

    $stmt = $conn->prepare("SELECT `{$nameCol}` AS item_name FROM `items` WHERE `{$idCol}` = ? LIMIT 1");
    if (!$stmt) return 'Item';
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $name = trim((string)($row['item_name'] ?? ''));
    return $name !== '' ? $name : 'Item';
}

function collectWithdrawalItemRowsFromPost($conn) {
    $itemIds = $_POST['withdrawal_item_id'] ?? [];
    $qtys = $_POST['withdrawal_item_qty'] ?? [];
    $descriptions = $_POST['withdrawal_item_description'] ?? [];
    $uoms = $_POST['withdrawal_item_uom'] ?? [];
    $unitCosts = $_POST['withdrawal_item_unit_cost'] ?? [];
    $discounts = $_POST['withdrawal_item_discount'] ?? [];
    $totals = $_POST['withdrawal_item_total'] ?? [];

    if (!is_array($itemIds)) $itemIds = [$itemIds];

    $rows = [];
    foreach ($itemIds as $idx => $rawItemId) {
        $itemId = (int)$rawItemId;
        if ($itemId <= 0) continue;

        $qty = (float)str_replace(',', '', (string)($qtys[$idx] ?? 0));
        $unitCost = (float)str_replace(',', '', (string)($unitCosts[$idx] ?? 0));
        $discountText = trim((string)($discounts[$idx] ?? ''));
        $postedTotal = (float)str_replace([',', '₱', ' '], '', (string)($totals[$idx] ?? 0));

        $subtotal = max(0, $qty * $unitCost);
        $discountAmount = 0.00;
        if ($discountText !== '') {
            if (str_ends_with($discountText, '%')) {
                $percent = (float)str_replace(['%', ',', ' '], '', $discountText);
                $discountAmount = $subtotal * ($percent / 100);
            } else {
                $discountAmount = (float)str_replace([',', '₱', ' '], '', $discountText);
            }
        }

        $computedTotal = max(0, $subtotal - $discountAmount);
        $lineTotal = $postedTotal > 0 ? $postedTotal : $computedTotal;
        if ($lineTotal <= 0) continue;

        $rows[] = [
            'item_id' => $itemId,
            'item_name' => getWithdrawalItemNameForPosting($conn, $itemId),
            'qty' => $qty,
            'description' => trim((string)($descriptions[$idx] ?? '')),
            'uom' => trim((string)($uoms[$idx] ?? '')),
            'unit_cost' => $unitCost,
            'discount' => $discountText,
            'total' => round($lineTotal, 2)
        ];
    }

    return $rows;
}

function postWithdrawalItemsToChartOfAccounts($conn, $itemRows, $branchId, $transactionDate, $referenceNumber, $memo, $transactionId, $createdBy, $counterparty = '', $attachmentPath = '') {
    if (empty($itemRows)) return;

    $transactionNo = trim((string)$referenceNumber) !== ''
        ? trim((string)$referenceNumber)
        : 'WDL-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT);

    foreach ($itemRows as $row) {
        $account = resolveItemAssetAccountForWithdrawal($conn, (int)$row['item_id'], $branchId);
        if (!$account) {
            throw new Exception('Asset account was not found for item: ' . ($row['item_name'] ?? 'Item') . '. Please tag this item to an active asset account in Chart of Accounts.');
        }

        $lineMemoParts = [];
        $lineMemoParts[] = trim((string)$memo);
        $lineMemoParts[] = 'Item: ' . ($row['item_name'] ?? 'Item');
        if (!empty($row['description'])) $lineMemoParts[] = 'Description: ' . $row['description'];
        if (!empty($row['qty'])) $lineMemoParts[] = 'Qty: ' . $row['qty'];
        if (!empty($row['uom'])) $lineMemoParts[] = 'UOM: ' . $row['uom'];
        $lineMemo = implode(' | ', array_filter($lineMemoParts, fn($v) => trim((string)$v) !== ''));

        postChartAccountEntryForWithdrawal(
            $conn,
            $account,
            $branchId,
            $transactionDate,
            'Check',
            $transactionNo,
            $lineMemo,
            'bank_transactions',
            $transactionId,
            (float)$row['total'],
            0.00,
            $createdBy,
            $counterparty,
            $attachmentPath,
            $referenceNumber
        );
    }
}

function ensureExpenseAccountsTableForWithdrawal($conn) {
    // Kept for backward compatibility with older installs.
    // New Withdrawal expense dropdown now reads from Chart of Accounts.
    return true;
}

function getExpenseAccountOptions($conn, $view_all_branches, $branch_id) {
    // Account Title options come from Chart of Accounts.
    // Parent and sub accounts are shown in hierarchy; sub accounts are indented without arrows or bold styling.
    if (!tableExists($conn, 'chart_of_accounts')) return [];

    $sql = "SELECT account_id, parent_account_id, account_code, account_title, account_type, description, branch_id
            FROM chart_of_accounts
            WHERE status = 'active'";
    if (!$view_all_branches && $branch_id > 0) {
        $sql .= " AND (branch_id = ? OR branch_id = 0)";
    }
    $sql .= " ORDER BY FIELD(account_type, 'Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset', 'Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability', 'Equity', 'Income', 'Cost of Goods Sold', 'Expense', 'Other Income', 'Other Expense'), account_code ASC, account_title ASC";

    $rawRows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) {
            $stmt->bind_param('i', $branch_id);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $title = trim($row['account_title'] ?? '');
            if ($title === '') continue;
            $row['id'] = (int)($row['account_id'] ?? 0);
            $rawRows[] = $row;
        }
        $stmt->close();
    }

    $treeRows = buildWithdrawalCoaDropdownTree($rawRows);

    $rows = [];
    $seen = [];
    foreach ($treeRows as $row) {
        $title = trim($row['account_title'] ?? '');
        if ($title === '') continue;

        $code = trim($row['account_code'] ?? '');
        $level = (int)($row['level'] ?? 0);
        $indentText = str_repeat('    ', $level);
        $displayTitle = $indentText . $title;
        $label = ($code !== '' ? $code . ' · ' : '') . $displayTitle;
        $key = strtolower($label);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $rows[] = [
            'id' => (int)($row['account_id'] ?? 0),
            'label' => $label,
            'display_label' => $label,
            'display_title' => $displayTitle,
            'bank_name' => $title,
            'account_title' => $title,
            'account_code' => $code,
            'account_type' => trim($row['account_type'] ?? ''),
            'is_sub_account' => $level > 0 ? 1 : 0,
            'level' => $level,
            'has_children' => !empty($row['has_children']) ? 1 : 0,
            'selectable' => !empty($row['has_children']) ? 0 : 1,
            'parent_bank_name' => '',
            'description' => trim($row['description'] ?? '')
        ];
    }

    return $rows;
}

function findExpenseAccountOptionByLabel($conn, $view_all_branches, $branch_id, $label) {
    $label = trim((string)$label);
    if ($label === '') return null;
    $normalized = strtolower($label);
    foreach (getExpenseAccountOptions($conn, $view_all_branches, $branch_id) as $option) {
        $optionLabel = trim((string)($option['label'] ?? ''));
        $optionTitle = trim((string)($option['account_title'] ?? ''));
        $optionCode = trim((string)($option['account_code'] ?? ''));
        $labelWithoutCode = preg_replace('/^\s*[^·-]+\s*[·-]\s*/u', '', $optionLabel);
        $matches = [
            strtolower($optionLabel),
            strtolower($optionTitle),
            strtolower(trim((string)$labelWithoutCode)),
            strtolower(($optionCode !== '' ? $optionCode . ' - ' . $optionTitle : $optionTitle)),
            strtolower(($optionCode !== '' ? $optionCode . ' · ' . $optionTitle : $optionTitle))
        ];
        if (in_array($normalized, array_unique($matches), true)) return $option;
    }
    return null;
}

function collectWithdrawalExpenseRowsFromPost($conn, $view_all_branches, $branch_id) {
    $accounts = $_POST['withdrawal_expense_account'] ?? ($_POST['expense_account'] ?? []);
    $amounts = $_POST['withdrawal_expense_amount'] ?? [];
    $memos = $_POST['withdrawal_expense_memo'] ?? [];
    if (!is_array($accounts)) $accounts = [$accounts];
    if (!is_array($amounts)) $amounts = [$amounts];
    if (!is_array($memos)) $memos = [$memos];
    $rows = [];
    foreach ($accounts as $idx => $rawAccount) {
        $accountLabel = trim((string)$rawAccount);
        $lineAmount = round((float)str_replace([',', '₱', ' '], '', (string)($amounts[$idx] ?? 0)), 2);
        $lineMemo = trim((string)($memos[$idx] ?? ''));
        if ($accountLabel === '' && $lineAmount <= 0 && $lineMemo === '') continue;
        if ($accountLabel === '') throw new Exception('Expense account is required on row ' . ($idx + 1) . '.');
        if ($lineAmount <= 0) throw new Exception('Expense amount must be greater than zero on row ' . ($idx + 1) . '.');
        $selected = findExpenseAccountOptionByLabel($conn, $view_all_branches, $branch_id, $accountLabel);
        if (!$selected) throw new Exception('Please select a valid Expense account from Chart of Accounts on row ' . ($idx + 1) . '.');
        if (!empty($selected['has_children'])) throw new Exception('Account Title cannot be a parent account on row ' . ($idx + 1) . '.');
        $rows[] = ['account_id'=>(int)($selected['id'] ?? 0),'account_label'=>(string)($selected['label'] ?? $accountLabel),'account_title'=>(string)($selected['account_title'] ?? $accountLabel),'description'=>(string)($selected['description'] ?? ''),'amount'=>$lineAmount,'memo'=>$lineMemo];
    }
    return $rows;
}

function saveWithdrawalToJournalEntriesMulti($conn, $bankAccount, array $debitRows, $branchId, $transactionDate, $referenceNumber, $memo, $amount, $transactionId, $createdBy, $payee, $attachmentPath) {
    ensureWithdrawalJournalTables($conn);
    if (empty($debitRows)) return;
    $journalDate = date('Y-m-d', strtotime((string)$transactionDate));
    $entryNo = trim((string)$referenceNumber) !== '' ? trim((string)$referenceNumber) : 'WDL-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT);
    $sourceTable = 'bank_transactions'; $sourceType = 'Withdrawal';
    $headerStmt = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, source_table, source_id, source_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$headerStmt) throw new Exception('Unable to prepare withdrawal journal header: ' . $conn->error);
    $branchId=(int)$branchId; $transactionId=(int)$transactionId; $createdBy=(int)$createdBy;
    $headerStmt->bind_param('sssisssi', $entryNo, $journalDate, $attachmentPath, $branchId, $sourceTable, $transactionId, $sourceType, $createdBy);
    if (!$headerStmt->execute()) throw new Exception('Unable to save withdrawal journal header: ' . $headerStmt->error);
    $journalId=(int)$conn->insert_id; $headerStmt->close();
    $detailStmt = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$detailStmt) throw new Exception('Unable to prepare withdrawal journal details: ' . $conn->error);
    foreach ($debitRows as $row) {
        $account = $row['account'] ?? [];
        $expenseAccountId=(int)($account['account_id'] ?? $row['account_id'] ?? 0);
        $expenseTitle=(string)($account['account_title'] ?? $row['account_title'] ?? 'Withdrawal Expense');
        $debit=round((float)($row['amount'] ?? 0),2); if ($debit <= 0) continue;
        $credit=0.00; $lineMemo=trim((string)($row['memo'] ?? '')) !== '' ? trim((string)$row['memo']) : $memo;
        $detailStmt->bind_param('iisddss', $journalId, $expenseAccountId, $expenseTitle, $debit, $credit, $lineMemo, $payee);
        if (!$detailStmt->execute()) throw new Exception('Unable to save withdrawal journal debit: ' . $detailStmt->error);
    }
    $bankAccountId=(int)($bankAccount['account_id'] ?? 0); $bankTitle=(string)($bankAccount['account_title'] ?? 'Bank');
    $debit=0.00; $credit=round((float)$amount,2);
    $detailStmt->bind_param('iisddss', $journalId, $bankAccountId, $bankTitle, $debit, $credit, $memo, $payee);
    if (!$detailStmt->execute()) throw new Exception('Unable to save withdrawal journal credit: ' . $detailStmt->error);
    $detailStmt->close();
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
    $saved_files = [];

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



// ========== WITHDRAWAL ATTACHMENT HELPERS ==========
function ensureWithdrawalAttachmentsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `withdrawal_attachments` (
        `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` int(11) NOT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `file_name` varchar(255) NOT NULL,
        `stored_name` varchar(255) NOT NULL,
        `file_path` varchar(500) NOT NULL,
        `file_type` varchar(120) DEFAULT NULL,
        `file_size` int(11) NOT NULL DEFAULT 0,
        `uploaded_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`attachment_id`),
        KEY `transaction_id` (`transaction_id`),
        KEY `branch_id` (`branch_id`),
        KEY `uploaded_by` (`uploaded_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function saveWithdrawalAttachments($conn, $transaction_id, $branch_id, $uploaded_by) {
    ensureWithdrawalAttachmentsTable($conn);

    if (empty($_FILES['withdrawal_attachments']) || empty($_FILES['withdrawal_attachments']['name'])) return 0;

    $project_root = realpath(dirname(__DIR__));
    if ($project_root === false) $project_root = dirname(__DIR__);

    $upload_dir = rtrim($project_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'withdrawal_attachments' . DIRECTORY_SEPARATOR;
    $public_dir = '../uploads/withdrawal_attachments/';

    if (!is_dir($upload_dir)) {
        if (!@mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
            throw new Exception('Unable to create attachment upload folder: uploads/withdrawal_attachments');
        }
    }
    @chmod($upload_dir, 0775);
    if (!is_writable($upload_dir)) {
        throw new Exception('Attachment upload folder is not writable: uploads/withdrawal_attachments');
    }

    $allowed_ext = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','csv','txt'];
    $saved_files = [];

    $names = $_FILES['withdrawal_attachments']['name'];
    $tmp_names = $_FILES['withdrawal_attachments']['tmp_name'];
    $errors = $_FILES['withdrawal_attachments']['error'];
    $sizes = $_FILES['withdrawal_attachments']['size'];
    $types = $_FILES['withdrawal_attachments']['type'];

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
            throw new Exception('Failed to upload withdrawal attachment: ' . $original_name . ' (error code: ' . $upload_error . ')');
        }

        $tmp_file = $tmp_names[$i] ?? '';
        if ($tmp_file === '' || !is_uploaded_file($tmp_file)) {
            throw new Exception('Invalid uploaded withdrawal attachment: ' . $original_name);
        }

        $file_size = (int)($sizes[$i] ?? 0);
        if ($file_size > 10 * 1024 * 1024) {
            throw new Exception('Withdrawal attachment is too large. Maximum allowed size is 10MB per file: ' . $original_name);
        }

        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed_ext, true)) {
            throw new Exception('Invalid withdrawal attachment type: ' . $original_name);
        }

        $safe_original_name = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $original_name);
        if ($safe_original_name === '' || $safe_original_name === null) $safe_original_name = 'attachment.' . $ext;

        $stored_name = 'withdrawal_' . (int)$transaction_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target_path = $upload_dir . $stored_name;

        if (!move_uploaded_file($tmp_file, $target_path)) {
            throw new Exception('Unable to save attachment to uploads/withdrawal_attachments: ' . $original_name);
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
        $stmt = $conn->prepare("INSERT INTO withdrawal_attachments
            (transaction_id, branch_id, file_name, stored_name, file_path, file_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            @unlink($target_path);
            throw new Exception('Failed to prepare withdrawal attachment insert: ' . $conn->error);
        }
        $transaction_id_int = (int)$transaction_id;
        $branch_id_int = (int)$branch_id;
        $uploaded_by_int = (int)$uploaded_by;
        $stmt->bind_param('iissssii', $transaction_id_int, $branch_id_int, $safe_original_name, $stored_name, $public_path, $file_type, $file_size, $uploaded_by_int);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            @unlink($target_path);
            throw new Exception('Failed to save withdrawal attachment record: ' . $error);
        }
        $stmt->close();
        $saved_files[] = [
            'original_name' => $safe_original_name,
            'file_name' => $safe_original_name,
            'stored_name' => $stored_name,
            'file_path' => $public_path,
            'path' => $public_path,
            'url' => $public_path,
            'size' => $file_size,
            'type' => $file_type
        ];
    }

    return $saved_files;
}

function getWithdrawalAttachmentsByTransactionId($conn, $transaction_id) {
    ensureWithdrawalAttachmentsTable($conn);
    $transaction_id = (int)$transaction_id;
    if ($transaction_id <= 0) return [];

    $rows = [];
    $stmt = $conn->prepare("SELECT attachment_id, file_name, stored_name, file_path, file_type, file_size, created_at
                            FROM withdrawal_attachments
                            WHERE transaction_id = ?
                            ORDER BY attachment_id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $transaction_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}



function getWithdrawalItemOptions($conn, $view_all_branches, $branch_id) {
    if (!tableExists($conn, 'items')) return [];

    $item_unit_pricing_exists = tableExists($conn, 'item_unit_pricing') && tableExists($conn, 'unit_types');
    $item_unit_types_exists = tableExists($conn, 'item_unit_types');

    $columns = [];
    $res = $conn->query("SHOW COLUMNS FROM `items`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower($row['Field'])] = $row['Field'];
        }
    }

    $idCol = $columns['item_id'] ?? 'item_id';
    $nameCol = $columns['item_name'] ?? ($columns['name'] ?? 'item_name');
    $codeCol = $columns['item_code'] ?? null;
    $descCol = $columns['description'] ?? null;
    $unitCol = $columns['unit_type'] ?? ($columns['uom'] ?? null);
    $baseUnitCol = $columns['base_unit_type'] ?? null;
    $priceCol = $columns['unit_price'] ?? ($columns['price'] ?? null);
    $statusCol = $columns['status'] ?? null;
    $branchCol = $columns['branch_id'] ?? null;

    $select = "`{$idCol}` AS item_id, `{$nameCol}` AS item_name";
    $select .= $codeCol ? ", `{$codeCol}` AS item_code" : ", '' AS item_code";
    $select .= $descCol ? ", `{$descCol}` AS description" : ", '' AS description";
    $select .= $unitCol ? ", `{$unitCol}` AS unit_type" : ", '' AS unit_type";
    $select .= $baseUnitCol ? ", `{$baseUnitCol}` AS base_unit_type" : ", '' AS base_unit_type";
    $select .= $priceCol ? ", `{$priceCol}` AS unit_price" : ", 0 AS unit_price";

    $sql = "SELECT {$select} FROM `items` WHERE 1=1";
    if ($statusCol) $sql .= " AND (`{$statusCol}` IS NULL OR `{$statusCol}` = '' OR LOWER(`{$statusCol}`) = 'active')";
    if (!$view_all_branches && $branch_id > 0 && $branchCol) $sql .= " AND (`{$branchCol}` = ? OR `{$branchCol}` = 0 OR `{$branchCol}` IS NULL)";
    $sql .= " ORDER BY `{$nameCol}` ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && $branchCol) {
            $bid = (int)$branch_id;
            $stmt->bind_param('i', $bid);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $itemId = (int)($row['item_id'] ?? 0);
            $row['available_uoms'] = [];
            $row['uom_prices'] = [];

            if ($itemId > 0 && $item_unit_pricing_exists) {
                $uomStmt = $conn->prepare("SELECT ut.unit_type_name, iup.unit_price
                                           FROM item_unit_pricing iup
                                           INNER JOIN unit_types ut ON ut.unit_type_id = iup.unit_type_id
                                           WHERE iup.item_id = ?
                                           ORDER BY iup.pricing_id ASC");
                if ($uomStmt) {
                    $uomStmt->bind_param('i', $itemId);
                    $uomStmt->execute();
                    $uomRes = $uomStmt->get_result();
                    while ($uomRow = $uomRes->fetch_assoc()) {
                        $uomName = trim((string)($uomRow['unit_type_name'] ?? ''));
                        if ($uomName === '') continue;
                        if (!in_array($uomName, $row['available_uoms'], true)) $row['available_uoms'][] = $uomName;
                        $row['uom_prices'][strtolower($uomName)] = (float)($uomRow['unit_price'] ?? 0);
                    }
                    $uomStmt->close();
                }
            }

            if ($itemId > 0 && $item_unit_types_exists) {
                $typeStmt = $conn->prepare("SELECT unit_type_name
                                            FROM item_unit_types
                                            WHERE item_id = ? AND (status IS NULL OR status = '' OR LOWER(status) = 'active')
                                            ORDER BY is_default_uom DESC, unit_type_name ASC");
                if ($typeStmt) {
                    $typeStmt->bind_param('i', $itemId);
                    $typeStmt->execute();
                    $typeRes = $typeStmt->get_result();
                    while ($typeRow = $typeRes->fetch_assoc()) {
                        $uomName = trim((string)($typeRow['unit_type_name'] ?? ''));
                        if ($uomName !== '' && !in_array($uomName, $row['available_uoms'], true)) $row['available_uoms'][] = $uomName;
                    }
                    $typeStmt->close();
                }
            }

            foreach ([$row['unit_type'] ?? '', $row['base_unit_type'] ?? ''] as $fallbackUom) {
                $fallbackUom = trim((string)$fallbackUom);
                if ($fallbackUom !== '' && !in_array($fallbackUom, $row['available_uoms'], true)) $row['available_uoms'][] = $fallbackUom;
            }
            if (empty($row['uom_prices']) && trim((string)($row['unit_type'] ?? '')) !== '') {
                $row['uom_prices'][strtolower(trim((string)$row['unit_type']))] = (float)($row['unit_price'] ?? 0);
            }

            $rows[] = $row;
        }
        $stmt->close();
    }
    return $rows;
}


function getWithdrawalPayeeOptions($conn, $view_all_branches, $branch_id) {
    $options = [];
    $seen = [];

    $formatPayeeRole = function($role) {
        $role = trim((string)$role);
        if ($role === '') return '';
        $roleKey = strtolower(str_replace(['-', ' '], '_', $role));
        $roleLabels = [
            'delivery' => 'Driver',
            'driver' => 'Driver',
            'sales' => 'Sales',
            'warehouse' => 'Warehouse',
            'warehouseman' => 'Warehouseman',
            'rolling' => 'Rolling',
            'motorpool' => 'Motorpool',
            'global' => 'Global'
        ];
        return $roleLabels[$roleKey] ?? ucwords(str_replace('_', ' ', $roleKey));
    };

    $isAdminPayeeRole = function($role) {
        $roleKey = strtolower(str_replace(['-', ' '], '_', trim((string)$role)));
        return in_array($roleKey, ['admin', 'branch_admin', 'super_duper_admin'], true) || str_contains($roleKey, 'admin');
    };

    $addOption = function($type, $id, $name, $address = '', $role = '') use (&$options, &$seen, $formatPayeeRole, $isAdminPayeeRole) {
        $name = trim((string)$name);
        $type = trim((string)$type);
        $role = trim((string)$role);
        if ($name === '') return;
        if (strcasecmp($type, 'Employee') === 0 && $isAdminPayeeRole($role)) return;
        $key = strtolower($type . '|' . $name . '|' . $role);
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $roleLabel = (strcasecmp($type, 'Employee') === 0) ? $formatPayeeRole($role) : '';
        $label = $type . ' - ' . $name . ($roleLabel !== '' ? ' (' . $roleLabel . ')' : '');
        $options[] = [
            'type' => $type,
            'id' => (int)$id,
            'name' => $name,
            'address' => trim((string)$address),
            'role' => $roleLabel,
            'label' => $label
        ];
    };

    if (tableExists($conn, 'suppliers')) {
        $nameColumn = columnExists($conn, 'suppliers', 'supplier_name') ? 'supplier_name' : (columnExists($conn, 'suppliers', 'name') ? 'name' : '');
        if ($nameColumn !== '') {
            $addressParts = [];
            foreach (['full_address', 'address', 'street_address'] as $col) {
                if (columnExists($conn, 'suppliers', $col)) $addressParts[] = "NULLIF(TRIM(`{$col}`), '')";
            }
            $addressSelect = !empty($addressParts) ? "COALESCE(" . implode(', ', $addressParts) . ", '')" : "''";
            $sql = "SELECT supplier_id AS id, `{$nameColumn}` AS name, {$addressSelect} AS address FROM suppliers WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";
            if (columnExists($conn, 'suppliers', 'status')) {
                $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
            }
            if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'suppliers', 'branch_id')) {
                $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
            }
            $sql .= " ORDER BY `{$nameColumn}` ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'suppliers', 'branch_id')) {
                    $bid = (int)$branch_id;
                    $stmt->bind_param('i', $bid);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addOption('Supplier', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '');
                }
                $stmt->close();
            }
        }
    }

    if (tableExists($conn, 'customers')) {
        $nameColumn = columnExists($conn, 'customers', 'customer_name') ? 'customer_name' : (columnExists($conn, 'customers', 'name') ? 'name' : '');
        if ($nameColumn !== '') {
            $addressParts = [];
            foreach (['full_address', 'address'] as $col) {
                if (columnExists($conn, 'customers', $col)) $addressParts[] = "NULLIF(TRIM(`{$col}`), '')";
            }
            $addressSelect = !empty($addressParts) ? "COALESCE(" . implode(', ', $addressParts) . ", '')" : "''";
            $sql = "SELECT customer_id AS id, `{$nameColumn}` AS name, {$addressSelect} AS address FROM customers WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";
            if (columnExists($conn, 'customers', 'status')) {
                $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
            }
            if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'customers', 'branch_id')) {
                // Customers in Payee dropdown must only come from the current branch.
                $sql .= " AND branch_id = ?";
            }
            $sql .= " ORDER BY `{$nameColumn}` ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'customers', 'branch_id')) {
                    $bid = (int)$branch_id;
                    $stmt->bind_param('i', $bid);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addOption('Customer', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '');
                }
                $stmt->close();
            }
        }
    }

    if (tableExists($conn, 'employees')) {
        $nameColumn = columnExists($conn, 'employees', 'employee_name') ? 'employee_name' : (columnExists($conn, 'employees', 'name') ? 'name' : '');
        if ($nameColumn !== '') {
            $roleColumn = '';
            foreach (['role', 'employee_role', 'position', 'designation', 'job_title'] as $candidateRoleColumn) {
                if (columnExists($conn, 'employees', $candidateRoleColumn)) {
                    $roleColumn = $candidateRoleColumn;
                    break;
                }
            }
            $roleSelect = $roleColumn !== '' ? ", `{$roleColumn}` AS role_name" : ", '' AS role_name";
            $sql = "SELECT employee_id AS id, `{$nameColumn}` AS name, '' AS address {$roleSelect} FROM employees WHERE TRIM(COALESCE(`{$nameColumn}`, '')) <> ''";
            if (columnExists($conn, 'employees', 'status')) {
                $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
            }
            if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'employees', 'branch_id')) {
                // Employees in Payee dropdown must only come from the current branch.
                $sql .= " AND branch_id = ?";
            }
            $sql .= " ORDER BY `{$nameColumn}` ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'employees', 'branch_id')) {
                    $bid = (int)$branch_id;
                    $stmt->bind_param('i', $bid);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addOption('Employee', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '', $row['role_name'] ?? '');
                }
                $stmt->close();
            }
        }
    } elseif (tableExists($conn, 'users')) {
        $roleSelect = columnExists($conn, 'users', 'role') ? ', role AS role_name' : ", '' AS role_name";
        $sql = "SELECT user_id AS id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name, '' AS address {$roleSelect}
                FROM users
                WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) <> ''";
        if (columnExists($conn, 'users', 'role')) {
            // Do not include admin accounts in the Employee payee list.
            $sql .= " AND LOWER(role) NOT IN ('admin', 'branch_admin', 'super_duper_admin') AND LOWER(role) NOT LIKE '%admin%'";
        }
        if (columnExists($conn, 'users', 'status')) {
            $sql .= " AND (status IS NULL OR status = '' OR LOWER(status) = 'active')";
        }
        if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'users', 'branch_id')) {
            // User fallback for employees must only come from the current branch.
            $sql .= " AND branch_id = ?";
        }
        $sql .= " ORDER BY first_name ASC, last_name ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!$view_all_branches && $branch_id > 0 && columnExists($conn, 'users', 'branch_id')) {
                $bid = (int)$branch_id;
                $stmt->bind_param('i', $bid);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $addOption('Employee', $row['id'] ?? 0, $row['name'] ?? '', $row['address'] ?? '', $row['role_name'] ?? '');
            }
            $stmt->close();
        }
    }

    usort($options, function($a, $b) {
        $typeCompare = strcmp($a['type'], $b['type']);
        if ($typeCompare !== 0) return $typeCompare;
        return strcmp($a['name'], $b['name']);
    });

    return $options;
}


createBankingTables($conn);
createBanksTable($conn);
createBankPaymentMethodsTable($conn);
createSupplierPaymentTable($conn);
ensureExpenseAccountsTableForWithdrawal($conn);
ensureSupplierBeginningBalanceTables($conn);
$withdrawal_items_list = getWithdrawalItemOptions($conn, $view_all_branches, $branch_id);
$withdrawal_payee_options = getWithdrawalPayeeOptions($conn, $view_all_branches, $branch_id);

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

function ensureWithdrawalJournalTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `journal_entries` (
        `journal_id` int(11) NOT NULL AUTO_INCREMENT,
        `entry_no` varchar(100) NOT NULL,
        `journal_date` date NOT NULL,
        `attachment_path` text DEFAULT NULL,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `source_table` varchar(100) DEFAULT NULL,
        `source_id` int(11) DEFAULT NULL,
        `source_type` varchar(100) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`journal_id`),
        KEY `entry_no` (`entry_no`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $journalCols = [
        'attachment_path' => "ALTER TABLE `journal_entries` ADD COLUMN `attachment_path` text DEFAULT NULL AFTER `journal_date`",
        'branch_id' => "ALTER TABLE `journal_entries` ADD COLUMN `branch_id` int(11) NOT NULL DEFAULT 0 AFTER `attachment_path`",
        'source_table' => "ALTER TABLE `journal_entries` ADD COLUMN `source_table` varchar(100) DEFAULT NULL AFTER `branch_id`",
        'source_id' => "ALTER TABLE `journal_entries` ADD COLUMN `source_id` int(11) DEFAULT NULL AFTER `source_table`",
        'source_type' => "ALTER TABLE `journal_entries` ADD COLUMN `source_type` varchar(100) DEFAULT NULL AFTER `source_id`",
        'created_by' => "ALTER TABLE `journal_entries` ADD COLUMN `created_by` int(11) NOT NULL DEFAULT 0 AFTER `source_type`"
    ];
    foreach ($journalCols as $col => $sql) {
        if (!columnExists($conn, 'journal_entries', $col)) @($conn->query($sql));
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `journal_entry_details` (
        `detail_id` int(11) NOT NULL AUTO_INCREMENT,
        `journal_id` int(11) NOT NULL,
        `account_id` int(11) NOT NULL DEFAULT 0,
        `account_title` varchar(255) NOT NULL,
        `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
        `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
        `memo` text DEFAULT NULL,
        `counterparty` varchar(255) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`detail_id`),
        KEY `journal_id` (`journal_id`),
        KEY `account_id` (`account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if (!columnExists($conn, 'journal_entry_details', 'counterparty')) {
        @($conn->query("ALTER TABLE `journal_entry_details` ADD COLUMN `counterparty` varchar(255) DEFAULT NULL AFTER `memo`"));
    }
}

function saveWithdrawalToJournalEntries($conn, $bankAccount, $expenseAccount, $branchId, $transactionDate, $referenceNumber, $memo, $amount, $transactionId, $createdBy, $payee, $attachmentPath) {
    ensureWithdrawalJournalTables($conn);
    $journalDate = date('Y-m-d', strtotime((string)$transactionDate));
    $entryNo = trim((string)$referenceNumber) !== '' ? trim((string)$referenceNumber) : 'WDL-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT);
    $sourceTable = 'bank_transactions';
    $sourceType = 'Withdrawal';

    $headerStmt = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, source_table, source_id, source_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$headerStmt) throw new Exception('Unable to prepare withdrawal journal header: ' . $conn->error);
    $branchId = (int)$branchId;
    $transactionId = (int)$transactionId;
    $createdBy = (int)$createdBy;
    $headerStmt->bind_param('sssisssi', $entryNo, $journalDate, $attachmentPath, $branchId, $sourceTable, $transactionId, $sourceType, $createdBy);
    if (!$headerStmt->execute()) throw new Exception('Unable to save withdrawal journal header: ' . $headerStmt->error);
    $journalId = (int)$conn->insert_id;
    $headerStmt->close();

    $detailStmt = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$detailStmt) throw new Exception('Unable to prepare withdrawal journal details: ' . $conn->error);

    $expenseAccountId = (int)($expenseAccount['account_id'] ?? 0);
    $expenseTitle = (string)($expenseAccount['account_title'] ?? 'Withdrawal Expense');
    $bankAccountId = (int)($bankAccount['account_id'] ?? 0);
    $bankTitle = (string)($bankAccount['account_title'] ?? 'Bank');
    $amount = round((float)$amount, 2);
    $debit = $amount;
    $credit = 0.00;
    $detailStmt->bind_param('iisddss', $journalId, $expenseAccountId, $expenseTitle, $debit, $credit, $memo, $payee);
    if (!$detailStmt->execute()) throw new Exception('Unable to save withdrawal journal debit: ' . $detailStmt->error);

    $debit = 0.00;
    $credit = $amount;
    $detailStmt->bind_param('iisddss', $journalId, $bankAccountId, $bankTitle, $debit, $credit, $memo, $payee);
    if (!$detailStmt->execute()) throw new Exception('Unable to save withdrawal journal credit: ' . $detailStmt->error);
    $detailStmt->close();
}

$flash_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_url = 'Withdrawal.php';
    try {
        if ($action === 'create_withdrawal') {
            $transaction_date_input = trim($_POST['transaction_date'] ?? '');
            if (empty($transaction_date_input)) throw new Exception('Transaction date is required.');
            $transaction_date = date('Y-m-d 00:00:00', strtotime($transaction_date_input));
            $reference_number = trim($_POST['reference_number'] ?? '');
            if ($reference_number === '') throw new Exception('Reference number is required.');
            $check_number = trim($_POST['check_number'] ?? '');
            $bank_id = (int)($_POST['bank_id'] ?? 0);
            $description = trim($_POST['description'] ?? 'Bank withdrawal');
            $amount = (float)str_replace([',', '₱', ' '], '', (string)($_POST['amount'] ?? 0));
            $expense_account = trim($_POST['expense_account'] ?? '');
            $payee = trim($_POST['payee'] ?? '');
            $withdrawal_item_rows = collectWithdrawalItemRowsFromPost($conn);
            $withdrawal_expense_rows = collectWithdrawalExpenseRowsFromPost($conn, $view_all_branches, $branch_id);
            $is_items_withdrawal = !empty($withdrawal_item_rows);
            $items_total_amount = 0.00;
            foreach ($withdrawal_item_rows as $itemRow) $items_total_amount += (float)($itemRow['total'] ?? 0);
            $items_total_amount = round($items_total_amount, 2);
            $expense_total_amount = 0.00;
            foreach ($withdrawal_expense_rows as $expenseRow) $expense_total_amount += (float)($expenseRow['amount'] ?? 0);
            $expense_total_amount = round($expense_total_amount, 2);
            if ($bank_id <= 0) throw new Exception('Please select a bank account.');
            if ($is_items_withdrawal && $items_total_amount > 0) $amount = $items_total_amount;
            elseif (!$is_items_withdrawal && $expense_total_amount > 0) $amount = $expense_total_amount;
            if ($amount <= 0) throw new Exception('Withdrawal amount must be greater than zero.');
            if (!$is_items_withdrawal && empty($withdrawal_expense_rows)) throw new Exception('Please add at least one expense row.');
            if ($payee === '') throw new Exception('Payee is required.');
            $selected_expense_account = null;
            if (!$is_items_withdrawal) {
                $expenseLabelsForSummary = array_map(fn($row) => $row['account_title'] ?? $row['account_label'] ?? 'Expense', $withdrawal_expense_rows);
                $uniqueExpenseLabels = array_values(array_unique($expenseLabelsForSummary));
                $expense_account = implode(', ', array_slice($uniqueExpenseLabels, 0, 5));
                if (count($uniqueExpenseLabels) > 5) $expense_account .= '...';
                if ($description === '' || $description === 'Bank withdrawal') {
                    $firstMemo = trim((string)($withdrawal_expense_rows[0]['memo'] ?? ''));
                    $firstDescription = trim((string)($withdrawal_expense_rows[0]['description'] ?? ''));
                    $description = $firstMemo !== '' ? $firstMemo : ($firstDescription !== '' ? $firstDescription : 'Expense withdrawal');
                }
            } else {
                $itemNamesForSummary = array_map(fn($row) => $row['item_name'] ?? 'Item', $withdrawal_item_rows);
                $expense_account = 'Items: ' . implode(', ', array_slice($itemNamesForSummary, 0, 5));
                if (count($itemNamesForSummary) > 5) $expense_account .= '...';
                if ($description === '' || $description === 'Bank withdrawal') $description = 'Item withdrawal';
            }

            $bank_sql = "SELECT account_id, account_title, balance FROM chart_of_accounts WHERE account_id = ? AND account_type = 'Bank' AND status = 'active'";
            if (!$view_all_branches && $branch_id > 0) $bank_sql .= " AND (branch_id = ? OR branch_id = 0)";
            $bank_sql .= " LIMIT 1";
            $bank_stmt = $conn->prepare($bank_sql);
            if (!$bank_stmt) throw new Exception('Unable to verify selected bank account.');
            if (!$view_all_branches && $branch_id > 0) $bank_stmt->bind_param('ii', $bank_id, $branch_id); else $bank_stmt->bind_param('i', $bank_id);
            $bank_stmt->execute();
            $bank_row = $bank_stmt->get_result()->fetch_assoc();
            $bank_stmt->close();
            if (!$bank_row) throw new Exception('Please select a Bank account from Chart of Accounts.');
            assertWithdrawalSelectableCoaAccount($conn, $bank_id, $branch_id, 'Bank Account');
            $bank_name = trim($bank_row['account_title'] ?? '');

            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, check_number, bank_name, bank_id, description, expense_account, payee, amount, created_by) VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Failed to prepare withdrawal transaction: ' . $conn->error);
            $stmt->bind_param('issssisssdi', $effective_branch_id, $transaction_date, $reference_number, $check_number, $bank_name, $bank_id, $description, $expense_account, $payee, $amount, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to save withdrawal transaction: ' . $stmt->error);
            $withdrawal_transaction_id = (int)$stmt->insert_id;
            $stmt->close();

            $savedWithdrawalAttachments = saveWithdrawalAttachments($conn, $withdrawal_transaction_id, $effective_branch_id, $user_id);
            $withdrawalAttachmentPath = !empty($savedWithdrawalAttachments) ? json_encode($savedWithdrawalAttachments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            postWithdrawalToChartOfAccounts($conn, $bank_id, $effective_branch_id, $transaction_date, $reference_number, $description, $amount, $withdrawal_transaction_id, $user_id, $payee, (string)$withdrawalAttachmentPath);
            $journalDebitRows = [];
            if ($is_items_withdrawal) {
                postWithdrawalItemsToChartOfAccounts($conn, $withdrawal_item_rows, $effective_branch_id, $transaction_date, $reference_number, $description, $withdrawal_transaction_id, $user_id, $payee, (string)$withdrawalAttachmentPath);
                foreach ($withdrawal_item_rows as $itemRow) {
                    $itemAccount = resolveItemAssetAccountForWithdrawal($conn, (int)($itemRow['item_id'] ?? 0), $effective_branch_id);
                    if ($itemAccount) $journalDebitRows[] = ['account'=>$itemAccount, 'amount'=>(float)($itemRow['total'] ?? 0), 'memo'=>trim(($description ? $description . ' | ' : '') . 'Item: ' . ($itemRow['item_name'] ?? 'Item'))];
                }
            } else {
                foreach ($withdrawal_expense_rows as $expenseRow) {
                    postWithdrawalExpenseAccountToChartOfAccounts($conn, (int)$expenseRow['account_id'], $effective_branch_id, $transaction_date, $reference_number, ($expenseRow['memo'] ?: $description), (float)$expenseRow['amount'], $withdrawal_transaction_id, $user_id, $payee, (string)$withdrawalAttachmentPath);
                    $expenseAccountForJournal = resolveChartAccountByIdForWithdrawal($conn, (int)$expenseRow['account_id'], $effective_branch_id);
                    if ($expenseAccountForJournal) $journalDebitRows[] = ['account'=>$expenseAccountForJournal, 'amount'=>(float)$expenseRow['amount'], 'memo'=>($expenseRow['memo'] ?: $description)];
                }
            }
            $bankAccountForJournal = resolveChartAccountByIdForWithdrawal($conn, $bank_id, $effective_branch_id);
            if ($bankAccountForJournal && !empty($journalDebitRows)) {
                saveWithdrawalToJournalEntriesMulti($conn, $bankAccountForJournal, $journalDebitRows, $effective_branch_id, $transaction_date, $reference_number, $description, $amount, $withdrawal_transaction_id, $user_id, $payee, (string)$withdrawalAttachmentPath);
            }
            $conn->commit();
            $_SESSION['success_message'] = 'Withdrawal transaction saved successfully.';
            if (isset($_POST['save_new']) && $_POST['save_new'] === '1') {
                $redirect_url = 'Withdrawal.php?open_withdrawal=1&clear_withdrawal=1';
            }
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
            $selected_payment_method = trim($_POST['payment_method'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $payment_date_input = trim($_POST['payment_date'] ?? '');
            if (empty($payment_date_input)) throw new Exception('Payment date is required.');
            $payment_date = date('Y-m-d 00:00:00', strtotime($payment_date_input));

            if ($selected_bank_id <= 0) throw new Exception('Please select a bank account.');
            if ($selected_payment_method !== '' && !in_array($selected_payment_method, ['check','online_transfer','cash'], true)) throw new Exception('Please select a valid payment method.');
            if ($amount <= 0) throw new Exception('Payment amount must be greater than zero.');

            $actual_bank_id = $selected_bank_id;

            $bank_sql = "SELECT account_id, account_title AS bank_name, description AS bank_branch
                         FROM chart_of_accounts
                         WHERE account_id = ? AND account_type = 'Bank' AND status = 'active'";
            if (!$view_all_branches && $branch_id > 0) {
                $bank_sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
            }
            $bank_sql .= " LIMIT 1";
            $bank_stmt = $conn->prepare($bank_sql);
            if (!$bank_stmt) throw new Exception('Unable to verify selected bank account.');
            if (!$view_all_branches && $branch_id > 0) {
                $bank_stmt->bind_param('ii', $actual_bank_id, $branch_id);
            } else {
                $bank_stmt->bind_param('i', $actual_bank_id);
            }
            $bank_stmt->execute();
            $bank = $bank_stmt->get_result()->fetch_assoc();
            $bank_stmt->close();
            if (!$bank) throw new Exception('Please select an active Bank account from Chart of Accounts.');
            assertWithdrawalSelectableCoaAccount($conn, $actual_bank_id, $branch_id, 'Bank Account');
            $bank_name = $bank['bank_name'];
            $bank_branch = $bank['bank_branch'] ?? '';

            $methods = getBankPaymentMethods($conn, $actual_bank_id);
            if ($selected_payment_method !== '' && !empty($methods) && !in_array($selected_payment_method, $methods, true)) {
                throw new Exception('This bank does not support the selected payment method.');
            }

            $reference_number = trim($_POST['reference_number'] ?? '');
            if ($reference_number === '') throw new Exception('Reference number is required.');
            $check_date = null;
            $check_number = null;
            $cash_tendered = null;
            $cash_change = null;

            if ($selected_payment_method === 'check') {
                $check_date = trim($_POST['check_date'] ?? '');
                $check_number = trim($_POST['check_number'] ?? '');
                if ($check_date === '' || $check_number === '') throw new Exception('Check date and check number are required.');
            } elseif ($selected_payment_method === 'online_transfer') {
                if ($reference_number === '') throw new Exception('Reference number is required for online transfer.');
                $check_date = null;
                $check_number = null;
            } else {
                $check_date = null;
                $check_number = null;
            }

            // Payment Method is optional. If left blank, save as cash internally so existing supplier_payments enum remains valid.
            $payment_method_mapped = ($selected_payment_method === 'check') ? 'check' : (($selected_payment_method === 'online_transfer') ? 'online_transfer' : 'cash');
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;

            if ($payable_type === 'expense') {
                $expense_id = (int)($_POST['expense_id'] ?? 0);
                if ($expense_id <= 0) throw new Exception('Invalid expense selected.');
                $payable = getExpensePayableById($conn, $expense_id, $view_all_branches, $branch_id);
                if (!$payable) throw new Exception('Expense payable not found or already fully paid.');

                $balance = (float)$payable['balance'];
                if ($amount > ($balance + 0.009)) throw new Exception('Payment amount cannot be greater than the remaining payable balance.');

                $conn->begin_transaction();
                $description = 'Expense payment - ' . ($payable['expense_no'] ?? ('EXP-' . (int)$payable['expense_id'])) . ' / ' . ($payable['account_summary'] ?? $payable['account'] ?? 'Expense');

                $bt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, check_number, bank_name, bank_id, description, expense_account, payee, amount, created_by) VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$bt) throw new Exception('Failed to prepare bank transaction: ' . $conn->error);
                $payee = $payable['expense_no'] ?? ('EXP-' . (int)$payable['expense_id']);
                $expense_account_name = $payable['account_summary'] ?? $payable['account'] ?? 'Expense';
                $bt->bind_param('issssisssdi', $effective_branch_id, $payment_date, $reference_number, $check_number, $bank_name, $actual_bank_id, $description, $expense_account_name, $payee, $amount, $user_id);
                if (!$bt->execute()) throw new Exception('Failed to save bank transaction: ' . $bt->error);
                $bank_transaction_id = (int)$conn->insert_id;
                $bt->close();

                $ep = $conn->prepare("INSERT INTO billexpense_payments (expense_id, branch_id, amount, payment_date, reference_number, check_number, bank_name, bank_id, bank_transaction_id, memo, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$ep) throw new Exception('Failed to prepare expense payment: ' . $conn->error);
                $memo = $payable['memo'] ?? ($payable['account_summary'] ?? '');
                $ep->bind_param('iidssssiisi', $expense_id, $effective_branch_id, $amount, $payment_date, $reference_number, $check_number, $bank_name, $actual_bank_id, $bank_transaction_id, $memo, $user_id);
                if (!$ep->execute()) throw new Exception('Failed to save expense payment: ' . $ep->error);
                $ep->close();

                postPayablePaymentToChartOfAccounts(
                    $conn,
                    $payable['payable_account'] ?? 'Accounts Payable',
                    $actual_bank_id,
                    $bank_name,
                    $effective_branch_id,
                    $payment_date,
                    $reference_number ?: ($payable['expense_no'] ?? ('EXP-' . $expense_id)),
                    $description,
                    'billexpenses',
                    $expense_id,
                    $amount,
                    $user_id
                );

                $new_balance = $balance - $amount;
                $new_status = ($new_balance <= 0.009) ? 'paid' : 'partial';
                $update_expense = $conn->prepare("UPDATE billexpenses SET status = ?, balance = ?, updated_at = NOW() WHERE expense_id = ?");
                if ($update_expense) {
                    $update_expense->bind_param('sdi', $new_status, $new_balance, $expense_id);
                    $update_expense->execute();
                    $update_expense->close();
                }

                $conn->commit();
                $_SESSION['success_message'] = 'Expense payment recorded successfully.';
            }
            elseif ($payable_type === 'beginning_balance') {
                if ($beginning_balance_id <= 0) throw new Exception('Invalid supplier beginning balance selected.');
                $payable = getSupplierBeginningBalanceById($conn, $beginning_balance_id, $view_all_branches, $branch_id);
                if (!$payable) throw new Exception('Supplier beginning balance not found or already fully paid.');

                $balance = (float)$payable['balance'];
                if ($amount > ($balance + 0.009)) throw new Exception('Payment amount cannot be greater than the remaining payable balance.');

                $conn->begin_transaction();

                $description = 'Supplier beginning balance payment - ' . ($payable['supplier_name'] ?? 'Supplier') . ' / ' . ($payable['document_no'] ?? ('BB-' . $beginning_balance_id));

                $bt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, check_number, bank_name, bank_id, description, expense_account, payee, amount, created_by) VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, 'Supplier Beginning Balance', ?, ?, ?)");
                if (!$bt) throw new Exception('Failed to prepare bank transaction: ' . $conn->error);
                $bt->bind_param('issssissdi', $effective_branch_id, $payment_date, $reference_number, $check_number, $bank_name, $actual_bank_id, $description, $payable['supplier_name'], $amount, $user_id);
                if (!$bt->execute()) throw new Exception('Failed to save bank transaction: ' . $bt->error);
                $bank_transaction_id = (int)$conn->insert_id;
                $bt->close();

                $supplier_id = isset($payable['supplier_id']) && $payable['supplier_id'] !== null ? (int)$payable['supplier_id'] : null;
                $sp = $conn->prepare("INSERT INTO supplier_payments (po_id, beginning_balance_id, supplier_id, branch_id, payment_method, amount, payment_date, reference_number, check_date, bank_name, bank_branch, check_number, cash_tendered, cash_change, bank_transaction_id, created_by) VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$sp) throw new Exception('Failed to prepare supplier beginning balance payment: ' . $conn->error);
                $sp->bind_param('iiisdssssssddii', $beginning_balance_id, $supplier_id, $effective_branch_id, $payment_method_mapped, $amount, $payment_date, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change, $bank_transaction_id, $user_id);
                if (!$sp->execute()) throw new Exception('Failed to save supplier beginning balance payment: ' . $sp->error);
                $sp->close();

                postPayablePaymentToChartOfAccounts(
                    $conn,
                    'Accounts Payable',
                    $actual_bank_id,
                    $bank_name,
                    $effective_branch_id,
                    $payment_date,
                    $reference_number ?: ($payable['document_no'] ?? ('BB-' . $beginning_balance_id)),
                    $description,
                    'supplier_beginning_balances',
                    $beginning_balance_id,
                    $amount,
                    $user_id
                );

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

                $bt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, check_number, bank_name, bank_id, description, expense_account, payee, amount, created_by) VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, 'Supplier Payment', ?, ?, ?)");
                if (!$bt) throw new Exception('Failed to prepare bank transaction: ' . $conn->error);
                $bt->bind_param('issssissdi', $effective_branch_id, $payment_date, $reference_number, $check_number, $bank_name, $actual_bank_id, $description, $payable['display_supplier_name'], $amount, $user_id);
                if (!$bt->execute()) throw new Exception('Failed to save bank transaction: ' . $bt->error);
                $bank_transaction_id = (int)$conn->insert_id;
                $bt->close();

                $supplier_id = isset($payable['supplier_id']) && $payable['supplier_id'] !== null ? (int)$payable['supplier_id'] : null;
                $sp = $conn->prepare("INSERT INTO supplier_payments (po_id, supplier_id, branch_id, payment_method, amount, payment_date, reference_number, check_date, bank_name, bank_branch, check_number, cash_tendered, cash_change, bank_transaction_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$sp) throw new Exception('Failed to prepare supplier payment: ' . $conn->error);
                $sp->bind_param('iiisdssssssddii', $po_id, $supplier_id, $effective_branch_id, $payment_method_mapped, $amount, $payment_date, $reference_number, $check_date, $bank_name, $bank_branch, $check_number, $cash_tendered, $cash_change, $bank_transaction_id, $user_id);
                if (!$sp->execute()) throw new Exception('Failed to save supplier payment: ' . $sp->error);
                $sp->close();

                postPayablePaymentToChartOfAccounts(
                    $conn,
                    $payable['payable_account'] ?? 'Accounts Payable',
                    $actual_bank_id,
                    $bank_name,
                    $effective_branch_id,
                    $payment_date,
                    $reference_number ?: ($payable['po_number'] ?? ('PO-' . $po_id)),
                    $description,
                    'purchase_orders',
                    $po_id,
                    $amount,
                    $user_id
                );

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
    header('Location: ' . $redirect_url);
    exit();
}

$banks = getBanks($conn, $view_all_branches, $branch_id, true, true);
$all_banks_for_mapping = getAllBanksForMapping($conn, $view_all_branches, $branch_id);

$available_payments = getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$recent_payments = getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$bank_transactions = getBankTransactions($conn, $view_all_branches, $branch_id);
$supplier_payables = getSupplierPayables($conn, $view_all_branches, $branch_id, $po_branch_column_exists, $po_supplier_id_exists);
$supplier_beginning_balance_payables = getSupplierBeginningBalancePayables($conn, $view_all_branches, $branch_id);
$expense_payables = getExpensePayables($conn, $view_all_branches, $branch_id);
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
        'reference' => h($tx['reference_number'] ?: ($tx['check_number'] ?: '—')),
        'check_number' => h($tx['check_number'] ?: ''),
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
foreach ($banks as $bank) {
    $bank_balance_by_id[(int)$bank['bank_id']] = (float)($bank['balance'] ?? 0);
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
foreach ($expense_payables as $row) $total_supplier_payables += (float)$row['balance'];
$total_supplier_vendor_payables_count = count($supplier_payables) + count($supplier_beginning_balance_payables) + count($expense_payables);

$page_title = 'Write Check';
$page_subtitle = 'Write Check, Expenses, Items & Payment History';
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
    color:#fff;
    font-size:1.25rem;
}
.section-card{
    background:#fff;
    border-radius:18px;
    border:1px solid rgba(68,211,78,.12);
    box-shadow:0 8px 20px rgba(15,23,42,.05);
    margin-bottom:1rem;
    overflow:hidden;
}
.section-header{
    padding:1rem 1.25rem;
    border-bottom:1px solid #eef2f7;
    display:flex;justify-content:space-between;
    align-items:center;
    gap:1rem;
}
.section-body{
    padding:1rem 1.25rem;
}
.badge-soft-green{
    background:rgba(68,211,78,.16);
    color:#047857;
}
.badge-soft-blue{
    background:rgba(34,211,238,.15);
    color:#0f766e;
}
.badge-soft-red{
    background:rgba(248,113,113,.14);
    color:#b91c1c;
}
.badge-soft-yellow{
    background:rgba(251,191,36,.18);
    color:#92400e;
}
.table thead th{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
    color:#fff!important;
    border:none;
    white-space:nowrap;
    font-size:.84rem;
}
.table tbody td{
    vertical-align:middle;
    font-size:.92rem;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:200px;
}
.collector-name{
    font-weight:600;
    color:#052A47;
}
.amount-positive{
    color:#047857;
    font-weight:700;
}
.amount-negative{
    color:#dc2626;
    font-weight:700;
}
.amount-neutral{
    color:#052A47;
    font-weight:700;
}
.payment-select-box{
    max-height:500px;
    overflow:auto;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:.75rem;
    background:#f9fafb;
}
.payment-option{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:.75rem;
    margin-bottom:.6rem;
}
.form-control,.form-select{
    border-radius:10px;
    min-height:44px;
}
.btn-amgc-primary{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
    color:#fff;
    border:none;
    border-radius:999px;
    padding:8px 18px;
    min-height:36px;
    font-weight:600;
    font-size:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    box-shadow:0 4px 10px rgba(0,0,0,.15);
    transition:all .2s ease;
}
.btn-amgc-primary:hover{
    color:#fff;
    transform:translateY(-1px);
    box-shadow:0 6px 14px rgba(0,0,0,.2);
    opacity:.95;
}
.btn-amgc-dark{
    background:#047857;
    color:#fff;
    border:none;
    border-radius:999px;
    padding:8px 18px;
    min-height:36px;
    font-weight:600;
    font-size:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 4px 10px rgba(0,0,0,.15);
    transition:all .2s ease;
}
.btn-amgc-dark:hover{
    color:#fff;
    transform:translateY(-1px);
    box-shadow:0 6px 14px rgba(0,0,0,.2);
    opacity:.96;
}
.nav-tabs .nav-link{
    font-weight:700;
    color:#052A47;
}
.nav-tabs .nav-link.active{
    color:#047857;
}
.navbar-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:1.5rem;
}
.mobile-menu-btn{
    display:none;
    background:transparent;
    border:none;
    font-size:1.5rem;
    color:#052A47;
}
.qb-payee-select-wrap{
    width:100%;
    display:flex;
    flex-direction:column;
    gap:6px;
}
.qb-payee-search-input{
    height:31px!important;
    border:1px solid #c7cdd1!important;
    border-radius:3px!important;
    background:#fff!important;
    font-size:13px!important;
}
.qb-payee-select-wrap .form-select{
    height:31px!important;
    border:1px solid #c7cdd1!important;
    border-radius:3px!important;
    background-color:#f1f2f4!important;
    font-size:14px!important;
}
@media(max-width:992px){
    .sidebar{
        transform:translateX(-100%);
    }
    .sidebar.active{
        transform:translateX(0);
    }
    .main-content{
        margin-left:0!important;
    }
    .mobile-menu-btn{
        display:block;
    }
    body{
        padding-bottom:70px;
    }
}
@media(max-width:768px){
    .table-responsive{
        overflow-x:auto;
    }
    .section-header{
        display:block;
    }
    .stat-value{
        font-size:1.2rem;
    }
}
.clickable-row{
    cursor:pointer;
    transition:background .15s;
}
.clickable-row:hover{
    background:#f1f5f9!important;
}
.transaction-summary-card{
    background:#e8f5e9;
    border:1px solid rgba(68,211,78,.25);
    border-radius:16px;
    padding:1rem;
    margin-bottom:1rem;
}
.transaction-detail-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:.75rem;
}
.transaction-detail-item{
    border:1px solid #eef2f7;
    border-radius:12px;
    padding:.75rem;
    background:#fff;
}
.transaction-detail-label{
    font-size:.75rem;
    color:#6b7280;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.02em;
    margin-bottom:.25rem;
}
.transaction-detail-value{
    font-size:.95rem;
    color:#052A47;
    font-weight:700;
    word-break:break-word
}
#paymentDetailsModal .payment-details-wide-modal{
    max-width:1180px!important;
    width:calc(100% - 2rem)!important;
}
#paymentDetailsModal .modal-body{
    overflow:visible!important;
}
#paymentDetailsModal .transaction-detail-grid{
    grid-template-columns:repeat(3,minmax(0,1fr));
}
#paymentDetailsModal .transaction-detail-item{
    min-width:0;
}
.withdrawal-attachment-open{
    border:0;
    background:transparent;
    color:#047857;
    font-weight:700;
    padding:0;
    text-align:left;
    text-decoration:none
}
.withdrawal-attachment-open:hover{
    text-decoration:underline;
    color:#052A47;
}
#withdrawalAttachmentViewModal .modal-dialog{
    max-width:980px!important;
    width:calc(100% - 2rem)!important;
}
#withdrawalAttachmentViewModal .modal-content{
    border-radius:18px;
    border:none;
    overflow:hidden;
}
#withdrawalAttachmentViewModal .modal-header{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
    color:#fff;
}
#withdrawalAttachmentViewModal .attachment-viewer-frame{
    width:100%;
    height:72vh;
    border:0;
    background:#f8fafc;
    border-radius:12px;
}
#withdrawalAttachmentViewModal .attachment-viewer-image{
    max-width:100%;
    max-height:72vh;
    display:block;
    margin:0 auto;
    border-radius:12px;
}
#withdrawalAttachmentViewModal .attachment-download-box{
    border:1px dashed rgba(4,120,87,.35);
    border-radius:14px;
    padding:1.25rem;
    background:#f8fafc;
    text-align:center;
}
@media(max-width:992px){
    #paymentDetailsModal .transaction-detail-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}
@media(max-width:768px){
    .transaction-detail-grid{
        grid-template-columns:1fr;
    }
    #paymentDetailsModal .payment-details-wide-modal{
        width:calc(100% - 1rem)!important;
    }
    #paymentDetailsModal .transaction-detail-grid{
        grid-template-columns:1fr;
    }#withdrawalAttachmentViewModal .modal-dialog{
        width:calc(100% - 1rem)!important;
    }#withdrawalAttachmentViewModal .attachment-viewer-frame{
        height:65vh;
    }
}
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

#supplierBeginningBalanceModal .modal-dialog{
    margin:1rem auto!important;
    max-width:900px!important;
}
#supplierBeginningBalanceModal .modal-content{
    border:none!important;
    border-radius:24px!important;
    overflow:hidden!important;
    box-shadow:0 20px 40px rgba(0,0,0,.2)!important;
    max-height:90vh!important;
    display:flex!important;
    flex-direction:column!important;
}
#supplierBeginningBalanceModal .modal-header{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%)!important;
    color:white!important;
    border-bottom:none!important;
    padding:1rem 1.25rem!important;
}
#supplierBeginningBalanceModal .modal-title{
    font-weight:600!important;
    font-size:1.1rem!important;
    display:flex!important;
    align-items:center!important;
    gap:8px!important;
    color:white!important;
}
#supplierBeginningBalanceModal .btn-close{
    filter:invert(1);
    opacity:1;
}
#supplierBeginningBalanceModal .modal-body{
    padding:1.5rem!important;
    overflow-y:auto!important;
    background:#f8fafc!important;
}
#supplierBeginningBalanceModal .form-label{
    font-weight:600!important;
    font-size:.8rem!important;
    color:#1f2937!important;
}
#supplierBeginningBalanceModal .form-control,#supplierBeginningBalanceModal .form-select{
    border-radius:10px!important;
    border:1px solid #e2e8f0!important;
    padding:.6rem .75rem!important;
    font-size:.85rem!important;
}
#supplierBeginningBalanceModal .form-control:focus,#supplierBeginningBalanceModal .form-select:focus{
    border-color:#44D34E!important;
    box-shadow:0 0 0 3px rgba(68,211,78,.1)!important;
}
@media(max-width:768px){
    #supplierBeginningBalanceModal .modal-dialog{
    margin:.75rem auto!important;
    max-width:calc(100% - 1.5rem)!important;
    width:calc(100% - 1.5rem)!important;
    }
}

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

/* QuickBooks-style New Withdrawal modal */
.qb-withdrawal-modal{
    border:0;
    border-radius:14px;
    overflow:hidden;
    background:#fff;
    font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.qb-toolbar{
    background:#f3f6f8;
    border-bottom:1px solid #d9e1e6;
    color:#052A47;
    padding:12px 18px;
}
.qb-body{
    background:#fff;
    padding:0 14px 14px 14px;
}
.qb-topbar{
    display:flex;
    gap:90px;
    align-items:center;
    background:#fff;
    padding:12px 0 6px;
}
.qb-bank-wrap,.qb-ending-balance{
    display:flex;
    align-items:center;
    background:#fff200;
    padding:7px 9px;
    min-height:40px;
}
.qb-bank-wrap{
    width:410px;
    gap:14px;
}
.qb-bank-wrap label,.qb-ending-balance label{
    font-size:12px;
    color:#047857;
    margin:0;
    white-space:nowrap;
}
.qb-bank-wrap select{
    height:31px;
    border:1px solid #94a3b8;
    border-radius:3px;
    background:#e9ff00;
    font-weight:700;
    color:#052A47;
}
.qb-ending-balance{
    width:340px;
    gap:35px;
}
.qb-ending-balance strong{
    font-size:21px;
    color:#000;
    line-height:1;
}
.qb-check-panel{
    display:flex;
    min-height:330px;
    border:1px solid #d7ead7;
    background:#f6fff2;
    background-image:repeating-linear-gradient(45deg,rgba(4,120,87,.035) 0,rgba(4,120,87,.035) 2px,transparent 2px,transparent 8px);
    padding:44px 36px 18px;
}
.qb-check-left{
    flex:1;
    padding-right:28px;
}
.qb-check-right{
    width:250px;
}
.qb-payee-row{
    display:grid;
    grid-template-columns:150px 1fr;
    align-items:center;
    gap:14px;
}
.qb-check-panel label{
    font-size:12px;
    color:#54616b;
    margin:0;
}
.qb-check-panel .form-control{
    height:31px;
    border:1px solid #c7cdd1;
    border-radius:3px;
    background:#f1f2f4;
    font-size:14px;
}
.qb-dollars-line{
    height:45px;
    border-bottom:1px solid #63706c;
    margin:0 42px 8px 0;
    color:#052A47;
    font-size:13px;
    padding:0 0 4px 0;
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:12px;
}
.qb-amount-words{
    font-size:18px!important;
    font-weight:700!important;
    line-height:1.45;
    text-transform:uppercase;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    min-width:0;
    letter-spacing:.2px;
    color:#052A47;
}
.qb-pesos-label{
    color:#54616b;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.qb-address-row textarea{
    width:290px;
    resize:none;
    background:#f1f2f4!important;
}
.qb-memo-row{
    display:grid;grid-template-columns:60px 1fr;
    align-items:center;
    gap:10px;
    margin-top:8px;
}
.qb-mini-field{
    display:grid;
    grid-template-columns:45px 1fr;
    align-items:center;
    gap:8px;
    margin-bottom:10px;
}
.qb-class-field{
    display:grid;
    grid-template-columns:60px 1fr;
    align-items:center;
    gap:10px;
    margin-top:148px;
}
.qb-tabs{
    display:flex;
    align-items:flex-end;
    margin-top:14px;
}
.qb-tab{
    border:1px solid #d6d6d6;
    border-bottom:0;
    background:#d9d9d9;
    color:#052A47;
    font-weight:600;
    padding:6px 12px;
    min-width:150px;
    text-align:left;
}
.qb-tab.active{
    background:#fff;
}
.qb-tab span{
    float:right;
    font-weight:700;
}
.qb-expense-table-wrap{
    border:1px solid #d6d6d6;
    max-height:310px;
    overflow:auto;
}
.qb-expense-table thead th{
    font-size:13px;
    color:#737f89;
    font-weight:500;
    background:#fff;
    border-bottom:1px solid #cbd5dc;
    padding:7px;
}
.qb-expense-table tbody td{
    height:35px;
    border-right:1px solid #d9e1e6;
    padding:3px 5px;
}
.qb-expense-table tbody tr:nth-child(odd){
    background:#eaf4ff;
}
.qb-expense-table tbody tr:nth-child(even){
    background:#fff;
}
.qb-table-select,.qb-table-input{
    border:0!important;
    background:transparent!important;
    height:28px!important;
    padding:2px 6px!important;
    box-shadow:none!important;
}
.qb-footer{
    background:#f8fafc;
    border-top:1px solid #e5e7eb;
}
.qb-footer .btn{
    min-width:135px;
    font-weight:700;
}
@media(max-width:992px){
    .qb-topbar{
        flex-direction:column;
        align-items:stretch;
        gap:8px;
    }
    .qb-bank-wrap,.qb-ending-balance{
        width:100%;
    }
    .qb-check-panel{
        flex-direction:column;
        padding:22px 16px;
    }
    .qb-check-right{
        width:100%;
        margin-top:16px;
    }
    .qb-class-field{
        margin-top:10px;
    }
    .qb-payee-row{
        grid-template-columns:1fr;
    }
    .qb-address-row textarea{
        width:100%;
    }
}

/* New Withdrawal modal final UI adjustment: purchase_order.php-style modal size + clean AMGC palette */
#withdrawalModal .modal-dialog{
    max-width:min(96vw,1500px)!important;
    width:min(96vw,1500px)!important;
    margin:1rem auto!important;
}
#withdrawalModal .modal-content.qb-withdrawal-modal{
    border:0!important;
    border-radius:16px!important;
    overflow:hidden!important;
    box-shadow:0 18px 45px rgba(5,42,71,.18)!important;
    max-height:94vh!important;
    background:#ffffff!important;
}
#withdrawalModal .modal-header.qb-toolbar{
    background:linear-gradient(135deg,#052A47 0%,#047857 100%)!important;
    color:#ffffff!important;
    border-bottom:0!important;
    padding:1rem 1.25rem!important;
}
#withdrawalModal .modal-header.qb-toolbar .modal-title,
#withdrawalModal .modal-header.qb-toolbar .modal-title i{
    color:#ffffff!important;
    font-weight:700!important;
}
#withdrawalModal .modal-header.qb-toolbar .btn-close{
    filter:invert(1) grayscale(100%) brightness(200%);
    opacity:1!important;
}
#withdrawalModal .modal-body.qb-body{
    background:#ffffff!important;
    padding:1rem 1.1rem 1.15rem!important;
    max-height:none!important;
    overflow-y:auto!important;
}
#withdrawalModal .qb-topbar{
    display:grid!important;
    grid-template-columns:minmax(420px,1fr) minmax(320px,420px)!important;
    gap:1rem!important;
    align-items:center!important;
    background:#ffffff!important;
    padding:0 0 .8rem!important;
}
#withdrawalModal .qb-bank-wrap,
#withdrawalModal .qb-ending-balance{
    width:100%!important;
    min-height:44px!important;
    border-radius:10px!important;
    border:1px solid rgba(4,120,87,.18)!important;
    background:#f0fdf4!important;
    padding:.55rem .75rem!important;
}
#withdrawalModal .qb-bank-wrap{
    display:grid!important;
    grid-template-columns:125px 1fr!important;
    gap:.75rem!important;
}
#withdrawalModal .qb-bank-wrap label,
#withdrawalModal .qb-ending-balance label{
    color:#047857!important;
    font-weight:800!important;
    letter-spacing:.02em!important;
}
#withdrawalModal .qb-bank-wrap select{
    height:34px!important;
    background:#ffffff!important;
    border:1px solid #cbd5e1!important;
    border-radius:8px!important;
    color:#052A47!important;
    font-weight:700!important;
}
#withdrawalModal .qb-ending-balance{
    display:flex!important;
    justify-content:space-between!important;
    gap:1rem!important;
}
#withdrawalModal .qb-ending-balance strong{
    font-size:1.35rem!important;
    color:#052A47!important;
}
#withdrawalModal .qb-check-panel{
    min-height:310px!important;
    border:1px solid #dbeafe!important;
    border-radius:14px!important;
    padding:2rem 2rem 1.1rem!important;
    background:#f8fffb!important;
    background-image:repeating-linear-gradient(45deg,rgba(4,120,87,.035) 0,rgba(4,120,87,.035) 2px,transparent 2px,transparent 8px)!important;
}
#withdrawalModal .qb-check-left{
    padding-right:2rem!important;
}
#withdrawalModal .qb-check-right{
    width:270px!important;
}
#withdrawalModal .qb-check-panel .form-control,
#withdrawalModal .qb-check-panel .form-select{
    height:36px!important;
    border-radius:8px!important;
    background:#ffffff!important;
    border:1px solid #cbd5e1!important;
    color:#052A47!important;
}
#withdrawalModal .qb-address-row textarea.form-control{
    width:320px!important;
    height:82px!important;
    background:#f8fafc!important;
}
#withdrawalModal .qb-tabs{margin-top:1rem!important;}
#withdrawalModal .qb-tab{
    min-width:180px!important;
    padding:.55rem .8rem!important;
    border-radius:10px 10px 0 0!important;
    border-color:#d7dee8!important;
    background:#e5e7eb!important;
}
#withdrawalModal .qb-tab.active{
    background:#ffffff!important;
    color:#052A47!important;
}
#withdrawalModal .qb-expense-table-wrap{
    max-height:330px!important;
    border:1px solid #d7dee8!important;
    border-radius:0 10px 10px 10px!important;
    overflow:auto!important;
}
#withdrawalModal .qb-expense-table thead th{
    background:#ffffff!important;
    color:#64748b!important;
    font-size:.82rem!important;
    font-weight:700!important;
    padding:.65rem .6rem!important;
    white-space:nowrap!important;
}
#withdrawalModal .qb-expense-table tbody td{
    height:38px!important;
    vertical-align:middle!important;
}
#withdrawalModal .qb-table-select,
#withdrawalModal .qb-table-input{
    height:31px!important;
    border:0!important;
    border-radius:0!important;
    background:transparent!important;
}
#withdrawalModal .modal-footer.qb-footer{
    background:#f8fafc!important;
    border-top:1px solid #e5e7eb!important;
    padding:.9rem 1.1rem!important;
}
#withdrawalModal .modal-footer.qb-footer .btn{
    border-radius:10px!important;
    min-width:135px!important;
    font-weight:700!important;
}
@media(max-width:992px){
    #withdrawalModal .modal-dialog{
        width:calc(100% - 1rem)!important;
        max-width:calc(100% - 1rem)!important;
    }
    #withdrawalModal .qb-topbar{
        grid-template-columns:1fr!important;
    }
    #withdrawalModal .qb-check-panel{
        flex-direction:column!important;
        padding:1.25rem!important;
    }
    #withdrawalModal .qb-check-left{
        padding-right:0!important;
    }
    #withdrawalModal .qb-check-right{
        width:100%!important;
        margin-top:1rem!important;
    }
    #withdrawalModal .qb-payee-row{
        grid-template-columns:1fr!important;
    }
    #withdrawalModal .qb-address-row textarea.form-control{
        width:100%!important;
    }
}
@media(max-width:576px){
    #withdrawalModal .modal-body.qb-body{
        padding:.75rem!important;
    }
    #withdrawalModal .qb-bank-wrap{
        grid-template-columns:1fr!important;
        gap:.35rem!important;
    }
    #withdrawalModal .qb-tabs{
        overflow-x:auto!important;
    }
    #withdrawalModal .qb-tab{
        min-width:150px!important;
    }
}


/* Final New Withdrawal UI fixes: smaller content scale, peso label, cleaner tabs */
#withdrawalModal .modal-dialog{
    max-width:min(94vw,1320px)!important;
    width:min(94vw,1320px)!important;
}
#withdrawalModal .modal-body.qb-body{
    padding:.85rem .95rem 1rem!important;
}
#withdrawalModal .qb-topbar{
    grid-template-columns:minmax(390px,1fr) minmax(280px,360px)!important;
    gap:.75rem!important;
    padding:0 0 .65rem!important;
}
#withdrawalModal .qb-bank-wrap,
#withdrawalModal .qb-ending-balance{
    min-height:39px!important;
    padding:.45rem .65rem!important;
    border-radius:8px!important;
}
#withdrawalModal .qb-bank-wrap{
    grid-template-columns:112px 1fr!important;
}
#withdrawalModal .qb-bank-wrap label,
#withdrawalModal .qb-ending-balance label,
#withdrawalModal .qb-check-panel label{
    font-size:.72rem!important;
}
#withdrawalModal .qb-bank-wrap select,
#withdrawalModal .qb-check-panel .form-control,
#withdrawalModal .qb-check-panel .form-select{
    height:32px!important;
    font-size:.86rem!important;
    border-radius:6px!important;
}
#withdrawalModal .qb-ending-balance strong{
    font-size:1.1rem!important;
}
#withdrawalModal .qb-check-panel{
    min-height:255px!important;
    padding:1.35rem 1.45rem .9rem!important;
    border-radius:10px!important;
}
#withdrawalModal .qb-check-left{
    padding-right:1.25rem!important;
}
#withdrawalModal .qb-check-right{
    width:245px!important;
}
#withdrawalModal .qb-payee-row{
    grid-template-columns:138px 1fr!important;
    gap:.65rem!important;
}
#withdrawalModal .qb-dollars-line{
    height:34px!important;
    margin:0 30px .55rem 0!important;
    padding-top:22px!important;
    font-size:.72rem!important;
}
#withdrawalModal .qb-address-row textarea.form-control{
    width:290px!important;
    height:66px!important;
}
#withdrawalModal .qb-mini-field{
    margin-bottom:.45rem!important;
}
#withdrawalModal .qb-tabs{
    margin-top:.65rem!important;
    gap:0!important;
    align-items:flex-end!important;
}
#withdrawalModal .qb-tab{
    min-width:150px!important;
    padding:.42rem .65rem!important;
    font-size:.86rem!important;
    border-radius:8px 8px 0 0!important;
}
#withdrawalModal .qb-tab.active{
    border-top:3px solid #047857!important;
    color:#052A47!important;
}
#withdrawalModal .qb-tab-disabled{
    opacity:.65!important;
    cursor:not-allowed!important;
    pointer-events:none!important;
}
#withdrawalModal .qb-expense-table-wrap{
    max-height:260px!important;
}
#withdrawalModal .qb-expense-table thead th{
    font-size:.76rem!important;
    padding:.45rem .5rem!important;
}
#withdrawalModal .qb-expense-table tbody td{
    height:32px!important;
}
#withdrawalModal .qb-table-select,
#withdrawalModal .qb-table-input{
    height:28px!important;
    font-size:.84rem!important;
}
#withdrawalModal .modal-footer.qb-footer{
    padding:.7rem .95rem!important;
}
#withdrawalModal .modal-footer.qb-footer .btn{
    min-width:120px!important;
    padding:.45rem .8rem!important;
    font-size:.88rem!important;
}

/* 100% Purchase Order style override for New Withdrawal modal */
#withdrawalModal .modal-dialog{
    width: min(96vw, 1500px) !important;
    max-width: min(96vw, 1500px) !important;
    margin: 1rem auto !important;
}
#withdrawalModal .modal-content.qb-withdrawal-modal{
    border:0 !important;
    border-radius:18px !important;
    overflow:hidden !important;
    box-shadow:0 18px 45px rgba(5,42,71,.22) !important;
    max-height:92vh !important;
}
#withdrawalModal .modal-header.qb-toolbar{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%) !important;
    color:#fff !important;
    border:0 !important;
    padding:.8rem 1rem !important;
}
#withdrawalModal .modal-header.qb-toolbar .modal-title{
    color:#fff !important;
    font:700 1rem Inter,system-ui,sans-serif !important;
}
#withdrawalModal .modal-header.qb-toolbar .btn-close{
    background-color:rgba(255,255,255,.24) !important;
    border-radius:50% !important;
    opacity:1 !important;
}
#withdrawalModal .modal-body.qb-body{
    background:#f2f2f2 !important;
    padding:.65rem !important;
    overflow:auto !important;
    font-family:Arial, Helvetica, sans-serif !important;
    transform:none !important;
    zoom:1 !important;
}
#withdrawalModal .qb-topbar{
    display:grid !important;
    grid-template-columns:minmax(420px, 1fr) 340px !important;
    gap:1rem !important;
    align-items:center !important;
    margin:0 0 .45rem !important;
    padding:0 !important;
}
#withdrawalModal .qb-bank-wrap,
#withdrawalModal .qb-ending-balance{
    background:#f2f2f2 !important;
    border:0 !important;
    border-radius:0 !important;
    min-height:34px !important;
    padding:3px 4px !important;
    box-shadow:none !important;
    display:grid !important;
    align-items:center !important;
}
#withdrawalModal .qb-bank-wrap{
    grid-template-columns:120px minmax(0,1fr) !important;
}
#withdrawalModal .qb-ending-balance{
    grid-template-columns:135px minmax(0,1fr) !important;
}
#withdrawalModal .qb-bank-wrap label,
#withdrawalModal .qb-ending-balance label{
    margin:0 !important;
    color:#334155 !important;
    font-size:13px !important;
    font-weight:500 !important;
    text-transform:uppercase !important;
}
#withdrawalModal .qb-bank-wrap select{
    width:100% !important;
    min-width:250px !important;
    height:23px !important;
    min-height:23px !important;
    border:1px solid #bfc4ca !important;
    border-radius:2px !important;
    background:#fff !important;
    color:#111827 !important;
    padding:1px 6px !important;
    font-size:13px !important;
    font-weight:400 !important;
    line-height:1.2 !important;
    box-shadow:none !important;
    outline:none !important;
}
#withdrawalModal .qb-bank-wrap select:focus{
    border-color:#44D34E !important;
    box-shadow:0 0 0 1px rgba(68, 211, 78, .35) !important;
}
#withdrawalModal .qb-bank-wrap select option,
#withdrawalModal .qb-expense-table select option{
    background:#ffffff !important;
    color:#111827 !important;
}
#withdrawalModal .qb-ending-balance strong{
    background:#ffffff !important;
    min-height:23px !important;
    height:23px !important;
    display:flex !important;
    align-items:center !important;
    padding:1px 8px !important;
    color:#111 !important;
    font-size:16px !important;
    font-weight:700 !important;
    border:1px solid #e5e7eb !important;
    border-radius:0 !important;
}
#withdrawalModal .qb-check-panel{
    position:relative !important;
    display:flex !important;
    gap:16px !important;
    min-height:280px !important;
    border:2px solid #c1dcbc !important;
    outline:1px solid #f1fbee !important;
    border-radius:0 !important;
    background-color:#fcfffb !important;
    background-image:repeating-linear-gradient(45deg,rgba(150,190,155,.1) 0 1px,transparent 1px 6px),repeating-linear-gradient(-45deg,rgba(150,160,155,.08) 0 1px,transparent 1px 7px) !important;
    padding:42px 26px 18px !important;
    box-shadow:none !important;
}
#withdrawalModal .qb-check-left{
    flex:1 1 auto !important;
    padding:0 !important;
}
#withdrawalModal .qb-check-right{
    width:290px !important;
    flex:0 0 290px !important;
}
#withdrawalModal .qb-payee-row{
    display:grid !important;
    grid-template-columns:155px minmax(0,1fr) !important;
    gap:10px !important;
    align-items:center !important;
    margin-bottom:18px !important;
}
#withdrawalModal .qb-check-panel label{
    margin:0 !important;
    font-size:14px !important;
    color:#3f454f !important;
    text-transform:uppercase !important;
    font-weight:400 !important;
    line-height:24px !important;
}
#withdrawalModal .qb-check-panel .form-control,
#withdrawalModal .qb-check-panel .form-select{
    min-height:25px !important;
    height:25px !important;
    border:1px solid #c4c7cc !important;
    background:#eeeeee !important;
    border-radius:2px !important;
    padding:2px 6px !important;
    font-size:14px !important;
    color:#111 !important;
    box-shadow:inset 0 1px 2px rgba(0,0,0,.08) !important;
}
#withdrawalModal .qb-dollars-line{
    height:28px !important;
    border-bottom:1px solid #4b5563 !important;
    margin:0 92px 8px 0 !important;
    display:flex !important;
    align-items:flex-end !important;
    justify-content:flex-end !important;
    padding:0 0 2px 0 !important;
    color:#3f454f !important;
    font-size:13px !important;
    line-height:1 !important;
    text-transform:uppercase !important;
}
#withdrawalModal .qb-address-row{
    margin-bottom:8px !important;
}
#withdrawalModal .qb-address-row label{
    display:block !important;
    line-height:22px !important;
}
#withdrawalModal .qb-address-row textarea.form-control{
    width:280px !important;
    height:82px !important;
    resize:none !important;
}
#withdrawalModal .qb-memo-row{
    display:grid !important;
    grid-template-columns:56px minmax(0,1fr) !important;
    gap:8px !important;
    align-items:center !important;
    margin-top:8px !important;
}
#withdrawalModal .qb-mini-field{
    display:grid !important;
    grid-template-columns:52px 1fr !important;
    gap:8px !important;
    align-items:center !important;
    margin-bottom:6px !important;
}
#withdrawalModal .qb-amount-field label{
    text-align:right !important;
    padding-right:6px !important;
}
#withdrawalModal .qb-tabs{
    display:flex !important;
    align-items:flex-end !important;
    gap:0 !important;
    margin-top:10px !important;
    border-bottom:1px solid #d9d9d9 !important;
}
#withdrawalModal .qb-tab{
    border:1px solid #bfc4ca !important;
    border-bottom:0 !important;
    border-radius:0 !important;
    background:#cfcfcf !important;
    color:#111 !important;
    font-size:16px !important;
    font-weight:500 !important;
    height:30px !important;
    line-height:18px !important;
    padding:5px 10px !important;
    margin:0 2px 0 0 !important;
    min-width:130px !important;
    box-shadow:none !important;
}
#withdrawalModal .qb-tab.active{
    background:#fff !important;
    color:#047857 !important;
    font-weight:600 !important;
    border-top:1px solid #bfc4ca !important;
}
#withdrawalModal .qb-tab span{
    float:right !important;
    margin-left:25px !important;
    font-weight:400 !important;
}
#withdrawalModal .qb-expense-table-wrap{
    max-height:330px !important;
    overflow:auto !important;
    border:1px solid #d9d9d9 !important;
    border-top:0 !important;
    background:#fff !important;
}
#withdrawalModal .qb-expense-table{
    width:100% !important;
    border-collapse:collapse !important;
    table-layout:fixed !important;
    margin:0 !important;
}
#withdrawalModal .qb-expense-table thead th{
    position:sticky !important;
    top:0 !important;
    z-index:2 !important;
    height:34px !important;
    background:#fff !important;
    color:#6b7280 !important;
    text-transform:uppercase !important;
    font-size:14px !important;
    font-weight:500 !important;
    border-right:1px solid #d8dde3 !important;
    border-bottom:1px solid #9ca3af !important;
    padding:5px 7px !important;
    text-align:left !important;
}
#withdrawalModal .qb-expense-table tbody tr:nth-child(odd) td{
    background:#fff !important;
}
#withdrawalModal .qb-expense-table tbody tr:nth-child(even) td{
    background:#e8ffe7 !important;
}
#withdrawalModal .qb-expense-table tbody td{
    height:32px !important;
    border-right:1px solid #d8dde3 !important;
    padding:0 !important;
    vertical-align:middle !important;
}
#withdrawalModal .qb-table-select,
#withdrawalModal .qb-table-input,
#withdrawalModal .qb-expense-table input,
#withdrawalModal .qb-expense-table select{
    width:100% !important;
    height:25px !important;
    min-height:25px !important;
    border:0 !important;
    background:transparent !important;
    border-radius:0 !important;
    padding:2px 6px !important;
    font-size:14px !important;
    color:#111827 !important;
    outline:none !important;
    box-shadow:none !important;
}
#withdrawalModal .qb-table-select:focus,
#withdrawalModal .qb-table-input:focus,
#withdrawalModal .qb-expense-table input:focus,
#withdrawalModal .qb-expense-table select:focus{
    background:#fff !important;
    box-shadow:inset 0 0 0 1px #58e84b !important;
}
#withdrawalModal .modal-footer.qb-footer{
    background:#f2f2f2 !important;
    border-top:1px solid #d9d9d9 !important;
    padding:.75rem 1rem !important;
}
#withdrawalModal .modal-footer.qb-footer .btn{
    border-radius:2px !important;
    min-width:125px !important;
    font-weight:700 !important;
    padding:.45rem .8rem !important;
}
@media(max-width:992px){
    #withdrawalModal .qb-topbar{
        grid-template-columns:1fr !important;
    }
    #withdrawalModal .qb-check-panel{
        flex-direction:column !important;
        padding:20px !important;
    }
    #withdrawalModal .qb-check-right{
        width:100% !important;
        flex-basis:auto !important;
    }
}
@media(max-width:576px){
    #withdrawalModal .modal-dialog{
        width:calc(100% - 1rem) !important;
        max-width:calc(100% - 1rem) !important;
    }
    #withdrawalModal .qb-bank-wrap,
    #withdrawalModal .qb-ending-balance,
    #withdrawalModal .qb-payee-row,
    #withdrawalModal .qb-memo-row,
    #withdrawalModal .qb-mini-field,
    #withdrawalModal .qb-address-row textarea.form-control{
        width:100% !important;
    }
}

/* Searchable Payee dropdown, same behavior as Expenses Account Title dropdown */
#withdrawalModal .withdrawal-payee-combo{
    position:relative !important;
    width:100% !important;
}
#withdrawalModal .withdrawal-payee-input{
    width:100% !important;
    height:34px !important;
    border:1px solid #58e84b !important;
    background:#fff !important;
    color:#111827 !important;
    font-size:13px !important;
    font-weight:400 !important;
    outline:none !important;
    padding:5px 42px 5px 10px !important;
    border-radius:2px !important;
    box-shadow:none !important;
}
#withdrawalModal .withdrawal-payee-input:focus{
    border-color:#ced4da !important;
    box-shadow:none !important;
    outline:none !important;
    background:#fff !important;
}
#withdrawalModal .withdrawal-payee-toggle{
    position:absolute !important;
    right:6px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    width:24px !important;
    height:24px !important;
    border:0 !important;
    background:transparent !important;
    color:#6c757d !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    cursor:pointer !important;
    border-radius:2px !important;
    z-index:5 !important;
    padding:0 !important;
    outline:none !important;
    box-shadow:none !important;
    transition:color .15s ease !important;
}
#withdrawalModal .withdrawal-payee-toggle:focus,
#withdrawalModal .withdrawal-payee-toggle:active{
    outline:none !important;
    box-shadow:none !important;
}
#withdrawalModal .withdrawal-payee-toggle i{
    font-size:12px !important;
    line-height:1 !important;
    transition:transform .15s ease !important;
    pointer-events:none !important;
}
#withdrawalModal .withdrawal-payee-toggle:hover,
#withdrawalModal .withdrawal-payee-toggle.active{
    background:transparent !important;
    border:0 !important;
    color:#6c757d !important;
    box-shadow:none !important;
    outline:none !important;
}
#withdrawalModal .withdrawal-payee-toggle.active i{
    transform:rotate(180deg) !important;
}
.withdrawal-payee-dropdown .withdrawal-account-option small{
    max-width:135px !important;
    text-transform:none !important;
}
.withdrawal-payee-group{
    padding:7px 14px 5px !important;
    background:#f4fff4 !important;
    color:#047857 !important;
    border-bottom:1px solid #dff7df !important;
    font-size:11px !important;
    font-weight:800 !important;
    text-transform:uppercase !important;
    letter-spacing:.04em !important;
}


/* PAY TO THE ORDER OF compact layout fix */
#withdrawalModal .qb-payee-row.qb-payee-search-row,
#withdrawalModal .qb-payee-search-row{
    grid-template-columns:max-content 420px !important;
    justify-content:start !important;
    align-items:center !important;
    column-gap:12px !important;
}
#withdrawalModal .qb-payee-search-row > label{
    white-space:nowrap !important;
    min-width:max-content !important;
    width:auto !important;
}
#withdrawalModal .qb-payee-search-row .withdrawal-payee-combo{
    width:420px !important;
    max-width:420px !important;
}
#withdrawalModal .qb-payee-search-row .withdrawal-payee-input{
    width:420px !important;
    max-width:420px !important;
}
@media(max-width:576px){
    #withdrawalModal .qb-payee-row.qb-payee-search-row,
    #withdrawalModal .qb-payee-search-row{
        grid-template-columns:1fr !important;
    }
    #withdrawalModal .qb-payee-search-row .withdrawal-payee-combo,
    #withdrawalModal .qb-payee-search-row .withdrawal-payee-input{
        width:100% !important;
        max-width:100% !important;
    }
}

/* Withdrawal account dropdown, same style as purchase_order.php */
#withdrawalModal .withdrawal-expense-account-cell{
    position:relative!important;
    overflow:visible!important;
}
#withdrawalModal .withdrawal-account-combo{
    position:relative!important;
    width:100%!important;
    height:25px!important;
}
#withdrawalModal .withdrawal-expense-account-input{
    width:100%!important;
    height:25px!important;
    border:0!important;
    background:transparent!important;
    font-size:13px!important;
    color:#111827!important;
    outline:none!important;
    padding:2px 34px 2px 6px!important;
    box-shadow:none!important;
}
#withdrawalModal .withdrawal-expense-account-input:focus{
    background:#fff!important;
    box-shadow:inset 0 0 0 1px #58e84b,0 0 0 1px rgba(47,128,237,.15)!important;
}
#withdrawalModal .withdrawal-account-toggle{
    position:absolute!important;
    right:1px!important;
    top:1px!important;
    width:26px!important;
    height:23px!important;
    border:0!important;
    border-left:1px solid transparent!important;
    background:transparent!important;
    color:#64748b!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    cursor:pointer!important;
    border-radius:2px!important;
    z-index:2!important;
    transition:background .15s ease,color .15s ease,transform .15s ease!important;
}
#withdrawalModal .withdrawal-account-toggle:hover,
#withdrawalModal .withdrawal-account-toggle.active{
    background:#eeffee!important;
    color:#58e84b!important;
}
#withdrawalModal .withdrawal-account-toggle i{
    font-size:12px!important;
    line-height:1!important;
    pointer-events:none!important;
}
.withdrawal-account-dropdown{
    position:fixed!important;
    z-index:99999!important;
    display:none!important;
    min-width:260px!important;
    max-height:245px!important;
    overflow-y:auto!important;
    background:#fff!important;
    border:1px solid #a3df9d!important;
    border-radius:4px!important;
    box-shadow:0 8px 20px rgba(15,23,42,.18)!important;
    padding:0!important;
    font-size:13px!important;
    color:#1f2937!important;
}
.withdrawal-account-dropdown.show{
    display:block!important;
    visibility:visible!important;
    opacity:1!important;
    pointer-events:auto!important;
}
.withdrawal-account-option{
    width:100%!important;
    min-height:34px!important;
    padding:8px 14px!important;
    margin:0!important;
    border:0!important;
    border-bottom:1px solid #eef2f7!important;
    border-radius:0!important;
    cursor:pointer!important;
    line-height:1.35!important;
    background:#fff!important;
    color:#111827!important;
    font-family:inherit!important;
    font-size:13px!important;
    font-weight:400!important;
    text-align:left!important;
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:12px!important;
    box-shadow:none!important;
    appearance:none!important;
    -webkit-appearance:none!important;
}
.withdrawal-account-option:last-child{
    border-bottom:0!important;
}
.withdrawal-account-option:hover,
.withdrawal-account-option.active{
    background:#e7f2ff!important;
    color:#0f172a!important;
}
.withdrawal-account-option-label{
    min-width:0!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
    white-space:nowrap!important;
}
.withdrawal-account-option small{
    flex:0 0 auto!important;
    max-width:120px!important;
    color:#052A47!important;
    font-size:11px!important;
    font-weight:500!important;
    white-space:nowrap!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
}
.withdrawal-account-empty{
    padding:9px 14px!important;
    color:#6b7280!important;
    font-size:12px!important;
}

/* FINAL: Expense Account dropdown style copied from purchase_order.php look */
#withdrawalModal .withdrawal-expense-account-cell{
    position:relative !important;
    overflow:visible !important;
    padding:0 !important;
}
#withdrawalModal .withdrawal-account-combo{
    position:relative !important;
    width:100% !important;
    height:32px !important;
    background:#fff !important;
}
#withdrawalModal .withdrawal-expense-account-input{
    width:100% !important;
    height:32px !important;
    border:1px solid #58e84b !important;
    background:#fff !important;
    color:#111827 !important;
    font-size:13px !important;
    font-weight:400 !important;
    outline:none !important;
    padding:5px 34px 5px 8px !important;
    border-radius:2px !important;
    box-shadow:none !important;
}
#withdrawalModal .withdrawal-expense-account-input:focus{
    border-color:#44D34E !important;
    box-shadow:0 0 0 1px rgba(68,211,78,.30) !important;
    background:#fff !important;
}
#withdrawalModal .withdrawal-account-toggle{
    position:absolute !important;
    right:1px !important;
    top:1px !important;
    width:30px !important;
    height:30px !important;
    border:0 !important;
    border-left:1px solid #d8f5d4 !important;
    background:#f7fff7 !important;
    color:#58e84b !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    cursor:pointer !important;
    border-radius:0 2px 2px 0 !important;
    z-index:5 !important;
}
#withdrawalModal .withdrawal-account-toggle:hover,
#withdrawalModal .withdrawal-account-toggle.active{
    background:#eaffea !important;
    color:#047857 !important;
}
.withdrawal-account-dropdown{
    position:fixed !important;
    z-index:200000 !important;
    display:none !important;
    min-width:320px !important;
    max-height:235px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    background:#fff !important;
    border:1px solid #9eea9b !important;
    border-top:0 !important;
    border-radius:0 0 4px 4px !important;
    box-shadow:0 8px 18px rgba(15,23,42,.14) !important;
    padding:0 !important;
    margin:0 !important;
    font-size:13px !important;
    color:#1f2937 !important;
}
.withdrawal-account-dropdown.show{
    display:block !important;
    visibility:visible !important;
    opacity:1 !important;
    pointer-events:auto !important;
}
.withdrawal-account-dropdown::-webkit-scrollbar{
    width:8px !important;
}
.withdrawal-account-dropdown::-webkit-scrollbar-track{
    background:#ecfff0 !important;
}
.withdrawal-account-dropdown::-webkit-scrollbar-thumb{
    background:#44D34E !important;
    border-radius:10px !important;
}
.withdrawal-account-option{
    width:100% !important;
    min-height:34px !important;
    padding:8px 14px !important;
    margin:0 !important;
    border:0 !important;
    border-bottom:1px solid #edf2f7 !important;
    border-radius:0 !important;
    cursor:pointer !important;
    background:#fff !important;
    color:#1f2937 !important;
    font-family:inherit !important;
    font-size:13px !important;
    font-weight:400 !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:16px !important;
    text-align:left !important;
}
.withdrawal-account-option:hover,
.withdrawal-account-option.active{
    background:#f6fff6 !important;
}
.withdrawal-account-option-label{
    flex:1 1 auto !important;
    min-width:0 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    color:#1f2937 !important;
}
.withdrawal-account-option small{
    flex:0 0 auto !important;
    font-size:11px !important;
    color:#1f2937 !important;
    white-space:nowrap !important;
    text-align:right !important;
    font-weight:400 !important;
}
.withdrawal-account-empty{
    padding:10px 14px !important;
    font-size:13px !important;
    color:#64748b !important;
}

/* Withdrawal Items tab table format, matched to purchase_order.php item table */
#withdrawalModal .qb-tab-panel{
    display:none;
}
#withdrawalModal .qb-tab-panel.active{
    display:block;
}
#withdrawalModal .qb-tab[data-withdrawal-tab]{
    cursor:pointer!important;
    pointer-events:auto!important;
    opacity:1!important;
}
#withdrawalModal .qb-items-table-wrap{
    border-radius:0 10px 10px 10px!important;
}
#withdrawalModal .qb-items-table{
    min-width:1180px!important;
}
#withdrawalModal .qb-items-table thead th{
    font-size:.74rem!important;
    text-transform:none!important;
    white-space:nowrap!important;
}
#withdrawalModal .qb-items-table tbody td{
    height:32px!important;
    padding:2px 4px!important;
}
#withdrawalModal .qb-items-table .qb-table-input{
    width:100%!important;
    border:0!important;
    background:transparent!important;
    height:28px!important;
    font-size:.83rem!important;
    padding:2px 5px!important;
    box-shadow:none!important;
}
#withdrawalModal .qb-items-table .qb-table-input:focus{
    background:#fff!important;
    box-shadow:inset 0 0 0 1px rgba(68,211,78,.55)!important;
    outline:none!important;
}
#withdrawalModal .qb-items-table .withdrawal-item-total{
    font-weight:700!important;
    color:#052A47!important;
    text-align:right!important;
}
#withdrawalModal .qb-items-table .withdrawal-item-qty,
#withdrawalModal .qb-items-table .withdrawal-item-unit-cost,
#withdrawalModal .qb-items-table .withdrawal-item-discount{
    text-align:right!important;
}

#withdrawalModal .item-entry-select,
#withdrawalModal .item-entry-input{
    width:100%!important;
    height:28px!important;
    border:0!important;
    background:transparent!important;
    box-shadow:none!important;
    border-radius:0!important;
    font-size:.83rem!important;
    padding:2px 5px!important;
}
#withdrawalModal .item-entry-select:focus,
#withdrawalModal .item-entry-input:focus{
    background:#fff!important;
    box-shadow:inset 0 0 0 1px rgba(68,211,78,.55)!important;
    outline:none!important;
}
#withdrawalModal .item-entry-select{
    appearance:auto!important;
    -webkit-appearance:auto!important;
    cursor:pointer!important;
}
#withdrawalModal .withdrawal-item-total-display{
    font-weight:700!important;
    color:#052A47!important;
    text-align:right!important;
    background:transparent!important;
}
#withdrawalModal .withdrawal-items-top-total{
    font-size:12px!important;
    color:#047857!important;
    font-weight:700!important;
    margin-left:8px!important;
    white-space:nowrap!important;
}


/* ===== AMOUNT IN WORDS LEFT ALIGN FINAL FIX ===== */
#withdrawalModal .qb-dollars-line{
    width:auto !important;
    margin:0 92px 8px 0 !important;
    padding:0 0 2px 0 !important;
    display:flex !important;
    align-items:flex-end !important;
    justify-content:flex-start !important;
    text-align:left !important;
    gap:0 !important;
}
#withdrawalModal #withdrawalAmountWords,
#withdrawalModal .qb-amount-words{
    display:block !important;
    flex:1 1 auto !important;
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    margin-left:0 !important;
    padding:0 !important;
    padding-left:0 !important;
    text-indent:0 !important;
    text-align:left !important;
    font-size:18px !important;
    font-weight:700 !important;
    line-height:1.45 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}
#withdrawalModal .qb-pesos-label,
#withdrawalModal .amount-words-suffix,
#withdrawalModal .peso-label{
    display:none !important;
}


/* Withdrawal attachment UI, matched with the clean purchase order attachment style */
#withdrawalModal .withdrawal-attachment-card{
    margin-top:1rem!important;
    border:1px dashed #b7c4d4!important;
    border-radius:12px!important;
    background:#f8fafc!important;
    padding:14px 16px!important;
}
#withdrawalModal .withdrawal-attachment-card label{
    color:#052A47!important;
    font-size:.84rem!important;
    font-weight:800!important;
    margin-bottom:7px!important;
    display:flex!important;
    align-items:center!important;
    gap:7px!important;
}
#withdrawalModal .withdrawal-attachment-card input[type="file"]{
    background:#fff!important;
    border:1px solid #d7dee8!important;
    border-radius:10px!important;
    padding:.55rem .7rem!important;
    font-size:.86rem!important;
}
#withdrawalModal .withdrawal-attachment-help{
    color:#64748b!important;
    font-size:.78rem!important;
    margin-top:6px!important;
}
#withdrawalModal .withdrawal-attachment-preview{
    display:flex!important;
    flex-wrap:wrap!important;
    gap:8px!important;
    margin-top:10px!important;
}
#withdrawalModal .withdrawal-attachment-chip{
    display:inline-flex!important;
    align-items:center!important;
    gap:7px!important;
    border:1px solid #d7dee8!important;
    border-radius:999px!important;
    background:#fff!important;
    color:#052A47!important;
    padding:6px 10px!important;
    font-size:.8rem!important;
    font-weight:700!important;
    max-width:260px!important;
}
#withdrawalModal .withdrawal-attachment-chip span{
    white-space:nowrap!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
}

/* ADDRESS field one-line layout like Memo */
#withdrawalModal .qb-address-row{
    display:grid !important;
    grid-template-columns:70px minmax(0,1fr) !important;
    gap:8px !important;
    align-items:center !important;
    margin-top:8px !important;
    margin-bottom:8px !important;
}
#withdrawalModal .qb-address-row label{
    display:block !important;
    margin:0 !important;
    white-space:nowrap !important;
    line-height:1.2 !important;
}
#withdrawalModal .qb-address-row input.form-control,
#withdrawalModal .qb-address-row textarea.form-control{
    width:100% !important;
    max-width:none !important;
    height:36px !important;
    min-height:36px !important;
    resize:none !important;
    overflow:hidden !important;
}

/* Withdrawal attachment moved beside Address area like Purchase Order transaction attachment */
#withdrawalModal .qb-check-panel{
    align-items:stretch !important;
}
#withdrawalModal .qb-check-left{
    min-width:0 !important;
}
#withdrawalModal .qb-check-right{
    width:240px !important;
    flex:0 0 240px !important;
}
#withdrawalModal .qb-check-attachment{
    width:300px !important;
    flex:0 0 300px !important;
    display:flex !important;
    align-items:stretch !important;
}
#withdrawalModal .qb-check-attachment .withdrawal-attachment-card{
    width:100% !important;
    margin-top:0 !important;
    border:1px solid #c1dcbc !important;
    border-radius:0 !important;
    background:#fcfffb !important;
    background-image:repeating-linear-gradient(45deg,rgba(150,190,155,.1) 0 1px,transparent 1px 6px),repeating-linear-gradient(-45deg,rgba(150,160,155,.08) 0 1px,transparent 1px 7px) !important;
    padding:12px 14px !important;
    min-height:150px !important;
    box-shadow:none !important;
}
#withdrawalModal .qb-check-attachment .withdrawal-attachment-card > label{
    display:block !important;
    color:#052A47 !important;
    font-size:14px !important;
    font-weight:800 !important;
    margin:0 0 10px !important;
    text-transform:none !important;
    line-height:20px !important;
}
#withdrawalModal .withdrawal-attach-inner{
    border:1px dashed #d7dee8 !important;
    border-radius:8px !important;
    background:rgba(255,255,255,.45) !important;
    padding:18px 18px 14px !important;
    min-height:120px !important;
}
#withdrawalModal .withdrawal-attach-title{
    display:block !important;
    color:#3f454f !important;
    font-size:12px !important;
    font-weight:700 !important;
    margin-bottom:10px !important;
}
#withdrawalModal .qb-check-attachment .withdrawal-attachment-card input[type="file"]{
    height:auto !important;
    min-height:35px !important;
    background:#fff !important;
    border:1px solid #d7dee8 !important;
    border-radius:4px !important;
    padding:4px 8px !important;
    font-size:12px !important;
    box-shadow:none !important;
}
#withdrawalModal .qb-check-attachment .withdrawal-attachment-help{
    color:#64748b !important;
    font-size:12px !important;
    line-height:1.35 !important;
    margin-top:9px !important;
}
#withdrawalModal .qb-check-attachment .withdrawal-attachment-preview{
    gap:6px !important;
    margin-top:8px !important;
}
#withdrawalModal .qb-check-attachment .withdrawal-attachment-chip{
    max-width:245px !important;
    border-radius:6px !important;
    padding:5px 8px !important;
    font-size:12px !important;
}
@media(max-width:1200px){
    #withdrawalModal .qb-check-panel{
        flex-wrap:wrap !important;
    }
    #withdrawalModal .qb-check-attachment{
        width:100% !important;
        flex:1 1 100% !important;
        margin-top:10px !important;
    }
}
@media(max-width:992px){
    #withdrawalModal .qb-check-right,
    #withdrawalModal .qb-check-attachment{
        width:100% !important;
        flex:1 1 100% !important;
    }
}


/* ===== FINAL UI CONSISTENCY FIX: dropdown icons + spacing ===== */
/* Match Expenses Account Title dropdown icon with PAY TO THE ORDER OF */
#withdrawalModal .withdrawal-account-toggle{
    position:absolute !important;
    right:6px !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    width:24px !important;
    height:24px !important;
    border:0 !important;
    border-left:0 !important;
    background:transparent !important;
    color:#6c757d !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    cursor:pointer !important;
    border-radius:2px !important;
    z-index:5 !important;
    padding:0 !important;
    outline:none !important;
    box-shadow:none !important;
    transition:color .15s ease !important;
}
#withdrawalModal .withdrawal-account-toggle:hover,
#withdrawalModal .withdrawal-account-toggle.active,
#withdrawalModal .withdrawal-account-toggle:focus,
#withdrawalModal .withdrawal-account-toggle:active{
    background:transparent !important;
    border:0 !important;
    color:#6c757d !important;
    outline:none !important;
    box-shadow:none !important;
}
#withdrawalModal .withdrawal-account-toggle i{
    font-size:12px !important;
    line-height:1 !important;
    transition:transform .15s ease !important;
    pointer-events:none !important;
}
#withdrawalModal .withdrawal-account-toggle.active i{
    transform:rotate(180deg) !important;
}
#withdrawalModal .withdrawal-expense-account-input:focus{
    border-color:#ced4da !important;
    box-shadow:none !important;
    outline:none !important;
    background:#fff !important;
}

/* Match Items tab native dropdown arrow with PAY TO THE ORDER OF */
#withdrawalModal .item-entry-select{
    appearance:none !important;
    -webkit-appearance:none !important;
    -moz-appearance:none !important;
    cursor:pointer !important;
    padding-right:28px !important;
    background-color:transparent !important;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 16 16'%3E%3Cpath fill='%236c757d' d='M4.2 6.2a1 1 0 0 1 1.4 0L8 8.6l2.4-2.4a1 1 0 1 1 1.4 1.4l-3.1 3.1a1 1 0 0 1-1.4 0L4.2 7.6a1 1 0 0 1 0-1.4z'/%3E%3C/svg%3E") !important;
    background-repeat:no-repeat !important;
    background-position:right 8px center !important;
    background-size:12px 12px !important;
}
#withdrawalModal .item-entry-select:focus{
    background-color:#fff !important;
    box-shadow:none !important;
    outline:none !important;
}

/* Add breathing space between amount in words, address, and memo */
#withdrawalModal .qb-dollars-line{
    margin-bottom:14px !important;
}
#withdrawalModal .qb-address-row{
    margin-top:14px !important;
    margin-bottom:14px !important;
}
#withdrawalModal .qb-memo-row{
    margin-top:14px !important;
    margin-bottom:14px !important;
}


/* Write Check page layout: keeps the AMGC palette while removing the old modal/tab container */
.write-check-page-card{
    overflow:visible;
}
#withdrawalModal.write-check-page-card .section-header.qb-toolbar{
    border-radius:18px 18px 0 0;
}
#withdrawalModal.write-check-page-card .qb-withdrawal-modal{
    background:#fff;
    border:0;
    box-shadow:none;
}
#withdrawalModal.write-check-page-card .section-body.qb-body{
    max-height:none;
    overflow:visible;
    padding:1rem;
}
#withdrawalModal.write-check-page-card .write-check-footer{
    display:flex;
    justify-content:flex-end;
    gap:.65rem;
    padding:1rem;
    border-top:1px solid rgba(5,42,71,.08);
    background:#fff;
    border-radius:0 0 18px 18px;
}
#withdrawalModal.write-check-page-card .write-check-footer .btn{
    border-radius:12px;
    font-weight:800;
}
#withdrawalModal.write-check-page-card .qb-tab-panel{
    display:none;
}
#withdrawalModal.write-check-page-card .qb-tab-panel.active{
    display:block;
}


.withdrawal-account-option.disabled,
.withdrawal-account-option.disabled:hover,
.withdrawal-account-option.disabled.active{
    cursor:not-allowed !important;
    background:#f8fafc !important;
    color:#64748b !important;
}
.withdrawal-account-option.disabled .withdrawal-account-option-label,
.withdrawal-account-option.disabled small{
    color:#64748b !important;
    font-weight:400 !important;
}
/* Parent */
.sidebar .nav-link{
    position:relative;
}
.sidebar-parent-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    flex: 0 0 24px;
}

.task-parent-badge {
    position: absolute;
    top: -10px;
    right: -3px;

    min-width: 17px;
    height: 17px;
    padding: 0 4px;

    border-radius: 999px;
    background: #ef4444;
    color: #fff;

    font-size: 10px;
    font-weight: 700;
    line-height: 1;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    z-index: 30;
    pointer-events: none;
    box-sizing: border-box;
}

/* Badge sa Tasks child kapag open ang dropdown */
.task-child-badge {
    margin-left: auto;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    display: none;
    align-items: center;
    justify-content: center;
}

/* Closed dropdown: parent badge visible */
.employees-dropdown .task-parent-badge {
    display: inline-flex;
}

/* Open dropdown: parent badge hidden */
.employees-dropdown.employees-menu-open .task-parent-badge {
    display: none;
}

/* Open dropdown: Tasks badge visible */
.employees-dropdown.employees-menu-open .task-child-badge {
    display: inline-flex;
}

/* Allow badge to extend outside icon */
.employees-dropdown > .nav-link,
.sidebar-parent-icon {
    overflow: visible !important;
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

            <span class="nav-text">Branch Admin</span>
        </h3>
    </div>

    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="branchdashboard.php">
                        <i class="bi bi-speedometer2"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <!-- Tasks -->
                <li class="nav-item">
                    <a class="nav-link" href="tasks.php">
                        <i class="bi bi-calendar-check"></i>
                        <span class="nav-text">Tasks</span>
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
                                <a class="nav-link" href="purchase_order.php">
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
                                <a class="nav-link" href="supplier.php">
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
                <li class="nav-item dropdown-nav employees-dropdown">
                    <a class="nav-link"
                    href="#"
                    onclick="toggleSidebarDropdown(event, 'employeesMenu')">

                        <span class="sidebar-parent-icon">
                            <i class="bi bi-briefcase"></i>

                            <?php if ($task_badge_count > 0): ?>
                                <span class="task-parent-badge">
                                    <?= $task_badge_count ?>
                                </span>
                            <?php endif; ?>
                        </span>

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

                            <li class="nav-item">
                                <a class="nav-link" href="tasks.php">
                                    <i class="bi bi-calendar-check"></i>
                                    <span class="nav-text">Tasks</span>

                                    <?php if ($task_badge_count > 0): ?>
                                        <span class="task-child-badge">
                                            <?= $task_badge_count ?>
                                        </span>
                                    <?php endif; ?>
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
                                <a class="nav-link active" href="Withdrawal.php">
                                    <i class="bi bi-journal-check"></i>
                                    <span class="nav-text">Write Checks</span>
                                </a>
                            </li>
                            
                            <li class="nav-item">
                                <a class="nav-link" href="transferfunds.php">
                                    <i class="bi bi-arrow-left-right"></i>
                                    <span class="nav-text">Transfer Funds</span>
                                </a>
                            </li>
                            
                            <li class="nav-item" hidden>
                                <a class="nav-link" href="bank_statement.php">
                                    <i class="bi bi-receipt"></i>
                                    <span class="nav-text">Bank Statement</span>
                                </a>
                            </li>

                            <li class="nav-item" hidden>
                                <a class="nav-link" href="expenses.php">
                                    <i class="bi bi-cash-stack"></i>
                                    <span class="nav-text">Expenses</span>
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
                                <a class="nav-link" href="current_inventory.php">
                                    <i class="bi bi-box"></i>
                                    <span class="nav-text">Items</span>
                                </a>
                            </li>
                            
                             <li class="nav-item">
                                <a class="nav-link" href="fixed_assets.php">
                                    <i class="bi bi-building"></i>
                                    <span class="nav-text">Fixed Assets</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="chartofaccounts.php">
                                    <i class="bi bi-graph-up"></i>
                                    <span class="nav-text">Chart of Accounts</span>
                                </a>
                            </li>

                            <li class="nav-item" hidden>
                                <a class="nav-link" href="trip_tickets.php">
                                    <i class="bi bi-ticket-perforated"></i>
                                    <span class="nav-text">Trip Tickets</span>
                                </a>
                            </li>

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

                            <li class="nav-item">
                                <a class="nav-link" href="drivers.php">
                                    <i class="bi bi-people-fill"></i>
                                    <span class="nav-text">Users</span>
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

<div class="main-content" id="mainContent"><div id="dashboardContent" class="page-content active">
<div class="navbar-top no-print"><button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button><div class="page-title"><h2><?php echo h($page_title); ?></h2><p><?php echo h($page_subtitle); ?></p></div></div>

<!--<div class="row g-3 mb-3">-->
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Undeposited Funds</div><div class="stat-value">₱<?php echo number_format($undeposited_total, 2); ?></div><div class="page-note"><?php echo count($available_payments); ?> payment(s) waiting</div></div><div class="stat-icon"><i class="bi bi-wallet2"></i></div></div></div>-->
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Total Collections</div><div class="stat-value">₱<?php echo number_format($total_collections, 2); ?></div><div class="page-note">Latest recorded payments</div></div><div class="stat-icon"><i class="bi bi-cash-coin"></i></div></div></div>-->
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Supplier/Vendors</div><div class="stat-value">₱<?php echo number_format($total_supplier_payables, 2); ?></div><div class="page-note"><?php echo $total_supplier_vendor_payables_count; ?> payable(s) unpaid</div></div><div class="stat-icon"><i class="bi bi-building"></i></div></div></div>-->
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Bank Balance</div><div class="stat-value">₱<?php echo number_format($bank_balance, 2); ?></div><div class="page-note">From actual bank accounts</div></div><div class="stat-icon"><i class="bi bi-bank"></i></div></div></div>-->
<!--</div>-->

<!-- WRITE CHECK PAGE -->
<div class="section-card write-check-page-card" id="withdrawalModal">
   <form method="POST" action="Withdrawal.php" class="qb-withdrawal-modal write-check-page-form" id="withdrawalForm" enctype="multipart/form-data">
       <input type="hidden" name="action" value="create_withdrawal">
       <div class="section-header qb-toolbar">
           <!--<div>-->
           <!--    <h5 class="modal-title mb-1"><i class="bi bi-arrow-up-circle me-2"></i>Write Check</h5>-->
           <!--    <div class="page-note">Create withdrawal transactions and review payment history.</div>-->
           <!--</div>-->
           <!--<div class="d-flex align-items-center gap-2 flex-wrap">-->
           <!--    <a href="paybills.php" class="btn btn-amgc-dark btn-sm">-->
           <!--        <i class="bi bi-receipt-cutoff me-1"></i> Pay Bills-->
           <!--    </a>-->
           <!--    <button type="button" class="btn btn-amgc-dark btn-sm" data-bs-toggle="modal" data-bs-target="#supplierBeginningBalanceModal" id="addSupplierBeginningBalanceBtn">-->
           <!--        <i class="bi bi-journal-plus me-1"></i> Add Beginning Balance-->
           <!--    </button>-->
           <!--</div>-->
       </div>
       <div class="section-body qb-body">
                <div class="qb-topbar">
                    <div class="qb-bank-wrap">
                        <label>BANK ACCOUNT</label>
                        <select name="bank_id" id="withdrawalBankSelect" class="form-select" required>
                            <option value="">Select bank account</option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?php echo (int)$bank['bank_id']; ?>" data-balance="<?php echo (float)($bank['balance'] ?? 0); ?>" <?php echo !empty($bank['has_children']) ? 'disabled' : ''; ?>>
                                    <?php echo str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int)($bank['level'] ?? 0)) . h($bank['bank_name'] . (!empty($bank['account_number']) ? ' - ' . $bank['account_number'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="qb-ending-balance">
                        <label>ENDING BALANCE</label>
                        <strong id="withdrawalEndingBalance">₱0.00</strong>
                    </div>
                </div>

                <div class="qb-check-panel">
                    <div class="qb-check-left">
                        <div class="qb-payee-row qb-payee-search-row">
                            <label>PAY TO THE ORDER OF</label>
                            <div class="qb-payee-select-wrap withdrawal-payee-combo" id="withdrawalPayeeCombo">
                                <input type="hidden" name="payee" id="withdrawalPayeeValue" required>
                                <input type="text" id="withdrawalPayeeInput" class="form-control withdrawal-payee-input" autocomplete="off" placeholder="Select payee">
                                <button type="button" class="withdrawal-payee-toggle" tabindex="-1" aria-label="Open payee dropdown">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="qb-dollars-line"><span id="withdrawalAmountWords" class="qb-amount-words">Zero Pesos Only</span></div>
                        <div class="qb-address-row">
                            <label>ADDRESS</label>
                            <input type="text" name="address" id="withdrawalAddress" class="form-control" placeholder="Address">
                        </div>
                        <div class="qb-memo-row">
                            <label>MEMO</label>
                            <input type="text" name="description" id="withdrawalDescription" class="form-control" placeholder="Description">
                        </div>
                    </div>
                    <div class="qb-check-right">
                        <div class="qb-mini-field">
                            <label>NO.</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="Reference No." required>
                        </div>
                        <div class="qb-mini-field">
                            <label>DATE</label>
                            <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="qb-mini-field qb-amount-field">
                            <label>₱</label>
                            <div class="w-100">
                                <input type="number" step="0.01" min="0.01" name="amount" id="withdrawalAmount" class="form-control" placeholder="0.00" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="qb-check-attachment">
                        <div class="withdrawal-attachment-card">
                            <label for="withdrawalAttachmentInput"><i class="bi bi-paperclip"></i>Transaction Attachment</label>
                            <div class="withdrawal-attach-inner">
                                <span class="withdrawal-attach-title"><i class="bi bi-paperclip"></i>Attach</span>
                                <input type="file" name="withdrawal_attachments[]" id="withdrawalAttachmentInput" class="form-control" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                                <div class="withdrawal-attachment-help">This attachment applies to the whole withdrawal transaction.</div>
                                <div id="withdrawalAttachmentPreview" class="withdrawal-attachment-preview"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="qb-tabs" id="withdrawalTabButtons">
                    <button type="button" class="qb-tab active" data-withdrawal-tab="expenses">Expenses <span id="withdrawalExpenseTotal">₱0.00</span></button>
                    <button type="button" class="qb-tab" data-withdrawal-tab="items">Items <span id="withdrawalItemsTotal">₱0.00</span></button>
                    <button type="button" class="qb-tab" data-withdrawal-tab="history">Payment History</button>
                </div>
                <div class="qb-tab-panel active" id="withdrawalExpensesPanel" data-withdrawal-panel="expenses">
                    <div class="qb-expense-table-wrap">
                        <table class="table qb-expense-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width:50%;">ACCOUNT TITLE</th>
                                    <th style="width:20%;">AMOUNT</th>
                                    <th style="width:30%;">MEMO</th>
                                </tr>
                            </thead>
                            <tbody id="withdrawalExpensesTableBody">
                                <?php for ($i = 0; $i < 8; $i++): ?>
                                <tr class="withdrawal-expense-row" data-row-index="<?php echo $i; ?>">
                                    <td class="withdrawal-expense-account-cell">
                                        <div class="qb-account-picker withdrawal-account-combo">
                                            <input type="text" name="withdrawal_expense_account[]" <?php echo $i === 0 ? 'id="withdrawalExpenseAccountSelect"' : ''; ?> class="expense-account-input withdrawal-expense-account-input" autocomplete="off" placeholder="Select account">
                                            <button type="button" class="qb-account-toggle withdrawal-account-toggle" tabindex="-1" aria-label="Open account list"><i class="bi bi-chevron-down"></i></button>
                                        </div>
                                    </td>
                                    <td><input type="number" step="0.01" min="0" name="withdrawal_expense_amount[]" <?php echo $i === 0 ? 'id="withdrawalExpenseAmountMirror"' : ''; ?> class="form-control qb-table-input withdrawal-expense-amount" placeholder="0.00"></td>
                                    <td><input type="text" name="withdrawal_expense_memo[]" <?php echo $i === 0 ? 'id="withdrawalExpenseMemoMirror"' : ''; ?> class="form-control qb-table-input withdrawal-expense-memo" placeholder="Memo"></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="qb-tab-panel" id="withdrawalItemsPanel" data-withdrawal-panel="items">
                    <div class="qb-expense-table-wrap qb-items-table-wrap">
                        <table class="table qb-expense-table qb-items-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width:18%;">ITEM NAME</th>
                                    <th style="width:8%;">QTY</th>
                                    <th style="width:28%;">Item Code / Description</th>
                                    <th style="width:10%;">UOM</th>
                                    <th style="width:12%;">Unit Cost</th>
                                    <th style="width:12%;">Discount</th>
                                    <th style="width:12%;">Total Unit Cost</th>
                                </tr>
                            </thead>
                            <tbody id="withdrawalItemsTableBody">
                                <?php for ($i = 0; $i < 8; $i++): ?>
                                <tr class="withdrawal-item-row" data-row-index="<?php echo $i; ?>">
                                    <td>
                                        <select class="item-entry-select withdrawal-item-selector" name="withdrawal_item_id[]" data-row-index="<?php echo $i; ?>">
                                            <option value="">-- Select Item --</option>
                                            <?php foreach ($withdrawal_items_list as $item): ?>
                                                <option value="<?php echo (int)$item['item_id']; ?>"><?php echo h($item['item_name'] ?? ''); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" min="0" name="withdrawal_item_qty[]" class="item-entry-input text-end withdrawal-item-qty" placeholder="0"></td>
                                    <td>
                                        <input type="text" name="withdrawal_item_description[]" class="item-entry-input withdrawal-item-description" placeholder="Item code / description">
                                        <input type="hidden" class="withdrawal-item-code-hidden">
                                    </td>
                                    <td>
                                        <select class="item-entry-select withdrawal-item-uom" name="withdrawal_item_uom[]">
                                            <option value="">-- UOM --</option>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" min="0" name="withdrawal_item_unit_cost[]" class="item-entry-input text-end withdrawal-item-unit-cost" placeholder="0.00"></td>
                                    <td><input type="text" name="withdrawal_item_discount[]" class="item-entry-input text-end withdrawal-item-discount" placeholder="0 or 0%" autocomplete="off" inputmode="decimal"></td>
                                    <td><input type="text" class="item-entry-input text-end withdrawal-item-total withdrawal-item-total-display" value="₱0.00" readonly><input type="hidden" name="withdrawal_item_total[]" class="withdrawal-item-total-hidden" value="0"></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="qb-tab-panel" id="withdrawalHistoryPanel" data-withdrawal-panel="history">
                   <h5 class="mb-3">Payment History</h5>
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
                                <tr><td colspan="7" class="text-center py-4 text-muted">No payment history found.</td></tr>
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
                                        $modalDetails['check_number'] = $entry['check_number'] ?? '';
                                        $modalDetails['bank_name_full'] = $entry['bank_name_full'] ?? $entry['partner'];
                                        $modalDetails['expense_account_full'] = $entry['expense_account_full'] ?? $entry['expense_account'];
                                        $modalDetails['payee_full'] = $entry['payee_full'] ?? $entry['payee'];
                                        $modalDetails['transaction_id'] = $entry['transaction_id'] ?? '';
                                        $withdrawalAttachmentDetails = [];
                                        if (!empty($entry['transaction_id'])) {
                                            foreach (getWithdrawalAttachmentsByTransactionId($conn, (int)$entry['transaction_id']) as $att) {
                                                $withdrawalAttachmentDetails[] = [
                                                    'name' => $att['file_name'] ?? 'Attachment',
                                                    'path' => $att['file_path'] ?? '',
                                                    'type' => $att['file_type'] ?? '',
                                                    'size' => (int)($att['file_size'] ?? 0)
                                                ];
                                            }
                                        }
                                        $modalDetails['attachments'] = $withdrawalAttachmentDetails;
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

       </div>
       <div class="qb-footer write-check-footer">
            <button type="submit" class="btn btn-amgc-primary" name="save_close" value="1"><i class="bi bi-check-circle me-1"></i>Save & Close</button>
            <button type="submit" class="btn btn-amgc-dark" name="save_new" value="1"><i class="bi bi-plus-circle me-1"></i>Save & New</button>
            <button type="button" class="btn btn-light border" id="withdrawalClearBtn">Clear</button>
       </div>
   </form>
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
            <input type="hidden" name="expense_id" id="supplier_expense_id" value="">
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
                            <option value="<?php echo (int)$bank['bank_id']; ?>" <?php echo !empty($bank['has_children']) ? 'disabled' : ''; ?>
                                    data-bank-name="<?php echo str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int)($bank['level'] ?? 0)) . h((!empty($bank['parent_bank_name']) ? $bank['parent_bank_name'] . ' / ' : '') . $bank['bank_name'] . (!empty($bank['account_number']) ? ' - ' . $bank['account_number'] : '')); ?>"
                                    data-bank-branch="<?php echo h($bank['bank_branch'] ?? ''); ?>"
                                    data-balance="<?php echo isset($bank_balance_by_id[$bank['bank_id']]) ? (float)$bank_balance_by_id[$bank['bank_id']] : 0; ?>">
                                <?php echo str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int)($bank['level'] ?? 0)) . h((!empty($bank['parent_bank_name']) ? $bank['parent_bank_name'] . ' / ' : '') . $bank['bank_name'] . (!empty($bank['account_number']) ? ' - ' . $bank['account_number'] : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1" id="bankBalanceDisplay">Current Balance: ₱0.00</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Payment Method</label>
                        <select name="payment_method" id="paymentMethodSelect" class="form-select">
                            <option value="">-- Optional --</option>
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
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Reference Number</label>
                        <input type="text" name="reference_number" class="form-control" required>
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
    $is_supplier_page = in_array($current_page, ['purchase_order.php', 'paybills.php', 'supplier.php']);
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
                <a href="paybills.php" class="dropdown-item <?php echo ($current_page == 'paybills.php') ? 'active' : ''; ?>">
                    <i class="bi bi-receipt-cutoff"></i><span>Pay Bills</span>
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
<div class="modal-dialog modal-xl modal-dialog-centered payment-details-wide-modal">
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

<!-- MODAL FOR VIEWING WITHDRAWAL ATTACHMENTS -->
<div class="modal fade" id="withdrawalAttachmentViewModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="bi bi-paperclip me-2"></i><span id="withdrawalAttachmentViewTitle">Attachment</span></h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-4">
    <div id="withdrawalAttachmentViewBody"></div>
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

const withdrawalItemsData = <?php echo json_encode($withdrawal_items_list ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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


function renderWithdrawalAttachmentPreview() {
    const input = document.getElementById('withdrawalAttachmentInput');
    const preview = document.getElementById('withdrawalAttachmentPreview');
    if (!input || !preview) return;

    const files = Array.from(input.files || []);
    if (!files.length) {
        preview.innerHTML = '';
        return;
    }

    preview.innerHTML = files.map(file => `
        <div class="withdrawal-attachment-chip" title="${escapeHtml(file.name)}">
            <i class="bi bi-file-earmark-arrow-up"></i>
            <span>${escapeHtml(file.name)}</span>
        </div>
    `).join('');
}

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


function populatePaymentMethods(bankId) {
    const paymentSelect = document.getElementById('paymentMethodSelect');
    if (!paymentSelect) return;
    let methods = bankPaymentMethods[bankId] || [];
    // Chart of Accounts bank accounts may not have bank_payment_methods records yet.
    // Keep the dropdown usable and optional by showing the standard choices as fallback.
    if (!methods.length) {
        methods = ['check', 'online_transfer', 'cash'];
    }
    paymentSelect.innerHTML = '<option value="">-- Optional --</option>';
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
}

function updateFieldsAfterBankSelection() {
    const bankSelect = document.getElementById('supplierBankSelect');
    const selectedOption = bankSelect.options[bankSelect.selectedIndex];
    const bankId = selectedOption ? parseInt(selectedOption.value) : null;
    if (!bankId) {
document.getElementById('paymentMethodSelect').innerHTML = '<option value="">-- Optional --</option>';
        document.getElementById('paymentMethodSelect').disabled = true;
        document.getElementById('bankBalanceDisplay').textContent = 'Current Balance: ₱0.00';
        return;
    }
    const balance = selectedOption.getAttribute('data-balance') ? parseFloat(selectedOption.getAttribute('data-balance')) : 0;
    document.getElementById('bankBalanceDisplay').textContent = 'Current Balance: ₱' + balance.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    populatePaymentMethods(bankId);
    document.querySelectorAll('.supplier-cash-fields, .supplier-check-fields, .supplier-online-fields').forEach(el => el.classList.add('d-none'));
    document.getElementById('bankBranchAutoFill').value = bankBranchMap[bankId] || '';
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
const WITHDRAWAL_PAYEE_OPTIONS = <?php echo json_encode($withdrawal_payee_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let activeWithdrawalAccountPicker = null;
let withdrawalAccountDropdown = null;

function normalizeWithdrawalAccountText(value) {
    return String(value || '').trim().toLowerCase();
}

function getWithdrawalAccountTitleFromOption(option) {
    return String(option?.account_title || option?.bank_name || option?.label || '').trim();
}

function getWithdrawalAccountDisplayLabel(option) {
    return String(option?.display_label || option?.label || getWithdrawalAccountTitleFromOption(option)).trim();
}

function getSelectedExpenseAccountOption(input = null) {
    input = input || document.getElementById('withdrawalExpenseAccountSelect');
    if (!input || !input.value) return null;
    const selectedValue = normalizeWithdrawalAccountText(input.value);
    return WITHDRAWAL_EXPENSE_ACCOUNTS.find(item => {
        const title = normalizeWithdrawalAccountText(getWithdrawalAccountTitleFromOption(item));
        const label = normalizeWithdrawalAccountText(item.label);
        const display = normalizeWithdrawalAccountText(getWithdrawalAccountDisplayLabel(item));
        return selectedValue === title || selectedValue === label || selectedValue === display;
    }) || null;
}

function getWithdrawalAccountDropdown() {
    if (withdrawalAccountDropdown) return withdrawalAccountDropdown;
    withdrawalAccountDropdown = document.createElement('div');
    withdrawalAccountDropdown.className = 'withdrawal-account-dropdown';
    document.body.appendChild(withdrawalAccountDropdown);
    return withdrawalAccountDropdown;
}

function closeWithdrawalAccountDropdown() {
    const dropdown = getWithdrawalAccountDropdown();
    dropdown.classList.remove('show');
    dropdown.innerHTML = '';
    document.querySelectorAll('#withdrawalModal .withdrawal-account-toggle.active').forEach(btn => btn.classList.remove('active'));
    activeWithdrawalAccountPicker = null;
}

function positionWithdrawalAccountDropdown(picker) {
    const dropdown = getWithdrawalAccountDropdown();
    const rect = picker.getBoundingClientRect();
    const dropdownWidth = Math.max(rect.width, 320);
    const viewportPadding = 8;
    let left = rect.left;
    if (left + dropdownWidth > window.innerWidth - viewportPadding) {
        left = Math.max(viewportPadding, window.innerWidth - dropdownWidth - viewportPadding);
    }
    dropdown.style.width = dropdownWidth + 'px';
    dropdown.style.minWidth = dropdownWidth + 'px';
    dropdown.style.left = left + 'px';
    dropdown.style.top = rect.bottom + 'px';
}

function renderWithdrawalAccountDropdown(input, showAll = false) {
    const picker = input.closest('.withdrawal-account-combo');
    if (!picker) return;

    const dropdown = getWithdrawalAccountDropdown();
    const keyword = normalizeWithdrawalAccountText(input.value);
    const filtered = showAll || keyword === ''
        ? WITHDRAWAL_EXPENSE_ACCOUNTS.slice()
        : WITHDRAWAL_EXPENSE_ACCOUNTS.filter(account => {
            const title = normalizeWithdrawalAccountText(getWithdrawalAccountTitleFromOption(account));
            const label = normalizeWithdrawalAccountText(account.label);
            const type = normalizeWithdrawalAccountText(account.account_type);
            const code = normalizeWithdrawalAccountText(account.account_code);
            return title.includes(keyword) || label.includes(keyword) || type.includes(keyword) || code.includes(keyword);
        });

    dropdown.innerHTML = '';
    if (!filtered.length) {
        dropdown.innerHTML = '<div class="withdrawal-account-empty">No account title found</div>';
    } else {
        filtered.forEach(account => {
            const title = getWithdrawalAccountTitleFromOption(account);
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'withdrawal-account-option';
            const isParentAccount = String(account.has_children || '0') === '1';
            if (isParentAccount) option.classList.add('disabled');
            option.dataset.value = title;
            option.dataset.description = account.description || '';
            const accountLevel = parseInt(account.level || 0, 10);
            option.innerHTML = `<span class="withdrawal-account-option-label" style="padding-left:${accountLevel * 18}px;font-weight:400;">${escapeHtml(getWithdrawalAccountDisplayLabel(account))}</span><small>${isParentAccount ? 'Parent account' : escapeHtml(account.account_type || '')}</small>`;
            option.addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (isParentAccount) return;
                input.value = title;
                input.dataset.description = account.description || '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                autoFillWithdrawalDescription();
                closeWithdrawalAccountDropdown();
                input.focus();
            });
            dropdown.appendChild(option);
        });
    }

    activeWithdrawalAccountPicker = picker;
    positionWithdrawalAccountDropdown(picker);
    dropdown.classList.add('show');
    document.querySelectorAll('#withdrawalModal .withdrawal-account-toggle.active').forEach(btn => btn.classList.remove('active'));
    const btn = picker.querySelector('.withdrawal-account-toggle');
    if (btn) btn.classList.add('active');
}

function autoFillWithdrawalDescription(input = null) {
    input = input || document.getElementById('withdrawalExpenseAccountSelect');
    const descriptionInput = document.getElementById('withdrawalDescription');
    const memoMirror = input ? input.closest('tr')?.querySelector('.withdrawal-expense-memo') : document.getElementById('withdrawalExpenseMemoMirror');
    if (!input || !descriptionInput) return;

    const selected = getSelectedExpenseAccountOption(input);
    const description = selected ? (selected.description || '') : (input.dataset.description || '');
    if (!descriptionInput.value.trim()) descriptionInput.value = description;
    if (memoMirror) memoMirror.value = descriptionInput.value || description;
}

let withdrawalExpenseTabAmount = 0;
let withdrawalItemsTabAmount = 0;
let withdrawalIsSwitchingTab = false;

function getWithdrawalActiveTabName() {
    const activeBtn = document.querySelector('#withdrawalTabButtons .qb-tab.active');
    return activeBtn ? (activeBtn.getAttribute('data-withdrawal-tab') || 'expenses') : 'expenses';
}

function getWithdrawalMainAmountValue() {
    const amountInput = document.getElementById('withdrawalAmount');
    return amountInput ? parseWithdrawalMoneyValue(amountInput.value) : 0;
}

function setWithdrawalMainAmountValue(amount) {
    const amountInput = document.getElementById('withdrawalAmount');
    if (amountInput) amountInput.value = amount > 0 ? Number(amount).toFixed(2) : '';
    if (typeof updateWithdrawalAmountWords === 'function') updateWithdrawalAmountWords();
    if (typeof updateWithdrawalBankBalance === 'function') updateWithdrawalBankBalance();
}

function getWithdrawalCalculatedExpensesTotal() {
    let total = 0;
    document.querySelectorAll('#withdrawalExpensesTableBody .withdrawal-expense-amount').forEach(input => { total += parseWithdrawalMoneyValue(input.value); });
    return Math.round(total * 100) / 100;
}
function updateWithdrawalExpenseTabDisplay(amount = null, syncMirror = false) {
    amount = amount === null ? getWithdrawalCalculatedExpensesTotal() : (Number(amount) || 0);
    withdrawalExpenseTabAmount = amount;
    const totalDisplay = document.getElementById('withdrawalExpenseTotal');
    if (totalDisplay) totalDisplay.textContent = formatWithdrawalPeso(amount);
    if (syncMirror) setWithdrawalMainAmountValue(amount);
}
function updateWithdrawalExpensesTotal(syncMainAmount = true) {
    const total = getWithdrawalCalculatedExpensesTotal();
    updateWithdrawalExpenseTabDisplay(total, false);
    if (syncMainAmount && getWithdrawalActiveTabName() === 'expenses') setWithdrawalMainAmountValue(total);
    updateWithdrawalAmountWords(); updateWithdrawalBankBalance(); return total;
}

function updateWithdrawalItemsTabDisplay(amount) {
    amount = Number(amount) || 0;
    withdrawalItemsTabAmount = amount;
    const totalDisplay = document.getElementById('withdrawalItemsTotal');
    const topTotalDisplay = document.getElementById('withdrawalTopItemsTotal');
    const formattedTotal = formatWithdrawalPeso(amount);
    if (totalDisplay) totalDisplay.textContent = formattedTotal;
    if (topTotalDisplay) topTotalDisplay.textContent = formattedTotal;
}

function saveWithdrawalActiveTabAmount() {
    const activeTab = getWithdrawalActiveTabName();
    const amount = getWithdrawalMainAmountValue();
    if (activeTab === 'items') {
        withdrawalItemsTabAmount = amount;
        updateWithdrawalItemsTabDisplay(amount);
    } else if (activeTab === 'expenses') {
        withdrawalExpenseTabAmount = getWithdrawalCalculatedExpensesTotal();
        updateWithdrawalExpenseTabDisplay(withdrawalExpenseTabAmount, false);
    }
}

function handleWithdrawalMainAmountInput() {
    const activeTab = getWithdrawalActiveTabName();
    const amount = getWithdrawalMainAmountValue();

    if (activeTab === 'items') {
        updateWithdrawalItemsTabDisplay(amount);
    } else if (activeTab === 'expenses') {
        updateWithdrawalExpensesTotal(true);
    }

    if (typeof updateWithdrawalAmountWords === 'function') updateWithdrawalAmountWords();
    if (typeof updateWithdrawalBankBalance === 'function') updateWithdrawalBankBalance();
}

function getWithdrawalDefaultItemAccountOption() {
    const accounts = Array.isArray(WITHDRAWAL_EXPENSE_ACCOUNTS) ? WITHDRAWAL_EXPENSE_ACCOUNTS : [];
    if (!accounts.length) return null;

    const preferredTypes = ['cost of goods sold', 'expense', 'other expense'];
    for (const type of preferredTypes) {
        const found = accounts.find(account => String(account.account_type || '').trim().toLowerCase() === type);
        if (found) return found;
    }
    return accounts[0] || null;
}

function getWithdrawalFilledItemsCount() {
    let count = 0;
    document.querySelectorAll('#withdrawalItemsTableBody .withdrawal-item-row').forEach(row => {
        const itemId = String(row.querySelector('.withdrawal-item-selector')?.value || '').trim();
        const qty = parseWithdrawalMoneyValue(row.querySelector('.withdrawal-item-qty')?.value);
        const total = calculateWithdrawalItemRow(row);
        if (itemId !== '' && qty > 0 && total > 0) count++;
    });
    return count;
}

function syncWithdrawalItemsBeforeSubmit() {
    const activeTab = getWithdrawalActiveTabName();
    if (activeTab !== 'items') return false;

    updateWithdrawalItemsTotal(true);
    const filledItems = getWithdrawalFilledItemsCount();
    if (filledItems <= 0) return false;

    const amountInput = document.getElementById('withdrawalAmount');
    let total = 0;
    document.querySelectorAll('#withdrawalItemsTableBody .withdrawal-item-row').forEach(row => {
        total += calculateWithdrawalItemRow(row);
    });
    if (amountInput && total > 0) {
        amountInput.value = total.toFixed(2);
    }

    const expenseInput = document.getElementById('withdrawalExpenseAccountSelect');
    let selected = getSelectedExpenseAccountOption();
    if (!selected) {
        selected = getWithdrawalDefaultItemAccountOption();
        if (expenseInput && selected) {
            expenseInput.value = getWithdrawalAccountTitleFromOption(selected);
            expenseInput.dataset.accountId = selected.id || '';
            expenseInput.dataset.description = selected.description || '';
        }
    }

    const descriptionInput = document.getElementById('withdrawalDescription');
    if (descriptionInput && !descriptionInput.value.trim()) {
        descriptionInput.value = 'Items withdrawal';
    }

    updateWithdrawalAmountWords();
    return true;
}

function syncWithdrawalExpensesBeforeSubmit() {
    if (getWithdrawalActiveTabName() !== 'expenses') return false;
    const total = updateWithdrawalExpensesTotal(true);
    const amountInput = document.getElementById('withdrawalAmount'); if (amountInput) amountInput.value = total > 0 ? total.toFixed(2) : '';
    return total > 0;
}
function validateWithdrawalExpenseAccount(e) {
    if (getWithdrawalActiveTabName() === 'items') return syncWithdrawalItemsBeforeSubmit();
    syncWithdrawalExpensesBeforeSubmit();
    let hasValidRow = false; let firstInvalidInput = null;
    document.querySelectorAll('#withdrawalExpensesTableBody .withdrawal-expense-row').forEach(row => {
        const input = row.querySelector('.withdrawal-expense-account-input'); const amountInput = row.querySelector('.withdrawal-expense-amount');
        const amount = parseWithdrawalMoneyValue(amountInput ? amountInput.value : ''); const accountText = input ? input.value.trim() : ''; const selected = input ? getSelectedExpenseAccountOption(input) : null;
        if (accountText === '' && amount <= 0) return;
        if (!selected || amount <= 0) { if (!firstInvalidInput) firstInvalidInput = !selected ? input : amountInput; return; }
        input.value = getWithdrawalAccountTitleFromOption(selected); input.dataset.description = selected.description || ''; hasValidRow = true;
    });
    if (!hasValidRow || firstInvalidInput) {
        e.preventDefault(); amgcSwalFire({ icon: 'error', title: 'Invalid Expenses', text: 'Please add at least one valid expense row with Account Title and Amount.' });
        setWithdrawalActiveTab('expenses'); if (firstInvalidInput) { firstInvalidInput.focus(); if (firstInvalidInput.classList.contains('withdrawal-expense-account-input')) renderWithdrawalAccountDropdown(firstInvalidInput, true); } return false;
    }
    return true;
}

function attachWithdrawalAccountDropdownEvents() {
    document.querySelectorAll('#withdrawalModal .withdrawal-expense-account-input').forEach(input => {
        input.addEventListener('input', function() { renderWithdrawalAccountDropdown(input, false); autoFillWithdrawalDescription(input); });
        input.addEventListener('focus', function() { renderWithdrawalAccountDropdown(input, false); });
    });
    document.querySelectorAll('#withdrawalModal .withdrawal-account-toggle').forEach(toggle => {
        toggle.addEventListener('mousedown', function(e) {
            e.preventDefault(); e.stopPropagation();
            const picker = toggle.closest('.withdrawal-account-combo'); const input = picker ? picker.querySelector('.withdrawal-expense-account-input') : null; if (!picker || !input) return;
            const dropdown = getWithdrawalAccountDropdown(); const isOpenForThisPicker = activeWithdrawalAccountPicker === picker && dropdown.classList.contains('show');
            if (isOpenForThisPicker) closeWithdrawalAccountDropdown(); else { renderWithdrawalAccountDropdown(input, true); setTimeout(() => input.focus(), 0); }
        });
    });
    document.querySelectorAll('#withdrawalExpensesTableBody .withdrawal-expense-amount, #withdrawalExpensesTableBody .withdrawal-expense-memo').forEach(input => {
        input.addEventListener('input', () => updateWithdrawalExpensesTotal(true)); input.addEventListener('change', () => updateWithdrawalExpensesTotal(true));
    });
    document.addEventListener('mousedown', function(e) { if (e.target.closest('.withdrawal-account-dropdown') || e.target.closest('.withdrawal-account-combo')) return; closeWithdrawalAccountDropdown(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeWithdrawalAccountDropdown(); });
    window.addEventListener('resize', closeWithdrawalAccountDropdown);
    window.addEventListener('scroll', function() { if (activeWithdrawalAccountPicker && getWithdrawalAccountDropdown().classList.contains('show')) positionWithdrawalAccountDropdown(activeWithdrawalAccountPicker); }, true);
}


function withdrawalNumberToWordsBelowThousand(num) {
    const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    num = Math.floor(Number(num) || 0);

    if (num === 0) return '';
    if (num < 20) return ones[num];
    if (num < 100) {
        return tens[Math.floor(num / 10)] + (num % 10 ? ' ' + ones[num % 10] : '');
    }

    return ones[Math.floor(num / 100)] + ' Hundred' + (num % 100 ? ' ' + withdrawalNumberToWordsBelowThousand(num % 100) : '');
}

function withdrawalNumberToWords(num) {
    num = Math.floor(Number(num) || 0);
    if (num === 0) return 'Zero';

    const units = [
        { value: 1000000000, label: 'Billion' },
        { value: 1000000, label: 'Million' },
        { value: 1000, label: 'Thousand' }
    ];

    let words = '';
    for (const unit of units) {
        if (num >= unit.value) {
            const unitCount = Math.floor(num / unit.value);
            words += withdrawalNumberToWordsBelowThousand(unitCount) + ' ' + unit.label + ' ';
            num %= unit.value;
        }
    }

    if (num > 0) words += withdrawalNumberToWordsBelowThousand(num);
    return words.trim();
}

function withdrawalAmountToPesoWords(value) {
    const amount = Math.max(0, Number(value) || 0);
    const pesos = Math.floor(amount);
    const centavos = Math.round((amount - pesos) * 100);
    const pesoWord = pesos === 1 ? 'Peso' : 'Pesos';

    let words = withdrawalNumberToWords(pesos) + ' ' + pesoWord;
    if (centavos > 0) {
        words += ' and ' + String(centavos).padStart(2, '0') + '/100';
    }
    return words + ' Only';
}

function updateWithdrawalAmountWords() {
    const amountInput = document.getElementById('withdrawalAmount');
    const wordsDisplay = document.getElementById('withdrawalAmountWords');
    if (!wordsDisplay) return;

    const rawAmount = amountInput ? amountInput.value : '';
    const amount = parseFloat(rawAmount);
    wordsDisplay.textContent = amount > 0 ? withdrawalAmountToPesoWords(amount).toUpperCase() : 'ZERO PESOS ONLY';
}

function updateWithdrawalBankBalance() {
    const bankSelect = document.getElementById('withdrawalBankSelect');
    if (!bankSelect) return;
    const selectedOption = bankSelect.options[bankSelect.selectedIndex];
    const balance = selectedOption && selectedOption.value ? (parseFloat(selectedOption.getAttribute('data-balance')) || 0) : 0;
    const balanceText = '₱' + balance.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    const oldDisplay = document.getElementById('withdrawalBankBalance');
    if (oldDisplay) oldDisplay.textContent = 'Current Balance: ' + balanceText;
    const endingDisplay = document.getElementById('withdrawalEndingBalance');
    if (endingDisplay) endingDisplay.textContent = balanceText;
}
function syncWithdrawalExpenseTable(force = false) {
    updateWithdrawalExpensesTotal(getWithdrawalActiveTabName() === 'expenses' || force);
}



function clearWithdrawalForm() {
    const form = document.getElementById('withdrawalForm');
    if (!form) return;

    withdrawalExpenseTabAmount = 0;
    withdrawalItemsTabAmount = 0;

    form.querySelectorAll('input, select, textarea').forEach(field => {
        if (field.type === 'hidden') return;
        if (field.name === 'transaction_date') {
            field.value = new Date().toISOString().slice(0, 10);
            return;
        }
        if (field.type === 'checkbox' || field.type === 'radio') {
            field.checked = false;
            return;
        }
        field.value = '';
        if (field.id === 'withdrawalExpenseAccountSelect') {
            field.dataset.accountId = '';
            field.dataset.description = '';
        }
    });

    const amountMirror = document.getElementById('withdrawalExpenseAmountMirror');
    const memoMirror = document.getElementById('withdrawalExpenseMemoMirror');
    const totalDisplay = document.getElementById('withdrawalExpenseTotal');
    const endingDisplay = document.getElementById('withdrawalEndingBalance');

    if (amountMirror) amountMirror.value = '';
    if (memoMirror) memoMirror.value = '';
    if (totalDisplay) totalDisplay.textContent = '₱0.00';
    if (endingDisplay) endingDisplay.textContent = '₱0.00';
    const attachmentPreview = document.getElementById('withdrawalAttachmentPreview');
    if (attachmentPreview) attachmentPreview.innerHTML = '';
    updateWithdrawalAmountWords();
    if (typeof clearWithdrawalItemsTable === 'function') clearWithdrawalItemsTable();
    if (typeof setWithdrawalActiveTab === 'function') setWithdrawalActiveTab('expenses');
    if (typeof closeWithdrawalAccountDropdown === 'function') closeWithdrawalAccountDropdown();
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
            updateEmployeesTaskBadge();
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
    updateEmployeesTaskBadge();
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

function peso(n){return '₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}

function openSupplierPaymentModal(data){
    document.getElementById('supplier_payable_type').value = data.payable_type || 'po';
    document.getElementById('supplier_po_id').value = data.po_id || '';
    document.getElementById('supplier_beginning_balance_id').value = data.beginning_balance_id || '';
    document.getElementById('supplier_expense_id').value = data.expense_id || '';
    document.getElementById('supplier_name_label').textContent=data.supplier_name;
    document.querySelector('#supplierPaymentModal .modal-title').innerHTML = '<i class="bi bi-cash-coin me-2"></i>Record Supplier/Vendor Payment';
    document.getElementById('supplier_po_label').textContent=data.po_number;
    document.getElementById('supplier_invoice_amount_label').textContent=peso(data.invoice_amount||0);
    document.getElementById('supplier_payments_made_label').textContent=peso(data.payments_made||0);
    document.getElementById('supplier_outstanding_balance_label').textContent=peso(data.balance||0);
    document.getElementById('supplier_payment_amount').value=Number(data.balance||0).toFixed(2);
    
    const bankSelect = document.getElementById('supplierBankSelect');
    if (bankSelect) bankSelect.value = '';
    updateFieldsAfterBankSelection();

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

function detailItemIfDifferent(label, value, compareValue, extraClass = '') {
    const normalizedValue = String(value || '').trim();
    const normalizedCompare = String(compareValue || '').trim();
    if (!normalizedValue || normalizedValue === '—') return '';
    if (normalizedCompare && normalizedValue.toLowerCase() === normalizedCompare.toLowerCase()) return '';
    return detailItem(label, normalizedValue, extraClass);
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
            const rawName = file.name || ('Attachment ' + (index + 1));
            const rawPath = file.path || '#';
            const rawType = file.type || '';
            const name = escapeHtml(rawName);
            const path = escapeHtml(rawPath);
            const type = escapeHtml(rawType);
            const size = formatFileSize(file.size);
            const meta = size ? `<small class="text-muted ms-1">(${escapeHtml(size)})</small>` : '';
            return `<div class="mb-1"><button type="button" class="withdrawal-attachment-open" data-attachment-path="${path}" data-attachment-name="${name}" data-attachment-type="${type}"><i class="bi bi-paperclip me-1"></i>${name}</button>${meta}</div>`;
        }).join('');
    }
    return `<div class="transaction-detail-item" style="grid-column:1/-1">
        <div class="transaction-detail-label">Attachments</div>
        <div class="transaction-detail-value">${content}</div>
    </div>`;
}

function openWithdrawalAttachmentView(path, name, type) {
    const modalElement = document.getElementById('withdrawalAttachmentViewModal');
    const titleElement = document.getElementById('withdrawalAttachmentViewTitle');
    const bodyElement = document.getElementById('withdrawalAttachmentViewBody');
    if (!modalElement || !bodyElement || !path) return;

    const safeName = name || 'Attachment';
    const filePath = path || '#';
    const mimeType = (type || '').toLowerCase();
    const extension = safeName.split('.').pop().toLowerCase();
    const isImage = mimeType.startsWith('image/') || ['jpg','jpeg','png','gif','webp','bmp','svg'].includes(extension);
    const isPdf = mimeType === 'application/pdf' || extension === 'pdf';

    if (titleElement) titleElement.textContent = safeName;

    if (isImage) {
        bodyElement.innerHTML = `<img src="${escapeHtml(filePath)}" alt="${escapeHtml(safeName)}" class="attachment-viewer-image">`;
    } else if (isPdf) {
        bodyElement.innerHTML = `<iframe src="${escapeHtml(filePath)}" class="attachment-viewer-frame" title="${escapeHtml(safeName)}"></iframe>`;
    } else {
        bodyElement.innerHTML = `<div class="attachment-download-box">
            <i class="bi bi-file-earmark-text fs-1 text-success d-block mb-2"></i>
            <div class="fw-bold mb-1" style="color:#052A47">${escapeHtml(safeName)}</div>
            <div class="text-muted">Preview is not available for this file type.</div>
        </div>`;
    }

    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

document.addEventListener('click', function(e) {
    const attachmentBtn = e.target.closest('.withdrawal-attachment-open');
    if (!attachmentBtn) return;
    e.preventDefault();
    e.stopPropagation();
    openWithdrawalAttachmentView(
        attachmentBtn.dataset.attachmentPath || '#',
        attachmentBtn.dataset.attachmentName || 'Attachment',
        attachmentBtn.dataset.attachmentType || ''
    );
});

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
    } else if (details.type === 'Expense Payable') {
        let linesHtml = '';
        if (Array.isArray(details.lines) && details.lines.length) {
            linesHtml = '<div class="detail-item"><span class="detail-label">Expense Lines</span><span class="detail-value"><table class="table table-sm mb-0"><thead><tr><th>Account</th><th>Amount</th><th>Memo</th></tr></thead><tbody>' +
                details.lines.map(line => `<tr><td>${escapeHtml(line.account)}</td><td>₱${escapeHtml(line.amount)}</td><td>${escapeHtml(line.memo || '—')}</td></tr>`).join('') +
                '</tbody></table></span></div>';
        }
        html += `
            ${detailItem('Expense No.', details.document_no)}
            ${detailItem('Date', details.document_date)}
            ${detailItem('Account Summary', details.account)}
            ${linesHtml}
            ${detailItem('Total Amount', '₱' + (details.amount || '0.00'), 'amount-neutral')}
            ${detailItem('Paid', '₱' + (details.paid_amount || '0.00'), 'amount-positive')}
            ${detailItem('Balance', '₱' + (details.balance || '0.00'), 'amount-negative')}
            ${detailItem('Memo', details.memo)}
            ${detailItem('Status', details.status)}
        `;

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
    } else if (details.type === 'Expense Payable') {
        let linesHtml = '';
        if (Array.isArray(details.lines) && details.lines.length) {
            linesHtml = '<div class="detail-item"><span class="detail-label">Expense Lines</span><span class="detail-value"><table class="table table-sm mb-0"><thead><tr><th>Account</th><th>Amount</th><th>Memo</th></tr></thead><tbody>' +
                details.lines.map(line => `<tr><td>${escapeHtml(line.account)}</td><td>₱${escapeHtml(line.amount)}</td><td>${escapeHtml(line.memo || '—')}</td></tr>`).join('') +
                '</tbody></table></span></div>';
        }
        html += `
            ${detailItem('Expense No.', details.document_no)}
            ${detailItem('Date', details.document_date)}
            ${detailItem('Account Summary', details.account)}
            ${linesHtml}
            ${detailItem('Total Amount', '₱' + (details.amount || '0.00'), 'amount-neutral')}
            ${detailItem('Paid', '₱' + (details.paid_amount || '0.00'), 'amount-positive')}
            ${detailItem('Balance', '₱' + (details.balance || '0.00'), 'amount-negative')}
            ${detailItem('Memo', details.memo)}
            ${detailItem('Status', details.status)}
        `;

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
            ${detailItem('Check Number', details.check_number)}
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
                ${detailItem('Bank Name', details.bank_name_full || details.partner)}
                ${detailItemIfDifferent('Expense Account Full', details.expense_account_full, details.expense_account)}
                ${detailItemIfDifferent('Payee Full', details.payee_full, details.payee)}
                ${attachmentsDetailItem(details.attachments)}
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
        const row = e.target.closest('.clickable-row[data-details-b64], .clickable-row[data-details]');
        if (row) openDetailsRow(row);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const row = e.target.closest('.clickable-row[data-details-b64], .clickable-row[data-details]');
        if (row) {
            e.preventDefault();
            openDetailsRow(row);
        }
    });
}

attachWithdrawalDetailsModalHandlers();

function initSupplierPayablesFilters() {
    const table = document.getElementById('supplierPayablesTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const searchFilter = document.getElementById('supplierPayableSearchFilter');
    const dateFilter = document.getElementById('supplierPayableDateFilter');
    const sortFilter = document.getElementById('supplierPayableSortFilter');
    const typeFilter = document.getElementById('supplierPayableTypeFilter');
    const emptyRow = tbody ? tbody.querySelector('tr td[colspan]')?.closest('tr') : null;
    const rows = tbody ? Array.from(tbody.querySelectorAll('tr.supplier-payable-row')) : [];

    if (!tbody || !rows.length) return;

    function getDateValue(row) {
        const raw = row.dataset.payableDate || '';
        const time = raw ? new Date(raw + 'T00:00:00').getTime() : 0;
        return Number.isFinite(time) ? time : 0;
    }

    function getAmountValue(row) {
        const amount = parseFloat(row.dataset.payableAmount || '0');
        return Number.isFinite(amount) ? amount : 0;
    }

    function applySupplierPayableFilters() {
        const selectedSearch = searchFilter ? searchFilter.value.trim().toLowerCase() : '';
        const selectedDate = dateFilter ? dateFilter.value : '';
        const selectedType = typeFilter ? typeFilter.value : 'all';
        const selectedSort = sortFilter ? sortFilter.value : 'date_desc';

        rows.forEach(row => {
            const rowDate = row.dataset.payableDate || '';
            const rowType = row.dataset.payableType || '';
            const rowText = row.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
            const matchSearch = selectedSearch === '' || rowText.includes(selectedSearch);
            const matchDate = selectedDate === '' || rowDate === selectedDate;
            const matchType = selectedType === 'all' || rowType === selectedType;
            row.style.display = (matchSearch && matchDate && matchType) ? '' : 'none';
        });

        const sortedRows = rows.slice().sort((a, b) => {
            if (selectedSort === 'date_asc') {
                return getDateValue(a) - getDateValue(b);
            }
            if (selectedSort === 'amount_desc') {
                const amountDiff = getAmountValue(b) - getAmountValue(a);
                return amountDiff !== 0 ? amountDiff : getDateValue(b) - getDateValue(a);
            }
            if (selectedSort === 'type') {
                const typeDiff = (a.dataset.payableType || '').localeCompare(b.dataset.payableType || '');
                return typeDiff !== 0 ? typeDiff : getDateValue(b) - getDateValue(a);
            }
            return getDateValue(b) - getDateValue(a);
        });

        sortedRows.forEach(row => tbody.appendChild(row));

        const visibleRows = rows.filter(row => row.style.display !== 'none');
        let noMatchRow = tbody.querySelector('#supplierPayableNoMatchRow');
        if (!noMatchRow) {
            noMatchRow = document.createElement('tr');
            noMatchRow.id = 'supplierPayableNoMatchRow';
            noMatchRow.innerHTML = '<td colspan="8" class="text-center py-4 text-muted">No payables match the selected filters.</td>';
            tbody.appendChild(noMatchRow);
        }
        noMatchRow.style.display = visibleRows.length ? 'none' : '';
        if (emptyRow) emptyRow.style.display = rows.length ? 'none' : '';
    }

    if (searchFilter) searchFilter.addEventListener('input', applySupplierPayableFilters);
    [dateFilter, sortFilter, typeFilter].forEach(control => {
        if (control) control.addEventListener('change', applySupplierPayableFilters);
    });

    applySupplierPayableFilters();
}

document.addEventListener('DOMContentLoaded',function(){
    initSupplierPayablesFilters();
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
    attachPaymentMethodListener();

    const withdrawalBankSelect = document.getElementById('withdrawalBankSelect');
    if (withdrawalBankSelect) {
        withdrawalBankSelect.addEventListener('change', updateWithdrawalBankBalance);
        updateWithdrawalBankBalance();
    }

    attachWithdrawalAccountDropdownEvents();
    autoFillWithdrawalDescription();

    const withdrawalAmount = document.getElementById('withdrawalAmount');
    if (withdrawalAmount) {
        withdrawalAmount.addEventListener('input', handleWithdrawalMainAmountInput);
        withdrawalAmount.addEventListener('change', handleWithdrawalMainAmountInput);
        withdrawalAmount.addEventListener('keyup', handleWithdrawalMainAmountInput);
        handleWithdrawalMainAmountInput();
    }
    const withdrawalDescription = document.getElementById('withdrawalDescription');
    if (withdrawalDescription) {
        withdrawalDescription.addEventListener('input', syncWithdrawalExpenseTable);
    }

    const withdrawalClearBtn = document.getElementById('withdrawalClearBtn');
    if (withdrawalClearBtn) {
        withdrawalClearBtn.addEventListener('click', clearWithdrawalForm);
    }

    const withdrawalForm = document.getElementById('withdrawalForm');
    if (withdrawalForm) {
        withdrawalForm.addEventListener('submit', validateWithdrawalExpenseAccount);
    }

    const withdrawalAttachmentInput = document.getElementById('withdrawalAttachmentInput');
    if (withdrawalAttachmentInput) {
        withdrawalAttachmentInput.addEventListener('change', renderWithdrawalAttachmentPreview);
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_withdrawal') === '1') {
        const withdrawalModalEl = document.getElementById('withdrawalModal');
        if (withdrawalModalEl) {
            if (urlParams.get('clear_withdrawal') === '1') {
                clearWithdrawalForm();
            }
            withdrawalModalEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }

});


// Withdrawal Items tab behavior and PO-style item table calculations
function setWithdrawalActiveTab(tabName) {
    const modal = document.getElementById('withdrawalModal');
    if (!modal) return;

    const currentTab = getWithdrawalActiveTabName();
    if (currentTab !== tabName) saveWithdrawalActiveTabAmount();

    withdrawalIsSwitchingTab = true;

    modal.querySelectorAll('[data-withdrawal-tab]').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-withdrawal-tab') === tabName);
    });

    modal.querySelectorAll('[data-withdrawal-panel]').forEach(panel => {
        panel.classList.toggle('active', panel.getAttribute('data-withdrawal-panel') === tabName);
    });

    if (tabName === 'items') {
        const calculatedItemsTotal = getWithdrawalCalculatedItemsTotal();
        if (calculatedItemsTotal > 0) withdrawalItemsTabAmount = calculatedItemsTotal;
        setWithdrawalMainAmountValue(withdrawalItemsTabAmount);
        updateWithdrawalItemsTabDisplay(withdrawalItemsTabAmount);
    } else if (tabName === 'expenses') {
        withdrawalExpenseTabAmount = getWithdrawalCalculatedExpensesTotal();
        setWithdrawalMainAmountValue(withdrawalExpenseTabAmount);
        updateWithdrawalExpenseTabDisplay(withdrawalExpenseTabAmount, false);
    }

    withdrawalIsSwitchingTab = false;
}


function parseWithdrawalMoneyValue(value) {
    return parseFloat(String(value || '').replace(/,/g, '').replace(/[₱\s]/g, '')) || 0;
}

function formatWithdrawalPeso(value) {
    return '₱' + (Number(value) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getWithdrawalItemById(itemId) {
    itemId = String(itemId || '');
    return (Array.isArray(withdrawalItemsData) ? withdrawalItemsData : []).find(item => String(item.item_id) === itemId) || null;
}

function getWithdrawalItemPriceForUom(item, uom) {
    if (!item) return 0;
    const key = String(uom || '').toLowerCase();
    if (item.uom_prices && Object.prototype.hasOwnProperty.call(item.uom_prices, key)) {
        return parseWithdrawalMoneyValue(item.uom_prices[key]);
    }
    return parseWithdrawalMoneyValue(item.unit_price);
}

function buildWithdrawalUomOptions(row, item) {
    const uomSelect = row.querySelector('.withdrawal-item-uom');
    if (!uomSelect) return;

    const currentValue = uomSelect.value;
    uomSelect.innerHTML = '<option value="">-- UOM --</option>';

    const uoms = Array.isArray(item?.available_uoms) ? item.available_uoms : [];
    uoms.forEach(uom => {
        const cleanUom = String(uom || '').trim();
        if (!cleanUom) return;
        const option = document.createElement('option');
        option.value = cleanUom;
        option.textContent = cleanUom;
        uomSelect.appendChild(option);
    });

    if (currentValue && [...uomSelect.options].some(opt => opt.value === currentValue)) {
        uomSelect.value = currentValue;
    } else if (uoms.length > 0) {
        uomSelect.value = String(uoms[0] || '');
    }
}

function syncWithdrawalItemRowFromSelectedItem(row) {
    if (!row) return;
    const selector = row.querySelector('.withdrawal-item-selector');
    const item = getWithdrawalItemById(selector?.value);
    const descInput = row.querySelector('.withdrawal-item-description');
    const codeHidden = row.querySelector('.withdrawal-item-code-hidden');
    const qtyInput = row.querySelector('.withdrawal-item-qty');
    const unitCostInput = row.querySelector('.withdrawal-item-unit-cost');

    if (!item) {
        if (descInput) descInput.value = '';
        if (codeHidden) codeHidden.value = '';
        buildWithdrawalUomOptions(row, null);
        if (unitCostInput) unitCostInput.value = '';
        calculateWithdrawalItemRow(row);
        updateWithdrawalItemsTotal(true);
        return;
    }

    const code = String(item.item_code || '').trim();
    const description = String(item.description || '').trim();
    if (descInput) descInput.value = [code, description].filter(Boolean).join(' - ');
    if (codeHidden) codeHidden.value = code;

    buildWithdrawalUomOptions(row, item);
    const uom = row.querySelector('.withdrawal-item-uom')?.value || item.unit_type || '';
    const price = getWithdrawalItemPriceForUom(item, uom);
    if (unitCostInput) unitCostInput.value = price > 0 ? price.toFixed(2) : '';
    if (qtyInput && !qtyInput.value) qtyInput.value = '1';

    calculateWithdrawalItemRow(row);
    updateWithdrawalItemsTotal(true);
}

function syncWithdrawalItemPriceFromUom(row) {
    if (!row) return;
    const selector = row.querySelector('.withdrawal-item-selector');
    const item = getWithdrawalItemById(selector?.value);
    const unitCostInput = row.querySelector('.withdrawal-item-unit-cost');
    const uom = row.querySelector('.withdrawal-item-uom')?.value || '';
    const price = getWithdrawalItemPriceForUom(item, uom);
    if (unitCostInput && price > 0) unitCostInput.value = price.toFixed(2);
    calculateWithdrawalItemRow(row);
    updateWithdrawalItemsTotal(true);
}

function calculateWithdrawalItemRow(row) {
    if (!row) return 0;
    const qty = parseWithdrawalMoneyValue(row.querySelector('.withdrawal-item-qty')?.value);
    const unitCost = parseWithdrawalMoneyValue(row.querySelector('.withdrawal-item-unit-cost')?.value);
    const discountText = String(row.querySelector('.withdrawal-item-discount')?.value || '').trim();
    let subtotal = qty * unitCost;
    let discount = 0;

    if (discountText.endsWith('%')) {
        const percent = parseWithdrawalMoneyValue(discountText.replace('%', ''));
        discount = subtotal * (percent / 100);
    } else {
        discount = parseWithdrawalMoneyValue(discountText);
    }

    const total = Math.max(0, subtotal - discount);
    const totalInput = row.querySelector('.withdrawal-item-total');
    if (totalInput) totalInput.value = total > 0 ? formatWithdrawalPeso(total) : '₱0.00';
    const totalHidden = row.querySelector('.withdrawal-item-total-hidden');
    if (totalHidden) totalHidden.value = total > 0 ? total.toFixed(2) : '0';
    return total;
}

function getWithdrawalCalculatedItemsTotal() {
    let total = 0;
    document.querySelectorAll('#withdrawalItemsTableBody .withdrawal-item-row').forEach(row => {
        total += calculateWithdrawalItemRow(row);
    });
    return total;
}

function updateWithdrawalItemsTotal(syncTopAmount = false) {
    const total = getWithdrawalCalculatedItemsTotal();
    const activeTab = getWithdrawalActiveTabName();

    updateWithdrawalItemsTabDisplay(total);

    // Only the Items tab can write the calculated item total into the top Amount field.
    // Expenses tab amount is preserved separately.
    if (syncTopAmount && activeTab === 'items' && total > 0 && !withdrawalIsSwitchingTab) {
        setWithdrawalMainAmountValue(total);
    }
}


function clearWithdrawalItemsTable() {
    document.querySelectorAll('#withdrawalItemsTableBody .withdrawal-item-row').forEach(row => {
        row.querySelectorAll('input').forEach(input => {
            if (input.classList.contains('withdrawal-item-total')) {
                input.value = '₱0.00';
            } else if (input.classList.contains('withdrawal-item-total-hidden')) {
                input.value = '0';
            } else {
                input.value = '';
            }
        });
        row.querySelectorAll('select').forEach(select => {
            if (select.classList.contains('withdrawal-item-uom')) {
                select.innerHTML = '<option value="">-- UOM --</option>';
            } else {
                select.value = '';
            }
        });
    });
    updateWithdrawalItemsTotal(false);
}

function attachWithdrawalItemsTabEvents() {
    const modal = document.getElementById('withdrawalModal');
    if (!modal) return;

    modal.querySelectorAll('[data-withdrawal-tab]').forEach(btn => {
        btn.addEventListener('click', function() {
            setWithdrawalActiveTab(this.getAttribute('data-withdrawal-tab') || 'expenses');
        });
    });

    modal.querySelectorAll('#withdrawalItemsTableBody .withdrawal-item-selector').forEach(select => {
        select.addEventListener('change', function() {
            syncWithdrawalItemRowFromSelectedItem(this.closest('.withdrawal-item-row'));
        });
    });

    modal.querySelectorAll('#withdrawalItemsTableBody .withdrawal-item-uom').forEach(select => {
        select.addEventListener('change', function() {
            syncWithdrawalItemPriceFromUom(this.closest('.withdrawal-item-row'));
        });
    });

    modal.querySelectorAll('#withdrawalItemsTableBody .withdrawal-item-qty, #withdrawalItemsTableBody .withdrawal-item-unit-cost, #withdrawalItemsTableBody .withdrawal-item-discount').forEach(input => {
        input.addEventListener('input', () => updateWithdrawalItemsTotal(true));
        input.addEventListener('change', () => updateWithdrawalItemsTotal(true));
        input.addEventListener('keyup', () => updateWithdrawalItemsTotal(true));
    });
}

document.addEventListener('DOMContentLoaded', function() {
    attachWithdrawalItemsTabEvents();
    updateWithdrawalItemsTotal(false);
});


let activeWithdrawalPayeeCombo = null;
let withdrawalPayeeDropdownEl = null;

function getWithdrawalPayeeDropdown() {
    if (!withdrawalPayeeDropdownEl) {
        withdrawalPayeeDropdownEl = document.createElement('div');
        withdrawalPayeeDropdownEl.className = 'withdrawal-account-dropdown withdrawal-payee-dropdown';
        document.body.appendChild(withdrawalPayeeDropdownEl);
    }
    return withdrawalPayeeDropdownEl;
}

function positionWithdrawalPayeeDropdown(combo) {
    const dropdown = getWithdrawalPayeeDropdown();
    const rect = combo.getBoundingClientRect();
    dropdown.style.left = rect.left + 'px';
    dropdown.style.top = (rect.bottom - 1) + 'px';
    dropdown.style.width = rect.width + 'px';
    dropdown.style.minWidth = Math.max(rect.width, 320) + 'px';
}

function closeWithdrawalPayeeDropdown() {
    const dropdown = getWithdrawalPayeeDropdown();
    dropdown.classList.remove('show');
    const toggle = document.querySelector('#withdrawalModal .withdrawal-payee-toggle');
    if (toggle) toggle.classList.remove('active');
    activeWithdrawalPayeeCombo = null;
}

function clearWithdrawalPayeeSelection(clearAddress = true) {
    const hidden = document.getElementById('withdrawalPayeeValue');
    const input = document.getElementById('withdrawalPayeeInput');
    const addressField = document.getElementById('withdrawalAddress');

    if (hidden) hidden.value = '';
    if (input) {
        delete input.dataset.selectedLabel;
        delete input.dataset.selectedName;
        delete input.dataset.address;
    }
    if (clearAddress && addressField) {
        addressField.value = '';
    }
}

function setWithdrawalPayeeSelection(option) {
    const hidden = document.getElementById('withdrawalPayeeValue');
    const input = document.getElementById('withdrawalPayeeInput');
    const addressField = document.getElementById('withdrawalAddress');

    const name = String(option.name || '').trim();
    const label = String(option.label || name).trim();
    const address = String(option.address || '').trim();

    if (hidden) hidden.value = name;
    if (input) {
        input.value = label;
        input.dataset.selectedLabel = label;
        input.dataset.selectedName = name;
        input.dataset.address = address;
    }
    if (addressField) {
        // Always sync the Address field with the selected Payee.
        // If the selected Payee has no saved address, keep it blank so the user can type one manually.
        addressField.value = address;
    }
    closeWithdrawalPayeeDropdown();
}

function renderWithdrawalPayeeDropdown(showAll = false) {
    const combo = document.getElementById('withdrawalPayeeCombo');
    const input = document.getElementById('withdrawalPayeeInput');
    if (!combo || !input) return;

    const dropdown = getWithdrawalPayeeDropdown();
    activeWithdrawalPayeeCombo = combo;
    positionWithdrawalPayeeDropdown(combo);

    const keyword = showAll ? '' : String(input.value || '').trim().toLowerCase();
    const payees = Array.isArray(WITHDRAWAL_PAYEE_OPTIONS) ? WITHDRAWAL_PAYEE_OPTIONS : [];
    const filtered = payees.filter(option => {
        const searchableText = [
            option.label || '',
            option.name || '',
            option.type || '',
            option.role || '',
            option.address || ''
        ].join(' ').toLowerCase();
        return keyword === '' || searchableText.includes(keyword);
    });

    dropdown.innerHTML = '';
    if (!filtered.length) {
        const empty = document.createElement('div');
        empty.className = 'withdrawal-account-empty';
        empty.textContent = 'No payee found';
        dropdown.appendChild(empty);
    } else {
        let lastType = '';
        filtered.forEach(option => {
            const type = String(option.type || 'Payee').trim() || 'Payee';
            if (type !== lastType) {
                const group = document.createElement('div');
                group.className = 'withdrawal-payee-group';
                group.textContent = type + 's';
                dropdown.appendChild(group);
                lastType = type;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'withdrawal-account-option';
            const label = String(option.label || option.name || '').trim();
            const role = String(option.role || '').trim();
            const subLabel = type === 'Employee' && role !== '' ? role : type;
            button.innerHTML = `<span class="withdrawal-account-option-label"></span><small></small>`;
            button.querySelector('.withdrawal-account-option-label').textContent = label;
            button.querySelector('small').textContent = subLabel;
            button.addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                setWithdrawalPayeeSelection(option);
            });
            dropdown.appendChild(button);
        });
    }

    dropdown.classList.add('show');
    const toggle = document.querySelector('#withdrawalModal .withdrawal-payee-toggle');
    if (toggle) toggle.classList.add('active');
}

function attachWithdrawalPayeeDropdownEvents() {
    const combo = document.getElementById('withdrawalPayeeCombo');
    const input = document.getElementById('withdrawalPayeeInput');
    const hidden = document.getElementById('withdrawalPayeeValue');
    const toggle = document.querySelector('#withdrawalModal .withdrawal-payee-toggle');
    const form = document.getElementById('withdrawalForm');
    if (!combo || !input || !hidden) return;

    input.addEventListener('input', function() {
        clearWithdrawalPayeeSelection();
        renderWithdrawalPayeeDropdown(false);
    });
    input.addEventListener('focus', function() {
        renderWithdrawalPayeeDropdown(false);
    });

    if (toggle) {
        toggle.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const dropdown = getWithdrawalPayeeDropdown();
            const isOpenForThisCombo = activeWithdrawalPayeeCombo === combo && dropdown.classList.contains('show');
            if (isOpenForThisCombo) {
                closeWithdrawalPayeeDropdown();
            } else {
                renderWithdrawalPayeeDropdown(true);
                setTimeout(() => input.focus(), 0);
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            if (!hidden.value.trim()) {
                e.preventDefault();
                amgcSwalFire({
                    icon: 'error',
                    title: 'Payee Required',
                    text: 'Please select a Payee from the dropdown.'
                });
                input.focus();
                renderWithdrawalPayeeDropdown(false);
                return false;
            }
            return true;
        });
    }

    document.addEventListener('mousedown', function(e) {
        if (e.target.closest('.withdrawal-payee-dropdown') || e.target.closest('.withdrawal-payee-combo')) return;
        closeWithdrawalPayeeDropdown();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeWithdrawalPayeeDropdown();
    });

    window.addEventListener('resize', closeWithdrawalPayeeDropdown);
    window.addEventListener('scroll', function() {
        if (activeWithdrawalPayeeCombo && getWithdrawalPayeeDropdown().classList.contains('show')) {
            positionWithdrawalPayeeDropdown(activeWithdrawalPayeeCombo);
        }
    }, true);
}

document.addEventListener('DOMContentLoaded', function() {
    attachWithdrawalPayeeDropdownEvents();
});

document.addEventListener('DOMContentLoaded', function () {
    updateEmployeesTaskBadge();
});


</script>

<?php
/* AMGC_JOURNAL_SOURCE_AUTOFILL_PATCH_V3
   This block is intentionally placed inside the source page so Journal Entries can open
   the original UI and populate the visible fields/tables from the original source record. */
$amgcJournalAutofillPayload = null;
if ((string)($_GET['from_journal_entries'] ?? '') === '1' || (string)($_GET['amgc_journal_autofill'] ?? '') === '1') {
    $amgcSourceTable = strtolower(trim((string)($_GET['source_table'] ?? '')));
    $amgcSourceId = (int)($_GET['source_id'] ?? $_GET['journal_source_id'] ?? 0);
    $amgcTransactionId = (int)($_GET['transaction_id'] ?? $_GET['journal_transaction_id'] ?? 0);
    $amgcTransactionNo = trim((string)($_GET['transaction_no'] ?? $_GET['journal_transaction_no'] ?? ''));
    $amgcReferenceNo = trim((string)($_GET['reference_no'] ?? $_GET['ref_no'] ?? ''));

    if (!function_exists('amgcJournalPatchTableExists')) {
        function amgcJournalPatchTableExists($conn, $table) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
            if ($table === '') return false;
            $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
            return $res && $res->num_rows > 0;
        }
        function amgcJournalPatchColumnExists($conn, $table, $column) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
            $column = preg_replace('/[^A-Za-z0-9_]/', '', (string)$column);
            if ($table === '' || $column === '' || !amgcJournalPatchTableExists($conn, $table)) return false;
            $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");
            return $res && $res->num_rows > 0;
        }
        function amgcJournalPatchFetchRows($conn, $sourceTable, $sourceId, $transactionNo, $referenceNo, $branchId, $viewAllBranches) {
            $rows = [];
            if (!amgcJournalPatchTableExists($conn, 'chart_account_transactions')) return $rows;
            $where = [];
            $types = '';
            $values = [];
            if ($sourceTable !== '' && $sourceId > 0 && amgcJournalPatchColumnExists($conn, 'chart_account_transactions', 'source_table') && amgcJournalPatchColumnExists($conn, 'chart_account_transactions', 'source_id')) {
                $where[] = '(cat.source_table = ? AND cat.source_id = ?)';
                $types .= 'si'; $values[] = $sourceTable; $values[] = $sourceId;
            }
            if ($transactionNo !== '') {
                $where[] = 'cat.transaction_no = ?';
                $types .= 's'; $values[] = $transactionNo;
            }
            if ($referenceNo !== '') {
                $where[] = 'cat.reference_no = ?';
                $types .= 's'; $values[] = $referenceNo;
            }
            if (!$where) return $rows;
            $accountExpr = amgcJournalPatchColumnExists($conn, 'chart_account_transactions', 'account_name') ? "COALESCE(NULLIF(cat.account_name,''), coa.account_title, CONCAT('Account #', cat.account_id))" : "COALESCE(coa.account_title, CONCAT('Account #', cat.account_id))";
            $sql = "SELECT cat.transaction_id, cat.account_id, {$accountExpr} AS account_title, cat.transaction_date, cat.transaction_type, cat.transaction_no, cat.reference_no, cat.memo, COALESCE(cat.debit,0) AS debit, COALESCE(cat.credit,0) AS credit";
            if (amgcJournalPatchColumnExists($conn, 'chart_account_transactions', 'counterparty')) $sql .= ", cat.counterparty"; else $sql .= ", '' AS counterparty";
            $sql .= " FROM chart_account_transactions cat LEFT JOIN chart_of_accounts coa ON coa.account_id = cat.account_id WHERE (" . implode(' OR ', $where) . ")";
            if (!$viewAllBranches && (int)$branchId > 0 && amgcJournalPatchColumnExists($conn, 'chart_account_transactions', 'branch_id')) {
                $sql .= " AND cat.branch_id = ?"; $types .= 'i'; $values[] = (int)$branchId;
            }
            $sql .= " ORDER BY cat.transaction_id ASC LIMIT 50";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($types !== '') $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $rows[] = $r;
                $stmt->close();
            }
            return $rows;
        }
        function amgcJournalPatchFetchOneByIdOrRef($conn, $table, $idColumn, $id, $refColumns, $transactionNo, $referenceNo, $branchId, $viewAllBranches) {
            if (!amgcJournalPatchTableExists($conn, $table)) return null;
            $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
            $safeId = preg_replace('/[^A-Za-z0-9_]/', '', $idColumn);
            $where = []; $types = ''; $values = [];
            if ($id > 0 && amgcJournalPatchColumnExists($conn, $safeTable, $safeId)) { $where[] = "`{$safeId}` = ?"; $types .= 'i'; $values[] = $id; }
            foreach ((array)$refColumns as $col) {
                $safeCol = preg_replace('/[^A-Za-z0-9_]/', '', $col);
                if ($safeCol === '' || !amgcJournalPatchColumnExists($conn, $safeTable, $safeCol)) continue;
                if ($transactionNo !== '') { $where[] = "`{$safeCol}` = ?"; $types .= 's'; $values[] = $transactionNo; }
                if ($referenceNo !== '') { $where[] = "`{$safeCol}` = ?"; $types .= 's'; $values[] = $referenceNo; }
            }
            if (!$where) return null;
            $sql = "SELECT * FROM `{$safeTable}` WHERE (" . implode(' OR ', $where) . ")";
            if (!$viewAllBranches && (int)$branchId > 0 && amgcJournalPatchColumnExists($conn, $safeTable, 'branch_id')) { $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)"; $types .= 'i'; $values[] = (int)$branchId; }
            $sql .= " ORDER BY `{$safeId}` DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return null;
            if ($types !== '') $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        }
        function amgcJournalPatchFetchAllById($conn, $table, $idColumn, $id, $limit = 100) {
            $rows = [];
            if ($id <= 0 || !amgcJournalPatchTableExists($conn, $table) || !amgcJournalPatchColumnExists($conn, $table, $idColumn)) return $rows;
            $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
            $safeId = preg_replace('/[^A-Za-z0-9_]/', '', $idColumn);
            $stmt = $conn->prepare("SELECT * FROM `{$safeTable}` WHERE `{$safeId}` = ? LIMIT " . (int)$limit);
            if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $res = $stmt->get_result(); while ($r = $res->fetch_assoc()) $rows[] = $r; $stmt->close(); }
            return $rows;
        }
    }

    $amgcJournalAutofillPayload = [
        'page' => basename($_SERVER['PHP_SELF']),
        'source_table' => $amgcSourceTable,
        'source_id' => $amgcSourceId,
        'transaction_id' => $amgcTransactionId,
        'transaction_no' => $amgcTransactionNo,
        'reference_no' => $amgcReferenceNo,
        'journal_rows' => amgcJournalPatchFetchRows($conn, $amgcSourceTable, $amgcSourceId, $amgcTransactionNo, $amgcReferenceNo, $branch_id ?? 0, $view_all_branches ?? false),
        'source' => null,
        'items' => []
    ];

    $lookupId = $amgcSourceTable === 'bank_transactions' ? $amgcSourceId : ($amgcTransactionId ?: $amgcSourceId);
    if ((int)($_GET['bank_transaction_id'] ?? 0) > 0) $lookupId = (int)$_GET['bank_transaction_id'];
    if ((int)($_GET['withdrawal_id'] ?? 0) > 0) $lookupId = (int)$_GET['withdrawal_id'];
    $amgcJournalAutofillPayload['source'] = amgcJournalPatchFetchOneByIdOrRef($conn, 'bank_transactions', 'transaction_id', $lookupId, ['reference_number','check_number'], $amgcTransactionNo, $amgcReferenceNo, $branch_id ?? 0, $view_all_branches ?? false);

}
?>
<script>
window.AMGC_JOURNAL_AUTOFILL_PAYLOAD = <?php echo json_encode($amgcJournalAutofillPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
(function(){
    const payload = window.AMGC_JOURNAL_AUTOFILL_PAYLOAD;
    if (!payload) return;
    const money = v => Number(String(v ?? 0).replace(/,/g,'')) || 0;
    const fmt = v => money(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
    const setVal = (sel, val) => { const el = document.querySelector(sel); if (el) { el.value = val == null ? '' : String(val); el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); } };
    const esc = v => String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    function firstDebitExpense(){
        const rows = Array.isArray(payload.journal_rows) ? payload.journal_rows : [];
        return rows.find(r => money(r.debit) > 0 && !String(r.account_title||'').toLowerCase().includes('bank')) || rows.find(r => money(r.debit)>0) || rows[0] || {};
    }
    function showNotice(msg){
        if (window.Swal) Swal.fire({icon:'success',title:'Journal transaction loaded',text:msg || 'The source transaction was auto-filled from Journal Entries.',timer:1600,showConfirmButton:false});
    }
    function openPayBills(){
        const src = payload.source || {}; const rows = Array.isArray(payload.items) && payload.items.length ? payload.items : [];
        let matched = null;
        document.querySelectorAll('#supplierPayablesTable tbody tr.supplier-payable-row').forEach(row => {
            if (matched) return;
            let d={}; try{ d=JSON.parse(row.getAttribute('data-payment')||'{}'); }catch(e){}
            const sameId = (payload.source_table === 'purchase_orders' && Number(d.po_id||0) === Number(payload.source_id||0)) || (Number(d.expense_id||0) === Number(payload.source_id||0));
            const sameRef = String(d.po_number||d.ref_no||d.expense_no||'').trim() && [payload.transaction_no,payload.reference_no,src.po_number,src.ref_no,src.expense_no].map(x=>String(x||'').trim()).includes(String(d.po_number||d.ref_no||d.expense_no||'').trim());
            if (sameId || sameRef) matched = row;
        });
        if (matched) {
            document.querySelectorAll('#supplierPayablesTable tbody tr.supplier-payable-row').forEach(r=>r.classList.remove('selected-bill-row'));
            matched.classList.add('selected-bill-row');
            const cb = matched.querySelector('.paybill-row-check'); if(cb) cb.checked = true;
            if (typeof refreshHighlightedBill === 'function') refreshHighlightedBill(matched);
            setTimeout(()=>{ if (typeof openPayBillsTransactionModal === 'function') openPayBillsTransactionModal('bill'); }, 150);
            showNotice('Pay Bills record was found in the Pay Bills list and opened.');
            return;
        }
        // Fallback for paid/closed bills that no longer appear in the payable list.
        if (document.getElementById('payBillsTransactionModal')) {
            const set = (id,v)=>{const el=document.getElementById(id); if(el) el.value = v == null ? '' : String(v);};
            set('payBillsModalPayableAccount', src.payable_account || firstDebitExpense().account_title || 'Accounts Payable');
            set('payBillsModalVendor', src.supplier_name || src.vendor_name || src.display_supplier_name || firstDebitExpense().counterparty || '');
            set('payBillsModalAddress', src.address || src.vendor_address || '');
            set('payBillsModalTerms', src.terms || '');
            set('payBillsModalDocumentNo', src.po_number || src.expense_no || src.ref_no || payload.transaction_no || payload.reference_no || '');
            set('payBillsModalBillDue', String(src.expected_delivery || src.bill_due || src.due_date || src.order_date || '').slice(0,10));
            set('payBillsModalMemo', src.memo || firstDebitExpense().memo || '');
            set('payBillsModalDate', String(src.order_date || src.expense_date || firstDebitExpense().transaction_date || '').slice(0,10));
            set('payBillsModalRefNo', src.ref_no || src.po_number || payload.reference_no || payload.transaction_no || '');
            set('payBillsModalAmount', fmt(src.total_amount || src.balance || firstDebitExpense().debit || firstDebitExpense().credit));
            const itemBody = document.getElementById('payBillsModalItemsBody');
            const expBody = document.getElementById('payBillsModalExpensesBody');
            if (itemBody) {
                const useRows = rows.length ? rows : [];
                itemBody.innerHTML = useRows.length ? useRows.map(r=>`<tr><td>${esc(r.item_name||r.product_name||r.name||src.po_number||'Item')}</td><td>${esc(r.description||r.item_description||r.product_code||r.memo||'')}</td><td>${esc(r.unit_type||r.uom||'')}</td><td class="text-end">${fmt(r.quantity||r.qty||r.quantity_ordered||1)}</td><td class="text-end">${fmt(r.unit_price||r.unit_cost||r.price||0)}</td><td class="text-end">${fmt(r.line_total||r.total_cost||r.amount||r.total_amount||0)}</td></tr>`).join('') : '<tr class="paybills-modal-empty-row"><td colspan="6">No item rows found. Accounting rows are shown in Expenses.</td></tr>';
            }
            if (expBody) {
                const jr = payload.journal_rows || [];
                expBody.innerHTML = jr.length ? jr.map(r=>`<tr><td>${esc(r.account_title)}</td><td>${esc(r.memo||r.counterparty||'')}</td><td class="text-end">${fmt(money(r.debit)||money(r.credit))}</td></tr>`).join('') : '<tr class="paybills-modal-empty-row"><td colspan="3">No accounting rows found.</td></tr>';
            }
            const modal = new bootstrap.Modal(document.getElementById('payBillsTransactionModal')); modal.show();
            showNotice('Paid/closed Pay Bills record was loaded directly from database.');
        }
    }
    function openWithdrawal(){
        const src = payload.source || {}; const row = firstDebitExpense();
        setVal('#withdrawalBankSelect', src.bank_id || '');
        setVal('input[name="reference_number"]', src.reference_number || src.check_number || payload.reference_no || payload.transaction_no || '');
        setVal('input[name="transaction_date"]', String(src.transaction_date || row.transaction_date || '').slice(0,10));
        setVal('#withdrawalAmount', src.amount || row.debit || row.credit || '');
        setVal('#withdrawalDescription', src.description || row.memo || '');
        setVal('#withdrawalPayeeValue', src.payee || row.counterparty || '');
        setVal('#withdrawalPayeeInput', src.payee || row.counterparty || '');
        setVal('#withdrawalAddress', src.address || '');
        const exp = document.getElementById('withdrawalExpenseAccountSelect'); if(exp){ exp.value = src.expense_account || row.account_title || ''; exp.dataset.accountId = row.account_id || ''; exp.dispatchEvent(new Event('input',{bubbles:true})); }
        setVal('#withdrawalExpenseAmountMirror', src.amount || row.debit || row.credit || '');
        setVal('#withdrawalExpenseMemoMirror', src.description || row.memo || '');
        if (typeof updateWithdrawalExpensesTotal === 'function') updateWithdrawalExpensesTotal(true);
        if (typeof setWithdrawalActiveTab === 'function') setWithdrawalActiveTab('expenses');
        if (typeof updateWithdrawalAmountWords === 'function') updateWithdrawalAmountWords();
        document.getElementById('withdrawalForm')?.scrollIntoView({behavior:'smooth',block:'start'});
        showNotice('Withdrawal form and expense table were auto-filled.');
    }
    function openDeposit(){
        const src = payload.source || {}; const jr = payload.journal_rows || [];
        const items = Array.isArray(payload.items) ? payload.items : [];
        const details = {
            date: String(src.transaction_date || jr[0]?.transaction_date || '').slice(0,10),
            reference: src.reference_number || src.check_number || payload.reference_no || payload.transaction_no || '',
            bank: src.bank_name || '',
            description: src.description || jr[0]?.memo || '',
            amount: fmt(src.amount || jr.reduce((s,r)=>s+money(r.debit),0) || jr.reduce((s,r)=>s+money(r.credit),0)),
            encoded_by: src.created_by_name || '',
            items: items.length ? items : jr.map(r=>({type:'payment', customer:r.counterparty||'', invoice:r.reference_no||r.transaction_no||'', amount:fmt(money(r.debit)||money(r.credit))}))
        };
        if (typeof showDepositDetails === 'function') showDepositDetails(JSON.stringify(details));
        document.getElementById('depositFormWrapper')?.scrollIntoView({behavior:'smooth',block:'start'});
        showNotice('Deposit details table was auto-filled.');
    }
    function openGeneric(){
        const jr = payload.journal_rows || [];
        const html = `<div style="text-align:left"><b>Source:</b> ${esc(payload.source_table)} #${esc(payload.source_id)}<div class="table-responsive mt-3"><table class="table table-sm table-bordered"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th><th>Memo</th><th>Counterparty</th></tr></thead><tbody>${jr.length ? jr.map(r=>`<tr><td>${esc(r.account_title)}</td><td class="text-end">${money(r.debit)?fmt(r.debit):''}</td><td class="text-end">${money(r.credit)?fmt(r.credit):''}</td><td>${esc(r.memo)}</td><td>${esc(r.counterparty)}</td></tr>`).join('') : '<tr><td colspan="5" class="text-center">No rows found.</td></tr>'}</tbody></table></div></div>`;
        if (window.Swal) Swal.fire({title:'Journal Source Transaction',html,width:900,confirmButtonText:'Close'});
    }
    document.addEventListener('DOMContentLoaded', function(){
        setTimeout(function(){
            const page = String(payload.page || '').toLowerCase();
            if (page.includes('paybills')) openPayBills();
            else if (page.includes('withdrawal')) openWithdrawal();
            else if (page.includes('deposit')) openDeposit();
            else openGeneric();
        }, 450);
    });
})();
</script>


<script>/* AMGC_JOURNAL_UNLOCK_EDIT_PATCH_V4 */
document.addEventListener('DOMContentLoaded',function(){
 if(!(new URLSearchParams(location.search).get('from_journal_entries')==='1')) return;
 setTimeout(function(){document.querySelectorAll('input,select,textarea,button').forEach(function(el){ if(el.type!=='hidden'){ el.disabled=false; el.readOnly=false; }});},700);
});

function updateEmployeesTaskBadge() {
    const employeesMenu = document.getElementById('employeesMenu');
    const employeesDropdown = employeesMenu?.closest('.employees-dropdown');

    if (!employeesMenu || !employeesDropdown) return;

    employeesDropdown.classList.toggle(
        'employees-menu-open',
        employeesMenu.classList.contains('show')
    );
}
</script>

</body>
</html>