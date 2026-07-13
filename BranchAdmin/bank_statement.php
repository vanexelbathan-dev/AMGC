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

// Handle AJAX request for transaction modal content (including account details)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_transactions') {
    $bank_id = (int)($_GET['bank_id'] ?? 0);
    $bank_name = trim($_GET['bank_name'] ?? '');
    $branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $view_all_branches = (bool)($_SESSION['view_all_branches'] ?? false);
    
    function formatAmountAjax($value) {
        $value = (float)$value;
        if ($value == floor($value)) {
            return '₱' . number_format($value, 0);
        } else {
            return '₱' . number_format($value, 2);
        }
    }
    
    function columnExistsAjax($conn, $table, $column) {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }

    function getBankPaymentMethodsAjax($conn, $bank_id) {
        $methods = [];
        if ($bank_id <= 0) return $methods;
        $stmt = $conn->prepare("SELECT payment_method FROM bank_payment_methods WHERE bank_id = ? ORDER BY payment_method");
        $stmt->bind_param('i', $bank_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $methods[] = $row['payment_method'];
        }
        $stmt->close();
        return $methods;
    }

    function getBankDetailsAjax($conn, $bank_id, $bank_name) {
        $details = [
            'bank_name' => $bank_name,
            'bank_branch' => '',
            'account_name' => '',
            'account_number' => '',
            'initial_balance' => 0.0,
            'initial_balance_date' => null,
            'parent_bank_name' => '',
            'payment_methods' => []
        ];
        if ($bank_id > 0) {
            $stmt = $conn->prepare("SELECT bank_name, bank_branch, account_name, account_number, initial_balance, initial_balance_date, parent_bank_id FROM banks WHERE bank_id = ? LIMIT 1");
            $stmt->bind_param('i', $bank_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $details['bank_name'] = $row['bank_name'];
                $details['bank_branch'] = $row['bank_branch'] ?? '';
                $details['account_name'] = $row['account_name'] ?? '';
                $details['account_number'] = $row['account_number'] ?? '';
                $details['initial_balance'] = (float)($row['initial_balance'] ?? 0);
                $details['initial_balance_date'] = $row['initial_balance_date'] ?? null;
                if (!empty($row['parent_bank_id'])) {
                    $pStmt = $conn->prepare("SELECT bank_name FROM banks WHERE bank_id = ?");
                    $pStmt->bind_param('i', $row['parent_bank_id']);
                    $pStmt->execute();
                    $pRow = $pStmt->get_result()->fetch_assoc();
                    $pStmt->close();
                    $details['parent_bank_name'] = $pRow['bank_name'] ?? '';
                }
                $details['payment_methods'] = getBankPaymentMethodsAjax($conn, $bank_id);
            }
        }
        return $details;
    }

    function getBankOpeningAjax($conn, $bank_id) {
        $opening = ['initial_balance' => 0.0, 'initial_balance_date' => null];
        if ($bank_id <= 0 || !columnExistsAjax($conn, 'banks', 'initial_balance')) return $opening;
        $stmt = $conn->prepare("SELECT initial_balance, initial_balance_date FROM banks WHERE bank_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $bank_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $opening['initial_balance'] = (float)($row['initial_balance'] ?? 0);
                $opening['initial_balance_date'] = $row['initial_balance_date'] ?? null;
            }
        }
        return $opening;
    }

    function getBankTransactionsAjax($conn, $view_all_branches, $branch_id, $bank_id, $bank_name) {
        $hasBankId = columnExistsAjax($conn, 'bank_transactions', 'bank_id');
        $rows = [];

        if ($hasBankId && $bank_id > 0) {
            $sql = "SELECT bt.transaction_id, bt.branch_id, bt.transaction_type, bt.transaction_date,
                           bt.reference_number, bt.bank_name, bt.description, bt.amount,
                           CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
                    FROM bank_transactions bt
                    LEFT JOIN users u ON bt.created_by = u.user_id
                    WHERE bt.bank_id = ? AND NOT (bt.transaction_type = 'deposit' AND (bt.reference_number LIKE 'INITIAL_%' OR bt.description LIKE 'Initial balance%'))";
            if (!$view_all_branches && $branch_id > 0) $sql .= " AND bt.branch_id = ?";
            $sql .= " ORDER BY bt.transaction_date ASC, bt.transaction_id ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('ii', $bank_id, $branch_id);
                else $stmt->bind_param('i', $bank_id);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }

        if (empty($rows) && $bank_name !== '') {
            $countSql = "SELECT COUNT(*) AS cnt FROM banks WHERE bank_name = ?";
            if (!$view_all_branches && $branch_id > 0) $countSql .= " AND (branch_id = ? OR branch_id = 0)";
            $cntStmt = $conn->prepare($countSql);
            $sameNameCount = 0;
            if ($cntStmt) {
                if (!$view_all_branches && $branch_id > 0) $cntStmt->bind_param('si', $bank_name, $branch_id);
                else $cntStmt->bind_param('s', $bank_name);
                $cntStmt->execute();
                $sameNameCount = (int)($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
                $cntStmt->close();
            }

            if ($sameNameCount <= 1) {
                $sql = "SELECT bt.transaction_id, bt.branch_id, bt.transaction_type, bt.transaction_date,
                               bt.reference_number, bt.bank_name, bt.description, bt.amount,
                               CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
                        FROM bank_transactions bt
                        LEFT JOIN users u ON bt.created_by = u.user_id
                        WHERE bt.bank_name = ? AND NOT (bt.transaction_type = 'deposit' AND (bt.reference_number LIKE 'INITIAL_%' OR bt.description LIKE 'Initial balance%'))";
                if ($hasBankId) $sql .= " AND (bt.bank_id IS NULL OR bt.bank_id = 0)";
                if (!$view_all_branches && $branch_id > 0) $sql .= " AND bt.branch_id = ?";
                $sql .= " ORDER BY bt.transaction_date ASC, bt.transaction_id ASC";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('si', $bank_name, $branch_id);
                    else $stmt->bind_param('s', $bank_name);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                }
            }
        }

        $opening = getBankOpeningAjax($conn, (int)$bank_id);
        $running_balance = (float)$opening['initial_balance'];
        foreach ($rows as &$tx) {
            $amount = (float)$tx['amount'];
            if ($tx['transaction_type'] === 'deposit') {
                $running_balance += $amount;
                $tx['deposit'] = $amount;
                $tx['withdrawal'] = 0.0;
            } else {
                $running_balance -= $amount;
                $tx['deposit'] = 0.0;
                $tx['withdrawal'] = $amount;
            }
            $tx['balance'] = $running_balance;
        }
        $rows = array_reverse($rows);
        return $rows;
    }

    // Get bank details and transactions for the modal
    $bankDetails = getBankDetailsAjax($conn, $bank_id, $bank_name);
    $opening = getBankOpeningAjax($conn, $bank_id);
    $transactions = getBankTransactionsAjax($conn, $view_all_branches, $branch_id, $bank_id, $bank_name);
    
    // Output modal content with compact grid layout and green background
    echo '<div class="mb-4 p-3 border rounded" style="background:#e8f5e9">';
    echo '<h6 class="mb-2"><i class="bi bi-bank2"></i> Account Details</h6>';
    echo '<div class="row g-2">';
    if (!empty($bankDetails['parent_bank_name'])) {
        echo '<div class="col-md-6 col-lg-4"><strong>Parent Bank:</strong> ' . htmlspecialchars($bankDetails['parent_bank_name']) . '</div>';
    }
    echo '<div class="col-md-6 col-lg-4"><strong>Bank/Branch:</strong> ' . htmlspecialchars($bankDetails['bank_branch'] ?: '-') . '</div>';
    echo '<div class="col-md-6 col-lg-4"><strong>Account Name:</strong> ' . htmlspecialchars($bankDetails['account_name'] ?: '-') . '</div>';
    echo '<div class="col-md-6 col-lg-4"><strong>Account Number:</strong> ' . htmlspecialchars($bankDetails['account_number'] ?: '-') . '</div>';
    echo '<div class="col-md-6 col-lg-4"><strong>Initial Balance:</strong> ' . formatAmountAjax($bankDetails['initial_balance']) . '</div>';
    echo '<div class="col-md-6 col-lg-4"><strong>As of Date:</strong> ' . (!empty($bankDetails['initial_balance_date']) ? date('M d, Y', strtotime($bankDetails['initial_balance_date'])) : '-') . '</div>';
    echo '<div class="col-12"><strong>Payment Methods:</strong> ';
    foreach ($bankDetails['payment_methods'] as $pm) {
        $label = ($pm == 'check') ? 'Check' : (($pm == 'online_transfer') ? 'Online Transfer' : 'Cash');
        echo '<span class="badge bg-secondary bg-opacity-10 text-dark me-1">' . $label . '</span>';
    }
    echo '</div></div></div>';
    
    if (empty($transactions) && (float)$opening['initial_balance'] <= 0) {
        echo '<div class="text-center py-5 text-muted">No transactions recorded for this bank yet.</div>';
    } else {
        echo '<div class="mb-3 p-3 border rounded" style="background:#e8f5e9"><strong>Initial Current Balance:</strong> ' . formatAmountAjax($opening['initial_balance']) . ' <span class="text-muted ms-2">As of: ' . htmlspecialchars(!empty($opening['initial_balance_date']) ? date('M d, Y', strtotime($opening['initial_balance_date'])) : '-') . '</span></div>';
        echo '<div class="table-responsive"><table class="table table-hover align-middle">';
        echo '<thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Deposit</th><th>Withdrawal</th><th>Balance</th><th>Encoded By</th></tr></thead><tbody>';
        if ((float)$opening['initial_balance'] > 0) {
            echo '<tr>';
            echo '<td>' . (!empty($opening['initial_balance_date']) ? date('M d, Y', strtotime($opening['initial_balance_date'])) : '-') . '</td>';
            echo '<td>OPENING</td>';
            echo '<td>Initial Current Balance</td>';
            echo '<td class="amount-positive">' . formatAmountAjax($opening['initial_balance']) . '</td>';
            echo '<td class="amount-negative">-</td>';
            echo '<td class="amount-neutral">' . formatAmountAjax($opening['initial_balance']) . '</td>';
            echo '<td>System</td>';
            echo '</tr>';
        }
        foreach ($transactions as $tx) {
            echo '<tr>';
            echo '<td>' . date('M d, Y', strtotime($tx['transaction_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($tx['reference_number'] ?: '-') . '</td>';
            echo '<td>' . htmlspecialchars($tx['description'] ?: '-') . '</td>';
            echo '<td class="amount-positive">' . ($tx['deposit'] > 0 ? formatAmountAjax($tx['deposit']) : '-') . '</td>';
            echo '<td class="amount-negative">' . ($tx['withdrawal'] > 0 ? formatAmountAjax($tx['withdrawal']) : '-') . '</td>';
            echo '<td class="amount-neutral">' . formatAmountAjax($tx['balance']) . '</td>';
            echo '<td>' . htmlspecialchars(trim($tx['created_by_name']) !== '' ? $tx['created_by_name'] : 'Unknown') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></div>';
    }
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim(($_SESSION['first_name'] ?? 'Branch') . ' ' . ($_SESSION['last_name'] ?? 'Admin'));
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = (bool)($_SESSION['view_all_branches'] ?? false);

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function formatAmount($value) {
    $value = (float)$value;
    if ($value == floor($value)) {
        return '₱' . number_format($value, 0);
    } else {
        return '₱' . number_format($value, 2);
    }
}

function tableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}
function columnExists($conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}
function createBanksTable($conn) {
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

    if (!columnExists($conn, 'banks', 'parent_bank_id')) {
        $conn->query("ALTER TABLE banks ADD COLUMN parent_bank_id int(11) DEFAULT NULL AFTER status");
    }
    if (!columnExists($conn, 'banks', 'initial_balance')) {
        $conn->query("ALTER TABLE banks ADD COLUMN initial_balance decimal(12,2) NOT NULL DEFAULT 0.00 AFTER parent_bank_id");
    }
    if (!columnExists($conn, 'banks', 'initial_balance_date')) {
        $conn->query("ALTER TABLE banks ADD COLUMN initial_balance_date date DEFAULT NULL AFTER initial_balance");
    }
    $idx = $conn->query("SHOW INDEX FROM banks WHERE Key_name = 'parent_bank_id'");
    if (!$idx || $idx->num_rows === 0) {
        $conn->query("ALTER TABLE banks ADD INDEX parent_bank_id (parent_bank_id)");
    }
}
function migrateInitialBalanceTransactions($conn) {
    if (!columnExists($conn, 'banks', 'initial_balance') || !columnExists($conn, 'banks', 'initial_balance_date')) return;
    if (!tableExists($conn, 'bank_transactions') || !columnExists($conn, 'bank_transactions', 'bank_id')) return;

    $sql = "SELECT bank_id, amount, transaction_date FROM bank_transactions WHERE transaction_type = 'deposit' AND bank_id IS NOT NULL AND bank_id > 0 AND (reference_number LIKE 'INITIAL_%' OR description LIKE 'Initial balance%') ORDER BY transaction_date ASC, transaction_id ASC";
    $res = $conn->query($sql);
    if (!$res) return;

    while ($row = $res->fetch_assoc()) {
        $bank_id = (int)$row['bank_id'];
        $amount = (float)$row['amount'];
        $date = date('Y-m-d', strtotime($row['transaction_date']));
        $stmt = $conn->prepare("UPDATE banks SET initial_balance = CASE WHEN initial_balance = 0 THEN ? ELSE initial_balance END, initial_balance_date = CASE WHEN initial_balance_date IS NULL THEN ? ELSE initial_balance_date END WHERE bank_id = ?");
        if ($stmt) {
            $stmt->bind_param('dsi', $amount, $date, $bank_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}
function createBankPaymentMethodsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS bank_payment_methods (
        id int(11) NOT NULL AUTO_INCREMENT,
        bank_id int(11) NOT NULL,
        payment_method enum('check','online_transfer','cash') NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_bank_method (bank_id,`payment_method`),
        KEY bank_id (bank_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
function getBankPaymentMethods($conn, $bank_id) {
    $methods = [];
    $stmt = $conn->prepare("SELECT payment_method FROM bank_payment_methods WHERE bank_id = ? ORDER BY payment_method");
    $stmt->bind_param('i', $bank_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $methods[] = $row['payment_method'];
    }
    $stmt->close();
    return $methods;
}
function getBanksHierarchical($conn, $view_all_branches, $branch_id, $active_only = true) {
    $sqlMain = "SELECT b.*, NULL AS parent_bank_name FROM banks b WHERE b.parent_bank_id IS NULL";
    if ($active_only) $sqlMain .= " AND b.status = 'active'";
    if (!$view_all_branches && $branch_id > 0) $sqlMain .= " AND (b.branch_id = ? OR b.branch_id = 0)";
    $sqlMain .= " ORDER BY b.bank_name ASC, b.bank_id ASC";

    $banks = [];
    $stmtMain = $conn->prepare($sqlMain);
    if ($stmtMain) {
        if (!$view_all_branches && $branch_id > 0) $stmtMain->bind_param('i', $branch_id);
        $stmtMain->execute();
        $mainRows = $stmtMain->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtMain->close();
        foreach ($mainRows as $main) {
            $main['is_sub_account'] = false;
            $banks[] = $main;
            $sqlSub = "SELECT b.*, pb.bank_name AS parent_bank_name FROM banks b LEFT JOIN banks pb ON b.parent_bank_id = pb.bank_id WHERE b.parent_bank_id = ?";
            if ($active_only) $sqlSub .= " AND b.status = 'active'";
            if (!$view_all_branches && $branch_id > 0) $sqlSub .= " AND (b.branch_id = ? OR b.branch_id = 0)";
            $sqlSub .= " ORDER BY b.bank_name ASC, b.bank_id ASC";
            $stmtSub = $conn->prepare($sqlSub);
            if ($stmtSub) {
                if (!$view_all_branches && $branch_id > 0) $stmtSub->bind_param('ii', $main['bank_id'], $branch_id);
                else $stmtSub->bind_param('i', $main['bank_id']);
                $stmtSub->execute();
                $subRows = $stmtSub->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmtSub->close();
                foreach ($subRows as $sub) {
                    $sub['is_sub_account'] = true;
                    $banks[] = $sub;
                }
            }
        }
    }
    foreach ($banks as &$bank) $bank['payment_methods'] = getBankPaymentMethods($conn, $bank['bank_id']);
    unset($bank);
    return $banks;
}

function createBankingTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS bank_transactions (
        transaction_id int(11) NOT NULL AUTO_INCREMENT,
        branch_id int(11) NOT NULL DEFAULT 0,
        transaction_type enum('deposit','withdrawal') NOT NULL,
        transaction_date datetime NOT NULL,
        reference_number varchar(100) DEFAULT NULL,
        bank_name varchar(150) DEFAULT NULL,
        bank_id int(11) DEFAULT NULL,
        description text DEFAULT NULL,
        amount decimal(12,2) NOT NULL DEFAULT 0.00,
        created_by int(11) NOT NULL DEFAULT 0,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (transaction_id),
        KEY branch_id (branch_id),
        KEY transaction_type (transaction_type),
        KEY transaction_date (transaction_date),
        KEY bank_id (bank_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    if (!columnExists($conn, 'bank_transactions', 'bank_id')) {
        $conn->query("ALTER TABLE bank_transactions ADD COLUMN bank_id int(11) DEFAULT NULL AFTER bank_name");
    }
    $idxBankTx = $conn->query("SHOW INDEX FROM bank_transactions WHERE Key_name = 'bank_id'");
    if (!$idxBankTx || $idxBankTx->num_rows === 0) {
        $conn->query("ALTER TABLE bank_transactions ADD INDEX bank_id (bank_id)");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS bank_transaction_payments (
        id int(11) NOT NULL AUTO_INCREMENT,
        transaction_id int(11) NOT NULL,
        payment_id int(11) NOT NULL,
        amount_applied decimal(12,2) NOT NULL DEFAULT 0.00,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY uniq_transaction_payment (transaction_id, payment_id),
        KEY payment_id (payment_id),
        KEY transaction_id (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
createBankingTables($conn);
createBanksTable($conn);
migrateInitialBalanceTransactions($conn);
createBankPaymentMethodsTable($conn);

// Handle Add Bank POST request (no sub-account)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bank_btn'])) {
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $bank_branch = trim($_POST['bank_branch'] ?? '');
    $payment_methods = $_POST['payment_methods'] ?? [];
    $is_sub_account = isset($_POST['is_sub_account']) && $_POST['is_sub_account'] == '1';
    $parent_bank_id = null;
    $status = 'active';
    $errors = [];
    
    $initial_balance = isset($_POST['initial_balance']) ? (float)$_POST['initial_balance'] : 0.0;
    $as_of_date = trim($_POST['as_of_date'] ?? '');
    
    if ($bank_name === '') $errors[] = 'Bank/Branch name is required.';
    if (empty($payment_methods)) $errors[] = 'Please select at least one payment method.';
    if ($is_sub_account) {
        $parent_bank_id = isset($_POST['parent_bank_id']) && $_POST['parent_bank_id'] !== '' ? (int)$_POST['parent_bank_id'] : 0;
        if ($parent_bank_id <= 0) $errors[] = 'Please select a parent bank account for this sub account.';
    }
    if ($initial_balance < 0) $errors[] = 'Initial balance cannot be negative.';
    if ($initial_balance > 0 && empty($as_of_date)) $errors[] = 'Please provide an "As of Date" when initial balance is positive.';
    
    if (empty($errors)) {
        $target_branch = $view_all_branches ? 0 : $branch_id;
        $conn->begin_transaction();
        try {
            $initial_balance_date = ($initial_balance > 0 && !empty($as_of_date)) ? $as_of_date : null;
            $stmt = $conn->prepare("INSERT INTO banks (branch_id, bank_name, account_name, account_number, bank_branch, status, parent_bank_id, initial_balance, initial_balance_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssssidsi', $target_branch, $bank_name, $account_name, $account_number, $bank_branch, $status, $parent_bank_id, $initial_balance, $initial_balance_date, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to insert bank: ' . $stmt->error);
            $bank_id = $conn->insert_id;
            $stmt->close();
            
            $pmStmt = $conn->prepare("INSERT INTO bank_payment_methods (bank_id, payment_method) VALUES (?, ?)");
            foreach ($payment_methods as $method) {
                $pmStmt->bind_param('is', $bank_id, $method);
                $pmStmt->execute();
            }
            $pmStmt->close();
                        
            $conn->commit();
            $msg = "Bank '{$bank_name}' has been added successfully.";
            if ($is_sub_account) $msg .= " Sub account registered.";
            if ($initial_balance > 0) $msg .= " Initial balance of " . formatAmount($initial_balance) . " saved as of " . date('M d, Y', strtotime($as_of_date)) . ".";
            $_SESSION['success_message'] = $msg;
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = implode(' ', $errors);
    }
    header("Location: bank_statement.php");
    exit();
}

// Handle Add Funds (manual deposit) POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_funds_btn'])) {
    $bank_id = (int)$_POST['bank_id'];
    $amount = (float)$_POST['amount'];
    $effective_date = trim($_POST['effective_date']);
    $description = trim($_POST['funds_description']);
    $errors = [];
    
    if ($bank_id <= 0) $errors[] = 'Invalid bank.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if (empty($effective_date)) $errors[] = 'Effective date is required.';
    
    if (empty($errors)) {
        $bankStmt = $conn->prepare("SELECT b.bank_name, b.branch_id, b.parent_bank_id, (SELECT COUNT(*) FROM banks c WHERE c.parent_bank_id = b.bank_id AND c.status = 'active') AS child_count FROM banks b WHERE b.bank_id = ?");
        $bankStmt->bind_param('i', $bank_id);
        $bankStmt->execute();
        $bank = $bankStmt->get_result()->fetch_assoc();
        $bankStmt->close();
        
        if (!$bank) {
            $_SESSION['error_message'] = 'Bank not found.';
        } elseif (empty($bank['parent_bank_id']) || (int)($bank['child_count'] ?? 0) > 0) {
            $_SESSION['error_message'] = 'Invalid bank selection. Please select a sub account. Parent banks are folders only.';
        } else {
            $bank_name = $bank['bank_name'];
            $target_branch = $view_all_branches ? 0 : $branch_id;
            $transaction_date = date('Y-m-d H:i:s', strtotime($effective_date . ' 00:00:00'));
            $ref_number = 'FUNDS_' . $bank_id . '_' . time();
            $desc = "Add funds: " . ($description ?: "Manual deposit");
            
            $txStmt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, bank_id, description, amount, created_by) VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?, ?)");
            $txStmt->bind_param('isssisdi', $target_branch, $transaction_date, $ref_number, $bank_name, $bank_id, $desc, $amount, $user_id);
            if ($txStmt->execute()) {
                $_SESSION['success_message'] = "Added " . formatAmount($amount) . " to " . $bank_name . " as of " . date('M d, Y', strtotime($effective_date)) . ".";
            } else {
                $_SESSION['error_message'] = 'Failed to add funds: ' . $txStmt->error;
            }
            $txStmt->close();
        }
    } else {
        $_SESSION['error_message'] = implode(' ', $errors);
    }
    header("Location: bank_statement.php");
    exit();
}

function getUserInitials($user_name) {
    $parts = preg_split('/\s+/', trim($user_name));
    $initials = '';
    foreach ($parts as $part) { if ($part !== '') $initials .= strtoupper(substr($part, 0, 1)); }
    return $initials !== '' ? substr($initials, 0, 2) : 'BA';
}
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
function getBankOpeningBalance($conn, $bank_id) {
    $opening = ['initial_balance' => 0.0, 'initial_balance_date' => null];
    if ((int)$bank_id <= 0 || !columnExists($conn, 'banks', 'initial_balance')) return $opening;
    $stmt = $conn->prepare("SELECT initial_balance, initial_balance_date FROM banks WHERE bank_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $bank_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $opening['initial_balance'] = (float)($row['initial_balance'] ?? 0);
            $opening['initial_balance_date'] = $row['initial_balance_date'] ?? null;
        }
    }
    return $opening;
}
function getBankTransactionsByBank($conn, $view_all_branches, $branch_id, $bank_id, $bank_name = '') {
    $hasBankId = columnExists($conn, 'bank_transactions', 'bank_id');
    $rows = [];

    if ($hasBankId && (int)$bank_id > 0) {
        $sql = "SELECT bt.transaction_id, bt.branch_id, bt.transaction_type, bt.transaction_date,
                       bt.reference_number, bt.bank_name, bt.description, bt.amount,
                       CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
                FROM bank_transactions bt
                LEFT JOIN users u ON bt.created_by = u.user_id
                WHERE bt.bank_id = ? AND NOT (bt.transaction_type = 'deposit' AND (bt.reference_number LIKE 'INITIAL_%' OR bt.description LIKE 'Initial balance%'))";
        if (!$view_all_branches && $branch_id > 0) $sql .= " AND bt.branch_id = ?";
        $sql .= " ORDER BY bt.transaction_date ASC, bt.transaction_id ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('ii', $bank_id, $branch_id);
            else $stmt->bind_param('i', $bank_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    if (empty($rows) && trim($bank_name) !== '') {
        $countSql = "SELECT COUNT(*) AS cnt FROM banks WHERE bank_name = ?";
        if (!$view_all_branches && $branch_id > 0) $countSql .= " AND (branch_id = ? OR branch_id = 0)";
        $cntStmt = $conn->prepare($countSql);
        $sameNameCount = 0;
        if ($cntStmt) {
            if (!$view_all_branches && $branch_id > 0) $cntStmt->bind_param('si', $bank_name, $branch_id);
            else $cntStmt->bind_param('s', $bank_name);
            $cntStmt->execute();
            $sameNameCount = (int)($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $cntStmt->close();
        }

        if ($sameNameCount <= 1) {
            $sql = "SELECT bt.transaction_id, bt.branch_id, bt.transaction_type, bt.transaction_date,
                           bt.reference_number, bt.bank_name, bt.description, bt.amount,
                           CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
                    FROM bank_transactions bt
                    LEFT JOIN users u ON bt.created_by = u.user_id
                    WHERE bt.bank_name = ? AND NOT (bt.transaction_type = 'deposit' AND (bt.reference_number LIKE 'INITIAL_%' OR bt.description LIKE 'Initial balance%'))";
            if ($hasBankId) $sql .= " AND (bt.bank_id IS NULL OR bt.bank_id = 0)";
            if (!$view_all_branches && $branch_id > 0) $sql .= " AND bt.branch_id = ?";
            $sql .= " ORDER BY bt.transaction_date ASC, bt.transaction_id ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('si', $bank_name, $branch_id);
                else $stmt->bind_param('s', $bank_name);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }
    }

    $opening = getBankOpeningBalance($conn, (int)$bank_id);
    $running_balance = (float)$opening['initial_balance'];
    foreach ($rows as &$tx) {
        $amount = (float)$tx['amount'];
        if ($tx['transaction_type'] === 'deposit') {
            $running_balance += $amount;
            $tx['deposit'] = $amount;
            $tx['withdrawal'] = 0.0;
        } else {
            $running_balance -= $amount;
            $tx['deposit'] = 0.0;
            $tx['withdrawal'] = $amount;
        }
        $tx['balance'] = $running_balance;
    }
    $rows = array_reverse($rows);
    return $rows;
}


function isParentBank($conn, $bank_id) {
    if ((int)$bank_id <= 0) return false;
    $stmt = $conn->prepare("SELECT COUNT(*) AS child_count FROM banks WHERE parent_bank_id = ? AND status = 'active'");
    if (!$stmt) return false;
    $stmt->bind_param('i', $bank_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['child_count'] ?? 0) > 0;
}

function computeBankCurrentBalance($conn, $view_all_branches, $branch_id, $bank) {
    $bank_id = (int)($bank['bank_id'] ?? 0);
    if ($bank_id <= 0) return 0.0;

    if (empty($bank['parent_bank_id']) && isParentBank($conn, $bank_id)) {
        return 0.0;
    }

    $txns = getBankTransactionsByBank($conn, $view_all_branches, $branch_id, $bank_id, $bank['bank_name'] ?? '');
    $opening = getBankOpeningBalance($conn, $bank_id);
    $balance = (float)$opening['initial_balance'];
    if (!empty($txns)) {
        $balance = (float)$txns[0]['balance'];
    }
    return $balance;
}

function buildParentBankRows($banks) {
    $parents = [];
    $childrenByParent = [];
    $orphans = [];

    foreach ($banks as $bank) {
        $parentId = (int)($bank['parent_bank_id'] ?? 0);
        if ($parentId > 0) {
            $childrenByParent[$parentId][] = $bank;
        } else {
            $parents[(int)$bank['bank_id']] = $bank;
        }
    }

    foreach ($childrenByParent as $parentId => $children) {
        if (!isset($parents[$parentId])) {
            foreach ($children as $child) $orphans[] = $child;
        }
    }

    $rows = [];
    foreach ($parents as $parentId => $parent) {
        $children = $childrenByParent[$parentId] ?? [];
        $parent['children'] = $children;
        $parent['folder_balance'] = array_sum(array_map(function($child) {
            return (float)($child['balance'] ?? 0);
        }, $children));
        $rows[] = $parent;
    }

    if (!empty($orphans)) {
        $rows[] = [
            'bank_id' => 0,
            'bank_name' => 'Unassigned Sub Accounts',
            'children' => $orphans,
            'folder_balance' => array_sum(array_map(function($child) {
                return (float)($child['balance'] ?? 0);
            }, $orphans)),
            'is_orphan_group' => true
        ];
    }

    return $rows;
}

$user_initials = getUserInitials($user_name);
$so_branch_column_exists = columnExists($conn, 'sales_orders', 'branch_id');
$invoices_has_so_id = columnExists($conn, 'invoices', 'so_id');

$flash_success = $_SESSION['success_message'] ?? '';
$flash_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$available_payments = getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$recent_payments = getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);

// Get all active banks with sub accounts ordered below their parent banks.
// Parent banks are folders only. Sub accounts carry the actual transactions and balances.
$banks = getBanksHierarchical($conn, $view_all_branches, $branch_id, true);
foreach ($banks as &$bank) {
    $opening = getBankOpeningBalance($conn, (int)$bank['bank_id']);
    $bank['initial_balance'] = (float)$opening['initial_balance'];
    $bank['initial_balance_date'] = $opening['initial_balance_date'];
    $bank['balance'] = computeBankCurrentBalance($conn, $view_all_branches, $branch_id, $bank);
}
unset($bank);

$parent_banks = array_values(array_filter($banks, function($b) { return empty($b['parent_bank_id']); }));
$has_parent_banks = count($parent_banks) > 0;
$parentBankRows = buildParentBankRows($banks);

$totalCashInBank = array_sum(array_map(function($parent) {
    return (float)($parent['folder_balance'] ?? 0);
}, $parentBankRows));
$totalCashOnHand = 0.0;

$undeposited_total = 0.0; foreach ($available_payments as $row) $undeposited_total += (float)$row['amount'];
$total_collections = 0.0; foreach ($recent_payments as $row) $total_collections += (float)$row['amount'];

$total_deposits = 0.0; $total_withdrawals = 0.0;
foreach ($banks as $b) {
    $txs = getBankTransactionsByBank($conn, $view_all_branches, $branch_id, (int)$b['bank_id'], $b['bank_name']);
    foreach ($txs as $tx) {
        if ($tx['transaction_type'] === 'deposit') $total_deposits += (float)$tx['amount'];
        else $total_withdrawals += (float)$tx['amount'];
    }
}
$total_initial_balances = array_sum(array_map(function($b) { return !empty($b['parent_bank_id']) ? (float)($b['initial_balance'] ?? 0) : 0.0; }, $banks));
$aggregate_balance = $total_initial_balances + $total_deposits - $total_withdrawals;

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_bank_balances') {
    header('Content-Type: application/json; charset=utf-8');
    $balanceBanks = getBanksHierarchical($conn, $view_all_branches, $branch_id, true);
    foreach ($balanceBanks as &$balanceBank) {
        $opening = getBankOpeningBalance($conn, (int)$balanceBank['bank_id']);
        $balanceBank['initial_balance'] = (float)$opening['initial_balance'];
        $balanceBank['initial_balance_date'] = $opening['initial_balance_date'];
        $balanceBank['balance'] = computeBankCurrentBalance($conn, $view_all_branches, $branch_id, $balanceBank);
    }
    unset($balanceBank);
    $balanceParents = buildParentBankRows($balanceBanks);
    $payload = ['success'=>true,'updated_at'=>date('Y-m-d H:i:s'),'banks'=>[],'parents'=>[],'aggregate_balance'=>0,'formatted_aggregate_balance'=>formatAmount(0)];
    foreach ($balanceBanks as $b) {
        $payload['banks'][(string)((int)$b['bank_id'])] = ['balance'=>(float)($b['balance'] ?? 0),'formatted_balance'=>formatAmount($b['balance'] ?? 0)];
    }
    $aggregateLiveBalance = 0.0;
    foreach ($balanceParents as $parent) {
        $parentId = (string)((int)($parent['bank_id'] ?? 0));
        $folderBalance = (float)($parent['folder_balance'] ?? 0);
        $aggregateLiveBalance += $folderBalance;
        $payload['parents'][$parentId] = ['folder_balance'=>$folderBalance,'formatted_folder_balance'=>formatAmount($folderBalance)];
    }
    $payload['aggregate_balance'] = $aggregateLiveBalance;
    $payload['formatted_aggregate_balance'] = formatAmount($aggregateLiveBalance);
    echo json_encode($payload);
    exit();
}
$page_title = 'Bank Statement'; $page_subtitle = 'All bank accounts and their statements'; $active_page = 'statement';
?>
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
.section-card{background:#fff;border-radius:18px;border:1px solid rgba(68,211,78,.12);box-shadow:0 8px 20px rgba(15,23,42,.05);margin-bottom:1rem;overflow:hidden}.section-header{padding:1rem 1.25rem;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}.section-body{padding:1rem 1.25rem}
.badge-soft-green{background:rgba(68,211,78,.16);color:#047857}.badge-soft-blue{background:rgba(34,211,238,.15);color:#0f766e}.badge-soft-red{background:rgba(248,113,113,.14);color:#b91c1c}
.table thead th{background-color:#f8f9fa;color:#052A47;border-bottom:2px solid #dee2e6;white-space:nowrap;font-weight:600}
.table tbody td{vertical-align:middle;font-size:.92rem}
.table-hover tbody tr:hover{background-color:#f5f5f5}
.clickable-bank-row{cursor:pointer}.clickable-bank-row:hover td{background-color:#f8fff9!important}.bank-name-link{color:#047857;font-weight:800;text-decoration:none}.bank-name-link:hover{text-decoration:underline}
.amount-positive{color:#047857;font-weight:700}.amount-negative{color:#dc2626;font-weight:700}.amount-neutral{color:#052A47;font-weight:700}
.form-control,.form-select{border-radius:10px;min-height:44px}
.btn-amgc-primary{background:linear-gradient(135deg,#047857 0%,#44D34E 100%);color:#fff;border:none;border-radius:999px;padding:8px 18px;min-height:36px;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 10px rgba(0,0,0,.15);transition:all .2s ease}.btn-amgc-primary:hover{color:#fff;transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.2);opacity:.95}.btn-amgc-dark{background:#052A47;color:#fff;border:none;border-radius:999px;padding:8px 18px;min-height:36px;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 4px 10px rgba(0,0,0,.15);transition:all .2s ease}.btn-amgc-dark:hover{color:#fff;transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.2);opacity:.96}.nav-tabs .nav-link{font-weight:700;color:#052A47}.nav-tabs .nav-link.active{color:#047857}.navbar-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}.mobile-menu-btn{display:none;background:transparent;border:none;font-size:1.5rem;color:#052A47}
.navbar-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}.mobile-menu-btn{display:none;background:transparent;border:none;font-size:1.5rem;color:#052A47}
@media(max-width:992px){.sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}.main-content{margin-left:0!important}.mobile-menu-btn{display:block}body{padding-bottom:70px}}@media(max-width:768px){.table-responsive{overflow-x:auto}.section-header{display:block}.stat-value{font-size:1.2rem}}
.bank-group-table{margin-bottom:1.5rem}.bank-group-table .group-header{background:#f8f9fa;font-weight:700;border-bottom:2px solid #dee2e6}.bank-group-table .group-header td{padding:12px 8px}.parent-folder-content{display:flex;align-items:center;justify-content:space-between;width:100%;gap:1rem}.parent-folder-name{display:flex;align-items:center;min-width:0;flex-wrap:wrap}.parent-total-cell,.sub-balance-cell{white-space:nowrap}.balance-updated{animation:balancePulse .8s ease}@keyframes balancePulse{0%{background:#d1fae5}100%{background:transparent}}.bank-group-table .child-row td{padding:8px 8px;background:#fff}.bank-group-table .child-row:hover{background:#f9fafb}.bank-name-link{cursor:pointer;color:#047857;text-decoration:none}.bank-name-link:hover{text-decoration:underline}.sub-account-row td:first-child{padding-left:2rem!important}.sub-account-marker{display:inline-flex;align-items:center;gap:.35rem;color:#6b7280;font-size:.78rem;margin-right:.35rem}.parent-bank-badge{background:#f3f4f6;padding:.2rem .55rem;border-radius:20px;font-size:.7rem;color:#4b5563}
.modal-xl{max-width:1140px}
/* Base modal styles */
#statementModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 900px !important;
}

@media (max-width: 768px) {
    #statementModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #statementModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#statementModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#statementModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
}

@media (max-width: 768px) {
    #statementModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#statementModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

@media (max-width: 576px) {
    #statementModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button */
#statementModal .modal-header .btn-close {
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

#statementModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
}

#statementModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

#statementModal .modal-body {
    padding: 1.25rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

@media (max-width: 576px) {
    #statementModal .modal-body {
        padding: 1rem !important;
    }
}

/* Account Details - walang card, simple lang */
#statementModal .modal-body > div:first-child {
    background: transparent !important;
    border: none !important;
    border-radius: 0 !important;
    margin-bottom: 1rem !important;
    padding: 0 !important;
}

#statementModal .modal-body > div:first-child h6 {
    color: #047857 !important;
    font-weight: 600 !important;
    margin-bottom: 0.75rem !important;
    font-size: 0.9rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}

#statementModal .modal-body > div:first-child .row {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 0.5rem 1rem !important;
    margin-bottom: 0.5rem !important;
}

@media (max-width: 768px) {
    #statementModal .modal-body > div:first-child .row {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

@media (max-width: 576px) {
    #statementModal .modal-body > div:first-child .row {
        grid-template-columns: 1fr !important;
        gap: 0.5rem !important;
    }
}

#statementModal .modal-body > div:first-child .col-md-6,
#statementModal .modal-body > div:first-child .col-lg-4 {
    background: transparent !important;
    padding: 0.25rem 0 !important;
    border: none !important;
}

#statementModal .modal-body > div:first-child strong {
    color: #6c757d !important;
    font-size: 0.7rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: inline-block !important;
    min-width: 110px !important;
    margin-bottom: 0 !important;
}

#statementModal .modal-body > div:first-child .col-md-6,
#statementModal .modal-body > div:first-child .col-lg-4 {
    font-weight: 500 !important;
    color: #1f2937 !important;
    font-size: 0.85rem !important;
}

/* Badge styles */
#statementModal .modal-body .badge.bg-secondary.bg-opacity-10 {
    background: #e8f5e9 !important;
    color: #047857 !important;
    padding: 0.25rem 0.75rem !important;
    border-radius: 20px !important;
    font-weight: 500 !important;
    font-size: 0.7rem !important;
    margin-right: 0.25rem !important;
}

/* Initial Balance Section */
#statementModal .modal-body .mb-3.p-3.border.rounded {
    background: #f0fdf4 !important;
    border: 1px solid #bbf7d0 !important;
    border-radius: 12px !important;
    margin-bottom: 1rem !important;
    padding: 0.75rem 1rem !important;
}

#statementModal .modal-body .mb-3.p-3.border.rounded strong {
    color: #047857 !important;
    font-weight: 600 !important;
}

/* Table Styles */
#statementModal .modal-body .table-responsive {
    border-radius: 16px !important;
    border: 1px solid #e9ecef !important;
    overflow-x: auto !important;
    background: white !important;
}

#statementModal .modal-body .table {
    margin-bottom: 0 !important;
    font-size: 0.85rem !important;
}

#statementModal .modal-body .table thead th {
    background: #f8fafc !important;
    color: #1f2937 !important;
    font-weight: 600 !important;
    padding: 0.875rem 1rem !important;
    border-bottom: 2px solid #e9ecef !important;
    white-space: nowrap !important;
}

#statementModal .modal-body .table tbody td {
    padding: 0.875rem 1rem !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #e9ecef !important;
    color: #475569 !important;
}

#statementModal .modal-body .table tbody tr:hover {
    background: #f8fafc !important;
}

/* Amount colors */
#statementModal .modal-body .amount-positive {
    color: #059669 !important;
    font-weight: 600 !important;
}

#statementModal .modal-body .amount-negative {
    color: #dc2626 !important;
    font-weight: 600 !important;
}

#statementModal .modal-body .amount-neutral {
    color: #1f2937 !important;
    font-weight: 600 !important;
}

/* Empty state */
#statementModal .modal-body .text-center.py-5.text-muted {
    text-align: center !important;
    padding: 3rem 1.5rem !important;
    background: white !important;
    border-radius: 16px !important;
    border: 1px solid #e9ecef !important;
    color: #6c757d !important;
}

#statementModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
}

@media (max-width: 576px) {
    #statementModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

#statementModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #statementModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
}

#statementModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#statementModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#statementModal .modal-footer .btn-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    color: white !important;
}

#statementModal .modal-footer .btn-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* Mobile Table View */
@media (max-width: 768px) {
    #statementModal .modal-body .table thead {
        display: none !important;
    }
    
    #statementModal .modal-body .table tbody tr {
        display: block !important;
        background: white !important;
        border-radius: 12px !important;
        margin-bottom: 0.75rem !important;
        padding: 0.75rem !important;
        border: 1px solid #e9ecef !important;
    }
    
    #statementModal .modal-body .table tbody td {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 0.5rem 0 !important;
        border: none !important;
        border-bottom: 1px solid #e9ecef !important;
        font-size: 0.75rem !important;
        text-align: right !important;
    }
    
    #statementModal .modal-body .table tbody td:last-child {
        border-bottom: none !important;
    }
    
    #statementModal .modal-body .table tbody td::before {
        content: attr(data-label) !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        min-width: 100px !important;
        text-align: left !important;
        font-size: 0.7rem !important;
        text-transform: uppercase !important;
    }
}

/* Scrollbar */
#statementModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#statementModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#statementModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #statementModal .modal-content {
        max-height: 95vh !important;
    }
}
/* Add Bank Modal Styles - fixed scrolling issue */
#addBankModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 900px !important;
}

