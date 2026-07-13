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
    // Deposit bank list now comes from Chart of Accounts.
    // Only active accounts with Account Type = Bank are shown here.
    // Sub accounts are kept under their parent account with indentation for the Select Account dropdown.
    if (!tableExists($conn, 'chart_of_accounts')) return [];

    $has_parent_account = columnExists($conn, 'chart_of_accounts', 'parent_account_id');
    $parent_select = $has_parent_account ? 'parent_account_id' : '0 AS parent_account_id';

    $sql = "SELECT
                account_id AS bank_id,
                account_title AS bank_name,
                account_code AS account_number,
                {$parent_select},
                balance,
                branch_id
            FROM chart_of_accounts
            WHERE account_type = 'Bank'";
    if ($active_only) $sql .= " AND status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $sql .= " ORDER BY account_title ASC, account_id ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    if (empty($rows)) return [];

    $by_id = [];
    foreach ($rows as $row) {
        $row['bank_id'] = (int)($row['bank_id'] ?? 0);
        $row['parent_account_id'] = (int)($row['parent_account_id'] ?? 0);
        $row['indent_level'] = 0;
        $by_id[$row['bank_id']] = $row;
    }

    $children = [];
    $roots = [];
    foreach ($by_id as $id => $row) {
        $parent_id = (int)($row['parent_account_id'] ?? 0);
        if ($parent_id > 0 && isset($by_id[$parent_id])) {
            if (!isset($children[$parent_id])) $children[$parent_id] = [];
            $children[$parent_id][] = $row;
        } else {
            $roots[] = $row;
        }
    }

    foreach ($by_id as $id => $row) {
        $by_id[$id]['has_children'] = !empty($children[$id]);
    }
    foreach ($roots as &$rootRow) {
        $rootId = (int)($rootRow['bank_id'] ?? 0);
        $rootRow['has_children'] = !empty($children[$rootId]);
    }
    unset($rootRow);
    foreach ($children as $parentId => &$childRowsForFlag) {
        foreach ($childRowsForFlag as &$childRowForFlag) {
            $childId = (int)($childRowForFlag['bank_id'] ?? 0);
            $childRowForFlag['has_children'] = !empty($children[$childId]);
        }
        unset($childRowForFlag);
    }
    unset($childRowsForFlag);

    $sort_accounts = function (&$accounts) {
        usort($accounts, function ($a, $b) {
            $nameCompare = strcasecmp((string)($a['bank_name'] ?? ''), (string)($b['bank_name'] ?? ''));
            if ($nameCompare !== 0) return $nameCompare;
            return ((int)($a['bank_id'] ?? 0)) <=> ((int)($b['bank_id'] ?? 0));
        });
    };

    $sort_accounts($roots);
    foreach ($children as &$childRows) {
        $sort_accounts($childRows);
    }
    unset($childRows);

    $ordered = [];
    $appendAccount = function ($account, $level) use (&$appendAccount, &$ordered, &$children) {
        $account['indent_level'] = max(0, (int)$level);
        $ordered[] = $account;
        $account_id = (int)($account['bank_id'] ?? 0);
        if (!empty($children[$account_id])) {
            foreach ($children[$account_id] as $child) {
                $appendAccount($child, $level + 1);
            }
        }
    };

    foreach ($roots as $root) {
        $appendAccount($root, 0);
    }

    return $ordered;
}
function getRegisteredBankName($conn, $bank_id, $view_all_branches, $branch_id) {
    if (!tableExists($conn, 'chart_of_accounts')) return '';
    $bank_id = (int)$bank_id;
    if ($bank_id <= 0) return '';
    $sql = "SELECT account_title FROM chart_of_accounts WHERE account_id = ? AND account_type = 'Bank' AND status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return '';
    if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('ii', $bank_id, $branch_id); else $stmt->bind_param('i', $bank_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim($row['account_title'] ?? '');
}
function depositAccountHasSubAccounts($conn, $account_id, $view_all_branches, $branch_id) {
    if (!tableExists($conn, 'chart_of_accounts') || !columnExists($conn, 'chart_of_accounts', 'parent_account_id')) return false;
    $account_id = (int)$account_id;
    if ($account_id <= 0) return false;

    $sql = "SELECT COUNT(*) AS total FROM chart_of_accounts WHERE parent_account_id = ? AND account_type = 'Bank' AND status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('ii', $account_id, $branch_id); else $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function getChartBankBalanceTotal($conn, $view_all_branches, $branch_id) {
    if (!tableExists($conn, 'chart_of_accounts')) return 0.00;
    $sql = "SELECT COALESCE(SUM(balance),0) AS total FROM chart_of_accounts WHERE account_type = 'Bank' AND status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sql .= " AND (branch_id = ? OR branch_id = 0)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.00;
    if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['total'] ?? 0);
}
function ensureDepositAccountingTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `journal_entries` (
        `journal_id` INT(11) NOT NULL AUTO_INCREMENT,
        `entry_no` VARCHAR(100) NOT NULL,
        `journal_date` DATE NOT NULL,
        `attachment_path` TEXT DEFAULT NULL,
        `branch_id` INT(11) NOT NULL DEFAULT 0,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`journal_id`),
        KEY `entry_no` (`entry_no`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `journal_entry_details` (
        `detail_id` INT(11) NOT NULL AUTO_INCREMENT,
        `journal_id` INT(11) NOT NULL,
        `account_id` INT(11) NOT NULL DEFAULT 0,
        `account_title` VARCHAR(255) NOT NULL,
        `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `memo` TEXT DEFAULT NULL,
        `counterparty` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`detail_id`),
        KEY `journal_id` (`journal_id`),
        KEY `account_id` (`account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `chart_account_transactions` (
        `transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
        `account_id` INT(11) NOT NULL DEFAULT 0,
        `branch_id` INT(11) NOT NULL DEFAULT 0,
        `transaction_date` DATE DEFAULT NULL,
        `transaction_type` VARCHAR(100) DEFAULT NULL,
        `transaction_no` VARCHAR(100) DEFAULT NULL,
        `reference_no` VARCHAR(100) DEFAULT NULL,
        `memo` TEXT DEFAULT NULL,
        `account_name` VARCHAR(255) DEFAULT NULL,
        `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `source_table` VARCHAR(100) DEFAULT NULL,
        `source_id` INT(11) DEFAULT NULL,
        `counterparty` VARCHAR(255) DEFAULT NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`transaction_id`),
        KEY `account_id` (`account_id`),
        KEY `branch_id` (`branch_id`),
        KEY `source_table_id` (`source_table`, `source_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $neededColumns = [
        'chart_account_transactions' => [
            'transaction_date' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_date` DATE DEFAULT NULL AFTER `branch_id`",
            'transaction_type' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_type` VARCHAR(100) DEFAULT NULL AFTER `transaction_date`",
            'transaction_no' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `transaction_no` VARCHAR(100) DEFAULT NULL AFTER `transaction_type`",
            'reference_no' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `reference_no` VARCHAR(100) DEFAULT NULL AFTER `transaction_no`",
            'memo' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `memo` TEXT DEFAULT NULL AFTER `reference_no`",
            'account_name' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `account_name` VARCHAR(255) DEFAULT NULL AFTER `memo`",
            'balance_after' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `credit`",
            'source_table' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_table` VARCHAR(100) DEFAULT NULL AFTER `balance_after`",
            'source_id' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `source_id` INT(11) DEFAULT NULL AFTER `source_table`",
            'counterparty' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `counterparty` VARCHAR(255) DEFAULT NULL AFTER `source_id`",
            'created_by' => "ALTER TABLE `chart_account_transactions` ADD COLUMN `created_by` INT(11) NOT NULL DEFAULT 0 AFTER `counterparty`"
        ],
        'journal_entry_details' => [
            'counterparty' => "ALTER TABLE `journal_entry_details` ADD COLUMN `counterparty` VARCHAR(255) DEFAULT NULL AFTER `memo`"
        ]
    ];

    foreach ($neededColumns as $table => $columns) {
        if (!tableExists($conn, $table)) continue;
        foreach ($columns as $column => $sql) {
            if (!columnExists($conn, $table, $column)) @ $conn->query($sql);
        }
    }
}

function findOrCreateDepositAccount($conn, $titles, $type, $branch_id, $user_id) {
    if (!is_array($titles)) $titles = [$titles];
    $branch_id = (int)$branch_id;
    foreach ($titles as $title) {
        $title = trim((string)$title);
        if ($title === '') continue;
        $sql = "SELECT account_id, account_title, balance FROM chart_of_accounts WHERE status = 'active' AND account_title = ?";
        if ($branch_id > 0 && columnExists($conn, 'chart_of_accounts', 'branch_id')) {
            $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL) ORDER BY CASE WHEN branch_id = ? THEN 0 ELSE 1 END, account_id ASC LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) $stmt->bind_param('sii', $title, $branch_id, $branch_id);
        } else {
            $sql .= " ORDER BY account_id ASC LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) $stmt->bind_param('s', $title);
        }
        if (!$stmt) continue;
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    }

    $title = trim((string)$titles[0]);
    if ($title === '') throw new Exception('Missing account title for deposit posting.');
    $target_branch = $branch_id > 0 ? $branch_id : null;
    $description = 'Auto-created by Record Deposits accounting posting.';
    $balance = 0.00;
    $account_code = '';
    $parent = null;
    $stmt = $conn->prepare("INSERT INTO chart_of_accounts (branch_id, parent_account_id, account_code, account_title, account_type, description, balance, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('Failed to create chart account: ' . $conn->error);
    $stmt->bind_param('iissssdi', $target_branch, $parent, $account_code, $title, $type, $description, $balance, $user_id);
    if (!$stmt->execute()) throw new Exception('Failed to create chart account ' . $title . ': ' . $stmt->error);
    $id = (int)$conn->insert_id;
    $stmt->close();
    return ['account_id' => $id, 'account_title' => $title, 'balance' => 0.00];
}

function updateDepositAccountBalance($conn, $account_id, $debit, $credit) {
    $account_id = (int)$account_id;
    $debit = round((float)$debit, 2);
    $credit = round((float)$credit, 2);
    $stmt = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance,0) + ? - ? WHERE account_id = ?");
    if (!$stmt) throw new Exception('Unable to update Chart of Accounts balance: ' . $conn->error);
    $stmt->bind_param('ddi', $debit, $credit, $account_id);
    if (!$stmt->execute()) throw new Exception('Failed to update Chart of Accounts balance: ' . $stmt->error);
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT COALESCE(balance,0) AS balance FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if (!$stmt2) return 0.00;
    $stmt2->bind_param('i', $account_id);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    return (float)($row['balance'] ?? 0);
}


function getUndepositedPaymentsSourceTotal($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    if (!tableExists($conn, 'payments') || !tableExists($conn, 'bank_transaction_payments')) return 0.00;

    $payments_has_so_id = columnExists($conn, 'payments', 'so_id');
    $has_payment_branch = columnExists($conn, 'payments', 'branch_id');
    $has_invoice_branch = tableExists($conn, 'invoices') && columnExists($conn, 'invoices', 'branch_id');
    $has_customer_branch = tableExists($conn, 'customers') && columnExists($conn, 'customers', 'branch_id');
    $has_payment_status = columnExists($conn, 'payments', 'status');

    $sales_order_join = "LEFT JOIN sales_orders so ON 1=0";
    if (tableExists($conn, 'sales_orders')) {
        if ($invoices_has_so_id && $payments_has_so_id) {
            $sales_order_join = "LEFT JOIN sales_orders so ON (i.so_id = so.so_id OR p.so_id = so.so_id)";
        } elseif ($invoices_has_so_id) {
            $sales_order_join = "LEFT JOIN sales_orders so ON i.so_id = so.so_id";
        } elseif ($payments_has_so_id) {
            $sales_order_join = "LEFT JOIN sales_orders so ON p.so_id = so.so_id";
        }
    }

    $sql = "SELECT COALESCE(SUM(p.amount), 0) AS total
            FROM payments p
            LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            {$sales_order_join}
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            WHERE btp.payment_id IS NULL";

    if ($has_payment_status) {
        $sql .= " AND (p.status IS NULL OR TRIM(p.status) = '' OR LOWER(TRIM(p.status)) = 'completed')";
    }

    if (!$view_all_branches && (int)$branch_id > 0) {
        $branch_id_int = (int)$branch_id;
        $branchParts = [];
        if ($has_payment_branch) $branchParts[] = "p.branch_id = {$branch_id_int}";
        if ($has_invoice_branch) $branchParts[] = "i.branch_id = {$branch_id_int}";
        if ($so_branch_column_exists) $branchParts[] = "so.branch_id = {$branch_id_int}";
        if ($has_customer_branch) $branchParts[] = "c.branch_id = {$branch_id_int}";
        if (!empty($branchParts)) $sql .= " AND (" . implode(' OR ', $branchParts) . ")";
    }

    $res = $conn->query($sql);
    if (!$res) return 0.00;
    $row = $res->fetch_assoc();
    return round((float)($row['total'] ?? 0), 2);
}

