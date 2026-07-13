<?php
// AMGC_JOURNAL_CREATE_TAB_AUTOFILL_EDIT_PATCH_V23
require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role_raw = strtolower(trim((string)($_SESSION['role'] ?? '')));

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit();
}

if ($user_role_raw !== 'motorpool') {
    if ($user_role_raw === 'branch_admin') {
        header('Location: ../Branch_Admin/branchdashboard.php');
    } elseif ($user_role_raw === 'admin') {
        header('Location: ../Admin/dashboard.php');
    } else {
        header('Location: ../login.php');
    }
    exit();
}

$user_name = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
if ($user_name === '') {
    $user_name = 'Motorpool Account';
}
$user_role = 'motorpool';
$view_all_branches = false;
$_SESSION['view_all_branches'] = false;

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mpJournalTableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function mpJournalEnsureMotorpoolBranch(mysqli $conn): array {
    $branchName = 'Motorpool';
    if (mpJournalTableExists($conn, 'branches')) {
        $sql = "SELECT branch_id, branch_name
                FROM branches
                WHERE LOWER(TRIM(branch_name)) = 'motorpool'
                   OR LOWER(TRIM(branch_name)) LIKE '%motorpool%'
                ORDER BY CASE WHEN LOWER(TRIM(branch_name)) = 'motorpool' THEN 0 ELSE 1 END, branch_id ASC
                LIMIT 1";
        $res = $conn->query($sql);
        if ($res && ($row = $res->fetch_assoc())) {
            return [(int)$row['branch_id'], trim((string)$row['branch_name']) ?: $branchName];
        }

        $stmt = $conn->prepare("INSERT INTO branches (branch_name) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param('s', $branchName);
            if (@$stmt->execute()) {
                $newId = (int)$conn->insert_id;
                $stmt->close();
                return [$newId, $branchName];
            }
            $stmt->close();
        }
    }

    $sessionBranch = (int)($_SESSION['branch_id'] ?? 0);
    return [$sessionBranch > 0 ? $sessionBranch : 0, $branchName];
}

[$branch_id, $branch_name] = mpJournalEnsureMotorpoolBranch($conn);
$_SESSION['branch_id'] = $branch_id;
$_SESSION['branch_name'] = $branch_name;

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
if ($user_initials === '') {
    $user_initials = 'BA';
}

$journal_success_message = $_SESSION['journal_success_message'] ?? '';
$journal_success_redirect = $_SESSION['journal_success_redirect'] ?? '';
$journal_error_message = '';
unset($_SESSION['journal_success_message'], $_SESSION['journal_success_redirect']);


// ========== FETCH CHART OF ACCOUNTS FOR ACCOUNT TITLE DROPDOWN ==========
$chart_accounts_list = [];
$check_chart_accounts_table = $conn->query("SHOW TABLES LIKE 'chart_of_accounts'");
if ($check_chart_accounts_table && $check_chart_accounts_table->num_rows > 0) {
    $chart_query = "SELECT account_id, account_code, account_title, account_type, parent_account_id
                    FROM chart_of_accounts
                    WHERE status = 'active'";

    if (!$view_all_branches && $branch_id > 0) {
        $check_chart_branch = $conn->query("SHOW COLUMNS FROM chart_of_accounts LIKE 'branch_id'");
        if ($check_chart_branch && $check_chart_branch->num_rows > 0) {
            $chart_query .= " AND branch_id = " . (int)$branch_id;
        }
    }

    $chart_query .= " ORDER BY account_code ASC, account_title ASC";
    $chart_result = $conn->query($chart_query);
    $chart_accounts_list = $chart_result ? $chart_result->fetch_all(MYSQLI_ASSOC) : [];
}

// ========== AUTO-GENERATE ENTRY NO. ==========
$generated_entry_no = 'JE-' . date('Ymd') . '-0001';
$journal_prefix = 'JE-' . date('Ymd') . '-';

$next_journal_no = 1;
$journal_no_sources = [
    ['table' => 'journal_entries', 'column' => 'entry_no'],
    ['table' => 'journal_entries', 'column' => 'journal_no'],
    ['table' => 'journal_entry_headers', 'column' => 'entry_no'],
    ['table' => 'chart_account_transactions', 'column' => 'transaction_no']
];

foreach ($journal_no_sources as $source) {
    $table = $conn->real_escape_string($source['table']);
    $column = $conn->real_escape_string($source['column']);

    $check_table = $conn->query("SHOW TABLES LIKE '{$table}'");
    if (!$check_table || $check_table->num_rows === 0) {
        continue;
    }

    $check_column = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if (!$check_column || $check_column->num_rows === 0) {
        continue;
    }

    $result = $conn->query("SELECT `{$column}` AS last_no FROM `{$table}` WHERE `{$column}` LIKE '" . $conn->real_escape_string($journal_prefix) . "%' ORDER BY `{$column}` DESC LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $last_no = trim((string)($row['last_no'] ?? ''));
        $last_part = (int)substr($last_no, strlen($journal_prefix));
        if ($last_part >= $next_journal_no) {
            $next_journal_no = $last_part + 1;
        }
    }
}

$generated_entry_no = $journal_prefix . str_pad((string)$next_journal_no, 4, '0', STR_PAD_LEFT);


// ========== FETCH COUNTERPARTY DROPDOWN OPTIONS ==========
$counterparty_options = [];

$check_suppliers_table = $conn->query("SHOW TABLES LIKE 'suppliers'");
if ($check_suppliers_table && $check_suppliers_table->num_rows > 0) {
    $supplier_query = "SELECT supplier_id, supplier_code, supplier_name FROM suppliers WHERE status = 'active'";
    if (!$view_all_branches && $branch_id > 0) {
        $check_supplier_branch = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
        if ($check_supplier_branch && $check_supplier_branch->num_rows > 0) {
            $supplier_query .= " AND branch_id = " . (int)$branch_id;
        }
    }
    $supplier_query .= " ORDER BY supplier_name ASC";
    $supplier_result = $conn->query($supplier_query);
    if ($supplier_result) {
        while ($supplier = $supplier_result->fetch_assoc()) {
            $name = trim((string)($supplier['supplier_name'] ?? ''));
            if ($name === '') continue;
            $counterparty_options[] = [
                'value' => $name,
                'label' => $name,
                'type' => 'Vendor'
            ];
        }
    }
}

$check_customers_table = $conn->query("SHOW TABLES LIKE 'customers'");
if ($check_customers_table && $check_customers_table->num_rows > 0) {
    $customer_query = "SELECT customer_id, customer_code, customer_name, store_name FROM customers WHERE status = 'active'";
    if (!$view_all_branches && $branch_id > 0) {
        $check_customer_branch = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
        if ($check_customer_branch && $check_customer_branch->num_rows > 0) {
            $customer_query .= " AND branch_id = " . (int)$branch_id;
        }
    }
    $customer_query .= " ORDER BY customer_name ASC";
    $customer_result = $conn->query($customer_query);
    if ($customer_result) {
        while ($customer = $customer_result->fetch_assoc()) {
            $name = trim((string)($customer['customer_name'] ?? ''));
            if ($name === '') continue;
            $store = trim((string)($customer['store_name'] ?? ''));
            $label_name = $store !== '' ? $name . ' - ' . $store : $name;
            $counterparty_options[] = [
                'value' => $name,
                'label' => $label_name,
                'type' => 'Customer'
            ];
        }
    }
}

$check_employees_table = $conn->query("SHOW TABLES LIKE 'employees'");
if ($check_employees_table && $check_employees_table->num_rows > 0) {
    $employee_query = "SELECT employee_id, employee_name FROM employees WHERE status = 'active'";
    if (!$view_all_branches && $branch_id > 0) {
        $check_employee_branch = $conn->query("SHOW COLUMNS FROM employees LIKE 'branch_id'");
        if ($check_employee_branch && $check_employee_branch->num_rows > 0) {
            $employee_query .= " AND branch_id = " . (int)$branch_id;
        }
    }
    $employee_query .= " ORDER BY employee_name ASC";
    $employee_result = $conn->query($employee_query);
    if ($employee_result) {
        while ($employee = $employee_result->fetch_assoc()) {
            $name = trim((string)($employee['employee_name'] ?? ''));
            if ($name === '') continue;
            $counterparty_options[] = [
                'value' => $name,
                'label' => $name,
                'type' => 'Employee'
            ];
        }
    }
}

usort($counterparty_options, function($a, $b) {
    $type_compare = strcasecmp($a['type'] ?? '', $b['type'] ?? '');
    if ($type_compare !== 0) return $type_compare;
    return strcasecmp($a['label'] ?? '', $b['label'] ?? '');
});