@media (max-width: 768px) {
    #addBankModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #addBankModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#addBankModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#addBankModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

@media (max-width: 768px) {
    #addBankModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#addBankModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#addBankModal .modal-header .modal-title i {
    color: white !important;
}

@media (max-width: 576px) {
    #addBankModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button */
#addBankModal .modal-header .btn-close {
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
    #addBankModal .modal-header .btn-close {
        width: 30px !important;
        height: 30px !important;
    }
}

#addBankModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
    font-family: system-ui, -apple-system, sans-serif !important;
}

@media (max-width: 576px) {
    #addBankModal .modal-header .btn-close::before {
        font-size: 0.9rem !important;
    }
}

#addBankModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

/* MODAL BODY - important: dapat may overflow-y auto para mag-scroll */
#addBankModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    background: #f8fafc !important;
    max-height: calc(90vh - 130px) !important; /* I-adjust para may space para sa footer */
}

@media (max-width: 576px) {
    #addBankModal .modal-body {
        padding: 1rem !important;
        max-height: calc(90vh - 110px) !important;
    }
}

/* MODAL FOOTER - fixed sa bottom */
#addBankModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    position: relative !important;
    z-index: 1 !important;
}

@media (max-width: 576px) {
    #addBankModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

/* Form Styles */
#addBankModal .form-label {
    font-weight: 600 !important;
    font-size: 0.8rem !important;
    margin-bottom: 0.5rem !important;
    color: #1f2937 !important;
}