function syncUndepositedFundsBalanceFromSource($conn, $view_all_branches, $branch_id, $user_id, $so_branch_column_exists, $invoices_has_so_id) {
    if (!tableExists($conn, 'chart_of_accounts')) return 0.00;
    $effective_branch_id = (!$view_all_branches && (int)$branch_id > 0) ? (int)$branch_id : 0;
    $source_total = getUndepositedPaymentsSourceTotal($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
    $account = findOrCreateDepositAccount($conn, ['Undeposited Funds'], 'Other Current Asset', $effective_branch_id, $user_id);
    $account_id = (int)($account['account_id'] ?? 0);
    if ($account_id > 0) {
        $stmt = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
        if ($stmt) {
            $stmt->bind_param('di', $source_total, $account_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    return $source_total;
}

function postDepositToChartOfAccounts($conn, $account_id, $branch_id_db, $transaction_date, $reference_number, $memo, $amount, $transaction_id, $user_id) {
    if (!tableExists($conn, 'chart_of_accounts')) return;
    ensureDepositAccountingTables($conn);

    $account_id = (int)$account_id;
    $amount = round((float)$amount, 2);
    $transaction_id = (int)$transaction_id;
    $branch_id_db = (int)$branch_id_db;
    $user_id = (int)$user_id;
    if ($account_id <= 0 || $amount <= 0 || $transaction_id <= 0) return;

    // Strong duplicate guard: one deposit posting only per bank transaction.
    if (tableExists($conn, 'chart_account_transactions')) {
        $dup = $conn->prepare("SELECT transaction_id FROM chart_account_transactions WHERE source_table = 'bank_transactions' AND source_id = ? AND transaction_type = 'Record Deposits' LIMIT 1");
        if ($dup) {
            $dup->bind_param('i', $transaction_id);
            $dup->execute();
            $exists = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($exists) return;
        }
    }

    $stmt = $conn->prepare("SELECT account_id, balance, account_title FROM chart_of_accounts WHERE account_id = ? AND account_type = 'Bank' AND status = 'active' LIMIT 1");
    if (!$stmt) throw new Exception('Unable to verify selected bank account.');
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $bank = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bank) throw new Exception('Selected bank account was not found in Chart of Accounts.');

    $undeposited = findOrCreateDepositAccount($conn, ['Undeposited Funds'], 'Other Current Asset', $branch_id_db, $user_id);

    $txn_date = date('Y-m-d', strtotime($transaction_date));
    $transaction_no = 'DEP-' . str_pad((string)$transaction_id, 6, '0', STR_PAD_LEFT);
    $reference_number = trim((string)$reference_number);
    if ($reference_number === '') $reference_number = $transaction_no;
    $memo = trim((string)$memo) !== '' ? trim((string)$memo) : 'Record Deposits';
    $counterparty = 'Record Deposits';

    // Journal Header
    $jh = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, created_by) VALUES (?, ?, NULL, ?, ?)");
    if (!$jh) throw new Exception('Unable to prepare deposit journal entry: ' . $conn->error);
    $jh->bind_param('ssii', $transaction_no, $txn_date, $branch_id_db, $user_id);
    if (!$jh->execute()) throw new Exception('Failed to save deposit journal entry: ' . $jh->error);
    $journal_id = (int)$conn->insert_id;
    $jh->close();

    $details = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$details) throw new Exception('Unable to prepare deposit journal details: ' . $conn->error);

    $cat = $conn->prepare("INSERT INTO chart_account_transactions
        (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, debit, credit, balance_after, source_table, source_id, counterparty, created_by)
        VALUES (?, ?, ?, 'Record Deposits', ?, ?, ?, ?, ?, ?, ?, 'bank_transactions', ?, ?, ?)");
    if (!$cat) throw new Exception('Unable to prepare deposit Chart of Accounts transaction: ' . $conn->error);

    $lines = [
        ['account' => $bank, 'debit' => $amount, 'credit' => 0.00],
        ['account' => $undeposited, 'debit' => 0.00, 'credit' => $amount]
    ];

    foreach ($lines as $line) {
        $acc = $line['account'];
        $aid = (int)$acc['account_id'];
        $title = (string)$acc['account_title'];
        $debit = (float)$line['debit'];
        $credit = (float)$line['credit'];
        $balance_after = updateDepositAccountBalance($conn, $aid, $debit, $credit);

        $details->bind_param('iisddss', $journal_id, $aid, $title, $debit, $credit, $memo, $counterparty);
        if (!$details->execute()) throw new Exception('Failed to save deposit journal line: ' . $details->error);

        $cat->bind_param('iisssssdddisi', $aid, $branch_id_db, $txn_date, $transaction_no, $reference_number, $memo, $title, $debit, $credit, $balance_after, $transaction_id, $counterparty, $user_id);
        if (!$cat->execute()) throw new Exception('Failed to save deposit in Chart of Accounts quick report: ' . $cat->error);
    }

    $details->close();
    $cat->close();
}
function getUserInitials($user_name) {
    $parts = preg_split('/\s+/', trim($user_name));
    $initials = '';
    foreach ($parts as $part) { if ($part !== '') $initials .= strtoupper(substr($part, 0, 1)); }
    return $initials !== '' ? substr($initials, 0, 2) : 'BA';
}

function formatPaymentDetails($payment) {
    $method = strtolower(trim((string)($payment['payment_method'] ?? '')));
    if ($method === 'check') {
        $parts = [];
        $check_date = trim((string)($payment['check_date'] ?? ''));
        if ($check_date !== '' && $check_date !== '0000-00-00') $parts[] = date('m/d/Y', strtotime($check_date));
        $bank_name = trim((string)($payment['bank_name'] ?? ''));
        if ($bank_name !== '') $parts[] = $bank_name;
        $bank_branch = trim((string)($payment['bank_branch'] ?? ''));
        if ($bank_branch !== '') $parts[] = $bank_branch;
        $check_number = trim((string)($payment['check_number'] ?? ''));
        if ($check_number === '') $check_number = trim((string)($payment['reference_number'] ?? ''));
        if ($check_number !== '') $parts[] = $check_number;
        return !empty($parts) ? implode(' / ', $parts) : 'Check';
    }
    if ($method === 'online_transfer') {
        $wallet = trim((string)($payment['bank_name'] ?? ''));
        $reference_number = trim((string)($payment['reference_number'] ?? ''));
        $parts = [];
        if ($wallet !== '') $parts[] = $wallet;
        if ($reference_number !== '') $parts[] = $reference_number;
        return !empty($parts) ? implode(' / ', $parts) : 'Online Transfer';
    }
    if ($method === 'cash') return 'Cash';
    return ucwords(str_replace('_', ' ', $method));
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
$payments_has_description = columnExists($conn, 'payments', 'description');

function getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    $payment_description_select = columnExists($conn, 'payments', 'description') ? 'p.description' : "''";
    $payments_has_so_id = columnExists($conn, 'payments', 'so_id');
    $invoice_date_select = columnExists($conn, 'invoices', 'invoice_date') ? 'i.invoice_date AS invoice_date' : 'NULL AS invoice_date';
    $invoice_due_date_select = columnExists($conn, 'invoices', 'due_date') ? 'i.due_date AS invoice_due_date' : 'NULL AS invoice_due_date';
    $invoice_si_select = columnExists($conn, 'invoices', 'si_number') ? 'i.si_number AS invoice_si_number' : "'' AS invoice_si_number";
    $so_atw_select = columnExists($conn, 'sales_orders', 'atw_no') ? 'so.atw_no AS so_atw_no' : "'' AS so_atw_no";
    $so_gatepass_select = columnExists($conn, 'sales_orders', 'gatepass_no') ? 'so.gatepass_no AS so_gatepass_no' : "'' AS so_gatepass_no";
    $so_delivery_select = columnExists($conn, 'sales_orders', 'fulfillment_type') ? 'so.fulfillment_type AS so_delivery_type' : "'' AS so_delivery_type";
    $so_order_date_select = columnExists($conn, 'sales_orders', 'order_date') ? 'so.order_date AS so_order_date' : 'NULL AS so_order_date';
    $sales_order_join = "LEFT JOIN sales_orders so ON 1=0";
    if ($invoices_has_so_id && $payments_has_so_id) {
        $sales_order_join = "LEFT JOIN sales_orders so ON (i.so_id = so.so_id OR p.so_id = so.so_id)";
    } elseif ($invoices_has_so_id) {
        $sales_order_join = "LEFT JOIN sales_orders so ON i.so_id = so.so_id";
    } elseif ($payments_has_so_id) {
        $sales_order_join = "LEFT JOIN sales_orders so ON p.so_id = so.so_id";
    }
    $payment_so_id_select = $payments_has_so_id ? 'p.so_id AS payment_so_id' : 'NULL AS payment_so_id';
    $invoice_so_id_select = $invoices_has_so_id ? 'i.so_id AS invoice_so_id' : 'NULL AS invoice_so_id';
    $sql = "SELECT p.payment_id, p.invoice_id, {$payment_so_id_select}, {$invoice_so_id_select}, p.customer_id, p.payment_method, p.amount, p.payment_date,
                   p.reference_number, p.bank_name, p.bank_branch, p.check_date, p.check_number, p.si_number, {$payment_description_select} AS description, c.customer_name, i.invoice_number,
                   {$invoice_date_select}, {$invoice_due_date_select}, {$invoice_si_select}, {$so_atw_select}, {$so_gatepass_select}, {$so_delivery_select}, {$so_order_date_select},
                   COALESCE(so.so_number, '') AS so_number,
                   CASE
                       WHEN COALESCE(i.invoice_number, '') <> '' AND COALESCE(so.so_number, '') <> '' THEN CONCAT(i.invoice_number, ' / ', so.so_number)
                       WHEN COALESCE(i.invoice_number, '') <> '' THEN i.invoice_number
                       WHEN COALESCE(so.so_number, '') <> '' THEN so.so_number
                       ELSE CONCAT('Payment #', p.payment_id)
                   END AS transaction_label,
                   CASE
                       WHEN COALESCE(i.invoice_number, '') <> '' AND COALESCE(so.so_number, '') <> '' THEN 'Invoice / Sales Order'
                       WHEN COALESCE(i.invoice_number, '') <> '' THEN 'Invoice'
                       WHEN COALESCE(so.so_number, '') <> '' THEN 'Sales Order'
                       ELSE 'Payment'
                   END AS transaction_type_label,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            {$sales_order_join}
            LEFT JOIN users u ON p.created_by = u.user_id
            LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
            WHERE btp.payment_id IS NULL";
    if (!$view_all_branches && $branch_id > 0) {
        $branchParts = [];
        if (columnExists($conn, 'payments', 'branch_id')) $branchParts[] = 'p.branch_id = ?';
        if (columnExists($conn, 'invoices', 'branch_id')) $branchParts[] = 'i.branch_id = ?';
        if ($so_branch_column_exists && ($invoices_has_so_id || $payments_has_so_id)) $branchParts[] = 'so.branch_id = ?';
        if (columnExists($conn, 'customers', 'branch_id')) $branchParts[] = 'c.branch_id = ?';
        if (!empty($branchParts)) $sql .= ' AND (' . implode(' OR ', $branchParts) . ')';
    }
    $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) {
            $bindCount = 0;
            if (columnExists($conn, 'payments', 'branch_id')) $bindCount++;
            if (columnExists($conn, 'invoices', 'branch_id')) $bindCount++;
            if ($so_branch_column_exists && ($invoices_has_so_id || $payments_has_so_id)) $bindCount++;
            if (columnExists($conn, 'customers', 'branch_id')) $bindCount++;
            if ($bindCount > 0) {
                $types = str_repeat('i', $bindCount);
                $params = array_fill(0, $bindCount, $branch_id);
                $stmt->bind_param($types, ...$params);
            }
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}


function getSalesOrderItemsForPayments($conn, &$payments) {
    if (!is_array($payments) || empty($payments)) return;
    if (!tableExists($conn, 'sales_order_items')) return;

    $so_ids = [];
    foreach ($payments as $payment) {
        $payment_so_id = (int)($payment['payment_so_id'] ?? 0);
        $invoice_so_id = (int)($payment['invoice_so_id'] ?? 0);
        $so_id = $payment_so_id > 0 ? $payment_so_id : $invoice_so_id;
        if ($so_id > 0) $so_ids[] = $so_id;
    }
    $so_ids = array_values(array_unique($so_ids));
    if (empty($so_ids)) {
        foreach ($payments as &$payment) $payment['ordered_items'] = [];
        unset($payment);
        return;
    }

    $has_items_table = tableExists($conn, 'items');
    $item_name_select = $has_items_table ? "COALESCE(NULLIF(TRIM(items.item_name), ''), CONCAT('Item #', soi.item_id))" : "CONCAT('Item #', soi.item_id)";
    $item_code_select = $has_items_table ? "COALESCE(items.item_code, '')" : "''";
    $item_join = $has_items_table ? "LEFT JOIN items ON soi.item_id = items.item_id" : "";

    $placeholders = implode(',', array_fill(0, count($so_ids), '?'));
    $sql = "SELECT soi.so_id,
                   soi.item_id,
                   {$item_name_select} AS item_name,
                   {$item_code_select} AS item_code,
                   COALESCE(NULLIF(TRIM(soi.unit_type), ''), 'Piece') AS unit_type,
                   COALESCE(soi.quantity_ordered, 0) AS quantity_ordered,
                   COALESCE(NULLIF(soi.unit_price, 0), NULLIF(soi.net_price, 0), NULLIF(soi.gross_price, 0), 0) AS unit_price,
                   COALESCE(NULLIF(soi.order_amount, 0), (COALESCE(soi.quantity_ordered, 0) * COALESCE(NULLIF(soi.unit_price, 0), NULLIF(soi.net_price, 0), NULLIF(soi.gross_price, 0), 0))) AS line_total
            FROM sales_order_items soi
            {$item_join}
            WHERE soi.so_id IN ($placeholders)
            ORDER BY soi.so_id ASC, soi.so_item_id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        foreach ($payments as &$payment) $payment['ordered_items'] = [];
        unset($payment);
        return;
    }
    $types = str_repeat('i', count($so_ids));
    $stmt->bind_param($types, ...$so_ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $items_by_so = [];
    while ($row = $res->fetch_assoc()) {
        $so_id = (int)($row['so_id'] ?? 0);
        if (!isset($items_by_so[$so_id])) $items_by_so[$so_id] = [];
        $items_by_so[$so_id][] = [
            'item_id' => (int)($row['item_id'] ?? 0),
            'item_name' => $row['item_name'] ?? '',
            'item_code' => $row['item_code'] ?? '',
            'unit_type' => $row['unit_type'] ?? 'Piece',
            'quantity_ordered' => (float)($row['quantity_ordered'] ?? 0),
            'unit_price' => (float)($row['unit_price'] ?? 0),
            'line_total' => (float)($row['line_total'] ?? 0),
        ];
    }
    $stmt->close();

    foreach ($payments as &$payment) {
        $payment_so_id = (int)($payment['payment_so_id'] ?? 0);
        $invoice_so_id = (int)($payment['invoice_so_id'] ?? 0);
        $so_id = $payment_so_id > 0 ? $payment_so_id : $invoice_so_id;
        $payment['ordered_items'] = $items_by_so[$so_id] ?? [];
    }
    unset($payment);
}

// AMGC_DEPOSIT_JOURNAL_EDIT_PATCH_V16
function amgcDepositJournalRequested(): bool {
    return isset($_GET['from_journal_entries']) || isset($_GET['journal_edit']) || isset($_GET['open_deposit']) || isset($_GET['edit_deposit']);
}
function amgcDepositJournalTransactionId(mysqli $conn): int {
    foreach (['bank_transaction_id','deposit_id'] as $key) {
        $id = (int)($_GET[$key] ?? 0);
        if ($id > 0) return $id;
    }
    if (strtolower((string)($_GET['source_table'] ?? '')) === 'bank_transactions') {
        $id = (int)($_GET['source_id'] ?? 0);
        if ($id > 0) return $id;
    }
    $txn = trim((string)($_GET['transaction_no'] ?? ''));
    if (preg_match('/^DEP-0*([0-9]+)$/i', $txn, $m)) return (int)$m[1];
    $ref = trim((string)($_GET['reference_no'] ?? $_GET['ref_no'] ?? ''));
    if ($ref !== '' && tableExists($conn, 'bank_transactions')) {
        $stmt = $conn->prepare("SELECT transaction_id FROM bank_transactions WHERE transaction_type='deposit' AND reference_number=? ORDER BY transaction_id DESC LIMIT 1");
        if ($stmt) { $stmt->bind_param('s',$ref); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return (int)($row['transaction_id'] ?? 0); }
    }
    return 0;
}
function amgcDepositLoadEditData(mysqli $conn, int $transactionId, bool $viewAllBranches, int $branchId): array {
    $out = ['found'=>false,'transaction'=>[],'payment_ids'=>[],'credit_ids'=>[],'payments'=>[],'credits'=>[]];
    if ($transactionId <= 0 || !tableExists($conn,'bank_transactions')) return $out;
    $sql = "SELECT * FROM bank_transactions WHERE transaction_id=? AND transaction_type='deposit'";
    if (!$viewAllBranches && $branchId > 0 && columnExists($conn,'bank_transactions','branch_id')) $sql .= " AND (branch_id=? OR branch_id=0 OR branch_id IS NULL)";
    $sql .= " LIMIT 1";
    $stmt=$conn->prepare($sql); if(!$stmt) return $out;
    if (!$viewAllBranches && $branchId > 0 && columnExists($conn,'bank_transactions','branch_id')) $stmt->bind_param('ii',$transactionId,$branchId); else $stmt->bind_param('i',$transactionId);
    $stmt->execute(); $tx=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$tx) return $out;
    $out['found']=true; $out['transaction']=$tx;

    if (tableExists($conn,'bank_transaction_payments')) {
        $out['payment_applied_amounts'] = [];
        $stmt=$conn->prepare("SELECT payment_id, COALESCE(amount_applied,0) AS amount_applied FROM bank_transaction_payments WHERE transaction_id=? ORDER BY payment_id ASC");
        if($stmt){$stmt->bind_param('i',$transactionId);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc()){ $pid=(int)$r['payment_id']; $out['payment_ids'][]=$pid; $out['payment_applied_amounts'][$pid]=(float)$r['amount_applied']; }$stmt->close();}
    }
    if (!empty($out['payment_ids']) && tableExists($conn,'payments')) {
        $ids=$out['payment_ids']; $ph=implode(',',array_fill(0,count($ids),'?'));
        $desc = columnExists($conn,'payments','description') ? 'p.description' : "''";
        $hasPaySo = columnExists($conn,'payments','so_id');
        $hasInvSo = columnExists($conn,'invoices','so_id');
        $paySo = $hasPaySo ? 'p.so_id AS payment_so_id' : 'NULL AS payment_so_id';
        $invSo = $hasInvSo ? 'i.so_id AS invoice_so_id' : 'NULL AS invoice_so_id';
        $joinSo = $hasInvSo ? 'LEFT JOIN sales_orders so ON i.so_id=so.so_id' : ($hasPaySo ? 'LEFT JOIN sales_orders so ON p.so_id=so.so_id' : '');
        $sql="SELECT p.payment_id,p.invoice_id,$paySo,$invSo,p.customer_id,p.payment_method,p.amount,p.payment_date,p.reference_number,p.bank_name,p.bank_branch,p.check_date,p.check_number,p.si_number,$desc AS description,c.customer_name,i.invoice_number,COALESCE(so.so_number,'') AS so_number,
              CASE WHEN COALESCE(i.invoice_number,'')<>'' AND COALESCE(so.so_number,'')<>'' THEN CONCAT(i.invoice_number,' / ',so.so_number) WHEN COALESCE(i.invoice_number,'')<>'' THEN i.invoice_number WHEN COALESCE(so.so_number,'')<>'' THEN so.so_number ELSE CONCAT('Payment #',p.payment_id) END AS transaction_label,
              CASE WHEN COALESCE(i.invoice_number,'')<>'' AND COALESCE(so.so_number,'')<>'' THEN 'Invoice / Sales Order' WHEN COALESCE(i.invoice_number,'')<>'' THEN 'Invoice' WHEN COALESCE(so.so_number,'')<>'' THEN 'Sales Order' ELSE 'Payment' END AS transaction_type_label,
              CONCAT(COALESCE(u.first_name,''), CASE WHEN COALESCE(u.last_name,'')<>'' THEN CONCAT(' ',u.last_name) ELSE '' END) AS collected_by_name
              FROM payments p LEFT JOIN customers c ON p.customer_id=c.customer_id LEFT JOIN invoices i ON p.invoice_id=i.invoice_id $joinSo LEFT JOIN users u ON p.created_by=u.user_id WHERE p.payment_id IN ($ph)";
        $stmt=$conn->prepare($sql); if($stmt){$stmt->bind_param(str_repeat('i',count($ids)),...$ids);$stmt->execute();$out['payments']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach($out['payments'] as &$__p){ $__pid=(int)($__p['payment_id']??0); if(isset($out['payment_applied_amounts'][$__pid]) && $out['payment_applied_amounts'][$__pid] > 0){ $__p['amount']=(float)$out['payment_applied_amounts'][$__pid]; $__p['deposit_applied_amount']=(float)$out['payment_applied_amounts'][$__pid]; } } unset($__p);
        }
    }
    if (tableExists($conn,'bank_transaction_credit_memos')) {
        $out['credit_applied_amounts'] = [];
        $stmt=$conn->prepare("SELECT credit_memo_id, COALESCE(amount_applied,0) AS amount_applied FROM bank_transaction_credit_memos WHERE transaction_id=? ORDER BY credit_memo_id ASC");
        if($stmt){$stmt->bind_param('i',$transactionId);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc()){ $cid=(int)$r['credit_memo_id']; $out['credit_ids'][]=$cid; $out['credit_applied_amounts'][$cid]=(float)$r['amount_applied']; }$stmt->close();}
    }
    if (!empty($out['credit_ids']) && tableExists($conn,'credit_memos')) {
        $ids=$out['credit_ids']; $ph=implode(',',array_fill(0,count($ids),'?'));
        $sql="SELECT cm.*, c.customer_name, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS created_by_name FROM credit_memos cm LEFT JOIN customers c ON cm.customer_id=c.customer_id LEFT JOIN users u ON cm.created_by=u.user_id WHERE cm.credit_memo_id IN ($ph)";
        $stmt=$conn->prepare($sql); if($stmt){$stmt->bind_param(str_repeat('i',count($ids)),...$ids);$stmt->execute();$out['credits']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach($out['credits'] as &$__c){ $__cid=(int)($__c['credit_memo_id']??0); if(isset($out['credit_applied_amounts'][$__cid]) && $out['credit_applied_amounts'][$__cid] > 0){ $__c['amount']=(float)$out['credit_applied_amounts'][$__cid]; $__c['deposit_applied_amount']=(float)$out['credit_applied_amounts'][$__cid]; } } unset($__c);
        }
    }
    return $out;
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
    if (!$view_all_branches && $branch_id > 0) {
        $branchParts = [];
        if (columnExists($conn, 'payments', 'branch_id')) $branchParts[] = 'p.branch_id = ?';
        if (columnExists($conn, 'invoices', 'branch_id')) $branchParts[] = 'i.branch_id = ?';
        if ($so_branch_column_exists && $invoices_has_so_id) $branchParts[] = 'so.branch_id = ?';
        if (columnExists($conn, 'customers', 'branch_id')) $branchParts[] = 'c.branch_id = ?';
        if (!empty($branchParts)) $sql .= ' AND (' . implode(' OR ', $branchParts) . ')';
    }
    $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 50";
    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) {
            $bindCount = 0;
            if (columnExists($conn, 'payments', 'branch_id')) $bindCount++;
            if (columnExists($conn, 'invoices', 'branch_id')) $bindCount++;
            if ($so_branch_column_exists && $invoices_has_so_id) $bindCount++;
            if (columnExists($conn, 'customers', 'branch_id')) $bindCount++;
            if ($bindCount > 0) {
                $types = str_repeat('i', $bindCount);
                $params = array_fill(0, $bindCount, $branch_id);
                $stmt->bind_param($types, ...$params);
            }
        }
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
            $branchBindCount = 0;
            if (!$view_all_branches && $branch_id > 0) {
                $branchParts = [];
                if (columnExists($conn, 'payments', 'branch_id')) { $branchParts[] = 'p.branch_id = ?'; $branchBindCount++; }
                if (columnExists($conn, 'invoices', 'branch_id')) { $branchParts[] = 'i.branch_id = ?'; $branchBindCount++; }
                if ($so_branch_column_exists && $invoices_has_so_id) { $branchParts[] = 'so.branch_id = ?'; $branchBindCount++; }
                if (!empty($branchParts)) $sql .= ' AND (' . implode(' OR ', $branchParts) . ')';
            }
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($ids));
                $params = $ids;
                if ($branchBindCount > 0) { $types .= str_repeat('i', $branchBindCount); $params = array_merge($params, array_fill(0, $branchBindCount, $branch_id)); }
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


