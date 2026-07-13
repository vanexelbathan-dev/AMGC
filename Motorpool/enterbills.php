<?php
/* MOTORPOOL ENTER BILLS - Receive Inventory UI (Branch Admin layout), motorpool-only data. */
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) { die('Database connection failed: ' . mysqli_connect_error()); }

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
// Branch variables
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$branch_name = $_SESSION['branch_name'] ?? '';
$view_all_branches = ($_SESSION['view_all_branches'] ?? false);
if ($user_id <= 0) { header('Location: ../login.php'); exit; }
if ($user_role !== 'motorpool') {
    if ($user_role === 'branch_admin') header('Location: ../Branch_Admin/branchdashboard.php');
    elseif ($user_role === 'admin') header('Location: ../Admin/dashboard.php');
    else header('Location: ../login.php');
    exit;
}

$user_name = isset($_SESSION['first_name']) ? trim((string)$_SESSION['first_name'] . ' ' . (string)($_SESSION['last_name'] ?? '')) : 'Motorpool Account';
$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) { if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1)); }
if ($user_initials === '') $user_initials = 'MA';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value): string { return number_format((float)$value, 2); }
function qty($value): string { $n = (float)$value; return floor($n) == $n ? number_format($n, 0) : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); }
function dbTableExists2(mysqli $conn, string $table): bool { $table = $conn->real_escape_string($table); $r = $conn->query("SHOW TABLES LIKE '$table'"); return $r && $r->num_rows > 0; }
function dbColumnExists2(mysqli $conn, string $table, string $column): bool { $table = preg_replace('/[^A-Za-z0-9_]/', '', $table); $column = $conn->real_escape_string($column); $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'"); return $r && $r->num_rows > 0; }
function addCol2(mysqli $conn, string $table, string $column, string $definition): void { if (!dbColumnExists2($conn, $table, $column)) { @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition"); } }
function uploadMotorpoolBillFiles(string $field): string {
    if (empty($_FILES[$field]['name'])) return '';
    $base = __DIR__ . '/../uploads/motorpool_enterbills';
    if (!is_dir($base)) @mkdir($base, 0777, true);
    $saved = [];
    $names = is_array($_FILES[$field]['name']) ? $_FILES[$field]['name'] : [$_FILES[$field]['name']];
    $tmps = is_array($_FILES[$field]['tmp_name']) ? $_FILES[$field]['tmp_name'] : [$_FILES[$field]['tmp_name']];
    $allowed = ['jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx'];
    foreach ($names as $i => $name) {
        if ($name === '' || empty($tmps[$i]) || !is_uploaded_file($tmps[$i])) continue;
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $safe = 'motorpool_bill_' . date('YmdHis') . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($tmps[$i], $base . '/' . $safe)) $saved[] = $safe;
    }
    return implode(',', $saved);
}
function ensureMotorpoolEnterBillsClean(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_inventory_items` (
        `item_id` INT AUTO_INCREMENT PRIMARY KEY,
        `item_code` VARCHAR(80) UNIQUE NOT NULL,
        `item_name` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `category` VARCHAR(120) DEFAULT 'General',
        `unit_type` VARCHAR(80) DEFAULT 'Piece',
        `barcode` VARCHAR(120) DEFAULT NULL,
        `current_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `reorder_level` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `supplier` VARCHAR(255) DEFAULT NULL,
        `item_image` VARCHAR(255) DEFAULT NULL,
        `status` VARCHAR(30) DEFAULT 'active',
        `created_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_item_name` (`item_name`), KEY `idx_category` (`category`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_inventory_transactions` (
        `transaction_id` INT AUTO_INCREMENT PRIMARY KEY,
        `item_id` INT NOT NULL,
        `transaction_type` VARCHAR(40) NOT NULL,
        `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `remarks` TEXT DEFAULT NULL,
        `attachment` TEXT DEFAULT NULL,
        `reference_type` VARCHAR(80) DEFAULT NULL,
        `reference_id` INT DEFAULT NULL,
        `encoded_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_item_id` (`item_id`), KEY `idx_type` (`transaction_type`), KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    addCol2($conn, 'motorpool_inventory_transactions', 'reference_type', "`reference_type` VARCHAR(80) DEFAULT NULL AFTER `attachment`");
    addCol2($conn, 'motorpool_inventory_transactions', 'reference_id', "`reference_id` INT DEFAULT NULL AFTER `reference_type`");

    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_billexpenses` (
        `expense_id` INT AUTO_INCREMENT PRIMARY KEY,
        `expense_no` VARCHAR(60) DEFAULT NULL,
        `branch_id` INT NOT NULL DEFAULT 0,
        `expense_date` DATE DEFAULT NULL,
        `transaction_type` ENUM('bill','credit') NOT NULL DEFAULT 'bill',
        `bill_received` TINYINT(1) NOT NULL DEFAULT 1,
        `vendor_name` VARCHAR(255) DEFAULT NULL,
        `vendor_address` TEXT DEFAULT NULL,
        `terms` VARCHAR(100) DEFAULT NULL,
        `ref_no` VARCHAR(100) DEFAULT NULL,
        `bill_due` DATE DEFAULT NULL,
        `payable_account` VARCHAR(255) DEFAULT NULL,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `memo` TEXT DEFAULT NULL,
        `attachment` TEXT DEFAULT NULL,
        `status` ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
        `created_by` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_expense_no` (`expense_no`), KEY `idx_status` (`status`), KEY `idx_expense_date` (`expense_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    addCol2($conn, 'motorpool_billexpenses', 'attachment', "`attachment` TEXT DEFAULT NULL AFTER `memo`");

    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_billexpense_items` (
        `item_id` INT AUTO_INCREMENT PRIMARY KEY,
        `expense_id` INT NOT NULL,
        `account` VARCHAR(255) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `memo` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_expense_id` (`expense_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_receive_inventory` (
        `receive_id` INT AUTO_INCREMENT PRIMARY KEY,
        `expense_id` INT DEFAULT NULL,
        `receive_no` VARCHAR(60) DEFAULT NULL,
        `receive_date` DATE NOT NULL,
        `vendor_name` VARCHAR(255) DEFAULT NULL,
        `ref_no` VARCHAR(100) DEFAULT NULL,
        `memo` TEXT DEFAULT NULL,
        `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `attachment` TEXT DEFAULT NULL,
        `received_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_expense_id` (`expense_id`), KEY `idx_receive_date` (`receive_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_receive_inventory_items` (
        `receive_item_id` INT AUTO_INCREMENT PRIMARY KEY,
        `receive_id` INT NOT NULL,
        `item_id` INT NOT NULL,
        `item_name` VARCHAR(255) NOT NULL,
        `unit_type` VARCHAR(80) DEFAULT NULL,
        `quantity_received` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        KEY `idx_receive_id` (`receive_id`), KEY `idx_item_id` (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function generateExpenseNo2(mysqli $conn): string {
    $prefix = 'MP-BILL-' . date('Ymd') . '-';
    $res = $conn->query("SELECT expense_no FROM motorpool_billexpenses WHERE expense_no LIKE '" . $conn->real_escape_string($prefix) . "%' ORDER BY expense_id DESC LIMIT 1");
    $next = 1;
    if ($res && ($row = $res->fetch_assoc()) && preg_match('/(\d+)$/', (string)$row['expense_no'], $m)) $next = (int)$m[1] + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}
function generateReceiveNo2(mysqli $conn): string {
    $prefix = 'MP-RCV-' . date('Ymd') . '-';
    $res = $conn->query("SELECT receive_no FROM motorpool_receive_inventory WHERE receive_no LIKE '" . $conn->real_escape_string($prefix) . "%' ORDER BY receive_id DESC LIMIT 1");
    $next = 1;
    if ($res && ($row = $res->fetch_assoc()) && preg_match('/(\d+)$/', (string)$row['receive_no'], $m)) $next = (int)$m[1] + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

ensureMotorpoolEnterBillsClean($conn);

$save_status = '';
$save_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save_bill') {
        $mode = ($_POST['bill_type'] ?? 'bill') === 'credit' ? 'credit' : 'bill';
        $bill_received = isset($_POST['bill_received']) ? 1 : 0;
        $vendor_name = trim((string)($_POST['vendor_name'] ?? ''));
        $vendor_address = trim((string)($_POST['vendor_address'] ?? ''));
        $terms = trim((string)($_POST['terms'] ?? ''));
        $expense_date = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
        $ref_no = trim((string)($_POST['ref_no'] ?? ''));
        $bill_due = trim((string)($_POST['bill_due'] ?? ''));
        $memo = trim((string)($_POST['memo'] ?? ''));
        $payable_account = trim((string)($_POST['payable_account'] ?? 'Accounts Payable'));
        $line_accounts = $_POST['expense_account'] ?? [];
        $line_amounts = $_POST['expense_amount'] ?? [];
        $line_memos = $_POST['expense_memo'] ?? [];
        $item_ids = $_POST['item_id'] ?? [];
        $item_qtys = $_POST['item_qty'] ?? [];
        $item_costs = $_POST['item_cost'] ?? [];
        $item_memos = $_POST['item_memo'] ?? [];
        $submit_mode = trim((string)($_POST['submit_mode'] ?? 'close'));
        $attachment = uploadMotorpoolBillFiles('attachments');

        $expenseLines = [];
        $total = 0.0;
        if (is_array($line_accounts)) {
            foreach ($line_accounts as $i => $acct) {
                $acct = trim((string)$acct);
                $amt = (float)str_replace([',','₱',' '], '', (string)($line_amounts[$i] ?? 0));
                $lm = trim((string)($line_memos[$i] ?? ''));
                if ($acct !== '' && $amt > 0) { $expenseLines[] = ['account'=>$acct, 'amount'=>$amt, 'memo'=>$lm]; $total += $amt; }
            }
        }
        $receiveItems = [];
        if (is_array($item_ids)) {
            foreach ($item_ids as $i => $rawId) {
                $iid = (int)$rawId;
                $q = (float)str_replace([',',' '], '', (string)($item_qtys[$i] ?? 0));
                $cost = (float)str_replace([',','₱',' '], '', (string)($item_costs[$i] ?? 0));
                if ($iid <= 0 || $q <= 0) continue;
                $stmt = $conn->prepare("SELECT item_id, item_code, item_name, unit_type, current_stock, unit_cost, total_cost FROM motorpool_inventory_items WHERE item_id = ? AND LOWER(COALESCE(status,'active')) = 'active' LIMIT 1");
                if (!$stmt) continue;
                $stmt->bind_param('i', $iid); $stmt->execute(); $item = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$item) continue;
                $lineTotal = $q * $cost;
                $receiveItems[] = ['item'=>$item, 'qty'=>$q, 'unit_cost'=>$cost, 'line_total'=>$lineTotal, 'memo'=>trim((string)($item_memos[$i] ?? ''))];
                $total += $lineTotal;
            }
        }

        if ($vendor_name === '') { $save_status = 'error'; $save_message = 'Vendor is required.'; }
        elseif ($total <= 0) { $save_status = 'error'; $save_message = 'Please enter at least one expense or item amount.'; }
        else {
            $conn->begin_transaction();
            try {
                $expense_no = generateExpenseNo2($conn);
                $status = $mode === 'credit' ? 'paid' : 'unpaid';
                $balance = $mode === 'credit' ? 0.00 : $total;
                $stmt = $conn->prepare("INSERT INTO motorpool_billexpenses (expense_no, branch_id, expense_date, transaction_type, bill_received, vendor_name, vendor_address, terms, ref_no, bill_due, payable_account, amount, total_amount, balance, memo, attachment, status, created_by) VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param('sssissssssdddsssi', $expense_no, $expense_date, $mode, $bill_received, $vendor_name, $vendor_address, $terms, $ref_no, $bill_due, $payable_account, $total, $total, $balance, $memo, $attachment, $status, $user_id);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $expense_id = (int)$conn->insert_id;
                $stmt->close();

                if (!empty($expenseLines)) {
                    $lineStmt = $conn->prepare("INSERT INTO motorpool_billexpense_items (expense_id, account, amount, memo) VALUES (?, ?, ?, ?)");
                    if (!$lineStmt) throw new Exception($conn->error);
                    foreach ($expenseLines as $l) { $lineStmt->bind_param('isds', $expense_id, $l['account'], $l['amount'], $l['memo']); if (!$lineStmt->execute()) throw new Exception($lineStmt->error); }
                    $lineStmt->close();
                }

                if (!empty($receiveItems)) {
                    $receive_no = generateReceiveNo2($conn);
                    $recvTotal = array_sum(array_map(fn($r)=>(float)$r['line_total'], $receiveItems));
                    $rstmt = $conn->prepare("INSERT INTO motorpool_receive_inventory (expense_id, receive_no, receive_date, vendor_name, ref_no, memo, total_amount, attachment, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$rstmt) throw new Exception($conn->error);
                    $rstmt->bind_param('isssssdsi', $expense_id, $receive_no, $expense_date, $vendor_name, $ref_no, $memo, $recvTotal, $attachment, $user_id);
                    if (!$rstmt->execute()) throw new Exception($rstmt->error);
                    $receive_id = (int)$conn->insert_id;
                    $rstmt->close();

                    $riStmt = $conn->prepare("INSERT INTO motorpool_receive_inventory_items (receive_id, item_id, item_name, unit_type, quantity_received, unit_cost, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $txStmt = $conn->prepare("INSERT INTO motorpool_inventory_transactions (item_id, transaction_type, quantity, unit_cost, total_cost, remarks, attachment, reference_type, reference_id, encoded_by) VALUES (?, 'Stock In', ?, ?, ?, ?, ?, 'motorpool_enterbills', ?, ?)");
                    $upStmt = $conn->prepare("UPDATE motorpool_inventory_items SET current_stock = ?, unit_cost = ?, total_cost = ?, supplier = CASE WHEN ? <> '' THEN ? ELSE supplier END, updated_at = NOW() WHERE item_id = ?");
                    if (!$riStmt || !$txStmt || !$upStmt) throw new Exception($conn->error);
                    foreach ($receiveItems as $r) {
                        $it = $r['item']; $iid = (int)$it['item_id']; $name = (string)$it['item_name']; $unit = (string)($it['unit_type'] ?? 'Piece');
                        $q = (float)$r['qty']; $cost = (float)$r['unit_cost']; $lineTotal = (float)$r['line_total'];
                        $riStmt->bind_param('iissddd', $receive_id, $iid, $name, $unit, $q, $cost, $lineTotal);
                        if (!$riStmt->execute()) throw new Exception($riStmt->error);
                        $oldStock = (float)($it['current_stock'] ?? 0); $oldTotal = (float)($it['total_cost'] ?? 0); if ($oldTotal <= 0 && $oldStock > 0) $oldTotal = $oldStock * (float)($it['unit_cost'] ?? 0);
                        $newStock = $oldStock + $q; $newTotal = $oldTotal + $lineTotal; $newUnit = $newStock > 0 ? ($newTotal / $newStock) : $cost;
                        $remarks = 'Motorpool Enter Bills ' . $expense_no . ($r['memo'] !== '' ? ' - ' . $r['memo'] : '');
                        $txStmt->bind_param('idddssii', $iid, $q, $cost, $lineTotal, $remarks, $attachment, $expense_id, $user_id);
                        if (!$txStmt->execute()) throw new Exception($txStmt->error);
                        $upStmt->bind_param('ddsssi', $newStock, $newUnit, $newTotal, $vendor_name, $vendor_name, $iid);
                        if (!$upStmt->execute()) throw new Exception($upStmt->error);
                    }
                    $riStmt->close(); $txStmt->close(); $upStmt->close();
                }

                $conn->commit();
                if ($submit_mode === 'close') { header('Location: enterbills.php?saved=1&no=' . urlencode($expense_no)); exit; }
                $save_status = 'success'; $save_message = 'Motorpool bill saved successfully: ' . $expense_no;
            } catch (Throwable $e) {
                $conn->rollback();
                $save_status = 'error'; $save_message = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}
if (isset($_GET['saved'])) { $save_status = 'success'; $save_message = 'Motorpool bill saved successfully' . (!empty($_GET['no']) ? ': ' . h($_GET['no']) : '') . '.'; }

// Data lists
$vendors = [];
if ($r = $conn->query("SELECT DISTINCT supplier AS vendor_name FROM motorpool_inventory_items WHERE TRIM(COALESCE(supplier,'')) <> '' ORDER BY supplier ASC")) { while ($row = $r->fetch_assoc()) $vendors[] = (string)$row['vendor_name']; }
if ($r = $conn->query("SELECT DISTINCT vendor_name FROM motorpool_billexpenses WHERE TRIM(COALESCE(vendor_name,'')) <> '' ORDER BY vendor_name ASC")) { while ($row = $r->fetch_assoc()) if (!in_array((string)$row['vendor_name'], $vendors, true)) $vendors[] = (string)$row['vendor_name']; }

$items = [];
$res = $conn->query("SELECT item_id, item_code, item_name, description, category, unit_type, current_stock, unit_cost, total_cost, supplier FROM motorpool_inventory_items WHERE LOWER(COALESCE(status,'active')) = 'active' ORDER BY category ASC, item_name ASC");
if ($res) while ($row = $res->fetch_assoc()) $items[] = $row;

$accounts = [];
if (dbTableExists2($conn, 'chart_of_accounts')) {
    $res = $conn->query("SELECT account_title, account_code, account_type FROM chart_of_accounts WHERE status='active' ORDER BY account_code ASC, account_title ASC");
    if ($res) while ($row = $res->fetch_assoc()) $accounts[] = $row;
}
$payableAccounts = array_values(array_filter($accounts, fn($a)=>in_array(trim((string)$a['account_type']), ['Accounts Payable','Credit Card','Other Current Liability','Long Term Liability'], true)));
if (empty($payableAccounts)) $payableAccounts[] = ['account_title'=>'Accounts Payable','account_code'=>'','account_type'=>'Accounts Payable'];

$receiveHistory = [];
$res = $conn->query("SELECT r.*, COALESCE(u.first_name,'') AS first_name, COALESCE(u.last_name,'') AS last_name FROM motorpool_receive_inventory r LEFT JOIN users u ON u.user_id = r.received_by ORDER BY r.created_at DESC LIMIT 50");
if ($res) while ($row = $res->fetch_assoc()) $receiveHistory[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Motorpool Enter Bills</title>

    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-green: #44D34E;
            --brand-green: #28b847;
            --dark-green: #047857;
            --dark-color: #052A47;
            --muted: #64748b;
            --border: #d9f7e4;
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            overflow-x: hidden;
            color: #052A47;
            background: #f5f9fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        #appPage {
            min-height: 100vh;
            background: #f5f9fb;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            z-index: 998;
            display: none;
            background: rgba(0, 0, 0, .35);
        }

        .sidebar-overlay.active {
            display: block;
        }

        .page-header-card {
            margin-bottom: 10px;
            padding: 13px 22px;
            border: 1px solid #bff3d0;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .03);
        }

        .page-header-card h1 {
            margin: 0 0 3px;
            color: #052A47;
            font-size: 1.35rem;
            font-weight: 650;
            line-height: 1.1;
        }

        .page-header-card p {
            margin: 0;
            color: #64748b;
            font-size: .95rem;
        }

        .receive-card {
            margin-bottom: 8px;
            overflow: hidden;
            border: 1px solid #c7d0d9;
            background: #fff;
            box-shadow: none;
        }

        .qb-toolbar {
            display: grid;
            grid-template-columns: 220px minmax(420px, 1fr) 470px;
            align-items: center;
            min-height: 27px;
            padding: 0 8px;
            border-bottom: 1px solid #bdc7d0;
            background: #e9eef3;
            color: #052A47;
            font-size: 14px;
            font-weight: 500;
        }

        .qb-checks,
        .right-checks,
        .payable-row {
            display: flex;
            align-items: center;
        }

        .qb-checks {
            gap: 14px;
        }

        .right-checks {
            justify-content: flex-end;
            gap: 14px;
            white-space: nowrap;
        }

        .payable-row {
            justify-content: center;
            gap: 8px;
        }

        .payable-row select {
            width: 390px;
            max-width: 100%;
            height: 24px;
            border: 1px solid #a8b5c4;
            background: #fff;
            font-weight: 400;
        }

        .qb-toolbar label {
            margin: 0;
            font-weight: 500;
        }

        .qb-toolbar input[type="radio"],
        .qb-toolbar input[type="checkbox"] {
            margin-right: 3px;
        }

        .qb-form-row {
            display: grid;
            grid-template-columns: minmax(760px, 1fr) 375px;
            border-bottom: 1px solid #bdc7d0;
            background-color: #fbfffb;
            background-image:
                linear-gradient(45deg, #edf9ed 25%, transparent 25%),
                linear-gradient(-45deg, #edf9ed 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #edf9ed 75%),
                linear-gradient(-45deg, transparent 75%, #edf9ed 75%);
            background-size: 20px 20px;
            background-position: 0 0, 0 10px, 10px -10px, -10px 0;
        }

        .bill-panel {
            padding: 6px 16px 8px;
            border-right: 1px solid #b9d5b9;
        }

        .bill-title {
            margin: 0 0 5px;
            color: #052A47;
            font-size: 29px;
            font-weight: 400;
            line-height: .95;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 92px minmax(360px, 1fr) 90px 230px;
            align-items: center;
            gap: 5px 10px;
        }

        .form-grid label {
            margin: 0;
            color: #052A47;
            font-size: 15px;
            font-weight: 400;
            line-height: 1;
        }

        .form-grid input,
        .form-grid select,
        .form-grid textarea {
            height: 26px;
            padding: 2px 8px;
            border: 1px solid #b5c0ca;
            border-radius: 0;
            background: #f3f4f6;
            box-shadow: none;
            font-size: 14px;
            font-weight: 400;
        }

        .form-grid textarea {
            height: 42px;
            resize: vertical;
        }

        .attachment-panel {
            padding: 9px 14px;
        }

        .attachment-panel h3 {
            margin: 0 0 10px;
            color: #052A47;
            font-size: 16px;
            font-weight: 600;
        }

        .attach-box {
            min-height: 122px;
            padding: 18px 22px;
            border: 2px dashed #d4dbe5;
            border-radius: 8px;
            background: #f8fafc;
        }

        .attach-box strong {
            font-weight: 500;
        }

        .attach-box p {
            margin-top: 10px !important;
            color: #64748b;
            font-size: 13px;
        }

        .attach-box input[type="file"] {
            font-size: 13px;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid #bdc7d0;
            background: #f8fff8;
        }

        .tab-btn {
            padding: 8px 14px;
            border: 0;
            border-right: 1px solid #bdc7d0;
            background: #d5d5d5;
            color: #111;
            font-size: 15px;
            font-weight: 600;
        }

        .tab-btn.active {
            background: #fff;
            color: #00846a;
        }

        .tab-total {
            margin-left: 16px;
            color: #111;
            font-weight: 400;
        }

        .tab-content-box {
            display: none;
        }

        .tab-content-box.active {
            display: block;
        }

        .qb-table,
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .qb-table th {
            height: 33px;
            padding: 0 8px;
            border-right: 1px solid #d7dee8;
            border-bottom: 1px solid #aeb8c6;
            background: #fff;
            color: #3d5270;
            font-size: 14px;
            font-weight: 400;
            text-align: left;
        }

        .qb-table td {
            height: 27px;
            padding: 0;
            border-right: 1px solid #d7dee8;
        }

        .qb-table tr:nth-child(even) td {
            background: #eaffea;
        }

        .qb-table input,
        .qb-table select {
            width: 100%;
            height: 27px;
            padding: 0 8px;
            border: 0;
            background: transparent;
            font-size: 13px;
            font-weight: 400;
        }

        .qb-table input:focus,
        .qb-table select:focus {
            outline: 1px solid #22c55e;
            background: #fff;
        }

        .footer-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 8px;
        }

        .btn-qb {
            padding: 8px 26px;
            border: 1px solid #c9d1db;
            border-radius: 4px;
            background: #f8fafc;
            font-size: 14px;
            font-weight: 600;
        }

        .btn-save {
            border-color: #008a66;
            background: #008a66;
            color: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .14);
        }

        .history-table th,
        .history-table td {
            padding: 7px 8px;
            border: 1px solid #d7dee8;
        }

        .history-table th {
            background: #f1f5f9;
        }

        .remove-row {
            border: 0;
            background: transparent;
            color: #dc2626;
            font-weight: 600;
        }

        .alert {
            border-radius: 8px;
        }

        @media (max-width: 1400px) {
            .qb-toolbar {
                grid-template-columns: 180px minmax(320px, 1fr) 420px;
            }

            .qb-form-row {
                grid-template-columns: minmax(650px, 1fr) 360px;
            }

            .form-grid {
                grid-template-columns: 88px minmax(300px, 1fr) 80px 205px;
            }
        }

        @media (max-width: 1200px) {
            .main-content {
                padding: 14px !important;
            }

            .qb-toolbar {
                grid-template-columns: 1fr;
                gap: 4px;
                padding: 4px 8px;
            }

            .right-checks {
                justify-content: flex-start;
            }

            .qb-form-row {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 110px 1fr;
            }

            .qb-table {
                min-width: 900px;
            }

            .tab-content-box {
                overflow: auto;
            }

            .footer-actions {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                z-index: 999;
                transform: translateX(-100%);
                transition: transform .25s ease;
            }

            .sidebar.active,
            .sidebar.show {
                transform: translateX(0);
            }

            .main-content,
            .sidebar.collapsed + .main-content {
                margin-left: 0 !important;
            }

            .sidebar.collapsed {
                width: 300px;
            }
        }
        .mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #ffffff;
    border-top: 1px solid #e5e7eb;
    box-shadow: 0 -2px 12px rgba(0,0,0,.08);
    z-index: 9999;
    display: none;
    padding: 8px 0 12px;
    overflow: visible !important;
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

.mobile-nav .nav::-webkit-scrollbar { display: none; }
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
    padding: 6px 10px;
    color: #9ca3af;
    font-size: .7rem;
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
    line-height: 1;
    margin: 0;
}

.mobile-nav .nav-link span {
    font-size: .65rem;
    font-weight: 600;
}

.mobile-nav .nav-link.active {
    color: #059669;
    background: rgba(5, 150, 105, .1);
}

.mobile-nav .more-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
    border: 1px solid #e5e7eb;
    min-width: 205px;
    z-index: 10000;
    display: none !important;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity .2s ease, transform .2s ease;
    overflow: hidden;
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
    background: #ffffff;
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
    border-bottom: 1px solid #f3f4f6;
    font-size: .85rem;
    background: #ffffff;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

.mobile-nav .dropdown-item:last-child { border-bottom: none; }
.mobile-nav .dropdown-item:hover { background: #f9fafb; }
.mobile-nav .dropdown-item.active {
    background: rgba(5, 150, 105, .1);
    color: #059669;
}
.mobile-nav .dropdown-item i {
    width: 20px;
    font-size: 1rem;
    color: #6b7280;
}
.mobile-nav .dropdown-item.active i { color: #059669; }
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

            <span class="nav-text">Motorpool</span>
        </h3>
    </div>

    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="motorpool_inventory.php">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-text">Current Inventory</span>
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
                                <a class="nav-link" href="enterbills.php">
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
                                <a class="nav-link" href="vendors.php">
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
                                <a class="nav-link" href="withdrawal.php">
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
                                <a class="nav-link" href="chartofaccounts.php">
                                    <i class="bi bi-graph-up"></i>
                                    <span class="nav-text">Chart of Accounts</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="request_handler.php">
                                    <i class="bi bi-clipboard"></i>
                                    <span class="nav-text">RIS Monitoring</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="motorpool.php">
                                    <i class="bi bi-truck"></i>
                                    <span class="nav-text">Vehicle Profile</span>
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
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content no-spinner" id="mainContent">
        <div class="mobile-page-bar"><button class="mobile-toggle-btn" id="mobileToggleBtn" type="button"><i class="bi bi-list"></i></button></div>
        <div class="page-header-card"><h1>Receive Inventory</h1><p>Real-time inventory from database</p></div>
        <?php if ($save_message !== ''): ?>
            <div class="alert alert-<?= $save_status === 'success' ? 'success' : 'danger' ?>">
                <?= h($save_message) ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="billForm">
            <input type="hidden" name="action" value="save_bill">
            <input type="hidden" name="submit_mode" id="submitMode" value="close">

            <div class="receive-card">
                <div class="qb-toolbar">
                    <div class="qb-checks">
                        <label><input type="radio" name="bill_type" value="bill" checked> Bill</label>
                        <label><input type="radio" name="bill_type" value="credit"> Credit</label>
                    </div>

                    <div class="payable-row">
                        <span>Payable</span>
                        <select name="payable_account">
                            <?php foreach ($payableAccounts as $a): ?>
                                <option value="<?= h($a['account_title']) ?>">
                                    <?= h(($a['account_code'] ? $a['account_code'] . ' - ' : '') . $a['account_title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="right-checks">
                        <label><input type="checkbox" name="bill_received" checked> Bill Received</label>
                        <label><input type="checkbox" disabled> Link to Sales Invoice</label>
                        <label><input type="checkbox" disabled> With PO?</label>
                    </div>
                </div>

                <div class="qb-form-row">
                    <div class="bill-panel">
                        <div class="bill-title">Bill</div>
                        <div class="form-grid">
                            <label>VENDOR</label>
                            <select name="vendor_name" id="vendorName" required>
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= h($v) ?>"><?= h($v) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label>DATE</label>
                            <input type="date" name="expense_date" value="<?= h(date('Y-m-d')) ?>" required>

                            <label>ADDRESS</label>
                            <textarea name="vendor_address"></textarea>

                            <label>REF. NO.</label>
                            <input name="ref_no">

                            <label>TERMS</label>
                            <select name="terms" id="termsSelect">
                                <option value="Due on receipt">Due on receipt</option>
                                <option value="Net 15">Net 15</option>
                                <option value="Net 30">Net 30</option>
                                <option value="Net 60">Net 60</option>
                            </select>

                            <label>AMOUNT DUE</label>
                            <input id="amountDue" value="0.00" readonly>

                            <label>BILL DUE</label>
                            <input type="date" name="bill_due" value="<?= h(date('Y-m-d', strtotime('+10 days'))) ?>">

                            <span></span>
                            <span></span>

                            <label>MEMO</label>
                            <input name="memo">

                            <span></span>
                            <span></span>
                        </div>
                    </div>

                    <div class="attachment-panel">
                        <h3>Transaction Attachment</h3>
                        <div class="attach-box">
                            <strong><i class="bi bi-paperclip"></i> Attach</strong>
                            <br><br>
                            <input type="file" name="attachments[]" multiple>
                            <p class="mt-3 mb-0 text-muted">
                                This attachment applies to the whole motorpool receive transaction.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="tabs">
                    <button type="button" class="tab-btn active" data-tab="expenses">
                        Expenses <span class="tab-total" id="expenseTotal">₱0.00</span>
                    </button>
                    <button type="button" class="tab-btn" data-tab="items">
                        Items <span class="tab-total" id="itemTotal">₱0.00</span>
                    </button>
                    <button type="button" class="tab-btn" data-tab="history">
                        Receive History
                    </button>
                </div>

                <div id="expenses" class="tab-content-box active">
                    <table class="qb-table">
                        <thead>
                            <tr>
                                <th style="width: 38%">ACCOUNT TITLE</th>
                                <th style="width: 18%">AMOUNT</th>
                                <th>MEMO</th>
                                <th style="width: 40px"></th>
                            </tr>
                        </thead>
                        <tbody id="expenseRows"></tbody>
                    </table>
                </div>

                <div id="items" class="tab-content-box">
                    <table class="qb-table">
                        <thead>
                            <tr>
                                <th style="width: 32%">ITEM</th>
                                <th style="width: 12%">UNIT</th>
                                <th style="width: 12%">QTY</th>
                                <th style="width: 14%">UNIT COST</th>
                                <th style="width: 14%">AMOUNT</th>
                                <th>MEMO</th>
                                <th style="width: 40px"></th>
                            </tr>
                        </thead>
                        <tbody id="itemRows"></tbody>
                    </table>
                </div>

                <div id="history" class="tab-content-box p-3">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Receive No.</th>
                                <th>Vendor</th>
                                <th>Reference</th>
                                <th>Total</th>
                                <th>Received By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($receiveHistory)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No receive history yet.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($receiveHistory as $r): ?>
                                <tr>
                                    <td><?= h($r['receive_date']) ?></td>
                                    <td><?= h($r['receive_no']) ?></td>
                                    <td><?= h($r['vendor_name']) ?></td>
                                    <td><?= h($r['ref_no']) ?></td>
                                    <td>₱<?= money($r['total_amount']) ?></td>
                                    <td><?= h(trim($r['first_name'] . ' ' . $r['last_name']) ?: 'Motorpool') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="footer-actions">
                <button type="button" class="btn-qb" onclick="saveBill('close')">Save & Close</button>
                <button type="button" class="btn-qb btn-save" onclick="saveBill('new')">Save & New</button>
                <button type="button" class="btn-qb" onclick="clearForm()">Clear</button>
            </div>
        </form>
    </main>
  <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="motorpool_inventory.php">
                    <i class="bi bi-box-seam"></i>
                    <span>Inventory</span>
                </a>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'vendorMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Vendor</span>
                </a>
                <div class="more-dropdown" id="vendorMobileMenu">
                    <a class="dropdown-item active" href="enterbills.php"><i
                            class="bi bi-file-earmark-text"></i><span>Enter Bills</span></a>
                    <a class="dropdown-item" href="paybills.php"><i class="bi bi-currency-dollar"></i><span>Pay
                            Bills</span></a>
                    <a class="dropdown-item" href="vendors.php"><i class="bi bi-shop"></i><span>Vendor List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                    <i class="bi bi-people-fill"></i>
                    <span>Customer</span>
                </a>
                <div class="more-dropdown" id="customerMobileMenu">
                    <a class="dropdown-item" href="orderproduct.php"><i class="bi bi-receipt"></i><span>Create
                            Invoice</span></a>
                    <a class="dropdown-item" href="collections.php"><i class="bi bi-cash-stack"></i><span>Receive
                            Payment</span></a>
                    <a class="dropdown-item" href="customer_list.php"><i class="bi bi-person-badge"></i><span>Customer
                            List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'employeeMobileMenu')">
                    <i class="bi bi-briefcase"></i>
                    <span>Employees</span>
                </a>
                <div class="more-dropdown" id="employeeMobileMenu">
                    <a class="dropdown-item" href="employeelist.php"><i class="bi bi-receipt"></i><span>Employee
                            List</span></a>
                    <a class="dropdown-item" href="employee.php"><i class="bi bi-cash-stack"></i><span>Enter
                            Time</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                    <i class="bi bi-bank2"></i>
                    <span>Banking</span>
                </a>
                <div class="more-dropdown" id="bankingMobileMenu">
                    <a class="dropdown-item" href="deposit.php"><i class="bi bi-bank"></i><span>Record
                            Deposit</span></a>
                    <a class="dropdown-item" href="withdrawal.php"><i class="bi bi-journal-check"></i><span>Write
                            Checks</span></a>
                    <a class="dropdown-item" href="transferfunds.php"><i
                            class="bi bi-arrow-left-right"></i><span>Transfer Funds</span></a>
                </div>
            </li>


            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'companyMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Company</span>
                </a>
                <div class="more-dropdown" id="companyMobileMenu">
                    <a class="dropdown-item" href="chartofaccounts.php"><i class="bi bi-graph-up"></i><span>Chart of
                            Accounts</span></a>
                    <a class="dropdown-item" href="request_handler.php"><i class="bi bi-clipboard"></i><span>RIS
                            Monitoring</span></a>
                    <a class="dropdown-item" href="motorpool.php"><i class="bi bi-truck"></i><span>Vehicle
                            Profile</span></a>
                    <a class="dropdown-item" href="central_warehouse.php"><i class="bi bi-box-seam"></i><span>Central
                            Warehouse</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'accountingMobileMenu')">
                    <i class="bi bi-graph-up"></i>
                    <span>Accounting</span>
                </a>
                <div class="more-dropdown" id="accountingMobileMenu">
                    <a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal"></i><span>Journal
                            Entries</span></a>
                    <a class="dropdown-item" href="batch_transaction.php"><i class="bi bi-collection"></i><span>Batch
                            Transactions</span></a>
                    <a class="dropdown-item" href="item_adjustment.php"><i class="bi bi-sliders"></i><span>Item
                            Adjustment</span></a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="it_request.php">
                    <i class="bi bi-headset"></i>
                    <span>IT Request</span>
                </a>
            </li>


            <li class="nav-item" id="profileMobileBtn">
                <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#profileModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button
                        type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p><?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="branch-info mb-3"><i
                                class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span>
                        </div><?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100"
                        onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const accounts = <?= json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const items = <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, function (match) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            }[match];
        });
    }

    function num(value) {
        const parsed = parseFloat(String(value ?? '').replace(/,/g, ''));
        return Number.isNaN(parsed) ? 0 : parsed;
    }

    function fmt(value) {
        return num(value).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function accountOptions() {
        return '<option value=""></option>' + accounts.map(function (account) {
            const label = (account.account_code ? account.account_code + ' - ' : '') + account.account_title;
            return `<option value="${esc(account.account_title)}">${esc(label)}</option>`;
        }).join('');
    }

    function itemOptions() {
        return '<option value=""></option>' + items.map(function (item) {
            const label = (item.item_code ? item.item_code + ' - ' : '') + item.item_name;
            const unit = item.unit_type || 'Piece';
            return `
                <option
                    value="${esc(item.item_id)}"
                    data-unit="${esc(unit)}"
                    data-cost="${esc(item.unit_cost || 0)}"
                >${esc(label)} (${esc(unit)})</option>
            `;
        }).join('');
    }

    function addExpenseRow() {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <select name="expense_account[]" onchange="ensureExpenseRows()">
                    ${accountOptions()}
                </select>
            </td>
            <td>
                <input name="expense_amount[]" type="number" step="0.01" min="0" oninput="recalc()">
            </td>
            <td>
                <input name="expense_memo[]">
            </td>
            <td>
                <button type="button" class="remove-row" onclick="removeRow(this, 'expense')">×</button>
            </td>
        `;
        document.getElementById('expenseRows').appendChild(row);
    }

    function addItemRow() {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <select name="item_id[]" onchange="itemChanged(this); ensureItemRows()">
                    ${itemOptions()}
                </select>
            </td>
            <td><input name="item_unit[]" readonly></td>
            <td><input name="item_qty[]" type="number" step="0.01" min="0" oninput="recalc()"></td>
            <td><input name="item_cost[]" type="number" step="0.01" min="0" oninput="recalc()"></td>
            <td><input class="line-amount" readonly></td>
            <td><input name="item_memo[]"></td>
            <td>
                <button type="button" class="remove-row" onclick="removeRow(this, 'item')">×</button>
            </td>
        `;
        document.getElementById('itemRows').appendChild(row);
    }

    function removeRow(button, type) {
        button.closest('tr').remove();
        if (type === 'item') {
            ensureItemRows();
        } else {
            ensureExpenseRows();
        }
        recalc();
    }

    function ensureExpenseRows() {
        const rows = document.querySelectorAll('#expenseRows tr');
        if (rows.length < 8) {
            addExpenseRow();
        }
    }

    function ensureItemRows() {
        const rows = document.querySelectorAll('#itemRows tr');
        if (rows.length < 8) {
            addItemRow();
        }
    }

    function itemChanged(select) {
        const option = select.options[select.selectedIndex];
        const row = select.closest('tr');

        row.querySelector('[name="item_unit[]"]').value = option?.dataset.unit || '';
        row.querySelector('[name="item_cost[]"]').value = parseFloat(option?.dataset.cost || 0).toFixed(2);

        if (!row.querySelector('[name="item_qty[]"]').value) {
            row.querySelector('[name="item_qty[]"]').value = '1';
        }

        recalc();
    }

    function recalc() {
        let expenseTotal = 0;
        let itemTotal = 0;

        document.querySelectorAll('#expenseRows tr').forEach(function (row) {
            expenseTotal += num(row.querySelector('[name="expense_amount[]"]')?.value);
        });

        document.querySelectorAll('#itemRows tr').forEach(function (row) {
            const qty = num(row.querySelector('[name="item_qty[]"]')?.value);
            const cost = num(row.querySelector('[name="item_cost[]"]')?.value);
            const lineTotal = qty * cost;
            const lineAmount = row.querySelector('.line-amount');

            if (lineAmount) {
                lineAmount.value = fmt(lineTotal);
            }

            itemTotal += lineTotal;
        });

        document.getElementById('expenseTotal').textContent = '₱' + fmt(expenseTotal);
        document.getElementById('itemTotal').textContent = '₱' + fmt(itemTotal);
        document.getElementById('amountDue').value = fmt(expenseTotal + itemTotal);
    }

    function saveBill(mode) {
        document.getElementById('submitMode').value = mode;
        document.getElementById('billForm').submit();
    }

    function clearForm() {
        document.getElementById('billForm').reset();
        document.getElementById('expenseRows').innerHTML = '';
        document.getElementById('itemRows').innerHTML = '';

        for (let i = 0; i < 8; i++) {
            addExpenseRow();
            addItemRow();
        }

        recalc();
    }

    document.querySelectorAll('.tab-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content-box').forEach(tab => tab.classList.remove('active'));

            button.classList.add('active');
            document.getElementById(button.dataset.tab).classList.add('active');
        });
    });

    // ========== SIDEBAR FUNCTIONS ==========

    // Helper function para sa dropdown arrow state na walang layout shift
    function setArrowState(arrowElement, isOpen) {
        if (!arrowElement) return;
        if (isOpen) {
            arrowElement.style.transform = 'rotate(180deg)';
            arrowElement.style.willChange = 'transform';
        } else {
            arrowElement.style.transform = 'rotate(0deg)';
            arrowElement.style.willChange = '';
        }
    }

    // Dropdown behavior copied from chartofaccounts.php
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

        // Kapag collapsed ang sidebar sa desktop, i-expand muna bago buksan dropdown
        if (sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');

            setTimeout(function () {
                document.querySelectorAll('.sidebar .collapse.show').forEach(function (collapse) {
                    if (collapse.id !== targetId) {
                        collapse.classList.remove('show');
                        const otherBtn = document.querySelector('[onclick*="' + collapse.id + '"]');
                        if (otherBtn) setArrowState(otherBtn.querySelector('.dropdown-arrow'), false);
                    }
                });

                target.classList.add('show');
                setArrowState(arrow, true);
            }, 50);

            return false;
        }

        if (target.classList.contains('show')) {
            target.classList.remove('show');
            setArrowState(arrow, false);
        } else {
            document.querySelectorAll('.sidebar .collapse.show').forEach(function (collapse) {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector('[onclick*="' + collapse.id + '"]');
                    if (otherBtn) setArrowState(otherBtn.querySelector('.dropdown-arrow'), false);
                }
            });

            target.classList.add('show');
            setArrowState(arrow, true);
        }

        return false;
    }

    // Collapse/expand sidebar behavior copied from chartofaccounts.php
    window.toggleSidebar = function () {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return false;

        if (window.innerWidth <= 992) {
            const willOpen = !sidebar.classList.contains('active');
            sidebar.classList.toggle('active');
            let overlay = document.querySelector('.sidebar-overlay');

            if (willOpen) {
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', function () {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    });
                }
                setTimeout(function () { overlay.classList.add('active'); }, 10);
            } else if (overlay) {
                overlay.classList.remove('active');
            }
        } else {
            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');

            if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
                setTimeout(function () {
                    document.querySelectorAll('.dropdown-nav').forEach(function (dropdownNav) {
                        const activeLink = dropdownNav.querySelector('.nav-link.active');
                        if (activeLink) {
                            const collapseDiv = dropdownNav.querySelector('.collapse');
                            if (collapseDiv && !collapseDiv.classList.contains('show')) {
                                collapseDiv.classList.add('show');
                                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                                if (parentLink) setArrowState(parentLink.querySelector('.dropdown-arrow'), true);
                            }
                        }
                    });
                }, 150);
            } else if (sidebar.classList.contains('collapsed')) {
                document.querySelectorAll('.sidebar .collapse.show').forEach(function (collapse) {
                    collapse.classList.remove('show');
                    const parentBtn = document.querySelector('[onclick*="' + collapse.id + '"]');
                    if (parentBtn) setArrowState(parentBtn.querySelector('.dropdown-arrow'), false);
                });
            }
        }

        return false;
    };

    // Active sidebar item behavior copied from chartofaccounts.php
    function setActiveSidebarItem() {
        const currentPage = window.location.pathname.split('/').pop() || 'enterbills.php';

        document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
            link.classList.remove('active');
        });

        document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                link.classList.add('active');

                const collapseDiv = link.closest('.collapse');
                if (collapseDiv) {
                    collapseDiv.classList.add('show');
                    const parentBtn = document.querySelector('[onclick*="' + collapseDiv.id + '"]');
                    if (parentBtn) setArrowState(parentBtn.querySelector('.dropdown-arrow'), true);
                }
            }
        });

        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('collapsed')) {
            document.querySelectorAll('.dropdown-nav').forEach(function (dropdownNav) {
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

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn') || document.getElementById('mobileToggleBtn');

        if (sidebar && window.innerWidth > 992) {
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }

        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                window.toggleSidebar();
            });
        }

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                window.toggleSidebar();
            });
        }

        setActiveSidebarItem();

        document.addEventListener('click', function (e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.classList.remove('active');
            }
        });

        document.querySelectorAll('.sidebar .collapse').forEach(function (collapse) {
            collapse.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        });

        window.addEventListener('resize', function () {
            const overlay = document.querySelector('.sidebar-overlay');
            if (window.innerWidth > 992) {
                if (overlay) overlay.classList.remove('active');
                if (sidebar) sidebar.classList.remove('active');
            } else if (sidebar) {
                sidebar.classList.remove('collapsed');
            }
            setActiveSidebarItem();
        });
    }

    function logout() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#08d33f',
                confirmButtonText: 'Yes, logout'
            }).then(function (result) {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    location.href = '../logout.php';
                }
            });
            return;
        }

        localStorage.removeItem('sidebarCollapsed');
        location.href = '../logout.php';
    }

    initializeSidebar();

    clearForm();
    // ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