#addBankModal .form-control,
#addBankModal .form-select {
    border-radius: 10px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 0.6rem 0.75rem !important;
    font-size: 0.85rem !important;
    transition: all 0.2s ease !important;
}

#addBankModal .form-control:focus,
#addBankModal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1) !important;
    outline: none !important;
}

#addBankModal .form-text {
    font-size: 0.7rem !important;
    color: #6c757d !important;
    margin-top: 0.25rem !important;
}

#addBankModal .form-check-input {
    width: 1.1rem !important;
    height: 1.1rem !important;
    margin-top: 0.15rem !important;
    cursor: pointer !important;
}

#addBankModal .form-check-input:checked {
    background-color: #047857 !important;
    border-color: #047857 !important;
}

#addBankModal .form-check-label {
    font-size: 0.85rem !important;
    color: #1f2937 !important;
    cursor: pointer !important;
}

#addBankModal .form-check-inline {
    margin-right: 1rem !important;
}

/* Alert box */
#addBankModal .alert-light.border {
    background: #f0fdf4 !important;
    border: 1px solid #bbf7d0 !important;
    border-radius: 12px !important;
    color: #047857 !important;
}

#addBankModal .alert-light.border i {
    color: #047857 !important;
}

/* Modal Footer Buttons */
#addBankModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #addBankModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
}