// AMGC_DEPOSIT_INLINE_AMOUNT_EDIT_V17 - edit deposit rows directly on this page.
// AMGC_DEPOSIT_ALWAYS_INLINE_EDIT_V18 - normal Deposit page rows are editable too, not only Journal Entries.
// AMGC_DEPOSIT_JOURNAL_EDIT_PATCH_V16 - save edited existing deposit.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_journal_deposit') {
    try {
        $transaction_id = (int)($_POST['deposit_transaction_id'] ?? 0);
        if ($transaction_id <= 0) throw new Exception('Deposit transaction was not found.');
        $bank_id = (int)($_POST['bank_id'] ?? 0);
        if ($bank_id <= 0) throw new Exception('Please select Deposit To.');
        $bank_name = getRegisteredBankName($conn, $bank_id, $view_all_branches, $branch_id);
        if ($bank_name === '') throw new Exception('Selected bank was not found.');
        $transaction_date = date('Y-m-d 00:00:00', strtotime($_POST['transaction_date'] ?? 'now'));
        $description = trim((string)($_POST['description'] ?? 'Deposit'));
        $reference_number = trim((string)($_POST['reference_number'] ?? ''));
        $payment_ids = array_values(array_unique(array_filter(array_map('intval',(array)($_POST['payment_ids'] ?? [])), fn($id)=>$id>0)));
        $credit_ids = array_values(array_unique(array_filter(array_map('intval',(array)($_POST['credit_memo_ids'] ?? [])), fn($id)=>$id>0)));
        if (empty($payment_ids) && empty($credit_ids)) throw new Exception('No selected deposit records were submitted.');

        // AMGC_DEPOSIT_INLINE_AMOUNT_EDIT_V17: use the amounts edited directly in this deposit table.
        $postedPaymentAmounts = (array)($_POST['journal_payment_amount'] ?? []);
        $postedCreditAmounts = (array)($_POST['journal_credit_amount'] ?? []);
        $paymentAppliedAmounts = [];
        $creditAppliedAmounts = [];
        $payTotal = 0.0;
        foreach ($payment_ids as $pid) {
            $applied = isset($postedPaymentAmounts[$pid]) ? (float)str_replace([',','₱',' '], '', (string)$postedPaymentAmounts[$pid]) : 0.0;
            if ($applied <= 0) {
                $st = $conn->prepare("SELECT amount FROM payments WHERE payment_id=? LIMIT 1");
                if ($st) { $st->bind_param('i', $pid); $st->execute(); $applied = (float)($st->get_result()->fetch_assoc()['amount'] ?? 0); $st->close(); }
            }
            if ($applied <= 0) throw new Exception('Payment amount must be greater than zero.');
            $paymentAppliedAmounts[$pid] = round($applied, 2);
            $payTotal += $paymentAppliedAmounts[$pid];
        }
        $credTotal = 0.0;
        foreach ($credit_ids as $cid) {
            $applied = isset($postedCreditAmounts[$cid]) ? (float)str_replace([',','₱',' '], '', (string)$postedCreditAmounts[$cid]) : 0.0;
            if ($applied <= 0) {
                $st = $conn->prepare("SELECT amount FROM credit_memos WHERE credit_memo_id=? LIMIT 1");
                if ($st) { $st->bind_param('i', $cid); $st->execute(); $applied = (float)($st->get_result()->fetch_assoc()['amount'] ?? 0); $st->close(); }
            }
            if ($applied <= 0) throw new Exception('Credit memo amount must be greater than zero.');
            $creditAppliedAmounts[$cid] = round($applied, 2);
            $credTotal += $creditAppliedAmounts[$cid];
        }
        $amount = round($payTotal - $credTotal, 2);
        if ($amount <= 0) throw new Exception('Net deposit amount must be greater than zero.');
        $conn->begin_transaction();
        $old=[]; $st=$conn->prepare("SELECT account_id,debit,credit FROM chart_account_transactions WHERE source_table='bank_transactions' AND source_id=? AND transaction_type='Record Deposits'");
        if($st){$st->bind_param('i',$transaction_id);$st->execute();$old=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();}
        foreach($old as $r){ updateDepositAccountBalance($conn,(int)$r['account_id'],(float)$r['credit'],(float)$r['debit']); }
        $st=$conn->prepare("DELETE FROM chart_account_transactions WHERE source_table='bank_transactions' AND source_id=? AND transaction_type='Record Deposits'"); if($st){$st->bind_param('i',$transaction_id);$st->execute();$st->close();}
        $bid=(int)$branch_id;
        $st=$conn->prepare("UPDATE bank_transactions SET branch_id=?, transaction_date=?, reference_number=?, bank_name=?, bank_id=?, description=?, amount=? WHERE transaction_id=? AND transaction_type='deposit'");
        if(!$st) throw new Exception('Unable to prepare update.');
        $st->bind_param('isssisdi',$bid,$transaction_date,$reference_number,$bank_name,$bank_id,$description,$amount,$transaction_id);
        if(!$st->execute()) throw new Exception($st->error ?: 'Unable to update deposit.'); $st->close();
        $st=$conn->prepare("DELETE FROM bank_transaction_payments WHERE transaction_id=?"); if($st){$st->bind_param('i',$transaction_id);$st->execute();$st->close();}
        if($payment_ids){$link=$conn->prepare("INSERT INTO bank_transaction_payments (transaction_id,payment_id,amount_applied) VALUES (?,?,?)");foreach($payment_ids as $pid){$ap=(float)($paymentAppliedAmounts[$pid] ?? 0);$link->bind_param('iid',$transaction_id,$pid,$ap);$link->execute();}$link->close();}
        $oldc=[];$st=$conn->prepare("SELECT credit_memo_id FROM bank_transaction_credit_memos WHERE transaction_id=?"); if($st){$st->bind_param('i',$transaction_id);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc())$oldc[]=(int)$r['credit_memo_id'];$st->close();}
        if($oldc){$ph=implode(',',array_fill(0,count($oldc),'?'));$st=$conn->prepare("UPDATE credit_memos SET status='unapplied' WHERE credit_memo_id IN ($ph)"); if($st){$st->bind_param(str_repeat('i',count($oldc)),...$oldc);$st->execute();$st->close();}}
        $st=$conn->prepare("DELETE FROM bank_transaction_credit_memos WHERE transaction_id=?"); if($st){$st->bind_param('i',$transaction_id);$st->execute();$st->close();}
        if($credit_ids){$link=$conn->prepare("INSERT INTO bank_transaction_credit_memos (transaction_id,credit_memo_id,amount_applied) VALUES (?,?,?)");foreach($credit_ids as $cid){$ap=(float)($creditAppliedAmounts[$cid] ?? 0);$link->bind_param('iid',$transaction_id,$cid,$ap);$link->execute();$u=$conn->prepare("UPDATE credit_memos SET status='applied' WHERE credit_memo_id=?"); if($u){$u->bind_param('i',$cid);$u->execute();$u->close();}}$link->close();}
        postDepositToChartOfAccounts($conn,$bank_id,$bid,$transaction_date,$reference_number,$description,$amount,$transaction_id,$user_id);
        syncUndepositedFundsBalanceFromSource($conn,$view_all_branches,$branch_id,$user_id,$so_branch_column_exists,$invoices_has_so_id);
        $conn->commit(); $_SESSION['success_message']='Deposit transaction updated successfully.';
    } catch(Throwable $e) { try{$conn->rollback();}catch(Throwable $ignore){} $_SESSION['error_message']='Deposit update failed: '.$e->getMessage(); }
    header('Location: deposit.php'); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_deposit') {
    try {
        $payment_ids_raw = $_POST['payment_ids'] ?? [];
        $credit_memo_ids_raw = $_POST['credit_memo_ids'] ?? [];
        $valid = getValidDepositItems($conn, $payment_ids_raw, $credit_memo_ids_raw, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
        $payment_ids = $valid['payments'];
        $credit_memo_ids = $valid['credits'];
        // AMGC_DEPOSIT_ALWAYS_INLINE_EDIT_V18: accept edited amounts from the main deposit table for normal Save too.
        $postedPaymentAmounts = (array)($_POST['journal_payment_amount'] ?? []);
        $postedCreditAmounts = (array)($_POST['journal_credit_amount'] ?? []);
        $paymentAppliedAmounts = [];
        $creditAppliedAmounts = [];
        
        if (empty($payment_ids) && empty($credit_memo_ids)) throw new Exception('Please select at least one undeposited payment or credit memo.');
        
        $transaction_date = date('Y-m-d 00:00:00', strtotime($_POST['transaction_date'] ?? 'now'));
        $reference_number = trim($_POST['reference_number'] ?? '');
        $bank_id = (int)($_POST['bank_id'] ?? 0);
        $bank_name = getRegisteredBankName($conn, $bank_id, $view_all_branches, $branch_id);
        if ($bank_name === '') throw new Exception('Please select a Bank account from Chart of Accounts.');
        if (depositAccountHasSubAccounts($conn, $bank_id, $view_all_branches, $branch_id)) {
            throw new Exception('Please select a Bank account without sub accounts. Parent accounts cannot be used for deposit.');
        }
        $description = trim($_POST['description'] ?? 'Collections deposit');
        
        // Calculate total amount: sum(payments) - sum(credit_memos)
        $total_payments = 0;
        foreach ($payment_ids as $pid) {
            $applied = isset($postedPaymentAmounts[$pid]) ? (float)str_replace([',','₱',' '], '', (string)$postedPaymentAmounts[$pid]) : 0.0;
            if ($applied <= 0) {
                $st = $conn->prepare("SELECT amount FROM payments WHERE payment_id = ? LIMIT 1");
                if ($st) { $st->bind_param('i', $pid); $st->execute(); $applied = (float)($st->get_result()->fetch_assoc()['amount'] ?? 0); $st->close(); }
            }
            if ($applied <= 0) throw new Exception('Payment amount must be greater than zero.');
            $paymentAppliedAmounts[$pid] = round($applied, 2);
            $total_payments += $paymentAppliedAmounts[$pid];
        }
        
        $total_credits = 0;
        foreach ($credit_memo_ids as $cid) {
            $applied = isset($postedCreditAmounts[$cid]) ? (float)str_replace([',','₱',' '], '', (string)$postedCreditAmounts[$cid]) : 0.0;
            if ($applied <= 0) {
                $st = $conn->prepare("SELECT amount FROM credit_memos WHERE credit_memo_id = ? LIMIT 1");
                if ($st) { $st->bind_param('i', $cid); $st->execute(); $applied = (float)($st->get_result()->fetch_assoc()['amount'] ?? 0); $st->close(); }
            }
            if ($applied <= 0) throw new Exception('Credit memo amount must be greater than zero.');
            $creditAppliedAmounts[$cid] = round($applied, 2);
            $total_credits += $creditAppliedAmounts[$cid];
        }
        
        $deposit_amount = $total_payments - $total_credits;
        if ($deposit_amount <= 0) throw new Exception('Net deposit amount must be greater than zero.');
        
        $conn->begin_transaction();
        $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;
        // Keep Undeposited Funds equal to actual payments that are not yet deposited before posting this deposit.
        syncUndepositedFundsBalanceFromSource($conn, $view_all_branches, $branch_id, $user_id, $so_branch_column_exists, $invoices_has_so_id);
        $insert = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, bank_id, description, amount, created_by) VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param('isssisdi', $effective_branch_id, $transaction_date, $reference_number, $bank_name, $bank_id, $description, $deposit_amount, $user_id);
        if (!$insert->execute()) throw new Exception('Failed to save deposit transaction: ' . $insert->error);
        $transaction_id = (int)$conn->insert_id;
        $insert->close();

        postDepositToChartOfAccounts($conn, $bank_id, $effective_branch_id, $transaction_date, $reference_number, $description, $deposit_amount, $transaction_id, $user_id);
        
        // Link payments
        if (!empty($payment_ids)) {
            $link_stmt = $conn->prepare("INSERT INTO bank_transaction_payments (transaction_id, payment_id, amount_applied) VALUES (?, ?, ?)");
            foreach ($payment_ids as $pid) {
                $applied = (float)($paymentAppliedAmounts[$pid] ?? 0);
                $link_stmt->bind_param('iid', $transaction_id, $pid, $applied);
                if (!$link_stmt->execute()) throw new Exception('Failed to link payment: ' . $link_stmt->error);
            }
            $link_stmt->close();
        }
        
        // Link credit memos
        if (!empty($credit_memo_ids)) {
            $link_credit_stmt = $conn->prepare("INSERT INTO bank_transaction_credit_memos (transaction_id, credit_memo_id, amount_applied) VALUES (?, ?, ?)");
            foreach ($credit_memo_ids as $cid) {
                $applied = (float)($creditAppliedAmounts[$cid] ?? 0);
                $link_credit_stmt->bind_param('iid', $transaction_id, $cid, $applied);
                if (!$link_credit_stmt->execute()) throw new Exception('Failed to link credit memo: ' . $link_credit_stmt->error);
                // Update credit memo status to applied
                $upd = $conn->prepare("UPDATE credit_memos SET status = 'applied' WHERE credit_memo_id = ?");
                $upd->bind_param('i', $cid);
                $upd->execute();
                $upd->close();
            }
            $link_credit_stmt->close();
        }
        
        // Re-sync after marking the selected payments as deposited, so the COA Undeposited Funds balance
        // is always the exact total of remaining un-deposited payments.
        syncUndepositedFundsBalanceFromSource($conn, $view_all_branches, $branch_id, $user_id, $so_branch_column_exists, $invoices_has_so_id);

        $conn->commit();
        $_SESSION['success_message'] = 'Deposit transaction saved successfully.';
    } catch (Exception $e) {
        if (isset($conn) && $conn) @$conn->rollback();
        $_SESSION['error_message'] = $e->getMessage();
    }
    header('Location: deposit.php'); exit();
}

