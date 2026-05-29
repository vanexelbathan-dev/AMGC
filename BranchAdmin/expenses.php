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

function formatAmount($value) {
    $value = (float)$value;
    return $value == floor($value) ? '₱' . number_format($value, 0) : '₱' . number_format($value, 2);
}

function getUserInitials($user_name) {
    $parts = preg_split('/\s+/', trim($user_name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials !== '' ? substr($initials, 0, 2) : 'BA';
}

function ensureBankTransactionExpenseColumns($conn) {
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
}

function ensureExpenseAccountsTable($conn) {
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

function getExpenseRows($conn, $view_all_branches, $branch_id, $from_date = null, $to_date = null, $search = '') {
    ensureBankTransactionExpenseColumns($conn);

    $sql = "SELECT bt.transaction_id, bt.branch_id, bt.transaction_date, bt.reference_number,
                   bt.bank_name, bt.bank_id, bt.description, bt.expense_account, bt.payee,
                   bt.amount, bt.created_at,
                   CONCAT(COALESCE(u.first_name, ''), CASE WHEN u.last_name IS NOT NULL AND u.last_name <> '' THEN CONCAT(' ', u.last_name) ELSE '' END) AS created_by_name
            FROM bank_transactions bt
            LEFT JOIN users u ON u.user_id = bt.created_by
            WHERE bt.transaction_type = 'withdrawal'
              AND TRIM(COALESCE(bt.expense_account, '')) <> ''";

    if (!$view_all_branches && $branch_id > 0) $sql .= " AND bt.branch_id = ?";
    
    // Apply date filters
    if (!empty($from_date)) {
        $sql .= " AND DATE(bt.transaction_date) >= ?";
    }
    if (!empty($to_date)) {
        $sql .= " AND DATE(bt.transaction_date) <= ?";
    }
    // Apply search filter
    if (!empty($search)) {
        $search_param = '%' . $search . '%';
        $sql .= " AND (bt.expense_account LIKE ? OR bt.payee LIKE ? OR bt.description LIKE ?)";
    }
    
    $sql .= " ORDER BY bt.expense_account ASC, bt.payee ASC, bt.transaction_date DESC, bt.transaction_id DESC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $params = [];
        $types = '';
        if (!$view_all_branches && $branch_id > 0) {
            $params[] = $branch_id;
            $types .= 'i';
        }
        if (!empty($from_date)) {
            $params[] = $from_date;
            $types .= 's';
        }
        if (!empty($to_date)) {
            $params[] = $to_date;
            $types .= 's';
        }
        if (!empty($search)) {
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= 'sss';
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return $rows;
}

function getExpenseTree($rows) {
    $tree = [];
    foreach ($rows as $row) {
        $account = trim($row['expense_account'] ?? '') ?: 'Uncategorized Expense';
        $payee = trim($row['payee'] ?? '') ?: 'No Payee';
        if (!isset($tree[$account])) {
            $tree[$account] = [
                'account' => $account,
                'total' => 0.0,
                'count' => 0,
                'payees' => []
            ];
        }
        if (!isset($tree[$account]['payees'][$payee])) {
            $tree[$account]['payees'][$payee] = [
                'payee' => $payee,
                'total' => 0.0,
                'count' => 0,
                'latest_date' => null
            ];
        }
        $amount = (float)$row['amount'];
        $tree[$account]['total'] += $amount;
        $tree[$account]['count']++;
        $tree[$account]['payees'][$payee]['total'] += $amount;
        $tree[$account]['payees'][$payee]['count']++;
        $latest = $tree[$account]['payees'][$payee]['latest_date'];
        if ($latest === null || strtotime($row['transaction_date']) > strtotime($latest)) {
            $tree[$account]['payees'][$payee]['latest_date'] = $row['transaction_date'];
        }
    }
    ksort($tree, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($tree as &$account) {
        uasort($account['payees'], function($a, $b) {
            return $b['total'] <=> $a['total'];
        });
    }
    unset($account);
    return $tree;
}

function getSavedExpenseAccounts($conn, $view_all_branches, $branch_id, $search = '') {
    ensureExpenseAccountsTable($conn);

    $sql = "SELECT id, bank_name, is_sub_account, parent_bank_name, description, branch_id, created_at
            FROM expense_accounts
            WHERE 1=1";

    if (!$view_all_branches && $branch_id > 0) {
        $sql .= " AND branch_id = ?";
    }

    if ($search !== '') {
        $sql .= " AND (bank_name LIKE ? OR parent_bank_name LIKE ? OR description LIKE ?)";
    }

    $sql .= " ORDER BY COALESCE(NULLIF(parent_bank_name, ''), bank_name) ASC, is_sub_account ASC, bank_name ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $params = [];
        $types = '';

        if (!$view_all_branches && $branch_id > 0) {
            $params[] = $branch_id;
            $types .= 'i';
        }

        if ($search !== '') {
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'sss';
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    return $rows;
}

function mergeSavedExpenseAccountsIntoTree($tree, $savedAccounts) {
    foreach ($savedAccounts as $saved) {
        $name = trim($saved['bank_name'] ?? '');
        if ($name === '') continue;

        $isSub = (int)($saved['is_sub_account'] ?? 0) === 1;
        $parent = trim($saved['parent_bank_name'] ?? '');

        if ($isSub) {
            $accountName = $parent !== '' ? $parent : 'Uncategorized Expense';
            $payeeName = $name;
        } else {
            $accountName = $name;
            $payeeName = '';
        }

        if (!isset($tree[$accountName])) {
            $tree[$accountName] = [
                'account' => $accountName,
                'total' => 0.0,
                'count' => 0,
                'payees' => []
            ];
        }

        if ($payeeName !== '' && !isset($tree[$accountName]['payees'][$payeeName])) {
            $tree[$accountName]['payees'][$payeeName] = [
                'payee' => $payeeName,
                'total' => 0.0,
                'count' => 0,
                'latest_date' => null
            ];
        }
    }

    ksort($tree, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($tree as &$account) {
        uasort($account['payees'], function($a, $b) {
            if ((float)$a['total'] === (float)$b['total']) {
                return strcasecmp($a['payee'], $b['payee']);
            }
            return $b['total'] <=> $a['total'];
        });
    }
    unset($account);

    return $tree;
}

function renderExpensesTableHtml($expenseTree) {
    ob_start();
    ?>
    <div class="section-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle expense-group-table" id="expensesTable">
                <thead>
                    <tr><th>Expense Account / Payee</th><th class="text-center">Transactions</th><th>Latest Date</th><th class="text-end">Amount</th><th class="text-end">Total</th></tr>
                </thead>
                <tbody>
                <?php if (!empty($expenseTree)): ?>
                    <?php foreach ($expenseTree as $account): ?>
                        <tr class="group-header expense-account-row clickable-expense-row" data-expense-account="<?php echo h($account['account']); ?>" data-payee="" data-search="<?php echo h(strtolower($account['account'])); ?>">
                            <td><div class="expense-folder-name"><strong><?php echo h($account['account']); ?></strong></div></td>
                            <td class="text-center"><?php echo number_format($account['count']); ?></td>
                            <td class="text-muted">All payees</td><td class="text-end text-muted">-</td>
                            <td class="text-end amount-neutral fw-bold"><?php echo formatAmount($account['total']); ?></td>
                        </tr>
                        <?php foreach ($account['payees'] as $payee): ?>
                            <tr class="child-row payee-row clickable-expense-row" data-expense-account="<?php echo h($account['account']); ?>" data-payee="<?php echo h($payee['payee']); ?>" data-search="<?php echo h(strtolower($account['account'] . ' ' . $payee['payee'])); ?>">
                                <td><span class="expense-name-link"><?php echo h($payee['payee']); ?></span></td>
                                <td class="text-center"><?php echo number_format($payee['count']); ?></td>
                                <td><?php echo !empty($payee['latest_date']) ? h(date('M d, Y', strtotime($payee['latest_date']))) : '-'; ?></td>
                                <td class="text-end amount-negative"><?php echo ((float)$payee['total'] > 0) ? formatAmount($payee['total']) : '-'; ?></td>
                                <td class="text-end text-muted">-</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-5">No expenses found for the selected filters. Try adjusting date range or search.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
function renderExpenseTransactionsHtml($conn, $view_all_branches, $branch_id, $expense_account, $payee, $from_date = null, $to_date = null, $search = '') {
    // Fetch filtered rows again to ensure consistency with global filters
    $rows = getExpenseRows($conn, $view_all_branches, $branch_id, $from_date, $to_date, $search);
    $filtered = [];
    foreach ($rows as $row) {
        $rowAccount = trim($row['expense_account'] ?? '') ?: 'Uncategorized Expense';
        $rowPayee = trim($row['payee'] ?? '') ?: 'No Payee';
        if ($rowAccount === $expense_account && ($payee === '' || $rowPayee === $payee)) {
            $filtered[] = $row;
        }
    }

    $total = 0.0;
    foreach ($filtered as $row) $total += (float)$row['amount'];

    echo '<div class="mb-4 p-3 border rounded" style="background:#e8f5e9">';
    echo '<h6 class="mb-2">Expense Details</h6>';
    echo '<div class="row g-2">';
    echo '<div class="col-md-6 col-lg-4"><strong>Expense Account:</strong> ' . h($expense_account) . '</div>';
    echo '<div class="col-md-6 col-lg-4"><strong>Payee:</strong> ' . h($payee !== '' ? $payee : 'All Payees') . '</div>';
    echo '<div class="col-md-6 col-lg-4"><strong>Total Amount:</strong> ' . formatAmount($total) . '</div>';
    echo '<div class="col-md-6 col-lg-4"><strong>Transaction Count:</strong> ' . count($filtered) . '</div>';
    echo '</div></div>';

    if (empty($filtered)) {
        echo '<div class="text-center py-5 text-muted">No expenses found for this selection.</div>';
        return;
    }

    echo '<div class="table-responsive"><table class="table table-hover align-middle">';
    echo '<thead><tr><th>Date</th><th>Reference</th><th>Paid To</th><th>Bank</th><th>Description</th><th class="text-end">Amount</th><th>Encoded By</th></tr></thead><tbody>';
    foreach ($filtered as $tx) {
        echo '<tr>';
        echo '<td>' . h(date('M d, Y', strtotime($tx['transaction_date']))) . '</td>';
        echo '<td>' . h($tx['reference_number'] ?: '-') . '</td>';
        echo '<td>' . h($tx['payee'] ?: '-') . '</td>';
        echo '<td>' . h($tx['bank_name'] ?: '-') . '</td>';
        echo '<td>' . h($tx['description'] ?: '-') . '</td>';
        echo '<td class="text-end amount-negative">' . formatAmount($tx['amount']) . '</td>';
        echo '<td>' . h(trim($tx['created_by_name']) !== '' ? $tx['created_by_name'] : 'Unknown') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

ensureBankTransactionExpenseColumns($conn);
ensureExpenseAccountsTable($conn);

// Handle AJAX request for filtered table data
if (isset($_GET["ajax"]) && $_GET["ajax"] === "get_filtered_data") {
    header("Content-Type: application/json; charset=utf-8");
    try {
        $ajax_from_date = isset($_GET["from_date"]) && $_GET["from_date"] !== "" ? $_GET["from_date"] : null;
        $ajax_to_date = isset($_GET["to_date"]) && $_GET["to_date"] !== "" ? $_GET["to_date"] : null;
        $ajax_search = isset($_GET["search"]) ? trim($_GET["search"]) : "";
        $ajaxExpenseRows = getExpenseRows($conn, $view_all_branches, $branch_id, $ajax_from_date, $ajax_to_date, $ajax_search);
        $ajaxSavedExpenseAccounts = getSavedExpenseAccounts($conn, $view_all_branches, $branch_id, $ajax_search);
        $ajaxExpenseTree = mergeSavedExpenseAccountsIntoTree(getExpenseTree($ajaxExpenseRows), $ajaxSavedExpenseAccounts);
        echo json_encode(["success" => true, "table" => renderExpensesTableHtml($ajaxExpenseTree)]);
    } catch (Throwable $e) {
        echo json_encode(["success" => false, "message" => "Failed to load expenses: " . $e->getMessage(), "table" => "<div class=\"section-body\"><div class=\"text-center text-muted py-4\">Failed to load expenses.</div></div>"]);
    }
    exit();
}

// Get filter parameters from URL for TABLE only
$from_date = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : null;
$to_date = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : null;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// For TABLE: get filtered rows (may filter)
$expenseRows = getExpenseRows($conn, $view_all_branches, $branch_id, $from_date, $to_date, $search_term);

// For STATS: get ALL rows (walang filter)
$allExpenseRows = getExpenseRows($conn, $view_all_branches, $branch_id, null, null, '');
$allSavedExpenseAccounts = getSavedExpenseAccounts($conn, $view_all_branches, $branch_id, '');
$allExpenseTree = mergeSavedExpenseAccountsIntoTree(getExpenseTree($allExpenseRows), $allSavedExpenseAccounts);

// Handle AJAX request for expense transactions
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_expense_transactions') {
    $expense_account = trim($_GET['expense_account'] ?? '');
    $payee = trim($_GET['payee'] ?? '');
    if ($expense_account === '') {
        echo '<div class="text-center text-muted py-4">Invalid expense account.</div>';
        exit();
    }
    // Pass current filters to the render function
    renderExpenseTransactionsHtml($conn, $view_all_branches, $branch_id, $expense_account, $payee, $from_date, $to_date, $search_term);
    exit();
}

// Handle Add Expense Account POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense_account') {
    header('Content-Type: application/json');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $is_sub_account = isset($_POST['is_sub_account']) ? 1 : 0;
    $parent_bank_name = ($is_sub_account == 1) ? trim($_POST['parent_bank_name'] ?? '') : null;
    $description = trim($_POST['description'] ?? '');
    
    $errors = [];
    if (empty($bank_name)) $errors[] = 'Expense Account is required.';
    if (empty($description)) $errors[] = 'Description is required.';
    if ($is_sub_account && empty($parent_bank_name)) $errors[] = 'Parent Expense Account is required for sub-account.';
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit();
    }
    
    $stmt = $conn->prepare("INSERT INTO expense_accounts (bank_name, is_sub_account, parent_bank_name, description, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('sissii', $bank_name, $is_sub_account, $parent_bank_name, $description, $branch_id, $user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Expense account added successfully.']);
        } else {
            echo json_encode(['success' => false, 'errors' => ['Database error: ' . $stmt->error]]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'errors' => ['Failed to prepare statement.']]);
    }
    exit();
}

$savedExpenseAccounts = getSavedExpenseAccounts($conn, $view_all_branches, $branch_id, $search_term);
$expenseTree = mergeSavedExpenseAccountsIntoTree(getExpenseTree($expenseRows), $savedExpenseAccounts);
$total_expenses = 0.0;
$total_transactions = count($expenseRows);
$largest_account = '-';
$largest_account_total = 0.0;
$latest_expense_date = null;

// For STATS - gamitin ang ALL rows (walang filter)
$total_expenses_all = 0.0;
$total_transactions_all = count($allExpenseRows);
$largest_account_all = '-';
$largest_account_total_all = 0.0;

foreach ($allExpenseRows as $row) {
    $total_expenses_all += (float)$row['amount'];
}
foreach ($allExpenseTree as $account) {
    if ($account['total'] > $largest_account_total_all) {
        $largest_account_total_all = $account['total'];
        $largest_account_all = $account['account'];
    }
}

// For TABLE - gamitin ang filtered rows
foreach ($expenseRows as $row) {
    $total_expenses += (float)$row['amount'];
    if ($latest_expense_date === null || strtotime($row['transaction_date']) > strtotime($latest_expense_date)) {
        $latest_expense_date = $row['transaction_date'];
    }
}
foreach ($expenseTree as $account) {
    if ($account['total'] > $largest_account_total) {
        $largest_account_total = $account['total'];
        $largest_account = $account['account'];
    }
}

// I-override ang stats variables para sa display (gamitin ang ALL data)
$total_expenses = $total_expenses_all;
$total_transactions = $total_transactions_all;
$largest_account = $largest_account_all;
$largest_account_total = $largest_account_total_all;

$user_initials = getUserInitials($user_name);

$expenseAccountOptions = [];
$parentExpenseAccountOptions = [];
foreach ($allExpenseRows as $row) {
    $accountName = trim($row['expense_account'] ?? '');
    if ($accountName !== '') {
        $expenseAccountOptions[$accountName] = $accountName;
        $parentExpenseAccountOptions[$accountName] = $accountName;
    }
}
foreach ($allSavedExpenseAccounts as $saved) {
    $accountName = trim($saved['bank_name'] ?? '');
    $parentName = trim($saved['parent_bank_name'] ?? '');
    $isSubAccount = (int)($saved['is_sub_account'] ?? 0) === 1;

    if ($accountName !== '') {
        $expenseAccountOptions[$accountName] = $accountName;
        if (!$isSubAccount) {
            $parentExpenseAccountOptions[$accountName] = $accountName;
        }
    }
    if ($parentName !== '') {
        $parentExpenseAccountOptions[$parentName] = $parentName;
    }
}
natcasesort($expenseAccountOptions);
natcasesort($parentExpenseAccountOptions);
$page_title = 'Expenses';
$page_subtitle = 'Expense accounts and paid-to summaries from withdrawals';
$active_page = 'expenses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
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
.section-card{background:#fff;border-radius:18px;border:1px solid rgba(68,211,78,.12);box-shadow:0 8px 20px rgba(15,23,42,.05);margin-bottom:1rem;overflow:hidden}.section-header{padding:1rem 1.25rem;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}.section-body{padding:1rem 1.25rem}
.table thead th{background-color:#f8f9fa;color:#052A47;border-bottom:2px solid #dee2e6;white-space:nowrap;font-weight:600}.table tbody td{vertical-align:middle;font-size:.92rem}.table-hover tbody tr:hover{background-color:#f5f5f5}
.amount-positive{color:#047857;font-weight:700}.amount-negative{color:#dc2626;font-weight:700}.amount-neutral{color:#052A47;font-weight:700}
.form-control,.form-select{border-radius:10px;min-height:44px}
.navbar-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}.mobile-menu-btn{display:none;background:transparent;border:none;font-size:1.5rem;color:#052A47}.page-note{font-size:.78rem;color:#6b7280}.modal-xl{max-width:1140px}
.expense-group-table{margin-bottom:1.5rem}.expense-group-table .group-header{background:#f8f9fa;font-weight:700;border-bottom:2px solid #dee2e6}.expense-group-table .group-header td{padding:12px 8px}.expense-folder-name{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}.clickable-expense-row{cursor:pointer}.clickable-expense-row:hover td{background-color:#f8fff9!important}.expense-name-link{color:#047857;font-weight:800;text-decoration:none;cursor:pointer}.expense-name-link:hover{text-decoration:underline}.child-row td:first-child{padding-left:2rem!important; background:#fff}.payee-row td:first-child{padding-left:2rem!important}.soft-badge{background:#f3f4f6;padding:.2rem .55rem;border-radius:20px;font-size:.72rem;color:#4b5563}.filter-wrap{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.filter-wrap .form-control,.filter-wrap .form-select{width:auto;min-width:140px}.search-input{min-width:200px}
@media(max-width:992px){.sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}.main-content{margin-left:0!important}.mobile-menu-btn{display:block}body{padding-bottom:70px}}@media(max-width:768px){.table-responsive{overflow-x:auto}.section-header{display:block}.stat-value{font-size:1.2rem}.filter-wrap .form-control,.filter-wrap .form-select{width:100%;margin-bottom:5px}}
/* Statistics Cards - gaya ng customer.php */
.stat-card-row {
    margin-bottom: 1.5rem;
}

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

/* Hover effect for stat cards */
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
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
        font-size: 1rem !important;
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }
    
    .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.65rem !important;
        font-weight: 500 !important;
        width: 100% !important;
        word-break: break-word !important;
        white-space: normal !important;
        line-height: 1.3 !important;
    }
    
    /* Hide the small text on mobile to save space */
    .stat-card small {
        display: none !important;
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
        font-size: 0.9rem !important;
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
        font-size: 0.75rem !important;
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


/* Base button styling - UNIFORM SHAPE */
.btn-amgc-primary,
.btn-amgc-success,
.btn-amgc-danger,
.btn-amgc-warning,
.btn-amgc-info,
.btn-amgc-light,
.btn-amgc-dark,
.btn-outline-amgc-primary,
.btn-outline-amgc-dark,
.btn-outline-amgc-light {
    padding: 0.5rem 1rem;
    border-radius: 999px !important;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 10px rgba(0,0,0,.15);
}

.btn-amgc-primary i,
.btn-amgc-success i,
.btn-amgc-danger i,
.btn-amgc-warning i,
.btn-amgc-info i,
.btn-amgc-light i,
.btn-amgc-dark i,
.btn-outline-amgc-primary i,
.btn-outline-amgc-dark i,
.btn-outline-amgc-light i {
    font-size: 1.1rem;
}

/* Primary (Using #44D34E) */
.btn-amgc-primary {
    background: linear-gradient(135deg, #047857, #44D34E);
    color: white;
}

.btn-amgc-primary:hover {
    background: linear-gradient(135deg, #065f46, #34c73e);
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(68, 211, 78, 0.4);
    color: white;
}

.btn-amgc-primary:active {
    transform: translateY(0);
}

/* Success (Using #44D34E) */
.btn-amgc-success {
    background: linear-gradient(135deg, #44D34E, #34c73e);
    color: white;
}

.btn-amgc-success:hover {
    background: linear-gradient(135deg, #34c73e, #2bb835);
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(68, 211, 78, 0.4);
    color: white;
}

.btn-amgc-success:active {
    transform: translateY(0);
}

/* Danger (Red) */
.btn-amgc-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.btn-amgc-danger:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(239, 68, 68, 0.4);
    color: white;
}

.btn-amgc-danger:active {
    transform: translateY(0);
}

/* Warning (Orange) */
.btn-amgc-warning {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.btn-amgc-warning:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(245, 158, 11, 0.4);
    color: white;
}

.btn-amgc-warning:active {
    transform: translateY(0);
}

/* Info (Using #44D34E light variant) */
.btn-amgc-info {
    background: linear-gradient(135deg, #44D34E, #d1fae5);
    color: #047857;
}

.btn-amgc-info:hover {
    background: linear-gradient(135deg, #34c73e, #bef5d4);
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(68, 211, 78, 0.3);
    color: #047857;
}

.btn-amgc-info:active {
    transform: translateY(0);
}

/* Light (Using #d1fae5) */
.btn-amgc-light {
    background: linear-gradient(135deg, #d1fae5, #bef5d4);
    color: #047857;
    border: 1px solid #44D34E;
}

.btn-amgc-light:hover {
    background: linear-gradient(135deg, #bef5d4, #a8f0c2);
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(68, 211, 78, 0.2);
    color: #047857;
}

.btn-amgc-light:active {
    transform: translateY(0);
}

/* Dark (Using #047857) */
.btn-amgc-dark {
    background: linear-gradient(135deg, #047857, #065f46);
    color: white;
}

.btn-amgc-dark:hover {
    background: linear-gradient(135deg, #065f46, #044e3a);
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(4, 120, 87, 0.4);
    color: white;
}

.btn-amgc-dark:active {
    transform: translateY(0);
}

/* Outline variants - SAME SHAPE */
.btn-outline-amgc-primary {
    background: transparent;
    border: 2px solid #44D34E;
    color: #047857;
}

.btn-outline-amgc-primary:hover {
    background: linear-gradient(135deg, #44D34E, #34c73e);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(68, 211, 78, 0.3);
    border-color: transparent;
}

.btn-outline-amgc-primary:active {
    transform: translateY(0);
}

/* Outline Dark variant using #047857 */
.btn-outline-amgc-dark {
    background: transparent;
    border: 2px solid #047857;
    color: #047857;
}

.btn-outline-amgc-dark:hover {
    background: linear-gradient(135deg, #047857, #065f46);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(4, 120, 87, 0.3);
    border-color: transparent;
}

.btn-outline-amgc-dark:active {
    transform: translateY(0);
}

/* Outline Light variant using #d1fae5 */
.btn-outline-amgc-light {
    background: transparent;
    border: 2px solid #44D34E;
    color: #047857;
}

.btn-outline-amgc-light:hover {
    background: linear-gradient(135deg, #d1fae5, #bef5d4);
    color: #047857;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(68, 211, 78, 0.2);
}

.btn-outline-amgc-light:active {
    transform: translateY(0);
}

/* Disabled state - SAME SHAPE */
.btn-amgc-primary:disabled,
.btn-amgc-success:disabled,
.btn-amgc-danger:disabled,
.btn-amgc-warning:disabled,
.btn-amgc-info:disabled,
.btn-amgc-light:disabled,
.btn-amgc-dark:disabled,
.btn-outline-amgc-primary:disabled,
.btn-outline-amgc-dark:disabled,
.btn-outline-amgc-light:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Small button variant - SAME SHAPE */
.btn-sm {
    padding: 0.3rem 0.8rem;
    font-size: 0.8125rem;
    border-radius: 999px !important;
}

.btn-sm i {
    font-size: 0.9rem;
}

/* Large button variant - SAME SHAPE */
.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    border-radius: 999px !important;
}

.btn-lg i {
    font-size: 1.2rem;
}

/* Full width button - SAME SHAPE */
.btn-block {
    width: 100%;
    justify-content: center;
    border-radius: 999px !important;
}
/* Add Expense Account Modal Styles - same style as other modals */
#addExpenseAccountModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 500px !important;
}

@media (max-width: 768px) {
    #addExpenseAccountModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #addExpenseAccountModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#addExpenseAccountModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#addExpenseAccountModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

@media (max-width: 768px) {
    #addExpenseAccountModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#addExpenseAccountModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#addExpenseAccountModal .modal-header .modal-title i {
    color: white !important;
}

@media (max-width: 576px) {
    #addExpenseAccountModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button */
#addExpenseAccountModal .modal-header .btn-close {
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
    #addExpenseAccountModal .modal-header .btn-close {
        width: 30px !important;
        height: 30px !important;
    }
}

#addExpenseAccountModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
    font-family: system-ui, -apple-system, sans-serif !important;
}

@media (max-width: 576px) {
    #addExpenseAccountModal .modal-header .btn-close::before {
        font-size: 0.9rem !important;
    }
}

#addExpenseAccountModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#addExpenseAccountModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    background: #f8fafc !important;
    max-height: calc(90vh - 130px) !important;
}

@media (max-width: 576px) {
    #addExpenseAccountModal .modal-body {
        padding: 1rem !important;
        max-height: calc(90vh - 110px) !important;
    }
}

/* Form Styles */
#addExpenseAccountModal .form-label {
    font-weight: 600 !important;
    font-size: 0.8rem !important;
    margin-bottom: 0.5rem !important;
    color: #1f2937 !important;
}

#addExpenseAccountModal .form-control,
#addExpenseAccountModal .form-select {
    border-radius: 10px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 0.6rem 0.75rem !important;
    font-size: 0.85rem !important;
    transition: all 0.2s ease !important;
}

#addExpenseAccountModal .form-control:focus,
#addExpenseAccountModal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1) !important;
    outline: none !important;
}

#addExpenseAccountModal .form-control:disabled,
#addExpenseAccountModal .form-control[readonly] {
    background-color: #f1f5f9 !important;
    cursor: not-allowed !important;
}

#addExpenseAccountModal .form-text {
    font-size: 0.7rem !important;
    color: #6c757d !important;
    margin-top: 0.25rem !important;
}

/* Textarea */
#addExpenseAccountModal textarea.form-control {
    resize: vertical !important;
}

/* Checkbox */
#addExpenseAccountModal .form-check {
    padding-left: 1.8rem !important;
}

#addExpenseAccountModal .form-check-input {
    width: 1.1rem !important;
    height: 1.1rem !important;
    margin-left: -1.8rem !important;
    margin-top: 0.15rem !important;
    cursor: pointer !important;
}

#addExpenseAccountModal .form-check-input:checked {
    background-color: #047857 !important;
    border-color: #047857 !important;
}

#addExpenseAccountModal .form-check-label {
    font-size: 0.85rem !important;
    color: #1f2937 !important;
    cursor: pointer !important;
}

/* Parent Bank Group transition */
#parent_bank_group {
    transition: all 0.3s ease !important;
}

/* Modal Footer */
#addExpenseAccountModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    position: relative !important;
    z-index: 1 !important;
}

@media (max-width: 576px) {
    #addExpenseAccountModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

#addExpenseAccountModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #addExpenseAccountModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
}

#addExpenseAccountModal .modal-footer .btn-amgc-light {
    background: transparent !important;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
}

#addExpenseAccountModal .modal-footer .btn-amgc-light:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    transform: translateY(-1px) !important;
}

