<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!file_exists('../config/database.php')) {
    die('Database configuration file not found. Please ensure ../config/database.php exists.');
}
require_once '../config/database.php';

if (file_exists('../config/session_handler.php')) {
    require_once '../config/session_handler.php';
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit();
}

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
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
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = false;
$_SESSION['view_all_branches'] = false;

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensureMotorpoolBranchContext(mysqli $conn): array {
    $branchName = 'Motorpool';

    if (tfTableExists($conn, 'branches')) {
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

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') {
        $user_initials .= strtoupper(substr($part, 0, 1));
    }
}
$user_initials = $user_initials ?: 'MP';

function tfTableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function tfColumnExists(mysqli $conn, string $table, string $column): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    if ($safeTable === '' || $safeColumn === '') return false;
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

[$branch_id, $branch_name] = ensureMotorpoolBranchContext($conn);
$_SESSION['branch_id'] = $branch_id;
$_SESSION['branch_name'] = $branch_name;
$_SESSION['view_all_branches'] = false;
$view_all_branches = false;

function ensureTransferFundsTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `fund_transfers` (
        `transfer_id` INT(11) NOT NULL AUTO_INCREMENT,
        `transfer_no` VARCHAR(100) NOT NULL,
        `transfer_date` DATE NOT NULL,
        `from_account_id` INT(11) NOT NULL,
        `from_account_title` VARCHAR(255) NOT NULL,
        `to_account_id` INT(11) NOT NULL,
        `to_account_title` VARCHAR(255) NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `memo` TEXT DEFAULT NULL,
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Online Transfer',
        `check_bank_branch` VARCHAR(255) DEFAULT NULL,
        `check_no` VARCHAR(100) DEFAULT NULL,
        `online_reference_no` VARCHAR(150) DEFAULT NULL,
        `attachment_paths` TEXT DEFAULT NULL,
        `is_online_transfer` TINYINT(1) NOT NULL DEFAULT 0,
        `branch_id` INT(11) NOT NULL DEFAULT 0,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`transfer_id`),
        UNIQUE KEY `transfer_no` (`transfer_no`),
        KEY `from_account_id` (`from_account_id`),
        KEY `to_account_id` (`to_account_id`),
        KEY `branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $transferColumns = [
        'transfer_no' => "ALTER TABLE `fund_transfers` ADD COLUMN `transfer_no` VARCHAR(100) NOT NULL AFTER `transfer_id`",
        'transfer_date' => "ALTER TABLE `fund_transfers` ADD COLUMN `transfer_date` DATE NOT NULL AFTER `transfer_no`",
        'from_account_id' => "ALTER TABLE `fund_transfers` ADD COLUMN `from_account_id` INT(11) NOT NULL DEFAULT 0 AFTER `transfer_date`",
        'from_account_title' => "ALTER TABLE `fund_transfers` ADD COLUMN `from_account_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `from_account_id`",
        'to_account_id' => "ALTER TABLE `fund_transfers` ADD COLUMN `to_account_id` INT(11) NOT NULL DEFAULT 0 AFTER `from_account_title`",
        'to_account_title' => "ALTER TABLE `fund_transfers` ADD COLUMN `to_account_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `to_account_id`",
        'amount' => "ALTER TABLE `fund_transfers` ADD COLUMN `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `to_account_title`",
        'memo' => "ALTER TABLE `fund_transfers` ADD COLUMN `memo` TEXT DEFAULT NULL AFTER `amount`",
        'payment_method' => "ALTER TABLE `fund_transfers` ADD COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Online Transfer' AFTER `memo`",
        'check_bank_branch' => "ALTER TABLE `fund_transfers` ADD COLUMN `check_bank_branch` VARCHAR(255) DEFAULT NULL AFTER `payment_method`",
        'check_no' => "ALTER TABLE `fund_transfers` ADD COLUMN `check_no` VARCHAR(100) DEFAULT NULL AFTER `check_bank_branch`",
        'online_reference_no' => "ALTER TABLE `fund_transfers` ADD COLUMN `online_reference_no` VARCHAR(150) DEFAULT NULL AFTER `check_no`",
        'attachment_paths' => "ALTER TABLE `fund_transfers` ADD COLUMN `attachment_paths` TEXT DEFAULT NULL AFTER `online_reference_no`",
        'is_online_transfer' => "ALTER TABLE `fund_transfers` ADD COLUMN `is_online_transfer` TINYINT(1) NOT NULL DEFAULT 0 AFTER `attachment_paths`",
        'branch_id' => "ALTER TABLE `fund_transfers` ADD COLUMN `branch_id` INT(11) NOT NULL DEFAULT 0 AFTER `is_online_transfer`",
        'created_by' => "ALTER TABLE `fund_transfers` ADD COLUMN `created_by` INT(11) NOT NULL DEFAULT 0 AFTER `branch_id`",
        'created_at' => "ALTER TABLE `fund_transfers` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`"
    ];
    foreach ($transferColumns as $column => $sql) {
        if (!tfColumnExists($conn, 'fund_transfers', $column)) {
            $conn->query($sql);
        }
    }
    if (!tfColumnExists($conn, 'fund_transfers', 'transfer_no')) {
        $conn->query("ALTER TABLE `fund_transfers` ADD UNIQUE KEY `transfer_no` (`transfer_no`)");
    }

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
        `account_id` INT(11) NOT NULL,
        `branch_id` INT(11) NOT NULL DEFAULT 0,
        `transaction_date` DATE DEFAULT NULL,
        `transaction_type` VARCHAR(50) DEFAULT NULL,
        `transaction_no` VARCHAR(100) DEFAULT NULL,
        `reference_no` VARCHAR(100) DEFAULT NULL,
        `memo` TEXT DEFAULT NULL,
        `account_name` VARCHAR(255) DEFAULT NULL,
        `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `source_table` VARCHAR(100) DEFAULT NULL,
        `source_id` INT(11) DEFAULT NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`transaction_id`),
        KEY `account_id` (`account_id`),
        KEY `branch_id` (`branch_id`),
        KEY `source_table` (`source_table`),
        KEY `source_id` (`source_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function getAccountById(mysqli $conn, int $accountId, int $branchId, bool $viewAllBranches): ?array {
    $sql = "SELECT account_id, account_title, account_type, balance, branch_id FROM chart_of_accounts WHERE account_id = ? AND status = 'active' AND account_type = 'Bank'";
    if (!$viewAllBranches && $branchId > 0 && tfColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('ii', $accountId, $branchId);
    } else {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $accountId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function tfAccountHasSubAccounts(mysqli $conn, int $accountId, int $branchId, bool $viewAllBranches): bool {
    // AMGC_TRANSFER_FUNDS_SUB_ACCOUNT_SELECTION_FIX_V21
    // Only block top-level parent Bank accounts. Child/sub accounts are allowed even if
    // the chart hierarchy gives them lower-level children, because users select those as
    // the operating bank accounts in Transfer Funds.
    if ($accountId <= 0 || !tfTableExists($conn, 'chart_of_accounts') || !tfColumnExists($conn, 'chart_of_accounts', 'parent_account_id')) {
        return false;
    }

    $parentSql = "SELECT COALESCE(parent_account_id, 0) AS parent_account_id FROM chart_of_accounts WHERE account_id = ?";
    if (!$viewAllBranches && $branchId > 0 && tfColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $parentSql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
        $parentStmt = $conn->prepare($parentSql . " LIMIT 1");
        if (!$parentStmt) return false;
        $parentStmt->bind_param('ii', $accountId, $branchId);
    } else {
        $parentStmt = $conn->prepare($parentSql . " LIMIT 1");
        if (!$parentStmt) return false;
        $parentStmt->bind_param('i', $accountId);
    }
    $parentStmt->execute();
    $parentRow = $parentStmt->get_result()->fetch_assoc();
    $parentStmt->close();

    // If it already has a parent, treat it as selectable sub account.
    if ((int)($parentRow['parent_account_id'] ?? 0) > 0) {
        return false;
    }

    $sql = "SELECT COUNT(*) AS total FROM chart_of_accounts WHERE parent_account_id = ? AND status = 'active'";
    if (!$viewAllBranches && $branchId > 0 && tfColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ii', $accountId, $branchId);
    } else {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('i', $accountId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function generateTransferNo(mysqli $conn): string {
    $prefix = 'TF-' . date('Ymd') . '-';
    $next = 1;
    if (tfTableExists($conn, 'fund_transfers')) {
        $stmt = $conn->prepare("SELECT transfer_no FROM fund_transfers WHERE transfer_no LIKE ? ORDER BY transfer_no DESC LIMIT 1");
        if ($stmt) {
            $like = $prefix . '%';
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && !empty($row['transfer_no'])) {
                $last = (int)substr($row['transfer_no'], strlen($prefix));
                $next = max($next, $last + 1);
            }
            $stmt->close();
        }
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function saveTransferAttachments(array $files, string $transferNo): array {
    if (empty($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $uploadDir = __DIR__ . '/../uploads/transfer_funds/';
    $publicDir = '../uploads/transfer_funds/';

    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new Exception('Attachment upload folder is not writable.');
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $saved = [];
    $safeTransferNo = preg_replace('/[^A-Za-z0-9_-]/', '_', $transferNo);

    foreach ($files['name'] as $index => $originalName) {
        if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if (($files['error'][$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('Unable to upload one of the attachments.');
        }

        $tmpName = $files['tmp_name'][$index] ?? '';
        $size = (int)($files['size'][$index] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new Exception('Invalid attachment upload.');
        }

        if ($size > 10 * 1024 * 1024) {
            throw new Exception('Each attachment must not exceed 10MB.');
        }

        $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new Exception('Attachment file type is not allowed.');
        }

        $baseName = pathinfo((string)$originalName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName);
        $baseName = trim($baseName, '_');
        if ($baseName === '') {
            $baseName = 'attachment';
        }

        $fileName = $safeTransferNo . '_' . date('YmdHis') . '_' . $index . '_' . $baseName . '.' . $extension;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new Exception('Failed to save attachment.');
        }

        $saved[] = $publicDir . $fileName;
    }

    return $saved;
}

ensureTransferFundsTables($conn);

$success_message = $_SESSION['transfer_success_message'] ?? '';
$success_redirect = $_SESSION['transfer_success_redirect'] ?? '';
$error_message = '';
unset($_SESSION['transfer_success_message'], $_SESSION['transfer_success_redirect']);

$generated_transfer_no = generateTransferNo($conn);

// ========== JOURNAL ENTRIES EDIT SUPPORT (AMGC_TRANSFER_FUNDS_JOURNAL_EDIT_PATCH_V21) ==========
$journal_edit_transfer_id = 0;
foreach (['transfer_id', 'source_id', 'journal_source_id', 'id'] as $tfEditParam) {
    if (isset($_GET[$tfEditParam]) && (int)$_GET[$tfEditParam] > 0) {
        $journal_edit_transfer_id = (int)$_GET[$tfEditParam];
        break;
    }
}
if ($journal_edit_transfer_id <= 0 && isset($_GET['transaction_id']) && tfTableExists($conn, 'chart_account_transactions')) {
    $catId = (int)$_GET['transaction_id'];
    $stmt = $conn->prepare("SELECT source_id FROM chart_account_transactions WHERE transaction_id = ? AND source_table = 'fund_transfers' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $catId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) $journal_edit_transfer_id = (int)($row['source_id'] ?? 0);
    }
}
if ($journal_edit_transfer_id <= 0 && !empty($_GET['transaction_no']) && tfTableExists($conn, 'fund_transfers')) {
    $txNo = trim((string)$_GET['transaction_no']);
    $stmt = $conn->prepare("SELECT transfer_id FROM fund_transfers WHERE transfer_no = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $txNo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) $journal_edit_transfer_id = (int)($row['transfer_id'] ?? 0);
    }
}

$journal_edit_transfer = null;
$is_journal_edit_mode = $journal_edit_transfer_id > 0;
if ($is_journal_edit_mode && tfTableExists($conn, 'fund_transfers')) {
    $sql = "SELECT * FROM fund_transfers WHERE transfer_id = ?";
    if (!$view_all_branches && $branch_id > 0 && tfColumnExists($conn, 'fund_transfers', 'branch_id')) {
        $sql .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ii', $journal_edit_transfer_id, $branch_id);
        }
    } else {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $journal_edit_transfer_id);
        }
    }
    if (isset($stmt) && $stmt) {
        $stmt->execute();
        $journal_edit_transfer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($journal_edit_transfer) {
        $generated_transfer_no = (string)($journal_edit_transfer['transfer_no'] ?? $generated_transfer_no);
    } else {
        $is_journal_edit_mode = false;
        $journal_edit_transfer_id = 0;
    }
}

$tf_form_transfer_date = $journal_edit_transfer ? (string)($journal_edit_transfer['transfer_date'] ?? date('Y-m-d')) : date('Y-m-d');
$tf_form_from_account_id = $journal_edit_transfer ? (int)($journal_edit_transfer['from_account_id'] ?? 0) : 0;
$tf_form_to_account_id = $journal_edit_transfer ? (int)($journal_edit_transfer['to_account_id'] ?? 0) : 0;
$tf_form_amount = $journal_edit_transfer ? number_format((float)($journal_edit_transfer['amount'] ?? 0), 2) : '';
$tf_form_memo = $journal_edit_transfer ? (string)($journal_edit_transfer['memo'] ?? 'Funds Transfer') : 'Funds Transfer';
$tf_form_payment_method = $journal_edit_transfer ? (string)($journal_edit_transfer['payment_method'] ?? 'Online Transfer') : 'Online Transfer';
if (!in_array($tf_form_payment_method, ['Cash', 'Check', 'Online Transfer'], true)) $tf_form_payment_method = 'Online Transfer';
$tf_form_check_bank_branch = $journal_edit_transfer ? (string)($journal_edit_transfer['check_bank_branch'] ?? '') : '';
$tf_form_check_no = $journal_edit_transfer ? (string)($journal_edit_transfer['check_no'] ?? '') : '';
$tf_form_online_reference_no = $journal_edit_transfer ? (string)($journal_edit_transfer['online_reference_no'] ?? '') : '';

$accounts = [];
if (tfTableExists($conn, 'chart_of_accounts')) {
    $accountSql = "SELECT account_id, account_code, account_title, account_type, balance, parent_account_id
                   FROM chart_of_accounts
                   WHERE status = 'active'
                     AND account_type = 'Bank'";
    if (!$view_all_branches && $branch_id > 0 && tfColumnExists($conn, 'chart_of_accounts', 'branch_id')) {
        $accountSql .= " AND (branch_id = " . (int)$branch_id . " OR branch_id = 0 OR branch_id IS NULL)";
    }
    $accountSql .= " ORDER BY 
        CASE 
            WHEN LOWER(account_type) LIKE '%bank%' THEN 0
            WHEN LOWER(account_type) LIKE '%cash%' THEN 1
            ELSE 2
        END,
        account_code ASC, account_title ASC";
    $accountResult = $conn->query($accountSql);
    if ($accountResult) {
        while ($row = $accountResult->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
}



function tfBuildAccountDropdownRows(array $accounts): array {
    $children = [];
    $byId = [];

    foreach ($accounts as $account) {
        $id = (int)($account['account_id'] ?? 0);
        if ($id <= 0) continue;
        $parent = (int)($account['parent_account_id'] ?? 0);
        $account['_level'] = 0;
        $account['_has_children'] = false;
        $account['_amount_display'] = '₱' . number_format((float)($account['balance'] ?? 0), 2);
        $byId[$id] = $account;
        if (!isset($children[$parent])) $children[$parent] = [];
        $children[$parent][] = $id;
    }

    foreach ($children as $parentId => $childIds) {
        if ((int)$parentId > 0 && isset($byId[(int)$parentId])) {
            $byId[(int)$parentId]['_has_children'] = true;
        }
    }

    $sortChildIds = function(array &$ids) use (&$byId) {
        usort($ids, function($a, $b) use (&$byId) {
            $codeA = trim((string)($byId[$a]['account_code'] ?? ''));
            $codeB = trim((string)($byId[$b]['account_code'] ?? ''));
            $codeCompare = strcasecmp($codeA, $codeB);
            if ($codeCompare !== 0) return $codeCompare;
            $titleCompare = strcasecmp((string)($byId[$a]['account_title'] ?? ''), (string)($byId[$b]['account_title'] ?? ''));
            if ($titleCompare !== 0) return $titleCompare;
            return ((int)$a) <=> ((int)$b);
        });
    };

    foreach ($children as &$childIds) {
        $sortChildIds($childIds);
    }
    unset($childIds);

    $ordered = [];
    $seen = [];
    $walk = function(int $parentId, int $level) use (&$walk, &$children, &$byId, &$ordered, &$seen) {
        if (empty($children[$parentId])) return;
        foreach ($children[$parentId] as $childId) {
            if (!isset($byId[$childId]) || isset($seen[$childId])) continue;
            $row = $byId[$childId];
            $row['_level'] = max(0, $level);
            $row['_has_children'] = !empty($children[$childId]);
            $ordered[] = $row;
            $seen[$childId] = true;
            $walk((int)$childId, $level + 1);
        }
    };

    $walk(0, 0);

    foreach ($byId as $id => $account) {
        if (isset($seen[$id])) continue;
        $row = $account;
        $row['_level'] = 0;
        $row['_has_children'] = !empty($children[$id]);
        $ordered[] = $row;
        $seen[$id] = true;
        $walk((int)$id, 1);
    }

    return $ordered;
}

$account_dropdown_rows = tfBuildAccountDropdownRows($accounts);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transfer'])) {
    $transferNo = trim((string)($_POST['transfer_no'] ?? $generated_transfer_no));
    $editTransferId = (int)($_POST['journal_edit_transfer_id'] ?? 0);
    $transferDate = trim((string)($_POST['transfer_date'] ?? date('Y-m-d')));
    $fromAccountId = (int)($_POST['from_account_id'] ?? 0);
    $toAccountId = (int)($_POST['to_account_id'] ?? 0);
    $amount = round((float)str_replace(',', '', (string)($_POST['amount'] ?? '0')), 2);
    $memo = trim((string)($_POST['memo'] ?? 'Funds Transfer'));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Online Transfer'));
    $checkBankBranch = trim((string)($_POST['check_bank_branch'] ?? ''));
    $checkNo = trim((string)($_POST['check_no'] ?? ''));
    $onlineReferenceNo = trim((string)($_POST['online_reference_no'] ?? ''));
    $allowedPaymentMethods = ['Cash', 'Check', 'Online Transfer'];
    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $paymentMethod = 'Online Transfer';
    }
    $isOnline = ($paymentMethod === 'Online Transfer') ? 1 : 0;
    if ($paymentMethod !== 'Check') {
        $checkBankBranch = '';
        $checkNo = '';
    }
    if ($paymentMethod !== 'Online Transfer') {
        $onlineReferenceNo = '';
    }
    $saveAction = trim((string)($_POST['save_action'] ?? 'new'));
    if (!in_array($saveAction, ['new', 'close'], true)) $saveAction = 'new';

    if ($transferNo === '') {
        $error_message = 'Transfer No. is required.';
    } elseif ($transferDate === '' || !strtotime($transferDate)) {
        $error_message = 'Valid Date is required.';
    } elseif ($fromAccountId <= 0) {
        $error_message = 'Transfer Funds From is required.';
    } elseif ($toAccountId <= 0) {
        $error_message = 'Transfer Funds To is required.';
    } elseif ($fromAccountId === $toAccountId) {
        $error_message = 'Transfer Funds From and Transfer Funds To must be different.';
    } elseif ($amount <= 0) {
        $error_message = 'Transfer Amount must be greater than zero.';
    } elseif (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $error_message = 'Please select a valid payment method.';
    } elseif ($paymentMethod === 'Check' && $checkBankBranch === '') {
        $error_message = 'Bank/Branch is required for Check payment method.';
    } elseif ($paymentMethod === 'Check' && $checkNo === '') {
        $error_message = 'Check No. is required for Check payment method.';
    } elseif ($paymentMethod === 'Online Transfer' && $onlineReferenceNo === '') {
        $error_message = 'Reference Number is required for Online Transfer payment method.';
    } else {
        $fromAccount = getAccountById($conn, $fromAccountId, $branch_id, $view_all_branches);
        $toAccount = getAccountById($conn, $toAccountId, $branch_id, $view_all_branches);

        if (!$fromAccount) {
            $error_message = 'Selected From bank account was not found.';
        } elseif (!$toAccount) {
            $error_message = 'Selected To bank account was not found.';
        } elseif (tfAccountHasSubAccounts($conn, $fromAccountId, $branch_id, $view_all_branches)) {
            $error_message = 'Transfer Funds From cannot be an account that has sub accounts.';
        } elseif (tfAccountHasSubAccounts($conn, $toAccountId, $branch_id, $view_all_branches)) {
            $error_message = 'Transfer Funds To cannot be an account that has sub accounts.';
        } elseif ($editTransferId > 0) {
            $oldStmt = $conn->prepare("SELECT * FROM fund_transfers WHERE transfer_id = ? LIMIT 1");
            if (!$oldStmt) {
                $error_message = 'Unable to load transfer for editing.';
            } else {
                $oldStmt->bind_param('i', $editTransferId);
                $oldStmt->execute();
                $oldTransfer = $oldStmt->get_result()->fetch_assoc();
                $oldStmt->close();

                if (!$oldTransfer) {
                    $error_message = 'Transfer transaction was not found.';
                } else {
                    $conn->begin_transaction();
                    try {
                        $oldFromAccountId = (int)($oldTransfer['from_account_id'] ?? 0);
                        $oldToAccountId = (int)($oldTransfer['to_account_id'] ?? 0);
                        $oldAmount = round((float)($oldTransfer['amount'] ?? 0), 2);
                        $oldTransferNo = (string)($oldTransfer['transfer_no'] ?? $transferNo);
                        $oldAttachmentPaths = (string)($oldTransfer['attachment_paths'] ?? '');

                        $fromTitle = (string)$fromAccount['account_title'];
                        $toTitle = (string)$toAccount['account_title'];
                        $finalMemo = $memo !== '' ? $memo : 'Funds Transfer';
                        $paymentDetails = '';
                        if ($paymentMethod === 'Check') {
                            $paymentDetails = ' | Bank/Branch: ' . $checkBankBranch . ' | Check No.: ' . $checkNo;
                        } elseif ($paymentMethod === 'Online Transfer') {
                            $paymentDetails = ' | Reference No.: ' . $onlineReferenceNo;
                        }
                        $journalMemo = $finalMemo . ' | Payment Method: ' . $paymentMethod . $paymentDetails;
                        $savedAttachments = saveTransferAttachments($_FILES['attachments'] ?? [], $oldTransferNo);
                        $attachmentPaths = !empty($savedAttachments) ? json_encode($savedAttachments, JSON_UNESCAPED_SLASHES) : ($oldAttachmentPaths !== '' ? $oldAttachmentPaths : null);

                        // Reverse old balance impact, then apply the updated transfer impact.
                        if ($oldFromAccountId > 0 && $oldAmount > 0) {
                            $reverseOldFrom = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance,0) + ? WHERE account_id = ?");
                            if ($reverseOldFrom) {
                                $reverseOldFrom->bind_param('di', $oldAmount, $oldFromAccountId);
                                $reverseOldFrom->execute();
                                $reverseOldFrom->close();
                            }
                        }
                        if ($oldToAccountId > 0 && $oldAmount > 0) {
                            $reverseOldTo = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance,0) - ? WHERE account_id = ?");
                            if ($reverseOldTo) {
                                $reverseOldTo->bind_param('di', $oldAmount, $oldToAccountId);
                                $reverseOldTo->execute();
                                $reverseOldTo->close();
                            }
                        }

                        $applyFrom = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance,0) - ? WHERE account_id = ?");
                        if ($applyFrom) {
                            $applyFrom->bind_param('di', $amount, $fromAccountId);
                            $applyFrom->execute();
                            $applyFrom->close();
                        }
                        $applyTo = $conn->prepare("UPDATE chart_of_accounts SET balance = COALESCE(balance,0) + ? WHERE account_id = ?");
                        if ($applyTo) {
                            $applyTo->bind_param('di', $amount, $toAccountId);
                            $applyTo->execute();
                            $applyTo->close();
                        }

                        $fromBalanceAfter = 0.00;
                        $toBalanceAfter = 0.00;
                        $balStmt = $conn->prepare("SELECT account_id, balance FROM chart_of_accounts WHERE account_id IN (?, ?)");
                        if ($balStmt) {
                            $balStmt->bind_param('ii', $fromAccountId, $toAccountId);
                            $balStmt->execute();
                            $balRows = $balStmt->get_result();
                            while ($bal = $balRows->fetch_assoc()) {
                                if ((int)$bal['account_id'] === $fromAccountId) $fromBalanceAfter = (float)$bal['balance'];
                                if ((int)$bal['account_id'] === $toAccountId) $toBalanceAfter = (float)$bal['balance'];
                            }
                            $balStmt->close();
                        }

                        $updateTransfer = $conn->prepare("UPDATE fund_transfers SET transfer_date = ?, from_account_id = ?, from_account_title = ?, to_account_id = ?, to_account_title = ?, amount = ?, memo = ?, payment_method = ?, check_bank_branch = ?, check_no = ?, online_reference_no = ?, attachment_paths = ?, is_online_transfer = ? WHERE transfer_id = ?");
                        if (!$updateTransfer) throw new Exception('Unable to prepare transfer update.');
                        $updateTransfer->bind_param('sisissssssssii', $transferDate, $fromAccountId, $fromTitle, $toAccountId, $toTitle, $amount, $finalMemo, $paymentMethod, $checkBankBranch, $checkNo, $onlineReferenceNo, $attachmentPaths, $isOnline, $editTransferId);
                        if (!$updateTransfer->execute()) throw new Exception($updateTransfer->error ?: 'Unable to update transfer.');
                        $updateTransfer->close();

                        $referenceNo = ($paymentMethod === 'Online Transfer' && $onlineReferenceNo !== '') ? $onlineReferenceNo : (($paymentMethod === 'Check' && $checkNo !== '') ? $checkNo : ('Transfer #' . $editTransferId));

                        if (tfTableExists($conn, 'chart_account_transactions')) {
                            $delCat = $conn->prepare("DELETE FROM chart_account_transactions WHERE source_table = 'fund_transfers' AND source_id = ?");
                            if ($delCat) {
                                $delCat->bind_param('i', $editTransferId);
                                $delCat->execute();
                                $delCat->close();
                            }

                            $transactionStmt = $conn->prepare("INSERT INTO chart_account_transactions (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, debit, credit, balance_after, source_table, source_id, created_by) VALUES (?, ?, ?, 'Transfer Funds', ?, ?, ?, ?, ?, ?, ?, 'fund_transfers', ?, ?)");
                            if ($transactionStmt) {
                                $debit = 0.00;
                                $credit = $amount;
                                $transactionStmt->bind_param('iisssssdddii', $fromAccountId, $branch_id, $transferDate, $oldTransferNo, $referenceNo, $journalMemo, $fromTitle, $debit, $credit, $fromBalanceAfter, $editTransferId, $user_id);
                                if (!$transactionStmt->execute()) throw new Exception($transactionStmt->error ?: 'Unable to update From account transaction.');

                                $debit = $amount;
                                $credit = 0.00;
                                $transactionStmt->bind_param('iisssssdddii', $toAccountId, $branch_id, $transferDate, $oldTransferNo, $referenceNo, $journalMemo, $toTitle, $debit, $credit, $toBalanceAfter, $editTransferId, $user_id);
                                if (!$transactionStmt->execute()) throw new Exception($transactionStmt->error ?: 'Unable to update To account transaction.');
                                $transactionStmt->close();
                            }
                        }

                        if (tfTableExists($conn, 'journal_entries')) {
                            $journalId = 0;
                            $journalStmt = $conn->prepare("SELECT journal_id FROM journal_entries WHERE entry_no = ? ORDER BY journal_id DESC LIMIT 1");
                            if ($journalStmt) {
                                $journalStmt->bind_param('s', $oldTransferNo);
                                $journalStmt->execute();
                                $journalRow = $journalStmt->get_result()->fetch_assoc();
                                $journalStmt->close();
                                $journalId = (int)($journalRow['journal_id'] ?? 0);
                            }

                            if ($journalId > 0) {
                                $headerStmt = $conn->prepare("UPDATE journal_entries SET journal_date = ?, attachment_path = ? WHERE journal_id = ?");
                                if ($headerStmt) {
                                    $headerStmt->bind_param('ssi', $transferDate, $attachmentPaths, $journalId);
                                    $headerStmt->execute();
                                    $headerStmt->close();
                                }
                                if (tfTableExists($conn, 'journal_entry_details')) {
                                    $delDetails = $conn->prepare("DELETE FROM journal_entry_details WHERE journal_id = ?");
                                    if ($delDetails) {
                                        $delDetails->bind_param('i', $journalId);
                                        $delDetails->execute();
                                        $delDetails->close();
                                    }
                                }
                            } else {
                                $headerStmt = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, created_by) VALUES (?, ?, ?, ?, ?)");
                                if (!$headerStmt) throw new Exception('Unable to recreate journal header.');
                                $headerStmt->bind_param('sssii', $oldTransferNo, $transferDate, $attachmentPaths, $branch_id, $user_id);
                                if (!$headerStmt->execute()) throw new Exception($headerStmt->error ?: 'Unable to recreate journal header.');
                                $journalId = (int)$conn->insert_id;
                                $headerStmt->close();
                            }

                            if ($journalId > 0 && tfTableExists($conn, 'journal_entry_details')) {
                                $detailStmt = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                if (!$detailStmt) throw new Exception('Unable to update journal details.');
                                $counterparty = 'Transfer Funds';
                                $debit = $amount;
                                $credit = 0.00;
                                $detailStmt->bind_param('iisddss', $journalId, $toAccountId, $toTitle, $debit, $credit, $journalMemo, $counterparty);
                                if (!$detailStmt->execute()) throw new Exception($detailStmt->error ?: 'Unable to update debit entry.');

                                $debit = 0.00;
                                $credit = $amount;
                                $detailStmt->bind_param('iisddss', $journalId, $fromAccountId, $fromTitle, $debit, $credit, $journalMemo, $counterparty);
                                if (!$detailStmt->execute()) throw new Exception($detailStmt->error ?: 'Unable to update credit entry.');
                                $detailStmt->close();
                            }
                        }

                        $conn->commit();
                        $_SESSION['transfer_success_message'] = 'Transfer funds updated successfully.';
                        $_SESSION['transfer_success_redirect'] = 'journal_entries.php';
                        header('Location: transferfunds.php?transfer_id=' . $editTransferId . '&from_journal_entries=1&edit=1');
                        exit();
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error_message = 'Update failed: ' . $e->getMessage();
                    }
                }
            }
        } else {
            $existsStmt = $conn->prepare("SELECT transfer_id FROM fund_transfers WHERE transfer_no = ? LIMIT 1");
            if ($existsStmt) {
                $existsStmt->bind_param('s', $transferNo);
                $existsStmt->execute();
                $existsResult = $existsStmt->get_result();
                if ($existsResult && $existsResult->num_rows > 0) {
                    $error_message = 'Transfer No. already exists. Please refresh the page.';
                }
                $existsStmt->close();
            }

            if ($error_message === '') {
                $conn->begin_transaction();
                try {
                    $fromTitle = (string)$fromAccount['account_title'];
                    $toTitle = (string)$toAccount['account_title'];
                    $finalMemo = $memo !== '' ? $memo : 'Funds Transfer';
                    $paymentDetails = '';
                    if ($paymentMethod === 'Check') {
                        $paymentDetails = ' | Bank/Branch: ' . $checkBankBranch . ' | Check No.: ' . $checkNo;
                    } elseif ($paymentMethod === 'Online Transfer') {
                        $paymentDetails = ' | Reference No.: ' . $onlineReferenceNo;
                    }
                    $journalMemo = $finalMemo . ' | Payment Method: ' . $paymentMethod . $paymentDetails;
                    $savedAttachments = saveTransferAttachments($_FILES['attachments'] ?? [], $transferNo);
                    $attachmentPaths = !empty($savedAttachments) ? json_encode($savedAttachments, JSON_UNESCAPED_SLASHES) : null;
                    $transferStmt = $conn->prepare("INSERT INTO fund_transfers (transfer_no, transfer_date, from_account_id, from_account_title, to_account_id, to_account_title, amount, memo, payment_method, check_bank_branch, check_no, online_reference_no, attachment_paths, is_online_transfer, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$transferStmt) throw new Exception('Unable to prepare transfer save.');
                    $transferStmt->bind_param('ssisisdssssssiii', $transferNo, $transferDate, $fromAccountId, $fromTitle, $toAccountId, $toTitle, $amount, $finalMemo, $paymentMethod, $checkBankBranch, $checkNo, $onlineReferenceNo, $attachmentPaths, $isOnline, $branch_id, $user_id);
                    if (!$transferStmt->execute()) throw new Exception($transferStmt->error ?: 'Unable to save transfer.');
                    $transferId = (int)$conn->insert_id;
                    $transferStmt->close();

                    $entryNo = $transferNo;
                    $headerStmt = $conn->prepare("INSERT INTO journal_entries (entry_no, journal_date, attachment_path, branch_id, created_by) VALUES (?, ?, ?, ?, ?)");
                    if (!$headerStmt) throw new Exception('Unable to prepare journal header.');
                    $headerStmt->bind_param('sssii', $entryNo, $transferDate, $attachmentPaths, $branch_id, $user_id);
                    if (!$headerStmt->execute()) throw new Exception($headerStmt->error ?: 'Unable to save journal header.');
                    $journalId = (int)$conn->insert_id;
                    $headerStmt->close();

                    $detailStmt = $conn->prepare("INSERT INTO journal_entry_details (journal_id, account_id, account_title, debit, credit, memo, counterparty) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if (!$detailStmt) throw new Exception('Unable to prepare journal details.');

                    $counterparty = 'Transfer Funds';
                    $debit = $amount;
                    $credit = 0.00;
                    $detailStmt->bind_param('iisddss', $journalId, $toAccountId, $toTitle, $debit, $credit, $journalMemo, $counterparty);
                    if (!$detailStmt->execute()) throw new Exception($detailStmt->error ?: 'Unable to save debit entry.');

                    $debit = 0.00;
                    $credit = $amount;
                    $detailStmt->bind_param('iisddss', $journalId, $fromAccountId, $fromTitle, $debit, $credit, $journalMemo, $counterparty);
                    if (!$detailStmt->execute()) throw new Exception($detailStmt->error ?: 'Unable to save credit entry.');
                    $detailStmt->close();

                    $newFromBalance = round((float)$fromAccount['balance'] - $amount, 2);
                    $newToBalance = round((float)$toAccount['balance'] + $amount, 2);

                    $updateFrom = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
                    if ($updateFrom) {
                        $updateFrom->bind_param('di', $newFromBalance, $fromAccountId);
                        $updateFrom->execute();
                        $updateFrom->close();
                    }

                    $updateTo = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
                    if ($updateTo) {
                        $updateTo->bind_param('di', $newToBalance, $toAccountId);
                        $updateTo->execute();
                        $updateTo->close();
                    }

                    $transactionStmt = $conn->prepare("INSERT INTO chart_account_transactions (account_id, branch_id, transaction_date, transaction_type, transaction_no, reference_no, memo, account_name, debit, credit, balance_after, source_table, source_id, created_by) VALUES (?, ?, ?, 'Transfer Funds', ?, ?, ?, ?, ?, ?, ?, 'fund_transfers', ?, ?)");
                    if ($transactionStmt) {
                        $referenceNo = ($paymentMethod === 'Online Transfer' && $onlineReferenceNo !== '') ? $onlineReferenceNo : (($paymentMethod === 'Check' && $checkNo !== '') ? $checkNo : ('Transfer #' . $transferId));
                        $debit = 0.00;
                        $credit = $amount;
                        $transactionStmt->bind_param('iisssssdddii', $fromAccountId, $branch_id, $transferDate, $transferNo, $referenceNo, $journalMemo, $fromTitle, $debit, $credit, $newFromBalance, $transferId, $user_id);
                        if (!$transactionStmt->execute()) throw new Exception($transactionStmt->error ?: 'Unable to save From account transaction.');

                        $debit = $amount;
                        $credit = 0.00;
                        $transactionStmt->bind_param('iisssssdddii', $toAccountId, $branch_id, $transferDate, $transferNo, $referenceNo, $journalMemo, $toTitle, $debit, $credit, $newToBalance, $transferId, $user_id);
                        if (!$transactionStmt->execute()) throw new Exception($transactionStmt->error ?: 'Unable to save To account transaction.');
                        $transactionStmt->close();
                    }

                    $conn->commit();

                    $_SESSION['transfer_success_message'] = 'Transfer funds saved successfully.';
                    $_SESSION['transfer_success_redirect'] = ($saveAction === 'close') ? 'motorpool.php' : 'transferfunds.php';
                    header('Location: transferfunds.php');
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error_message = 'Save failed: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Funds - Motorpool</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root{
            --amgc-green:#44D34E;
            --amgc-dark-green:#047857;
            --amgc-navy:#052A47;
            --amgc-sidebar:#062f4f;
            --amgc-muted:#64748b;
        }
        *{box-sizing:border-box}
        body{
            font-family:Inter,"Segoe UI",Arial,sans-serif;
            background:#f8fafc;
            color:#0f172a;
            min-height:100vh;
            overflow-x:hidden;
        }
        .main-content{
            margin-left:292px;
            min-height:100vh;
            padding:22px 24px;
            transition:.25s ease;
        }
        .main-content.sidebar-collapsed{
            margin-left:85px;
        }
        .navbar-top{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:18px;
            padding:18px 22px;
            margin-bottom:18px;
            box-shadow:0 10px 30px rgba(15,23,42,.06);
        }
        .page-title h2{
            font-size:1.55rem;
            font-weight:800;
            color:#052A47;
            margin:0;
        }
        .page-title p{
            color:#64748b;
            margin:.2rem 0 0;
        }

        .transfer-layout{
            width:100%;
            display:grid;
            grid-template-columns:minmax(0,1fr) 335px;
            gap:8px;
            align-items:stretch;
        }
        .attachment-card{
            background:#f0fff4;
            border:2px solid rgba(68,211,78,.42);
            background-image:linear-gradient(45deg, rgba(68,211,78,.08) 25%, transparent 25%),linear-gradient(-45deg, rgba(68,211,78,.08) 25%, transparent 25%),
            linear-gradient(45deg, transparent 75%, rgba(68,211,78,.08) 75%),linear-gradient(-45deg, transparent 75%, rgba(68,211,78,.08) 75%);
            background-size:14px 14px;
            background-position:0 0,0 7px,7px -7px,-7px 0;
            padding:14px;
            min-height:214px;
        }
        .attachment-title{
            color:#052A47;
            font-size:1rem;
            font-weight:800;
            margin:0 0 12px;
            line-height:1.2;
        }
        .attachment-inner{
            background:rgba(255,255,255,.76);
            border:2px dashed rgba(4,120,87,.22);
            border-radius:10px;
            padding:22px 20px;
            min-height:132px;
        }
        .attachment-label{
            color:#047857;
            font-weight:700;
            font-size:.88rem;
            margin-bottom:10px;
            display:flex;
            align-items:center;
            gap:6px;
        }
        .attachment-file{
            height:42px;
            border:1px solid rgba(4,120,87,.25);
            background:#fff;
            border-radius:5px;
            font-size:.88rem;
            width:100%;
        }
        .attachment-help{
            color:#64748b;
            font-size:.8rem;
            line-height:1.3;
            margin:10px 0 0;
        }
        .attachment-selected{
            color:#047857;
            font-size:.78rem;
            font-weight:700;
            margin-top:8px;
            word-break:break-word;
        }
        .transfer-card{
            background:#ecfdf3;
            border:2px solid rgba(68,211,78,.42);
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.72),0 8px 22px rgba(4,120,87,.08);
            padding:16px 20px 14px;
            width:100%;
            min-height:214px;
        }
        .transfer-header{
            display:grid;
            grid-template-columns:minmax(220px,1fr) auto;
            gap:16px;
            align-items:start;
        }
        .transfer-title{
            font-size:1.65rem;
            font-weight:700;
            color:#047857;
            margin:0;
            line-height:1.1;
        }
        .transfer-grid{
            display:grid;
            grid-template-columns:150px minmax(420px,1fr) 240px;
            gap:10px 14px;
            align-items:start;
            margin-top:12px;
        }
        .tf-label{
            text-transform:uppercase;
            font-size:.72rem;
            font-weight:700;
            color:#047857;
            text-align:left;
            margin:0;
        }
        .tf-label.left{
            text-align:left;
            text-transform:none;
            font-size:.8rem;
            color:#047857;
        }
        .amount-group{
            grid-column:3;
            grid-row:1 / span 2;
            display:flex;
            flex-direction:column;
            gap:6px;
            width:220px;
            align-self:start;
        }
        .amount-label{
            text-align:left;
            white-space:nowrap;
            margin:0;
        }
        .amount-wrapper{
            width:100%;
            min-width:0;
            max-width:100%;
            overflow:hidden;
        }
        #amount{
            width:100%;
            max-width:100%;
            min-width:0;
            box-sizing:border-box;
            text-align:right!important;
            padding-right:12px;
            font-weight:600;
        }
        .tf-account-dropdown{
            position:relative;
            width:100%;
            min-width:0;
        }
        .tf-account-trigger{
            height:28px;
            width:100%;
            min-width:0;
            border:1px solid rgba(4,120,87,.25);
            background:#f8fffb;
            border-radius:2px;
            padding:3px 8px;
            font-size:.83rem;
            color:#0f172a;
            display:flex;
            align-items:center;
            justify-content:flex-start;
            gap:8px;
            text-align:left;
        }
        .tf-account-trigger:focus{
            outline:none;
            border-color:#44D34E;
            box-shadow:0 0 0 2px rgba(68,211,78,.14);
        }
        .tf-account-placeholder{
            white-space:nowrap;
            overflow:visible;
            text-overflow:unset;
            min-width:0;
        }
        .tf-account-menu{
            position:absolute;
            left:0;
            right:0;
            top:calc(100% + 3px);
            background:#fff;
            border:1px solid rgba(68,211,78,.65);
            border-radius:4px;
            box-shadow:0 12px 28px rgba(15,23,42,.12);
            z-index:1055;
            display:none;
            overflow:hidden;
        }
        .tf-account-dropdown.open .tf-account-menu{
            display:block;
        }
        .tf-account-menu-head{
            display:grid;
            grid-template-columns:minmax(0,1fr) 122px;
            gap:12px;
            padding:7px 12px;
            background:#ecfdf3;
            border-bottom:1px solid rgba(68,211,78,.26);
            color:#047857;
            font-size:.74rem;
            font-weight:800;
            text-transform:uppercase;
        }

        .tf-account-menu-head span:last-child{
            text-align:right;
        }
        .tf-account-options{
            max-height:260px;
            overflow-y:auto;
            scrollbar-color:#44D34E #ecfdf3;
            scrollbar-width:thin;
        }
        .tf-account-options::-webkit-scrollbar{
            width:9px;
        }
        .tf-account-options::-webkit-scrollbar-track{
            background:#ecfdf3;
        }
        .tf-account-options::-webkit-scrollbar-thumb{
            background:#44D34E;
            border-radius:999px;
        }
        .tf-account-option{
            width:100%;
            border:0;
            border-bottom:1px solid #edf2f7;
            background:#fff;
            display:grid;
            grid-template-columns:minmax(0,1fr) 122px;
            gap:12px;
            align-items:center;
            padding:9px 12px;
            font-size:.86rem;
            color:#0f172a;
            text-align:left;
        }
        .tf-account-option:hover,
        .tf-account-option.active{
            background:#ecfdf3;
        }
        .tf-account-option.is-disabled,
        .tf-account-option:disabled{
            cursor:not-allowed;
            color:#64748b;
            background:#f8fafc;
            opacity:.88;
        }
        .tf-account-option.is-disabled:hover,
        .tf-account-option:disabled:hover{
            background:#f8fafc;
        }
        .tf-account-name{
            padding-left:var(--indent,0px);
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .tf-account-option.is-disabled .tf-account-name{
            font-weight:700;
        }
        .tf-account-option.is-sub-account .tf-account-name::before{
            content:'';
        }
        .tf-account-amount{
            color:#052A47;
            text-align:right;
            white-space:nowrap;
            font-size:.78rem;
        }
        .tf-input,.tf-select{
            height:28px;
            border:1px solid rgba(4,120,87,.25);
            background:#f8fffb;
            border-radius:2px;
            padding:3px 8px;
            font-size:.83rem;
            width:100%;
            color:#0f172a;
        }
        .tf-input:focus,.tf-select:focus{
            outline:none;
            border-color:#44D34E;
            box-shadow:0 0 0 2px rgba(68,211,78,.14);
        }
        .tf-date{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:8px;
            white-space:nowrap;
        }
        .tf-date label{
            font-size:.68rem;
            font-weight:700;
            color:#047857;
            text-transform:uppercase;
            margin:0;
        }
        .tf-check-row{
            display:flex;
            align-items:center;
            gap:6px;
            grid-column:1 / 3;
            padding-top:2px;
        }
        .tf-check-row input{
            width:13px;
            height:13px;
            accent-color:#047857;
        }

        .payment-detail-row{
            grid-column:1 / 3;
            display:grid;
            grid-template-columns:150px minmax(420px,1fr);
            gap:10px 14px;
            align-items:center;
            margin-top:0;
            width:100%;
        }
        .payment-detail-row.d-none{
            display:none!important;
        }
        .payment-detail-row .tf-label{
            height:28px;
            display:flex;
            align-items:center;
            margin:0;
        }
        .payment-detail-row .tf-input{
            width:100%;
            height:28px;
        }
        .memo-row{
            display:grid;
            grid-template-columns:54px 1fr;
            gap:8px;
            align-items:center;
            margin-top:12px;
        }
        .action-bar{
            max-width:1050px;
            display:flex;
            justify-content:flex-end;
            gap:8px;
            margin-top:14px;
        }
        .btn-qb{
            border:1px solid #d1d5db;
            background:#f4f4f4;
            color:#374151;
            border-radius:3px;
            padding:6px 22px;
            font-weight:700;
            font-size:.86rem;
            line-height:1.1;
        }
        .btn-qb:hover{background:#e5e7eb}
        .btn-qb-primary{
            background:linear-gradient(135deg,#3b82f6,#1d4ed8);
            border-color:#2563eb;
            color:#fff;
        }
        .btn-qb-primary:hover,
        .btn-qb-primary:focus{
            background:linear-gradient(135deg,#2563eb,#1e40af);
            border-color:#1d4ed8;
            color:#fff;
            filter:none;
            box-shadow:0 4px 12px rgba(37,99,235,.24);
        }
        .btn-qb-primary:active{
            background:linear-gradient(135deg,#1d4ed8,#1e3a8a);
            border-color:#1e40af;
            color:#fff;
        }
        .btn-qb-green{
            background:linear-gradient(135deg,#44D34E,#047857);
            border-color:#047857;
            color:#fff;
        }
        .btn-qb-green:hover{color:#fff;filter:brightness(.96)}
        .transfer-no-pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            background:#fff;
            border:1px solid rgba(68,211,78,.45);
            border-radius:999px;
            padding:7px 12px;
            color:#047857;
            font-weight:700;
            margin-top:10px;
            font-size:.82rem;
        }
        .sidebar .nav-link[href="transferfunds.php"]{
            background:rgba(68,211,78,.14)!important;
            color:#44D34E!important;
            border-left:4px solid #44D34E!important;
        }
        .sidebar.collapsed .nav-link[href="transferfunds.php"]{
            border-left:none!important;
        }
        .amgc-swal-popup{
            border-radius:18px!important;
            padding:1.4rem!important;
            box-shadow:0 18px 45px rgba(15,23,42,.18)!important;
            border:1px solid rgba(5,150,105,.12)!important;
        }
        .amgc-swal-title{
            color:#064e3b!important;
            font-weight:800!important;
        }
        .amgc-swal-confirm{
            background:linear-gradient(135deg,#44D34E,#047857)!important;
            border:none!important;
            border-radius:10px!important;
            padding:.65rem 1.25rem!important;
            font-weight:800!important;
            color:#fff!important;
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

        @media(max-width:1280px){
            .transfer-layout{
                grid-template-columns:minmax(0,1fr) 315px;
            }
            .transfer-grid{
                grid-template-columns:145px minmax(260px,1fr) 210px;
                gap:9px 10px;
            }
            .amount-group{
                width:200px;
            }
            .payment-detail-row{
                grid-column:1 / 3;
                grid-template-columns:145px minmax(260px,1fr);
                gap:9px 10px;
            }
        }
        @media(max-width:992px){
            .main-content,.main-content.sidebar-collapsed{margin-left:0}
            .transfer-layout{
                grid-template-columns:1fr;
            }
            .transfer-header{
                grid-template-columns:1fr;
            }
            .tf-date{
                justify-content:flex-start;
            }
            .transfer-grid{
                grid-template-columns:1fr;
            }
            .amount-group{
                grid-column:auto;
                grid-row:auto;
                width:100%;
            }
            .payment-detail-row{
                grid-column:auto;
                grid-template-columns:1fr;
            }
            .tf-label{text-align:left}
            .tf-check-row{grid-column:auto}
            .action-bar{justify-content:stretch;flex-wrap:wrap}
            .action-bar .btn-qb{flex:1}
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

    <main class="main-content" id="mainContent">

        <form method="POST" id="transferForm" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="save_transfer" value="1">
            <input type="hidden" name="journal_edit_transfer_id" value="<?php echo (int)$journal_edit_transfer_id; ?>">
            <input type="hidden" name="transfer_no" value="<?php echo h($generated_transfer_no); ?>">
            <input type="hidden" name="save_action" id="saveAction" value="new">

            <div class="transfer-layout">
                <div class="transfer-card">
                    <?php if ($is_journal_edit_mode): ?>
                    <div style="background:#d1fae5;border:1px solid rgba(4,120,87,.22);color:#047857;border-radius:10px;padding:10px 12px;margin-bottom:12px;font-weight:800;">
                        <i class="bi bi-pencil-square"></i> Edit Transfer from Journal Entries
                    </div>
                    <?php endif; ?>
                    <div class="transfer-header">
                        <div>
                            <h1 class="transfer-title">Transfer Funds</h1>
                            <div class="transfer-no-pill">
                                <i class="bi bi-receipt"></i>
                                <span><?php echo h($generated_transfer_no); ?></span>
                            </div>
                        </div>
                        <div class="tf-date">
                            <label for="transferDate">Date</label>
                            <input type="date" class="tf-input" style="width:150px" id="transferDate" name="transfer_date" value="<?php echo h($tf_form_transfer_date); ?>" required>
                        </div>
                    </div>

                <div class="transfer-grid">
                    <label class="tf-label" for="fromAccount">Transfer Funds From</label>
                    <div class="tf-account-dropdown" data-dropdown="fromAccount">
                        <input type="hidden" id="fromAccount" name="from_account_id" required>
                        <button type="button" class="tf-account-trigger" id="fromAccountTrigger">
                            <span class="tf-account-placeholder">Select Account</span>
                        </button>
                        <div class="tf-account-menu" id="fromAccountMenu">
                            <div class="tf-account-menu-head">
                                <span>Account Title</span>
                                <span>Amount</span>
                            </div>
                            <div class="tf-account-options">
                                <?php foreach ($account_dropdown_rows as $account): ?>
                                    <?php
                                        $level = max(0, (int)($account['_level'] ?? 0));
                                        $indent = min($level * 28, 112);
                                        $accountTitle = trim((string)($account['account_title'] ?? ''));
                                        $amountDisplay = (string)($account['_amount_display'] ?? ('₱' . number_format((float)($account['balance'] ?? 0), 2)));
                                        $hasChildren = !empty($account['_has_children']) && $level === 0;
                                        $optionClasses = trim('tf-account-option ' . ($level > 0 ? 'is-sub-account ' : '') . ($hasChildren ? 'is-disabled' : ''));
                                    ?>
                                    <button type="button"
                                            class="<?php echo h($optionClasses); ?>"
                                            data-target="fromAccount"
                                            data-value="<?php echo (int)$account['account_id']; ?>"
                                            data-title="<?php echo h($accountTitle); ?>"
                                            data-amount="<?php echo h($amountDisplay); ?>"
                                            data-disabled="<?php echo $hasChildren ? '1' : '0'; ?>"
                                            <?php echo $hasChildren ? 'disabled aria-disabled="true"' : ''; ?>
                                            style="--indent:<?php echo (int)$indent; ?>px;">
                                        <span class="tf-account-name"><?php echo h($accountTitle); ?></span>
                                        <span class="tf-account-amount"><?php echo h($amountDisplay); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="amount-group">
                        <label class="tf-label amount-label" for="amount">Transfer Amount</label>
                        <div class="amount-wrapper">
                            <input type="text" class="tf-input" id="amount" name="amount" inputmode="decimal" autocomplete="off" placeholder="0.00" value="<?php echo h($tf_form_amount); ?>" required>
                        </div>
                    </div>

                    <label class="tf-label" for="toAccount">Transfer Funds To</label>
                    <div class="tf-account-dropdown" data-dropdown="toAccount">
                        <input type="hidden" id="toAccount" name="to_account_id" required>
                        <button type="button" class="tf-account-trigger" id="toAccountTrigger">
                            <span class="tf-account-placeholder">Select Account</span>
                        </button>
                        <div class="tf-account-menu" id="toAccountMenu">
                            <div class="tf-account-menu-head">
                                <span>Account Title</span>
                                <span>Amount</span>
                            </div>
                            <div class="tf-account-options">
                                <?php foreach ($account_dropdown_rows as $account): ?>
                                    <?php
                                        $level = max(0, (int)($account['_level'] ?? 0));
                                        $indent = min($level * 28, 112);
                                        $accountTitle = trim((string)($account['account_title'] ?? ''));
                                        $amountDisplay = (string)($account['_amount_display'] ?? ('₱' . number_format((float)($account['balance'] ?? 0), 2)));
                                        $hasChildren = !empty($account['_has_children']) && $level === 0;
                                        $optionClasses = trim('tf-account-option ' . ($level > 0 ? 'is-sub-account ' : '') . ($hasChildren ? 'is-disabled' : ''));
                                    ?>
                                    <button type="button"
                                            class="<?php echo h($optionClasses); ?>"
                                            data-target="toAccount"
                                            data-value="<?php echo (int)$account['account_id']; ?>"
                                            data-title="<?php echo h($accountTitle); ?>"
                                            data-amount="<?php echo h($amountDisplay); ?>"
                                            data-disabled="<?php echo $hasChildren ? '1' : '0'; ?>"
                                            <?php echo $hasChildren ? 'disabled aria-disabled="true"' : ''; ?>
                                            style="--indent:<?php echo (int)$indent; ?>px;">
                                        <span class="tf-account-name"><?php echo h($accountTitle); ?></span>
                                        <span class="tf-account-amount"><?php echo h($amountDisplay); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <label class="tf-label" for="paymentMethod">Payment Method</label>
                    <select class="tf-select" id="paymentMethod" name="payment_method" required>
                        <option value="Cash" <?php echo $tf_form_payment_method === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="Check" <?php echo $tf_form_payment_method === 'Check' ? 'selected' : ''; ?>>Check</option>
                        <option value="Online Transfer" <?php echo $tf_form_payment_method === 'Online Transfer' ? 'selected' : ''; ?>>Online Transfer</option>
                    </select>
                    <div></div>

                    <div class="payment-detail-row d-none" id="checkDetailsRow">
                        <label class="tf-label" for="checkBankBranch">Bank/Branch</label>
                        <input type="text" class="tf-input" id="checkBankBranch" name="check_bank_branch" autocomplete="off" placeholder="Bank / Branch" value="<?php echo h($tf_form_check_bank_branch); ?>">
                        <label class="tf-label" for="checkNo">Check No.</label>
                        <input type="text" class="tf-input" id="checkNo" name="check_no" autocomplete="off" placeholder="Check No." value="<?php echo h($tf_form_check_no); ?>">
                    </div>

                    <div class="payment-detail-row" id="onlineDetailsRow">
                        <label class="tf-label" for="onlineReferenceNo">Reference No.</label>
                        <input type="text" class="tf-input" id="onlineReferenceNo" name="online_reference_no" autocomplete="off" placeholder="Reference Number" value="<?php echo h($tf_form_online_reference_no); ?>">
                    </div>
                </div>

                    <div class="memo-row">
                        <label class="tf-label left" for="memo">MEMO</label>
                        <input type="text" class="tf-input" id="memo" name="memo" value="<?php echo h($tf_form_memo); ?>">
                    </div>
                </div>

                <aside class="attachment-card">
                    <h5 class="attachment-title">Transaction Attachment</h5>
                    <div class="attachment-inner">
                        <label class="attachment-label" for="attachments">
                            <i class="bi bi-paperclip"></i>
                            <span>Attach</span>
                        </label>
                        <input type="file" class="form-control attachment-file" id="attachments" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx">
                        <p class="attachment-help">This attachment applies to the whole transfer transaction.</p>
                        <div class="attachment-selected" id="attachmentSelected"></div>
                    </div>
                </aside>
            </div>

            <div class="action-bar" style="max-width:none;">
                <?php if ($is_journal_edit_mode): ?>
                <button type="button" class="btn-qb btn-qb-primary" id="saveNewBtn">Update Transfer</button>
                <?php else: ?>
                <button type="button" class="btn-qb" id="saveCloseBtn">Save &amp; Close</button>
                <button type="button" class="btn-qb btn-qb-primary" id="saveNewBtn">Save &amp; New</button>
                <?php endif; ?>
                <button type="button" class="btn-qb" id="clearBtn">Clear</button>
            </div>
        </form>

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
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                    <i class="bi bi-bank2"></i>
                    <span>Banking</span>
                </a>
                <div class="more-dropdown" id="bankingMobileMenu">
                    <a class="dropdown-item" href="deposit.php"><i class="bi bi-bank"></i><span>Record
                            Deposit</span></a>
                    <a class="dropdown-item" href="withdrawal.php"><i class="bi bi-journal-check"></i><span>Write
                            Checks</span></a>
                    <a class="dropdown-item active" href="transferfunds.php"><i
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
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // ========== SIDEBAR FUNCTIONS (same behavior as chartofaccounts(3).php) ==========
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

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

        function syncMainContentSidebarState() {
            if (!mainContent || !sidebar) return;
            if (window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
                mainContent.classList.add('sidebar-collapsed');
            } else {
                mainContent.classList.remove('sidebar-collapsed');
            }
        }

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

            if (sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                localStorage.setItem('sidebarCollapsed', 'false');
                syncMainContentSidebarState();

                setTimeout(() => {
                    document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                        if (collapse.id !== targetId) {
                            collapse.classList.remove('show');
                            const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                            if (otherBtn) {
                                otherBtn.classList.remove('active-parent');
                                const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                                setArrowState(otherArrow, false);
                            }
                        }
                    });

                    target.classList.add('show');
                    if (btn) btn.classList.add('active-parent');
                    setArrowState(arrow, true);
                }, 50);
                return false;
            }

            if (target.classList.contains('show')) {
                target.classList.remove('show');
                if (btn) btn.classList.remove('active-parent');
                setArrowState(arrow, false);
            } else {
                document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                    if (collapse.id !== targetId) {
                        collapse.classList.remove('show');
                        const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                        if (otherBtn) {
                            otherBtn.classList.remove('active-parent');
                            const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                            setArrowState(otherArrow, false);
                        }
                    }
                });

                target.classList.add('show');
                if (btn) btn.classList.add('active-parent');
                setArrowState(arrow, true);
            }

            return false;
        }

        window.toggleSidebar = function() {
            const sidebar = document.getElementById('sidebar');
            if (!sidebar) return false;

            // Mobile sidebar toggle removed. Mobile navigation now uses only the bottom mobile-nav.
            if (window.innerWidth <= 992) {
                sidebar.classList.remove('active');
                document.querySelectorAll('.sidebar-overlay').forEach(function(overlay) { overlay.remove(); });
                return false;
            }

            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');
            syncMainContentSidebarState();

            if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
                setTimeout(function() {
                    document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
                        const activeLink = dropdownNav.querySelector('.nav-link.active');
                        if (activeLink) {
                            const collapseDiv = dropdownNav.querySelector('.collapse');
                            if (collapseDiv && !collapseDiv.classList.contains('show')) {
                                collapseDiv.classList.add('show');
                                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                                if (parentLink) {
                                    parentLink.classList.add('active-parent');
                                    const arrow = parentLink.querySelector('.dropdown-arrow');
                                    setArrowState(arrow, true);
                                }
                            }
                        }
                    });
                }, 150);
            } else if (sidebar.classList.contains('collapsed')) {
                document.querySelectorAll('.sidebar .collapse.show').forEach(function(collapse) {
                    collapse.classList.remove('show');
                    const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (parentBtn) {
                        parentBtn.classList.remove('active-parent');
                        const arrow = parentBtn.querySelector('.dropdown-arrow');
                        setArrowState(arrow, false);
                    }
                });
            }

            return false;
        };

        function setActiveSidebarItem() {
            const currentPage = window.location.pathname.split('/').pop() || 'transferfunds.php';

            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.classList.remove('active');
            });
            document.querySelectorAll('.sidebar .nav-item').forEach(item => {
                item.classList.remove('active');
            });

            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                const href = (link.getAttribute('href') || '').split('?')[0];
                if (href === currentPage) {
                    link.classList.add('active');
                    const navItem = link.closest('.nav-item');
                    if (navItem) navItem.classList.add('active');

                    const collapseDiv = link.closest('.collapse');
                    if (collapseDiv && !(sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed'))) {
                        collapseDiv.classList.add('show');
                        const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                        if (parentBtn) {
                            parentBtn.classList.add('active-parent');
                            const arrow = parentBtn.querySelector('.dropdown-arrow');
                            setArrowState(arrow, true);
                        }
                    }
                }
            });

            const sidebarEl = document.getElementById('sidebar');
            if (sidebarEl && sidebarEl.classList.contains('collapsed')) {
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

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');

            if (sidebar && window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                } else {
                    sidebar.classList.remove('collapsed');
                }
            }
            syncMainContentSidebarState();

            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.toggleSidebar();
                });
            }

            setActiveSidebarItem();

            document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
                collapse.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            });

            window.addEventListener('resize', function() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.querySelector('.sidebar-overlay');
                if (!sidebar) return;

                if (window.innerWidth > 992) {
                    if (overlay) overlay.remove();
                    sidebar.classList.remove('active');
                    const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                    if (savedCollapsed === 'true') sidebar.classList.add('collapsed');
                } else {
                    sidebar.classList.remove('collapsed');
                    syncMainContentSidebarState();
                }
            });
        }

        initializeSidebar();

        const form = document.getElementById('transferForm');
        const saveAction = document.getElementById('saveAction');
        const saveNewBtn = document.getElementById('saveNewBtn');
        const saveCloseBtn = document.getElementById('saveCloseBtn');
        const clearBtn = document.getElementById('clearBtn');
        const fromAccount = document.getElementById('fromAccount');
        const toAccount = document.getElementById('toAccount');
        const amount = document.getElementById('amount');

        function formatAmountWithCommas(value) {
            value = String(value || '').replace(/,/g, '').replace(/[^0-9.]/g, '');

            const firstDot = value.indexOf('.');
            if (firstDot !== -1) {
                value = value.substring(0, firstDot + 1) + value.substring(firstDot + 1).replace(/\./g, '');
            }

            let parts = value.split('.');
            let whole = parts[0] || '';
            let decimal = parts.length > 1 ? parts[1].slice(0, 2) : '';

            whole = whole.replace(/^0+(?=\d)/, '');
            if (whole !== '') {
                whole = Number(whole).toLocaleString('en-US');
            }

            return parts.length > 1 ? whole + '.' + decimal : whole;
        }

        amount?.addEventListener('input', function() {
            const cursorAtEnd = this.selectionStart === this.value.length;
            this.value = formatAmountWithCommas(this.value);
            if (cursorAtEnd) {
                this.setSelectionRange(this.value.length, this.value.length);
            }
        });

        amount?.addEventListener('blur', function() {
            const cleanAmount = this.value.replace(/,/g, '');
            if (cleanAmount !== '' && !isNaN(parseFloat(cleanAmount))) {
                this.value = parseFloat(cleanAmount).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
        });
        const paymentMethod = document.getElementById('paymentMethod');
        const checkDetailsRow = document.getElementById('checkDetailsRow');
        const onlineDetailsRow = document.getElementById('onlineDetailsRow');
        const checkBankBranch = document.getElementById('checkBankBranch');
        const checkNo = document.getElementById('checkNo');
        const onlineReferenceNo = document.getElementById('onlineReferenceNo');
        const attachmentsInput = document.getElementById('attachments');
        const attachmentSelected = document.getElementById('attachmentSelected');

        function togglePaymentFields() {
            const method = paymentMethod?.value || '';
            checkDetailsRow?.classList.toggle('d-none', method !== 'Check');
            onlineDetailsRow?.classList.toggle('d-none', method !== 'Online Transfer');

            if (method !== 'Check') {
                if (checkBankBranch) checkBankBranch.value = '';
                if (checkNo) checkNo.value = '';
            }
            if (method !== 'Online Transfer') {
                if (onlineReferenceNo) onlineReferenceNo.value = '';
            }
        }

        paymentMethod?.addEventListener('change', togglePaymentFields);
        togglePaymentFields();

        function closeAccountDropdowns(exceptDropdown = null) {
            document.querySelectorAll('.tf-account-dropdown.open').forEach(dropdown => {
                if (dropdown !== exceptDropdown) dropdown.classList.remove('open');
            });
        }

        document.querySelectorAll('.tf-account-trigger').forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                const dropdown = this.closest('.tf-account-dropdown');
                if (!dropdown) return;
                const isOpen = dropdown.classList.contains('open');
                closeAccountDropdowns(dropdown);
                dropdown.classList.toggle('open', !isOpen);
            });
        });

        document.querySelectorAll('.tf-account-option').forEach(option => {
            option.addEventListener('click', function(e) {
                e.preventDefault();
                if (this.disabled || this.dataset.disabled === '1') {
                    return;
                }
                const targetId = this.dataset.target;
                const input = document.getElementById(targetId);
                const dropdown = this.closest('.tf-account-dropdown');
                const triggerText = dropdown?.querySelector('.tf-account-placeholder');
                const title = this.dataset.title || '';
                const amountText = this.dataset.amount || '';

                if (input) {
                    input.value = this.dataset.value || '';
                    input.dispatchEvent(new Event('change', {bubbles:true}));
                }
                if (triggerText) {
                    triggerText.textContent = amountText ? `${title}  ${amountText}` : title;
                }
                dropdown?.querySelectorAll('.tf-account-option.active').forEach(active => active.classList.remove('active'));
                this.classList.add('active');
                closeAccountDropdowns();
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.tf-account-dropdown')) {
                closeAccountDropdowns();
            }
        });

        function resetAccountDropdown(targetId) {
            const input = document.getElementById(targetId);
            const dropdown = document.querySelector(`.tf-account-dropdown[data-dropdown="${targetId}"]`);
            const triggerText = dropdown?.querySelector('.tf-account-placeholder');
            if (input) input.value = '';
            if (triggerText) triggerText.textContent = 'Select Account';
            dropdown?.querySelectorAll('.tf-account-option.active').forEach(active => active.classList.remove('active'));
        }

        function selectAccountDropdownValue(targetId, value) {
            value = String(value || '');
            if (!value) return;
            const dropdown = document.querySelector(`.tf-account-dropdown[data-dropdown="${targetId}"]`);
            const option = dropdown?.querySelector(`.tf-account-option[data-value="${CSS.escape(value)}"]`);
            const input = document.getElementById(targetId);
            const triggerText = dropdown?.querySelector('.tf-account-placeholder');
            if (option && input) {
                input.value = value;
                const title = option.dataset.title || '';
                const amountText = option.dataset.amount || '';
                if (triggerText) triggerText.textContent = amountText ? `${title}  ${amountText}` : title;
                dropdown.querySelectorAll('.tf-account-option.active').forEach(active => active.classList.remove('active'));
                option.classList.add('active');
            }
        }

        const journalEditTransferData = <?php echo json_encode([
            'is_edit' => $is_journal_edit_mode,
            'from_account_id' => $tf_form_from_account_id,
            'to_account_id' => $tf_form_to_account_id,
            'amount' => $tf_form_amount,
            'payment_method' => $tf_form_payment_method
        ], JSON_UNESCAPED_SLASHES); ?>;
        if (journalEditTransferData && journalEditTransferData.is_edit) {
            selectAccountDropdownValue('fromAccount', journalEditTransferData.from_account_id);
            selectAccountDropdownValue('toAccount', journalEditTransferData.to_account_id);
            if (amount && journalEditTransferData.amount) amount.value = journalEditTransferData.amount;
            if (paymentMethod) {
                paymentMethod.value = journalEditTransferData.payment_method || 'Online Transfer';
                togglePaymentFields();
            }
        }

        function validateTransfer() {
            if (!fromAccount.value) {
                Swal.fire({icon:'warning', title:'Required', text:'Please select Transfer Funds From.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                return false;
            }
            if (!toAccount.value) {
                Swal.fire({icon:'warning', title:'Required', text:'Please select Transfer Funds To.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                return false;
            }
            if (fromAccount.value === toAccount.value) {
                Swal.fire({icon:'warning', title:'Invalid Accounts', text:'From and To account must be different.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                return false;
            }
            const cleanAmount = (amount.value || '').replace(/,/g, '');
            if (!cleanAmount || isNaN(parseFloat(cleanAmount)) || parseFloat(cleanAmount) <= 0) {
                Swal.fire({icon:'warning', title:'Invalid Amount', text:'Transfer Amount must be greater than zero.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                return false;
            }
            if (!paymentMethod.value) {
                Swal.fire({icon:'warning', title:'Required', text:'Please select Payment Method.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                return false;
            }
            if (paymentMethod.value === 'Check') {
                if (!checkBankBranch.value.trim()) {
                    Swal.fire({icon:'warning', title:'Required', text:'Please enter Bank/Branch for Check payment.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                    return false;
                }
                if (!checkNo.value.trim()) {
                    Swal.fire({icon:'warning', title:'Required', text:'Please enter Check No.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                    return false;
                }
            }
            if (paymentMethod.value === 'Online Transfer' && !onlineReferenceNo.value.trim()) {
                Swal.fire({icon:'warning', title:'Required', text:'Please enter Reference Number for Online Transfer.', customClass:{popup:'amgc-swal-popup', title:'amgc-swal-title', confirmButton:'amgc-swal-confirm'}});
                return false;
            }
            return true;
        }

        saveNewBtn?.addEventListener('click', function() {
            if (!validateTransfer()) return;
            saveAction.value = 'new';
            form.submit();
        });

        saveCloseBtn?.addEventListener('click', function() {
            if (!validateTransfer()) return;
            saveAction.value = 'close';
            form.submit();
        });

        clearBtn?.addEventListener('click', function() {
            form.reset();
            document.getElementById('memo').value = 'Funds Transfer';
            document.getElementById('transferDate').value = '<?php echo h(date('Y-m-d')); ?>';
            document.getElementById('paymentMethod').value = 'Online Transfer';
            if (checkBankBranch) checkBankBranch.value = '';
            if (checkNo) checkNo.value = '';
            if (onlineReferenceNo) onlineReferenceNo.value = '';
            togglePaymentFields();
            resetAccountDropdown('fromAccount');
            resetAccountDropdown('toAccount');
            if (attachmentsInput) attachmentsInput.value = '';
            if (attachmentSelected) attachmentSelected.textContent = '';
        });

        attachmentsInput?.addEventListener('change', function() {
            const files = Array.from(this.files || []);
            attachmentSelected.textContent = files.length ? files.map(file => file.name).join(', ') : '';
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                window.location.href = 'branchdashboard.php';
            }
        });

        document.querySelector('.sidebar .nav-link[href="transferfunds.php"]')?.scrollIntoView({block:'center'});

        <?php if ($success_message !== ''): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: <?php echo json_encode($success_message); ?>,
            timer: 1200,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            customClass: {
                popup: 'amgc-swal-popup',
                title: 'amgc-swal-title',
                confirmButton: 'amgc-swal-confirm'
            }
        }).then(function() {
            window.location.href = <?php echo json_encode($success_redirect ?: 'transferfunds.php'); ?>;
        });
        setTimeout(function() {
            window.location.href = <?php echo json_encode($success_redirect ?: 'transferfunds.php'); ?>;
        }, 1500);
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: <?php echo json_encode($error_message); ?>,
            customClass: {
                popup: 'amgc-swal-popup',
                title: 'amgc-swal-title',
                confirmButton: 'amgc-swal-confirm'
            }
        });
        <?php endif; ?>

         // ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
function getMobileDropdownButton(dropdownId) {
    return document.querySelector('.mobile-nav .more-btn[data-dropdown="' + dropdownId + '"]') ||
           document.querySelector('.mobile-nav .more-btn[onclick*="' + dropdownId + '"]');
}

function mobileDropdownHasActiveChild(dropdown) {
    return !!(dropdown && dropdown.querySelector('.dropdown-item.active'));
}

function closeAllMobileDropdowns() {
    document.querySelectorAll('.mobile-nav .more-dropdown.show').forEach(function (dropdown) {
        dropdown.classList.remove('show');
    });

    document.querySelectorAll('.mobile-nav .more-btn').forEach(function (btn) {
        const navItem = btn.closest('.dropdown-more');
        const dropdown = navItem ? navItem.querySelector('.more-dropdown') : null;
        if (!mobileDropdownHasActiveChild(dropdown)) {
            btn.classList.remove('active');
        }
        btn.setAttribute('aria-expanded', 'false');
    });
}

function toggleMobileDropdown(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const dropdown = document.getElementById(dropdownId);
    const btn = event ? event.currentTarget : getMobileDropdownButton(dropdownId);
    if (!dropdown || !btn) return false;

    const willOpen = !dropdown.classList.contains('show');
    closeAllMobileDropdowns();

    if (willOpen) {
        dropdown.classList.add('show');
        btn.classList.add('active');
        btn.setAttribute('aria-expanded', 'true');
    } else {
        dropdown.classList.remove('show');
        btn.setAttribute('aria-expanded', 'false');
        if (!mobileDropdownHasActiveChild(dropdown)) {
            btn.classList.remove('active');
        }
    }

    return false;
}

function setActiveMobileNav() {
    const currentPage = (window.location.pathname.split('/').pop() || 'transferfunds.php').toLowerCase();

    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        link.classList.remove('active');
        if (link.classList.contains('more-btn')) {
            link.setAttribute('aria-expanded', 'false');
        }
    });

    document.querySelectorAll('.mobile-nav .dropdown-more').forEach(function (item) {
        item.classList.remove('active');
    });

    document.querySelectorAll('.mobile-nav .more-dropdown').forEach(function (dropdown) {
        dropdown.classList.remove('show');
    });

    document.querySelectorAll('.mobile-nav .nav-link[href], .mobile-nav .dropdown-item[href]').forEach(function (link) {
        const href = (link.getAttribute('href') || '').split('?')[0].split('#')[0].toLowerCase();
        if (href && href !== '#' && href === currentPage) {
            link.classList.add('active');
            const dropdown = link.closest('.more-dropdown');
            if (dropdown) {
                const navItem = dropdown.closest('.dropdown-more');
                const parentBtn = navItem ? navItem.querySelector('.more-btn') : getMobileDropdownButton(dropdown.id);
                if (navItem) navItem.classList.add('active');
                if (parentBtn) {
                    parentBtn.classList.add('active');
                    parentBtn.setAttribute('aria-expanded', 'false');
                }
            }
        }
    });
}

document.querySelectorAll('.mobile-nav .more-btn').forEach(function (btn) {
    const onclickValue = btn.getAttribute('onclick') || '';
    const match = onclickValue.match(/'([^']+)'/);
    if (match && match[1]) {
        btn.setAttribute('data-dropdown', match[1]);
        btn.setAttribute('aria-expanded', 'false');
    }
});

setActiveMobileNav();

document.addEventListener('click', function (e) {
    if (!e.target.closest('.mobile-nav .dropdown-more')) {
        closeAllMobileDropdowns();
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMobileDropdowns();
});
    </script>
</body>
</html>