#addBankModal .modal-footer .btn-outline-secondary {
    background: transparent !important;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
}

#addBankModal .modal-footer .btn-outline-secondary:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    transform: translateY(-1px) !important;
}

#addBankModal .modal-footer .btn-amgc-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    color: white !important;
}

#addBankModal .modal-footer .btn-amgc-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* Parent Bank Wrapper transition */
#parentBankWrapper {
    transition: all 0.3s ease !important;
}

/* Scrollbar */
#addBankModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#addBankModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#addBankModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #addBankModal .modal-content {
        max-height: 95vh !important;
    }
    
    #addBankModal .modal-body {
        padding: 0.75rem !important;
        max-height: calc(95vh - 100px) !important;
    }
    
    #addBankModal .row.mb-3 {
        margin-bottom: 0.5rem !important;
    }
}
/* Add Funds Modal Styles - same style as viewCustomerModal */
#addFundsModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 500px !important;
}

@media (max-width: 768px) {
    #addFundsModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #addFundsModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#addFundsModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#addFundsModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

@media (max-width: 768px) {
    #addFundsModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#addFundsModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#addFundsModal .modal-header .modal-title i {
    color: white !important;
}

@media (max-width: 576px) {
    #addFundsModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button */
#addFundsModal .modal-header .btn-close {
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
    #addFundsModal .modal-header .btn-close {
        width: 30px !important;
        height: 30px !important;
    }
}

#addFundsModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
    font-family: system-ui, -apple-system, sans-serif !important;
}

@media (max-width: 576px) {
    #addFundsModal .modal-header .btn-close::before {
        font-size: 0.9rem !important;
    }
}

#addFundsModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#addFundsModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    background: #f8fafc !important;
    max-height: calc(90vh - 130px) !important;
}

@media (max-width: 576px) {
    #addFundsModal .modal-body {
        padding: 1rem !important;
        max-height: calc(90vh - 110px) !important;
    }
}

/* Form Styles */
#addFundsModal .form-label {
    font-weight: 600 !important;
    font-size: 0.8rem !important;
    margin-bottom: 0.5rem !important;
    color: #1f2937 !important;
}

#addFundsModal .form-control,
#addFundsModal .form-select {
    border-radius: 10px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 0.6rem 0.75rem !important;
    font-size: 0.85rem !important;
    transition: all 0.2s ease !important;
}

#addFundsModal .form-control:focus,
#addFundsModal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1) !important;
    outline: none !important;
}

#addFundsModal .form-control:disabled,
#addFundsModal .form-control[readonly] {
    background-color: #f1f5f9 !important;
    cursor: not-allowed !important;
}

#addFundsModal .form-text {
    font-size: 0.7rem !important;
    color: #6c757d !important;
    margin-top: 0.25rem !important;
}

/* Textarea */
#addFundsModal textarea.form-control {
    resize: vertical !important;
}

/* Alert Box */
#addFundsModal .alert-info {
    background: #f0fdf4 !important;
    border: 1px solid #bbf7d0 !important;
    border-radius: 12px !important;
    color: #047857 !important;
    font-size: 0.8rem !important;
    padding: 0.75rem 1rem !important;
}

#addFundsModal .alert-info i {
    color: #047857 !important;
}

/* Modal Footer */
#addFundsModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    position: relative !important;
    z-index: 1 !important;
}

@media (max-width: 576px) {
    #addFundsModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

#addFundsModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #addFundsModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
}

#addFundsModal .modal-footer .btn-outline-secondary {
    background: transparent !important;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
}

#addFundsModal .modal-footer .btn-outline-secondary:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    transform: translateY(-1px) !important;
}

#addFundsModal .modal-footer .btn-amgc-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    color: white !important;
}

#addFundsModal .modal-footer .btn-amgc-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* Scrollbar */
#addFundsModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#addFundsModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#addFundsModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #addFundsModal .modal-content {
        max-height: 95vh !important;
    }
    
    #addFundsModal .modal-body {
        padding: 0.75rem !important;
        max-height: calc(95vh - 100px) !important;
    }
    
    #addFundsModal .mb-3 {
        margin-bottom: 0.5rem !important;
    }
}

