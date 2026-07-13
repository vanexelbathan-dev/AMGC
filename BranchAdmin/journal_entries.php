<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

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
            $chart_query .= " AND (branch_id = " . (int)$branch_id . " OR branch_id = 0 OR branch_id IS NULL)";
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
            $supplier_query .= " AND (branch_id = " . (int)$branch_id . " OR branch_id = 0 OR branch_id IS NULL)";
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
            $customer_query .= " AND (branch_id = " . (int)$branch_id . " OR branch_id = 0 OR branch_id IS NULL)";
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
            $employee_query .= " AND (branch_id = " . (int)$branch_id . " OR branch_id = 0 OR branch_id IS NULL)";
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
    echo '<style>body{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8fafc;margin:0}.amgc-swal-popup{border-radius:18px!important;padding:1.5rem!important;box-shadow:0 18px 45px rgba(15,23,42,.18)!important;border:1px solid rgba(5,150,105,.12)!important}.amgc-swal-title{color:#064e3b!important;font-weight:700!important}.amgc-swal-html{color:#475569!important}.amgc-swal-confirm{background:linear-gradient(135deg,#44D34E,#047857)!important;border:none!important;border-radius:10px!important;padding:.65rem 1.25rem!important;font-weight:700!important;color:#fff!important}</style>';
    echo '</head><body>';
    echo '<script>';
    echo 'document.addEventListener("DOMContentLoaded",function(){Swal.fire({icon:"success",title:"Success",text:' . $safeMessage . ',timer:1200,timerProgressBar:true,showConfirmButton:false,allowOutsideClick:false,allowEscapeKey:false,customClass:{popup:"amgc-swal-popup",title:"amgc-swal-title",htmlContainer:"amgc-swal-html",confirmButton:"amgc-swal-confirm"}}).then(function(){window.location.href=' . $safeRedirect . ';});setTimeout(function(){window.location.href=' . $safeRedirect . ';},1500);});';
    echo '</script></body></html>';
}

