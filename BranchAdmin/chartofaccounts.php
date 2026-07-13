<?php
// chartofaccounts.php - Chart of Accounts Management

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
}
$user_initials = $user_initials ?: 'BA';

$branch_name = 'All Branches';
if (!$view_all_branches && (int)$branch_id > 0) {
    $branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1");
    if ($branch_stmt) {
        $branch_stmt->bind_param('i', $branch_id);
        $branch_stmt->execute();
        $branch_result = $branch_stmt->get_result();
        if ($row = $branch_result->fetch_assoc()) $branch_name = $row['branch_name'];
        $branch_stmt->close();
    }
}

// Create and update chart_of_accounts table.
$conn->query("CREATE TABLE IF NOT EXISTS chart_of_accounts (
    account_id INT(11) NOT NULL AUTO_INCREMENT,
    branch_id INT(11) DEFAULT NULL,
    parent_account_id INT(11) DEFAULT NULL,
    account_code VARCHAR(50) DEFAULT NULL,
    account_title VARCHAR(255) NOT NULL,
    account_type VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    bank_branch VARCHAR(150) DEFAULT NULL,
    account_number VARCHAR(100) DEFAULT NULL,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    as_of_date DATE DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    bank_id INT(11) DEFAULT NULL,
    created_by INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    KEY idx_chart_accounts_branch_id (branch_id),
    KEY idx_chart_accounts_parent (parent_account_id),
    KEY idx_chart_accounts_type (account_type),
    KEY idx_chart_accounts_status (status),
    KEY idx_chart_accounts_bank_id (bank_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$columns_to_add = [
    'parent_account_id' => "ALTER TABLE chart_of_accounts ADD COLUMN parent_account_id INT(11) DEFAULT NULL AFTER branch_id",
    'account_code' => "ALTER TABLE chart_of_accounts ADD COLUMN account_code VARCHAR(50) DEFAULT NULL AFTER parent_account_id",
    'description' => "ALTER TABLE chart_of_accounts ADD COLUMN description TEXT DEFAULT NULL AFTER account_type",
    'bank_branch' => "ALTER TABLE chart_of_accounts ADD COLUMN bank_branch VARCHAR(150) DEFAULT NULL AFTER description",
    'account_number' => "ALTER TABLE chart_of_accounts ADD COLUMN account_number VARCHAR(100) DEFAULT NULL AFTER bank_branch",
    'as_of_date' => "ALTER TABLE chart_of_accounts ADD COLUMN as_of_date DATE DEFAULT NULL AFTER balance",
    'status' => "ALTER TABLE chart_of_accounts ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER as_of_date",
    'bank_id' => "ALTER TABLE chart_of_accounts ADD COLUMN bank_id INT(11) DEFAULT NULL AFTER status"
];
foreach ($columns_to_add as $column => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM chart_of_accounts LIKE '$column'");
    if ($check && $check->num_rows == 0) $conn->query($sql);
}
$index_checks = [
    'idx_chart_accounts_parent' => 'parent_account_id',
    'idx_chart_accounts_status' => 'status',
    'idx_chart_accounts_bank_id' => 'bank_id'
];
foreach ($index_checks as $index => $column) {
    $idx = $conn->query("SHOW INDEX FROM chart_of_accounts WHERE Key_name = '$index'");
    if ($idx && $idx->num_rows == 0) $conn->query("ALTER TABLE chart_of_accounts ADD INDEX $index ($column)");
}

// Bank accounts must always have an As of Date. If older Bank accounts were saved
// without one, use the account creation date as the default date.
@$conn->query("UPDATE chart_of_accounts 
              SET as_of_date = DATE(created_at) 
              WHERE account_type = 'Bank' 
                AND (as_of_date IS NULL OR as_of_date = '0000-00-00')");

$account_types = [
    'Bank',
    'Accounts Receivable',
    'Other Current Asset',
    'Fixed Asset',
    'Other Asset',
    'Accounts Payable',
    'Credit Card',
    'Other Current Liability',
    'Long Term Liability',
    'Equity',
    'Income',
    'Cost of Goods Sold',
    'Expense',
    'Other Income',
    'Other Expense'
];

$flash_success = $_SESSION['success_message'] ?? '';
$flash_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

function cleanMoney($value) {
    $value = str_replace([',', ' '], '', (string)$value);
    return is_numeric($value) ? (float)$value : 0.00;
}

function getEffectiveBranchId($view_all_branches, $branch_id, $posted_branch_id = null) {
    if ($view_all_branches) return $posted_branch_id !== null && $posted_branch_id !== '' ? (int)$posted_branch_id : null;
    return (int)$branch_id > 0 ? (int)$branch_id : null;
}

function chartAccountColumnExistsSimple($conn, $table, $column) {
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
    $column = $conn->real_escape_string((string)$column);
    if ($table === '' || $column === '') return false;
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function ensureChartBankTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS banks (
        bank_id int(11) NOT NULL AUTO_INCREMENT,
        branch_id int(11) NOT NULL DEFAULT 0,
        bank_name varchar(150) NOT NULL,
        account_name varchar(150) DEFAULT NULL,
        account_number varchar(100) DEFAULT NULL,
        bank_branch varchar(150) DEFAULT NULL,
        status enum('active','inactive') NOT NULL DEFAULT 'active',
        parent_bank_id int(11) DEFAULT NULL,
        initial_balance decimal(12,2) NOT NULL DEFAULT 0.00,
        initial_balance_date date DEFAULT NULL,
        created_by int(11) NOT NULL DEFAULT 0,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (bank_id),
        KEY branch_id (branch_id),
        KEY status (status),
        KEY parent_bank_id (parent_bank_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $bank_columns = [
        'account_name' => "ALTER TABLE banks ADD COLUMN account_name varchar(150) DEFAULT NULL AFTER bank_name",
        'account_number' => "ALTER TABLE banks ADD COLUMN account_number varchar(100) DEFAULT NULL AFTER account_name",
        'bank_branch' => "ALTER TABLE banks ADD COLUMN bank_branch varchar(150) DEFAULT NULL AFTER account_number",
        'status' => "ALTER TABLE banks ADD COLUMN status enum('active','inactive') NOT NULL DEFAULT 'active' AFTER bank_branch",
        'parent_bank_id' => "ALTER TABLE banks ADD COLUMN parent_bank_id int(11) DEFAULT NULL AFTER status",
        'initial_balance' => "ALTER TABLE banks ADD COLUMN initial_balance decimal(12,2) NOT NULL DEFAULT 0.00 AFTER parent_bank_id",
        'initial_balance_date' => "ALTER TABLE banks ADD COLUMN initial_balance_date date DEFAULT NULL AFTER initial_balance",
        'created_by' => "ALTER TABLE banks ADD COLUMN created_by int(11) NOT NULL DEFAULT 0 AFTER initial_balance_date"
    ];
    foreach ($bank_columns as $column => $sql) {
        if (!chartAccountColumnExistsSimple($conn, 'banks', $column)) @$conn->query($sql);
    }

    $idx = $conn->query("SHOW INDEX FROM banks WHERE Key_name = 'parent_bank_id'");
    if (!$idx || $idx->num_rows === 0) @$conn->query("ALTER TABLE banks ADD INDEX parent_bank_id (parent_bank_id)");

    $conn->query("CREATE TABLE IF NOT EXISTS bank_payment_methods (
        id int(11) NOT NULL AUTO_INCREMENT,
        bank_id int(11) NOT NULL,
        payment_method enum('check','online_transfer','cash') NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_bank_method (bank_id,`payment_method`),
        KEY bank_id (bank_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function getLinkedParentBankId($conn, $parent_account_id) {
    $parent_account_id = (int)$parent_account_id;
    if ($parent_account_id <= 0) return null;
    $stmt = $conn->prepare("SELECT bank_id FROM chart_of_accounts WHERE account_id = ? AND account_type = 'Bank' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $parent_account_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row['bank_id']) ? (int)$row['bank_id'] : null;
}

function saveDefaultBankPaymentMethods($conn, $bank_id) {
    $bank_id = (int)$bank_id;
    if ($bank_id <= 0) return;
    $methods = ['cash', 'check', 'online_transfer'];
    $stmt = $conn->prepare("INSERT IGNORE INTO bank_payment_methods (bank_id, payment_method) VALUES (?, ?)");
    if (!$stmt) return;
    foreach ($methods as $method) {
        $stmt->bind_param('is', $bank_id, $method);
        $stmt->execute();
    }
    $stmt->close();
}

function syncChartBankAccount($conn, $account_id, $effective_branch_id, $parent_account_id, $account_title, $bank_branch, $account_number, $balance, $as_of_date, $status, $user_id) {
    $account_id = (int)$account_id;
    if ($account_id <= 0) return;
    ensureChartBankTables($conn);

    $bank_id = null;
    $stmt = $conn->prepare("SELECT bank_id FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $account_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['bank_id'])) $bank_id = (int)$row['bank_id'];
    }

    $target_branch = $effective_branch_id === null ? 0 : (int)$effective_branch_id;
    $linked_parent_bank_id = getLinkedParentBankId($conn, $parent_account_id);
    $initial_balance_date = trim((string)$as_of_date) !== '' ? $as_of_date : date('Y-m-d');
    $status = $status === 'inactive' ? 'inactive' : 'active';
    $account_name = '';
    $account_number = trim((string)$account_number);

    if ($bank_id && $bank_id > 0) {
        $update = $conn->prepare("UPDATE banks SET branch_id = ?, bank_name = ?, account_name = ?, account_number = ?, bank_branch = ?, status = ?, parent_bank_id = ?, initial_balance = ?, initial_balance_date = ? WHERE bank_id = ?");
        if ($update) {
            $update->bind_param('isssssidsi', $target_branch, $account_title, $account_name, $account_number, $bank_branch, $status, $linked_parent_bank_id, $balance, $initial_balance_date, $bank_id);
            $update->execute();
            $update->close();
            saveDefaultBankPaymentMethods($conn, $bank_id);
        }
    } else {
        $insert = $conn->prepare("INSERT INTO banks (branch_id, bank_name, account_name, account_number, bank_branch, status, parent_bank_id, initial_balance, initial_balance_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($insert) {
            $insert->bind_param('isssssidsi', $target_branch, $account_title, $account_name, $account_number, $bank_branch, $status, $linked_parent_bank_id, $balance, $initial_balance_date, $user_id);
            if ($insert->execute()) {
                $bank_id = (int)$conn->insert_id;
                $link = $conn->prepare("UPDATE chart_of_accounts SET bank_id = ? WHERE account_id = ?");
                if ($link) {
                    $link->bind_param('ii', $bank_id, $account_id);
                    $link->execute();
                    $link->close();
                }
                saveDefaultBankPaymentMethods($conn, $bank_id);
            }
            $insert->close();
        }
    }
}

function disableLinkedBankIfAccountIsNotBank($conn, $account_id) {
    $account_id = (int)$account_id;
    if ($account_id <= 0) return;
    $stmt = $conn->prepare("SELECT bank_id FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($row['bank_id'])) {
        $bank_id = (int)$row['bank_id'];
        $upd = $conn->prepare("UPDATE banks SET status = 'inactive' WHERE bank_id = ?");
        if ($upd) {
            $upd->bind_param('i', $bank_id);
            $upd->execute();
            $upd->close();
        }
        $clear = $conn->prepare("UPDATE chart_of_accounts SET bank_id = NULL, bank_branch = NULL, account_number = NULL, as_of_date = NULL WHERE account_id = ?");
        if ($clear) {
            $clear->bind_param('i', $account_id);
            $clear->execute();
            $clear->close();
        }
    }
}

function ensureDefaultChartAccounts($conn, $view_all_branches, $branch_id, $user_id) {
    $default_branch_id = (!$view_all_branches && (int)$branch_id > 0) ? (int)$branch_id : null;
    $default_accounts = [
        ['Cash and Cash Equivalents', 'Bank', 'Default account for cash, bank, and other cash-equivalent balances.'],
        ['Accounts Receivable', 'Accounts Receivable', 'Default account for customer receivables and unpaid invoices.'],
        ['Inventory', 'Other Current Asset', 'Default account for inventory assets.'],
        ['Accounts Payable', 'Accounts Payable', 'Default account for supplier payables and unpaid bills.'],
        ['Opening Balance Equity', 'Equity', 'Default account used for beginning balances.']
    ];

    foreach ($default_accounts as $default_account) {
        [$title, $type, $description] = $default_account;
        if ($default_branch_id === null) {
            $check = $conn->prepare("SELECT account_id FROM chart_of_accounts WHERE account_title = ? AND branch_id IS NULL LIMIT 1");
            if (!$check) continue;
            $check->bind_param('s', $title);
        } else {
            $check = $conn->prepare("SELECT account_id FROM chart_of_accounts WHERE account_title = ? AND branch_id = ? LIMIT 1");
            if (!$check) continue;
            $check->bind_param('si', $title, $default_branch_id);
        }
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if ($exists) continue;

        $parent_account_id = null;
        $account_code = '';
        $balance = 0.00;
        $created_by = (int)$user_id;
        $stmt = $conn->prepare("INSERT INTO chart_of_accounts (branch_id, parent_account_id, account_code, account_title, account_type, description, balance, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) continue;
        $stmt->bind_param('iissssdi', $default_branch_id, $parent_account_id, $account_code, $title, $type, $description, $balance, $created_by);
        $stmt->execute();
        $stmt->close();
    }
}

ensureDefaultChartAccounts($conn, $view_all_branches, $branch_id, $user_id);


function isDescendantAccount($conn, $account_id, $possible_descendant_id) {
    $account_id = (int)$account_id;
    $possible_descendant_id = (int)$possible_descendant_id;
    if ($account_id <= 0 || $possible_descendant_id <= 0) return false;

    $current_id = $possible_descendant_id;
    $guard = 0;

    while ($current_id > 0 && $guard < 100) {
        if ($current_id === $account_id) return true;

        $stmt = $conn->prepare("SELECT parent_account_id FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $current_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['parent_account_id'])) return false;
        $current_id = (int)$row['parent_account_id'];
        $guard++;
    }

    return false;
}

function countSubAccounts($conn, $account_id) {
    $account_id = (int)$account_id;
    if ($account_id <= 0) return 0;

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM chart_of_accounts WHERE parent_account_id = ?");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function countAccountTransactions($conn, $account_id) {
    $account_id = (int)$account_id;
    if ($account_id <= 0) return 0;

    $table_check = $conn->query("SHOW TABLES LIKE 'chart_account_transactions'");
    if (!$table_check || $table_check->num_rows == 0) return 0;

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM chart_account_transactions WHERE account_id = ?");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function validateParentAccountType($conn, $parent_account_id, $account_type, $current_account_id = 0) {
    $parent_account_id = (int)$parent_account_id;
    $current_account_id = (int)$current_account_id;

    if ($parent_account_id <= 0) return;

    if ($current_account_id > 0 && $parent_account_id === $current_account_id) {
        throw new Exception('Parent account cannot be the same account.');
    }

    $stmt = $conn->prepare("SELECT account_type FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Unable to validate parent account.');
    }

    $stmt->bind_param('i', $parent_account_id);
    $stmt->execute();
    $parent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$parent) {
        throw new Exception('Invalid parent account selected.');
    }

    if ((string)$parent['account_type'] !== (string)$account_type) {
        throw new Exception('Parent account must have the same account type.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $save_mode = $_POST['save_mode'] ?? 'close';
    try {
        if ($action === 'add_account') {
            $account_code = trim($_POST['account_code'] ?? '');
            $account_title = trim($_POST['account_title'] ?? '');
            $account_type = trim($_POST['account_type'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $parent_account_id = !empty($_POST['parent_account_id']) ? (int)$_POST['parent_account_id'] : null;
            $balance = cleanMoney($_POST['balance'] ?? 0);
            $bank_branch = $account_type === 'Bank' ? trim($_POST['bank_branch'] ?? '') : '';
            $account_number = $account_type === 'Bank' ? trim($_POST['account_number'] ?? '') : '';
            $as_of_date = $account_type === 'Bank' ? trim($_POST['as_of_date'] ?? '') : null;
            if ($account_type === 'Bank' && empty($as_of_date)) {
                $as_of_date = date('Y-m-d');
            }
            $effective_branch_id = getEffectiveBranchId($view_all_branches, $branch_id, $_POST['branch_id'] ?? null);

            if ($account_title === '') throw new Exception('Account title is required.');
            if ($account_type === '') throw new Exception('Account type is required.');
            validateParentAccountType($conn, $parent_account_id, $account_type);
            $stmt = $conn->prepare("INSERT INTO chart_of_accounts (branch_id, parent_account_id, account_code, account_title, account_type, description, bank_branch, account_number, balance, as_of_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iissssssdsi', $effective_branch_id, $parent_account_id, $account_code, $account_title, $account_type, $description, $bank_branch, $account_number, $balance, $as_of_date, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to add account: ' . $stmt->error);
            $new_account_id = (int)$conn->insert_id;
            $stmt->close();
            if ($account_type === 'Bank') {
                syncChartBankAccount($conn, $new_account_id, $effective_branch_id, $parent_account_id, $account_title, $bank_branch, $account_number, $balance, $as_of_date, 'active', $user_id);
            }
            $_SESSION['success_message'] = $account_type === 'Bank' ? 'Bank account added successfully.' : 'Account added successfully.';
        }

        if ($action === 'update_account') {
            $account_id = (int)($_POST['account_id'] ?? 0);
            $account_code = trim($_POST['account_code'] ?? '');
            $account_title = trim($_POST['account_title'] ?? '');
            $account_type = trim($_POST['account_type'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $parent_account_id = !empty($_POST['parent_account_id']) ? (int)$_POST['parent_account_id'] : null;
            $balance = cleanMoney($_POST['balance'] ?? 0);
            $bank_branch = $account_type === 'Bank' ? trim($_POST['bank_branch'] ?? '') : '';
            $account_number = $account_type === 'Bank' ? trim($_POST['account_number'] ?? '') : '';
            $as_of_date = $account_type === 'Bank' ? trim($_POST['as_of_date'] ?? '') : null;
            if ($account_type === 'Bank' && empty($as_of_date)) {
                $as_of_date = date('Y-m-d');
            }
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            if ($account_id <= 0) throw new Exception('Invalid account selected.');
            if ($account_title === '') throw new Exception('Account title is required.');
            if ($parent_account_id === $account_id) throw new Exception('Parent account cannot be the same account.');
            if ($parent_account_id !== null && isDescendantAccount($conn, $account_id, $parent_account_id)) {
                throw new Exception('Invalid parent account selected. An account cannot use its own sub account as parent.');
            }
            validateParentAccountType($conn, $parent_account_id, $account_type, $account_id);

            $sql = "UPDATE chart_of_accounts SET parent_account_id = ?, account_code = ?, account_title = ?, account_type = ?, description = ?, bank_branch = ?, account_number = ?, balance = ?, as_of_date = ?, status = ? WHERE account_id = ?";
            if (!$view_all_branches && (int)$branch_id > 0) $sql .= " AND branch_id = " . (int)$branch_id;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('issssssdssi', $parent_account_id, $account_code, $account_title, $account_type, $description, $bank_branch, $account_number, $balance, $as_of_date, $status, $account_id);
            if (!$stmt->execute()) throw new Exception('Failed to update account: ' . $stmt->error);
            $stmt->close();
            $effective_branch_id = getEffectiveBranchId($view_all_branches, $branch_id, $_POST['branch_id'] ?? null);
            if ($account_type === 'Bank') {
                syncChartBankAccount($conn, $account_id, $effective_branch_id, $parent_account_id, $account_title, $bank_branch, $account_number, $balance, $as_of_date, $status, $user_id);
            } else {
                disableLinkedBankIfAccountIsNotBank($conn, $account_id);
            }
            $_SESSION['success_message'] = 'Account updated successfully.';
        }

        if ($action === 'delete_account') {
            $account_id = (int)($_POST['account_id'] ?? 0);
            if ($account_id <= 0) throw new Exception('Invalid account selected.');
            $child_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM chart_of_accounts WHERE parent_account_id = ?");
            $child_stmt->bind_param('i', $account_id);
            $child_stmt->execute();
            $child_total = (int)($child_stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $child_stmt->close();
            if ($child_total > 0) throw new Exception('This account has sub accounts. Remove or move the sub accounts first.');
            if (countAccountTransactions($conn, $account_id) > 0) {
                throw new Exception('This account already has transactions and cannot be deleted.');
            }
            $sql = "DELETE FROM chart_of_accounts WHERE account_id = ?";
            if (!$view_all_branches && (int)$branch_id > 0) $sql .= " AND branch_id = " . (int)$branch_id;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $account_id);
            disableLinkedBankIfAccountIsNotBank($conn, $account_id);
            if (!$stmt->execute()) throw new Exception('Failed to delete account: ' . $stmt->error);
            $stmt->close();
            $_SESSION['success_message'] = 'Account deleted successfully.';
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
    $redirect_url = 'chartofaccounts.php';
    if ($action === 'add_account' && $save_mode === 'new' && empty($_SESSION['error_message'])) {
        $redirect_url .= '?open_add=1';
    }
    header('Location: ' . $redirect_url);
    exit();
}

$where = "WHERE 1=1";
$params = [];
$types = '';
if (!$view_all_branches && (int)$branch_id > 0) {
    $where .= " AND (branch_id = ? OR branch_id IS NULL)";
    $types .= 'i';
    $params[] = $branch_id;
}
$search = trim($_GET['search'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
if ($search !== '') {
    $where .= " AND (account_title LIKE ? OR account_code LIKE ? OR account_type LIKE ? OR description LIKE ?)";
    $like = '%' . $search . '%';
    $types .= 'ssss';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($type_filter !== '') {
    $where .= " AND account_type = ?";
    $types .= 's';
    $params[] = $type_filter;
}

$account_type_order_sql = "FIELD(account_type, 'Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset', 'Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability', 'Equity', 'Income', 'Cost of Goods Sold', 'Expense', 'Other Income', 'Other Expense')";
$sql = "SELECT * FROM chart_of_accounts $where ORDER BY $account_type_order_sql ASC, COALESCE(parent_account_id, account_id) ASC, parent_account_id ASC, account_code ASC, account_title ASC";
$stmt = $conn->prepare($sql);
if ($stmt && !empty($params)) $stmt->bind_param($types, ...$params);
if ($stmt) {
    $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $accounts = [];
}

function buildAccountTree($accounts) {
    $children = [];
    foreach ($accounts as $account) {
        $parent = $account['parent_account_id'] ?: 0;
        if (!isset($children[$parent])) $children[$parent] = [];
        $children[$parent][] = $account;
    }
    $ordered = [];
    $walk = function($parentId, $level) use (&$walk, &$children, &$ordered) {
        if (empty($children[$parentId])) return;
        foreach ($children[$parentId] as $account) {
            $account['_level'] = $level;
            $account['_has_children'] = !empty($children[(int)$account['account_id']]);
            $ordered[] = $account;
            $walk((int)$account['account_id'], $level + 1);
        }
    };
    $walk(0, 0);
    foreach ($accounts as $account) {
        $found = false;
        foreach ($ordered as $o) if ((int)$o['account_id'] === (int)$account['account_id']) { $found = true; break; }
        if (!$found) {
            $account['_level'] = 0;
            $account['_has_children'] = false;
            $ordered[] = $account;
        }
    }
    return $ordered;
}

$ordered_accounts = buildAccountTree($accounts);
$parent_account_options = $ordered_accounts;

function formatParentAccountOptionLabel($account) {
    $level = max(0, (int)($account['_level'] ?? 0));
    $indent = $level > 0 ? str_repeat("\u{00A0}\u{00A0}\u{00A0}\u{00A0}", $level) . '↳ ' : '';
    $code = trim((string)($account['account_code'] ?? ''));
    $title = trim((string)($account['account_title'] ?? ''));
    return $indent . ($code !== '' ? $code . ' · ' : '') . $title;
}

function buildAccountTotals($accounts) {
    $children = [];
    $byId = [];
    foreach ($accounts as $account) {
        $id = (int)$account['account_id'];
        $parent = (int)($account['parent_account_id'] ?? 0);
        $byId[$id] = $account;
        if (!isset($children[$parent])) $children[$parent] = [];
        $children[$parent][] = $id;
    }

    $totals = [];
    $sumAccount = function($accountId) use (&$sumAccount, &$children, &$byId, &$totals) {
        if (isset($totals[$accountId])) return $totals[$accountId];
        $total = isset($byId[$accountId]) ? (float)$byId[$accountId]['balance'] : 0.00;
        if (!empty($children[$accountId])) {
            foreach ($children[$accountId] as $childId) {
                $total += $sumAccount((int)$childId);
            }
        }
        $totals[$accountId] = $total;
        return $total;
    };

    foreach ($byId as $id => $account) {
        $sumAccount((int)$id);
    }
    return $totals;
}

$account_totals = buildAccountTotals($accounts);

// Chart of Accounts Quick Report rows.
// The report now keeps ALL transactions per account, not only the latest row.
// When the source is purchase_orders, Reference No. and Memo are pulled from purchase_orders
// so the report shows the exact values typed in purchase_order.php.
function chartTableExists($conn, $tableName) {
    $safe = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '$safe'");
    return $result && $result->num_rows > 0;
}
function chartColumnExists($conn, $tableName, $columnName) {
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    $column = $conn->real_escape_string($columnName);
    if ($table === '') return false;
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}


function chartFirstExistingColumn($conn, $tableName, $columns) {
    foreach ($columns as $columnName) {
        if (chartColumnExists($conn, $tableName, $columnName)) return $columnName;
    }
    return '';
}

function chartNormalizeCounterpartyKey($value) {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/^(customer|vendor|supplier|payee)\s*-\s*/i', '', $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function chartBuildCustomerGroupLookup($conn) {
    $lookup = [];
    if (!chartTableExists($conn, 'customers')) return $lookup;

    $nameColumn = chartFirstExistingColumn($conn, 'customers', [
        'customer_name', 'name', 'customer', 'company_name', 'business_name', 'full_name', 'fullname'
    ]);
    if ($nameColumn === '') return $lookup;

    $groupColumn = chartFirstExistingColumn($conn, 'customers', [
        'customer_group', 'group_name', 'customer_type', 'type', 'classification'
    ]);
    $groupIdColumn = chartFirstExistingColumn($conn, 'customers', [
        'customer_group_id', 'group_id'
    ]);

    if ($groupColumn !== '') {
        $sql = "SELECT `$nameColumn` AS customer_name, `$groupColumn` AS customer_group FROM customers WHERE `$nameColumn` IS NOT NULL AND TRIM(`$nameColumn`) <> ''";
    } elseif ($groupIdColumn !== '' && chartTableExists($conn, 'customer_groups')) {
        $groupNameColumn = chartFirstExistingColumn($conn, 'customer_groups', ['group_name', 'customer_group', 'name', 'title']);
        $groupPrimaryColumn = chartFirstExistingColumn($conn, 'customer_groups', ['customer_group_id', 'group_id', 'id']);
        if ($groupNameColumn === '' || $groupPrimaryColumn === '') return $lookup;
        $sql = "SELECT c.`$nameColumn` AS customer_name, cg.`$groupNameColumn` AS customer_group
                FROM customers c
                LEFT JOIN customer_groups cg ON cg.`$groupPrimaryColumn` = c.`$groupIdColumn`
                WHERE c.`$nameColumn` IS NOT NULL AND TRIM(c.`$nameColumn`) <> ''";
    } else {
        return $lookup;
    }

    $result = $conn->query($sql);
    if (!$result) return $lookup;
    while ($row = $result->fetch_assoc()) {
        $key = chartNormalizeCounterpartyKey($row['customer_name'] ?? '');
        if ($key === '') continue;
        $lookup[$key] = trim((string)($row['customer_group'] ?? ''));
    }
    return $lookup;
}

$quick_report_customer_group_lookup = chartBuildCustomerGroupLookup($conn);

function chartAttachCustomerGroupToQuickReportRow($row, $customerGroupLookup) {
    $counterparty = trim((string)($row['report_counterparty'] ?? ''));
    $key = chartNormalizeCounterpartyKey($counterparty);
    $row['report_customer_group'] = $key !== '' && isset($customerGroupLookup[$key]) ? $customerGroupLookup[$key] : '';
    return $row;
}

$account_transaction_counts = [];
if (chartTableExists($conn, 'chart_account_transactions')) {
    $transactionCountSql = "SELECT account_id, COUNT(*) AS total FROM chart_account_transactions GROUP BY account_id";
    $transactionCountResult = $conn->query($transactionCountSql);
    if ($transactionCountResult) {
        while ($transactionCountRow = $transactionCountResult->fetch_assoc()) {
            $account_transaction_counts[(int)$transactionCountRow['account_id']] = (int)$transactionCountRow['total'];
        }
    }
}

$quick_report_transactions = [];
if (chartTableExists($conn, 'purchase_orders') && chartTableExists($conn, 'chart_account_transactions')) {
    // Quick Report must read PO header details from purchase_orders.
    // chart_account_transactions is used only to identify the account and debit/credit movement.
    if (!chartColumnExists($conn, 'purchase_orders', 'address')) {
        @$conn->query("ALTER TABLE `purchase_orders` ADD COLUMN `address` text DEFAULT NULL AFTER `supplier_name`");
    }
    if (!chartColumnExists($conn, 'purchase_orders', 'ref_no')) {
        $after = chartColumnExists($conn, 'purchase_orders', 'payable_account') ? 'payable_account' : 'total_amount';
        @$conn->query("ALTER TABLE `purchase_orders` ADD COLUMN `ref_no` varchar(100) DEFAULT NULL AFTER `{$after}`");
    }
    if (!chartColumnExists($conn, 'purchase_orders', 'memo')) {
        $after = chartColumnExists($conn, 'purchase_orders', 'ref_no') ? 'ref_no' : 'total_amount';
        @$conn->query("ALTER TABLE `purchase_orders` ADD COLUMN `memo` text DEFAULT NULL AFTER `{$after}`");
    }

    $po_has_ref_no = chartColumnExists($conn, 'purchase_orders', 'ref_no');
    $po_has_memo = chartColumnExists($conn, 'purchase_orders', 'memo');
    $po_has_address = chartColumnExists($conn, 'purchase_orders', 'address');
    $po_has_supplier_name = chartColumnExists($conn, 'purchase_orders', 'supplier_name');
    $po_has_transaction_type = chartColumnExists($conn, 'purchase_orders', 'transaction_type');

    $referenceExpr = $po_has_ref_no ? "NULLIF(TRIM(po.ref_no), '')" : "NULL";
    $memoExpr = $po_has_memo ? "NULLIF(TRIM(po.memo), '')" : "NULL";
    $addressExpr = $po_has_address ? "COALESCE(po.address, '')" : "''";
    $counterpartyExpr = $po_has_supplier_name ? "COALESCE(NULLIF(TRIM(po.supplier_name), ''), '')" : "''";
    $transactionTypeExpr = $po_has_transaction_type
        ? "CASE WHEN LOWER(COALESCE(po.transaction_type,'')) = 'credit' THEN 'Credit' WHEN LOWER(COALESCE(po.transaction_type,'')) = 'bill' THEN 'Bill' ELSE COALESCE(NULLIF(TRIM(cat.transaction_type), ''), 'Purchase Order') END"
        : "COALESCE(NULLIF(TRIM(cat.transaction_type), ''), 'Purchase Order')";

    $qrSql = "SELECT
                cat.account_id,
                DATE(COALESCE(cat.transaction_date, po.order_date, po.created_at)) AS transaction_date,
                {$transactionTypeExpr} AS transaction_type,
                COALESCE($referenceExpr, NULLIF(TRIM(po.po_number), ''), NULLIF(TRIM(cat.transaction_no), ''), CONCAT('PO-', po.po_id)) AS report_reference_no,
                COALESCE($memoExpr, '') AS report_memo,
                $addressExpr AS report_address,
                $counterpartyExpr AS report_counterparty,
                cat.debit,
                cat.credit,
                cat.balance_after,
                cat.transaction_id,
                cat.source_table,
                cat.source_id,
                po.po_id,
                po.po_number
            FROM purchase_orders po
            INNER JOIN chart_account_transactions cat
                ON cat.source_table = 'purchase_orders'
               AND cat.source_id = po.po_id";
    if (!$view_all_branches && (int)$branch_id > 0) {
        $qrSql .= " WHERE cat.branch_id = " . (int)$branch_id;
    }
    $qrSql .= " ORDER BY cat.account_id ASC, DATE(COALESCE(cat.transaction_date, po.order_date, po.created_at)) ASC, cat.transaction_id ASC";

    $qrResult = $conn->query($qrSql);
    if ($qrResult) {
        while ($qrRow = $qrResult->fetch_assoc()) {
            $aid = (int)($qrRow['account_id'] ?? 0);
            if ($aid <= 0) continue;
            if (!isset($quick_report_transactions[$aid])) $quick_report_transactions[$aid] = [];
            $qrRow = chartAttachCustomerGroupToQuickReportRow($qrRow, $quick_report_customer_group_lookup);
            $quick_report_transactions[$aid][] = $qrRow;
        }
    }
}

// Include Deposit and Withdrawal transactions posted from deposit.php / Withdrawal.php.
// This allows Bank, Expense, Reference No., Description/Memo, and Payee/Counterparty
// to show in the same Quick Report table for the affected Chart of Accounts.
if (chartTableExists($conn, 'bank_transactions') && chartTableExists($conn, 'chart_account_transactions')) {
    if (!chartColumnExists($conn, 'bank_transactions', 'payee')) {
        @$conn->query("ALTER TABLE `bank_transactions` ADD COLUMN `payee` varchar(150) DEFAULT NULL AFTER `expense_account`");
    }

    $bankQrSql = "SELECT
                cat.account_id,
                DATE(COALESCE(cat.transaction_date, bt.transaction_date, cat.created_at)) AS transaction_date,
                CASE WHEN bt.transaction_type = 'withdrawal' THEN 'Check' ELSE COALESCE(NULLIF(TRIM(cat.transaction_type), ''), 'Deposit') END AS transaction_type,
                COALESCE(NULLIF(TRIM(bt.reference_number), ''), NULLIF(TRIM(cat.reference_no), ''), NULLIF(TRIM(cat.transaction_no), ''), CONCAT(CASE WHEN bt.transaction_type = 'withdrawal' THEN 'WDL-' ELSE 'DEP-' END, bt.transaction_id)) AS report_reference_no,
                COALESCE(NULLIF(TRIM(bt.description), ''), NULLIF(TRIM(cat.memo), ''), '') AS report_memo,
                '' AS report_address,
                CASE 
                    WHEN bt.transaction_type = 'withdrawal' THEN COALESCE(NULLIF(TRIM(bt.payee), ''), NULLIF(TRIM(cat.account_name), ''), NULLIF(TRIM(bt.bank_name), ''))
                    ELSE COALESCE(NULLIF(TRIM(bt.payee), ''), NULLIF(TRIM(bt.bank_name), ''), NULLIF(TRIM(cat.account_name), ''))
                END AS report_counterparty,
                cat.debit,
                cat.credit,
                cat.balance_after,
                cat.transaction_id,
                cat.source_table,
                cat.source_id,
                bt.transaction_id AS po_id,
                COALESCE(NULLIF(TRIM(cat.transaction_no), ''), CONCAT(CASE WHEN bt.transaction_type = 'withdrawal' THEN 'WDL-' ELSE 'DEP-' END, bt.transaction_id)) AS po_number
            FROM chart_account_transactions cat
            INNER JOIN bank_transactions bt
                ON cat.source_table = 'bank_transactions'
               AND cat.source_id = bt.transaction_id
            WHERE bt.transaction_type IN ('deposit', 'withdrawal')";
    if (!$view_all_branches && (int)$branch_id > 0) {
        $bankQrSql .= " AND cat.branch_id = " . (int)$branch_id;
    }
    $bankQrSql .= " ORDER BY cat.account_id ASC, DATE(COALESCE(cat.transaction_date, bt.transaction_date, cat.created_at)) ASC, cat.transaction_id ASC";

    $bankQrResult = $conn->query($bankQrSql);
    if ($bankQrResult) {
        while ($qrRow = $bankQrResult->fetch_assoc()) {
            $aid = (int)($qrRow['account_id'] ?? 0);
            if ($aid <= 0) continue;
            if (!isset($quick_report_transactions[$aid])) $quick_report_transactions[$aid] = [];
            $qrRow = chartAttachCustomerGroupToQuickReportRow($qrRow, $quick_report_customer_group_lookup);
            $quick_report_transactions[$aid][] = $qrRow;
        }
    }
}

// Include Repair Payment transactions posted from Branch Admin Motorpool repair payments.
// Repair Payment memo must show the Remarks entered in the Motorpool payment modal.
if (chartTableExists($conn, 'repair_payment_history') && chartTableExists($conn, 'chart_account_transactions')) {
    $repairQrSql = "SELECT
                cat.account_id,
                DATE(COALESCE(cat.transaction_date, rph.payment_date, rph.created_at)) AS transaction_date,
                COALESCE(NULLIF(TRIM(cat.transaction_type), ''), 'Repair Payment') AS transaction_type,
                COALESCE(NULLIF(TRIM(cat.reference_no), ''), NULLIF(TRIM(cat.transaction_no), ''), NULLIF(TRIM(rph.ris_number), ''), CONCAT('RPAY-', rph.payment_id)) AS report_reference_no,
                COALESCE(NULLIF(TRIM(rph.remarks), ''), NULLIF(TRIM(cat.memo), ''), '') AS report_memo,
                '' AS report_address,
                COALESCE(NULLIF(TRIM(rph.bank_account_name), ''), NULLIF(TRIM(rph.expense_account_name), ''), NULLIF(TRIM(cat.account_name), '')) AS report_counterparty,
                cat.debit,
                cat.credit,
                cat.balance_after,
                cat.transaction_id,
                cat.source_table,
                cat.source_id,
                rph.payment_id AS po_id,
                COALESCE(NULLIF(TRIM(cat.transaction_no), ''), CONCAT('RPAY-', rph.payment_id)) AS po_number
            FROM chart_account_transactions cat
            INNER JOIN repair_payment_history rph
                ON cat.source_table = 'repair_payment_history'
               AND cat.source_id = rph.payment_id
            WHERE 1=1";
    if (!$view_all_branches && (int)$branch_id > 0) {
        $repairQrSql .= " AND cat.branch_id = " . (int)$branch_id;
    }
    $repairQrSql .= " ORDER BY cat.account_id ASC, DATE(COALESCE(cat.transaction_date, rph.payment_date, rph.created_at)) ASC, cat.transaction_id ASC";

    $repairQrResult = $conn->query($repairQrSql);
    if ($repairQrResult) {
        while ($qrRow = $repairQrResult->fetch_assoc()) {
            $aid = (int)($qrRow['account_id'] ?? 0);
            if ($aid <= 0) continue;
            if (!isset($quick_report_transactions[$aid])) $quick_report_transactions[$aid] = [];
            $qrRow = chartAttachCustomerGroupToQuickReportRow($qrRow, $quick_report_customer_group_lookup);
            $quick_report_transactions[$aid][] = $qrRow;
        }
    }
}


// Include Transfer Funds transactions posted from transferfunds.php.
// This makes Transfer Funds appear in the same Quick Report table for both
// the From account and To account in Chart of Accounts.
if (chartTableExists($conn, 'fund_transfers') && chartTableExists($conn, 'chart_account_transactions')) {
    $transferColumnsToAdd = [
        'payment_method' => "ALTER TABLE `fund_transfers` ADD COLUMN `payment_method` varchar(50) NOT NULL DEFAULT 'Online Transfer' AFTER `memo`",
        'check_bank_branch' => "ALTER TABLE `fund_transfers` ADD COLUMN `check_bank_branch` varchar(255) DEFAULT NULL AFTER `payment_method`",
        'check_no' => "ALTER TABLE `fund_transfers` ADD COLUMN `check_no` varchar(100) DEFAULT NULL AFTER `check_bank_branch`",
        'online_reference_no' => "ALTER TABLE `fund_transfers` ADD COLUMN `online_reference_no` varchar(150) DEFAULT NULL AFTER `check_no`"
    ];
    foreach ($transferColumnsToAdd as $column => $sqlToRun) {
        if (!chartColumnExists($conn, 'fund_transfers', $column)) {
            @$conn->query($sqlToRun);
        }
    }

    $transferQrSql = "SELECT
                cat.account_id,
                DATE(COALESCE(cat.transaction_date, ft.transfer_date, cat.created_at)) AS transaction_date,
                COALESCE(NULLIF(TRIM(cat.transaction_type), ''), 'Transfer Funds') AS transaction_type,
                COALESCE(NULLIF(TRIM(cat.reference_no), ''), NULLIF(TRIM(cat.transaction_no), ''), NULLIF(TRIM(ft.transfer_no), ''), CONCAT('TF-', ft.transfer_id)) AS report_reference_no,
                CONCAT(
                    COALESCE(NULLIF(TRIM(ft.memo), ''), NULLIF(TRIM(cat.memo), ''), 'Funds Transfer'),
                    CASE 
                        WHEN ft.payment_method IS NOT NULL AND TRIM(ft.payment_method) <> '' 
                            THEN CONCAT(' | Payment Method: ', ft.payment_method)
                        ELSE ''
                    END,
                    CASE
                        WHEN ft.payment_method = 'Check'
                            THEN CONCAT(
                                CASE WHEN ft.check_bank_branch IS NOT NULL AND TRIM(ft.check_bank_branch) <> '' THEN CONCAT(' | Bank/Branch: ', ft.check_bank_branch) ELSE '' END,
                                CASE WHEN ft.check_no IS NOT NULL AND TRIM(ft.check_no) <> '' THEN CONCAT(' | Check No.: ', ft.check_no) ELSE '' END
                            )
                        WHEN ft.payment_method = 'Online Transfer' AND ft.online_reference_no IS NOT NULL AND TRIM(ft.online_reference_no) <> ''
                            THEN CONCAT(' | Reference No.: ', ft.online_reference_no)
                        ELSE ''
                    END
                ) AS report_memo,
                '' AS report_address,
                CASE
                    WHEN cat.account_id = ft.from_account_id THEN COALESCE(NULLIF(CONCAT('Transfer To: ', ft.to_account_title), 'Transfer To: '), 'Transfer Funds')
                    WHEN cat.account_id = ft.to_account_id THEN COALESCE(NULLIF(CONCAT('Transfer From: ', ft.from_account_title), 'Transfer From: '), 'Transfer Funds')
                    ELSE COALESCE(NULLIF(TRIM(cat.account_name), ''), 'Transfer Funds')
                END AS report_counterparty,
                cat.debit,
                cat.credit,
                cat.balance_after,
                cat.transaction_id,
                cat.source_table,
                cat.source_id,
                ft.transfer_id AS po_id,
                COALESCE(NULLIF(TRIM(ft.transfer_no), ''), CONCAT('TF-', ft.transfer_id)) AS po_number
            FROM chart_account_transactions cat
            INNER JOIN fund_transfers ft
                ON cat.source_table = 'fund_transfers'
               AND cat.source_id = ft.transfer_id
            WHERE 1=1";
    if (!$view_all_branches && (int)$branch_id > 0) {
        $transferQrSql .= " AND cat.branch_id = " . (int)$branch_id;
    }
    $transferQrSql .= " ORDER BY cat.account_id ASC, DATE(COALESCE(cat.transaction_date, ft.transfer_date, cat.created_at)) ASC, cat.transaction_id ASC";

    $transferQrResult = $conn->query($transferQrSql);
    if ($transferQrResult) {
        while ($qrRow = $transferQrResult->fetch_assoc()) {
            $aid = (int)($qrRow['account_id'] ?? 0);
            if ($aid <= 0) continue;
            if (!isset($quick_report_transactions[$aid])) $quick_report_transactions[$aid] = [];
            $qrRow = chartAttachCustomerGroupToQuickReportRow($qrRow, $quick_report_customer_group_lookup);
            $quick_report_transactions[$aid][] = $qrRow;
        }
    }
}

foreach ($quick_report_transactions as $aid => $rows) {
    usort($quick_report_transactions[$aid], function($a, $b) {
        $dateCompare = strcmp((string)($a['transaction_date'] ?? ''), (string)($b['transaction_date'] ?? ''));
        if ($dateCompare !== 0) return $dateCompare;
        return ((int)($a['transaction_id'] ?? 0)) <=> ((int)($b['transaction_id'] ?? 0));
    });
}

// Stat card computation.
// Liability and Equity accounts are credit-normal, while the stored balance column
// is updated using debit-minus-credit movement in several transaction modules.
// To keep the dashboard equation correct, convert Liabilities and Equity to their
// normal balance first, then compute Assets using:
// Total Assets = Total Liabilities + Total Equity.
function accountNormalBalanceMultiplier($account_type) {
    $type = strtolower(trim((string)$account_type));
    $credit_normal_types = [
        'accounts payable',
        'credit card',
        'other current liability',
        'long term liability',
        'equity'
    ];
    return in_array($type, $credit_normal_types, true) ? -1 : 1;
}

$total_assets = 0;
$total_liabilities = 0;
$total_equity = 0;
$active_count = 0;
$asset_account_types = ['Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset'];
$liability_account_types = ['Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability'];
$equity_account_types = ['Equity'];

foreach ($accounts as $account) {
    $account_status = $account['status'] ?? 'active';
    $account_type_for_total = trim((string)($account['account_type'] ?? ''));
    $account_balance_for_total = (float)($account['balance'] ?? 0);
    $normal_balance_for_total = $account_balance_for_total * accountNormalBalanceMultiplier($account_type_for_total);

    if ($account_status === 'active') {
        $active_count++;
    }

    if (in_array($account_type_for_total, $liability_account_types, true)) {
        $total_liabilities += $normal_balance_for_total;
    } elseif (in_array($account_type_for_total, $equity_account_types, true)) {
        $total_equity += $normal_balance_for_total;
    }
}

$total_assets = $total_liabilities + $total_equity;

$account_label_map = [];
foreach ($accounts as $account_map_row) {
    $account_label_map[(int)$account_map_row['account_id']] = trim(($account_map_row['account_code'] ? $account_map_row['account_code'] . ' · ' : '') . $account_map_row['account_title']);
}

$branches = [];
if ($view_all_branches) {
    $branch_result = $conn->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name ASC");
    if ($branch_result) while ($row = $branch_result->fetch_assoc()) $branches[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chart of Accounts - AMGC</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root{
            --primary:#052A47;
            --green:#047857;
            --accent:#44D34E;
            --soft:#f5f7fb;
            --line:#dbe7f3;
            --text:#0f172a;
            --muted:#64748b;
        }
        body, button, input, select, textarea, .table, .form-control, .form-select, .btn{
            font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif !important;
        }
        .page-title h2{
            color:#052A47;
            font-weight:700;
            letter-spacing:-.02em;
        }
        .page-title p{
            color:#64748b;
            font-size:.94rem;
            margin:0;
        }
        .page-header-card{
            background:linear-gradient(135deg,#052A47 0%,#047857 100%);
            color:#fff;
            border-radius:18px;
            padding:1.25rem 1.5rem;
            box-shadow:0 10px 25px rgba(5,42,71,.12);
            margin-bottom:1rem;
        }
        .page-header-card h4{
            font-weight:700;
            letter-spacing:-.01em;
        }
        .page-header-card p{
            margin:0;
            opacity:.9;
            font-size:.94rem;
        }
        .content-card{
            background:#fff;
            border-radius:18px;
            border:1px solid rgba(68,211,78,.14);
            box-shadow:0 8px 20px rgba(15,23,42,.06);
            overflow:hidden;
        }
        .card-toolbar{
            padding:1rem;
            border-bottom:1px solid #eef2f7;
            background:#fff;
        }
        .btn-green{
            background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
            color:#fff;
            border:none;
            border-radius:999px;
            padding:.65rem 1rem;
            font-weight:600;
            font-size:14px;
            box-shadow:0 4px 10px rgba(0,0,0,.12);
        }
        .btn-green:hover{
            color:#fff;
            opacity:.95;
            transform:translateY(-1px);
        }
        .btn-outline-green{
            border:1px solid #047857;
            color:#047857;
            border-radius:999px;
            font-weight:600;
        }
        .btn-outline-green:hover{
            background:#047857;
            color:#fff;
        }
        .account-modal-footer{
            gap:.5rem;
            flex-wrap:wrap;
        }
        .account-modal-footer .btn{
            border-radius:999px;
            font-weight:600;
        }
        .stat-card{
            background:linear-gradient(135deg,#047857,#059669) !important;
            border:none !important;
            border-radius:20px;
            padding:1rem;
            box-shadow:0 4px 10px rgba(0,0,0,.08) !important;
            color:#fff;
            min-height:120px;
        }
        .stat-label{
            color:#fff;
            font-size:.88rem;
        }
        .stat-value{
            font-size:1.45rem;
            font-weight:700;
            color:#fff;
        }
        .stat-icon{
            width:48px;
            height:48px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            font-size:1.25rem;
        }
        .qb-table{
            margin:0;
            border-collapse:separate;
            border-spacing:0;
        }
        .qb-table thead th{
            background-color:#f8f9fa;
            color:#052A47;
            border-bottom:2px solid #dee2e6;
            white-space:nowrap;
            font-weight:600;
            font-size:.82rem;
            letter-spacing:.01em;
        }
        .qb-table tbody td{
            vertical-align:middle;
            font-size:.92rem;
            border-bottom:1px solid #edf2f7;
            padding:.62rem .75rem;
        }
        .qb-table tbody tr:nth-child(even){
            background:#f8fbff;
        }
        .qb-table tbody tr:nth-child(odd){
            background:#fff;
        }
        .qb-table tbody tr.account-parent{
            background:#e8f5e9;
            font-weight:700;
        }
        .qb-table tbody tr.account-parent td{
            border-bottom:1px solid #d8eadc;
        }
        .qb-table tbody tr:hover{
            background:#f5f5f5;
        }
        .clickable-account-row{
            cursor:pointer;
        }
        .clickable-account-row:hover .account-title{
            color:#047857;
        }
        .account-details-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:1rem;
        }
        .account-detail-item{
            border:1px solid #e5edf5;
            border-radius:14px;
            padding:.85rem;
            background:#f8fbff;
        }
        .account-detail-label{
            font-size:.78rem;
            font-weight:700;
            color:#64748b;
            text-transform:uppercase;
            letter-spacing:.03em;
            margin-bottom:.25rem;
        }
        .account-detail-value{
            color:#0f172a;
            font-weight:600;
            word-break:break-word;
        }
        .account-detail-description{
            white-space:pre-wrap;
            line-height:1.5;
            font-weight:500;
        }

        .account-full-details-list{
            background:#fff;
            border:1px solid #e5edf5;
            border-radius:14px;
            overflow:hidden;
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
        }
        .account-full-detail-row{
            display:flex;
            align-items:flex-start;
            gap:.55rem;
            padding:.68rem .85rem;
            border-bottom:1px solid #eef2f7;
            border-right:1px solid #eef2f7;
            color:#111827;
            font-size:.9rem;
            line-height:1.35;
            min-height:46px;
        }
        .account-full-detail-row:nth-child(2n){
            border-right:0;
        }
        .account-full-detail-row:nth-last-child(-n+2){
            border-bottom:0;
        }
        .account-full-detail-row.detail-full-width{
            grid-column:1 / -1;
            border-right:0;
        }
        .account-full-detail-row strong{
            min-width:135px;
            color:#052A47;
            font-weight:700;
            white-space:nowrap;
        }
        .account-full-detail-row span{
            flex:1;
            color:#111827;
            font-weight:500;
            word-break:break-word;
        }
        .account-full-detail-row.description-row span{
            white-space:pre-wrap;
        }
        @media(max-width:768px){
            .account-full-details-list{
                grid-template-columns:1fr;
            }
            .account-full-detail-row{
                border-right:0;
            }
            .account-full-detail-row:nth-last-child(-n+2){
                border-bottom:1px solid #eef2f7;
            }
            .account-full-detail-row:last-child{
                border-bottom:0;
            }
        }
        @media(max-width:576px){
            .account-full-detail-row{
                flex-direction:column;
                gap:.25rem;
            }
            .account-full-detail-row strong{
                min-width:0;
                white-space:normal;
            }
        }


        .details-modal-footer{
            justify-content:space-between;
            gap:.75rem;
            background:#f8fbff;
            border-top:1px solid #e5edf5;
            padding:1rem 1.25rem;
        }
        .details-action-group{
            display:flex;
            align-items:center;
            gap:.65rem;
            flex-wrap:wrap;
            width:100%;
        }
        .details-action-btn{
            min-width:145px;
            height:42px;
            border-radius:999px;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:.35rem;
            box-shadow:0 4px 10px rgba(15,23,42,.08);
        }
        .details-action-btn.quick-report{
            background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
            border:0;
            color:#fff;
            margin-right:auto;
        }
        .details-action-btn.quick-report:hover{
            color:#fff;
            opacity:.95;
            transform:translateY(-1px);
        }
        .details-action-btn.edit-account{
            background:#052A47;
            border:1px solid #052A47;
            color:#fff;
        }
        .details-action-btn.edit-account:hover{
            background:#0b3c63;
            border-color:#0b3c63;
            color:#fff;
        }
        .details-action-btn.delete-account{
            background:#dc2626;
            border:1px solid #dc2626;
            color:#fff;
        }
        .details-action-btn.delete-account:hover{
            background:#b91c1c;
            border-color:#b91c1c;
            color:#fff;
        }
        @media(max-width:576px){
            .details-action-group{
                flex-direction:column;
                align-items:stretch;
            }
            .details-action-btn{
                width:100%;
                min-width:0;
            }
            .details-action-btn.quick-report{
                margin-right:0;
            }
        }

        .quick-report-box{
            border:1px solid #9aa9b5;
            border-radius:4px;
            overflow:hidden;
            background:#fff;
            color:#111827;
            font-family:Arial, Helvetica, sans-serif!important;
        }
        .quick-report-topbar{
            display:flex;
            align-items:center;
            gap:.55rem;
            flex-wrap:wrap;
            padding:.45rem .55rem;
            background:linear-gradient(#f7f7f7,#d8dde2);
            border-bottom:1px solid #aeb8c2;
        }
        .quick-report-tool-btn{
            border:1px solid #b7b7b7;
            background:linear-gradient(#ffffff,#e9e9e9);
            color:#111827;
            border-radius:3px;
            padding:.25rem .65rem;
            font-size:.78rem;
            font-weight:700;
            line-height:1.2;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.75);
        }

        .quick-report-customize-panel{
            display:none;
            padding:.85rem 1rem;
            background:#f8fbff;
            border-bottom:1px solid #c7cdd3;
            color:#111827;
            font-size:.82rem;
        }
        .quick-report-customize-panel.show{
            display:block;
        }
        .quick-report-customize-title{
            font-weight:800;
            color:#052A47;
            margin-bottom:.65rem;
        }
        .quick-report-customize-grid{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:.75rem;
        }
        .quick-report-customize-card{
            border:1px solid #d8e2ee;
            background:#fff;
            border-radius:8px;
            padding:.75rem;
        }
        .quick-report-customize-card h6{
            font-size:.78rem;
            font-weight:800;
            color:#052A47;
            text-transform:uppercase;
            letter-spacing:.03em;
            margin:0 0 .55rem;
        }
        .quick-report-checkline{
            display:flex;
            align-items:center;
            gap:.45rem;
            margin:.28rem 0;
            font-weight:600;
            color:#334155;
        }
        .quick-report-customize-actions{
            display:flex;
            justify-content:flex-end;
            gap:.5rem;
            margin-top:.75rem;
            flex-wrap:wrap;
        }
        .quick-report-mini-btn{
            border:1px solid #b7b7b7;
            background:linear-gradient(#ffffff,#e9e9e9);
            color:#111827;
            border-radius:4px;
            padding:.35rem .75rem;
            font-size:.78rem;
            font-weight:700;
        }
        .quick-report-mini-btn.primary{
            background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
            border:0;
            color:#fff;
        }
        @media(max-width:768px){
            .quick-report-customize-grid{
                grid-template-columns:1fr;
            }
        }

        .quick-report-filterbar{
            display:flex;
            align-items:center;
            gap:.4rem;
            flex-wrap:wrap;
            padding:.45rem .55rem;
            background:linear-gradient(#f8f8f8,#eceff3);
            border-bottom:1px solid #c7cdd3;
            color:#111827;
            font-size:.8rem;
        }
        .quick-report-filterbar label{
            margin:0;
            color:#111827;
            font-weight:700;
        }
        .quick-report-filterbox{
            min-width:110px;
            border:1px solid #b9c1ca;
            border-radius:3px;
            background:#fff;
            padding:.15rem .45rem;
            font-size:.78rem;
            height:26px;
            display:inline-flex;
            align-items:center;
        }
        .quick-report-linkline{
            padding:.55rem .75rem;
            color:#000080;
            font-size:.82rem;
            font-weight:700;
            border-bottom:1px solid #eef0f3;
        }
        .quick-report-body{
            padding:1.15rem 1.6rem 1.35rem;
            background:#fff;
        }
        .quick-report-title-wrap{
            display:grid;
            grid-template-columns:130px 1fr 130px;
            align-items:start;
            margin-bottom:.75rem;
        }
        .quick-report-left-stamp{
            color:#000080;
            font-weight:700;
            font-size:.82rem;
            line-height:1.6;
        }
        .quick-report-heading{
            text-align:center;
            color:#000080;
            line-height:1.15;
        }
        .quick-report-company{
            font-size:1rem;
            font-weight:800;
        }
        .quick-report-title{
            font-size:1.15rem;
            font-weight:800;
        }
        .quick-report-subtitle{
            color:#000080;
            font-size:.82rem;
            font-weight:700;
            margin-top:.15rem;
        }
        .quick-report-table{
            width:100%;
            border-collapse:collapse;
            color:#111827;
            font-size:.8rem;
        }
        .quick-report-table th{
            background:#f1f1f1;
            color:#111827;
            border-right:1px dotted #b9b9b9;
            border-bottom:1px solid #b9b9b9;
            padding:.25rem .45rem;
            font-weight:800;
            text-align:left;
            white-space:nowrap;
        }
        .quick-report-table th:last-child{
            border-right:0;
        }
        .quick-report-table td{
            border-right:1px dotted #d1d5db;
            border-bottom:1px solid #edf0f3;
            padding:.28rem .45rem;
            vertical-align:middle;
            white-space:nowrap;
        }
        .quick-report-table td:last-child{
            border-right:0;
        }
        .quick-report-table .group-row td{
            background:#e5e5e5;
            color:#111827;
            font-weight:800;
            border-bottom:1px solid #d2d2d2;
        }
        .quick-report-table .transaction-row{
            cursor:pointer;
        }
        .quick-report-table .transaction-row:hover td{
            background:#fffbea;
        }
        .quick-report-table .selected-transaction td{
            border-top:0;
            border-bottom:1px solid #edf0f3;
            background:#fff;
        }
        .quick-report-table .selected-transaction td:first-child{
            border-left:0;
        }
        .quick-report-table .selected-transaction td:last-child{
            border-right:0;
        }
        .quick-report-table .amount-cell{
            text-align:right;
            font-variant-numeric:tabular-nums;
            font-weight:400;
        }
        .quick-report-table .total-row td{
            border-top:2px solid #111827;
            background:#f8fafc;
            font-weight:600;
        }
        .quick-report-table .total-row .amount-cell{
            font-weight:400;
        }
        .quick-report-description{
            max-width:260px;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .quick-report-footer{
            display:flex;
            justify-content:space-between;
            gap:1rem;
            padding-top:.75rem;
            margin-top:.75rem;
            border-top:1px solid #d6dbe1;
            color:#64748b;
            font-size:.76rem;
        }
        .quick-report-empty-note{
            margin-top:.75rem;
            color:#64748b;
            font-size:.78rem;
        }

        .quick-report-filterbox{
            min-height:26px;
        }
        select.quick-report-filterbox,
        input.quick-report-filterbox{
            border:1px solid #c9d1d9;
            border-radius:3px;
            background:#fff;
            color:#111827;
            font-size:.78rem;
            padding:.16rem .35rem;
            min-width:104px;
        }
        .quick-report-linkline{
            border:0;
            background:transparent;
            display:block;
            width:max-content;
            cursor:pointer;
        }
        .quick-report-filter-panel{
            padding:.7rem 1rem;
            border-top:1px solid #d8e2ee;
            border-bottom:1px solid #d8e2ee;
            background:#f8fbff;
            color:#111827;
            font-size:.82rem;
            line-height:1.55;
        }
        @media(max-width:768px){
            .quick-report-body{
                padding:.85rem;
            }
            .quick-report-title-wrap{
                grid-template-columns:1fr;
                gap:.35rem;
            }
            .quick-report-left-stamp{
                text-align:left;
            }
            .quick-report-table{
                min-width:850px;
            }
        }
        @media print{
            @page{
                size:A4 portrait;
                margin:12mm;
            }
            body.quick-report-printing{
                background:#fff!important;
            }
            body.quick-report-printing .sidebar,
            body.quick-report-printing .main-content,
            body.quick-report-printing .modal,
            body.quick-report-printing .modal-backdrop{
                display:none!important;
            }
            body.quick-report-printing #quickReportPrintArea{
                display:block!important;
                position:static!important;
                width:100%!important;
                padding:0!important;
                margin:0!important;
                color:#000!important;
                background:#fff!important;
                font-family:Arial, Helvetica, sans-serif!important;
                font-size:10.5px!important;
            }
            body.quick-report-printing #quickReportPrintArea *{
                box-shadow:none!important;
                text-shadow:none!important;
                -webkit-print-color-adjust:exact!important;
                print-color-adjust:exact!important;
            }
            .print-report{
                width:100%;
                color:#000;
                background:#fff;
            }
            .print-report-header{
                text-align:center;
                margin-bottom:10px;
                line-height:1.25;
            }
            .print-company{
                font-size:14px;
                font-weight:700;
                text-transform:uppercase;
            }
            .print-title{
                font-size:13px;
                font-weight:700;
            }
            .print-subtitle{
                font-size:10.5px;
            }
            .print-report-info{
                width:100%;
                border-collapse:collapse;
                margin:8px 0 10px;
            }
            .print-report-info td{
                border:0;
                padding:2px 4px;
                font-size:10.5px;
            }
            .print-report-info .print-label{
                font-weight:700;
                width:82px;
                white-space:nowrap;
            }
            .print-ledger-table{
                width:100%;
                border-collapse:collapse;
                table-layout:fixed;
            }
            .print-ledger-table thead{
                display:table-header-group;
            }
            .print-ledger-table tfoot{
                display:table-footer-group;
            }
            .print-ledger-table th,
            .print-ledger-table td{
                border:1px solid #000;
                padding:4px 5px;
                font-size:9.5px;
                vertical-align:top;
                color:#000;
                background:#fff;
                word-wrap:break-word;
            }
            .print-ledger-table th{
                font-weight:700;
                text-align:left;
            }
            .print-ledger-table .amount{
                text-align:right;
                white-space:nowrap;
                font-variant-numeric:tabular-nums;
            }
            .print-ledger-table .group-row td{
                font-weight:700;
                background:#f2f2f2!important;
            }
            .print-ledger-table .total-row td{
                font-weight:700;
                border-top:2px solid #000;
            }
            .print-note{
                margin-top:8px;
                font-size:9.5px;
                line-height:1.35;
            }
            .print-report-footer{
                display:flex;
                justify-content:space-between;
                gap:12px;
                border-top:1px solid #000;
                margin-top:10px;
                padding-top:5px;
                font-size:9.5px;
            }
        }
        .qb-table tbody tr.hidden-sub-account{
            display:none;
        }
        .account-name-cell{
            display:flex;
            align-items:center;
            gap:.4rem;
            min-width:350px;
        }
        .account-indent{
            display:inline-block;
            width:calc(var(--level) * 24px);
        }
        .tree-toggle{
            width:28px;
            height:28px;
            border:0;
            border-radius:50%;
            background:rgba(4,120,87,.12);
            color:#047857;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            flex:0 0 28px;
            transition:all .2s ease;
        }
        .tree-toggle:hover{
            background:#047857;
            color:#fff;
        }
        .tree-icon{
            font-size:.8rem;
            color:#052A47;
            width:14px;
            text-align:center;
            display:inline-block;
        }
        .account-title{
            font-weight:700;
            color:#111827;
        }
        .sub-account .account-title{
            font-weight:600;
            color:#374151;
        }
        .sub-account .account-title:before{
            color:#64748b;
            margin-right:.35rem;
        }
        .balance-text{
            font-variant-numeric:tabular-nums;
            font-weight:700;
            white-space:nowrap;
        }
        .negative{
            color:#dc2626;
        }
        .badge-type{
            background:#edf8ef;
            color:#047857;
            border:1px solid rgba(4,120,87,.2);
            font-weight:700;
        }
        .modal-content{
            border:0;
            border-radius:18px;
            overflow:hidden;
        }
        .modal-header{
            background:#047857;
            color:#fff;
        }
        .form-label{
            font-weight:700;
            color:#052A47;
        }
        .form-control,.form-select{
            border-radius:10px;
            border:1px solid #d7e3ef;
            min-height:44px;
        }
        .form-control:focus,.form-select:focus{
            border-color:#44D34E;
            box-shadow:0 0 0 .2rem rgba(68,211,78,.12);
        }
        .actions .btn{
            border-radius:10px;
        }
        .empty-state{
            padding:3rem;
            text-align:center;
            color:#64748b;
        }
        .no-spinner input[type=number]::-webkit-outer-spin-button,.no-spinner input[type=number]::-webkit-inner-spin-button{
            -webkit-appearance:none;
            margin:0;
        }
        .no-spinner input[type=number]{
            -moz-appearance:textfield;
        }
        @media(max-width:992px){
            .main-content{
                margin-left:0!important
            }
            .mobile-menu-btn{
                display:block;
            }
        }
        @media(max-width:768px){
            .account-name-cell{
                min-width:240px
            }.page-header-card{
                padding:1rem
            }.stat-value{
                font-size:1.15rem
            }.card-toolbar .row{
                gap:.5rem
            }.table-responsive{
                overflow-x:auto;
            }
        }
    

        /* Quick report modal clean format */
        #quickReportModal .modal-dialog{
            max-width:min(1280px, 96vw);
        }
        #quickReportModal .modal-content{
            max-height:92vh;
            display:flex;
            flex-direction:column;
        }
        #quickReportModal .modal-body{
            max-height:calc(92vh - 64px);
            overflow:auto;
            padding:1rem;
        }
        #quickReportModal .table-responsive{
            max-height:none !important;
            overflow:visible !important;
        }
        #quickReportModal .quick-report-table thead th{
            position:static !important;
        }
        .quick-report-account-header{
            margin:.55rem 0 .85rem;
            padding:.65rem .8rem;
            border:1px solid #dbe7f3;
            background:#f8fbff;
            border-radius:8px;
            color:#052A47;
            font-size:.86rem;
            font-weight:700;
        }
        .quick-report-account-header span{
            color:#111827;
            font-weight:600;
        }

    </style>
</head>
<body>
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
                                <a class="nav-link" href="customer_list.php">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="nav-text">Customer List</span>
                                </a>
                            </li>

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
                                <a class="nav-link" href="Withdrawal.php">
                                    <i class="bi bi-journal-check"></i>
                                    <span class="nav-text">Write Checks</span>
                                </a>
                            </li>

                            <li class="nav-item">
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
                                <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link active" href="chartofaccounts.php">
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
        <div class="navbar-top no-print">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title"><h2>Chart of Accounts</h2><p>Manage account titles, account types, sub accounts, and balances</p></div>
        </div>


        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="stat-card d-flex justify-content-between align-items-center"><div><div class="stat-label">Total Assets</div>
            <div class="stat-value">₱<?php echo number_format($total_assets, 2); ?></div></div><div class="stat-icon"><i class="bi bi-bank2"></i></div></div></div>
            <div class="col-md-4"><div class="stat-card d-flex justify-content-between align-items-center"><div><div class="stat-label">Total Liabilities</div>
            <div class="stat-value">₱<?php echo number_format($total_liabilities, 2); ?></div></div><div class="stat-icon"><i class="bi bi-receipt"></i></div></div></div>
            <div class="col-md-4"><div class="stat-card d-flex justify-content-between align-items-center"><div><div class="stat-label">Total Equity</div>
            <div class="stat-value">₱<?php echo number_format($total_equity, 2); ?></div></div><div class="stat-icon"><i class="bi bi-pie-chart"></i></div></div></div>
        </div>

        <div class="content-card">
            <div class="card-toolbar">
                <form method="GET" id="accountFilterForm" class="row g-2 align-items-end">
                    <div class="col-md-5"><label class="form-label mb-1">Search</label>
                    <input type="text" name="search" value="<?php echo h($search); ?>" class="form-control" placeholder="Search account title, code, or type"></div>
                    <div class="col-md-4"><label class="form-label mb-1">Account Type</label>
                    <select name="type" id="accountTypeFilter" class="form-select"><option value="">All Types</option><?php foreach ($account_types as $type): ?>
                    <option value="<?php echo h($type); ?>" <?php echo $type_filter === $type ? 'selected' : ''; ?>><?php echo h($type); ?>
                    </option><?php endforeach; ?></select></div>
                    <div class="col-md-3 d-flex gap-2"><button class="btn btn-green flex-fill" type="button" data-bs-toggle="modal" data-bs-target="#accountModal" 
                    onclick="openAddModal()"><i class="bi bi-plus-circle me-1"></i>Add Account</button>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table qb-table align-middle">
                    <thead><tr><th>Account Title</th><th>Account Type</th><th class="text-end">Current Balance</th></tr></thead>
                    <tbody>
                    <?php if (empty($ordered_accounts)): ?>
                        <tr><td colspan="3"><div class="empty-state"><i class="bi bi-journal-x fs-1 d-block mb-2"></i>No account found.</div></td></tr>
                    <?php else: foreach ($ordered_accounts as $account):
                        $level = (int)($account['_level'] ?? 0);
                        $is_parent = !empty($account['_has_children']);
                        $row_class = $is_parent ? 'account-parent' : ($level > 0 ? 'sub-account' : '');
                        $parent_id = (int)($account['parent_account_id'] ?? 0);
                    ?>
                        <?php
                            $own_balance = (float)$account['balance'];
                            $total_account_balance = (float)($account_totals[(int)$account['account_id']] ?? $own_balance);
                            $detail_account = $account;
                            if (($detail_account['account_type'] ?? '') === 'Bank' && empty($detail_account['as_of_date']) && !empty($detail_account['created_at'])) {
                                $detail_account['as_of_date'] = date('Y-m-d', strtotime($detail_account['created_at']));
                            }
                            $detail_account['_parent_display'] = $parent_id > 0 ? ($account_label_map[$parent_id] ?? 'Parent account not found') : 'Main account';
                            $detail_account['_own_balance_display'] = number_format($own_balance, 2);
                            $detail_account['_total_balance_display'] = number_format($total_account_balance, 2);
                            $detail_account['_level_label'] = $level > 0 ? 'Sub Account Level ' . $level : 'Main Account';
                            $transaction_count = (int)($account_transaction_counts[(int)$account['account_id']] ?? 0);
                            $detail_account['_transaction_count'] = $transaction_count;
                            $detail_account['_has_transactions'] = $transaction_count > 0 ? 1 : 0;
                            $qr_transactions = $quick_report_transactions[(int)$account['account_id']] ?? [];
                            $detail_account['_qr_transactions'] = [];
                            foreach ($qr_transactions as $qr_transaction) {
                                $detail_account['_qr_transactions'][] = [
                                    'transaction_date' => $qr_transaction['transaction_date'] ?? '',
                                    'transaction_type' => $qr_transaction['transaction_type'] ?? '',
                                    'reference_no' => $qr_transaction['report_reference_no'] ?? '',
                                    'memo' => $qr_transaction['report_memo'] ?? '',
                                    'address' => $qr_transaction['report_address'] ?? '',
                                    'counterparty' => $qr_transaction['report_counterparty'] ?? '',
                                    'customer_group' => $qr_transaction['report_customer_group'] ?? '',
                                    'debit' => number_format((float)($qr_transaction['debit'] ?? 0), 2, '.', ''),
                                    'credit' => number_format((float)($qr_transaction['credit'] ?? 0), 2, '.', ''),
                                    'balance' => number_format((float)($qr_transaction['balance_after'] ?? 0), 2, '.', ''),
                                    'transaction_id' => (int)($qr_transaction['transaction_id'] ?? 0),
                                    'source_table' => $qr_transaction['source_table'] ?? '',
                                    'source_id' => (int)($qr_transaction['source_id'] ?? 0),
                                    'po_id' => (int)($qr_transaction['po_id'] ?? 0),
                                    'po_number' => $qr_transaction['po_number'] ?? ''
                                ];
                            }
                            if (!empty($detail_account['_qr_transactions'])) {
                                $latest_qr_transaction = end($detail_account['_qr_transactions']);
                                $detail_account['_qr_transaction_date'] = $latest_qr_transaction['transaction_date'] ?? '';
                                $detail_account['_qr_transaction_type'] = $latest_qr_transaction['transaction_type'] ?? '';
                                $detail_account['_qr_reference_no'] = $latest_qr_transaction['reference_no'] ?? '';
                                $detail_account['_qr_memo'] = $latest_qr_transaction['memo'] ?? '';
                                $detail_account['_qr_address'] = $latest_qr_transaction['address'] ?? '';
                                $detail_account['_qr_counterparty'] = $latest_qr_transaction['counterparty'] ?? '';
                                $detail_account['_qr_customer_group'] = $latest_qr_transaction['customer_group'] ?? '';
                                $detail_account['_qr_debit'] = $latest_qr_transaction['debit'] ?? '0.00';
                                $detail_account['_qr_credit'] = $latest_qr_transaction['credit'] ?? '0.00';
                                $detail_account['_qr_balance'] = $latest_qr_transaction['balance'] ?? '0.00';
                                $detail_account['_qr_po_id'] = $latest_qr_transaction['po_id'] ?? 0;
                                $detail_account['_qr_po_number'] = $latest_qr_transaction['po_number'] ?? '';
                            }

                        ?>
                        <tr class="<?php echo trim($row_class . ' clickable-account-row'); ?>" data-account-id="<?php echo (int)$account['account_id']; ?>" data-parent-id="<?php echo $parent_id; ?>" data-level="<?php echo $level; ?>" data-has-children="<?php echo $is_parent ? '1' : '0'; ?>" data-own-balance="<?php echo h(number_format($own_balance, 2, '.', '')); ?>" data-total-balance="<?php echo h(number_format($total_account_balance, 2, '.', '')); ?>" onclick='openDetailsModal(<?php echo json_encode($detail_account, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                            <td>
                                <div class="account-name-cell" style="--level:<?php echo $level; ?>">
                                    <span class="account-indent"></span>
                                    <?php if ($is_parent): ?>
                                        <button type="button" class="tree-toggle no-print" data-account-toggle="<?php echo (int)$account['account_id']; ?>" title="Hide/Show sub accounts" aria-label="Hide or show sub accounts"><i class="bi bi-chevron-down"></i></button>
                                    <?php else: ?>
                                        <span class="tree-icon"><?php echo $level > 0 ? '•' : ''; ?></span>
                                    <?php endif; ?>
                                    <span class="account-title"><?php echo h($account['account_code'] ? $account['account_code'] . ' · ' : ''); ?><?php echo h($account['account_title']); ?></span>
                                    <?php if (($account['status'] ?? 'active') === 'inactive'): ?><span class="badge bg-secondary ms-2">Inactive</span><?php endif; ?>
                                </div>
                            </td>
                            <td><span class="badge badge-type"><?php echo h($account['account_type']); ?></span></td>
                            <td class="text-end balance-text <?php echo $own_balance < 0 ? 'negative' : ''; ?>" data-balance-display><?php echo number_format($own_balance, 2); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content no-spinner">
            <form method="POST" id="accountForm">
                <input type="hidden" name="action" id="formAction" value="add_account"><input type="hidden" name="account_id" id="accountId"><input type="hidden" name="save_mode" id="saveMode" value="close">
                <div class="modal-header"><h5 class="modal-title" id="accountModalTitle"><i class="bi bi-plus-circle me-2"></i>Add Account</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Account Code</label><input type="text" name="account_code" id="accountCode" class="form-control" placeholder="Example: 10100"></div>
                        <div class="col-md-8"><label class="form-label">Account Title <span class="text-danger">*</span></label><input type="text" name="account_title" id="accountTitle" class="form-control" required placeholder="Example: Checking"></div>
                        <div class="col-md-6"><label class="form-label">Account Type <span class="text-danger">*</span></label><select name="account_type" id="accountType" class="form-select" required><option value="">Select account type</option><?php foreach ($account_types as $type): ?><option value="<?php echo h($type); ?>"><?php echo h($type); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Parent Account</label><select name="parent_account_id" id="parentAccountId" class="form-select"><option value="">Main account</option><?php foreach ($parent_account_options as $parent): ?><option value="<?php echo (int)$parent['account_id']; ?>" data-account-type="<?php echo h($parent['account_type']); ?>" data-level="<?php echo (int)($parent['_level'] ?? 0); ?>"><?php echo h(formatParentAccountOptionLabel($parent)); ?></option><?php endforeach; ?></select><small class="text-muted">Only accounts with the same account type can be selected as parent.</small></div>
                        <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="accountDescription" class="form-control" rows="3" placeholder="Enter account description or notes"></textarea></div>
                        <div class="col-md-6 bank-only-field" style="display:none;"><label class="form-label">Bank/Branch Location <span class="text-muted">(Optional)</span></label><input type="text" name="bank_branch" id="bankBranch" class="form-control" placeholder="BDO/LEMERY"></div>
                        <div class="col-md-6 bank-only-field" style="display:none;"><label class="form-label">Account Number <span class="text-muted">(Optional)</span></label><input type="text" name="account_number" id="accountNumber" class="form-control" placeholder="Enter bank account number"></div>
                        <div class="col-md-6"><label class="form-label" id="balanceLabel">Balance</label><input type="number" step="0.01" name="balance" id="accountBalance" class="form-control" value="0.00"></div>
                        <div class="col-md-6 bank-only-field" style="display:none;"><label class="form-label">As of Date</label><input type="date" name="as_of_date" id="asOfDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                        <?php if ($view_all_branches): ?><div class="col-md-6"><label class="form-label">Branch</label><select name="branch_id" id="branchId" class="form-select"><option value="">No branch / All</option><?php foreach ($branches as $branch): ?><option value="<?php echo (int)$branch['branch_id']; ?>"><?php echo h($branch['branch_name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
                        <div class="col-md-6" id="statusWrap" style="display:none;"><label class="form-label">Status</label><select name="status" id="accountStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    </div>
                </div>
                <div class="modal-footer account-modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-outline-green add-save-btn" id="saveNewBtn" data-save-mode="new"><i class="bi bi-plus-circle me-1"></i>Save &amp; New</button>
                    <button type="submit" class="btn btn-green" id="saveCloseBtn" data-save-mode="close"><i class="bi bi-save me-1"></i>Save &amp; Close</button>
                </div>
            </form>
        </div></div>
    </div>

    <div class="modal fade" id="accountDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-journal-text me-2"></i>Account Full Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="account-full-details-list">
                    <div class="account-full-detail-row"><strong>Account Code:</strong><span id="detailAccountCode">-</span></div>
                    <div class="account-full-detail-row"><strong>Account Title:</strong><span id="detailAccountTitle">-</span></div>
                    <div class="account-full-detail-row"><strong>Account Type:</strong><span id="detailAccountType">-</span></div>
                    <div class="account-full-detail-row"><strong>Parent Account:</strong><span id="detailParentAccount">-</span></div>
                    <div class="account-full-detail-row"><strong>Account Level:</strong><span id="detailAccountLevel">-</span></div>
                    <div class="account-full-detail-row"><strong>Status:</strong><span id="detailAccountStatus">-</span></div>
                    <div class="account-full-detail-row detail-bank-row" style="display:none;"><strong>Bank/Branch Location:</strong><span id="detailBankBranch">-</span></div>
                    <div class="account-full-detail-row detail-bank-row" style="display:none;"><strong>Account Number:</strong><span id="detailAccountNumber">-</span></div>
                    <div class="account-full-detail-row"><strong id="detailOwnBalanceLabel">Balance:</strong><span id="detailOwnBalance">-</span></div>
                    <div class="account-full-detail-row detail-bank-row" style="display:none;"><strong>As of Date:</strong><span id="detailAsOfDate">-</span></div>
                    <div class="account-full-detail-row"><strong id="detailTotalBalanceLabel">Total Balance With Sub Accounts:</strong><span id="detailTotalBalance">-</span></div>
                    <div class="account-full-detail-row"><strong>Created At:</strong><span id="detailCreatedAt">-</span></div>
                    <div class="account-full-detail-row"><strong>Updated At:</strong><span id="detailUpdatedAt">-</span></div>
                    <div class="account-full-detail-row description-row detail-full-width"><strong>Description:</strong><span class="account-detail-description" id="detailDescription">-</span></div>
                </div>
            </div>
            <div class="modal-footer details-modal-footer">
                <form method="POST" id="detailsDeleteForm" class="m-0 d-none">
                    <input type="hidden" name="action" value="delete_account">
                    <input type="hidden" name="account_id" id="detailsDeleteAccountId" value="">
                </form>
                <div class="details-action-group">
                    <button type="button" class="btn details-action-btn quick-report" id="detailsQuickReportBtn" onclick="quickReportFromDetails()"><i class="bi bi-file-earmark-bar-graph"></i>Quick Report</button>
                    <button type="button" class="btn details-action-btn edit-account" id="detailsEditBtn" onclick="editFromDetails()"><i class="bi bi-pencil-square"></i>Edit Account</button>
                    <button type="button" class="btn details-action-btn delete-account" id="detailsDeleteBtn" onclick="deleteFromDetails()"><i class="bi bi-trash"></i>Delete Account</button>
                </div>
            </div>
        </div></div>
    </div>


    <div class="modal fade" id="quickReportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-bar-graph me-2"></i><span id="quickReportModalTitleText">Quick Report</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="quick-report-box" id="quickReportContent">
                    <div class="quick-report-topbar no-print">
                        <button type="button" class="quick-report-tool-btn" onclick="openQuickReportCustomize()">Customize Report</button>
                        <button type="button" class="quick-report-tool-btn" onclick="addQuickReportComment()">Comment on Report</button>
                        <button type="button" class="quick-report-tool-btn" onclick="memorizeQuickReport()">Memorize</button>
                        <button type="button" class="quick-report-tool-btn" onclick="printQuickReport()">Print</button>
                        <button type="button" class="quick-report-tool-btn" onclick="emailQuickReport()">E-mail</button>
                        <button type="button" class="quick-report-tool-btn" onclick="exportQuickReportExcel()">Excel</button>
                        <button type="button" class="quick-report-tool-btn" onclick="refreshQuickReport()">Refresh</button>
                    </div>

                    <div class="quick-report-customize-panel no-print" id="quickReportCustomizePanel">
                        <div class="quick-report-customize-title"><i class="bi bi-sliders me-1"></i>Customize Report</div>
                        <div class="quick-report-customize-grid">
                            <div class="quick-report-customize-card">
                                <h6>Columns</h6>
                                <label class="quick-report-checkline"><input type="checkbox" class="quick-report-column-check" value="date" checked> Date</label>
                                <label class="quick-report-checkline"><input type="checkbox" class="quick-report-column-check" value="type" checked> Type</label>
                                <label class="quick-report-checkline"><input type="checkbox" class="quick-report-column-check" value="txn" checked> Transaction No.</label>
                                <label class="quick-report-checkline"><input type="checkbox" class="quick-report-column-check" value="memo" checked> Memo</label>
                                <label class="quick-report-checkline"><input type="checkbox" class="quick-report-column-check" value="debit" checked> Debit</label>
                                <label class="quick-report-checkline"><input type="checkbox" class="quick-report-column-check" value="credit" checked> Credit</label>
                                <label class="quick-report-checkline"><input type="checkbox" class="quick-report-column-check" value="balance" checked> Balance</label>
                            </div>
                            <div class="quick-report-customize-card">
                                <h6>Dates</h6>
                                <div class="mb-2"><label class="form-label mb-1">Date Preset</label><select class="form-select form-select-sm" id="customizeDatePreset" onchange="syncCustomizeDateFields()"><option value="custom">Custom</option><option value="today">Today</option><option value="this_month">This Month</option><option value="this_year">This Year</option><option value="all">All</option></select></div>
                                <div class="row g-2"><div class="col-6"><label class="form-label mb-1">From</label><input type="date" class="form-control form-control-sm" id="customizeFromDate"></div><div class="col-6"><label class="form-label mb-1">To</label><input type="date" class="form-control form-control-sm" id="customizeToDate"></div></div>
                            </div>
                            <div class="quick-report-customize-card">
                                <h6>Display</h6>
                                <div class="mb-2"><label class="form-label mb-1">Sort By</label><select class="form-select form-select-sm" id="customizeSortBy"><option value="default">Default</option><option value="date_asc">Date</option><option value="date_desc">Date Descending</option><option value="amount_desc">Amount Highest First</option><option value="type">Type</option></select></div>
                                <label class="quick-report-checkline"><input type="checkbox" id="customizeShowHeader" checked> Show report header</label>
                                <label class="quick-report-checkline"><input type="checkbox" id="customizeShowNote" checked> Show report note</label>
                            </div>
                        </div>
                        <div class="quick-report-customize-actions">
                            <button type="button" class="quick-report-mini-btn" onclick="resetQuickReportCustomize()">Reset</button>
                            <button type="button" class="quick-report-mini-btn" onclick="closeQuickReportCustomize()">Cancel</button>
                            <button type="button" class="quick-report-mini-btn primary" onclick="applyQuickReportCustomize()">Apply</button>
                        </div>
                    </div>
                    <div class="quick-report-filterbar no-print">
                        <label for="quickReportDatePreset">Dates</label>
                        <select class="quick-report-filterbox" id="quickReportDatePreset" onchange="applyQuickReportDatePreset()">
                            <option value="custom">Custom</option>
                            <option value="today">Today</option>
                            <option value="this_month">This Month</option>
                            <option value="this_year">This Year</option>
                            <option value="all">All</option>
                        </select>
                        <label for="quickReportFromDate">From</label>
                        <input type="date" class="quick-report-filterbox" id="quickReportFromDate" onchange="updateQuickReportDateFilters()">
                        <label for="quickReportToDate">To</label>
                        <input type="date" class="quick-report-filterbox" id="quickReportToDate" onchange="updateQuickReportDateFilters()">
                        <label for="quickReportCustomerGroupFilter">Customer Group</label>
                        <select class="quick-report-filterbox" id="quickReportCustomerGroupFilter" onchange="updateQuickReportCounterpartyOptions(true)">
                            <option value="">All Groups</option>
                        </select>
                        <label for="quickReportCounterpartyFilter">Counterparty</label>
                        <select class="quick-report-filterbox" id="quickReportCounterpartyFilter" onchange="refreshQuickReport()">
                            <option value="">All Counterparties</option>
                        </select>
                        <label for="quickReportSortBy">Sort By</label>
                        <select class="quick-report-filterbox" id="quickReportSortBy" onchange="refreshQuickReport()">
                            <option value="default">Default</option>
                            <option value="date_asc">Date</option>
                            <option value="date_desc">Date Descending</option>
                            <option value="amount_desc">Amount Highest First</option>
                            <option value="type">Type</option>
                        </select>
                    </div>
                    <button type="button" class="quick-report-linkline no-print" onclick="toggleQuickReportFilters()" id="quickReportShowFiltersBtn">Show Filters</button>
                    <div class="quick-report-filter-panel no-print" id="quickReportFilterPanel" style="display:none;">
                        <div><strong>Account:</strong> <span id="filterPanelAccount">-</span></div>
                        <div><strong>Account Type:</strong> <span id="filterPanelType">-</span></div>
                        <div><strong>Status:</strong> <span id="filterPanelStatus">-</span></div>
                        <div><strong>Customer Group:</strong> <span id="filterPanelCustomerGroup">All Groups</span></div>
                        <div><strong>Counterparty:</strong> <span id="filterPanelCounterparty">All Counterparties</span></div>
                        <div><strong>Note:</strong> Date, customer group, and counterparty filters are applied to the account transactions shown below.</div>
                    </div>
                    <div class="quick-report-body">
                        <div class="quick-report-title-wrap">
                            <div class="quick-report-left-stamp">
                                <div id="reportGeneratedTime">-</div>
                                <div id="reportGeneratedDate">-</div>
                            </div>
                            <div class="quick-report-heading">
                                <div class="quick-report-company">AMGC</div>
                                <div class="quick-report-title" id="quickReportTitleText">Account Quick Report</div>
                                <div class="quick-report-subtitle"><span id="reportPeriodText">-</span></div>
                            </div>
                            <div></div>
                        </div>

                        <div class="quick-report-account-header">Account: <span id="quickReportAccountHeaderText">-</span></div>

                        <div class="table-responsive">
                            <table class="quick-report-table">
                                <thead>
                                    <tr>
                                        <th style="width:12%;" data-report-col="date">Date</th>
                                        <th style="width:13%;" data-report-col="type">Type</th>
                                        <th style="width:16%;" data-report-col="txn">Reference No.</th>
                                        <th style="width:32%;" data-report-col="memo">Memo</th>
                                        <th style="width:9%;" class="text-end" data-report-col="debit">Debit</th>
                                        <th style="width:9%;" class="text-end" data-report-col="credit">Credit</th>
                                        <th style="width:9%;" class="text-end" data-report-col="balance">Balance</th>
                                    </tr>
                                </thead>
                                <tbody id="quickReportTableBody">
                                    <tr class="group-row"><td colspan="7" id="reportAccountGroup">Account Title</td></tr>
                                    <tr class="transaction-row">
                                        <td data-report-col="date">-</td>
                                        <td data-report-col="type">-</td>
                                        <td data-report-col="txn">-</td>
                                        <td class="quick-report-description" data-report-col="memo">-</td>
                                        <td class="amount-cell" data-report-col="debit">0.00</td>
                                        <td class="amount-cell" data-report-col="credit">0.00</td>
                                        <td class="amount-cell" data-report-col="balance">0.00</td>
                                    </tr>
                                    <tr class="total-row">
                                        <td colspan="4" id="reportTotalLabelCell">Total <span id="reportTotalAccountLabel">Account</span></td>
                                        <td class="amount-cell" id="reportTotalDebit" data-report-col="debit">0.00</td>
                                        <td class="amount-cell" id="reportTotalCredit" data-report-col="credit">0.00</td>
                                        <td class="amount-cell" id="reportEndingBalance" data-report-col="balance">0.00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="quick-report-empty-note" id="quickReportSystemNote">Note: This quick report reads Chart of Accounts transactions. Purchase Orders and Deposits linked to this account will show their Date, Type, Reference No., Memo, Debit, Credit, and Balance.</div>
                        <div class="quick-report-empty-note" id="quickReportCommentBox" style="display:none;"></div>
                        <div class="quick-report-footer">
                            <div>Branch: <?php echo h($branch_name); ?> • Prepared by: <?php echo h($user_name); ?></div>
                            <div>Generated: <span id="reportFooterGeneratedAt">-</span></div>
                        </div>
                    </div>
                </div>            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-green" onclick="printQuickReport()"><i class="bi bi-printer me-1"></i>Print Report</button>
            </div>
        </div></div>
    </div>

    <div id="quickReportPrintArea" style="display:none;"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const flashSuccess = <?php echo json_encode($flash_success); ?>;
    const flashError = <?php echo json_encode($flash_error); ?>;
    const shouldOpenAddModal = <?php echo json_encode(($_GET['open_add'] ?? '') === '1'); ?>;
    let accountModal;
    let accountDetailsModal;
    let quickReportModal;
    let currentDetailsAccount = null;
    let quickReportPrintTransactions = [];
    const quickReportAccountTypeOrder = <?php echo json_encode($account_types); ?>;
    
    document.addEventListener('DOMContentLoaded', function(){
        accountModal = new bootstrap.Modal(document.getElementById('accountModal'));
        accountDetailsModal = new bootstrap.Modal(document.getElementById('accountDetailsModal'));
        quickReportModal = new bootstrap.Modal(document.getElementById('quickReportModal'));

        const accountForm = document.getElementById('accountForm');
        const saveModeInput = document.getElementById('saveMode');
        const saveNewBtn = document.getElementById('saveNewBtn');
        const saveCloseBtn = document.getElementById('saveCloseBtn');
        if (accountForm && saveModeInput) {
            [saveNewBtn, saveCloseBtn].forEach(btn => {
                if (!btn) return;
                btn.addEventListener('click', function(){
                    saveModeInput.value = this.dataset.saveMode || 'close';
                });
            });
        }
        
        if (flashSuccess) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: flashSuccess,
                confirmButtonColor: '#047857',
                timer: 1800,
                timerProgressBar: true
            });
        }
        
        if (flashError) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: flashError,
                confirmButtonColor: '#047857'
            });
        }

        if (shouldOpenAddModal) {
            setTimeout(function(){
                openAddModal();
                accountModal.show();
            }, 250);
        }
        
        // Initialize sidebar
        initializeSidebar();
        
        // Delete form confirmation
        document.querySelectorAll('.delete-form').forEach(form => {
            form.addEventListener('submit', function(e){
                e.preventDefault();
                Swal.fire({
                    title: 'Delete account?',
                    text: 'This cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Yes, delete it',
                    cancelButtonText: 'Cancel'
                }).then(result => { 
                    if(result.isConfirmed) form.submit(); 
                });
            });
        });

        // Account toggle functionality
        document.querySelectorAll('[data-account-toggle]').forEach(button => {
            button.addEventListener('click', function(e){
                e.preventDefault();
                e.stopPropagation();
                toggleSubAccounts(this.dataset.accountToggle, this);
            });
        });

        // Account type filter
        const accountTypeFilter = document.getElementById('accountTypeFilter');
        const accountFilterForm = document.getElementById('accountFilterForm');
        if (accountTypeFilter && accountFilterForm) {
            accountTypeFilter.addEventListener('change', function(){
                accountFilterForm.submit();
            });
        }
        
        // Prevent number input scroll
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('wheel', e => e.target.blur());
        });
    });

    // ========== SIDEBAR FUNCTIONS ==========
    
    // Helper function to set arrow state without layout shift
    function setArrowState(arrowElement, isOpen) {
        if (!arrowElement) return;
        // Use transform with preserve-3d to prevent layout shift
        if (isOpen) {
            arrowElement.style.transform = 'rotate(180deg)';
            arrowElement.style.willChange = 'transform';
        } else {
            arrowElement.style.transform = 'rotate(0deg)';
            arrowElement.style.willChange = '';
        }
    }
    
    // Toggle sidebar dropdown
    function toggleSidebarDropdown(event, targetId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        const target = document.getElementById(targetId);
        const btn = event ? event.currentTarget : null;
        const arrow = btn ? btn.querySelector('.dropdown-arrow') : null;
        const sidebar = document.getElementById('sidebar');
        
        if (!target) return false;
        
        // Check if sidebar is collapsed on desktop
        if (sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
            // Expand sidebar first
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
                            setArrowState(otherArrow, false);
                        }
                    }
                });
                
                // Open the clicked dropdown
                target.classList.add('show');
                setArrowState(arrow, true);
            }, 50);
            return false;
        }
        
        // Normal behavior when sidebar is expanded or on mobile
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            setArrowState(arrow, false);
        } else {
            // Close all other open dropdowns
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (otherBtn) {
                        const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                        setArrowState(otherArrow, false);
                    }
                }
            });
            
            target.classList.add('show');
            setArrowState(arrow, true);
        }
        
        return false;
    }
    
    // Toggle sidebar (collapse/expand)
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return false;
        
        if (window.innerWidth <= 992) {
            // Mobile behavior
            const willOpen = !sidebar.classList.contains('active');
            sidebar.classList.toggle('active');
            let overlay = document.querySelector('.sidebar-overlay');
            
            if (willOpen) {
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
            } else if (overlay) {
                overlay.classList.remove('active');
                setTimeout(function() { overlay.remove(); }, 300);
            }
        } else {
            // Desktop behavior - toggle collapse
            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            
            if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
                // Expanding - restore active dropdowns
                setTimeout(function() {
                    document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
                        const activeLink = dropdownNav.querySelector('.nav-link.active');
                        if (activeLink) {
                            const collapseDiv = dropdownNav.querySelector('.collapse');
                            if (collapseDiv && !collapseDiv.classList.contains('show')) {
                                collapseDiv.classList.add('show');
                                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                                if (parentLink) {
                                    const arrow = parentLink.querySelector('.dropdown-arrow');
                                    setArrowState(arrow, true);
                                }
                            }
                        }
                    });
                }, 150);
            } else if (sidebar.classList.contains('collapsed')) {
                // Collapsing - close all dropdowns
                document.querySelectorAll('.sidebar .collapse.show').forEach(function(collapse) {
                    collapse.classList.remove('show');
                    const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (parentBtn) {
                        const arrow = parentBtn.querySelector('.dropdown-arrow');
                        setArrowState(arrow, false);
                    }
                });
            }
        }
        return false;
    };
    
    // Set active sidebar item based on current page
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
                
                // If this link is inside a dropdown, expand the dropdown
                const collapseDiv = link.closest('.collapse');
                if (collapseDiv) {
                    collapseDiv.classList.add('show');
                    const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                    if (parentBtn) {
                        const arrow = parentBtn.querySelector('.dropdown-arrow');
                        setArrowState(arrow, true);
                    }
                }
            }
        });
        
        // For sidebar collapsed mode - add active class to parent dropdown
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('collapsed')) {
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
    
    // Initialize sidebar
    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        
        // Restore sidebar state from localStorage for desktop
        if (sidebar && window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
        
        // Desktop toggle button
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                window.toggleSidebar();
            });
        }
        
        // Mobile menu button
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                window.toggleSidebar();
            });
        }
        
        // Set active sidebar item
        setActiveSidebarItem();
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
            }
        });
        
        // Prevent dropdown from closing when clicking inside it
        document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
            collapse.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) overlay.remove();
                sidebar.classList.remove('active');
            } else {
                sidebar.classList.remove('collapsed');
            }
        });
    }

    // ========== ACCOUNT TREE FUNCTIONS ==========
    
    function getChildRows(parentId){
        return Array.from(document.querySelectorAll('tr[data-parent-id="' + parentId + '"]'));
    }

    function getDescendantRows(parentId){
        let descendants = [];
        getChildRows(parentId).forEach(childRow => {
            descendants.push(childRow);
            descendants = descendants.concat(getDescendantRows(childRow.dataset.accountId));
        });
        return descendants;
    }

    function formatBalance(value){
        const numberValue = Number(value || 0);
        return numberValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function calculateTotalBalance(accountId){
        const row = document.querySelector('tr[data-account-id="' + accountId + '"]');
        if (!row) return 0;

        let total = Number(row.dataset.ownBalance || 0);
        getDescendantRows(accountId).forEach(descendantRow => {
            total += Number(descendantRow.dataset.ownBalance || 0);
        });
        return total;
    }

    function setBalanceCell(row, value){
        if (!row) return;
        const balanceCell = row.querySelector('[data-balance-display]');
        if (!balanceCell) return;

        balanceCell.textContent = formatBalance(value);
        balanceCell.classList.toggle('negative', Number(value || 0) < 0);
    }

    function showOwnBalance(accountId){
        const row = document.querySelector('tr[data-account-id="' + accountId + '"]');
        if (!row) return;
        setBalanceCell(row, Number(row.dataset.ownBalance || 0));
    }

    function showTotalBalance(accountId){
        const row = document.querySelector('tr[data-account-id="' + accountId + '"]');
        if (!row) return;

        const total = calculateTotalBalance(accountId);
        row.dataset.totalBalance = total.toFixed(2);
        setBalanceCell(row, total);
    }

    function setToggleIcon(button, collapsed){
        if (!button) return;
        button.dataset.collapsed = collapsed ? '1' : '0';
        const icon = button.querySelector('i');
        if (icon) icon.className = collapsed ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
    }

    function hideAccountBranch(parentId){
        getChildRows(parentId).forEach(row => {
            row.classList.add('hidden-sub-account');

            const childToggle = row.querySelector('[data-account-toggle]');
            if (childToggle) {
                setToggleIcon(childToggle, true);
                showTotalBalance(childToggle.dataset.accountToggle);
            }

            hideAccountBranch(row.dataset.accountId);
        });
    }

    function showAccountBranch(parentId){
        getChildRows(parentId).forEach(row => {
            row.classList.remove('hidden-sub-account');

            const childToggle = row.querySelector('[data-account-toggle]');
            if (childToggle) {
                const childIsCollapsed = childToggle.dataset.collapsed === '1';

                if (childIsCollapsed) {
                    showTotalBalance(childToggle.dataset.accountToggle);
                    hideAccountBranch(row.dataset.accountId);
                } else {
                    showOwnBalance(childToggle.dataset.accountToggle);
                    showAccountBranch(row.dataset.accountId);
                }
            }
        });
    }

    function toggleSubAccounts(parentId, button){
        const isCollapsed = button.dataset.collapsed === '1';

        if (isCollapsed) {
            // Expand: show sub accounts, parent shows own balance
            setToggleIcon(button, false);
            showOwnBalance(parentId);
            showAccountBranch(parentId);
        } else {
            // Collapse: hide sub accounts, parent shows total balance
            setToggleIcon(button, true);
            hideAccountBranch(parentId);
            showTotalBalance(parentId);
        }
    }

    // ========== MODAL FUNCTIONS ==========
    
    function setDetailText(id, value){
        const element = document.getElementById(id);
        if (!element) return;
        const cleanValue = value === null || value === undefined || String(value).trim() === '' ? '-' : String(value);
        element.textContent = cleanValue;
    }

    function formatDateTimeDisplay(value){
        if (!value) return '-';
        const normalized = String(value).replace(' ', 'T');
        const dateValue = new Date(normalized);
        if (Number.isNaN(dateValue.getTime())) return value;
        return dateValue.toLocaleString('en-US', {
            year:'numeric', month:'short', day:'2-digit',
            hour:'2-digit', minute:'2-digit'
        });
    }

    function formatDateOnlyDisplay(value){
        if (!value) return '-';
        const dateValue = new Date(String(value) + 'T00:00:00');
        if (Number.isNaN(dateValue.getTime())) return value;
        return dateValue.toLocaleDateString('en-US', {
            year:'numeric', month:'short', day:'2-digit'
        });
    }

    function openDetailsModal(account){
        currentDetailsAccount = account || null;
        const deleteAccountInput = document.getElementById('detailsDeleteAccountId');
        if (deleteAccountInput) deleteAccountInput.value = account && account.account_id ? account.account_id : '';
        const detailsDeleteBtn = document.getElementById('detailsDeleteBtn');
        if (detailsDeleteBtn) {
            const hasTransactions = Number(account._has_transactions || 0) > 0;
            detailsDeleteBtn.classList.toggle('d-none', hasTransactions);
            detailsDeleteBtn.disabled = hasTransactions;
            detailsDeleteBtn.title = hasTransactions ? 'This account already has transactions and cannot be deleted.' : '';
        }
        setDetailText('detailAccountCode', account.account_code || '-');
        setDetailText('detailAccountTitle', account.account_title || '-');
        setDetailText('detailAccountType', account.account_type || '-');
        setDetailText('detailParentAccount', account._parent_display || 'Main account');
        setDetailText('detailAccountLevel', account._level_label || 'Main Account');
        setDetailText('detailAccountStatus', account.status ? account.status.charAt(0).toUpperCase() + account.status.slice(1) : 'Active');

        const isBankAccount = (account.account_type || '') === 'Bank';
        document.querySelectorAll('.detail-bank-row').forEach(row => {
            row.style.display = isBankAccount ? '' : 'none';
        });
        setDetailText('detailBankBranch', isBankAccount ? (account.bank_branch || '-') : '-');
        setDetailText('detailAccountNumber', isBankAccount ? (account.account_number || '-') : '-');
        setDetailText('detailAsOfDate', isBankAccount ? formatDateOnlyDisplay(account.as_of_date) : '-');

        const ownBalanceLabel = document.getElementById('detailOwnBalanceLabel');
        const totalBalanceLabel = document.getElementById('detailTotalBalanceLabel');
        if (ownBalanceLabel) ownBalanceLabel.textContent = isBankAccount ? 'Current Balance:' : 'Balance:';
        if (totalBalanceLabel) totalBalanceLabel.textContent = isBankAccount ? 'Total Current Balance With Sub Accounts:' : 'Total Balance With Sub Accounts:';

        setDetailText('detailOwnBalance', '₱' + (account._own_balance_display || Number(account.balance || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})));
        setDetailText('detailTotalBalance', '₱' + (account._total_balance_display || Number(account.balance || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})));
        setDetailText('detailCreatedAt', formatDateTimeDisplay(account.created_at));
        setDetailText('detailUpdatedAt', formatDateTimeDisplay(account.updated_at));
        setDetailText('detailDescription', account.description || '-');
        accountDetailsModal.show();
    }


    function quickReportFromDetails(){
        if (!currentDetailsAccount) return;
        try {
            const detailsEl = document.getElementById('accountDetailsModal');
            const qrEl = document.getElementById('quickReportModal');
            if (detailsEl) bootstrap.Modal.getOrCreateInstance(detailsEl).hide();
            setTimeout(() => {
                document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');
                openQuickReportModal(currentDetailsAccount);
                if (qrEl) bootstrap.Modal.getOrCreateInstance(qrEl).show();
            }, 220);
        } catch (error) {
            console.error('Quick Report open error:', error);
            openQuickReportModal(currentDetailsAccount);
        }
    }

    function editFromDetails(){
        if (!currentDetailsAccount) return;
        accountDetailsModal.hide();
        setTimeout(() => openEditModal(currentDetailsAccount), 180);
    }

    function deleteFromDetails(){
        if (!currentDetailsAccount) return;
        if (Number(currentDetailsAccount._has_transactions || 0) > 0) {
            Swal.fire({
                icon: 'info',
                title: 'Cannot delete account',
                text: 'This account already has transactions, so the delete button is disabled.',
                confirmButtonColor: '#047857'
            });
            return;
        }
        const form = document.getElementById('detailsDeleteForm');
        if (!form) return;
        Swal.fire({
            title: 'Delete account?',
            text: 'This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (result.isConfirmed) form.submit();
        });
    }

    function openQuickReportModal(account){
        currentDetailsAccount = account || currentDetailsAccount;
        const now = new Date();
        const created = account && account.created_at ? new Date(String(account.created_at).replace(' ', 'T')) : now;
        const safeCreated = isNaN(created.getTime()) ? now : created;
        const fromInput = document.getElementById('quickReportFromDate');
        const toInput = document.getElementById('quickReportToDate');
        const preset = document.getElementById('quickReportDatePreset');
        if (preset) preset.value = 'custom';
        if (fromInput) fromInput.value = toInputDateValue(safeCreated);
        if (toInput) toInput.value = toInputDateValue(now);
        const groupFilter = document.getElementById('quickReportCustomerGroupFilter');
        const counterpartyFilter = document.getElementById('quickReportCounterpartyFilter');
        if (groupFilter) groupFilter.value = '';
        if (counterpartyFilter) counterpartyFilter.value = '';
        populateQuickReportCustomerGroupOptions(currentDetailsAccount);
        updateQuickReportCounterpartyOptions(false);
        fillQuickReport(currentDetailsAccount, false);
        quickReportModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('quickReportModal'));
        quickReportModal.show();
    }


    function normalizeQuickReportFilterValue(value){
        return String(value || '').trim();
    }

    function getQuickReportTransactionsForCurrentDate(account){
        let transactions = Array.isArray(account?._qr_transactions) ? account._qr_transactions.slice() : [];
        const fromInputDate = parseDateInputValue(document.getElementById('quickReportFromDate')?.value);
        const toInputDate = parseDateInputValue(document.getElementById('quickReportToDate')?.value);
        const endOfToDate = toInputDate ? new Date(toInputDate.getFullYear(), toInputDate.getMonth(), toInputDate.getDate(), 23, 59, 59) : null;
        return transactions.filter(txn => {
            const raw = txn.transaction_date ? new Date(String(txn.transaction_date).replace(' ', 'T')) : null;
            if (!raw || isNaN(raw.getTime())) return true;
            if (fromInputDate && raw < fromInputDate) return false;
            if (endOfToDate && raw > endOfToDate) return false;
            return true;
        });
    }

    function populateQuickReportCustomerGroupOptions(account){
        const groupSelect = document.getElementById('quickReportCustomerGroupFilter');
        if (!groupSelect) return;
        const currentValue = groupSelect.value || '';
        const groups = new Set();
        getQuickReportTransactionsForCurrentDate(account).forEach(txn => {
            const group = normalizeQuickReportFilterValue(txn.customer_group);
            if (group) groups.add(group);
        });
        const sortedGroups = Array.from(groups).sort((a, b) => a.localeCompare(b));
        groupSelect.innerHTML = '<option value="">All Groups</option>' + sortedGroups.map(group => `<option value="${escapeHtml(group)}">${escapeHtml(group)}</option>`).join('');
        if (currentValue && sortedGroups.includes(currentValue)) {
            groupSelect.value = currentValue;
        } else {
            groupSelect.value = '';
        }
    }

    function updateQuickReportCounterpartyOptions(resetCounterparty){
        const account = currentDetailsAccount;
        const groupSelect = document.getElementById('quickReportCustomerGroupFilter');
        const counterpartySelect = document.getElementById('quickReportCounterpartyFilter');
        if (!counterpartySelect) return;

        const selectedGroup = normalizeQuickReportFilterValue(groupSelect?.value);
        const currentCounterparty = resetCounterparty ? '' : (counterpartySelect.value || '');
        const counterparties = new Set();
        getQuickReportTransactionsForCurrentDate(account).forEach(txn => {
            const group = normalizeQuickReportFilterValue(txn.customer_group);
            const counterparty = normalizeQuickReportFilterValue(txn.counterparty);
            if (selectedGroup && group !== selectedGroup) return;
            if (counterparty) counterparties.add(counterparty);
        });
        const sortedCounterparties = Array.from(counterparties).sort((a, b) => a.localeCompare(b));
        counterpartySelect.innerHTML = '<option value="">All Counterparties</option>' + sortedCounterparties.map(counterparty => `<option value="${escapeHtml(counterparty)}">${escapeHtml(counterparty)}</option>`).join('');
        if (currentCounterparty && sortedCounterparties.includes(currentCounterparty)) {
            counterpartySelect.value = currentCounterparty;
        } else {
            counterpartySelect.value = '';
        }
        refreshQuickReport();
    }

    function fillQuickReport(account, keepGeneratedTime){
        if (!account) return;
        const now = new Date();
        const generatedAt = now.toLocaleString('en-US', {
            year:'numeric', month:'short', day:'2-digit',
            hour:'2-digit', minute:'2-digit'
        });
        const generatedTime = now.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
        const generatedDateShort = now.toLocaleDateString('en-US', {month:'2-digit', day:'2-digit', year:'2-digit'});
        const fromInputDate = parseDateInputValue(document.getElementById('quickReportFromDate')?.value);
        const toInputDate = parseDateInputValue(document.getElementById('quickReportToDate')?.value);
        const fromDate = fromInputDate || (account.created_at ? new Date(String(account.created_at).replace(' ', 'T')) : now);
        const toDate = toInputDate || now;
        const fromDateText = isNaN(fromDate.getTime()) ? generatedDateShort : fromDate.toLocaleDateString('en-US');
        const toDateText = isNaN(toDate.getTime()) ? generatedDateShort : toDate.toLocaleDateString('en-US');
        const periodText = fromDateText + ' through ' + toDateText;
        const accountDisplay = (account.account_code ? account.account_code + ' · ' : '') + (account.account_title || '-');
        const statusText = account.status ? account.status.charAt(0).toUpperCase() + account.status.slice(1) : 'Active';
        const tbody = document.getElementById('quickReportTableBody');

        if (!keepGeneratedTime) {
            setDetailText('reportGeneratedTime', generatedTime);
            setDetailText('reportGeneratedDate', generatedDateShort);
        }
        setDetailText('reportFooterGeneratedAt', generatedAt);
        setDetailText('reportPeriodText', periodText);
        const quickReportTitle = 'Quick Report';
        setDetailText('reportTotalAccountLabel', accountDisplay);
        setDetailText('quickReportTitleText', quickReportTitle);
        setDetailText('quickReportModalTitleText', quickReportTitle);
        setDetailText('quickReportAccountHeaderText', accountDisplay);
        setDetailText('filterPanelAccount', accountDisplay);
        setDetailText('filterPanelType', account.account_type || '-');
        setDetailText('filterPanelStatus', statusText);

        populateQuickReportCustomerGroupOptions(account);
        const selectedCustomerGroup = normalizeQuickReportFilterValue(document.getElementById('quickReportCustomerGroupFilter')?.value);
        const selectedCounterparty = normalizeQuickReportFilterValue(document.getElementById('quickReportCounterpartyFilter')?.value);
        let transactions = getQuickReportTransactionsForCurrentDate(account).filter(txn => {
            const txnGroup = normalizeQuickReportFilterValue(txn.customer_group);
            const txnCounterparty = normalizeQuickReportFilterValue(txn.counterparty);
            if (selectedCustomerGroup && txnGroup !== selectedCustomerGroup) return false;
            if (selectedCounterparty && txnCounterparty !== selectedCounterparty) return false;
            return true;
        });
        setDetailText('filterPanelCustomerGroup', selectedCustomerGroup || 'All Groups');
        setDetailText('filterPanelCounterparty', selectedCounterparty || 'All Counterparties');

        const sort = document.getElementById('quickReportSortBy')?.value || 'default';
        transactions.sort((a, b) => {
            const da = new Date(String(a.transaction_date || '').replace(' ', 'T')).getTime() || 0;
            const db = new Date(String(b.transaction_date || '').replace(' ', 'T')).getTime() || 0;
            if (sort === 'date_desc') return db - da;
            if (sort === 'date_asc') return da - db;
            if (sort === 'amount_desc') return (Number(b.debit || 0) + Number(b.credit || 0)) - (Number(a.debit || 0) + Number(a.credit || 0));
            if (sort === 'amount_asc') return (Number(a.debit || 0) + Number(a.credit || 0)) - (Number(b.debit || 0) + Number(b.credit || 0));
            return da - db;
        });

        quickReportPrintTransactions = transactions.map(txn => ({...txn}));

        const accountTypeForBalance = String(account.account_type || '').trim().toLowerCase();
        const creditNormalTypes = [
            'accounts payable',
            'credit card',
            'other current liability',
            'long term liability',
            'equity',
            'income',
            'other income'
        ];
        const isCreditNormalAccount = creditNormalTypes.includes(accountTypeForBalance);

        let totalDebit = 0;
        let totalCredit = 0;
        let endingBalance = 0;
        let rowsHtml = '';

        if (transactions.length === 0) {
            rowsHtml += `<tr class="transaction-row"><td colspan="7" class="text-center text-muted py-3">No transactions found for the selected filters.</td></tr>`;
        } else {
            transactions.forEach(txn => {
                const debit = Number(txn.debit || 0);
                const credit = Number(txn.credit || 0);
                totalDebit += debit;
                totalCredit += credit;

                // Quick Report balance must always be based on the visible totals.
                // Asset / Expense / COGS accounts: Debit - Credit.
                // Liability / Equity / Income accounts: Credit - Debit.
                endingBalance = isCreditNormalAccount ? (totalCredit - totalDebit) : (totalDebit - totalCredit);
                const balance = endingBalance;

                const txDate = txn.transaction_date ? formatDateOnlyDisplay(txn.transaction_date) : '-';
                const txnPayload = encodeURIComponent(JSON.stringify(txn));
                rowsHtml += `<tr class="transaction-row" title="Open source transaction" onclick="openQuickReportTransactionFromRow('${txnPayload}')">
                    <td data-report-col="date">${escapeHtml(txDate)}</td>
                    <td data-report-col="type">${escapeHtml(txn.transaction_type || '-')}</td>
                    <td data-report-col="txn">${escapeHtml(txn.reference_no || '-')}</td>
                    <td class="quick-report-description" data-report-col="memo">${escapeHtml(txn.memo || '-')}</td>
                    <td class="amount-cell" data-report-col="debit">${debit > 0 ? debit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) : ''}</td>
                    <td class="amount-cell" data-report-col="credit">${credit > 0 ? credit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) : ''}</td>
                    <td class="amount-cell" data-report-col="balance">${balance.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>`;
            });
        }

        rowsHtml += `<tr class="total-row">
            <td colspan="4" id="reportTotalLabelCell">Total <span id="reportTotalAccountLabel">${escapeHtml(accountDisplay)}</span></td>
            <td class="amount-cell" id="reportTotalDebit" data-report-col="debit">${totalDebit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
            <td class="amount-cell" id="reportTotalCredit" data-report-col="credit">${totalCredit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
            <td class="amount-cell" id="reportEndingBalance" data-report-col="balance">${endingBalance.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
        </tr>`;

        if (tbody) tbody.innerHTML = rowsHtml;
        updateQuickReportTotalColspan();
        document.querySelectorAll('.quick-report-column-check').forEach(check => {
            setQuickReportColumnVisibility(check.value, check.checked);
        });
    }

    function formatDateOnlyDisplay(value){
        if (!value) return '-';
        const date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) return value;
        return date.toLocaleDateString('en-US');
    }

    function toInputDateValue(date){
        if (!(date instanceof Date) || isNaN(date.getTime())) return '';
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function parseDateInputValue(value){
        if (!value) return null;
        const date = new Date(value + 'T00:00:00');
        return isNaN(date.getTime()) ? null : date;
    }

    function applyQuickReportDatePreset(){
        const preset = document.getElementById('quickReportDatePreset')?.value || 'custom';
        const fromInput = document.getElementById('quickReportFromDate');
        const toInput = document.getElementById('quickReportToDate');
        const now = new Date();
        let from = parseDateInputValue(fromInput?.value) || now;
        let to = now;

        if (preset === 'today') {
            from = now;
            to = now;
        } else if (preset === 'this_month') {
            from = new Date(now.getFullYear(), now.getMonth(), 1);
            to = now;
        } else if (preset === 'this_year') {
            from = new Date(now.getFullYear(), 0, 1);
            to = now;
        } else if (preset === 'all' && currentDetailsAccount) {
            const created = currentDetailsAccount.created_at ? new Date(String(currentDetailsAccount.created_at).replace(' ', 'T')) : now;
            from = isNaN(created.getTime()) ? now : created;
            to = now;
        }

        if (fromInput) fromInput.value = toInputDateValue(from);
        if (toInput) toInput.value = toInputDateValue(to);
        populateQuickReportCustomerGroupOptions(currentDetailsAccount);
        updateQuickReportCounterpartyOptions(false);
    }


    function openQuickReportCustomize(){
        const panel = document.getElementById('quickReportCustomizePanel');
        if (!panel) return;
        const isOpen = panel.classList.contains('show');
        if (isOpen) {
            panel.classList.remove('show');
            return;
        }
        syncCustomizeInputsFromToolbar();
        panel.classList.add('show');
    }

    function closeQuickReportCustomize(){
        const panel = document.getElementById('quickReportCustomizePanel');
        if (panel) panel.classList.remove('show');
    }

    function syncCustomizeInputsFromToolbar(){
        const preset = document.getElementById('quickReportDatePreset')?.value || 'custom';
        const from = document.getElementById('quickReportFromDate')?.value || '';
        const to = document.getElementById('quickReportToDate')?.value || '';
        const sort = document.getElementById('quickReportSortBy')?.value || 'default';
        const customPreset = document.getElementById('customizeDatePreset');
        const customFrom = document.getElementById('customizeFromDate');
        const customTo = document.getElementById('customizeToDate');
        const customSort = document.getElementById('customizeSortBy');
        if (customPreset) customPreset.value = preset;
        if (customFrom) customFrom.value = from;
        if (customTo) customTo.value = to;
        if (customSort) customSort.value = sort;
    }

    function syncCustomizeDateFields(){
        const customPreset = document.getElementById('customizeDatePreset');
        const toolbarPreset = document.getElementById('quickReportDatePreset');
        if (toolbarPreset && customPreset) toolbarPreset.value = customPreset.value;
        applyQuickReportDatePreset();
        const customFrom = document.getElementById('customizeFromDate');
        const customTo = document.getElementById('customizeToDate');
        const toolbarFrom = document.getElementById('quickReportFromDate');
        const toolbarTo = document.getElementById('quickReportToDate');
        if (customFrom && toolbarFrom) customFrom.value = toolbarFrom.value;
        if (customTo && toolbarTo) customTo.value = toolbarTo.value;
    }

    function applyQuickReportCustomize(){
        const preset = document.getElementById('customizeDatePreset')?.value || 'custom';
        const from = document.getElementById('customizeFromDate')?.value || '';
        const to = document.getElementById('customizeToDate')?.value || '';
        const sort = document.getElementById('customizeSortBy')?.value || 'default';
        const toolbarPreset = document.getElementById('quickReportDatePreset');
        const toolbarFrom = document.getElementById('quickReportFromDate');
        const toolbarTo = document.getElementById('quickReportToDate');
        const toolbarSort = document.getElementById('quickReportSortBy');
        if (toolbarPreset) toolbarPreset.value = preset;
        if (toolbarFrom) toolbarFrom.value = from;
        if (toolbarTo) toolbarTo.value = to;
        if (toolbarSort) toolbarSort.value = sort;

        document.querySelectorAll('.quick-report-column-check').forEach(check => {
            setQuickReportColumnVisibility(check.value, check.checked);
        });

        const header = document.querySelector('#quickReportContent .quick-report-title-wrap');
        const note = document.getElementById('quickReportSystemNote');
        const showHeader = document.getElementById('customizeShowHeader')?.checked ?? true;
        const showNote = document.getElementById('customizeShowNote')?.checked ?? true;
        if (header) header.style.display = showHeader ? 'grid' : 'none';
        if (note) note.style.display = showNote ? 'block' : 'none';

        updateQuickReportTotalColspan();
        refreshQuickReport();
        closeQuickReportCustomize();
        Swal.fire({icon:'success', title:'Applied', text:'Report customization has been applied.', confirmButtonColor:'#047857', timer:1200, timerProgressBar:true});
    }

    function resetQuickReportCustomize(){
        document.querySelectorAll('.quick-report-column-check').forEach(check => check.checked = true);
        const showHeader = document.getElementById('customizeShowHeader');
        const showNote = document.getElementById('customizeShowNote');
        if (showHeader) showHeader.checked = true;
        if (showNote) showNote.checked = true;
        const preset = document.getElementById('customizeDatePreset');
        const sort = document.getElementById('customizeSortBy');
        if (preset) preset.value = 'all';
        if (sort) sort.value = 'default';
        syncCustomizeDateFields();
    }

    function setQuickReportColumnVisibility(column, isVisible){
        document.querySelectorAll('[data-report-col="' + column + '"]').forEach(cell => {
            cell.style.display = isVisible ? '' : 'none';
        });
    }

    function updateQuickReportTotalColspan(){
        const labelCell = document.getElementById('reportTotalLabelCell');
        if (!labelCell) return;
        const leftColumns = ['date','type','txn','memo'];
        let visibleCount = 0;
        leftColumns.forEach(col => {
            const check = document.querySelector('.quick-report-column-check[value="' + col + '"]');
            if (!check || check.checked) visibleCount++;
        });
        labelCell.colSpan = Math.max(1, visibleCount);
    }

    function toggleQuickReportFilters(){
        const panel = document.getElementById('quickReportFilterPanel');
        const btn = document.getElementById('quickReportShowFiltersBtn');
        if (!panel) return;
        const willShow = panel.style.display === 'none' || panel.style.display === '';
        panel.style.display = willShow ? 'block' : 'none';
        if (btn) btn.textContent = willShow ? 'Hide Filters' : 'Show Filters';
    }

    function updateQuickReportDateFilters(){
        if (!currentDetailsAccount) return;
        populateQuickReportCustomerGroupOptions(currentDetailsAccount);
        updateQuickReportCounterpartyOptions(false);
    }

    function refreshQuickReport(){
        if (!currentDetailsAccount) return;
        fillQuickReport(currentDetailsAccount, true);
    }

    function addQuickReportComment(){
        Swal.fire({
            title: 'Comment on Report',
            input: 'textarea',
            inputLabel: 'Add note/comment',
            inputPlaceholder: 'Type your report comment here...',
            showCancelButton: true,
            confirmButtonColor: '#047857',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Add Comment'
        }).then(result => {
            if (!result.isConfirmed) return;
            const box = document.getElementById('quickReportCommentBox');
            if (!box) return;
            box.textContent = result.value ? 'Comment: ' + result.value : '';
            box.style.display = result.value ? 'block' : 'none';
        });
    }

    function memorizeQuickReport(){
        if (!currentDetailsAccount) return;
        const memory = {
            account_id: currentDetailsAccount.account_id || '',
            account_title: currentDetailsAccount.account_title || '',
            date_preset: document.getElementById('quickReportDatePreset')?.value || 'custom',
            from: document.getElementById('quickReportFromDate')?.value || '',
            to: document.getElementById('quickReportToDate')?.value || '',
            sort: document.getElementById('quickReportSortBy')?.value || 'default'
        };
        localStorage.setItem('chartOfAccountsQuickReport', JSON.stringify(memory));
        Swal.fire({icon:'success', title:'Memorized', text:'Quick report settings saved in this browser.', confirmButtonColor:'#047857', timer:1600, timerProgressBar:true});
    }

    function emailQuickReport(){
        if (!currentDetailsAccount) return;
        const subject = encodeURIComponent('Quick Report - ' + (currentDetailsAccount.account_title || 'Account'));
        const body = encodeURIComponent(buildQuickReportText());
        window.location.href = `mailto:?subject=${subject}&body=${body}`;
    }

    function buildQuickReportText(){
        const rows = Array.from(document.querySelectorAll('#quickReportContent .quick-report-table tbody tr'));
        const lines = ['AMGC - Account Quick Report', 'Period: ' + (document.getElementById('reportPeriodText')?.textContent || '-'), ''];
        rows.forEach(row => {
            const cells = Array.from(row.children).map(td => td.textContent.trim()).filter(Boolean);
            if (cells.length) lines.push(cells.join(' | '));
        });
        return lines.join('\n');
    }

    function exportQuickReportExcel(){
        const rows = [['Date','Type','Reference No.','Memo','Counterparty','Account','Debit','Credit','Balance']];
        document.querySelectorAll('#quickReportContent .quick-report-table tbody tr').forEach(row => {
            if (row.classList.contains('group-row')) return;
            const cells = Array.from(row.children).map(td => td.textContent.trim());
            if (cells.length >= 9) rows.push(cells);
        });
        const csv = rows.map(row => row.map(value => '"' + String(value).replace(/"/g, '""') + '"').join(',')).join('\n');
        const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const filenameAccount = (currentDetailsAccount?.account_title || 'quick-report').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase();
        a.href = url;
        a.download = filenameAccount + '-quick-report.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function openQuickReportTransaction(){
        if (!currentDetailsAccount) return;
        quickReportModal.hide();
        setTimeout(() => openDetailsModal(currentDetailsAccount), 180);
    }

    function openQuickReportTransactionFromRow(encodedTxn){
        let txn = null;
        try {
            txn = JSON.parse(decodeURIComponent(encodedTxn || ''));
        } catch (e) {
            txn = null;
        }
        if (!txn) {
            Swal.fire({
                icon:'error',
                title:'Transaction not found',
                text:'Unable to read the selected transaction details.',
                confirmButtonColor:'#047857'
            });
            return;
        }

        const sourceTable = String(txn.source_table || '').trim().toLowerCase();
        const sourceId = Number(txn.source_id || 0);
        const transactionType = String(txn.transaction_type || '').trim().toLowerCase();
        const accountType = String(currentDetailsAccount?.account_type || '').trim().toLowerCase();
        const accountTitle = String(currentDetailsAccount?.account_title || '').trim().toLowerCase();
        const referenceNo = txn.reference_no || txn.po_number || '-';
        const encodedSourceId = encodeURIComponent(String(sourceId || ''));
        const encodedRef = encodeURIComponent(String(referenceNo || ''));
        let pageUrl = '';
        let pageLabel = '';
        let sourceLabel = '';

        if (sourceTable === 'purchase_orders') {
            pageUrl = `purchase_order.php?source_id=${encodedSourceId}&ref=${encodedRef}`;
            pageLabel = 'Enter Bills';
            sourceLabel = 'Enter Bills';
        } else if (sourceTable === 'bank_transactions') {
            if (transactionType.includes('check') || transactionType.includes('withdrawal')) {
                pageUrl = `Withdrawal.php?source_id=${encodedSourceId}&ref=${encodedRef}`;
                pageLabel = 'Write Check';
                sourceLabel = 'Write Check';
            } else {
                pageUrl = `deposit.php?source_id=${encodedSourceId}&ref=${encodedRef}`;
                pageLabel = 'Deposit';
                sourceLabel = 'Deposit';
            }
        } else if (sourceTable === 'repair_payment_history') {
            const isMotorpoolExpense = accountType === 'expense' || accountTitle.includes('motorpool') || accountTitle.includes('repair') || accountTitle.includes('vehicle');
            if (isMotorpoolExpense) {
                Swal.fire({
                    icon:'info',
                    title:'Motorpool account transaction',
                    html:'This transaction was encoded in the Motorpool account/module.<br>Please login there to view the full transaction details.',
                    confirmButtonText:'OK',
                    confirmButtonColor:'#047857'
                });
                return;
            }
            pageUrl = `motorpool.php?source_id=${encodedSourceId}&ref=${encodedRef}`;
            pageLabel = 'Motorpool';
            sourceLabel = 'Motorpool';
        } else {
            Swal.fire({
                icon:'info',
                title:'Source page not available',
                text:'This transaction does not have a supported source page yet.',
                confirmButtonColor:'#047857'
            });
            return;
        }

        const detailsHtml = `
            <div class="text-start" style="font-size:14px;line-height:1.6">
                <div><strong>Encoded From:</strong> ${escapeHtml(sourceLabel)}</div>
                <div><strong>Reference No.:</strong> ${escapeHtml(referenceNo || '-')}</div>
                <div><strong>Date:</strong> ${escapeHtml(txn.transaction_date ? formatDateOnlyDisplay(txn.transaction_date) : '-')}</div>
                <div><strong>Type:</strong> ${escapeHtml(txn.transaction_type || '-')}</div>
            </div>`;

        Swal.fire({
            icon:'question',
            title:`Open ${pageLabel}?`,
            html: detailsHtml,
            showCancelButton:true,
            confirmButtonText:`Go to ${pageLabel}`,
            cancelButtonText:'Cancel',
            confirmButtonColor:'#047857',
            cancelButtonColor:'#6b7280'
        }).then(result => {
            if (result.isConfirmed) {
                window.location.href = pageUrl;
            }
        });
    }

    function escapeHtml(value){
        return String(value ?? '').replace(/[&<>"']/g, function(char){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
        });
    }

    function printQuickReport(){
        const printArea = document.getElementById('quickReportPrintArea');
        if (!printArea || !currentDetailsAccount) return;

        const getText = id => (document.getElementById(id)?.textContent || '-').trim();
        const generated = getText('reportFooterGeneratedAt');
        const period = getText('reportPeriodText');
        const accountName = getText('quickReportAccountHeaderText');
        const reportTitle = 'Quick Report';
        const accountType = currentDetailsAccount.account_type || '-';
        const status = currentDetailsAccount.status ? currentDetailsAccount.status.charAt(0).toUpperCase() + currentDetailsAccount.status.slice(1) : 'Active';
        const preparedBy = <?php echo json_encode($user_name); ?>;
        const branchName = <?php echo json_encode($branch_name); ?>;
        const showNote = document.getElementById('customizeShowNote')?.checked !== false;

        const accountTypeForBalance = String(currentDetailsAccount.account_type || '').trim().toLowerCase();
        const creditNormalTypes = [
            'accounts payable',
            'credit card',
            'other current liability',
            'long term liability',
            'equity',
            'income',
            'other income'
        ];
        const isCreditNormalAccount = creditNormalTypes.includes(accountTypeForBalance);

        let printTotalDebit = 0;
        let printTotalCredit = 0;
        let printEndingBalance = 0;

        const printableTransactions = Array.isArray(quickReportPrintTransactions) ? quickReportPrintTransactions : [];
        const tableRows = printableTransactions.map(txn => {
            const debit = Number(txn.debit || 0);
            const credit = Number(txn.credit || 0);
            printTotalDebit += debit;
            printTotalCredit += credit;
            printEndingBalance = isCreditNormalAccount ? (printTotalCredit - printTotalDebit) : (printTotalDebit - printTotalCredit);

            const txDate = txn.transaction_date ? formatDateOnlyDisplay(txn.transaction_date) : '-';
            const counterparty = txn.counterparty || txn.report_counterparty || '-';
            const referenceNo = txn.reference_no || txn.report_reference_no || '-';
            const memo = txn.memo || txn.report_memo || '-';

            return `<tr>
                <td>${escapeHtml(txDate)}</td>
                <td>${escapeHtml(txn.transaction_type || '-')}</td>
                <td>${escapeHtml(counterparty || '-')}</td>
                <td>${escapeHtml(referenceNo || '-')}</td>
                <td>${escapeHtml(memo || '-')}</td>
                <td class="amount">${debit > 0 ? escapeHtml(debit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})) : ''}</td>
                <td class="amount">${credit > 0 ? escapeHtml(credit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})) : ''}</td>
                <td class="amount">${escapeHtml(printEndingBalance.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}))}</td>
            </tr>`;
        }).join('') || `<tr><td colspan="8" style="text-align:center; padding:12px;">No records found for the selected filters.</td></tr>`;

        const totalDebitText = printableTransactions.length
            ? printTotalDebit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})
            : getText('reportTotalDebit');
        const totalCreditText = printableTransactions.length
            ? printTotalCredit.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})
            : getText('reportTotalCredit');
        const endingBalanceText = printableTransactions.length
            ? printEndingBalance.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})
            : getText('reportEndingBalance');

        printArea.innerHTML = `
            <div class="print-report">
                <div class="print-report-header">
                    <div class="print-company">AMGC</div>
                    <div class="print-title">${escapeHtml(reportTitle)}</div>
                    <div class="print-subtitle">${escapeHtml(period)}</div>
                </div>

                <table class="print-report-info">
                    <tr>
                        <td class="print-label">Account:</td><td>${escapeHtml(accountName)}</td>
                        <td class="print-label">Branch:</td><td>${escapeHtml(branchName)}</td>
                    </tr>
                    <tr>
                        <td class="print-label">Type:</td><td>${escapeHtml(accountType)}</td>
                        <td class="print-label">Status:</td><td>${escapeHtml(status)}</td>
                    </tr>
                    <tr>
                        <td class="print-label">Prepared By:</td><td>${escapeHtml(preparedBy)}</td>
                        <td class="print-label">Generated:</td><td>${escapeHtml(generated)}</td>
                    </tr>
                </table>

                <table class="print-ledger-table">
                    <thead>
                        <tr>
                            <th style="width:10%;">Date</th>
                            <th style="width:11%;">Type</th>
                            <th style="width:15%;">Counterparty</th>
                            <th style="width:14%;">Reference No.</th>
                            <th style="width:23%;">Memo</th>
                            <th style="width:9%; text-align:right;">Debit</th>
                            <th style="width:9%; text-align:right;">Credit</th>
                            <th style="width:9%; text-align:right;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="5">Total ${escapeHtml(accountName)}</td>
                            <td class="amount">${escapeHtml(totalDebitText)}</td>
                            <td class="amount">${escapeHtml(totalCreditText)}</td>
                            <td class="amount">${escapeHtml(endingBalanceText)}</td>
                        </tr>
                    </tfoot>
                </table>

                ${showNote ? `<div class="print-note">Note: This quick report uses only the saved data from Account Full Details.</div>` : ''}

                <div class="print-report-footer">
                    <div>Printed from Chart of Accounts</div>
                    <div>Generated: ${escapeHtml(generated)}</div>
                </div>
            </div>
        `;

        document.body.classList.add('quick-report-printing');
        window.print();
        setTimeout(() => {
            document.body.classList.remove('quick-report-printing');
            printArea.innerHTML = '';
        }, 300);
    }

    function logout() { 
        Swal.fire({
            title: 'Logout?',
            text: 'Are you sure you want to logout?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#047857',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Logout',
            cancelButtonText: 'Cancel'
        }).then(r => { 
            if(r.isConfirmed) window.location.href = '../logout.php'; 
        }); 
    }
    

    function filterParentAccountsByType(currentAccountId = '') {
        const accountType = document.getElementById('accountType').value;
        const parentSelect = document.getElementById('parentAccountId');
        const selectedValue = parentSelect.value;

        Array.from(parentSelect.options).forEach(opt => {
            if (!opt.value) {
                opt.hidden = false;
                opt.disabled = false;
                return;
            }

            const parentType = opt.getAttribute('data-account-type') || '';
            const isSameType = accountType !== '' && parentType === accountType;
            const isCurrentAccount = currentAccountId !== '' && opt.value == currentAccountId;

            opt.hidden = !isSameType || isCurrentAccount;
            opt.disabled = !isSameType || isCurrentAccount;
        });

        const selectedOption = parentSelect.options[parentSelect.selectedIndex];
        if (selectedOption && selectedOption.value && (selectedOption.hidden || selectedOption.disabled)) {
            parentSelect.value = '';
        }

        if (!accountType) {
            parentSelect.value = '';
        }
    }

    function getTodayDateValue(){
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function toggleBankFields(){
        const isBank = document.getElementById('accountType').value === 'Bank';
        const balanceLabel = document.getElementById('balanceLabel');
        const asOfDateInput = document.getElementById('asOfDate');
        document.querySelectorAll('.bank-only-field').forEach(field => {
            field.style.display = isBank ? '' : 'none';
        });
        if (balanceLabel) balanceLabel.textContent = isBank ? 'Current Balance' : 'Balance';
        if (isBank && asOfDateInput && !asOfDateInput.value) {
            asOfDateInput.value = getTodayDateValue();
        }
        if (!isBank) {
            document.getElementById('bankBranch').value = '';
            if (asOfDateInput) asOfDateInput.value = '';
        }
    }

    document.getElementById('accountType').addEventListener('change', function() {
        filterParentAccountsByType(document.getElementById('accountId').value || '');
        toggleBankFields();
    });

    function openAddModal(){
        document.getElementById('accountForm').reset();
        document.getElementById('formAction').value = 'add_account';
        document.getElementById('accountId').value = '';
        document.getElementById('saveMode').value = 'close';
        document.getElementById('accountModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add Account';
        document.getElementById('saveNewBtn').style.display = '';
        document.getElementById('saveCloseBtn').innerHTML = '<i class="bi bi-save me-1"></i>Save &amp; Close';
        const parentSelect = document.getElementById('parentAccountId');
        parentSelect.disabled = false;
        Array.from(parentSelect.options).forEach(opt => {
            opt.disabled = false;
            opt.hidden = false;
        });
        filterParentAccountsByType();
        document.getElementById('statusWrap').style.display = 'none';
        document.getElementById('accountDescription').value = '';
        document.getElementById('accountBalance').value = '0.00';
        document.getElementById('bankBranch').value = '';
        document.getElementById('accountNumber').value = '';
        document.getElementById('asOfDate').value = getTodayDateValue();
        toggleBankFields();
    }
    
    function openEditModal(account){
        document.getElementById('accountForm').reset();
        document.getElementById('formAction').value = 'update_account';
        document.getElementById('accountId').value = account.account_id || '';
        document.getElementById('saveMode').value = 'close';
        document.getElementById('accountCode').value = account.account_code || '';
        document.getElementById('accountTitle').value = account.account_title || '';
        document.getElementById('accountType').value = account.account_type || '';
        document.getElementById('accountDescription').value = account.description || '';
        document.getElementById('bankBranch').value = account.bank_branch || '';
        document.getElementById('accountNumber').value = account.account_number || '';
        document.getElementById('asOfDate').value = account.as_of_date || (account.account_type === 'Bank' ? getTodayDateValue() : '');
        const parentSelect = document.getElementById('parentAccountId');
        Array.from(parentSelect.options).forEach(opt => {
            opt.disabled = false;
            opt.hidden = false;
        });
        filterParentAccountsByType(account.account_id || '');
        parentSelect.value = account.parent_account_id || '';
        if (account._has_children) {
            parentSelect.value = '';
            parentSelect.disabled = true;
        } else {
            parentSelect.disabled = false;
        }
        document.getElementById('accountBalance').value = Number(account.balance || 0).toFixed(2);
        document.getElementById('accountStatus').value = account.status || 'active';
        document.getElementById('statusWrap').style.display = 'block';
        document.getElementById('accountModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Account';
        document.getElementById('saveNewBtn').style.display = 'none';
        document.getElementById('saveCloseBtn').innerHTML = '<i class="bi bi-save me-1"></i>Save Changes';
        filterParentAccountsByType(account.account_id || '');
        toggleBankFields();
        accountModal.show();
    }

    const accountForm = document.getElementById('accountForm');
    if (accountForm) {
        accountForm.addEventListener('submit', function() {
            const accountTypeInput = document.getElementById('accountType');
            const asOfDateInput = document.getElementById('asOfDate');
            if (accountTypeInput && accountTypeInput.value === 'Bank' && asOfDateInput && !asOfDateInput.value) {
                asOfDateInput.value = getTodayDateValue();
            }
        });
    }

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
</body>
</html>