#addExpenseAccountModal .modal-footer .btn-amgc-primary {
    background: linear-gradient(135deg, #059669, #047857) !important;
    border: none !important;
    color: white !important;
}

#addExpenseAccountModal .modal-footer .btn-amgc-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
}

/* Scrollbar */
#addExpenseAccountModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#addExpenseAccountModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#addExpenseAccountModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #addExpenseAccountModal .modal-content {
        max-height: 95vh !important;
    }
    
    #addExpenseAccountModal .modal-body {
        padding: 0.75rem !important;
        max-height: calc(95vh - 100px) !important;
    }
    
    #addExpenseAccountModal .mb-3 {
        margin-bottom: 0.5rem !important;
    }
}
/* Expense Modal Styles - same style as other modals */
#expenseModal .modal-dialog {
    margin: 1rem auto !important;
    max-width: 900px !important;
}

@media (max-width: 768px) {
    #expenseModal .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
        width: calc(100% - 1.5rem) !important;
    }
}

@media (max-width: 576px) {
    #expenseModal .modal-dialog {
        margin: 0.5rem auto !important;
        max-width: calc(100% - 1rem) !important;
        width: calc(100% - 1rem) !important;
    }
}

#expenseModal .modal-content {
    border: none !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#expenseModal .modal-header {
    background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.25rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