$available_payments = getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
getSalesOrderItemsForPayments($conn, $available_payments);
$available_credits = getAvailableCreditMemos($conn, $view_all_branches, $branch_id);
enrichCreditMemosWithAttachments($conn, $available_credits);

$journal_deposit_transaction_id = amgcDepositJournalTransactionId($conn);
$journal_deposit_edit_mode = amgcDepositJournalRequested() && $journal_deposit_transaction_id > 0;
$journal_deposit_edit_data = $journal_deposit_edit_mode ? amgcDepositLoadEditData($conn, $journal_deposit_transaction_id, (bool)$view_all_branches, (int)$branch_id) : ['found'=>false,'transaction'=>[],'payment_ids'=>[],'credit_ids'=>[],'payments'=>[],'credits'=>[]];
$journal_deposit_edit_mode = !empty($journal_deposit_edit_data['found']);
$journal_deposit_selected_payment_ids = array_map('intval', $journal_deposit_edit_data['payment_ids'] ?? []);
$journal_deposit_selected_credit_ids = array_map('intval', $journal_deposit_edit_data['credit_ids'] ?? []);
if ($journal_deposit_edit_mode) {
    $existingIds = array_map(fn($p)=>(int)($p['payment_id']??0), $available_payments);
    foreach (($journal_deposit_edit_data['payments'] ?? []) as $p) { if (!in_array((int)($p['payment_id']??0), $existingIds, true)) { $available_payments[]=$p; $existingIds[]=(int)($p['payment_id']??0); } }
    $existingC = array_map(fn($c)=>(int)($c['credit_memo_id']??0), $available_credits);
    foreach (($journal_deposit_edit_data['credits'] ?? []) as $c) { if (!in_array((int)($c['credit_memo_id']??0), $existingC, true)) { $available_credits[]=$c; $existingC[]=(int)($c['credit_memo_id']??0); } }
}
$recent_payments = getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$bank_transactions = getBankTransactions($conn, $view_all_branches, $branch_id);
$banks = getBanks($conn, $view_all_branches, $branch_id);

// Get active customers for credit memo dropdown (current branch only, no duplicate names)
$customers = getCreditMemoCustomers($conn, $view_all_branches, $branch_id);

$undeposited_payments_total = getUndepositedPaymentsSourceTotal($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
syncUndepositedFundsBalanceFromSource($conn, $view_all_branches, $branch_id, $user_id, $so_branch_column_exists, $invoices_has_so_id);
$undeposited_credits_total = array_sum(array_column($available_credits, 'amount'));
$undeposited_net = $undeposited_payments_total - $undeposited_credits_total;
$total_collections = array_sum(array_column($recent_payments, 'amount'));
$total_deposits = array_sum(array_column($bank_transactions, 'amount'));
$bank_balance = getChartBankBalanceTotal($conn, $view_all_branches, $branch_id);

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
<?php
$journal_deposit_tx = is_array($journal_deposit_edit_data['transaction'] ?? null) ? $journal_deposit_edit_data['transaction'] : [];
$journal_deposit_form_action = $journal_deposit_edit_mode ? 'update_journal_deposit' : 'create_deposit';
$journal_deposit_form_date = $journal_deposit_edit_mode && !empty($journal_deposit_tx['transaction_date']) ? date('Y-m-d', strtotime($journal_deposit_tx['transaction_date'])) : date('Y-m-d');
$journal_deposit_form_description = $journal_deposit_edit_mode ? (string)($journal_deposit_tx['description'] ?? 'Deposit') : 'Deposit';
$journal_deposit_form_reference = $journal_deposit_edit_mode ? (string)($journal_deposit_tx['reference_number'] ?? '') : '';
$journal_deposit_form_bank_id = $journal_deposit_edit_mode ? (int)($journal_deposit_tx['bank_id'] ?? 0) : 0;
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
    display:flex;
    justify-content:space-between;
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
.table thead th{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
    color:#fff!important;
    border:none;
    white-space:nowrap;
    font-size:.84rem;
}
.table-nowrap th, .table-nowrap td { 
    white-space: nowrap !important;
    vertical-align: middle;
}
.table tbody td{
    vertical-align:middle;
    font-size:.92rem;
}
.table-responsive{
    overflow-x:auto;
    -webkit-overflow-scrolling:touch
}
#undepositedTable, #creditMemoTable{
    min-width:980px;
    width:100%;
}
#undepositedTable th,#undepositedTable td, #creditMemoTable th,#creditMemoTable td{
    white-space:nowrap;
    word-break:normal;
    padding:0.6rem 0.5rem;
}
.clickable-row{
    cursor:pointer;
    transition:background 0.2s;
}
.clickable-row:hover{
    background-color:#f0fdf4!important;
}
.deposit-row-clickable{
    cursor:pointer;
}
.deposit-row-clickable:hover{
    background-color:#f0fdf4!important;
}
.amount-positive{
    color:#047857;
    font-weight:700;
}
.amount-negative{
    color:#dc3545;
    font-weight:700;
}
.filter-bar{
    background:#f8fafc;
    border-radius:16px;
    padding:0.8rem 1rem;
    margin-bottom:1rem;
    border:1px solid #e2e8f0;
}
.filter-bar .form-select,.filter-bar .form-control{
    min-height:38px;
    font-size:0.85rem;
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
    background:#052A47;
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
.deposit-form-wrapper{
    transition:all 0.3s ease;
}
.deposit-form-wrapper.collapsed{
    display:none;
}
.toggle-form-btn{
    background:#f0fdf4;
    border:1px solid #44D34E;
    color:#047857;
    border-radius:40px;
    padding:0.3rem 1rem;
    font-size:0.85rem;
    font-weight:600;
}
.undeposited-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:0.75rem;
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

/* Invoice-style Payment Details modal */
#paymentDetailsModal .payment-details-invoice-dialog{
    max-width:1220px!important;
    width:96vw!important;
    z-index:20051!important;
}
#paymentDetailsModal .modal-content.payment-invoice-modal{
    border:1px solid #d7dbe0!important;
    border-radius:0!important;
    overflow:hidden!important;
    background:#fff!important;
    box-shadow:0 24px 65px rgba(5,42,71,.25)!important;
}
#paymentDetailsModal .modal-header.payment-invoice-modal-header{
    padding:0!important;
    border:0!important;
    background:#047857!important;
    min-height:38px!important;
}
.payment-invoice-topbar{
    width:100%;
    display:grid;
    grid-template-columns:minmax(360px,1fr) minmax(420px,1fr) auto;
    gap:20px;
    align-items:center;
    background:#047857;
    padding:5px 8px;
}
.payment-invoice-topbar-field{
    display:grid;
    grid-template-columns:auto 1fr;
    align-items:center;
    gap:8px;
    min-width:0;
}
.payment-invoice-topbar-field label{
    margin:0;
    color:#fff;
    font-size:12px;
    font-weight:800;
    text-transform:uppercase;
    white-space:nowrap;
}
.payment-invoice-topbar-value{
    height:27px;
    border:1px solid #d1d5db;
    background:#fff;
    color:#111827;
    display:flex;
    align-items:center;
    padding:3px 8px;
    font-size:13px;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}