/* SweetAlert AMGC style */
.swal2-popup.amgc-swal-popup{
    border-radius:20px !important;
    border:1px solid rgba(4,120,87,.15) !important;
    box-shadow:0 18px 45px rgba(5,42,71,.18) !important;
}
.swal2-title.amgc-swal-title{
    color:#052A47 !important;
    font-weight:600 !important;
}
.swal2-html-container.amgc-swal-html{
    font-weight:400 !important;
    color:#374151 !important;
}
.swal2-confirm.amgc-swal-confirm{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%) !important;
    border:0 !important;
    border-radius:12px !important;
    box-shadow:0 6px 14px rgba(4,120,87,.25) !important;
    font-weight:500 !important;
}
.swal2-cancel.amgc-swal-cancel{
    background:#6c757d !important;
    border:0 !important;
    border-radius:12px !important;
    font-weight:500 !important;
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

                <!-- Vendor Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
                        <i class="bi bi-people"></i>
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
                                    <i class="bi bi-people"></i>
                                    <span class="nav-text">Vendor List</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Customer Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                        <i class="bi bi-people"></i>
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
                        <i class="bi bi-people"></i>
                        <span class="nav-text">Employees</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="employeesMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="employeelist.php">
                                    <i class="bi bi-people"></i>
                                    <span class="nav-text">Employee List</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="employee.php">
                                    <i class="bi bi-clock"></i>
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
                                <a class="nav-link active" href="bank_statement.php">
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
                                <a class="nav-link" href="batch_transaction.php">
                                    <i class="bi bi-collection"></i>
                                    <span class="nav-text">Batch Transaction</span>
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

<div class="main-content" id="mainContent"><div id="dashboardContent" class="page-content active">
<div class="navbar-top no-print"><button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button><div class="page-title"><h2><?php echo h($page_title); ?></h2><p><?php echo h($page_subtitle); ?></p></div></div>

<div class="row g-3 mb-4">
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Undeposited Funds</div><div class="stat-value"><?php echo formatAmount($undeposited_total); ?></div><div class="page-note"><?php echo count($available_payments); ?> payment(s) waiting</div></div><div class="stat-icon"><i class="bi bi-wallet2"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Total Collections</div><div class="stat-value"><?php echo formatAmount($total_collections); ?></div><div class="page-note">Latest recorded payments</div></div><div class="stat-icon"><i class="bi bi-cash-coin"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Total Deposits (All Banks)</div><div class="stat-value"><?php echo formatAmount($total_deposits); ?></div><div class="page-note">Posted to bank accounts</div></div><div class="stat-icon"><i class="bi bi-arrow-down-circle"></i></div></div></div>
<div class="col-md-6 col-xl-3"><div class="stat-card-banking d-flex justify-content-between align-items-center"><div><div class="stat-label">Aggregate Bank Balance</div><div class="stat-value" id="aggregateBankBalanceValue"><?php echo formatAmount($aggregate_balance); ?></div><div class="page-note">Initial + Deposit - Withdrawal</div></div><div class="stat-icon"><i class="bi bi-bank"></i></div></div></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Bank Accounts</h5>
    <button class="btn btn-amgc-primary" data-bs-toggle="modal" data-bs-target="#addBankModal"><i class="bi bi-plus-circle me-1"></i> Add Bank</button>
</div>

<div class="table-responsive">
    <table class="table table-hover align-middle bank-group-table">
        <thead>
            <tr><th>Bank Name</th><th class="text-end">Balance</th><th class="text-end">Total</th></tr>
        </thead>
        <tbody>
            <?php if (!empty($parentBankRows)): ?>
                <?php foreach ($parentBankRows as $parent): ?>
                    <tr class="group-header parent-folder-row" data-parent-id="<?php echo (int)$parent['bank_id']; ?>">
                        <td>
                            <div class="parent-folder-name">
                                <strong><?php echo h($parent['bank_name']); ?></strong>
                            </div>
                        </td>
                        <td class="text-end text-muted">-</td>
                        <td class="text-end amount-neutral fw-bold parent-total-cell" data-parent-total-id="<?php echo (int)$parent['bank_id']; ?>"><?php echo formatAmount($parent['folder_balance'] ?? 0); ?></td>
                    </tr>

                    <?php if (!empty($parent['children'])): ?>
                        <?php foreach ($parent['children'] as $sub): ?>
                            <tr class="child-row clickable-bank-row sub-account-row" data-bank-id="<?php echo (int)$sub['bank_id']; ?>" data-bank-name="<?php echo h($sub['bank_name']); ?>">
                                <td>
                                    <span class="bank-name-link"><?php echo h($sub['bank_name']); ?></span>
                                </td>
                                <td class="text-end amount-neutral sub-balance-cell" data-bank-balance-id="<?php echo (int)$sub['bank_id']; ?>"><?php echo formatAmount($sub['balance'] ?? 0); ?></td>
                                <td class="text-end text-muted">-</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="child-row">
                            <td class="text-muted" style="padding-left:2rem!important"><i class="bi bi-info-circle me-1"></i>No sub accounts yet</td>
                            <td class="text-end text-muted">-</td>
                            <td class="text-end text-muted">-</td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No registered banks found. Click "Add Bank" to register a bank account.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div></div>

</div></div></div>

<!-- MODAL ADD BANK - 3 COLUMNS, SUB ACCOUNT CHECKBOX BELOW BANK NAME -->
<div class="modal fade" id="addBankModal" tabindex="-1" aria-labelledby="addBankModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="border-radius:20px">
      <form method="POST" action="" id="addBankForm">
        <div class="modal-header" style="border-bottom:1px solid #eef2f7">
          <h5 class="modal-title" id="addBankModalLabel"><i class="bi bi-building-add me-2" style="color:#047857"></i>Register New Bank</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            
            <!-- Row 1: Bank/Branch Name (full width) -->
            <div class="row mb-3">
                <div class="col-12">
                    <label class="form-label">Bank/Branch Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="bank_name" required placeholder="e.g., BPI, BDO, Petty Cash">
                </div>
            </div>
            
            <!-- Row 2: Sub Account Checkbox (below Bank Name) -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="isSubAccountCheckbox" name="is_sub_account" value="1" <?php echo !$has_parent_banks ? 'disabled' : ''; ?>>
                        <label class="form-check-label" for="isSubAccountCheckbox">
                            Sub Account <i class="bi bi-diagram-2"></i>
                            <span class="text-muted">(under an existing bank/branch)</span>
                        </label>
                        <?php if (!$has_parent_banks): ?>
                            <div class="form-text text-warning">Add a main bank first before creating a sub account.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Row 3: Parent Bank Dropdown (hidden initially) -->
            <div id="parentBankWrapper" class="row mb-3" style="display:none;">
                <div class="col-12">
                    <label class="form-label">Parent Bank Account <span class="text-danger">*</span></label>
                    <select class="form-select" name="parent_bank_id" id="parentBankSelect">
                        <option value="">-- Select Parent Bank --</option>
                        <?php foreach ($parent_banks as $bankOption): ?>
                            <option value="<?php echo (int)$bankOption['bank_id']; ?>">
                                <?php echo h($bankOption['bank_name']); ?>
                                <?php if (!empty($bankOption['account_name'])): ?> - <?php echo h($bankOption['account_name']); ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Sub account will appear indented under the parent in the table.</div>
                </div>
            </div>
            
            <!-- Row 4: 3 columns for Location, Account Name, Account Number -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Bank/Branch Location (Optional)</label>
                    <input type="text" class="form-control" name="bank_branch" placeholder="e.g., Makati Branch">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Account Name (Optional)</label>
                    <input type="text" class="form-control" name="account_name" placeholder="Business name or Account holder">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Account Number (Optional)</label>
                    <input type="text" class="form-control" name="account_number" placeholder="Bank account number">
                </div>
            </div>
            
            <!-- Row 5: 3 columns for Initial Balance, As of Date, (empty) -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Initial Current Balance (₱)</label>
                    <input type="number" step="0.01" class="form-control" name="initial_balance" value="0.00" placeholder="0.00">
                    <div class="form-text">If existing balance, enter here</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">As of Date</label>
                    <input type="date" class="form-control" name="as_of_date" value="<?php echo date('Y-m-d'); ?>">
                    <div class="form-text">Required if initial balance > 0</div>
                </div>
                <div class="col-md-4">
                    <!-- Reserved for future use -->
                </div>
            </div>
            
            <!-- Payment Methods (full width) -->
            <div class="mb-3">
                <label class="form-label">Payment Methods <span class="text-danger">*</span> (select one or more)</label>
                <div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="payment_methods[]" value="check" id="pmCheck">
                        <label class="form-check-label" for="pmCheck">Check</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="payment_methods[]" value="online_transfer" id="pmOnline">
                        <label class="form-check-label" for="pmOnline">Online Transfer</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="payment_methods[]" value="cash" id="pmCash">
                        <label class="form-check-label" for="pmCash">Cash</label>
                    </div>
                </div>
                <div class="form-text">Select payment methods accepted for this account.</div>
            </div>
            
            <div class="alert alert-light border mt-2" style="background:#f9fafb; font-size:0.85rem">
                <i class="bi bi-info-circle-fill me-1"></i> The bank will be active immediately.
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_bank_btn" class="btn btn-amgc-primary">Add Bank</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL ADD FUNDS -->
<div class="modal fade" id="addFundsModal" tabindex="-1" aria-labelledby="addFundsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:20px">
      <form method="POST" action="">
        <div class="modal-header" style="border-bottom:1px solid #eef2f7">
          <h5 class="modal-title" id="addFundsModalLabel"><i class="bi bi-cash-stack me-2" style="color:#047857"></i>Add Funds to Bank</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="bank_id" id="fundsBankId">
            <div class="mb-3">
                <label class="form-label">Bank Name</label>
                <input type="text" class="form-control" id="fundsBankName" readonly disabled>
            </div>
            <div class="mb-3">
                <label class="form-label">Amount <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="fundsAmount" required placeholder="Enter amount">
            </div>
            <div class="mb-3">
                <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="effective_date" id="fundsDate" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Description (Optional)</label>
                <textarea class="form-control" name="funds_description" id="fundsDesc" rows="2" placeholder="e.g., Cash injection, replenishment"></textarea>
            </div>
            <div class="alert alert-info">
                <i class="bi bi-info-circle-fill me-1"></i> This will create a deposit transaction and increase the bank's balance.
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_funds_btn" class="btn btn-amgc-primary">Add Funds</button>
        </div>
      </form>
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


<!-- MODAL VIEW STATEMENT - Styled like viewCustomerModal -->
<div class="modal fade" id="statementModal" tabindex="-1" aria-labelledby="statementModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="statementModalLabel">
          <i class="bi bi-receipt me-2"></i>Bank Statement - <span id="statementBankName"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="statementModalBody">
        <div class="text-center py-5">
          <div class="spinner-border text-primary"></div> Loading...
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="statementAddFundsBtn"><i class="bi bi-cash-stack me-1"></i> Add Funds</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const flashSuccessMessage = <?php echo json_encode($flash_success); ?>;
const flashErrorMessage = <?php echo json_encode($flash_error); ?>;

const amgcSwalDefaults = {
    confirmButtonColor: '#047857',
    cancelButtonColor: '#6c757d',
    buttonsStyling: true,
    customClass: {
        popup: 'amgc-swal-popup',
        title: 'amgc-swal-title',
        htmlContainer: 'amgc-swal-html',
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

function logout(){Swal.fire({title:'Are you sure?',text:'You will be logged out of the system',icon:'question',showCancelButton:true,confirmButtonColor:'#07d826',cancelButtonColor:'#6c757d',confirmButtonText:'Yes, logout'}).then((r)=>{if(r.isConfirmed)window.location.href='../logout.php';})}

const banksData = <?php
    $bankJson = [];
    foreach ($parent_banks as $b) {
        $bankJson[] = ['bank_id' => (int)$b['bank_id'], 'bank_name' => $b['bank_name'] ?? '', 'bank_branch' => $b['bank_branch'] ?? '', 'account_name' => $b['account_name'] ?? '', 'payment_methods' => $b['payment_methods'] ?? []];
    }
    echo json_encode($bankJson);
?>;

document.addEventListener('DOMContentLoaded',function(){
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

    const subCheckbox = document.getElementById('isSubAccountCheckbox');
    const parentSelect = document.getElementById('parentBankSelect');
    const parentWrapper = document.getElementById('parentBankWrapper');
    if (subCheckbox && parentWrapper) {
        subCheckbox.addEventListener('change', function() {
            if (this.checked) {
                parentWrapper.style.display = 'flex'; // show the row
                if (parentSelect) parentSelect.required = true;
            } else {
                parentWrapper.style.display = 'none';
                if (parentSelect) { parentSelect.required = false; parentSelect.value = ''; }
            }
        });
    }
    
    // View Statement Modal
    const statementModal = new bootstrap.Modal(document.getElementById('statementModal'));
    const statementModalBody = document.getElementById('statementModalBody');
    const statementBankNameSpan = document.getElementById('statementBankName');
    const statementAddFundsBtn = document.getElementById('statementAddFundsBtn');
    let selectedBankId = '';
    let selectedBankName = '';

    async function openStatementModal(bankId, bankName) {
        selectedBankId = bankId;
        selectedBankName = bankName;
        statementBankNameSpan.innerText = bankName;
        statementModalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div> Loading transactions...</div>';
        statementModal.show();

        try {
            const response = await fetch(`bank_statement.php?ajax=get_transactions&bank_id=${encodeURIComponent(bankId)}&bank_name=${encodeURIComponent(bankName)}`);
            const html = await response.text();
            statementModalBody.innerHTML = html;
        } catch (err) {
            statementModalBody.innerHTML = '<div class="alert alert-danger">Failed to load transactions.</div>';
        }
    }

    document.querySelectorAll('.clickable-bank-row').forEach(row => {
        row.addEventListener('click', function() {
            openStatementModal(this.getAttribute('data-bank-id'), this.getAttribute('data-bank-name'));
        });
    });

    // Add Funds Modal - inside the statement modal only
    const fundsModal = new bootstrap.Modal(document.getElementById('addFundsModal'));

    function openAddFundsModal(bankId, bankName) {
        document.getElementById('fundsBankId').value = bankId;
        document.getElementById('fundsBankName').value = bankName;
        document.getElementById('fundsAmount').value = '';
        document.getElementById('fundsDesc').value = '';
        fundsModal.show();
    }

    if (statementAddFundsBtn) {
        statementAddFundsBtn.addEventListener('click', function() {
            if (!selectedBankId) return;
            statementModal.hide();
            setTimeout(function() {
                openAddFundsModal(selectedBankId, selectedBankName);
            }, 180);
        });
    }
    async function refreshBankBalances(showPulse = false) {
        try {
            const response = await fetch('bank_statement.php?ajax=get_bank_balances', { cache: 'no-store' });
            const data = await response.json();
            if (!data || !data.success) return;
            Object.keys(data.banks || {}).forEach(function(bankId) {
                const cell = document.querySelector(`[data-bank-balance-id="${bankId}"]`);
                if (!cell) return;
                const newValue = data.banks[bankId].formatted_balance || '₱0';
                if (cell.textContent.trim() !== newValue) {
                    cell.textContent = newValue;
                    if (showPulse) { cell.classList.remove('balance-updated'); void cell.offsetWidth; cell.classList.add('balance-updated'); }
                }
            });
            Object.keys(data.parents || {}).forEach(function(parentId) {
                const cell = document.querySelector(`[data-parent-total-id="${parentId}"]`);
                if (!cell) return;
                const newValue = data.parents[parentId].formatted_folder_balance || '₱0';
                if (cell.textContent.trim() !== newValue) {
                    cell.textContent = newValue;
                    if (showPulse) { cell.classList.remove('balance-updated'); void cell.offsetWidth; cell.classList.add('balance-updated'); }
                }
            });
            const aggregateValue = document.getElementById('aggregateBankBalanceValue');
            if (aggregateValue && data.formatted_aggregate_balance) aggregateValue.textContent = data.formatted_aggregate_balance;
        } catch (err) { console.warn('Balance refresh failed:', err); }
    }

    const addFundsForm = document.getElementById('addFundsForm');
    if (addFundsForm) {
        addFundsForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const submitBtn = addFundsForm.querySelector('button[name="add_funds_btn"]');
            const originalText = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...'; }
            try {
                const formData = new FormData(addFundsForm);
                formData.append('add_funds_btn', '1');
                const response = await fetch('bank_statement.php', { method:'POST', body:formData, headers:{'X-Requested-With':'XMLHttpRequest'} });
                if (!response.ok) throw new Error('Failed to add funds.');
                fundsModal.hide();
                await refreshBankBalances(true);
                amgcSwalFire({icon:'success',title:'Funds added',text:'The bank balance has been updated.'});
                if (selectedBankId) setTimeout(function(){ openStatementModal(selectedBankId, selectedBankName); }, 250);
            } catch (err) {
                amgcSwalFire({icon:'error',title:'Error',text:err.message || 'Failed to add funds.'});
            } finally {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; }
            }
        });
    }

    refreshBankBalances(false);
    setInterval(function(){ refreshBankBalances(true); }, 8000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) refreshBankBalances(true); });
    window.addEventListener('focus', function(){ refreshBankBalances(true); });
});
</script>
</body></html>