// ========== FETCH JOURNAL ENTRIES FROM DATABASE ==========
function journalTableExists(mysqli $conn, string $tableName): bool {
    $safe = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function journalColumnExists(mysqli $conn, string $tableName, string $columnName): bool {
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    $column = $conn->real_escape_string($columnName);
    if ($table === '' || $column === '') return false;
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}


// ========== JOURNAL EDIT AUDIT TRAIL PATCH ==========
// Keeps old and new values whenever chart_account_transactions is edited.
function ensureJournalTransactionHistoryTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `journal_transaction_history` (
        `history_id` INT(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` INT(11) NOT NULL DEFAULT 0,
        `transaction_no` VARCHAR(100) DEFAULT NULL,
        `reference_no` VARCHAR(100) DEFAULT NULL,
        `source_table` VARCHAR(100) DEFAULT NULL,
        `source_id` INT(11) DEFAULT 0,
        `history_action` VARCHAR(30) NOT NULL DEFAULT 'updated',
        `edited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `edited_by` INT(11) NOT NULL DEFAULT 0,
        `edited_by_name` VARCHAR(255) DEFAULT NULL,
        `branch_id` INT(11) DEFAULT 0,
        `old_transaction_date` DATE DEFAULT NULL,
        `old_account_id` INT(11) DEFAULT NULL,
        `old_account_title` VARCHAR(255) DEFAULT NULL,
        `old_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `old_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `old_memo` TEXT DEFAULT NULL,
        `old_counterparty` VARCHAR(255) DEFAULT NULL,
        `new_account_id` INT(11) DEFAULT NULL,
        `new_account_title` VARCHAR(255) DEFAULT NULL,
        `new_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `new_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `new_memo` TEXT DEFAULT NULL,
        `new_counterparty` VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (`history_id`),
        KEY `transaction_id` (`transaction_id`),
        KEY `transaction_no` (`transaction_no`),
        KEY `reference_no` (`reference_no`),
        KEY `source_lookup` (`source_table`, `source_id`),
        KEY `edited_at` (`edited_at`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $requiredColumns = [
        'transaction_id' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `transaction_id` INT(11) NOT NULL DEFAULT 0",
        'transaction_no' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `transaction_no` VARCHAR(100) DEFAULT NULL",
        'reference_no' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `reference_no` VARCHAR(100) DEFAULT NULL",
        'source_table' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `source_table` VARCHAR(100) DEFAULT NULL",
        'source_id' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `source_id` INT(11) DEFAULT 0",
        'history_action' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `history_action` VARCHAR(30) NOT NULL DEFAULT 'updated'",
        'edited_at' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `edited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'edited_by' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `edited_by` INT(11) NOT NULL DEFAULT 0",
        'edited_by_name' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `edited_by_name` VARCHAR(255) DEFAULT NULL",
        'branch_id' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `branch_id` INT(11) DEFAULT 0",
        'old_transaction_date' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `old_transaction_date` DATE DEFAULT NULL",
        'old_account_id' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `old_account_id` INT(11) DEFAULT NULL",
        'old_account_title' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `old_account_title` VARCHAR(255) DEFAULT NULL",
        'old_debit' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `old_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        'old_credit' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `old_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        'old_memo' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `old_memo` TEXT DEFAULT NULL",
        'old_counterparty' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `old_counterparty` VARCHAR(255) DEFAULT NULL",
        'new_account_id' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `new_account_id` INT(11) DEFAULT NULL",
        'new_account_title' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `new_account_title` VARCHAR(255) DEFAULT NULL",
        'new_debit' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `new_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        'new_credit' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `new_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        'new_memo' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `new_memo` TEXT DEFAULT NULL",
        'new_counterparty' => "ALTER TABLE `journal_transaction_history` ADD COLUMN `new_counterparty` VARCHAR(255) DEFAULT NULL"
    ];
    foreach ($requiredColumns as $column => $alterSql) {
        if (!journalColumnExists($conn, 'journal_transaction_history', $column)) {
            @$conn->query($alterSql);
        }
    }
}

function journalAuditOldExpr(mysqli $conn, string $column, string $fallback): string {
    return journalColumnExists($conn, 'chart_account_transactions', $column) ? "OLD.`{$column}`" : $fallback;
}
function journalAuditNewExpr(mysqli $conn, string $column, string $fallback): string {
    return journalColumnExists($conn, 'chart_account_transactions', $column) ? "NEW.`{$column}`" : $fallback;
}

function ensureJournalTransactionAuditTriggers(mysqli $conn): void {
    ensureJournalTransactionHistoryTable($conn);
    if (!journalTableExists($conn, 'chart_account_transactions')) return;

    // Best-effort triggers catch edits made from OTHER pages too. If hosting disallows triggers,
    // Journal Entries edit still logs through PHP before UPDATE.
    @$conn->query("DROP TRIGGER IF EXISTS `trg_cat_journal_audit_update`");
    @$conn->query("DROP TRIGGER IF EXISTS `trg_cat_journal_audit_delete`");

    $oldTransactionNo = journalAuditOldExpr($conn, 'transaction_no', "''");
    $oldReferenceNo = journalAuditOldExpr($conn, 'reference_no', "''");
    $oldSourceTable = journalAuditOldExpr($conn, 'source_table', "''");
    $oldSourceId = journalAuditOldExpr($conn, 'source_id', '0');
    $oldBranchId = journalAuditOldExpr($conn, 'branch_id', '0');
    $oldDate = journalAuditOldExpr($conn, 'transaction_date', 'NULL');
    $oldAccountName = journalAuditOldExpr($conn, 'account_name', "''");
    $newAccountName = journalAuditNewExpr($conn, 'account_name', "''");
    $oldMemo = journalAuditOldExpr($conn, 'memo', "''");
    $newMemo = journalAuditNewExpr($conn, 'memo', "''");
    $oldCounterparty = journalAuditOldExpr($conn, 'counterparty', "''");
    $newCounterparty = journalAuditNewExpr($conn, 'counterparty', "''");

    $compareParts = [
        "IFNULL(OLD.`account_id`,0) <> IFNULL(NEW.`account_id`,0)",
        "IFNULL(OLD.`debit`,0) <> IFNULL(NEW.`debit`,0)",
        "IFNULL(OLD.`credit`,0) <> IFNULL(NEW.`credit`,0)"
    ];
    if (journalColumnExists($conn, 'chart_account_transactions', 'account_name')) $compareParts[] = "IFNULL(OLD.`account_name`,'') <> IFNULL(NEW.`account_name`,'')";
    if (journalColumnExists($conn, 'chart_account_transactions', 'memo')) $compareParts[] = "IFNULL(OLD.`memo`,'') <> IFNULL(NEW.`memo`,'')";
    if (journalColumnExists($conn, 'chart_account_transactions', 'counterparty')) $compareParts[] = "IFNULL(OLD.`counterparty`,'') <> IFNULL(NEW.`counterparty`,'')";
    $compareSql = implode(' OR ', $compareParts);

    @$conn->query("CREATE TRIGGER `trg_cat_journal_audit_update` BEFORE UPDATE ON `chart_account_transactions` FOR EACH ROW
    BEGIN
        IF (({$compareSql}) AND IFNULL(@journal_skip_audit, 0) = 0) THEN
            INSERT INTO `journal_transaction_history` (
                transaction_id, transaction_no, reference_no, source_table, source_id, history_action,
                edited_at, edited_by, edited_by_name, branch_id, old_transaction_date,
                old_account_id, old_account_title, old_debit, old_credit, old_memo, old_counterparty,
                new_account_id, new_account_title, new_debit, new_credit, new_memo, new_counterparty
            ) VALUES (
                OLD.`transaction_id`, {$oldTransactionNo}, {$oldReferenceNo}, {$oldSourceTable}, {$oldSourceId}, 'updated',
                NOW(), IFNULL(@journal_editor_id, 0), IFNULL(@journal_editor_name, 'System / Source Page'), {$oldBranchId}, {$oldDate},
                OLD.`account_id`, {$oldAccountName}, OLD.`debit`, OLD.`credit`, {$oldMemo}, {$oldCounterparty},
                NEW.`account_id`, {$newAccountName}, NEW.`debit`, NEW.`credit`, {$newMemo}, {$newCounterparty}
            );
        END IF;
    END");

    @$conn->query("CREATE TRIGGER `trg_cat_journal_audit_delete` BEFORE DELETE ON `chart_account_transactions` FOR EACH ROW
    BEGIN
        IF IFNULL(@journal_skip_audit, 0) = 0 THEN
            INSERT INTO `journal_transaction_history` (
                transaction_id, transaction_no, reference_no, source_table, source_id, history_action,
                edited_at, edited_by, edited_by_name, branch_id, old_transaction_date,
                old_account_id, old_account_title, old_debit, old_credit, old_memo, old_counterparty,
                new_account_id, new_account_title, new_debit, new_credit, new_memo, new_counterparty
            ) VALUES (
                OLD.`transaction_id`, {$oldTransactionNo}, {$oldReferenceNo}, {$oldSourceTable}, {$oldSourceId}, 'deleted/replaced',
                NOW(), IFNULL(@journal_editor_id, 0), IFNULL(@journal_editor_name, 'System / Source Page'), {$oldBranchId}, {$oldDate},
                OLD.`account_id`, {$oldAccountName}, OLD.`debit`, OLD.`credit`, {$oldMemo}, {$oldCounterparty},
                NULL, '', 0.00, 0.00, '', ''
            );
        END IF;
    END");
}

function journalGetEditorName(mysqli $conn, int $userId, string $fallbackName = ''): string {
    $fallbackName = trim($fallbackName);
    if ($fallbackName !== '') return $fallbackName;
    return $userId > 0 ? ('User #' . $userId) : 'System';
}

function journalAuditFetchCurrentTransactionRow(mysqli $conn, int $transactionId): ?array {
    if ($transactionId <= 0 || !journalTableExists($conn, 'chart_account_transactions')) return null;
    $hasAccountName = journalColumnExists($conn, 'chart_account_transactions', 'account_name');
    $hasCounterparty = journalColumnExists($conn, 'chart_account_transactions', 'counterparty');
    $hasSourceTable = journalColumnExists($conn, 'chart_account_transactions', 'source_table');
    $hasSourceId = journalColumnExists($conn, 'chart_account_transactions', 'source_id');
    $hasBranchId = journalColumnExists($conn, 'chart_account_transactions', 'branch_id');
    $hasReferenceNo = journalColumnExists($conn, 'chart_account_transactions', 'reference_no');
    $hasTransactionNo = journalColumnExists($conn, 'chart_account_transactions', 'transaction_no');
    $hasTransactionDate = journalColumnExists($conn, 'chart_account_transactions', 'transaction_date');
    $hasChartAccounts = journalTableExists($conn, 'chart_of_accounts');

    $accountTitleExpr = $hasAccountName ? "COALESCE(NULLIF(cat.account_name,''), " . ($hasChartAccounts ? "coa.account_title" : "NULL") . ", CONCAT('Account #', cat.account_id))" : ($hasChartAccounts ? "COALESCE(coa.account_title, CONCAT('Account #', cat.account_id))" : "CONCAT('Account #', cat.account_id)");
    $select = "SELECT cat.transaction_id, cat.account_id, {$accountTitleExpr} AS account_title, COALESCE(cat.debit,0) AS debit, COALESCE(cat.credit,0) AS credit, "
        . ($hasTransactionDate ? "cat.transaction_date" : "NULL AS transaction_date") . ", "
        . ($hasTransactionNo ? "cat.transaction_no" : "'' AS transaction_no") . ", "
        . ($hasReferenceNo ? "cat.reference_no" : "'' AS reference_no") . ", "
        . ($hasSourceTable ? "cat.source_table" : "'' AS source_table") . ", "
        . ($hasSourceId ? "cat.source_id" : "0 AS source_id") . ", "
        . ($hasBranchId ? "cat.branch_id" : "0 AS branch_id") . ", "
        . ($hasCounterparty ? "cat.counterparty" : "'' AS counterparty") . ", cat.memo
        FROM chart_account_transactions cat";
    if ($hasChartAccounts) $select .= " LEFT JOIN chart_of_accounts coa ON coa.account_id = cat.account_id";
    $select .= " WHERE cat.transaction_id = ? LIMIT 1";
    $stmt = $conn->prepare($select);
    if (!$stmt) return null;
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function journalAuditRowChanged(array $old, array $new): bool {
    return ((int)($old['account_id'] ?? 0) !== (int)($new['account_id'] ?? 0))
        || (trim((string)($old['account_title'] ?? '')) !== trim((string)($new['account_title'] ?? '')))
        || (round((float)($old['debit'] ?? 0), 2) !== round((float)($new['debit'] ?? 0), 2))
        || (round((float)($old['credit'] ?? 0), 2) !== round((float)($new['credit'] ?? 0), 2))
        || (trim((string)($old['memo'] ?? '')) !== trim((string)($new['memo'] ?? '')))
        || (trim((string)($old['counterparty'] ?? '')) !== trim((string)($new['counterparty'] ?? '')));
}

function journalInsertAuditHistory(mysqli $conn, array $old, array $new, int $userId, string $userName, string $action = 'updated'): void {
    ensureJournalTransactionHistoryTable($conn);
    if (!journalAuditRowChanged($old, $new) && $action === 'updated') return;

    $stmt = $conn->prepare("INSERT INTO journal_transaction_history (
        transaction_id, transaction_no, reference_no, source_table, source_id, history_action,
        edited_at, edited_by, edited_by_name, branch_id, old_transaction_date,
        old_account_id, old_account_title, old_debit, old_credit, old_memo, old_counterparty,
        new_account_id, new_account_title, new_debit, new_credit, new_memo, new_counterparty
    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;

    $transactionId = (int)($old['transaction_id'] ?? ($new['transaction_id'] ?? 0));
    $transactionNo = (string)($old['transaction_no'] ?? ($new['transaction_no'] ?? ''));
    $referenceNo = (string)($old['reference_no'] ?? ($new['reference_no'] ?? ''));
    $sourceTable = (string)($old['source_table'] ?? ($new['source_table'] ?? ''));
    $sourceId = (int)($old['source_id'] ?? ($new['source_id'] ?? 0));
    $branchId = (int)($old['branch_id'] ?? ($new['branch_id'] ?? 0));
    $editedByName = journalGetEditorName($conn, $userId, $userName);
    $oldDate = !empty($old['transaction_date']) ? (string)$old['transaction_date'] : null;
    $oldAccountId = (int)($old['account_id'] ?? 0);
    $oldTitle = (string)($old['account_title'] ?? '');
    $oldDebit = round((float)($old['debit'] ?? 0), 2);
    $oldCredit = round((float)($old['credit'] ?? 0), 2);
    $oldMemo = (string)($old['memo'] ?? '');
    $oldCounterparty = (string)($old['counterparty'] ?? '');
    $newAccountId = (int)($new['account_id'] ?? 0);
    $newTitle = (string)($new['account_title'] ?? '');
    $newDebit = round((float)($new['debit'] ?? 0), 2);
    $newCredit = round((float)($new['credit'] ?? 0), 2);
    $newMemo = (string)($new['memo'] ?? '');
    $newCounterparty = (string)($new['counterparty'] ?? '');

    $stmt->bind_param(
        'isssisissisddssisddss',
        $transactionId, $transactionNo, $referenceNo, $sourceTable, $sourceId, $action,
        $userId, $editedByName, $branchId, $oldDate,
        $oldAccountId, $oldTitle, $oldDebit, $oldCredit, $oldMemo, $oldCounterparty,
        $newAccountId, $newTitle, $newDebit, $newCredit, $newMemo, $newCounterparty
    );
    @$stmt->execute();
    $stmt->close();
}

function journalFetchTransactionHistoryForGroup(mysqli $conn, array $group, int $branchId, bool $viewAllBranches): array {
    ensureJournalTransactionHistoryTable($conn);
    $rows = $group['rows'] ?? [];
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['transaction_id'] ?? 0), $rows), fn($id) => $id > 0)));
    $transactionNo = trim((string)($group['transaction_no'] ?? ''));
    $referenceNo = trim((string)($group['reference_no'] ?? ''));
    $sourceTable = trim((string)($group['source_table'] ?? ''));
    $sourceId = (int)($group['source_id'] ?? 0);

    $orParts = [];
    $types = '';
    $values = [];
    foreach ($ids as $id) { $orParts[] = 'transaction_id = ?'; $types .= 'i'; $values[] = $id; }
    if ($transactionNo !== '') { $orParts[] = 'transaction_no = ?'; $types .= 's'; $values[] = $transactionNo; }
    if ($referenceNo !== '') { $orParts[] = 'reference_no = ?'; $types .= 's'; $values[] = $referenceNo; }
    if ($sourceTable !== '' && $sourceId > 0) { $orParts[] = '(source_table = ? AND source_id = ?)'; $types .= 'si'; $values[] = $sourceTable; $values[] = $sourceId; }
    if (empty($orParts)) return [];

    $sql = "SELECT * FROM journal_transaction_history WHERE (" . implode(' OR ', $orParts) . ")";
    if (!$viewAllBranches && $branchId > 0) { $sql .= " AND (branch_id = ?)"; $types .= 'i'; $values[] = $branchId; }
    $sql .= " ORDER BY edited_at DESC, history_id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    $seen = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $key = (string)($row['history_id'] ?? md5(json_encode($row)));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $history[] = $row;
    }
    $stmt->close();
    return $history;
}

// Create the audit table/triggers early so edits from source pages are captured after this file is deployed.
ensureJournalTransactionAuditTriggers($conn);


function journalFindLoggedInUserForPassword(mysqli $conn, int $userId, string $userRole = ''): ?array {
    if ($userId <= 0) return null;

    $candidateTables = [
        'users', 'user_accounts', 'admin_users', 'branch_admins', 'employees', 'employee_users', 'staff_users'
    ];
    $idColumns = ['user_id', 'id', 'admin_id', 'employee_id', 'staff_id'];
    $passwordColumns = ['password', 'password_hash', 'user_password', 'account_password', 'passcode'];

    foreach ($candidateTables as $table) {
        if (!journalTableExists($conn, $table)) continue;

        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        foreach ($idColumns as $idColumn) {
            if (!journalColumnExists($conn, $table, $idColumn)) continue;

            $existingPasswordColumns = [];
            foreach ($passwordColumns as $passwordColumn) {
                if (journalColumnExists($conn, $table, $passwordColumn)) {
                    $existingPasswordColumns[] = $passwordColumn;
                }
            }
            if (empty($existingPasswordColumns)) continue;

            $selectParts = [];
            foreach (array_unique(array_merge([$idColumn], $existingPasswordColumns)) as $column) {
                $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $column);
                $selectParts[] = "`{$safeColumn}`";
            }

            $safeIdColumn = preg_replace('/[^A-Za-z0-9_]/', '', $idColumn);
            $stmt = $conn->prepare("SELECT " . implode(', ', $selectParts) . " FROM `{$safeTable}` WHERE `{$safeIdColumn}` = ? LIMIT 1");
            if (!$stmt) continue;
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($row) {
                $row['_password_columns'] = $existingPasswordColumns;
                $row['_source_table'] = $table;
                return $row;
            }
        }
    }

    return null;
}

function journalVerifyLoggedInUserPassword(mysqli $conn, int $userId, string $plainPassword, string $userRole = ''): bool {
    $plainPassword = (string)$plainPassword;
    if ($userId <= 0 || $plainPassword === '') return false;

    $userRow = journalFindLoggedInUserForPassword($conn, $userId, $userRole);
    if (!$userRow) return false;

    foreach (($userRow['_password_columns'] ?? []) as $passwordColumn) {
        $storedPassword = (string)($userRow[$passwordColumn] ?? '');
        if ($storedPassword === '') continue;

        if (password_verify($plainPassword, $storedPassword)) {
            return true;
        }

        // Fallback for older systems that still store md5/sha1/plain passwords.
        if (strlen($storedPassword) === 32 && hash_equals(strtolower($storedPassword), md5($plainPassword))) {
            return true;
        }
        if (strlen($storedPassword) === 40 && hash_equals(strtolower($storedPassword), sha1($plainPassword))) {
            return true;
        }
        if (hash_equals($storedPassword, $plainPassword)) {
            return true;
        }
    }

    return false;
}

function journalBuildUrl(string $page, array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null) continue;
        if (is_string($value) && trim($value) === '') continue;
        $clean[$key] = $value;
    }
    return $page . '?' . http_build_query($clean);
}


// AMGC_PAID_PO_ROUTE_PATCH_V6
// Determines whether a purchase order / Enter Bills source already has a supplier payment.
// Unpaid bills open in Enter Bills. Paid bills open in Pay Bills for payment editing.
function journalPurchaseOrderHasPayment(mysqli $conn, int $poId, string $transactionNo = '', string $referenceNo = ''): bool {
    if ($poId <= 0) return false;

    if (journalTableExists($conn, 'supplier_payments')) {
        $where = [];
        $types = '';
        $values = [];

        if (journalColumnExists($conn, 'supplier_payments', 'po_id')) {
            $where[] = 'po_id = ?';
            $types .= 'i';
            $values[] = $poId;
        }
        if ($transactionNo !== '' && journalColumnExists($conn, 'supplier_payments', 'reference_number')) {
            $where[] = 'reference_number = ?';
            $types .= 's';
            $values[] = $transactionNo;
        }
        if ($referenceNo !== '' && journalColumnExists($conn, 'supplier_payments', 'reference_number')) {
            $where[] = 'reference_number = ?';
            $types .= 's';
            $values[] = $referenceNo;
        }

        if (!empty($where)) {
            $sql = "SELECT supplier_payment_id FROM supplier_payments WHERE (" . implode(' OR ', $where) . ")";
            if (journalColumnExists($conn, 'supplier_payments', 'status')) {
                $sql .= " AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','void','deleted','reversed')";
            }
            $sql .= " LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($types !== '') $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $result = $stmt->get_result();
                $hasPayment = $result && $result->num_rows > 0;
                $stmt->close();
                if ($hasPayment) return true;
            }
        }
    }

    if (journalTableExists($conn, 'purchase_orders')) {
        $statusColumns = ['payment_status', 'pay_status', 'status'];
        $select = [];
        foreach ($statusColumns as $column) {
            if (journalColumnExists($conn, 'purchase_orders', $column)) $select[] = "`{$column}`";
        }
        foreach (['balance', 'amount_due', 'remaining_balance', 'paid_amount', 'total_paid', 'total_amount', 'grand_total'] as $column) {
            if (journalColumnExists($conn, 'purchase_orders', $column)) $select[] = "`{$column}`";
        }
        if (!empty($select) && journalColumnExists($conn, 'purchase_orders', 'po_id')) {
            $stmt = $conn->prepare("SELECT " . implode(',', array_unique($select)) . " FROM purchase_orders WHERE po_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $poId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    foreach ($statusColumns as $column) {
                        $value = strtolower(trim((string)($row[$column] ?? '')));
                        if (in_array($value, ['paid','fully paid','completed','settled','closed'], true)) return true;
                    }
                    foreach (['balance', 'amount_due', 'remaining_balance'] as $column) {
                        if (array_key_exists($column, $row) && abs((float)$row[$column]) < 0.005) return true;
                    }
                    $paid = 0.0;
                    foreach (['paid_amount', 'total_paid'] as $column) {
                        if (array_key_exists($column, $row)) $paid = max($paid, (float)$row[$column]);
                    }
                    $total = 0.0;
                    foreach (['total_amount', 'grand_total'] as $column) {
                        if (array_key_exists($column, $row)) $total = max($total, (float)$row[$column]);
                    }
                    if ($total > 0 && $paid + 0.005 >= $total) return true;
                }
            }
        }
    }

    return false;
}

function journalGetTransactionOpenUrl(mysqli $conn, array $row): string {
    $sourceTable = strtolower(trim((string)($row['source_table'] ?? '')));
    $sourceId = (int)($row['source_id'] ?? 0);
    $transactionId = (int)($row['transaction_id'] ?? 0);
    $transactionType = strtolower(trim((string)($row['transaction_type'] ?? '')));
    $transactionNo = trim((string)($row['transaction_no'] ?? ''));
    $referenceNo = trim((string)($row['reference_no'] ?? ''));
    $sectionLabel = strtolower(trim((string)($row['section_label'] ?? '')));

    if ($sourceId <= 0 && $transactionId <= 0 && $transactionNo === '' && $referenceNo === '') return '';

    $baseParams = [
        // These are harmless when the source page does not use them, but useful for pages that already support auto-open/edit.
        'from_journal_entries' => '1',
        'journal_edit' => '1',
        'journal_edit_granted' => '1',
        'auto_open' => '1',
        'mode' => 'edit',
        'source_table' => $sourceTable,
        'source_id' => $sourceId,
        'transaction_id' => $transactionId,
        'transaction_no' => $transactionNo,
        'reference_no' => $referenceNo,
        'ref_no' => $referenceNo,
    ];

    // Manual journal entry source.
    if ($sourceTable === 'journal_entries' || str_contains($transactionType, 'journal')) {
        return journalBuildUrl('journal_entries.php', $baseParams + [
            'edit_journal' => '1',
            'journal_id' => $sourceId,
            'entry_no' => $transactionNo,
        ]);
    }

    // AMGC_PAID_PO_ROUTE_PATCH_V6
    // Purchase Orders are conditional:
    // - Unpaid / Receive Inventory / Enter Bills => purchase_order.php (Enter Bills UI)
    // - Paid / Bill Payment / Pay Bills => paybills.php (Pay Bills UI)
    $purchaseOrderHasPayment = ($sourceTable === 'purchase_orders' && $sourceId > 0)
        ? journalPurchaseOrderHasPayment($conn, $sourceId, $transactionNo, $referenceNo)
        : false;

    if ($sourceTable === 'purchase_orders' && !$purchaseOrderHasPayment && !str_contains($transactionType, 'pay bill') && !str_contains($transactionType, 'bill payment') && $sectionLabel !== 'pay bills') {
        return journalBuildUrl('enterbills.php', $baseParams + [
            'open_enter_bills' => '1',
            'open_receive_inventory' => '1',
            'edit_enter_bills' => '1',
            'edit_po' => '1',
            'po_id' => $sourceId,
            'selected_po_id' => $sourceId,
            'po_number' => $transactionNo,
        ]);
    }

    // Pay Bills payments only. Paid purchase orders must fall into this route.
    // Send every commonly-used payment opener key so the existing Pay Bills UI can find and preselect the transaction.
    if (($sourceTable === 'purchase_orders' && ($purchaseOrderHasPayment || $sectionLabel === 'pay bills' || str_contains($transactionType, 'pay bill') || str_contains($transactionType, 'bill payment')))
        || in_array($sourceTable, ['supplier_payments', 'supplier_beginning_balances', 'billexpense_payments'], true)
        || $sectionLabel === 'pay bills'
        || str_contains($transactionType, 'pay bill') || str_contains($transactionType, 'bill payment')) {
        $payableType = 'po';
        if (in_array($sourceTable, ['supplier_beginning_balances'], true)) $payableType = 'beginning_balance';
        if (in_array($sourceTable, ['billexpenses', 'billexpense_payments'], true)) $payableType = 'expense';

        return journalBuildUrl('paybills.php', $baseParams + [
            'open_paybills' => '1',
            'open_paybill' => '1',
            'open_payment' => '1',
            'edit_payment' => '1',
            'edit_paybill' => '1',
            'payable_type' => $payableType,
            'po_id' => $payableType === 'po' ? $sourceId : null,
            'selected_po_id' => $payableType === 'po' ? $sourceId : null,
            'paybill_po_id' => $payableType === 'po' ? $sourceId : null,
            'beginning_balance_id' => $payableType === 'beginning_balance' ? $sourceId : null,
            'supplier_balance_id' => $payableType === 'beginning_balance' ? $sourceId : null,
            'expense_id' => $payableType === 'expense' ? $sourceId : null,
            'expense_payment_id' => $sourceTable === 'billexpense_payments' ? $sourceId : null,
            'supplier_payment_id' => $sourceTable === 'supplier_payments' ? $sourceId : null,
            'payment_id' => $sourceTable === 'supplier_payments' ? $sourceId : null,
            'po_number' => $transactionNo,
        ]);
    }

    // Deposit / Record Deposits.
    if ($transactionType === 'deposit' || str_contains($sectionLabel, 'record deposits') || in_array($sourceTable, ['bank_transaction_payments', 'bank_transaction_credit_memos'], true)) {
        return journalBuildUrl('deposit.php', $baseParams + [
            'open_deposit' => '1',
            'edit_deposit' => '1',
            'deposit_id' => $sourceTable === 'bank_transactions' ? $sourceId : ($transactionId > 0 ? $transactionId : null),
            'bank_transaction_id' => $sourceTable === 'bank_transactions' ? $sourceId : ($transactionId > 0 ? $transactionId : null),
            'payment_id' => $sourceTable === 'bank_transaction_payments' ? $sourceId : null,
            'credit_memo_id' => $sourceTable === 'bank_transaction_credit_memos' ? $sourceId : null,
        ]);
    }

    // Withdrawal / Write Checks.
    if ($sourceTable === 'bank_transactions'
        || in_array($transactionType, ['check', 'withdrawal', 'write check', 'write checks'], true)
        || str_contains($sectionLabel, 'write checks')) {
        $bankTransactionId = $sourceTable === 'bank_transactions' ? $sourceId : ($transactionId > 0 ? $transactionId : $sourceId);
        return journalBuildUrl('Withdrawal.php', $baseParams + [
            'open_withdrawal' => '1',
            'edit_withdrawal' => '1',
            'withdrawal_id' => $bankTransactionId,
            'bank_transaction_id' => $bankTransactionId,
            'transaction_id' => $bankTransactionId,
            'check_number' => $transactionNo,
        ]);
    }

    // Invoice / Sales Order source.
    if (in_array($sourceTable, ['invoices', 'sales_orders'], true) || str_contains($sectionLabel, 'create invoice')) {
        return journalBuildUrl('orderproduct.php', $baseParams + [
            'open_invoice' => '1',
            'edit_invoice' => '1',
            'invoice_id' => $sourceTable === 'invoices' ? $sourceId : null,
            'so_id' => $sourceTable === 'sales_orders' ? $sourceId : null,
            'sales_order_id' => $sourceTable === 'sales_orders' ? $sourceId : null,
        ]);
    }

    // Receive Payment / Collections / Customer Credit.
    if (in_array($sourceTable, ['collection_records', 'payments', 'credit_memos', 'bank_transaction_credit_memos', 'credit_discounts'], true)
        || str_contains($sectionLabel, 'receive payment') || str_contains($sectionLabel, 'customer credit')) {
        return journalBuildUrl('collections.php', $baseParams + [
            'open_collection' => '1',
            'open_payment' => '1',
            'edit_payment' => '1',
            'record_id' => $sourceTable === 'collection_records' ? $sourceId : null,
            'payment_id' => $sourceTable === 'payments' ? $sourceId : null,
            'credit_memo_id' => in_array($sourceTable, ['credit_memos', 'bank_transaction_credit_memos'], true) ? $sourceId : null,
            'credit_id' => $sourceTable === 'credit_discounts' ? $sourceId : null,
        ]);
    }

    if ($sourceTable === 'fund_transfers' || str_contains($sectionLabel, 'transfer funds')) {
        return journalBuildUrl('transferfunds.php', $baseParams + [
            'open_transfer' => '1',
            'edit_transfer' => '1',
            'transfer_id' => $sourceId,
        ]);
    }

    if (in_array($sourceTable, ['employee_dtr', 'employees'], true) || str_contains($sectionLabel, 'enter time')) {
        return journalBuildUrl('employee.php', $baseParams + [
            'open_dtr' => '1',
            'edit_dtr' => '1',
            'dtr_id' => $sourceTable === 'employee_dtr' ? $sourceId : null,
            'employee_id' => $sourceTable === 'employees' ? $sourceId : null,
        ]);
    }

    if (str_contains($sourceTable, 'motorpool') || str_contains($transactionType, 'repair') || str_contains($sectionLabel, 'motorpool')) {
        return journalBuildUrl('motorpool.php', $baseParams + [
            'open_motorpool' => '1',
            'edit_motorpool' => '1',
            'id' => $sourceId,
        ]);
    }

    $fallbackPages = [
        'expenses' => 'expenses.php',
        'rolling_expenses' => 'rolling_expenses.php',
        'deliveries' => 'delivery.php',
        'beginning_balances' => 'collections.php'
    ];

    if ($sourceTable !== '' && isset($fallbackPages[$sourceTable])) {
        return journalBuildUrl($fallbackPages[$sourceTable], $baseParams + [
            'id' => $sourceId,
            'edit_id' => $sourceId,
        ]);
    }

    return '';
}

function handleJournalPasswordAjax(mysqli $conn, int $userId, string $userRole): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['journal_ajax_action'] ?? '') !== 'verify_edit_password') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');

    $password = (string)($_POST['password'] ?? '');
    if ($password === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter your password.']);
        exit;
    }

    if (journalVerifyLoggedInUserPassword($conn, $userId, $password, $userRole)) {
        $_SESSION['journal_edit_verified_until'] = time() + 300;
        echo json_encode(['success' => true, 'message' => 'Password confirmed.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Incorrect password. Editing is not allowed.']);
    exit;
}

handleJournalPasswordAjax($conn, (int)$user_id, (string)$user_role);

function handleJournalTransactionUpdateAjax(mysqli $conn, int $userId, int $branchId, bool $viewAllBranches): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['journal_ajax_action'] ?? '') !== 'update_journal_transaction') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');

    $verifiedUntil = (int)($_SESSION['journal_edit_verified_until'] ?? 0);
    if ($verifiedUntil < time()) {
        echo json_encode(['success' => false, 'message' => 'Password confirmation expired. Please confirm your password again.']);
        exit;
    }

    if (!journalTableExists($conn, 'chart_account_transactions')) {
        echo json_encode(['success' => false, 'message' => 'chart_account_transactions table was not found.']);
        exit;
    }

    $rowsJson = (string)($_POST['rows'] ?? '');
    $rows = json_decode($rowsJson, true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'No transaction rows received.']);
        exit;
    }

    $transactionIds = [];
    $cleanRows = [];
    $totalDebit = 0.00;
    $totalCredit = 0.00;

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $transactionId = (int)($row['transaction_id'] ?? 0);
        $accountTitle = trim((string)($row['account_title'] ?? ''));
        $debit = round((float)str_replace(',', '', (string)($row['debit'] ?? 0)), 2);
        $credit = round((float)str_replace(',', '', (string)($row['credit'] ?? 0)), 2);
        $memo = trim((string)($row['memo'] ?? ''));
        $counterparty = trim((string)($row['counterparty'] ?? ''));

        if ($transactionId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid transaction row found.']);
            exit;
        }
        if ($accountTitle === '') {
            echo json_encode(['success' => false, 'message' => 'Account Title is required on every row.']);
            exit;
        }
        if ($debit < 0 || $credit < 0) {
            echo json_encode(['success' => false, 'message' => 'Debit and Credit must not be negative.']);
            exit;
        }
        if ($debit > 0 && $credit > 0) {
            echo json_encode(['success' => false, 'message' => 'A row cannot have both Debit and Credit.']);
            exit;
        }
        if ($debit <= 0 && $credit <= 0) {
            echo json_encode(['success' => false, 'message' => 'Each row must have either Debit or Credit amount.']);
            exit;
        }

        $account = findJournalAccount($conn, $accountTitle, $branchId, $viewAllBranches);
        if (!$account) {
            echo json_encode(['success' => false, 'message' => 'Account Title not found: ' . $accountTitle]);
            exit;
        }
        if (journalAccountHasSubAccount($conn, (int)$account['account_id'], $branchId, $viewAllBranches)) {
            echo json_encode(['success' => false, 'message' => 'Parent accounts with sub accounts cannot be selected: ' . $accountTitle]);
            exit;
        }

        $transactionIds[] = $transactionId;
        $totalDebit += $debit;
        $totalCredit += $credit;
        $cleanRows[] = [
            'transaction_id' => $transactionId,
            'account_id' => (int)$account['account_id'],
            'account_title' => (string)$account['account_title'],
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
            'counterparty' => $counterparty
        ];
    }

    if (count($cleanRows) < 2) {
        echo json_encode(['success' => false, 'message' => 'A journal transaction must have at least two rows.']);
        exit;
    }
    if (abs($totalDebit - $totalCredit) > 0.009) {
        echo json_encode(['success' => false, 'message' => 'Total Debit and Credit must be equal.']);
        exit;
    }

    $hasCounterparty = journalColumnExists($conn, 'chart_account_transactions', 'counterparty');
    if (!$hasCounterparty) {
        @$conn->query("ALTER TABLE `chart_account_transactions` ADD COLUMN `counterparty` varchar(255) DEFAULT NULL");
        $hasCounterparty = journalColumnExists($conn, 'chart_account_transactions', 'counterparty');
    }
    $hasAccountName = journalColumnExists($conn, 'chart_account_transactions', 'account_name');

    $conn->begin_transaction();
    try {
        // We insert history manually for Journal Entries edits, then skip the trigger to avoid duplicate history rows.
        @$conn->query("SET @journal_skip_audit = 1");
        @$conn->query("SET @journal_editor_id = " . (int)$userId);
        @$conn->query("SET @journal_editor_name = '" . $conn->real_escape_string(journalGetEditorName($conn, (int)$userId)) . "'");
        foreach ($cleanRows as $row) {
            $oldAuditRow = journalAuditFetchCurrentTransactionRow($conn, (int)$row['transaction_id']);
            if ($oldAuditRow) {
                $newAuditRow = $oldAuditRow;
                $newAuditRow['account_id'] = (int)$row['account_id'];
                $newAuditRow['account_title'] = (string)$row['account_title'];
                $newAuditRow['debit'] = (float)$row['debit'];
                $newAuditRow['credit'] = (float)$row['credit'];
                $newAuditRow['memo'] = (string)$row['memo'];
                $newAuditRow['counterparty'] = (string)$row['counterparty'];
                journalInsertAuditHistory($conn, $oldAuditRow, $newAuditRow, (int)$userId, '', 'updated');
            }
            if ($hasAccountName && $hasCounterparty) {
                $stmt = $conn->prepare("UPDATE chart_account_transactions SET account_id = ?, account_name = ?, debit = ?, credit = ?, memo = ?, counterparty = ? WHERE transaction_id = ?" . (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_account_transactions', 'branch_id') ? " AND branch_id = ?" : ""));
                if (!$stmt) throw new Exception('Unable to prepare update.');
                if (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_account_transactions', 'branch_id')) {
                    $stmt->bind_param('isddssii', $row['account_id'], $row['account_title'], $row['debit'], $row['credit'], $row['memo'], $row['counterparty'], $row['transaction_id'], $branchId);
                } else {
                    $stmt->bind_param('isddssi', $row['account_id'], $row['account_title'], $row['debit'], $row['credit'], $row['memo'], $row['counterparty'], $row['transaction_id']);
                }
            } elseif ($hasAccountName) {
                $stmt = $conn->prepare("UPDATE chart_account_transactions SET account_id = ?, account_name = ?, debit = ?, credit = ?, memo = ? WHERE transaction_id = ?" . (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_account_transactions', 'branch_id') ? " AND branch_id = ?" : ""));
                if (!$stmt) throw new Exception('Unable to prepare update.');
                if (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_account_transactions', 'branch_id')) {
                    $stmt->bind_param('isddsii', $row['account_id'], $row['account_title'], $row['debit'], $row['credit'], $row['memo'], $row['transaction_id'], $branchId);
                } else {
                    $stmt->bind_param('isddsi', $row['account_id'], $row['account_title'], $row['debit'], $row['credit'], $row['memo'], $row['transaction_id']);
                }
            } else {
                $stmt = $conn->prepare("UPDATE chart_account_transactions SET account_id = ?, debit = ?, credit = ?, memo = ? WHERE transaction_id = ?" . (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_account_transactions', 'branch_id') ? " AND branch_id = ?" : ""));
                if (!$stmt) throw new Exception('Unable to prepare update.');
                if (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_account_transactions', 'branch_id')) {
                    $stmt->bind_param('iddsii', $row['account_id'], $row['debit'], $row['credit'], $row['memo'], $row['transaction_id'], $branchId);
                } else {
                    $stmt->bind_param('iddsi', $row['account_id'], $row['debit'], $row['credit'], $row['memo'], $row['transaction_id']);
                }
            }
            if (!$stmt->execute()) throw new Exception($stmt->error ?: 'Unable to update row.');
            $stmt->close();
        }
        $conn->commit();
        @$conn->query("SET @journal_skip_audit = 0");
        echo json_encode(['success' => true, 'message' => 'Transaction updated successfully.']);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        @$conn->query("SET @journal_skip_audit = 0");
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        exit;
    }
}

handleJournalTransactionUpdateAjax($conn, (int)$user_id, (int)$branch_id, (bool)$view_all_branches);

// ========== SAVE CREATE JOURNAL ==========
function ensureManualJournalTables(mysqli $conn): void {
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
}

function renderJournalSaveSuccessRedirect(string $message, string $redirectUrl): void {
    $safeMessage = json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $safeRedirect = json_encode($redirectUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Saving Journal</title>';
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<style>body{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8fafc;margin:0}.amgc-swal-popup{border-radius:18px!important;padding:1.5rem!important;box-shadow:0 18px 45px rgba(15,23,42,.18)!important;border:1px solid rgba(5,150,105,.12)!important}.amgc-swal-title{color:#064e3b!important;font-weight:700!important}.amgc-swal-html{color:#475569!important}.amgc-swal-confirm{background:linear-gradient(135deg,#44D34E,#047857)!important;border:none!important;border-radius:10px!important;padding:.65rem 1.25rem!important;font-weight:700!important;color:#fff!important}
        .sidebar .nav-link[href="journal_entries.php"]{
            background:rgba(68,211,78,.14)!important;
            color:#44D34E!important;
            border-left:4px solid #44D34E!important;
        }
        .sidebar.collapsed .nav-link[href="journal_entries.php"]{
            border-left:none!important;
        }
</style>';
    echo '</head><body>';
    echo '<script>';
    echo 'document.addEventListener("DOMContentLoaded",function(){Swal.fire({icon:"success",title:"Success",text:' . $safeMessage . ',timer:1200,timerProgressBar:true,showConfirmButton:false,allowOutsideClick:false,allowEscapeKey:false,customClass:{popup:"amgc-swal-popup",title:"amgc-swal-title",htmlContainer:"amgc-swal-html",confirmButton:"amgc-swal-confirm"}}).then(function(){window.location.href=' . $safeRedirect . ';});setTimeout(function(){window.location.href=' . $safeRedirect . ';},1500);});';
    echo '</script></body></html>';
}

function findJournalAccount(mysqli $conn, string $accountTitle, int $branchId, bool $viewAllBranches): ?array {
    $sql = "SELECT account_id, account_title FROM chart_of_accounts WHERE status = 'active' AND account_title = ?";
    if (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ?)";
        $sql .= " ORDER BY CASE WHEN branch_id = ? THEN 0 ELSE 1 END, account_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('sii', $accountTitle, $branchId, $branchId);
    } else {
        $sql .= " ORDER BY account_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('s', $accountTitle);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function journalAccountHasSubAccount(mysqli $conn, int $accountId, int $branchId, bool $viewAllBranches): bool {
    if ($accountId <= 0 || !journalTableExists($conn, 'chart_of_accounts') || !journalColumnExists($conn, 'chart_of_accounts', 'parent_account_id')) {
        return false;
    }

    $sql = "SELECT account_id FROM chart_of_accounts WHERE status = 'active' AND parent_account_id = ?";
    if (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ?)";
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ii', $accountId, $branchId);
    } else {
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('i', $accountId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $hasSubAccount = $result && $result->num_rows > 0;
    $stmt->close();

    return $hasSubAccount;
}

function handleCreateJournalSave(mysqli $conn, int $userId, int $branchId, bool $viewAllBranches, string &$journalError): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_journal'])) {
        return false;
    }

    ensureManualJournalTables($conn);

    $entryNo = trim((string)($_POST['entry_no'] ?? ''));
    $journalDate = trim((string)($_POST['journal_date'] ?? date('Y-m-d')));
    $saveAction = trim((string)($_POST['save_journal_action'] ?? 'new'));
    if (!in_array($saveAction, ['new', 'close'], true)) {
        $saveAction = 'new';
    }
    $accountTitles = $_POST['account_title'] ?? [];
    $debits = $_POST['debit'] ?? [];
    $credits = $_POST['credit'] ?? [];
    $memos = $_POST['line_memo'] ?? [];
    $counterparties = $_POST['counterparty'] ?? [];

    if ($entryNo === '') {
        $journalError = 'Entry No. is required.';
        return true;
    }

    if ($journalDate === '' || !strtotime($journalDate)) {
        $journalError = 'Valid journal date is required.';
        return true;
    }

    $lines = [];
    $totalDebit = 0.00;
    $totalCredit = 0.00;
    $rowCount = max(count((array)$accountTitles), count((array)$debits), count((array)$credits), count((array)$memos), count((array)$counterparties));

    for ($i = 0; $i < $rowCount; $i++) {
        $accountTitle = trim((string)($accountTitles[$i] ?? ''));
        $debit = (float)str_replace(',', '', (string)($debits[$i] ?? 0));
        $credit = (float)str_replace(',', '', (string)($credits[$i] ?? 0));
        $memo = trim((string)($memos[$i] ?? ''));
        $counterparty = trim((string)($counterparties[$i] ?? ''));

        if ($accountTitle === '' && abs($debit) < 0.005 && abs($credit) < 0.005 && $memo === '' && $counterparty === '') {
            continue;
        }

        if ($accountTitle === '') {
            $journalError = 'Account Title is required on every filled row.';
            return true;
        }

        if ($debit < 0 || $credit < 0) {
            $journalError = 'Debit and Credit must not be negative.';
            return true;
        }

        if ($debit > 0 && $credit > 0) {
            $journalError = 'A row cannot have both Debit and Credit.';
            return true;
        }

        if ($debit <= 0 && $credit <= 0) {
            $journalError = 'Each filled row must have either Debit or Credit amount.';
            return true;
        }

        $account = findJournalAccount($conn, $accountTitle, $branchId, $viewAllBranches);
        if (!$account) {
            $journalError = 'Account Title not found: ' . $accountTitle;
            return true;
        }

        if (journalAccountHasSubAccount($conn, (int)$account['account_id'], $branchId, $viewAllBranches)) {
            $journalError = 'Parent accounts with sub accounts cannot be selected: ' . $accountTitle . '. Please select a sub account.';
            return true;
        }

        $debit = round($debit, 2);
        $credit = round($credit, 2);
        $totalDebit += $debit;
        $totalCredit += $credit;

        $lines[] = [
            'account_id' => (int)$account['account_id'],
            'account_title' => (string)$account['account_title'],
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
            'counterparty' => $counterparty
        ];
    }

    if (count($lines) < 2) {
        $journalError = 'Please add at least two journal lines.';
        return true;
    }

    if (abs($totalDebit - $totalCredit) > 0.009) {
        $journalError = 'Total Debit and Credit must be equal. Debit: ' . number_format($totalDebit, 2) . ' Credit: ' . number_format($totalCredit, 2);
        return true;
    }

    $existingStmt = $conn->prepare("SELECT journal_id FROM journal_entries WHERE entry_no = ? LIMIT 1");
    if ($existingStmt) {
        $existingStmt->bind_param('s', $entryNo);
        $existingStmt->execute();
        $existingResult = $existingStmt->get_result();
        if ($existingResult && $existingResult->num_rows > 0) {
            $existingStmt->close();
            $journalError = 'Entry No. already exists. Please refresh the page to generate a new Entry No.';
            return true;
        }
        $existingStmt->close();
    }

    $attachmentPaths = [];
    if (!empty($_FILES['journal_attachment']['name'][0])) {
        $uploadDir = __DIR__ . '/../uploads/journal_attachments/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        foreach ($_FILES['journal_attachment']['name'] as $idx => $originalName) {
            if (($_FILES['journal_attachment']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)$originalName));
            $fileName = date('YmdHis') . '_' . uniqid('', true) . '_' . $safeName;
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['journal_attachment']['tmp_name'][$idx], $targetPath)) {
                $attachmentPaths[] = '../uploads/journal_attachments/' . $fileName;
            }
        }
    }

    $attachmentJson = !empty($attachmentPaths) ? json_encode($attachmentPaths, JSON_UNESCAPED_SLASHES) : null;

    $conn->begin_transaction();
    try {
        $headerStmt = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, created_by) VALUES (?, ?, ?, ?, ?)");
        if (!$headerStmt) {
            throw new Exception('Unable to prepare journal header save.');
        }
        $headerStmt->bind_param('sssii', $entryNo, $journalDate, $attachmentJson, $branchId, $userId);
        if (!$headerStmt->execute()) {
            throw new Exception($headerStmt->error ?: 'Unable to save journal header.');
        }
        $journalId = (int)$conn->insert_id;
        $headerStmt->close();

        $detailStmt = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$detailStmt) {
            throw new Exception('Unable to prepare journal detail save.');
        }

        $transactionStmt = null;
        if (journalTableExists($conn, 'chart_account_transactions')) {
            $transactionStmt = $conn->prepare("INSERT INTO chart_account_transactions (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, debit, credit, balance_after, source_table, source_id, created_by) VALUES (?, ?, ?, 'Journal Entry', ?, ?, ?, ?, ?, ?, 0.00, 'journal_entries', ?, ?)");
            if (!$transactionStmt) {
                throw new Exception('Unable to prepare chart account transaction save.');
            }
        }

        $referenceNo = 'Journal #' . $journalId;
        foreach ($lines as $line) {
            $detailStmt->bind_param(
                'iisddss',
                $journalId,
                $line['account_id'],
                $line['account_title'],
                $line['debit'],
                $line['credit'],
                $line['memo'],
                $line['counterparty']
            );
            if (!$detailStmt->execute()) {
                throw new Exception($detailStmt->error ?: 'Unable to save journal details.');
            }

            if ($transactionStmt) {
                $transactionMemo = $line['memo'] !== '' ? $line['memo'] : ('Manual Journal Entry ' . $entryNo);
                $transactionStmt->bind_param(
                    'iisssssddii',
                    $line['account_id'],
                    $branchId,
                    $journalDate,
                    $entryNo,
                    $referenceNo,
                    $transactionMemo,
                    $line['account_title'],
                    $line['debit'],
                    $line['credit'],
                    $journalId,
                    $userId
                );
                if (!$transactionStmt->execute()) {
                    throw new Exception($transactionStmt->error ?: 'Unable to save chart account transaction.');
                }
            }
        }

        $detailStmt->close();
        if ($transactionStmt) $transactionStmt->close();

        $conn->commit();

        $successText = ($saveAction === 'close')
            ? 'Journal entry saved successfully. Redirecting to dashboard...'
            : 'Journal entry saved successfully. Ready for a new journal entry.';

        $_SESSION['journal_success_message'] = $successText;
        $_SESSION['journal_success_redirect'] = ($saveAction === 'close') ? 'motorpool.php' : '';

        $selfUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $selfUrl);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $journalError = 'Save failed: ' . $e->getMessage();
        return true;
    }
}

handleCreateJournalSave($conn, $user_id, $branch_id, (bool)$view_all_branches, $journal_error_message);

function journalEntrySectionLabel(array $row): string {
    $type = strtolower(trim((string)($row['transaction_type'] ?? '')));
    $source = strtolower(trim((string)($row['source_table'] ?? '')));

    // AMGC_RECEIVE_INVENTORY_TO_ENTER_BILLS_PATCH_V5
    if (str_contains($type, 'receive inventory') || str_contains($type, 'receive item') || str_contains($type, 'inventory received')) return 'Enter Bills';
    if ($type === 'bill') return 'Enter Bills';
    if ($type === 'credit') return 'Enter Bills - Credits';
    if (in_array($type, ['bill payment', 'pay bill', 'pay bills', 'bill payment'], true) || str_contains($type, 'bill payment')) return 'Pay Bills';
    if (in_array($type, ['invoice', 'create invoice', 'sales order', 'sales'], true) || in_array($source, ['invoices', 'sales_orders'], true)) return 'Create Invoice';
    if (in_array($type, ['customer credit', 'credit memo'], true) || in_array($source, ['credit_memos', 'bank_transaction_credit_memos'], true)) return 'Customer Credit';
    if (in_array($type, ['receive payment', 'payment', 'collection'], true) || in_array($source, ['payments', 'collection_records'], true)) return 'Receive Payment';
    if ($type === 'deposit' || in_array($source, ['bank_transaction_payments'], true)) return 'Record Deposits';
    if (in_array($type, ['check', 'withdrawal', 'write check', 'write checks'], true)) return 'Write Checks';
    if ($type === 'transfer funds' || $source === 'fund_transfers') return 'Transfer Funds';
    if (in_array($type, ['enter time', 'salary', 'payroll'], true) || in_array($source, ['employee_dtr', 'employees'], true)) return 'Enter Time (Employees)';
    if (str_contains($type, 'repair') || str_contains($source, 'motorpool') || $source === 'repair_payment_history') return 'Motorpool';

    return trim((string)($row['transaction_type'] ?? 'Journal Entry')) ?: 'Journal Entry';
}

function journalEntryRemarks(string $sectionLabel): string {
    if (in_array($sectionLabel, ['Enter Bills', 'Enter Bills - Credits', 'Pay Bills', 'Enter Time (Employees)', 'Motorpool'], true)) {
        return 'All transactions saved on a Payable account should appear in Pay Bills';
    }
    if (in_array($sectionLabel, ['Create Invoice', 'Receive Payment'], true)) {
        return 'All transactions saved on a Receivable account should appear in Receive Payments';
    }
    if ($sectionLabel === 'Customer Credit') {
        return 'Since this is a Customer Credit, it should appear in Receive Payments as available credit.';
    }
    return '';
}


function journalSafeJson(array $value): string {
    return htmlspecialchars(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

function journalNormalizeAttachmentPath(string $path, string $sourceTable = ''): string {
    $path = trim($path);
    if ($path === '') return '';

    if (str_starts_with($path, 'data:') || preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);

    // Keep paths that are already valid relative paths from this Motorpool page.
    if (str_starts_with($path, '../') || str_starts_with($path, './') || str_starts_with($path, '/')) {
        return $path;
    }

    // Most existing tables store paths like uploads/receive_attachments/...
    // From branch_admin/*.php, that should become ../uploads/receive_attachments/...
    if (preg_match('/^uploads\//i', $path)) {
        return '../' . ltrim($path, '/');
    }

    $sourceTable = strtolower(trim($sourceTable));
    $uploadMap = [
        'journal_entries' => '../uploads/journal_attachments/',
        'purchase_orders' => '../uploads/receive_attachments/',
        'fund_transfers' => '../uploads/transfer_funds/',
        'collection_records' => '../uploads/collection_attachments/',
        'payments' => '../uploads/payment_attachments/',
        'payment_attachments' => '../',
        'withdrawal_attachments' => '../uploads/withdrawal_attachments/',
        'expenses' => '../uploads/expenses/',
        'repair_payment_history' => '../uploads/repair_payments/',
        'motorpool_ris_workflow_history' => '../uploads/motorpool/',
        'motorpool_ris_requests' => '../uploads/motorpool/',
        'motorpool_repair_release_proofs' => '../uploads/motorpool/',
        'motorpool_start_repair_proofs' => '../uploads/motorpool/',
        'vehicle_repair_history' => '../uploads/motorpool/',
        'rolling_expenses' => '../uploads/rolling_expenses/',
        'credit_memos' => '../uploads/credit_memos/',
        'credit_memo_attachments' => '../uploads/credit_memos/',
        'credit_discount_attachments' => '../uploads/credit_discounts/',
        'beginning_balance_attachments' => '../uploads/beginning_balances/',
        'supplier_beginning_balance_attachments' => '../uploads/supplier_beginning_balances/',
        'delivery_attachments' => '../uploads/delivery_attachments/',
        'collection_invoice_returns' => '../uploads/collection_attachments/',
        'central_warehouse_attachments' => '../uploads/centralwarehouse_attachments/'
    ];

    return ($uploadMap[$sourceTable] ?? '../uploads/') . ltrim($path, '/');
}

function journalAddAttachment(array &$attachments, string $path, string $name = '', string $sourceTable = ''): void {
    $path = trim($path);
    if ($path === '') return;

    $decoded = json_decode($path, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $itemPath = (string)($item['file_path'] ?? $item['path'] ?? $item['relative_path'] ?? $item['url'] ?? $item['attachment_path'] ?? $item['attachment'] ?? '');
                $itemName = (string)($item['file_name'] ?? $item['name'] ?? $item['original_name'] ?? $item['stored_name'] ?? basename($itemPath));
                journalAddAttachment($attachments, $itemPath, $itemName, $sourceTable);
            } else {
                journalAddAttachment($attachments, (string)$item, '', $sourceTable);
            }
        }
        return;
    }

    if (str_contains($path, ',') && !str_starts_with($path, 'data:')) {
        foreach (explode(',', $path) as $part) {
            journalAddAttachment($attachments, trim($part), '', $sourceTable);
        }
        return;
    }

    $normalized = journalNormalizeAttachmentPath($path, $sourceTable);
    if ($normalized === '') return;

    $label = trim($name) !== '' ? trim($name) : basename(parse_url($normalized, PHP_URL_PATH) ?: $normalized);
    $key = md5($normalized);
    $pathOnly = parse_url($normalized, PHP_URL_PATH) ?: $normalized;
    $isImage = (bool)preg_match('/\.(png|jpe?g|gif|webp|bmp|svg)$/i', $pathOnly) || str_starts_with($normalized, 'data:image/');
    $isPdf = (bool)preg_match('/\.pdf$/i', $pathOnly);

    $attachments[$key] = [
        'name' => $label !== '' ? $label : 'Attachment',
        'path' => $normalized,
        'is_image' => $isImage,
        'is_pdf' => $isPdf
    ];
}

function journalFetchAttachmentsFromTable(mysqli $conn, string $table, string $whereColumn, int $sourceId, array $pathColumns, array $nameColumns = []): array {
    $attachments = [];
    if ($sourceId <= 0 || $whereColumn === '' || !journalTableExists($conn, $table) || !journalColumnExists($conn, $table, $whereColumn)) {
        return [];
    }

    $selectParts = [];
    foreach (array_unique(array_merge($pathColumns, $nameColumns)) as $column) {
        if (journalColumnExists($conn, $table, $column)) {
            $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $column);
            $selectParts[] = "`{$safeColumn}`";
        }
    }

    if (empty($selectParts)) return [];

    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeWhere = preg_replace('/[^A-Za-z0-9_]/', '', $whereColumn);
    $sql = "SELECT " . implode(', ', $selectParts) . " FROM `{$safeTable}` WHERE `{$safeWhere}` = ? LIMIT 50";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $sourceId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $name = '';
        foreach ($nameColumns as $nameColumn) {
            if (isset($row[$nameColumn]) && trim((string)$row[$nameColumn]) !== '') {
                $name = (string)$row[$nameColumn];
                break;
            }
        }
        foreach ($pathColumns as $pathColumn) {
            if (isset($row[$pathColumn]) && trim((string)$row[$pathColumn]) !== '') {
                journalAddAttachment($attachments, (string)$row[$pathColumn], $name, $table);
            }
        }
    }
    $stmt->close();
    return array_values($attachments);
}

function journalMergeAttachments(array &$attachments, array $files): void {
    foreach ($files as $file) {
        if (!empty($file['path'])) {
            $attachments[md5($file['path'])] = $file;
        }
    }
}

function journalGetBankTransactionIdsByReference(mysqli $conn, string $transactionNo, string $referenceNo): array {
    $ids = [];
    if (!journalTableExists($conn, 'bank_transactions')) return $ids;

    $values = array_values(array_filter(array_unique([$transactionNo, $referenceNo]), fn($v) => trim((string)$v) !== ''));
    if (empty($values)) return $ids;

    foreach ($values as $value) {
        $stmt = $conn->prepare("SELECT transaction_id FROM bank_transactions WHERE reference_number = ? OR check_number = ? LIMIT 20");
        if (!$stmt) continue;
        $stmt->bind_param('ss', $value, $value);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $id = (int)($row['transaction_id'] ?? 0);
            if ($id > 0) $ids[$id] = $id;
        }
        $stmt->close();
    }
    return array_values($ids);
}

function journalGetTransactionAttachments(mysqli $conn, array $row): array {
    $sourceTable = strtolower(trim((string)($row['source_table'] ?? '')));
    $sourceId = (int)($row['source_id'] ?? 0);
    $transactionNo = trim((string)($row['transaction_no'] ?? ''));
    $referenceNo = trim((string)($row['reference_no'] ?? ''));
    $transactionType = strtolower(trim((string)($row['transaction_type'] ?? '')));
    $memo = trim((string)($row['memo'] ?? ''));
    $catAttachmentPath = trim((string)($row['cat_attachment_path'] ?? ''));
    $attachments = [];

    if ($catAttachmentPath !== '') {
        journalAddAttachment($attachments, $catAttachmentPath, '', $sourceTable);
    }

    $directAttachmentColumns = [
        'attachment_path', 'attachment', 'source_attachment', 'ris_attachment',
        'approval_proof_attachment', 'fuel_attachment', 'return_attachment',
        'release_attachment', 'proof_photo', 'or_attachment', 'vehicle_image',
        'cr_vehicle_images', 'proof_delivery_photo', 'rejection_photo', 'attachment_paths'
    ];
    $directNameColumns = ['attachment_name', 'file_name', 'original_file_name', 'stored_name', 'return_attachment_original'];

    $primaryKeys = [
        'bank_transactions' => 'transaction_id',
        'purchase_orders' => 'po_id',
        'payments' => 'payment_id',
        'collection_records' => 'record_id',
        'expenses' => 'expense_id',
        'repair_payment_history' => 'payment_id',
        'credit_memos' => 'credit_memo_id',
        'journal_entries' => 'journal_id',
        'fund_transfers' => 'transfer_id',
        'motorpool_ris_requests' => 'ris_id',
        'motorpool_ris_workflow_history' => 'history_id',
        'vehicle_repair_history' => 'repair_id',
        'rolling_expenses' => 'expense_id',
        'invoices' => 'invoice_id',
        'sales_orders' => 'so_id',
        'deliveries' => 'delivery_id',
        'delivery_attachments' => 'delivery_id',
        'collection_invoice_returns' => 'return_id',
        'motorpool_branch_parts_purchases' => 'purchase_id',
        'motorpool_fuel_monitoring' => 'fuel_id',
        'motorpool_inventory_transactions' => 'transaction_id',
        'motorpool_repair_backlogs' => 'backlog_id',
        'rolling_expenses' => 'expense_id'
    ];

    // 1) Only read direct attachment columns from the ACTUAL source table.
    // Do not use source_id across every attachment table, because IDs overlap between tables
    // and that causes wrong files to appear on unrelated transactions.
    if ($sourceTable !== '' && $sourceId > 0 && isset($primaryKeys[$sourceTable])) {
        journalMergeAttachments(
            $attachments,
            journalFetchAttachmentsFromTable($conn, $sourceTable, $primaryKeys[$sourceTable], $sourceId, $directAttachmentColumns, $directNameColumns)
        );
    }

    // Purchase Order / Receive Inventory fallback:
    // Some receive transactions are posted to Chart of Accounts with source_id = 0
    // or were saved before chart_account_transactions.attachment_path existed.
    // In those cases, match the PO by PO number / reference number and read purchase_orders.attachment_path.
    if ($sourceTable === 'purchase_orders' && journalTableExists($conn, 'purchase_orders') && journalColumnExists($conn, 'purchase_orders', 'attachment_path')) {
        $poKeys = array_values(array_filter(array_unique([$transactionNo, $referenceNo]), fn($v) => trim((string)$v) !== ''));
        foreach ($poKeys as $poKey) {
            $whereParts = [];
            if (journalColumnExists($conn, 'purchase_orders', 'po_number')) $whereParts[] = 'po_number = ?';
            if (journalColumnExists($conn, 'purchase_orders', 'ref_no')) $whereParts[] = 'ref_no = ?';
            if (empty($whereParts)) continue;

            $sql = "SELECT attachment_path FROM purchase_orders WHERE (" . implode(' OR ', $whereParts) . ") AND COALESCE(attachment_path, '') <> '' ORDER BY po_id DESC LIMIT 10";
            $stmt = $conn->prepare($sql);
            if (!$stmt) continue;
            $types = str_repeat('s', count($whereParts));
            $values = array_fill(0, count($whereParts), $poKey);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($poAttachment = $result ? $result->fetch_assoc() : null) {
                if (!$poAttachment) break;
                journalAddAttachment($attachments, (string)($poAttachment['attachment_path'] ?? ''), '', 'purchase_orders');
            }
            $stmt->close();
        }
    }

    // 2) Manual Create Journal attachments.
    if (($sourceTable === 'journal_entries' || str_contains($transactionType, 'journal')) && $transactionNo !== '') {
        if (journalTableExists($conn, 'journal_entries') && journalColumnExists($conn, 'journal_entries', 'entry_no') && journalColumnExists($conn, 'journal_entries', 'attachment_path')) {
            $stmt = $conn->prepare("SELECT attachment_path FROM journal_entries WHERE entry_no = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $transactionNo);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($je = ($result ? $result->fetch_assoc() : null)) {
                    journalAddAttachment($attachments, (string)($je['attachment_path'] ?? ''), '', 'journal_entries');
                }
                $stmt->close();
            }
        }
    }

    // 3) Bank transactions: Write Checks, Deposits, and Deposit details.
    $bankTransactionIds = [];
    if ($sourceTable === 'bank_transactions' && $sourceId > 0) {
        $bankTransactionIds[$sourceId] = $sourceId;
    }
    foreach (journalGetBankTransactionIdsByReference($conn, $transactionNo, $referenceNo) as $bankId) {
        $bankTransactionIds[$bankId] = $bankId;
    }

    foreach (array_values($bankTransactionIds) as $bankTransactionId) {
        // Write Check / Withdrawal attachments are stored here.
        journalMergeAttachments(
            $attachments,
            journalFetchAttachmentsFromTable($conn, 'withdrawal_attachments', 'transaction_id', $bankTransactionId, ['file_path'], ['file_name', 'stored_name'])
        );

        // Deposit attachments are attached to the collection/payment records linked to bank_transaction_payments.
        if (journalTableExists($conn, 'bank_transaction_payments') && journalColumnExists($conn, 'bank_transaction_payments', 'transaction_id') && journalColumnExists($conn, 'bank_transaction_payments', 'payment_id')) {
            $stmt = $conn->prepare("SELECT payment_id FROM bank_transaction_payments WHERE transaction_id = ? LIMIT 100");
            if ($stmt) {
                $stmt->bind_param('i', $bankTransactionId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($btp = $result ? $result->fetch_assoc() : null) {
                    if (!$btp) break;
                    $paymentId = (int)($btp['payment_id'] ?? 0);
                    if ($paymentId <= 0) continue;
                    journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'collection_records', 'record_id', $paymentId, ['attachment_path'], ['attachment_name']));
                    journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'payment_attachments', 'payment_id', $paymentId, ['file_path'], ['file_name']));
                }
                $stmt->close();
            }
        }

        // Deposited customer credits.
        if (journalTableExists($conn, 'bank_transaction_credit_memos') && journalColumnExists($conn, 'bank_transaction_credit_memos', 'transaction_id') && journalColumnExists($conn, 'bank_transaction_credit_memos', 'credit_memo_id')) {
            $stmt = $conn->prepare("SELECT credit_memo_id FROM bank_transaction_credit_memos WHERE transaction_id = ? LIMIT 100");
            if ($stmt) {
                $stmt->bind_param('i', $bankTransactionId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($btc = $result ? $result->fetch_assoc() : null) {
                    if (!$btc) break;
                    $creditMemoId = (int)($btc['credit_memo_id'] ?? 0);
                    if ($creditMemoId <= 0) continue;
                    journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'credit_memo_attachments', 'credit_memo_id', $creditMemoId, ['file_path'], ['file_name', 'stored_name']));
                }
                $stmt->close();
            }
        }
    }

    // 4) Pay Bills / Supplier Payment. Source row is usually purchase_orders.po_id,
    // while uploaded proof is under withdrawal_attachments.transaction_id via supplier_payments.bank_transaction_id.
    if ($sourceTable === 'purchase_orders' && $sourceId > 0 && journalTableExists($conn, 'supplier_payments')) {
        $wherePairs = [];
        if (journalColumnExists($conn, 'supplier_payments', 'po_id')) {
            $wherePairs[] = ['po_id', $sourceId];
        }
        if (journalColumnExists($conn, 'supplier_payments', 'beginning_balance_id')) {
            $wherePairs[] = ['beginning_balance_id', $sourceId];
        }

        foreach ($wherePairs as $whereInfo) {
            [$whereColumn, $whereValue] = $whereInfo;
            $safeWhere = preg_replace('/[^A-Za-z0-9_]/', '', $whereColumn);
            $selectCols = ['supplier_payment_id'];
            if (journalColumnExists($conn, 'supplier_payments', 'bank_transaction_id')) $selectCols[] = 'bank_transaction_id';
            if (journalColumnExists($conn, 'supplier_payments', 'reference_number')) $selectCols[] = 'reference_number';

            $stmt = $conn->prepare("SELECT " . implode(', ', $selectCols) . " FROM supplier_payments WHERE `{$safeWhere}` = ? LIMIT 100");
            if (!$stmt) continue;
            $stmt->bind_param('i', $whereValue);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($sp = $result ? $result->fetch_assoc() : null) {
                if (!$sp) break;
                $supplierPaymentId = (int)($sp['supplier_payment_id'] ?? 0);
                $spBankTransactionId = (int)($sp['bank_transaction_id'] ?? 0);
                if ($spBankTransactionId > 0) {
                    journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'withdrawal_attachments', 'transaction_id', $spBankTransactionId, ['file_path'], ['file_name', 'stored_name']));
                }
                if ($supplierPaymentId > 0 && journalColumnExists($conn, 'withdrawal_attachments', 'withdrawal_id')) {
                    journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'withdrawal_attachments', 'withdrawal_id', $supplierPaymentId, ['file_path'], ['file_name', 'stored_name']));
                }
            }
            $stmt->close();
        }
    }

    // 5) Receive Payment / Collections / Invoice source.
    if (in_array($sourceTable, ['collection_records', 'payments'], true) && $sourceId > 0) {
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'collection_records', 'record_id', $sourceId, ['attachment_path'], ['attachment_name']));
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'payment_attachments', 'payment_id', $sourceId, ['file_path'], ['file_name']));
    }

    if (in_array($sourceTable, ['invoices', 'sales_orders'], true) && $sourceId > 0 && journalTableExists($conn, 'collection_records')) {
        $collectionKeys = $sourceTable === 'sales_orders' ? ['so_id', 'invoice_id'] : ['invoice_id'];
        foreach ($collectionKeys as $collectionKey) {
            if (!journalColumnExists($conn, 'collection_records', $collectionKey)) continue;
            $safeKey = preg_replace('/[^A-Za-z0-9_]/', '', $collectionKey);
            $stmt = $conn->prepare("SELECT record_id, attachment_path, attachment_name FROM collection_records WHERE `{$safeKey}` = ? LIMIT 100");
            if (!$stmt) continue;
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($cr = $result ? $result->fetch_assoc() : null) {
                if (!$cr) break;
                $recordId = (int)($cr['record_id'] ?? 0);
                journalAddAttachment($attachments, (string)($cr['attachment_path'] ?? ''), (string)($cr['attachment_name'] ?? ''), 'collection_records');
                if ($recordId > 0) {
                    journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'payment_attachments', 'payment_id', $recordId, ['file_path'], ['file_name']));
                }
            }
            $stmt->close();
        }
    }

    // 6) Delivery / Sales Order attachments.
    if ($sourceTable === 'sales_orders' && $sourceId > 0) {
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'delivery_attachments', 'so_id', $sourceId, ['file_path'], ['file_name']));
    }
    if ($sourceTable === 'deliveries' && $sourceId > 0) {
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'delivery_attachments', 'delivery_id', $sourceId, ['file_path'], ['file_name']));
    }

    // 7) Customer Credit / Credit Memo attachments.
    if (in_array($sourceTable, ['credit_memos', 'bank_transaction_credit_memos'], true) && $sourceId > 0) {
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'credit_memo_attachments', 'credit_memo_id', $sourceId, ['file_path'], ['file_name', 'stored_name']));
    }
    if (in_array($sourceTable, ['credit_discounts', 'credit_discount_attachments'], true) && $sourceId > 0) {
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'credit_discount_attachments', 'credit_id', $sourceId, ['file_path'], ['original_file_name', 'file_name']));
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'credit_discount_attachments', 'credit_discount_id', $sourceId, ['file_path'], ['original_file_name', 'file_name']));
    }

    // 8) Beginning balance attachments.
    if (in_array($sourceTable, ['beginning_balances', 'beginning_balance_attachments'], true) && $sourceId > 0) {
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'beginning_balance_attachments', 'invoice_id', $sourceId, ['file_path'], ['file_name', 'stored_name']));
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'beginning_balance_attachments', 'so_id', $sourceId, ['file_path'], ['file_name', 'stored_name']));
    }
    if (in_array($sourceTable, ['supplier_beginning_balances', 'supplier_beginning_balance_attachments'], true) && $sourceId > 0) {
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'supplier_beginning_balance_attachments', 'supplier_balance_id', $sourceId, ['file_path'], ['file_name', 'stored_name']));
        journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'supplier_beginning_balance_attachments', 'bill_id', $sourceId, ['file_path'], ['file_name', 'stored_name']));
    }

    // 9) Motorpool extra attachment tables.
    if (str_contains($sourceTable, 'motorpool') || str_contains($transactionType, 'repair') || str_contains(strtolower($memo), 'repair')) {
        $risId = 0;
        if ($sourceTable === 'repair_payment_history' && $sourceId > 0 && journalTableExists($conn, 'repair_payment_history') && journalColumnExists($conn, 'repair_payment_history', 'ris_id')) {
            $stmt = $conn->prepare("SELECT ris_id FROM repair_payment_history WHERE payment_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $sourceId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($r = ($result ? $result->fetch_assoc() : null)) {
                    $risId = (int)($r['ris_id'] ?? 0);
                }
                $stmt->close();
            }
        }
        if ($risId <= 0 && in_array($sourceTable, ['motorpool_ris_requests', 'motorpool_repair_release_proofs', 'motorpool_start_repair_proofs'], true)) {
            $risId = $sourceId;
        }
        if ($risId > 0) {
            journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'motorpool_repair_release_proofs', 'ris_id', $risId, ['release_attachment'], ['release_attachment']));
            journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'motorpool_start_repair_proofs', 'ris_id', $risId, ['proof_photo'], ['proof_photo']));
            journalMergeAttachments($attachments, journalFetchAttachmentsFromTable($conn, 'motorpool_branch_parts_purchases', 'ris_id', $risId, ['source_attachment'], ['source_attachment']));
        }
    }

    return array_values($attachments);
}

$journal_entries_list = [];
if (journalTableExists($conn, 'chart_account_transactions')) {
    if (!journalColumnExists($conn, 'chart_account_transactions', 'attachment_path')) {
        @($conn->query("ALTER TABLE `chart_account_transactions` ADD COLUMN `attachment_path` text DEFAULT NULL"));
    }
    if (!journalColumnExists($conn, 'chart_account_transactions', 'counterparty')) {
        @($conn->query("ALTER TABLE `chart_account_transactions` ADD COLUMN `counterparty` varchar(255) DEFAULT NULL"));
    }
    $hasAccountName = journalColumnExists($conn, 'chart_account_transactions', 'account_name');
    $hasSourceTable = journalColumnExists($conn, 'chart_account_transactions', 'source_table');
    $hasSourceId = journalColumnExists($conn, 'chart_account_transactions', 'source_id');
    $hasCreatedAt = journalColumnExists($conn, 'chart_account_transactions', 'created_at');
    $hasBranchId = journalColumnExists($conn, 'chart_account_transactions', 'branch_id');
    $hasChartAccounts = journalTableExists($conn, 'chart_of_accounts');
    $hasCatCounterparty = journalColumnExists($conn, 'chart_account_transactions', 'counterparty');
    $hasCatAttachmentPath = journalColumnExists($conn, 'chart_account_transactions', 'attachment_path');
    $hasJournalDetails = journalTableExists($conn, 'journal_entry_details')
        && journalColumnExists($conn, 'journal_entry_details', 'journal_id')
        && journalColumnExists($conn, 'journal_entry_details', 'account_id')
        && journalColumnExists($conn, 'journal_entry_details', 'counterparty');

    $accountTitleExpr = $hasAccountName
        ? "COALESCE(NULLIF(cat.account_name, ''), " . ($hasChartAccounts ? "coa.account_title" : "NULL") . ", CONCAT('Account #', cat.account_id))"
        : ($hasChartAccounts ? "COALESCE(coa.account_title, CONCAT('Account #', cat.account_id))" : "CONCAT('Account #', cat.account_id)");

    $sourceTableExpr = $hasSourceTable ? "cat.source_table" : "''";
    $sourceIdExpr = $hasSourceId ? "cat.source_id" : "0";
    $createdAtExpr = $hasCreatedAt ? "cat.created_at" : "cat.transaction_date";
    $catAttachmentExpr = $hasCatAttachmentPath ? "cat.attachment_path" : "''";

    $journalDetailCounterpartyExpr = $hasJournalDetails && $hasSourceTable && $hasSourceId
        ? "(SELECT jed.counterparty
             FROM journal_entry_details jed
             WHERE jed.journal_id = cat.source_id
               AND cat.source_table = 'journal_entries'
               AND jed.account_id = cat.account_id
               AND COALESCE(jed.debit, 0) = COALESCE(cat.debit, 0)
               AND COALESCE(jed.credit, 0) = COALESCE(cat.credit, 0)
             ORDER BY jed.detail_id ASC
             LIMIT 1)"
        : "NULL";

    $counterpartyExpr = $hasCatCounterparty
        ? "COALESCE(NULLIF(cat.counterparty, ''), {$journalDetailCounterpartyExpr}, '')"
        : "COALESCE({$journalDetailCounterpartyExpr}, '')";

    $journal_sql = "SELECT
            cat.transaction_id,
            cat.account_id,
            cat.transaction_date,
            cat.transaction_type,
            cat.transaction_no,
            cat.reference_no,
            cat.memo,
            {$counterpartyExpr} AS counterparty,
            {$accountTitleExpr} AS account_title,
            COALESCE(cat.debit, 0) AS debit,
            COALESCE(cat.credit, 0) AS credit,
            {$sourceTableExpr} AS source_table,
            {$sourceIdExpr} AS source_id,
            {$catAttachmentExpr} AS cat_attachment_path,
            {$createdAtExpr} AS created_at
        FROM chart_account_transactions cat";

    if ($hasChartAccounts) {
        $journal_sql .= " LEFT JOIN chart_of_accounts coa ON coa.account_id = cat.account_id";
    }

    $journal_sql .= " WHERE 1=1";
    if (!$view_all_branches && $branch_id > 0 && $hasBranchId) {
        $journal_sql .= " AND cat.branch_id = " . (int)$branch_id;
    }

    // Latest transactions first. transaction_id DESC is important for entries created on the same date/time.
    $journal_sql .= " ORDER BY {$createdAtExpr} DESC, cat.transaction_date DESC, cat.transaction_id DESC LIMIT 500";

    $journal_result = $conn->query($journal_sql);
    if ($journal_result) {
        while ($row = $journal_result->fetch_assoc()) {
            $row['section_label'] = journalEntrySectionLabel($row);
            $row['attachments'] = journalGetTransactionAttachments($conn, $row);
            $journal_entries_list[] = $row;
        }
    }
}

// Sort journal list by latest transaction first globally, not by transaction section/type.
// Rows from the same transaction stay beside each other, then debit lines appear before credit lines.
usort($journal_entries_list, function($a, $b) {
    $groupA = implode('|', [
        (string)($a['transaction_no'] ?? ''),
        (string)($a['reference_no'] ?? ''),
        (string)($a['source_table'] ?? ''),
        (string)($a['source_id'] ?? '')
    ]);
    $groupB = implode('|', [
        (string)($b['transaction_no'] ?? ''),
        (string)($b['reference_no'] ?? ''),
        (string)($b['source_table'] ?? ''),
        (string)($b['source_id'] ?? '')
    ]);

    if ($groupA === $groupB) {
        $aIsDebit = (float)($a['debit'] ?? 0) > 0 ? 0 : 1;
        $bIsDebit = (float)($b['debit'] ?? 0) > 0 ? 0 : 1;
        if ($aIsDebit !== $bIsDebit) return $aIsDebit <=> $bIsDebit;

        return ((int)($a['transaction_id'] ?? 0)) <=> ((int)($b['transaction_id'] ?? 0));
    }

    $ca = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $cb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    if ($ca !== $cb) return $cb <=> $ca;

    $da = strtotime((string)($a['transaction_date'] ?? '')) ?: 0;
    $db = strtotime((string)($b['transaction_date'] ?? '')) ?: 0;
    if ($da !== $db) return $db <=> $da;

    $ta = trim((string)($a['transaction_no'] ?? ''));
    $tb = trim((string)($b['transaction_no'] ?? ''));
    if ($ta !== $tb) return strnatcasecmp($tb, $ta);

    return ((int)($b['transaction_id'] ?? 0)) <=> ((int)($a['transaction_id'] ?? 0));
});


$journal_transaction_groups = [];
foreach ($journal_entries_list as $entry) {
    $transactionKey = trim((string)($entry['transaction_no'] ?? ''));
    if ($transactionKey === '') $transactionKey = trim((string)($entry['reference_no'] ?? ''));
    if ($transactionKey === '') $transactionKey = 'Transaction #' . (int)($entry['source_id'] ?? $entry['transaction_id'] ?? 0);

    $groupKey = md5(implode('|', [
        $transactionKey,
        (string)($entry['reference_no'] ?? ''),
        (string)($entry['source_table'] ?? ''),
        (string)($entry['source_id'] ?? '')
    ]));

    if (!isset($journal_transaction_groups[$groupKey])) {
        $journal_transaction_groups[$groupKey] = [
            'key' => $groupKey,
            'transaction_no' => $transactionKey,
            'date' => (string)($entry['transaction_date'] ?? ''),
            'section_label' => (string)($entry['section_label'] ?? 'Journal Entry'),
            'transaction_type' => (string)($entry['transaction_type'] ?? ''),
            'reference_no' => (string)($entry['reference_no'] ?? ''),
            'source_table' => (string)($entry['source_table'] ?? ''),
            'source_id' => (int)($entry['source_id'] ?? 0),
            'edit_url' => journalGetTransactionOpenUrl($conn, $entry),
            'attachments' => $entry['attachments'] ?? [],
            'rows' => []
        ];
    }

    $journal_transaction_groups[$groupKey]['rows'][] = [
        'transaction_id' => (int)($entry['transaction_id'] ?? 0),
        'account_id' => (int)($entry['account_id'] ?? 0),
        'account_title' => (string)($entry['account_title'] ?? ''),
        'debit' => (float)($entry['debit'] ?? 0),
        'credit' => (float)($entry['credit'] ?? 0),
        'memo' => (string)($entry['memo'] ?? ''),
        'counterparty' => (string)($entry['counterparty'] ?? '')
    ];
}

// Attach audit history to every transaction group so row click can show old/new values.
foreach ($journal_transaction_groups as $historyGroupKey => $historyGroup) {
    $journal_transaction_groups[$historyGroupKey]['history'] = journalFetchTransactionHistoryForGroup($conn, $historyGroup, (int)$branch_id, (bool)$view_all_branches);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entries - Motorpool</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .journal-page-wrap {
            padding: 0 12px 24px;
        }

        .custom-tabs {
            border-bottom: 2px solid #e5e7eb;
            margin-top: 10px;
        }

        .custom-tabs .nav-link {
            color: #5f6b80;
            font-weight: 700;
            border: none;
            padding: 12px 24px;
            background: transparent;
            font-size: 16px;
        }

        .custom-tabs .nav-link:hover {
            color: #047857;
        }

        .custom-tabs .nav-link.active {
            color: #047857;
            border: none;
            border-bottom: 3px solid #44D34E;
            background: transparent;
        }

        .qb-clean-panel {
            margin-top: 12px;
            background: #fff;
        }

        #create-journal {
            overflow: hidden;
        }

        .journal-topbar {
            display: grid;
            grid-template-columns: 1fr 250px;
            gap: 40px;
            align-items: end;
            margin-bottom: 14px;
        }

        .journal-fields-clean {
            display: grid;
            grid-template-columns: 120px 170px 90px 170px;
            gap: 10px 12px;
            align-items: center;
            max-width: 590px;
        }

        .journal-fields-clean label {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #052A47;
            text-transform: uppercase;
        }

        .journal-fields-clean input {
            width: 100%;
            height: 36px;
            border: 1px solid #d7e2d5;
            border-radius: 6px;
            background: #fff;
            padding: 6px 10px;
            color: #052A47;
            font-size: 14px;
            outline: none;
        }

        .journal-fields-clean input:focus {
            border-color: #44D34E;
            box-shadow: 0 0 0 2px rgba(68, 211, 78, 0.12);
        }

        .attachment-compact {
            position: relative;
            height: 30px;
            border: 1px solid #cbd5e1;
            border-radius: 2px;
            background: linear-gradient(#f8f8f8, #e9e9e9);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #052A47;
            cursor: pointer;
            overflow: hidden;
        }

        .attachment-compact:hover {
            border-color: #44D34E;
        }

        .attachment-compact i {
            margin-right: 8px;
            color: #047857;
        }

        .attachment-compact input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .selected-file-name {
            display: block;
            margin-top: 4px;
            color: #64748b;
            font-size: 12px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .qb-journal-table-wrap {
            width: 100%;
            overflow: hidden;
            background: #fff;
            border: 1px solid #d7e2d5;
            border-radius: 8px 8px 0 0;
        }

        .qb-journal-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 14px;
            background: #fff;
        }

        .qb-journal-table thead {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .qb-journal-table tbody {
            display: block;
            width: 100%;
            max-height: 435px;
            overflow-y: hidden;
            overflow-x: hidden;
        }

        .qb-journal-table-wrap.is-scrollable .qb-journal-table tbody {
            overflow-y: auto;
        }

        .qb-journal-table tbody::-webkit-scrollbar {
            width: 8px;
        }

        .qb-journal-table tbody::-webkit-scrollbar-thumb {
            background: #44D34E;
            border-radius: 999px;
        }

        .qb-journal-table tbody::-webkit-scrollbar-track {
            background: #eef9ef;
        }

        .qb-journal-table tbody tr {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .qb-journal-table th {
            position: relative;
            top: auto;
            z-index: 1;
            height: 30px;
            border-right: 1px solid #d5dde7;
            border-bottom: 1px solid #c6ccd4;
            color: #6b7280;
            background: #fff;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
            padding: 6px 8px;
            vertical-align: middle;
        }

        .qb-journal-table td {
            height: 29px;
            border-right: 1px solid #d5dde7;
            padding: 0;
        }

        .qb-journal-table th:last-child,
        .qb-journal-table td:last-child {
            border-right: none;
        }

        .qb-journal-table tbody tr:nth-child(odd) {
            background: #fff;
        }

        .qb-journal-table tbody tr:nth-child(even) {
            background: #eaf8ec;
        }

        .qb-journal-table input,
        .qb-journal-table select {
            width: 100%;
            height: 29px;
            border: none;
            background: transparent;
            padding: 4px 8px;
            outline: none;
            font-size: 14px;
            color: #052A47;
        }

        .qb-journal-table input[type="number"] {
            text-align: right;
        }

        .qb-journal-table .account-cell,
        .qb-journal-table .counterparty-cell {
            position: relative;
        }

        .qb-journal-table .account-cell::after,
        .qb-journal-table .counterparty-cell::after {
            content: "⌄";
            position: absolute;
            right: 10px;
            top: 4px;
            color: #047857;
            pointer-events: none;
            font-size: 16px;
        }

        .journal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 0 0;
        }

        .journal-btn {
            border: none;
            border-radius: 6px;
            padding: 9px 18px;
            font-weight: 700;
            font-size: 14px;
        }

        .journal-btn.primary {
            background: #44D34E;
            color: #052A47;
            border: 1px solid #44D34E;
        }

        .journal-btn.primary:hover {
            background: #2fc53a;
            border-color: #2fc53a;
        }

        @media (max-width: 992px) {
            .journal-topbar {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .attachment-area-clean {
                max-width: 260px;
            }
        }

        .journal-edit-readonly{background:#f1f5f9!important;color:#475569!important;}

        @media (max-width: 768px) {
            .journal-fields-clean {
                grid-template-columns: 1fr;
                max-width: 100%;
            }
        }


        .qb-journal-table td.account-cell {
            position: relative;
            overflow: visible;
        }

        .qb-journal-table .account-cell::after {
            content: none !important;
        }

        .qb-journal-table .qb-account-picker {
            position: relative;
            width: 100%;
            height: 29px;
        }

        .qb-journal-table .expense-account-input {
            width: 100%;
            height: 29px;
            border: none;
            background: transparent;
            font-size: 14px;
            color: #052A47;
            outline: none;
            padding: 4px 34px 4px 8px;
        }

        .qb-journal-table .expense-account-input:focus {
            background-color: #fff;
            box-shadow: inset 0 0 0 1px #58e84b, 0 0 0 1px rgba(47, 128, 237, .15);
        }

        .qb-account-toggle {
            position: absolute;
            right: 1px;
            top: 1px;
            width: 26px;
            height: 27px;
            border: 0;
            background: transparent;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 2px;
            z-index: 2;
            transition: background .15s ease, color .15s ease;
        }

        .qb-account-toggle:hover,
        .qb-account-toggle.active {
            background: #eeffee;
            color: #047857;
        }

        .qb-account-toggle i {
            font-size: 12px;
            line-height: 1;
            pointer-events: none;
        }

        .qb-account-dropdown {
            position: fixed;
            z-index: 99999;
            display: none;
            min-width: 260px;
            max-height: 245px;
            overflow-y: auto;
            background: #ffffff;
            border: 1px solid #a3df9d;
            border-radius: 4px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .18);
            padding: 0;
            font-size: 13px;
            color: #1f2937;
        }

        .qb-account-dropdown.show {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        .qb-account-option {
            width: 100%;
            min-height: 34px;
            padding: 8px 14px;
            margin: 0;
            border: 0;
            border-bottom: 1px solid #eef2f7;
            border-radius: 0;
            cursor: pointer;
            line-height: 1.35;
            background: #fff;
            color: #111827;
            font-family: inherit;
            font-size: 13px;
            font-weight: 400;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .qb-account-option:hover {
            background: #eaffea;
            color: #047857;
        }

        .qb-account-option-disabled,
        .qb-account-option-disabled:hover {
            background: #f8fafc !important;
            color: #334155 !important;
            cursor: not-allowed !important;
            font-weight: 400 !important;
            opacity: 1 !important;
        }

        .qb-account-option-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .qb-account-option small {
            color: #64748b;
            font-size: 11px;
            white-space: nowrap;
        }

        .qb-account-empty {
            padding: 10px 14px;
            color: #64748b;
            font-size: 13px;
        }

        .qb-journal-table td.journal-counterparty-cell {
            position: relative;
            overflow: visible;
        }

        .qb-journal-table .counterparty-cell::after {
            content: none !important;
        }

        .qb-counterparty-picker {
            position: relative;
            width: 100%;
            height: 29px;
        }

        .counterparty-input {
            width: 100%;
            height: 29px;
            border: none;
            background: transparent;
            font-size: 14px;
            color: #052A47;
            outline: none;
            padding: 4px 34px 4px 8px !important;
        }

        .counterparty-input:focus {
            background-color: #fff;
            box-shadow: inset 0 0 0 1px #58e84b, 0 0 0 1px rgba(47, 128, 237, .15);
        }

        .qb-counterparty-toggle {
            position: absolute;
            right: 1px;
            top: 1px;
            width: 26px;
            height: 27px;
            border: 0;
            background: transparent;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 2px;
            z-index: 2;
            transition: background .15s ease, color .15s ease;
        }

        .qb-counterparty-toggle:hover,
        .qb-counterparty-toggle.active {
            background: #eeffee;
            color: #047857;
        }

        .qb-counterparty-toggle i {
            font-size: 12px;
            line-height: 1;
            pointer-events: none;
        }

        .qb-counterparty-dropdown {
            position: fixed;
            z-index: 99999;
            display: none;
            width: auto;
            min-width: unset;
            max-width: unset;
            height: 220px;
            max-height: 220px;
            overflow-y: auto;
            overflow-x: hidden;
            background: #ffffff;
            border: 1px solid #a3df9d;
            border-radius: 4px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .18);
            padding: 0;
            font-size: 13px;
            color: #1f2937;
        }

        .qb-counterparty-dropdown.show {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        .qb-counterparty-option {
            width: 100%;
            min-height: 34px;
            padding: 8px 14px;
            margin: 0;
            border: 0;
            border-bottom: 1px solid #eef2f7;
            border-radius: 0;
            cursor: pointer;
            line-height: 1.35;
            background: #fff;
            color: #111827;
            font-family: inherit;
            font-size: 13px;
            font-weight: 400;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .qb-counterparty-option:hover {
            background: #eaffea;
            color: #047857;
        }

        .qb-counterparty-option-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .qb-counterparty-option small {
            display: none !important;
        }

        .qb-counterparty-option {
            justify-content: flex-start !important;
        }

        .qb-counterparty-group {
            background: #e8f5e9;
            color: #047857;
            font-weight: 700;
            padding: 8px 12px;
            border-top: 1px solid #44D34E;
            border-bottom: 1px solid rgba(4, 120, 87, 0.12);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        .qb-counterparty-empty {
            padding: 10px 14px;
            color: #64748b;
            font-size: 13px;
        }


        /* ========== JOURNAL ENTRIES TAB DATABASE TABLE ========== */
        .journal-entries-db-wrap {
            width: 100%;
            overflow-x: hidden;
            background: #fff;
            border: none;
            border-radius: 0;
        }

        .journal-entries-db-table {
            width: 100%;
            min-width: 0 !important;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 12px;
        }

        .journal-entries-db-table th,
        .journal-entries-db-table td {
            box-sizing: border-box;
        }

        .journal-entries-db-table th {
            height: 22px;
            border: none;
            border-bottom: 1px solid #000;
            color: #000;
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            text-transform: none;
            padding: 2px 5px;
            vertical-align: top;
            text-align: left;
            line-height: 1.15;
        }

        .journal-entries-db-table td {
            height: 22px;
            border: none;
            padding: 2px 5px;
            vertical-align: top;
            color: #000;
            line-height: 1.2;
        }

        .journal-entries-db-table tbody tr:nth-child(odd):not(.journal-section-row) {
            background: #fff;
        }

        .journal-entries-db-table tbody tr:nth-child(even):not(.journal-section-row) {
            background: #eaf8ec;
        }

        .journal-entries-db-table .journal-section-row td {
            background: #fff;
            color: #000;
            font-weight: 700;
            text-transform: none;
            letter-spacing: 0;
            border-top: 1px solid #000;
            border-bottom: none;
            height: 22px;
            padding-top: 4px;
        }

        .journal-entries-db-table .amount-cell {
            text-align: right !important;
            white-space: nowrap !important;
            padding-right: 12px !important;
            font-variant-numeric: tabular-nums;
        }

        .journal-entries-db-table th.amount-header {
            text-align: right !important;
            padding-right: 12px !important;
            font-variant-numeric: tabular-nums;
        }

        .journal-entries-db-table th.debit-col,
        .journal-entries-db-table td.debit-col,
        .journal-entries-db-table th.credit-col,
        .journal-entries-db-table td.credit-col {
            width: 9% !important;
            min-width: 0 !important;
            max-width: 9% !important;
        }

        .journal-entries-db-table th.memo-col,
        .journal-entries-db-table td.memo-cell {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .journal-entries-db-table td.memo-cell {
            white-space: normal;
            word-break: break-word;
        }

        .journal-entries-db-table .memo-cell {
            font-style: italic;
        }

        .journal-entries-db-table .empty-row td {
            text-align: center;
            color: #64748b;
            padding: 18px 8px;
        }


        .journal-open-transaction-btn {
            border: 0;
            background: transparent;
            color: #047857;
            font-weight: 700;
            padding: 0;
            cursor: pointer;
            text-align: left;
            text-decoration: underline;
            text-underline-offset: 2px;
            font-size: 12px;
        }

        .journal-open-transaction-btn:hover {
            color: #052A47;
        }
        

        .journal-inline-modal-table-wrap {
            max-height: 420px;
            overflow: auto;
            border: 1px solid #d7e2d5;
            border-radius: 8px;
            background: #fff;
        }

        .journal-inline-modal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .journal-inline-modal-table th {
            background: #eaf8ec;
            color: #052A47;
            padding: 7px 8px;
            border-bottom: 1px solid #d7e2d5;
            font-weight: 800;
            text-align: left;
        }

        .journal-inline-modal-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }

        .journal-inline-input {
            width: 100%;
            height: 30px;
            border: 1px solid #d7e2d5;
            border-radius: 5px;
            padding: 4px 7px;
            font-size: 12px;
            color: #052A47;
            background: #fff;
        }

        .journal-inline-input:disabled {
            border-color: transparent;
            background: transparent;
            padding-left: 0;
            color: #000;
            opacity: 1;
        }

        .journal-inline-input.amount {
            text-align: right;
        }

        .journal-inline-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 14px;
            margin-bottom: 12px;
            color: #052A47;
            font-size: 13px;
            text-align: left;
        }

        .journal-inline-meta strong {
            color: #047857;
        }


        .journal-history-wrap {
            text-align: left;
            color: #052A47;
            max-height: 72vh;
            overflow-y: auto;
            padding-right: 4px;
        }
        .journal-history-current-table,
        .journal-history-compare {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 8px;
            border: 1px solid #cfe8d4;
            border-radius: 10px;
            overflow: hidden;
        }
        .journal-history-current-table th,
        .journal-history-current-table td,
        .journal-history-compare th,
        .journal-history-compare td {
            padding: 8px 10px;
            border-bottom: 1px solid #e5efe7;
            vertical-align: top;
        }
        .journal-history-current-table th,
        .journal-history-compare th {
            background: #e9f8ec;
            color: #052A47;
            font-weight: 700;
        }
        .journal-history-card {
            border: 1px solid #d9eadc;
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 12px;
            background: #fff;
        }
        .journal-history-card h5 {
            margin: 0 0 8px;
            font-size: 15px;
            color: #047857;
            font-weight: 800;
        }
        .journal-history-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px 14px;
            font-size: 12.5px;
            margin-bottom: 8px;
            color: #475569;
        }
        .journal-history-meta strong { color: #047857; }
        .journal-history-empty {
            background: #f8fafc;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
            color: #64748b;
            font-size: 14px;
        }
        .journal-entry-clickable-row { cursor: pointer; }
        .journal-entry-clickable-row:hover td { background: #f4fff6; }



        /* ========== CREATE JOURNAL FIXED TAB HEIGHT: TABLE BODY ONLY SCROLLS ========== */
        .create-journal-form {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #create-journal.tab-pane.active,
        #create-journal.show.active {
            height: calc(100vh - 218px);
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column;
            min-height: 0;
        }

        #create-journal .qb-clean-panel {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin-top: 8px;
        }

        #create-journal .journal-topbar {
            flex: 0 0 auto;
            margin-bottom: 10px;
        }

        #create-journal .qb-journal-table-wrap {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden !important;
        }

        #create-journal .qb-journal-table {
            height: 100%;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        #create-journal .qb-journal-table thead {
            flex: 0 0 auto;
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        #create-journal .qb-journal-table tbody {
            flex: 1 1 auto;
            display: block;
            width: 100%;
            min-height: 0;
            max-height: none !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }

        #create-journal .qb-journal-table tbody tr {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        #create-journal .journal-actions {
            flex: 0 0 auto;
            padding: 10px 0 0;
        }

        @media (max-height: 780px) {
            #create-journal.tab-pane.active,
            #create-journal.show.active {
                height: calc(100vh - 200px);
            }

            #create-journal .journal-topbar {
                margin-bottom: 8px;
            }

            #create-journal .journal-actions {
                padding-top: 8px;
            }
        }
    

        /* ========== FIX CREATE JOURNAL TABLE ALIGNMENT ========== */
        /* Scroll is now handled by the table wrapper, not by tbody.
           This keeps the header and body columns perfectly aligned. */
        #create-journal .qb-journal-table-wrap {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }

        #create-journal .qb-journal-table {
            display: table !important;
            width: 100% !important;
            height: auto !important;
            min-height: 0 !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
        }

        #create-journal .qb-journal-table thead {
            display: table-header-group !important;
            width: auto !important;
            table-layout: auto !important;
            position: sticky;
            top: 0;
            z-index: 10;
            background: #fff;
        }

        #create-journal .qb-journal-table tbody {
            display: table-row-group !important;
            width: auto !important;
            height: auto !important;
            min-height: 0 !important;
            max-height: none !important;
            overflow: visible !important;
        }

        #create-journal .qb-journal-table tbody tr {
            display: table-row !important;
            width: auto !important;
            table-layout: auto !important;
        }

        #create-journal .qb-journal-table th,
        #create-journal .qb-journal-table td {
            box-sizing: border-box;
        }

        #create-journal .qb-journal-table th {
            background: #fff !important;
        }

        #create-journal .qb-journal-table-wrap::-webkit-scrollbar {
            width: 8px;
        }

        #create-journal .qb-journal-table-wrap::-webkit-scrollbar-thumb {
            background: #44D34E;
            border-radius: 999px;
        }

        #create-journal .qb-journal-table-wrap::-webkit-scrollbar-track {
            background: #eef9ef;
        }


        /* ========== SIDEBAR TOGGLE LAYOUT FIX ========== */
        .main-content {
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        .main-content.sidebar-expanded {
            margin-left: 292px !important;
            width: calc(100% - 292px) !important;
        }

        .main-content.sidebar-collapsed {
            margin-left: 85px !important;
            width: calc(100% - 85px) !important;
        }

        @media (max-width: 992px) {
            .main-content,
            .main-content.sidebar-expanded,
            .main-content.sidebar-collapsed {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }


        /* ========== COLLAPSED SIDEBAR ACTIVE STYLE FIX ========== */
        /* When desktop sidebar is collapsed, active item should be icon-highlight only.
           Remove the left green border/stripe so it matches purchase_order.php. */
        .sidebar.collapsed .nav-link.active,
        .sidebar.collapsed .nav-item.active > .nav-link,
        .sidebar.collapsed .dropdown-nav > .nav-link.active {
            border-left: none !important;
            border-inline-start: none !important;
            box-shadow: none !important;
        }

        .sidebar.collapsed .nav-link.active::before,
        .sidebar.collapsed .nav-link.active::after,
        .sidebar.collapsed .nav-item.active > .nav-link::before,
        .sidebar.collapsed .nav-item.active > .nav-link::after,
        .sidebar.collapsed .dropdown-nav > .nav-link.active::before,
        .sidebar.collapsed .dropdown-nav > .nav-link.active::after {
            display: none !important;
            content: none !important;
            border: 0 !important;
            box-shadow: none !important;
        }

        .sidebar.collapsed .nav-link.active {
            margin-left: 0 !important;
        }


        /* ========== JOURNAL ENTRIES ATTACHMENTS ========== */
        .journal-attachment-btn {
            border: 1px solid #44D34E;
            background: #eaffea;
            color: #047857;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.1;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .journal-attachment-btn:hover {
            background: #44D34E;
            color: #052A47;
        }

        .journal-no-attachment {
            color: #94a3b8;
            font-size: 12px;
        }

        .attachment-modal-viewer {
            display: none;
            margin-bottom: 14px;
            border: 1px solid #d7e2d5;
            border-radius: 10px;
            background: #f8fafc;
            overflow: hidden;
        }

        .attachment-modal-viewer.show {
            display: block;
        }

        .attachment-modal-viewer-header {
            padding: 8px 12px;
            background: #eaffea;
            color: #052A47;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .attachment-modal-viewer-body {
            min-height: 320px;
            max-height: 68vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }

        .attachment-modal-viewer-body img {
            width: 100%;
            max-height: 68vh;
            object-fit: contain;
        }

        .attachment-modal-viewer-body iframe {
            width: 100%;
            height: 68vh;
            border: 0;
            background: #fff;
        }

        .attachment-modal-viewer-body .unsupported-file {
            padding: 28px;
            text-align: center;
            color: #64748b;
        }

        .attachment-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 12px;
        }

        .attachment-preview-card {
            border: 1px solid #d7e2d5;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .attachment-preview-thumb {
            height: 130px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .attachment-preview-thumb img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .attachment-preview-thumb i {
            font-size: 42px;
            color: #047857;
        }

        .attachment-preview-body {
            padding: 9px;
        }

        .attachment-preview-name {
            color: #052A47;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-bottom: 8px;
        }

        .attachment-preview-open {
            width: 100%;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            border-radius: 6px;
            padding: 6px 8px;
            background: #44D34E;
            color: #052A47;
            font-size: 12px;
            font-weight: 700;
        }

        .attachment-preview-open:hover {
            background: #2fc53a;
            color: #052A47;
        }

        .journal-attachment-select-modal {
            border: none;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
        }

        .journal-attachment-select-modal .modal-header {
            background: linear-gradient(135deg, #047857 0%, #44D34E 100%) !important;
            color: #fff !important;
            border-bottom: none !important;
            padding: 0.35rem 1rem !important;
            min-height: 42px !important;
            flex-shrink: 0 !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 10 !important;
        }

        .journal-attachment-select-modal .modal-title {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .journal-attachment-select-modal .modal-header small {
            color: rgba(255,255,255,.78);
            font-size: 12px;
        }

        .journal-attachment-select-modal .btn-close {
            filter: invert(1);
            opacity: .9;
        }

        .journal-attachment-select-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 420px;
            overflow-y: auto;
        }

        .journal-attachment-select-item {
            width: 100%;
            border: 1px solid #d7e2d5;
            background: #fff;
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
            transition: .15s ease;
        }

        .journal-attachment-select-item:hover {
            border-color: #44D34E;
            background: #eaffea;
        }

        .journal-attachment-select-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #eaffea;
            color: #047857;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            font-size: 17px;
        }

        .journal-attachment-select-text {
            min-width: 0;
            flex: 1;
        }

        .journal-attachment-select-name {
            display: block;
            color: #052A47;
            font-size: 13px;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .journal-attachment-select-text small {
            color: #64748b;
            font-size: 11px;
        }

        .journal-attachment-select-open {
            color: #047857;
            flex: 0 0 auto;
        }

        /* Gallery-style attachment chooser */
        .journal-attachment-select-modal .modal-body {
            padding: 16px;
            background: #f8fafc;
        }

        .journal-attachment-select-list {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)) !important;
            gap: 12px !important;
            max-height: 68vh !important;
            overflow-y: auto !important;
            padding-right: 2px;
        }

        .journal-attachment-select-item {
            width: 100% !important;
            border: 1px solid #d7e2d5 !important;
            background: #fff !important;
            border-radius: 12px !important;
            padding: 0 !important;
            display: block !important;
            text-align: left !important;
            transition: .15s ease !important;
            overflow: hidden !important;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .04);
        }

        .journal-attachment-select-item:hover {
            transform: translateY(-1px);
            border-color: #44D34E !important;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .10);
            background: #fff !important;
        }

        .journal-attachment-gallery-thumb {
            width: 100%;
            height: 130px;
            background: #eaffea;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            border-bottom: 1px solid #e5e7eb;
        }

        .journal-attachment-gallery-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .journal-attachment-gallery-thumb i {
            font-size: 42px;
            color: #047857;
        }

        .journal-attachment-gallery-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            border-radius: 999px;
            padding: 3px 8px;
            background: rgba(15, 164, 2, 0.88);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .journal-attachment-gallery-name {
            display: block;
            padding: 9px 10px 10px;
            color: #171718;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.25;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 576px) {
            .journal-attachment-select-list {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .journal-attachment-gallery-thumb {
                height: 110px;
            }
        }

        #journalFilePreviewModal {
            z-index: 1085 !important;
        }

        #journalFilePreviewModal .modal-dialog {
            max-width: 96vw !important;
            width: auto !important;
            margin: 0 auto !important;
        }

        #journalFilePreviewModal .modal-content {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }

        #journalFilePreviewModal .modal-body {
            min-height: 100dvh !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            padding: 20px !important;
            overflow: hidden !important;
        }

        #journalFilePreviewModal .journal-attachment-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }

        #journalFilePreviewModal .journal-attachment-wrapper {
            position: relative;
            display: inline-block;
            line-height: 0;
        }

        #journalFilePreviewModal .journal-attachment-content img {
            display: block;
            max-width: 92vw;
            max-height: 92vh;
            width: auto;
            height: auto;
            object-fit: contain;
            border-radius: 10px;
            box-shadow: 0 4px 30px rgba(0,0,0,0.3);
        }

        #journalFilePreviewModal .journal-attachment-content embed {
            display: block;
            width: 92vw;
            height: 92vh;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 4px 30px rgba(0,0,0,0.3);
        }

        #journalFilePreviewModal .journal-attachment-content .alert {
            max-width: 520px;
            margin: 20px;
            display: block;
            line-height: 1.4;
        }

        #journalFilePreviewModal .btn-close-journal-attachment,
        #journalFilePreviewModal .btn-download-journal-attachment {
            position: absolute;
            right: 10px;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 50%;
            background: rgba(0,0,0,.7);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: .2s ease;
            z-index: 10;
            text-decoration: none;
        }

        #journalFilePreviewModal .btn-close-journal-attachment {
            top: 10px;
        }

        #journalFilePreviewModal .btn-download-journal-attachment {
            bottom: 10px;
        }

        #journalFilePreviewModal .btn-close-journal-attachment:hover,
        #journalFilePreviewModal .btn-download-journal-attachment:hover {
            background: rgba(0,0,0,.9);
            transform: scale(1.05);
            color: #fff;
        }

        @media (max-width: 768px) {
            #journalFilePreviewModal .modal-body {
                padding: 10px !important;
            }

            #journalFilePreviewModal .journal-attachment-wrapper {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                max-width: 100%;
                max-height: 100%;
            }

            #journalFilePreviewModal .journal-attachment-content img {
                max-width: 100%;
                max-height: calc(100dvh - 20px);
            }

            #journalFilePreviewModal .journal-attachment-content embed {
                width: 94vw;
                height: calc(100dvh - 20px);
            }
        }


        /* ========== SWEETALERT2 AMGC THEME ========== */
        .amgc-swal-popup {
            border-radius: 18px !important;
            padding: 1.5rem !important;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18) !important;
            border: 1px solid rgba(5, 150, 105, 0.12) !important;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
        }

        .amgc-swal-title {
            color: #064e3b !important;
            font-weight: 700 !important;
            font-size: 1.25rem !important;
        }

        .amgc-swal-html {
            color: #475569 !important;
            font-size: 0.95rem !important;
            line-height: 1.5 !important;
        }

        .amgc-swal-confirm {
            background: linear-gradient(135deg, #44D34E, #047857) !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 0.65rem 1.25rem !important;
            font-weight: 700 !important;
            color: #ffffff !important;
            box-shadow: 0 6px 14px rgba(4, 120, 87, 0.22) !important;
        }

        .amgc-swal-cancel {
            background: #f1f5f9 !important;
            color: #334155 !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 0.65rem 1.25rem !important;
            font-weight: 700 !important;
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
    <div class="appPage" id="appPage">
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
                            
                            <li class="nav-item active">
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
                                <a class="nav-link active" href="journal_entries.php">
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

        <div class="main-content" id="mainContent">
            <div class="navbar-top">
                <div class="page-title">
                    <h2>Journal Entries</h2>
                    <p>Manage journal transactions and entries</p>
                </div>
            </div>

            <div class="container-fluid mt-3">
                <ul class="nav nav-tabs custom-tabs" id="journalTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active"
                                id="create-journal-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#create-journal"
                                type="button"
                                role="tab">
                            <i class="bi bi-plus-circle me-2"></i>Create Journal
                        </button>
                    </li>

                    <li class="nav-item" role="presentation">
                        <button class="nav-link"
                                id="journal-entries-tab"
                                data-bs-toggle="tab"
                                data-bs-target="#journal-entries"
                                type="button"
                                role="tab">
                            <i class="bi bi-journal-text me-2"></i>Journal Entries
                        </button>
                    </li>
                </ul>

                <div class="tab-content mt-3" id="journalTabsContent">

                    <!-- Create Journal -->
                    <div class="tab-pane fade show active" id="create-journal" role="tabpanel">
                        <form id="createJournalForm" method="POST" enctype="multipart/form-data" class="create-journal-form">
                            <input type="hidden" name="save_journal" value="1">
                            <input type="hidden" name="save_journal_action" id="saveJournalAction" value="new">
                        <div class="qb-clean-panel">
                            <div class="journal-topbar">
                                <div class="journal-fields-clean">
                                    <label for="entryNo">Entry No.</label>
                                    <input type="text" id="entryNo" name="entry_no" value="<?php echo htmlspecialchars($generated_entry_no); ?>" placeholder="Auto-generate">

                                    <label for="journalDate">Date</label>
                                    <input type="date" id="journalDate" name="journal_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>

                                <div class="attachment-area-clean">
                                    <label class="attachment-compact">
                                        <i class="bi bi-paperclip"></i>
                                        Attachment
                                        <input type="file" id="journalAttachment" name="journal_attachment[]" multiple>
                                    </label>
                                    <span class="selected-file-name" id="selectedFileName">No file chosen</span>
                                </div>
                            </div>

                            <div class="qb-journal-table-wrap">
                                <table class="qb-journal-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 30%;">Account Title</th>
                                            <th style="width: 14%;">Debit</th>
                                            <th style="width: 14%;">Credit</th>
                                            <th style="width: 24%;">Memo</th>
                                            <th style="width: 18%;">Counterparty</th>
                                        </tr>
                                    </thead>
                                    <tbody id="createJournalTableBody">
                                        <?php for ($i = 0; $i < 15; $i++): ?>
                                            <tr>
                                                <td class="account-cell expense-account-cell">
                                                    <div class="qb-account-picker expense-account-combo">
                                                        <input type="text" class="expense-account-input" name="account_title[]" autocomplete="off">
                                                        <button type="button" class="qb-account-toggle" tabindex="-1" aria-label="Open account title list"><i class="bi bi-chevron-down"></i></button>
                                                    </div>
                                                </td>
                                                <td><input type="number" step="0.01" name="debit[]"></td>
                                                <td><input type="number" step="0.01" name="credit[]"></td>
                                                <td><input type="text" name="line_memo[]"></td>
                                                <td class="counterparty-cell journal-counterparty-cell">
                                                    <div class="qb-counterparty-picker counterparty-combo">
                                                        <input type="text" class="counterparty-input" name="counterparty[]" autocomplete="off">
                                                        <button type="button" class="qb-counterparty-toggle" tabindex="-1" aria-label="Open counterparty list"><i class="bi bi-chevron-down"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="journal-actions">
                            <button type="submit" class="journal-btn primary" id="saveNewJournalBtn" name="save_journal_action" value="new">Save &amp; New</button>
                            <button type="submit" class="journal-btn primary" id="saveCloseJournalBtn" name="save_journal_action" value="close">Save &amp; Close</button>
                            <button type="button" class="journal-btn secondary" id="clearJournalBtn">Clear</button>
                        </div>
                        </form>
                    </div>

                    <!-- Journal Entries -->
                    <div class="tab-pane fade" id="journal-entries" role="tabpanel">
                        <div class="journal-entries-db-wrap">
                            <table class="journal-entries-db-table">
                                <thead>
                                    <tr>
                                        <th style="width: 11%;">Transaction</th>
                                        <th style="width: 7%;">Date</th>
                                        <th style="width: 18%;">Account Title</th>
                                        <th class="amount-header debit-col" style="width: 9%;">Debit</th>
                                        <th class="amount-header credit-col" style="width: 9%;">Credit</th>
                                        <th class="memo-col" style="width: 27%;">Memo/Description</th>
                                        <th style="width: 12%;">Counter Party</th>
                                        <th style="width: 7%;">Attachments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($journal_entries_list)): ?>
                                        <tr class="empty-row">
                                            <td colspan="8">No journal entries found from database.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php
                                            $currentSection = '';
                                            $currentTransactionKey = '';
                                        ?>
                                        <?php foreach ($journal_entries_list as $entry): ?>
                                            <?php
                                                $section = (string)($entry['section_label'] ?? 'Journal Entry');
                                                if ($section !== $currentSection):
                                                    $currentSection = $section;
                                                    $currentTransactionKey = '';
                                            ?>
                                            <?php endif; ?>

                                            <?php
                                                $transactionKey = trim((string)($entry['transaction_no'] ?? ''));
                                                if ($transactionKey === '') {
                                                    $transactionKey = trim((string)($entry['reference_no'] ?? ''));
                                                }
                                                if ($transactionKey === '') {
                                                    $transactionKey = 'Transaction #' . (int)($entry['source_id'] ?? $entry['transaction_id'] ?? 0);
                                                }
                                                $showTransaction = $transactionKey !== $currentTransactionKey;
                                                if ($showTransaction) {
                                                    $currentTransactionKey = $transactionKey;
                                                }

                                                $entryDate = trim((string)($entry['transaction_date'] ?? ''));
                                                $displayDate = $entryDate !== '' ? date('m/d/Y', strtotime($entryDate)) : '';
                                                $debit = (float)($entry['debit'] ?? 0);
                                                $credit = (float)($entry['credit'] ?? 0);
                                                $journalGroupKey = md5(implode('|', [
                                                    $transactionKey,
                                                    (string)($entry['reference_no'] ?? ''),
                                                    (string)($entry['source_table'] ?? ''),
                                                    (string)($entry['source_id'] ?? '')
                                                ]));
                                            ?>
                                            <tr class="journal-entry-clickable-row" data-transaction-key="<?php echo htmlspecialchars($journalGroupKey); ?>">
                                                <td>
                                                    <?php if ($showTransaction): ?>
                                                        <button type="button"
                                                                class="journal-open-transaction-btn"
                                                                data-transaction="<?php echo htmlspecialchars($transactionKey); ?>"
                                                                data-transaction-key="<?php echo htmlspecialchars($journalGroupKey); ?>">
                                                            <?php echo htmlspecialchars($transactionKey); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($displayDate); ?></td>
                                                <td><?php echo htmlspecialchars((string)($entry['account_title'] ?? '')); ?></td>
                                                <td class="amount-cell debit-col"><?php echo $debit > 0 ? number_format($debit, 2) : ''; ?></td>
                                                <td class="amount-cell credit-col"><?php echo $credit > 0 ? number_format($credit, 2) : ''; ?></td>
                                                <td class="memo-cell"><?php echo htmlspecialchars((string)($entry['memo'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string)($entry['counterparty'] ?? '')); ?></td>
                                                <td>
                                                    <?php if ($showTransaction && !empty($entry['attachments'])): ?>
                                                        <button type="button"
                                                                class="journal-attachment-btn"
                                                                data-transaction="<?php echo htmlspecialchars($transactionKey); ?>"
                                                                data-attachments="<?php echo journalSafeJson($entry['attachments']); ?>">
                                                            <i class="bi bi-paperclip"></i>
                                                            View <?php echo count($entry['attachments']); ?>
                                                        </button>
                                                    <?php elseif ($showTransaction): ?>
                                                        <span class="journal-no-attachment">None</span>
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
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'vendorMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Vendor</span>
                </a>
                <div class="more-dropdown" id="vendorMobileMenu">
                    <a class="dropdown-item" href="enterbills.php"><i
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
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'accountingMobileMenu')">
                    <i class="bi bi-graph-up"></i>
                    <span>Accounting</span>
                </a>
                <div class="more-dropdown" id="accountingMobileMenu">
                    <a class="dropdown-item active" href="journal_entries.php"><i class="bi bi-journal"></i><span>Journal
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


    <div class="modal fade" id="journalAttachmentSelectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content journal-attachment-select-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Transaction Attachments</h5>
                        <small id="journalAttachmentSelectCount">Tap an attachment to preview.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="journalAttachmentSelectList" class="journal-attachment-select-list"></div>
                </div>
            </div>
        </div>
    </div>


    <div class="modal fade" id="journalFilePreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-transparent border-0 shadow-none">
                <div class="modal-body p-0">
                    <div class="journal-attachment-container">
                        <div class="journal-attachment-wrapper">
                            <button type="button" class="btn-close-journal-attachment" data-bs-dismiss="modal" aria-label="Close">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <a href="#" id="journalDownloadLink" class="btn-download-journal-attachment" download>
                                <i class="bi bi-download"></i>
                            </a>
                            <div class="journal-attachment-content" id="journalPreviewBody">
                                <div class="spinner-border text-light" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <script>

function showAMGCSwal(options) {
    const defaults = {
        confirmButtonColor: '#047857',
        customClass: {
            popup: 'amgc-swal-popup',
            title: 'amgc-swal-title',
            htmlContainer: 'amgc-swal-html',
            confirmButton: 'amgc-swal-confirm',
            cancelButton: 'amgc-swal-cancel'
        },
        buttonsStyling: true
    };

    return Swal.fire(Object.assign({}, defaults, options || {}));
}

function showAMGCLoading(title, text) {
    return Swal.fire({
        title: title || 'Loading...',
        text: text || 'Please wait',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        customClass: {
            popup: 'amgc-swal-popup',
            title: 'amgc-swal-title',
            htmlContainer: 'amgc-swal-html'
        },
        didOpen: () => {
            Swal.showLoading();
        }
    });
}

// ========== SIDEBAR DROPDOWN HANDLING ==========

// Toggle sidebar dropdown function - properly handles collapsed state
function toggleSidebarDropdown(event, targetId) {
    event.preventDefault();
    event.stopPropagation();
    
    const target = document.getElementById(targetId);
    const btn = event.currentTarget;
    const arrow = btn.querySelector('.dropdown-arrow');
    const sidebar = document.getElementById('sidebar');
    
    // If sidebar is collapsed, expand it first then open dropdown
    if (sidebar.classList.contains('collapsed')) {
        // Expand the sidebar first
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
                        if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                    }
                }
            });
            
            // Open the clicked dropdown
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }, 50);
        return;
    }
    
    // Normal behavior when sidebar is already expanded
    if (target.classList.contains('show')) {
        target.classList.remove('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
    } else {
        // Close all other open dropdowns
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
                    if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }
            }
        }
    });
}

// Update active state for dropdown parent when sidebar is collapsed
function updateDropdownParentActiveState() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const isCollapsed = sidebar.classList.contains('collapsed');

    document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => {
        const parentLink = dropdownNav.querySelector(':scope > .nav-link');
        const activeChild = dropdownNav.querySelector(':scope .collapse .nav-link.active');

        if (!parentLink) return;

        // Parent dropdown should only look active while the sidebar is collapsed.
        // When expanded, only the actual child page link must stay active.
        if (isCollapsed && activeChild) {
            parentLink.classList.add('active');
        } else {
            parentLink.classList.remove('active');
        }
    });
}

function clearDropdownParentActiveState() {
    document.querySelectorAll('.sidebar .dropdown-nav > .nav-link').forEach(parentLink => {
        parentLink.classList.remove('active');
    });
}

// Function to expand all dropdown containers that contain active links
function expandActiveDropdownContainers() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => {
        const activeChild = dropdownNav.querySelector(':scope .collapse .nav-link.active');
        const collapseDiv = dropdownNav.querySelector(':scope .collapse');
        const parentLink = dropdownNav.querySelector(':scope > .nav-link');

        if (activeChild && collapseDiv) {
            collapseDiv.classList.add('show');

            if (parentLink) {
                const arrow = parentLink.querySelector('.dropdown-arrow');
                if (arrow) {
                    arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }

                if (sidebar.classList.contains('collapsed')) {
                    parentLink.classList.add('active');
                } else {
                    parentLink.classList.remove('active');
                }
            }
        }
    });
}

// Toggle sidebar function - based on purchase_order behavior, with main content layout sync
function applySidebarLayoutState() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (!sidebar || !mainContent) return;

    if (window.innerWidth <= 992) {
        mainContent.classList.remove('sidebar-expanded', 'sidebar-collapsed');
        document.querySelectorAll('.nav-text').forEach(text => {
            text.style.display = '';
        });
        return;
    }

    const isCollapsed = sidebar.classList.contains('collapsed');

    mainContent.classList.toggle('sidebar-collapsed', isCollapsed);
    mainContent.classList.toggle('sidebar-expanded', !isCollapsed);

    document.querySelectorAll('.nav-text').forEach(text => {
        text.style.display = isCollapsed ? 'none' : 'inline-block';
    });

    if (!isCollapsed) {
        clearDropdownParentActiveState();
    }

    updateDropdownParentActiveState();
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    if (sidebar) {
        sidebar.classList.remove('active');
    }

    if (overlay) {
        overlay.classList.remove('active');
        setTimeout(() => overlay.remove(), 300);
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const isMobile = window.innerWidth <= 992;

    if (isMobile) {
        sidebar.classList.toggle('active');

        if (sidebar.classList.contains('active') && !document.querySelector('.sidebar-overlay')) {
            const overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', closeMobileSidebar);
            setTimeout(() => overlay.classList.add('active'), 10);
        }

        if (!sidebar.classList.contains('active')) {
            closeMobileSidebar();
        }

        return;
    }

    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');

    if (sidebar.classList.contains('collapsed')) {
        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
            collapse.classList.remove('show');

            const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
            if (parentBtn) {
                const arrow = parentBtn.querySelector('.dropdown-arrow');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
            }
        });
    } else {
        clearDropdownParentActiveState();
        setActiveSidebarItem();
        setTimeout(() => {
            expandActiveDropdownContainers();
            updateDropdownParentActiveState();
        }, 120);
    }

    applySidebarLayoutState();
}
window.toggleSidebar = toggleSidebar;

function initializeSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    if (window.innerWidth > 992) {
        const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        sidebar.classList.toggle('collapsed', savedCollapsed);
    } else {
        sidebar.classList.remove('collapsed');
    }

    applySidebarLayoutState();
}

// Initialize sidebar on DOM load
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');

    initializeSidebar();
    setActiveSidebarItem();
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        clearDropdownParentActiveState();
    }
    expandActiveDropdownContainers();
    updateDropdownParentActiveState();
    applySidebarLayoutState();

    document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
        collapse.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 992) {
                closeMobileSidebar();
            }
        });
    });

    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        if (
            window.innerWidth <= 992 &&
            sidebar &&
            sidebar.classList.contains('active') &&
            !sidebar.contains(event.target) &&
            (!mobileMenuBtn || !mobileMenuBtn.contains(event.target))
        ) {
            closeMobileSidebar();
        }
    });

    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');

        if (!sidebar) return;

        if (window.innerWidth > 992) {
            closeMobileSidebar();

            const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            sidebar.classList.toggle('collapsed', savedCollapsed);
        } else {
            sidebar.classList.remove('collapsed');
        }

        applySidebarLayoutState();
    });
});

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


// ========== ACCOUNT TITLE DROPDOWN LIKE PURCHASE ORDER ==========



function getAttachmentPathCandidates(path) {
    const original = String(path || '').trim();
    const candidates = [];
    const add = (value) => {
        value = String(value || '').trim();
        if (value && !candidates.includes(value)) candidates.push(value);
    };

    add(original);
    if (original.startsWith('../uploads/')) add(original.replace(/^\.\.\//, ''));
    if (original.startsWith('uploads/')) add('../' + original);
    if (original.startsWith('./uploads/')) add('../' + original.replace(/^\.\//, ''));
    if (original.startsWith('/uploads/')) add('..' + original);
    if (!/^([a-z]+:)?\/\//i.test(original) && !original.startsWith('data:') && !original.includes('/')) {
        add('../uploads/' + original);
        add('../uploads/motorpool/' + original);
    }

    return candidates;
}

function setupAttachmentImageFallback(img, path) {
    const candidates = getAttachmentPathCandidates(path);
    img.dataset.fallbackIndex = '0';
    img.onerror = function () {
        let nextIndex = parseInt(this.dataset.fallbackIndex || '0', 10) + 1;
        if (nextIndex < candidates.length) {
            this.dataset.fallbackIndex = String(nextIndex);
            this.src = candidates[nextIndex];
        } else {
            this.onerror = null;
            this.style.display = 'none';
            const parent = this.parentElement;
            if (parent && !parent.querySelector('.attachment-broken-icon')) {
                const icon = document.createElement('i');
                icon.className = 'bi bi-image attachment-broken-icon';
                parent.appendChild(icon);
            }
        }
    };
    img.src = candidates[0] || path;
}

let journalFilePreviewModal;

function getOpenJournalParentModalId() {
    const modal = document.querySelector('.modal.show:not(#journalFilePreviewModal)');
    return modal ? modal.id : '';
}

function getJournalPreviewSource(path) {
    const candidates = getAttachmentPathCandidates(path);
    return candidates[0] || String(path || '').trim();
}

function openJournalFilePreview(file, title) {
    if (!file) return;

    const fileData = typeof file === 'string' ? { path: file, name: title || 'Attachment' } : file;
    const path = String(fileData.path || '').trim();
    const name = String(fileData.name || title || 'Attachment').trim();
    if (!path) return;

    const src = getJournalPreviewSource(path);
    const pathOnly = src.split('?')[0].split('#')[0];
    const ext = (pathOnly.split('.').pop() || '').toLowerCase();
    const previewBody = document.getElementById('journalPreviewBody');
    const downloadLink = document.getElementById('journalDownloadLink');
    const parentModalId = getOpenJournalParentModalId();

    if (parentModalId) {
        sessionStorage.setItem('journalReturnModalId', parentModalId);
        const parentModalElement = document.getElementById(parentModalId);
        const parentModal = bootstrap.Modal.getInstance(parentModalElement) || bootstrap.Modal.getOrCreateInstance(parentModalElement);
        parentModal.hide();
    } else {
        sessionStorage.removeItem('journalReturnModalId');
    }

    if (downloadLink) {
        downloadLink.href = src;
        downloadLink.download = name || pathOnly.split('/').pop() || 'attachment';
    }

    if (previewBody) {
        previewBody.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';

        setTimeout(function() {
            if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(ext) || fileData.is_image) {
                const img = document.createElement('img');
                img.alt = name;
                img.style.opacity = '0';
                img.onload = function() { img.style.opacity = '1'; };
                img.onerror = function() {
                    previewBody.innerHTML = '<div class="alert alert-warning m-0"><i class="bi bi-exclamation-triangle me-2"></i>Unable to load this image.</div>';
                };
                previewBody.innerHTML = '';
                previewBody.appendChild(img);
                setupAttachmentImageFallback(img, path);
            } else if (ext === 'pdf' || fileData.is_pdf) {
                const embed = document.createElement('embed');
                embed.src = src;
                embed.type = 'application/pdf';
                previewBody.innerHTML = '';
                previewBody.appendChild(embed);
            } else {
                previewBody.innerHTML = '<div class="alert alert-info m-0"><i class="bi bi-info-circle me-2"></i>This file type cannot be previewed directly. Please download to view.<br><small>' + escapeHtml(name) + '</small></div>';
            }
        }, 80);
    }

    if (!journalFilePreviewModal) {
        journalFilePreviewModal = new bootstrap.Modal(document.getElementById('journalFilePreviewModal'));
    }

    const modalElement = document.getElementById('journalFilePreviewModal');
    modalElement.removeEventListener('hidden.bs.modal', handleJournalFilePreviewHidden);
    modalElement.addEventListener('hidden.bs.modal', handleJournalFilePreviewHidden);

    setTimeout(function() {
        journalFilePreviewModal.show();
    }, parentModalId ? 180 : 0);
}

function handleJournalFilePreviewHidden() {
    requestAnimationFrame(function() {
        const previewBody = document.getElementById('journalPreviewBody');
        if (previewBody) {
            previewBody.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';
        }

        const returnModalId = sessionStorage.getItem('journalReturnModalId');
        sessionStorage.removeItem('journalReturnModalId');

        if (returnModalId) {
            const returnModalElement = document.getElementById(returnModalId);
            if (returnModalElement) {
                setTimeout(function() {
                    bootstrap.Modal.getOrCreateInstance(returnModalElement).show();
                    if (!document.body.classList.contains('modal-open')) {
                        document.body.classList.add('modal-open');
                    }
                }, 80);
                return;
            }
        }

        const anyModalOpen = document.querySelector('.modal.show');
        if (!anyModalOpen) {
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    });
}

function showAttachmentInsideModal(file) {
    openJournalFilePreview(file, file && file.name ? file.name : 'Attachment');
}

let journalAttachmentSelectModal;

function showJournalAttachmentChooser(attachments) {
    const list = document.getElementById('journalAttachmentSelectList');
    const countLabel = document.getElementById('journalAttachmentSelectCount');

    if (!list) return;

    list.innerHTML = '';

    if (countLabel) {
        countLabel.textContent = attachments.length + ' attachments found. Tap an attachment to preview.';
    }

    attachments.forEach(function(file, index) {
        const fileName = String(file?.name || file?.path || ('Attachment ' + (index + 1))).trim();
        const filePath = String(file?.path || '').trim();
        const previewSrc = getJournalPreviewSource(filePath);
        const pathOnly = previewSrc.split('?')[0].split('#')[0];
        const ext = (pathOnly.split('.').pop() || '').toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(ext) || !!file?.is_image;
        const isPdf = ext === 'pdf' || !!file?.is_pdf;

        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'journal-attachment-select-item';
        item.setAttribute('title', fileName);

        const thumb = document.createElement('span');
        thumb.className = 'journal-attachment-gallery-thumb';

        if (isImage) {
            const img = document.createElement('img');
            img.alt = fileName;
            img.loading = 'lazy';
            thumb.appendChild(img);
            setupAttachmentImageFallback(img, filePath);
        } else {
            const icon = document.createElement('i');
            icon.className = 'bi ' + (isPdf ? 'bi-file-earmark-pdf' : 'bi-paperclip');
            thumb.appendChild(icon);
        }

        const badge = document.createElement('span');
        badge.className = 'journal-attachment-gallery-badge';
        badge.textContent = isImage ? 'IMAGE' : (isPdf ? 'PDF' : 'FILE');
        thumb.appendChild(badge);

        const name = document.createElement('span');
        name.className = 'journal-attachment-gallery-name';
        name.textContent = fileName;

        item.appendChild(thumb);
        item.appendChild(name);

        item.addEventListener('click', function() {
            // Keep the gallery modal as the return modal.
            // openJournalFilePreview will hide it first, then show it again when the preview is closed.
            openJournalFilePreview(file, fileName);
        });

        list.appendChild(item);
    });

    const modalElement = document.getElementById('journalAttachmentSelectModal');
    if (!journalAttachmentSelectModal) {
        journalAttachmentSelectModal = new bootstrap.Modal(modalElement);
    }
    journalAttachmentSelectModal.show();
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

const chartAccountOptions = <?php
    $accounts_by_id = [];
    $children_by_parent = [];

    foreach ($chart_accounts_list as $account) {
        $account_id = (int)($account['account_id'] ?? 0);
        $title = trim((string)($account['account_title'] ?? ''));
        if ($account_id <= 0 || $title === '') continue;

        $parent_id = (int)($account['parent_account_id'] ?? 0);
        $account['_account_id'] = $account_id;
        $account['_parent_id'] = $parent_id;
        $accounts_by_id[$account_id] = $account;
    }

    foreach ($accounts_by_id as $account_id => $account) {
        $parent_id = (int)($account['_parent_id'] ?? 0);
        if ($parent_id <= 0 || !isset($accounts_by_id[$parent_id])) {
            $parent_id = 0;
        }
        if (!isset($children_by_parent[$parent_id])) $children_by_parent[$parent_id] = [];
        $children_by_parent[$parent_id][] = $account_id;
    }

    $sort_account_ids = function (&$ids) use (&$accounts_by_id): void {
        usort($ids, function ($a, $b) use (&$accounts_by_id) {
            $code_a = trim((string)($accounts_by_id[$a]['account_code'] ?? ''));
            $code_b = trim((string)($accounts_by_id[$b]['account_code'] ?? ''));
            if ($code_a !== '' || $code_b !== '') {
                $code_compare = strnatcasecmp($code_a, $code_b);
                if ($code_compare !== 0) return $code_compare;
            }
            return strcasecmp((string)($accounts_by_id[$a]['account_title'] ?? ''), (string)($accounts_by_id[$b]['account_title'] ?? ''));
        });
    };

    foreach ($children_by_parent as &$child_ids) {
        $sort_account_ids($child_ids);
    }
    unset($child_ids);

    $journal_account_options = [];
    $walk_accounts = function (int $parent_id, int $level) use (&$walk_accounts, &$children_by_parent, &$accounts_by_id, &$journal_account_options): void {
        if (empty($children_by_parent[$parent_id])) return;

        foreach ($children_by_parent[$parent_id] as $account_id) {
            if (!isset($accounts_by_id[$account_id])) continue;

            $account = $accounts_by_id[$account_id];
            $title = trim((string)($account['account_title'] ?? ''));
            if ($title === '') continue;

            $code = trim((string)($account['account_code'] ?? ''));
            $has_children = !empty($children_by_parent[$account_id]);
            $label = ($code !== '' ? $code . ' - ' : '') . $title;

            $journal_account_options[] = [
                'id' => $account_id,
                'value' => $title,
                'label' => $label,
                'type' => trim((string)($account['account_type'] ?? '')),
                'level' => $level,
                'has_children' => $has_children,
                'selectable' => !$has_children
            ];

            $walk_accounts($account_id, $level + 1);
        }
    };

    $walk_accounts(0, 0);

    echo json_encode($journal_account_options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>.filter(account => String(account?.value || '').trim() !== '');

let activeExpenseAccountPicker = null;
let expenseAccountDropdown = null;

function getExpenseAccountDropdown() {
    if (expenseAccountDropdown) return expenseAccountDropdown;
    expenseAccountDropdown = document.createElement('div');
    expenseAccountDropdown.className = 'qb-account-dropdown expense-account-dropdown';
    document.body.appendChild(expenseAccountDropdown);
    return expenseAccountDropdown;
}

function closeExpenseAccountDropdown() {
    const dropdown = getExpenseAccountDropdown();
    dropdown.classList.remove('show');
    dropdown.innerHTML = '';
    document.querySelectorAll('.qb-account-toggle.active').forEach(btn => btn.classList.remove('active'));
    activeExpenseAccountPicker = null;
}

function positionExpenseAccountDropdown(picker) {
    const dropdown = getExpenseAccountDropdown();
    const rect = picker.getBoundingClientRect();
    const dropdownWidth = Math.max(rect.width, 260);
    const viewportPadding = 8;
    let left = rect.left;

    if (left + dropdownWidth > window.innerWidth - viewportPadding) {
        left = Math.max(viewportPadding, window.innerWidth - dropdownWidth - viewportPadding);
    }

    dropdown.style.width = dropdownWidth + 'px';
    dropdown.style.minWidth = dropdownWidth + 'px';
    dropdown.style.left = left + 'px';
    dropdown.style.top = (rect.bottom + 2) + 'px';
}

function renderExpenseAccountDropdown(input, showAll = false) {
    const picker = input.closest('.qb-account-picker');
    if (!picker) return;

    const dropdown = getExpenseAccountDropdown();
    const keyword = String(input.value || '').trim().toLowerCase();
    const filtered = showAll || keyword === ''
        ? chartAccountOptions.slice()
        : chartAccountOptions.filter(account => {
            const value = String(account?.value || '').toLowerCase();
            const label = String(account?.label || '').toLowerCase();
            const type = String(account?.type || '').toLowerCase();
            return value.includes(keyword) || label.includes(keyword) || type.includes(keyword);
        });

    dropdown.innerHTML = '';

    if (!filtered.length) {
        dropdown.innerHTML = '<div class="qb-account-empty">No account title found</div>';
    } else {
        filtered.forEach(account => {
            const option = document.createElement('button');
            const isSelectable = account.selectable !== false;
            const level = Math.max(0, Number(account.level || 0));

            option.type = 'button';
            option.className = 'qb-account-option' + (isSelectable ? '' : ' qb-account-option-disabled');
            option.dataset.value = account.value;
            option.dataset.selectable = isSelectable ? '1' : '0';
            option.style.paddingLeft = (14 + (level * 22)) + 'px';
            option.innerHTML = `<span class="qb-account-option-label">${escapeHtml(account.label || account.value)}</span><small>${escapeHtml(account.type || '')}</small>`;

            if (!isSelectable) {
                option.disabled = true;
                option.title = 'Parent account with sub account cannot be selected';
            } else {
                option.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    input.value = account.value;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    closeExpenseAccountDropdown();
                    input.focus();
                });
            }

            dropdown.appendChild(option);
        });
    }

    activeExpenseAccountPicker = picker;
    positionExpenseAccountDropdown(picker);
    dropdown.classList.add('show');

    document.querySelectorAll('.qb-account-toggle.active').forEach(btn => btn.classList.remove('active'));
    const btn = picker.querySelector('.qb-account-toggle');
    if (btn) btn.classList.add('active');
}

document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('expense-account-input')) return;
    renderExpenseAccountDropdown(e.target, false);
});

document.addEventListener('focusin', function(e) {
    if (!e.target.classList.contains('expense-account-input')) return;
    renderExpenseAccountDropdown(e.target, false);
});

document.addEventListener('mousedown', function(e) {
    const toggle = e.target.closest('.qb-account-toggle');
    if (toggle && toggle.closest('.expense-account-combo')) {
        e.preventDefault();
        e.stopPropagation();

        const picker = toggle.closest('.qb-account-picker');
        const input = picker ? picker.querySelector('.expense-account-input') : null;
        if (!input) return;

        const dropdown = getExpenseAccountDropdown();
        const isOpenForThisPicker = activeExpenseAccountPicker === picker && dropdown.classList.contains('show');

        if (typeof closeCounterpartyDropdown === 'function') closeCounterpartyDropdown();

        if (isOpenForThisPicker) {
            closeExpenseAccountDropdown();
        } else {
            renderExpenseAccountDropdown(input, true);
            setTimeout(() => input.focus(), 0);
        }
        return;
    }

    if (e.target.closest('.qb-account-dropdown')) return;

    if (!e.target.closest('.expense-account-combo')) {
        closeExpenseAccountDropdown();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && getExpenseAccountDropdown().classList.contains('show')) {
        closeExpenseAccountDropdown();
    }
});

window.addEventListener('resize', closeExpenseAccountDropdown);
window.addEventListener('scroll', function() {
    if (activeExpenseAccountPicker && getExpenseAccountDropdown().classList.contains('show')) {
        positionExpenseAccountDropdown(activeExpenseAccountPicker);
    }
}, true);



// ========== COUNTERPARTY DROPDOWN LIKE ACCOUNT TITLE ==========
const counterpartyOptions = <?php
    echo json_encode(array_values($counterparty_options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>.filter(counterparty => String(counterparty?.value || '').trim() !== '');


const journalTransactionGroups = <?php echo json_encode($journal_transaction_groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let activeManualJournalEditGroupKey = null;
let activeManualJournalEditOriginalButtons = null;

let activeCounterpartyPicker = null;
let counterpartyDropdown = null;

function getCounterpartyDropdown() {
    if (counterpartyDropdown) return counterpartyDropdown;
    counterpartyDropdown = document.createElement('div');
    counterpartyDropdown.className = 'qb-counterparty-dropdown';
    document.body.appendChild(counterpartyDropdown);
    return counterpartyDropdown;
}

function closeCounterpartyDropdown() {
    const dropdown = getCounterpartyDropdown();
    dropdown.classList.remove('show');
    dropdown.innerHTML = '';
    document.querySelectorAll('.qb-counterparty-toggle.active').forEach(btn => btn.classList.remove('active'));
    activeCounterpartyPicker = null;
}

function positionCounterpartyDropdown(picker) {
    const dropdown = getCounterpartyDropdown();
    const rect = picker.getBoundingClientRect();

    dropdown.style.width = rect.width + 'px';
    dropdown.style.minWidth = rect.width + 'px';
    dropdown.style.maxWidth = rect.width + 'px';

    dropdown.style.left = rect.left + 'px';
    dropdown.style.top = (rect.bottom + 2) + 'px';
}

function renderCounterpartyDropdown(input, showAll = false) {
    const picker = input.closest('.qb-counterparty-picker');
    if (!picker) return;

    const dropdown = getCounterpartyDropdown();
    const keyword = String(input.value || '').trim().toLowerCase();
    const filtered = showAll || keyword === ''
        ? counterpartyOptions.slice()
        : counterpartyOptions.filter(counterparty => {
            const value = String(counterparty?.value || '').toLowerCase();
            const label = String(counterparty?.label || '').toLowerCase();
            const type = String(counterparty?.type || '').toLowerCase();
            return value.includes(keyword) || label.includes(keyword) || type.includes(keyword);
        });

    dropdown.innerHTML = '';

    if (!filtered.length) {
        dropdown.innerHTML = '<div class="qb-counterparty-empty">No counterparty found</div>';
    } else {
        const grouped = {};
        const groupOrder = ['Vendor', 'Customer', 'Employee'];

        filtered.forEach(counterparty => {
            const type = String(counterparty?.type || 'Others').trim() || 'Others';
            if (!grouped[type]) grouped[type] = [];
            grouped[type].push(counterparty);
        });

        Object.keys(grouped).sort((a, b) => {
            const ai = groupOrder.indexOf(a);
            const bi = groupOrder.indexOf(b);
            if (ai !== -1 || bi !== -1) {
                return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi);
            }
            return a.localeCompare(b);
        }).forEach(type => {
            const header = document.createElement('div');
            header.className = 'qb-counterparty-group';
            header.textContent = type;
            dropdown.appendChild(header);

            grouped[type].forEach(counterparty => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'qb-counterparty-option';
                option.dataset.value = counterparty.value;
                option.innerHTML = `<span class="qb-counterparty-option-label">${escapeHtml(counterparty.label || counterparty.value)}</span>`;
                option.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    input.value = counterparty.value;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    closeCounterpartyDropdown();
                    input.focus();
                });
                dropdown.appendChild(option);
            });
        });
    }

    activeCounterpartyPicker = picker;
    positionCounterpartyDropdown(picker);
    dropdown.classList.add('show');

    document.querySelectorAll('.qb-counterparty-toggle.active').forEach(btn => btn.classList.remove('active'));
    const btn = picker.querySelector('.qb-counterparty-toggle');
    if (btn) btn.classList.add('active');
}

document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('counterparty-input')) return;
    renderCounterpartyDropdown(e.target, false);
});

document.addEventListener('focusin', function(e) {
    if (!e.target.classList.contains('counterparty-input')) return;
    renderCounterpartyDropdown(e.target, false);
});

document.addEventListener('mousedown', function(e) {
    const toggle = e.target.closest('.qb-counterparty-toggle');
    if (toggle && toggle.closest('.counterparty-combo')) {
        e.preventDefault();
        e.stopPropagation();

        const picker = toggle.closest('.qb-counterparty-picker');
        const input = picker ? picker.querySelector('.counterparty-input') : null;
        if (!input) return;

        const dropdown = getCounterpartyDropdown();
        const isOpenForThisPicker = activeCounterpartyPicker === picker && dropdown.classList.contains('show');

        closeExpenseAccountDropdown();

        if (isOpenForThisPicker) {
            closeCounterpartyDropdown();
        } else {
            renderCounterpartyDropdown(input, true);
            setTimeout(() => input.focus(), 0);
        }
        return;
    }

    if (e.target.closest('.qb-counterparty-dropdown')) return;

    if (!e.target.closest('.counterparty-combo')) {
        closeCounterpartyDropdown();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && getCounterpartyDropdown().classList.contains('show')) {
        closeCounterpartyDropdown();
    }
});

window.addEventListener('resize', closeCounterpartyDropdown);
window.addEventListener('scroll', function() {
    if (activeCounterpartyPicker && getCounterpartyDropdown().classList.contains('show')) {
        positionCounterpartyDropdown(activeCounterpartyPicker);
    }
}, true);


// ========== CREATE JOURNAL AUTO ADD ROW ==========
function createJournalRow() {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td class="account-cell expense-account-cell">
            <div class="qb-account-picker expense-account-combo">
                <input type="text" class="expense-account-input" name="account_title[]" autocomplete="off">
                <button type="button" class="qb-account-toggle" tabindex="-1" aria-label="Open account title list"><i class="bi bi-chevron-down"></i></button>
            </div>
        </td>
        <td><input type="number" step="0.01" name="debit[]"></td>
        <td><input type="number" step="0.01" name="credit[]"></td>
        <td><input type="text" name="line_memo[]"></td>
        <td class="counterparty-cell journal-counterparty-cell">
            <div class="qb-counterparty-picker counterparty-combo">
                <input type="text" class="counterparty-input" name="counterparty[]" autocomplete="off">
                <button type="button" class="qb-counterparty-toggle" tabindex="-1" aria-label="Open counterparty list"><i class="bi bi-chevron-down"></i></button>
            </div>
        </td>
    `;
    return row;
}

function journalRowHasValue(row) {
    if (!row) return false;
    return Array.from(row.querySelectorAll('input')).some(input => String(input.value || '').trim() !== '');
}

function updateCreateJournalScrollState() {
    const tbody = document.getElementById('createJournalTableBody');
    const tableWrap = document.querySelector('#create-journal .qb-journal-table-wrap');
    if (!tbody || !tableWrap) return;

    const rowsCount = tbody.querySelectorAll('tr').length;
    tableWrap.classList.toggle('is-scrollable', rowsCount > 15);
}

function handleCreateJournalAutoRow(event) {
    const tbody = document.getElementById('createJournalTableBody');
    if (!tbody || !event.target.closest('#createJournalTableBody')) return;

    const rows = Array.from(tbody.querySelectorAll('tr'));
    const lastRow = rows[rows.length - 1];

    if (event.target.closest('tr') === lastRow && journalRowHasValue(lastRow)) {
        tbody.appendChild(createJournalRow());
        updateCreateJournalScrollState();

        if (tbody.querySelectorAll('tr').length > 15) {
            tbody.scrollTop = tbody.scrollHeight;
        }
    } else {
        updateCreateJournalScrollState();
    }
}

function resetCreateJournalRows() {
    const tbody = document.getElementById('createJournalTableBody');
    if (!tbody) return;

    closeExpenseAccountDropdown();
    closeCounterpartyDropdown();

    tbody.innerHTML = '';
    for (let i = 0; i < 15; i++) {
        tbody.appendChild(createJournalRow());
    }

    updateCreateJournalScrollState();
}

function clearCreateJournalForm() {
    resetCreateJournalRows();

    const journalDate = document.getElementById('journalDate');
    if (journalDate) {
        journalDate.value = '<?php echo date('Y-m-d'); ?>';
    }

    const attachmentInput = document.getElementById('journalAttachment');
    const selectedFileName = document.getElementById('selectedFileName');
    if (attachmentInput) attachmentInput.value = '';
    if (selectedFileName) selectedFileName.textContent = 'No file chosen';
}

document.addEventListener('input', handleCreateJournalAutoRow);
document.addEventListener('change', handleCreateJournalAutoRow);

document.addEventListener('DOMContentLoaded', function () {
    updateCreateJournalScrollState();

    const clearJournalBtn = document.getElementById('clearJournalBtn');
    if (clearJournalBtn) {
        clearJournalBtn.addEventListener('click', function () {
            showAMGCSwal({
                icon: 'warning',
                title: 'Clear Journal?',
                text: 'All entered journal lines and attachments will be removed.',
                showCancelButton: true,
                confirmButtonText: 'Yes, Clear',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    clearCreateJournalForm();

                    showAMGCSwal({
                        icon: 'success',
                        title: 'Cleared',
                        text: 'Journal form has been cleared.'
                    });
                }
            });
        });
    }

    const attachmentInput = document.getElementById('journalAttachment');
    const selectedFileName = document.getElementById('selectedFileName');

    if (attachmentInput && selectedFileName) {
        attachmentInput.addEventListener('change', function () {
            if (this.files.length === 0) {
                selectedFileName.textContent = 'No file chosen';
            } else if (this.files.length === 1) {
                selectedFileName.textContent = this.files[0].name;
            } else {
                selectedFileName.textContent = this.files.length + ' files selected';
            }
        });
    }
    
    const createJournalForm = document.getElementById('createJournalForm');
    const saveJournalAction = document.getElementById('saveJournalAction');
    const saveNewJournalBtn = document.getElementById('saveNewJournalBtn');
    const saveCloseJournalBtn = document.getElementById('saveCloseJournalBtn');
    if (createJournalForm) {
        createJournalForm.addEventListener('submit', function (event) {
            const submitter = event.submitter || document.activeElement;
            const actionValue = submitter && submitter.value === 'close' ? 'close' : 'new';

            if (saveJournalAction) {
                saveJournalAction.value = actionValue;
            }

            if (saveNewJournalBtn) saveNewJournalBtn.disabled = true;
            if (saveCloseJournalBtn) saveCloseJournalBtn.disabled = true;

            if (submitter && submitter.tagName === 'BUTTON') {
                submitter.textContent = 'Saving...';
            }

            showAMGCLoading('Saving Journal...', 'Please wait while the journal entry is being saved.');
        });
    }


    function formatJournalAmount(value) {
        const number = Number(value || 0);
        return number > 0 ? number.toFixed(2) : '';
    }

    function collectJournalInlineRows(container) {
        const rows = [];
        container.querySelectorAll('tr[data-transaction-id]').forEach(function(row) {
            rows.push({
                transaction_id: row.getAttribute('data-transaction-id'),
                account_title: row.querySelector('[data-field="account_title"]')?.value || '',
                debit: row.querySelector('[data-field="debit"]')?.value || '0',
                credit: row.querySelector('[data-field="credit"]')?.value || '0',
                memo: row.querySelector('[data-field="memo"]')?.value || '',
                counterparty: row.querySelector('[data-field="counterparty"]')?.value || ''
            });
        });
        return rows;
    }


    function formatJournalDateTime(value) {
        if (!value) return 'N/A';
        const raw = String(value).replace(' ', 'T');
        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('en-US', {year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit'});
    }

    function renderJournalHistoryCompareRows(history) {
        const rows = [
            ['Account Title', history.old_account_title || '', history.new_account_title || '', false],
            ['Debit', history.old_debit || 0, history.new_debit || 0, true],
            ['Credit', history.old_credit || 0, history.new_credit || 0, true],
            ['Memo/Description', history.old_memo || '', history.new_memo || '', false],
            ['Counter Party', history.old_counterparty || '', history.new_counterparty || '', false]
        ];
        return rows.map(function(row) {
            const oldValue = row[3] ? formatJournalAmount(row[1] || 0) : escapeHtml(row[1] || '');
            const newValue = row[3] ? formatJournalAmount(row[2] || 0) : escapeHtml(row[2] || '');
            return `<tr><td>${escapeHtml(row[0])}</td><td>${oldValue}</td><td>${newValue}</td></tr>`;
        }).join('');
    }

    function openJournalTransactionHistory(groupKey) {
        const group = journalTransactionGroups[groupKey];
        if (!group) {
            showAMGCSwal({ icon: 'warning', title: 'Cannot Open History', text: 'Transaction data was not found on this page.' });
            return;
        }

        const rows = Array.isArray(group.rows) ? group.rows : [];
        const currentRowsHtml = rows.length ? rows.map(function(row) {
            return `<tr>
                <td>${escapeHtml(row.account_title || '')}</td>
                <td style="text-align:right;">${Number(row.debit || 0) > 0 ? formatJournalAmount(row.debit || 0) : ''}</td>
                <td style="text-align:right;">${Number(row.credit || 0) > 0 ? formatJournalAmount(row.credit || 0) : ''}</td>
                <td>${escapeHtml(row.memo || '')}</td>
                <td>${escapeHtml(row.counterparty || '')}</td>
            </tr>`;
        }).join('') : `<tr><td colspan="5" style="text-align:center;color:#64748b;">No current rows found.</td></tr>`;

        const currentHtml = `
            <div style="font-size:16px;color:#475569;margin-bottom:8px;">Current Transaction Information</div>
            <div class="journal-inline-meta">
                <div><strong>Transaction:</strong> ${escapeHtml(group.transaction_no || '')}</div>
                <div><strong>Date Entered:</strong> ${escapeHtml(group.date ? new Date(group.date + 'T00:00:00').toLocaleDateString('en-US') : 'N/A')}</div>
                <div><strong>Source:</strong> ${escapeHtml(group.section_label || group.transaction_type || 'Journal Entry')}</div>
                <div><strong>Reference:</strong> ${escapeHtml(group.reference_no || 'N/A')}</div>
            </div>
            <table class="journal-history-current-table">
                <thead><tr><th>Account Title</th><th>Debit</th><th>Credit</th><th>Memo/Description</th><th>Counter Party</th></tr></thead>
                <tbody>${currentRowsHtml}</tbody>
            </table>`;

        const history = Array.isArray(group.history) ? group.history : [];
        const historyHtml = history.length ? history.map(function(item, index) {
            return `<div class="journal-history-card">
                <h5>${escapeHtml((item.history_action || 'updated').toUpperCase())} Record #${history.length - index}</h5>
                <div class="journal-history-meta">
                    <div><strong>Date Edited:</strong> ${escapeHtml(formatJournalDateTime(item.edited_at))}</div>
                    <div><strong>Edited By:</strong> ${escapeHtml(item.edited_by_name || ('User #' + (item.edited_by || '')))}</div>
                    <div><strong>Transaction No:</strong> ${escapeHtml(item.transaction_no || group.transaction_no || '')}</div>
                    <div><strong>Original Date Entered:</strong> ${escapeHtml(item.old_transaction_date || group.date || 'N/A')}</div>
                </div>
                <table class="journal-history-compare">
                    <thead><tr><th style="width:24%;">Field</th><th style="width:38%;">Old / Previous</th><th style="width:38%;">New / Updated</th></tr></thead>
                    <tbody>${renderJournalHistoryCompareRows(item)}</tbody>
                </table>
            </div>`;
        }).join('') : `<div class="journal-history-empty">No edit history found yet. Starting now, every edit made in Journal Entries will be saved here. Edits from source pages will also be saved if database triggers are allowed on your hosting.</div>`;

        showAMGCSwal({
            title: 'Transaction Edit History',
            html: `<div class="journal-history-wrap">${currentHtml}<div style="font-size:16px;color:#475569;margin:16px 0 8px;">Edit Audit Trail</div>${historyHtml}</div>`,
            width: 1200,
            showConfirmButton: true,
            confirmButtonText: 'Close'
        });
    }

    function openJournalTransactionInsidePage(groupKey, mode) {
        const group = journalTransactionGroups[groupKey];
        if (!group) {
            showAMGCSwal({ icon: 'warning', title: 'Cannot Open Transaction', text: 'The transaction data was not found on this page.' });
            return;
        }

        const createTabBtn = document.getElementById('create-journal-tab');
        if (createTabBtn && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(createTabBtn).show();
        } else {
            document.getElementById('create-journal')?.classList.add('show', 'active');
            document.getElementById('journal-entries')?.classList.remove('show', 'active');
        }

        activeManualJournalEditGroupKey = groupKey;
        const form = document.getElementById('createJournalForm');
        if (form) {
            form.dataset.journalEditMode = '1';
            form.dataset.journalEditGroupKey = groupKey;
        }

        const entryInput = document.getElementById('entryNo');
        const dateInput = document.getElementById('journalDate');
        if (entryInput) {
            entryInput.value = group.transaction_no || '';
            entryInput.readOnly = true;
            entryInput.classList.add('journal-edit-readonly');
        }
        if (dateInput) {
            dateInput.value = group.date || '';
        }

        const tbody = document.getElementById('createJournalTableBody');
        if (!tbody) return;

        const rows = Array.isArray(group.rows) ? group.rows : [];
        const neededRows = Math.max(15, rows.length + 3);
        const rowTemplate = function(row) {
            return `<tr data-edit-transaction-id="${escapeHtml(row?.transaction_id || '')}">
                <td class="account-cell expense-account-cell">
                    <div class="qb-account-picker expense-account-combo">
                        <input type="text" class="expense-account-input" name="account_title[]" autocomplete="off" value="${escapeHtml(row?.account_title || '')}">
                        <button type="button" class="qb-account-toggle" tabindex="-1" aria-label="Open account title list"><i class="bi bi-chevron-down"></i></button>
                    </div>
                </td>
                <td><input type="number" step="0.01" name="debit[]" value="${escapeHtml(formatJournalAmount(row?.debit || 0).replace(/,/g, ''))}"></td>
                <td><input type="number" step="0.01" name="credit[]" value="${escapeHtml(formatJournalAmount(row?.credit || 0).replace(/,/g, ''))}"></td>
                <td><input type="text" name="line_memo[]" value="${escapeHtml(row?.memo || '')}"></td>
                <td class="counterparty-cell journal-counterparty-cell">
                    <div class="qb-counterparty-picker counterparty-combo">
                        <input type="text" class="counterparty-input" name="counterparty[]" autocomplete="off" value="${escapeHtml(row?.counterparty || '')}">
                        <button type="button" class="qb-counterparty-toggle" tabindex="-1" aria-label="Open counterparty list"><i class="bi bi-chevron-down"></i></button>
                    </div>
                </td>
            </tr>`;
        };

        let html = '';
        for (let i = 0; i < neededRows; i++) {
            html += rowTemplate(rows[i] || {});
        }
        tbody.innerHTML = html;

        const saveNewBtn = document.getElementById('saveNewJournalBtn');
        const saveCloseBtn = document.getElementById('saveCloseJournalBtn');
        const clearBtn = document.getElementById('clearJournalBtn');
        if (!activeManualJournalEditOriginalButtons) {
            activeManualJournalEditOriginalButtons = {
                saveNew: saveNewBtn ? saveNewBtn.textContent : '',
                saveClose: saveCloseBtn ? saveCloseBtn.textContent : '',
                clear: clearBtn ? clearBtn.textContent : ''
            };
        }
        if (saveNewBtn) {
            saveNewBtn.textContent = 'Update Journal';
            saveNewBtn.value = 'update';
        }
        if (saveCloseBtn) {
            saveCloseBtn.style.display = 'none';
        }
        if (clearBtn) {
            clearBtn.textContent = 'Cancel Edit';
        }

        let banner = document.getElementById('journalInlineEditBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'journalInlineEditBanner';
            banner.className = 'alert alert-success py-2 px-3 mb-3';
            const panel = document.querySelector('#create-journal .qb-clean-panel');
            if (panel) panel.parentNode.insertBefore(banner, panel);
        }
        banner.innerHTML = `<strong>Edit Mode:</strong> ${escapeHtml(group.transaction_no || 'Journal Entry')} is loaded below. Edit the table then click <strong>Update Journal</strong>.`;

        setTimeout(function(){ document.getElementById('entryNo')?.scrollIntoView({behavior:'smooth', block:'center'}); }, 80);
    }

    function collectCreateJournalEditRows() {
        const tbody = document.getElementById('createJournalTableBody');
        const rows = [];
        if (!tbody) return rows;
        tbody.querySelectorAll('tr').forEach(function(row) {
            const transactionId = parseInt(row.getAttribute('data-edit-transaction-id') || '0', 10) || 0;
            if (transactionId <= 0) return;
            rows.push({
                transaction_id: transactionId,
                account_title: row.querySelector('input[name="account_title[]"]')?.value || '',
                debit: row.querySelector('input[name="debit[]"]')?.value || '0',
                credit: row.querySelector('input[name="credit[]"]')?.value || '0',
                memo: row.querySelector('input[name="line_memo[]"]')?.value || '',
                counterparty: row.querySelector('input[name="counterparty[]"]')?.value || ''
            });
        });
        return rows;
    }

    function resetCreateJournalEditMode() {
        activeManualJournalEditGroupKey = null;
        const form = document.getElementById('createJournalForm');
        if (form) {
            delete form.dataset.journalEditMode;
            delete form.dataset.journalEditGroupKey;
        }
        const banner = document.getElementById('journalInlineEditBanner');
        if (banner) banner.remove();
        const saveNewBtn = document.getElementById('saveNewJournalBtn');
        const saveCloseBtn = document.getElementById('saveCloseJournalBtn');
        const clearBtn = document.getElementById('clearJournalBtn');
        if (saveNewBtn) {
            saveNewBtn.textContent = activeManualJournalEditOriginalButtons?.saveNew || 'Save & New';
            saveNewBtn.value = 'new';
        }
        if (saveCloseBtn) {
            saveCloseBtn.style.display = '';
        }
        if (clearBtn) {
            clearBtn.textContent = activeManualJournalEditOriginalButtons?.clear || 'Clear';
        }
        const entryInput = document.getElementById('entryNo');
        if (entryInput) {
            entryInput.readOnly = false;
            entryInput.classList.remove('journal-edit-readonly');
        }
    }

    function openJournalSourcePage(groupKey) {
        const group = journalTransactionGroups[groupKey];
        if (!group) {
            showAMGCSwal({ icon: 'warning', title: 'Cannot Open Transaction', text: 'The transaction data was not found on this page.' });
            return;
        }

        const url = group.edit_url || '';
        if (!url) {
            showAMGCSwal({
                icon: 'warning',
                title: 'Source Page Not Found',
                text: 'This transaction has no recognized source page/source ID. Please check source_table and source_id in chart_account_transactions.'
            });
            return;
        }

        window.location.href = url;
    }

    const createJournalFormForEdit = document.getElementById('createJournalForm');
    if (createJournalFormForEdit) {
        createJournalFormForEdit.addEventListener('submit', function(e) {
            if (this.dataset.journalEditMode !== '1') return;
            e.preventDefault();
            const rows = collectCreateJournalEditRows();
            if (!rows.length) {
                showAMGCSwal({icon:'warning', title:'No Rows', text:'No editable journal rows were loaded.'});
                return;
            }

            let debit = 0;
            let credit = 0;
            for (const row of rows) {
                const title = String(row.account_title || '').trim();
                const d = Number(String(row.debit || '0').replace(/,/g, '')) || 0;
                const c = Number(String(row.credit || '0').replace(/,/g, '')) || 0;
                if (!title) {
                    showAMGCSwal({icon:'warning', title:'Missing Account', text:'Account Title is required on every loaded row.'});
                    return;
                }
                if (d > 0 && c > 0) {
                    showAMGCSwal({icon:'warning', title:'Invalid Row', text:'A row cannot have both Debit and Credit.'});
                    return;
                }
                if (d <= 0 && c <= 0) {
                    showAMGCSwal({icon:'warning', title:'Invalid Row', text:'Each loaded row must have either Debit or Credit amount.'});
                    return;
                }
                debit += d;
                credit += c;
            }
            if (Math.abs(debit - credit) > 0.009) {
                showAMGCSwal({icon:'warning', title:'Not Balanced', text:'Total Debit and Credit must be equal.'});
                return;
            }

            const formData = new FormData();
            formData.append('journal_ajax_action', 'update_journal_transaction');
            formData.append('rows', JSON.stringify(rows));

            showAMGCSwal({
                title:'Update Journal?',
                text:'Save changes to this journal transaction?',
                icon:'question',
                showCancelButton:true,
                confirmButtonText:'Update Journal',
                cancelButtonText:'Cancel'
            }).then(function(confirmResult) {
                if (!confirmResult.isConfirmed) return;
                fetch(window.location.href, {method:'POST', body:formData, credentials:'same-origin'})
                    .then(response => response.json())
                    .then(data => {
                        if (!data || !data.success) throw new Error((data && data.message) ? data.message : 'Unable to update journal.');
                        showAMGCSwal({icon:'success', title:'Updated', text:'Journal transaction updated successfully.', timer:1200, showConfirmButton:false})
                            .then(function(){ window.location.reload(); });
                    })
                    .catch(error => {
                        showAMGCSwal({icon:'error', title:'Update Failed', text:error.message || 'Unable to update journal.'});
                    });
            });
        });
    }

    const originalClearJournalBtn = document.getElementById('clearJournalBtn');
    if (originalClearJournalBtn) {
        originalClearJournalBtn.addEventListener('click', function() {
            if (document.getElementById('createJournalForm')?.dataset.journalEditMode === '1') {
                window.location.reload();
            }
        }, true);
    }

    document.querySelectorAll('.journal-entry-clickable-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target.closest('.journal-attachment-btn')) return;
            const groupKey = this.getAttribute('data-transaction-key') || '';
            openJournalTransactionHistory(groupKey);
        });
    });

    document.querySelectorAll('.journal-open-transaction-btn').forEach(function (button) {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const groupKey = this.getAttribute('data-transaction-key') || '';
            openJournalTransactionHistory(groupKey);
            return;
            const transactionNo = this.getAttribute('data-transaction') || 'Transaction';

            showAMGCSwal({
                icon: 'warning',
                title: 'Edit ' + transactionNo,
                text: 'Enter your account password before opening this source transaction for editing.',
                input: 'password',
                inputPlaceholder: 'Password',
                inputAttributes: {
                    autocapitalize: 'off',
                    autocomplete: 'current-password'
                },
                showCancelButton: true,
                confirmButtonText: 'Confirm & Edit',
                cancelButtonText: 'Cancel',
                showLoaderOnConfirm: true,
                allowOutsideClick: () => !Swal.isLoading(),
                preConfirm: (password) => {
                    if (!password) {
                        Swal.showValidationMessage('Please enter your password.');
                        return false;
                    }

                    const formData = new FormData();
                    formData.append('journal_ajax_action', 'verify_edit_password');
                    formData.append('password', password);

                    return fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data || !data.success) {
                            throw new Error((data && data.message) ? data.message : 'Incorrect password.');
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(error.message || 'Unable to verify password.');
                    });
                }
            }).then(function (passwordResult) {
                if (passwordResult.isConfirmed) {
                    const group = journalTransactionGroups[groupKey];
                    const sourceTable = String((group && group.source_table) ? group.source_table : '').toLowerCase();
                    const transactionType = String((group && group.transaction_type) ? group.transaction_type : '').toLowerCase();
                    const isManualJournal = sourceTable === 'journal_entries' || transactionType.includes('journal');

                    // AMGC_JOURNAL_INLINE_MANUAL_EDIT_PATCH_V22
                    // If this is a manual Journal Entry, edit it here using the existing filled table.
                    // Other source transactions still open their own source page.
                    if (isManualJournal || !group || !group.edit_url) {
                        openJournalTransactionInsidePage(groupKey, 'edit');
                    } else {
                        openJournalSourcePage(groupKey);
                    }
                }
            });
        });
    });

    document.querySelectorAll('.journal-attachment-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            let attachments = [];

            try {
                attachments = JSON.parse(this.getAttribute('data-attachments') || '[]');
            } catch (error) {
                attachments = [];
            }

            if (!attachments.length) {
                showAMGCSwal({
                    icon: 'info',
                    title: 'No Attachment',
                    text: 'No attachments found for this transaction.'
                });
                return;
            }

            if (attachments.length === 1) {
                openJournalFilePreview(attachments[0], attachments[0]?.name || 'Attachment');
                return;
            }

            showJournalAttachmentChooser(attachments);
        });
    });

    const closeAttachmentViewerBtn = document.getElementById('closeAttachmentViewerBtn');
    if (closeAttachmentViewerBtn) {
        closeAttachmentViewerBtn.addEventListener('click', function () {
            const viewer = document.getElementById('journalAttachmentViewer');
            const viewerBody = document.getElementById('journalAttachmentViewerBody');
            if (viewer) viewer.classList.remove('show');
            if (viewerBody) viewerBody.innerHTML = '';
        });
    }

    <?php if ($journal_success_message !== ''): ?>
    showAMGCSwal({
        icon: 'success',
        title: 'Success',
        text: <?php echo json_encode($journal_success_message); ?>,
        timer: 1200,
        timerProgressBar: true,
        showConfirmButton: false,
        allowOutsideClick: false,
        allowEscapeKey: false
    }).then(function () {
        const redirectUrl = <?php echo json_encode($journal_success_redirect); ?>;
        if (redirectUrl) {
            window.location.href = redirectUrl;
        }
    });
    <?php endif; ?>

    <?php if ($journal_error_message !== ''): ?>
    showAMGCSwal({
        icon: 'error',
        title: 'Error',
        text: <?php echo json_encode($journal_error_message); ?>
    });
    <?php endif; ?>
});

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