@media (max-width: 768px) {
    #expenseModal .modal-header {
        padding: 0.875rem 1rem !important;
    }
}

#expenseModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#expenseModal .modal-header .modal-title i {
    color: white !important;
}

@media (max-width: 576px) {
    #expenseModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
}

/* Close button */
#expenseModal .modal-header .btn-close {
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
    #expenseModal .modal-header .btn-close {
        width: 30px !important;
        height: 30px !important;
    }
}

#expenseModal .modal-header .btn-close::before {
    font-size: 1rem !important;
    font-weight: bold !important;
    color: white !important;
    font-family: system-ui, -apple-system, sans-serif !important;
}

@media (max-width: 576px) {
    #expenseModal .modal-header .btn-close::before {
        font-size: 0.9rem !important;
    }
}

#expenseModal .modal-header .btn-close:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    transform: rotate(90deg) !important;
}

/* Modal Body */
#expenseModal .modal-body {
    padding: 1.25rem !important;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    background: #f8fafc !important;
    max-height: calc(90vh - 130px) !important;
}

@media (max-width: 576px) {
    #expenseModal .modal-body {
        padding: 1rem !important;
        max-height: calc(90vh - 110px) !important;
    }
}

/* Loading spinner */
#expenseModal .modal-body .spinner-border {
    color: #047857 !important;
}