.payment-invoice-topbar .btn-close{filter:brightness(0) invert(1);opacity:.9;padding:0!important;margin:0!important;}
#paymentDetailsModal .modal-body.payment-invoice-modal-body{padding:26px 26px 18px!important;background:#fff!important;max-height:calc(100vh - 80px);overflow:auto;}
.payment-invoice-view{font-family:Arial,Helvetica,sans-serif;color:#052A47;min-width:1060px;background:#fff;}
.payment-invoice-head{display:grid;grid-template-columns:minmax(320px,1fr) minmax(560px,1.15fr);gap:34px;align-items:start;margin-bottom:24px;}
.payment-invoice-title{font-size:42px;line-height:1;font-weight:400;color:#052A47;margin:6px 0 0;}
.payment-invoice-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:14px 16px;}
.payment-invoice-extra{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 16px;margin-top:26px;}
.payment-invoice-field label{display:block;font-size:11px;color:#64748b;font-weight:800;margin-bottom:5px;text-transform:uppercase;}
.payment-invoice-control{min-height:28px;border:1px solid #d1d5db;background:#f3f4f6;border-radius:4px;padding:5px 8px;color:#111827;font-size:13px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
.payment-invoice-table-wrap{border:1px solid #d7dbe0;background:#fff;overflow:hidden;}
.payment-invoice-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:13px;}
.payment-invoice-table thead th{background:#fff!important;color:#8a8f98!important;border-right:1px solid #d7dbe0!important;border-bottom:1px solid #d7dbe0!important;height:28px;padding:5px 6px!important;font-size:12px;font-weight:700;text-transform:uppercase;}
.payment-invoice-table tbody tr:nth-child(odd){background:#e7f4e7!important;}
.payment-invoice-table tbody tr:nth-child(even){background:#fff!important;}
.payment-invoice-table tbody td{height:27px;padding:4px 6px;border-right:1px solid #e3e7eb;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.payment-invoice-table .text-end{text-align:right;}
.payment-invoice-bottom{display:grid;grid-template-columns:minmax(470px,1fr) 300px;gap:34px;margin-top:20px;align-items:start;}
.payment-invoice-message-label{font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;margin-bottom:5px;}
.payment-invoice-message-box{height:44px;border:1px solid #d1d5db;background:#f3f4f6;border-radius:4px;padding:8px;color:#111827;font-size:13px;}
.payment-invoice-note{margin-top:18px;color:#64748b;font-size:12px;}
.payment-invoice-toggle-note{margin-top:18px;display:flex;align-items:center;gap:10px;border:1px solid #e3e7eb;background:#f8fafc;border-radius:10px;padding:14px;color:#64748b;font-size:12px;width:520px;max-width:100%;}
.payment-invoice-toggle{width:32px;height:16px;border-radius:20px;border:1px solid #d1d5db;background:#fff;position:relative;flex:0 0 auto;}
.payment-invoice-toggle:before{content:'';width:10px;height:10px;border-radius:50%;background:#b8bec5;position:absolute;left:3px;top:2px;}
.payment-invoice-totals{color:#111827;font-size:13px;}
.payment-invoice-total-row,.payment-invoice-balance-row{display:grid;grid-template-columns:1fr 115px;gap:12px;align-items:center;margin-bottom:8px;}
.payment-invoice-total-row .label,.payment-invoice-balance-row .label{text-align:right;color:#052A47;font-weight:700;text-transform:uppercase;}
.payment-invoice-total-row .value,.payment-invoice-balance-row .value{text-align:right;color:#111827;}
.payment-invoice-balance-row .label,.payment-invoice-balance-row .value{font-size:18px;font-weight:800;}
.payment-invoice-actions{display:flex;justify-content:flex-end;gap:12px;margin-top:16px;}
.payment-invoice-btn{min-width:104px;border:1px solid #d7dbe0;background:#f8fafc;color:#111827;border-radius:6px;padding:8px 14px;font-size:13px;font-weight:700;}
.payment-invoice-btn.primary{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);color:#fff;border-color:#047857;}
@media(max-width:1100px){.payment-invoice-view{min-width:900px;}.payment-invoice-topbar{grid-template-columns:1fr;gap:6px;}}

@media(max-width:768px){
    .section-header{
        display:block;
    }
    .stat-value{
        font-size:1.2rem;
    }
    .filter-bar .row > div{
        margin-bottom:0.5rem;
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

/* ===== QUICKBOOKS-LIKE DEPOSIT UI ===== */
.qb-deposit-shell{
    background:#f3f4f6;
    border:1px solid #cfd3d8;
    box-shadow:0 8px 20px rgba(15,23,42,.05);
    font-family:Arial,Helvetica,sans-serif;
    color:#111827;
    margin-bottom:1rem;
}
.qb-tabs{
    display:flex;
    gap:4px;
    padding:8px 8px 0;
    background:#eef2f7;
    border-bottom:1px solid #c9ced6;
}
.qb-tabs .nav-link{
    border:1px solid #c9ced6;
    border-bottom:none;
    border-radius:4px 4px 0 0;
    background:#e6e9ee;
    color:#052A47;
    font-size:13px;
    font-weight:600;
    padding:7px 14px;
}
.qb-tabs .nav-link.active{
    background:#fff;
    color:#047857;
}
.qb-pane{
    background:#fff;
    padding:0;
}
.qb-form{
    background:#f7f7f7;
    padding:8px 6px 10px;
}
.qb-topbar{
    display:flex;
    align-items:center;
    gap:18px;
    flex-wrap:wrap;
    margin-bottom:12px;
}
.qb-field{
    display:flex;
    align-items:
    center;gap:6px;
}
.qb-label{
    font-size:14px;
    color:#111827;
    white-space:nowrap;
    margin:0;
}
.qb-control{
    height:26px;
    border:1px solid #b9bec5;
    background:linear-gradient(#eeeeee,#dadde1);
    font-size:14px;
    border-radius:0;
    padding:2px 6px;
    color:#111827;
}
.qb-select{
    min-width:160px;
    background:linear-gradient(#eeeeee,#dadde1);
    color:#111827;
    border-color:#b9bec5;
    font-weight:400;
}
.qb-date{
    width:116px;
}
.qb-memo{
    width:170px;
}
.qb-instruction{
    font-size:14px;
    margin:6px 0 26px;
    color:#111827;
}
.qb-table-wrap{
    border:1px solid #cfd3d8;
    background:repeating-linear-gradient(to bottom,#fff 0,#fff 20px,#e3f2e1 20px,#e3f2e1 40px);
    height:370px;
    overflow:auto;
}
.qb-table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
    font-size:13px;
}
.qb-table thead th{
    background:#fff!important;
    color:#8a8f98!important;
    border-right:1px solid #d7dbe0!important;
    border-bottom:1px solid #d7dbe0!important;
    font-weight:600;
    text-transform:uppercase;
    font-size:12px;
    height:22px;
    padding:2px 4px;
    white-space:nowrap;
}
.qb-table tbody tr:nth-child(odd){
    background:#fff;
}
.qb-table tbody tr:nth-child(even){
    background:#e3f2e1;
}
.qb-table tbody tr.qb-selected{
    outline:none!important;
    box-shadow:none!important;
    background:#d1fae5!important;
}
.qb-table tbody tr{
    height:20px!important;
    max-height:20px!important;
}
.qb-table tbody td{
    border-right:1px solid #d7dbe0;
    border-bottom:0;
    height:20px!important;
    max-height:20px!important;
    line-height:16px!important;
    padding:2px 5px!important;
    font-size:13px;vertical-align:middle;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    color:#111827;
    box-sizing:border-box;
}
.qb-table tbody tr.main-payment-row td,.qb-table tbody tr.qb-blank-row td{
    height:20px!important;
    max-height:20px!important;
    line-height:16px!important;
    padding-top:2px!important;
    padding-bottom:2px!important;
}
.qb-table tbody tr.main-payment-row input.qb-hidden-check{
    position:absolute!important;
    opacity:0!important;
    pointer-events:none!important;
    width:1px!important;
    height:1px!important;
    min-height:1px!important;
    margin:0!important;
    padding:0!important;
    border:0!important;
}
.qb-table .qb-amount{
    text-align:right;
    font-weight:600;
    color:#111827;
}
.qb-table tbody tr.qb-blank-row:nth-child(odd){
    background:#fff!important;
}
.qb-table tbody tr.qb-blank-row:nth-child(even){
    background:#e3f2e1!important;
}
.qb-table tbody tr.qb-blank-row td{
    color:transparent!important;
    user-select:none;
    border-bottom:0!important;
}
/* Undeposited Payments table light green rows */
#undepositedTable tbody tr{
    height:20px!important;
    max-height:20px!important;
}
#undepositedTable tbody tr:nth-child(odd),
#undepositedTable tbody tr.qb-blank-row:nth-child(odd){
    background:#ffffff!important;
}
#undepositedTable tbody tr:nth-child(even),
#undepositedTable tbody tr.qb-blank-row:nth-child(even){
    background:#e3f2e1!important;
}
#undepositedTable tbody td{
    height:20px!important;
    max-height:20px!important;
    line-height:16px!important;
    padding:2px 5px!important;
    border-right:1px solid #d7dbe0!important;
    border-bottom:0!important;
    vertical-align:middle!important;
}

#undepositedTable th:nth-child(1), 
#undepositedTable td:nth-child(1){
    min-width:190px!important;
    width:190px!important;
}
#undepositedTable th:nth-child(2), 
#undepositedTable td:nth-child(2){
    min-width:135px!important;
    width:135px!important;
}
#undepositedTable th:nth-child(3), 
#undepositedTable td:nth-child(3){
    min-width:190px!important;
    width:190px!important;
}
#undepositedTable th:nth-child(4), 
#undepositedTable td:nth-child(4){
    min-width:95px!important;
    width:95px!important;
    text-align:left!important;
}
#undepositedTable th:nth-child(5), 
#undepositedTable td:nth-child(5){
    min-width:120px!important;
    width:120px!important;
    text-align:left!important;
}
#undepositedTable th:nth-child(6), 
#undepositedTable td:nth-child(6){
    min-width:220px!important;
    width:220px!important;
    text-align:left!important;
}
#undepositedTable th:nth-child(7), 
#undepositedTable td:nth-child(7){
    min-width:120px!important;
    width:120px!important;
    text-align:right!important;
}
#undepositedTable th:nth-child(4), 
#undepositedTable th:nth-child(5), 
#undepositedTable th:nth-child(6){
    padding-left:10px!important;
    padding-right:10px!important;
}
#undepositedTable td:nth-child(4), 
#undepositedTable td:nth-child(5), 
#undepositedTable td:nth-child(6){
    padding-left:10px!important;
    padding-right:10px!important;
}

#undepositedTableWrap{
    max-height:326px;
    height:auto!important;
    overflow-x:auto;
    overflow-y:hidden;
}
#undepositedTableWrap.has-scroll{
    overflow-y:auto;
}

.qb-hidden-check{
    position:absolute;
    opacity:0;
    pointer-events:none;
    width:1px;
    height:1px;
}
.qb-subtotal-row{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:28px;
    padding:8px 2px 20px;
    font-size:14px;
}
.qb-subtotal-label{
    min-width:130px;
    text-align:left;
}.qb-subtotal-amount{
    min-width:140px;
    text-align:right;
    font-weight:700;
}
.qb-cashback{
    display:flex;
    justify-content:space-between;
    gap:24px;
    align-items:flex-start;
    padding:0 0 12px;
}
.qb-cashback-text{
    font-size:14px;
    line-height:1.25;
    max-width:600px;
    margin-bottom:8px;
}
.qb-cashback-fields{
    display:flex;
    gap:24px;
    flex-wrap:wrap;
}
.qb-cashback-field label{
    display:block;
    font-size:14px;
    margin-bottom:4px;
}
.qb-cashback-field select,.qb-cashback-field input{
    height:25px;
    border:1px solid #cfd3d8;
    background:linear-gradient(#eeeeee,#dadde1);
    border-radius:2px;
}
.qb-cashback-field select{
    width:185px;
}
.qb-cashback-field input{
    width:250px;
}
.qb-cashback-field .cash-amount{
    width:160px;
}
.qb-total-actions{
    min-width:360px;
    text-align:right;
    padding-top:46px;
}
.qb-total-actions-clean{
    min-width:0;
    padding:0 2px 12px;
    display:flex;
    flex-direction:column;
    align-items:flex-end;
}
.qb-total-label{
    font-size:15px;
    margin-bottom:14px;
}
.qb-total-amount{
    font-weight:700;
    margin-left:24px;
}
.qb-buttons{
    display:flex;
    justify-content:flex-end;
    gap:8px;
}
.qb-btn{
    border:1px solid #d7dbe0;
    background:#f3f4f6;
    color:#555;
    font-weight:700;
    font-size:13px;
    padding:7px 18px;
    border-radius:2px;
}
.qb-btn-primary{
    background:linear-gradient(135deg, #047857 0%, #44D34E 100%);
    color:#fff;
    border-color:#047857;
}
.qb-filter-line{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin:0 0 8px;
}
.qb-filter-line .form-select,.qb-filter-line .form-control{
    height:27px;
    border-radius:0;
    font-size:12px;
    padding:2px 6px;
}
.qb-payment-modal .modal-dialog{
    max-width:1280px;
}
.qb-payment-modal .modal-content{
    border-radius:0;
    border:1px solid #9aa3ad;
    font-family:Arial,Helvetica,sans-serif;
}
.qb-payment-modal .modal-header{
    height:18px;
    background:linear-gradient( #2dab31, #16791f);
    color:#fff;
    padding:0 8px;
    border-radius:0;
    border-bottom:1px solid #94a3af;
}
.qb-payment-modal .modal-title{
    font-size:14px;
    width:100%;
    text-align:center;
    font-weight:600;
    line-height:18px;
}
.qb-payment-modal .btn-close{
    filter:invert(1);
    opacity:.7;
    padding:0;margin:0;
    font-size:10px;
}
.qb-payment-modal .modal-body{
    background:#fff;
    padding:10px 14px 0;
}
.qb-payment-modal .modal-footer{
    background:#fff;
    border-top:0;
    padding:8px 14px 12px;
}
.qb-select-view{
    border:1px solid #edf0f2;
    background:#fbfbfb;
    padding:8px 10px 10px;
    margin-bottom:8px;
}
.qb-select-view-title,.qb-select-payments-title{
    font-weight:700;
    font-size:13px;
    color:#111827;
    text-transform:uppercase;
}
.qb-modal-field{
    display:flex;
    align-items:center;
    gap:10px;
    margin:5px 0;
}
.qb-modal-field label{
    width:180px;
    font-size:14px;
}
.qb-modal-field select{
    width:200px;
    height:26px;
    border:1px solid #b9bec5;
    background:linear-gradient(#eeeeee,#dadde1);
    border-radius:0;
    padding:2px 5px;
}
.qb-modal-field select.qb-modal-green,
.qb-modal-field select.qb-modal-green:focus,
.qb-modal-field select.qb-modal-green:active{
    background:linear-gradient(#eeeeee,#dadde1)!important;
    color:#111827!important;
    border:1px solid #b9bec5!important;
    font-weight:400!important;
    box-shadow:none!important;
    outline:none!important;
}
.qb-modal-field select
.qb-modal-green option{
    background:#fff!important;
    color:#111827!important;
}
.qb-modal-help{
    color:#3155b7;
    font-size:14px;
    margin-left:0;
}
.qb-modal-table-wrap{
    height:405px;
    border:1px solid #cfd3d8;
    overflow:auto;
    margin-top:4px;
}
.qb-modal-table{
    width:100%;
    min-width:1120px;
    border-collapse:collapse;
    table-layout:fixed;
    font-size:14px;
}
.qb-modal-table thead th{
    background:#fff!important;
    color:#8a8f98!important;
    border-right:1px solid #d7dbe0!important;
    border-bottom:1px solid #cfd3d8!important;
    font-size:12px;
    font-weight:600;
    text-transform:uppercase;
    height:22px;
    padding:2px 4px;
}
.qb-modal-table tbody tr:nth-child(odd){
    background:#fff;
}
.qb-modal-table tbody tr:nth-child(even){
    background:#e3f2e1 !important;
}
.qb-modal-table tbody td{
    height:20px;
    padding:2px 4px;
    border-right:1px solid #d7dbe0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.qb-modal-amount{
    text-align:right;
    font-weight:600;
}
.qb-modal-summary{
    display:flex;
    justify-content:space-between;
    background:#f2f2f2;
    border:1px solid #e5e7eb;
    border-top:0;
    padding:5px 8px;
    font-size:14px;
    font-weight:700;
}
.qb-modal-bottom-buttons{
    display:flex;
    justify-content:space-between;
    width:100%;
    align-items:center;
}
.qb-modal-left-buttons{
    display:flex;
    gap:8px;
}
.qb-modal-right-buttons{
    display:flex;
    gap:8px;
}
.qb-modal-btn{
    border:1px solid #d7dbe0;
    background:#f3f4f6;
    color:#555;
    font-weight:700;
    font-size:13px;
    padding:7px 20px;
    border-radius:2px;
    min-width:100px;
}
.qb-modal-btn-primary{
    background:linear-gradient( #6de86f, #35d032);
    color:#fff;
    border-color: none;
}
.qb-modal-btn:disabled{
    opacity:.45;
}
#paymentDetailsModal{z-index:20050!important;}
#paymentDetailsModal .modal-dialog{z-index:20051!important;}
.modal-backdrop.payment-details-backdrop{z-index:20040!important;}
body.modal-open .modal.qb-payment-modal{overflow:auto;}
#paymentsToDepositTable tbody tr[data-payment-id]{cursor:pointer;}
#paymentsToDepositTable .modal-payment-check{cursor:pointer;}

@media(max-width:768px){
    .qb-table-wrap{
        height:360px;
    }
    .qb-cashback{
        display:block;
    }
    .qb-total-actions{
        min-width:0;
        padding-top:12px;
    }
    .qb-total-actions-clean{
        align-items:flex-start;
        padding-top:0;
    }
    .qb-buttons{
        justify-content:flex-start;
    }
    .qb-topbar{
        gap:8px;
    }
    .qb-memo{
        width:150px;
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
                                <a class="nav-link active" href="deposit.php">
                                    <i class="bi bi-bank"></i>
                                    <span class="nav-text">Record Deposit</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="Withdrawal.php">
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
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Undeposited Payments</div><div class="stat-value">₱<?php echo number_format($undeposited_payments_total, 2); ?></div><div class="page-note"><?php echo count($available_payments); ?> payment(s)</div></div><div class="stat-icon"><i class="bi bi-wallet2"></i></div></div></div>-->
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Undeposited Credits</div><div class="stat-value">-₱<?php echo number_format($undeposited_credits_total, 2); ?></div><div class="page-note"><?php echo count($available_credits); ?> credit memo(s)</div></div><div class="stat-icon"><i class="bi bi-pencil-square"></i></div></div></div>-->
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Net Undeposited</div><div class="stat-value">₱<?php echo number_format($undeposited_net, 2); ?></div><div class="page-note">Payments - Credits</div></div><div class="stat-icon"><i class="bi bi-calculator"></i></div></div></div>-->
<!--<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Bank Balance</div><div class="stat-value">₱<?php echo number_format($bank_balance, 2); ?></div><div class="page-note">From Chart of Accounts</div></div><div class="stat-icon"><i class="bi bi-bank"></i></div></div></div>-->
<!--</div>-->

<!-- QuickBooks-style Deposit Workspace -->
<div class="qb-deposit-shell">
    <ul class="nav nav-tabs qb-tabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#undepositedTab" type="button">Undeposited Payments</button></li>
        <!--<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#creditTab" type="button">Credit Memos</button></li>-->
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#historyTab" type="button">Deposit History</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active qb-pane" id="undepositedTab">
            <form method="POST" action="deposit.php" id="depositForm" class="qb-form">
                <input type="hidden" name="action" value="<?php echo h($journal_deposit_form_action); ?>">
                <?php if ($journal_deposit_edit_mode): ?>
                    <input type="hidden" name="deposit_transaction_id" value="<?php echo (int)$journal_deposit_transaction_id; ?>">
                    <div class="alert alert-success py-2 mb-2"><strong>Edit Deposit Mode:</strong> Edit the rows directly below, then click Update Deposit.</div>
                <?php endif; ?>
                <div class="qb-topbar">
                    <div class="qb-field">
                        <label class="qb-label">Deposit To</label>
                        <select name="bank_id" class="qb-control qb-select" required>
                            <option value="">Select Account</option>
                            <?php foreach ($banks as $bank): ?>
                                <?php
                                    $indent_level = max(0, (int)($bank['indent_level'] ?? 0));
                                    $indent_prefix = $indent_level > 0 ? str_repeat("\u{00A0}\u{00A0}\u{00A0}\u{00A0}", $indent_level) . ' ' : '';
                                    $account_label = (!empty($bank['account_number']) ? $bank['account_number'] . ' · ' : '') . ($bank['bank_name'] ?? '');
                                    $has_children = !empty($bank['has_children']);
                                ?>
                                <option value="<?php echo (int)$bank['bank_id']; ?>" <?php echo $has_children ? 'disabled' : ''; ?> <?php echo ((int)$bank['bank_id'] === (int)$journal_deposit_form_bank_id) ? 'selected' : ''; ?>><?php echo h($indent_prefix . $account_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="qb-field">
                        <label class="qb-label">Date</label>
                        <input type="date" name="transaction_date" class="qb-control qb-date" value="<?php echo h($journal_deposit_form_date); ?>" required>
                    </div>
                    <div class="qb-field">
                        <label class="qb-label">Memo</label>
                        <input type="text" name="description" class="qb-control qb-memo" value="<?php echo h($journal_deposit_form_description); ?>">
                        <input type="hidden" name="reference_number" value="<?php echo h($journal_deposit_form_reference); ?>">
                    </div>
                </div>


                <div class="qb-table-wrap" id="undepositedTableWrap">
                    <table class="qb-table" id="undepositedTable">
                        <thead>
                            <tr>
                                <th style="width:20%">Received From</th>
                                <th style="width:14%">From Account</th>
                                <th style="width:20%">Memo</th>
                                <th style="width:10%">Chk No.</th>
                                <th style="width:12%">Pmt Meth.</th>
                                <th style="width:16%">Payment Details</th>
                                <th style="width:8%">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($available_payments as $payment): 
                                $payment_date = date('Y-m-d', strtotime($payment['payment_date']));
                                $collected_by_trimmed = trim($payment['collected_by_name'] ?? '');
                                $method_trimmed = trim($payment['payment_method']);
                                $payment_details = formatPaymentDetails($payment);
                                $memo_text = trim($payment['description'] ?? '');
                                if ($memo_text === '') $memo_text = trim($payment['transaction_label'] ?? (($payment['invoice_number'] ?: 'No Invoice') . (!empty($payment['so_number']) ? ' / ' . $payment['so_number'] : '')));
                            ?>
                            <tr class="clickable-row main-payment-row" style="<?php echo ($journal_deposit_edit_mode && !in_array((int)$payment['payment_id'], $journal_deposit_selected_payment_ids, true)) ? 'display:none' : ''; ?>" data-payment-id="<?php echo (int)$payment['payment_id']; ?>" 
                                data-collected-by="<?php echo h($collected_by_trimmed); ?>"
                                data-payment-method="<?php echo h($method_trimmed); ?>"
                                data-payment-details="<?php echo h($payment_details); ?>"
                                data-payment-date="<?php echo $payment_date; ?>"
                                data-amount="<?php echo (float)$payment['amount']; ?>"
                                data-type="payment">
                                <td>
                                    <input type="checkbox" class="form-check-input deposit-item-checkbox qb-hidden-check" data-type="payment" value="<?php echo (int)$payment['payment_id']; ?>" data-amount="<?php echo (float)$payment['amount']; ?>" <?php echo in_array((int)$payment['payment_id'], $journal_deposit_selected_payment_ids, true) ? 'checked' : ''; ?> onclick="event.stopPropagation()">
<?php echo h(trim($payment['customer_name'] ?: 'Unknown Customer')); ?>
                                </td>
                                <td>Accounts Receivable</td>
                                <td><?php echo h($memo_text); ?></td>
                                <td><?php echo h($payment['reference_number'] ?: ''); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', h($method_trimmed))); ?></td>
                                <td title="<?php echo h($payment_details); ?>"><?php echo h($payment_details); ?></td>
                                <td class="qb-amount">
                                    <input type="number" step="0.01" min="0.01" class="qb-control journal-deposit-amount" name="journal_payment_amount[<?php echo (int)$payment['payment_id']; ?>]" value="<?php echo h(number_format((float)$payment['amount'], 2, '.', '')); ?>" data-type="payment" data-linked-checkbox="<?php echo (int)$payment['payment_id']; ?>" style="width:100%; text-align:right; border:1px solid #86efac; background:#fff; padding:3px 6px;">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php for($i=0;$i<15;$i++): ?>
                            <tr class="qb-blank-row" aria-hidden="true">
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td class="qb-amount">&nbsp;</td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <div class="qb-subtotal-row">
                    <div class="qb-subtotal-label">Deposit Subtotal</div>
                    <div class="qb-subtotal-amount" id="selectedDepositTotal">₱0.00</div>
                </div>

                <div class="qb-total-actions qb-total-actions-clean">
                    <div class="qb-total-label">Deposit Total <span class="qb-total-amount" id="selectedDepositTotalBottom">₱0.00</span></div>
                    <div class="qb-buttons">
                        <button type="submit" class="qb-btn" <?php echo (!$journal_deposit_edit_mode && empty($available_payments)) ? 'disabled' : ''; ?>><?php echo $journal_deposit_edit_mode ? 'Update Deposit' : 'Save &amp; Close'; ?></button>
                        <?php if (!$journal_deposit_edit_mode): ?>
                        <button type="submit" class="qb-btn qb-btn-primary" <?php echo empty($available_payments) ? 'disabled' : ''; ?>>Save &amp; New</button>
                        <?php endif; ?>
                        <button type="button" class="qb-btn" onclick="clearDepositSelection()">Clear</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="tab-pane fade qb-pane p-3" id="creditTab">
            <div class="undeposited-header">
                <h6 class="mb-0">Credit Memos</h6>
                <div>
                    <button type="button" class="btn btn-sm btn-amgc-primary me-2" data-bs-toggle="modal" data-bs-target="#createCreditMemoModal">
                        <i class="bi bi-plus-circle"></i> New Credit Memo
                    </button>
                    <span class="badge rounded-pill badge-soft-red px-3 py-2">-₱<?php echo number_format($undeposited_credits_total, 2); ?></span>
                </div>
            </div>
            <div class="filter-bar">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-6"><label class="form-label small fw-bold mb-1">Customer</label><select id="filterCreditCustomer" class="form-select form-select-sm"><option value="">All Customers</option><?php foreach ($distinct_credit_customers as $cust): ?><option value="<?php echo h($cust); ?>"><?php echo h($cust); ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label small fw-bold mb-1">Date Range</label><div class="d-flex gap-2"><input type="date" id="creditDateFrom" class="form-control form-control-sm"><input type="date" id="creditDateTo" class="form-control form-control-sm"></div></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-nowrap" id="creditMemoTable">
                    <thead><tr><th style="width:40px"><input type="checkbox" id="selectAllCreditsCheckbox"></th><th>Customer</th><th>Amount</th><th>Credit Date</th><th>Reference</th><th>Description</th><th>Created By</th></tr></thead>
                    <tbody>
                        <?php if (empty($available_credits)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No credit memos available.</td></tr>
                        <?php else: foreach ($available_credits as $credit): $credit_date = date('Y-m-d', strtotime($credit['credit_date'])); ?>
                        <tr class="clickable-row credit-memo-clickable-row" onclick="handleCreditMemoRowClick(event, this)" data-credit-id="<?php echo (int)$credit['credit_memo_id']; ?>" data-customer-name="<?php echo h($credit['customer_name'] ?? ''); ?>" data-credit-date="<?php echo $credit_date; ?>" data-amount="<?php echo (float)$credit['amount']; ?>" data-type="credit">
                            <td><input type="checkbox" class="form-check-input deposit-item-checkbox" data-type="credit" value="<?php echo (int)$credit['credit_memo_id']; ?>" data-amount="<?php echo (float)$credit['amount']; ?>" <?php echo in_array((int)$credit['credit_memo_id'], $journal_deposit_selected_credit_ids, true) ? 'checked' : ''; ?> onclick="event.stopPropagation()"></td>
                            <td><?php echo h($credit['customer_name'] ?? 'Unknown Customer'); ?></td><td class="amount-negative">-₱<?php echo number_format((float)$credit['amount'], 2); ?></td><td><?php echo date('M d, Y', strtotime($credit['credit_date'])); ?></td><td><?php echo h($credit['reference_number'] ?: '-'); ?></td><td><?php echo h($credit['description'] ?: '-'); ?></td><td><?php echo h($credit['created_by_name'] ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade qb-pane p-3" id="historyTab">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-nowrap">
                    <thead><tr><th>Date</th><th>Reference</th><th>Bank</th><th>Description</th><th>Amount</th><th style="width:90px">Action</th></tr></thead>
                    <tbody>
                        <?php if (empty($bank_transactions)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No deposit history yet.</td></tr>
                        <?php else: foreach ($bank_transactions as $tx): 
                            $payment_list = [];
                            if (!empty($tx['payment_links'])) { foreach (explode(';;', $tx['payment_links']) as $link) { $parts = explode('||', $link); if (count($parts) >= 4 && $parts[0] === 'payment') $payment_list[] = ['type'=>'payment','customer'=>$parts[1],'invoice'=>$parts[2],'amount'=>$parts[3]]; } }
                            if (!empty($tx['credit_links'])) { foreach (explode(';;', $tx['credit_links']) as $link) { $parts = explode('||', $link); if (count($parts) >= 4 && $parts[0] === 'credit') $payment_list[] = ['type'=>'credit','customer'=>$parts[1],'ref'=>$parts[2],'amount'=>$parts[3]]; } }
                        ?>
                        <tr class="deposit-row-clickable" data-transaction='<?php echo htmlspecialchars(json_encode(['date'=>date('M d, Y', strtotime($tx['transaction_date'])),'reference'=>$tx['reference_number'] ?: '-','bank'=>$tx['bank_name'] ?: '-','description'=>$tx['description'] ?: '-','amount'=>number_format((float)$tx['amount'], 2),'items'=>$payment_list,'encoded_by'=>trim($tx['created_by_name']) !== '' ? $tx['created_by_name'] : 'Unknown User']), ENT_QUOTES, 'UTF-8'); ?>'>
                            <td><?php echo date('M d, Y', strtotime($tx['transaction_date'])); ?></td><td><?php echo h($tx['reference_number'] ?: '-'); ?></td><td><?php echo h($tx['bank_name'] ?: '-'); ?></td><td><?php echo h($tx['description'] ?: '-'); ?></td><td class="amount-positive">₱<?php echo number_format((float)$tx['amount'], 2); ?></td><td><a class="btn btn-sm btn-outline-success" href="deposit.php?edit_deposit=1&bank_transaction_id=<?php echo (int)$tx['transaction_id']; ?>">Edit</a></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div></div></div></div></div></div>

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
    <div class="modal-dialog modal-dialog-centered payment-details-invoice-dialog">
        <div class="modal-content payment-invoice-modal">
            <div class="modal-header payment-invoice-modal-header">
                <div class="payment-invoice-topbar">
                    <div class="payment-invoice-topbar-field">
                        <label>Customer:</label>
                        <div class="payment-invoice-topbar-value" id="paymentDetailsTopCustomer">-</div>
                    </div>
                    <div class="payment-invoice-topbar-field">
                        <label>Accounts Receivable</label>
                        <div class="payment-invoice-topbar-value">Accounts Receivable</div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body payment-invoice-modal-body">
                <div id="modalPaymentContent">Loading...</div>
            </div>
        </div>
    </div>
</div>


<!-- Payments to Deposit Modal -->
<div class="modal fade qb-payment-modal" id="paymentsToDepositModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payments to Deposit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="qb-select-view">
                    <div class="qb-select-view-title">Select View</div>
                    <div class="d-flex flex-wrap align-items-start gap-2">
                        <div>
                            <div class="qb-modal-field">
                                <label>View payment method type</label>
                                <select id="modalPaymentMethodFilter" class="qb-modal-green">
                                    <option value="">All types</option>
                                    <?php foreach ($distinct_methods as $method): ?>
                                        <option value="<?php echo h($method); ?>"><?php echo ucwords(str_replace('_', ' ', h($method))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="qb-modal-field">
                                <label>View collected by</label>
                                <select id="modalCollectorFilter" class="qb-modal-green">
                                    <option value="">All collectors</option>
                                    <?php foreach ($distinct_collectors as $collector): ?>
                                        <option value="<?php echo h($collector); ?>"><?php echo h($collector); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="qb-modal-field">
                                <label>Sort payments by</label>
                                <select id="modalPaymentSort">
                                    <option value="method">Payment Method</option>
                                    <option value="collector">Collector</option>
                                    <option value="date">Date</option>
                                    <option value="name">Name</option>
                                    <option value="amount">Amount</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="qb-select-payments-title">Select Payments to Deposit</div>
                <div class="qb-modal-table-wrap">
                    <table class="qb-modal-table" id="paymentsToDepositTable">
                        <thead>
                            <tr>
                                <th style="width:4%">✓</th>
                                <th style="width:11%">Date</th>
                                <th style="width:9%">Time</th>
                                <th style="width:12%">Type</th>
                                <th style="width:24%">Transaction</th>
                                <th style="width:15%">Payment Method</th>
                                <th style="width:18%">Name</th>
                                <th style="width:14%">Collector</th>
                                <th style="width:10%">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($available_payments as $payment): 
                                $modal_date = date('m/d/Y', strtotime($payment['payment_date']));
                                $modal_time = date('h:i A', strtotime($payment['payment_date']));
                                $method_trimmed = trim($payment['payment_method']);
                                $collector_trimmed = trim($payment['collected_by_name'] ?? '');
                                $invoice_no = trim($payment['invoice_number'] ?? '');
                                $transaction_label = trim($payment['transaction_label'] ?? '');
                                $transaction_type_label = trim($payment['transaction_type_label'] ?? 'Payment');
                                $row_no = trim($payment['reference_number'] ?? '');
                                if ($row_no === '') $row_no = trim($payment['check_number'] ?? '');
                                if ($row_no === '') $row_no = trim($payment['si_number'] ?? '');
                                if ($row_no === '') $row_no = $invoice_no;
                                if ($transaction_label === '') $transaction_label = $row_no !== '' ? $row_no : ('Payment #' . (int)$payment['payment_id']);
                                $name_text = trim($payment['customer_name'] ?: 'Unknown Customer');
                            ?>
                            <tr data-payment-id="<?php echo (int)$payment['payment_id']; ?>" data-method="<?php echo h($method_trimmed); ?>" data-collector="<?php echo h($collector_trimmed); ?>" data-date="<?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?>" data-name="<?php echo h($name_text); ?>" data-amount="<?php echo (float)$payment['amount']; ?>">
                                <td><input type="checkbox" class="modal-payment-check" value="<?php echo (int)$payment['payment_id']; ?>" <?php echo in_array((int)$payment['payment_id'], $journal_deposit_selected_payment_ids, true) ? 'checked' : ''; ?> onclick="event.stopPropagation()"></td>
                                <td><?php echo h($modal_date); ?></td>
                                <td><?php echo h($modal_time); ?></td>
                                <td><?php echo h($transaction_type_label); ?></td>
                                <td><?php echo h($transaction_label); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', h($method_trimmed))); ?></td>
                                <td><?php echo h($name_text); ?></td>
                                <td><?php echo h($collector_trimmed !== '' ? $collector_trimmed : 'Unknown User'); ?></td>
                                <td class="qb-modal-amount"><?php echo number_format((float)$payment['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php $modal_blank_rows = max(0, 16 - count($available_payments)); for($i=0;$i<$modal_blank_rows;$i++): ?><tr><td colspan="9">&nbsp;</td></tr><?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <div class="qb-modal-summary">
                    <div><span id="modalSelectedCount">0</span> of <?php echo count($available_payments); ?> payments selected for deposit</div>
                    <div>Payments Subtotal&nbsp;&nbsp;&nbsp;&nbsp;<span id="modalPaymentsSubtotal">0.00</span></div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="qb-modal-bottom-buttons">
                    <div class="qb-modal-left-buttons">
                        <button type="button" class="qb-modal-btn" id="modalSelectAllPayments">Select All</button>
                        <button type="button" class="qb-modal-btn" id="modalSelectNonePayments" disabled>Select None</button>
                    </div>
                    <div class="qb-modal-right-buttons">
                        <button type="button" class="qb-modal-btn qb-modal-btn-primary" id="modalPaymentsOk">OK</button>
                        <button type="button" class="qb-modal-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="qb-modal-btn">Help</button>
                    </div>
                </div>
            </div>
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

document.addEventListener('DOMContentLoaded', function () {
    updateEmployeesTaskBadge();
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
function updateDepositTotal(){
    let total = 0;
    document.querySelectorAll('.deposit-item-checkbox:checked').forEach(cb => {
        let amount = parseFloat(cb.dataset.amount || '0');
        const row = cb.closest('tr');
        const amountInput = row ? row.querySelector('.journal-deposit-amount') : null;
        if (amountInput) {
            amount = parseFloat(String(amountInput.value || '0').replace(/,/g, '')) || 0;
            cb.dataset.amount = amount;
        }
        if (cb.dataset.type === 'credit') amount = -amount;
        total += amount;
    });
    const el = document.getElementById('selectedDepositTotal');
    if(el) el.textContent = '₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
    const bottomEl = document.getElementById('selectedDepositTotalBottom');
    if(bottomEl) bottomEl.textContent = '₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.querySelectorAll('#undepositedTable tbody tr.clickable-row').forEach(row => {
        const cb = row.querySelector('.deposit-item-checkbox');
        if(cb && cb.checked) row.classList.add('qb-selected'); else row.classList.remove('qb-selected');
    });
}

function applyPaymentFilters(){
    const filterCollectedByEl = document.getElementById('filterCollectedBy');
    if(!filterCollectedByEl) return;
    const collectedBy = (filterCollectedByEl.value || '').trim().toLowerCase();
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

function clearDepositSelection(){
    document.querySelectorAll('.deposit-item-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.modal-payment-check').forEach(cb => cb.checked = false);
    document.querySelectorAll('#undepositedTable tbody tr.main-payment-row').forEach(row => row.style.display = 'none');
    if (typeof refreshBlankDepositRows === 'function') refreshBlankDepositRows();
    if (typeof updatePaymentsToDepositModalSummary === 'function') updatePaymentsToDepositModalSummary();
    updateDepositTotal();
    updateSelectAllCheckbox();
    updateSelectAllCreditsCheckbox();
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
    const p = undepositedPayments.find(p => p.payment_id == paymentId);
    const modalBody = document.getElementById('modalPaymentContent');
    const topCustomer = document.getElementById('paymentDetailsTopCustomer');
    if(!modalBody) return;

    if(!p){
        if(topCustomer) topCustomer.textContent = '-';
        modalBody.innerHTML = '<div class="alert alert-danger mb-0">Payment details not found.</div>';
    } else {
        const customerName = p.customer_name || 'Unknown Customer';
        const transactionLabel = p.transaction_label || p.invoice_number || p.so_number || ('Payment #' + p.payment_id);
        const transactionType = p.transaction_type_label || 'Invoice';
        const amount = parseFloat(p.amount || 0);
        const amountText = amount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        const invoiceDateRaw = p.invoice_date || p.so_order_date || p.payment_date || '';
        const dueDateRaw = p.invoice_due_date || p.invoice_date || p.so_order_date || p.payment_date || '';
        const invoiceDate = invoiceDateRaw ? new Date(invoiceDateRaw) : null;
        const dueDate = dueDateRaw ? new Date(dueDateRaw) : null;
        const dateText = invoiceDate && !isNaN(invoiceDate.getTime()) ? invoiceDate.toLocaleDateString('en-PH', {month:'2-digit', day:'2-digit', year:'numeric'}) : '-';
        const dueDateText = dueDate && !isNaN(dueDate.getTime()) ? dueDate.toLocaleDateString('en-PH', {month:'2-digit', day:'2-digit', year:'numeric'}) : dateText;
        const methodText = (p.payment_method || '-').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        const collectorText = p.collected_by_name || 'Unknown User';
        const invoiceNo = p.invoice_number || p.invoice_si_number || transactionLabel;
        const atwNo = p.so_atw_no || '-';
        const gatepassNo = p.so_gatepass_no || '-';
        const deliveryType = (p.so_delivery_type || 'Pick Up').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        const orderedItems = Array.isArray(p.ordered_items) ? p.ordered_items : [];
        const rows = [];
        let invoiceTotal = 0;
        if (orderedItems.length > 0) {
            orderedItems.forEach(item => {
                const itemName = item.item_name || item.item_code || ('Item #' + (item.item_id || ''));
                const unitType = item.unit_type || 'Piece';
                const qty = parseFloat(item.quantity_ordered || 0);
                const price = parseFloat(item.unit_price || 0);
                const lineTotal = parseFloat(item.line_total || (qty * price) || 0);
                invoiceTotal += lineTotal;
                rows.push(`<tr>
                    <td>${escapeHtml(itemName)}</td>
                    <td>${escapeHtml(unitType)}</td>
                    <td class="text-end">${qty.toLocaleString('en-PH', {maximumFractionDigits:2})}</td>
                    <td class="text-end">${price.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    <td class="text-end">${lineTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>`);
            });
        } else {
            invoiceTotal = amount;
            rows.push(`<tr>
                <td>${escapeHtml(transactionLabel)}</td>
                <td>${escapeHtml(transactionType)}</td>
                <td class="text-end">1</td>
                <td class="text-end">${amountText}</td>
                <td class="text-end">${amountText}</td>
            </tr>`);
        }
        const invoiceTotalText = invoiceTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        const blankCount = Math.max(0, 10 - rows.length);
        for(let i=0;i<blankCount;i++) rows.push(`<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>`);

        if(topCustomer) topCustomer.textContent = customerName;
        modalBody.innerHTML = `
            <div class="payment-invoice-view">
                <div class="payment-invoice-head">
                    <div><div class="payment-invoice-title">Invoice</div></div>
                    <div>
                        <div class="payment-invoice-meta">
                            <div class="payment-invoice-field"><label>Date</label><div class="payment-invoice-control">${escapeHtml(dateText)}</div></div>
                            <div class="payment-invoice-field"><label>Invoice #</label><div class="payment-invoice-control">${escapeHtml(invoiceNo)}</div></div>
                            <div class="payment-invoice-field"><label>Terms</label><div class="payment-invoice-control">-</div></div>
                            <div class="payment-invoice-field"><label>Due Date</label><div class="payment-invoice-control">${escapeHtml(dueDateText)}</div></div>
                        </div>
                        <div class="payment-invoice-extra">
                            <div class="payment-invoice-field"><label>ATW No.</label><div class="payment-invoice-control">${escapeHtml(atwNo)}</div></div>
                            <div class="payment-invoice-field"><label>Gatepass No.</label><div class="payment-invoice-control">${escapeHtml(gatepassNo)}</div></div>
                            <div class="payment-invoice-field"><label>Delivery Type</label><div class="payment-invoice-control">${escapeHtml(deliveryType)}</div></div>
                        </div>
                    </div>
                </div>
                <div class="payment-invoice-table-wrap">
                    <table class="payment-invoice-table">
                        <thead><tr><th style="width:34%">Product</th><th style="width:18%">Unit</th><th style="width:12%" class="text-end">Qty</th><th style="width:18%" class="text-end">Price</th><th style="width:18%" class="text-end">Total</th></tr></thead>
                        <tbody>${rows.join('')}</tbody>
                    </table>
                </div>
                <div class="payment-invoice-bottom">
                    <div>
                        <div class="payment-invoice-message-label">Customer Message</div>
                        <div class="payment-invoice-message-box">Collected by ${escapeHtml(collectorText)} through ${escapeHtml(methodText)}.</div>
                        <div class="payment-invoice-toggle-note"><span class="payment-invoice-toggle"></span><strong>Collect payment now</strong></div>
                        <div class="payment-invoice-note">Viewing only. This modal fills out the invoice transaction connected to the selected payment.</div>
                    </div>
                    <div class="payment-invoice-totals">
                        <div class="payment-invoice-total-row"><div class="label">(0.0%)</div><div class="value">0.00</div></div>
                        <div class="payment-invoice-total-row"><div class="label">Total</div><div class="value">${invoiceTotalText}</div></div>
                        <div class="payment-invoice-total-row"><div class="label">Payments Applied</div><div class="value">${amountText}</div></div>
                        <div class="payment-invoice-balance-row"><div class="label">Balance Due</div><div class="value">${Math.max(0, invoiceTotal - amount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</div></div>
                        <div class="payment-invoice-actions"><button type="button" class="payment-invoice-btn" data-bs-dismiss="modal">Close</button><button type="button" class="payment-invoice-btn primary" data-bs-dismiss="modal">Done</button></div>
                    </div>
                </div>
            </div>`;
    }

    const detailsModalEl = document.getElementById('paymentDetailsModal');
    if (!detailsModalEl) return;
    detailsModalEl.style.zIndex = '20050';
    const detailsModal = bootstrap.Modal.getOrCreateInstance(detailsModalEl, {backdrop:true, keyboard:true});
    detailsModal.show();
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
    const AMGC_DEPOSIT_JOURNAL_EDIT_MODE = <?php echo $journal_deposit_edit_mode ? 'true' : 'false'; ?>;
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
    

    document.getElementById('paymentDetailsModal')?.addEventListener('show.bs.modal', function() {
        this.style.zIndex = '20050';
        const paymentsModal = document.getElementById('paymentsToDepositModal');
        if (paymentsModal && paymentsModal.classList.contains('show')) {
            paymentsModal.style.zIndex = '20020';
        }
    });

    document.getElementById('paymentDetailsModal')?.addEventListener('shown.bs.modal', function() {
        this.style.zIndex = '20050';
        const backdrops = document.querySelectorAll('.modal-backdrop');
        const lastBackdrop = backdrops[backdrops.length - 1];
        if (lastBackdrop) {
            lastBackdrop.classList.add('payment-details-backdrop');
            lastBackdrop.style.zIndex = '20040';
        }
    });

    document.getElementById('paymentDetailsModal')?.addEventListener('hidden.bs.modal', function() {
        this.style.zIndex = '';
        const paymentsModal = document.getElementById('paymentsToDepositModal');
        if (paymentsModal) paymentsModal.style.zIndex = '';
        if (document.getElementById('paymentsToDepositModal')?.classList.contains('show')) {
            document.body.classList.add('modal-open');
        }
    });
    document.querySelectorAll('#historyTab .deposit-row-clickable').forEach(row => {
        row.addEventListener('click', function() {
            const transactionData = this.getAttribute('data-transaction');
            if (transactionData) showDepositDetails(transactionData);
        });
    });
    
    document.querySelectorAll('#undepositedTable tbody tr.clickable-row').forEach(row=>{
        row.addEventListener('click',function(e){
            if(e.target.closest('input, button, a, select, textarea')) return;
            const paymentId = this.getAttribute('data-payment-id');
            if (paymentId) showPaymentDetails(paymentId);
        });
    });
    
    document.querySelectorAll('#creditMemoTable tbody tr.clickable-row').forEach(row=>{
        row.addEventListener('click',function(e){
            handleCreditMemoRowClick(e, this);
        });
    });
    
    document.querySelectorAll('.deposit-item-checkbox').forEach(cb=>cb.addEventListener('change',function(){updateDepositTotal(); updateSelectAllCheckbox(); updateSelectAllCreditsCheckbox();}));
    document.querySelectorAll('.journal-deposit-amount').forEach(inp=>inp.addEventListener('input', function(){
        const row = this.closest('tr');
        const cb = row ? row.querySelector('.deposit-item-checkbox') : null;
        if (cb) cb.dataset.amount = String(parseFloat(String(this.value || '0').replace(/,/g, '')) || 0);
        updateDepositTotal();
    }));
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
    

    function refreshBlankDepositRows() {
        const tbody = document.querySelector('#undepositedTable tbody');
        if (!tbody) return;

        const shownRows = Array.from(document.querySelectorAll('#undepositedTable tbody tr.main-payment-row'))
            .filter(row => window.getComputedStyle(row).display !== 'none')
            .length;

        let blanks = Array.from(document.querySelectorAll('#undepositedTable tbody tr.qb-blank-row'));
        const requiredBlankRows = Math.max(0, 15 - shownRows);

        while (blanks.length < 15) {
            const blank = document.createElement('tr');
            blank.className = 'qb-blank-row';
            blank.setAttribute('aria-hidden', 'true');
            blank.innerHTML = `
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td class="qb-amount">&nbsp;</td>
            `;
            tbody.appendChild(blank);
            blanks.push(blank);
        }

        blanks.forEach((row, index) => {
            row.style.display = index < requiredBlankRows ? '' : 'none';
        });

        const tableWrap = document.getElementById('undepositedTableWrap');
        if (tableWrap) {
            tableWrap.classList.toggle('has-scroll', shownRows > 15);
        }
    }
    window.refreshBlankDepositRows = refreshBlankDepositRows;

    function syncMainDepositTableFromModal() {
        const selectedIds = new Set(Array.from(document.querySelectorAll('.modal-payment-check:checked')).map(cb => cb.value));
        document.querySelectorAll('#undepositedTable tbody tr.main-payment-row').forEach(row => {
            const id = row.getAttribute('data-payment-id');
            const cb = row.querySelector('.deposit-item-checkbox');
            if (selectedIds.has(id)) {
                row.style.display = '';
                if (cb) cb.checked = true;
            } else {
                row.style.display = 'none';
                if (cb) cb.checked = false;
            }
        });
        refreshBlankDepositRows();
        updateDepositTotal();
    }

    function updatePaymentsToDepositModalSummary() {
        let count = 0;
        let total = 0;
        document.querySelectorAll('.modal-payment-check').forEach(cb => {
            if (cb.checked) {
                count++;
                const row = cb.closest('tr');
                total += parseFloat(row?.getAttribute('data-amount') || '0');
            }
        });
        const countEl = document.getElementById('modalSelectedCount');
        const totalEl = document.getElementById('modalPaymentsSubtotal');
        const noneBtn = document.getElementById('modalSelectNonePayments');
        if (countEl) countEl.textContent = count;
        if (totalEl) totalEl.textContent = total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        if (noneBtn) noneBtn.disabled = count === 0;
    }

    function scrollPaymentsToDepositModalTop() {
        const tableWrap = document.querySelector('#paymentsToDepositModal .qb-modal-table-wrap');
        if (!tableWrap) return;
        tableWrap.scrollTop = 0;
        tableWrap.scrollLeft = 0;
    }

    function applyPaymentsToDepositModalFilterAndSort() {
        const method = (document.getElementById('modalPaymentMethodFilter')?.value || '').trim().toLowerCase();
        const collector = (document.getElementById('modalCollectorFilter')?.value || '').trim().toLowerCase();
        const sortBy = document.getElementById('modalPaymentSort')?.value || 'method';
        const tbody = document.querySelector('#paymentsToDepositTable tbody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr[data-payment-id]'));
        const blankRows = Array.from(tbody.querySelectorAll('tr:not([data-payment-id])'));

        rows.forEach(row => {
            const rowMethod = (row.getAttribute('data-method') || '').trim().toLowerCase();
            const rowCollector = (row.getAttribute('data-collector') || '').trim().toLowerCase();
            let show = true;
            if (method && rowMethod !== method) show = false;
            if (collector && rowCollector !== collector) show = false;
            row.style.display = show ? '' : 'none';
        });

        rows.sort((a,b) => {
            if (sortBy === 'amount') return parseFloat(b.getAttribute('data-amount') || '0') - parseFloat(a.getAttribute('data-amount') || '0');
            if (sortBy === 'date') return (b.getAttribute('data-date') || '').localeCompare(a.getAttribute('data-date') || '');
            if (sortBy === 'name') return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
            if (sortBy === 'collector') return (a.getAttribute('data-collector') || '').localeCompare(b.getAttribute('data-collector') || '');
            return (a.getAttribute('data-method') || '').localeCompare(b.getAttribute('data-method') || '');
        });

        // Keep real transaction rows above the blank filler rows.
        // Before this fix, sorting appended the transactions after blank rows,
        // so the payment appeared at the bottom of the modal.
        rows.forEach(row => tbody.appendChild(row));
        blankRows.forEach(row => tbody.appendChild(row));
        scrollPaymentsToDepositModalTop();
    }

    document.querySelectorAll('.modal-payment-check').forEach(cb => cb.addEventListener('change', updatePaymentsToDepositModalSummary));
    document.querySelectorAll('#paymentsToDepositTable tbody tr[data-payment-id]').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('input, button, a, label, select, textarea')) return;
            const paymentId = this.getAttribute('data-payment-id');
            if (paymentId) showPaymentDetails(paymentId);
        });
    });
    document.getElementById('modalPaymentMethodFilter')?.addEventListener('change', applyPaymentsToDepositModalFilterAndSort);
    document.getElementById('modalCollectorFilter')?.addEventListener('change', applyPaymentsToDepositModalFilterAndSort);
    document.getElementById('modalPaymentSort')?.addEventListener('change', applyPaymentsToDepositModalFilterAndSort);
    document.getElementById('paymentsToDepositModal')?.addEventListener('shown.bs.modal', function() {
        applyPaymentsToDepositModalFilterAndSort();
        scrollPaymentsToDepositModalTop();
    });
    document.getElementById('modalSelectAllPayments')?.addEventListener('click', function() {
        document.querySelectorAll('#paymentsToDepositTable tbody tr[data-payment-id]').forEach(row => {
            if (row.style.display !== 'none') {
                const cb = row.querySelector('.modal-payment-check');
                if (cb) cb.checked = true;
            }
        });
        updatePaymentsToDepositModalSummary();
    });
    document.getElementById('modalSelectNonePayments')?.addEventListener('click', function() {
        document.querySelectorAll('.modal-payment-check').forEach(cb => cb.checked = false);
        updatePaymentsToDepositModalSummary();
    });
    document.getElementById('modalPaymentsOk')?.addEventListener('click', function() {
        syncMainDepositTableFromModal();
        const modalEl = document.getElementById('paymentsToDepositModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modalInstance.hide();
    });

    refreshBlankDepositRows();
    updatePaymentsToDepositModalSummary();
    applyPaymentsToDepositModalFilterAndSort();
    const paymentsToDepositModalEl = document.getElementById('paymentsToDepositModal');
    if (paymentsToDepositModalEl && !AMGC_DEPOSIT_JOURNAL_EDIT_MODE) {
        const paymentsToDepositModal = new bootstrap.Modal(paymentsToDepositModalEl);
        paymentsToDepositModal.show();
    }
    if (AMGC_DEPOSIT_JOURNAL_EDIT_MODE) {
        document.querySelectorAll('#undepositedTable tbody tr.main-payment-row').forEach(row => {
            const cb = row.querySelector('.deposit-item-checkbox');
            if (cb && cb.checked) row.style.display = '';
        });
        if (typeof refreshBlankDepositRows === 'function') refreshBlankDepositRows();
        if (typeof updateDepositTotal === 'function') updateDepositTotal();
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
</body></html>