function closeAllMobileDropdowns() {
    document.querySelectorAll('.mobile-nav .more-dropdown.show').forEach(function (dropdown) {
        dropdown.classList.remove('show');
    });
    document.querySelectorAll('.mobile-nav .more-btn.active').forEach(function (btn) {
        const menuId = (btn.getAttribute('onclick') || '').match(/'([^']+)'/);
        const menu = menuId ? document.getElementById(menuId[1]) : null;
        const hasActiveChild = menu && menu.querySelector('.dropdown-item.active');
        if (!hasActiveChild) btn.classList.remove('active');
    });
}

function toggleMobileDropdown(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const dropdown = document.getElementById(dropdownId);
    const btn = event ? event.currentTarget : document.querySelector(`[onclick*="${dropdownId}"]`);
    if (!dropdown || !btn) return false;

    if (dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
        if (!dropdown.querySelector('.dropdown-item.active')) btn.classList.remove('active');
    } else {
        closeAllMobileDropdowns();
        dropdown.classList.add('show');
        btn.classList.add('active');
    }
    return false;
}

function setActiveMobileNav() {
    const currentPage = window.location.pathname.split('/').pop() || 'request_handler.php';
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        link.classList.remove('active');
    });
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        const href = (link.getAttribute('href') || '').split('?')[0];
        if (href === currentPage) {
            link.classList.add('active');
            const parentDropdown = link.closest('.more-dropdown');
            if (parentDropdown) {
                const parentBtn = document.querySelector(`[onclick*="${parentDropdown.id}"]`);
                if (parentBtn) parentBtn.classList.add('active');
            }
        }
    });
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.mobile-nav .dropdown-more')) closeAllMobileDropdowns();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMobileDropdowns();
});
</script>
</body>
</html>