/* Modal Footer */
#expenseModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 0.875rem 1.25rem !important;
    background: #ffffff !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    position: relative !important;
    z-index: 1 !important;
}

@media (max-width: 576px) {
    #expenseModal .modal-footer {
        padding: 0.75rem 1rem !important;
        gap: 0.5rem !important;
    }
}

#expenseModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
}

@media (max-width: 576px) {
    #expenseModal .modal-footer .btn {
        flex: 1 !important;
        padding: 0.45rem 0.5rem !important;
        font-size: 0.75rem !important;
    }
}

#expenseModal .modal-footer .btn-outline-amgc-dark {
    background: transparent !important;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
}

#expenseModal .modal-footer .btn-outline-amgc-dark:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    transform: translateY(-1px) !important;
}

/* Scrollbar */
#expenseModal .modal-body::-webkit-scrollbar {
    width: 5px;
}

#expenseModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#expenseModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

/* Content styles inside modal body - for tables and cards */
#expenseModal .modal-body .card,
#expenseModal .modal-body .expense-details-card {
    background: white !important;
    border: 1px solid #e9ecef !important;
    border-radius: 16px !important;
    margin-bottom: 1rem !important;
    overflow: hidden !important;
}

#expenseModal .modal-body .table-responsive {
    border-radius: 16px !important;
    border: 1px solid #e9ecef !important;
    background: white !important;
}

