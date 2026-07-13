<?php
/* MOTORPOOL WRITE CHECK ENDING BALANCE V3 - generated update */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) { die('Database connection failed: ' . mysqli_connect_error()); }

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) { if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1)); }
if ($user_initials === '') $user_initials = 'BA';

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}
function getColumns(mysqli $conn, string $table): array {
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[] = $row['Field'];
    }
    return $columns;
}
function columnExistsSafe(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}
function addColumnIfMissingSafe(mysqli $conn, string $table, string $column, string $definition): void {
    if (!columnExistsSafe($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}
function firstExisting(array $columns, array $choices): ?string {
    foreach ($choices as $choice) if (in_array($choice, $columns, true)) return $choice;
    return null;
}
function uploadMotorpoolFile(string $field, string $uploadDir): string {
    if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif','pdf'];
    if (!in_array($ext, $allowed, true)) return '';
    $filename = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim($uploadDir, '/') . '/' . $filename;
    return move_uploaded_file($_FILES[$field]['tmp_name'], $target) ? $filename : '';
}

function generateNextVehicleId(mysqli $conn, string $table, array $columns): string {
    $idCol = in_array('id', $columns, true) ? 'id' : null;
    $vehicleCol = firstExisting($columns, ['vehicle_id','vehicle_code','vehicle_no']);

    if ($idCol) {
        $result = $conn->query("SELECT MAX(`$idCol`) AS max_id FROM `$table`");
        $maxId = 0;
        if ($result && ($row = $result->fetch_assoc())) $maxId = (int)($row['max_id'] ?? 0);
        return (string)($maxId + 1);
    }

    if ($vehicleCol) {
        $result = $conn->query("SELECT MAX(CAST(`$vehicleCol` AS UNSIGNED)) AS max_vehicle_id FROM `$table`");
        $maxId = 0;
        if ($result && ($row = $result->fetch_assoc())) $maxId = (int)($row['max_vehicle_id'] ?? 0);
        return (string)($maxId + 1);
    }

    return (string)time();
}

function uploadMultipleMotorpoolFiles(string $field, string $uploadDir): array {
    $saved = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return $saved;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $allowed = ['jpg','jpeg','png','webp','gif','pdf'];
    foreach ($_FILES[$field]['name'] as $index => $name) {
        if (empty($name) || !is_uploaded_file($_FILES[$field]['tmp_name'][$index])) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $filename = $field . '_' . date('YmdHis') . '_' . $index . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = rtrim($uploadDir, '/') . '/' . $filename;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$index], $target)) $saved[] = $filename;
    }
    return $saved;
}


function repairPaymentCreateBanksTableIfNeeded(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `banks` (`bank_id` int(11) NOT NULL AUTO_INCREMENT, `branch_id` int(11) NOT NULL DEFAULT 0, `bank_name` varchar(150) NOT NULL, `account_name` varchar(150) DEFAULT NULL, `account_number` varchar(100) DEFAULT NULL, `bank_branch` varchar(150) DEFAULT NULL, `status` varchar(30) NOT NULL DEFAULT 'active', `parent_bank_id` int(11) DEFAULT NULL, `created_by` int(11) NOT NULL DEFAULT 0, `created_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`bank_id`), KEY `branch_id` (`branch_id`), KEY `status` (`status`), KEY `parent_bank_id` (`parent_bank_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    addColumnIfMissingSafe($conn, 'banks', 'parent_bank_id', '`parent_bank_id` int(11) DEFAULT NULL AFTER `status`');
}
function repairPaymentCreateBankPaymentMethodsTableIfNeeded(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `bank_payment_methods` (`id` int(11) NOT NULL AUTO_INCREMENT, `bank_id` int(11) NOT NULL, `payment_method` varchar(50) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `unique_bank_method` (`bank_id`,`payment_method`), KEY `bank_id` (`bank_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
function getRepairPaymentBankAccounts(mysqli $conn, bool $view_all_branches, int $branch_id): array {
    // Repair payments use Bank accounts from Chart of Accounts, not the banks table.
    if (!tableExists($conn, 'chart_of_accounts')) return [];
    $columns = getColumns($conn, 'chart_of_accounts');
    if (!in_array('account_id', $columns, true) || !in_array('account_title', $columns, true) || !in_array('account_type', $columns, true)) return [];

    $sql = "SELECT account_id, account_code, account_title, account_type, branch_id, balance
            FROM chart_of_accounts
            WHERE LOWER(TRIM(account_type)) = 'bank'
              AND LOWER(TRIM(COALESCE(status, 'active'))) NOT IN ('inactive','deleted','archived')";
    if (!$view_all_branches && $branch_id > 0) {
        $sql .= " AND (branch_id = ? OR branch_id IS NULL)";
    }
    $sql .= " ORDER BY account_title ASC";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!$view_all_branches && $branch_id > 0) $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $label = trim(((string)($row['account_code'] ?? '') !== '' ? (string)$row['account_code'] . ' · ' : '') . (string)($row['account_title'] ?? ''));
                $rows[] = [
                    'bank_id' => (int)($row['account_id'] ?? 0),
                    'bank_name' => (string)($row['account_title'] ?? ''),
                    'account_name' => (string)($row['account_title'] ?? ''),
                    'account_number' => (string)($row['account_code'] ?? ''),
                    'bank_branch' => '',
                    'balance' => (float)($row['balance'] ?? 0),
                    'display_name' => $label !== '' ? $label : ('Bank Account #' . (int)($row['account_id'] ?? 0))
                ];
            }
        }
        $stmt->close();
    }
    return $rows;
}
function getRepairPaymentExpenseAccounts(mysqli $conn): array {
    foreach (['chart_of_accounts', 'chart_accounts', 'accounts', 'chartofaccounts'] as $table) {
        if (!tableExists($conn, $table)) continue;
        $columns = getColumns($conn, $table);
        $idCol = firstExisting($columns, ['account_id', 'id', 'coa_id']);
        $nameCol = firstExisting($columns, ['account_title', 'account_name', 'name', 'title']);
        $typeCol = firstExisting($columns, ['account_type', 'type', 'category']);
        $statusCol = firstExisting($columns, ['status', 'account_status']);
        if (!$idCol || !$nameCol) continue;
        $select = "`$idCol` AS account_id, `$nameCol` AS account_name" . ($typeCol ? ", `$typeCol` AS account_type" : ", '' AS account_type");
        $sql = "SELECT $select FROM `$table` WHERE 1=1";
        if ($typeCol) $sql .= " AND LOWER(TRIM(`$typeCol`)) IN ('expense','expenses','other expense','other expenses')";
        if ($statusCol) $sql .= " AND LOWER(TRIM(COALESCE(`$statusCol`, 'active'))) NOT IN ('inactive','deleted','archived')";
        $sql .= " ORDER BY `$nameCol` ASC";
        $result = $conn->query($sql);
        $rows = [];
        if ($result) while ($row = $result->fetch_assoc()) $rows[] = ['account_id'=>(int)($row['account_id'] ?? 0), 'account_name'=>trim((string)($row['account_name'] ?? '')), 'account_type'=>trim((string)($row['account_type'] ?? '')), 'source_table'=>$table];
        if (!empty($rows)) return $rows;
    }
    return [];
}

function ensureRepairPaymentChartTransactionTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `chart_account_transactions` (
        `transaction_id` INT AUTO_INCREMENT PRIMARY KEY,
        `branch_id` INT DEFAULT NULL,
        `account_id` INT NOT NULL,
        `account_name` VARCHAR(255) DEFAULT NULL,
        `transaction_date` DATE DEFAULT NULL,
        `transaction_type` VARCHAR(100) DEFAULT NULL,
        `transaction_no` VARCHAR(120) DEFAULT NULL,
        `reference_no` VARCHAR(120) DEFAULT NULL,
        `memo` TEXT DEFAULT NULL,
        `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `source_table` VARCHAR(120) DEFAULT NULL,
        `source_id` INT DEFAULT NULL,
        `created_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_account_id` (`account_id`),
        KEY `idx_branch_id` (`branch_id`),
        KEY `idx_source` (`source_table`, `source_id`),
        KEY `idx_transaction_date` (`transaction_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'branch_id', '`branch_id` INT DEFAULT NULL AFTER `transaction_id`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'account_name', '`account_name` VARCHAR(255) DEFAULT NULL AFTER `account_id`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'transaction_date', '`transaction_date` DATE DEFAULT NULL AFTER `account_name`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'transaction_type', '`transaction_type` VARCHAR(100) DEFAULT NULL AFTER `transaction_date`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'transaction_no', '`transaction_no` VARCHAR(120) DEFAULT NULL AFTER `transaction_type`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'reference_no', '`reference_no` VARCHAR(120) DEFAULT NULL AFTER `transaction_no`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'memo', '`memo` TEXT DEFAULT NULL AFTER `reference_no`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'debit', '`debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `memo`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'credit', '`credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `debit`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'balance_after', '`balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `credit`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'source_table', '`source_table` VARCHAR(120) DEFAULT NULL AFTER `balance_after`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'source_id', '`source_id` INT DEFAULT NULL AFTER `source_table`');
    addColumnIfMissingSafe($conn, 'chart_account_transactions', 'created_by', '`created_by` INT DEFAULT NULL AFTER `source_id`');
}

function getRepairPaymentChartAccount(mysqli $conn, int $account_id, array $allowedTypes): ?array {
    if ($account_id <= 0 || !tableExists($conn, 'chart_of_accounts')) return null;
    $stmt = $conn->prepare("SELECT account_id, account_title, account_type, balance FROM chart_of_accounts WHERE account_id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $type = strtolower(trim((string)($row['account_type'] ?? '')));
    $allowed = array_map(fn($t) => strtolower(trim((string)$t)), $allowedTypes);
    if (!in_array($type, $allowed, true)) return null;
    return $row;
}

function insertRepairPaymentChartEntry(mysqli $conn, int $branch_id, int $account_id, string $account_name, string $transaction_date, string $transaction_type, string $transaction_no, string $reference_no, string $memo, float $debit, float $credit, float $balance_after, int $source_id, int $created_by): bool {
    $source_table = 'repair_payment_history';
    $stmt = $conn->prepare("INSERT INTO chart_account_transactions
        (branch_id, account_id, account_name, transaction_date, transaction_type, transaction_no, reference_no, memo, debit, credit, balance_after, source_table, source_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('iissssssdddsii', $branch_id, $account_id, $account_name, $transaction_date, $transaction_type, $transaction_no, $reference_no, $memo, $debit, $credit, $balance_after, $source_table, $source_id, $created_by);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getRepairPaymentRepairsToMakeMemo(mysqli $conn, int $ris_id, string $fallback = ''): string {
    $memo = '';
    if ($ris_id > 0 && tableExists($conn, 'motorpool_ris_assessments')) {
        $stmt = $conn->prepare("SELECT repairs_summary FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $memo = trim((string)($row['repairs_summary'] ?? ''));
        }
    }
    if ($memo === '') $memo = trim($fallback);
    $memo = preg_replace('/^Repairs\s*(to\s*Make|Done)?\s*:\s*/i', '', $memo);
    $memo = preg_replace('/\R+/', ', ', trim($memo));
    return trim((string)$memo);
}

function postRepairPaymentToChartOfAccounts(mysqli $conn, int $payment_id, int $bank_account_id, int $expense_account_id, float $amount, string $payment_date, int $ris_id, string $ris_number, string $payment_method, string $reference_no, string $repairs_to_make_memo, int $branch_id, int $created_by): array {
    if ($payment_id <= 0) return ['success' => false, 'message' => 'Invalid repair payment record.'];
    if ($amount <= 0) return ['success' => false, 'message' => 'Amount must be greater than zero.'];
    ensureRepairPaymentChartTransactionTable($conn);

    $bank = getRepairPaymentChartAccount($conn, $bank_account_id, ['Bank']);
    if (!$bank) return ['success' => false, 'message' => 'Selected bank account was not found in Chart of Accounts.'];

    $expense = getRepairPaymentChartAccount($conn, $expense_account_id, ['Expense', 'Other Expense']);
    if (!$expense) return ['success' => false, 'message' => 'Selected expense account was not found in Chart of Accounts.'];

    $transaction_no = 'RPAY-' . str_pad((string)$payment_id, 5, '0', STR_PAD_LEFT);
    $transaction_type = 'Repair Payment';
    $memo = getRepairPaymentRepairsToMakeMemo($conn, $ris_id, $repairs_to_make_memo);
    if ($memo === '') $memo = $ris_number !== '' ? $ris_number : $transaction_no;
    $branchForTransaction = $branch_id > 0 ? $branch_id : 0;

    $conn->begin_transaction();
    try {
        $expenseNewBalance = (float)$expense['balance'] + $amount;
        $stmt = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
        if (!$stmt) throw new Exception('Failed to prepare expense account update.');
        $stmt->bind_param('di', $expenseNewBalance, $expense_account_id);
        if (!$stmt->execute()) throw new Exception('Failed to update expense account balance.');
        $stmt->close();
        if (!insertRepairPaymentChartEntry($conn, $branchForTransaction, $expense_account_id, (string)$expense['account_title'], $payment_date, $transaction_type, $transaction_no, $reference_no, $memo, $amount, 0.00, $expenseNewBalance, $payment_id, $created_by)) {
            throw new Exception('Failed to insert expense ledger entry.');
        }

        $bankNewBalance = (float)$bank['balance'] - $amount;
        $stmt = $conn->prepare("UPDATE chart_of_accounts SET balance = ? WHERE account_id = ?");
        if (!$stmt) throw new Exception('Failed to prepare bank account update.');
        $stmt->bind_param('di', $bankNewBalance, $bank_account_id);
        if (!$stmt->execute()) throw new Exception('Failed to update bank account balance.');
        $stmt->close();
        if (!insertRepairPaymentChartEntry($conn, $branchForTransaction, $bank_account_id, (string)$bank['account_title'], $payment_date, $transaction_type, $transaction_no, $reference_no, $memo, 0.00, $amount, $bankNewBalance, $payment_id, $created_by)) {
            throw new Exception('Failed to insert bank ledger entry.');
        }

        $conn->commit();
        return ['success' => true, 'message' => 'Repair payment was posted to Chart of Accounts.'];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


$vehicle_table = 'motorpool_vehicles';
$vehicle_table_exists = tableExists($conn, $vehicle_table);

// Auto-create motorpool_vehicles table if it doesn't exist
if (!$vehicle_table_exists) {
    $createTableSQL = "CREATE TABLE IF NOT EXISTS `motorpool_vehicles` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `vehicle_id` VARCHAR(50) UNIQUE NOT NULL,
        `lto_cr_no` VARCHAR(100),
        `color` VARCHAR(50),
        `date_registration` DATE,
        `type_of_fuel` VARCHAR(50),
        `plate_no` VARCHAR(50) UNIQUE NOT NULL,
        `classification` VARCHAR(100),
        `engine_no` VARCHAR(100),
        `body_type` VARCHAR(100),
        `chassis_no` VARCHAR(100),
        `series` VARCHAR(100),
        `vin` VARCHAR(100),
        `gross_weight` VARCHAR(50),
        `file_no` VARCHAR(100),
        `net_weight` VARCHAR(50),
        `vehicle_type` VARCHAR(100),
        `year_model` VARCHAR(50),
        `vehicle_category` VARCHAR(100),
        `year_rebuilt` VARCHAR(50),
        `make_brand` VARCHAR(100),
        `piston_displacement` VARCHAR(100),
        `max_power_kw` VARCHAR(50),
        `passenger_capacity` VARCHAR(50),
        `status` VARCHAR(20) DEFAULT 'active',
        `vehicle_image` VARCHAR(255),
        `cr_vehicle_images` LONGTEXT,
        `reg_date` DATE,
        `or_no` VARCHAR(100),
        `next_renewal` DATE,
        `or_attachment` VARCHAR(255),
        `branch_id` INT,
        `created_by` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_branch_id` (`branch_id`),
        KEY `idx_vehicle_id` (`vehicle_id`),
        KEY `idx_plate_no` (`plate_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($createTableSQL)) {
        $vehicle_table_exists = true;
    }
}

// Add missing column for the separate vehicle image upload on existing databases.
if ($vehicle_table_exists) {
    $existingVehicleColumns = getColumns($conn, $vehicle_table);
    if (!in_array('vehicle_image', $existingVehicleColumns, true)) {
        $conn->query("ALTER TABLE `$vehicle_table` ADD COLUMN `vehicle_image` VARCHAR(255) NULL AFTER `status`");
    }
}

// Auto-create RIS request and repair history tables if they do not exist.
$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_ris_requests` (
    `ris_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_number` VARCHAR(50) UNIQUE NOT NULL,
    `vehicle_db_id` INT NOT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `vehicle_details` VARCHAR(255) DEFAULT NULL,
    `vehicle_category` VARCHAR(150) DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `requested_by` INT DEFAULT NULL,
    `concerns` TEXT NOT NULL,
    `endorsed_by` VARCHAR(255) DEFAULT NULL,
    `date_requested` DATE DEFAULT NULL,
    `status` ENUM('Pending','Ongoing','Completed','Rejected') DEFAULT 'Pending',
    `findings` TEXT DEFAULT NULL,
    `action_taken` TEXT DEFAULT NULL,
    `repairs_done` TEXT DEFAULT NULL,
    `parts_replaced` TEXT DEFAULT NULL,
    `mechanic` VARCHAR(255) DEFAULT NULL,
    `repair_start_date` DATE DEFAULT NULL,
    `repair_end_date` DATE DEFAULT NULL,
    `repair_cost` DECIMAL(12,2) DEFAULT 0.00,
    `ris_attachment` VARCHAR(255) DEFAULT NULL,
    `completed_by` INT DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_branch_id` (`branch_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('endorsed_signature', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `endorsed_signature` LONGTEXT NULL AFTER `endorsed_by`");
}

// Workflow columns used by the Motorpool account and Branch Admin approval.
// Convert status to VARCHAR so new workflow statuses such as For Approval and For Parts Completion can be saved.
$conn->query("ALTER TABLE `motorpool_ris_requests` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'Pending'");
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('workflow_status', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `workflow_status` VARCHAR(50) DEFAULT 'For Vehicle Endorsement' AFTER `status`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('branch_approval_status', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_status` VARCHAR(30) DEFAULT 'Pending' AFTER `workflow_status`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('branch_approval_by', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_by` INT DEFAULT NULL AFTER `branch_approval_status`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('branch_approval_at', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_at` DATETIME DEFAULT NULL AFTER `branch_approval_by`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('branch_approval_remarks', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_remarks` TEXT DEFAULT NULL AFTER `branch_approval_at`");
}
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'parts_purchase_by', "`parts_purchase_by` VARCHAR(30) NOT NULL DEFAULT 'motorpool' AFTER `branch_approval_remarks`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'branch_parts_total', "`branch_parts_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `parts_purchase_by`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'motorpool_parts_total', "`motorpool_parts_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `branch_parts_total`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'branch_parts_purchase_remarks', "`branch_parts_purchase_remarks` TEXT DEFAULT NULL AFTER `motorpool_parts_total`");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_branch_parts_purchases` (
    `purchase_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `vehicle_db_id` INT DEFAULT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `repair_description` TEXT DEFAULT NULL,
    `item_no` VARCHAR(120) DEFAULT NULL,
    `item_description` TEXT DEFAULT NULL,
    `specification` TEXT DEFAULT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `expense_memo` TEXT DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `purchased_by` INT DEFAULT NULL,
    `purchased_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ris_id` (`ris_id`),
    KEY `idx_branch_id` (`branch_id`),
    KEY `idx_purchased_at` (`purchased_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'source_status', "`source_status` VARCHAR(40) NOT NULL DEFAULT 'pending_source' AFTER `total_cost`");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'estimated_unit_cost', "`estimated_unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `source_status`");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'estimated_total_cost', "`estimated_total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `estimated_unit_cost`");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'actual_unit_cost', "`actual_unit_cost` DECIMAL(12,2) DEFAULT NULL AFTER `estimated_total_cost`");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'actual_total_cost', "`actual_total_cost` DECIMAL(12,2) DEFAULT NULL AFTER `actual_unit_cost`");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'supplier_name', "`supplier_name` VARCHAR(255) DEFAULT NULL AFTER `actual_total_cost`");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'source_attachment', "`source_attachment` VARCHAR(255) DEFAULT NULL AFTER `supplier_name`");
addColumnIfMissingSafe($conn, 'motorpool_branch_parts_purchases', 'expense_posted', "`expense_posted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `actual_total_cost`");
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('motorpool_return_remarks', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `motorpool_return_remarks` TEXT DEFAULT NULL AFTER `branch_approval_remarks`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('motorpool_returned_by', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `motorpool_returned_by` INT DEFAULT NULL AFTER `motorpool_return_remarks`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('motorpool_returned_at', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `motorpool_returned_at` DATETIME DEFAULT NULL AFTER `motorpool_returned_by`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('branch_resubmission_remarks', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_resubmission_remarks` TEXT DEFAULT NULL AFTER `motorpool_returned_at`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('branch_resubmitted_by', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_resubmitted_by` INT DEFAULT NULL AFTER `branch_resubmission_remarks`");
}
$risRequestColumns = getColumns($conn, 'motorpool_ris_requests');
if (!in_array('branch_resubmitted_at', $risRequestColumns, true)) {
    $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_resubmitted_at` DATETIME DEFAULT NULL AFTER `branch_resubmitted_by`");
}

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_ris_assessments` (
    `assessment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `assessment_json` LONGTEXT NOT NULL,
    `repairs_summary` TEXT DEFAULT NULL,
    `parts_summary` TEXT DEFAULT NULL,
    `assessed_by` INT DEFAULT NULL,
    `assessed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ris_assessment` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_ris_quality_checks` (
    `quality_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `quality_check_json` LONGTEXT DEFAULT NULL,
    `quality_summary` TEXT DEFAULT NULL,
    `quality_check_by` VARCHAR(255) NOT NULL,
    `quality_check_datetime` DATETIME NOT NULL,
    `remarks` TEXT DEFAULT NULL,
    `saved_by` INT DEFAULT NULL,
    `saved_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ris_quality_check` (`ris_id`),
    KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Sync older quality-checked RIS rows so Branch Admin can see them as For Release.
if (tableExists($conn, 'motorpool_ris_quality_checks')) {
    $conn->query("UPDATE motorpool_ris_requests r
        INNER JOIN motorpool_ris_quality_checks qc ON qc.ris_id = r.ris_id
        SET r.workflow_status = 'For Release',
            r.status = 'For Release',
            r.action_taken = 'Quality check completed. Ready for release.'
        WHERE r.completed_at IS NULL
          AND LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) IN ('for quality check', 'quality check', 'for release')");
}



$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_ris_workflow_history` (
    `history_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `vehicle_db_id` INT DEFAULT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `workflow_status` VARCHAR(100) NOT NULL,
    `details` LONGTEXT DEFAULT NULL,
    `attachment` LONGTEXT DEFAULT NULL,
    `processed_by` INT DEFAULT NULL,
    `processed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ris_id` (`ris_id`),
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_workflow_status` (`workflow_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


$conn->query("CREATE TABLE IF NOT EXISTS `vehicle_repair_history` (
    `repair_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT DEFAULT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `vehicle_db_id` INT NOT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `repair_date` DATE DEFAULT NULL,
    `repairs_done` TEXT DEFAULT NULL,
    `parts_replaced` TEXT DEFAULT NULL,
    `mechanic` VARCHAR(255) DEFAULT NULL,
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `attachment` VARCHAR(255) DEFAULT NULL,
    `repair_cost` DECIMAL(12,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

addColumnIfMissingSafe($conn, 'vehicle_repair_history', 'backlog_count', '`backlog_count` INT NOT NULL DEFAULT 0 AFTER `repair_cost`');
addColumnIfMissingSafe($conn, 'vehicle_repair_history', 'last_backlog_at', '`last_backlog_at` DATETIME DEFAULT NULL AFTER `backlog_count`');

addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'is_repair_backlog', "`is_repair_backlog` TINYINT(1) NOT NULL DEFAULT 0 AFTER `branch_approval_remarks`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'parent_ris_id', "`parent_ris_id` INT DEFAULT NULL AFTER `is_repair_backlog`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'parent_ris_number', "`parent_ris_number` VARCHAR(50) DEFAULT NULL AFTER `parent_ris_id`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'backlog_source_repair_id', "`backlog_source_repair_id` INT DEFAULT NULL AFTER `parent_ris_number`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'motorpool_return_remarks', "`motorpool_return_remarks` TEXT DEFAULT NULL AFTER `branch_approval_remarks`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'motorpool_returned_by', "`motorpool_returned_by` INT DEFAULT NULL AFTER `motorpool_return_remarks`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'motorpool_returned_at', "`motorpool_returned_at` DATETIME DEFAULT NULL AFTER `motorpool_returned_by`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'branch_resubmission_remarks', "`branch_resubmission_remarks` TEXT DEFAULT NULL AFTER `motorpool_returned_at`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'branch_resubmitted_by', "`branch_resubmitted_by` INT DEFAULT NULL AFTER `branch_resubmission_remarks`");
addColumnIfMissingSafe($conn, 'motorpool_ris_requests', 'branch_resubmitted_at', "`branch_resubmitted_at` DATETIME DEFAULT NULL AFTER `branch_resubmitted_by`");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_repair_backlogs` (
    `backlog_id` INT AUTO_INCREMENT PRIMARY KEY,
    `source_repair_id` INT NOT NULL,
    `source_ris_id` INT DEFAULT NULL,
    `source_ris_number` VARCHAR(50) DEFAULT NULL,
    `new_ris_id` INT DEFAULT NULL,
    `new_ris_number` VARCHAR(50) DEFAULT NULL,
    `vehicle_db_id` INT NOT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `backlog_date` DATE DEFAULT NULL,
    `problem_description` TEXT NOT NULL,
    `remarks` TEXT DEFAULT NULL,
    `attachment` VARCHAR(255) DEFAULT NULL,
    `reported_by` INT DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_source_repair_id` (`source_repair_id`),
    KEY `idx_source_ris_id` (`source_ris_id`),
    KEY `idx_new_ris_id` (`new_ris_id`),
    KEY `idx_vehicle_db_id` (`vehicle_db_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");



// Repair payment tracking for completed Motorpool repairs.
// This is shown inside Vehicle Details > Repair Payments beside Repair History.
$conn->query("CREATE TABLE IF NOT EXISTS `repair_payment_history` (
    `payment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT DEFAULT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `vehicle_db_id` INT DEFAULT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `repair_date` DATE DEFAULT NULL,
    `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_date` DATE DEFAULT NULL,
    `payment_method` VARCHAR(100) DEFAULT NULL,
    `reference_no` VARCHAR(120) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `attachment` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ris_id` (`ris_id`),
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_payment_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

addColumnIfMissingSafe($conn, 'repair_payment_history', 'expense_account_id', '`expense_account_id` INT DEFAULT NULL AFTER `payment_method`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'expense_account_name', '`expense_account_name` VARCHAR(255) DEFAULT NULL AFTER `expense_account_id`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'bank_account_id', '`bank_account_id` INT DEFAULT NULL AFTER `expense_account_name`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'bank_account_name', '`bank_account_name` VARCHAR(255) DEFAULT NULL AFTER `bank_account_id`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'check_date', '`check_date` DATE DEFAULT NULL AFTER `reference_no`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'bank_name', '`bank_name` VARCHAR(150) DEFAULT NULL AFTER `check_date`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'bank_branch', '`bank_branch` VARCHAR(150) DEFAULT NULL AFTER `bank_name`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'check_number', '`check_number` VARCHAR(100) DEFAULT NULL AFTER `bank_branch`');
addColumnIfMissingSafe($conn, 'repair_payment_history', 'payment_scope', "`payment_scope` VARCHAR(40) NOT NULL DEFAULT 'motorpool' AFTER `amount_paid`");
$repairPaymentExpenseAccounts = getRepairPaymentExpenseAccounts($conn);
$repairPaymentBankAccounts = getRepairPaymentBankAccounts($conn, (bool)$view_all_branches, (int)$branch_id);


function cleanupDuplicateRepairHistory(mysqli $conn): void {
    // Do not delete repair history rows automatically.
    // Previous cleanup removed duplicate RIS records, but it also made some Branch Admin
    // repair history records disappear from the Vehicle Details modal.
    return;
}

cleanupDuplicateRepairHistory($conn);


$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_registration_history` (
    `registration_id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_db_id` INT NOT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `or_no` VARCHAR(100) DEFAULT NULL,
    `reg_date` DATE DEFAULT NULL,
    `next_renewal` DATE DEFAULT NULL,
    `or_attachment` VARCHAR(255) DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `encoded_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_branch_id` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_fuel_monitoring` (
    `fuel_id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_db_id` INT NOT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `fuel_date` DATE NOT NULL,
    `current_odometer` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `previous_odometer` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `distance_covered` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `liters_consumed` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `fuel_efficiency` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `branch_id` INT DEFAULT NULL,
    `encoded_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_branch_id` (`branch_id`),
    KEY `idx_fuel_date` (`fuel_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
addColumnIfMissingSafe($conn, 'motorpool_fuel_monitoring', 'refuel_liters', '`refuel_liters` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `liters_consumed`');
addColumnIfMissingSafe($conn, 'motorpool_fuel_monitoring', 'fuel_price', '`fuel_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `fuel_efficiency`');
addColumnIfMissingSafe($conn, 'motorpool_fuel_monitoring', 'fuel_attachment', '`fuel_attachment` VARCHAR(255) DEFAULT NULL AFTER `fuel_price`');
addColumnIfMissingSafe($conn, 'motorpool_fuel_monitoring', 'driver_id', '`driver_id` INT DEFAULT NULL AFTER `encoded_by`');
addColumnIfMissingSafe($conn, 'motorpool_fuel_monitoring', 'driver_name', '`driver_name` VARCHAR(255) DEFAULT NULL AFTER `driver_id`');


function generateRisNumber(mysqli $conn): string {
    $prefix = 'RIS-' . date('Ymd') . '-';
    $result = $conn->query("SELECT ris_number FROM motorpool_ris_requests WHERE ris_number LIKE '" . $conn->real_escape_string($prefix) . "%' ORDER BY ris_id DESC LIMIT 1");
    $next = 1;
    if ($result && ($row = $result->fetch_assoc())) {
        $last = (string)$row['ris_number'];
        $num = (int)substr($last, -4);
        $next = $num + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function generateRepairBacklogRisNumber(mysqli $conn, string $parentRisNumber): string {
    $base = trim($parentRisNumber);
    if ($base === '') return generateRisNumber($conn);
    $base = preg_replace('/[^A-Za-z0-9\-]/', '', $base);
    if (strlen($base) > 40) $base = substr($base, 0, 40);
    $prefix = $base . '-BL';
    $result = $conn->query("SELECT ris_number FROM motorpool_ris_requests WHERE ris_number LIKE '" . $conn->real_escape_string($prefix) . "%' ORDER BY ris_id DESC LIMIT 1");
    $next = 1;
    if ($result && ($row = $result->fetch_assoc())) {
        $last = (string)$row['ris_number'];
        if (preg_match('/-BL(\d+)$/', $last, $matches)) $next = ((int)$matches[1]) + 1;
    }
    return $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT);
}

function jsonResponse(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_ris') {
    $vehicle_db_id = (int)($_POST['vehicle_db_id'] ?? 0);
    $vehicle_id_value = trim($_POST['vehicle_id'] ?? '');
    $plate_no = trim($_POST['plate_no'] ?? '');
    $vehicle_details = trim($_POST['vehicle_details'] ?? '');
    $vehicle_category = trim($_POST['vehicle_category'] ?? '');
    $make_brand_value = trim($_POST['make_brand'] ?? '');
    $vehicle_type_value = trim($_POST['vehicle_type'] ?? '');
    $classification_value = trim($_POST['classification'] ?? '');
    $body_type_value = trim($_POST['body_type'] ?? '');
    $color_value = trim($_POST['color'] ?? '');
    $fuel_type_value = trim($_POST['type_of_fuel'] ?? '');
    $year_model_value = trim($_POST['year_model'] ?? '');
    $series_value = trim($_POST['series'] ?? '');
    $passenger_capacity_value = trim($_POST['passenger_capacity'] ?? '');
    $max_power_value = trim($_POST['max_power_kw'] ?? '');
    $lto_cr_no_value = trim($_POST['lto_cr_no'] ?? '');
    $date_registration_value = trim($_POST['date_registration'] ?? '');
    $file_no_value = trim($_POST['file_no'] ?? '');
    $engine_no_value = trim($_POST['engine_no'] ?? '');
    $chassis_no_value = trim($_POST['chassis_no'] ?? '');
    $vin_value = trim($_POST['vin'] ?? '');
    $gross_weight_value = trim($_POST['gross_weight'] ?? '');
    $net_weight_value = trim($_POST['net_weight'] ?? '');
    $year_rebuilt_value = trim($_POST['year_rebuilt'] ?? '');
    $piston_displacement_value = trim($_POST['piston_displacement'] ?? '');
    $concerns = trim($_POST['concerns'] ?? '');
    $endorsed_by = trim($_POST['endorsed_by'] ?? '');
    $endorsed_signature = trim($_POST['signature'] ?? '');
    $date_requested = trim($_POST['date_requested'] ?? date('Y-m-d'));

    if ($vehicle_db_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    }
    if ($concerns === '') {
        jsonResponse(['success' => false, 'message' => 'Concern/s is required.']);
    }
    if ($endorsed_by === '') {
        jsonResponse(['success' => false, 'message' => 'Endorsed by is required.']);
    }

    $ris_number = generateRisNumber($conn);
    $stmt = $conn->prepare("INSERT INTO motorpool_ris_requests
        (ris_number, vehicle_db_id, vehicle_id, plate_no, vehicle_details, vehicle_category, branch_id, requested_by, concerns, endorsed_by, endorsed_signature, date_requested, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Failed to prepare RIS request: ' . $conn->error]);
    }
    $stmt->bind_param(
        'sissssiissss',
        $ris_number,
        $vehicle_db_id,
        $vehicle_id_value,
        $plate_no,
        $vehicle_details,
        $vehicle_category,
        $branch_id,
        $user_id,
        $concerns,
        $endorsed_by,
        $endorsed_signature,
        $date_requested
    );

    if ($stmt->execute()) {
        jsonResponse([
            'success' => true,
            'message' => 'RIS request sent to Motorpool account.',
            'ris_number' => $ris_number,
            'date_requested' => $date_requested,
            'vehicle_id' => $vehicle_id_value,
            'plate_no' => $plate_no,
            'vehicle_details' => $vehicle_details,
            'vehicle_category' => $vehicle_category,
            'make_brand' => $make_brand_value,
            'vehicle_type' => $vehicle_type_value,
            'classification' => $classification_value,
            'body_type' => $body_type_value,
            'color' => $color_value,
            'type_of_fuel' => $fuel_type_value,
            'year_model' => $year_model_value,
            'series' => $series_value,
            'passenger_capacity' => $passenger_capacity_value,
            'max_power_kw' => $max_power_value,
            'lto_cr_no' => $lto_cr_no_value,
            'date_registration' => $date_registration_value,
            'file_no' => $file_no_value,
            'engine_no' => $engine_no_value,
            'chassis_no' => $chassis_no_value,
            'vin' => $vin_value,
            'gross_weight' => $gross_weight_value,
            'net_weight' => $net_weight_value,
            'year_rebuilt' => $year_rebuilt_value,
            'piston_displacement' => $piston_displacement_value,
            'concerns' => $concerns,
            'endorsed_by' => $endorsed_by,
            'endorsed_signature' => $endorsed_signature
        ]);
    }

    jsonResponse(['success' => false, 'message' => 'Failed to send RIS request: ' . $stmt->error]);
}


function logRisWorkflowHistory(mysqli $conn, int $ris_id, string $status, string $details = '', string $attachment = '', int $processed_by = 0): void {
    $stmt = $conn->prepare("SELECT ris_number, vehicle_db_id, vehicle_id, plate_no FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;

    $check = $conn->prepare("SELECT history_id FROM motorpool_ris_workflow_history WHERE ris_id = ? AND workflow_status = ? ORDER BY history_id DESC LIMIT 1");
    $existing_id = 0;
    if ($check) {
        $check->bind_param('is', $ris_id, $status);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $existing_id = $existing ? (int)$existing['history_id'] : 0;
        $check->close();
    }

    if ($existing_id > 0) {
        $update = $conn->prepare("UPDATE motorpool_ris_workflow_history SET details = ?, attachment = CASE WHEN ? <> '' THEN ? ELSE attachment END, processed_by = ?, processed_at = NOW() WHERE history_id = ?");
        if ($update) {
            $update->bind_param('sssii', $details, $attachment, $attachment, $processed_by, $existing_id);
            $update->execute();
            $update->close();
        }
        return;
    }

    $insert = $conn->prepare("INSERT INTO motorpool_ris_workflow_history (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, workflow_status, details, attachment, processed_by, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$insert) return;
    $insert->bind_param('isisssssi', $ris_id, $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $status, $details, $attachment, $processed_by);
    $insert->execute();
    $insert->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_repair_backlog') {
    $source_repair_id = (int)($_POST['backlog_repair_id'] ?? 0);
    $source_ris_id = (int)($_POST['backlog_ris_id'] ?? 0);
    $source_ris_number = trim((string)($_POST['backlog_ris_number'] ?? ''));
    $vehicle_db_id = (int)($_POST['backlog_vehicle_db_id'] ?? 0);
    $backlog_date = trim((string)($_POST['backlog_date'] ?? date('Y-m-d')));
    $problem_description = trim((string)($_POST['backlog_problem_description'] ?? ''));
    $remarks = trim((string)($_POST['backlog_remarks'] ?? ''));

    if ($source_repair_id <= 0) jsonResponse(['success' => false, 'message' => 'Repair history record was not found.']);
    if ($vehicle_db_id <= 0) jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    if ($backlog_date === '') jsonResponse(['success' => false, 'message' => 'Backlog date is required.']);
    if ($problem_description === '') jsonResponse(['success' => false, 'message' => 'Please describe what was damaged again.']);

    $branchFilter = '';
    if (!$view_all_branches && (int)$branch_id > 0) {
        $branchFilter = ' AND (COALESCE(r.branch_id, v.branch_id) = ' . intval($branch_id) . ' OR r.branch_id IS NULL)';
    }

    $sql = "SELECT h.*,
                   COALESCE(r.branch_id, v.branch_id, ?) AS final_branch_id,
                   COALESCE(r.vehicle_details, CONCAT(COALESCE(v.make_brand,''), ' ', COALESCE(v.vehicle_type,''))) AS vehicle_details,
                   COALESCE(r.vehicle_category, v.vehicle_category) AS vehicle_category,
                   COALESCE(r.requested_by, ?) AS original_requested_by,
                   COALESCE(r.endorsed_by, '') AS original_endorsed_by
            FROM vehicle_repair_history h
            LEFT JOIN motorpool_ris_requests r ON r.ris_id = h.ris_id
            LEFT JOIN motorpool_vehicles v ON v.id = h.vehicle_db_id
            WHERE h.repair_id = ?
              AND h.vehicle_db_id = ?
              $branchFilter
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Failed to prepare backlog lookup: ' . $conn->error]);
    $stmt->bind_param('iiii', $branch_id, $user_id, $source_repair_id, $vehicle_db_id);
    $stmt->execute();
    $repair = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$repair) jsonResponse(['success' => false, 'message' => 'Repair history record was not found or is outside your branch.']);

    $finalSourceRisId = (int)($repair['ris_id'] ?? $source_ris_id);
    $finalSourceRisNumber = trim((string)($repair['ris_number'] ?? $source_ris_number));
    if ($finalSourceRisNumber === '') $finalSourceRisNumber = $source_ris_number;
    $newRisNumber = generateRepairBacklogRisNumber($conn, $finalSourceRisNumber);
    $attachment = uploadMotorpoolFile('backlog_attachment', '../uploads/motorpool/repair_backlogs');

    $finalVehicleId = trim((string)($repair['vehicle_id'] ?? ''));
    $finalPlateNo = trim((string)($repair['plate_no'] ?? ''));
    $finalBranchId = (int)($repair['final_branch_id'] ?? $branch_id);
    $vehicleDetails = trim((string)($repair['vehicle_details'] ?? ''));
    $vehicleCategory = trim((string)($repair['vehicle_category'] ?? ''));
    $endorsedBy = trim((string)($repair['original_endorsed_by'] ?? ''));
    if ($endorsedBy === '') $endorsedBy = $user_name;

    $concerns = "Repair Backlog from RIS: " . $finalSourceRisNumber . "\n\nDamaged Again / Issue:\n" . $problem_description;
    if ($remarks !== '') $concerns .= "\n\nRemarks:\n" . $remarks;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO motorpool_ris_requests
            (ris_number, vehicle_db_id, vehicle_id, plate_no, vehicle_details, vehicle_category, branch_id, requested_by, concerns, endorsed_by, date_requested, status, workflow_status, is_repair_backlog, parent_ris_id, parent_ris_number, backlog_source_repair_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'For Vehicle Endorsement', 1, ?, ?, ?)");
        if (!$stmt) throw new Exception('Failed to prepare new backlog RIS: ' . $conn->error);
        $stmt->bind_param('sissssiisssisi', $newRisNumber, $vehicle_db_id, $finalVehicleId, $finalPlateNo, $vehicleDetails, $vehicleCategory, $finalBranchId, $user_id, $concerns, $endorsedBy, $backlog_date, $finalSourceRisId, $finalSourceRisNumber, $source_repair_id);
        if (!$stmt->execute()) throw new Exception('Failed to create backlog RIS: ' . $stmt->error);
        $newRisId = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO motorpool_repair_backlogs
            (source_repair_id, source_ris_id, source_ris_number, new_ris_id, new_ris_number, vehicle_db_id, vehicle_id, plate_no, backlog_date, problem_description, remarks, attachment, reported_by, branch_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception('Failed to prepare backlog save: ' . $conn->error);
        $stmt->bind_param('iisissssssssii', $source_repair_id, $finalSourceRisId, $finalSourceRisNumber, $newRisId, $newRisNumber, $vehicle_db_id, $finalVehicleId, $finalPlateNo, $backlog_date, $problem_description, $remarks, $attachment, $user_id, $finalBranchId);
        if (!$stmt->execute()) throw new Exception('Failed to save repair backlog: ' . $stmt->error);
        $backlogId = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE vehicle_repair_history SET backlog_count = COALESCE(backlog_count, 0) + 1, last_backlog_at = NOW() WHERE repair_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $source_repair_id);
            $stmt->execute();
            $stmt->close();
        }

        logRisWorkflowHistory($conn, $newRisId, 'For Vehicle Endorsement', "Repair backlog created from RIS: " . $finalSourceRisNumber . "\nIssue: " . $problem_description . ($remarks !== '' ? "\nRemarks: " . $remarks : ''), $attachment, (int)$user_id);

        $conn->commit();
        jsonResponse([
            'success' => true,
            'message' => 'Repair backlog saved and sent to Motorpool request handler.',
            'backlog_id' => $backlogId,
            'new_ris_id' => $newRisId,
            'new_ris_number' => $newRisNumber,
            'source_ris_number' => $finalSourceRisNumber
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}





if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_repair_payment') {
    $ris_id = (int)($_POST['payment_ris_id'] ?? 0);
    $vehicle_db_id = (int)($_POST['payment_vehicle_db_id'] ?? 0);
    $ris_number = trim((string)($_POST['payment_ris_number'] ?? ''));
    $repair_date = trim((string)($_POST['payment_repair_date'] ?? ''));
    $total_cost = (float)str_replace(',', '', (string)($_POST['payment_total_cost'] ?? 0));
    $amount_paid = (float)str_replace(',', '', (string)($_POST['payment_amount'] ?? 0));
    $payment_scope = strtolower(trim((string)($_POST['payment_scope'] ?? 'motorpool')));
    if (!in_array($payment_scope, ['motorpool','branch_source'], true)) $payment_scope = 'motorpool';
    $payment_date = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $expense_account_id = (int)($_POST['expense_account_id'] ?? 0);
    $expense_account_name = trim((string)($_POST['expense_account_name'] ?? ''));
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
    $bank_account_name = trim((string)($_POST['bank_account_name'] ?? ''));
    $reference_no = trim((string)($_POST['payment_reference_no'] ?? ''));
    $check_date = trim((string)($_POST['payment_check_date'] ?? ''));
    $check_number = trim((string)($_POST['payment_check_number'] ?? ''));
    $bank_name = trim((string)($_POST['payment_bank_name'] ?? ''));
    $bank_branch = trim((string)($_POST['payment_bank_branch'] ?? ''));
    $remarks = trim((string)($_POST['payment_remarks'] ?? ''));

    if ($ris_id <= 0 && $ris_number === '') {
        jsonResponse(['success' => false, 'message' => 'RIS record was not found.']);
    }
    if ($vehicle_db_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    }
    if ($total_cost <= 0) {
        jsonResponse(['success' => false, 'message' => 'Total cost must be greater than zero.']);
    }
    if ($amount_paid <= 0) {
        jsonResponse(['success' => false, 'message' => 'Amount paid must be greater than zero.']);
    }
    if ($payment_date === '') {
        jsonResponse(['success' => false, 'message' => 'Payment date is required.']);
    }
    if ($payment_method === '') {
        jsonResponse(['success' => false, 'message' => 'Payment method is required.']);
    }
    if ($bank_account_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Bank account is required.']);
    }
    if ($expense_account_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Expense account is required.']);
    }
    $normalizedPaymentMethod = strtolower(str_replace([' ', '-'], '_', $payment_method));
    if ($normalizedPaymentMethod === 'check' && $check_number === '') {
        jsonResponse(['success' => false, 'message' => 'Check number is required.']);
    }
    $expenseLookup = [];
    foreach (getRepairPaymentExpenseAccounts($conn) as $acc) $expenseLookup[(int)$acc['account_id']] = (string)$acc['account_name'];
    if ($expense_account_name === '' && isset($expenseLookup[$expense_account_id])) $expense_account_name = $expenseLookup[$expense_account_id];
    $bankLookup = [];
    foreach (getRepairPaymentBankAccounts($conn, (bool)$view_all_branches, (int)$branch_id) as $bank) $bankLookup[(int)$bank['bank_id']] = $bank;
    if ($bank_account_id > 0 && isset($bankLookup[$bank_account_id])) {
        $bankRow = $bankLookup[$bank_account_id];
        if ($bank_account_name === '') $bank_account_name = (string)($bankRow['display_name'] ?? $bankRow['bank_name'] ?? '');
        if ($bank_name === '') $bank_name = (string)($bankRow['bank_name'] ?? '');
        if ($bank_branch === '') $bank_branch = (string)($bankRow['bank_branch'] ?? '');
    }

    $whereBranch = '';
    if (!$view_all_branches && (int)$branch_id > 0) {
        $whereBranch = ' AND COALESCE(r.branch_id, h.vehicle_db_id) IS NOT NULL AND (r.branch_id = ' . intval($branch_id) . ' OR r.branch_id IS NULL)';
    }

    $repair = null;
    if (tableExists($conn, 'vehicle_repair_history')) {
        $sql = "SELECT h.ris_id, h.ris_number, h.vehicle_db_id, h.vehicle_id, h.plate_no, h.repair_date
                FROM vehicle_repair_history h
                LEFT JOIN motorpool_ris_requests r ON r.ris_id = h.ris_id
                WHERE h.vehicle_db_id = ?
                  AND (h.ris_id = ? OR h.ris_number = ?)
                  $whereBranch
                ORDER BY h.repair_id DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iis', $vehicle_db_id, $ris_id, $ris_number);
            $stmt->execute();
            $repair = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }

    if (!$repair && tableExists($conn, 'motorpool_ris_requests')) {
        $sql = "SELECT ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, DATE(COALESCE(completed_at, updated_at, created_at)) AS repair_date
                FROM motorpool_ris_requests r
                WHERE r.vehicle_db_id = ?
                  AND (r.ris_id = ? OR r.ris_number = ?)
                  $whereBranch
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iis', $vehicle_db_id, $ris_id, $ris_number);
            $stmt->execute();
            $repair = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }

    if (!$repair) {
        jsonResponse(['success' => false, 'message' => 'Repair history record was not found.']);
    }

    $existingPaid = 0.0;
    if (tableExists($conn, 'repair_payment_history')) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) AS paid_total FROM repair_payment_history WHERE vehicle_db_id = ? AND (ris_id = ? OR ris_number = ?) AND COALESCE(payment_scope, 'motorpool') = ?");
        if ($stmt) {
            $stmt->bind_param('iiss', $vehicle_db_id, $ris_id, $ris_number, $payment_scope);
            $stmt->execute();
            $paidRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $existingPaid = (float)($paidRow['paid_total'] ?? 0);
        }
    }

    $balance = max(0, $total_cost - $existingPaid);
    if ($amount_paid > ($balance + 0.01)) {
        jsonResponse(['success' => false, 'message' => 'Amount paid is greater than the current balance.']);
    }

    $attachment = uploadMotorpoolFile('payment_attachment', '../uploads/motorpool/repair_payments');
    $finalRisId = (int)($repair['ris_id'] ?? $ris_id);
    $finalRisNumber = trim((string)($repair['ris_number'] ?? $ris_number));
    $finalVehicleDbId = (int)($repair['vehicle_db_id'] ?? $vehicle_db_id);
    $finalVehicleId = trim((string)($repair['vehicle_id'] ?? ''));
    $finalPlateNo = trim((string)($repair['plate_no'] ?? ''));
    $finalRepairDate = trim((string)($repair['repair_date'] ?? $repair_date));
    if ($finalRepairDate === '') $finalRepairDate = $payment_date;

    $stmt = $conn->prepare("INSERT INTO repair_payment_history
        (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, repair_date, total_cost, amount_paid, payment_scope, payment_date, payment_method, expense_account_id, expense_account_name, bank_account_id, bank_account_name, reference_no, check_date, bank_name, bank_branch, check_number, remarks, attachment, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Failed to prepare repair payment: ' . $conn->error]);
    }
    $stmt->bind_param('isisssddsssisissssssssi', $finalRisId, $finalRisNumber, $finalVehicleDbId, $finalVehicleId, $finalPlateNo, $finalRepairDate, $total_cost, $amount_paid, $payment_scope, $payment_date, $payment_method, $expense_account_id, $expense_account_name, $bank_account_id, $bank_account_name, $reference_no, $check_date, $bank_name, $bank_branch, $check_number, $remarks, $attachment, $user_id);
    if (!$stmt->execute()) {
        jsonResponse(['success' => false, 'message' => 'Failed to save repair payment: ' . $stmt->error]);
    }
    $payment_id = $stmt->insert_id;
    $stmt->close();

    $paymentRemarksMemo = preg_replace('/\R+/', ', ', trim((string)$remarks));
    $posting = postRepairPaymentToChartOfAccounts($conn, $payment_id, $bank_account_id, $expense_account_id, $amount_paid, $payment_date, $finalRisId, $finalRisNumber, $payment_method, $reference_no, $paymentRemarksMemo, (int)$branch_id, (int)$user_id);
    if (empty($posting['success'])) {
        jsonResponse(['success' => false, 'message' => 'Payment was saved, but Chart of Accounts posting failed: ' . ($posting['message'] ?? 'Unknown error.')]);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Repair payment saved successfully.',
        'payment' => [
            'payment_id' => $payment_id,
            'ris_id' => $finalRisId,
            'ris_number' => $finalRisNumber,
            'vehicle_db_id' => $finalVehicleDbId,
            'vehicle_id' => $finalVehicleId,
            'plate_no' => $finalPlateNo,
            'repair_date' => $finalRepairDate,
            'total_cost' => number_format($total_cost, 2, '.', ''),
            'amount_paid' => number_format($amount_paid, 2, '.', ''),
            'payment_scope' => $payment_scope,
            'payment_date' => $payment_date,
            'payment_method' => $payment_method,
            'expense_account_id' => $expense_account_id,
            'expense_account_name' => $expense_account_name,
            'bank_account_id' => $bank_account_id,
            'bank_account_name' => $bank_account_name,
            'reference_no' => $reference_no,
            'check_date' => $check_date,
            'bank_name' => $bank_name,
            'bank_branch' => $bank_branch,
            'check_number' => $check_number,
            'remarks' => $remarks,
            'attachment' => $attachment,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_branch_source_actual_costs') {
    $ris_id = (int)($_POST['source_ris_id'] ?? 0);
    if ($ris_id <= 0) jsonResponse(['success' => false, 'message' => 'RIS record was not found.']);

    $purchaseIds = $_POST['purchase_id'] ?? [];
    $suppliers = $_POST['supplier_name'] ?? [];
    $actualUnitCosts = $_POST['actual_unit_cost'] ?? [];
    $actualTotalCosts = $_POST['actual_total_cost'] ?? [];

    if (!is_array($purchaseIds) || empty($purchaseIds)) {
        jsonResponse(['success' => false, 'message' => 'No Branch Source item was found.']);
    }

    $updated = 0;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE motorpool_branch_parts_purchases
            SET supplier_name = ?,
                actual_unit_cost = ?,
                actual_total_cost = ?,
                source_status = 'sourced',
                expense_posted = 0
            WHERE purchase_id = ? AND ris_id = ?");
        if (!$stmt) throw new Exception('Failed to prepare Branch Source update: ' . $conn->error);

        foreach ($purchaseIds as $i => $purchaseIdRaw) {
            $purchaseId = (int)$purchaseIdRaw;
            if ($purchaseId <= 0) continue;
            $supplier = trim((string)($suppliers[$i] ?? ''));
            $actualUnit = motorpoolCostNumber($actualUnitCosts[$i] ?? 0);
            $actualTotal = motorpoolCostNumber($actualTotalCosts[$i] ?? 0);
            if ($supplier === '') throw new Exception('Supplier is required for each Branch Source item.');
            if ($actualUnit <= 0 && $actualTotal <= 0) throw new Exception('Actual cost is required for each Branch Source item.');
            if ($actualTotal <= 0) {
                $qtyStmt = $conn->prepare("SELECT quantity FROM motorpool_branch_parts_purchases WHERE purchase_id = ? AND ris_id = ? LIMIT 1");
                $qty = 1.0;
                if ($qtyStmt) {
                    $qtyStmt->bind_param('ii', $purchaseId, $ris_id);
                    $qtyStmt->execute();
                    $qtyRow = $qtyStmt->get_result()->fetch_assoc();
                    $qtyStmt->close();
                    $qty = max(1.0, motorpoolCostNumber($qtyRow['quantity'] ?? 1));
                }
                $actualTotal = $actualUnit * $qty;
            }
            if ($actualUnit <= 0 && $actualTotal > 0) {
                $qtyStmt = $conn->prepare("SELECT quantity FROM motorpool_branch_parts_purchases WHERE purchase_id = ? AND ris_id = ? LIMIT 1");
                $qty = 1.0;
                if ($qtyStmt) {
                    $qtyStmt->bind_param('ii', $purchaseId, $ris_id);
                    $qtyStmt->execute();
                    $qtyRow = $qtyStmt->get_result()->fetch_assoc();
                    $qtyStmt->close();
                    $qty = max(1.0, motorpoolCostNumber($qtyRow['quantity'] ?? 1));
                }
                $actualUnit = $actualTotal / $qty;
            }
            $stmt->bind_param('sddii', $supplier, $actualUnit, $actualTotal, $purchaseId, $ris_id);
            if (!$stmt->execute()) throw new Exception('Failed to save Branch Source item cost: ' . $stmt->error);
            $updated += $stmt->affected_rows >= 0 ? 1 : 0;
        }
        $stmt->close();
        logRisWorkflowHistory($conn, $ris_id, 'Branch Source Cost Updated', 'Branch Source supplier and actual cost were encoded by Branch Admin.', '', (int)$user_id);
        $conn->commit();
        jsonResponse(['success' => true, 'message' => 'Branch Source actual costs saved successfully.', 'updated' => $updated]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

$vehicle_columns = $vehicle_table_exists ? getColumns($conn, $vehicle_table) : [];
$save_message = '';
$save_status = '';

$fieldMap = [
    'vehicle_id' => ['vehicle_id','vehicle_code','vehicle_no'],
    'lto_cr_no' => ['lto_cr_no','cr_no'],
    'date_registration' => ['date_registration','registration_date','date_of_registration'],
    'plate_no' => ['plate_no','plate_number'],
    'engine_no' => ['engine_no','engine_number'],
    'chassis_no' => ['chassis_no','chassis_number'],
    'vin' => ['vin'],
    'file_no' => ['file_no'],
    'vehicle_type' => ['vehicle_type','type'],
    'vehicle_category' => ['vehicle_category','category'],
    'make_brand' => ['make_brand','make','brand'],
    'passenger_capacity' => ['passenger_capacity'],
    'color' => ['color'],
    'type_of_fuel' => ['type_of_fuel','fuel_type'],
    'classification' => ['classification'],
    'body_type' => ['body_type'],
    'series' => ['series'],
    'gross_weight' => ['gross_weight'],
    'net_weight' => ['net_weight'],
    'year_model' => ['year_model'],
    'year_rebuilt' => ['year_rebuilt'],
    'piston_displacement' => ['piston_displacement'],
    'max_power_kw' => ['max_power_kw','max_power'],
    'vehicle_image' => ['vehicle_image'],
    'cr_vehicle_images' => ['cr_vehicle_images','attachments','vehicle_images'],
    'reg_date' => ['reg_date','registration_history_date'],
    'or_no' => ['or_no'],
    'next_renewal' => ['next_renewal'],
    'or_attachment' => ['or_attachment'],
    'branch_id' => ['branch_id'],
    'created_by' => ['created_by','encoded_by'],
    'created_at' => ['created_at','date_created']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resubmit_returned_motorpool_ris') {
    $ris_id = (int)($_POST['returned_ris_id'] ?? 0);
    $updated_concerns = trim((string)($_POST['returned_concerns'] ?? ''));
    $endorsed_by = trim((string)($_POST['returned_endorsed_by'] ?? ''));
    $date_requested = trim((string)($_POST['returned_date_requested'] ?? date('Y-m-d')));
    $resubmission_remarks = trim((string)($_POST['branch_resubmission_remarks'] ?? ''));

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'Returned RIS record was not found.';
    } elseif ($updated_concerns === '') {
        $save_status = 'error';
        $save_message = 'Concern/s is required before resubmitting.';
    } elseif ($endorsed_by === '') {
        $save_status = 'error';
        $save_message = 'Endorsed by is required before resubmitting.';
    } elseif ($date_requested === '') {
        $save_status = 'error';
        $save_message = 'Date requested is required before resubmitting.';
    } else {
        $scopeWhere = '';
        if (!$view_all_branches && (int)$branch_id > 0) {
            $scopeWhere = ' AND (r.branch_id = ' . intval($branch_id) . ' OR v.branch_id = ' . intval($branch_id) . ' OR r.requested_by = ' . intval($user_id) . ')';
        }

        $sql = "SELECT r.ris_id, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no, r.motorpool_return_remarks, COALESCE(r.workflow_status, r.status) AS current_status
                FROM motorpool_ris_requests r
                LEFT JOIN motorpool_vehicles v ON v.id = r.vehicle_db_id
                WHERE r.ris_id = ?
                  AND (
                        LOWER(TRIM(COALESCE(r.workflow_status, ''))) = 'returned to branch admin'
                        OR LOWER(TRIM(COALESCE(r.status, ''))) = 'returned to branch admin'
                        OR (COALESCE(r.motorpool_return_remarks, '') <> '' AND LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) LIKE '%returned%')
                      )
                  $scopeWhere
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare resubmission check: ' . $conn->error;
        } else {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $returnedRis = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$returnedRis) {
                $save_status = 'error';
                $save_message = 'Returned RIS was not found or is outside your branch.';
            } else {
                $conn->begin_transaction();
                try {
                    $newStatus = 'For Vehicle Endorsement';
                    $update = $conn->prepare("UPDATE motorpool_ris_requests
                        SET concerns = ?,
                            endorsed_by = ?,
                            date_requested = ?,
                            workflow_status = ?,
                            status = ?,
                            branch_resubmission_remarks = ?,
                            branch_resubmitted_by = ?,
                            branch_resubmitted_at = NOW(),
                            updated_at = NOW()
                        WHERE ris_id = ?");
                    if (!$update) throw new Exception('Failed to prepare RIS resubmission: ' . $conn->error);
                    $update->bind_param('ssssssii', $updated_concerns, $endorsed_by, $date_requested, $newStatus, $newStatus, $resubmission_remarks, $user_id, $ris_id);
                    if (!$update->execute()) throw new Exception('Failed to resubmit RIS: ' . $update->error);
                    $update->close();

                    $details = "Returned RIS corrected and resubmitted by Branch Admin.\nConcern/s: " . $updated_concerns;
                    if ($resubmission_remarks !== '') $details .= "\nBranch Remarks: " . $resubmission_remarks;
                    if (trim((string)($returnedRis['motorpool_return_remarks'] ?? '')) !== '') {
                        $details .= "\nPrevious Motorpool Return Remarks: " . trim((string)$returnedRis['motorpool_return_remarks']);
                    }
                    logRisWorkflowHistory($conn, $ris_id, 'For Vehicle Endorsement', $details, '', (int)$user_id);

                    $conn->commit();
                    $save_status = 'success';
                    $save_message = 'Returned RIS was updated and resubmitted to Motorpool.';
                } catch (Exception $e) {
                    $conn->rollback();
                    $save_status = 'error';
                    $save_message = $e->getMessage();
                }
            }
        }
    }
}


function motorpoolApprovalNumber($value): float {
    if ($value === null) return 0.0;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($clean === '' || !is_numeric($clean)) return 0.0;
    return (float)$clean;
}
function motorpoolApprovalRepairText(array $repair): string {
    foreach (['repair','repairs_to_make','repair_to_make','description','action','work_required'] as $key) {
        if (isset($repair[$key]) && trim((string)$repair[$key]) !== '') return trim((string)$repair[$key]);
    }
    return '';
}
function motorpoolApprovalParts(array $repair): array {
    foreach (['parts','items','parts_needed','items_needed'] as $key) {
        if (isset($repair[$key]) && is_array($repair[$key])) return $repair[$key];
    }
    return [];
}
function motorpoolPartQtyForApproval(array $part): float {
    foreach (['quantity','qty','qty_needed','quantity_needed','needed_quantity'] as $key) {
        if (isset($part[$key])) return motorpoolApprovalNumber($part[$key]);
    }
    return 0.0;
}
function motorpoolPartUnitCostForApproval(array $part): float {
    foreach (['unit_cost','cost','item_cost','inventory_cost','price'] as $key) {
        if (isset($part[$key])) return motorpoolApprovalNumber($part[$key]);
    }
    return 0.0;
}
function motorpoolPartTotalForApproval(array $part): float {
    foreach (['estimated_cost','total_cost','total_estimated_cost','amount'] as $key) {
        if (isset($part[$key]) && trim((string)$part[$key]) !== '') return motorpoolApprovalNumber($part[$key]);
    }
    return motorpoolPartQtyForApproval($part) * motorpoolPartUnitCostForApproval($part);
}
function motorpoolPartSelectionKey(int $repairIndex, int $partIndex): string {
    return $repairIndex . '_' . $partIndex;
}

function saveBranchSourcedMotorpoolParts(mysqli $conn, array $ris, array $assessment, int $branch_id, int $user_id, string $remarks, array $selectedKeys): array {
    $risId = (int)($ris['ris_id'] ?? 0);
    if ($risId <= 0) return ['total' => 0.0, 'count' => 0, 'details' => [], 'motorpool_total' => 0.0, 'assessment' => $assessment];

    $selectedLookup = [];
    foreach ($selectedKeys as $key) {
        $key = trim((string)$key);
        if ($key !== '') $selectedLookup[$key] = true;
    }
    if (empty($selectedLookup)) {
        throw new Exception('Please select at least one item that will be sourced by the branch.');
    }

    $conn->query("DELETE FROM motorpool_branch_parts_purchases WHERE ris_id = " . $risId);

    $total = 0.0;
    $motorpoolTotal = 0.0;
    $count = 0;
    $details = [];
    $stmt = $conn->prepare("INSERT INTO motorpool_branch_parts_purchases
        (ris_id, ris_number, branch_id, vehicle_db_id, vehicle_id, plate_no, repair_description, item_no, item_description, specification, quantity, unit_cost, total_cost, expense_memo, remarks, purchased_by, purchased_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) throw new Exception('Failed to prepare branch parts source save: ' . $conn->error);

    foreach ($assessment as $repairIndex => &$repair) {
        if (!is_array($repair)) continue;
        $repairText = motorpoolApprovalRepairText($repair);
        $parts = motorpoolApprovalParts($repair);
        foreach ($parts as $partIndex => &$part) {
            if (!is_array($part)) continue;
            $qty = motorpoolPartQtyForApproval($part);
            $unitCost = motorpoolPartUnitCostForApproval($part);
            $lineTotal = motorpoolPartTotalForApproval($part);
            if ($lineTotal <= 0 && $qty > 0 && $unitCost > 0) $lineTotal = $qty * $unitCost;
            if ($qty <= 0 && $lineTotal <= 0) continue;

            $selectionKey = motorpoolPartSelectionKey((int)$repairIndex, (int)$partIndex);
            $isSelectedForBranch = isset($selectedLookup[$selectionKey]);

            $itemNo = trim((string)($part['item_no'] ?? $part['itemNo'] ?? $part['item_number'] ?? $part['item'] ?? $part['part_no'] ?? ''));
            $desc = trim((string)($part['description'] ?? $part['name'] ?? $part['part'] ?? $part['part_name'] ?? $part['item_description'] ?? ''));
            $spec = trim((string)($part['specification'] ?? $part['specs'] ?? $part['spec'] ?? ''));

            if (!$isSelectedForBranch) {
                $part['source_by'] = 'motorpool';
                $part['purchased_by'] = 'motorpool';
                $part['branch_sourced'] = 0;
                $part['branch_purchased'] = 0;
                $part['branch_source_estimated_total'] = 0;
                $part['branch_expense_total'] = 0;
                $part['motorpool_billable_cost'] = $lineTotal;
                unset($part['branch_source_memo']);
                unset($part['branch_expense_memo']);
                $motorpoolTotal += $lineTotal;
                continue;
            }

            $memo = 'Motorpool parts sourced by Branch for RIS ' . (string)($ris['ris_number'] ?? '') . '. Item: ' . ($desc !== '' ? $desc : $itemNo) . '. Reason: ' . ($repairText !== '' ? $repairText : 'Motorpool repair');

            $risNumber = (string)($ris['ris_number'] ?? '');
            $vehicleDbId = (int)($ris['vehicle_db_id'] ?? 0);
            $vehicleId = (string)($ris['vehicle_id'] ?? '');
            $plateNo = (string)($ris['plate_no'] ?? '');
            $stmt->bind_param('isiissssssdddssi', $risId, $risNumber, $branch_id, $vehicleDbId, $vehicleId, $plateNo, $repairText, $itemNo, $desc, $spec, $qty, $unitCost, $lineTotal, $memo, $remarks, $user_id);
            if (!$stmt->execute()) throw new Exception('Failed to save branch parts source: ' . $stmt->error);
            $sourceRowId = (int)$stmt->insert_id;
            if ($sourceRowId > 0) {
                $markPending = $conn->prepare("UPDATE motorpool_branch_parts_purchases SET source_status = 'pending_source', estimated_unit_cost = ?, estimated_total_cost = ?, actual_unit_cost = NULL, actual_total_cost = NULL, expense_posted = 0 WHERE purchase_id = ?");
                if ($markPending) {
                    $markPending->bind_param('ddi', $unitCost, $lineTotal, $sourceRowId);
                    $markPending->execute();
                    $markPending->close();
                }
            }

            $part['source_status'] = 'pending_source';
            $part['actual_unit_cost'] = null;
            $part['actual_total_cost'] = null;
            $part['expense_posted'] = 0;
            $part['source_by'] = 'branch';
            $part['purchased_by'] = 'branch';
            $part['branch_sourced'] = 1;
            $part['branch_purchased'] = 0;
            $part['branch_source_estimated_total'] = $lineTotal;
            $part['branch_expense_total'] = 0;
            $part['motorpool_billable_cost'] = 0;
            $part['branch_source_memo'] = $memo;
            $part['branch_expense_memo'] = '';

            $total += $lineTotal;
            $count++;
            $details[] = ($desc !== '' ? $desc : ($itemNo !== '' ? $itemNo : 'Item')) . ' x ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' = PHP ' . number_format($lineTotal, 2);
        }
        unset($part);
        if (isset($repair['parts']) && is_array($repair['parts'])) $repair['parts'] = $parts;
        elseif (isset($repair['items']) && is_array($repair['items'])) $repair['items'] = $parts;
        elseif (isset($repair['parts_needed']) && is_array($repair['parts_needed'])) $repair['parts_needed'] = $parts;
        elseif (isset($repair['items_needed']) && is_array($repair['items_needed'])) $repair['items_needed'] = $parts;
    }
    unset($repair);
    $stmt->close();

    if ($count <= 0) {
        throw new Exception('The selected branch item/s were not valid. Please select at least one item with quantity or cost.');
    }

    return ['total' => $total, 'count' => $count, 'details' => $details, 'motorpool_total' => $motorpoolTotal, 'assessment' => $assessment];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'branch_review_motorpool_assessment') {
    $ris_id = (int)($_POST['approval_ris_id'] ?? 0);
    $decision = strtolower(trim($_POST['approval_decision'] ?? ''));
    $remarks = trim($_POST['approval_remarks'] ?? '');
    $parts_purchase_by = strtolower(trim((string)($_POST['parts_purchase_by'] ?? 'motorpool')));
    if (!in_array($parts_purchase_by, ['motorpool', 'branch'], true)) $parts_purchase_by = 'motorpool';
    $branch_purchase_parts = $_POST['branch_purchase_parts'] ?? [];
    if (!is_array($branch_purchase_parts)) $branch_purchase_parts = [];

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS assessment was not found.';
    } elseif (!in_array($decision, ['approved', 'rejected'], true)) {
        $save_status = 'error';
        $save_message = 'Invalid approval action.';
    } else {
        $whereBranch = '';
        if (!$view_all_branches && $branch_id > 0) {
            $whereBranch = ' AND branch_id = ' . intval($branch_id);
        }

        if ($decision === 'approved') {
            $lookupSql = "SELECT r.*, a.assessment_json
                          FROM motorpool_ris_requests r
                          LEFT JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id
                          WHERE r.ris_id = ?
                            AND COALESCE(r.workflow_status, r.status) = 'For Approval'" . ($whereBranch !== '' ? ' AND r.branch_id = ' . intval($branch_id) : '') . "
                          LIMIT 1";
            $lookup = $conn->prepare($lookupSql);
            if (!$lookup) {
                $save_status = 'error';
                $save_message = 'Failed to prepare approval lookup: ' . $conn->error;
            } else {
                $lookup->bind_param('i', $ris_id);
                $lookup->execute();
                $approvalRis = $lookup->get_result()->fetch_assoc();
                $lookup->close();

                if (!$approvalRis) {
                    $save_status = 'error';
                    $save_message = 'Unable to approve. The request may have already been updated.';
                } else {
                    $assessment = json_decode((string)($approvalRis['assessment_json'] ?? '[]'), true);
                    if (!is_array($assessment)) $assessment = [];
                    $branchPartsTotal = 0.0;
                    $motorpoolPartsTotal = 0.0;
                    $purchaseDetails = [];
                    $nextStatus = 'For Parts Completion';
                    $approvedAssessmentJson = (string)($approvalRis['assessment_json'] ?? '[]');

                    $conn->begin_transaction();
                    try {
                        if ($parts_purchase_by === 'branch') {
                            $purchaseResult = saveBranchSourcedMotorpoolParts($conn, $approvalRis, $assessment, (int)$branch_id, (int)$user_id, $remarks, $branch_purchase_parts);
                            $branchPartsTotal = (float)($purchaseResult['total'] ?? 0);
                            $motorpoolPartsTotal = (float)($purchaseResult['motorpool_total'] ?? 0);
                            $purchaseDetails = $purchaseResult['details'] ?? [];
                            $assessment = $purchaseResult['assessment'] ?? $assessment;
                            $approvedAssessmentJson = json_encode($assessment, JSON_UNESCAPED_UNICODE);
                            $nextStatus = 'For Parts Completion';

                            $updateAssessment = $conn->prepare("UPDATE motorpool_ris_assessments SET assessment_json = ?, parts_summary = CONCAT(COALESCE(parts_summary, ''), '\nBranch sourced selected parts estimated total: PHP ', ?) WHERE ris_id = ?");
                            if ($updateAssessment) {
                                $branchPartsTotalText = number_format($branchPartsTotal, 2, '.', '');
                                $updateAssessment->bind_param('ssi', $approvedAssessmentJson, $branchPartsTotalText, $ris_id);
                                $updateAssessment->execute();
                                $updateAssessment->close();
                            }
                        } else {
                            foreach ($assessment as $repair) {
                                if (!is_array($repair)) continue;
                                foreach (motorpoolApprovalParts($repair) as $part) {
                                    if (is_array($part)) $motorpoolPartsTotal += motorpoolPartTotalForApproval($part);
                                }
                            }
                        }

                        $sql = "UPDATE motorpool_ris_requests
                                SET workflow_status = ?,
                                    status = ?,
                                    branch_approval_status = 'Approved',
                                    branch_approval_by = ?,
                                    branch_approval_at = NOW(),
                                    branch_approval_remarks = ?,
                                    parts_purchase_by = ?,
                                    branch_parts_total = ?,
                                    motorpool_parts_total = ?,
                                    branch_parts_purchase_remarks = ?
                                WHERE ris_id = ?
                                  AND COALESCE(workflow_status, status) = 'For Approval'" . $whereBranch;
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) throw new Exception('Failed to prepare approval: ' . $conn->error);
                        $stmt->bind_param('ssissddsi', $nextStatus, $nextStatus, $user_id, $remarks, $parts_purchase_by, $branchPartsTotal, $motorpoolPartsTotal, $remarks, $ris_id);
                        if (!$stmt->execute() || $stmt->affected_rows <= 0) throw new Exception('Unable to approve. The request may have already been updated.');
                        $stmt->close();

                        $approvalDetails = 'Assessment approved by Branch Admin.' . ($remarks !== '' ? "\nRemarks: " . $remarks : '');
                        $approvalDetails .= "\nParts Source By: " . ($parts_purchase_by === 'branch' ? 'Branch Admin' : 'Motorpool');
                        if ($parts_purchase_by === 'branch') {
                            $approvalDetails .= "\nBranch Parts Estimated Source Total: PHP " . number_format($branchPartsTotal, 2);
                            if (!empty($purchaseDetails)) $approvalDetails .= "\nItems Sourced by Branch (still for Parts Completion, zero Motorpool cost):\n- " . implode("\n- ", $purchaseDetails);
                            logRisWorkflowHistory($conn, $ris_id, 'For Approval', $approvalDetails, '', (int)$user_id);
                            logRisWorkflowHistory($conn, $ris_id, 'For Parts Completion', 'Branch Admin approved the assessment and selected item/s to source. Selected parts were marked as pending branch source, not posted to expense yet. They will still appear in Parts Completion with zero Motorpool cost. Remaining Motorpool parts cost: PHP ' . number_format($motorpoolPartsTotal, 2) . ($remarks !== '' ? "\nRemarks: " . $remarks : ''), '', (int)$user_id);
                            $save_message = 'Assessment approved. Selected branch-sourced parts will still appear in Parts Completion with zero Motorpool cost. No expense was posted yet.';
                        } else {
                            logRisWorkflowHistory($conn, $ris_id, 'For Approval', $approvalDetails, '', (int)$user_id);
                            logRisWorkflowHistory($conn, $ris_id, 'For Parts Completion', 'Branch Admin approved the assessment. Motorpool may now complete the required parts.' . ($remarks !== '' ? "\nRemarks: " . $remarks : ''), '', (int)$user_id);
                            $save_message = 'Motorpool assessment approved. Request is now for parts completion.';
                        }

                        $conn->commit();
                        $save_status = 'success';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $save_status = 'error';
                        $save_message = $e->getMessage();
                    }
                }
            }
        } else {
            if ($remarks === '') {
                $save_status = 'error';
                $save_message = 'Please add remarks when returning the assessment.';
            } else {
                $sql = "UPDATE motorpool_ris_requests
                        SET workflow_status = 'For Assessment',
                            status = 'For Assessment',
                            branch_approval_status = 'Rejected',
                            branch_approval_by = ?,
                            branch_approval_at = NOW(),
                            branch_approval_remarks = ?
                        WHERE ris_id = ?
                          AND COALESCE(workflow_status, status) = 'For Approval'" . $whereBranch;
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('isi', $user_id, $remarks, $ris_id);
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $save_status = 'success';
                        logRisWorkflowHistory($conn, $ris_id, 'For Approval', 'Assessment returned by Branch Admin for revision.' . "\nRemarks: " . $remarks, '', (int)$user_id);
                        logRisWorkflowHistory($conn, $ris_id, 'For Assessment', 'Assessment was returned for revision by Branch Admin.' . "\nRemarks: " . $remarks, '', (int)$user_id);
                        $save_message = 'Assessment returned to Motorpool for revision.';
                    } else {
                        $save_status = 'error';
                        $save_message = 'Unable to return. The request may have already been updated.';
                    }
                    $stmt->close();
                } else {
                    $save_status = 'error';
                    $save_message = 'Failed to prepare return action: ' . $conn->error;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    if (!$vehicle_table_exists) {
        $save_status = 'error';
        $save_message = 'motorpool_vehicles table was not found. Please add the table first, then try again.';
    } else {
        $uploadDir = '../uploads/motorpool';
        $vehicleImage = uploadMotorpoolFile('vehicle_image', $uploadDir);
        $crImages = uploadMultipleMotorpoolFiles('cr_vehicle_images', $uploadDir);
        $orAttachment = uploadMotorpoolFile('or_attachment', $uploadDir);

        $data = [];
        foreach ($fieldMap as $formField => $choices) {
            $col = firstExisting($vehicle_columns, $choices);
            if (!$col) continue;
            if ($formField === 'vehicle_image') $data[$col] = $vehicleImage;
            elseif ($formField === 'cr_vehicle_images') $data[$col] = json_encode($crImages);
            elseif ($formField === 'or_attachment') $data[$col] = $orAttachment;
            elseif ($formField === 'branch_id') $data[$col] = (string)$branch_id;
            elseif ($formField === 'created_by') $data[$col] = (string)$user_id;
            elseif ($formField === 'created_at') $data[$col] = date('Y-m-d H:i:s');
            elseif ($formField === 'vehicle_id') $data[$col] = generateNextVehicleId($conn, $vehicle_table, $vehicle_columns);
            else $data[$col] = trim($_POST[$formField] ?? '');
        }

        if (empty($data)) {
            $save_status = 'error';
            $save_message = 'No matching columns were found in motorpool_vehicles.';
        } else {
            $cols = array_keys($data);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $types = str_repeat('s', count($cols));
            $sql = "INSERT INTO `$vehicle_table` (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $values = array_values($data);
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) {
                    $save_status = 'success';
                    $save_message = 'Vehicle saved successfully.';
                } else {
                    $save_status = 'error';
                    $save_message = 'Failed to save vehicle: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $save_status = 'error';
                $save_message = 'Failed to prepare save query: ' . $conn->error;
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_vehicle') {
    $vehicle_db_id = (int)($_POST['vehicle_db_id'] ?? 0);
    if (!$vehicle_table_exists || $vehicle_db_id <= 0) {
        $save_status = 'error';
        $save_message = 'Vehicle record was not found.';
    } else {
        $uploadDir = '../uploads/motorpool';
        $vehicleImage = uploadMotorpoolFile('vehicle_image', $uploadDir);
        $crImages = uploadMultipleMotorpoolFiles('cr_vehicle_images', $uploadDir);
        $orAttachment = uploadMotorpoolFile('or_attachment', $uploadDir);

        $data = [];
        foreach ($fieldMap as $formField => $choices) {
            if (in_array($formField, ['vehicle_id','branch_id','created_by','created_at'], true)) continue;
            $col = firstExisting($vehicle_columns, $choices);
            if (!$col) continue;
            if ($formField === 'vehicle_image') {
                if ($vehicleImage !== '') $data[$col] = $vehicleImage;
            } elseif ($formField === 'cr_vehicle_images') {
                if (!empty($crImages)) $data[$col] = json_encode($crImages);
            } elseif ($formField === 'or_attachment') {
                if ($orAttachment !== '') $data[$col] = $orAttachment;
            } else {
                $data[$col] = trim($_POST[$formField] ?? '');
            }
        }

        if (empty($data)) {
            $save_status = 'error';
            $save_message = 'No changes were found.';
        } else {
            $setParts = [];
            foreach (array_keys($data) as $col) $setParts[] = "`$col` = ?";
            $types = str_repeat('s', count($data)) . 'i';
            $sql = "UPDATE `$vehicle_table` SET " . implode(', ', $setParts) . " WHERE `id` = ?";
            if (!$view_all_branches && $branch_id > 0 && in_array('branch_id', $vehicle_columns, true)) {
                $sql .= " AND `branch_id` = " . intval($branch_id);
            }
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $values = array_values($data);
                $values[] = $vehicle_db_id;
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) {
                    $save_status = 'success';
                    $save_message = 'Vehicle updated successfully.';
                } else {
                    $save_status = 'error';
                    $save_message = 'Failed to update vehicle: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $save_status = 'error';
                $save_message = 'Failed to prepare update query: ' . $conn->error;
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fuel_monitoring') {
    $vehicle_db_id = (int)($_POST['vehicle_db_id'] ?? 0);
    $vehicle_id_value = trim($_POST['vehicle_id'] ?? '');
    $plate_no = trim($_POST['plate_no'] ?? '');
    $fuel_date = trim($_POST['fuel_date'] ?? date('Y-m-d'));
    $current_odometer = (float)($_POST['current_odometer'] ?? 0);
    $previous_odometer = (float)($_POST['previous_odometer'] ?? 0);
    $distance_covered = (float)($_POST['distance_covered'] ?? 0);
    $liters_consumed = (float)($_POST['liters_consumed'] ?? 0);
    $refuel_liters = (float)($_POST['refuel_liters'] ?? 0);
    $fuel_efficiency = (float)($_POST['fuel_efficiency'] ?? 0);
    $fuel_price = (float)($_POST['fuel_price'] ?? 0);

    if ($vehicle_db_id <= 0) jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    if ($fuel_date === '') jsonResponse(['success' => false, 'message' => 'Date is required.']);
    if ($current_odometer < 0 || $previous_odometer < 0 || $liters_consumed < 0 || $refuel_liters < 0 || $fuel_price < 0) jsonResponse(['success' => false, 'message' => 'Odometer, liters, and price values cannot be negative.']);
    if ($distance_covered <= 0) $distance_covered = max(0, $current_odometer - $previous_odometer);
    if ($fuel_efficiency <= 0 && $liters_consumed > 0) $fuel_efficiency = $distance_covered / $liters_consumed;
    if ($distance_covered <= 0) jsonResponse(['success' => false, 'message' => 'Distance covered must be greater than zero.']);
    if ($liters_consumed <= 0) jsonResponse(['success' => false, 'message' => 'Liters consumed must be greater than zero.']);
    if ($refuel_liters <= 0) jsonResponse(['success' => false, 'message' => 'Refuel liters must be greater than zero.']);
    if ($fuel_price <= 0) jsonResponse(['success' => false, 'message' => 'Price is required.']);

    $attachment = uploadMotorpoolFile('fuel_attachment', '../uploads/motorpool');
    if ($attachment === '') jsonResponse(['success' => false, 'message' => 'Attachment is required.']);

    $stmt = $conn->prepare("INSERT INTO motorpool_fuel_monitoring
        (vehicle_db_id, vehicle_id, plate_no, fuel_date, current_odometer, previous_odometer, distance_covered, liters_consumed, refuel_liters, fuel_efficiency, fuel_price, fuel_attachment, branch_id, encoded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Failed to prepare fuel monitoring save: ' . $conn->error]);
    $stmt->bind_param('isssdddddddsii', $vehicle_db_id, $vehicle_id_value, $plate_no, $fuel_date, $current_odometer, $previous_odometer, $distance_covered, $liters_consumed, $refuel_liters, $fuel_efficiency, $fuel_price, $attachment, $branch_id, $user_id);
    if ($stmt->execute()) {
        jsonResponse([
            'success' => true,
            'message' => 'Fuel monitoring record saved successfully.',
            'fuel_id' => $conn->insert_id,
            'vehicle_db_id' => $vehicle_db_id,
            'vehicle_id' => $vehicle_id_value,
            'plate_no' => $plate_no,
            'fuel_date' => $fuel_date,
            'current_odometer' => number_format($current_odometer, 2, '.', ''),
            'previous_odometer' => number_format($previous_odometer, 2, '.', ''),
            'distance_covered' => number_format($distance_covered, 2, '.', ''),
            'liters_consumed' => number_format($liters_consumed, 2, '.', ''),
            'refuel_liters' => number_format($refuel_liters, 2, '.', ''),
            'fuel_efficiency' => number_format($fuel_efficiency, 2, '.', ''),
            'fuel_price' => number_format($fuel_price, 2, '.', ''),
            'fuel_attachment' => $attachment,
            'driver_name' => '',
            'encoded_by_name' => $user_name,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    jsonResponse(['success' => false, 'message' => 'Failed to save fuel monitoring record: ' . $stmt->error]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'renew_registration') {
    $vehicle_db_id = (int)($_POST['vehicle_db_id'] ?? 0);
    $vehicle_id_value = trim($_POST['vehicle_id'] ?? '');
    $plate_no = trim($_POST['plate_no'] ?? '');
    $or_no_value = trim($_POST['or_no'] ?? '');
    $reg_date_value = trim($_POST['reg_date'] ?? '');
    $next_renewal_value = trim($_POST['next_renewal'] ?? '');
    $uploadDir = '../uploads/motorpool';
    $orAttachment = uploadMotorpoolFile('or_attachment', $uploadDir);

    if ($vehicle_db_id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    }
    if ($or_no_value === '' || $reg_date_value === '' || $next_renewal_value === '' || $orAttachment === '') {
        jsonResponse(['success' => false, 'message' => 'Please complete all registration renewal fields, including OR attachment.']);
    }

    $previousRegistration = [
        'or_no' => '',
        'reg_date' => '',
        'next_renewal' => '',
        'or_attachment' => ''
    ];

    if ($vehicle_table_exists && $vehicle_db_id > 0) {
        $orCol = firstExisting($vehicle_columns, ['or_no']);
        $regCol = firstExisting($vehicle_columns, ['reg_date','registration_history_date']);
        $renewCol = firstExisting($vehicle_columns, ['next_renewal']);
        $attachCol = firstExisting($vehicle_columns, ['or_attachment']);
        $selectParts = [];
        if ($orCol) $selectParts[] = "`$orCol` AS or_no";
        if ($regCol) $selectParts[] = "`$regCol` AS reg_date";
        if ($renewCol) $selectParts[] = "`$renewCol` AS next_renewal";
        if ($attachCol) $selectParts[] = "`$attachCol` AS or_attachment";

        if (!empty($selectParts)) {
            $sqlPrev = "SELECT " . implode(', ', $selectParts) . " FROM `$vehicle_table` WHERE `id` = ?";
            if (!$view_all_branches && $branch_id > 0 && in_array('branch_id', $vehicle_columns, true)) {
                $sqlPrev .= " AND `branch_id` = " . intval($branch_id);
            }
            $prevStmt = $conn->prepare($sqlPrev);
            if ($prevStmt) {
                $prevStmt->bind_param('i', $vehicle_db_id);
                $prevStmt->execute();
                $prevResult = $prevStmt->get_result();
                if ($prevResult && ($prevRow = $prevResult->fetch_assoc())) {
                    $previousRegistration['or_no'] = trim((string)($prevRow['or_no'] ?? ''));
                    $previousRegistration['reg_date'] = trim((string)($prevRow['reg_date'] ?? ''));
                    $previousRegistration['next_renewal'] = trim((string)($prevRow['next_renewal'] ?? ''));
                    $previousRegistration['or_attachment'] = trim((string)($prevRow['or_attachment'] ?? ''));
                }
                $prevStmt->close();
            }
        }
    }

    $hasPreviousRegistration = ($previousRegistration['or_no'] !== '' || $previousRegistration['reg_date'] !== '' || $previousRegistration['next_renewal'] !== '' || $previousRegistration['or_attachment'] !== '');
    $sameAsNew = (
        $previousRegistration['or_no'] === $or_no_value &&
        $previousRegistration['reg_date'] === $reg_date_value &&
        $previousRegistration['next_renewal'] === $next_renewal_value &&
        ($orAttachment === '' || $previousRegistration['or_attachment'] === $orAttachment)
    );

    if ($hasPreviousRegistration && !$sameAsNew) {
        $dupStmt = $conn->prepare("SELECT registration_id FROM motorpool_registration_history WHERE vehicle_db_id = ? AND COALESCE(or_no,'') = ? AND COALESCE(reg_date,'') = ? AND COALESCE(next_renewal,'') = ? AND COALESCE(or_attachment,'') = ? LIMIT 1");
        $alreadySaved = false;
        if ($dupStmt) {
            $dupStmt->bind_param('issss', $vehicle_db_id, $previousRegistration['or_no'], $previousRegistration['reg_date'], $previousRegistration['next_renewal'], $previousRegistration['or_attachment']);
            $dupStmt->execute();
            $dupResult = $dupStmt->get_result();
            $alreadySaved = $dupResult && $dupResult->num_rows > 0;
            $dupStmt->close();
        }

        if (!$alreadySaved) {
            $prevHistoryStmt = $conn->prepare("INSERT INTO motorpool_registration_history
                (vehicle_db_id, vehicle_id, plate_no, or_no, reg_date, next_renewal, or_attachment, branch_id, encoded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($prevHistoryStmt) {
                $prevHistoryStmt->bind_param(
                    'issssssii',
                    $vehicle_db_id,
                    $vehicle_id_value,
                    $plate_no,
                    $previousRegistration['or_no'],
                    $previousRegistration['reg_date'],
                    $previousRegistration['next_renewal'],
                    $previousRegistration['or_attachment'],
                    $branch_id,
                    $user_id
                );
                $prevHistoryStmt->execute();
                $prevHistoryStmt->close();
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO motorpool_registration_history
        (vehicle_db_id, vehicle_id, plate_no, or_no, reg_date, next_renewal, or_attachment, branch_id, encoded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Failed to prepare registration renewal: ' . $conn->error]);
    }
    $stmt->bind_param(
        'issssssii',
        $vehicle_db_id,
        $vehicle_id_value,
        $plate_no,
        $or_no_value,
        $reg_date_value,
        $next_renewal_value,
        $orAttachment,
        $branch_id,
        $user_id
    );

    if (!$stmt->execute()) {
        jsonResponse(['success' => false, 'message' => 'Failed to save registration renewal: ' . $stmt->error]);
    }
    $stmt->close();

    if ($vehicle_table_exists && $vehicle_db_id > 0) {
        $updateData = [];
        $orCol = firstExisting($vehicle_columns, ['or_no']);
        $regCol = firstExisting($vehicle_columns, ['reg_date','registration_history_date']);
        $renewCol = firstExisting($vehicle_columns, ['next_renewal']);
        $attachCol = firstExisting($vehicle_columns, ['or_attachment']);
        if ($orCol) $updateData[$orCol] = $or_no_value;
        if ($regCol) $updateData[$regCol] = $reg_date_value;
        if ($renewCol) $updateData[$renewCol] = $next_renewal_value;
        if ($attachCol && $orAttachment !== '') $updateData[$attachCol] = $orAttachment;

        if (!empty($updateData)) {
            $setParts = [];
            foreach (array_keys($updateData) as $col) $setParts[] = "`$col` = ?";
            $types = str_repeat('s', count($updateData)) . 'i';
            $sql = "UPDATE `$vehicle_table` SET " . implode(', ', $setParts) . " WHERE `id` = ?";
            if (!$view_all_branches && $branch_id > 0 && in_array('branch_id', $vehicle_columns, true)) {
                $sql .= " AND `branch_id` = " . intval($branch_id);
            }
            $updateStmt = $conn->prepare($sql);
            if ($updateStmt) {
                $values = array_values($updateData);
                $values[] = $vehicle_db_id;
                $updateStmt->bind_param($types, ...$values);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
    }

    jsonResponse([
        'success' => true,
        'message' => 'Registration renewal saved successfully.',
        'or_no' => $or_no_value,
        'reg_date' => $reg_date_value,
        'next_renewal' => $next_renewal_value,
        'or_attachment' => $orAttachment,
        'fuel_price' => isset($fuel_price) ? number_format((float)$fuel_price, 2, '.', '') : '0.00',
            'fuel_attachment' => isset($attachment) ? $attachment : '',
            'driver_name' => '',
            'encoded_by_name' => $user_name,
            'refuel_liters' => number_format($refuel_liters, 2, '.', ''),
            'created_at' => date('Y-m-d H:i:s')
    ]);
}

function fetchVehicles(mysqli $conn, string $table, bool $tableExists, array $columns, int $branch_id, bool $view_all_branches): array {
    if (!$tableExists) return [];
    $where = 'WHERE 1=1';
    if (!$view_all_branches && $branch_id > 0 && in_array('branch_id', $columns, true)) {
        $where .= ' AND branch_id = ' . intval($branch_id);
    }
    $orderCol = in_array('created_at', $columns, true) ? 'created_at' : (in_array('id', $columns, true) ? 'id' : $columns[0]);
    $sql = "SELECT * FROM `$table` $where ORDER BY `$orderCol` DESC";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}
function v(array $row, array $columns, array $choices): string {
    $col = firstExisting($columns, $choices);
    return $col && isset($row[$col]) ? (string)$row[$col] : '';
}

function motorpoolImageCell(string $filename, string $alt = 'Vehicle Image'): string {
    $filename = trim($filename);
    if ($filename === '') {
        return '<div class="item-thumbnail"><i class="bi bi-image text-muted"></i></div>';
    }
    $src = '../uploads/motorpool/' . h($filename);
    return '<div class="item-thumbnail"><img src="' . $src . '" alt="' . h($alt) . '" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<i class=&quot;bi bi-image text-muted&quot;></i>\';"></div>';
}

function motorpoolPartQtyValue(array $part): string {
    $qty = $part['used_quantity'] ?? ($part['qty_used'] ?? ($part['qty_to_use'] ?? ($part['quantity_to_use'] ?? ($part['quantity_used'] ?? ($part['quantity'] ?? ($part['qty'] ?? ''))))));
    return trim((string)$qty);
}

function motorpoolPartItemNo(array $part): string {
    return trim((string)($part['item_no'] ?? ($part['item_no_text'] ?? ($part['item'] ?? ($part['item_name'] ?? ($part['name'] ?? ($part['item_number'] ?? '')))))));
}

function motorpoolPartDescription(array $part): string {
    return trim((string)($part['description'] ?? ($part['part_description'] ?? ($part['item_description'] ?? ($part['desc'] ?? '')))));
}

function motorpoolPartSpecification(array $part): string {
    return trim((string)($part['specification'] ?? ($part['part_specification'] ?? ($part['item_specification'] ?? ($part['specs'] ?? ($part['spec'] ?? ''))))));
}

function motorpoolPartUnitCostValue(array $part): string {
    $value = $part['unit_cost'] ?? ($part['cost'] ?? ($part['unitCost'] ?? ''));
    return trim((string)$value);
}

function motorpoolPartEstimatedCostValue(array $part): string {
    $value = $part['estimated_total_cost'] ?? ($part['estimated_cost'] ?? ($part['total_cost'] ?? ($part['totalCost'] ?? '')));
    return trim((string)$value);
}

function motorpoolPartCostSourceValue(array $part): string {
    $value = $part['cost_source'] ?? ($part['costSource'] ?? ($part['source'] ?? ''));
    return trim((string)$value);
}

function motorpoolMoneyText($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (is_numeric($value)) return number_format((float)$value, 2, '.', '');
    return $value;
}


function motorpoolCostNumber($value): float {
    $raw = trim(str_replace(['₱', ','], '', (string)$value));
    return is_numeric($raw) ? (float)$raw : 0.0;
}

function motorpoolNormalizeMoney($value): string {
    return number_format(motorpoolCostNumber($value), 2, '.', '');
}

function motorpoolMiscDescriptionValue(array $row): string {
    foreach (['miscellaneous_description', 'misc_description', 'miscellaneous', 'misc_remarks', 'other_description', 'additional_description'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function motorpoolMiscCostValue(array $row): float {
    foreach (['miscellaneous_cost', 'misc_cost', 'miscellaneous_amount', 'other_cost', 'additional_cost'] as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') return motorpoolCostNumber($row[$key]);
    }
    return 0.0;
}

function motorpoolPartSourceLabelValue(array $part): string {
    $label = trim((string)($part['source_label'] ?? ''));
    if ($label !== '') return $label;
    $source = strtolower(trim((string)($part['source_by'] ?? $part['parts_source_by'] ?? $part['source_type'] ?? $part['source'] ?? $part['purchased_by'] ?? $part['cost_source'] ?? '')));
    if ($source === '') return '';
    if (strpos($source, 'branch') !== false) return 'Branch Source';
    if (strpos($source, 'motorpool') !== false) return 'Motorpool Source';
    return ucwords(str_replace('_', ' ', $source));
}

function motorpoolBuildCostsForRis(mysqli $conn, int $ris_id): array {
    $result = [
        'repair_cost' => 0.0,
        'item_cost' => 0.0,
        'motorpool_item_cost' => 0.0,
        'branch_item_cost' => 0.0,
        'misc_cost' => 0.0,
        'misc_items' => [],
        'parts' => [],
        'repairs' => []
    ];
    if ($ris_id <= 0) return $result;

    $assessmentRepairs = [];
    $assessmentParts = [];
    if (tableExists($conn, 'motorpool_ris_assessments')) {
        $stmt = $conn->prepare("SELECT assessment_json FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $assessment = json_decode((string)($row['assessment_json'] ?? '[]'), true);
            if (is_array($assessment)) {
                foreach ($assessment as $repair) {
                    if (!is_array($repair)) continue;
                    $repairName = trim((string)($repair['repair'] ?? $repair['repair_description'] ?? ''));
                    $repairCost = motorpoolCostNumber($repair['repair_cost'] ?? $repair['labor_cost'] ?? $repair['service_cost'] ?? 0);
                    if ($repairName !== '') $assessmentRepairs[strtolower($repairName)] = $repairCost;
                    $parts = $repair['parts'] ?? [];
                    if (is_array($parts)) {
                        foreach ($parts as $part) {
                            if (!is_array($part)) continue;
                            $item = motorpoolPartItemNo($part);
                            $desc = motorpoolPartDescription($part);
                            $key = strtolower($item !== '' ? $item : $desc);
                            if ($key === '') continue;
                            $qty = motorpoolCostNumber($part['quantity'] ?? $part['qty'] ?? $part['needed_quantity'] ?? 0);
                            $unit = motorpoolCostNumber($part['unit_cost'] ?? $part['cost'] ?? 0);
                            $total = motorpoolCostNumber($part['estimated_total_cost'] ?? $part['estimated_cost'] ?? $part['total_cost'] ?? ($qty * $unit));
                            $assessmentParts[$key] = [
                                'item_no' => $item,
                                'description' => $desc,
                                'specification' => motorpoolPartSpecification($part),
                                'quantity' => $qty,
                                'unit_cost' => $unit,
                                'total_cost' => $total,
                                'source' => motorpoolPartSourceLabelValue($part)
                            ];
                        }
                    }
                }
            }
        }
    }

    $progressRows = [];
    if (tableExists($conn, 'motorpool_ris_repair_progress')) {
        $stmt = $conn->prepare("SELECT repair_progress_json FROM motorpool_ris_repair_progress WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $decoded = json_decode((string)($row['repair_progress_json'] ?? '[]'), true);
            if (is_array($decoded)) $progressRows = $decoded;
        }
    }

    $seenRepair = [];
    foreach ($progressRows as $repair) {
        if (!is_array($repair)) continue;
        $repairName = trim((string)($repair['repair'] ?? $repair['repair_description'] ?? ''));
        $repairKey = strtolower($repairName);
        $repairCost = motorpoolCostNumber($repair['repair_cost'] ?? $repair['labor_cost'] ?? $repair['service_cost'] ?? 0);
        if ($repairCost <= 0 && $repairKey !== '' && isset($assessmentRepairs[$repairKey])) $repairCost = (float)$assessmentRepairs[$repairKey];
        if ($repairKey !== '' && !isset($seenRepair[$repairKey])) {
            $result['repair_cost'] += $repairCost;
            $result['repairs'][] = ['repair' => $repairName, 'cost' => $repairCost];
            $seenRepair[$repairKey] = true;
        }

        $miscDesc = motorpoolMiscDescriptionValue($repair);
        $miscCost = motorpoolMiscCostValue($repair);
        if ($miscDesc !== '' || $miscCost > 0) {
            $miscKey = strtolower($repairName . '|' . $miscDesc . '|' . $miscCost);
            if (!isset($seenRepair['misc:' . $miscKey])) {
                $result['misc_cost'] += $miscCost;
                $result['misc_items'][] = ['repair' => $repairName, 'description' => $miscDesc, 'cost' => $miscCost];
                $seenRepair['misc:' . $miscKey] = true;
            }
        }
    }

    $partsSource = [];
    if (tableExists($conn, 'motorpool_repair_start_logs')) {
        $stmt = $conn->prepare("SELECT parts_used_json FROM motorpool_repair_start_logs WHERE ris_id = ? ORDER BY log_id ASC");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $parts = json_decode((string)($row['parts_used_json'] ?? '[]'), true);
                if (is_array($parts)) {
                    foreach ($parts as $part) if (is_array($part)) $partsSource[] = $part;
                }
            }
            $stmt->close();
        }
    }
    if (empty($partsSource)) {
        foreach ($progressRows as $repair) {
            if (!is_array($repair)) continue;
            $parts = $repair['parts_used'] ?? [];
            if (is_array($parts)) foreach ($parts as $part) if (is_array($part)) $partsSource[] = $part;
        }
    }

    $partMap = [];
    foreach ($partsSource as $part) {
        $item = motorpoolPartItemNo($part);
        $desc = motorpoolPartDescription($part);
        $spec = motorpoolPartSpecification($part);
        $key = strtolower($item !== '' ? $item : ($desc !== '' ? $desc : md5(json_encode($part))));
        $qty = motorpoolCostNumber(motorpoolPartQtyValue($part));
        $unit = motorpoolCostNumber($part['unit_cost'] ?? $part['cost'] ?? 0);
        $total = motorpoolCostNumber($part['estimated_total_cost'] ?? $part['estimated_cost'] ?? $part['total_cost'] ?? 0);
        $source = motorpoolPartSourceLabelValue($part);

        if (isset($assessmentParts[$key])) {
            $assessed = $assessmentParts[$key];
            if ($unit <= 0) $unit = (float)$assessed['unit_cost'];
            if ($total <= 0 && $qty > 0 && $unit > 0) $total = $qty * $unit;
            if ($total <= 0) $total = (float)$assessed['total_cost'];
            if ($desc === '') $desc = (string)$assessed['description'];
            if ($spec === '') $spec = (string)$assessed['specification'];
            if ($source === '') $source = (string)$assessed['source'];
        } elseif ($total <= 0 && $qty > 0 && $unit > 0) {
            $total = $qty * $unit;
        }

        if (!isset($partMap[$key])) {
            $partMap[$key] = [
                'item_no' => $item,
                'description' => $desc,
                'specification' => $spec,
                'quantity' => $qty,
                'unit_cost' => $unit,
                'total_cost' => $total,
                'source' => $source
            ];
        } else {
            if ($qty > 0) $partMap[$key]['quantity'] = $qty;
            if ($unit > 0) $partMap[$key]['unit_cost'] = $unit;
            if ($total > 0) $partMap[$key]['total_cost'] = $total;
            if ($source !== '') $partMap[$key]['source'] = $source;
        }
    }

    if (tableExists($conn, 'motorpool_branch_parts_purchases')) {
        $stmt = $conn->prepare("SELECT purchase_id, repair_description, item_no, item_description, specification, quantity, unit_cost, total_cost, estimated_unit_cost, estimated_total_cost, actual_unit_cost, actual_total_cost, supplier_name, source_status FROM motorpool_branch_parts_purchases WHERE ris_id = ? ORDER BY purchase_id ASC");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($bp = $res->fetch_assoc()) {
                $item = trim((string)($bp['item_no'] ?? ''));
                $desc = trim((string)($bp['item_description'] ?? ''));
                $spec = trim((string)($bp['specification'] ?? ''));
                $key = strtolower($item !== '' ? $item : ($desc !== '' ? $desc : ('branch_purchase_' . (int)($bp['purchase_id'] ?? 0))));
                $qty = motorpoolCostNumber($bp['quantity'] ?? 0);
                $actualUnit = motorpoolCostNumber($bp['actual_unit_cost'] ?? 0);
                $actualTotal = motorpoolCostNumber($bp['actual_total_cost'] ?? 0);
                $estimatedUnit = motorpoolCostNumber($bp['estimated_unit_cost'] ?? ($bp['unit_cost'] ?? 0));
                $estimatedTotal = motorpoolCostNumber($bp['estimated_total_cost'] ?? ($bp['total_cost'] ?? 0));
                $unit = $actualUnit > 0 ? $actualUnit : $estimatedUnit;
                $total = $actualTotal > 0 ? $actualTotal : ($estimatedTotal > 0 ? $estimatedTotal : ($qty * $unit));
                $partMap[$key] = [
                    'purchase_id' => (int)($bp['purchase_id'] ?? 0),
                    'item_no' => $item,
                    'description' => $desc,
                    'specification' => $spec,
                    'quantity' => $qty,
                    'unit_cost' => $unit,
                    'estimated_unit_cost' => $estimatedUnit,
                    'estimated_total_cost' => $estimatedTotal,
                    'actual_unit_cost' => $actualUnit,
                    'actual_total_cost' => $actualTotal,
                    'total_cost' => $total,
                    'source' => 'Branch Source',
                    'source_by' => 'branch',
                    'supplier_name' => trim((string)($bp['supplier_name'] ?? '')),
                    'source_status' => trim((string)($bp['source_status'] ?? 'pending_source'))
                ];
            }
            $stmt->close();
        }
    }

    foreach ($partMap as $part) {
        $partTotal = (float)($part['total_cost'] ?? 0);
        $sourceText = strtolower((string)($part['source'] ?? $part['source_by'] ?? ''));
        if (strpos($sourceText, 'branch') !== false) $result['branch_item_cost'] += $partTotal;
        else $result['motorpool_item_cost'] += $partTotal;
        $result['item_cost'] += $partTotal;
        $result['parts'][] = $part;
    }

    return $result;
}

function motorpoolBuildCostSummaryTextForRis(mysqli $conn, int $ris_id): string {
    $costs = motorpoolBuildCostsForRis($conn, $ris_id);
    $grand = $costs['repair_cost'] + $costs['item_cost'] + $costs['misc_cost'];
    if ($grand <= 0 && empty($costs['misc_items']) && empty($costs['parts']) && empty($costs['repairs'])) return '';

    $lines = [];
    $lines[] = 'Cost Summary:';
    $lines[] = 'Repair Cost: ' . motorpoolMoneyText($costs['repair_cost']);
    $lines[] = 'Item Cost: ' . motorpoolMoneyText($costs['item_cost']);
    $lines[] = 'Miscellaneous Cost: ' . motorpoolMoneyText($costs['misc_cost']);
    if (!empty($costs['misc_items'])) {
        $misc = [];
        foreach ($costs['misc_items'] as $item) {
            $desc = trim((string)($item['description'] ?? ''));
            $repair = trim((string)($item['repair'] ?? ''));
            $label = $desc !== '' ? $desc : 'Miscellaneous';
            if ($repair !== '') $label .= ' (' . $repair . ')';
            $misc[] = $label . ' - ' . motorpoolMoneyText($item['cost'] ?? 0);
        }
        $lines[] = 'Miscellaneous Details: ' . implode('; ', $misc);
    }
    $lines[] = 'Grand Total: ' . motorpoolMoneyText($grand);
    return implode("\n", $lines);
}

function motorpoolGrandTotalCostForRis(mysqli $conn, int $ris_id, $fallback = 0): float {
    $costs = motorpoolBuildCostsForRis($conn, $ris_id);
    $grand = (float)$costs['repair_cost'] + (float)$costs['item_cost'] + (float)$costs['misc_cost'];
    return $grand > 0 ? $grand : motorpoolCostNumber($fallback);
}

function motorpoolAppendCostSummaryForRis(mysqli $conn, int $ris_id, string $details): string {
    $details = rtrim($details);
    if ($ris_id <= 0 || stripos($details, 'Cost Summary:') !== false) return $details;
    $summary = motorpoolBuildCostSummaryTextForRis($conn, $ris_id);
    if ($summary === '') return $details;
    return $details . ($details !== '' ? "\n\n" : '') . $summary;
}

function motorpoolAssessmentPartsMap(mysqli $conn, int $ris_id): array {
    $map = [];
    if ($ris_id <= 0 || !tableExists($conn, 'motorpool_ris_assessments')) return $map;
    $stmt = $conn->prepare("SELECT assessment_json, parts_summary FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
    if (!$stmt) return $map;
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $map;

    $add = function(string $itemNo, string $description, string $specification, string $quantity = '', string $unitCost = '', string $estimatedCost = '', string $costSource = '', string $repairCost = '') use (&$map): void {
        $itemNo = trim($itemNo);
        $description = trim($description);
        $specification = trim($specification);
        $quantity = trim($quantity);
        $unitCost = motorpoolMoneyText($unitCost);
        $estimatedCost = motorpoolMoneyText($estimatedCost);
        $costSource = trim($costSource);
        $repairCost = motorpoolMoneyText($repairCost);
        if ($itemNo === '' && $description === '') return;

        $keys = [];
        if ($itemNo !== '') $keys[] = strtolower($itemNo);
        if ($description !== '') $keys[] = strtolower($description);
        $keys = array_values(array_unique($keys));

        foreach ($keys as $key) {
            if (!isset($map[$key])) {
                $map[$key] = [
                    'item_no' => $itemNo !== '' ? $itemNo : $description,
                    'description' => '',
                    'specification' => '',
                    'quantity' => '',
                    'unit_cost' => '',
                    'estimated_cost' => '',
                    'cost_source' => '',
                    'repair_cost' => ''
                ];
            }
            if ($map[$key]['item_no'] === '' && $itemNo !== '') $map[$key]['item_no'] = $itemNo;
            if ($map[$key]['description'] === '' && $description !== '') $map[$key]['description'] = $description;
            if ($map[$key]['specification'] === '' && $specification !== '') $map[$key]['specification'] = $specification;
            if ($map[$key]['quantity'] === '' && $quantity !== '') $map[$key]['quantity'] = $quantity;
            if ($map[$key]['unit_cost'] === '' && $unitCost !== '') $map[$key]['unit_cost'] = $unitCost;
            if ($map[$key]['estimated_cost'] === '' && $estimatedCost !== '') $map[$key]['estimated_cost'] = $estimatedCost;
            if ($map[$key]['cost_source'] === '' && $costSource !== '') $map[$key]['cost_source'] = $costSource;
    if (($map[$key]['repair_cost'] ?? '') === '' && $repairCost !== '') $map[$key]['repair_cost'] = motorpoolMoneyText($repairCost);
            if (($map[$key]['repair_cost'] ?? '') === '' && $repairCost !== '') $map[$key]['repair_cost'] = $repairCost;
        }
    };

    $assessment = json_decode((string)($row['assessment_json'] ?? '[]'), true);
    if (is_array($assessment)) {
        foreach ($assessment as $repair) {
            if (!is_array($repair)) continue;
            $parts = $repair['parts'] ?? [];
            if (!is_array($parts)) continue;
            $repairCostValueForParts = (string)($repair['repair_cost'] ?? ($repair['labor_cost'] ?? ($repair['service_cost'] ?? '')));
            foreach ($parts as $part) {
                if (!is_array($part)) continue;
                $quantityValue = (string)($part['quantity'] ?? ($part['qty'] ?? ''));
                $unitCostValue = motorpoolPartUnitCostValue($part);
                $estimatedCostValue = motorpoolPartEstimatedCostValue($part);
                if ($estimatedCostValue === '' && is_numeric($quantityValue) && is_numeric($unitCostValue)) {
                    $estimatedCostValue = (string)((float)$quantityValue * (float)$unitCostValue);
                }
                $add(
                    (string)($part['item_no'] ?? ($part['item_code'] ?? '')),
                    (string)($part['description'] ?? ($part['part_description'] ?? ($part['item_description'] ?? ($part['name'] ?? ($part['item_name'] ?? ''))))),
                    (string)($part['specification'] ?? ($part['part_specification'] ?? ($part['item_specification'] ?? ($part['specs'] ?? ($part['spec'] ?? ($part['unit_type'] ?? '')))))),
                    $quantityValue,
                    $unitCostValue,
                    $estimatedCostValue,
                    motorpoolPartCostSourceValue($part),
                    $repairCostValueForParts
                );
            }
        }
    }

    foreach (preg_split('/\R+/', (string)($row['parts_summary'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Assessed By:') === 0) continue;
        $itemNo = $description = $specification = $quantity = $unitCost = $estimatedCost = $costSource = '';
        foreach (explode('|', $line) as $seg) {
            $pieces = explode(':', $seg, 2);
            if (count($pieces) < 2) continue;
            $key = strtolower(trim($pieces[0]));
            $val = trim($pieces[1]);
            if (in_array($key, ['item no.', 'item no', 'item', 'item number'], true)) $itemNo = $val;
            elseif ($key === 'description') $description = $val;
            elseif (in_array($key, ['specification', 'specs'], true)) $specification = $val;
            elseif (in_array($key, ['quantity', 'qty', 'needed qty', 'needed quantity'], true)) $quantity = $val;
            elseif (in_array($key, ['unit cost', 'unit_cost', 'cost'], true)) $unitCost = $val;
            elseif (in_array($key, ['estimated cost', 'estimated_cost', 'estimated total cost', 'estimated_total_cost', 'total cost', 'total_cost'], true)) $estimatedCost = $val;
            elseif (in_array($key, ['cost source', 'cost_source', 'source'], true)) $costSource = $val;
        }
        $add($itemNo, $description, $specification, $quantity, $unitCost, $estimatedCost, $costSource);
    }

    return $map;
}

function motorpoolAddUsedPartToMap(array &$map, array $part, array $assessmentMap): void {
    $itemNo = motorpoolPartItemNo($part);
    $qty = motorpoolPartQtyValue($part);
    $description = motorpoolPartDescription($part);
    $specification = motorpoolPartSpecification($part);
    if ($itemNo === '' && $qty === '' && $description === '' && $specification === '') return;

    $key = $itemNo !== '' ? strtolower($itemNo) : md5($description . '|' . $specification);
    if (!isset($map[$key])) {
        $map[$key] = [
            'item_no' => $itemNo,
            'quantity' => 0,
            'quantity_text' => '',
            'description' => '',
            'specification' => '',
            'unit_cost' => '',
            'estimated_cost' => '',
            'cost_source' => '',
            'repair_cost' => ''
        ];
    }
    if ($map[$key]['item_no'] === '' && $itemNo !== '') $map[$key]['item_no'] = $itemNo;

    $assessment = ($itemNo !== '' && isset($assessmentMap[strtolower($itemNo)])) ? $assessmentMap[strtolower($itemNo)] : [];
    if (empty($assessment) && $description !== '' && isset($assessmentMap[strtolower($description)])) $assessment = $assessmentMap[strtolower($description)];
    if (empty($assessment) && $itemNo !== '') {
        foreach ($assessmentMap as $candidate) {
            if (strtolower((string)($candidate['description'] ?? '')) === strtolower($itemNo)) { $assessment = $candidate; break; }
        }
    }
    if ($description === '' && !empty($assessment['description'])) $description = (string)$assessment['description'];
    if ($specification === '' && !empty($assessment['specification'])) $specification = (string)$assessment['specification'];

    $unitCost = motorpoolPartUnitCostValue($part);
    $estimatedCost = motorpoolPartEstimatedCostValue($part);
    $costSource = motorpoolPartCostSourceValue($part);

    if ($unitCost === '' && !empty($assessment['unit_cost'])) $unitCost = (string)$assessment['unit_cost'];
    if ($estimatedCost === '' && !empty($assessment['estimated_cost'])) $estimatedCost = (string)$assessment['estimated_cost'];
    if ($costSource === '' && !empty($assessment['cost_source'])) $costSource = (string)$assessment['cost_source'];
    $repairCost = !empty($part['repair_cost']) ? (string)$part['repair_cost'] : (!empty($assessment['repair_cost']) ? (string)$assessment['repair_cost'] : '');

    if ($estimatedCost === '' && is_numeric($qty) && is_numeric($unitCost)) {
        $estimatedCost = (string)((float)$qty * (float)$unitCost);
    }

    if ($map[$key]['description'] === '' && $description !== '') $map[$key]['description'] = $description;
    if ($map[$key]['specification'] === '' && $specification !== '') $map[$key]['specification'] = $specification;
    if ($map[$key]['unit_cost'] === '' && $unitCost !== '') $map[$key]['unit_cost'] = motorpoolMoneyText($unitCost);
    if ($map[$key]['estimated_cost'] === '' && $estimatedCost !== '') $map[$key]['estimated_cost'] = motorpoolMoneyText($estimatedCost);
    if ($map[$key]['cost_source'] === '' && $costSource !== '') $map[$key]['cost_source'] = $costSource;
    if (($map[$key]['repair_cost'] ?? '') === '' && $repairCost !== '') $map[$key]['repair_cost'] = motorpoolMoneyText($repairCost);

    if ($qty !== '') {
        if (is_numeric($qty)) {
            // Keep the latest actual used quantity for this RIS item instead of summing
            // repeated workflow copies of the same part.
            $map[$key]['quantity'] = (float)$qty;
            $map[$key]['quantity_text'] = '';
        } else {
            $map[$key]['quantity_text'] = $qty;
        }
    }
}

function motorpoolRowsToPartsSummary(array $rows): string {
    $lines = [];
    foreach ($rows as $row) {
        $qty = '';
        if (isset($row['quantity']) && (float)$row['quantity'] > 0) {
            $qty = rtrim(rtrim(number_format((float)$row['quantity'], 2, '.', ''), '0'), '.');
        } elseif (!empty($row['quantity_text'])) {
            $qty = (string)$row['quantity_text'];
        }
        $line = 'Quantity: ' . ($qty !== '' ? $qty : '0')
            . ' | Item: ' . (trim((string)($row['item_no'] ?? '')) !== '' ? trim((string)$row['item_no']) : 'N/A')
            . ' | Description: ' . (trim((string)($row['description'] ?? '')) !== '' ? trim((string)$row['description']) : 'N/A')
            . ' | Specification: ' . (trim((string)($row['specification'] ?? '')) !== '' ? trim((string)$row['specification']) : 'N/A');

        if (trim((string)($row['estimated_cost'] ?? '')) !== '') {
            $line .= ' | Estimated Cost: ' . motorpoolMoneyText($row['estimated_cost']);
        }
        if (trim((string)($row['repair_cost'] ?? '')) !== '') {
            $line .= ' | Repair Cost: ' . motorpoolMoneyText($row['repair_cost']);
        }

        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function motorpoolParseExistingPartsText(string $text, array $assessmentMap): string {
    $map = [];
    foreach (preg_split('/\R+/', $text) as $line) {
        $line = trim(preg_replace('/^(Parts Replaced:|Part\s*\d+:|Item\s*\d+:)/i', '', (string)$line));
        if ($line === '') continue;
        $part = ['item_no' => '', 'used_quantity' => '', 'description' => '', 'specification' => '', 'unit_cost' => '', 'estimated_cost' => '', 'cost_source' => ''];
        foreach (explode('|', $line) as $seg) {
            $pieces = explode(':', $seg, 2);
            if (count($pieces) < 2) continue;
            $key = strtolower(trim($pieces[0]));
            $val = trim($pieces[1]);
            if (in_array($key, ['item', 'item no.', 'item no', 'item number'], true)) $part['item_no'] = $val;
            elseif (in_array($key, ['quantity', 'qty', 'qty used', 'used qty', 'quantity used', 'qty to use'], true)) $part['used_quantity'] = $val;
            elseif ($key === 'description') $part['description'] = $val;
            elseif (in_array($key, ['specification', 'specs'], true)) $part['specification'] = $val;
            elseif (in_array($key, ['unit cost', 'unit_cost', 'cost'], true)) $part['unit_cost'] = $val;
            elseif (in_array($key, ['estimated cost', 'estimated_cost', 'estimated total cost', 'estimated_total_cost', 'total cost', 'total_cost'], true)) $part['estimated_cost'] = $val;
            elseif (in_array($key, ['cost source', 'cost_source', 'source'], true)) $part['cost_source'] = $val;
        }
        motorpoolAddUsedPartToMap($map, $part, $assessmentMap);
    }
    return motorpoolRowsToPartsSummary(array_values($map));
}

function motorpoolBuildPartsReplacedSummaryForRis(mysqli $conn, int $ris_id, string $fallback = ''): string {
    if ($ris_id <= 0) return trim($fallback);
    $assessmentMap = motorpoolAssessmentPartsMap($conn, $ris_id);

    /*
     * Use ONE source of truth for actual parts used.
     *
     * The same parts are commonly saved in both:
     *   1) motorpool_repair_start_logs.parts_used_json
     *   2) motorpool_ris_repair_progress.repair_progress_json
     *
     * Previous versions added both sources together, which doubled the quantity
     * in Repair History / Quality Check / For Release. Example: actual Qty Used = 2,
     * but the table showed 4 because it summed start_logs + repair_progress.
     *
     * Priority:
     *   - start logs first, because they are per-repair logs and are the cleanest record
     *   - repair progress only as fallback when start logs are empty
     *   - existing stored text only as last fallback
     */
    $mapFromLogs = [];
    if (tableExists($conn, 'motorpool_repair_start_logs')) {
        $stmt = $conn->prepare("SELECT parts_used_json FROM motorpool_repair_start_logs WHERE ris_id = ? ORDER BY log_id ASC");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $parts = json_decode((string)($row['parts_used_json'] ?? '[]'), true);
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        if (is_array($part)) motorpoolAddUsedPartToMap($mapFromLogs, $part, $assessmentMap);
                    }
                }
            }
            $stmt->close();
        }
    }
    $summaryFromLogs = motorpoolRowsToPartsSummary(array_values($mapFromLogs));
    if ($summaryFromLogs !== '') return $summaryFromLogs;

    $mapFromProgress = [];
    if (tableExists($conn, 'motorpool_ris_repair_progress')) {
        $stmt = $conn->prepare("SELECT repair_progress_json FROM motorpool_ris_repair_progress WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $progress = json_decode((string)($row['repair_progress_json'] ?? '[]'), true);
            if (is_array($progress)) {
                foreach ($progress as $repair) {
                    if (!is_array($repair)) continue;
                    $parts = $repair['parts_used'] ?? [];
                    if (is_array($parts)) {
                        foreach ($parts as $part) {
                            if (is_array($part)) motorpoolAddUsedPartToMap($mapFromProgress, $part, $assessmentMap);
                        }
                    }
                }
            }
        }
    }
    $summaryFromProgress = motorpoolRowsToPartsSummary(array_values($mapFromProgress));
    if ($summaryFromProgress !== '') return $summaryFromProgress;

    $parsedFallback = motorpoolParseExistingPartsText($fallback, $assessmentMap);
    return $parsedFallback !== '' ? $parsedFallback : trim($fallback);
}

function motorpoolBuildPartsUsedSummaryFromJsonForRis(mysqli $conn, int $ris_id, string $partsJson): string {
    $assessmentMap = motorpoolAssessmentPartsMap($conn, $ris_id);
    $map = [];
    $parts = json_decode($partsJson, true);
    if (is_array($parts)) foreach ($parts as $part) if (is_array($part)) motorpoolAddUsedPartToMap($map, $part, $assessmentMap);
    $summary = motorpoolRowsToPartsSummary(array_values($map));
    return $summary !== '' ? $summary : 'No parts used.';
}


function motorpoolBuildRepairsDoneFromAssessment(mysqli $conn, int $ris_id, string $fallback = ''): string {
    $names = [];
    if ($ris_id > 0 && tableExists($conn, 'motorpool_ris_assessments')) {
        $stmt = $conn->prepare("SELECT assessment_json, repairs_summary FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $assessment = json_decode((string)($row['assessment_json'] ?? '[]'), true);
                if (is_array($assessment)) {
                    foreach ($assessment as $repair) {
                        if (!is_array($repair)) continue;
                        $name = trim((string)($repair['repair'] ?? ($repair['repair_description'] ?? ($repair['repairs_done'] ?? ''))));
                        if ($name !== '') $names[$name] = $name;
                    }
                }
                if (empty($names)) {
                    foreach (preg_split('/\R+/', (string)($row['repairs_summary'] ?? '')) as $line) {
                        $line = trim($line);
                        if ($line === '' || stripos($line, 'Assessed By:') === 0) continue;
                        $name = $line;
                        foreach (explode('|', $line) as $seg) {
                            $pieces = explode(':', $seg, 2);
                            if (count($pieces) < 2) continue;
                            $key = strtolower(trim($pieces[0]));
                            $val = trim($pieces[1]);
                            if (in_array($key, ['repair', 'repair to make', 'repairs done'], true)) { $name = $val; break; }
                        }
                        $name = preg_replace('/\s*\|\s*(repair cost|labor cost|service cost)\s*:\s*[^|]+/i', '', $name);
                        $name = trim($name);
                        if ($name !== '') $names[$name] = $name;
                    }
                }
            }
        }
    }
    if (!empty($names)) return implode("\n", array_values($names));
    $clean = [];
    foreach (preg_split('/\R+/', (string)$fallback) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $line = preg_replace('/\s*\|\s*(repair cost|labor cost|service cost)\s*:\s*[^|]+/i', '', $line);
        $line = preg_replace('/^(Repair|Repair To Make|Repairs Done)\s*:\s*/i', '', $line);
        $line = trim($line);
        if ($line !== '') $clean[$line] = $line;
    }
    return implode("\n", array_values($clean));
}

function motorpoolFallbackRepairHistoryRowsFromRis(mysqli $conn, array $vehicleIds): array {
    $rowsByVehicle = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
    if (empty($ids) || !tableExists($conn, 'motorpool_ris_requests')) return $rowsByVehicle;
    $idList = implode(',', $ids);
    $sql = "SELECT r.ris_id, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no,
                   COALESCE(r.completed_at, r.updated_at, r.created_at) AS repair_date_time,
                   r.repairs_done, r.parts_replaced, r.mechanic,
                   r.repair_start_date, r.repair_end_date, r.ris_attachment, r.repair_cost, r.completed_at, r.created_at
            FROM motorpool_ris_requests r
            WHERE r.vehicle_db_id IN ($idList)
              AND (
                    r.completed_at IS NOT NULL
                    OR LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) IN ('for release', 'completed', 'released')
                    OR LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) LIKE '%release%'
                  )
            ORDER BY COALESCE(r.completed_at, r.updated_at, r.created_at) DESC, r.ris_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $risId = (int)($r['ris_id'] ?? 0);
            $vehicleId = (int)($r['vehicle_db_id'] ?? 0);
            if ($vehicleId <= 0 || $risId <= 0) continue;
            $parts = motorpoolBuildPartsReplacedSummaryForRis($conn, $risId, (string)($r['parts_replaced'] ?? ''));
            $rowsByVehicle[$vehicleId][] = [
                'repair_id' => 0,
                'vehicle_db_id' => $vehicleId,
                'ris_id' => $risId,
                'ris_number' => (string)($r['ris_number'] ?? ''),
                'repair_date' => substr((string)($r['repair_date_time'] ?? ''), 0, 10),
                'repairs_done' => motorpoolBuildRepairsDoneFromAssessment($conn, $risId, (string)($r['repairs_done'] ?? '')),
                'parts_replaced' => $parts,
                'mechanic' => (string)($r['mechanic'] ?? ''),
                'start_date' => (string)($r['repair_start_date'] ?? ''),
                'end_date' => (string)($r['repair_end_date'] ?? ''),
                'attachment' => (string)($r['ris_attachment'] ?? ''),
                'repair_cost' => (string)($r['repair_cost'] ?? '0.00'),
                'grand_total_cost' => motorpoolGrandTotalCostForRis($conn, $risId, (string)($r['repair_cost'] ?? '0.00')),
                'created_at' => (string)($r['completed_at'] ?? ($r['created_at'] ?? '')),
                'checked_received_by' => '',
                'received_datetime' => ''
            ];
        }
    }
    return $rowsByVehicle;
}

function fetchVehicleRepairHistories(mysqli $conn, array $vehicles, int $branch_id = 0, bool $view_all_branches = false): array {
    $histories = [];
    $ids = [];
    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return $histories;

    $idList = implode(',', array_map('intval', $ids));

    $releaseJoin = '';
    $checkedSelect = "'' AS checked_received_by";
    $receivedSelect = "'' AS received_datetime";
    if (tableExists($conn, 'motorpool_repair_release_proofs')) {
        $releaseColumns = getColumns($conn, 'motorpool_repair_release_proofs');
        $checkedExpr = in_array('checked_received_by', $releaseColumns, true) ? "COALESCE(rel.checked_received_by, '')" : "''";
        $receivedExpr = in_array('received_datetime', $releaseColumns, true) ? "COALESCE(rel.received_datetime, '')" : "''";
        $checkedSelect = "$checkedExpr AS checked_received_by";
        $receivedSelect = "$receivedExpr AS received_datetime";
        $releaseJoin = "LEFT JOIN motorpool_repair_release_proofs rel ON rel.ris_id = h.ris_id";
    }

    $sql = "SELECT h.repair_id, h.vehicle_db_id, h.ris_id, h.ris_number, h.repair_date, h.repairs_done, h.parts_replaced, h.mechanic, h.start_date, h.end_date, h.attachment, h.repair_cost, COALESCE(h.backlog_count, 0) AS backlog_count, h.last_backlog_at, h.created_at,
                   $checkedSelect,
                   $receivedSelect
            FROM vehicle_repair_history h
            $releaseJoin
            WHERE h.vehicle_db_id IN ($idList)
            ORDER BY COALESCE(h.repair_date, DATE(h.created_at)) DESC, h.repair_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['checked_received_by'] = trim((string)($row['checked_received_by'] ?? ''));
            $row['received_datetime'] = trim((string)($row['received_datetime'] ?? ''));
            $row['repairs_done'] = motorpoolBuildRepairsDoneFromAssessment($conn, (int)($row['ris_id'] ?? 0), (string)($row['repairs_done'] ?? ''));
            $row['parts_replaced'] = motorpoolBuildPartsReplacedSummaryForRis($conn, (int)($row['ris_id'] ?? 0), (string)($row['parts_replaced'] ?? ''));
            $costPack = motorpoolBuildCostsForRis($conn, (int)($row['ris_id'] ?? 0));
            $row['repair_cost_total'] = number_format((float)($costPack['repair_cost'] ?? 0), 2, '.', '');
            $row['motorpool_item_cost'] = number_format((float)($costPack['motorpool_item_cost'] ?? 0), 2, '.', '');
            $row['branch_item_cost'] = number_format((float)($costPack['branch_item_cost'] ?? 0), 2, '.', '');
            $row['miscellaneous_cost_total'] = number_format((float)($costPack['misc_cost'] ?? 0), 2, '.', '');
            $row['payment_parts'] = $costPack['parts'] ?? [];
            $row['payment_repairs'] = $costPack['repairs'] ?? [];
            $row['payment_misc_items'] = $costPack['misc_items'] ?? [];
            $row['has_branch_source_items'] = ((float)($costPack['branch_item_cost'] ?? 0) > 0 || array_filter(($costPack['parts'] ?? []), function($part) { return stripos((string)($part['source'] ?? $part['source_by'] ?? ''), 'branch') !== false; })) ? 1 : 0;
            $row['grand_total_cost'] = number_format((float)($costPack['repair_cost'] ?? 0) + (float)($costPack['item_cost'] ?? 0) + (float)($costPack['misc_cost'] ?? 0), 2, '.', '');
            if ((float)$row['grand_total_cost'] <= 0) $row['grand_total_cost'] = number_format(motorpoolGrandTotalCostForRis($conn, (int)($row['ris_id'] ?? 0), (string)($row['repair_cost'] ?? '0.00')), 2, '.', '');
            $histories[(int)$row['vehicle_db_id']][] = $row;
        }
    }
    $fallbackRows = motorpoolFallbackRepairHistoryRowsFromRis($conn, $ids);
    foreach ($fallbackRows as $vehicleId => $rows) {
        foreach ($rows as $fallbackRow) {
            $exists = false;
            foreach ($histories[$vehicleId] ?? [] as $existing) {
                if ((int)($existing['ris_id'] ?? 0) > 0 && (int)($existing['ris_id'] ?? 0) === (int)($fallbackRow['ris_id'] ?? 0)) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) $histories[$vehicleId][] = $fallbackRow;
        }
        usort($histories[$vehicleId], function($a, $b) {
            $ad = (string)($a['repair_date'] ?? ($a['created_at'] ?? ''));
            $bd = (string)($b['repair_date'] ?? ($b['created_at'] ?? ''));
            return strcmp($bd, $ad);
        });
    }
    return $histories;
}



function normalizeWorkflowStatusPHP(string $status): string {
    $value = strtolower(trim(str_replace('-', ' ', $status)));
    $value = preg_replace('/\s+/', ' ', $value);
    if (strpos($value, 'endorsement') !== false) return 'For Vehicle Endorsement';
    if (strpos($value, 'assessment') !== false) return 'For Assessment';
    if (strpos($value, 'approval') !== false) return 'For Approval';
    if (strpos($value, 'parts completion') !== false) return 'For Parts Completion';
    if ($value === 'for repair' || strpos($value, 'for repair') !== false) return 'For Repair';
    if (strpos($value, 'ongoing repair') !== false || strpos($value, 'on going repair') !== false) return 'On-going Repair';
    if (strpos($value, 'quality check') !== false) return 'For Quality Check';
    if (strpos($value, 'release') !== false || strpos($value, 'completed repair') !== false) return 'For Release';
    return $status;
}

function workflowKeyExists(array $items, int $risId, string $status): bool {
    foreach ($items as $item) {
        if ((int)($item['ris_id'] ?? 0) === $risId && normalizeWorkflowStatusPHP((string)($item['workflow_status'] ?? '')) === $status) {
            return true;
        }
    }
    return false;
}

function fetchVehicleWorkflowHistories(mysqli $conn, array $vehicles): array {
    $histories = [];
    $ids = [];
    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return $histories;

    $idList = implode(',', array_map('intval', $ids));

    if (tableExists($conn, 'motorpool_ris_workflow_history')) {
        $sql = "SELECT h.*, CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS processed_by_name
                FROM motorpool_ris_workflow_history h
                LEFT JOIN users u ON u.user_id = h.processed_by
                WHERE h.vehicle_db_id IN ($idList)
                ORDER BY h.processed_at ASC, h.history_id ASC";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $risIdForParts = (int)($row['ris_id'] ?? 0);
                $statusForParts = normalizeWorkflowStatusPHP((string)($row['workflow_status'] ?? ''));
                if ($risIdForParts > 0 && in_array($statusForParts, ['For Repair', 'On-going Repair', 'For Quality Check', 'For Release'], true)) {
                    $fullPartsSummary = motorpoolBuildPartsReplacedSummaryForRis($conn, $risIdForParts, '');
                    if (trim($fullPartsSummary) !== '') {
                        $existingDetails = (string)($row['details'] ?? '');
                        $hasDetailedParts = (stripos($existingDetails, 'Description:') !== false && stripos($existingDetails, 'Specification:') !== false);
                        if (!$hasDetailedParts) {
                            $row['details'] = rtrim($existingDetails) . "

Parts Replaced / Used:
" . $fullPartsSummary;
                        }
                    }
                    $row['details'] = motorpoolAppendCostSummaryForRis($conn, $risIdForParts, (string)($row['details'] ?? ''));
                }
                $histories[(int)$row['vehicle_db_id']][] = $row;
            }
        }
    }

    $sql = "SELECT r.*, 
                   a.repairs_summary, a.parts_summary, a.assessment_json, a.assessed_at,
                   qc.quality_summary, qc.quality_check_by, qc.quality_check_datetime, qc.remarks AS quality_remarks,
                   CONCAT(COALESCE(assessor.first_name,''), ' ', COALESCE(assessor.last_name,'')) AS assessed_by_name,
                   CONCAT(COALESCE(approver.first_name,''), ' ', COALESCE(approver.last_name,'')) AS approved_by_name
            FROM motorpool_ris_requests r
            LEFT JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_quality_checks qc ON qc.ris_id = r.ris_id
            LEFT JOIN users assessor ON assessor.user_id = a.assessed_by
            LEFT JOIN users approver ON approver.user_id = r.branch_approval_by
            WHERE r.vehicle_db_id IN ($idList)
            ORDER BY r.created_at ASC, r.ris_id ASC";
    $result = $conn->query($sql);
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $vid = (int)($r['vehicle_db_id'] ?? 0);
            if ($vid <= 0) continue;
            if (!isset($histories[$vid])) $histories[$vid] = [];
            $risId = (int)($r['ris_id'] ?? 0);
            $risNo = (string)($r['ris_number'] ?? '');
            $common = [
                'ris_id' => $risId,
                'ris_number' => $risNo,
                'vehicle_db_id' => $vid,
                'vehicle_id' => $r['vehicle_id'] ?? '',
                'plate_no' => $r['plate_no'] ?? '',
                'processed_by' => $r['requested_by'] ?? '',
                'processed_by_name' => 'System'
            ];

            if (!workflowKeyExists($histories[$vid], $risId, 'For Vehicle Endorsement')) {
                $histories[$vid][] = $common + [
                    'workflow_status' => 'For Vehicle Endorsement',
                    'details' => 'RIS submitted for vehicle endorsement.' . (!empty($r['concerns']) ? "\nConcern/s: " . $r['concerns'] : ''),
                    'attachment' => '',
                    'processed_at' => $r['created_at'] ?? $r['date_requested'] ?? ''
                ];
            }

            if (!empty($r['repairs_summary']) || !empty($r['parts_summary'])) {
                $assessmentDetails = "Repairs to Make:\n" . (string)($r['repairs_summary'] ?? '') . "\n\nItems / Parts Needed:\n" . (string)($r['parts_summary'] ?? '');
                if (!workflowKeyExists($histories[$vid], $risId, 'For Assessment')) {
                    $histories[$vid][] = $common + [
                        'workflow_status' => 'For Assessment',
                        'details' => $assessmentDetails,
                        'attachment' => '',
                        'processed_by_name' => trim((string)($r['assessed_by_name'] ?? '')) ?: 'Motorpool',
                        'processed_at' => $r['assessed_at'] ?? $r['updated_at'] ?? ''
                    ];
                }
                if (!workflowKeyExists($histories[$vid], $risId, 'For Approval')) {
                    $histories[$vid][] = $common + [
                        'workflow_status' => 'For Approval',
                        'details' => 'Assessment sent to Branch Admin for approval.' . "\n\n" . $assessmentDetails,
                        'attachment' => '',
                        'processed_by_name' => trim((string)($r['assessed_by_name'] ?? '')) ?: 'Motorpool',
                        'processed_at' => $r['assessed_at'] ?? $r['updated_at'] ?? ''
                    ];
                }
            }

            if (strtolower((string)($r['branch_approval_status'] ?? '')) === 'approved') {
                $approvalDetails = 'Assessment approved by Branch Admin.';
                if (!empty($r['branch_approval_remarks'])) $approvalDetails .= "\nRemarks: " . $r['branch_approval_remarks'];
                if (!workflowKeyExists($histories[$vid], $risId, 'For Parts Completion')) {
                    $histories[$vid][] = $common + [
                        'workflow_status' => 'For Parts Completion',
                        'details' => $approvalDetails . "\nMotorpool may now complete the required parts.",
                        'attachment' => '',
                        'processed_by_name' => trim((string)($r['approved_by_name'] ?? '')) ?: 'Branch Admin',
                        'processed_at' => $r['branch_approval_at'] ?? $r['updated_at'] ?? ''
                    ];
                }
            }

            if (!empty($r['quality_summary']) && !workflowKeyExists($histories[$vid], $risId, 'For Quality Check')) {
                $histories[$vid][] = $common + [
                    'workflow_status' => 'For Quality Check',
                    'details' => motorpoolAppendCostSummaryForRis($conn, $risId, (string)$r['quality_summary']),
                    'attachment' => '',
                    'processed_by_name' => trim((string)($r['quality_check_by'] ?? '')) ?: 'Motorpool',
                    'processed_at' => $r['quality_check_datetime'] ?? $r['updated_at'] ?? ''
                ];
            }

            if (!workflowKeyExists($histories[$vid], $risId, 'For Release') && (!empty($r['completed_at']) || normalizeWorkflowStatusPHP((string)($r['workflow_status'] ?? $r['status'] ?? '')) === 'For Release')) {
                $releaseDetails = 'Repair is ready for release.';
                if (!empty($r['quality_summary'])) $releaseDetails = 'Quality check completed. Repair is ready for release.

' . $r['quality_summary'];
                $histories[$vid][] = $common + [
                    'workflow_status' => 'For Release',
                    'details' => motorpoolAppendCostSummaryForRis($conn, $risId, $releaseDetails),
                    'attachment' => $r['ris_attachment'] ?? '',
                    'processed_by_name' => 'Motorpool',
                    'processed_at' => $r['completed_at'] ?? $r['updated_at'] ?? ''
                ];
            }
        }
    }

    if (tableExists($conn, 'motorpool_vehicle_receipt_photos')) {
        $sql = "SELECT p.ris_id, p.filename, p.timestamp_text, p.uploaded_at, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no, vr.received_by_name, vr.received_datetime
                FROM motorpool_vehicle_receipt_photos p
                INNER JOIN motorpool_ris_requests r ON r.ris_id = p.ris_id
                LEFT JOIN motorpool_vehicle_receipts vr ON vr.ris_id = p.ris_id
                WHERE r.vehicle_db_id IN ($idList)
                ORDER BY p.uploaded_at ASC, p.photo_id ASC";
        $result = $conn->query($sql);
        $byRis = [];
        if ($result) {
            while ($p = $result->fetch_assoc()) {
                $byRis[(int)$p['ris_id']]['row'] = $p;
                $byRis[(int)$p['ris_id']]['photos'][] = [
                    'filename' => $p['filename'],
                    'timestamp_text' => $p['timestamp_text'],
                    'uploaded_at' => $p['uploaded_at']
                ];
            }
        }
        foreach ($byRis as $risId => $pack) {
            $p = $pack['row'];
            $vid = (int)$p['vehicle_db_id'];
            if (!isset($histories[$vid])) $histories[$vid] = [];
            if (!workflowKeyExists($histories[$vid], $risId, 'For Vehicle Endorsement')) {
                $histories[$vid][] = [
                    'ris_id' => $risId,
                    'ris_number' => $p['ris_number'],
                    'vehicle_db_id' => $vid,
                    'vehicle_id' => $p['vehicle_id'],
                    'plate_no' => $p['plate_no'],
                    'workflow_status' => 'For Vehicle Endorsement',
                    'details' => 'Vehicle received by ' . ($p['received_by_name'] ?? 'Motorpool') . '.',
                    'attachment' => json_encode($pack['photos']),
                    'processed_by_name' => 'Motorpool',
                    'processed_at' => $p['received_datetime'] ?? $p['uploaded_at']
                ];
            }
        }
    }

    if (tableExists($conn, 'motorpool_repair_start_logs')) {
        $sql = "SELECT l.*, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no
                FROM motorpool_repair_start_logs l
                INNER JOIN motorpool_ris_requests r ON r.ris_id = l.ris_id
                WHERE r.vehicle_db_id IN ($idList)
                ORDER BY l.start_datetime ASC, l.log_id ASC";
        $result = $conn->query($sql);
        if ($result) {
            while ($log = $result->fetch_assoc()) {
                $vid = (int)($log['vehicle_db_id'] ?? 0);
                if ($vid <= 0) continue;
                if (!isset($histories[$vid])) $histories[$vid] = [];
                $partsText = motorpoolBuildPartsUsedSummaryFromJsonForRis($conn, (int)($log['ris_id'] ?? 0), (string)($log['parts_used_json'] ?? '[]'));
                $repairType = strtolower(trim((string)($log['repair_type'] ?? ''))) === 'with_parts' ? 'With Parts' : 'Labor Only';
                $histories[$vid][] = [
                    'ris_id' => (int)($log['ris_id'] ?? 0),
                    'ris_number' => $log['ris_number'] ?? '',
                    'vehicle_db_id' => $vid,
                    'vehicle_id' => $log['vehicle_id'] ?? '',
                    'plate_no' => $log['plate_no'] ?? '',
                    'workflow_status' => 'For Repair',
                    'details' => 'Repair: ' . (string)($log['repair_description'] ?? '') . "
Repair Type: " . $repairType . "
Start Date/Time: " . (string)($log['start_datetime'] ?? '') . "
Mechanic: " . (string)($log['mechanic'] ?? '') . "
Parts Used:
" . $partsText,
                    'attachment' => '',
                    'processed_by_name' => trim((string)($log['mechanic'] ?? '')) ?: 'Motorpool',
                    'processed_at' => $log['start_datetime'] ?? $log['saved_at'] ?? ''
                ];
                if (trim((string)($log['end_datetime'] ?? '')) !== '' || strtolower((string)($log['log_status'] ?? '')) === 'done') {
                    $doneMechanic = trim((string)($log['completion_mechanic'] ?? '')) ?: trim((string)($log['mechanic'] ?? ''));
                    $histories[$vid][] = [
                        'ris_id' => (int)($log['ris_id'] ?? 0),
                        'ris_number' => $log['ris_number'] ?? '',
                        'vehicle_db_id' => $vid,
                        'vehicle_id' => $log['vehicle_id'] ?? '',
                        'plate_no' => $log['plate_no'] ?? '',
                        'workflow_status' => 'On-going Repair',
                        'details' => 'Repair Done: ' . (string)($log['repair_description'] ?? '') . "
Repair Type: " . $repairType . "
Start Date/Time: " . (string)($log['start_datetime'] ?? '') . "
End Date/Time: " . (string)($log['end_datetime'] ?? '') . "
Mechanic: " . $doneMechanic . "
Parts Used:
" . $partsText,
                        'attachment' => '',
                        'processed_by_name' => $doneMechanic ?: 'Motorpool',
                        'processed_at' => $log['end_datetime'] ?? $log['saved_at'] ?? ''
                    ];
                }
            }
        }
    }

    if (tableExists($conn, 'motorpool_repair_release_proofs')) {
        $relCols = getColumns($conn, 'motorpool_repair_release_proofs');
        $checkedCol = in_array('checked_received_by', $relCols, true) ? 'rel.checked_received_by' : "''";
        $receivedCol = in_array('received_datetime', $relCols, true) ? 'rel.received_datetime' : "''";
        $sql = "SELECT rel.*, $checkedCol AS checked_received_by_safe, $receivedCol AS received_datetime_safe, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no
                FROM motorpool_repair_release_proofs rel
                INNER JOIN motorpool_ris_requests r ON r.ris_id = rel.ris_id
                WHERE r.vehicle_db_id IN ($idList)
                ORDER BY rel.released_at ASC";
        $result = $conn->query($sql);
        if ($result) {
            while ($rel = $result->fetch_assoc()) {
                $vid = (int)($rel['vehicle_db_id'] ?? 0);
                if ($vid <= 0) continue;
                if (!isset($histories[$vid])) $histories[$vid] = [];
                $partsSummaryForRelease = motorpoolBuildPartsReplacedSummaryForRis($conn, (int)($rel['ris_id'] ?? 0), '');
                $details = 'Repair completed and released to Branch Admin repair history.';
                if (trim($partsSummaryForRelease) !== '') {
                    $details .= "

Parts Replaced / Used:
" . $partsSummaryForRelease;
                }
                if (trim((string)($rel['checked_received_by_safe'] ?? '')) !== '') $details .= "

Checked and Received By: " . $rel['checked_received_by_safe'];
                if (trim((string)($rel['received_datetime_safe'] ?? '')) !== '') $details .= "
Date and Time Received: " . $rel['received_datetime_safe'];
                $histories[$vid][] = [
                    'ris_id' => (int)($rel['ris_id'] ?? 0),
                    'ris_number' => $rel['ris_number'] ?? '',
                    'vehicle_db_id' => $vid,
                    'vehicle_id' => $rel['vehicle_id'] ?? '',
                    'plate_no' => $rel['plate_no'] ?? '',
                    'workflow_status' => 'For Release',
                    'details' => $details,
                    'attachment' => $rel['release_attachment'] ?? '',
                    'processed_by_name' => 'Motorpool',
                    'processed_at' => $rel['released_at'] ?? ''
                ];
            }
        }
    }

    foreach ($histories as $vid => $items) {
        usort($items, function($a, $b) {
            $order = [
                'For Vehicle Endorsement' => 1,
                'For Assessment' => 2,
                'For Approval' => 3,
                'For Parts Completion' => 4,
                'For Repair' => 5,
                'On-going Repair' => 6,
                'For Quality Check' => 7,
                'For Release' => 8
            ];
            $oa = $order[normalizeWorkflowStatusPHP((string)($a['workflow_status'] ?? ''))] ?? 99;
            $ob = $order[normalizeWorkflowStatusPHP((string)($b['workflow_status'] ?? ''))] ?? 99;
            if ($oa === $ob) return strcmp((string)($a['processed_at'] ?? ''), (string)($b['processed_at'] ?? ''));
            return $oa <=> $ob;
        });
        $histories[$vid] = $items;
    }

    return $histories;
}


function fetchVehicleRegistrationHistories(mysqli $conn, array $vehicles): array {
    $histories = [];
    $ids = [];
    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return $histories;

    $idList = implode(',', array_map('intval', $ids));
    $sql = "SELECT vehicle_db_id, vehicle_id, plate_no, or_no, reg_date, next_renewal, or_attachment, created_at
            FROM motorpool_registration_history
            WHERE vehicle_db_id IN ($idList)
            ORDER BY COALESCE(reg_date, DATE(created_at)) DESC, registration_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $vid = (int)$row['vehicle_db_id'];
            $histories[$vid][] = $row;
        }
    }
    return $histories;
}

function fetchMotorpoolReturnedRequests(mysqli $conn, int $branch_id, bool $view_all_branches, int $user_id = 0): array {
    if (!tableExists($conn, 'motorpool_ris_requests')) return [];

    $branchFilter = '';
    if (!$view_all_branches) {
        $scopeParts = [];
        if ($branch_id > 0) {
            $scopeParts[] = 'r.branch_id = ' . intval($branch_id);
            if (tableExists($conn, 'motorpool_vehicles')) {
                $scopeParts[] = 'v.branch_id = ' . intval($branch_id);
            }
        }
        if ($user_id > 0) {
            $scopeParts[] = 'r.requested_by = ' . intval($user_id);
        }
        if (!empty($scopeParts)) {
            $branchFilter = ' AND (' . implode(' OR ', $scopeParts) . ')';
        }
    }

    $sql = "SELECT r.*,
                   COALESCE(v.branch_id, r.branch_id) AS vehicle_branch_id,
                   TRIM(CONCAT(COALESCE(req.first_name,''), ' ', COALESCE(req.last_name,''))) AS requested_by_name,
                   TRIM(CONCAT(COALESCE(ret.first_name,''), ' ', COALESCE(ret.last_name,''))) AS returned_by_name
            FROM motorpool_ris_requests r
            LEFT JOIN motorpool_vehicles v ON v.id = r.vehicle_db_id
            LEFT JOIN users req ON req.user_id = r.requested_by
            LEFT JOIN users ret ON ret.user_id = r.motorpool_returned_by
            WHERE (
                    LOWER(TRIM(COALESCE(r.workflow_status, ''))) = 'returned to branch admin'
                    OR LOWER(TRIM(COALESCE(r.status, ''))) = 'returned to branch admin'
                    OR (COALESCE(r.motorpool_return_remarks, '') <> '' AND LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) LIKE '%returned%')
                  )
              AND LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) NOT IN ('completed', 'released')
              $branchFilter
            ORDER BY COALESCE(r.motorpool_returned_at, r.updated_at, r.created_at) DESC, r.ris_id DESC";

    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['workflow_status'] = 'Returned to Branch Admin';
            $row['status'] = 'Returned to Branch Admin';
            $rows[] = $row;
        }
    }
    return $rows;
}


function fetchMotorpoolAssessmentsForApproval(mysqli $conn, int $branch_id, bool $view_all_branches): array {
    $where = "WHERE COALESCE(r.workflow_status, r.status) = 'For Approval'";
    if (!$view_all_branches && $branch_id > 0) {
        $where .= ' AND r.branch_id = ' . intval($branch_id);
    }

    $sql = "SELECT r.*,
                   a.assessment_json,
                   a.repairs_summary,
                   a.parts_summary,
                   a.assessed_at,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS assessed_by_name,
                   CONCAT(COALESCE(req.first_name,''), ' ', COALESCE(req.last_name,'')) AS requested_by_name
            FROM motorpool_ris_requests r
            INNER JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id
            LEFT JOIN users u ON u.user_id = a.assessed_by
            LEFT JOIN users req ON req.user_id = r.requested_by
            $where
            ORDER BY r.updated_at DESC, r.ris_id DESC";

    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}


function fetchMotorpoolForReleaseRequests(mysqli $conn, int $branch_id, bool $view_all_branches, int $user_id = 0): array {
    /*
     * Show every RIS that is already For Release.
     * Also include RIS rows with a saved quality check, because quality check is
     * the step that pushes the request to For Release.
     */
    $branchFilter = '';
    if (!$view_all_branches) {
        $scopeParts = [];
        if ($branch_id > 0) {
            $scopeParts[] = 'r.branch_id = ' . intval($branch_id);
            $scopeParts[] = 'v.branch_id = ' . intval($branch_id);
        }
        if ($user_id > 0) {
            $scopeParts[] = 'r.requested_by = ' . intval($user_id);
        }
        if (!empty($scopeParts)) {
            $branchFilter = ' AND (' . implode(' OR ', $scopeParts) . ')';
        }
    }

    $where = "WHERE (
                    LOWER(TRIM(COALESCE(r.workflow_status, ''))) LIKE '%release%'
                    OR LOWER(TRIM(COALESCE(r.status, ''))) LIKE '%release%'
                    OR qc.quality_id IS NOT NULL
                )
                AND LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) NOT IN ('completed', 'released')
                AND r.completed_at IS NULL
                AND vh.repair_id IS NULL
                " . $branchFilter;

    $sql = "SELECT r.*,
                   COALESCE(v.branch_id, r.branch_id) AS vehicle_branch_id,
                   a.repairs_summary,
                   a.parts_summary,
                   a.assessment_json,
                   COALESCE(qc.quality_check_json, '[]') AS quality_check_json,
                   COALESCE(qc.quality_summary, '') AS quality_summary,
                   COALESCE(qc.quality_check_by, '') AS quality_check_by,
                   qc.quality_check_datetime,
                   COALESCE(qc.remarks, '') AS quality_remarks,
                   CONCAT(COALESCE(req.first_name,''), ' ', COALESCE(req.last_name,'')) AS requested_by_name
            FROM motorpool_ris_requests r
            LEFT JOIN motorpool_vehicles v ON v.id = r.vehicle_db_id
            LEFT JOIN motorpool_ris_quality_checks qc ON qc.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id
            LEFT JOIN vehicle_repair_history vh ON vh.ris_id = r.ris_id
            LEFT JOIN users req ON req.user_id = r.requested_by
            $where
            GROUP BY r.ris_id
            ORDER BY COALESCE(qc.quality_check_datetime, r.updated_at, r.created_at) DESC, r.ris_id DESC";

    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['workflow_status'] = 'For Release';
            $row['status'] = 'For Release';
            $rows[] = $row;
        }
    }
    return $rows;
}



function fetchVehicleFuelHistories(mysqli $conn, array $vehicles): array {
    $histories = [];
    $ids = [];
    foreach ($vehicles as $vehicle) if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return $histories;
    $idList = implode(',', array_map('intval', $ids));
    $sql = "SELECT f.fuel_id, f.vehicle_db_id, f.vehicle_id, f.plate_no, f.fuel_date,
                   f.current_odometer, f.previous_odometer, f.distance_covered,
                   f.liters_consumed, COALESCE(f.refuel_liters, 0) AS refuel_liters,
                   f.fuel_efficiency, COALESCE(f.fuel_price, 0) AS fuel_price,
                   COALESCE(f.fuel_attachment, '') AS fuel_attachment,
                   COALESCE(f.driver_name, '') AS driver_name,
                   f.encoded_by,
                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS encoded_by_name,
                   f.created_at
            FROM motorpool_fuel_monitoring f
            LEFT JOIN users u ON u.user_id = f.encoded_by
            WHERE f.vehicle_db_id IN ($idList)
            ORDER BY f.fuel_date DESC, f.fuel_id DESC";
    $result = $conn->query($sql);
    if ($result) while ($row = $result->fetch_assoc()) $histories[(int)$row['vehicle_db_id']][] = $row;
    return $histories;
}


function fetchVehicleRepairPaymentHistories(mysqli $conn, array $vehicles, int $branch_id = 0, bool $view_all_branches = false): array {
    $histories = [];
    $ids = [];
    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids) || !tableExists($conn, 'repair_payment_history')) return $histories;

    $idList = implode(',', array_map('intval', $ids));
    $whereBranch = '';
    if (!$view_all_branches && $branch_id > 0 && tableExists($conn, 'motorpool_ris_requests')) {
        $whereBranch = ' AND (r.branch_id = ' . intval($branch_id) . ' OR r.branch_id IS NULL)';
    }

    $sql = "SELECT p.*
            FROM repair_payment_history p
            LEFT JOIN motorpool_ris_requests r ON r.ris_id = p.ris_id
            WHERE p.vehicle_db_id IN ($idList)
            $whereBranch
            ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.payment_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $histories[(int)($row['vehicle_db_id'] ?? 0)][] = $row;
        }
    }
    return $histories;
}

function branchApprovalBadge(string $status): string {
    $status = trim($status) !== '' ? $status : 'Pending';
    $class = 'secondary';
    if (strtolower($status) === 'pending') $class = 'warning text-dark';
    if (strtolower($status) === 'approved') $class = 'success';
    if (strtolower($status) === 'rejected') $class = 'danger';
    return '<span class="badge bg-' . $class . '">' . h($status) . '</span>';
}

$vehicles = fetchVehicles($conn, $vehicle_table, $vehicle_table_exists, $vehicle_columns, (int)$branch_id, (bool)$view_all_branches);
$vehicleRepairHistories = fetchVehicleRepairHistories($conn, $vehicles, (int)$branch_id, (bool)$view_all_branches);
$vehicleRepairPaymentHistories = fetchVehicleRepairPaymentHistories($conn, $vehicles, (int)$branch_id, (bool)$view_all_branches);
$vehicleWorkflowHistories = fetchVehicleWorkflowHistories($conn, $vehicles);
$vehicleRegistrationHistories = fetchVehicleRegistrationHistories($conn, $vehicles);
$vehicleFuelHistories = fetchVehicleFuelHistories($conn, $vehicles);
$motorpoolReturnedRequests = fetchMotorpoolReturnedRequests($conn, (int)$branch_id, (bool)$view_all_branches, (int)$user_id);
$motorpoolApprovalRequests = fetchMotorpoolAssessmentsForApproval($conn, (int)$branch_id, (bool)$view_all_branches);
$motorpoolForReleaseRequests = fetchMotorpoolForReleaseRequests($conn, (int)$branch_id, (bool)$view_all_branches, (int)$user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Motorpool - Branch Admin</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<style>
.form-card {
    background:#fff;
    border-radius:14px;
    padding:18px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
}
.custom-table th {
    background:#052A47;
    color:#fff;
    white-space:nowrap
}
.custom-table td {
    vertical-align:middle;
}
.ris-number-cell {
    white-space: nowrap !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    min-width: 180px;
}
.concern-cell {
    max-width: 280px;
    white-space: normal !important;
    word-break: break-word;
    overflow-wrap: anywhere;
    line-height: 1.4;
}
.item-thumbnail {
    width:46px;
    height:46px;
    border-radius:8px;
    background:#f1f3f5;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}
.item-thumbnail img {
    width:100%;
    height:100%;
    object-fit:cover;
}
.btn-action-text {
    white-space:nowrap;
    border-radius:8px;
}


.sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.35);
    z-index: 998;
    opacity: 0;
    transition: opacity .25s ease;
}
.sidebar-overlay.active {
    opacity: 1;
}
.dropdown-arrow {
    margin-left: auto;
    transition: transform .2s ease;
}
@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform .25s ease;
        z-index: 999;
    }
    .sidebar.active,
    .sidebar.show {
        transform: translateX(0);
    }
}


.vehicle-toolbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:16px;
}
.section-title {
    font-weight:700;
    color:#052A47;
    margin:18px 0 10px
}
.vehicle-form-section {
    border:1px solid #e6e8eb;
    border-radius:12px;
    padding:14px;
    margin-bottom:14px;
    background:#fff;
}
.vehicle-form-section .form-label {
    font-size:.85rem;
    font-weight:600;
    color: #334155
}
.vehicle-thumb {
    width:48px;
    height:48px;
    border-radius:10px;
    background: #eef2f7;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#64748b;
}
.file-hint {
    font-size:.78rem;
    color: #64748b;
}
.modal-xl-custom {
    max-width: 1180px;
}
.history-table th {
    background: #f8fafc!important;
    color:#0f172a!important;
    border-bottom:1px solid #e5e7eb
}
.required-mark {
    color: #dc3545;
}

/* Fixed Add Vehicle modal layout */
#vehicleModal .modal-dialog {
    margin-top: 12px;
    margin-bottom: 12px;
}
#vehicleModal .modal-content {
    max-height: calc(100vh - 24px);
    display: flex;
    flex-direction: column;
}
#vehicleModal .modal-body {
    overflow-y: auto;
    max-height: calc(100vh - 155px);
    padding-bottom: 12px;
}
#vehicleModal .modal-header,
#vehicleModal .modal-footer {
    flex-shrink: 0;
    background: #fff;
    z-index: 2;
}
#vehicleModal .modal-footer {
    position: sticky;
    bottom: 0;
    border-top: 1px solid #dee2e6;
}
@media (max-width: 768px) {
    #vehicleModal .modal-dialog {
        margin: 6px;
    }
    #vehicleModal .modal-content {
        max-height: calc(100vh - 12px);
    }
    #vehicleModal .modal-body {
        max-height: calc(100vh - 140px);
    }
}


/* Add Vehicle modal design aligned with existing system buttons and font */
#vehicleModal,
#vehicleModal * {
    font-family: inherit;
}
#vehicleModal .modal-dialog {
    max-width: 1240px;
}
#vehicleModal .modal-content {
    border: 1px solid #dfe6ec;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
#vehicleModal .modal-header {
    background: #07b83f;
    color: #ffffff;
    border-bottom: 0;
    padding: 14px 18px;
}
#vehicleModal .modal-title {
    font-weight: 600;
    font-size: 1.05rem;
}
#vehicleModal .btn-close {
    filter: invert(1) grayscale(100%) brightness(200%);
    opacity: .9;
}
#vehicleModal .modal-body {
    padding: 16px;
    background: #f8fafc;
}
#vehicleModal .modal-footer {
    background: #ffffff;
    border-top: 1px solid #dee2e6;
    padding: 12px 18px;
}
#vehicleModal .modal-footer .btn {
    border-radius: 8px;
    font-weight: 500;
    padding: 8px 14px;
}
#vehicleModal .btn-success {
    background: #07b83f;
    border-color: #07b83f;
}
#vehicleModal .btn-success:hover {
    background: #069d36;
    border-color: #069d36;
}
#vehicleModal .btn-secondary {
    background: #6c757d;
    border-color: #6c757d;
}
.motorpool-table-section {
    border: 1px solid #dfe6ec;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
    background: #ffffff;
}
.motorpool-table-section .table-responsive {
    margin: 0;
}
.motorpool-form-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 0;
    background: #ffffff;
}
.motorpool-form-table th {
    background: #07b83f;
    color: #ffffff;
    padding: 11px 14px;
    font-size: .95rem;
    font-weight: 600;
    border: 1px solid #07b83f;
}
.motorpool-form-table td {
    border: 1px solid #e3e8ef;
    padding: 10px;
    vertical-align: top;
    background: #ffffff;
}
.motorpool-form-table tr:nth-child(even) td {
    background: #fbfbfb;
}
.motorpool-form-table .form-label {
    margin-bottom: 5px;
    font-size: .85rem;
    font-weight: 500;
    color: #333333;
}
.motorpool-form-table .form-control {
    min-height: 38px;
    font-size: .9rem;
    border: 1px solid #ced4da;
    border-radius: 8px;
    background-color: #ffffff;
}
.motorpool-form-table .form-control:focus {
    border-color: #07b83f;
    box-shadow: 0 0 0 .18rem rgba(7, 184, 63, .15);
}
.file-hint {
    color: #6c757d;
    font-size: .78rem;
}
.required-mark {
    color: #dc3545;
    font-weight: 600;
}
.history-table {
    background: #ffffff;
}
.history-table thead tr:first-child th {
    background: #07b83f !important;
    color: #ffffff !important;
    padding: 11px 14px;
    font-weight: 600;
    border-color: #07b83f !important;
}
.history-table thead tr:not(:first-child) th {
    background: #eaf8ef !important;
    color: #333333 !important;
    font-size: .85rem;
    font-weight: 500;
    border-color: #dfe6ec !important;
    white-space: nowrap;
}
.history-table td {
    border-color: #e3e8ef !important;
}
@media (max-width: 768px) {
    #vehicleModal .modal-body {
        padding: 10px;
    }
    .motorpool-form-table,
    .motorpool-form-table tbody,
    .motorpool-form-table tr,
    .motorpool-form-table td {
        display: block;
        width: 100%;
    }
    .motorpool-form-table td {
        border-right: 1px solid #e3e8ef;
        border-bottom: 1px solid #e3e8ef;
    }
}



/* Current inventory style table behavior for Motorpool */
.custom-table tbody tr.vehicle-click-row {
    cursor: pointer;
    transition: background-color .18s ease, transform .18s ease;
}
.custom-table tbody tr.vehicle-click-row:hover td {
    background: #f4fbf6;
}
.custom-table .col-image {
    width: 78px;
    text-align: center;
}
.item-thumbnail {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    background: #f1f3f5;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    margin: 0 auto;
}
.item-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.vehicle-detail-hero {
    display: flex;
    gap: 18px;
    align-items: center;
    padding: 16px;
    background: #f8fafc;
    border: 1px solid #e3e8ef;
    border-radius: 12px;
    margin-bottom: 16px;
}
.vehicle-detail-image {
    width: 120px;
    height: 120px;
    border-radius: 14px;
    background: #f1f3f5;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.vehicle-detail-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.vehicle-detail-title h4 {
    margin: 0 0 6px;
    font-weight: 700;
    color: #1f2937;
}
.vehicle-detail-title .badge {
    font-size: .8rem;
}
.vehicle-detail-tabs .nav-link {
    color: #495057;
    font-weight: 600;
    border-radius: 8px 8px 0 0;
}
.vehicle-detail-tabs .nav-link.active {
    color: #07b83f;
    border-color: #dee2e6 #dee2e6 #fff;
}
.detail-info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    column-gap: 28px;
    row-gap: 10px;
}
.detail-info-item {
    display: grid;
    grid-template-columns: 145px minmax(0, 1fr);
    align-items: start;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid #eef2f6;
    background: transparent;
}
.detail-info-item small {
    color: #6c757d;
    font-size: .82rem;
    line-height: 1.25;
}
.detail-info-item strong {
    color: #212529;
    font-weight: 600;
    line-height: 1.25;
    word-break: break-word;
}
.vehicle-image-preview-wrap {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
}
.vehicle-image-preview {
    border: 1px solid #e3e8ef;
    border-radius: 12px;
    background: #fff;
    padding: 10px;
}
.vehicle-image-preview img {
    width: 100%;
    height: 130px;
    object-fit: cover;
    border-radius: 8px;
    background: #f1f3f5;
}
.vehicle-image-preview a {
    display: block;
    margin-top: 7px;
    font-size: .85rem;
    color: #07b83f;
    font-weight: 600;
    text-decoration: none;
}
@media (max-width: 768px) {
    .vehicle-detail-hero { align-items: flex-start; flex-direction: column; }
    .detail-info-grid { grid-template-columns: 1fr; }
    .detail-info-item { grid-template-columns: 130px minmax(0, 1fr); }
}



/* Plain 3-column Add Vehicle form layout */
.motorpool-form-panel {
    background: #ffffff;
    border: 1px solid #e3e8ef;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 14px;
}
.motorpool-panel-title {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #1f2937;
    font-weight: 600;
    padding-bottom: 8px;
    margin-bottom: 14px;
    border-bottom: 2px solid #0d6efd;
}
.motorpool-form-panel .form-label {
    font-size: .86rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 5px;
}
.motorpool-form-panel .form-control {
    min-height: 38px;
    height: auto;
    border: 1px solid #d8e0ea;
    border-radius: 9px;
    font-size: .9rem;
    padding: .45rem .75rem;
    background-color: #ffffff;
}
.motorpool-form-panel input[type="file"].form-control {
    padding: .38rem .65rem;
}
.motorpool-form-panel .form-control:focus {
    border-color: #07b83f;
    box-shadow: 0 0 0 .15rem rgba(7, 184, 63, .15);
}

#vehicleRegistrationTab .history-table th,
#vehicleRegistrationTab .history-table td {
    font-size: .92rem;
    vertical-align: middle;
    white-space: nowrap;
}
#renewRegistrationModal .form-label {
    font-weight: 600;
    color: #374151;
}
#renewRegistrationModal .form-control:focus {
    border-color: #07b83f;
    box-shadow: 0 0 0 .15rem rgba(7, 184, 63, .15);
}

.compact-ris-info {
    margin-top: 8px;
}
#risModal .modal-dialog {
    max-width: 1100px !important;
}
#risModal .modal-body {
    padding: 16px 18px;
}
#risModal .section-title {
    font-weight: 700;
    color: #052A47;
    border-bottom: 2px solid #07b83f;
    display: inline-block;
    padding-bottom: 5px;
    margin-bottom: 8px;
    font-size: 1rem;
}
#risModal .compact-ris-info {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    column-gap: 26px;
    row-gap: 6px;
}
#risModal .compact-ris-info .detail-info-item {
    display: block;
    padding: 5px 0 6px;
    border: 0;
    border-bottom: 1px solid #eef2f6;
    background: transparent;
    min-width: 0;
}
#risModal .compact-ris-info .detail-info-item small {
    display: block;
    color: #6c757d;
    font-size: .9rem;
    font-weight: 600;
    line-height: 1.2;
    margin-bottom: 2px;
}
#risModal .compact-ris-info .detail-info-item strong {
    display: block;
    color: #212529;
    font-size: .98rem;
    font-weight: 600;
    line-height: 1.3;
    white-space: normal;
    overflow-wrap: anywhere;
}
#risModal hr {
    margin: 12px 0 !important;
}
#risModal .row.g-3 {
    --bs-gutter-y: .65rem;
}
#risModal .form-label {
    font-size: .92rem;
    font-weight: 600;
    margin-bottom: 4px;
}
#risModal .form-control {
    min-height: 36px;
    font-size: .95rem;
    padding: .42rem .68rem;
}
#risModal textarea.form-control {
    min-height: 78px;
    resize: vertical;
}
#risModal .modal-header,
#risModal .modal-footer {
    padding: 11px 16px;
}
@media (max-width: 992px) {
    #risModal .compact-ris-info {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 576px) {
    #risModal .compact-ris-info {
        grid-template-columns: 1fr;
    }
}


/* File Preview Modal - same behavior/style as approve_credit_requests.php */
#motorpoolFilePreviewModal .modal-dialog {
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    max-width: none;
    width: auto;
}

#motorpoolFilePreviewModal .modal-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    width: auto;
    margin: 0 auto;
}

#motorpoolFilePreviewModal .modal-body {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    padding: 20px !important;
}

#motorpoolFilePreviewModal .attachment-container {
    display: flex;
    justify-content: center;
    align-items: center;
}

#motorpoolFilePreviewModal .attachment-wrapper {
    position: relative;
    display: inline-block;
    line-height: 0;
}

#motorpoolFilePreviewModal .btn-close-attachment {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 30px;
    height: 30px;
    background-color: rgba(0,0,0,0.6);
    border-radius: 50%;
    display: flex !important;
    align-items: center;
    justify-content: center;
    border: none;
    color: white;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 10;
    padding: 0;
    margin: 0;
}

#motorpoolFilePreviewModal .btn-close-attachment:hover {
    background-color: rgba(0,0,0,0.8);
    transform: scale(1.05);
}

#motorpoolFilePreviewModal .btn-download-attachment {
    position: absolute;
    bottom: 5px;
    right: 5px;
    width: 30px;
    height: 30px;
    background-color: rgba(0,0,0,0.6);
    border-radius: 50%;
    display: flex !important;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: white;
    font-size: 12px;
    transition: all 0.2s ease;
    z-index: 10;
}

#motorpoolFilePreviewModal .btn-download-attachment:hover {
    background-color: rgba(0,0,0,0.8);
    transform: scale(1.05);
    color: white;
}

#motorpoolFilePreviewModal .attachment-content {
    display: inline-block;
    line-height: 0;
}

#motorpoolFilePreviewModal .attachment-content img {
    max-height: 85vh;
    max-width: 85vw;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.3);
    display: block;
}

#motorpoolFilePreviewModal .attachment-content embed {
    width: 80vw;
    height: 80vh;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.3);
    display: block;
}

#motorpoolFilePreviewModal .attachment-content .alert {
    max-width: 500px;
    margin: 20px;
    display: block;
    line-height: 1.4;
}

.signature-preview-box {
    min-height: 92px;
    border: 1px dashed #b8c2cc;
    border-radius: 10px;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px;
}
.signature-preview-empty {
    color: #6c757d;
    font-size: .9rem;
}
.signature-preview-image {
    max-width: 100%;
    max-height: 78px;
    object-fit: contain;
}
.signature-pad-box {
    border: 1px solid #ced4da;
    border-radius: 10px;
    padding: 10px;
    background: #ffffff;
}
.signature-pad-canvas {
    width: 100%;
    height: 320px;
    border: 1px solid #ccc;
    border-radius: 8px;
    cursor: crosshair;
    background: #ffffff;
    touch-action: none;
    display: block;
}
#signatureModal .modal-dialog {
    max-width: 920px;
}
#signatureModal .modal-content {
    border-radius: 14px;
}
#signatureModal .modal-body {
    padding: 18px;
}
#signatureModal .signature-pad-box {
    padding: 12px;
}
@media (max-width: 576px) {
    #signatureModal .modal-dialog {
        max-width: calc(100% - 16px);
        margin-left: auto;
        margin-right: auto;
    }
    .signature-pad-canvas {
        height: 260px;
    }
}
#signatureModal .modal-header {
    background: #ffffff;
    border-bottom: 1px solid #dee2e6;
}

/* File Preview Modal */
#motorpoolFilePreviewModal {
    padding: 0 !important;
}

#motorpoolFilePreviewModal .modal-dialog {
    position: fixed;
    inset: 0;
    margin: 0 !important;
    width: 100vw;
    height: 100vh;
    max-width: 100vw !important;
    display: flex;
    align-items: center;
    justify-content: center;
}

#motorpoolFilePreviewModal .modal-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    overflow: visible !important;
}

#motorpoolFilePreviewModal .modal-body {
    padding: 0 !important;
    margin: 0 !important;
    overflow: visible !important;
    display: flex;
    align-items: center;
    justify-content: center;
}

#motorpoolFilePreviewModal .attachment-container {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}

#motorpoolFilePreviewModal .attachment-wrapper {
    position: relative;
    display: inline-block;
    line-height: 0;
}

#motorpoolFilePreviewModal .attachment-content img {
    display: block;
    max-width: 92vw;
    max-height: 92vh;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
}

#motorpoolFilePreviewModal .attachment-content embed {
    display: block;
    width: 92vw;
    height: 92vh;
    border-radius: 10px;
    background: #fff;
}

#motorpoolFilePreviewModal .btn-close-attachment {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 50%;
    background: rgba(0,0,0,.7);
    color: #fff;
    z-index: 9999;
    display: flex !important;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 14px;
    transition: .2s ease;
}

#motorpoolFilePreviewModal .btn-close-attachment:hover {
    background: rgba(0,0,0,.9);
    transform: scale(1.05);
}

#motorpoolFilePreviewModal .btn-download-attachment {
    position: absolute;
    bottom: 10px;
    right: 10px;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(0,0,0,.7);
    color: #fff;
    z-index: 9999;
    display: flex !important;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: .2s ease;
}

#motorpoolFilePreviewModal .btn-download-attachment:hover {
    background: rgba(0,0,0,.9);
    transform: scale(1.05);
    color: #fff;
}

body.modal-open {
    overflow: hidden !important;
    padding-right: 0 !important;
}

.modal {
    overflow-y: hidden !important;
}

/* =========================
   TABLET & MOBILE FIX ONLY
========================= */
@media (max-width: 991px) {

    #motorpoolFilePreviewModal .modal-body {
        padding: 10px !important;
        overflow: hidden !important;
    }

    #motorpoolFilePreviewModal .attachment-wrapper {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 100%;
        max-height: 100%;
    }

    #motorpoolFilePreviewModal .attachment-content img {
        max-width: 100%;
        max-height: calc(100dvh - 20px);
        width: auto;
        height: auto;
        object-fit: contain;
    }

    #motorpoolFilePreviewModal .attachment-content embed {
        width: calc(100vw - 20px);
        height: calc(100dvh - 20px);
    }

    /* FIX BUTTONS */
    #motorpoolFilePreviewModal .btn-close-attachment {
        position: absolute !important;
        top: 10px !important;
        right: 10px !important;
        z-index: 99999 !important;
        display: flex !important;
    }

    #motorpoolFilePreviewModal .btn-download-attachment {
        position: absolute !important;
        bottom: 10px !important;
        right: 10px !important;
        z-index: 99999 !important;
        display: flex !important;
    }
}

/* =========================
   MOBILE LANDSCAPE FIX
========================= */
@media (max-width: 991px) and (orientation: landscape) {

    #motorpoolFilePreviewModal .modal-body {
        padding: 14px !important;
    }

    #motorpoolFilePreviewModal .attachment-content img {
        max-height: calc(100dvh - 28px);
    }

    #motorpoolFilePreviewModal .attachment-content embed {
        height: calc(100dvh - 28px);
    }
}
/* =========================
   SMALL MOBILE
========================= */
@media (max-width: 380px) {

    #motorpoolFilePreviewModal .attachment-content img {
        max-height: 78vh;
    }

    #motorpoolFilePreviewModal .attachment-content embed {
        height: 78vh;
    }

    #motorpoolFilePreviewModal .btn-close-attachment,
    #motorpoolFilePreviewModal .btn-download-attachment {
        width: 28px;
        height: 28px;
        font-size: 11px;
    }
}

/* =========================
   PORTRAIT TABLET / IPAD / MOBILE ONLY
========================= */
@media (max-width: 991px) and (orientation: portrait) {

    #motorpoolFilePreviewModal .modal-dialog {
        height: 100dvh !important;
        align-items: center !important;
        justify-content: center !important;
    }

    #motorpoolFilePreviewModal .modal-content {
        width: auto !important;
        height: auto !important;
        max-width: calc(100vw - 24px) !important;
        max-height: calc(100dvh - 24px) !important;
        overflow: visible !important;
    }

    #motorpoolFilePreviewModal .modal-body {
        width: auto !important;
        height: auto !important;
        padding: 0 !important;
        overflow: visible !important;
    }

    #motorpoolFilePreviewModal .attachment-container {
        width: auto !important;
        height: auto !important;
    }

    #motorpoolFilePreviewModal .attachment-wrapper {
        display: inline-block !important;
        width: auto !important;
        height: auto !important;
        max-width: calc(100vw - 24px) !important;
        max-height: calc(100dvh - 24px) !important;
        overflow: visible !important;
        line-height: 0 !important;
    }

    #motorpoolFilePreviewModal .attachment-content img {
        display: block !important;
        max-width: calc(100vw - 24px) !important;
        max-height: calc(100dvh - 24px) !important;
        width: auto !important;
        height: auto !important;
        object-fit: contain !important;
    }

    #motorpoolFilePreviewModal .btn-close-attachment {
        top: 8px !important;
        right: 8px !important;
        z-index: 99999 !important;
    }

    #motorpoolFilePreviewModal .btn-download-attachment {
        bottom: 8px !important;
        right: 8px !important;
        z-index: 99999 !important;
    }
}

.motorpool-approval-card {
    border: 1px solid #d1fae5;
    border-radius: 14px;
    background: #ffffff;
    padding: 18px;
    margin-bottom: 18px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}
.motorpool-approval-card .approval-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.motorpool-approval-card h5 {
    color: #052A47;
    font-weight: 700;
    margin: 0;
}
.approval-row {
    cursor: pointer;
}
.approval-row:hover td {
    background: #f4fbf6;
}
#branchApprovalModal .modal-dialog {
    max-width: 1120px;
}
#branchApprovalModal .modal-content {
    border-radius: 14px;
    overflow: hidden;
}
#branchApprovalModal .modal-header {
    background: #07b83f;
    color: #ffffff;
    border-bottom: 0;
}
#branchApprovalModal .btn-close {
    filter: invert(1) grayscale(100%) brightness(200%);
}
#branchApprovalModal .modal-body {
    max-height: calc(100vh - 160px);
    overflow-y: auto;
    background: #f8fafc;
}
.approval-info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px 18px;
    margin-bottom: 14px;
}
.approval-info-item {
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 7px;
}
.approval-info-item small {
    display: block;
    color: #6c757d;
    font-weight: 600;
    margin-bottom: 2px;
}
.approval-info-item strong {
    color: #212529;
    overflow-wrap: anywhere;
}
.assessment-view-box {
    background: #ffffff;
    border: 1px solid #e3e8ef;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 14px;
}
.assessment-view-box h6 {
    color: #052A47;
    font-weight: 700;
    margin-bottom: 10px;
}
.assessment-view-box table th {
    background: #087f5b;
    color: #ffffff;
    white-space: nowrap;
}
.assessment-repair-card {
    background: #ffffff;
    border: 1px solid #dfe6ec;
    border-radius: 12px;
    padding: 14px;
}
.approval-parts-table th {
    background: #087f5b !important;
    color: #ffffff !important;
    white-space: nowrap;
}
.approval-parts-table th,
.approval-parts-table td {
    text-align: center !important;
    vertical-align: middle !important;
}
.approval-parts-table td {
    word-break: break-word;
}
.approval-parts-table tfoot td,
.approval-parts-table tfoot th {
    text-align: center !important;
    vertical-align: middle !important;
    font-weight: 700;
    background: #f1fdf5 !important;
    border-top: 2px solid #087f5b !important;
}
.approval-total-label {
    text-align: right !important;
    color: #047857 !important;
    font-weight: 700 !important;
}
.approval-total-amount {
    color: #047857 !important;
    font-size: 1rem;
    font-weight: 800 !important;
}
.repair-work-text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.approval-summary-pre {
    white-space: pre-wrap;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
    margin: 0;
    font-family: inherit;
    font-size: .9rem;
    color: #212529;
}
@media (max-width: 768px) {
    .approval-info-grid {
        grid-template-columns: 1fr;
    }
}
/* Mobile Bottom Navigation Styles */
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

/* Small mobile adjustments */
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


/* Detailed repair workflow modal and parts table */
.repair-history-main-table th,
.repair-history-main-table td {
    text-align: center;
    vertical-align: middle !important;
}
.repair-history-main-table .repair-history-text {
    text-align: left;
    max-width: 280px;
    margin: 0 auto;
}
.repair-history-main-table .parts-replaced-mini-table-wrap {
    min-width: 420px;
    margin: 0 !important;
}
.repair-history-main-table .parts-replaced-mini-table th,
.repair-history-main-table .parts-replaced-mini-table td {
    text-align: center;
    vertical-align: middle !important;
}
.repair-history-main-table .btn-view-workflow,
.repair-history-main-table .btn-backlog-repair {
    white-space: nowrap;
    border-radius: 8px;
}
.repair-timeline{position:relative;padding:6px 0 6px 24px}.repair-timeline:before{content:'';position:absolute;left:8px;top:8px;bottom:8px;width:2px;background:#dbe7e0}.timeline-item{position:relative;margin-bottom:14px;padding:12px 14px;background:#fff;border:1px solid #e3e8ef;border-radius:12px}.timeline-item:before{content:'';position:absolute;left:-22px;top:16px;width:12px;height:12px;border-radius:50%;background:#07b83f;border:2px solid #fff;box-shadow:0 0 0 2px #07b83f}.timeline-status{font-weight:700;color:#052A47}.timeline-meta{font-size:.85rem;color:#64748b;margin-top:2px}.timeline-details{margin-top:8px;white-space:normal}.timeline-empty{padding:28px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc}.repair-history-click-row{cursor:pointer}.repair-history-click-row:hover td{background:#f4fbf6!important}.parts-replaced-mini-table-wrap{min-width:520px}.parts-replaced-mini-table th{background:#eaf8ef!important;color:#212529!important;font-size:.78rem;white-space:nowrap}.parts-replaced-mini-table td{font-size:.84rem;white-space:normal;vertical-align:top}
#repairWorkflowModal .modal-content{border-radius:14px;overflow:hidden}#repairWorkflowModal .modal-header{background:#16894f;color:#fff;border-bottom:0}#repairWorkflowModal .btn-close{filter:invert(1) grayscale(100%) brightness(200%);opacity:.95}#repairWorkflowModal .modal-body{background:#f8fafc;max-height:76vh;overflow-y:auto}


.clickable-fuel-row { cursor: pointer; }
.clickable-fuel-row:hover td { background: #ecfdf5 !important; }
#fuelRecordDetailsModal .detail-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (max-width: 768px) { #fuelRecordDetailsModal .detail-info-grid { grid-template-columns: 1fr; } }

.repair-progress-mini-table-wrap {
    min-width: 420px;
}
.repair-progress-mini-table th {
    background: #eaf8ef !important;
    color: #212529 !important;
    font-size: .78rem;
    white-space: nowrap;
}
.repair-progress-mini-table td {
    font-size: .82rem;
    white-space: normal;
    vertical-align: top;
}



/* Repair payment modal scroll fix. The form wraps the modal body, so Bootstrap's default scrollable selector needs support. */
#repairPaymentModal .modal-dialog{
    height:calc(100vh - 1.75rem);
    max-height:calc(100vh - 1.75rem);
}
#repairPaymentModal .modal-content{
    max-height:100%;
    overflow:hidden;
}
#repairPaymentModal #repairPaymentForm{
    display:flex;
    flex-direction:column;
    max-height:100%;
    min-height:0;
}
#repairPaymentModal .modal-body{
    overflow-y:auto!important;
    min-height:0;
    max-height:calc(100vh - 185px);
}
@media(max-width:576px){
    #repairPaymentModal .modal-dialog{
        height:calc(100vh - .75rem);
        max-height:calc(100vh - .75rem);
        margin:.35rem;
    }
    #repairPaymentModal .modal-body{
        max-height:calc(100vh - 170px);
    }
}



/* Stronger Repair Payment modal scroll fix */
#repairPaymentModal .modal-dialog{
    height:auto!important;
    max-height:calc(100vh - 1rem)!important;
    margin-top:.5rem!important;
    margin-bottom:.5rem!important;
}
#repairPaymentModal .modal-content{
    display:flex!important;
    flex-direction:column!important;
    max-height:calc(100vh - 1rem)!important;
    overflow:hidden!important;
}
#repairPaymentModal #repairPaymentForm{
    display:flex!important;
    flex-direction:column!important;
    flex:1 1 auto!important;
    min-height:0!important;
    overflow:hidden!important;
}
#repairPaymentModal .modal-header,
#repairPaymentModal .modal-footer{
    flex:0 0 auto!important;
}
#repairPaymentModal .modal-body{
    flex:1 1 auto!important;
    overflow-y:auto!important;
    overflow-x:hidden!important;
    max-height:calc(100vh - 210px)!important;
    min-height:0!important;
    -webkit-overflow-scrolling:touch;
}
@media(max-width:576px){
    #repairPaymentModal .modal-dialog{
        max-height:calc(100vh - .5rem)!important;
        margin:.25rem!important;
    }
    #repairPaymentModal .modal-content{
        max-height:calc(100vh - .5rem)!important;
    }
    #repairPaymentModal .modal-body{
        max-height:calc(100vh - 190px)!important;
    }
}


/* Repair Backlog modal scroll fix.
   The form is inside .modal-content, so this keeps the header/footer fixed
   and makes only the form body scroll on desktop and mobile. */
#repairBacklogModal .modal-dialog{
    height:auto!important;
    max-height:calc(100vh - 1rem)!important;
    margin-top:.5rem!important;
    margin-bottom:.5rem!important;
}
#repairBacklogModal .modal-content{
    display:flex!important;
    flex-direction:column!important;
    max-height:calc(100vh - 1rem)!important;
    overflow:hidden!important;
    border-radius:14px;
}
#repairBacklogModal #repairBacklogForm{
    display:flex!important;
    flex-direction:column!important;
    flex:1 1 auto!important;
    min-height:0!important;
    overflow:hidden!important;
}
#repairBacklogModal .modal-header,
#repairBacklogModal .modal-footer{
    flex:0 0 auto!important;
}
#repairBacklogModal .modal-body{
    flex:1 1 auto!important;
    overflow-y:auto!important;
    overflow-x:hidden!important;
    max-height:calc(100vh - 190px)!important;
    min-height:0!important;
    -webkit-overflow-scrolling:touch;
}
#repairBacklogModal .detail-info-grid{
    grid-template-columns:repeat(2, minmax(0, 1fr));
}
@media(max-width:576px){
    #repairBacklogModal .modal-dialog{
        max-height:calc(100vh - .5rem)!important;
        margin:.25rem!important;
    }
    #repairBacklogModal .modal-content{
        max-height:calc(100vh - .5rem)!important;
    }
    #repairBacklogModal .modal-body{
        max-height:calc(100vh - 175px)!important;
        padding:14px!important;
    }
    #repairBacklogModal .modal-footer{
        gap:8px;
    }
    #repairBacklogModal .modal-footer .btn{
        width:100%;
    }
    #repairBacklogModal .detail-info-grid{
        grid-template-columns:1fr;
    }
}


/* Returned RIS Fix & Resubmit modal scroll and auto-filled fields */
#resubmitReturnedRisModal .modal-dialog{
    max-width: 820px;
}
#resubmitReturnedRisModal .modal-content{
    border-radius: 16px;
    overflow: hidden;
}
#resubmitReturnedRisModal form{
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 3.5rem);
}
#resubmitReturnedRisModal .modal-body{
    overflow-y: auto;
    max-height: calc(100vh - 13rem);
    background: #f8fafc;
}
#resubmitReturnedRisModal .modal-footer,
#resubmitReturnedRisModal .modal-header{
    flex-shrink: 0;
}
#returnedRisNumber{
    white-space: nowrap;
    font-weight: 700;
    letter-spacing: .2px;
}
@media (max-width: 576px){
    #resubmitReturnedRisModal .modal-dialog{
        margin: .5rem;
    }
    #resubmitReturnedRisModal form{
        max-height: calc(100vh - 1rem);
    }
    #resubmitReturnedRisModal .modal-body{
        max-height: calc(100vh - 12rem);
    }
}


.branch-parts-choice{
    display:flex;
    gap:10px;
    align-items:flex-start;
    width:100%;
    height:100%;
    padding:12px 14px;
    border:1px solid #d9e5dd;
    border-radius:12px;
    background:#fff;
    cursor:pointer;
    transition:.2s ease;
}
.branch-parts-choice input{ margin-top:3px; accent-color:#047857; }
.branch-parts-choice strong{ display:block; color:#052A47; font-size:.9rem; }
.branch-parts-choice small{ display:block; color:#6b7280; line-height:1.35; margin-top:2px; }
.branch-parts-choice.active{ border-color:#44D34E; box-shadow:0 0 0 3px rgba(68,211,78,.14); }

.branch-parts-selector-panel{display:none;border:1px solid #d9e5dd;border-radius:12px;background:#fff;padding:12px;margin-top:12px;}
.branch-parts-selector-panel.show{display:block;}
.branch-parts-selector-title{font-weight:700;color:#052A47;font-size:.92rem;margin-bottom:8px;}
.approval-branch-part-check{accent-color:#047857;transform:scale(1.05);}
.approval-branch-selected-row{background:rgba(68,211,78,.08)!important;}
.branch-selected-summary{font-size:.9rem;color:#052A47;background:#f1fbf3;border:1px solid rgba(68,211,78,.45);border-radius:10px;padding:10px 12px;margin-top:10px;}



/* Write Check style modal for Motorpool Repair Payment */
#repairPaymentModal .modal-dialog{
    max-width:min(1180px, calc(100vw - 24px));
}
#repairPaymentModal .modal-content.repair-write-check-modal{
    border:0;
    border-radius:18px;
    overflow:hidden;
    background:#fff;
    box-shadow:0 24px 70px rgba(5,42,71,.22);
}
#repairPaymentModal .modal-header.qb-toolbar{
    background:linear-gradient(135deg,#047857 0%,#44D34E 100%);
    color:#fff;
    border:0;
    padding:1rem 1.25rem;
}
#repairPaymentModal .modal-header.qb-toolbar .modal-title,
#repairPaymentModal .modal-header.qb-toolbar .modal-title i{color:#fff;}
#repairPaymentModal .modal-body.qb-body{
    background:#f8fafc;
    padding:1rem 1.25rem 1.25rem;
}
#repairPaymentModal .qb-topbar{
    display:flex;
    align-items:stretch;
    gap:1rem;
    margin-bottom:1rem;
}
#repairPaymentModal .qb-bank-wrap,
#repairPaymentModal .qb-ending-balance{
    background:#fff;
    border:1px solid rgba(5,42,71,.10);
    border-radius:14px;
    padding:.85rem 1rem;
    box-shadow:0 8px 24px rgba(5,42,71,.06);
}
#repairPaymentModal .qb-bank-wrap{flex:1;}
#repairPaymentModal .qb-ending-balance{min-width:220px;}
#repairPaymentModal .qb-bank-wrap label,
#repairPaymentModal .qb-ending-balance label,
#repairPaymentModal .qb-check-panel label{
    display:block;
    font-size:.72rem;
    font-weight:800;
    letter-spacing:.04em;
    color:#052A47;
    margin-bottom:.35rem;
    text-transform:uppercase;
}
#repairPaymentModal .qb-ending-balance strong{
    color:#047857;
    font-size:1.25rem;
}
#repairPaymentModal .qb-check-panel{
    position:relative;
    display:grid;
    grid-template-columns:minmax(0,1fr) 240px;
    gap:1rem;
    background:#fff;
    border:1px solid rgba(5,42,71,.10);
    border-radius:16px;
    padding:1rem;
    box-shadow:0 8px 24px rgba(5,42,71,.06);
}
#repairPaymentModal .qb-check-left{min-width:0;}
#repairPaymentModal .qb-check-right{display:grid;gap:.75rem;align-content:start;}
#repairPaymentModal .qb-payee-row{display:grid;grid-template-columns:180px minmax(0,1fr);gap:.75rem;align-items:center;margin-bottom:.65rem;}
#repairPaymentModal .qb-dollars-line{
    min-height:38px;
    display:flex;
    align-items:center;
    border-bottom:1px solid rgba(5,42,71,.16);
    color:#052A47;
    font-weight:700;
    margin-bottom:.65rem;
}
#repairPaymentModal .qb-address-row,
#repairPaymentModal .qb-memo-row{
    display:grid;
    grid-template-columns:180px minmax(0,1fr);
    gap:.75rem;
    align-items:center;
    margin-bottom:.65rem;
}
#repairPaymentModal .qb-mini-field{display:grid;grid-template-columns:52px minmax(0,1fr);gap:.5rem;align-items:center;}
#repairPaymentModal .qb-amount-field .form-control{font-weight:800;font-size:1.05rem;text-align:right;}
#repairPaymentModal .qb-check-attachment{grid-column:1 / -1;}
#repairPaymentModal .withdrawal-attachment-card{
    border:1px dashed rgba(4,120,87,.35);
    border-radius:14px;
    background:#f8fff9;
    padding:.85rem;
}
#repairPaymentModal .withdrawal-attach-inner{display:grid;gap:.45rem;}
#repairPaymentModal .withdrawal-attach-title{font-weight:800;color:#047857;}
#repairPaymentModal .withdrawal-attachment-help{font-size:.82rem;color:#64748b;}
#repairPaymentModal .withdrawal-attachment-preview{display:flex;gap:.45rem;flex-wrap:wrap;}
#repairPaymentModal .withdrawal-attachment-chip{
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    border:1px solid rgba(4,120,87,.18);
    background:#fff;
    color:#052A47;
    border-radius:999px;
    padding:.3rem .65rem;
    font-size:.82rem;
}
#repairPaymentModal .qb-tabs{display:flex;gap:.45rem;margin-top:1rem;}
#repairPaymentModal .qb-tab{
    border:1px solid rgba(5,42,71,.12);
    background:#fff;
    color:#052A47;
    border-radius:12px 12px 0 0;
    padding:.65rem 1rem;
    font-weight:800;
}
#repairPaymentModal .qb-tab.active{background:#047857;color:#fff;border-color:#047857;}
#repairPaymentModal .qb-tab-disabled{opacity:.55;cursor:not-allowed;}
#repairPaymentModal .qb-tab-panel{background:#fff;border:1px solid rgba(5,42,71,.10);border-radius:0 14px 14px 14px;overflow:hidden;}
#repairPaymentModal .qb-expense-table-wrap{overflow:auto;max-height:320px;}
#repairPaymentModal .qb-expense-table thead th{
    background:#e8f5e9;
    color:#047857;
    border-color:rgba(4,120,87,.18);
    font-size:.78rem;
    letter-spacing:.03em;
}
#repairPaymentModal .qb-expense-table tbody td{border-color:rgba(5,42,71,.08);vertical-align:middle;}
#repairPaymentModal .qb-table-select,
#repairPaymentModal .qb-table-input{border:0!important;background:transparent!important;box-shadow:none!important;border-radius:0!important;}
#repairPaymentModal .qb-footer{
    background:#fff;
    border-top:1px solid rgba(5,42,71,.10);
    padding:1rem 1.25rem;
}
#repairPaymentModal .btn-amgc-primary{
    background:#047857;
    border-color:#047857;
    color:#fff;
    font-weight:700;
}
#repairPaymentModal .btn-amgc-primary:hover{background:#036847;border-color:#036847;color:#fff;}
@media (max-width: 768px){
    #repairPaymentModal .qb-topbar,
    #repairPaymentModal .qb-check-panel,
    #repairPaymentModal .qb-payee-row,
    #repairPaymentModal .qb-address-row,
    #repairPaymentModal .qb-memo-row{display:block;}
    #repairPaymentModal .qb-ending-balance{min-width:0;}
    #repairPaymentModal .qb-check-right{margin-top:.75rem;}
    #repairPaymentModal .qb-mini-field{grid-template-columns:1fr;}
}

/* EXACT Withdrawal / Write Checks UI for Motorpool Repair Payment */
#repairPaymentModal .modal-dialog{
    width:min(96vw,1500px)!important;
    max-width:min(96vw,1500px)!important;
    margin:1rem auto!important;
}
#repairPaymentModal .modal-content.repair-write-check-modal{
    border:0!important;
    border-radius:0!important;
    overflow:hidden!important;
    background:#ffffff!important;
    box-shadow:0 18px 45px rgba(5,42,71,.22)!important;
    max-height:94vh!important;
}
#repairPaymentModal #repairPaymentForm{
    display:flex!important;
    flex-direction:column!important;
    max-height:94vh!important;
    min-height:0!important;
    background:#fff!important;
}
#repairPaymentModal .modal-body.qb-body{
    position:relative!important;
    background:#f2f2f2!important;
    padding:.65rem!important;
    overflow:auto!important;
    max-height:calc(94vh - 72px)!important;
    font-family:Arial, Helvetica, sans-serif!important;
}
#repairPaymentModal .repair-write-check-close{
    position:absolute!important;
    top:6px!important;
    right:10px!important;
    z-index:5!important;
    width:26px!important;
    height:26px!important;
    border:0!important;
    background:transparent!important;
    color:#64748b!important;
    font-size:22px!important;
    line-height:1!important;
}
#repairPaymentModal .qb-topbar{
    display:grid!important;
    grid-template-columns:minmax(420px,1fr) 340px!important;
    gap:1rem!important;
    align-items:center!important;
    margin:0 0 .45rem!important;
    padding:0!important;
    background:#ffffff!important;
}
#repairPaymentModal .qb-bank-wrap,
#repairPaymentModal .qb-ending-balance{
    background:#f2f2f2!important;
    border:0!important;
    border-radius:0!important;
    min-height:34px!important;
    padding:3px 4px!important;
    box-shadow:none!important;
    display:grid!important;
    align-items:center!important;
}
#repairPaymentModal .qb-bank-wrap{
    grid-template-columns:120px minmax(0,1fr)!important;
}
#repairPaymentModal .qb-ending-balance{
    grid-template-columns:135px minmax(0,1fr)!important;
}
#repairPaymentModal .qb-bank-wrap label,
#repairPaymentModal .qb-ending-balance label{
    margin:0!important;
    color:#334155!important;
    font-size:13px!important;
    font-weight:500!important;
    text-transform:uppercase!important;
}
#repairPaymentModal .qb-bank-wrap select{
    width:100%!important;
    min-width:250px!important;
    height:23px!important;
    min-height:23px!important;
    border:1px solid #bfc4ca!important;
    border-radius:2px!important;
    background:#fff!important;
    color:#111827!important;
    padding:1px 6px!important;
    font-size:13px!important;
    font-weight:400!important;
    line-height:1.2!important;
    box-shadow:none!important;
    outline:none!important;
}
#repairPaymentModal .qb-bank-wrap select:focus{
    border-color:#44D34E!important;
    box-shadow:0 0 0 1px rgba(68,211,78,.35)!important;
}
#repairPaymentModal .qb-ending-balance strong{
    background:#ffffff!important;
    min-height:23px!important;
    height:23px!important;
    display:flex!important;
    align-items:center!important;
    padding:1px 8px!important;
    color:#111!important;
    font-size:16px!important;
    font-weight:700!important;
    border:1px solid #e5e7eb!important;
    border-radius:0!important;
}
#repairPaymentModal .qb-check-panel{
    position:relative!important;
    display:grid!important;
    grid-template-columns:minmax(0,1fr) 260px 320px!important;
    gap:18px!important;
    min-height:280px!important;
    border:2px solid #c1dcbc!important;
    outline:1px solid #f1fbee!important;
    border-radius:0!important;
    background-color:#fcfffb!important;
    background-image:repeating-linear-gradient(45deg,rgba(150,190,155,.1) 0 1px,transparent 1px 6px),repeating-linear-gradient(-45deg,rgba(150,160,155,.08) 0 1px,transparent 1px 7px)!important;
    padding:42px 26px 18px!important;
    box-shadow:none!important;
}
#repairPaymentModal .qb-check-left{
    min-width:0!important;
    padding:0!important;
}
#repairPaymentModal .qb-check-right{
    width:auto!important;
    display:block!important;
}
#repairPaymentModal .qb-payee-row{
    display:grid!important;
    grid-template-columns:155px minmax(0,420px)!important;
    gap:10px!important;
    align-items:center!important;
    margin-bottom:18px!important;
}
#repairPaymentModal .qb-check-panel label{
    margin:0!important;
    font-size:14px!important;
    color:#3f454f!important;
    text-transform:uppercase!important;
    font-weight:400!important;
    line-height:24px!important;
}
#repairPaymentModal .qb-check-panel .form-control,
#repairPaymentModal .qb-check-panel .form-select{
    min-height:25px!important;
    height:25px!important;
    border:1px solid #c4c7cc!important;
    background:#eeeeee!important;
    border-radius:2px!important;
    padding:2px 6px!important;
    font-size:14px!important;
    color:#111!important;
    box-shadow:inset 0 1px 2px rgba(0,0,0,.08)!important;
}
#repairPaymentModal .withdrawal-payee-combo{
    position:relative!important;
    width:100%!important;
    max-width:420px!important;
}
#repairPaymentModal .withdrawal-payee-input{
    width:100%!important;
    height:25px!important;
    border:1px solid #c4c7cc!important;
    background:#eeeeee!important;
    color:#111827!important;
    font-size:14px!important;
    font-weight:400!important;
    outline:none!important;
    padding:2px 34px 2px 6px!important;
    border-radius:2px!important;
    box-shadow:inset 0 1px 2px rgba(0,0,0,.08)!important;
}
#repairPaymentModal .withdrawal-payee-toggle{
    position:absolute!important;
    right:6px!important;
    top:50%!important;
    transform:translateY(-50%)!important;
    width:24px!important;
    height:24px!important;
    border:0!important;
    background:transparent!important;
    color:#6c757d!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    cursor:default!important;
    border-radius:2px!important;
    z-index:5!important;
    padding:0!important;
    outline:none!important;
    box-shadow:none!important;
}
#repairPaymentModal .withdrawal-payee-toggle i{
    font-size:12px!important;
    line-height:1!important;
    pointer-events:none!important;
}
#repairPaymentModal .qb-dollars-line{
    height:28px!important;
    border-bottom:1px solid #4b5563!important;
    margin:0 92px 8px 0!important;
    display:flex!important;
    align-items:flex-end!important;
    justify-content:flex-start!important;
    padding:0 0 2px 0!important;
    color:#052A47!important;
    font-size:13px!important;
    line-height:1!important;
    text-transform:uppercase!important;
}
#repairPaymentModal .qb-amount-words{
    font-size:18px!important;
    font-weight:700!important;
    line-height:1.15!important;
    color:#052A47!important;
    text-transform:uppercase!important;
    white-space:nowrap!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
}
#repairPaymentModal .qb-address-row{
    display:grid!important;
    grid-template-columns:75px minmax(0,1fr)!important;
    gap:8px!important;
    align-items:center!important;
    margin-top:14px!important;
    margin-bottom:14px!important;
}
#repairPaymentModal .qb-memo-row{
    display:grid!important;
    grid-template-columns:56px minmax(0,1fr)!important;
    gap:8px!important;
    align-items:center!important;
    margin-top:8px!important;
}
#repairPaymentModal .qb-mini-field{
    display:grid!important;
    grid-template-columns:52px 1fr!important;
    gap:8px!important;
    align-items:center!important;
    margin-bottom:6px!important;
}
#repairPaymentModal .qb-amount-field label{
    text-align:right!important;
    padding-right:6px!important;
}
#repairPaymentModal .qb-amount-field .form-control{
    text-align:left!important;
    font-weight:400!important;
}
#repairPaymentModal .qb-check-attachment{
    grid-column:auto!important;
}
#repairPaymentModal .withdrawal-attachment-card{
    border:1px solid #c1dcbc!important;
    border-radius:0!important;
    background:rgba(255,255,255,.42)!important;
    padding:14px 16px!important;
    min-height:222px!important;
}
#repairPaymentModal .withdrawal-attachment-card > label{
    display:block!important;
    margin-bottom:14px!important;
    color:#052A47!important;
    font-size:16px!important;
    font-weight:700!important;
    text-transform:none!important;
}
#repairPaymentModal .withdrawal-attach-inner{
    border:1px dashed #d9e1e6!important;
    background:rgba(255,255,255,.5)!important;
    padding:18px 22px!important;
    display:grid!important;
    gap:10px!important;
}
#repairPaymentModal .withdrawal-attach-title{
    color:#334155!important;
    font-weight:700!important;
    font-size:14px!important;
}
#repairPaymentModal .withdrawal-attachment-help{
    color:#475569!important;
    font-size:13px!important;
    line-height:1.35!important;
}
#repairPaymentModal .withdrawal-attachment-preview{
    display:flex!important;
    flex-wrap:wrap!important;
    gap:6px!important;
}
#repairPaymentModal .withdrawal-attachment-chip{
    display:inline-flex!important;
    align-items:center!important;
    gap:5px!important;
    padding:4px 8px!important;
    border:1px solid #d9e1e6!important;
    background:#fff!important;
    font-size:12px!important;
}
#repairPaymentModal .qb-tabs{
    display:flex!important;
    align-items:flex-end!important;
    gap:0!important;
    margin-top:10px!important;
    border-bottom:1px solid #d9d9d9!important;
}
#repairPaymentModal .qb-tab{
    border:1px solid #bfc4ca!important;
    border-bottom:0!important;
    border-radius:0!important;
    background:#cfcfcf!important;
    color:#111!important;
    font-size:16px!important;
    font-weight:500!important;
    height:30px!important;
    line-height:18px!important;
    padding:5px 10px!important;
    margin:0 2px 0 0!important;
    min-width:130px!important;
    box-shadow:none!important;
}
#repairPaymentModal .qb-tab.active{
    background:#fff!important;
    color:#047857!important;
    font-weight:600!important;
    border-top:1px solid #bfc4ca!important;
}
#repairPaymentModal .qb-tab span{
    float:right!important;
    margin-left:25px!important;
    font-weight:400!important;
}
#repairPaymentModal .qb-tab-panel{
    display:none!important;
    background:#fff!important;
    border:0!important;
    border-radius:0!important;
    overflow:visible!important;
}
#repairPaymentModal .qb-tab-panel.active{
    display:block!important;
}
#repairPaymentModal .qb-expense-table-wrap{
    max-height:330px!important;
    overflow:auto!important;
    border:1px solid #d9d9d9!important;
    border-top:0!important;
    background:#fff!important;
}
#repairPaymentModal .qb-expense-table{
    width:100%!important;
    border-collapse:collapse!important;
    table-layout:fixed!important;
    margin:0!important;
}
#repairPaymentModal .qb-expense-table thead th{
    position:sticky!important;
    top:0!important;
    z-index:2!important;
    height:34px!important;
    background:#fff!important;
    color:#6b7280!important;
    text-transform:uppercase!important;
    font-size:14px!important;
    font-weight:500!important;
    border-right:1px solid #d8dde3!important;
    border-bottom:1px solid #9ca3af!important;
    padding:5px 7px!important;
    text-align:left!important;
}
#repairPaymentModal .qb-expense-table tbody tr:nth-child(odd) td{
    background:#fff!important;
}
#repairPaymentModal .qb-expense-table tbody tr:nth-child(even) td{
    background:#e8ffe7!important;
}
#repairPaymentModal .qb-expense-table tbody td{
    height:32px!important;
    border-right:1px solid #d8dde3!important;
    padding:0!important;
    vertical-align:middle!important;
}
#repairPaymentModal .qb-table-select,
#repairPaymentModal .qb-table-input,
#repairPaymentModal .qb-expense-table input,
#repairPaymentModal .qb-expense-table select{
    width:100%!important;
    height:25px!important;
    min-height:25px!important;
    border:0!important;
    background:transparent!important;
    border-radius:0!important;
    padding:2px 6px!important;
    font-size:14px!important;
    color:#111827!important;
    outline:none!important;
    box-shadow:none!important;
}
#repairPaymentModal .qb-table-select:focus,
#repairPaymentModal .qb-table-input:focus,
#repairPaymentModal .qb-expense-table input:focus,
#repairPaymentModal .qb-expense-table select:focus{
    background:#fff!important;
    box-shadow:inset 0 0 0 1px #58e84b!important;
}
#repairPaymentModal .repair-write-check-summary{
    display:none!important;
}
#repairPaymentModal .modal-footer.qb-footer{
    background:#f2f2f2!important;
    border-top:1px solid #d9d9d9!important;
    padding:.75rem 1rem!important;
    display:flex!important;
    justify-content:flex-end!important;
    gap:.65rem!important;
}
#repairPaymentModal .modal-footer.qb-footer .btn{
    border-radius:12px!important;
    min-width:125px!important;
    font-weight:700!important;
    padding:.45rem .8rem!important;
}
#repairPaymentModal .modal-footer.qb-footer #repairPaymentBranchSourceActualCostBtn{
    border-color:#047857;
    color:#047857;
    font-weight:700;
}
#repairPaymentModal .modal-footer.qb-footer #repairPaymentBranchSourceActualCostBtn:hover{
    background:#047857;
    color:#fff;
}
#repairPaymentModal .modal-footer.qb-footer .btn-amgc-primary{
    background:#16a34a!important;
    border-color:#16a34a!important;
    color:#fff!important;
}
#repairPaymentModal .modal-footer.qb-footer .btn-amgc-dark{
    background:#047857!important;
    border-color:#047857!important;
    color:#fff!important;
}
@media(max-width:1200px){
    #repairPaymentModal .qb-check-panel{
        grid-template-columns:1fr 260px!important;
    }
    #repairPaymentModal .qb-check-attachment{
        grid-column:1 / -1!important;
    }
}
@media(max-width:992px){
    #repairPaymentModal .qb-topbar{
        grid-template-columns:1fr!important;
    }
    #repairPaymentModal .qb-check-panel{
        grid-template-columns:1fr!important;
        padding:20px!important;
    }
    #repairPaymentModal .qb-check-right{
        width:100%!important;
        flex-basis:auto!important;
    }
}
@media(max-width:576px){
    #repairPaymentModal .modal-dialog{
        width:calc(100% - 1rem)!important;
        max-width:calc(100% - 1rem)!important;
    }
    #repairPaymentModal .qb-bank-wrap,
    #repairPaymentModal .qb-ending-balance,
    #repairPaymentModal .qb-payee-row,
    #repairPaymentModal .qb-memo-row,
    #repairPaymentModal .qb-mini-field{
        width:100%!important;
        grid-template-columns:1fr!important;
    }
    #repairPaymentModal .qb-dollars-line{
        margin-right:0!important;
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
                                <a class="nav-link active" href="motorpool.php">
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



<main class="main-content" id="mainContent">
    <div id="dashboardContent" class="page-content active">
        <div class="navbar-top">
            <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>Motorpool</h2>
                <p id="dashboardSubtitle">Registered Vehicle List / Profile</p>
            </div>
        </div>

        <?php if (!empty($motorpoolReturnedRequests)): ?>
        <div class="motorpool-approval-card">
            <div class="approval-title">
                <div>
                    <h5>Returned Motorpool RIS</h5>
                    <small class="text-muted">RIS requests returned by Motorpool because details or requirements are incomplete.</small>
                </div>
                <span class="badge bg-danger"><?php echo count($motorpoolReturnedRequests); ?> Returned</span>
            </div>

            <div class="alert alert-danger d-flex align-items-start mb-3">
                <i class="bi bi-arrow-counterclockwise me-2 mt-1"></i>
                <div>
                    Motorpool returned the RIS below. Please review the remarks, update what is needed, then coordinate with Motorpool before resubmitting.
                </div>
            </div>

            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>RIS No.</th>
                            <th>Date Requested</th>
                            <th>Plate No.</th>
                            <th>Vehicle Details</th>
                            <th>Return Remarks</th>
                            <th>Returned By</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($motorpoolReturnedRequests as $returned): ?>
                        <tr>
                            <td class="ris-number-cell"><strong><?php echo h($returned['ris_number'] ?? ''); ?></strong></td>
                            <td><?php echo h($returned['date_requested'] ?? ''); ?></td>
                            <td><strong><?php echo h($returned['plate_no'] ?? ''); ?></strong><br><small class="text-muted">Vehicle ID: <?php echo h($returned['vehicle_id'] ?? ''); ?></small></td>
                            <td><?php echo h($returned['vehicle_details'] ?? $returned['vehicle_category'] ?? ''); ?></td>
                            <td class="concern-cell"><?php echo nl2br(h($returned['motorpool_return_remarks'] ?? '')); ?></td>
                            <td><?php echo h(trim((string)($returned['returned_by_name'] ?? '')) !== '' ? $returned['returned_by_name'] : 'Motorpool'); ?><br><small class="text-muted"><?php echo h($returned['motorpool_returned_at'] ?? $returned['updated_at'] ?? ''); ?></small></td>
                            <td><span class="badge bg-danger">Returned</span></td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-success returned-ris-resubmit-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#resubmitReturnedRisModal"
                                        data-ris-id="<?php echo h($returned['ris_id'] ?? ''); ?>"
                                        data-ris-number="<?php echo h($returned['ris_number'] ?? ''); ?>"
                                        data-concerns="<?php echo h($returned['concerns'] ?? ''); ?>"
                                        data-endorsed-by="<?php echo h($returned['endorsed_by'] ?? $user_name); ?>"
                                        data-date-requested="<?php echo h($returned['date_requested'] ?? date('Y-m-d')); ?>"
                                        data-return-remarks="<?php echo h($returned['motorpool_return_remarks'] ?? ''); ?>">
                                    <i class="bi bi-send-check me-1"></i>Fix & Resubmit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="modal fade" id="resubmitReturnedRisModal" tabindex="-1" aria-labelledby="resubmitReturnedRisModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="POST" id="resubmitReturnedRisForm">
                        <input type="hidden" name="action" value="resubmit_returned_motorpool_ris">
                        <input type="hidden" name="returned_ris_id" id="returnedRisId">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="resubmitReturnedRisModalLabel">Fix & Resubmit Returned RIS</h5>
                                <small class="text-muted">Update the missing or incorrect details, then send the request back to Motorpool.</small>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger mb-3">
                                <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Motorpool Return Remarks</div>
                                <div id="returnedMotorpoolRemarks" style="white-space:pre-wrap;"></div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">RIS No.</label>
                                    <input type="text" class="form-control" id="returnedRisNumber" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Date Requested</label>
                                    <input type="date" class="form-control" name="returned_date_requested" id="returnedDateRequested" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Endorsed By</label>
                                    <input type="text" class="form-control" name="returned_endorsed_by" id="returnedEndorsedBy" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Corrected Concern/s</label>
                                    <textarea class="form-control" name="returned_concerns" id="returnedConcerns" rows="5" required></textarea>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Branch Remarks / What was fixed</label>
                                    <textarea class="form-control" name="branch_resubmission_remarks" id="branchResubmissionRemarks" rows="3" placeholder="Example: Added missing attachment details / corrected concern description."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-send-check me-1"></i>Resubmit to Motorpool
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!empty($motorpoolApprovalRequests)): ?>
        <div class="motorpool-approval-card">
            <div class="approval-title">
                <div>
                    <h5>Motorpool Assessments for Approval</h5>
                    <small class="text-muted">Approve the repairs and parts needed submitted by the Motorpool account.</small>
                </div>
                <span class="badge bg-success"><?php echo count($motorpoolApprovalRequests); ?> Pending Approval</span>
            </div>

            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>RIS No.</th>
                            <th>Date Requested</th>
                            <th>Plate No.</th>
                            <th>Vehicle Details</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($motorpoolApprovalRequests as $approval):
                        $approvalPayload = h(json_encode($approval, JSON_HEX_APOS | JSON_HEX_QUOT));
                    ?>
                        <tr class="approval-row" data-approval='<?php echo $approvalPayload; ?>' onclick="openBranchApprovalModal(this)">
                            <td><strong><?php echo h($approval['ris_number'] ?? ''); ?></strong></td>
                            <td><?php echo h($approval['date_requested'] ?? ''); ?></td>
                            <td><strong><?php echo h($approval['plate_no'] ?? ''); ?></strong><br><small class="text-muted">Vehicle ID: <?php echo h($approval['vehicle_id'] ?? ''); ?></small></td>
                            <td><?php echo h($approval['vehicle_details'] ?? $approval['vehicle_category'] ?? ''); ?></td>
                            <td><?php echo branchApprovalBadge($approval['branch_approval_status'] ?? 'Pending'); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-success btn-sm btn-action-text" onclick="event.stopPropagation(); openBranchApprovalModal(this.closest('tr'))">
                                    <i class="bi bi-check2-square me-1"></i>Review
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($motorpoolForReleaseRequests)): ?>
        <div class="motorpool-approval-card">
            <div class="approval-title">
                <div>
                    <h5>Motorpool Repairs for Release</h5>
                    <small class="text-muted">RIS requests from your branch that passed quality check and are still pending release.</small>
                </div>
                <span class="badge bg-info text-dark"><?php echo count($motorpoolForReleaseRequests); ?> For Release</span>
            </div>


            <div class="alert alert-warning d-flex align-items-center mb-3">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div>
                    This vehicle is ready for release. Please proceed to the Motorpool Department for vehicle turnover and release processing.
                </div>
            </div>

            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>RIS No.</th>
                            <th>Date Requested</th>
                            <th>Plate No.</th>
                            <th>Vehicle Details</th>
                            <th>Quality Check By</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($motorpoolForReleaseRequests as $release):
                        $releasePayload = h(json_encode($release, JSON_HEX_APOS | JSON_HEX_QUOT));
                    ?>
                        <tr class="approval-row" data-release='<?php echo $releasePayload; ?>' onclick="openBranchForReleaseModal(this)">
                            <td><strong><?php echo h($release['ris_number'] ?? ''); ?></strong></td>
                            <td><?php echo h($release['date_requested'] ?? ''); ?></td>
                            <td><strong><?php echo h($release['plate_no'] ?? ''); ?></strong><br><small class="text-muted">Vehicle ID: <?php echo h($release['vehicle_id'] ?? ''); ?></small></td>
                            <td><?php echo h($release['vehicle_details'] ?? $release['vehicle_category'] ?? ''); ?></td>
                            <td><?php echo h($release['quality_check_by'] ?? ''); ?><br><small class="text-muted"><?php echo h($release['quality_check_datetime'] ?? ''); ?></small></td>
                            <td><span class="badge bg-success">For Release</span></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-success btn-sm btn-action-text" onclick="event.stopPropagation(); openBranchForReleaseModal(this.closest('tr'))">
                                    <i class="bi bi-truck me-1"></i>View Release
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="vehicle-toolbar">
                <div>
                    <h5 class="mb-1">Registered Vehicles</h5>
                    <small class="text-muted">All registered motorpool vehicles are listed here.</small>
                </div>
                <button type="button" class="btn btn-success btn-action-text" onclick="openVehicleModal(); return false;" id="addVehicleBtn">
                    <i class="bi bi-plus-circle me-1"></i>Add Vehicle
                </button>
            </div>

            <?php if (!$vehicle_table_exists): ?>
                <div class="alert alert-warning mb-3">
                    The <strong>motorpool_vehicles</strong> table could not be created. Please check your database permissions.
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle">
                    <thead>
                        <tr>
                            <th class="col-image">Image</th>
                            <th>Plate No.</th>
                            <th>Make/Brand</th>
                            <th>Vehicle Type</th>
                            <th>Category</th>
                            <th>Color</th>
                            <th>Year Model</th>
                            <th class="action-col text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($vehicles)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No registered vehicles found.</td></tr>
                    <?php else: foreach ($vehicles as $vehicle):
                        $vehicleDbId = (int)($vehicle['id'] ?? 0);
                        $vehicleId = v($vehicle, $vehicle_columns, ['vehicle_id','vehicle_code','vehicle_no','id']);
                        $plateNo = v($vehicle, $vehicle_columns, ['plate_no','plate_number']);
                        $makeBrand = v($vehicle, $vehicle_columns, ['make_brand','make','brand']);
                        $vehicleType = v($vehicle, $vehicle_columns, ['vehicle_type','type']);
                        $vehicleCategory = v($vehicle, $vehicle_columns, ['vehicle_category','category']);
                        $color = v($vehicle, $vehicle_columns, ['color']);
                        $yearModel = v($vehicle, $vehicle_columns, ['year_model']);
                        $vehicleImage = v($vehicle, $vehicle_columns, ['vehicle_image']);
                        $dataAttrs = ' data-db-id="' . h($vehicleDbId) . '"';
                        foreach ($fieldMap as $formField => $choices) {
                            $dataAttrs .= ' data-' . h(str_replace('_','-', $formField)) . '="' . h(v($vehicle, $vehicle_columns, $choices)) . '"';
                        }
                        $repairHistoryJson = json_encode($vehicleRepairHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT);
                        $dataAttrs .= ' data-repair-history="' . h($repairHistoryJson) . '"';
                        $repairPaymentHistoryJson = json_encode($vehicleRepairPaymentHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT);
                        $dataAttrs .= ' data-repair-payment-history="' . h($repairPaymentHistoryJson) . '"';
                        $workflowHistoryJson = json_encode($vehicleWorkflowHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT);
                        $dataAttrs .= ' data-workflow-history="' . h($workflowHistoryJson) . '"';
                        $registrationHistoryJson = json_encode($vehicleRegistrationHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT);
                        $dataAttrs .= ' data-registration-history="' . h($registrationHistoryJson) . '"';
                        $fuelHistoryJson = json_encode($vehicleFuelHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT);
                        $dataAttrs .= ' data-fuel-history="' . h($fuelHistoryJson) . '"';
                    ?>
                        <tr class="vehicle-click-row js-view-vehicle" data-row-purpose="vehicle-profile" onclick="return branchAdminVehicleRowInlineClick(event, this)"<?= $dataAttrs; ?>>
                            <td class="col-image"><?= motorpoolImageCell($vehicleImage, $plateNo); ?></td>
                            <td><strong><?= h($plateNo); ?></strong><br><small class="text-muted">Vehicle ID: <?= h($vehicleId); ?></small></td>
                            <td><?= h($makeBrand); ?></td>
                            <td><?= h($vehicleType); ?></td>
                            <td><?= h($vehicleCategory); ?></td>
                            <td><?= h($color); ?></td>
                            <td><?= h($yearModel); ?></td>
                            <td class="action-col text-end">
                                <button type="button" class="btn btn-success btn-sm btn-action-text me-1" onclick="event.stopPropagation(); openRisModal(this)"><i class="bi bi-clipboard-check me-1"></i>RIS</button>
                                <button type="button" class="btn btn-outline-success btn-sm btn-action-text" onclick="event.stopPropagation(); openFuelMonitoringModal(this)"><i class="bi bi-fuel-pump me-1"></i>Refuel</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>


<div class="modal fade" id="branchApprovalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="branchApprovalForm">
                <input type="hidden" name="action" value="branch_review_motorpool_assessment">
                <input type="hidden" name="approval_ris_id" id="approvalRisId">
                <input type="hidden" name="approval_decision" id="approvalDecision" value="approved">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i><span id="approvalModalTitle">Assessment for Approval</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="approval-info-grid" id="approvalInfoGrid"></div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Original Concern/s</label>
                        <textarea class="form-control" id="approvalConcerns" rows="3" readonly></textarea>
                    </div>

                    <div class="assessment-view-box">
                        <h6><i class="bi bi-tools me-1"></i>Repairs to Make and Parts Needed</h6>
                        <div id="approvalAssessmentView"></div>
                    </div>

                    <div class="branch-parts-choice-box border rounded bg-light p-3 mb-3">
                        <label class="form-label fw-bold mb-2">Who will source the parts?</label>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="branch-parts-choice active" id="partsChoiceMotorpoolLabel">
                                    <input type="radio" name="parts_purchase_by" value="motorpool" id="partsPurchaseMotorpool" checked>
                                    <span><strong>Motorpool will source the parts</strong><small>Request will continue to For Parts Completion.</small></span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label class="branch-parts-choice" id="partsChoiceBranchLabel">
                                    <input type="radio" name="parts_purchase_by" value="branch" id="partsPurchaseBranch">
                                    <span><strong>Branch will source the parts</strong><small>Selected parts will be marked as pending branch source. Pricing can be updated later before expense posting.</small></span>
                                </label>
                            </div>
                        </div>
                        <div class="alert alert-success small mt-3 mb-0 d-none" id="branchPartsExpenseNotice">
                            <i class="bi bi-receipt-cutoff me-1"></i>
                            Only the checked item/s below will be marked as pending branch source. No expense will be posted yet because the final price may still change.
                        </div>
                        <div class="branch-parts-selector-panel" id="branchPartsSelectorPanel">
                            <div class="branch-parts-selector-title"><i class="bi bi-check2-square me-1"></i>Select item/s that Branch will source</div>
                            <div class="small text-muted mb-2">Check only the parts that the branch will source. These selected items will be set to zero Motorpool cost, but no expense will be posted until the actual price is final.</div>
                            <div id="branchPartsSelectedSummary" class="branch-selected-summary d-none"></div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold">Branch Remarks</label>
                        <textarea class="form-control" name="approval_remarks" id="approvalRemarks" rows="3" placeholder="Optional for approval, required if returned for revision"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-action-text" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-danger btn-action-text" onclick="submitBranchApproval('rejected')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Return for Revision
                    </button>
                    <button type="button" class="btn btn-success btn-action-text" onclick="submitBranchApproval('approved')">
                        <i class="bi bi-check-circle me-1"></i>Approve Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<div class="modal fade" id="branchForReleaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-truck me-2"></i><span id="branchForReleaseModalTitle">Motorpool For Release</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="approval-info-grid" id="branchForReleaseInfoGrid"></div>

                <div class="alert alert-warning d-flex align-items-center mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        This vehicle is ready for release. Please proceed to the Motorpool Department for vehicle turnover and release processing.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Original Concern/s</label>
                    <textarea class="form-control" id="branchForReleaseConcerns" rows="3" readonly></textarea>
                </div>

                <div class="assessment-view-box mb-3">
                    <h6><i class="bi bi-patch-check me-1"></i>Quality Check Details</h6>
                    <div id="branchForReleaseQualityView"></div>
                </div>

                <div class="assessment-view-box">
                    <h6><i class="bi bi-tools me-1"></i>Repairs and Parts Used</h6>
                    <div id="branchForReleaseRepairsView"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-action-text" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="vehicleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-xl-custom modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data" id="vehicleForm">
        <input type="hidden" name="action" id="vehicleFormAction" value="add_vehicle">
        <input type="hidden" name="vehicle_db_id" id="vehicle_db_id" value="">
        <div class="modal-header">
            <h5 class="modal-title" id="vehicleModalTitle"><i class="bi bi-truck-front me-2"></i>Add Vehicle Profile</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="motorpool-form-panel">
                <div class="motorpool-panel-title"><i class="bi bi-info-circle me-2"></i>Vehicle Information</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Plate No. <span class="required-mark">*</span></label>
                        <input class="form-control" name="plate_no" id="plate_no" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Make/Brand</label>
                        <input class="form-control" name="make_brand" id="make_brand">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Vehicle Type</label>
                        <input class="form-control" name="vehicle_type" id="vehicle_type">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Vehicle Category</label>
                        <input class="form-control" name="vehicle_category" id="vehicle_category">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Classification</label>
                        <input class="form-control" name="classification" id="classification">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Body Type</label>
                        <input class="form-control" name="body_type" id="body_type">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Color</label>
                        <input class="form-control" name="color" id="color">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Type of Fuel</label>
                        <input class="form-control" name="type_of_fuel" id="type_of_fuel">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Year Model</label>
                        <input class="form-control" name="year_model" id="year_model">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Series</label>
                        <input class="form-control" name="series" id="series">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Passenger Capacity</label>
                        <input class="form-control" name="passenger_capacity" id="passenger_capacity">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Max Power (KW)</label>
                        <input class="form-control" name="max_power_kw" id="max_power_kw">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">LTO CR No.</label>
                        <input class="form-control" name="lto_cr_no" id="lto_cr_no">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date of Registration</label>
                        <input type="date" class="form-control" name="date_registration" id="date_registration">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">File No.</label>
                        <input class="form-control" name="file_no" id="file_no">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Engine No.</label>
                        <input class="form-control" name="engine_no" id="engine_no">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Chassis No.</label>
                        <input class="form-control" name="chassis_no" id="chassis_no">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">VIN</label>
                        <input class="form-control" name="vin" id="vin">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Gross Weight</label>
                        <input class="form-control" name="gross_weight" id="gross_weight">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Net Weight</label>
                        <input class="form-control" name="net_weight" id="net_weight">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Year Rebuilt</label>
                        <input class="form-control" name="year_rebuilt" id="year_rebuilt">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Piston Displacement</label>
                        <input class="form-control" name="piston_displacement" id="piston_displacement">
                    </div>
                </div>
            </div>

            <div class="motorpool-form-panel mb-0">
                <div class="motorpool-panel-title"><i class="bi bi-card-checklist me-2"></i>Registration Information</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">OR No. <span class="required-mark">*</span></label>
                        <input class="form-control" name="or_no" id="or_no">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Registration History Date <span class="required-mark">*</span></label>
                        <input type="date" class="form-control" name="reg_date" id="reg_date">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Next Renewal <span class="required-mark">*</span></label>
                        <input type="date" class="form-control" name="next_renewal" id="next_renewal">
                    </div>
                </div>
            </div>

            <div class="motorpool-form-panel mb-0">
                <div class="motorpool-panel-title"><i class="bi bi-paperclip me-2"></i>Attachments</div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Vehicle Image</label>
                        <input type="file" class="form-control" name="vehicle_image" id="vehicle_image" accept="image/*,.pdf">
                        <div class="file-hint mt-1">Upload image of the vehicle only.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">CR Image/s</label>
                        <input type="file" class="form-control" name="cr_vehicle_images[]" accept="image/*,.pdf" multiple>
                        <div class="file-hint mt-1">Upload CR image/s or PDF only.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">OR Attachment <span class="required-mark">*</span></label>
                        <input type="file" class="form-control" name="or_attachment" id="or_attachment" accept="image/*,.pdf">
                        <div class="file-hint mt-1">Upload OR image or PDF only.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-success" id="vehicleSaveBtn" onclick="saveVehicle()"><i class="bi bi-save me-1"></i>Save Vehicle</button>
        </div>
      </form>
    </div>
  </div>
</div>


<div class="modal fade" id="vehicleDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:95%;">
    <div class="modal-content">
      <div class="modal-header bg-white sticky-top" style="z-index:10;border-bottom:1px solid #dee2e6;">
        <h5 class="modal-title"><i class="bi bi-truck-front me-2 text-success"></i>Vehicle Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="vehicle-detail-hero">
            <div class="vehicle-detail-image" id="detailVehicleImage"><i class="bi bi-image text-muted fs-1"></i></div>
            <div class="vehicle-detail-title">
                <h4 id="detailVehicleName">Vehicle</h4>
                <div class="mb-2"><span class="badge bg-success" id="detailPlateBadge">Plate No.</span></div>
                <div class="text-muted" id="detailVehicleSub">Vehicle information and registration records.</div>
            </div>
        </div>

        <ul class="nav nav-tabs vehicle-detail-tabs" id="vehicleDetailTabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#vehicleOverviewTab" type="button" role="tab"><i class="bi bi-info-circle me-1"></i>Vehicle</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleRegistrationTab" type="button" role="tab"><i class="bi bi-card-checklist me-1"></i>Registration</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleImagesTab" type="button" role="tab"><i class="bi bi-paperclip me-1"></i>Attachments</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleUsageTab" type="button" role="tab"><i class="bi bi-clock-history me-1"></i>Usage History</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleRepairTab" type="button" role="tab"><i class="bi bi-tools me-1"></i>Repair History</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleRepairPaymentsTab" type="button" role="tab"><i class="bi bi-cash-coin me-1"></i>Repair Payments</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleFuelMonitoringTab" type="button" role="tab"><i class="bi bi-fuel-pump me-1"></i>Fuel Monitoring</button></li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white">
            <div class="tab-pane fade show active" id="vehicleOverviewTab" role="tabpanel"><div class="detail-info-grid" id="overviewDetailsGrid"></div></div>
            <div class="tab-pane fade" id="vehicleRegistrationTab" role="tabpanel">
                <div class="mb-2 text-muted" style="font-size:.92rem;">Renewed registration records will appear here.</div>
                <div class="table-responsive">
                    <table class="table table-bordered history-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>OR No.</th>
                                <th>Registration History Date</th>
                                <th>Next Renewal</th>
                                <th>OR Attachment</th>
                                <th>Date Encoded</th>
                            </tr>
                        </thead>
                        <tbody id="registrationHistoryBody">
                            <tr><td colspan="5" class="text-muted text-center py-3">No registration history found.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="tab-pane fade" id="vehicleImagesTab" role="tabpanel"><div class="vehicle-image-preview-wrap" id="vehicleImagesGrid"></div></div>
            <div class="tab-pane fade" id="vehicleUsageTab" role="tabpanel"><div class="table-responsive"><table class="table table-bordered history-table mb-0"><thead><tr><th>Date</th><th>Transaction</th><th>Business Unit</th><th>Branch</th><th>Customer</th><th>Driver</th><th>Starting Odometer</th><th>Ending Odometer</th></tr></thead><tbody><tr><td colspan="10" class="text-muted text-center py-3">Usage history will appear here once available.</td></tr></tbody></table></div></div>
            <div class="tab-pane fade" id="vehicleRepairTab" role="tabpanel"><div class="table-responsive"><table class="table table-bordered history-table repair-history-main-table mb-0 align-middle"><thead><tr><th>Repair Date</th><th>RIS No.</th><th>Repairs Done</th><th>Parts Replaced / Used</th><th>Mechanic</th><th>Grand Total</th><th>Attachment/s</th><th>Action</th></tr></thead><tbody id="detailRepairHistoryBody"><tr><td colspan="8" class="text-muted text-center py-3">Repair history will appear here once available.</td></tr></tbody></table></div></div>
            <div class="tab-pane fade" id="vehicleRepairPaymentsTab" role="tabpanel"><div class="mb-2 text-muted" style="font-size:.92rem;">Completed repair costs can be paid here even after the vehicle is already in Repair History.</div><div class="table-responsive"><table class="table table-bordered history-table mb-0 align-middle"><thead><tr><th>RIS No.</th><th>Repair Date</th><th>Total Cost</th><th>Payment Status</th><th>Amount Paid</th><th>Balance</th><th>Action</th></tr></thead><tbody id="detailRepairPaymentsBody"><tr><td colspan="7" class="text-muted text-center py-3">Repair payment records will appear here once available.</td></tr></tbody></table></div></div>
            <div class="tab-pane fade" id="vehicleFuelMonitoringTab" role="tabpanel"><div class="table-responsive"><table class="table table-bordered history-table mb-0"><thead><tr><th>Date</th><th>Refuel (Liters)</th><th>Current Odometer Reading</th><th>Previous Odometer Reading</th><th>Distance Covered (km)</th><th>Liters Consumed</th><th>Fuel Efficiency (km/L)</th></tr></thead><tbody id="detailFuelMonitoringBody"><tr><td colspan="7" class="text-muted text-center py-3">Fuel monitoring records will appear here once available.</td></tr></tbody></table></div></div>
        </div>
      </div>
      <div class="modal-footer bg-white sticky-bottom" style="border-top:1px solid #dee2e6;z-index:10;">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-success" onclick="openRenewRegistrationModal()"><i class="bi bi-arrow-repeat me-1"></i>Renew Registration</button>
        <button type="button" class="btn btn-success" onclick="openEditVehicleFromDetails()"><i class="bi bi-pencil-square me-1"></i>Edit Vehicle</button>
      </div>
    </div>
  </div>
</div>



<div class="modal fade" id="repairBacklogModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="repairBacklogForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_repair_backlog">
        <input type="hidden" name="backlog_repair_id" id="backlogRepairId">
        <input type="hidden" name="backlog_ris_id" id="backlogRisId">
        <input type="hidden" name="backlog_ris_number" id="backlogRisNumber">
        <input type="hidden" name="backlog_vehicle_db_id" id="backlogVehicleDbId">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Repair Backlog</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info mb-3">
            This will create a repair backlog using the same original RIS as reference and send it back to the Motorpool request handler.
          </div>
          <div class="detail-info-grid mb-3">
            <div class="detail-info-item"><small>Original RIS No.</small><strong id="backlogDisplayRisNo">-</strong></div>
            <div class="detail-info-item"><small>Plate No.</small><strong id="backlogDisplayPlateNo">-</strong></div>
            <div class="detail-info-item"><small>Repair Date</small><strong id="backlogDisplayRepairDate">-</strong></div>
            <div class="detail-info-item"><small>Mechanic</small><strong id="backlogDisplayMechanic">-</strong></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Backlog Date</label>
            <input type="date" class="form-control" name="backlog_date" id="backlogDate" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Damaged Again / Issue</label>
            <textarea class="form-control" name="backlog_problem_description" id="backlogProblemDescription" rows="4" placeholder="Describe what was damaged again from the previous repair..." required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Remarks</label>
            <textarea class="form-control" name="backlog_remarks" id="backlogRemarks" rows="3" placeholder="Optional remarks"></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Attachment</label>
            <input type="file" class="form-control" name="backlog_attachment" id="backlogAttachment" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf">
          </div>
        </div>
        <div class="modal-footer bg-white">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i>Send Backlog to Motorpool</button>
        </div>
      </form>
    </div>
  </div>
</div>



<div class="modal fade" id="repairPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content repair-write-check-modal">
      <form id="repairPaymentForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_repair_payment">
        <input type="hidden" name="payment_ris_id" id="paymentRisId">
        <input type="hidden" name="payment_ris_number" id="paymentRisNumber">
        <input type="hidden" name="payment_vehicle_db_id" id="paymentVehicleDbId">
        <input type="hidden" name="payment_repair_date" id="paymentRepairDate">
        <input type="hidden" name="payment_total_cost" id="paymentTotalCostValue">
        <input type="hidden" name="payment_scope" id="paymentScope" value="motorpool">
        <input type="hidden" name="payment_method" id="paymentMethod" value="check">
        <input type="hidden" name="payment_reference_no" id="paymentReferenceNo">
        <input type="hidden" name="payment_check_date" id="paymentCheckDate">
        <input type="hidden" name="bank_account_name" id="paymentBankAccountName">
        <input type="hidden" name="payment_bank_name" id="paymentBankName">
        <input type="hidden" name="payment_bank_branch" id="paymentBankBranch">
        <input type="hidden" name="expense_account_name" id="paymentExpenseAccountName">

        <div class="modal-body qb-body">
          <button type="button" class="repair-write-check-close" data-bs-dismiss="modal" aria-label="Close">&times;</button>

          <div class="qb-topbar">
            <div class="qb-bank-wrap">
              <label>BANK ACCOUNT</label>
              <select class="form-select" name="bank_account_id" id="paymentBankAccount" required>
                <option value="">Select bank account</option>
                <?php foreach (($repairPaymentBankAccounts ?? []) as $bankAccount): ?>
                  <option value="<?php echo (int)$bankAccount['bank_id']; ?>"
                          data-balance="<?php echo (float)($bankAccount['balance'] ?? 0); ?>"
                          data-name="<?php echo h($bankAccount['display_name']); ?>"
                          data-bank-name="<?php echo h($bankAccount['bank_name'] ?? ''); ?>"
                          data-bank-branch="<?php echo h($bankAccount['bank_branch'] ?? ''); ?>">
                    <?php echo h($bankAccount['display_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="qb-ending-balance">
              <label>ENDING BALANCE</label>
              <strong id="repairWriteCheckEndingBalance">₱0.00</strong>
            </div>
          </div>

          <div class="qb-check-panel">
            <div class="qb-check-left">
              <div class="qb-payee-row qb-payee-search-row">
                <label>PAY TO THE ORDER OF</label>
                <div class="qb-payee-select-wrap withdrawal-payee-combo">
                  <input type="text" id="repairWriteCheckPayee" class="form-control withdrawal-payee-input" value="Motorpool Repair" readonly>
                  <button type="button" class="withdrawal-payee-toggle" tabindex="-1" aria-label="Open payee dropdown">
                    <i class="bi bi-chevron-down"></i>
                  </button>
                </div>
              </div>

              <div class="qb-dollars-line">
                <span id="repairPaymentAmountWords" class="qb-amount-words">ZERO PESOS ONLY</span>
              </div>

              <div class="qb-address-row">
                <label>ADDRESS</label>
                <input type="text" id="repairWriteCheckAddress" class="form-control" value="Motorpool" readonly>
              </div>

              <div class="qb-memo-row">
                <label>MEMO</label>
                <input type="text" name="payment_remarks" id="paymentRemarks" class="form-control" placeholder="Description">
              </div>
            </div>

            <div class="qb-check-right">
              <div class="qb-mini-field">
                <label>NO.</label>
                <input type="text" name="payment_check_number" id="paymentCheckNumber" class="form-control" placeholder="Reference No." required>
              </div>

              <div class="qb-mini-field">
                <label>DATE</label>
                <input type="date" name="payment_date" id="paymentDate" class="form-control" required>
              </div>

              <div class="qb-mini-field qb-amount-field">
                <label>₱</label>
                <input type="number" step="0.01" min="0.01" name="payment_amount" id="paymentAmount" class="form-control" placeholder="0.00" required title="Pwede i-edit para sa partial payment">
              </div>
            </div>

            <div class="qb-check-attachment">
              <div class="withdrawal-attachment-card">
                <label for="paymentAttachment"><i class="bi bi-paperclip"></i>Transaction Attachment</label>
                <div class="withdrawal-attach-inner">
                  <span class="withdrawal-attach-title"><i class="bi bi-paperclip"></i>Attach</span>
                  <input type="file" class="form-control" name="payment_attachment" id="paymentAttachment" accept="image/*,.pdf">
                  <div class="withdrawal-attachment-help">This attachment applies to the whole withdrawal transaction.</div>
                  <div id="repairPaymentAttachmentPreview" class="withdrawal-attachment-preview"></div>
                </div>
              </div>
            </div>
          </div>

          <div class="qb-tabs" id="repairPaymentTabButtons">
            <button type="button" class="qb-tab active" data-repair-payment-tab="expenses">Expenses <span id="repairPaymentExpenseTotal">₱0.00</span></button>
            <button type="button" class="qb-tab" data-repair-payment-tab="items">Items <span>₱0.00</span></button>
            <button type="button" class="qb-tab" data-repair-payment-tab="history">Payment History</button>
          </div>

          <div class="qb-tab-panel active" id="repairPaymentExpensesPanel" data-repair-payment-panel="expenses">
            <div class="qb-expense-table-wrap">
              <table class="table qb-expense-table mb-0">
                <thead>
                  <tr>
                    <th style="width:50%;">ACCOUNT TITLE</th>
                    <th style="width:20%;">AMOUNT</th>
                    <th style="width:30%;">MEMO</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="withdrawal-expense-account-cell">
                      <div class="qb-account-picker withdrawal-account-combo">
                        <select class="form-select qb-table-select withdrawal-expense-account-input" name="expense_account_id" id="paymentExpenseAccount" required>
                          <option value="">Select account</option>
                          <?php foreach (($repairPaymentExpenseAccounts ?? []) as $expenseAccount): ?>
                            <option value="<?php echo (int)$expenseAccount['account_id']; ?>"
                                    data-name="<?php echo h($expenseAccount['account_name']); ?>">
                              <?php echo h($expenseAccount['account_name']); ?><?php echo trim((string)($expenseAccount['account_type'] ?? '')) !== '' ? ' (' . h($expenseAccount['account_type']) . ')' : ''; ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </td>
                    <td><input type="number" step="0.01" min="0.01" id="repairPaymentExpenseAmountMirror" class="form-control qb-table-input text-end" placeholder="0.00" title="Pwede i-edit para sa partial payment"></td>
                    <td><input type="text" id="repairPaymentExpenseMemoMirror" class="form-control qb-table-input" readonly></td>
                  </tr>
                  <?php for ($i = 0; $i < 7; $i++): ?>
                    <tr class="qb-empty-row"><td>&nbsp;</td><td></td><td></td></tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="qb-tab-panel" id="repairPaymentItemsPanel" data-repair-payment-panel="items">
            <div class="qb-expense-table-wrap">
              <table class="table qb-expense-table mb-0">
                <thead>
                  <tr>
                    <th style="width:18%;">ITEM NAME</th>
                    <th style="width:8%;">QTY</th>
                    <th style="width:28%;">Item Code / Description</th>
                    <th style="width:10%;">UOM</th>
                    <th style="width:12%;">Unit Cost</th>
                    <th style="width:12%;">Discount</th>
                    <th style="width:12%;">Total Unit Cost</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = 0; $i < 8; $i++): ?>
                    <tr class="qb-empty-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="qb-tab-panel" id="repairPaymentHistoryPanel" data-repair-payment-panel="history">
            <div class="qb-expense-table-wrap">
              <table class="table qb-expense-table mb-0">
                <thead>
                  <tr>
                    <th style="width:18%;">DATE</th>
                    <th style="width:18%;">TYPE</th>
                    <th style="width:24%;">REFERENCE</th>
                    <th style="width:24%;">MEMO</th>
                    <th style="width:16%;">AMOUNT</th>
                  </tr>
                </thead>
                <tbody id="repairPaymentHistoryModalBody">
                  <tr><td colspan="5" class="text-center text-muted">No payment history found.</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="repair-write-check-summary">
            <span>RIS No.: <strong id="paymentRisLabel">N/A</strong></span>
            <span>Full Repair Balance: <strong id="paymentBalanceLabel">₱0.00</strong></span>
            <span>Motorpool To Pay: <strong id="paymentTotalCostLabel">₱0.00</strong></span>
            <span id="paymentBranchSourceSummaryWrap" class="d-none">Branch Source Balance: <strong id="paymentBranchSourceBalanceLabel">₱0.00</strong></span>
            <span class="text-muted">Amount field below is for Motorpool payment only.</span>
            <strong id="paymentFullRepairBalanceLabel" class="d-none">₱0.00</strong>
          </div>
        </div>

        <div class="modal-footer qb-footer write-check-footer">
          <button type="button" class="btn btn-outline-primary d-none" id="repairPaymentBranchSourceActualCostBtn"><i class="bi bi-pencil-square me-1"></i>Branch Source Actual Cost</button>
          <button type="submit" class="btn btn-amgc-primary" name="save_close" value="1"><i class="bi bi-check-circle me-1"></i>Save & Close</button>
          <button type="submit" class="btn btn-amgc-dark" name="save_new" value="1"><i class="bi bi-plus-circle me-1"></i>Save & New</button>
          <button type="button" class="btn btn-light border" id="repairPaymentClearBtn">Clear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="branchSourceCostModalV2" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form id="branchSourceCostFormV2">
        <input type="hidden" name="action" value="save_branch_source_actual_costs">
        <input type="hidden" name="source_ris_id" id="branchSourceRisIdV2">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Branch Source Actual Cost</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 mb-3">
            RIS No.: <strong id="branchSourceRisLabelV2">N/A</strong><br>
            Encode supplier and actual cost for Branch Source item/s only.
          </div>
          <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Specification</th>
                  <th class="text-end">Qty</th>
                  <th>Supplier</th>
                  <th>Actual Unit Cost</th>
                  <th>Actual Total Cost</th>
                </tr>
              </thead>
              <tbody id="branchSourceCostBodyV2">
                <tr><td colspan="6" class="text-muted text-center py-3">No Branch Source item found.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-amgc-primary" id="branchSourcePayBtnV2"><i class="bi bi-cash-coin me-1"></i>Pay Branch Source</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Save Source Cost</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="renewRegistrationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <form id="renewRegistrationForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="renew_registration">
        <input type="hidden" name="vehicle_db_id" id="renewVehicleDbId">
        <input type="hidden" name="vehicle_id" id="renewVehicleCode">
        <input type="hidden" name="plate_no" id="renewPlateNo">
        <div class="modal-header bg-white" style="border-bottom:1px solid #dee2e6;">
          <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2 text-success"></i>Renew Registration</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">OR No. <span class="required-mark">*</span></label>
              <input class="form-control" name="or_no" id="renewOrNo">
            </div>
            <div class="col-12">
              <label class="form-label">Registration History Date <span class="required-mark">*</span></label>
              <input type="date" class="form-control" name="reg_date" id="renewRegDate">
            </div>
            <div class="col-12">
              <label class="form-label">Next Renewal <span class="required-mark">*</span></label>
              <input type="date" class="form-control" name="next_renewal" id="renewNextRenewal">
            </div>
            <div class="col-12">
              <label class="form-label">OR Attachment <span class="required-mark">*</span></label>
              <input type="file" class="form-control" name="or_attachment" id="renewOrAttachment" accept="image/*,.pdf">
              <div class="file-hint mt-1">Upload OR image or PDF only.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-white" style="border-top:1px solid #dee2e6;">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-success" onclick="saveRenewRegistration()"><i class="bi bi-save me-1"></i>Save Renewal</button>
        </div>
      </form>
    </div>
  </div>
</div>



<div class="modal fade" id="fuelMonitoringModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="fuelMonitoringForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_fuel_monitoring">
        <input type="hidden" name="vehicle_db_id" id="fuelVehicleDbId">
        <input type="hidden" name="vehicle_id" id="fuelVehicleCode">
        <input type="hidden" name="plate_no" id="fuelPlateNo">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="bi bi-fuel-pump me-2"></i>Fuel Monitoring</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border mb-3"><strong id="fuelVehicleTitle">Vehicle</strong><div class="small text-muted" id="fuelVehicleSubtitle">Fuel monitoring record</div></div>
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Date <span class="required-mark">*</span></label><input type="date" class="form-control" name="fuel_date" id="fuelDate" required></div>
            <div class="col-md-4"><label class="form-label">Current Odometer Reading <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="current_odometer" id="fuelCurrentOdometer" required></div>
            <div class="col-md-4"><label class="form-label">Previous Odometer Reading <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="previous_odometer" id="fuelPreviousOdometer" required></div>
            <div class="col-md-4"><label class="form-label">Distance Covered (km) <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="distance_covered" id="fuelDistanceCovered" required></div>
            <div class="col-md-4"><label class="form-label">Liters Consumed <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="liters_consumed" id="fuelLitersConsumed" required></div>
            <div class="col-md-4"><label class="form-label">Fuel Efficiency (km/L) <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control" name="fuel_efficiency" id="fuelEfficiency" required></div>
            <div class="col-md-4"><label class="form-label">Refuel (Liters) <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control" name="refuel_liters" id="fuelRefuelLiters" required></div>
            <div class="col-md-6"><label class="form-label">Price <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control" name="fuel_price" id="fuelPrice" required></div>
            <div class="col-md-6"><label class="form-label">Attachment <span class="required-mark">*</span></label><input type="file" class="form-control" name="fuel_attachment" id="fuelAttachment" accept="image/*,.pdf" required></div>
          </div>
        </div>
        <div class="modal-footer bg-white"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-success" onclick="saveFuelMonitoring()"><i class="bi bi-save me-1"></i>Save Fuel Record</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="repairWorkflowModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:95%;">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0"><i class="bi bi-clock-history me-2"></i><span id="repairWorkflowTitle">Detailed Repair Workflow</span></h5>
          <small id="repairWorkflowSubtitle" class="d-block mt-1 text-white-50"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="repair-timeline" id="repairWorkflowTimelineBody">
          <div class="timeline-empty">No workflow history found.</div>
        </div>
      </div>
      <div class="modal-footer bg-white">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<div class="modal fade" id="fuelRecordDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:95%;">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0"><i class="bi bi-fuel-pump me-2"></i>Fuel Monitoring Details</h5>
          <small id="fuelRecordDetailsSubtitle" class="d-block mt-1 text-white-50"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="detail-info-grid" id="fuelRecordDetailsGrid"></div>
        <div class="mt-3" id="fuelRecordAttachmentWrap"></div>
      </div>
      <div class="modal-footer bg-white">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="motorpoolFilePreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-transparent border-0 shadow-none">
      <div class="modal-body p-0">
        <div class="attachment-container">
          <div class="attachment-wrapper">
            <button type="button" class="btn-close-attachment" data-bs-dismiss="modal" aria-label="Close">
              <i class="bi bi-x-lg"></i>
            </button>
            <a href="#" id="motorpoolDownloadLink" class="btn-download-attachment" download>
              <i class="bi bi-download"></i>
            </a>
            <div class="attachment-content" id="motorpoolPreviewBody">
              <div class="spinner-border text-light" role="status">
                <span class="visually-hidden">Loading...</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="risModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:95%;">
    <div class="modal-content">
      <div class="modal-header bg-white sticky-top" style="z-index:10;border-bottom:1px solid #dee2e6;">
        <h5 class="modal-title"><i class="bi bi-clipboard-check me-2 text-success"></i>Request for Inspection Slip</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="risForm">
          <input type="hidden" name="action" value="submit_ris">
          <input type="hidden" name="vehicle_db_id" id="risVehicleDbId">
          <input type="hidden" name="vehicle_id" id="risVehicleCode">
          <input type="hidden" name="vehicle_details" id="risVehicleName">
          <input type="hidden" name="plate_no" id="risPlateNo">
          <input type="hidden" name="make_brand" id="risMakeBrand">
          <input type="hidden" name="vehicle_type" id="risVehicleType">
          <input type="hidden" name="vehicle_category" id="risCategory">
          <input type="hidden" name="classification" id="risClassification">
          <input type="hidden" name="body_type" id="risBodyType">
          <input type="hidden" name="color" id="risColor">
          <input type="hidden" name="type_of_fuel" id="risFuelType">
          <input type="hidden" name="year_model" id="risYearModel">
          <input type="hidden" name="series" id="risSeries">
          <input type="hidden" name="passenger_capacity" id="risPassengerCapacity">
          <input type="hidden" name="max_power_kw" id="risMaxPower">
          <input type="hidden" name="lto_cr_no" id="risLtoCrNo">
          <input type="hidden" name="date_registration" id="risDateRegistration">
          <input type="hidden" name="file_no" id="risFileNo">
          <input type="hidden" name="engine_no" id="risEngineNo">
          <input type="hidden" name="chassis_no" id="risChassisNo">
          <input type="hidden" name="vin" id="risVin">
          <input type="hidden" name="gross_weight" id="risGrossWeight">
          <input type="hidden" name="net_weight" id="risNetWeight">
          <input type="hidden" name="year_rebuilt" id="risYearRebuilt">
          <input type="hidden" name="piston_displacement" id="risPistonDisplacement">

          <div class="mb-3">
            <div class="section-title mb-2"><i class="bi bi-truck-front me-1"></i>Vehicle Information</div>
            <div class="detail-info-grid compact-ris-info" id="risVehicleDetailsGrid"></div>
          </div>

          <hr class="my-3">

          <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Concern/s <span class="required-mark">*</span></label>
                <textarea class="form-control" name="concerns" id="risConcerns" rows="4" placeholder="Enter concern/s"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Endorsed by (Driver/Operator) <span class="required-mark">*</span></label>
                <input class="form-control" name="endorsed_by" id="risEndorsedBy" placeholder="Driver/operator name">
            </div>

            <div class="col-md-6">
                <label class="form-label">Date Requested</label>
                <input type="date" class="form-control" name="date_requested" id="risDate">
            </div>

            <div class="col-md-6">
                <label class="form-label">Signature of Driver/Operator</label>
                <input type="hidden" name="signature" id="signatureInput">
                <div class="signature-preview-box" id="signaturePreviewBox">
                    <div class="signature-preview-empty" id="signaturePreviewEmpty">No signature added yet.</div>
                    <img src="" alt="Driver/Operator Signature" id="signaturePreviewImage" class="signature-preview-image d-none">
                </div>
                <button type="button" class="btn btn-outline-success btn-sm mt-2" id="openSignatureModalBtn" onclick="openSignatureModal()">
                    <i class="bi bi-pencil-square me-1"></i>Add Signature
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm mt-2 ms-1 d-none" id="removeSignatureBtn" onclick="removeSavedSignature()">
                    <i class="bi bi-trash me-1"></i>Remove
                </button>
            </div>

          </div>
        </form>
      </div>
      <div class="modal-footer bg-white sticky-bottom" style="border-top:1px solid #dee2e6;z-index:10;">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-success" onclick="sendAndPrintRis()"><i class="bi bi-send-check me-1"></i>Send &amp; Print RIS</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="signatureModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-success"></i>Driver/Operator Signature</h5>
        <button type="button" class="btn-close" onclick="cancelSignatureModal()" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="signature-pad-box">
          <canvas id="signaturePad" class="signature-pad-canvas"></canvas>
        </div>
        <small class="text-muted d-block mt-2">Draw the signature inside the box.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cancelSignatureModal()">Cancel</button>
        <button type="button" class="btn btn-outline-danger" onclick="clearSignaturePadOnly()"><i class="bi bi-eraser me-1"></i>Clear</button>
        <button type="button" class="btn btn-success" onclick="saveSignatureFromModal()"><i class="bi bi-check-circle me-1"></i>Use Signature</button>
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


</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function branchApprovalEscape(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(match) {
        return ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'})[match];
    });
}


function branchReleaseInfoItem(label, value) {
    return '<div class="approval-info-item"><small>' + branchApprovalEscape(label) + '</small><strong>' + branchApprovalEscape(value || 'N/A') + '</strong></div>';
}

function formatBranchReleaseParts(parts) {
    if (!Array.isArray(parts) || parts.length === 0) return '<span class="text-muted">No parts used</span>';
    const rows = parts.map(function(part) {
        const itemNo = escapeHtml(part.item_no || '-');
        const qty = escapeHtml(part.used_quantity || part.qty_to_use || part.quantity || '0');
        return `<tr><td>${itemNo}</td><td>${qty}</td></tr>`;
    }).join('');
    return `<div class="table-responsive"><table class="table table-bordered approval-parts-table mb-0"><thead><tr><th>Item No.</th><th>Qty Used</th></tr></thead><tbody>${rows}</tbody></table></div>`;
}

function renderBranchForReleaseRepairs(data) {
    let rows = [];
    try { rows = JSON.parse(data.quality_check_json || '[]'); } catch (e) { rows = []; }
    if (!Array.isArray(rows) || rows.length === 0) {
        return '<div class="text-muted">No completed repair log found.</div>';
    }

    return rows.map(function(item, index) {
        const repairType = item.repair_type === 'with_parts' ? 'With Parts' : 'Labor Only';
        return `
            <div class="assessment-repair-card mb-3">
                <div class="assessment-repair-title">Repair #${index + 1}</div>
                <div class="approval-info-grid mb-2">
                    ${branchReleaseInfoItem('Repair', item.repair || '-')}
                    ${branchReleaseInfoItem('Repair Type', repairType)}
                    ${branchReleaseInfoItem('Mechanic', item.mechanic || '-')}
                    ${branchReleaseInfoItem('Start Date/Time', `${item.start_date || '-'} ${item.start_time || ''}`.trim())}
                    ${branchReleaseInfoItem('End Date/Time', `${item.end_date || '-'} ${item.end_time || ''}`.trim())}
                </div>
                <div>${formatBranchReleaseParts(item.parts_used || [])}</div>
            </div>`;
    }).join('');
}

function openBranchForReleaseModal(row) {
    if (!row) return;
    let data = {};
    try {
        data = JSON.parse(row.getAttribute('data-release') || '{}');
    } catch (e) {
        data = {};
    }

    document.getElementById('branchForReleaseModalTitle').textContent = data.ris_number ? 'For Release - ' + data.ris_number : 'Motorpool For Release';
    document.getElementById('branchForReleaseConcerns').value = data.concerns || '';
    document.getElementById('branchForReleaseInfoGrid').innerHTML = [
        ['RIS No.', data.ris_number || ''],
        ['Plate No.', data.plate_no || ''],
        ['Vehicle ID', data.vehicle_id || ''],
        ['Vehicle Details', data.vehicle_details || data.vehicle_category || ''],
        ['Date Requested', data.date_requested || ''],
        ['Status', 'For Release']
    ].map(function(item) { return branchReleaseInfoItem(item[0], item[1]); }).join('');

    document.getElementById('branchForReleaseQualityView').innerHTML = [
        branchReleaseInfoItem('Quality Check By', data.quality_check_by || '-'),
        branchReleaseInfoItem('Date and Time', data.quality_check_datetime || '-'),
        branchReleaseInfoItem('Remarks', data.quality_remarks || '-')
    ].join('');

    document.getElementById('branchForReleaseRepairsView').innerHTML = renderBranchForReleaseRepairs(data);
    motorpoolShowModalFromCurrent('branchForReleaseModal');
}

function openBranchApprovalModal(row) {
    if (!row) return;

    let data = {};
    try {
        data = JSON.parse(row.getAttribute('data-approval') || '{}');
    } catch (e) {
        data = {};
    }

    document.getElementById('approvalRisId').value = data.ris_id || '';
    document.getElementById('approvalDecision').value = 'approved';
    document.getElementById('approvalRemarks').value = data.branch_approval_remarks || '';
    const partsMotorpool = document.getElementById('partsPurchaseMotorpool');
    const partsBranch = document.getElementById('partsPurchaseBranch');
    if (partsMotorpool) partsMotorpool.checked = true;
    if (partsBranch) partsBranch.checked = false;
    refreshPartsPurchaseChoiceUI();
    document.getElementById('approvalModalTitle').textContent = data.ris_number ? 'Assessment for Approval - ' + data.ris_number : 'Assessment for Approval';
    document.getElementById('approvalConcerns').value = data.concerns || '';

    let assessment = [];
    try {
        assessment = JSON.parse(data.assessment_json || '[]');
    } catch (e) {
        assessment = [];
    }

    const assessedByFromJson = getAssessmentAssessedBy(assessment);
    const info = [
        ['RIS No.', data.ris_number || ''],
        ['Date Requested', data.date_requested || ''],
        ['Branch', data.branch_name || (data.branch_id ? 'Branch #' + data.branch_id : '')],
        ['Requested By', (data.requested_by_name || '').trim() || (data.requested_by ? 'User #' + data.requested_by : '')],
        ['Plate No.', data.plate_no || ''],
        ['Vehicle ID', data.vehicle_id || ''],
        ['Vehicle Details', data.vehicle_details || ''],
        ['Category', data.vehicle_category || ''],
        ['Endorsed By', data.endorsed_by || ''],
        ['Assessed By', assessedByFromJson || (data.assessed_by_name || '').trim() || 'Motorpool'],
        ['Assessed At', data.assessed_at || ''],
        ['Approval Status', data.branch_approval_status || 'Pending']
    ];

    document.getElementById('approvalInfoGrid').innerHTML = info.map(function(item) {
        return '<div class="approval-info-item"><small>' + branchApprovalEscape(item[0]) + '</small><strong>' + branchApprovalEscape(item[1] || 'N/A') + '</strong></div>';
    }).join('');

    document.getElementById('approvalAssessmentView').innerHTML = renderApprovalAssessmentDetails(assessment, data);
    updateBranchSelectedPartsSummary();
    motorpoolShowModalFromCurrent('branchApprovalModal');
}

function getAssessmentAssessedBy(assessment) {
    if (!Array.isArray(assessment) || assessment.length === 0) return '';
    for (const repair of assessment) {
        if (repair && repair.assessed_by_global) return repair.assessed_by_global;
        if (repair && repair.assessed_by) return repair.assessed_by;
    }
    return '';
}

function getRepairTextForApproval(repair) {
    if (!repair || typeof repair !== 'object') return '';
    return repair.repair || repair.repairs_to_make || repair.repair_to_make || repair.description || repair.action || repair.work_required || '';
}

function normalizeApprovalParts(repair) {
    if (!repair || typeof repair !== 'object') return [];
    if (Array.isArray(repair.parts)) return repair.parts;
    if (Array.isArray(repair.items)) return repair.items;
    if (Array.isArray(repair.parts_needed)) return repair.parts_needed;
    if (Array.isArray(repair.items_needed)) return repair.items_needed;
    return [];
}

function approvalToNumber(value) {
    if (value === null || value === undefined) return 0;
    const cleaned = String(value).replace(/[^0-9.-]/g, '');
    const num = parseFloat(cleaned);
    return Number.isFinite(num) ? num : 0;
}

function approvalMoney(value) {
    return '₱' + approvalToNumber(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function approvalPartUnitCost(part) {
    if (!part || typeof part !== 'object') return 0;
    return approvalToNumber(part.unit_cost ?? part.cost ?? part.item_cost ?? part.inventory_cost ?? part.price ?? 0);
}

function approvalPartEstimatedCost(part) {
    if (!part || typeof part !== 'object') return 0;
    const direct = part.estimated_cost ?? part.total_cost ?? part.total_estimated_cost ?? part.amount ?? '';
    if (direct !== '' && direct !== null && direct !== undefined) return approvalToNumber(direct);
    const qty = approvalToNumber(part.quantity ?? part.qty ?? part.qty_needed ?? part.quantity_needed ?? 0);
    return qty * approvalPartUnitCost(part);
}

function approvalRepairLaborCost(repair) {
    if (!repair || typeof repair !== 'object') return 0;
    return approvalToNumber(
        repair.repair_cost ??
        repair.labor_cost ??
        repair.service_cost ??
        repair.repairCost ??
        repair.laborCost ??
        repair.serviceCost ??
        0
    );
}


function renderApprovalAssessmentDetails(assessment, data) {
    if (!Array.isArray(assessment) || assessment.length === 0) {
        return '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>No detailed assessment rows were found. Please check the Motorpool assessment record.</div>';
    }

    let grandLaborCost = 0;
    let grandPartsCost = 0;

    const detailsHtml = assessment.map(function(repair, index) {
        const repairText = getRepairTextForApproval(repair);
        const repairLaborCost = approvalRepairLaborCost(repair);
        grandLaborCost += repairLaborCost;

        const parts = normalizeApprovalParts(repair);
        let partsRows = '';
        let repairPartsEstimatedCost = 0;

        if (!parts.length) {
            partsRows = '<tr><td colspan="7" class="text-muted text-center">No item or part details listed for this repair.</td></tr>';
        } else {
            partsRows = parts.map(function(part, partIndex) {
                const itemNo = part.item_no || part.itemNo || part.item_number || part.item || part.part_no || (partIndex + 1);
                const description = part.description || part.name || part.part || part.part_name || part.item_description || '';
                const specification = part.specification || part.specs || part.spec || '';
                const quantity = part.quantity || part.qty || part.qty_needed || part.quantity_needed || '';
                const unitCost = approvalPartUnitCost(part);
                const estimatedCost = approvalPartEstimatedCost(part);
                repairPartsEstimatedCost += estimatedCost;
                grandPartsCost += estimatedCost;

                const selectionValue = index + '_' + partIndex;
                return '<tr data-branch-part-row="1" data-branch-part-total="' + estimatedCost + '">'
                    + '<td class="text-center" style="width:70px;"><input type="checkbox" class="approval-branch-part-check" name="branch_purchase_parts[]" value="' + branchApprovalEscape(selectionValue) + '" onchange="updateBranchSelectedPartsSummary()"></td>'
                    + '<td>' + branchApprovalEscape(itemNo) + '</td>'
                    + '<td>' + branchApprovalEscape(description) + '</td>'
                    + '<td>' + branchApprovalEscape(specification) + '</td>'
                    + '<td>' + branchApprovalEscape(quantity) + '</td>'
                    + '<td>' + approvalMoney(unitCost) + '</td>'
                    + '<td>' + approvalMoney(estimatedCost) + '</td>'
                    + '</tr>';
            }).join('');
        }

        return '<div class="assessment-repair-card mb-3">'
            + '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
            + '<div class="fw-bold text-success">Repair No. ' + (index + 1) + '</div>'
            + '<span class="badge bg-light text-dark border">' + parts.length + ' item(s)</span>'
            + '</div>'
            + '<div class="border rounded bg-light p-2 mb-2 repair-work-text">' + branchApprovalEscape(repairText || 'No repair description') + '</div>'
            + '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border rounded bg-white p-2 mb-2">'
            + '<span class="fw-semibold text-muted">Repair/Labor Cost</span>'
            + '<span class="fw-bold text-success">' + approvalMoney(repairLaborCost) + '</span>'
            + '</div>'
            + '<div class="table-responsive">'
            + '<table class="table table-bordered align-middle mb-0 approval-parts-table">'
            + '<thead><tr>'
            + '<th style="width:70px;" class="text-center">Branch</th>'
            + '<th style="width:120px;">Item No.</th>'
            + '<th>Description</th>'
            + '<th>Specification</th>'
            + '<th style="width:110px;">Quantity</th>'
            + '<th style="width:130px;">Unit Cost</th>'
            + '<th style="width:150px;">Estimated Cost</th>'
            + '</tr></thead>'
            + '<tbody>' + partsRows + '</tbody>'
            + '</table>'
            + '</div>'
            + '</div>';
    }).join('');

    const grandTotal = grandLaborCost + grandPartsCost;
    const grandTotalHtml = '<div class="approval-grand-total mt-3 p-3 border rounded bg-light">'
        + '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">'
        + '<span class="fw-bold text-dark">Grand Total Cost</span>'
        + '<span class="fw-bold text-success fs-5">' + approvalMoney(grandTotal) + '</span>'
        + '</div>'
        + '<div class="small text-muted mt-1">Includes Repair/Labor Cost plus all item Estimated Cost.</div>'
        + '</div>';

    return detailsHtml + grandTotalHtml;
}


function updateBranchSelectedPartsSummary() {
    const checks = Array.from(document.querySelectorAll('.approval-branch-part-check'));
    let selectedCount = 0;
    let selectedTotal = 0;
    checks.forEach(function(check) {
        const row = check.closest('tr');
        const isSelected = !!check.checked;
        if (row) row.classList.toggle('approval-branch-selected-row', isSelected);
        if (isSelected) {
            selectedCount++;
            selectedTotal += approvalToNumber(row ? row.getAttribute('data-branch-part-total') : 0);
        }
    });

    const summary = document.getElementById('branchPartsSelectedSummary');
    if (summary) {
        if (selectedCount > 0) {
            summary.classList.remove('d-none');
            summary.innerHTML = '<strong>Selected Branch Item/s:</strong> ' + selectedCount + ' item(s)<br><strong>Estimated Branch Source Total:</strong> ' + approvalMoney(selectedTotal);
        } else {
            summary.classList.add('d-none');
            summary.innerHTML = '';
        }
    }
}

function refreshPartsPurchaseChoiceUI() {
    const motorpool = document.getElementById('partsPurchaseMotorpool');
    const branch = document.getElementById('partsPurchaseBranch');
    const motorpoolLabel = document.getElementById('partsChoiceMotorpoolLabel');
    const branchLabel = document.getElementById('partsChoiceBranchLabel');
    const notice = document.getElementById('branchPartsExpenseNotice');
    const selectorPanel = document.getElementById('branchPartsSelectorPanel');
    const branchSelected = !!(branch && branch.checked);
    if (motorpoolLabel) motorpoolLabel.classList.toggle('active', !!(motorpool && motorpool.checked));
    if (branchLabel) branchLabel.classList.toggle('active', branchSelected);
    if (notice) notice.classList.toggle('d-none', !branchSelected);
    if (selectorPanel) selectorPanel.classList.toggle('show', branchSelected);
    if (!branchSelected) {
        document.querySelectorAll('.approval-branch-part-check').forEach(function(check) { check.checked = false; });
    }
    updateBranchSelectedPartsSummary();
}
document.addEventListener('change', function(e) {
    if (e.target && e.target.name === 'parts_purchase_by') refreshPartsPurchaseChoiceUI();
    if (e.target && e.target.classList && e.target.classList.contains('approval-branch-part-check')) updateBranchSelectedPartsSummary();
});

function submitBranchApproval(decision) {
    const form = document.getElementById('branchApprovalForm');
    const remarks = document.getElementById('approvalRemarks').value.trim();

    if (decision === 'rejected' && !remarks) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Remarks Required',
                text: 'Please add remarks before returning the assessment.',
                confirmButtonColor: '#07b83f'
            });
        } else {
            alert('Please add remarks before returning the assessment.');
        }
        return;
    }

    const branchPartsChoice = document.getElementById('partsPurchaseBranch');
    if (decision === 'approved' && branchPartsChoice && branchPartsChoice.checked) {
        const selectedParts = document.querySelectorAll('.approval-branch-part-check:checked');
        if (!selectedParts.length) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Item Selected',
                    text: 'Please select at least one item that the branch will source, or choose Motorpool will source the parts.',
                    confirmButtonColor: '#07b83f'
                });
            } else {
                alert('Please select at least one item that the branch will source, or choose Motorpool will source the parts.');
            }
            return;
        }
    }

    document.getElementById('approvalDecision').value = decision;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: decision === 'approved' ? 'Approve assessment?' : 'Return for revision?',
            text: decision === 'approved'
                ? (document.getElementById('partsPurchaseBranch') && document.getElementById('partsPurchaseBranch').checked ? 'Branch will source only the selected item/s. This will not post an expense yet. Unselected parts will remain under Motorpool.' : 'This will send the request to Motorpool for parts completion.')
                : 'This will return the request to Motorpool for another assessment.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: decision === 'approved' ? '#07b83f' : '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: decision === 'approved' ? 'Yes, approve' : 'Yes, return'
        }).then(function(result) {
            if (result.isConfirmed) form.submit();
        });
    } else {
        if (confirm(decision === 'approved' ? 'Approve assessment?' : 'Return for revision?')) form.submit();
    }
}

</script>
<script>
function validateRenewRegistrationForm(form) {
    const requiredFields = form.querySelectorAll('[required]');
    for (const field of requiredFields) {
        if (!field.value || field.value.trim() === '') {
            field.focus();
            if (typeof Swal !== 'undefined') {
                Swal.fire('Required', 'Please complete all registration renewal fields.', 'warning');
            } else {
                alert('Please complete all registration renewal fields.');
            }
            return false;
        }
    }
    return true;
}

</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>

/* ===== MOTORPOOL MODAL-TO-MODAL HANDLER
   Kapag may modal na binuksan mula sa loob ng ibang modal,
   itatago muna ang current modal, tapos ibabalik ito kapag naisara ang bagong modal.
===== */
function motorpoolGetVisibleModal(excludeId) {
    const openModals = Array.from(document.querySelectorAll('.modal.show'))
        .filter(function(modal) {
            return modal && modal.id && modal.id !== excludeId;
        });

    return openModals.length ? openModals[openModals.length - 1] : null;
}

function motorpoolCleanupModalState() {
    const hasOpenModal = document.querySelector('.modal.show');
    if (hasOpenModal) {
        document.body.classList.add('modal-open');
        return;
    }

    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
}

function motorpoolShowModalFromCurrent(targetModalId, options) {
    options = options || {};

    const targetModalElement = document.getElementById(targetModalId);
    if (!targetModalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }

    const explicitParentId = options.parentModalId || '';
    let parentModalElement = null;

    if (explicitParentId) {
        const possibleParent = document.getElementById(explicitParentId);

        // Important: gagamitin lang na parent kung talagang naka-open siya ngayon.
        // Para kapag standalone modal tulad ng Fuel Monitoring ang binuksan,
        // hindi siya magbabalik ng maling previous modal pag sinara.
        if (possibleParent && possibleParent.classList.contains('show')) {
            parentModalElement = possibleParent;
        }
    } else {
        parentModalElement = motorpoolGetVisibleModal(targetModalId);
    }

    const targetModal = bootstrap.Modal.getOrCreateInstance(targetModalElement);

    targetModalElement.removeEventListener('hidden.bs.modal', motorpoolRestorePreviousModal);

    if (parentModalElement && parentModalElement.id && parentModalElement.id !== targetModalId) {
        targetModalElement.dataset.motorpoolReturnModalId = parentModalElement.id;

        if (options.returnTabTarget) {
            targetModalElement.dataset.motorpoolReturnTabTarget = options.returnTabTarget;
        } else {
            targetModalElement.removeAttribute('data-motorpool-return-tab-target');
        }

        targetModalElement.addEventListener('hidden.bs.modal', motorpoolRestorePreviousModal);

        const parentModal = bootstrap.Modal.getOrCreateInstance(parentModalElement);
        parentModal.hide();

        setTimeout(function() {
            targetModal.show();
            document.body.classList.add('modal-open');
        }, options.delay || 220);
    } else {
        targetModalElement.removeAttribute('data-motorpool-return-modal-id');
        targetModalElement.removeAttribute('data-motorpool-return-tab-target');
        targetModal.show();
        document.body.classList.add('modal-open');
    }
}

function motorpoolRestorePreviousModal(event) {
    const closedModalElement = event.currentTarget;
    const returnModalId = closedModalElement.dataset.motorpoolReturnModalId || '';
    const returnTabTarget = closedModalElement.dataset.motorpoolReturnTabTarget || '';

    closedModalElement.removeEventListener('hidden.bs.modal', motorpoolRestorePreviousModal);
    closedModalElement.removeAttribute('data-motorpool-return-modal-id');
    closedModalElement.removeAttribute('data-motorpool-return-tab-target');

    if (!returnModalId) {
        setTimeout(motorpoolCleanupModalState, 80);
        return;
    }

    const returnModalElement = document.getElementById(returnModalId);
    if (!returnModalElement) {
        setTimeout(motorpoolCleanupModalState, 80);
        return;
    }

    setTimeout(function() {
        const returnModal = bootstrap.Modal.getOrCreateInstance(returnModalElement);
        returnModal.show();
        document.body.classList.add('modal-open');

        if (returnTabTarget) {
            returnModalElement.addEventListener('shown.bs.modal', function() {
                const tabButton = document.querySelector('[data-bs-target="' + returnTabTarget + '"]');
                if (tabButton && bootstrap.Tab) {
                    bootstrap.Tab.getOrCreateInstance(tabButton).show();
                }
            }, { once: true });
        }
    }, 120);
}

document.addEventListener('click', function(event) {
    const trigger = event.target.closest('[data-bs-toggle="modal"][data-bs-target]');
    if (!trigger) return;

    const parentModalElement = trigger.closest('.modal.show');
    if (!parentModalElement || !parentModalElement.id) return;

    const targetSelector = trigger.getAttribute('data-bs-target') || '';
    if (!targetSelector || targetSelector.charAt(0) !== '#') return;

    const targetModalId = targetSelector.substring(1);
    if (!targetModalId || targetModalId === parentModalElement.id) return;

    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') {
        event.stopImmediatePropagation();
    }

    motorpoolShowModalFromCurrent(targetModalId, { parentModalId: parentModalElement.id });
}, true);


// ========== SIDEBAR FUNCTIONS ==========
let isSidebarPinned = false;

function getSidebarMenuIds() {
    return [
        'warehouseMenu',
        'supplierMenu',
        'customerMenu',
        'deliveryMenu',
        'bankingMenu',
        'sharedServicesMenu'
    ];
}

function toggleSidebarDropdown(event, targetId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const sidebar = document.getElementById('sidebar');
    const target = document.getElementById(targetId);
    if (!target) return false;

    const clickedLink = event ? event.currentTarget : document.querySelector(`[onclick*="${targetId}"]`);
    const clickedArrow = clickedLink ? clickedLink.querySelector('.dropdown-arrow') : null;

    if (sidebar && sidebar.classList.contains('collapsed') && window.innerWidth > 992) {
        isSidebarPinned = true;
        sidebar.classList.remove('collapsed');

        const mainContent = document.getElementById('mainContent');
        if (mainContent) mainContent.classList.remove('expanded');

        localStorage.setItem('sidebarCollapsed', 'false');

        setTimeout(function () {
            openOnlySidebarMenu(targetId);
        }, 120);

        return false;
    }

    const willOpen = !target.classList.contains('show');

    getSidebarMenuIds().forEach(function(menuId) {
        const menu = document.getElementById(menuId);
        const menuLink = document.querySelector(`[onclick*="${menuId}"]`);
        const arrow = menuLink ? menuLink.querySelector('.dropdown-arrow') : null;

        if (menu && menuId !== targetId) {
            menu.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        }
    });

    target.classList.toggle('show', willOpen);
    if (clickedArrow) {
        clickedArrow.style.transform = willOpen ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
    }

    return false;
}

function openOnlySidebarMenu(targetId) {
    getSidebarMenuIds().forEach(function(menuId) {
        const menu = document.getElementById(menuId);
        const menuLink = document.querySelector(`[onclick*="${menuId}"]`);
        const arrow = menuLink ? menuLink.querySelector('.dropdown-arrow') : null;

        if (!menu) return;

        const shouldOpen = menuId === targetId;
        menu.classList.toggle('show', shouldOpen);
        if (arrow) {
            arrow.style.transform = shouldOpen ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
        }
    });
}

function expandCurrentMenu() {
    const currentFile = window.location.pathname.split('/').pop();
    const menuMap = {
        'current_inventory.php': 'warehouseMenu',
        'bad_orders.php': 'warehouseMenu',
        'pick_list_items.php': 'warehouseMenu',
        'warehouses.php': 'warehouseMenu',
        'purchase_order.php': 'supplierMenu',
        'supplier.php': 'supplierMenu',
        'customer_list.php': 'customerMenu',
        'approve_credit_requests.php': 'customerMenu',
        'sales_order.php': 'customerMenu',
        'collections.php': 'customerMenu',
        'trip_tickets.php': 'deliveryMenu',
        'deposit.php': 'bankingMenu',
        'Withdrawal.php': 'bankingMenu',
        'bank_statement.php': 'bankingMenu',
        'expenses.php': 'bankingMenu',
        'motorpool.php': 'sharedServicesMenu',
        'central_warehouse.php': 'sharedServicesMenu'
    };

    openOnlySidebarMenu(menuMap[currentFile] || 'sharedServicesMenu');

    document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
        const href = link.getAttribute('href');
        if (href === currentFile) {
            link.classList.add('active');
        } else if (href && href !== '#') {
            link.classList.remove('active');
        }
    });
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    if (!sidebar) return;

    if (window.innerWidth <= 992) {
        sidebar.classList.toggle('active');

        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);

            overlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                setTimeout(function() {
                    if (overlay && overlay.parentNode) overlay.remove();
                }, 250);
            });
        }

        setTimeout(function() {
            overlay.classList.toggle('active', sidebar.classList.contains('active'));
        }, 10);
    } else {
        isSidebarPinned = false;
        sidebar.classList.toggle('collapsed');
        if (mainContent) mainContent.classList.toggle('expanded', sidebar.classList.contains('collapsed'));
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');
    }
}

function initSidebarButtons() {
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');

    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }
    if (mobileToggleBtn) {
        mobileToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }
}

function initSidebarState() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (!sidebar) return;

    if (window.innerWidth > 992 && localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
        if (mainContent) mainContent.classList.add('expanded');
    }

    expandCurrentMenu();
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

document.addEventListener('DOMContentLoaded', function() {
    initSidebarButtons();
    initSidebarState();
});


function today(){ return new Date().toISOString().slice(0,10); }

// Initialize on page load
let selectedVehicleRow = null;

document.addEventListener('DOMContentLoaded', function() {
    const addBtn = document.getElementById('addVehicleBtn');
    if (addBtn) {
        addBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openVehicleModal();
        });
    }
});

function safeText(value) {
    return value && String(value).trim() !== '' ? String(value) : 'N/A';
}

function buildDetailCard(label, value) {
    return `<div class="detail-info-item"><small>${label}</small><strong>${safeText(value)}</strong></div>`;
}

function buildRisDetailItem(label, value) {
    return `<div class="detail-info-item"><small>${label}</small><strong>${safeText(value)}</strong></div>`;
}

function dataValue(row, key) {
    return row ? (row.dataset[key] || '') : '';
}

function getVehicleImageHtml(filename, plateNo) {
    if (!filename) return '<i class="bi bi-image text-muted fs-1"></i>';
    const src = '../uploads/motorpool/' + filename;
    return `<img src="${src}" alt="${plateNo || 'Vehicle Image'}" onerror="this.style.display='none';this.parentNode.innerHTML='<i class=&quot;bi bi-image text-muted fs-1&quot;></i>';">`;
}

function openVehicleModal(){
    const form = document.getElementById('vehicleForm');
    if (form) form.reset();
    document.getElementById('vehicleFormAction').value = 'add_vehicle';
    document.getElementById('vehicle_db_id').value = '';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-truck-front me-2"></i>Add Vehicle Profile';
    document.getElementById('vehicleSaveBtn').innerHTML = '<i class="bi bi-save me-1"></i>Save Vehicle';
    motorpoolShowModalFromCurrent('vehicleModal');
}

function fillVehicleFormFromRow(row) {
    if (!row) return;
    document.getElementById('vehicle_db_id').value = dataValue(row, 'dbId');
    const fields = ['ltoCrNo','dateRegistration','plateNo','engineNo','chassisNo','vin','fileNo','vehicleType','vehicleCategory','makeBrand','passengerCapacity','color','typeOfFuel','classification','bodyType','series','grossWeight','netWeight','yearModel','yearRebuilt','pistonDisplacement','maxPowerKw','regDate','orNo','nextRenewal'];
    fields.forEach(function(key){
        const inputId = key.replace(/[A-Z]/g, m => '_' + m.toLowerCase());
        const el = document.getElementById(inputId);
        if (el) el.value = dataValue(row, key);
    });
}

function openEditVehicleFromDetails() {
    if (!selectedVehicleRow) return;
    fillVehicleFormFromRow(selectedVehicleRow);
    document.getElementById('vehicleFormAction').value = 'edit_vehicle';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Vehicle Profile';
    document.getElementById('vehicleSaveBtn').innerHTML = '<i class="bi bi-save me-1"></i>Update Vehicle';
    motorpoolShowModalFromCurrent('vehicleModal', { parentModalId: 'vehicleDetailsModal' });
}

function saveVehicle(){
    const form = document.getElementById('vehicleForm');
    if (!form) return;
    const plateNo = form.querySelector('[name="plate_no"]')?.value.trim();
    if (!plateNo) return alert('Plate Number is required');
    saveSignature();
    const formData = new FormData(form);
    fetch('motorpool.php', { method: 'POST', body: formData }).then(r => r.text()).then(() => {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleModal')).hide();
        window.location.reload();
    }).catch(e => console.error('Error:', e));
}

function viewVehicleDetails(row){
    selectedVehicleRow = row;
    const plateNo = dataValue(row, 'plateNo');
    const makeBrand = dataValue(row, 'makeBrand');
    const vehicleType = dataValue(row, 'vehicleType');
    const vehicleImage = dataValue(row, 'vehicleImage');
    const vehicleId = dataValue(row, 'vehicleId');

    document.getElementById('detailVehicleImage').innerHTML = getVehicleImageHtml(vehicleImage, plateNo);
    document.getElementById('detailVehicleName').textContent = [makeBrand, vehicleType].filter(Boolean).join(' - ') || 'Vehicle Details';
    document.getElementById('detailPlateBadge').textContent = plateNo || 'No Plate No.';
    document.getElementById('detailVehicleSub').textContent = vehicleId ? 'Vehicle ID: ' + vehicleId : 'Vehicle information and registration records.';

    document.getElementById('overviewDetailsGrid').innerHTML = [
        ['Plate No.', plateNo], ['Make/Brand', makeBrand], ['Vehicle Type', vehicleType],
        ['Vehicle Category', dataValue(row, 'vehicleCategory')], ['Classification', dataValue(row, 'classification')], ['Body Type', dataValue(row, 'bodyType')],
        ['Color', dataValue(row, 'color')], ['Type of Fuel', dataValue(row, 'typeOfFuel')], ['Year Model', dataValue(row, 'yearModel')],
        ['Series', dataValue(row, 'series')], ['Passenger Capacity', dataValue(row, 'passengerCapacity')], ['Max Power (KW)', dataValue(row, 'maxPowerKw')],
        ['LTO CR No.', dataValue(row, 'ltoCrNo')], ['Date of Registration', dataValue(row, 'dateRegistration')], ['File No.', dataValue(row, 'fileNo')],
        ['Engine No.', dataValue(row, 'engineNo')], ['Chassis No.', dataValue(row, 'chassisNo')], ['VIN', dataValue(row, 'vin')],
        ['Gross Weight', dataValue(row, 'grossWeight')], ['Net Weight', dataValue(row, 'netWeight')], ['Year Rebuilt', dataValue(row, 'yearRebuilt')],
        ['Piston Displacement', dataValue(row, 'pistonDisplacement')]
    ].map(([label,value]) => buildDetailCard(label,value)).join('');

    renderVehicleRegistrationHistory(row);

    renderVehiclePictures(row);
    renderVehicleRepairHistory(row);
    renderVehicleRepairPayments(row);
    renderVehicleFuelMonitoring(row);
    motorpoolShowModalFromCurrent('vehicleDetailsModal');
}

function getRegistrationHistory(row) {
    const raw = row ? (row.dataset.registrationHistory || '') : '';
    if (!raw) return [];
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

function fileLinkHtml(filename, label = 'View') {
    if (!filename) return '<span class="text-muted">No attachment</span>';
    const safeFile = escapeHtml(filename);
    const safeLabel = escapeHtml(label);
    return `<button type="button" class="btn btn-link p-0 text-success fw-semibold text-decoration-none" onclick="openMotorpoolFilePreview('${safeFile}', '${safeLabel}')"><i class="bi bi-eye me-1"></i>${safeLabel}</button>`;
}

let motorpoolFilePreviewModal;

function getOpenMotorpoolParentModalId() {
    const modal = document.querySelector('.modal.show:not(#motorpoolFilePreviewModal)');
    return modal ? modal.id : '';
}

function openMotorpoolFilePreview(filename, title) {
    if (!filename) return;

    const cleanFile = String(filename)
        .replace(/^\.\.\/uploads\/motorpool\//, '')
        .replace(/^uploads\/motorpool\//, '')
        .replace(/^\/uploads\/motorpool\//, '');
    const src = '../uploads/motorpool/' + encodeURIComponent(cleanFile).replace(/%2F/g, '/');
    const ext = cleanFile.split('.').pop().toLowerCase();
    const previewBody = document.getElementById('motorpoolPreviewBody');
    const downloadLink = document.getElementById('motorpoolDownloadLink');
    const parentModalId = getOpenMotorpoolParentModalId();

    if (parentModalId) {
        sessionStorage.setItem('motorpoolReturnModalId', parentModalId);
        const parentModalElement = document.getElementById(parentModalId);
        const parentModal = bootstrap.Modal.getInstance(parentModalElement) || bootstrap.Modal.getOrCreateInstance(parentModalElement);
        parentModal.hide();
    } else {
        sessionStorage.removeItem('motorpoolReturnModalId');
    }

    if (downloadLink) {
        downloadLink.href = src;
        downloadLink.download = cleanFile;
    }

    if (previewBody) {
        previewBody.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';

        setTimeout(function() {
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                const img = document.createElement('img');
                img.src = src;
                img.alt = title || cleanFile;
                img.style.opacity = '0';
                img.onload = function() { img.style.opacity = '1'; };
                img.onerror = function() {
                    previewBody.innerHTML = `<div class="alert alert-warning m-0"><i class="bi bi-exclamation-triangle me-2"></i>Unable to load this image.</div>`;
                };
                previewBody.innerHTML = '';
                previewBody.appendChild(img);
            } else if (ext === 'pdf') {
                const embed = document.createElement('embed');
                embed.src = src;
                embed.type = 'application/pdf';
                previewBody.innerHTML = '';
                previewBody.appendChild(embed);
            } else {
                previewBody.innerHTML = `<div class="alert alert-info m-0"><i class="bi bi-info-circle me-2"></i>This file type cannot be previewed directly. Please download to view.</div>`;
            }
        }, 80);
    }

    if (!motorpoolFilePreviewModal) {
        motorpoolFilePreviewModal = new bootstrap.Modal(document.getElementById('motorpoolFilePreviewModal'));
    }

    const modalElement = document.getElementById('motorpoolFilePreviewModal');
    modalElement.removeEventListener('hidden.bs.modal', handleMotorpoolFilePreviewHidden);
    modalElement.addEventListener('hidden.bs.modal', handleMotorpoolFilePreviewHidden);

    setTimeout(function() {
        motorpoolFilePreviewModal.show();
    }, parentModalId ? 180 : 0);
}

function handleMotorpoolFilePreviewHidden() {
    requestAnimationFrame(function() {
        const previewBody = document.getElementById('motorpoolPreviewBody');
        if (previewBody) {
            previewBody.innerHTML = '<div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div>';
        }

        const returnModalId = sessionStorage.getItem('motorpoolReturnModalId');
        sessionStorage.removeItem('motorpoolReturnModalId');

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

function renderVehicleRegistrationHistory(row) {
    const body = document.getElementById('registrationHistoryBody');
    if (!body) return;

    const renewalRows = getRegistrationHistory(row);
    const originalRegistration = {
        or_no: dataValue(row, 'orNo'),
        reg_date: dataValue(row, 'regDate'),
        next_renewal: dataValue(row, 'nextRenewal'),
        or_attachment: dataValue(row, 'orAttachment'),
        created_at: 'Initial Registration',
        is_initial: true
    };

    const hasOriginalRegistration = Boolean(
        originalRegistration.or_no ||
        originalRegistration.reg_date ||
        originalRegistration.next_renewal ||
        originalRegistration.or_attachment
    );

    const rows = hasOriginalRegistration ? [...renewalRows, originalRegistration] : renewalRows;

    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">No registration information found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map(function(item){
        const typeBadge = item.is_initial
            ? '<span class="badge bg-primary">Initial</span>'
            : '<span class="badge bg-success">Renewal</span>';
        return `<tr>
            <td>${escapeHtml(item.or_no || '-')}<div class="mt-1">${typeBadge}</div></td>
            <td>${escapeHtml(item.reg_date || '-')}</td>
            <td>${escapeHtml(item.next_renewal || '-')}</td>
            <td>${fileLinkHtml(item.or_attachment || '')}</td>
            <td>${escapeHtml(item.created_at || '-')}</td>
        </tr>`;
    }).join('');
}

function openRenewRegistrationModal() {
    if (!selectedVehicleRow) return;
    document.getElementById('renewRegistrationForm').reset();
    document.getElementById('renewVehicleDbId').value = dataValue(selectedVehicleRow, 'dbId');
    document.getElementById('renewVehicleCode').value = dataValue(selectedVehicleRow, 'vehicleId');
    document.getElementById('renewPlateNo').value = dataValue(selectedVehicleRow, 'plateNo');
    document.getElementById('renewOrNo').value = '';
    document.getElementById('renewRegDate').value = today();
    document.getElementById('renewNextRenewal').value = '';
    motorpoolShowModalFromCurrent('renewRegistrationModal', { parentModalId: 'vehicleDetailsModal', returnTabTarget: '#vehicleRegistrationTab' });
}

function saveRenewRegistration() {
    const renewForm = document.getElementById("renewRegistrationForm");
    if (renewForm && !validateRenewRegistrationForm(renewForm)) return;
    const form = document.getElementById('renewRegistrationForm');
    if (!form) return;
    const formData = new FormData(form);
    fetch('motorpool.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return alert(data.message || 'Failed to save renewal.');
            const renewalModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('renewRegistrationModal'));
            renewalModal.hide();

            if (selectedVehicleRow) {
                let history = getRegistrationHistory(selectedVehicleRow);
                history.unshift({
                    or_no: data.or_no || '',
                    reg_date: data.reg_date || '',
                    next_renewal: data.next_renewal || '',
                    or_attachment: data.or_attachment || '',
                    created_at: data.created_at || ''
                });
                selectedVehicleRow.dataset.registrationHistory = JSON.stringify(history);
                selectedVehicleRow.dataset.orNo = data.or_no || '';
                selectedVehicleRow.dataset.regDate = data.reg_date || '';
                selectedVehicleRow.dataset.nextRenewal = data.next_renewal || '';
                if (data.or_attachment) selectedVehicleRow.dataset.orAttachment = data.or_attachment;
                renderVehicleRegistrationHistory(selectedVehicleRow);
            }

            setTimeout(function(){
                motorpoolShowModalFromCurrent('vehicleDetailsModal');
                const registrationTabBtn = document.querySelector('[data-bs-target="#vehicleRegistrationTab"]');
                if (registrationTabBtn) bootstrap.Tab.getOrCreateInstance(registrationTabBtn).show();
            }, 200);
        })
        .catch(e => {
            console.error('Error:', e);
            alert('Failed to save renewal. Please try again.');
        });
}

function renderVehiclePictures(row) {
    const grid = document.getElementById('vehicleImagesGrid');
    const items = [];
    const vehicleImage = dataValue(row, 'vehicleImage');
    const crImagesRaw = dataValue(row, 'crVehicleImages');

    if (vehicleImage) items.push({label:'Vehicle Image', file:vehicleImage});
    if (crImagesRaw) {
        try {
            const parsed = JSON.parse(crImagesRaw);
            if (Array.isArray(parsed)) parsed.forEach((file, index) => { if (file) items.push({label:'CR Image ' + (index + 1), file:file}); });
        } catch(e) {
            crImagesRaw.split(',').forEach((file, index) => { file = file.trim(); if (file) items.push({label:'CR Image ' + (index + 1), file:file}); });
        }
    }
    if (!items.length) {
        grid.innerHTML = '<div class="text-muted text-center py-4 w-100">No pictures uploaded for this vehicle.</div>';
        return;
    }

    grid.innerHTML = items.map(item => {
        const src = '../uploads/motorpool/' + item.file;
        const isPdf = item.file.toLowerCase().endsWith('.pdf');
        const preview = isPdf ? `<div class="d-flex align-items-center justify-content-center bg-light rounded" style="height:130px;cursor:pointer;" onclick="openMotorpoolFilePreview('${escapeHtml(item.file)}', '${escapeHtml(item.label)}')"><i class="bi bi-file-earmark-pdf fs-1 text-danger"></i></div>` : `<img src="${src}" alt="${escapeHtml(item.label)}" style="cursor:pointer;" onclick="openMotorpoolFilePreview('${escapeHtml(item.file)}', '${escapeHtml(item.label)}')" onerror="this.style.display='none';">`;
        return `<div class="vehicle-image-preview">${preview}<button type="button" class="btn btn-link p-0 mt-2 text-success fw-semibold text-decoration-none" onclick="openMotorpoolFilePreview('${escapeHtml(item.file)}', '${escapeHtml(item.label)}')"><i class="bi bi-eye me-1"></i>${escapeHtml(item.label)}</button></div>`;
    }).join('');
}


function parseJsonSafe(value, fallback) {
    if (fallback === undefined) fallback = [];
    if (value === null || value === undefined || value === '') return fallback;
    try { return JSON.parse(value); } catch (e) { return fallback; }
}


/* =========================================================
   Repair History / Detailed Workflow shared helpers
   Added from working Motorpool account file.
   These functions are required by renderVehicleRepairHistory()
   and renderWorkflowTimelineForBranch(). Without them, the
   Repair History tab stops rendering.
========================================================= */
function parseKeyValueLineV38(line) {
    const current = { quantity: '', item: '', description: '', specification: '', unit_cost: '', estimated_cost: '', repair_cost: '', cost_source: '', _quantity_source: '' };

    function setQuantityIfBetter(value, source) {
        value = String(value || '').trim();
        if (value === '') return;

        // Priority is important for Detailed Repair Workflow.
        // Actual Qty Used must not be replaced by Available / Received Qty.
        const priority = { used: 4, quantity: 3, needed: 2, available: 1 };
        const oldPriority = priority[current._quantity_source] || 0;
        const newPriority = priority[source] || 0;
        if (newPriority >= oldPriority) {
            current.quantity = value;
            current._quantity_source = source;
        }
    }

    String(line || '').split('|').forEach(function (segment) {
        const pair = segment.split(':');
        const key = String(pair.shift() || '').trim().toLowerCase();
        const val = pair.join(':').trim();

        if (key === 'qty used' || key === 'used qty' || key === 'quantity used' || key === 'qty to use' || key === 'quantity to use' || key === 'used quantity') setQuantityIfBetter(val, 'used');
        else if (key === 'quantity' || key === 'qty') setQuantityIfBetter(val, 'quantity');
        else if (key === 'needed qty' || key === 'needed quantity' || key === 'needed_quantity') setQuantityIfBetter(val, 'needed');
        else if (key === 'available qty' || key === 'available quantity' || key === 'available_quantity' || key === 'received qty' || key === 'received quantity' || key === 'received_quantity' || key === 'available / received qty' || key === 'available / received quantity' || key === 'available/received qty' || key === 'available/received quantity') setQuantityIfBetter(val, 'available');

        if (key === 'item' || key === 'item no.' || key === 'item no' || key === 'item_no' || key === 'item number') current.item = val;
        if (key === 'description') current.description = val;
        if (key === 'specification' || key === 'specs' || key === 'spec') current.specification = val;
        if (key === 'unit cost' || key === 'unit_cost' || key === 'cost') current.unit_cost = val;
        if (key === 'estimated cost' || key === 'estimated_cost' || key === 'estimated total cost' || key === 'estimated_total_cost' || key === 'total cost' || key === 'total_cost') current.estimated_cost = val;
        if (key === 'repair cost' || key === 'repair_cost' || key === 'labor cost' || key === 'labor_cost') current.repair_cost = val;
        if (key === 'cost source' || key === 'cost_source' || key === 'source') current.cost_source = val;
    });
    return current;
}

function parsePartsReplacedRowsV21(value) {
    const rows = [];
    const rawValue = String(value || '').trim();
    if (rawValue.startsWith('[') || rawValue.startsWith('{')) {
        const parsed = parseJsonSafe(rawValue, null);
        const list = Array.isArray(parsed) ? parsed : (parsed ? [parsed] : []);
        list.forEach(function (part) {
            if (!part || typeof part !== 'object') return;
            rows.push({
                quantity: part.available_quantity || part.available_qty || part.received_quantity || part.received_qty || part.used_quantity || part.qty_used || part.qty_to_use || part.quantity_to_use || part.quantity_used || part.quantity || part.qty || part.needed_quantity || part.needed_qty || '',
                item: part.item_no || part.item_code || part.item || part.item_number || '',
                description: part.description || part.part_description || part.item_description || part.desc || part.item_name || '',
                specification: part.specification || part.part_specification || part.item_specification || part.specs || part.spec || part.unit_type || '',
                unit_cost: part.unit_cost || part.cost || '',
                estimated_cost: part.estimated_total_cost || part.estimated_cost || part.total_cost || '',
                repair_cost: part.repair_cost || part.labor_cost || part.service_cost || '',
                cost_source: part.cost_source || part.source || ''
            });
        });
        if (rows.length) return rows;
    }
    String(value || '').split(/\n+/).forEach(function (line) {
        let cleanLine = String(line || '').trim();
        if (!cleanLine) return;
        cleanLine = cleanLine.replace(/^Parts\s+Replaced\s*:\s*/i, '');
        cleanLine = cleanLine.replace(/^Items\s*\/\s*Parts\s+Needed\s*:\s*/i, '');
        cleanLine = cleanLine.replace(/^Part\s*\d+\s*:\s*/i, '');
        cleanLine = cleanLine.replace(/^Item\s*\d+\s*:\s*/i, '');

        const current = parseKeyValueLineV38(cleanLine);
        if (current.quantity || current.item || current.description || current.specification || current.unit_cost || current.estimated_cost || current.repair_cost || current.cost_source) rows.push(current);
    });
    return rows;
}

function formatPesoV41(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const cleaned = raw.replace(/[₱,]/g, '').trim();
    if (cleaned !== '' && !isNaN(Number(cleaned))) {
        return '₱' + Number(cleaned).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    return raw;
}

function renderPartsTableV23(rows, options) {
    if (!rows || !rows.length) return '';
    options = options || {};
    const isForRelease = !!options.is_for_release;
    const hasUnitCost = rows.some(function(part) {
        return String(part.unit_cost || '').trim() !== '';
    });
    const hasCost = rows.some(function(part) {
        return String(part.estimated_cost || '').trim() !== '';
    });
    const hasRepairCost = rows.some(function(part) {
        return String(part.repair_cost || '').trim() !== '';
    });
    let totalPartsCost = 0;
    let totalRepairCost = 0;
    let hasNumericTotal = false;

    const bodyRows = rows.map(function (part) {
        const computedEstimated = computeEstimatedCostFromQtyAndUnitV44(part.quantity || '', part.unit_cost || '');
        const displayEstimated = computedEstimated || part.estimated_cost || '';
        const estimatedRaw = String(displayEstimated || '').replace(/[₱,]/g, '').trim();
        if (estimatedRaw !== '' && !isNaN(Number(estimatedRaw))) {
            totalPartsCost += Number(estimatedRaw);
            hasNumericTotal = true;
        }
        const repairRaw = String(part.repair_cost || '').replace(/[₱,]/g, '').trim();
        if (repairRaw !== '' && !isNaN(Number(repairRaw))) {
            totalRepairCost += Number(repairRaw);
            hasNumericTotal = true;
        }
        return '<tr>'
            + '<td>' + escapeHtml(part.quantity || '') + '</td>'
            + '<td>' + escapeHtml(part.item || '') + '</td>'
            + '<td>' + escapeHtml(part.description || '') + '</td>'
            + '<td>' + escapeHtml(part.specification || '') + '</td>'
            + (hasUnitCost ? '<td>' + escapeHtml(formatPesoV41(part.unit_cost || '')) + '</td>' : '')
            + (hasCost ? '<td>' + escapeHtml(formatPesoV41(displayEstimated)) + '</td>' : '')
            + (hasRepairCost ? '<td>' + escapeHtml(formatPesoV41(part.repair_cost || '')) + '</td>' : '')
            + '</tr>';
    }).join('');

    const colCount = 4 + (hasUnitCost ? 1 : 0) + (hasCost ? 1 : 0) + (hasRepairCost ? 1 : 0);
    const footer = (isForRelease && hasNumericTotal)
        ? '<tfoot><tr>'
            + '<td colspan="' + Math.max(colCount - 1, 1) + '" class="text-end fw-semibold">Total Cost</td>'
            + '<td class="fw-semibold">' + escapeHtml(formatPesoV41((totalPartsCost + totalRepairCost).toFixed(2))) + '</td>'
            + '</tr></tfoot>'
        : '';

    return '<div class="table-responsive parts-replaced-mini-table-wrap mt-2 mb-2">'
        + '<table class="table table-bordered table-sm align-middle mb-0 parts-replaced-mini-table">'
        + '<thead><tr>'
        + '<th>Quantity</th>'
        + '<th>Item</th>'
        + '<th>Description</th>'
        + '<th>Specification</th>'
        + (hasUnitCost ? '<th>Unit Cost</th>' : '')
        + (hasCost ? '<th>' + (isForRelease ? 'Cost' : 'Estimated Cost') + '</th>' : '')
        + (hasRepairCost ? '<th>Repair Cost</th>' : '')
        + '</tr></thead><tbody>'
        + bodyRows
        + '</tbody>'
        + footer
        + '</table></div>';
}

function renderPartsReplacedColumnsV21(value, repairCost) {
    const rows = parsePartsReplacedRowsV21(value);
    // Do not use vehicle_repair_history.repair_cost as a per-part Repair Cost.
    // That field can contain the whole RIS total, which makes the same large amount
    // appear on every parts row. Per-row Repair Cost is now taken from the assessment
    // repair_cost/labor_cost connected to each repair item.
    if (!rows.length) return '<div class="repair-history-text">' + nl2brEscapeV38(value || '') + '</div>';
    return renderPartsTableV23(rows);
}

function partsReplacedTextForTimelineV21(value) {
    const rows = parsePartsReplacedRowsV21(value);
    if (!rows.length) return value || '';
    return rows.map(function (part, index) {
        let line = 'Part ' + (index + 1)
            + ': Quantity: ' + (part.quantity || 'N/A')
            + ' | Item: ' + (part.item || 'N/A')
            + ' | Description: ' + (part.description || 'N/A')
            + ' | Specification: ' + (part.specification || 'N/A');
        if (part.unit_cost) line += ' | Unit Cost: ' + part.unit_cost;
        if (part.estimated_cost) line += ' | Estimated Cost: ' + part.estimated_cost;
        if (part.repair_cost) line += ' | Repair Cost: ' + part.repair_cost;
        return line;
    }).join('\n');
}

function nl2brEscapeV38(value) {
    return escapeHtml(value || '').replace(/\n/g, '<br>');
}

function splitWorkflowSegmentsV29(line) {
    const segments = [];
    let current = '';
    String(line || '').split('|').forEach(function (part) {
        const text = String(part || '').trim();
        if (!text) return;
        const keyLike = /^[A-Za-z][A-Za-z\s\/]*\s*:/.test(text);
        if (keyLike || current === '') {
            if (current) segments.push(current);
            current = text;
        } else {
            current += ' | ' + text;
        }
    });
    if (current) segments.push(current);
    return segments;
}

function parseRepairProgressRowsV38(value) {
    const rows = [];
    String(value || '').split(/\n+/).forEach(function (line) {
        let cleanLine = String(line || '').trim();
        if (!cleanLine || cleanLine.indexOf('|') === -1) return;
        cleanLine = cleanLine.replace(/^Repair\s+\d+\s*:\s*/i, '');

        const row = {
            repair: '',
            type: '',
            start: '',
            end: '',
            date: '',
            mechanic: '',
            status: '',
            parts_text: '',
            parts_rows: []
        };

        splitWorkflowSegmentsV29(cleanLine).forEach(function (segment) {
            const idx = segment.indexOf(':');
            if (idx === -1) return;
            const key = segment.substring(0, idx).trim().toLowerCase();
            const val = segment.substring(idx + 1).trim();
            if (key === 'repair' || key === 'repair to make' || key === 'repairs done') row.repair = val;
            if (key === 'type' || key === 'repair type') row.type = val;
            if (key === 'start' || key === 'date started' || key === 'start date/time' || key === 'start datetime' || key === 'start date') row.start = val;
            if (key === 'end' || key === 'date updated' || key === 'end date/time' || key === 'end datetime' || key === 'end date') row.end = val;
            if (key === 'date' || key === 'repair date') row.date = val;
            if (key === 'mechanic') row.mechanic = val;
            if (key === 'status' || key === 'completion' || key === 'start selection') row.status = val;
            if (key === 'parts' || key === 'parts used' || key === 'parts replaced / used') row.parts_text = val;
        });

        if (!row.start && row.date) row.start = row.date;
        if (!row.status) {
            const lowerLine = cleanLine.toLowerCase();
            if (lowerLine.includes('status: done') || lowerLine.includes('completion: done')) row.status = 'Done';
            else if (row.end && row.end !== '-') row.status = 'Done';
            else if (row.start && row.start !== '-' && row.start.toLowerCase() !== 'not started') row.status = 'Ongoing';
            else row.status = 'Pending';
        }
        if (!row.type) row.type = cleanLine.toLowerCase().includes('with parts') ? 'With Parts' : 'Labor Only';

        const partRows = [];
        if (row.parts_text) {
            row.parts_text.split(';').forEach(function (partLine) {
                const parsed = parseKeyValueLineV38(String(partLine || '').trim());
                if (parsed.quantity || parsed.item || parsed.description || parsed.specification) partRows.push(parsed);
            });
        }
        row.parts_rows = partRows;

        if (row.repair) rows.push(row);
    });
    return rows;
}

function renderOngoingPartsMiniTableV29(rows, fallbackText) {
    if (rows && rows.length) return renderPartsTableV23(rows);
    const text = String(fallbackText || '').trim();
    if (!text || text.toLowerCase() === 'labor only') return '<span class="badge bg-success-subtle text-success border border-success-subtle">Labor only</span>';
    return '<div class="repair-history-text">' + escapeHtml(text) + '</div>';
}

// v30 actual table renderer for Ongoing Repair history in Detailed Repair Workflow
function renderRepairProgressTableV38(rows) {
    if (!rows || !rows.length) return '';

    // Ongoing Repair table should only show the repair log details.
    // Parts are intentionally removed from this table because they are already
    // rendered below as one clean "Parts Replaced / Used" table with quantity,
    // item, description, and specification.
    return '<div class="table-responsive repair-progress-table-wrap mt-2 mb-2">'
        + '<table class="table table-bordered table-sm align-middle mb-0 repair-progress-table ongoing-workflow-table-v31">'
        + '<thead><tr>'
        + '<th>Repair To Make</th>'
        + '<th>Repair Type</th>'
        + '<th>Mechanic</th>'
        + '<th>Start Date/Time</th>'
        + '<th>End Date/Time</th>'
        + '<th>Status</th>'
        + '</tr></thead><tbody>'
        + rows.map(function (row) {
            const statusText = String(row.status || '').toLowerCase();
            const badgeClass = statusText.includes('done') || statusText.includes('complete') ? 'bg-success' : (statusText.includes('pending') || statusText.includes('not') ? 'bg-secondary' : 'bg-warning text-dark');
            return '<tr>'
                + '<td class="fw-semibold">' + escapeHtml(row.repair || '') + '</td>'
                + '<td>' + escapeHtml(row.type || '') + '</td>'
                + '<td>' + escapeHtml(row.mechanic || '') + '</td>'
                + '<td>' + escapeHtml(row.start || '') + '</td>'
                + '<td>' + escapeHtml(row.end || '') + '</td>'
                + '<td><span class="badge ' + badgeClass + '">' + escapeHtml(row.status || '') + '</span></td>'
                + '</tr>';
        }).join('')
        + '</tbody></table></div>';
}


function parseQualityCheckRowsV32(value, lookup) {
    const rows = [];
    String(value || '').split(/\n+/).forEach(function (line) {
        const cleanLine = String(line || '').trim();
        if (!cleanLine || !/^Repair\s*:/i.test(cleanLine)) return;

        let repairText = cleanLine;
        let partsText = '';
        const partsIndex = cleanLine.toLowerCase().indexOf('| parts used:');
        if (partsIndex !== -1) {
            repairText = cleanLine.substring(0, partsIndex).trim();
            partsText = cleanLine.substring(partsIndex + '| parts used:'.length).trim();
        }

        repairText = repairText.replace(/^Repair\s*:\s*/i, '').trim();
        const partRows = [];
        if (partsText && !['none', 'labor only', '-'].includes(partsText.toLowerCase())) {
            partsText.split(';').forEach(function (partLine) {
                const parsed = parseKeyValueLineV38(String(partLine || '').trim());
                if (parsed.quantity || parsed.item || parsed.description || parsed.specification) partRows.push(parsed);
            });
        }

        rows.push({
            repair: repairText,
            parts_text: partsText,
            parts_rows: enrichPartsRowsWithLookupV24(partRows, lookup || {})
        });
    });
    return rows;
}

function renderQualityCheckTableV32(rows) {
    if (!rows || !rows.length) return '';
    return '<div class="table-responsive repair-progress-table-wrap mt-2 mb-2">'
        + '<table class="table table-bordered table-sm align-middle mb-0 repair-progress-table quality-check-table-v32">'
        + '<thead><tr>'
        + '<th>Repair Checked</th>'
        + '<th>Parts Checked / Used</th>'
        + '</tr></thead><tbody>'
        + rows.map(function (row) {
            const noParts = !row.parts_rows || !row.parts_rows.length;
            const partsHtml = noParts
                ? '<span class="badge bg-success-subtle text-success border border-success-subtle">No parts used</span>'
                : renderPartsTableV23(row.parts_rows);
            return '<tr>'
                + '<td class="fw-semibold">' + escapeHtml(row.repair || '') + '</td>'
                + '<td>' + partsHtml + '</td>'
                + '</tr>';
        }).join('')
        + '</tbody></table></div>';
}

function parseRepairCostSummaryRowsV43(value) {
    const rows = [];
    String(value || '').split(/\n+/).forEach(function (line) {
        let cleanLine = String(line || '').trim();
        if (!cleanLine) return;
        cleanLine = cleanLine.replace(/^Repairs\s+to\s+Make\s*:\s*/i, '').trim();
        if (!cleanLine) return;

        const row = {
            repair: '',
            repair_cost: '',
            parts_estimated_cost: '',
            repair_total_cost: ''
        };

        const segments = cleanLine.split('|').map(function (part) { return String(part || '').trim(); }).filter(Boolean);
        if (!segments.length) return;

        segments.forEach(function (segment, index) {
            const idx = segment.indexOf(':');
            if (idx === -1) {
                if (index === 0 && !row.repair) row.repair = segment.trim();
                return;
            }
            const key = segment.substring(0, idx).trim().toLowerCase();
            const val = segment.substring(idx + 1).trim();
            if (key === 'repair' || key === 'repair to make' || key === 'repairs done') row.repair = val;
            else if (key === 'repair cost' || key === 'labor cost' || key === 'repair_cost' || key === 'labor_cost') row.repair_cost = val;
            else if (key === 'parts estimated cost' || key === 'parts estimated' || key === 'parts_estimated_cost') row.parts_estimated_cost = val;
            else if (key === 'repair total cost' || key === 'total repair cost' || key === 'repair_total_cost') row.repair_total_cost = val;
            else if (index === 0 && !row.repair) row.repair = segment.trim();
        });

        if (!row.repair && segments[0]) row.repair = segments[0];
        if (row.repair || row.repair_cost || row.parts_estimated_cost || row.repair_total_cost) rows.push(row);
    });
    return rows;
}

function renderRepairCostSummaryTableV43(rows) {
    if (!rows || !rows.length) return '';
    return '<div class="table-responsive repair-progress-table-wrap mt-2 mb-2">'
        + '<table class="table table-bordered table-sm align-middle mb-0 repair-progress-table repairs-to-make-table-v43">'
        + '<thead><tr>'
        + '<th>Repair To Make</th>'
        + '<th>Repair Cost</th>'
        + '<th>Parts Estimated Cost</th>'
        + '<th>Total Repair Cost</th>'
        + '</tr></thead><tbody>'
        + rows.map(function (row) {
            return '<tr>'
                + '<td class="fw-semibold">' + escapeHtml(row.repair || '') + '</td>'
                + '<td>' + escapeHtml(formatPesoV41(row.repair_cost || '')) + '</td>'
                + '<td>' + escapeHtml(formatPesoV41(row.parts_estimated_cost || '')) + '</td>'
                + '<td>' + escapeHtml(formatPesoV41(row.repair_total_cost || '')) + '</td>'
                + '</tr>';
        }).join('')
        + '</tbody></table></div>';
}


function parseCostSummaryValueLineV50(line) {
    const text = String(line || '').trim();
    const idx = text.indexOf(':');
    if (idx === -1) return null;
    const label = text.substring(0, idx).trim();
    const value = text.substring(idx + 1).trim();
    const lower = label.toLowerCase();
    if (lower === 'repair cost' || lower === 'item cost' || lower === 'miscellaneous cost' || lower === 'grand total' || lower === 'grand total cost') {
        return { label: label, value: value, isGrand: lower === 'grand total' || lower === 'grand total cost' };
    }
    return null;
}

function parseMiscellaneousDetailsV50(line) {
    const text = String(line || '').trim();
    const idx = text.indexOf(':');
    const raw = idx === -1 ? text : text.substring(idx + 1).trim();
    if (!raw) return [];
    return raw.split(';').map(function (entry) {
        const clean = String(entry || '').trim();
        if (!clean) return null;
        let left = clean;
        let cost = '';
        const dashMatch = clean.match(/^(.*?)\s*-\s*₱?\s*([0-9,]+(?:\.\d+)?)\s*$/);
        if (dashMatch) {
            left = dashMatch[1].trim();
            cost = dashMatch[2].trim();
        }
        let description = left;
        let repair = '';
        const repairMatch = left.match(/^(.*?)\s*\((.*?)\)\s*$/);
        if (repairMatch) {
            description = repairMatch[1].trim();
            repair = repairMatch[2].trim();
        }
        return { description: description || 'Miscellaneous', repair: repair, cost: cost };
    }).filter(Boolean);
}

function renderMiscellaneousTableV50(items) {
    if (!items || !items.length) return '';
    return '<div class="fw-semibold mt-2 mb-1">Miscellaneous Details:</div>'
        + '<div class="table-responsive repair-progress-table-wrap mt-2 mb-2">'
        + '<table class="table table-bordered table-sm align-middle mb-0 repair-progress-table miscellaneous-cost-table-v50">'
        + '<thead><tr><th>Description</th><th>Repair</th><th>Cost</th></tr></thead><tbody>'
        + items.map(function (item) {
            return '<tr>'
                + '<td>' + escapeHtml(item.description || 'Miscellaneous') + '</td>'
                + '<td>' + escapeHtml(item.repair || 'N/A') + '</td>'
                + '<td class="text-end fw-semibold">' + escapeHtml(formatPesoV41(item.cost || '0')) + '</td>'
                + '</tr>';
        }).join('')
        + '</tbody></table></div>';
}

function renderCostSummaryTableV50(rows) {
    if (!rows || !rows.length) return '';
    return '<div class="fw-semibold mt-2 mb-1">Cost Summary:</div>'
        + '<div class="table-responsive repair-progress-table-wrap mt-2 mb-2">'
        + '<table class="table table-bordered table-sm align-middle mb-0 repair-progress-table cost-summary-table-v50">'
        + '<thead><tr><th>Cost Type</th><th>Amount</th></tr></thead><tbody>'
        + rows.map(function (row) {
            const grandClass = row.isGrand ? ' class="table-success fw-bold"' : '';
            return '<tr' + grandClass + '>'
                + '<td>' + escapeHtml(row.label || '') + '</td>'
                + '<td class="text-end fw-semibold">' + escapeHtml(formatPesoV41(row.value || '0')) + '</td>'
                + '</tr>';
        }).join('')
        + '</tbody></table></div>';
}

function parseCostSummaryBlockV50(lines) {
    const rows = [];
    const miscItems = [];
    (lines || []).forEach(function (line) {
        const text = String(line || '').trim();
        if (!text || text.toLowerCase() === 'cost summary:') return;
        const lower = text.toLowerCase();
        if (lower.startsWith('miscellaneous details:')) {
            parseMiscellaneousDetailsV50(text).forEach(function (item) { miscItems.push(item); });
            return;
        }
        const row = parseCostSummaryValueLineV50(text);
        if (row) rows.push(row);
    });
    return { rows: rows, miscItems: miscItems };
}

function isCostSummaryLineV50(line) {
    const lower = String(line || '').trim().toLowerCase();
    return lower === 'cost summary:'
        || lower.startsWith('repair cost:')
        || lower.startsWith('item cost:')
        || lower.startsWith('miscellaneous cost:')
        || lower.startsWith('miscellaneous details:')
        || lower.startsWith('grand total:')
        || lower.startsWith('grand total cost:');
}

function isWorkflowPartLineV23(line) {
    const cleanLine = String(line || '').trim();
    if (!cleanLine || cleanLine.indexOf('|') === -1) return false;
    const value = cleanLine.toLowerCase();
    return value.includes('quantity:')
        || value.includes('qty:')
        || value.includes('item no.:')
        || value.includes('item no:')
        || value.includes('item:')
        || value.includes('description:')
        || value.includes('specification:')
        || value.includes('unit cost:')
        || value.includes('estimated cost:')
        || value.includes('cost source:');
}

function isRepairProgressLineV38(line) {
    const cleanLine = String(line || '').trim();
    if (!cleanLine || cleanLine.indexOf('|') === -1) return false;
    const value = cleanLine.toLowerCase();

    // Ongoing Repair history can be saved in different summary formats, for example:
    // Repair: ... | Type: ... | Start: ... | End: ... | Mechanic: ... | Status: ... | Parts: ...
    // Repair: ... | Repair Type: ... | Date Started: ... | Mechanic: ... | Completion: ...
    // The old checker only accepted Date Started/Date Updated, so lines with Start/End were printed as plain text.
    const hasRepair = value.includes('repair:') || value.includes('repair to make:') || value.includes('repairs done:');
    const hasProgressField = value.includes('type:')
        || value.includes('repair type:')
        || value.includes('start:')
        || value.includes('date started:')
        || value.includes('start date/time:')
        || value.includes('end:')
        || value.includes('date updated:')
        || value.includes('end date/time:')
        || value.includes('mechanic:')
        || value.includes('status:')
        || value.includes('completion:')
        || value.includes('start selection:')
        || value.includes('parts:')
        || value.includes('parts used:')
        || value.includes('parts replaced / used:');

    return hasRepair && hasProgressField;
}


function buildWorkflowPartLookupV24(histories, risNumber, repairHistories) {
    const lookup = {};
    const wantedRis = String(risNumber || '').trim();

    function saveLookup(part) {
        if (!part) return;
        const itemValue = String(part.item || part.item_no || part.item_number || '').trim();
        const descriptionValue = String(part.description || part.part_description || part.item_description || '').trim();
        const specificationValue = String(part.specification || part.part_specification || part.item_specification || part.specs || part.spec || '').trim();
        if (!itemValue && !descriptionValue && !specificationValue) return;

        const data = {
            item: itemValue,
            description: descriptionValue,
            specification: specificationValue,
            unit_cost: String(part.unit_cost || part.unitCost || '').trim(),
            estimated_cost: String(part.estimated_cost || part.estimated_total_cost || part.total_cost || '').trim(),
            repair_cost: String(part.repair_cost || part.labor_cost || part.service_cost || '').trim(),
            cost_source: String(part.cost_source || part.source || '').trim()
        };

        const keys = [];
        if (itemValue) keys.push(itemValue.toLowerCase());
        if (descriptionValue) keys.push(descriptionValue.toLowerCase());
        keys.forEach(function (key) {
            if (!key) return;
            lookup[key] = Object.assign({}, lookup[key] || {}, data);
        });
    }

    (Array.isArray(histories) ? histories : []).forEach(function (item) {
        if (wantedRis && String(item.ris_number || '').trim() !== wantedRis) return;
        parsePartsReplacedRowsV21(String(item.details || '')).forEach(saveLookup);
        parsePartsReplacedRowsV21(String(item.parts_replaced || '')).forEach(saveLookup);
    });

    (Array.isArray(repairHistories) ? repairHistories : []).forEach(function (item) {
        if (wantedRis && String(item.ris_number || '').trim() !== wantedRis) return;
        parsePartsReplacedRowsV21(String(item.parts_replaced || '')).forEach(saveLookup);
        parsePartsReplacedRowsV21(String(item.details || '')).forEach(saveLookup);
    });

    return lookup;
}

function numericMoneyValueV44(value) {
    const cleaned = String(value || '').replace(/[₱,]/g, '').trim();
    if (cleaned === '' || isNaN(Number(cleaned))) return null;
    return Number(cleaned);
}

function computeEstimatedCostFromQtyAndUnitV44(quantity, unitCost) {
    const qty = numericMoneyValueV44(quantity);
    const unit = numericMoneyValueV44(unitCost);
    if (qty === null || unit === null) return '';
    return (qty * unit).toFixed(2);
}

function enrichPartsRowsWithLookupV24(rows, lookup) {
    return (rows || []).map(function (part) {
        const key = String(part.item || '').trim().toLowerCase();
        const byItem = key ? (lookup[key] || null) : null;
        const quantity = part.quantity || '';
        const unitCost = part.unit_cost || (byItem ? byItem.unit_cost : '');

        // Keep Detailed Workflow consistent:
        // Estimated Cost must follow the displayed quantity and unit cost.
        // This prevents assessment-needed qty totals from appearing beside actual Qty Used.
        const computedEstimatedCost = computeEstimatedCostFromQtyAndUnitV44(quantity, unitCost);

        return {
            quantity: quantity,
            item: part.item || (byItem ? byItem.item : ''),
            description: part.description || (byItem ? byItem.description : ''),
            specification: part.specification || (byItem ? byItem.specification : ''),
            unit_cost: unitCost,
            estimated_cost: computedEstimatedCost || part.estimated_cost || (byItem ? byItem.estimated_cost : ''),
            repair_cost: part.repair_cost || (byItem ? byItem.repair_cost : ''),
            cost_source: part.cost_source || (byItem ? byItem.cost_source : '')
        };
    });
}

function formatWorkflowDetailsWithLookupV24(details, lookup, workflowStage) {
    const lines = String(details || '').split(/\n/);
    const html = [];
    const normalizedStageForDetails = normalizeWorkflowStatusForBranch(workflowStage || '');
    const isForPartsCompletionDetails = normalizedStageForDetails === 'For Parts Completion';
    let partBuffer = [];
    let repairProgressBuffer = [];
    let qualityCheckBuffer = [];
    let repairsToMakeBuffer = [];
    let costSummaryBuffer = [];
    let captureCostSummaryRows = false;
    let captureQualityCheckRows = false;
    let captureRepairsToMakeRows = false;
    let qualityCheckPartsRendered = false;
    let skipDuplicateQualityParts = false;

    function flushParts() {
        if (!partBuffer.length) return;
        let rows = parsePartsReplacedRowsV21(partBuffer.join('\n'));
        // For Parts Completion must show the assessed/available quantity and the original
        // estimated cost. Do not enrich from actual used parts lookup here, because that
        // can replace the assessed cost with the later used-parts cost.
        if (!isForPartsCompletionDetails) {
            rows = enrichPartsRowsWithLookupV24(rows, lookup || {});
        }
        if (rows.length) html.push(renderPartsTableV23(rows, { is_for_release: normalizedStageForDetails === 'For Release' }));
        else html.push('<div>' + nl2brEscapeV38(partBuffer.join('\n')) + '</div>');
        partBuffer = [];
    }

    function flushRepairProgress() {
        if (!repairProgressBuffer.length) return;
        const rows = parseRepairProgressRowsV38(repairProgressBuffer.join('\n'));
        if (rows.length) html.push(renderRepairProgressTableV38(rows));
        else html.push('<div>' + nl2brEscapeV38(repairProgressBuffer.join('\n')) + '</div>');
        repairProgressBuffer = [];
    }

    function flushRepairsToMakeRows() {
        if (!repairsToMakeBuffer.length) return;
        const rows = parseRepairCostSummaryRowsV43(repairsToMakeBuffer.join('\n'));
        if (rows.length) html.push(renderRepairCostSummaryTableV43(rows));
        else html.push('<div>' + nl2brEscapeV38(repairsToMakeBuffer.join('\n')) + '</div>');
        repairsToMakeBuffer = [];
    }


    function flushCostSummaryRows() {
        if (!costSummaryBuffer.length) return;
        const parsed = parseCostSummaryBlockV50(costSummaryBuffer);
        const hideMiscellaneousDetailsTable = normalizedStageForDetails === 'For Release';
        if (parsed.miscItems.length && !hideMiscellaneousDetailsTable) html.push(renderMiscellaneousTableV50(parsed.miscItems));
        if (parsed.rows.length) html.push(renderCostSummaryTableV50(parsed.rows));
        if (!parsed.miscItems.length && !parsed.rows.length) html.push('<div>' + nl2brEscapeV38(costSummaryBuffer.join('\n')) + '</div>');
        costSummaryBuffer = [];
    }

    function flushQualityCheckRows() {
        if (!qualityCheckBuffer.length) return;
        const rows = parseQualityCheckRowsV32(qualityCheckBuffer.join('\n'), lookup || {});
        if (rows.length) {
            html.push(renderQualityCheckTableV32(rows));
            qualityCheckPartsRendered = true;
        } else {
            html.push('<div>' + nl2brEscapeV38(qualityCheckBuffer.join('\n')) + '</div>');
        }
        qualityCheckBuffer = [];
    }

    lines.forEach(function (line) {
        let current = String(line || '').trim();
        if (!current) {
            flushParts();
            flushRepairProgress();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            flushCostSummaryRows();
            captureQualityCheckRows = false;
            captureRepairsToMakeRows = false;
            captureCostSummaryRows = false;
            html.push('<div class="my-1"></div>');
            return;
        }

        const lower = current.toLowerCase();

        if (captureCostSummaryRows) {
            if (isCostSummaryLineV50(current)) {
                costSummaryBuffer.push(current);
                return;
            }
            flushCostSummaryRows();
            captureCostSummaryRows = false;
        }

        if (lower === 'cost summary:' || isCostSummaryLineV50(current)) {
            flushParts();
            flushRepairProgress();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            captureCostSummaryRows = true;
            costSummaryBuffer.push(current);
            return;
        }

        if (skipDuplicateQualityParts) {
            if (isWorkflowPartLineV23(current)) {
                return;
            }
            skipDuplicateQualityParts = false;
        }

        if (lower.startsWith('repairs to make:')) {
            flushParts();
            flushRepairProgress();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            flushCostSummaryRows();
            captureRepairsToMakeRows = true;
            // In For Parts Completion, hide the Repairs to Make table.
            // This step should only show Available / Received Parts.
            if (!isForPartsCompletionDetails) {
                html.push('<div class="fw-semibold mt-2 mb-1">Repairs to Make:</div>');
            }
            const rest = current.substring(current.indexOf(':') + 1).trim();
            if (rest && !isForPartsCompletionDetails) repairsToMakeBuffer.push(rest);
            return;
        }

        if (captureRepairsToMakeRows) {
            const startsNextSection = lower.startsWith('items / parts needed:')
                || lower.startsWith('available / received parts:')
                || lower.startsWith('parts replaced:')
                || lower.startsWith('parts used:')
                || lower.startsWith('parts replaced / used:')
                || lower.startsWith('completed repairs checked:');
            if (!startsNextSection) {
                if (!isForPartsCompletionDetails) repairsToMakeBuffer.push(current);
                return;
            }
            flushRepairsToMakeRows();
            captureRepairsToMakeRows = false;
        }

        if (lower.startsWith('completed repairs checked:')) {
            flushParts();
            flushRepairProgress();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            flushCostSummaryRows();
            captureQualityCheckRows = true;
            html.push('<div class="fw-semibold mt-2 mb-1">Completed Repairs Checked:</div>');
            const rest = current.substring(current.indexOf(':') + 1).trim();
            if (rest) qualityCheckBuffer.push(rest);
            return;
        }

        if (captureQualityCheckRows && /^repair\s*:/i.test(current)) {
            qualityCheckBuffer.push(current);
            return;
        } else if (captureQualityCheckRows) {
            flushQualityCheckRows();
            captureQualityCheckRows = false;
        }
        const prefixedParts = lower.startsWith('parts replaced:')
            || lower.startsWith('parts used:')
            || lower.startsWith('parts replaced / used:')
            || lower.startsWith('items / parts needed:');
        if (prefixedParts) {
            const isDuplicateQualityPartsSection = qualityCheckPartsRendered
                && (lower.startsWith('parts replaced / used:') || lower.startsWith('parts replaced:') || lower.startsWith('parts used:'));
            if (isDuplicateQualityPartsSection) {
                flushParts();
                flushRepairProgress();
                flushQualityCheckRows();
                skipDuplicateQualityParts = true;
                return;
            }
            const label = current.substring(0, current.indexOf(':') + 1);
            const rest = current.substring(current.indexOf(':') + 1).trim();
            flushRepairProgress();
            flushParts();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            flushCostSummaryRows();
            html.push('<div class="fw-semibold mt-2 mb-1">' + escapeHtml(label) + '</div>');
            if (rest) partBuffer.push(rest);
            return;
        }

        if (isRepairProgressLineV38(current)) {
            flushParts();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            flushCostSummaryRows();
            repairProgressBuffer.push(current);
            return;
        }

        if (isWorkflowPartLineV23(current)) {
            flushRepairProgress();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            flushCostSummaryRows();
            partBuffer.push(current);
            return;
        }

        flushParts();
        flushRepairProgress();
        flushQualityCheckRows();
        flushRepairsToMakeRows();
        flushCostSummaryRows();
        html.push('<div>' + escapeHtml(current) + '</div>');
    });

    flushParts();
    flushRepairProgress();
    flushQualityCheckRows();
    flushRepairsToMakeRows();
    flushCostSummaryRows();
    return html.join('') || 'No additional details recorded.';
}


function formatWorkflowDetailsV23(details) {
    const lines = String(details || '').split(/\n/);
    const html = [];
    let partBuffer = [];
    let repairProgressBuffer = [];

    function flushParts() {
        if (!partBuffer.length) return;
        const rows = parsePartsReplacedRowsV21(partBuffer.join('\n'));
        if (rows.length) html.push(renderPartsTableV23(rows));
        else html.push('<div>' + nl2brEscapeV38(partBuffer.join('\n')) + '</div>');
        partBuffer = [];
    }

    function flushRepairProgress() {
        if (!repairProgressBuffer.length) return;
        const rows = parseRepairProgressRowsV38(repairProgressBuffer.join('\n'));
        if (rows.length) html.push(renderRepairProgressTableV38(rows));
        else html.push('<div>' + nl2brEscapeV38(repairProgressBuffer.join('\n')) + '</div>');
        repairProgressBuffer = [];
    }

    lines.forEach(function (line) {
        let current = String(line || '').trim();
        if (!current) {
            flushParts();
            flushRepairProgress();
            html.push('<div class="my-1"></div>');
            return;
        }

        const lower = current.toLowerCase();
        const prefixedParts = lower.startsWith('parts replaced:')
            || lower.startsWith('parts used:')
            || lower.startsWith('parts replaced / used:')
            || lower.startsWith('items / parts needed:');
        if (prefixedParts) {
            const label = current.substring(0, current.indexOf(':') + 1);
            const rest = current.substring(current.indexOf(':') + 1).trim();
            flushRepairProgress();
            flushParts();
            html.push('<div class="fw-semibold mt-2 mb-1">' + escapeHtml(label) + '</div>');
            if (rest) partBuffer.push(rest);
            return;
        }

        if (isRepairProgressLineV38(current)) {
            flushParts();
            repairProgressBuffer.push(current);
            return;
        }

        if (isWorkflowPartLineV23(current)) {
            flushRepairProgress();
            partBuffer.push(current);
            return;
        }

        flushParts();
        flushRepairProgress();
        html.push('<div>' + escapeHtml(current) + '</div>');
    });

    flushParts();
    flushRepairProgress();
    return html.join('') || 'No additional details recorded.';
}



function renderVehicleRepairHistory(row) {
    const tbody = document.getElementById('detailRepairHistoryBody');
    if (!tbody) return;
    try {
        let history = [];
        const raw = dataValue(row, 'repairHistory');
        if (raw) history = parseJsonSafe(raw, []);
        if (!Array.isArray(history) || history.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">No repair history found.</td></tr>';
            return;
        }
        tbody.innerHTML = history.map(function(item) {
            const attachment = item.attachment || item.ris_attachment || '';
            const attachmentHtml = attachment ? '<button type="button" class="btn btn-link p-0 text-success fw-semibold text-decoration-none" onclick="event.stopPropagation(); openMotorpoolFilePreview(\'' + escapeHtml(attachment) + '\', \'Repair Attachment\')"><i class="bi bi-paperclip me-1"></i>View</button>' : 'N/A';
            const risNumber = escapeHtml(item.ris_number || '');
            const itemPayload = encodeURIComponent(JSON.stringify(item || {}));
            const backlogBadge = Number(item.backlog_count || 0) > 0 ? '<div class="small text-muted mt-1">Backlog: ' + escapeHtml(item.backlog_count || '0') + '</div>' : '';
            const backlogButton = '<button type="button" class="btn btn-success btn-sm btn-backlog-repair" data-no-row-click="1" onclick="event.stopPropagation(); openRepairBacklogModal(this);" data-repair-item="' + itemPayload + '"><i class="bi bi-arrow-counterclockwise me-1"></i>Backlog</button>' + backlogBadge;
            return '<tr class="repair-history-click-row" data-ris-number="' + risNumber + '" onclick="event.stopPropagation(); openRepairWorkflowModalFromRepairRow(this); return false;" title="Click to view detailed repair workflow">'
                + '<td>' + escapeHtml(item.repair_date || '') + '</td>'
                + '<td>' + escapeHtml(item.ris_number || '') + '</td>'
                + '<td><div class="repair-history-text">' + nl2brEscapeV38(item.repairs_done || '') + '</div></td>'
                + '<td>' + renderPartsReplacedColumnsV21(item.parts_replaced || '') + '</td>'
                + '<td>' + escapeHtml(item.mechanic || '') + '</td>'
                + '<td class="text-end fw-semibold">' + escapeHtml(formatPesoV41(item.grand_total_cost || item.grand_total || item.total_cost || item.repair_cost || '0')) + '</td>'
                + '<td>' + attachmentHtml + '</td>'
                + '<td>' + backlogButton + '</td>'
                + '</tr>';
        }).join('');
    } catch (err) {
        console.error('Repair history render error:', err);
        tbody.innerHTML = '<tr><td colspan="8" class="text-danger text-center py-3">Repair history could not be loaded.</td></tr>';
    }
}


function openRepairBacklogModal(button) {
    let item = {};
    try {
        item = JSON.parse(decodeURIComponent(button.getAttribute('data-repair-item') || '{}'));
    } catch (err) {
        item = {};
    }
    if (!item || !item.repair_id) {
        Swal.fire('Error', 'Repair history record was not found.', 'error');
        return;
    }

    const selectedVehicleDbId = selectedVehicleRow ? dataValue(selectedVehicleRow, 'dbId') : (item.vehicle_db_id || '');
    const selectedPlateNo = selectedVehicleRow ? dataValue(selectedVehicleRow, 'plateNo') : (item.plate_no || '');

    document.getElementById('backlogRepairId').value = item.repair_id || '';
    document.getElementById('backlogRisId').value = item.ris_id || '';
    document.getElementById('backlogRisNumber').value = item.ris_number || '';
    document.getElementById('backlogVehicleDbId').value = selectedVehicleDbId || item.vehicle_db_id || '';
    document.getElementById('backlogDisplayRisNo').textContent = item.ris_number || '-';
    document.getElementById('backlogDisplayPlateNo').textContent = selectedPlateNo || item.plate_no || '-';
    document.getElementById('backlogDisplayRepairDate').textContent = item.repair_date || '-';
    document.getElementById('backlogDisplayMechanic').textContent = item.mechanic || '-';
    document.getElementById('backlogDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('backlogProblemDescription').value = '';
    document.getElementById('backlogRemarks').value = '';
    const attachment = document.getElementById('backlogAttachment');
    if (attachment) attachment.value = '';

    motorpoolShowModalFromCurrent('repairBacklogModal', { parentModalId: 'vehicleDetailsModal' });
}

document.addEventListener('DOMContentLoaded', function() {
    const backlogForm = document.getElementById('repairBacklogForm');
    if (!backlogForm) return;

    backlogForm.addEventListener('submit', function(event) {
        event.preventDefault();
        const problem = document.getElementById('backlogProblemDescription')?.value.trim() || '';
        if (!problem) {
            Swal.fire('Required', 'Please describe what was damaged again.', 'warning');
            return;
        }

        const formData = new FormData(backlogForm);
        const submitBtn = backlogForm.querySelector('button[type="submit"]');
        const oldHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';
        }

        fetch('motorpool.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (!data || !data.success) throw new Error((data && data.message) ? data.message : 'Failed to save repair backlog.');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('repairBacklogModal')).hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Repair Backlog Sent',
                    text: 'New RIS reference: ' + (data.new_ris_number || ''),
                    confirmButtonColor: '#047857'
                }).then(() => window.location.reload());
            })
            .catch(error => {
                Swal.fire('Error', error.message || 'Failed to save repair backlog.', 'error');
            })
            .finally(() => {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = oldHtml;
                }
            });
    });
});



function branchMoneyToNumberV1(value) {
    const cleaned = String(value || '').replace(/[₱,]/g, '').trim();
    return cleaned !== '' && !isNaN(Number(cleaned)) ? Number(cleaned) : 0;
}

function branchRepairPaymentKeyV1(item) {
    return String(item.ris_id || '').trim() || String(item.ris_number || '').trim();
}

function repairPaymentNumberV2(value) {
    return branchMoneyToNumberV1(value || 0);
}

function repairPaymentPartsV2(item) {
    return Array.isArray(item.payment_parts) ? item.payment_parts : [];
}

function repairPaymentMiscItemsV2(item) {
    return Array.isArray(item.payment_misc_items) ? item.payment_misc_items : [];
}

function computeRepairTotalCostForPaymentV1(item) {
    const explicitGrand = repairPaymentNumberV2(item.grand_total_cost || item.total_cost || 0);
    if (explicitGrand > 0) return explicitGrand;

    const repairCost = repairPaymentNumberV2(item.repair_cost_total || item.repair_cost || 0);
    const motorpoolParts = repairPaymentNumberV2(item.motorpool_item_cost || 0);
    const branchParts = repairPaymentNumberV2(item.branch_item_cost || 0);
    const miscCost = repairPaymentNumberV2(item.miscellaneous_cost_total || item.misc_cost || 0);
    const computed = repairCost + motorpoolParts + branchParts + miscCost;
    if (computed > 0) return computed;

    const rows = parsePartsReplacedRowsV21(item.parts_replaced || '');
    let partsCost = 0;
    let parsedRepairCost = 0;
    rows.forEach(function(row) {
        const computedLine = computeEstimatedCostFromQtyAndUnitV44(row.quantity || '', row.unit_cost || '');
        partsCost += branchMoneyToNumberV1(computedLine || row.estimated_cost || '');
        parsedRepairCost += branchMoneyToNumberV1(row.repair_cost || '');
    });
    const fallbackRepairCost = branchMoneyToNumberV1(item.repair_cost || '');
    return partsCost + (parsedRepairCost > 0 ? parsedRepairCost : fallbackRepairCost);
}

function computeMotorpoolPayableForPaymentV5(item, breakdown) {
    const b = breakdown || repairPaymentBreakdownV2(item);
    let motorpoolPayable = repairPaymentNumberV2(b.repair_cost || 0)
        + repairPaymentNumberV2(b.motorpool_item_cost || 0)
        + repairPaymentNumberV2(b.miscellaneous_cost || 0);

    if (motorpoolPayable <= 0) {
        const grandTotal = repairPaymentNumberV2(b.grand_total || computeRepairTotalCostForPaymentV1(item));
        const branchTotal = repairPaymentNumberV2(b.branch_item_cost || 0);
        motorpoolPayable = Math.max(0, grandTotal - branchTotal);
    }

    return Math.max(0, motorpoolPayable);
}

function repairPaymentBreakdownV2(item) {
    const parts = repairPaymentPartsV2(item);
    const miscItems = repairPaymentMiscItemsV2(item);
    const repairCost = repairPaymentNumberV2(item.repair_cost_total || item.repair_cost || 0);
    const motorpoolItemCost = repairPaymentNumberV2(item.motorpool_item_cost || 0);
    const branchItemCost = repairPaymentNumberV2(item.branch_item_cost || 0);
    const miscCost = repairPaymentNumberV2(item.miscellaneous_cost_total || item.misc_cost || 0);
    const grandTotal = computeRepairTotalCostForPaymentV1(item);
    const branchItems = parts.filter(function(part) {
        return String(part.source || part.source_by || '').toLowerCase().indexOf('branch') !== -1;
    });
    return {
        repair_cost: repairCost,
        motorpool_item_cost: motorpoolItemCost,
        branch_item_cost: branchItemCost,
        miscellaneous_cost: miscCost,
        grand_total: grandTotal,
        motorpool_payable: repairCost + motorpoolItemCost + miscCost,
        parts: parts,
        branch_items: branchItems,
        misc_items: miscItems,
        has_branch_source_items: branchItems.length > 0 || String(item.has_branch_source_items || '') === '1'
    };
}

function renderRepairPaymentBreakdownTableV2(item, rowKey) {
    const breakdown = item.breakdown || repairPaymentBreakdownV2(item);
    const parts = Array.isArray(breakdown.parts) ? breakdown.parts : [];
    const miscItems = Array.isArray(breakdown.misc_items) ? breakdown.misc_items : [];
    const partRows = parts.length ? parts.map(function(part) {
        const source = part.source || part.source_by || '';
        const sourceLabel = String(source).toLowerCase().indexOf('branch') !== -1 ? 'Branch Source' : 'Motorpool Source';
        const supplier = part.supplier_name ? '<div class="text-muted small">Supplier: ' + escapeHtml(part.supplier_name) + '</div>' : '';
        const status = part.source_status ? '<div class="text-muted small">Status: ' + escapeHtml(part.source_status) + '</div>' : '';
        return '<tr>'
            + '<td><strong>' + escapeHtml(part.item_no || part.item || '') + '</strong><div class="text-muted small">' + escapeHtml(part.description || '') + '</div></td>'
            + '<td>' + escapeHtml(part.specification || '') + '</td>'
            + '<td class="text-end">' + escapeHtml(String(part.quantity || part.used_quantity || '')) + '</td>'
            + '<td>' + escapeHtml(sourceLabel) + supplier + status + '</td>'
            + '<td class="text-end">' + escapeHtml(formatPesoV41(repairPaymentNumberV2(part.unit_cost || part.actual_unit_cost || part.estimated_unit_cost || 0).toFixed(2))) + '</td>'
            + '<td class="text-end">' + escapeHtml(formatPesoV41(repairPaymentNumberV2(part.total_cost || part.actual_total_cost || part.estimated_total_cost || 0).toFixed(2))) + '</td>'
            + '</tr>';
    }).join('') : '<tr><td colspan="6" class="text-muted text-center py-2">No item cost breakdown found.</td></tr>';

    const miscRows = miscItems.length ? miscItems.map(function(misc) {
        return '<tr><td>' + escapeHtml(misc.description || 'Miscellaneous') + '</td><td>' + escapeHtml(misc.repair || '') + '</td><td class="text-end">' + escapeHtml(formatPesoV41(repairPaymentNumberV2(misc.cost || 0).toFixed(2))) + '</td></tr>';
    }).join('') : '<tr><td colspan="3" class="text-muted text-center py-2">No miscellaneous cost.</td></tr>';

    return '<tr id="repairPaymentBreakdown_' + escapeHtml(rowKey) + '" class="repair-payment-breakdown-row d-none">'
        + '<td colspan="7" class="bg-light">'
        + '<div class="p-2">'
        + '<div class="fw-bold mb-2">Payment Breakdown</div>'
        + '<div class="table-responsive mb-3"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Item</th><th>Specification</th><th class="text-end">Qty</th><th>Source</th><th class="text-end">Unit Cost</th><th class="text-end">Total Cost</th></tr></thead><tbody>' + partRows + '</tbody></table></div>'
        + '<div class="fw-bold mb-2">Miscellaneous</div>'
        + '<div class="table-responsive mb-3"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Description</th><th>Repair</th><th class="text-end">Cost</th></tr></thead><tbody>' + miscRows + '</tbody></table></div>'
        + '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><tbody>'
        + '<tr><td>Repair Cost</td><td class="text-end">' + escapeHtml(formatPesoV41(breakdown.repair_cost.toFixed(2))) + '</td></tr>'
        + '<tr><td>Motorpool Source Items</td><td class="text-end">' + escapeHtml(formatPesoV41(breakdown.motorpool_item_cost.toFixed(2))) + '</td></tr>'
        + '<tr><td>Branch Source Items</td><td class="text-end">' + escapeHtml(formatPesoV41(breakdown.branch_item_cost.toFixed(2))) + '</td></tr>'
        + '<tr><td>Miscellaneous Cost</td><td class="text-end">' + escapeHtml(formatPesoV41(breakdown.miscellaneous_cost.toFixed(2))) + '</td></tr>'
        + '<tr class="table-info fw-bold"><td>Motorpool Payable (Pay Button)</td><td class="text-end">' + escapeHtml(formatPesoV41(computeMotorpoolPayableForPaymentV5(item, breakdown).toFixed(2))) + '</td></tr>'
        + '<tr class="table-success fw-bold"><td>Grand Total</td><td class="text-end">' + escapeHtml(formatPesoV41(breakdown.grand_total.toFixed(2))) + '</td></tr>'
        + '</tbody></table></div>'
        + '</div></td></tr>';
}

window.branchRepairPaymentSourceRowsV2 = {};

function toggleRepairPaymentBreakdownV1(rowKey) {
    const row = document.getElementById('repairPaymentBreakdown_' + rowKey);
    if (row) row.classList.toggle('d-none');
}

function getRepairPaymentsForCurrentVehicleV1(row) {
    const raw = dataValue(row, 'repairPaymentHistory') || '[]';
    const payments = parseJsonSafe(raw, []);
    return Array.isArray(payments) ? payments : [];
}

function groupRepairPaymentsByRisV1(payments) {
    const map = {};
    payments.forEach(function(payment) {
        const key = String(payment.ris_id || '').trim() || String(payment.ris_number || '').trim();
        if (!key) return;
        if (!map[key]) map[key] = [];
        map[key].push(payment);
    });
    return map;
}

function repairPaymentStatusBadgeV1(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'paid') return '<span class="badge bg-success">Paid</span>';
    if (s === 'partial') return '<span class="badge bg-warning text-dark">Partial</span>';
    return '<span class="badge bg-secondary">Unpaid</span>';
}

function renderVehicleRepairPayments(row) {
    const tbody = document.getElementById('detailRepairPaymentsBody');
    if (!tbody) return;
    const history = parseJsonSafe(dataValue(row, 'repairHistory') || '[]', []);
    if (!Array.isArray(history) || !history.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">No repair history found for payment.</td></tr>';
        return;
    }
    const payments = getRepairPaymentsForCurrentVehicleV1(row);
    const grouped = groupRepairPaymentsByRisV1(payments);
    const paymentRows = [];

    history.forEach(function(item, index) {
        const key = branchRepairPaymentKeyV1(item);
        const breakdown = repairPaymentBreakdownV2(item);
        const grandTotal = breakdown.grand_total;
        const total = computeMotorpoolPayableForPaymentV5(item, breakdown);
        if (!key || total <= 0) return;
        const list = grouped[key] || [];
        let paid = 0;
        let branchSourcePaid = 0;
        list.forEach(function(payment) {
            const scope = String(payment.payment_scope || 'motorpool').toLowerCase();
            const amountPaid = branchMoneyToNumberV1(payment.amount_paid || '');
            if (scope === 'branch_source') branchSourcePaid += amountPaid;
            else paid += amountPaid;
        });
        const balance = Math.max(0, total - paid);
        const branchSourceTotal = Number((breakdown.branch_item_cost || 0).toFixed ? breakdown.branch_item_cost.toFixed(2) : breakdown.branch_item_cost) || 0;
        const branchSourceBalance = Math.max(0, branchSourceTotal - branchSourcePaid);
        const fullRepairBalance = Math.max(0, grandTotal - paid - branchSourcePaid);
        let status = 'Unpaid';
        if (paid > 0 && balance > 0.009) status = 'Partial';
        else if (paid > 0 && balance <= 0.009) status = 'Paid';
        paymentRows.push({
            row_key: String(index) + '_' + String(key).replace(/[^A-Za-z0-9_-]/g, ''),
            ris_id: item.ris_id || '',
            ris_number: item.ris_number || '',
            vehicle_db_id: item.vehicle_db_id || dataValue(row, 'dbId') || '',
            plate_no: item.plate_no || dataValue(row, 'plateNo') || '',
            repair_date: item.repair_date || item.created_at || '',
            total: total,
            grand_total: grandTotal,
            paid: paid,
            balance: balance,
            status: status,
            branch_source_total: branchSourceTotal,
            branch_source_paid: branchSourcePaid,
            branch_source_balance: branchSourceBalance,
            full_repair_balance: fullRepairBalance,
            breakdown: breakdown
        });
    });

    if (!paymentRows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">No payable repair costs found.</td></tr>';
        return;
    }

    tbody.innerHTML = paymentRows.map(function(item) {
        const canPay = item.balance > 0.009;
        const sourceKey = String(item.row_key || '');
        window.branchRepairPaymentSourceRowsV2[sourceKey] = item;
        const sourceButton = item.breakdown.has_branch_source_items
            ? '<button type="button" class="btn btn-outline-primary btn-sm me-1" onclick="event.stopPropagation(); openBranchSourceCostModalV2(\'' + escapeHtml(sourceKey) + '\')"><i class="bi bi-pencil-square me-1"></i>Source Cost</button>'
            : '';
        const payButton = canPay
            ? '<button type="button" class="btn btn-success btn-sm" onclick="event.stopPropagation(); openRepairPaymentModal(this)" data-payment-source-key="' + escapeHtml(sourceKey) + '" data-payment-has-branch-source="' + (item.breakdown.has_branch_source_items ? '1' : '0') + '" data-payment-branch-source-total="' + escapeHtml(item.breakdown.branch_item_cost.toFixed(2)) + '" data-payment-grand-total="' + escapeHtml(item.grand_total.toFixed(2)) + '" data-payment-full-repair-balance="' + escapeHtml(item.full_repair_balance.toFixed(2)) + '" data-payment-branch-source-balance="' + escapeHtml(item.branch_source_balance.toFixed(2)) + '" data-payment-ris-id="' + escapeHtml(item.ris_id) + '" data-payment-ris-number="' + escapeHtml(item.ris_number) + '" data-payment-vehicle-db-id="' + escapeHtml(item.vehicle_db_id) + '" data-payment-plate-no="' + escapeHtml(item.plate_no || '') + '" data-payment-repair-date="' + escapeHtml(item.repair_date) + '" data-payment-total-cost="' + escapeHtml(item.total.toFixed(2)) + '" data-payment-paid="' + escapeHtml(item.paid.toFixed(2)) + '" data-payment-balance="' + escapeHtml(item.balance.toFixed(2)) + '"><i class="bi bi-cash me-1"></i>Pay</button>'
            : '<button type="button" class="btn btn-outline-success btn-sm" disabled><i class="bi bi-check2-circle me-1"></i>Paid</button>';
        return '<tr class="repair-payment-clickable-row" style="cursor:pointer" onclick="toggleRepairPaymentBreakdownV1(\'' + escapeHtml(item.row_key) + '\')">'
            + '<td><strong>' + escapeHtml(item.ris_number || '') + '</strong><div class="text-muted small">Click row for breakdown</div></td>'
            + '<td>' + escapeHtml(String(item.repair_date || '').substring(0, 10)) + '</td>'
            + '<td>' + escapeHtml(formatPesoV41(item.total.toFixed(2))) + '</td>'
            + '<td>' + repairPaymentStatusBadgeV1(item.status) + '</td>'
            + '<td>' + escapeHtml(formatPesoV41(item.paid.toFixed(2))) + '</td>'
            + '<td>' + escapeHtml(formatPesoV41(item.full_repair_balance.toFixed(2))) + '</td>'
            + '<td>' + sourceButton + payButton + '</td>'
            + '</tr>'
            + renderRepairPaymentBreakdownTableV2(item, item.row_key);
    }).join('');
}

function openBranchSourceCostModalV2(rowKey) {
    const item = window.branchRepairPaymentSourceRowsV2[rowKey];
    if (!item || !item.breakdown) return;
    const modal = document.getElementById('branchSourceCostModalV2');
    const form = document.getElementById('branchSourceCostFormV2');
    const body = document.getElementById('branchSourceCostBodyV2');
    if (!modal || !form || !body) return;
    form.reset();
    document.getElementById('branchSourceRisIdV2').value = item.ris_id || '';
    document.getElementById('branchSourceRisLabelV2').textContent = item.ris_number || 'N/A';
    modal.setAttribute('data-source-key', rowKey);
    modal.setAttribute('data-ris-id', item.ris_id || '');
    modal.setAttribute('data-ris-number', item.ris_number || '');
    modal.setAttribute('data-vehicle-db-id', item.vehicle_db_id || '');
    modal.setAttribute('data-plate-no', item.plate_no || '');
    modal.setAttribute('data-repair-date', item.repair_date || '');
    modal.setAttribute('data-branch-source-balance', (item.branch_source_balance || item.breakdown.branch_item_cost || 0).toFixed ? (item.branch_source_balance || item.breakdown.branch_item_cost || 0).toFixed(2) : String(item.branch_source_balance || item.breakdown.branch_item_cost || 0));
    modal.setAttribute('data-full-repair-balance', (item.full_repair_balance || item.grand_total || 0).toFixed ? (item.full_repair_balance || item.grand_total || 0).toFixed(2) : String(item.full_repair_balance || item.grand_total || 0));
    const rows = (item.breakdown.branch_items || []).filter(function(part){ return Number(part.purchase_id || 0) > 0; });
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="6" class="text-muted text-center py-3">No Branch Source item with purchase record found.</td></tr>';
    } else {
        body.innerHTML = rows.map(function(part) {
            const qty = repairPaymentNumberV2(part.quantity || part.used_quantity || 0);
            const actualUnit = repairPaymentNumberV2(part.actual_unit_cost || 0);
            const actualTotal = repairPaymentNumberV2(part.actual_total_cost || 0);
            const unitValue = actualUnit > 0 ? actualUnit.toFixed(2) : '';
            const totalValue = actualTotal > 0 ? actualTotal.toFixed(2) : '';
            return '<tr>'
                + '<td><input type="hidden" name="purchase_id[]" value="' + escapeHtml(part.purchase_id || '') + '"><strong>' + escapeHtml(part.item_no || '') + '</strong><div class="text-muted small">' + escapeHtml(part.description || '') + '</div></td>'
                + '<td>' + escapeHtml(part.specification || '') + '</td>'
                + '<td class="text-end">' + escapeHtml(String(qty || '')) + '</td>'
                + '<td><input type="text" class="form-control form-control-sm" name="supplier_name[]" value="' + escapeHtml(part.supplier_name || '') + '" required></td>'
                + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm branch-source-unit-v2" name="actual_unit_cost[]" value="' + escapeHtml(unitValue) + '" data-qty="' + escapeHtml(String(qty || 0)) + '"></td>'
                + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm branch-source-total-v2" name="actual_total_cost[]" value="' + escapeHtml(totalValue) + '" required></td>'
                + '</tr>';
        }).join('');
    }
    motorpoolShowModalFromCurrent('branchSourceCostModalV2', {
        parentModalId: 'vehicleDetailsModal',
        returnTabTarget: '#vehicleRepairPaymentsTab'
    });
}


document.addEventListener('input', function(event) {
    if (event.target.classList && event.target.classList.contains('branch-source-unit-v2')) {
        const input = event.target;
        const qty = repairPaymentNumberV2(input.getAttribute('data-qty') || 0);
        const row = input.closest('tr');
        const totalInput = row ? row.querySelector('.branch-source-total-v2') : null;
        const unit = repairPaymentNumberV2(input.value || 0);
        if (totalInput && qty > 0 && unit > 0) totalInput.value = (qty * unit).toFixed(2);
    }
});

document.getElementById('branchSourceCostFormV2')?.addEventListener('submit', function(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(function(data) {
            if (!data.success) {
                Swal.fire({icon:'error', title:'Error', text:data.message || 'Failed to save Branch Source cost.', confirmButtonColor:'#dc3545'});
                return;
            }
            const modal = bootstrap.Modal.getInstance(document.getElementById('branchSourceCostModalV2'));
            if (modal) modal.hide();
            Swal.fire({icon:'success', title:'Saved', text:data.message || 'Branch Source actual costs saved successfully.', confirmButtonColor:'#07b83f'})
                .then(function(){ window.location.reload(); });
        })
        .catch(function(error) {
            console.error('Branch source cost save error:', error);
            Swal.fire({icon:'error', title:'Error', text:'Unable to save Branch Source cost. Please try again.', confirmButtonColor:'#dc3545'});
        });
});

document.getElementById('repairPaymentBranchSourceActualCostBtn')?.addEventListener('click', function() {
    const rowKey = this.getAttribute('data-source-key') || '';
    if (!rowKey) return;
    openBranchSourceCostModalV2(rowKey);
});

function syncRepairPaymentAccountFields() {
    const expenseSelect = document.getElementById('paymentExpenseAccount');
    const expenseName = document.getElementById('paymentExpenseAccountName');
    if (expenseSelect && expenseName) {
        const selectedExpense = expenseSelect.options[expenseSelect.selectedIndex];
        expenseName.value = selectedExpense ? (selectedExpense.getAttribute('data-name') || selectedExpense.textContent || '') : '';
    }
    const bankSelect = document.getElementById('paymentBankAccount');
    const bankAccountName = document.getElementById('paymentBankAccountName');
    const bankName = document.getElementById('paymentBankName');
    const bankBranch = document.getElementById('paymentBankBranch');
    if (bankSelect) {
        const selectedBank = bankSelect.options[bankSelect.selectedIndex];
        if (bankAccountName) bankAccountName.value = selectedBank ? (selectedBank.getAttribute('data-name') || selectedBank.textContent || '') : '';
        if (bankName) bankName.value = selectedBank ? (selectedBank.getAttribute('data-bank-name') || '') : '';
        if (bankBranch) bankBranch.value = selectedBank ? (selectedBank.getAttribute('data-bank-branch') || '') : '';
    }
}
function toggleRepairPaymentMethodFields() {
    const method = (document.getElementById('paymentMethod')?.value || '').toLowerCase();
    const isNonCash = method === 'check' || method === 'online_transfer';
    const isCheck = method === 'check';
    document.querySelectorAll('.repair-noncash-field').forEach(el => el.classList.toggle('d-none', !isNonCash));
    document.querySelectorAll('.repair-check-field').forEach(el => el.classList.toggle('d-none', !isCheck));
    const bankAccount = document.getElementById('paymentBankAccount');
    const checkNumber = document.getElementById('paymentCheckNumber');
    if (bankAccount) bankAccount.required = true;
    if (checkNumber) checkNumber.required = isCheck;
    if (!isNonCash) {
        const reference = document.getElementById('paymentReferenceNo');
        if (reference) reference.value = '';
    }
    if (!isCheck) {
        const checkDate = document.getElementById('paymentCheckDate');
        if (checkDate) checkDate.value = '';
        if (checkNumber) checkNumber.value = '';
    }
    syncRepairPaymentAccountFields();
}
document.getElementById('paymentMethod')?.addEventListener('change', toggleRepairPaymentMethodFields);
document.getElementById('paymentExpenseAccount')?.addEventListener('change', syncRepairPaymentAccountFields);
document.getElementById('paymentBankAccount')?.addEventListener('change', syncRepairPaymentAccountFields);

function openRepairPaymentModal(btn) {
    const form = document.getElementById('repairPaymentForm');
    if (form) form.reset();
    const risId = btn.getAttribute('data-payment-ris-id') || '';
    const risNumber = btn.getAttribute('data-payment-ris-number') || '';
    const vehicleDbId = btn.getAttribute('data-payment-vehicle-db-id') || '';
    const repairDate = btn.getAttribute('data-payment-repair-date') || '';
    const plateNo = btn.getAttribute('data-payment-plate-no') || (selectedVehicleRow ? dataValue(selectedVehicleRow, 'plateNo') : '');
    const totalCost = btn.getAttribute('data-payment-total-cost') || '0.00';
    const balance = btn.getAttribute('data-payment-balance') || '0.00';
    const sourceKey = btn.getAttribute('data-payment-source-key') || '';
    const hasBranchSource = btn.getAttribute('data-payment-has-branch-source') === '1';

    document.getElementById('paymentRisId').value = risId;
    document.getElementById('paymentRisNumber').value = risNumber;
    document.getElementById('paymentVehicleDbId').value = vehicleDbId;
    document.getElementById('paymentRepairDate').value = String(repairDate || '').substring(0, 10);
    document.getElementById('paymentTotalCostValue').value = totalCost;
    document.getElementById('paymentRisLabel').textContent = risNumber || 'N/A';
    document.getElementById('paymentTotalCostLabel').textContent = formatPesoV41(totalCost);
    document.getElementById('paymentBalanceLabel').textContent = formatPesoV41(balance);
    document.getElementById('paymentAmount').value = balance;
    document.getElementById('paymentAmount').max = balance;
    document.getElementById('paymentDate').value = today();

    const branchSourceBtn = document.getElementById('repairPaymentBranchSourceActualCostBtn');
    if (branchSourceBtn) {
        branchSourceBtn.classList.toggle('d-none', !hasBranchSource || !sourceKey);
        branchSourceBtn.setAttribute('data-source-key', sourceKey);
    }
    toggleRepairPaymentMethodFields();
    syncRepairPaymentAccountFields();

    motorpoolShowModalFromCurrent('repairPaymentModal', {
        parentModalId: 'vehicleDetailsModal',
        returnTabTarget: '#vehicleRepairPaymentsTab'
    });
}

document.getElementById('repairPaymentForm')?.addEventListener('submit', function(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const amount = branchMoneyToNumberV1(document.getElementById('paymentAmount').value || '0');
    const balance = branchMoneyToNumberV1(document.getElementById('paymentAmount').max || '0');
    if (amount <= 0) {
        Swal.fire({icon:'warning', title:'Invalid Amount', text:'Amount to pay must be greater than zero.', confirmButtonColor:'#07b83f'});
        return;
    }
    if (amount > balance + 0.01) {
        Swal.fire({icon:'warning', title:'Invalid Amount', text:'Amount to pay cannot be greater than the balance.', confirmButtonColor:'#07b83f'});
        return;
    }
    syncRepairPaymentAccountFields();
    const expenseAccount = document.getElementById('paymentExpenseAccount')?.value || '';
    const method = (document.getElementById('paymentMethod')?.value || '').toLowerCase();
    const bankAccount = document.getElementById('paymentBankAccount')?.value || '';
    if (!expenseAccount) {
        Swal.fire({icon:'warning', title:'Expense Account Required', text:'Please select an expense account.', confirmButtonColor:'#07b83f'});
        return;
    }
    if (!bankAccount) {
        Swal.fire({icon:'warning', title:'Bank Account Required', text:'Please select a bank account.', confirmButtonColor:'#07b83f'});
        return;
    }

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(function(data) {
            if (!data.success) {
                Swal.fire({icon:'error', title:'Error', text:data.message || 'Failed to save repair payment.', confirmButtonColor:'#dc3545'});
                return;
            }
            const modal = bootstrap.Modal.getInstance(document.getElementById('repairPaymentModal'));
            if (modal) modal.hide();
            if (selectedVehicleRow && data.payment) {
                const existing = getRepairPaymentsForCurrentVehicleV1(selectedVehicleRow);
                existing.unshift(data.payment);
                selectedVehicleRow.dataset.repairPaymentHistory = JSON.stringify(existing);
                renderVehicleRepairPayments(selectedVehicleRow);
            }
            Swal.fire({icon:'success', title:'Saved', text:data.message || 'Repair payment saved successfully.', confirmButtonColor:'#07b83f'})
                .then(function() {
                    setTimeout(function(){
                        motorpoolShowModalFromCurrent('vehicleDetailsModal');
                        const tabBtn = document.querySelector('[data-bs-target="#vehicleRepairPaymentsTab"]');
                        if (tabBtn) bootstrap.Tab.getOrCreateInstance(tabBtn).show();
                    }, 150);
                });
        })
        .catch(function(error) {
            console.error('Repair payment save error:', error);
            Swal.fire({icon:'error', title:'Error', text:'Unable to save repair payment. Please try again.', confirmButtonColor:'#dc3545'});
        });
});


function repairPaymentNumberToWordsV3(amount) {
    amount = Math.round((Number(amount) || 0) * 100) / 100;
    const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    function words(n) {
        n = Math.floor(Number(n) || 0);
        if (n === 0) return 'Zero';
        if (n < 20) return ones[n];
        if (n < 100) return tens[Math.floor(n / 10)] + (n % 10 ? ' ' + ones[n % 10] : '');
        if (n < 1000) return ones[Math.floor(n / 100)] + ' Hundred' + (n % 100 ? ' ' + words(n % 100) : '');
        if (n < 1000000) return words(Math.floor(n / 1000)) + ' Thousand' + (n % 1000 ? ' ' + words(n % 1000) : '');
        return words(Math.floor(n / 1000000)) + ' Million' + (n % 1000000 ? ' ' + words(n % 1000000) : '');
    }
    const pesos = Math.floor(amount);
    const cents = Math.round((amount - pesos) * 100);
    return words(pesos) + ' Pesos' + (cents > 0 ? ' and ' + String(cents).padStart(2, '0') + '/100' : '') + ' Only';
}

let repairPaymentSyncingAmountV4 = false;
function updateRepairWriteCheckMirrorsV3(source) {
    const amountInput = document.getElementById('paymentAmount');
    const amountMirror = document.getElementById('repairPaymentExpenseAmountMirror');
    const sourceId = source && source.id ? source.id : '';
    let amount = branchMoneyToNumberV1((sourceId === 'repairPaymentExpenseAmountMirror' ? amountMirror?.value : amountInput?.value) || '0');
    const balance = branchMoneyToNumberV1(amountInput?.getAttribute('max') || '0');

    // Allow partial payment, but never allow more than the current balance.
    if (balance > 0 && amount > balance) amount = balance;
    if (amount < 0) amount = 0;

    const amountFixed = amount > 0 ? amount.toFixed(2) : '';
    repairPaymentSyncingAmountV4 = true;
    if (amountInput && sourceId !== 'paymentAmount') amountInput.value = amountFixed;
    if (amountMirror && sourceId !== 'repairPaymentExpenseAmountMirror') amountMirror.value = amountFixed;
    repairPaymentSyncingAmountV4 = false;

    const amountWords = document.getElementById('repairPaymentAmountWords');
    if (amountWords) amountWords.textContent = amount > 0 ? repairPaymentNumberToWordsV3(amount) : 'Zero Pesos Only';
    const expenseTotal = document.getElementById('repairPaymentExpenseTotal');
    if (expenseTotal) expenseTotal.textContent = formatPesoV41((amount || 0).toFixed(2));
    const memo = document.getElementById('paymentRemarks')?.value || '';
    const memoMirror = document.getElementById('repairPaymentExpenseMemoMirror');
    if (memoMirror) memoMirror.value = memo;
    syncRepairPaymentAccountFields();
}

function syncRepairPaymentAccountFields() {
    const expenseSelect = document.getElementById('paymentExpenseAccount');
    const expenseName = document.getElementById('paymentExpenseAccountName');
    if (expenseSelect && expenseName) {
        const selectedExpense = expenseSelect.options[expenseSelect.selectedIndex];
        expenseName.value = selectedExpense ? (selectedExpense.getAttribute('data-name') || selectedExpense.textContent || '') : '';
    }
    const bankSelect = document.getElementById('paymentBankAccount');
    const bankAccountName = document.getElementById('paymentBankAccountName');
    const bankName = document.getElementById('paymentBankName');
    const bankBranch = document.getElementById('paymentBankBranch');
    const balanceDisplay = document.getElementById('repairWriteCheckEndingBalance');
    if (bankSelect) {
        const selectedBank = bankSelect.options[bankSelect.selectedIndex];
        if (bankAccountName) bankAccountName.value = selectedBank ? (selectedBank.getAttribute('data-name') || selectedBank.textContent || '') : '';
        if (bankName) bankName.value = selectedBank ? (selectedBank.getAttribute('data-bank-name') || '') : '';
        if (bankBranch) bankBranch.value = selectedBank ? (selectedBank.getAttribute('data-bank-branch') || '') : '';
        const bankBalance = selectedBank ? branchMoneyToNumberV1(selectedBank.getAttribute('data-balance') || '0') : 0;
        const amountInput = document.getElementById('paymentAmount');
        const amountToPay = amountInput ? branchMoneyToNumberV1(amountInput.value || '0') : 0;
        const endingBalance = Math.max(0, bankBalance - amountToPay);
        if (balanceDisplay) balanceDisplay.textContent = formatPesoV41(endingBalance.toFixed(2));
        if (balanceDisplay) balanceDisplay.setAttribute('title', 'Current Bank Balance: ' + formatPesoV41(bankBalance.toFixed(2)) + ' | Amount to Pay: ' + formatPesoV41(amountToPay.toFixed(2)));
    }
}

function toggleRepairPaymentMethodFields() {
    const methodInput = document.getElementById('paymentMethod');
    if (methodInput) methodInput.value = 'check';
    const bankAccount = document.getElementById('paymentBankAccount');
    const checkNumber = document.getElementById('paymentCheckNumber');
    if (bankAccount) bankAccount.required = true;
    if (checkNumber) checkNumber.required = true;
    syncRepairPaymentAccountFields();
}

function selectDefaultRepairExpenseAccountV3() {
    const select = document.getElementById('paymentExpenseAccount');
    if (!select || select.value) return;

    const options = Array.from(select.options).filter(function(opt) { return opt.value; });
    const normalize = function(opt) {
        return String((opt.getAttribute('data-name') || opt.textContent || '')).toLowerCase().replace(/\s+/g, ' ').trim();
    };
    const isFuel = function(opt) {
        const txt = normalize(opt);
        return txt.includes('fuel');
    };
    const findOption = function(predicate) {
        return options.find(function(opt) { return !isFuel(opt) && predicate(normalize(opt)); }) || null;
    };

    // Repair Payment must default to Motorpool/Vehicle Repair expense, not Fuel Expense.
    let preferred =
        findOption(function(txt) { return txt === 'motorpool expense' || txt.includes('motorpool expense'); }) ||
        findOption(function(txt) { return txt.includes('motorpool repair expense'); }) ||
        findOption(function(txt) { return txt.includes('vehicle repair expense'); }) ||
        findOption(function(txt) { return txt.includes('repair and maintenance'); }) ||
        findOption(function(txt) { return txt.includes('repairs and maintenance'); }) ||
        findOption(function(txt) { return txt.includes('repair expense'); }) ||
        findOption(function(txt) { return txt.includes('vehicle repair'); }) ||
        findOption(function(txt) { return txt.includes('motorpool'); }) ||
        findOption(function(txt) { return txt.includes('repair'); });

    if (!preferred) {
        preferred = options.find(function(opt) { return !isFuel(opt); }) || options[0] || null;
    }
    if (preferred) select.value = preferred.value;
}

function renderRepairPaymentAttachmentPreviewV3() {
    const input = document.getElementById('paymentAttachment');
    const preview = document.getElementById('repairPaymentAttachmentPreview');
    if (!input || !preview) return;
    const files = Array.from(input.files || []);
    preview.innerHTML = files.map(function(file) {
        return '<div class="withdrawal-attachment-chip"><i class="bi bi-file-earmark-arrow-up"></i><span>' + escapeHtml(file.name) + '</span></div>';
    }).join('');
}

document.getElementById('paymentAmount')?.addEventListener('input', function(event){ if (!repairPaymentSyncingAmountV4) updateRepairWriteCheckMirrorsV3(event.target); });
document.getElementById('repairPaymentExpenseAmountMirror')?.addEventListener('input', function(event){ if (!repairPaymentSyncingAmountV4) updateRepairWriteCheckMirrorsV3(event.target); });
document.getElementById('paymentRemarks')?.addEventListener('input', updateRepairWriteCheckMirrorsV3);
document.getElementById('paymentAttachment')?.addEventListener('change', renderRepairPaymentAttachmentPreviewV3);
document.getElementById('paymentBankAccount')?.addEventListener('change', function(){ syncRepairPaymentAccountFields(); });
document.getElementById('paymentExpenseAccount')?.addEventListener('change', function(){ syncRepairPaymentAccountFields(); });

function openRepairPaymentModal(btn) {
    const form = document.getElementById('repairPaymentForm');
    if (form) form.reset();
    const risId = btn.getAttribute('data-payment-ris-id') || '';
    const risNumber = btn.getAttribute('data-payment-ris-number') || '';
    const vehicleDbId = btn.getAttribute('data-payment-vehicle-db-id') || '';
    const repairDate = btn.getAttribute('data-payment-repair-date') || '';
    const plateNo = btn.getAttribute('data-payment-plate-no') || (selectedVehicleRow ? dataValue(selectedVehicleRow, 'plateNo') : '');
    const totalCost = btn.getAttribute('data-payment-total-cost') || '0.00';
    const balance = btn.getAttribute('data-payment-balance') || '0.00';
    const grandTotal = btn.getAttribute('data-payment-grand-total') || totalCost;
    const fullRepairBalance = btn.getAttribute('data-payment-full-repair-balance') || grandTotal;
    const branchSourceBalance = btn.getAttribute('data-payment-branch-source-balance') || '0.00';
    const sourceKey = btn.getAttribute('data-payment-source-key') || '';
    const hasBranchSource = btn.getAttribute('data-payment-has-branch-source') === '1';
    const scope = btn.getAttribute('data-payment-scope') || 'motorpool';
    const payee = btn.getAttribute('data-payment-payee') || (scope === 'branch_source' ? 'Branch Source Supplier' : 'Motorpool Repair');
    const address = btn.getAttribute('data-payment-address') || (scope === 'branch_source' ? 'Supplier' : 'Motorpool');

    document.getElementById('paymentRisId').value = risId;
    document.getElementById('paymentRisNumber').value = risNumber;
    document.getElementById('paymentVehicleDbId').value = vehicleDbId;
    document.getElementById('paymentRepairDate').value = String(repairDate || '').substring(0, 10);
    document.getElementById('paymentTotalCostValue').value = totalCost;
    document.getElementById('paymentScope').value = scope;
    document.getElementById('paymentRisLabel').textContent = risNumber || 'N/A';
    document.getElementById('paymentTotalCostLabel').textContent = formatPesoV41(balance);
    document.getElementById('paymentBalanceLabel').textContent = formatPesoV41(fullRepairBalance);
    const fullLabel = document.getElementById('paymentFullRepairBalanceLabel');
    if (fullLabel) fullLabel.textContent = formatPesoV41(fullRepairBalance);
    const branchWrap = document.getElementById('paymentBranchSourceSummaryWrap');
    const branchLabel = document.getElementById('paymentBranchSourceBalanceLabel');
    if (branchWrap) branchWrap.classList.toggle('d-none', !hasBranchSource && scope !== 'branch_source');
    if (branchLabel) branchLabel.textContent = formatPesoV41(branchSourceBalance);

    document.getElementById('paymentAmount').value = balance;
    document.getElementById('paymentAmount').max = balance;
    document.getElementById('paymentDate').value = today();
    document.getElementById('paymentCheckDate').value = today();
    document.getElementById('paymentReferenceNo').value = risNumber || '';
    document.getElementById('paymentMethod').value = 'check';
    const memoParts = [];
    memoParts.push(scope === 'branch_source' ? 'Branch Source item payment' : 'Motorpool repair payment');
    if (risNumber) memoParts.push('RIS: ' + risNumber);
    if (plateNo) memoParts.push('Plate No.: ' + plateNo);
    document.getElementById('paymentRemarks').value = memoParts.join(' | ');
    document.getElementById('repairWriteCheckPayee').value = payee;
    document.getElementById('repairWriteCheckAddress').value = address;
    const attachmentPreview = document.getElementById('repairPaymentAttachmentPreview');
    if (attachmentPreview) attachmentPreview.innerHTML = '';

    const branchSourceBtn = document.getElementById('repairPaymentBranchSourceActualCostBtn');
    if (branchSourceBtn) {
        branchSourceBtn.classList.toggle('d-none', !hasBranchSource || !sourceKey || scope === 'branch_source');
        branchSourceBtn.setAttribute('data-source-key', sourceKey);
    }

    const bankSelect = document.getElementById('paymentBankAccount');
    if (bankSelect && !bankSelect.value) {
        const firstBank = Array.from(bankSelect.options).find(function(opt){ return opt.value; });
        if (firstBank) bankSelect.value = firstBank.value;
    }
    selectDefaultRepairExpenseAccountV3();
    toggleRepairPaymentMethodFields();
    syncRepairPaymentAccountFields();
    updateRepairWriteCheckMirrorsV3();

    motorpoolShowModalFromCurrent('repairPaymentModal', {
        parentModalId: scope === 'branch_source' ? 'branchSourceCostModalV2' : 'vehicleDetailsModal',
        returnTabTarget: '#vehicleRepairPaymentsTab'
    });
}


document.getElementById('branchSourcePayBtnV2')?.addEventListener('click', function() {
    const modal = document.getElementById('branchSourceCostModalV2');
    if (!modal) return;
    const rows = Array.from(document.querySelectorAll('#branchSourceCostBodyV2 tr'));
    let supplier = '';
    let total = 0;
    let supplierSet = new Set();
    rows.forEach(function(row) {
        const supplierInput = row.querySelector('input[name="supplier_name[]"]');
        const totalInput = row.querySelector('input[name="actual_total_cost[]"]');
        if (!supplierInput || !totalInput) return;
        const s = String(supplierInput.value || '').trim();
        const t = repairPaymentNumberV2(totalInput.value || 0);
        if (s !== '' && t > 0) {
            supplierSet.add(s.toLowerCase());
            if (!supplier) supplier = s;
            total += t;
        }
    });
    if (!supplier) {
        Swal.fire({icon:'warning', title:'Supplier Required', text:'Please enter supplier name first before paying Branch Source cost.', confirmButtonColor:'#07b83f'});
        return;
    }
    if (supplierSet.size > 1) {
        Swal.fire({icon:'warning', title:'One Supplier Only', text:'Write Check can pay one supplier only. Please pay one supplier at a time.', confirmButtonColor:'#07b83f'});
        return;
    }
    if (total <= 0) {
        Swal.fire({icon:'warning', title:'Actual Cost Required', text:'Please enter actual total cost first before paying.', confirmButtonColor:'#07b83f'});
        return;
    }
    const remainingFromRepair = repairPaymentNumberV2(modal.getAttribute('data-branch-source-balance') || total);
    const amountToPay = remainingFromRepair > 0 ? Math.min(total, remainingFromRepair) : total;
    const fakeBtn = document.createElement('button');
    fakeBtn.setAttribute('data-payment-scope', 'branch_source');
    fakeBtn.setAttribute('data-payment-payee', supplier);
    fakeBtn.setAttribute('data-payment-address', supplier);
    fakeBtn.setAttribute('data-payment-ris-id', modal.getAttribute('data-ris-id') || document.getElementById('branchSourceRisIdV2')?.value || '');
    fakeBtn.setAttribute('data-payment-ris-number', modal.getAttribute('data-ris-number') || document.getElementById('branchSourceRisLabelV2')?.textContent || '');
    fakeBtn.setAttribute('data-payment-vehicle-db-id', modal.getAttribute('data-vehicle-db-id') || '');
    fakeBtn.setAttribute('data-payment-plate-no', modal.getAttribute('data-plate-no') || '');
    fakeBtn.setAttribute('data-payment-repair-date', modal.getAttribute('data-repair-date') || today());
    fakeBtn.setAttribute('data-payment-total-cost', total.toFixed(2));
    fakeBtn.setAttribute('data-payment-balance', amountToPay.toFixed(2));
    fakeBtn.setAttribute('data-payment-grand-total', modal.getAttribute('data-full-repair-balance') || total.toFixed(2));
    fakeBtn.setAttribute('data-payment-full-repair-balance', modal.getAttribute('data-full-repair-balance') || total.toFixed(2));
    fakeBtn.setAttribute('data-payment-branch-source-balance', amountToPay.toFixed(2));
    fakeBtn.setAttribute('data-payment-has-branch-source', '1');
    fakeBtn.setAttribute('data-payment-source-key', modal.getAttribute('data-source-key') || '');
    openRepairPaymentModal(fakeBtn);
});


function renderTimelineAttachmentButtonsForBranch(attachment) {
    if (!attachment) return '';
    const raw = String(attachment).trim();
    if (!raw) return '';
    if (raw.startsWith('[') || raw.startsWith('{')) {
        const parsed = parseJsonSafe(raw, null);
        const list = Array.isArray(parsed) ? parsed : (parsed ? [parsed] : []);
        const links = list.map(function(p, index) {
            const file = p.filename || p.proof_photo || p.release_attachment || p.attachment || p.file || '';
            return file ? '<button type="button" class="btn btn-outline-success btn-sm me-1 mt-2" onclick="openMotorpoolFilePreview(\'' + escapeHtml(file) + '\', \'Workflow Attachment\')">Attachment ' + (index + 1) + '</button>' : '';
        }).join('');
        return links ? '<div>' + links + '</div>' : '';
    }
    return '<div><button type="button" class="btn btn-outline-success btn-sm mt-2" onclick="openMotorpoolFilePreview(\'' + escapeHtml(raw) + '\', \'Workflow Attachment\')">View Attachment</button></div>';
}

function branchWorkflowDataObject(row) {
    return {
        repair_history: dataValue(row, 'repairHistory') || '[]',
        workflow_history: dataValue(row, 'workflowHistory') || '[]',
        plate_no: dataValue(row, 'plateNo') || '',
        branch_name: dataValue(row, 'branchName') || ''
    };
}

function renderTimelineAttachmentButtonsForBranch(attachment) {
    if (!attachment) return '';
    const raw = String(attachment).trim();
    if (!raw) return '';
    if (raw.startsWith('[') || raw.startsWith('{')) {
        const parsed = parseJsonSafe(raw, null);
        const list = Array.isArray(parsed) ? parsed : (parsed ? [parsed] : []);
        const links = list.map(function(p, index) {
            const file = p.filename || p.proof_photo || p.release_attachment || p.attachment || p.file || '';
            return file ? '<button type="button" class="btn btn-outline-success btn-sm me-1 mt-2" onclick="openMotorpoolFilePreview(\'' + escapeHtml(file) + '\', \'Workflow Attachment\')">Attachment ' + (index + 1) + '</button>' : '';
        }).join('');
        return links ? '<div>' + links + '</div>' : '';
    }
    return '<div><button type="button" class="btn btn-outline-success btn-sm mt-2" onclick="openMotorpoolFilePreview(\'' + escapeHtml(raw) + '\', \'Workflow Attachment\')">View Attachment</button></div>';
}

function buildFallbackTimelineFromRepairHistoryForBranch(rowOrData) {
    const d = rowOrData && rowOrData.nodeType ? branchWorkflowDataObject(rowOrData) : rowOrData;
    const repairHistories = parseJsonSafe(d.repair_history || '[]', []);
    if (!Array.isArray(repairHistories) || !repairHistories.length) return [];
    return repairHistories.map(function(item) {
        const details = [];
        if (item.repairs_done) details.push('Repairs Done: ' + item.repairs_done);
        if (item.parts_replaced) details.push('Parts Replaced / Used:\n' + partsReplacedTextForTimelineV21(item.parts_replaced));
        if (item.mechanic) details.push('Mechanic: ' + item.mechanic);
        if (item.start_date) details.push('Start Date: ' + item.start_date);
        if (item.end_date) details.push('End Date: ' + item.end_date);
        if (item.checked_received_by) details.push('Checked and Received By: ' + item.checked_received_by);
        if (item.received_datetime) details.push('Date and Time Received: ' + item.received_datetime);
        return { workflow_status: 'For Release', processed_at: item.created_at || item.repair_date || '', processed_by_name: 'Motorpool', ris_number: item.ris_number || '', details: details.join('\n'), attachment: item.attachment || '' };
    });
}

function normalizeWorkflowStatusForBranch(status) {
    const value = String(status || '').trim().toLowerCase().replace(/[\s\-]+/g, ' ');
    if (value.includes('endorsement')) return 'For Vehicle Endorsement';
    if (value.includes('assessment')) return 'For Assessment';
    if (value.includes('approval')) return 'For Approval';
    if (value.includes('parts completion')) return 'For Parts Completion';
    if (value === 'for repair' || value.includes('for repair')) return 'For Repair';
    if (value.includes('ongoing repair') || value.includes('on going repair') || value.includes('on-going repair')) return 'On-going Repair';
    if (value.includes('quality check')) return 'For Quality Check';
    if (value.includes('release') || value.includes('completed repair') || value.includes('completed')) return 'For Release';
    return status || '';
}

function getWorkflowRowsForBranch(row, risNumber) {
    const d = branchWorkflowDataObject(row);
    let histories = parseJsonSafe(d.workflow_history || '[]', []);
    if (!Array.isArray(histories)) histories = [];
    if (!histories.length) histories = buildFallbackTimelineFromRepairHistoryForBranch(d);
    const wantedRis = String(risNumber || '').trim();
    if (!wantedRis) return histories;
    const filtered = histories.filter(function(item) { return String(item.ris_number || '').trim() === wantedRis; });
    return filtered.length ? filtered : histories;
}

function buildCanonicalReleaseRowsForRisBranchV34(d, risNumber, releaseRows) {
    const wantedRis = String(risNumber || '').trim();
    const sourceReleaseRows = Array.isArray(releaseRows) ? releaseRows.slice() : [];

    function extractCostSummaryBlock(rowDetails) {
        const rawLines = String(rowDetails || '').split(/\r?\n/);
        const picked = [];
        let active = false;
        rawLines.forEach(function(line) {
            const text = String(line || '').trim();
            const lower = text.toLowerCase();
            if (lower === 'cost summary:' || isCostSummaryLineV50(text)) {
                active = true;
                picked.push(text);
                return;
            }
            if (active) {
                if (text === '') {
                    picked.push(text);
                    return;
                }
                if (isCostSummaryLineV50(text)) {
                    picked.push(text);
                    return;
                }
                active = false;
            }
        });
        return picked.filter(function(line) { return String(line || '').trim() !== ''; }).join('\\n');
    }

    const releaseCostSummary = (sourceReleaseRows.map(function(row) {
        return extractCostSummaryBlock(row.details || '');
    }).filter(function(text) { return String(text || '').trim() !== ''; }).pop() || '');

    const repairHistories = parseJsonSafe(d.repair_history || '[]', []);
    const matches = (Array.isArray(repairHistories) ? repairHistories : []).filter(function(item) {
        return !wantedRis || String(item.ris_number || '').trim() === wantedRis;
    });
    if (matches.length) {
        const item = matches[matches.length - 1];
        const details = [];
        details.push('Repair completed and released to Branch Admin repair history.');
        if (item.repair_date) details.push('Repair Date: ' + item.repair_date);
        if (item.parts_replaced) details.push('Parts Replaced / Used:\n' + partsReplacedTextForTimelineV21(item.parts_replaced));
        if (item.mechanic) details.push('Mechanic: ' + item.mechanic);
        if (item.start_date) details.push('Start Date: ' + item.start_date);
        if (item.end_date) details.push('End Date: ' + item.end_date);
        if (item.checked_received_by) details.push('Checked and Received By: ' + item.checked_received_by);
        if (item.received_datetime) details.push('Date and Time Received: ' + item.received_datetime);
        if (releaseCostSummary) details.push(releaseCostSummary);
        return [{ workflow_status: 'For Release', ris_number: item.ris_number || wantedRis, processed_at: item.created_at || item.repair_date || '', processed_by_name: 'Motorpool', details: details.join('\n'), attachment: item.attachment || '' }];
    }
    const rows = sourceReleaseRows;
    rows.sort(function(a,b){
        function score(row){
            const details = String(row.details || '').toLowerCase();
            let s = 0;
            if (details.includes('parts replaced / used')) s += 10;
            if (details.includes('cost summary:')) s += 10;
            if (details.includes('miscellaneous cost:')) s += 5;
            if (String(row.attachment || '').trim()) s += 8;
            if (details.includes('checked and received by')) s += 4;
            if (details.includes('date and time received')) s += 4;
            if (details.includes('parts replaced:') && !details.includes('parts replaced / used')) s -= 5;
            return s;
        }
        return score(b) - score(a);
    });
    return rows.length ? [rows[0]] : [];
}

function findReleaseAttachmentForRisBranchV34(d, risNumber, rows) {
    const wantedRis = String(risNumber || '').trim();
    const candidates = [];
    (Array.isArray(rows) ? rows : []).forEach(function(item) {
        const file = String(item.attachment || item.release_attachment || item.proof_photo || '').trim();
        if (file) candidates.push(file);
    });
    const repairHistories = parseJsonSafe(d.repair_history || '[]', []);
    (Array.isArray(repairHistories) ? repairHistories : []).forEach(function(item) {
        if (wantedRis && String(item.ris_number || '').trim() !== wantedRis) return;
        const file = String(item.attachment || item.release_attachment || item.proof_photo || '').trim();
        if (file) candidates.push(file);
    });
    const unique = [];
    const seen = {};
    candidates.forEach(function(file) { if (!file || seen[file]) return; seen[file] = true; unique.push(file); });
    return unique.length ? unique[unique.length - 1] : '';
}

function renderWorkflowTimelineForBranch(targetId, row, risNumber) {
    const body = document.getElementById(targetId);
    if (!body) return;
    const d = branchWorkflowDataObject(row);
    const workflowStages = ['For Vehicle Endorsement','For Assessment','For Approval','For Parts Completion','For Repair','On-going Repair','For Quality Check','For Release'];
    const histories = getWorkflowRowsForBranch(row, risNumber);
    const repairHistoriesForLookup = parseJsonSafe(d.repair_history || '[]', []);
    const partLookupV24 = buildWorkflowPartLookupV24(histories, risNumber || '', Array.isArray(repairHistoriesForLookup) ? repairHistoriesForLookup : []);
    const grouped = {};
    histories.forEach(function(item) {
        const key = normalizeWorkflowStatusForBranch(item.workflow_status || item.status || '');
        if (!key) return;
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(item);
    });
    if (grouped['For Release'] && grouped['For Release'].length) {
        grouped['For Release'] = buildCanonicalReleaseRowsForRisBranchV34(d, risNumber, grouped['For Release']);
    }
    body.innerHTML = workflowStages.map(function(stage) {
        const rows = grouped[stage] || [];
        const isDone = rows.length > 0;
        const releaseAttachmentFile = stage === 'For Release' ? findReleaseAttachmentForRisBranchV34(d, risNumber, rows) : '';
        const releaseBottomAttachment = (stage === 'For Release' && releaseAttachmentFile)
            ? '<div class="timeline-subrecord mt-2"><div class="fw-semibold mb-1">Attachment:</div>' + renderTimelineAttachmentButtonsForBranch(releaseAttachmentFile) + '</div>'
            : '';
        const cards = rows.length ? rows.map(function(item) {
            const attachmentHtml = (stage === 'For Release') ? '' : renderTimelineAttachmentButtonsForBranch(item.attachment || '');
            const processedBy = (item.processed_by_name || '').trim() || (item.processed_by ? 'User #' + item.processed_by : 'System');
            let details = formatWorkflowDetailsWithLookupV24(item.details || '', partLookupV24, stage);
            if (stage === 'For Quality Check') {
                details = details.replace(/<div class="fw-semibold mt-2 mb-1">Parts Replaced \/ Used:<\/div>[\s\S]*?(?=<div|$)/i, '');
            }
            const risNo = item.ris_number ? ' • RIS No.: ' + escapeHtml(item.ris_number) : '';
            return '<div class="timeline-subrecord"><div class="timeline-meta">' + escapeHtml(item.processed_at || '') + ' • Processed by: ' + escapeHtml(processedBy) + risNo + '</div><div class="timeline-details">' + details + '</div>' + attachmentHtml + '</div>';
        }).join('') : '<div class="timeline-meta text-muted">No record yet for this step.</div>';
        return '<div class="timeline-item ' + (isDone ? 'timeline-done' : 'timeline-pending') + '"><div class="timeline-status">' + escapeHtml(stage) + '</div>' + cards + releaseBottomAttachment + '</div>';
    }).join('');
}

function findVehicleRowByRepairRisNumber(risNumber) {
    const wanted = String(risNumber || '').trim();
    if (!wanted) return null;
    const rows = document.querySelectorAll('tr.vehicle-click-row, tr.js-view-vehicle, tr[data-repair-history]');
    for (let i = 0; i < rows.length; i++) {
        const raw = rows[i].dataset ? (rows[i].dataset.repairHistory || '') : '';
        if (!raw) continue;
        const repairHistory = parseJsonSafe(raw, []);
        if (!Array.isArray(repairHistory)) continue;
        if (repairHistory.some(function(item) { return String(item.ris_number || '').trim() === wanted; })) {
            return rows[i];
        }
    }
    return null;
}

function openRepairWorkflowModalFromRepairRow(repairRow) {
    const risNumber = repairRow ? (repairRow.dataset.risNumber || repairRow.getAttribute('data-ris-number') || '') : '';
    if (!selectedVehicleRow) {
        selectedVehicleRow = findVehicleRowByRepairRisNumber(risNumber);
    }
    openRepairWorkflowModal(risNumber);
}

function openRepairWorkflowModal(risNumber) {
    if (!selectedVehicleRow) {
        selectedVehicleRow = findVehicleRowByRepairRisNumber(risNumber);
    }
    if (!selectedVehicleRow) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'warning', title: 'Repair workflow not found', text: 'Please open the vehicle details first, then click the repair history row again.' });
        }
        return;
    }
    const title = document.getElementById('repairWorkflowTitle');
    const subtitle = document.getElementById('repairWorkflowSubtitle');
    if (title) title.textContent = risNumber ? 'Detailed Repair Workflow - ' + risNumber : 'Detailed Repair Workflow';
    if (subtitle) subtitle.textContent = (dataValue(selectedVehicleRow, 'plateNo') ? 'Plate No.: ' + dataValue(selectedVehicleRow, 'plateNo') : '');
    renderWorkflowTimelineForBranch('repairWorkflowTimelineBody', selectedVehicleRow, risNumber);
    const vehicleDetailsModal = document.getElementById('vehicleDetailsModal');
    if (vehicleDetailsModal && vehicleDetailsModal.classList.contains('show')) {
        motorpoolShowModalFromCurrent('repairWorkflowModal', { parentModalId: 'vehicleDetailsModal' });
    } else {
        motorpoolShowModalFromCurrent('repairWorkflowModal');
    }
}

window.openRepairWorkflowModal = openRepairWorkflowModal;
window.openRepairWorkflowModalFromRepairRow = openRepairWorkflowModalFromRepairRow;



function getRowData(btn, key){
    const tr = btn.closest('tr');
    return tr ? (tr.dataset[key] || '') : '';
}

function getFuelMonitoringHistory(row) {
    const raw = row ? (row.dataset.fuelHistory || '') : '';
    if (!raw) return [];
    return parseJsonSafe(raw, []);
}
function normalizeFuelAttachmentPath(path) {
    path = String(path || '').trim();
    if (path === '') return '';
    return path.replace(/^\.\.\/uploads\/motorpool\//, '').replace(/^uploads\/motorpool\//, '').replace(/^\/uploads\/motorpool\//, '');
}
function fuelAttachmentButton(path, label) {
    const clean = normalizeFuelAttachmentPath(path);
    if (!clean) return 'N/A';
    return '<button type="button" class="btn btn-link p-0 text-success fw-semibold text-decoration-none" onclick="event.stopPropagation(); openMotorpoolFilePreview(\'' + escapeHtml(clean) + '\', \'' + escapeHtml(label || 'Fuel Attachment') + '\')"><i class="bi bi-paperclip me-1"></i>View</button>';
}
function formatPeso(value) {
    const numberValue = parseFloat(value || 0);
    return '₱' + numberValue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function getFuelEncoderName(item) {
    return item.driver_name || item.encoded_by_name || item.encoded_by || 'N/A';
}
function openFuelRecordDetails(index) {
    if (!selectedVehicleRow) return;
    const history = getFuelMonitoringHistory(selectedVehicleRow);
    const item = history[index];
    if (!item) return;

    const grid = document.getElementById('fuelRecordDetailsGrid');
    const attachmentWrap = document.getElementById('fuelRecordAttachmentWrap');
    const subtitle = document.getElementById('fuelRecordDetailsSubtitle');
    if (subtitle) {
        const plate = item.plate_no || dataValue(selectedVehicleRow, 'plateNo') || '';
        subtitle.textContent = (plate ? 'Plate No.: ' + plate + ' • ' : '') + (item.fuel_date || 'Fuel record');
    }
    const details = [
        ['Date', item.fuel_date || 'N/A'],
        ['Vehicle ID', item.vehicle_id || dataValue(selectedVehicleRow, 'vehicleId') || 'N/A'],
        ['Plate No.', item.plate_no || dataValue(selectedVehicleRow, 'plateNo') || 'N/A'],
        ['Refuel (Liters)', item.refuel_liters || item.liters_consumed || '0.00'],
        ['Liters Consumed', item.liters_consumed || '0.00'],
        ['Price', formatPeso(item.fuel_price || 0)],
        ['Current Odometer Reading', item.current_odometer || '0.00'],
        ['Previous Odometer Reading', item.previous_odometer || '0.00'],
        ['Distance Covered (km)', item.distance_covered || '0.00'],
        ['Fuel Efficiency (km/L)', item.fuel_efficiency || '0.00'],
        ['Encoded By', getFuelEncoderName(item)],
        ['Date Encoded', item.created_at || 'N/A']
    ];

    if (grid) {
        grid.innerHTML = details.map(function(pair) {
            return '<div class="detail-info-item"><div class="detail-label">' + escapeHtml(pair[0]) + '</div><div class="detail-value">' + escapeHtml(pair[1]) + '</div></div>';
        }).join('');
    }

    if (attachmentWrap) {
        const attachment = item.fuel_attachment || '';
        attachmentWrap.innerHTML = '<div class="detail-info-item"><div class="detail-label">Attachment</div><div class="detail-value">' + fuelAttachmentButton(attachment, 'Fuel Attachment') + '</div></div>';
    }

    motorpoolShowModalFromCurrent('fuelRecordDetailsModal', { parentModalId: 'vehicleDetailsModal', returnTabTarget: '#vehicleFuelMonitoringTab' });
}
function renderVehicleFuelMonitoring(row) {
    const tbody = document.getElementById('detailFuelMonitoringBody');
    if (!tbody) return;
    const history = getFuelMonitoringHistory(row);
    if (!Array.isArray(history) || history.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">Fuel monitoring records will appear here once available.</td></tr>';
        return;
    }
    tbody.innerHTML = history.map(function(item, index) {
        return '<tr class="clickable-fuel-row" onclick="openFuelRecordDetails(' + index + ')" title="Click to view full fuel details">' +
            '<td>' + escapeHtml(item.fuel_date || '') + '</td>' +
            '<td>' + escapeHtml(item.refuel_liters || item.liters_consumed || '') + '</td>' +
            '<td>' + escapeHtml(item.current_odometer || '') + '</td>' +
            '<td>' + escapeHtml(item.previous_odometer || '') + '</td>' +
            '<td>' + escapeHtml(item.distance_covered || '') + '</td>' +
            '<td>' + escapeHtml(item.liters_consumed || '') + '</td>' +
            '<td>' + escapeHtml(item.fuel_efficiency || '') + '</td>' +
        '</tr>';
    }).join('');
}
function computeFuelMonitoringValues(changedFieldId = '') {
    const currentInput = document.getElementById('fuelCurrentOdometer');
    const previousInput = document.getElementById('fuelPreviousOdometer');
    const litersInput = document.getElementById('fuelLitersConsumed');
    const distanceInput = document.getElementById('fuelDistanceCovered');
    const efficiencyInput = document.getElementById('fuelEfficiency');

    const current = parseFloat(currentInput?.value || '0');
    const previous = parseFloat(previousInput?.value || '0');
    const liters = parseFloat(litersInput?.value || '0');
    const refuelInput = document.getElementById('fuelRefuelLiters');
    if (refuelInput && (!refuelInput.value || parseFloat(refuelInput.value || '0') <= 0) && liters > 0) {
        refuelInput.value = liters.toFixed(2);
    }

    let distance = parseFloat(distanceInput?.value || '0');
    const odometerChanged = changedFieldId === 'fuelCurrentOdometer' || changedFieldId === 'fuelPreviousOdometer';

    if (currentInput?.value !== '' && previousInput?.value !== '' && current >= previous) {
        distance = current - previous;
        if (distanceInput) distanceInput.value = distance > 0 ? distance.toFixed(2) : '';
    } else if (odometerChanged && distanceInput) {
        distance = 0;
        distanceInput.value = '';
    }

    if (distance > 0 && liters > 0 && efficiencyInput) {
        efficiencyInput.value = (distance / liters).toFixed(2);
    } else if ((odometerChanged || changedFieldId === 'fuelLitersConsumed' || changedFieldId === 'fuelDistanceCovered') && efficiencyInput) {
        efficiencyInput.value = '';
    }
}
document.addEventListener('input', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('fuel-calc-field')) {
        computeFuelMonitoringValues(e.target.id || '');
    }
});
function openFuelMonitoringModal(btn) {
    const row = btn.closest('tr'); if (!row) return;
    selectedVehicleRow = row;
    const form = document.getElementById('fuelMonitoringForm'); if (form) form.reset();
    document.getElementById('fuelVehicleDbId').value = dataValue(row, 'dbId');
    document.getElementById('fuelVehicleCode').value = dataValue(row, 'vehicleId');
    document.getElementById('fuelPlateNo').value = dataValue(row, 'plateNo');
    document.getElementById('fuelDate').value = today();
    document.getElementById('fuelVehicleTitle').textContent = [dataValue(row, 'makeBrand'), dataValue(row, 'vehicleType')].filter(Boolean).join(' - ') || 'Vehicle';
    document.getElementById('fuelVehicleSubtitle').textContent = dataValue(row, 'plateNo') ? 'Plate No.: ' + dataValue(row, 'plateNo') : 'Fuel monitoring record';
    const history = getFuelMonitoringHistory(row);
    if (history.length && history[0].current_odometer) document.getElementById('fuelPreviousOdometer').value = history[0].current_odometer;

    const vehicleDetailsModal = document.getElementById('vehicleDetailsModal');
    const openedFromVehicleDetails = vehicleDetailsModal && vehicleDetailsModal.classList.contains('show');

    if (openedFromVehicleDetails) {
        motorpoolShowModalFromCurrent('fuelMonitoringModal', {
            parentModalId: 'vehicleDetailsModal',
            returnTabTarget: '#vehicleFuelMonitoringTab'
        });
    } else {
        motorpoolShowModalFromCurrent('fuelMonitoringModal');
    }
}
function saveFuelMonitoring() {
    const form = document.getElementById('fuelMonitoringForm'); if (!form) return;
    computeFuelMonitoringValues();
    const formData = new FormData(form);
    fetch('motorpool.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
        if (!data.success) { alert(data.message || 'Failed to save fuel monitoring record.'); return; }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('fuelMonitoringModal')).hide();
        if (selectedVehicleRow) {
            const history = getFuelMonitoringHistory(selectedVehicleRow);
            history.unshift({ fuel_id: data.fuel_id || '', vehicle_db_id: data.vehicle_db_id || '', vehicle_id: data.vehicle_id || '', plate_no: data.plate_no || '', fuel_date: data.fuel_date || '', current_odometer: data.current_odometer || '', previous_odometer: data.previous_odometer || '', distance_covered: data.distance_covered || '', liters_consumed: data.liters_consumed || '', refuel_liters: data.refuel_liters || data.liters_consumed || '', fuel_efficiency: data.fuel_efficiency || '', fuel_price: data.fuel_price || '', fuel_attachment: data.fuel_attachment || '', driver_name: data.driver_name || '', encoded_by_name: data.encoded_by_name || '', created_at: data.created_at || '' });
            selectedVehicleRow.dataset.fuelHistory = JSON.stringify(history);
            renderVehicleFuelMonitoring(selectedVehicleRow);
        }
        setTimeout(function(){ motorpoolShowModalFromCurrent('vehicleDetailsModal'); const fuelTabBtn = document.querySelector('[data-bs-target="#vehicleFuelMonitoringTab"]'); if (fuelTabBtn) bootstrap.Tab.getOrCreateInstance(fuelTabBtn).show(); }, 200);
    }).catch(error => { console.error('Fuel monitoring save error:', error); alert('Failed to save fuel monitoring record. Please try again.'); });
}

function openRisModal(btn){
    const vehicleId = getRowData(btn, 'vehicleId');
    const vehicleDbId = getRowData(btn, 'dbId');
    const plateNo = getRowData(btn, 'plateNo');
    const makeBrand = getRowData(btn, 'makeBrand');
    const vehicleType = getRowData(btn, 'vehicleType');
    const category = getRowData(btn, 'vehicleCategory');
    const classification = getRowData(btn, 'classification');
    const bodyType = getRowData(btn, 'bodyType');
    const color = getRowData(btn, 'color');
    const fuelType = getRowData(btn, 'typeOfFuel');
    const yearModel = getRowData(btn, 'yearModel');
    const series = getRowData(btn, 'series');
    const passengerCapacity = getRowData(btn, 'passengerCapacity');
    const maxPower = getRowData(btn, 'maxPowerKw');
    const ltoCrNo = getRowData(btn, 'ltoCrNo');
    const dateRegistration = getRowData(btn, 'dateRegistration');
    const fileNo = getRowData(btn, 'fileNo');
    const engineNo = getRowData(btn, 'engineNo');
    const chassisNo = getRowData(btn, 'chassisNo');
    const vin = getRowData(btn, 'vin');
    const grossWeight = getRowData(btn, 'grossWeight');
    const netWeight = getRowData(btn, 'netWeight');
    const yearRebuilt = getRowData(btn, 'yearRebuilt');
    const pistonDisplacement = getRowData(btn, 'pistonDisplacement');

    document.getElementById('risVehicleDbId').value = vehicleDbId;
    document.getElementById('risVehicleCode').value = vehicleId;
    document.getElementById('risVehicleName').value = [makeBrand, vehicleType].filter(Boolean).join(' - ');
    document.getElementById('risPlateNo').value = plateNo;
    document.getElementById('risCategory').value = category;
    document.getElementById('risMakeBrand').value = makeBrand;
    document.getElementById('risVehicleType').value = vehicleType;
    document.getElementById('risClassification').value = classification;
    document.getElementById('risBodyType').value = bodyType;
    document.getElementById('risColor').value = color;
    document.getElementById('risFuelType').value = fuelType;
    document.getElementById('risYearModel').value = yearModel;
    document.getElementById('risSeries').value = series;
    document.getElementById('risPassengerCapacity').value = passengerCapacity;
    document.getElementById('risMaxPower').value = maxPower;
    document.getElementById('risLtoCrNo').value = ltoCrNo;
    document.getElementById('risDateRegistration').value = dateRegistration;
    document.getElementById('risFileNo').value = fileNo;
    document.getElementById('risEngineNo').value = engineNo;
    document.getElementById('risChassisNo').value = chassisNo;
    document.getElementById('risVin').value = vin;
    document.getElementById('risGrossWeight').value = grossWeight;
    document.getElementById('risNetWeight').value = netWeight;
    document.getElementById('risYearRebuilt').value = yearRebuilt;
    document.getElementById('risPistonDisplacement').value = pistonDisplacement;

    const risVehicleGrid = document.getElementById('risVehicleDetailsGrid');
    if (risVehicleGrid) {
        risVehicleGrid.innerHTML = [
            ['Plate No.', plateNo],
            ['Make/Brand', makeBrand],
            ['Vehicle Type', vehicleType],
            ['Vehicle Category', category],
            ['Classification', classification],
            ['Body Type', bodyType],
            ['Color', color],
            ['Type of Fuel', fuelType],
            ['Year Model', yearModel],
            ['Series', series],
            ['Passenger Capacity', passengerCapacity],
            ['Max Power (KW)', maxPower],
            ['LTO CR No.', ltoCrNo],
            ['Date of Registration', dateRegistration],
            ['File No.', fileNo],
            ['Engine No.', engineNo],
            ['Chassis No.', chassisNo],
            ['VIN', vin],
            ['Gross Weight', grossWeight],
            ['Net Weight', netWeight],
            ['Year Rebuilt', yearRebuilt],
            ['Piston Displacement', pistonDisplacement]
        ].map(([label, value]) => buildRisDetailItem(label, value)).join('');
    }

    document.getElementById('risConcerns').value = '';
    document.getElementById('risEndorsedBy').value = '';
    document.getElementById('risDate').value = today();
    clearSignature();

    const risModalElement = document.getElementById('risModal');
    motorpoolShowModalFromCurrent('risModal');
    setTimeout(function() {
        resizeSignatureCanvas();
        clearSignature();
    }, 250);
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function(match) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[match];
    });
}

function buildRisPrintHtml(data) {
    return `<!doctype html>
<html>
<head>
<title>${escapeHtml(data.ris_number || 'RIS')}</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;color:#111}
.header{text-align:center;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:18px}
h2{margin:0 0 4px}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
.box{border:1px solid #222;padding:8px}
.label{font-size:12px;color:#555;margin-bottom:3px}
.value{font-weight:700;min-height:18px}
.concern{border:1px solid #222;padding:10px;min-height:90px;white-space:pre-wrap}
.signatures{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:50px}
.sig{text-align:center;border-top:1px solid #111;padding-top:6px}.sig img{max-width:220px;max-height:90px;display:block;margin:-95px auto 8px;object-fit:contain}
@media print{button{display:none}}
</style>
</head>
<body>
<div class="header">
<h2>Request for Inspection Slip</h2>
<div>RIS No.: <strong>${escapeHtml(data.ris_number)}</strong></div>
</div>
<div class="meta">
<div class="box"><div class="label">Date Requested</div><div class="value">${escapeHtml(data.date_requested)}</div></div>
<div class="box"><div class="label">Status</div><div class="value">Pending</div></div>
<div class="box"><div class="label">Vehicle ID</div><div class="value">${escapeHtml(data.vehicle_id)}</div></div>
<div class="box"><div class="label">Plate No.</div><div class="value">${escapeHtml(data.plate_no)}</div></div>
<div class="box"><div class="label">Vehicle Details</div><div class="value">${escapeHtml(data.vehicle_details)}</div></div>
<div class="box"><div class="label">Category</div><div class="value">${escapeHtml(data.vehicle_category)}</div></div>
<div class="box"><div class="label">Make/Brand</div><div class="value">${escapeHtml(data.make_brand)}</div></div>
<div class="box"><div class="label">Vehicle Type</div><div class="value">${escapeHtml(data.vehicle_type)}</div></div>
<div class="box"><div class="label">Classification</div><div class="value">${escapeHtml(data.classification)}</div></div>
<div class="box"><div class="label">Body Type</div><div class="value">${escapeHtml(data.body_type)}</div></div>
<div class="box"><div class="label">Color</div><div class="value">${escapeHtml(data.color)}</div></div>
<div class="box"><div class="label">Type of Fuel</div><div class="value">${escapeHtml(data.type_of_fuel)}</div></div>
<div class="box"><div class="label">Year Model</div><div class="value">${escapeHtml(data.year_model)}</div></div>
<div class="box"><div class="label">Series</div><div class="value">${escapeHtml(data.series)}</div></div>
<div class="box"><div class="label">Passenger Capacity</div><div class="value">${escapeHtml(data.passenger_capacity)}</div></div>
<div class="box"><div class="label">Max Power (KW)</div><div class="value">${escapeHtml(data.max_power_kw)}</div></div>
<div class="box"><div class="label">LTO CR No.</div><div class="value">${escapeHtml(data.lto_cr_no)}</div></div>
<div class="box"><div class="label">Date of Registration</div><div class="value">${escapeHtml(data.date_registration)}</div></div>
<div class="box"><div class="label">File No.</div><div class="value">${escapeHtml(data.file_no)}</div></div>
<div class="box"><div class="label">Engine No.</div><div class="value">${escapeHtml(data.engine_no)}</div></div>
<div class="box"><div class="label">Chassis No.</div><div class="value">${escapeHtml(data.chassis_no)}</div></div>
<div class="box"><div class="label">VIN</div><div class="value">${escapeHtml(data.vin)}</div></div>
<div class="box"><div class="label">Gross Weight</div><div class="value">${escapeHtml(data.gross_weight)}</div></div>
<div class="box"><div class="label">Net Weight</div><div class="value">${escapeHtml(data.net_weight)}</div></div>
<div class="box"><div class="label">Year Rebuilt</div><div class="value">${escapeHtml(data.year_rebuilt)}</div></div>
<div class="box"><div class="label">Piston Displacement</div><div class="value">${escapeHtml(data.piston_displacement)}</div></div>
</div>
<div class="label">Concern/s</div>
<div class="concern">${escapeHtml(data.concerns)}</div>
<div class="signatures">
<div class="sig">${data.endorsed_signature ? `<img src="${data.endorsed_signature}" alt="Signature">` : ``}Endorsed by: ${escapeHtml(data.endorsed_by)}</div>
<div class="sig">Received by Motorpool</div>
</div>
<script>window.onload=function(){window.print();};<\/script>
</body>
</html>`;
}

function sendAndPrintRis(){
    const form = document.getElementById('risForm');
    const concerns = document.getElementById('risConcerns').value.trim();
    const endorsedBy = document.getElementById('risEndorsedBy').value.trim();

    if (!concerns) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Concern',
                text: 'Concern/s is required.',
                confirmButtonColor: '#07b83f'
            });
        } else {
            alert('Concern/s is required.');
        }
        return;
    }

    if (!endorsedBy) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Endorsement',
                text: 'Endorsed by is required.',
                confirmButtonColor: '#07b83f'
            });
        } else {
            alert('Endorsed by is required.');
        }
        return;
    }

    saveSignature();
    const formData = new FormData(form);

    fetch('motorpool.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: data.message || 'Failed to send RIS request.',
                        confirmButtonColor: '#dc3545'
                    });
                } else {
                    alert(data.message || 'Failed to send RIS request.');
                }
                return;
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('risModal')).hide();

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Request Sent!',
                    html: 'RIS request successfully sent to Motorpool account.<br><br>Ready to print RIS.',
                    confirmButtonText: 'Print RIS',
                    confirmButtonColor: '#07b83f',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        const printWindow = window.open('', '_blank', 'width=900,height=700');
                        if (printWindow) {
                            printWindow.document.open();
                            printWindow.document.write(buildRisPrintHtml(data));
                            printWindow.document.close();
                        } else {
                            window.print();
                        }
                    }
                });
            } else {
                alert(data.message || 'RIS request sent to Motorpool account.');
                const printWindow = window.open('', '_blank', 'width=900,height=700');
                if (printWindow) {
                    printWindow.document.open();
                    printWindow.document.write(buildRisPrintHtml(data));
                    printWindow.document.close();
                } else {
                    window.print();
                }
            }
        })
        .catch(error => {
            console.error('RIS send error:', error);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to send RIS request. Please try again.',
                    confirmButtonColor: '#dc3545'
                });
            } else {
                alert('Failed to send RIS request. Please try again.');
            }
        });
}
function printRis(){ window.print(); }
<?php if (!empty($save_message)): ?>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof Swal !== 'undefined') {
        Swal.fire({icon: '<?= h($save_status === 'success' ? 'success' : 'error') ?>', title: '<?= h($save_status === 'success' ? 'Saved' : 'Error') ?>', text: '<?= h($save_message) ?>'}).then(function(){ <?php if ($save_status === 'success'): ?>window.location.href = window.location.pathname;<?php endif; ?> });
    } else {
        alert('<?= h($save_message) ?>');
        <?php if ($save_status === 'success'): ?>window.location.href = window.location.pathname;<?php endif; ?>
    }
});
<?php endif; ?>

// Signature pad initialization
let signatureCanvas = null;
let signatureCtx = null;
let isSigning = false;
let signatureHasInk = false;
let signatureDraftValue = '';
let signatureReturnScrollTop = 0;

function initSignaturePad() {
    signatureCanvas = document.getElementById('signaturePad');
    if (!signatureCanvas) return;

    signatureCtx = signatureCanvas.getContext('2d');
    resizeSignatureCanvas();

    signatureCanvas.addEventListener('mousedown', startSignatureDraw);
    signatureCanvas.addEventListener('mouseup', stopSignatureDraw);
    signatureCanvas.addEventListener('mouseleave', stopSignatureDraw);
    signatureCanvas.addEventListener('mousemove', drawSignature);

    signatureCanvas.addEventListener('touchstart', startSignatureDraw, { passive: false });
    signatureCanvas.addEventListener('touchend', stopSignatureDraw, { passive: false });
    signatureCanvas.addEventListener('touchcancel', stopSignatureDraw, { passive: false });
    signatureCanvas.addEventListener('touchmove', drawSignature, { passive: false });

    updateSignaturePreview();
}

function resizeSignatureCanvas() {
    signatureCanvas = signatureCanvas || document.getElementById('signaturePad');
    if (!signatureCanvas) return;

    const previousSignature = signatureHasInk ? signatureCanvas.toDataURL('image/png') : '';
    const ratio = window.devicePixelRatio || 1;
    const rect = signatureCanvas.getBoundingClientRect();
    const width = rect.width || signatureCanvas.offsetWidth || 500;
    const height = window.innerWidth <= 576 ? 260 : 320;

    signatureCanvas.width = width * ratio;
    signatureCanvas.height = height * ratio;
    signatureCanvas.style.height = height + 'px';

    signatureCtx = signatureCanvas.getContext('2d');
    signatureCtx.setTransform(ratio, 0, 0, ratio, 0, 0);
    signatureCtx.lineWidth = 2;
    signatureCtx.lineCap = 'round';
    signatureCtx.lineJoin = 'round';
    signatureCtx.strokeStyle = '#000';

    if (previousSignature) {
        drawSignatureImage(previousSignature);
    }
}

function drawSignatureImage(dataUrl) {
    if (!signatureCanvas || !signatureCtx || !dataUrl) return;

    const rect = signatureCanvas.getBoundingClientRect();
    const width = rect.width || signatureCanvas.offsetWidth || 500;
    const height = window.innerWidth <= 576 ? 260 : 320;
    const image = new Image();

    image.onload = function() {
        signatureCtx.clearRect(0, 0, width, height);
        signatureCtx.drawImage(image, 0, 0, width, height);
        signatureHasInk = true;
    };
    image.src = dataUrl;
}

function getSignaturePoint(e) {
    const rect = signatureCanvas.getBoundingClientRect();
    const source = e.touches && e.touches.length ? e.touches[0] : e;
    return {
        x: source.clientX - rect.left,
        y: source.clientY - rect.top
    };
}

function startSignatureDraw(e) {
    if (!signatureCanvas || !signatureCtx) return;
    e.preventDefault();

    isSigning = true;
    signatureHasInk = true;

    const point = getSignaturePoint(e);
    signatureCtx.beginPath();
    signatureCtx.moveTo(point.x, point.y);
}

function stopSignatureDraw(e) {
    if (e) e.preventDefault();
    isSigning = false;
    if (signatureCtx) signatureCtx.beginPath();
}

function drawSignature(e) {
    if (!isSigning || !signatureCanvas || !signatureCtx) return;
    e.preventDefault();

    const point = getSignaturePoint(e);
    signatureCtx.lineTo(point.x, point.y);
    signatureCtx.stroke();
    signatureCtx.beginPath();
    signatureCtx.moveTo(point.x, point.y);
}

function clearSignaturePadOnly() {
    signatureCanvas = signatureCanvas || document.getElementById('signaturePad');
    if (!signatureCanvas) return;

    signatureCtx = signatureCtx || signatureCanvas.getContext('2d');
    signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
    signatureHasInk = false;
}

function clearSignature() {
    clearSignaturePadOnly();
    const signatureInput = document.getElementById('signatureInput');
    if (signatureInput) signatureInput.value = '';
    updateSignaturePreview();
}

function openSignatureModal() {
    const risModalElement = document.getElementById('risModal');
    const signatureModalElement = document.getElementById('signatureModal');
    const signatureInput = document.getElementById('signatureInput');
    const risModalBody = risModalElement ? risModalElement.querySelector('.modal-body') : null;

    if (!risModalElement || !signatureModalElement) return;

    signatureDraftValue = signatureInput ? signatureInput.value : '';
    signatureReturnScrollTop = risModalBody ? risModalBody.scrollTop : 0;

    signatureModalElement.addEventListener('shown.bs.modal', function() {
        resizeSignatureCanvas();
        clearSignaturePadOnly();
        if (signatureDraftValue) {
            drawSignatureImage(signatureDraftValue);
        }
    }, { once: true });

    motorpoolShowModalFromCurrent('signatureModal', { parentModalId: 'risModal' });
}

function restoreRisModalScrollPosition() {
    const risModalElement = document.getElementById('risModal');
    const risModalBody = risModalElement ? risModalElement.querySelector('.modal-body') : null;
    if (!risModalBody) return;

    risModalBody.scrollTop = signatureReturnScrollTop;

    setTimeout(function() {
        risModalBody.scrollTop = signatureReturnScrollTop;
    }, 80);

    setTimeout(function() {
        risModalBody.scrollTop = signatureReturnScrollTop;
    }, 220);
}

function closeSignatureModalAndReturnToRis() {
    const signatureModalElement = document.getElementById('signatureModal');
    const risModalElement = document.getElementById('risModal');

    if (!signatureModalElement || !risModalElement) return;

    risModalElement.addEventListener('shown.bs.modal', restoreRisModalScrollPosition, { once: true });
    signatureModalElement.addEventListener('hidden.bs.modal', function() {
        setTimeout(restoreRisModalScrollPosition, 260);
    }, { once: true });

    bootstrap.Modal.getOrCreateInstance(signatureModalElement).hide();
}

function cancelSignatureModal() {
    const signatureInput = document.getElementById('signatureInput');
    if (signatureInput) signatureInput.value = signatureDraftValue || '';
    closeSignatureModalAndReturnToRis();
}

function saveSignatureFromModal() {
    const signatureInput = document.getElementById('signatureInput');

    if (signatureInput) {
        signatureInput.value = signatureHasInk && signatureCanvas
            ? signatureCanvas.toDataURL('image/png')
            : '';
    }

    updateSignaturePreview();
    closeSignatureModalAndReturnToRis();
}

function removeSavedSignature() {
    const signatureInput = document.getElementById('signatureInput');
    if (signatureInput) signatureInput.value = '';
    clearSignaturePadOnly();
    updateSignaturePreview();
}

function updateSignaturePreview() {
    const signatureInput = document.getElementById('signatureInput');
    const previewImage = document.getElementById('signaturePreviewImage');
    const previewEmpty = document.getElementById('signaturePreviewEmpty');
    const openBtn = document.getElementById('openSignatureModalBtn');
    const removeBtn = document.getElementById('removeSignatureBtn');
    const value = signatureInput ? signatureInput.value : '';

    if (!previewImage || !previewEmpty || !openBtn || !removeBtn) return;

    if (value) {
        previewImage.src = value;
        previewImage.classList.remove('d-none');
        previewEmpty.classList.add('d-none');
        removeBtn.classList.remove('d-none');
        openBtn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit Signature';
    } else {
        previewImage.src = '';
        previewImage.classList.add('d-none');
        previewEmpty.classList.remove('d-none');
        removeBtn.classList.add('d-none');
        openBtn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Add Signature';
    }
}

function saveSignature() {
    const signatureInput = document.getElementById('signatureInput');
    if (!signatureInput) return;

    if (signatureInput.value) {
        updateSignaturePreview();
        return;
    }

    if (signatureCanvas && signatureHasInk) {
        signatureInput.value = signatureCanvas.toDataURL('image/png');
    }

    updateSignaturePreview();
}

document.addEventListener('DOMContentLoaded', initSignaturePad);
window.addEventListener('resize', resizeSignatureCanvas);

// ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
window.closeAllMobileDropdowns = function() {
    document.querySelectorAll('.more-dropdown').forEach(function(el) {
        el.classList.remove('show');
    });
    document.querySelectorAll('.more-btn').forEach(function(btn) {
        btn.classList.remove('active', 'has-active');
        btn.setAttribute('aria-expanded', 'false');
    });
};

window.toggleMobileDropdown = function(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    var dropdown = document.getElementById(dropdownId);
    var btn = event.currentTarget;

    if (!dropdown) return false;

    var isOpen = dropdown.classList.contains('show');

    window.closeAllMobileDropdowns();

    if (!isOpen) {
        dropdown.classList.add('show');
        btn.classList.add('active');
        btn.setAttribute('aria-expanded', 'true');
    }

    return false;
};

window.toggleDropdown = function(event, dropdownId) {
    return window.toggleMobileDropdown(event, dropdownId);
};

window.showProfileModal = function(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    if (typeof cleanupModalBackdrops === 'function') {
        cleanupModalBackdrops();
    }

    window.closeAllMobileDropdowns();

    var modal = document.getElementById('profileModal');
    if (modal) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    return false;
};

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.mobile-nav')) {
        window.closeAllMobileDropdowns();
    }
});

// Close dropdowns on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.closeAllMobileDropdowns();
    }
});

// Set active mobile nav item based on current page
function setActiveMobileNav() {
    var currentPage = window.location.pathname.split('/').pop();
    
    // Remove all active classes from ALL navigation elements
    document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(function(el) {
        el.classList.remove('active', 'has-active');
    });
    
    // Main navigation links (non-dropdown items)
    var mainNavLinks = document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)');
    mainNavLinks.forEach(function(link) {
        var href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
        }
    });
    
    // Dropdown items - set active state on the dropdown item itself
    document.querySelectorAll('.more-dropdown .dropdown-item').forEach(function(item) {
        var href = item.getAttribute('href');
        if (href === currentPage) {
            item.classList.add('active');
            
            // Mark the parent more-btn as has-active
            var parentDropdown = item.closest('.dropdown-more');
            if (parentDropdown) {
                var parentBtn = parentDropdown.querySelector('.more-btn');
                if (parentBtn) {
                    parentBtn.classList.add('has-active');
                }
            }
        }
    });
    
    // Special handling for motorpool.php (current page)
    if (currentPage === 'motorpool.php') {
        var sharedServicesBtn = document.querySelector('#sharedServicesMobileDropdown .more-btn');
        if (sharedServicesBtn) {
            sharedServicesBtn.classList.add('has-active');
        }
        var motorpoolItem = document.querySelector('#sharedServicesMobileMenu .dropdown-item[href="motorpool.php"]');
        if (motorpoolItem) {
            motorpoolItem.classList.add('active');
        }
    }
}

// Fix modal backdrop cleanup
function cleanupModalBackdrops() {
    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');
}

// Logout confirmation
function confirmLogout() {
    var modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
    if (modal) {
        modal.hide();
    }
    
    Swal.fire({
        title: 'Are you sure?',
        text: 'You will be logged out of the system',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#07d826',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, logout'
    }).then(function(result) {
        if (result.isConfirmed) {
            localStorage.removeItem('sidebarCollapsed');
            window.location.href = '../logout.php';
        }
    });
}

// Initialize mobile nav on DOM load
document.addEventListener('DOMContentLoaded', function() {
    setActiveMobileNav();
});

// AUTO-SCROLL TO ACTIVE SIDEBAR ITEM
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

// Expand dropdown menus that contain the active link (para lumabas ang nakatagong menu)
function expandActiveDropdownContainers() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar || sidebar.classList.contains('collapsed')) return;

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

// Execute after page loads (with a small delay para sure na rendered na ang sidebar)
setTimeout(() => {
    expandActiveDropdownContainers();  // Buksan ang dropdown kung nasa loob ang active item
    scrollToActiveSidebarItem();       // I-scroll papunta sa active item
}, 150);


/* ===== BRANCH ADMIN VEHICLE ROW CLICK FIX =====
   Keeps Repair History / Detailed Repair Workflow data intact.
   Only the registered vehicle table row opens Vehicle Details.
   Buttons inside the row, like RIS and Refuel, will not trigger the row modal.
*/
function branchAdminVehicleRowShouldIgnoreClick(target) {
    return !!(target && target.closest('button, a, input, select, textarea, label, .btn, .dropdown-menu, .modal, [data-no-row-click="1"]'));
}

function branchAdminOpenVehicleDetailsFromRow(row) {
    if (!row) return false;
    if (typeof window.viewVehicleDetails === 'function') {
        window.viewVehicleDetails(row);
        return false;
    }

    // Fallback only, in case another script loads late.
    window.selectedVehicleRow = row;
    const modalEl = document.getElementById('vehicleDetailsModal');
    if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    return false;
}

function branchAdminVehicleRowInlineClick(event, row) {
    if (event && event.target && event.target.closest('button, a, input, select, textarea, label, .btn, [data-bs-toggle]')) {
        return true;
    }
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    return branchAdminOpenVehicleDetailsFromRow(row);
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('tr.vehicle-click-row.js-view-vehicle[data-row-purpose="vehicle-profile"]').forEach(function(row) {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function(event) {
            if (branchAdminVehicleRowShouldIgnoreClick(event.target)) return;
            event.preventDefault();
            event.stopPropagation();
            branchAdminOpenVehicleDetailsFromRow(row);
        });
    });
});


/* ===== RETURNED RIS AUTO-FILL + SCROLLABLE MODAL ===== */
document.addEventListener('DOMContentLoaded', function() {
    const resubmitModal = document.getElementById('resubmitReturnedRisModal');
    const returnedRisId = document.getElementById('returnedRisId');
    const returnedRisNumber = document.getElementById('returnedRisNumber');
    const returnedDateRequested = document.getElementById('returnedDateRequested');
    const returnedEndorsedBy = document.getElementById('returnedEndorsedBy');
    const returnedConcerns = document.getElementById('returnedConcerns');
    const returnedMotorpoolRemarks = document.getElementById('returnedMotorpoolRemarks');
    const branchResubmissionRemarks = document.getElementById('branchResubmissionRemarks');

    document.querySelectorAll('.returned-ris-resubmit-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            const risId = this.getAttribute('data-ris-id') || '';
            const risNumber = this.getAttribute('data-ris-number') || '';
            const concerns = this.getAttribute('data-concerns') || '';
            const endorsedBy = this.getAttribute('data-endorsed-by') || '';
            const dateRequested = this.getAttribute('data-date-requested') || '';
            const returnRemarks = this.getAttribute('data-return-remarks') || '';

            if (returnedRisId) returnedRisId.value = risId;
            if (returnedRisNumber) returnedRisNumber.value = risNumber;
            if (returnedDateRequested) returnedDateRequested.value = dateRequested;
            if (returnedEndorsedBy) returnedEndorsedBy.value = endorsedBy;
            if (returnedConcerns) returnedConcerns.value = concerns;
            if (returnedMotorpoolRemarks) returnedMotorpoolRemarks.textContent = returnRemarks || 'No remarks provided.';
            if (branchResubmissionRemarks) branchResubmissionRemarks.value = '';

            if (resubmitModal) {
                const modalBody = resubmitModal.querySelector('.modal-body');
                if (modalBody) modalBody.scrollTop = 0;
            }
        });
    });

    if (resubmitModal) {
        resubmitModal.addEventListener('shown.bs.modal', function(event) {
            const trigger = event.relatedTarget;
            if (!trigger) return;

            const risId = trigger.getAttribute('data-ris-id') || '';
            const risNumber = trigger.getAttribute('data-ris-number') || '';
            const concerns = trigger.getAttribute('data-concerns') || '';
            const endorsedBy = trigger.getAttribute('data-endorsed-by') || '';
            const dateRequested = trigger.getAttribute('data-date-requested') || '';
            const returnRemarks = trigger.getAttribute('data-return-remarks') || '';

            if (returnedRisId) returnedRisId.value = risId;
            if (returnedRisNumber) returnedRisNumber.value = risNumber;
            if (returnedDateRequested) returnedDateRequested.value = dateRequested;
            if (returnedEndorsedBy) returnedEndorsedBy.value = endorsedBy;
            if (returnedConcerns) returnedConcerns.value = concerns;
            if (returnedMotorpoolRemarks) returnedMotorpoolRemarks.textContent = returnRemarks || 'No remarks provided.';

            const modalBody = resubmitModal.querySelector('.modal-body');
            if (modalBody) modalBody.scrollTop = 0;
        });
    }
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

/* Motorpool Repair Payment: exact Write Checks behavior */
(function(){
    function getRepairPaymentForm(){
        return document.getElementById('repairPaymentForm');
    }
    function showRepairPaymentTab(tabName){
        const modal = document.getElementById('repairPaymentModal');
        if (!modal) return;
        modal.querySelectorAll('[data-repair-payment-tab]').forEach(function(btn){
            btn.classList.toggle('active', btn.getAttribute('data-repair-payment-tab') === tabName);
        });
        modal.querySelectorAll('[data-repair-payment-panel]').forEach(function(panel){
            panel.classList.toggle('active', panel.getAttribute('data-repair-payment-panel') === tabName);
        });
    }
    function clearRepairPaymentWriteCheckFields(){
        const amount = document.getElementById('paymentAmount');
        const checkNo = document.getElementById('paymentCheckNumber');
        const remarks = document.getElementById('paymentRemarks');
        const attachment = document.getElementById('paymentAttachment');
        const preview = document.getElementById('repairPaymentAttachmentPreview');
        if (amount) amount.value = '';
        if (checkNo) checkNo.value = '';
        if (remarks) remarks.value = '';
        if (attachment) attachment.value = '';
        if (preview) preview.innerHTML = '';
        if (typeof updateRepairWriteCheckMirrorsV3 === 'function') updateRepairWriteCheckMirrorsV3();
    }
    document.addEventListener('click', function(event){
        const tabBtn = event.target.closest('[data-repair-payment-tab]');
        if (tabBtn) {
            showRepairPaymentTab(tabBtn.getAttribute('data-repair-payment-tab') || 'expenses');
        }
        if (event.target.closest('#repairPaymentClearBtn')) {
            clearRepairPaymentWriteCheckFields();
        }
    });
    document.addEventListener('shown.bs.modal', function(event){
        if (event.target && event.target.id === 'repairPaymentModal') {
            showRepairPaymentTab('expenses');
        }
    });
})();

</script>
</body>
</html>
