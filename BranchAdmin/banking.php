<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();

// Check if database config exists
if (!file_exists('../config/database.php')) {
    die('Database configuration file not found. Please ensure ../config/database.php exists.');
}

require_once '../config/database.php';

// Check if database connection was successful
if (!isset($conn) || !$conn || $conn->connect_error) {
    die('Database connection failed: ' . (isset($conn) ? $conn->connect_error : 'Connection variable not set'));
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = trim(($_SESSION['first_name'] ?? 'Branch') . ' ' . ($_SESSION['last_name'] ?? 'Admin'));
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = (bool)($_SESSION['view_all_branches'] ?? false);

define('BANKING_PAGE_TITLE', 'Banking');

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
    if (!$conn) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function getUserInitials($user_name) {
    $parts = preg_split('/\s+/', trim($user_name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return $initials !== '' ? substr($initials, 0, 2) : 'BA';
}

function getBranchName($conn, $branch_id, $view_all_branches) {
    if (!$conn) return 'Branch';
    if ($view_all_branches || $branch_id <= 0) {
        return 'All Branches';
    }
    $name = 'Branch';
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $name = $row['branch_name'];
        }
        $stmt->close();
    }
    return $name;
}

function createPaymentsTableIfMissing($conn) {
    if (!$conn) return;
    if (tableExists($conn, 'payments')) {
        return;
    }
    $conn->query("CREATE TABLE IF NOT EXISTS `payments` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function createBankingTables($conn) {
    if (!$conn) return;
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_transactions` (
        `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `transaction_type` enum('deposit','withdrawal') NOT NULL,
        `transaction_date` datetime NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `bank_name` varchar(150) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transaction_id`),
        KEY `branch_id` (`branch_id`),
        KEY `transaction_type` (`transaction_type`),
        KEY `transaction_date` (`transaction_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

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
}

// Create tables if missing
createPaymentsTableIfMissing($conn);
createBankingTables($conn);

$user_initials = getUserInitials($user_name);
$branch_name = getBranchName($conn, $branch_id, $view_all_branches);

// Check column existence safely
$so_branch_column_exists = $conn ? columnExists($conn, 'sales_orders', 'branch_id') : false;
$customers_branch_column_exists = $conn ? columnExists($conn, 'customers', 'branch_id') : false;
$invoices_has_so_id = $conn ? columnExists($conn, 'invoices', 'so_id') : false;

function getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    if (!$conn) return [];
    
    $sql = "SELECT
                p.payment_id,
                p.invoice_id,
                p.customer_id,
                p.payment_method,
                p.amount,
                p.payment_date,
                p.reference_number,
                p.bank_name,
                p.cash_tendered,
                p.cash_change,
                c.customer_name,
                i.invoice_number,
                COALESCE(so.so_number, '') AS so_number,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            " . ($invoices_has_so_id ? "LEFT JOIN sales_orders so ON i.so_id = so.so_id" : "LEFT JOIN sales_orders so ON 1=0") . "
            LEFT JOIN users u ON p.created_by = u.user_id
            LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
            WHERE btp.payment_id IS NULL";

    if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) {
        $sql .= " AND so.branch_id = ?";
    }

    $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) {
            $stmt->bind_param('i', $branch_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getBankTransactions($conn, $view_all_branches, $branch_id) {
    if (!$conn) return [];
    
    $sql = "SELECT
                bt.transaction_id,
                bt.branch_id,
                bt.transaction_type,
                bt.transaction_date,
                bt.reference_number,
                bt.bank_name,
                bt.description,
                bt.amount,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name,
                GROUP_CONCAT(DISTINCT p.payment_id ORDER BY p.payment_id SEPARATOR ',') AS payment_ids,
                GROUP_CONCAT(DISTINCT CONCAT(COALESCE(c.customer_name, 'Unknown'), ' / ', COALESCE(i.invoice_number, CONCAT('INV-', p.invoice_id)), ' / ₱', FORMAT(btp.amount_applied, 2)) ORDER BY p.payment_id SEPARATOR '||') AS payment_links
            FROM bank_transactions bt
            LEFT JOIN users u ON bt.created_by = u.user_id
            LEFT JOIN bank_transaction_payments btp ON bt.transaction_id = btp.transaction_id
            LEFT JOIN payments p ON btp.payment_id = p.payment_id
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            WHERE 1=1";
    if (!$view_all_branches && $branch_id > 0) {
        $sql .= " AND bt.branch_id = ?";
    }
    $sql .= " GROUP BY bt.transaction_id ORDER BY bt.transaction_date ASC, bt.transaction_id ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) {
            $stmt->bind_param('i', $branch_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    if (!$conn) return [];
    
    $sql = "SELECT
                p.payment_id,
                p.payment_method,
                p.amount,
                p.payment_date,
                p.reference_number,
                p.bank_name,
                p.cash_tendered,
                p.cash_change,
                c.customer_name,
                i.invoice_number,
                COALESCE(so.so_number, '') AS so_number,
                CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS collected_by_name,
                CASE WHEN btp.payment_id IS NULL THEN 'Undeposited' ELSE 'Deposited' END AS deposit_status
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.customer_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            " . ($invoices_has_so_id ? "LEFT JOIN sales_orders so ON i.so_id = so.so_id" : "LEFT JOIN sales_orders so ON 1=0") . "
            LEFT JOIN users u ON p.created_by = u.user_id
            LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
            WHERE 1=1";
    if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) {
        $sql .= " AND so.branch_id = ?";
    }
    $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 50";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) {
            $stmt->bind_param('i', $branch_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getPaymentIdsForDeposit($conn, $raw_ids, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id) {
    if (!$conn) return [];
    
    $ids = array_map('intval', (array)$raw_ids);
    $ids = array_filter($ids, function($id) { return $id > 0; });
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT p.payment_id
            FROM payments p
            LEFT JOIN bank_transaction_payments btp ON p.payment_id = btp.payment_id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            " . ($invoices_has_so_id ? "LEFT JOIN sales_orders so ON i.so_id = so.so_id" : "LEFT JOIN sales_orders so ON 1=0") . "
            WHERE p.payment_id IN ($placeholders)
              AND btp.payment_id IS NULL";
    if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) {
        $sql .= " AND so.branch_id = ?";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $types = str_repeat('i', count($ids)) . ((!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) ? 'i' : '');
    $params = $ids;
    if (!$view_all_branches && $branch_id > 0 && $so_branch_column_exists && $invoices_has_so_id) {
        $params[] = $branch_id;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = (int)$row['payment_id'];
    }
    $stmt->close();
    return $rows;
}

$flash_success = $_SESSION['success_message'] ?? '';
$flash_error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_deposit') {
            $payment_ids = $_POST['payment_ids'] ?? [];
            if (!is_array($payment_ids)) {
                $payment_ids = [$payment_ids];
            }
            $payment_ids = getPaymentIdsForDeposit($conn, $payment_ids, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
            if (empty($payment_ids)) {
                throw new Exception('Please select at least one undeposited payment.');
            }

            $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d\TH:i'));
            $reference_number = trim($_POST['reference_number'] ?? '');
            $bank_name = trim($_POST['bank_name'] ?? '');
            $description = trim($_POST['description'] ?? 'Collections deposit');
            $date_for_sql = date('Y-m-d H:i:s', strtotime($transaction_date ?: 'now'));

            $placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
            $amount_sql = "SELECT COALESCE(SUM(amount),0) AS total_amount FROM payments WHERE payment_id IN ($placeholders)";
            $amount_stmt = $conn->prepare($amount_sql);
            $amount_stmt->bind_param(str_repeat('i', count($payment_ids)), ...$payment_ids);
            $amount_stmt->execute();
            $amount_result = $amount_stmt->get_result()->fetch_assoc();
            $deposit_amount = (float)($amount_result['total_amount'] ?? 0);
            $amount_stmt->close();

            if ($deposit_amount <= 0) {
                throw new Exception('Invalid deposit amount computed from selected payments.');
            }

            $conn->begin_transaction();
            $insert = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, description, amount, created_by)
                                      VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?)");
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;
            $insert->bind_param('issssdi', $effective_branch_id, $date_for_sql, $reference_number, $bank_name, $description, $deposit_amount, $user_id);
            if (!$insert->execute()) {
                throw new Exception('Failed to save deposit transaction: ' . $insert->error);
            }
            $transaction_id = (int)$conn->insert_id;
            $insert->close();

            $link_stmt = $conn->prepare("INSERT INTO bank_transaction_payments (transaction_id, payment_id, amount_applied) VALUES (?, ?, ?)");
            $amt_stmt = $conn->prepare("SELECT amount FROM payments WHERE payment_id = ? LIMIT 1");
            foreach ($payment_ids as $payment_id) {
                $amt_stmt->bind_param('i', $payment_id);
                $amt_stmt->execute();
                $amt_row = $amt_stmt->get_result()->fetch_assoc();
                $applied = (float)($amt_row['amount'] ?? 0);
                $link_stmt->bind_param('iid', $transaction_id, $payment_id, $applied);
                if (!$link_stmt->execute()) {
                    throw new Exception('Failed to link payment to deposit: ' . $link_stmt->error);
                }
            }
            $amt_stmt->close();
            $link_stmt->close();
            $conn->commit();
            $_SESSION['success_message'] = 'Deposit transaction saved successfully.';
        } elseif ($action === 'create_withdrawal') {
            $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d\TH:i'));
            $reference_number = trim($_POST['reference_number'] ?? '');
            $bank_name = trim($_POST['bank_name'] ?? '');
            $description = trim($_POST['description'] ?? 'Bank withdrawal');
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) {
                throw new Exception('Withdrawal amount must be greater than zero.');
            }
            $date_for_sql = date('Y-m-d H:i:s', strtotime($transaction_date ?: 'now'));

            $stmt = $conn->prepare("INSERT INTO bank_transactions (branch_id, transaction_type, transaction_date, reference_number, bank_name, description, amount, created_by)
                                    VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?)");
            $effective_branch_id = (!$view_all_branches && $branch_id > 0) ? $branch_id : 0;
            $stmt->bind_param('issssdi', $effective_branch_id, $date_for_sql, $reference_number, $bank_name, $description, $amount, $user_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to save withdrawal transaction: ' . $stmt->error);
            }
            $stmt->close();
            $_SESSION['success_message'] = 'Withdrawal transaction saved successfully.';
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn && $conn->errno) {
            @$conn->rollback();
        }
        $_SESSION['error_message'] = $e->getMessage();
    }
    header('Location: banking.php');
    exit();
}