#expenseModal .modal-body .table {
    margin-bottom: 0 !important;
    font-size: 0.85rem !important;
}

#expenseModal .modal-body .table thead th {
    background: #f8fafc !important;
    color: #1f2937 !important;
    font-weight: 600 !important;
    padding: 0.875rem 1rem !important;
    border-bottom: 2px solid #e9ecef !important;
}

#expenseModal .modal-body .table tbody td {
    padding: 0.875rem 1rem !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #e9ecef !important;
    color: #475569 !important;
}

#expenseModal .modal-body .table tbody tr:hover {
    background: #f8fafc !important;
}

/* Amount styling */
#expenseModal .modal-body .amount-positive {
    color: #dc2626 !important;
    font-weight: 600 !important;
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    #expenseModal .modal-content {
        max-height: 95vh !important;
    }
    
    #expenseModal .modal-body {
        padding: 0.75rem !important;
        max-height: calc(95vh - 100px) !important;
    }
    
    #expenseModal .modal-body .table td,
    #expenseModal .modal-body .table th {
        padding: 0.5rem !important;
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


/* Editable dropdown fields for Add Expense Account */
.editable-dropdown-wrap {
    position: relative;
}

.editable-dropdown-wrap::after {
    content: "\F282";
    font-family: "bootstrap-icons";
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #047857;
    font-size: 0.9rem;
    pointer-events: none;
}