function findJournalAccount(mysqli $conn, string $accountTitle, int $branchId, bool $viewAllBranches): ?array {
    $sql = "SELECT account_id, account_title FROM chart_of_accounts WHERE status = 'active' AND account_title = ?";
    if (!$viewAllBranches && $branchId > 0 && journalColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
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
        $_SESSION['journal_success_redirect'] = ($saveAction === 'close') ? 'branchdashboard.php' : '';

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

    if ($type === 'bill') return 'Enter Bills';
    if ($type === 'credit') return 'Enter Bills - Credits';
    if (in_array($type, ['bill payment', 'pay bill', 'pay bills'], true)) return 'Pay Bills';
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

    // Keep paths that are already valid relative paths from this Branch Admin page.
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
                $itemPath = (string)($item['file_path'] ?? $item['path'] ?? $item['url'] ?? $item['attachment_path'] ?? $item['attachment'] ?? '');
                $itemName = (string)($item['file_name'] ?? $item['name'] ?? $item['original_name'] ?? basename($itemPath));
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
    $attachments = [];

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
    $hasAccountName = journalColumnExists($conn, 'chart_account_transactions', 'account_name');
    $hasSourceTable = journalColumnExists($conn, 'chart_account_transactions', 'source_table');
    $hasSourceId = journalColumnExists($conn, 'chart_account_transactions', 'source_id');
    $hasCreatedAt = journalColumnExists($conn, 'chart_account_transactions', 'created_at');
    $hasBranchId = journalColumnExists($conn, 'chart_account_transactions', 'branch_id');
    $hasChartAccounts = journalTableExists($conn, 'chart_of_accounts');
    $hasCatCounterparty = journalColumnExists($conn, 'chart_account_transactions', 'counterparty');
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
            {$createdAtExpr} AS created_at
        FROM chart_account_transactions cat";

    if ($hasChartAccounts) {
        $journal_sql .= " LEFT JOIN chart_of_accounts coa ON coa.account_id = cat.account_id";
    }

    $journal_sql .= " WHERE 1=1";
    if (!$view_all_branches && $branch_id > 0 && $hasBranchId) {
        $journal_sql .= " AND cat.branch_id = " . (int)$branch_id;
    }

    $journal_sql .= " ORDER BY cat.transaction_date DESC, {$createdAtExpr} DESC, cat.transaction_no DESC, cat.transaction_id ASC LIMIT 500";

    $journal_result = $conn->query($journal_sql);
    if ($journal_result) {
        while ($row = $journal_result->fetch_assoc()) {
            $row['section_label'] = journalEntrySectionLabel($row);
            $row['attachments'] = journalGetTransactionAttachments($conn, $row);
            $journal_entries_list[] = $row;
        }
    }
}

$journal_section_order = [
    'Enter Bills' => 1,
    'Enter Bills - Credits' => 2,
    'Pay Bills' => 3,
    'Create Invoice' => 4,
    'Customer Credit' => 5,
    'Receive Payment' => 6,
    'Record Deposits' => 7,
    'Write Checks' => 8,
    'Enter Time (Employees)' => 9,
    'Motorpool' => 10,
    'Journal Entry' => 11
];

usort($journal_entries_list, function($a, $b) use ($journal_section_order) {
    $sa = $a['section_label'] ?? 'Journal Entry';
    $sb = $b['section_label'] ?? 'Journal Entry';
    $oa = $journal_section_order[$sa] ?? 999;
    $ob = $journal_section_order[$sb] ?? 999;
    if ($oa !== $ob) return $oa <=> $ob;

    $da = strtotime((string)($a['transaction_date'] ?? '')) ?: 0;
    $db = strtotime((string)($b['transaction_date'] ?? '')) ?: 0;
    if ($da !== $db) return $db <=> $da;

    // Keep rows from the same transaction together, then show Debit rows before Credit rows.
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

    if ($groupA !== $groupB) {
        $ta = trim((string)($a['transaction_no'] ?? ''));
        $tb = trim((string)($b['transaction_no'] ?? ''));
        if ($ta !== $tb) return strnatcasecmp($tb, $ta);
        return ((int)($a['transaction_id'] ?? 0)) <=> ((int)($b['transaction_id'] ?? 0));
    }

    $aIsDebit = (float)($a['debit'] ?? 0) > 0 ? 0 : 1;
    $bIsDebit = (float)($b['debit'] ?? 0) > 0 ? 0 : 1;
    if ($aIsDebit !== $bIsDebit) return $aIsDebit <=> $bIsDebit;

    return ((int)($a['transaction_id'] ?? 0)) <=> ((int)($b['transaction_id'] ?? 0));
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entries - Branch Admin Dashboard</title>
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
                                <a class="nav-link" href="Withdrawal.php">
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
                                            ?>
                                            <tr>
                                                <td><?php echo $showTransaction ? '&gt; ' . htmlspecialchars($transactionKey) : ''; ?></td>
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
    $journal_account_title_map = [];
    foreach ($chart_accounts_list as $account) {
        $title = trim((string)($account['account_title'] ?? ''));
        if ($title === '') continue;
        $label = trim((string)($account['account_code'] ?? '')) !== ''
            ? trim((string)$account['account_code']) . ' - ' . $title
            : $title;
        $journal_account_title_map[strtolower($title)] = [
            'value' => $title,
            'label' => $label,
            'type' => trim((string)($account['account_type'] ?? ''))
        ];
    }
    uasort($journal_account_title_map, function($a, $b) {
        return strcasecmp($a['label'], $b['label']);
    });
    echo json_encode(array_values($journal_account_title_map), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            option.type = 'button';
            option.className = 'qb-account-option';
            option.dataset.value = account.value;
            option.innerHTML = `<span class="qb-account-option-label">${escapeHtml(account.label || account.value)}</span><small>${escapeHtml(account.type || '')}</small>`;
            option.addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                input.value = account.value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                closeExpenseAccountDropdown();
                input.focus();
            });
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
    </script>
    </body>
    </html>