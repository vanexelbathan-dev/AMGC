<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';

requireLogin();
requireRole(['rolling']);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim(($_SESSION['first_name'] ?? 'Rolling') . ' ' . ($_SESSION['last_name'] ?? 'Account'));
$user_role = $_SESSION['role'] ?? 'rolling';
$branch_id = (int)($_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0);
$view_all_branches = false;

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function columnExists($conn, $table, $column) {
    if (!tableExists($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function money($value) {
    return '₱' . number_format((float)$value, 2);
}

function getUserInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials !== '' ? substr($initials, 0, 2) : 'RA';
}

function ensureRollingExpenseTables($conn) {
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
        KEY `branch_id` (`branch_id`),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `rolling_expenses` (
        `expense_id` int(11) NOT NULL AUTO_INCREMENT,
        `branch_id` int(11) NOT NULL DEFAULT 0,
        `rolling_user_id` int(11) NOT NULL DEFAULT 0,
        `expense_date` datetime NOT NULL,
        `expense_account` varchar(150) NOT NULL,
        `payee` varchar(150) DEFAULT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `payment_method` enum('cash','check','online_transfer','other') NOT NULL DEFAULT 'cash',
        `reference_number` varchar(100) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `attachment_path` varchar(500) DEFAULT NULL,
        `attachment_name` varchar(255) DEFAULT NULL,
        `status` varchar(30) NOT NULL DEFAULT 'recorded',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`expense_id`),
        KEY `branch_id` (`branch_id`),
        KEY `rolling_user_id` (`rolling_user_id`),
        KEY `expense_date` (`expense_date`),
        KEY `expense_account` (`expense_account`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $cols = [
        'payment_method' => "ALTER TABLE rolling_expenses ADD COLUMN payment_method enum('cash','check','online_transfer','other') NOT NULL DEFAULT 'cash' AFTER amount",
        'reference_number' => "ALTER TABLE rolling_expenses ADD COLUMN reference_number varchar(100) DEFAULT NULL AFTER payment_method",
        'attachment_path' => "ALTER TABLE rolling_expenses ADD COLUMN attachment_path varchar(500) DEFAULT NULL AFTER description",
        'attachment_name' => "ALTER TABLE rolling_expenses ADD COLUMN attachment_name varchar(255) DEFAULT NULL AFTER attachment_path",
        'status' => "ALTER TABLE rolling_expenses ADD COLUMN status varchar(30) NOT NULL DEFAULT 'recorded' AFTER attachment_name"
    ];
    foreach ($cols as $col => $sql) {
        if (!columnExists($conn, 'rolling_expenses', $col)) {
            @$conn->query($sql);
        }
    }
}

function uploadExpenseAttachment($fieldName) {
    if (empty($_FILES[$fieldName]) || !isset($_FILES[$fieldName]['error']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Failed to upload attachment.');
    }

    $maxSize = 8 * 1024 * 1024;
    if ((int)$_FILES[$fieldName]['size'] > $maxSize) {
        throw new Exception('Attachment must not exceed 8MB.');
    }

    $original = basename((string)$_FILES[$fieldName]['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Only JPG, PNG, WEBP, GIF, and PDF files are allowed.');
    }

    $dir = __DIR__ . '/../uploads/rolling_expenses/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new Exception('Upload folder is not writable: uploads/rolling_expenses');
    }

    $safeName = 'rolling_expense_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . $safeName;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
        throw new Exception('Unable to save attachment.');
    }

    return ['../uploads/rolling_expenses/' . $safeName, $original];
}

function getSavedExpenseAccounts($conn, $branch_id, $user_id, $search = '') {
    ensureRollingExpenseTables($conn);
    $sql = "SELECT id, bank_name, is_sub_account, parent_bank_name, description, branch_id, created_by, created_at
            FROM expense_accounts
            WHERE (branch_id = ? OR branch_id = 0)
              AND (created_by = ? OR created_by = 0 OR created_by IS NULL)";
    $types = 'ii';
    $params = [$branch_id, $user_id];

    if ($search !== '') {
        $sql .= " AND (bank_name LIKE ? OR parent_bank_name LIKE ? OR description LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY COALESCE(NULLIF(parent_bank_name, ''), bank_name) ASC, is_sub_account ASC, bank_name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getExpenseRows($conn, $branch_id, $user_id, $from = null, $to = null, $search = '') {
    ensureRollingExpenseTables($conn);

    $sql = "SELECT re.*, CONCAT(COALESCE(u.first_name,''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
            FROM rolling_expenses re
            LEFT JOIN users u ON u.user_id = re.rolling_user_id
            WHERE re.rolling_user_id = ?";
    $types = 'i';
    $params = [$user_id];

    if ($branch_id > 0) {
        $sql .= " AND re.branch_id = ?";
        $types .= 'i';
        $params[] = $branch_id;
    }
    if (!empty($from)) {
        $sql .= " AND DATE(re.expense_date) >= ?";
        $types .= 's';
        $params[] = $from;
    }
    if (!empty($to)) {
        $sql .= " AND DATE(re.expense_date) <= ?";
        $types .= 's';
        $params[] = $to;
    }
    if ($search !== '') {
        $sql .= " AND (re.expense_account LIKE ? OR re.payee LIKE ? OR re.description LIKE ? OR re.reference_number LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY re.expense_date DESC, re.expense_id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getExpenseTree($rows, $savedAccounts = []) {
    $tree = [];
    foreach ($savedAccounts as $saved) {
        $name = trim($saved['bank_name'] ?? '');
        if ($name === '') continue;
        $isSub = (int)($saved['is_sub_account'] ?? 0) === 1;
        $parent = trim($saved['parent_bank_name'] ?? '');
        $account = $isSub && $parent !== '' ? $parent : $name;
        $payee = $isSub ? $name : '';
        if (!isset($tree[$account])) {
            $tree[$account] = ['account' => $account, 'total' => 0, 'count' => 0, 'payees' => []];
        }
        if ($payee !== '' && !isset($tree[$account]['payees'][$payee])) {
            $tree[$account]['payees'][$payee] = ['payee' => $payee, 'total' => 0, 'count' => 0, 'latest_date' => null];
        }
    }

    foreach ($rows as $row) {
        $account = trim($row['expense_account'] ?? '') ?: 'Uncategorized Expense';
        $payee = trim($row['payee'] ?? '') ?: 'No Payee';
        if (!isset($tree[$account])) {
            $tree[$account] = ['account' => $account, 'total' => 0, 'count' => 0, 'payees' => []];
        }
        if (!isset($tree[$account]['payees'][$payee])) {
            $tree[$account]['payees'][$payee] = ['payee' => $payee, 'total' => 0, 'count' => 0, 'latest_date' => null];
        }
        $amount = (float)$row['amount'];
        $tree[$account]['total'] += $amount;
        $tree[$account]['count']++;
        $tree[$account]['payees'][$payee]['total'] += $amount;
        $tree[$account]['payees'][$payee]['count']++;
        $latest = $tree[$account]['payees'][$payee]['latest_date'];
        if ($latest === null || strtotime($row['expense_date']) > strtotime($latest)) {
            $tree[$account]['payees'][$payee]['latest_date'] = $row['expense_date'];
        }
    }
    ksort($tree, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($tree as &$account) {
        uasort($account['payees'], function($a, $b) {
            if ((float)$a['total'] === (float)$b['total']) return strcasecmp($a['payee'], $b['payee']);
            return $b['total'] <=> $a['total'];
        });
    }
    unset($account);
    return $tree;
}

function renderExpensesTableHtml($tree) {
    ob_start();
    ?>
    <div class="section-card">
        <div class="section-header">
            <div>
                <h5 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>Expense Summary</h5>
                <small class="text-muted">Click an account or payee to view transactions.</small>
            </div>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle expense-group-table">
                    <thead>
                        <tr>
                            <th>Expense Account / Paid To</th>
                            <th class="text-center">Transactions</th>
                            <th>Latest Date</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($tree)): ?>
                        <?php foreach ($tree as $account): ?>
                            <tr class="group-header clickable-expense-row" data-expense-account="<?php echo h($account['account']); ?>" data-payee="">
                                <td><strong><i class="bi bi-folder2-open me-2"></i><?php echo h($account['account']); ?></strong></td>
                                <td class="text-center"><?php echo number_format($account['count']); ?></td>
                                <td class="text-muted">All payees</td>
                                <td class="text-end text-muted">-</td>
                                <td class="text-end amount-neutral fw-bold"><?php echo money($account['total']); ?></td>
                            </tr>
                            <?php foreach ($account['payees'] as $payee): ?>
                                <tr class="child-row clickable-expense-row" data-expense-account="<?php echo h($account['account']); ?>" data-payee="<?php echo h($payee['payee']); ?>">
                                    <td><span class="expense-name-link"><i class="bi bi-arrow-return-right me-2"></i><?php echo h($payee['payee']); ?></span></td>
                                    <td class="text-center"><?php echo number_format($payee['count']); ?></td>
                                    <td><?php echo !empty($payee['latest_date']) ? h(date('M d, Y', strtotime($payee['latest_date']))) : '-'; ?></td>
                                    <td class="text-end amount-negative"><?php echo ((float)$payee['total'] > 0) ? money($payee['total']) : '-'; ?></td>
                                    <td class="text-end text-muted">-</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>No expenses yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderExpenseTransactionsHtml($rows, $expenseAccount, $payee) {
    $filtered = [];
    foreach ($rows as $row) {
        $rowAccount = trim($row['expense_account'] ?? '') ?: 'Uncategorized Expense';
        $rowPayee = trim($row['payee'] ?? '') ?: 'No Payee';
        if ($rowAccount === $expenseAccount && ($payee === '' || $rowPayee === $payee)) {
            $filtered[] = $row;
        }
    }
    $total = 0.0;
    foreach ($filtered as $row) $total += (float)$row['amount'];

    ob_start();
    ?>
    <div class="mb-4 p-3 border rounded" style="background:#e8f5e9">
        <h6 class="mb-2">Expense Details</h6>
        <div class="row g-2">
            <div class="col-md-6 col-lg-4"><strong>Expense Account:</strong> <?php echo h($expenseAccount); ?></div>
            <div class="col-md-6 col-lg-4"><strong>Paid To:</strong> <?php echo h($payee !== '' ? $payee : 'All Payees'); ?></div>
            <div class="col-md-6 col-lg-4"><strong>Total Amount:</strong> <?php echo money($total); ?></div>
            <div class="col-md-6 col-lg-4"><strong>Transaction Count:</strong> <?php echo count($filtered); ?></div>
        </div>
    </div>
    <?php if (empty($filtered)): ?>
        <div class="text-center py-5 text-muted">No transactions found.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Paid To</th>
                        <th>Method</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                        <th>Attachment</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filtered as $tx): ?>
                    <tr>
                        <td><?php echo h(date('M d, Y', strtotime($tx['expense_date']))); ?></td>
                        <td><?php echo h($tx['reference_number'] ?: '-'); ?></td>
                        <td><?php echo h($tx['payee'] ?: '-'); ?></td>
                        <td><?php echo h(ucwords(str_replace('_', ' ', $tx['payment_method'] ?? 'cash'))); ?></td>
                        <td><?php echo h($tx['description'] ?: '-'); ?></td>
                        <td class="text-end amount-negative"><?php echo money($tx['amount']); ?></td>
                        <td>
                            <?php if (!empty($tx['attachment_path'])): ?>
                                <a href="<?php echo h($tx['attachment_path']); ?>" target="_blank" class="btn btn-sm btn-outline-amgc-dark"><i class="bi bi-paperclip"></i> View</a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

ensureRollingExpenseTables($conn);

$branch_name = 'Rolling Branch';
if ($branch_id > 0) {
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) $branch_name = $row['branch_name'];
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_expense') {
            $expense_date = trim($_POST['expense_date'] ?? '');
            $expense_account = trim($_POST['expense_account'] ?? '');
            $payee = trim($_POST['payee'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $payment_method = trim($_POST['payment_method'] ?? 'cash');
            $reference_number = trim($_POST['reference_number'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($expense_date === '') $expense_date = date('Y-m-d');
            if ($expense_account === '') throw new Exception('Expense Account is required.');
            if ($payee === '') throw new Exception('Paid To is required.');
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
            if (!in_array($payment_method, ['cash','check','online_transfer','other'], true)) $payment_method = 'cash';

            [$attachmentPath, $attachmentName] = uploadExpenseAttachment('attachment');
            $dt = date('Y-m-d H:i:s', strtotime($expense_date . ' ' . date('H:i:s')));

            $stmt = $conn->prepare("INSERT INTO rolling_expenses (branch_id, rolling_user_id, expense_date, expense_account, payee, amount, payment_method, reference_number, description, attachment_path, attachment_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'recorded')");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('iisssdsssss', $branch_id, $user_id, $dt, $expense_account, $payee, $amount, $payment_method, $reference_number, $description, $attachmentPath, $attachmentName);
            if (!$stmt->execute()) throw new Exception('Failed to save expense: ' . $stmt->error);
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Expense saved successfully.']);
            exit;
        }

        if ($action === 'add_expense_account') {
            $bank_name = trim($_POST['bank_name'] ?? '');
            $is_sub_account = isset($_POST['is_sub_account']) ? 1 : 0;
            $parent_bank_name = $is_sub_account ? trim($_POST['parent_bank_name'] ?? '') : null;
            $description = trim($_POST['description'] ?? '');

            if ($bank_name === '') throw new Exception('Expense Account is required.');
            if ($is_sub_account && trim((string)$parent_bank_name) === '') throw new Exception('Parent Expense Account is required.');
            if ($description === '') throw new Exception('Description is required.');

            $stmt = $conn->prepare("INSERT INTO expense_accounts (bank_name, is_sub_account, parent_bank_name, description, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('sissii', $bank_name, $is_sub_account, $parent_bank_name, $description, $branch_id, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to save expense account: ' . $stmt->error);
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Expense account added successfully.']);
            exit;
        }

        throw new Exception('Invalid action.');
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : null;
$to_date = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : null;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_filtered_data') {
    header('Content-Type: application/json; charset=utf-8');
    $rows = getExpenseRows($conn, $branch_id, $user_id, $from_date, $to_date, $search_term);
    $saved = getSavedExpenseAccounts($conn, $branch_id, $user_id, $search_term);
    $tree = getExpenseTree($rows, $saved);
    echo json_encode(['success' => true, 'table' => renderExpensesTableHtml($tree)]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_expense_transactions') {
    $expense_account = trim($_GET['expense_account'] ?? '');
    $payee = trim($_GET['payee'] ?? '');
    if ($expense_account === '') {
        echo '<div class="alert alert-danger">Invalid expense account.</div>';
        exit;
    }
    $rows = getExpenseRows($conn, $branch_id, $user_id, $from_date, $to_date, $search_term);
    echo renderExpenseTransactionsHtml($rows, $expense_account, $payee);
    exit;
}

$expenseRows = getExpenseRows($conn, $branch_id, $user_id, $from_date, $to_date, $search_term);
$allExpenseRows = getExpenseRows($conn, $branch_id, $user_id, null, null, '');
$savedAccounts = getSavedExpenseAccounts($conn, $branch_id, $user_id, $search_term);
$allSavedAccounts = getSavedExpenseAccounts($conn, $branch_id, $user_id, '');
$expenseTree = getExpenseTree($expenseRows, $savedAccounts);
$allExpenseTree = getExpenseTree($allExpenseRows, $allSavedAccounts);

$total_expenses = 0;
$total_transactions = count($allExpenseRows);
$largest_account = '-';
$largest_account_total = 0;
foreach ($allExpenseRows as $row) $total_expenses += (float)$row['amount'];
foreach ($allExpenseTree as $account) {
    if ((float)$account['total'] > $largest_account_total) {
        $largest_account_total = (float)$account['total'];
        $largest_account = $account['account'];
    }
}

$accountOptions = [];
foreach ($allSavedAccounts as $acc) {
    $name = trim($acc['bank_name'] ?? '');
    if ($name !== '') $accountOptions[$name] = $name;
    $parent = trim($acc['parent_bank_name'] ?? '');
    if ($parent !== '') $accountOptions[$parent] = $parent;
}
foreach ($allExpenseRows as $row) {
    $name = trim($row['expense_account'] ?? '');
    if ($name !== '') $accountOptions[$name] = $name;
}
ksort($accountOptions, SORT_NATURAL | SORT_FLAG_CASE);

$user_initials = getUserInitials($user_name);
$page_title = 'Rolling Expenses';
$page_subtitle = 'Record flat tire, vulcanizing, fuel, repair, and other route expenses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?> - AMGC</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/rolling.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.mobile-menu-btn {
    display:none;
    background:transparent;
    border:none;
    font-size:1.5rem;
    color: #052A47;
}
.section-card,.form-card {
    background:#fff;
    border-radius:18px;
    border:1px solid rgba(68,211,78,.12);
    box-shadow:0 8px 20px rgba(15,23,42,.05);
    margin-bottom:1rem;
    overflow:hidden;
}
.section-header {
    padding:1rem 1.25rem;
    border-bottom:1px solid #eef2f7;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:1rem;
    flex-wrap:wrap;
}
.section-body {
    padding:1rem 1.25rem;
}
.table thead th {
    background-color: #f8f9fa;
    color: #052A47;
    border-bottom:2px solid #dee2e6;
    white-space:nowrap;
    font-weight:400;
}
.amount-negative {
    color: #dc2626;
    font-weight:500;
}
.amount-neutral {
    color: #052A47;
    font-weight:500;
}
.form-control,.form-select {
    border-radius:10px;
    min-height:44px;
}
.expense-group-table .group-header {
    background:#f8f9fa;
    font-weight:400;
    border-bottom:2px solid #dee2e6;
}
.clickable-expense-row {
    cursor:pointer;
}
.clickable-expense-row:hover td {
    background-color: #f8fff9!important;
}
.expense-name-link {
    color: #047857;
    font-weight:500;
}
.child-row td:first-child {
    padding-left:2rem!important;
}
.stat-card-row {
    margin-bottom:1.5rem;
}
.stat-card {
    background:linear-gradient(135deg, #047857, #059669)!important;
    border:none!important;
    box-shadow:0 4px 10px rgba(0,0,0,.08)!important;
    padding:1rem!important;
    color: #fff!important;
    border-radius:14px!important;
    min-height:120px;
    display:flex;
    gap:.75rem;
    align-items:flex-start;
}
.stat-card *{
    color: #fff!important;
}
.stat-card:hover {
    transform:translateY(-2px);
    box-shadow:0 6px 15px rgba(0,0,0,.15)!important;
}
.stat-icon {
    font-size:1.6rem;
}
.stat-value {
    font-size:1.25rem;
}
.stat-label {
    font-size:.8rem;
}
.btn-amgc-primary,.btn-amgc-dark,.btn-amgc-light,.btn-outline-amgc-dark {
    padding:.5rem 1rem;
    border-radius:999px!important;
    font-size:14px;
    display:inline-flex;
    align-items:center;
    gap:.5rem;
    box-shadow:0 4px 10px rgba(0,0,0,.12);
    border:none;
}
.btn-amgc-primary {
    background:linear-gradient(135deg, #047857, #44D34E);
    color: #fff;
}
.btn-amgc-dark {
    background: #047857;
    color: #fff;
}
.btn-amgc-light {
    background: #d1fae5;
    color: #047857;
    border:1px solid #44D34E;
}
.btn-outline-amgc-dark {
    background: #fff;
    color: #047857;
    border:2px solid #047857;
}
.btn-amgc-primary:hover,.btn-amgc-dark:hover {
    color: #fff;
    transform:translateY(-1px);
}
.btn-amgc-light:hover,.btn-outline-amgc-dark:hover {
    color: #047857;
}
.mobile-nav {
    position:fixed;
    bottom:0;
    left:0;
    right:0;
    background: #fff;
    box-shadow:0 -2px 10px rgba(0,0,0,.1);
    padding:8px 12px;z-index:1000;
    display:none;
}
.mobile-nav .nav {
    display:flex;
    justify-content:space-around;
    list-style:none;
    margin:0;
    padding:0;
}
.mobile-nav .nav-link {
    display:flex;
    flex-direction:column;
    align-items:center;
    font-size:.72rem;
    color: #6c757d;
    text-decoration:none;
}
.mobile-nav .nav-link.active {
    color: #047857;
}
.mobile-nav i {
    font-size:1.25rem;
}
@media (max-width:992px) {
    .sidebar {
        transform:translateX(-100%);
    }
    .sidebar.active {
        transform:translateX(0);
    }
    .main-content {
        margin-left:0!important;
        padding:14px;
    }
    .mobile-menu-btn {
        display:block
    }
    .mobile-nav {
        display:block;
    }
    body {
        padding-bottom:76px;
    }
}
@media (max-width:768px) {
    .stat-card {
        aspect-ratio:1/1;
        min-height:auto;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        text-align:center;
        padding:.5rem!important;
    }
    .stat-card small {
        display:none;
    }
    .stat-value {
        font-size:.95rem;
    }
    .stat-label {
        font-size:.62rem;
    }
    .table {
        min-width:850px;
    }
    .section-header {
        display:block
    }
}

/* Rolling sidebar/mobile navigation matched with current_inventory.php */
.mobile-toggle-btn{display:none;border:none;background:transparent;color:var(--dark-color);font-size:1.6rem}
.sidebar-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1040;display:none}.sidebar-overlay.active{display:block}
.mobile-nav .nav-item{flex:1;text-align:center}.mobile-nav .nav-link{position:relative;gap:2px}.mobile-nav .nav-link span{font-size:.65rem}.mobile-nav .nav-link.active::after{content:'';position:absolute;bottom:-2px;left:50%;transform:translateX(-50%);width:30px;height:2px;background:#047857;border-radius:2px}
@media(max-width:992px){.mobile-toggle-btn{display:block}.sidebar{display:none}.sidebar.active{display:block;position:fixed;top:0;left:0;width:280px;height:100%;z-index:1050;background:#fff;box-shadow:2px 0 10px rgba(0,0,0,.1)}}



/* ===== MOBILE MORE NAV + RESPONSIVE EXPENSES UI ===== */
.dropdown-more{position:relative}
.more-dropdown{position:absolute;bottom:100%;right:0;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(15,23,42,.18);min-width:220px;display:none;margin-bottom:10px;z-index:1200;overflow:hidden;border:1px solid #e5e7eb}
.more-dropdown.show{display:block}
.more-dropdown .dropdown-item{display:flex;align-items:center;gap:12px;width:100%;padding:12px 16px;color:#1f2937;text-decoration:none;border:0;border-bottom:1px solid #f0f0f0;background:#fff;font-size:.86rem;text-align:left}
.more-dropdown .dropdown-item:last-child{border-bottom:none}
.more-dropdown .dropdown-item:hover,.more-dropdown .dropdown-item.active{background:#ecfdf5;color:#047857}
.more-dropdown .dropdown-item i{font-size:1.05rem;margin:0;color:inherit}
.logout-mobile-item{cursor:pointer}

@media(max-width:992px){
    body{padding-bottom:76px;overflow-x:hidden}
    .main-content{padding:12px!important}
    .navbar-top{background:#fff;border-radius:16px;padding:12px 14px;box-shadow:0 1px 3px rgba(0,0,0,.06);gap:.75rem;align-items:flex-start}
    .navbar-top .page-title{min-width:0;flex:1}
    .navbar-top .page-title h2{font-size:1.25rem;margin:0;color:#052A47;font-weight:800;line-height:1.2}
    .navbar-top .page-title p{font-size:.78rem;margin:.15rem 0 0;color:#64748b;line-height:1.25}
    .sticky-filter-wrap{top:0;z-index:50}
    .filter-header{padding:.85rem 1rem}
    .filter-header h5{font-size:.95rem;line-height:1.2}
    .filter-content{padding:.9rem 1rem}
    .filter-content .btn{width:100%;justify-content:center}
    .d-flex.justify-content-end.gap-2.mb-3.no-print{justify-content:stretch!important;display:grid!important;grid-template-columns:1fr;gap:.6rem!important}
    .d-flex.justify-content-end.gap-2.mb-3.no-print .btn{width:100%;justify-content:center}
    .section-card,.form-card{border-radius:16px;margin-bottom:.9rem}
    .section-header{padding:.9rem 1rem;display:block!important}
    .section-header h5{font-size:1rem;margin-bottom:.15rem}
    .section-body{padding:.85rem 1rem}
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .table{min-width:780px!important;font-size:.86rem}
    .table th,.table td{white-space:nowrap}
    .mobile-nav{display:block;padding:7px 8px calc(7px + env(safe-area-inset-bottom));}
    .mobile-nav .nav{gap:2px}
    .mobile-nav .nav-link{padding:6px 2px;min-height:50px;justify-content:center}
    .mobile-nav .nav-link i{font-size:1.2rem;margin-bottom:2px}
    .mobile-nav .nav-link span{font-size:.62rem;line-height:1.1}
    .more-dropdown{right:2px;min-width:215px}
}

@media(max-width:420px){
    .main-content{padding:10px!important}
    .mobile-nav .nav-link span{font-size:.58rem}
    .more-dropdown{right:0;min-width:205px}
}
/* Additional CSS to support the new stat card structure */

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
.stat-card {
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


</style>
</head>
<body>
<div id="appPage">
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>
            <button class="desktop-toggle-btn" id="desktopToggleBtn">
                <i class="bi bi-list" id="toggleIcon"></i>
            </button>
            <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
            <span class="nav-text">Rolling Account</span>
        </h3>
    </div>
    
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <!-- Inventory -->
                <li class="nav-item">
                    <a class="nav-link" href="current_inventory.php">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-text">Current Inventory</span>
                    </a>
                </li>

                <!-- Orders -->
                <li class="nav-item">
                    <a class="nav-link" href="customer_orderproduct.php">
                        <i class="bi bi-person-plus"></i>
                        <span class="nav-text">Orders</span>
                    </a>
                </li>

                <!-- Collections -->
                <li class="nav-item">
                    <a class="nav-link" href="collections.php">
                        <i class="bi bi-cash-stack"></i>
                        <span class="nav-text">Collections</span>
                    </a>
                </li>

                <!-- Sales Order -->
                <li class="nav-item">
                    <a class="nav-link" href="sales_order.php">
                        <i class="bi bi-cart"></i>
                        <span class="nav-text">Sales Orders</span>
                    </a>
                </li>

                <!-- Purchase Order -->
                <li class="nav-item">
                    <a class="nav-link" href="purchase_order.php">
                        <i class="bi bi-truck"></i>
                        <span class="nav-text">Recieve Inventory</span>
                    </a>
                </li>

                <!-- Expenses -->
                <li class="nav-item">
                    <a class="nav-link active" href="expenses.php">
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
            <div class="user-avatar-sidebar"><?php echo h($user_initials); ?></div>
            <div class="user-details-sidebar">
                <span class="user-name-sidebar"><?php echo h($user_name); ?></span>
                <span class="user-role-sidebar"><?php echo h(ucfirst($user_role)); ?></span>
            </div>
        </div>
        <button class="logout-btn-sidebar" onclick="logout()">
            <i class="bi bi-box-arrow-right"></i>
            <span class="logout-text">Logout</span>
        </button>
    </div>
</div>

<div class="main-content" id="mainContent">
    <div class="navbar-top no-print"><button class="mobile-toggle-btn mobile-menu-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button><div class="page-title"><h2><?php echo h($page_title); ?></h2><p><?php echo h($page_subtitle); ?></p></div></div>

    <div class="row stat-card-row g-1 g-sm-2 mb-4 no-print">
        <div class="col"><div class="stat-card"><i class="bi bi-cash-stack stat-icon"></i><div class="stat-content"><div class="stat-value"><?php echo money($total_expenses); ?></div><div class="stat-label">Total Expenses</div><small>All recorded expenses</small></div></div></div>
        <div class="col"><div class="stat-card"><i class="bi bi-folder2 stat-icon"></i><div class="stat-content"><div class="stat-value"><?php echo number_format(count($allExpenseTree)); ?></div><div class="stat-label">Expense Accounts</div><small>Grouped accounts</small></div></div></div>
        <div class="col"><div class="stat-card"><i class="bi bi-list-check stat-icon"></i><div class="stat-content"><div class="stat-value"><?php echo number_format($total_transactions); ?></div><div class="stat-label">Transactions</div><small>Posted records</small></div></div></div>
        <div class="col"><div class="stat-card"><i class="bi bi-graph-up-arrow stat-icon"></i><div class="stat-content"><div class="stat-value" style="font-size:.95rem;word-break:break-word"><?php echo h($largest_account); ?></div><div class="stat-label">Largest Account</div><small><?php echo money($largest_account_total); ?></small></div></div></div>
    </div>

    <div class="sticky-filter-wrap no-print">
        <div class="form-card mb-4">
            <div class="filter-header">
                <h5><i class="bi bi-funnel"></i> Filter Expenses <span id="activeFilterBadge" class="filter-active-badge">Active</span></h5>
                <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false"><i class="bi bi-chevron-down" id="filterIcon"></i></button>
            </div>
            <div class="filter-content collapsed" id="filterContent">
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-lg-3"><label class="form-label"><i class="bi bi-calendar-start"></i> From Date</label><input type="date" id="filter_from_date" class="form-control" value="<?php echo h($from_date); ?>"></div>
                    <div class="col-12 col-md-6 col-lg-3"><label class="form-label"><i class="bi bi-calendar-end"></i> To Date</label><input type="date" id="filter_to_date" class="form-control" value="<?php echo h($to_date); ?>"></div>
                    <div class="col-12 col-md-6 col-lg-6"><label class="form-label"><i class="bi bi-search"></i> Search</label><input type="text" id="filter_search" class="form-control" placeholder="Search account, paid to, description, reference..." value="<?php echo h($search_term); ?>"></div>
                </div>
                <div class="d-flex gap-2 mt-3 flex-wrap"><button type="button" id="applyFilterBtn" class="btn btn-amgc-primary"><i class="bi bi-funnel"></i> Apply Filter</button><button type="button" id="resetFilterBtn" class="btn btn-amgc-light"><i class="bi bi-x-circle"></i> Clear</button></div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-3 no-print flex-wrap">
        <button type="button" class="btn btn-amgc-dark" data-bs-toggle="modal" data-bs-target="#addExpenseAccountModal"><i class="bi bi-plus-circle"></i> Add Expense Account</button>
        <button type="button" class="btn btn-amgc-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal"><i class="bi bi-receipt"></i> Add Expense</button>
    </div>

    <div id="tableContainer"><?php echo renderExpensesTableHtml($expenseTree); ?></div>
</div>
</div>

<!-- Mobile Bottom Navigation -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <li class="nav-item"><a class="nav-link" href="current_inventory.php"><i class="bi bi-box-seam"></i><span>Inventory</span></a></li>
        <li class="nav-item"><a class="nav-link" href="customer_orderproduct.php"><i class="bi bi-person-plus"></i><span>Orders</span></a></li>
        <li class="nav-item"><a class="nav-link" href="collections.php"><i class="bi bi-cash-stack"></i><span>Collections</span></a></li>
        <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span>Sales</span></a></li>
        <li class="nav-item dropdown-more" id="mobileMoreNav">
            <a class="nav-link active more-btn" href="#" onclick="toggleDropdown(event,'mobileMoreMenu')"><i class="bi bi-three-dots"></i><span>More</span></a>
            <div class="more-dropdown" id="mobileMoreMenu">
                <a href="purchase_order.php" class="dropdown-item"><i class="bi bi-truck"></i><span>Receive Inventory</span></a>
                <a href="expenses.php" class="dropdown-item active"><i class="bi bi-receipt-cutoff"></i><span>Expenses</span></a>
                <a href="reports.php" class="dropdown-item"><i class="bi bi-file-earmark-bar-graph"></i><span>Reports</span></a>
                <button type="button" class="dropdown-item logout-mobile-item" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </div>
        </li>
    </ul>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content" style="border-radius:20px"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i><span id="expenseModalTitle">Expense Details</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="expenseModalBody"><div class="text-center py-5"><div class="spinner-border text-success"></div> Loading expenses...</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-amgc-dark" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Close</button></div></div></div></div>

<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content" style="border-radius:20px"><form id="addExpenseForm" enctype="multipart/form-data"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-receipt-cutoff me-2"></i>Add Rolling Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">Expense Date <span class="text-danger">*</span></label><input type="date" name="expense_date" class="form-control" value="<?php echo h(date('Y-m-d')); ?>" required></div><div class="col-md-6"><label class="form-label">Payment Method</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="check">Check</option><option value="online_transfer">Online Transfer</option><option value="other">Other</option></select></div><div class="col-md-6"><label class="form-label">Expense Account <span class="text-danger">*</span></label><input list="expenseAccountList" name="expense_account" class="form-control" placeholder="Example: Vehicle Repair" required><datalist id="expenseAccountList"><?php foreach ($accountOptions as $option): ?><option value="<?php echo h($option); ?>"></option><?php endforeach; ?></datalist></div><div class="col-md-6"><label class="form-label">Paid To <span class="text-danger">*</span></label><input type="text" name="payee" class="form-control" placeholder="Example: Vulcanizing Shop" required></div><div class="col-md-6"><label class="form-label">Amount <span class="text-danger">*</span></label><input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div><div class="col-md-6"><label class="form-label">Reference No.</label><input type="text" name="reference_number" class="form-control" placeholder="OR / receipt / reference"></div><div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3" placeholder="Example: Flat tire repair and vulcanizing during route"></textarea></div><div class="col-12"><label class="form-label">Attachment / Receipt</label><input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf"><small class="text-muted">Optional. JPG, PNG, WEBP, GIF, or PDF up to 8MB.</small></div></div></div><div class="modal-footer"><button type="button" class="btn btn-amgc-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-amgc-primary"><i class="bi bi-save"></i> Save Expense</button></div></form></div></div></div>

<div class="modal fade" id="addExpenseAccountModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:20px"><form id="addExpenseAccountForm"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>Add Expense Account</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">Expense Account <span class="text-danger">*</span></label><input type="text" name="bank_name" id="bank_name" class="form-control" required placeholder="Example: Vehicle Repair"></div><div class="mb-3 form-check"><input type="checkbox" name="is_sub_account" id="is_sub_account" class="form-check-input" value="1"><label class="form-check-label" for="is_sub_account">Sub Account</label></div><div class="mb-3" id="parent_bank_group" style="display:none"><label class="form-label">Parent Expense Account <span class="text-danger">*</span></label><input type="text" name="parent_bank_name" id="parent_bank_name" class="form-control" placeholder="Example: Vehicle Repair"></div><div class="mb-3"><label class="form-label">Description <span class="text-danger">*</span></label><textarea name="description" id="description" class="form-control" rows="3" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-amgc-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-amgc-primary">Save Account</button></div></form></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){
    const s=document.getElementById('sidebar');
    if(!s)return;
    if(window.innerWidth<=992){
        s.classList.toggle('active');
        let overlay=document.querySelector('.sidebar-overlay');
        if(s.classList.contains('active')){
            if(!overlay){overlay=document.createElement('div');overlay.className='sidebar-overlay';document.body.appendChild(overlay);overlay.addEventListener('click',()=>{s.classList.remove('active');overlay.classList.remove('active');setTimeout(()=>overlay.remove(),300);});}
            setTimeout(()=>overlay.classList.add('active'),10);
        }else if(overlay){overlay.classList.remove('active');setTimeout(()=>overlay.remove(),300);}
    }else{
        s.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed',s.classList.contains('collapsed'));
    }
}
function toggleSidebarDropdown(e,id){e.preventDefault();const t=document.getElementById(id);if(t)t.classList.toggle('show')}
function toggleDropdown(e,id){e.preventDefault();e.stopPropagation();document.querySelectorAll('.more-dropdown').forEach(d=>{if(d.id!==id)d.classList.remove('show')});const el=document.getElementById(id);if(el)el.classList.toggle('show')}
function logout(){Swal.fire({title:'Are you sure?',text:'You will be logged out of the system',icon:'question',showCancelButton:true,confirmButtonColor:'#07d826',cancelButtonColor:'#6c757d',confirmButtonText:'Yes, logout'}).then((r)=>{if(r.isConfirmed)window.location.href='../logout.php';})}
let expenseModal;
function updateFilterBadge(){const active=!!(document.getElementById('filter_from_date').value||document.getElementById('filter_to_date').value||document.getElementById('filter_search').value.trim());document.getElementById('activeFilterBadge')?.classList.toggle('show',active)}
async function loadFilteredData(){const fromDate=document.getElementById('filter_from_date').value;const toDate=document.getElementById('filter_to_date').value;const search=document.getElementById('filter_search').value;const tableContainer=document.getElementById('tableContainer');tableContainer.innerHTML='<div class="section-card"><div class="section-body text-center py-5"><div class="spinner-border text-success"></div><p class="mt-2 text-muted">Loading expenses...</p></div></div>';try{const url=`${window.location.pathname}?ajax=get_filtered_data&from_date=${encodeURIComponent(fromDate)}&to_date=${encodeURIComponent(toDate)}&search=${encodeURIComponent(search)}`;const response=await fetch(url,{cache:'no-store'});const data=await response.json();if(!data.success)throw new Error(data.message||'Failed to load expenses');tableContainer.innerHTML=data.table;attachExpenseRowClickListeners();const params=new URLSearchParams();if(fromDate)params.set('from_date',fromDate);if(toDate)params.set('to_date',toDate);if(search)params.set('search',search);window.history.pushState({},'',window.location.pathname+(params.toString()?'?'+params.toString():''));updateFilterBadge()}catch(error){tableContainer.innerHTML='<div class="section-card"><div class="section-body"><div class="alert alert-danger">Failed to load expenses. Please try again.</div></div></div>';}}
function resetFiltersAJAX(){document.getElementById('filter_from_date').value='';document.getElementById('filter_to_date').value='';document.getElementById('filter_search').value='';loadFilteredData()}
function attachExpenseRowClickListeners(){document.querySelectorAll('.clickable-expense-row').forEach(row=>{row.onclick=function(){openExpenseModal(this.getAttribute('data-expense-account'),this.getAttribute('data-payee')||'')}})}
async function openExpenseModal(expenseAccount,payee){document.getElementById('expenseModalTitle').innerText=payee?`${expenseAccount} / ${payee}`:expenseAccount;document.getElementById('expenseModalBody').innerHTML='<div class="text-center py-5"><div class="spinner-border text-success"></div> Loading expenses...</div>';expenseModal.show();try{const params=new URLSearchParams(window.location.search);let url=`${window.location.pathname}?ajax=get_expense_transactions&expense_account=${encodeURIComponent(expenseAccount)}&payee=${encodeURIComponent(payee||'')}`;['from_date','to_date','search'].forEach(k=>{if(params.get(k))url+=`&${k}=${encodeURIComponent(params.get(k))}`});const response=await fetch(url,{cache:'no-store'});document.getElementById('expenseModalBody').innerHTML=await response.text()}catch(e){document.getElementById('expenseModalBody').innerHTML='<div class="alert alert-danger">Failed to load expense transactions.</div>'}}
document.addEventListener('DOMContentLoaded',function(){expenseModal=new bootstrap.Modal(document.getElementById('expenseModal'));if(localStorage.getItem('sidebarCollapsed')==='true'&&window.innerWidth>992)document.getElementById('sidebar')?.classList.add('collapsed');document.getElementById('mobileMenuBtn')?.addEventListener('click',toggleSidebar);document.getElementById('mobileToggleBtn')?.addEventListener('click',toggleSidebar);document.getElementById('desktopToggleBtn')?.addEventListener('click',toggleSidebar);const toggleBtn=document.getElementById('filterToggleBtn');const filterContent=document.getElementById('filterContent');const filterIcon=document.getElementById('filterIcon');toggleBtn?.addEventListener('click',function(){const collapsed=filterContent.classList.contains('collapsed');filterContent.classList.toggle('collapsed',!collapsed);this.setAttribute('aria-expanded',collapsed?'true':'false');filterIcon.classList.toggle('bi-chevron-down',!collapsed);filterIcon.classList.toggle('bi-chevron-up',collapsed)});document.getElementById('applyFilterBtn')?.addEventListener('click',loadFilteredData);document.getElementById('resetFilterBtn')?.addEventListener('click',resetFiltersAJAX);document.getElementById('filter_search')?.addEventListener('keypress',function(e){if(e.key==='Enter'){e.preventDefault();loadFilteredData()}});['filter_from_date','filter_to_date','filter_search'].forEach(id=>document.getElementById(id)?.addEventListener('input',updateFilterBadge));updateFilterBadge();const chk=document.getElementById('is_sub_account');const parentGroup=document.getElementById('parent_bank_group');const parentInput=document.getElementById('parent_bank_name');chk?.addEventListener('change',function(){parentGroup.style.display=this.checked?'block':'none';parentInput.required=this.checked;if(!this.checked)parentInput.value=''})
const expenseForm=document.getElementById('addExpenseForm');expenseForm?.addEventListener('submit',function(e){e.preventDefault();const fd=new FormData(this);fd.append('action','add_expense');fetch(window.location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{if(data.success){Swal.fire('Success',data.message,'success').then(()=>window.location.reload())}else{Swal.fire('Error',data.message||'Failed to save expense','error')}}).catch(()=>Swal.fire('Error','Network error. Please try again.','error'))});
const accountForm=document.getElementById('addExpenseAccountForm');accountForm?.addEventListener('submit',function(e){e.preventDefault();const fd=new FormData(this);fd.append('action','add_expense_account');fetch(window.location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{if(data.success){Swal.fire('Success',data.message,'success').then(()=>window.location.reload())}else{Swal.fire('Error',data.message||'Failed to save account','error')}}).catch(()=>Swal.fire('Error','Network error. Please try again.','error'))});document.addEventListener('click',function(e){if(!e.target.closest('.dropdown-more'))document.querySelectorAll('.more-dropdown').forEach(d=>d.classList.remove('show'));});attachExpenseRowClickListeners()});
</script>
</body>
</html>