.editable-dropdown-wrap .form-control {
    padding-right: 2.5rem !important;
    background: #ffffff !important;
    color: #052A47 !important;
    border: 1px solid #d1fae5 !important;
}

.editable-dropdown-wrap .form-control:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.16) !important;
}

.editable-dropdown-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    z-index: 1065;
    display: none;
    max-height: 190px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid rgba(68, 211, 78, 0.35);
    border-radius: 12px;
    box-shadow: 0 14px 30px rgba(5, 42, 71, 0.14);
    padding: 0.35rem;
}

.editable-dropdown-menu.show {
    display: block;
}

.editable-dropdown-item {
    width: 100%;
    border: 0;
    background: #ffffff;
    color: #052A47;
    text-align: left;
    padding: 0.55rem 0.7rem;
    border-radius: 9px;
    font-size: 0.84rem;
    line-height: 1.25;
    cursor: pointer;
}

.editable-dropdown-item:hover,
.editable-dropdown-item.active {
    background: #d1fae5;
    color: #047857;
}

.editable-dropdown-empty {
    padding: 0.6rem 0.7rem;
    color: #64748b;
    font-size: 0.8rem;
}

.editable-dropdown-menu::-webkit-scrollbar {
    width: 5px;
}

.editable-dropdown-menu::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 8px;
}

.editable-dropdown-menu::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 8px;
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
    <li><a class="nav-link" href="current_inventory.php"><i class="bi bi-bar-chart-line"></i><span class="nav-text">Current Inventory</span></a></li><li><a class="nav-link" href="bad_orders.php"><i class="bi bi-recycle"></i><span class="nav-text">Bad Orders</span></a></li><li><a class="nav-link" href="pick_list_items.php"><i class="bi bi-list-check"></i><span class="nav-text">Pick List Items</span></a></li>
                                    <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
    </ul></div></li>
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
                                        <i class="bi bi-arrow-down-circle"></i>
                                        <span class="nav-text">Deposit</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="Withdrawal.php">
                                        <i class="bi bi-arrow-up-circle"></i>
                                        <span class="nav-text">Withdrawal</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="bank_statement.php">
                                        <i class="bi bi-receipt"></i>
                                        <span class="nav-text">Bank Statement</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link active" href="expenses.php">
                                        <i class="bi bi-cash-stack"></i>
                                        <span class="nav-text">Expenses</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
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
<div class="sidebar-footer"><div class="user-profile-sidebar"><div class="user-avatar-sidebar"><?php echo h($user_initials); ?></div><div class="user-details-sidebar"><span class="user-name-sidebar"><?php echo h($user_name); ?></span><span class="user-role-sidebar"><?php echo h(ucfirst($user_role)); ?></span></div></div><button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button></div>
</div>

<div class="main-content" id="mainContent"><div id="dashboardContent" class="page-content active">
<div class="navbar-top no-print"><button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button><div class="page-title"><h2><?php echo h($page_title); ?></h2><p><?php echo h($page_subtitle); ?></p></div></div>

<!-- Statistics Cards - HINDI NAGBABAGO (Total ng lahat ng expenses) -->
<div id="statsContainer" class="row stat-card-row g-1 g-sm-2 mb-4 no-print">
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-cash-stack stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value" id="statTotalExpenses"><?php echo formatAmount($total_expenses); ?></div>
                <div class="stat-label">Total Expenses</div>
                <small class="d-block">From withdrawal records</small>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-folder2 stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value" id="statExpenseAccounts"><?php echo number_format(count($allExpenseTree)); ?></div>
                <div class="stat-label">Expense Accounts</div>
                <small class="d-block">Folder total groups</small>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-list-check stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value" id="statTransactions"><?php echo number_format($total_transactions_all); ?></div>
                <div class="stat-label">Transactions</div>
                <small class="d-block">Posted withdrawals</small>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card complete largest-account-card">
            <i class="bi bi-graph-up-arrow stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value largest-amount" id="statLargestAmount"><?php echo formatAmount($largest_account_total); ?></div>
                <div class="stat-label">Largest Account</div>
                <small class="largest-account-name" id="statLargestAccount"><?php echo h($largest_account); ?></small>
            </div>
        </div>
    </div>
</div>