$available_payments = getAvailablePayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$recent_payments = getRecentPayments($conn, $view_all_branches, $branch_id, $so_branch_column_exists, $invoices_has_so_id);
$bank_transactions = getBankTransactions($conn, $view_all_branches, $branch_id);

$undeposited_total = 0.0;
foreach ($available_payments as $row) {
    $undeposited_total += (float)$row['amount'];
}
$total_collections = 0.0;
foreach ($recent_payments as $row) {
    $total_collections += (float)$row['amount'];
}
$total_deposits = 0.0;
$total_withdrawals = 0.0;
$running_balance = 0.0;
foreach ($bank_transactions as &$transaction) {
    $amount = (float)$transaction['amount'];
    if ($transaction['transaction_type'] === 'deposit') {
        $total_deposits += $amount;
        $running_balance += $amount;
        $transaction['deposit'] = $amount;
        $transaction['withdrawal'] = 0.0;
    } else {
        $total_withdrawals += $amount;
        $running_balance -= $amount;
        $transaction['deposit'] = 0.0;
        $transaction['withdrawal'] = $amount;
    }
    $transaction['balance'] = $running_balance;
}
unset($transaction);

$bank_balance = $total_deposits - $total_withdrawals;
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banking - AMGC</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Base styles matching sales_order.php */
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

        .page-header-card {
            background: linear-gradient(135deg, #052A47 0%, #047857 100%);
            color: #fff;
            border-radius: 18px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 10px 25px rgba(5,42,71,.12);
            margin-bottom: 1rem;
        }
        .page-header-card p { margin-bottom: 0; opacity: .9; }
        
        .stat-card-banking {
            background: #fff;
            border-radius: 18px;
            padding: 1rem 1.1rem;
            box-shadow: 0 8px 20px rgba(15,23,42,.06);
            border: 1px solid rgba(68,211,78,.14);
            height: 100%;
        }
        .stat-card-banking .stat-label { color: #6b7280; font-size: .88rem; margin-bottom: .35rem; }
        .stat-card-banking .stat-value { color: #052A47; font-weight: 800; font-size: 1.45rem; margin-bottom: .2rem; }
        .stat-card-banking .stat-icon {
            width: 48px; height: 48px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center;
            background: rgba(68,211,78,.14); color: #047857; font-size: 1.25rem;
        }
        
        .section-card {
            background: #fff;
            border-radius: 18px;
            border: 1px solid rgba(68,211,78,.12);
            box-shadow: 0 8px 20px rgba(15,23,42,.05);
            margin-bottom: 1rem;
            overflow: hidden;
        }
        .section-card .section-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #eef2f7;
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
        }
        .section-card .section-body { padding: 1rem 1.25rem; }
        
        .badge-soft-green { background: rgba(68,211,78,.16); color: #047857; }
        .badge-soft-blue { background: rgba(34,211,238,.15); color: #0f766e; }
        .badge-soft-red { background: rgba(248,113,113,.14); color: #b91c1c; }
        
        .table thead th {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%);
            color: #fff !important;
            border: none;
            white-space: nowrap;
            font-size: .84rem;
        }
        .table tbody td { vertical-align: middle; font-size: .92rem; }
        .collector-name { font-weight: 600; color: #052A47; }
        .amount-positive { color: #047857; font-weight: 700; }
        .amount-negative { color: #dc2626; font-weight: 700; }
        .amount-neutral { color: #052A47; font-weight: 700; }
        
        .sticky-actions { position: sticky; top: .5rem; }
        
        .payment-select-box {
            max-height: 360px; overflow: auto; border: 1px solid #e5e7eb; border-radius: 14px; padding: .75rem; background: #f9fafb;
        }
        .payment-option {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: .75rem; margin-bottom: .6rem;
        }
        .payment-option:last-child { margin-bottom: 0; }
        
        .form-control, .form-select {
            border-radius: 10px;
            min-height: 44px;
        }
        
        .btn-amgc-primary {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%);
            color: #fff; border: none; border-radius: 10px; min-height: 44px; font-weight: 700;
        }
        .btn-amgc-primary:hover { color: #fff; opacity: .95; }
        .btn-amgc-dark {
            background: #052A47; color: #fff; border: none; border-radius: 10px; min-height: 44px; font-weight: 700;
        }
        .btn-amgc-dark:hover { color: #fff; opacity: .96; }
        

        
        /* Mobile Navigation */
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            padding: 8px 12px;
            z-index: 1000;
            display: none;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0 !important;
            }
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
            transition: all 0.2s;
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
            position: relative;
        }

        .more-dropdown {
            position: absolute;
            bottom: 100%;
            right: 0;
            transform: translateY(-10px);
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            min-width: 180px;
            z-index: 1000;
            display: none;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.08);
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
            transition: background 0.2s ease;
            border-bottom: 1px solid #f0f0f0;
        }

        .more-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }

        .more-dropdown .dropdown-item:hover {
            background: #f5f5f5;
        }

        .navbar-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .mobile-menu-btn {
            display: none;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: #052A47;
        }

        @media (max-width: 992px) {
            .mobile-menu-btn {
                display: block;
            }
        }

        

        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
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
                <span class="nav-text">Branch Admin</span>
            </h3>
        </div>
        
        <div class="sidebar-content">
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <!-- Warehouse Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
                            <i class="bi bi-shop"></i>
                            <span class="nav-text">Warehouse</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>
                        <div class="collapse" id="warehouseMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="current_inventory.php">
                                        <i class="bi bi-bar-chart-line"></i>
                                        <span class="nav-text">Current Inventory</span>
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
                            </ul>
                        </div>
                    </li>

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
                                        <span class="nav-text">Purchase Order</span>
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
                                <li class="nav-item">
                                    <a class="nav-link" href="customer_list.php">
                                        <i class="bi bi-person-badge"></i>
                                        <span class="nav-text">Customer List</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="approve_credit_requests.php">
                                        <i class="bi bi-pencil-square"></i>
                                        <span class="nav-text">Approved Credit Request</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Delivery Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'deliveryMenu')">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Delivery</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>
                        <div class="collapse" id="deliveryMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="trip_tickets.php">
                                        <i class="bi bi-ticket-perforated"></i>
                                        <span class="nav-text">Trip Tickets</span>
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
                                        <i class="bi bi-ticket-perforated"></i>
                                        <span class="nav-text">Deposit</span>
                                    </a>
                                </li>
                            </ul>
                    

                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="Withdrawal.php">
                                        <i class="bi bi-ticket-perforated"></i>
                                        <span class="nav-text">Withdrawal</span>
                                    </a>
                                </li>
                            </ul>

                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="bank_statement.php">
                                        <i class="bi bi-ticket-perforated"></i>
                                        <span class="nav-text">Bank Statement</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    <!-- Users -->
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-people-fill"></i>
                            <span class="nav-text">Users</span>
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
            <button class="logout-btn-sidebar" onclick="logout()">
                <i class="bi bi-box-arrow-right"></i>
                <span class="logout-text">Logout</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div id="dashboardContent" class="page-content active">
            <div class="navbar-top no-print">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="bi bi-list"></i>
                </button>
                
                <div class="page-title">
                    <h2>Banking</h2>
                    <p id="dashboardSubtitle">
                        Manage bank deposits and withdrawals
                    </p>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($flash_success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo h($flash_success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo h($flash_error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-3">
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card-banking d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Undeposited Funds</div>
                            <div class="stat-value">₱<?php echo number_format($undeposited_total, 2); ?></div>
                            <div class="page-note"><?php echo count($available_payments); ?> payment(s) waiting for deposit</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card-banking d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Total Collections</div>
                            <div class="stat-value">₱<?php echo number_format($total_collections, 2); ?></div>
                            <div class="page-note">All recorded payments</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-cash-coin"></i></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card-banking d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Total Deposits</div>
                            <div class="stat-value">₱<?php echo number_format($total_deposits, 2); ?></div>
                            <div class="page-note">Posted to bank statement</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-arrow-down-circle"></i></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card-banking d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Bank Balance</div>
                            <div class="stat-value">₱<?php echo number_format($bank_balance, 2); ?></div>
                            <div class="page-note">Deposit - Withdrawal = Balance</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-bank"></i></div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-8">
                    <!-- Undeposited Funds Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h5 class="mb-1">Undeposited Funds</h5>
                                <div class="page-note">Payments from collection stay here first until deposited to the bank.</div>
                            </div>
                            <span class="badge rounded-pill badge-soft-green px-3 py-2">₱<?php echo number_format($undeposited_total, 2); ?></span>
                        </div>
                        <div class="section-body">
                            <form method="POST" action="banking.php" id="depositForm">
                                <input type="hidden" name="action" value="create_deposit">
                                <div class="row g-3">
                                    <div class="col-lg-7">
                                        <div class="payment-select-box">
                                            <?php if (empty($available_payments)): ?>
                                                <div class="text-center py-5 text-muted">
                                                    <i class="bi bi-check-circle fs-1 d-block mb-2"></i>
                                                    No undeposited payments found.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($available_payments as $payment): ?>
                                                    <label class="payment-option d-block">
                                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                                            <div class="form-check">
                                                                <input class="form-check-input deposit-payment-checkbox" type="checkbox" name="payment_ids[]" value="<?php echo (int)$payment['payment_id']; ?>" data-amount="<?php echo number_format((float)$payment['amount'], 2, '.', ''); ?>" id="payment_<?php echo (int)$payment['payment_id']; ?>">
                                                                <label class="form-check-label" for="payment_<?php echo (int)$payment['payment_id']; ?>">
                                                                    <span class="collector-name"><?php echo h($payment['customer_name'] ?: 'Unknown Customer'); ?></span><br>
                                                                    <small class="text-muted"><?php echo h($payment['invoice_number'] ?: 'No Invoice'); ?><?php echo !empty($payment['so_number']) ? ' • ' . h($payment['so_number']) : ''; ?></small>
                                                                </label>
                                                            </div>
                                                            <div class="text-end">
                                                                <div class="amount-positive">₱<?php echo number_format((float)$payment['amount'], 2); ?></div>
                                                                <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($payment['payment_date'])); ?></small>
                                                            </div>
                                                        </div>
                                                        <div class="mt-2 small text-muted">
                                                            <div><strong>Collected By:</strong> <?php echo h(trim($payment['collected_by_name']) !== '' ? $payment['collected_by_name'] : 'Unknown User'); ?></div>
                                                            <div><strong>Method:</strong> <?php echo ucwords(str_replace('_', ' ', h($payment['payment_method']))); ?><?php echo !empty($payment['reference_number']) ? ' • Ref: ' . h($payment['reference_number']) : ''; ?></div>
                                                        </div>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-lg-5">
                                        <div class="sticky-actions">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Deposit Date & Time</label>
                                                <input type="datetime-local" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Bank Name</label>
                                                <input type="text" name="bank_name" class="form-control" placeholder="Enter bank name">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Reference No.</label>
                                                <input type="text" name="reference_number" class="form-control" placeholder="Deposit slip / reference no.">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Description</label>
                                                <textarea name="description" class="form-control" rows="3" placeholder="Optional remarks for this deposit">Collections deposit</textarea>
                                            </div>
                                            <div class="alert alert-light border d-flex justify-content-between align-items-center mb-3">
                                                <span class="fw-bold">Selected Deposit Total</span>
                                                <span class="amount-positive fs-5" id="selectedDepositTotal">₱0.00</span>
                                            </div>
                                            <button type="submit" class="btn btn-amgc-primary w-100" <?php echo empty($available_payments) ? 'disabled' : ''; ?>><i class="bi bi-bank2 me-2"></i>Create Deposit</button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Bank Statement Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h5 class="mb-1">Bank Statement</h5>
                                <div class="page-note">Running balance.</div>
                            </div>
                            <span class="badge rounded-pill badge-soft-blue px-3 py-2">Balance ₱<?php echo number_format($bank_balance, 2); ?></span>
                        </div>
                        <div class="section-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Reference</th>
                                            <th>Bank</th>
                                            <th>Description</th>
                                            <th>Deposit</th>
                                            <th>Withdrawal</th>
                                            <th>Balance</th>
                                            <th>Encoded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($bank_transactions)): ?>
                                            <tr><td colspan="9" class="text-center py-5 text-muted">No bank transactions yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($bank_transactions as $tx): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y h:i A', strtotime($tx['transaction_date'])); ?></td>
                                                    <td>
                                                        <?php if ($tx['transaction_type'] === 'deposit'): ?>
                                                            <span class="badge rounded-pill badge-soft-green px-3 py-2">Deposit</span>
                                                        <?php else: ?>
                                                            <span class="badge rounded-pill badge-soft-red px-3 py-2">Withdrawal</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo h($tx['reference_number'] ?: '-'); ?></td>
                                                    <td><?php echo h($tx['bank_name'] ?: '-'); ?></td>
                                                    <td>
                                                        <div><?php echo h($tx['description'] ?: '-'); ?></div>
                                                        <?php if (!empty($tx['payment_links'])): ?>
                                                            <small class="text-muted d-block mt-1"><?php echo h(str_replace('||', '; ', $tx['payment_links'])); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="amount-positive"><?php echo $tx['deposit'] > 0 ? '₱' . number_format($tx['deposit'], 2) : '-'; ?></td>
                                                    <td class="amount-negative"><?php echo $tx['withdrawal'] > 0 ? '₱' . number_format($tx['withdrawal'], 2) : '-'; ?></td>
                                                    <td class="amount-neutral">₱<?php echo number_format($tx['balance'], 2); ?></td>
                                                    <td><?php echo h(trim($tx['created_by_name']) !== '' ? $tx['created_by_name'] : 'Unknown User'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <!-- New Withdrawal Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h5 class="mb-1">New Withdrawal</h5>
                                <div class="page-note">Record bank cash out or expense withdrawals.</div>
                            </div>
                        </div>
                        <div class="section-body">
                            <form method="POST" action="banking.php">
                                <input type="hidden" name="action" value="create_withdrawal">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Date & Time</label>
                                    <input type="datetime-local" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Bank Name</label>
                                    <input type="text" name="bank_name" class="form-control" placeholder="Enter bank name">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Reference No.</label>
                                    <input type="text" name="reference_number" class="form-control" placeholder="Cheque / transfer / withdrawal ref">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Amount</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Description</label>
                                    <textarea name="description" class="form-control" rows="3" placeholder="Reason for withdrawal" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-amgc-dark w-100"><i class="bi bi-arrow-up-circle me-2"></i>Save Withdrawal</button>
                            </form>
                        </div>
                    </div>

                    <!-- Recent Payments Section -->
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h5 class="mb-1">Payments</h5>
                                <div class="page-note">Latest collected payments and who collected them.</div>
                            </div>
                        </div>
                        <div class="section-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Payment</th>
                                            <th>Collector</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_payments)): ?>
                                            <tr><td colspan="4" class="text-center py-4 text-muted">No payments found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_payments as $payment): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold"><?php echo h($payment['customer_name'] ?: 'Unknown Customer'); ?></div>
                                                        <small class="text-muted"><?php echo h($payment['invoice_number'] ?: 'No Invoice'); ?><?php echo !empty($payment['so_number']) ? ' • ' . h($payment['so_number']) : ''; ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="amount-neutral">₱<?php echo number_format((float)$payment['amount'], 2); ?></div>
                                                        <small class="text-muted"><?php echo ucwords(str_replace('_', ' ', h($payment['payment_method']))); ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="collector-name"><?php echo h(trim($payment['collected_by_name']) !== '' ? $payment['collected_by_name'] : 'Unknown User'); ?></div>
                                                        <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($payment['payment_date'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($payment['deposit_status'] === 'Deposited'): ?>
                                                            <span class="badge rounded-pill badge-soft-blue px-3 py-2">Deposited</span>
                                                        <?php else: ?>
                                                            <span class="badge rounded-pill badge-soft-green px-3 py-2">Undeposited</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item dropdown-more" id="inventoryDropdown">
                <a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'inventoryDropdownMenu')">
                    <i class="bi bi-box-seam"></i><span>Inventory</span>
                </a>
                <div class="more-dropdown" id="inventoryDropdownMenu">
                    <a href="current_inventory.php" class="dropdown-item"><i class="bi bi-bar-chart-line"></i><span>Current Inventory</span></a>
                    <a href="bad_orders.php" class="dropdown-item"><i class="bi bi-recycle"></i><span>Bad Orders</span></a>
                </div>
            </li>
            <li class="nav-item dropdown-more" id="salesDropdown">
                <a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'salesDropdownMenu')">
                    <i class="bi bi-cart"></i><span>Sales</span>
                </a>
                <div class="more-dropdown" id="salesDropdownMenu">
                    <a href="sales_order.php" class="dropdown-item"><i class="bi bi-cart"></i><span>Sales Orders</span></a>
                    <a href="pick_list_items.php" class="dropdown-item"><i class="bi bi-list-check"></i><span>Pick Lists</span></a>
                </div>
            </li>
            <li class="nav-item dropdown-more" id="purchaseDropdown">
                <a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'purchaseDropdownMenu')">
                    <i class="bi bi-truck"></i><span>Purchase</span>
                </a>
                <div class="more-dropdown" id="purchaseDropdownMenu" style="right: 0 !important; left: auto !important;">
                    <a href="purchase_order.php" class="dropdown-item"><i class="bi bi-box"></i><span>Purchase Orders</span></a>
                    <a href="supplier.php" class="dropdown-item"><i class="bi bi-building"></i><span>Suppliers</span></a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span>Trips</span></a>
            </li>
            <li class="nav-item dropdown-more" id="moreDropdown">
                <a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'moreDropdownMenu')">
                    <i class="bi bi-three-dots-vertical"></i><span>More</span>
                </a>
                <div class="more-dropdown" id="moreDropdownMenu">
                    <a href="drivers.php" class="dropdown-item"><i class="bi bi-people"></i><span>Users</span></a>
                    <a href="approve_credit_requests.php" class="dropdown-item"><i class="bi bi-pencil-square"></i><span>Approve Requests</span></a>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item logout-item" onclick="logout(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
                </div>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3" style="width: 80px; height: 80px; background: #44D34E; color: #052A47; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800;"><?php echo $user_initials; ?></div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p>
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ========== SIDEBAR FUNCTIONS ==========
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            sidebar.classList.toggle('active');
            let overlay = document.querySelector('.sidebar-overlay');
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
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }
    }

    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault();
        event.stopPropagation();
        
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            setTimeout(() => {
                document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                    if (collapse.id !== targetId) collapse.classList.remove('show');
                });
                target.classList.add('show');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            }, 50);
            return;
        }
        
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        } else {
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
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }
    }

    function toggleDropdown(event, dropdownId) {
        event.preventDefault();
        event.stopPropagation();
        const dropdown = document.getElementById(dropdownId);
        const btn = event.currentTarget;
        if (!dropdown) return;
        if (dropdown.classList.contains('show')) {
            dropdown.classList.remove('show');
            btn.classList.remove('active');
        } else {
            ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => {
                const d = document.getElementById(id);
                if (d && d !== dropdown) d.classList.remove('show');
            });
            document.querySelectorAll('.more-btn').forEach(b => b.classList.remove('active'));
            dropdown.classList.add('show');
            btn.classList.add('active');
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('active');
        if (overlay) overlay.remove();
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
                window.location.href = '../logout.php';
            }
        });
    }

    function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
        logout();
    }

    function showProfileModal() {
        new bootstrap.Modal(document.getElementById('profileModal')).show();
    }

    // Banking specific functions
    function updateDepositTotal() {
        const checkboxes = document.querySelectorAll('.deposit-payment-checkbox:checked');
        let total = 0;
        checkboxes.forEach(cb => total += parseFloat(cb.dataset.amount || '0'));
        document.getElementById('selectedDepositTotal').textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Restore sidebar state
        const sidebar = document.getElementById('sidebar');
        if (sidebar && window.innerWidth > 992) {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) sidebar.classList.add('collapsed');
        }

        // Mobile menu button
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', toggleSidebar);
        }

        // Desktop toggle button
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', toggleSidebar);
        }

        // Deposit checkboxes
        document.querySelectorAll('.deposit-payment-checkbox').forEach(cb => {
            cb.addEventListener('change', updateDepositTotal);
        });
        updateDepositTotal();

        // Deposit form validation
        document.getElementById('depositForm')?.addEventListener('submit', function (e) {
            const checked = document.querySelectorAll('.deposit-payment-checkbox:checked').length;
            if (checked === 0) {
                e.preventDefault();
                Swal.fire({icon: 'warning', title: 'No payment selected', text: 'Please select at least one undeposited payment before creating a deposit.'});
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                closeMobileSidebar();
            }
        });
    });
</script>
</body>
</html>