<!-- FILTER SECTION -->
<div class="form-card mb-4" id="filterCard">
    <div class="filter-header" id="filterHeader" style="cursor: pointer;">
        <h5>
            <i class="bi bi-funnel"></i> Filter Expenses
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">
                    <i class="bi bi-calendar-start"></i> From Date
                </label>
                <input type="date" id="filter_from_date" class="form-control" value="<?php echo h($from_date); ?>">
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">
                    <i class="bi bi-calendar-end"></i> To Date
                </label>
                <input type="date" id="filter_to_date" class="form-control" value="<?php echo h($to_date); ?>">
            </div>
            <div class="col-12 col-md-6 col-lg-6">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <div class="search-box">
                    <input type="text" id="filter_search" class="form-control" placeholder="Search account or payee..." value="<?php echo h($search_term); ?>">
                </div>
            </div>
        </div>
        <div class="row g-3 mt-2">
            <div class="col-12">
                <div class="d-flex gap-2">
                    <button type="button" id="applyFilterBtn" class="btn btn-amgc-primary">
                        <i class="bi bi-funnel"></i> Apply Filter
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Expense Account Button -->
<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-amgc-dark" data-bs-toggle="modal" data-bs-target="#addExpenseAccountModal">
        <i class="bi bi-plus-circle"></i> &nbsp;Add Expense Account
    </button>
</div>

<!-- Table Container - ITO LANG ANG NAGBABAGO -->
<div id="tableContainer">
    <?php echo renderExpensesTableHtml($expenseTree); ?>
</div>

</div></div></div>

<!-- Modal for Expense Details -->
<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="border-radius:20px">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i><span id="expenseModalTitle">Expense Details</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="expenseModalBody">
        <div class="text-center py-5"><div class="spinner-border text-success"></div> Loading expenses...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-amgc-dark" data-bs-dismiss="modal">
          <i class="bi bi-x-lg"></i> Close
        </button>
      </div>
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


<!-- Modal for Add Expense Account -->
<div class="modal fade" id="addExpenseAccountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:20px">
      <form id="addExpenseAccountForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-bank2 me-2"></i> Add Expense Account</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Expense Account <span class="text-danger">*</span></label>
            <input type="text" name="bank_name" id="bank_name" class="form-control" autocomplete="off" placeholder="Enter expense account" required>
          </div>
          <div class="mb-3 form-check">
            <input type="checkbox" name="is_sub_account" id="is_sub_account" class="form-check-input" value="1">
            <label class="form-check-label" for="is_sub_account">Sub Account</label>
          </div>
          <div class="mb-3" id="parent_bank_group" style="display: none;">
            <label class="form-label">Parent Expense Account <span class="text-danger">*</span></label>
            <div class="editable-dropdown-wrap">
              <input type="text" name="parent_bank_name" id="parent_bank_name" class="form-control" autocomplete="off" placeholder="Select or type new parent expense account">
              <div class="editable-dropdown-menu" id="parentExpenseAccountDropdown"></div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description <span class="text-danger">*</span></label>
            <textarea name="description" id="description" class="form-control" rows="3" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-amgc-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-amgc-primary">Save Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
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

const parentExpenseAccountOptions = <?php echo json_encode(array_values($parentExpenseAccountOptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<script>

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

function logout(){amgcSwalFire({title:'Are you sure?',text:'You will be logged out of the system',icon:'question',showCancelButton:true,confirmButtonText:'Yes, logout'}).then((r)=>{if(r.isConfirmed)window.location.href='../logout.php';})}

let expenseModal;

// AJAX Filter Functions - TABLE ONLY
async function loadFilteredData() {
    const fromDate = document.getElementById('filter_from_date').value;
    const toDate = document.getElementById('filter_to_date').value;
    const search = document.getElementById('filter_search').value;
    
    const tableContainer = document.getElementById('tableContainer');
    if (tableContainer) {
        tableContainer.innerHTML = `
            <div class="section-body">
                <div class="text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading expenses...</p>
                </div>
            </div>
        `;
    }
    
    try {
        const url = `expenses.php?ajax=get_filtered_data&from_date=${encodeURIComponent(fromDate)}&to_date=${encodeURIComponent(toDate)}&search=${encodeURIComponent(search)}`;
        const response = await fetch(url, { cache: 'no-store' });
        const data = await response.json();
        
        if (data.table && tableContainer) {
            tableContainer.innerHTML = data.table;
        }
        
        attachExpenseRowClickListeners();
        
        const urlParams = new URLSearchParams();
        if (fromDate) urlParams.set('from_date', fromDate);
        if (toDate) urlParams.set('to_date', toDate);
        if (search) urlParams.set('search', search);
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.pushState({}, '', newUrl);
        
    } catch (error) {
        console.error('Error loading filtered data:', error);
        amgcSwalFire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to load expenses. Check browser console or server message.'
        });
        if (tableContainer) {
            tableContainer.innerHTML = `
                <div class="section-body">
                    <div class="text-center text-muted py-4">Failed to load expenses.</div>
                </div>
            `;
        }
    }
}

function resetFiltersAJAX() {
    document.getElementById('filter_from_date').value = '';
    document.getElementById('filter_to_date').value = '';
    document.getElementById('filter_search').value = '';
    loadFilteredData();
}

function attachExpenseRowClickListeners() {
    document.querySelectorAll('.clickable-expense-row').forEach(row => {
        row.removeEventListener('click', expenseRowClickHandler);
        row.addEventListener('click', expenseRowClickHandler);
    });
}

function expenseRowClickHandler() {
    openExpenseModal(this.getAttribute('data-expense-account'), this.getAttribute('data-payee') || '');
}

async function openExpenseModal(expenseAccount, payee) {
    const modalTitle = document.getElementById('expenseModalTitle');
    const modalBody = document.getElementById('expenseModalBody');
    
    if (modalTitle) modalTitle.innerText = payee ? `${expenseAccount} / ${payee}` : expenseAccount;
    if (modalBody) modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-success"></div> Loading expenses...</div>';
    
    if (expenseModal) expenseModal.show();
    
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const fromDate = urlParams.get('from_date') || '';
        const toDate = urlParams.get('to_date') || '';
        const search = urlParams.get('search') || '';
        let url = `expenses.php?ajax=get_expense_transactions&expense_account=${encodeURIComponent(expenseAccount)}&payee=${encodeURIComponent(payee || '')}`;
        if (fromDate) url += `&from_date=${encodeURIComponent(fromDate)}`;
        if (toDate) url += `&to_date=${encodeURIComponent(toDate)}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;
        const response = await fetch(url, {cache:'no-store'});
        if (modalBody) modalBody.innerHTML = await response.text();
    } catch (err) {
        amgcSwalFire({ icon: 'error', title: 'Error', text: 'Failed to load expense transactions.' });
        if (modalBody) modalBody.innerHTML = '<div class="text-center text-muted py-4">Failed to load expense transactions.</div>';
    }
}


function initEditableDropdown(inputId, dropdownId, options) {
    const input = document.getElementById(inputId);
    const dropdown = document.getElementById(dropdownId);
    if (!input || !dropdown) return;

    const normalizedOptions = Array.from(new Set((options || [])
        .map(item => String(item || '').trim())
        .filter(item => item !== '')))
        .sort((a, b) => a.localeCompare(b));

    function hideDropdown() {
        dropdown.classList.remove('show');
    }

    function renderDropdown() {
        const keyword = input.value.trim().toLowerCase();
        const matches = normalizedOptions
            .filter(item => item.toLowerCase().includes(keyword))
            .slice(0, 30);

        dropdown.innerHTML = '';

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'editable-dropdown-empty';
            empty.textContent = keyword ? 'No match. You can type a new account.' : 'No saved accounts yet.';
            dropdown.appendChild(empty);
        } else {
            matches.forEach(item => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'editable-dropdown-item';
                button.textContent = item;
                button.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    input.value = item;
                    hideDropdown();
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
                dropdown.appendChild(button);
            });
        }

        dropdown.classList.add('show');
    }

    input.addEventListener('focus', renderDropdown);
    input.addEventListener('input', renderDropdown);
    input.addEventListener('click', renderDropdown);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') hideDropdown();
    });
    document.addEventListener('mousedown', function(e) {
        if (!input.closest('.editable-dropdown-wrap').contains(e.target)) {
            hideDropdown();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initEditableDropdown('parent_bank_name', 'parentExpenseAccountDropdown', parentExpenseAccountOptions);
    expenseModal = new bootstrap.Modal(document.getElementById('expenseModal'));
    
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

    const toggleBtn = document.getElementById('filterToggleBtn');
    const filterContent = document.getElementById('filterContent');
    const filterIcon = document.getElementById('filterIcon');
    
    if (toggleBtn && filterContent) {
        const newToggleBtn = toggleBtn.cloneNode(true);
        toggleBtn.parentNode.replaceChild(newToggleBtn, toggleBtn);
        
        newToggleBtn.addEventListener('click', function() {
            const isExpanded = filterContent.classList.contains('collapsed');
            if (isExpanded) {
                filterContent.classList.remove('collapsed');
                filterContent.classList.add('expanded');
                newToggleBtn.setAttribute('aria-expanded', 'true');
                if (filterIcon) {
                    filterIcon.classList.remove('bi-chevron-down');
                    filterIcon.classList.add('bi-chevron-up');
                }
            } else {
                filterContent.classList.add('collapsed');
                filterContent.classList.remove('expanded');
                newToggleBtn.setAttribute('aria-expanded', 'false');
                if (filterIcon) {
                    filterIcon.classList.remove('bi-chevron-up');
                    filterIcon.classList.add('bi-chevron-down');
                }
            }
        });
    }
    
    const applyBtn = document.getElementById('applyFilterBtn');
    if (applyBtn) {
        applyBtn.addEventListener('click', loadFilteredData);
    }
    
    const resetBtn = document.getElementById('resetFilterBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', resetFiltersAJAX);
    }
    
    const searchInput = document.getElementById('filter_search');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadFilteredData();
            }
        });
    }

    const chkSubAccount = document.getElementById('is_sub_account');
    const parentGroup = document.getElementById('parent_bank_group');
    const parentBankInput = document.getElementById('parent_bank_name');
    if (chkSubAccount) {
        chkSubAccount.addEventListener('change', function() {
            if (this.checked) {
                if (parentGroup) parentGroup.style.display = 'block';
                if (parentBankInput) parentBankInput.required = true;
            } else {
                if (parentGroup) parentGroup.style.display = 'none';
                if (parentBankInput) {
                    parentBankInput.required = false;
                    parentBankInput.value = '';
                }
            }
        });
    }

    const addForm = document.getElementById('addExpenseAccountForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'add_expense_account');
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    amgcSwalFire({ icon: 'success', title: 'Success', text: data.message }).then(() => {
                        window.location.reload();
                    });
                } else {
                    let errorMsg = data.errors ? data.errors.join('<br>') : 'Something went wrong.';
                    amgcSwalFire({ icon: 'error', title: 'Error', html: errorMsg });
                }
            })
            .catch(error => {
                amgcSwalFire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
            });
        });
    }

    attachExpenseRowClickListeners();
});
// ===== FILTER TOGGLE - ENTIRE HEADER CLICKABLE =====
document.addEventListener('DOMContentLoaded', function() {
    const filterHeader = document.getElementById('filterHeader');
    const filterContent = document.getElementById('filterContent');
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterIcon = document.getElementById('filterIcon');
    
    if (filterHeader && filterContent) {
        // Set initial state - collapsed
        filterContent.classList.add('collapsed');
        if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        // Make the entire header clickable
        filterHeader.addEventListener('click', function(e) {
            // Don't toggle if clicking on the button itself (to avoid double toggle)
            if (e.target.closest('.filter-toggle-btn')) {
                e.stopPropagation();
            }
            toggleFilterContent();
        });
        
        // Also keep the button click as a fallback
        if (filterToggleBtn) {
            // Remove old listener by cloning and replacing
            const newToggleBtn = filterToggleBtn.cloneNode(true);
            filterToggleBtn.parentNode.replaceChild(newToggleBtn, filterToggleBtn);
            
            newToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleFilterContent();
            });
        }
    }
    
    function toggleFilterContent() {
        const isExpanded = !filterContent.classList.contains('collapsed');
        
        if (isExpanded) {
            // Collapse
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-up');
                filterIcon.classList.add('bi-chevron-down');
            }
        } else {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
        }
    }
    
    // Apply filter button
    const applyBtn = document.getElementById('applyFilterBtn');
    if (applyBtn) {
        const newApplyBtn = applyBtn.cloneNode(true);
        applyBtn.parentNode.replaceChild(newApplyBtn, applyBtn);
        newApplyBtn.addEventListener('click', loadFilteredData);
    }
    
    // Search input enter key
    const searchInput = document.getElementById('filter_search');
    if (searchInput) {
        const newSearchInput = searchInput.cloneNode(true);
        searchInput.parentNode.replaceChild(newSearchInput, searchInput);
        newSearchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadFilteredData();
            }
        });
    }
});
</script>
</body>
</html>