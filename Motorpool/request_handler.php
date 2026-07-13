<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
// Branch information
$branch_id = (int) ($_SESSION['branch_id'] ?? 0);
$branch_name = trim((string) ($_SESSION['branch_name'] ?? ''));
$view_all_branches = (bool) ($_SESSION['view_all_branches'] ?? false);

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($user_role !== 'motorpool') {
    if ($user_role === 'branch_admin')
        header('Location: ../Branch_Admin/branchdashboard.php');
    elseif ($user_role === 'admin')
        header('Location: ../Admin/dashboard.php');
    else
        header('Location: ../login.php');
    exit;
}

$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Motorpool';
$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part)
    if ($part !== '')
        $user_initials .= strtoupper(substr($part, 0, 1));
if ($user_initials === '')
    $user_initials = 'MP';

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function jsonText($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
}
function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}
function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}
function safeDateOrNull(string $value): ?string
{
    return trim($value) === '' ? null : trim($value);
}


function cleanRisConcernText(string $concern): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $concern));
    if ($text === '')
        return '';

    // Backlog RIS rows should show only the actual reported issue in the table.
    // It removes labels like "Repair Backlog from RIS: RIS-20260604-0001" but keeps the real issue text.
    $text = preg_replace('/^\s*Repair\s+Backlog\s+from\s+RIS\s*:\s*RIS-\d{8}-\d{4}\s*-\s*/i', '', $text);
    $text = preg_replace('/^\s*Repair\s+Backlog\s+from\s+RIS\s*:\s*RIS-\d{8}-\d{4}\s*/i', '', $text);

    if (stripos($text, 'Damaged Again / Issue:') !== false) {
        $parts = preg_split('/Damaged\s+Again\s*\/\s*Issue\s*:/i', $text, 2);
        $text = trim((string) ($parts[1] ?? $text));
    }

    // If remarks were appended to the same concern block, keep them out of the Concerns column.
    $text = preg_split('/\n\s*Remarks\s*:/i', $text, 2)[0];
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

function uploadReceivedPhotos(string $field, string $uploadDir): array
{
    $saved = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name']))
        return $saved;
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0775, true);
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (empty($name) || !is_uploaded_file($_FILES[$field]['tmp_name'][$i]))
            continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true))
            continue;
        $timestamp = date('YmdHis');
        $filename = 'received_vehicle_' . $timestamp . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = rtrim($uploadDir, '/') . '/' . $filename;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], $target)) {
            $saved[] = ['filename' => 'received_vehicle/' . $filename, 'timestamp_text' => date('M d, Y h:i A'), 'uploaded_at' => date('Y-m-d H:i:s')];
        }
    }
    return $saved;
}

function uploadReceivedVehicleAnglePhotos(string $uploadDir): array
{
    $saved = [];
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0775, true);

    $requiredPhotos = [
        'received_photo_front' => 'Front',
        'received_photo_left' => 'Left-side',
        'received_photo_right' => 'Right-side',
        'received_photo_back' => 'Back'
    ];

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    foreach ($requiredPhotos as $field => $label) {
        if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
            throw new Exception($label . ' vehicle photo is required.');
        }

        $ext = strtolower(pathinfo((string) $_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new Exception($label . ' vehicle photo must be JPG, PNG, WEBP, or GIF.');
        }

        $timestamp = date('YmdHis');
        $filename = 'received_vehicle_' . strtolower(str_replace([' ', '-'], '_', $label)) . '_' . $timestamp . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = rtrim($uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
            throw new Exception('Failed to save ' . $label . ' vehicle photo.');
        }

        $saved[] = [
            'photo_type' => $label,
            'filename' => 'received_vehicle/' . $filename,
            'timestamp_text' => date('M d, Y h:i A'),
            'uploaded_at' => date('Y-m-d H:i:s')
        ];
    }

    return $saved;
}

function uploadStartRepairPhotos(string $field, string $uploadDir): array
{
    $saved = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name']))
        return $saved;
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0775, true);
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (empty($name) || !is_uploaded_file($_FILES[$field]['tmp_name'][$i]))
            continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true))
            continue;
        $timestamp = date('YmdHis');
        $filename = 'start_repair_' . $timestamp . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = rtrim($uploadDir, '/') . '/' . $filename;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], $target)) {
            $saved[] = [
                'filename' => $filename,
                'timestamp_text' => date('M d, Y h:i A'),
                'uploaded_at' => date('Y-m-d H:i:s')
            ];
        }
    }
    return $saved;
}

function uploadReleaseProof(string $field, string $uploadDir): string
{
    if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name']))
        return '';
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0775, true);
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    if (!in_array($ext, $allowed, true))
        return '';
    $filename = 'repair_release_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim($uploadDir, '/') . '/' . $filename;
    return move_uploaded_file($_FILES[$field]['tmp_name'], $target) ? $filename : '';
}


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
    `status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement',
    `workflow_status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement',
    `branch_approval_status` VARCHAR(30) DEFAULT 'Pending',
    `branch_approval_by` INT DEFAULT NULL,
    `branch_approval_at` DATETIME DEFAULT NULL,
    `branch_approval_remarks` TEXT DEFAULT NULL,
    `motorpool_return_remarks` TEXT DEFAULT NULL,
    `motorpool_returned_by` INT DEFAULT NULL,
    `motorpool_returned_at` DATETIME DEFAULT NULL,
    `findings` TEXT DEFAULT NULL,
    `action_taken` TEXT DEFAULT NULL,
    `repairs_done` TEXT DEFAULT NULL,
    `parts_replaced` TEXT DEFAULT NULL,
    `mechanic` VARCHAR(255) DEFAULT NULL,
    `repair_start_date` DATE DEFAULT NULL,
    `repair_end_date` DATE DEFAULT NULL,
    `repair_cost` DECIMAL(12,2) DEFAULT 0.00,
    `completed_by` INT DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_branch_id` (`branch_id`),
    KEY `idx_workflow_status` (`workflow_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (tableExists($conn, 'motorpool_ris_requests')) {
    if (!columnExists($conn, 'motorpool_ris_requests', 'workflow_status')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `workflow_status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement' AFTER `status`");
    }
    if (!columnExists($conn, 'motorpool_ris_requests', 'branch_approval_status')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_status` VARCHAR(30) DEFAULT 'Pending' AFTER `workflow_status`");
    }
    if (!columnExists($conn, 'motorpool_ris_requests', 'branch_approval_by')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_by` INT DEFAULT NULL AFTER `branch_approval_status`");
    }
    if (!columnExists($conn, 'motorpool_ris_requests', 'branch_approval_at')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_at` DATETIME DEFAULT NULL AFTER `branch_approval_by`");
    }
    if (!columnExists($conn, 'motorpool_ris_requests', 'branch_approval_remarks')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `branch_approval_remarks` TEXT DEFAULT NULL AFTER `branch_approval_at`");
    }
    if (!columnExists($conn, 'motorpool_ris_requests', 'motorpool_return_remarks')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `motorpool_return_remarks` TEXT DEFAULT NULL AFTER `branch_approval_remarks`");
    }
    if (!columnExists($conn, 'motorpool_ris_requests', 'motorpool_returned_by')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `motorpool_returned_by` INT DEFAULT NULL AFTER `motorpool_return_remarks`");
    }
    if (!columnExists($conn, 'motorpool_ris_requests', 'motorpool_returned_at')) {
        $conn->query("ALTER TABLE `motorpool_ris_requests` ADD COLUMN `motorpool_returned_at` DATETIME DEFAULT NULL AFTER `motorpool_returned_by`");
    }

    $conn->query("ALTER TABLE `motorpool_ris_requests` MODIFY `status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement'");
    $conn->query("UPDATE motorpool_ris_requests SET workflow_status = CASE
        WHEN workflow_status IS NOT NULL AND workflow_status <> '' THEN workflow_status
        WHEN status IN ('Pending','Ongoing','Completed','Rejected') THEN 'For Vehicle Endorsement'
        ELSE status END");
    $conn->query("UPDATE motorpool_ris_requests SET status = workflow_status WHERE workflow_status IS NOT NULL AND workflow_status <> ''");

    $conn->query("UPDATE motorpool_ris_requests
        SET workflow_status = 'For Parts Completion', status = 'For Parts Completion'
        WHERE workflow_status = 'For Approval'
          AND LOWER(COALESCE(branch_approval_status, '')) = 'approved'");
}

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_vehicle_receipts` (
    `receipt_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `vehicle_db_id` INT DEFAULT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `received_by_name` VARCHAR(255) NOT NULL,
    `received_date` DATE NOT NULL,
    `received_time` TIME NOT NULL,
    `received_datetime` DATETIME NOT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ris_receipt` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_vehicle_receipt_photos` (
    `photo_id` INT AUTO_INCREMENT PRIMARY KEY,
    `receipt_id` INT NOT NULL,
    `ris_id` INT NOT NULL,
    `photo_type` VARCHAR(50) DEFAULT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `timestamp_text` VARCHAR(100) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_receipt_id` (`receipt_id`), KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (tableExists($conn, 'motorpool_vehicle_receipt_photos') && !columnExists($conn, 'motorpool_vehicle_receipt_photos', 'photo_type')) {
    $conn->query("ALTER TABLE `motorpool_vehicle_receipt_photos` ADD COLUMN `photo_type` VARCHAR(50) DEFAULT NULL AFTER `ris_id`");
}


$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_start_repair_proofs` (
    `proof_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `vehicle_db_id` INT DEFAULT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `proof_photo` VARCHAR(255) NOT NULL,
    `timestamp_text` VARCHAR(100) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL,
    `started_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_repair_release_proofs` (
    `release_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `vehicle_db_id` INT DEFAULT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `release_attachment` VARCHAR(255) NOT NULL,
    `released_by` INT DEFAULT NULL,
    `released_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_release_ris` (`ris_id`),
    KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (tableExists($conn, 'motorpool_repair_release_proofs')) {
    if (!columnExists($conn, 'motorpool_repair_release_proofs', 'checked_received_by')) {
        $conn->query("ALTER TABLE `motorpool_repair_release_proofs` ADD COLUMN `checked_received_by` VARCHAR(255) DEFAULT NULL AFTER `release_attachment`");
    }
    if (!columnExists($conn, 'motorpool_repair_release_proofs', 'received_datetime')) {
        $conn->query("ALTER TABLE `motorpool_repair_release_proofs` ADD COLUMN `received_datetime` DATETIME DEFAULT NULL AFTER `checked_received_by`");
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_ris_assessments` (
    `assessment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `assessment_json` LONGTEXT NOT NULL,
    `repairs_summary` TEXT DEFAULT NULL,
    `parts_summary` TEXT DEFAULT NULL,
    `assessed_by` INT DEFAULT NULL,
    `assessed_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ris_assessment` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_ris_parts_completion` (
    `completion_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `parts_available_json` LONGTEXT DEFAULT NULL,
    `completion_summary` TEXT DEFAULT NULL,
    `confirmed_complete` TINYINT(1) NOT NULL DEFAULT 0,
    `saved_by` INT DEFAULT NULL,
    `saved_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ris_parts_completion` (`ris_id`),
    KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_ris_repair_progress` (
    `progress_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `repair_progress_json` LONGTEXT DEFAULT NULL,
    `progress_summary` TEXT DEFAULT NULL,
    `confirmed_complete` TINYINT(1) NOT NULL DEFAULT 0,
    `saved_by` INT DEFAULT NULL,
    `saved_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ris_repair_progress` (`ris_id`),
    KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_repair_start_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ris_id` INT NOT NULL,
    `ris_number` VARCHAR(50) DEFAULT NULL,
    `repair_hash` VARCHAR(64) NOT NULL,
    `repair_description` TEXT NOT NULL,
    `repair_type` VARCHAR(30) NOT NULL DEFAULT 'labor',
    `parts_used_json` LONGTEXT DEFAULT NULL,
    `start_datetime` DATETIME DEFAULT NULL,
    `end_datetime` DATETIME DEFAULT NULL,
    `mechanic` VARCHAR(255) DEFAULT NULL,
    `completion_mechanic` VARCHAR(255) DEFAULT NULL,
    `log_status` VARCHAR(30) NOT NULL DEFAULT 'ongoing',
    `saved_by` INT DEFAULT NULL,
    `saved_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ris_repair_hash` (`ris_id`, `repair_hash`),
    KEY `idx_ris_id` (`ris_id`),
    KEY `idx_log_status` (`log_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (tableExists($conn, 'motorpool_repair_start_logs')) {
    if (!columnExists($conn, 'motorpool_repair_start_logs', 'end_datetime')) {
        $conn->query("ALTER TABLE `motorpool_repair_start_logs` ADD COLUMN `end_datetime` DATETIME DEFAULT NULL AFTER `start_datetime`");
    }
    if (!columnExists($conn, 'motorpool_repair_start_logs', 'completion_mechanic')) {
        $conn->query("ALTER TABLE `motorpool_repair_start_logs` ADD COLUMN `completion_mechanic` VARCHAR(255) DEFAULT NULL AFTER `mechanic`");
    }
}


/* ===== MOTORPOOL INVENTORY CONNECTION =====
   This connects Request Handler parts usage to motorpool_inventory.php.
   Source table: motorpool_inventory_items
   Movement table: motorpool_inventory_transactions
   Behavior:
   - When a repair with parts is started, actual Qty Used is deducted from inventory.
   - Cost is pulled from motorpool_inventory_items.unit_cost.
   - If the same repair log is edited before being marked done, only the difference is adjusted.
   - If Qty Used is reduced, the difference is returned to inventory.
   - Saved parts JSON is enriched with unit_cost, total_cost, inventory_item_id, item_code, and item_name.
*/
function ensureMotorpoolInventoryTablesForRequestHandler(mysqli $conn): void
{
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
        KEY `idx_item_code` (`item_code`),
        KEY `idx_item_name` (`item_name`),
        KEY `idx_category` (`category`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_inventory_transactions` (
        `transaction_id` INT AUTO_INCREMENT PRIMARY KEY,
        `item_id` INT NOT NULL,
        `transaction_type` VARCHAR(40) NOT NULL,
        `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `remarks` TEXT DEFAULT NULL,
        `attachment` VARCHAR(255) DEFAULT NULL,
        `encoded_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_item_id` (`item_id`),
        KEY `idx_transaction_type` (`transaction_type`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $extraColumns = [
        'ris_id' => "`ris_id` INT DEFAULT NULL AFTER `attachment`",
        'ris_number' => "`ris_number` VARCHAR(80) DEFAULT NULL AFTER `ris_id`",
        'repair_hash' => "`repair_hash` VARCHAR(64) DEFAULT NULL AFTER `ris_number`",
        'repair_description' => "`repair_description` TEXT DEFAULT NULL AFTER `repair_hash`",
        'reference_type' => "`reference_type` VARCHAR(80) DEFAULT NULL AFTER `repair_description`",
        'reference_id' => "`reference_id` INT DEFAULT NULL AFTER `reference_type`"
    ];
    foreach ($extraColumns as $column => $definition) {
        if (!columnExists($conn, 'motorpool_inventory_transactions', $column)) {
            @$conn->query("ALTER TABLE `motorpool_inventory_transactions` ADD COLUMN $definition");
        }
    }
}

function motorpoolPartUsedQtyRequestHandler(array $part): float
{
    foreach (['used_quantity', 'qty_used', 'qty_to_use', 'quantity_to_use', 'quantity_used', 'quantity', 'qty'] as $key) {
        if (isset($part[$key]) && trim((string) $part[$key]) !== '')
            return (float) $part[$key];
    }
    return 0.0;
}

function motorpoolPartLookupTextRequestHandler(array $part): string
{
    foreach (['inventory_item_id', 'item_id'] as $key) {
        if (!empty($part[$key]))
            return (string) $part[$key];
    }
    foreach (['item_code', 'item_no', 'item_no_text', 'barcode', 'item', 'item_name', 'name', 'description'] as $key) {
        if (!empty($part[$key]))
            return trim((string) $part[$key]);
    }
    return '';
}

function motorpoolPartSourceIsBranchRequestHandler(array $part): bool
{
    $source = strtolower(trim((string) ($part['source_by'] ?? $part['parts_source_by'] ?? $part['source_type'] ?? $part['source'] ?? $part['purchased_by'] ?? 'motorpool')));
    $branchSources = ['branch', 'branch admin', 'branch_admin', 'branch source', 'branch_source', 'branch sourced', 'branch_sourced'];
    return in_array($source, $branchSources, true) || !empty($part['branch_sourced']) || !empty($part['branch_purchased']);
}

function motorpoolPartSkipInventoryDeductionRequestHandler(array $part): bool
{
    return motorpoolPartSourceIsBranchRequestHandler($part) || !empty($part['skip_inventory_deduction']) || !empty($part['rework_returned']);
}


function requestHandlerFirstExistingColumn(mysqli $conn, string $table, array $columns): string
{
    foreach ($columns as $column) {
        if (columnExists($conn, $table, $column))
            return $column;
    }
    return '';
}

function requestHandlerBindAndExecute(mysqli_stmt $stmt, string $types, array $values): void
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
}

function ensureRequestHandlerSalesOrderTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS `customers` (
        `customer_id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_name` VARCHAR(255) NOT NULL,
        `customer_code` VARCHAR(80) DEFAULT NULL,
        `email` VARCHAR(150) DEFAULT NULL,
        `phone_number` VARCHAR(80) DEFAULT NULL,
        `address` TEXT DEFAULT NULL,
        `status` VARCHAR(30) DEFAULT 'active',
        `branch_id` INT DEFAULT NULL,
        `created_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_customer_name` (`customer_name`),
        KEY `idx_customer_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `sales_orders` (
        `so_id` INT AUTO_INCREMENT PRIMARY KEY,
        `so_number` VARCHAR(80) UNIQUE NOT NULL,
        `customer_id` INT NOT NULL DEFAULT 0,
        `branch_id` INT DEFAULT NULL,
        `order_date` DATETIME NOT NULL,
        `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `order_status` VARCHAR(40) NOT NULL DEFAULT 'pending',
        `payment_status` VARCHAR(40) NOT NULL DEFAULT 'unpaid',
        `created_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_so_created_by` (`created_by`),
        KEY `idx_so_branch` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `sales_order_items` (
        `so_item_id` INT AUTO_INCREMENT PRIMARY KEY,
        `so_id` INT NOT NULL,
        `item_id` INT NOT NULL,
        `unit_type` VARCHAR(80) DEFAULT 'Piece',
        `quantity_ordered` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `quantity_delivered` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        KEY `idx_so_id` (`so_id`),
        KEY `idx_so_item` (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $salesOrderColumns = [
        'order_amount' => "`order_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`",
        'fulfillment_type' => "`fulfillment_type` VARCHAR(40) NOT NULL DEFAULT 'pickup' AFTER `order_status`",
        'payment_status' => "`payment_status` VARCHAR(40) NOT NULL DEFAULT 'unpaid' AFTER `fulfillment_type`",
        'document_type' => "`document_type` VARCHAR(20) NOT NULL DEFAULT 'SO' AFTER `so_number`",
        'billing_type' => "`billing_type` VARCHAR(20) NOT NULL DEFAULT 'invoice' AFTER `document_type`",
        'source_module' => "`source_module` VARCHAR(80) DEFAULT NULL AFTER `payment_status`",
        'source_ris_id' => "`source_ris_id` INT DEFAULT NULL AFTER `source_module`",
        'source_ris_number' => "`source_ris_number` VARCHAR(80) DEFAULT NULL AFTER `source_ris_id`",
        'remarks' => "`remarks` TEXT DEFAULT NULL AFTER `source_ris_number`",
        'updated_at' => "`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
    ];
    foreach ($salesOrderColumns as $column => $definition) {
        if (!columnExists($conn, 'sales_orders', $column)) {
            @$conn->query("ALTER TABLE `sales_orders` ADD COLUMN $definition");
        }
    }

    $salesOrderItemColumns = [
        'quantity_delivered' => "`quantity_delivered` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `quantity_ordered`",
        'line_total' => "`line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`",
        'gross_price' => "`gross_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `line_total`",
        'net_price' => "`net_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `gross_price`",
        'order_amount' => "`order_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `net_price`",
        'ave_cost' => "`ave_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `order_amount`",
        'cogs_amount' => "`cogs_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `ave_cost`",
        'gross_profit' => "`gross_profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `cogs_amount`",
        'source_module' => "`source_module` VARCHAR(80) DEFAULT NULL AFTER `gross_profit`",
        'source_ris_id' => "`source_ris_id` INT DEFAULT NULL AFTER `source_module`",
        'source_repair_hash' => "`source_repair_hash` VARCHAR(64) DEFAULT NULL AFTER `source_ris_id`"
    ];
    foreach ($salesOrderItemColumns as $column => $definition) {
        if (!columnExists($conn, 'sales_order_items', $column)) {
            @$conn->query("ALTER TABLE `sales_order_items` ADD COLUMN $definition");
        }
    }
}

function requestHandlerGetOrCreateRisCustomer(mysqli $conn, array $ris, int $userId): int
{
    ensureRequestHandlerSalesOrderTables($conn);
    $risId = (int) ($ris['ris_id'] ?? 0);
    $branchId = (int) ($ris['branch_id'] ?? 0);
    $plate = trim((string) ($ris['plate_no'] ?? ''));
    $customerCode = 'RIS-CUST-' . $risId;
    $customerName = 'Motorpool RIS' . ($plate !== '' ? ' - ' . $plate : '');

    $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE customer_code = ? AND created_by = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('si', $customerCode, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row)
            return (int) $row['customer_id'];
    }

    $fields = ['customer_name', 'customer_code', 'email', 'phone_number', 'address', 'status', 'created_by'];
    $placeholders = ['?', '?', "''", "''", '?', "'active'", '?'];
    $types = 'sssi';
    $address = trim((string) ($ris['vehicle_details'] ?? ''));
    $values = [$customerName, $customerCode, $address, $userId];
    if (columnExists($conn, 'customers', 'branch_id')) {
        $fields[] = 'branch_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $branchId;
    }
    $sql = "INSERT INTO customers (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $insert = $conn->prepare($sql);
    if (!$insert)
        throw new Exception('Failed to prepare RIS customer insert: ' . $conn->error);
    requestHandlerBindAndExecute($insert, $types, $values);
    $customerId = (int) $conn->insert_id;
    $insert->close();
    return $customerId;
}

function requestHandlerAggregateRisSalesOrderParts(mysqli $conn, array $mergedRepairProgress): array
{
    $map = [];
    foreach ($mergedRepairProgress as $repairRow) {
        if (!is_array($repairRow))
            continue;
        if (empty($repairRow['checked']))
            continue;
        if ((string) ($repairRow['repair_type'] ?? 'labor') !== 'with_parts')
            continue;
        if (!empty($repairRow['rework_returned']) || !empty($repairRow['skip_inventory_deduction']))
            continue;

        $repairDesc = trim((string) ($repairRow['repair'] ?? ''));
        if ($repairDesc === '')
            continue;
        $repairHash = hash('sha256', mb_strtolower($repairDesc));

        $parts = is_array($repairRow['parts_used'] ?? null) ? $repairRow['parts_used'] : [];
        foreach ($parts as $part) {
            if (!is_array($part))
                continue;
            if (motorpoolPartSkipInventoryDeductionRequestHandler($part))
                continue;
            $qty = motorpoolPartUsedQtyRequestHandler($part);
            if ($qty <= 0)
                continue;
            $itemId = (int) ($part['inventory_item_id'] ?? $part['item_id'] ?? 0);
            if ($itemId <= 0) {
                $found = findMotorpoolInventoryItemForRequestHandler($conn, $part);
                $itemId = $found ? (int) $found['item_id'] : 0;
            }
            if ($itemId <= 0)
                continue;
            $key = $itemId . '|' . $repairHash;
            if (!isset($map[$key])) {
                $unitCost = isset($part['unit_cost']) && is_numeric($part['unit_cost']) ? (float) $part['unit_cost'] : 0.0;
                $map[$key] = [
                    'item_id' => $itemId,
                    'qty' => 0.0,
                    'unit_type' => trim((string) ($part['unit_type'] ?? 'Piece')) ?: 'Piece',
                    'unit_price' => $unitCost,
                    'repair_hash' => $repairHash,
                    'repair_description' => $repairDesc
                ];
            }
            $map[$key]['qty'] += $qty;
        }
    }
    return array_values($map);
}

function syncRisPartsToSalesOrderForRequestHandler(mysqli $conn, array $ris, array $mergedRepairProgress, int $userId): void
{
    ensureRequestHandlerSalesOrderTables($conn);
    $risId = (int) ($ris['ris_id'] ?? 0);
    $risNumber = trim((string) ($ris['ris_number'] ?? ''));
    if ($risId <= 0 || $risNumber === '')
        return;

    $parts = requestHandlerAggregateRisSalesOrderParts($conn, $mergedRepairProgress);
    $soNumber = 'RIS-SO-' . preg_replace('/[^A-Za-z0-9\-]/', '', $risNumber);
    $branchId = (int) ($ris['branch_id'] ?? 0);
    $customerId = requestHandlerGetOrCreateRisCustomer($conn, $ris, $userId);
    $totalAmount = 0.0;
    foreach ($parts as $p)
        $totalAmount += ((float) $p['qty'] * (float) $p['unit_price']);
    $totalAmount = round($totalAmount, 2);

    $soId = 0;
    if (columnExists($conn, 'sales_orders', 'source_ris_id')) {
        $stmt = $conn->prepare("SELECT so_id FROM sales_orders WHERE source_module = 'motorpool_ris' AND source_ris_id = ? LIMIT 1 FOR UPDATE");
        if ($stmt) {
            $stmt->bind_param('i', $risId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row)
                $soId = (int) $row['so_id'];
        }
    }
    if ($soId <= 0) {
        $stmt = $conn->prepare("SELECT so_id FROM sales_orders WHERE so_number = ? LIMIT 1 FOR UPDATE");
        if ($stmt) {
            $stmt->bind_param('s', $soNumber);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row)
                $soId = (int) $row['so_id'];
        }
    }

    if (empty($parts)) {
        if ($soId > 0) {
            $delItems = $conn->prepare("DELETE FROM sales_order_items WHERE so_id = ? AND COALESCE(source_module,'') = 'motorpool_ris'");
            if ($delItems) {
                $delItems->bind_param('i', $soId);
                $delItems->execute();
                $delItems->close();
            }
            $cancel = $conn->prepare("UPDATE sales_orders SET total_amount = 0, order_amount = IFNULL(order_amount,0) * 0, order_status = 'cancelled', payment_status = 'unpaid', updated_at = NOW() WHERE so_id = ?");
            if ($cancel) {
                $cancel->bind_param('i', $soId);
                $cancel->execute();
                $cancel->close();
            }
        }
        return;
    }

    if ($soId <= 0) {
        $fields = ['so_number', 'customer_id', 'branch_id', 'order_date', 'total_amount', 'order_status', 'created_by'];
        $placeholders = ['?', '?', '?', 'NOW()', '?', "'pending'", '?'];
        $types = 'siidi';
        $values = [$soNumber, $customerId, $branchId, $totalAmount, $userId];
        if (columnExists($conn, 'sales_orders', 'order_amount')) {
            $fields[] = 'order_amount';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = $totalAmount;
        }
        if (columnExists($conn, 'sales_orders', 'fulfillment_type')) {
            $fields[] = 'fulfillment_type';
            $placeholders[] = "'pickup'";
        }
        if (columnExists($conn, 'sales_orders', 'payment_status')) {
            $fields[] = 'payment_status';
            $placeholders[] = "'unpaid'";
        }
        if (columnExists($conn, 'sales_orders', 'document_type')) {
            $fields[] = 'document_type';
            $placeholders[] = "'SO'";
        }
        if (columnExists($conn, 'sales_orders', 'billing_type')) {
            $fields[] = 'billing_type';
            $placeholders[] = "'invoice'";
        }
        if (columnExists($conn, 'sales_orders', 'source_module')) {
            $fields[] = 'source_module';
            $placeholders[] = "'motorpool_ris'";
        }
        if (columnExists($conn, 'sales_orders', 'source_ris_id')) {
            $fields[] = 'source_ris_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $risId;
        }
        if (columnExists($conn, 'sales_orders', 'source_ris_number')) {
            $fields[] = 'source_ris_number';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $risNumber;
        }
        if (columnExists($conn, 'sales_orders', 'remarks')) {
            $fields[] = 'remarks';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = 'Auto-created from Motorpool RIS parts usage.';
        }
        $sql = "INSERT INTO sales_orders (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $insert = $conn->prepare($sql);
        if (!$insert)
            throw new Exception('Failed to prepare RIS sales order insert: ' . $conn->error);
        requestHandlerBindAndExecute($insert, $types, $values);
        $soId = (int) $conn->insert_id;
        $insert->close();
    } else {
        $sets = ['customer_id = ?', 'branch_id = ?', 'total_amount = ?', 'order_status = IF(LOWER(COALESCE(order_status,\'pending\')) = \'cancelled\', \'pending\', order_status)', 'updated_at = NOW()'];
        $types = 'iid';
        $values = [$customerId, $branchId, $totalAmount];
        if (columnExists($conn, 'sales_orders', 'order_amount')) {
            $sets[] = 'order_amount = ?';
            $types .= 'd';
            $values[] = $totalAmount;
        }
        if (columnExists($conn, 'sales_orders', 'source_module')) {
            $sets[] = "source_module = 'motorpool_ris'";
        }
        if (columnExists($conn, 'sales_orders', 'source_ris_id')) {
            $sets[] = 'source_ris_id = ?';
            $types .= 'i';
            $values[] = $risId;
        }
        if (columnExists($conn, 'sales_orders', 'source_ris_number')) {
            $sets[] = 'source_ris_number = ?';
            $types .= 's';
            $values[] = $risNumber;
        }
        $types .= 'i';
        $values[] = $soId;
        $upd = $conn->prepare('UPDATE sales_orders SET ' . implode(', ', $sets) . ' WHERE so_id = ?');
        if (!$upd)
            throw new Exception('Failed to prepare RIS sales order update: ' . $conn->error);
        requestHandlerBindAndExecute($upd, $types, $values);
        $upd->close();
    }

    $del = $conn->prepare("DELETE FROM sales_order_items WHERE so_id = ? AND COALESCE(source_module,'') = 'motorpool_ris'");
    if ($del) {
        $del->bind_param('i', $soId);
        $del->execute();
        $del->close();
    }

    foreach ($parts as $part) {
        $itemId = (int) $part['item_id'];
        $qty = round((float) $part['qty'], 2);
        $unitType = trim((string) $part['unit_type']) ?: 'Piece';
        $unitPrice = round((float) $part['unit_price'], 2);
        $lineTotal = round($qty * $unitPrice, 2);

        $fields = ['so_id', 'item_id', 'unit_type', 'quantity_ordered', 'unit_price'];
        $placeholders = ['?', '?', '?', '?', '?'];
        $types = 'iisdd';
        $values = [$soId, $itemId, $unitType, $qty, $unitPrice];
        $optional = [
            'quantity_delivered' => ['?', 'd', 0.0],
            'line_total' => ['?', 'd', $lineTotal],
            'gross_price' => ['?', 'd', $unitPrice],
            'net_price' => ['?', 'd', $unitPrice],
            'order_amount' => ['?', 'd', $lineTotal],
            'ave_cost' => ['?', 'd', $unitPrice],
            'cogs_amount' => ['?', 'd', $lineTotal],
            'gross_profit' => ['?', 'd', 0.0],
            'source_module' => ["'motorpool_ris'", '', null],
            'source_ris_id' => ['?', 'i', $risId],
            'source_repair_hash' => ['?', 's', (string) $part['repair_hash']]
        ];
        foreach ($optional as $column => $cfg) {
            if (!columnExists($conn, 'sales_order_items', $column))
                continue;
            $fields[] = $column;
            $placeholders[] = $cfg[0];
            if ($cfg[1] !== '') {
                $types .= $cfg[1];
                $values[] = $cfg[2];
            }
        }
        $sql = "INSERT INTO sales_order_items (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $ins = $conn->prepare($sql);
        if (!$ins)
            throw new Exception('Failed to prepare RIS sales order item insert: ' . $conn->error);
        requestHandlerBindAndExecute($ins, $types, $values);
        $ins->close();
    }
}

function findMotorpoolInventoryItemForRequestHandler(mysqli $conn, array $part): ?array
{
    ensureMotorpoolInventoryTablesForRequestHandler($conn);

    foreach (['inventory_item_id', 'item_id'] as $key) {
        $id = (int) ($part[$key] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT * FROM motorpool_inventory_items WHERE item_id = ? AND COALESCE(status,'active') <> 'deleted' LIMIT 1 FOR UPDATE");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row)
                    return $row;
            }
        }
    }

    $candidates = [];
    foreach (['item_code', 'item_no', 'item_no_text', 'barcode', 'item', 'item_name', 'name', 'description'] as $key) {
        $value = trim((string) ($part[$key] ?? ''));
        if ($value !== '')
            $candidates[$value] = $value;
    }
    foreach ($candidates as $value) {
        $stmt = $conn->prepare("SELECT * FROM motorpool_inventory_items
            WHERE COALESCE(status,'active') <> 'deleted'
              AND (item_code = ? OR barcode = ? OR item_name = ?)
            LIMIT 1 FOR UPDATE");
        if ($stmt) {
            $stmt->bind_param('sss', $value, $value, $value);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row)
                return $row;
        }
    }
    return null;
}

function motorpoolAggregatePartsByInventoryItem(mysqli $conn, array $parts): array
{
    $map = [];
    foreach ($parts as $part) {
        if (!is_array($part))
            continue;
        $qty = motorpoolPartUsedQtyRequestHandler($part);
        if ($qty <= 0)
            continue;

        // Manual typed parts are allowed. Only Motorpool Source parts affect Motorpool Inventory.
        // Branch Source parts and Quality Check rework rows are shown in the repair log,
        // but they must not deduct from Motorpool inventory again.
        if (motorpoolPartSkipInventoryDeductionRequestHandler($part))
            continue;

        $item = findMotorpoolInventoryItemForRequestHandler($conn, $part);
        if (!$item)
            continue;

        $itemId = (int) $item['item_id'];
        if (!isset($map[$itemId])) {
            $map[$itemId] = ['item' => $item, 'qty' => 0.0];
        }
        $map[$itemId]['qty'] += $qty;
    }
    return $map;
}

function motorpoolApplyInventoryDeltaForRepairParts(mysqli $conn, int $risId, string $risNumber, string $repairHash, string $repairDescription, array $newParts, array $oldParts, int $userId): array
{
    ensureMotorpoolInventoryTablesForRequestHandler($conn);

    $oldMap = motorpoolAggregatePartsByInventoryItem($conn, $oldParts);
    $newMap = motorpoolAggregatePartsByInventoryItem($conn, $newParts);
    $itemIds = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));

    $costByItem = [];
    foreach ($itemIds as $itemId) {
        $newQty = (float) ($newMap[$itemId]['qty'] ?? 0);
        $oldQty = (float) ($oldMap[$itemId]['qty'] ?? 0);
        $delta = $newQty - $oldQty;

        $stmt = $conn->prepare("SELECT * FROM motorpool_inventory_items WHERE item_id = ? AND COALESCE(status,'active') <> 'deleted' LIMIT 1 FOR UPDATE");
        if (!$stmt)
            throw new Exception('Failed to prepare inventory check: ' . $conn->error);
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item)
            throw new Exception('Motorpool inventory item was not found.');

        $currentStock = (float) ($item['current_stock'] ?? 0);
        $unitCost = (float) ($item['unit_cost'] ?? 0);
        $currentTotalCost = (float) ($item['total_cost'] ?? 0);
        if ($currentTotalCost <= 0 && $currentStock > 0)
            $currentTotalCost = $currentStock * $unitCost;

        if ($delta > 0 && $delta > $currentStock) {
            throw new Exception('Not enough stock for ' . ($item['item_name'] ?? $item['item_code']) . '. Available: ' . rtrim(rtrim(number_format($currentStock, 2, '.', ''), '0'), '.') . ', Qty to use: ' . rtrim(rtrim(number_format($delta, 2, '.', ''), '0'), '.'));
        }

        if (abs($delta) > 0.000001) {
            $newStock = $currentStock - $delta;
            $costDelta = $delta * $unitCost;
            $newTotalCost = $currentTotalCost - $costDelta;
            if ($newStock < 0)
                $newStock = 0;
            if ($newTotalCost < 0)
                $newTotalCost = 0;
            $newUnitCost = $newStock > 0 ? ($newTotalCost / $newStock) : $unitCost;

            $update = $conn->prepare("UPDATE motorpool_inventory_items SET current_stock = ?, unit_cost = ?, total_cost = ?, updated_at = NOW() WHERE item_id = ?");
            if (!$update)
                throw new Exception('Failed to prepare inventory update: ' . $conn->error);
            $update->bind_param('dddi', $newStock, $newUnitCost, $newTotalCost, $itemId);
            if (!$update->execute())
                throw new Exception('Failed to update motorpool inventory: ' . $update->error);
            $update->close();


            // Keep the unit inventory table used by orderproduct.php/current inventory in sync too.
            if (tableExists($conn, 'motorpool_item_unit_inventory')) {
                $unitTypeForSync = trim((string) ($item['unit_type'] ?? '')) ?: 'Piece';
                $unitInventoryId = 0;
                $unitInventoryStock = 0.0;
                $unitStmt = $conn->prepare("SELECT inventory_id, COALESCE(current_inventory,0) AS current_inventory FROM motorpool_item_unit_inventory WHERE item_id = ? AND LOWER(COALESCE(NULLIF(TRIM(unit_type),''),'piece')) = LOWER(?) LIMIT 1 FOR UPDATE");
                if ($unitStmt) {
                    $unitStmt->bind_param('is', $itemId, $unitTypeForSync);
                    $unitStmt->execute();
                    $unitRow = $unitStmt->get_result()->fetch_assoc();
                    $unitStmt->close();
                    if ($unitRow) {
                        $unitInventoryId = (int) $unitRow['inventory_id'];
                        $unitInventoryStock = (float) $unitRow['current_inventory'];
                    }
                }
                if ($unitInventoryId > 0) {
                    $newUnitInventoryStock = $unitInventoryStock - $delta;
                    $unitUpd = $conn->prepare("UPDATE motorpool_item_unit_inventory SET current_inventory = ?, unit_cost = ?, total_cost = GREATEST(0, ? * ?), updated_at = NOW() WHERE inventory_id = ?");
                    if ($unitUpd) {
                        $unitUpd->bind_param('ddddi', $newUnitInventoryStock, $newUnitCost, $newUnitInventoryStock, $newUnitCost, $unitInventoryId);
                        $unitUpd->execute();
                        $unitUpd->close();
                    }
                }
            }

            $transactionType = $delta > 0 ? 'Repair Parts Used' : 'Repair Parts Returned';
            $transactionQty = $delta > 0 ? -abs($delta) : abs($delta);
            $transactionTotal = $transactionQty * $unitCost;
            $remarks = 'Auto inventory ' . ($delta > 0 ? 'deduction' : 'return') . ' from RIS ' . $risNumber . ' - ' . $repairDescription;
            $referenceType = 'motorpool_repair';

            $trans = $conn->prepare("INSERT INTO motorpool_inventory_transactions
                (item_id, transaction_type, quantity, unit_cost, total_cost, remarks, attachment, ris_id, ris_number, repair_hash, repair_description, reference_type, reference_id, encoded_by)
                VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?)");
            if (!$trans)
                throw new Exception('Failed to prepare inventory transaction: ' . $conn->error);
            $trans->bind_param('isdddsissssii', $itemId, $transactionType, $transactionQty, $unitCost, $transactionTotal, $remarks, $risId, $risNumber, $repairHash, $repairDescription, $referenceType, $risId, $userId);
            if (!$trans->execute())
                throw new Exception('Failed to save inventory transaction: ' . $trans->error);
            $trans->close();
        }

        $costByItem[$itemId] = [
            'unit_cost' => $unitCost,
            'item_code' => (string) ($item['item_code'] ?? ''),
            'item_name' => (string) ($item['item_name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'unit_type' => (string) ($item['unit_type'] ?? '')
        ];
    }

    $enriched = [];
    foreach ($newParts as $part) {
        if (!is_array($part))
            continue;
        $qty = motorpoolPartUsedQtyRequestHandler($part);
        if ($qty <= 0) {
            $enriched[] = $part;
            continue;
        }
        $item = findMotorpoolInventoryItemForRequestHandler($conn, $part);
        if ($item) {
            $itemId = (int) $item['item_id'];
            $unitCost = (float) ($costByItem[$itemId]['unit_cost'] ?? $item['unit_cost'] ?? 0);
            $part['inventory_item_id'] = $itemId;
            $part['item_code'] = (string) ($item['item_code'] ?? '');
            $part['item_no'] = (string) ($item['item_code'] ?? ($part['item_no'] ?? ''));
            $part['item_name'] = (string) ($item['item_name'] ?? '');
            if (empty($part['description']))
                $part['description'] = (string) ($item['description'] ?? '');
            if (empty($part['unit_type']))
                $part['unit_type'] = (string) ($item['unit_type'] ?? '');
            $part['unit_cost'] = $unitCost;
            $part['total_cost'] = $qty * $unitCost;
        } else {
            // Manual part. Keep the typed item text and typed cost for history/release.
            $manualUnitCost = isset($part['unit_cost']) && is_numeric($part['unit_cost']) ? (float) $part['unit_cost'] : 0.0;
            $part['unit_cost'] = $manualUnitCost;
            $part['total_cost'] = $qty * $manualUnitCost;
        }
        $enriched[] = $part;
    }
    return $enriched;
}


function fetchMotorpoolInventoryOptionsForRequestHandler(mysqli $conn): array
{
    ensureMotorpoolInventoryTablesForRequestHandler($conn);
    $rows = [];
    $sql = "SELECT item_id, item_code, item_name, description, category, unit_type, barcode, current_stock, unit_cost, total_cost
            FROM motorpool_inventory_items
            WHERE COALESCE(status, 'active') = 'active'
              AND COALESCE(status, 'active') <> 'deleted'
            ORDER BY item_name ASC, item_code ASC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'unit_type' => (string) ($row['unit_type'] ?? ''),
                'barcode' => (string) ($row['barcode'] ?? ''),
                'current_stock' => (float) ($row['current_stock'] ?? 0),
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                'total_cost' => (float) ($row['total_cost'] ?? 0),
                'label' => trim((string) ($row['item_code'] ?? '') . ' - ' . (string) ($row['item_name'] ?? ''))
            ];
        }
    }
    return $rows;
}


function findMotorpoolInventoryItemForAssessmentCost(mysqli $conn, array $part): ?array
{
    ensureMotorpoolInventoryTablesForRequestHandler($conn);

    $inventoryId = (int) ($part['inventory_item_id'] ?? 0);
    if ($inventoryId > 0) {
        $stmt = $conn->prepare("SELECT item_id, item_code, item_name, description, category, unit_type, barcode, current_stock, unit_cost, total_cost FROM motorpool_inventory_items WHERE item_id = ? AND COALESCE(status,'active') <> 'deleted' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $inventoryId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row)
                return $row;
        }
    }

    $candidates = [];
    foreach (['item_code', 'item_no', 'item_label', 'item_name', 'barcode', 'description'] as $key) {
        $value = trim((string) ($part[$key] ?? ''));
        if ($value !== '')
            $candidates[$value] = $value;
    }

    foreach ($candidates as $value) {
        $stmt = $conn->prepare("SELECT item_id, item_code, item_name, description, category, unit_type, barcode, current_stock, unit_cost, total_cost FROM motorpool_inventory_items WHERE COALESCE(status,'active') <> 'deleted' AND (item_code = ? OR barcode = ? OR item_name = ? OR CONCAT(item_code, ' - ', item_name) = ?) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssss', $value, $value, $value, $value);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row)
                return $row;
        }
    }
    return null;
}

function enrichAssessmentPartsWithInventoryCost(mysqli $conn, array $assessment): array
{
    foreach ($assessment as &$repair) {
        if (!is_array($repair))
            continue;
        if (empty($repair['parts']) || !is_array($repair['parts'])) {
            $repair['parts'] = [];
            continue;
        }
        foreach ($repair['parts'] as &$part) {
            if (!is_array($part))
                continue;
            $inventory = findMotorpoolInventoryItemForAssessmentCost($conn, $part);
            $quantity = (float) ($part['quantity'] ?? ($part['qty'] ?? 0));
            $manualCost = isset($part['unit_cost']) && is_numeric($part['unit_cost']) ? (float) $part['unit_cost'] : 0.0;

            if ($inventory) {
                // Auto-fill from Motorpool Inventory, but keep the cost editable.
                // When the user changes the Unit Cost in the assessment modal, use that
                // typed value instead of forcing the inventory cost again on save.
                $inventoryUnitCost = (float) ($inventory['unit_cost'] ?? 0);
                $unitCost = $manualCost > 0 ? $manualCost : $inventoryUnitCost;
                $part['inventory_item_id'] = (int) ($inventory['item_id'] ?? 0);
                $part['item_code'] = (string) ($inventory['item_code'] ?? '');
                $part['item_no'] = (string) ($inventory['item_code'] ?? ($part['item_no'] ?? ''));
                $part['item_name'] = (string) ($inventory['item_name'] ?? '');
                $part['item_label'] = trim((string) ($inventory['item_code'] ?? '') . ' - ' . (string) ($inventory['item_name'] ?? ''));
                if (trim((string) ($part['description'] ?? '')) === '')
                    $part['description'] = (string) ($inventory['description'] ?? '');
                $part['unit_type'] = (string) ($inventory['unit_type'] ?? '');
                $part['available_quantity'] = (float) ($inventory['current_stock'] ?? 0);
                $part['unit_cost'] = $unitCost;
                $part['cost_source'] = $manualCost > 0 && abs($manualCost - $inventoryUnitCost) > 0.000001 ? 'Manual Override' : 'Motorpool Inventory';
            } else {
                $unitCost = $manualCost;
                $part['inventory_item_id'] = '';
                $part['item_code'] = (string) ($part['item_no'] ?? '');
                $part['item_name'] = (string) ($part['item_name'] ?? ($part['item_no'] ?? ''));
                $part['item_label'] = (string) ($part['item_label'] ?? ($part['item_no'] ?? ''));
                $part['available_quantity'] = (string) ($part['available_quantity'] ?? '');
                $part['unit_cost'] = $unitCost;
                $part['cost_source'] = 'Manual';
            }
            $part['estimated_total_cost'] = $quantity * $unitCost;
            $part['total_cost'] = $quantity * $unitCost;
        }
        unset($part);

        // Compute full cost per repair:
        // repair_total_cost = Repair Cost / Labor Cost + estimated cost of all item/part rows.
        $repairCostRaw = trim((string) ($repair['repair_cost'] ?? ($repair['labor_cost'] ?? ($repair['service_cost'] ?? '0'))));
        $repairCost = is_numeric($repairCostRaw) ? (float) $repairCostRaw : 0.0;
        $partsEstimatedCost = 0.0;
        foreach (($repair['parts'] ?? []) as $costPart) {
            if (!is_array($costPart))
                continue;
            if (isset($costPart['estimated_total_cost']) && is_numeric($costPart['estimated_total_cost'])) {
                $partsEstimatedCost += (float) $costPart['estimated_total_cost'];
            } elseif (isset($costPart['total_cost']) && is_numeric($costPart['total_cost'])) {
                $partsEstimatedCost += (float) $costPart['total_cost'];
            } else {
                $qty = isset($costPart['quantity']) && is_numeric($costPart['quantity']) ? (float) $costPart['quantity'] : (isset($costPart['qty']) && is_numeric($costPart['qty']) ? (float) $costPart['qty'] : 0.0);
                $unit = isset($costPart['unit_cost']) && is_numeric($costPart['unit_cost']) ? (float) $costPart['unit_cost'] : 0.0;
                $partsEstimatedCost += $qty * $unit;
            }
        }
        $repair['repair_cost'] = $repairCost;
        $repair['labor_cost'] = $repairCost;
        $repair['parts_estimated_cost'] = $partsEstimatedCost;
        $repair['repair_total_cost'] = $repairCost + $partsEstimatedCost;
        $repair['total_cost'] = $repairCost + $partsEstimatedCost;
    }
    unset($repair);
    return $assessment;
}


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

// Sync older quality-checked RIS rows so they always appear under For Release.
if (tableExists($conn, 'motorpool_ris_quality_checks')) {
    $conn->query("UPDATE motorpool_ris_requests r
        INNER JOIN motorpool_ris_quality_checks qc ON qc.ris_id = r.ris_id
        SET r.workflow_status = 'For Release',
            r.status = 'For Release',
            r.action_taken = 'Quality check completed. Ready for release.'
        WHERE r.completed_at IS NULL
          AND LOWER(TRIM(COALESCE(r.workflow_status, r.status, ''))) IN ('for quality check', 'quality check', 'for release')");
}



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
    KEY `idx_vehicle_db_id` (`vehicle_db_id`), KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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


$save_status = '';
$save_message = '';



function parseAssessmentPartsNeeded(mysqli $conn, int $ris_id): array
{
    $stmt = $conn->prepare("SELECT assessment_json FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
    if (!$stmt)
        return [];
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $assessment = json_decode((string) ($row['assessment_json'] ?? '[]'), true);
    if (!is_array($assessment))
        return [];

    $needed = [];
    foreach ($assessment as $repairItem) {
        $parts = $repairItem['parts'] ?? [];
        if (!is_array($parts))
            continue;
        foreach ($parts as $part) {
            $itemNo = trim((string) ($part['item_no'] ?? ''));
            $description = trim((string) ($part['description'] ?? ($part['name'] ?? '')));
            $specification = trim((string) ($part['specification'] ?? ''));
            $qtyRaw = trim((string) ($part['quantity'] ?? ($part['qty'] ?? '0')));
            if ($itemNo === '' && $description === '' && $specification === '' && $qtyRaw === '')
                continue;
            $sourceBy = strtolower(trim((string) ($part['source_by'] ?? $part['parts_source_by'] ?? $part['source_type'] ?? $part['source'] ?? $part['purchased_by'] ?? 'motorpool')));
            $isBranchSource = in_array($sourceBy, ['branch', 'branch admin', 'branch_admin', 'branch sourced', 'branch_source'], true) || !empty($part['branch_sourced']) || !empty($part['branch_purchased']);
            $unitCost = $isBranchSource ? 0.00 : (float) str_replace(',', '', (string) ($part['unit_cost'] ?? $part['cost'] ?? $part['price'] ?? 0));
            $qtyValue = is_numeric($qtyRaw) ? (float) $qtyRaw : 0;
            $motorpoolCost = $isBranchSource ? 0.00 : (float) ($part['motorpool_billable_cost'] ?? $part['estimated_total_cost'] ?? $part['estimated_cost'] ?? $part['total_cost'] ?? ($qtyValue * $unitCost));
            $needed[] = [
                'item_no' => $itemNo,
                'description' => $description,
                'specification' => $specification,
                'needed_quantity' => $qtyValue,
                'needed_quantity_text' => $qtyRaw,
                'source_by' => $isBranchSource ? 'branch' : 'motorpool',
                'source_label' => $isBranchSource ? 'Branch Source' : 'Motorpool Source',
                'source_status' => (string) ($part['source_status'] ?? ($isBranchSource ? 'pending_source' : '')),
                'branch_sourced' => $isBranchSource ? 1 : 0,
                'unit_cost' => $unitCost,
                'estimated_total_cost' => $motorpoolCost,
                'motorpool_billable_cost' => $motorpoolCost,
                'cost_source' => (string) ($part['cost_source'] ?? '')
            ];
        }
    }
    return $needed;
}

function normalizePartsCompletionRows(mysqli $conn, int $ris_id, array $submittedRows, array $previousRows = []): array
{
    $neededRows = parseAssessmentPartsNeeded($conn, $ris_id);
    $normalized = [];

    foreach ($neededRows as $index => $needed) {
        $submitted = $submittedRows[$index] ?? [];
        $previous = $previousRows[$index] ?? [];
        $wasComplete = !empty($previous['item_completed']);
        $neededQty = (float) ($needed['needed_quantity'] ?? 0);
        $availableQty = $wasComplete
            ? (float) ($previous['available_quantity'] ?? $neededQty)
            : (float) ($submitted['available_quantity'] ?? 0);

        if ($availableQty < 0)
            $availableQty = 0;
        if ($neededQty > 0 && $availableQty > $neededQty)
            $availableQty = $neededQty;
        $isComplete = ($neededQty <= 0) ? ($availableQty > 0) : ($availableQty >= $neededQty);

        $sourceBy = strtolower(trim((string) ($needed['source_by'] ?? $submitted['source_by'] ?? $previous['source_by'] ?? 'motorpool')));
        $isBranchSource = ($sourceBy === 'branch') || !empty($needed['branch_sourced']) || !empty($submitted['branch_sourced']) || !empty($previous['branch_sourced']);
        $unitCost = $isBranchSource ? 0.00 : (float) ($needed['unit_cost'] ?? $submitted['unit_cost'] ?? $previous['unit_cost'] ?? 0);
        $motorpoolCost = $isBranchSource ? 0.00 : (float) ($needed['motorpool_billable_cost'] ?? $submitted['motorpool_billable_cost'] ?? $submitted['estimated_total_cost'] ?? $previous['motorpool_billable_cost'] ?? $previous['estimated_total_cost'] ?? 0);
        $normalized[] = [
            'item_no' => $needed['item_no'],
            'description' => $needed['description'],
            'specification' => $needed['specification'],
            'needed_quantity' => $needed['needed_quantity_text'] !== '' ? $needed['needed_quantity_text'] : (string) $neededQty,
            'source_by' => $isBranchSource ? 'branch' : 'motorpool',
            'source_label' => $isBranchSource ? 'Branch Source' : 'Motorpool Source',
            'source_status' => (string) ($needed['source_status'] ?? $submitted['source_status'] ?? $previous['source_status'] ?? ''),
            'branch_sourced' => $isBranchSource ? 1 : 0,
            'unit_cost' => number_format($unitCost, 2, '.', ''),
            'estimated_total_cost' => number_format($motorpoolCost, 2, '.', ''),
            'motorpool_billable_cost' => number_format($motorpoolCost, 2, '.', ''),
            'cost_source' => (string) ($needed['cost_source'] ?? $submitted['cost_source'] ?? $previous['cost_source'] ?? ''),
            'available_quantity' => rtrim(rtrim(number_format($availableQty, 2, '.', ''), '0'), '.'),
            'item_completed' => $isComplete ? 1 : 0
        ];
    }

    return $normalized;
}

function partsCompletionAllComplete(array $rows): bool
{
    if (empty($rows))
        return false;
    foreach ($rows as $row) {
        if (empty($row['item_completed']))
            return false;
    }
    return true;
}

function logRisWorkflowHistory(mysqli $conn, int $ris_id, string $status, string $details = '', string $attachment = '', int $processed_by = 0): void
{
    $stmt = $conn->prepare("SELECT ris_number, vehicle_db_id, vehicle_id, plate_no FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
    if (!$stmt)
        return;
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row)
        return;

    $check = $conn->prepare("SELECT history_id FROM motorpool_ris_workflow_history WHERE ris_id = ? AND workflow_status = ? ORDER BY history_id DESC LIMIT 1");
    $existing_id = 0;
    if ($check) {
        $check->bind_param('is', $ris_id, $status);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $existing_id = $existing ? (int) $existing['history_id'] : 0;
        $check->close();
    }

    if ($existing_id > 0) {
        $update = $conn->prepare("UPDATE motorpool_ris_workflow_history SET details = ?, attachment = ?, processed_by = ?, processed_at = NOW() WHERE history_id = ?");
        if ($update) {
            $update->bind_param('ssii', $details, $attachment, $processed_by, $existing_id);
            $update->execute();
            $update->close();
        }
        return;
    }

    $insert = $conn->prepare("INSERT INTO motorpool_ris_workflow_history (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, workflow_status, details, attachment, processed_by, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$insert)
        return;
    $insert->bind_param('isisssssi', $ris_id, $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $status, $details, $attachment, $processed_by);
    $insert->execute();
    $insert->close();
}

function setRisWorkflow(mysqli $conn, int $ris_id, string $status): bool
{
    $stmt = $conn->prepare("UPDATE motorpool_ris_requests SET workflow_status = ?, status = ? WHERE ris_id = ?");
    if (!$stmt)
        return false;
    $stmt->bind_param('ssi', $status, $status, $ris_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}


function isQualityCheckReworkActive(mysqli $conn, int $ris_id, string $currentStatus = '', string $actionTaken = ''): bool
{
    if ($ris_id <= 0)
        return false;
    $status = strtolower(trim($currentStatus));
    if ($status === '') {
        $stmt = $conn->prepare("SELECT COALESCE(workflow_status, status) AS current_status, action_taken FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $status = strtolower(trim((string) ($row['current_status'] ?? '')));
            if ($actionTaken === '')
                $actionTaken = (string) ($row['action_taken'] ?? '');
        }
    }

    if (!in_array($status, ['for repair', 'ongoing repair'], true))
        return false;

    $action = strtolower(trim($actionTaken));
    if ((strpos($action, 'quality') !== false && (strpos($action, 'return') !== false || strpos($action, 'rework') !== false)) || strpos($action, 'returned from quality check') !== false) {
        return true;
    }

    if (!tableExists($conn, 'motorpool_ris_workflow_history'))
        return false;
    $stmt = $conn->prepare("SELECT workflow_status, details FROM motorpool_ris_workflow_history WHERE ris_id = ? ORDER BY processed_at DESC, history_id DESC LIMIT 12");
    if (!$stmt)
        return false;
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $foundQualityReturn = false;
    while ($hist = $res->fetch_assoc()) {
        $histStatus = strtolower(trim((string) ($hist['workflow_status'] ?? '')));
        $details = strtolower(trim((string) ($hist['details'] ?? '')));
        if (($histStatus === 'for repair' || strpos($details, 'return') !== false || strpos($details, 'rework') !== false) && strpos($details, 'quality') !== false) {
            $foundQualityReturn = true;
            break;
        }
    }
    $stmt->close();
    return $foundQualityReturn;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_motorpool_approval_proof_v64') {
    $ris_id = (int) ($_POST['approval_proof_ris_id'] ?? 0);
    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } else {
        $stmt = $conn->prepare("SELECT ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, COALESCE(workflow_status, status) AS current_status FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare approval proof check: ' . $conn->error;
        } else {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $ris = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ris) {
                $save_status = 'error';
                $save_message = 'RIS record was not found.';
            } elseif (strtolower(trim((string) ($ris['current_status'] ?? ''))) !== 'for approval') {
                $save_status = 'error';
                $save_message = 'Approval proof can only be uploaded while the request is For Approval.';
            } else {
                $proofFile = uploadReleaseProof('approval_proof_attachment', '../uploads/motorpool/approval_proofs');
                if ($proofFile === '') {
                    $save_status = 'error';
                    $save_message = 'Please upload a valid proof of approval image or PDF.';
                } else {
                    $remarks = 'Proof of Approval uploaded: approval_proofs/' . $proofFile;
                    $update = $conn->prepare("UPDATE motorpool_ris_requests
                        SET workflow_status = 'For Parts Completion',
                            status = 'For Parts Completion',
                            branch_approval_status = 'Approved',
                            branch_approval_by = ?,
                            branch_approval_at = NOW(),
                            branch_approval_remarks = ?
                        WHERE ris_id = ?");
                    if (!$update) {
                        $save_status = 'error';
                        $save_message = 'Failed to prepare approval proof save: ' . $conn->error;
                    } else {
                        $update->bind_param('isi', $user_id, $remarks, $ris_id);
                        if ($update->execute()) {
                            logRisWorkflowHistory($conn, $ris_id, 'For Approval', 'Proof of approval uploaded. Request approved for parts completion.', json_encode([['filename' => 'approval_proofs/' . $proofFile, 'uploaded_at' => date('Y-m-d H:i:s')]]), $user_id);
                            logRisWorkflowHistory($conn, $ris_id, 'For Parts Completion', 'Approval proof received. Motorpool may now complete the required parts.', json_encode([['filename' => 'approval_proofs/' . $proofFile, 'uploaded_at' => date('Y-m-d H:i:s')]]), $user_id);
                            $save_status = 'success';
                            $save_message = 'Proof of approval uploaded. Request is now for parts completion.';
                        } else {
                            $save_status = 'error';
                            $save_message = 'Failed to upload proof of approval: ' . $update->error;
                        }
                        $update->close();
                    }
                }
            }
        }
    }
}


function repairProgressKey(array $item): string
{
    $repair = trim((string) ($item['repair'] ?? ($item['repair_description'] ?? '')));
    $repair = preg_replace('/\s+/', ' ', $repair);
    return mb_strtolower($repair);
}

function normalizePartsUsedRows($parts): array
{
    if (!is_array($parts))
        return [];
    $rows = [];
    foreach ($parts as $part) {
        if (!is_array($part))
            continue;
        $itemNo = trim((string) ($part['item_no'] ?? ''));
        $availableQty = $part['available_quantity'] ?? ($part['needed_quantity'] ?? ($part['qty_available'] ?? ''));
        $usedQty = $part['used_quantity'] ?? ($part['qty_to_use'] ?? ($part['quantity_to_use'] ?? ($part['quantity_used'] ?? ($part['qty_used'] ?? ''))));
        $description = trim((string) ($part['description'] ?? ''));
        $specification = trim((string) ($part['specification'] ?? ''));
        if ($itemNo === '' && trim((string) $availableQty) === '' && trim((string) $usedQty) === '' && $description === '' && $specification === '')
            continue;
        $rows[] = [
            'item_no' => $itemNo,
            'available_quantity' => trim((string) $availableQty),
            'used_quantity' => trim((string) $usedQty),
            'description' => $description,
            'specification' => $specification
        ];
    }
    return $rows;
}

function mergeRepairProgressRows(array $existingRows, array $postedRows): array
{
    $map = [];
    $order = [];

    foreach ($existingRows as $row) {
        if (!is_array($row))
            continue;
        $key = repairProgressKey($row);
        if ($key === '')
            continue;
        $row['parts_used'] = normalizePartsUsedRows($row['parts_used'] ?? []);
        $map[$key] = $row;
        $order[] = $key;
    }

    foreach ($postedRows as $posted) {
        if (!is_array($posted))
            continue;
        $key = repairProgressKey($posted);
        if ($key === '')
            continue;
        if (!isset($map[$key])) {
            $order[] = $key;
            $map[$key] = [];
        }

        $old = $map[$key];
        $oldStage = strtolower(trim((string) ($old['stage'] ?? '')));
        $oldDone = !empty($old['done']) || $oldStage === 'done' || !empty($old['locked']);
        $oldStarted = !empty($old['checked']) || in_array($oldStage, ['ongoing', 'done'], true) || $oldDone;
        $postedStageForGuard = strtolower(trim((string) ($posted['stage'] ?? '')));
        $postedStarted = !empty($posted['checked']) || !empty($posted['done']) || in_array($postedStageForGuard, ['ongoing', 'done'], true);

        // Important: when starting another repair under the same RIS, the form may still post
        // unselected rows as for_repair/labor. Do not let those rows overwrite an already
        // started or completed repair log.
        if ($oldStarted && !$postedStarted) {
            continue;
        }

        $merged = $old;
        $merged['repair'] = trim((string) ($posted['repair'] ?? ($old['repair'] ?? '')));
        $merged['checked'] = (!empty($posted['checked']) || !empty($old['checked']) || $oldDone) ? 1 : 0;
        $merged['repair_type'] = trim((string) ($posted['repair_type'] ?? ($old['repair_type'] ?? 'labor')));
        if (!in_array($merged['repair_type'], ['labor', 'with_parts'], true))
            $merged['repair_type'] = 'labor';

        foreach (['start_date', 'start_time', 'end_time', 'mechanic', 'repair_cost'] as $field) {
            $postedValue = trim((string) ($posted[$field] ?? ''));
            if ($postedValue !== '')
                $merged[$field] = $postedValue;
            elseif (!isset($merged[$field]))
                $merged[$field] = '';
        }

        $postedParts = normalizePartsUsedRows($posted['parts_used'] ?? []);
        if (!empty($postedParts))
            $merged['parts_used'] = $postedParts;
        else
            $merged['parts_used'] = normalizePartsUsedRows($old['parts_used'] ?? []);

        if ($oldDone) {
            $merged['done'] = 1;
            $merged['checked'] = 1;
            $merged['stage'] = 'done';
            $merged['locked'] = 1;
        } else {
            $postedStage = strtolower(trim((string) ($posted['stage'] ?? '')));
            $postedDone = !empty($posted['done']) || $postedStage === 'done';
            if ($postedDone) {
                $merged['done'] = 1;
                $merged['checked'] = 1;
                $merged['stage'] = 'done';
                $merged['locked'] = 1;
                foreach (['end_date', 'end_time', 'completion_date', 'completion_time', 'completion_mechanic'] as $field) {
                    $postedValue = trim((string) ($posted[$field] ?? ''));
                    if ($postedValue !== '')
                        $merged[$field] = $postedValue;
                }
            } elseif (!empty($merged['checked'])) {
                $merged['done'] = 0;
                $merged['stage'] = 'ongoing';
            } else {
                $merged['done'] = 0;
                $merged['stage'] = 'for_repair';
            }
        }

        $map[$key] = $merged;
    }

    $result = [];
    foreach (array_values(array_unique($order)) as $key) {
        if (isset($map[$key]))
            $result[] = $map[$key];
    }
    return $result;
}


function loadRepairProgressRowsFromStartLogs(mysqli $conn, int $ris_id): array
{
    $rows = [];
    if ($ris_id <= 0 || !tableExists($conn, 'motorpool_repair_start_logs'))
        return $rows;
    $stmt = $conn->prepare("SELECT repair_description, repair_type, parts_used_json, start_datetime, end_datetime, mechanic, completion_mechanic, log_status FROM motorpool_repair_start_logs WHERE ris_id = ? ORDER BY start_datetime ASC, log_id ASC");
    if (!$stmt)
        return $rows;
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $repair = trim((string) ($row['repair_description'] ?? ''));
        if ($repair === '')
            continue;
        $parts = json_decode((string) ($row['parts_used_json'] ?? '[]'), true);
        if (!is_array($parts))
            $parts = [];
        $start = trim((string) ($row['start_datetime'] ?? ''));
        $end = trim((string) ($row['end_datetime'] ?? ''));
        $status = strtolower(trim((string) ($row['log_status'] ?? 'ongoing')));
        $isDone = ($status === 'done' || $status === 'completed' || $end !== '');
        $rows[] = [
            'repair' => $repair,
            'checked' => 1,
            'done' => $isDone ? 1 : 0,
            'locked' => $isDone ? 1 : 0,
            'stage' => $isDone ? 'done' : 'ongoing',
            'repair_type' => in_array((string) ($row['repair_type'] ?? 'labor'), ['labor', 'with_parts'], true) ? (string) $row['repair_type'] : 'labor',
            'parts_used' => normalizePartsUsedRows($parts),
            'start_date' => $start !== '' ? substr($start, 0, 10) : '',
            'start_time' => strlen($start) >= 16 ? substr($start, 11, 5) : '',
            'mechanic' => trim((string) ($row['mechanic'] ?? '')),
            'end_date' => $end !== '' ? substr($end, 0, 10) : '',
            'end_time' => strlen($end) >= 16 ? substr($end, 11, 5) : '',
            'completion_date' => $end !== '' ? substr($end, 0, 10) : '',
            'completion_time' => strlen($end) >= 16 ? substr($end, 11, 5) : '',
            'completion_mechanic' => trim((string) ($row['completion_mechanic'] ?? ''))
        ];
    }
    $stmt->close();
    return $rows;
}


function buildQualityCheckRowsForRis(mysqli $conn, int $ris_id): array
{
    $doneRows = [];
    $seen = [];

    $addDoneRow = function (array $row) use (&$doneRows, &$seen): void {
        $repair = trim((string) ($row['repair'] ?? ($row['repair_description'] ?? '')));
        if ($repair === '')
            return;
        $stage = strtolower(trim((string) ($row['stage'] ?? '')));
        $hasEnd = trim((string) ($row['end_date'] ?? ($row['completion_date'] ?? ''))) !== '' || trim((string) ($row['end_time'] ?? ($row['completion_time'] ?? ''))) !== '';
        $isDone = !empty($row['done']) || !empty($row['locked']) || $stage === 'done' || $hasEnd;
        if (!$isDone)
            return;

        $key = mb_strtolower($repair);
        $row['repair'] = $repair;
        $row['checked'] = 1;
        $row['done'] = 1;
        $row['locked'] = 1;
        $row['stage'] = 'done';
        if (empty($row['repair_type']) || !in_array((string) $row['repair_type'], ['labor', 'with_parts'], true)) {
            $row['repair_type'] = !empty($row['parts_used']) ? 'with_parts' : 'labor';
        }
        if (!isset($row['parts_used']) || !is_array($row['parts_used']))
            $row['parts_used'] = [];
        $row['parts_used'] = normalizePartsUsedRows($row['parts_used']);

        // Keep the newest active done row for each repair. This is important after
        // Quality Check returns a request to repair, because older done logs are
        // archived as rework_returned and the rework completion must be the row used
        // by the Quality Check modal.
        $seen[$key] = count($doneRows);
        $doneRows[$seen[$key]] = $row;
    };

    if ($ris_id <= 0)
        return [];

    // 1) Primary source: active repair progress JSON. This is what the Ongoing Repair
    // modal saves when the user checks "I confirm this repair is done".
    if (tableExists($conn, 'motorpool_ris_repair_progress')) {
        $stmt = $conn->prepare("SELECT repair_progress_json FROM motorpool_ris_repair_progress WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $progressRows = json_decode((string) ($row['repair_progress_json'] ?? '[]'), true);
            if (is_array($progressRows)) {
                foreach ($progressRows as $progressRow) {
                    if (is_array($progressRow))
                        $addDoneRow($progressRow);
                }
            }
        }
    }

    // 2) Fallback source: repair start logs with done/completed status or end_datetime.
    // This protects the QC modal if the progress JSON was not refreshed but the start
    // log was marked done.
    if (empty($doneRows) && tableExists($conn, 'motorpool_repair_start_logs')) {
        $stmt = $conn->prepare("SELECT repair_description, repair_type, parts_used_json, start_datetime, end_datetime, mechanic, completion_mechanic, log_status
            FROM motorpool_repair_start_logs
            WHERE ris_id = ?
              AND (LOWER(TRIM(COALESCE(log_status,''))) IN ('done','completed') OR end_datetime IS NOT NULL)
            ORDER BY log_id ASC");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($log = $res->fetch_assoc()) {
                $parts = json_decode((string) ($log['parts_used_json'] ?? '[]'), true);
                if (!is_array($parts))
                    $parts = [];
                $start = trim((string) ($log['start_datetime'] ?? ''));
                $end = trim((string) ($log['end_datetime'] ?? ''));
                $addDoneRow([
                    'repair' => trim((string) ($log['repair_description'] ?? '')),
                    'repair_type' => in_array((string) ($log['repair_type'] ?? 'labor'), ['labor', 'with_parts'], true) ? (string) $log['repair_type'] : 'labor',
                    'parts_used' => $parts,
                    'start_date' => $start !== '' ? substr($start, 0, 10) : '',
                    'start_time' => strlen($start) >= 16 ? substr($start, 11, 5) : '',
                    'mechanic' => trim((string) ($log['mechanic'] ?? '')),
                    'end_date' => $end !== '' ? substr($end, 0, 10) : '',
                    'end_time' => strlen($end) >= 16 ? substr($end, 11, 5) : '',
                    'completion_date' => $end !== '' ? substr($end, 0, 10) : '',
                    'completion_time' => strlen($end) >= 16 ? substr($end, 11, 5) : '',
                    'completion_mechanic' => trim((string) ($log['completion_mechanic'] ?? ($log['mechanic'] ?? ''))),
                    'done' => 1,
                    'locked' => 1,
                    'stage' => 'done'
                ]);
            }
            $stmt->close();
        }
    }

    return array_values($doneRows);
}


function loadAssessmentRepairProgressRows(mysqli $conn, int $ris_id): array
{
    $rows = [];
    if ($ris_id <= 0 || !tableExists($conn, 'motorpool_ris_assessments'))
        return $rows;
    $stmt = $conn->prepare("SELECT assessment_json FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
    if (!$stmt)
        return $rows;
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $assessment = json_decode((string) ($row['assessment_json'] ?? '[]'), true);
    if (!is_array($assessment))
        return $rows;
    foreach ($assessment as $assessmentRow) {
        if (!is_array($assessmentRow))
            continue;
        $repair = trim((string) ($assessmentRow['repair'] ?? ''));
        if ($repair === '')
            continue;
        $repairCost = trim((string) ($assessmentRow['repair_cost'] ?? ($assessmentRow['labor_cost'] ?? ($assessmentRow['service_cost'] ?? ''))));
        $rows[] = [
            'repair' => $repair,
            'repair_cost' => $repairCost,
            'checked' => 0,
            'done' => 0,
            'locked' => 0,
            'stage' => 'for_repair',
            'repair_type' => 'labor',
            'parts_used' => [],
            'start_date' => '',
            'start_time' => '',
            'mechanic' => '',
            'end_date' => '',
            'end_time' => '',
            'completion_date' => '',
            'completion_time' => '',
            'completion_mechanic' => ''
        ];
    }
    return $rows;
}

function buildMergedRepairRowsForRis(mysqli $conn, int $ris_id, array $postedRows = []): array
{
    $baseRows = loadAssessmentRepairProgressRows($conn, $ris_id);

    $currentStatus = '';
    $actionTaken = '';
    if ($ris_id > 0 && tableExists($conn, 'motorpool_ris_requests')) {
        $stmt = $conn->prepare("SELECT COALESCE(workflow_status, status) AS current_status, action_taken FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $currentStatus = strtolower(trim((string) ($row['current_status'] ?? '')));
            $actionTaken = strtolower(trim((string) ($row['action_taken'] ?? '')));
        }
    }

    $savedRows = [];
    if ($ris_id > 0 && tableExists($conn, 'motorpool_ris_repair_progress')) {
        $stmt = $conn->prepare("SELECT repair_progress_json FROM motorpool_ris_repair_progress WHERE ris_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $saved = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $savedRows = json_decode((string) ($saved['repair_progress_json'] ?? '[]'), true);
            if (!is_array($savedRows))
                $savedRows = [];
        }
    }

    $postedHasRework = false;
    foreach ($postedRows as $postedRow) {
        if (!is_array($postedRow))
            continue;
        if (!empty($postedRow['rework_returned']) || trim((string) ($postedRow['rework_cycle_key'] ?? '')) !== '') {
            $postedHasRework = true;
            break;
        }
    }

    $savedHasRework = false;
    foreach ($savedRows as $savedRow) {
        if (!is_array($savedRow))
            continue;
        if (!empty($savedRow['rework_returned']) || trim((string) ($savedRow['rework_cycle_key'] ?? '')) !== '') {
            $savedHasRework = true;
            break;
        }
        foreach (($savedRow['parts_used'] ?? []) as $savedPart) {
            if (is_array($savedPart) && !empty($savedPart['rework_returned'])) {
                $savedHasRework = true;
                break 2;
            }
        }
    }

    $statusIsReworkStage = in_array($currentStatus, ['for repair', 'ongoing repair'], true);
    $actionLooksLikeQualityReturn = (
        strpos($actionTaken, 'quality') !== false ||
        strpos($actionTaken, 'rework') !== false ||
        strpos($actionTaken, 'return') !== false
    );

    /*
     * IMPORTANT FIX FOR QUALITY CHECK REWORK:
     * savedRows can still contain rework_returned = 1 after the rework has already been
     * completed and the RIS has moved back to For Quality Check. The old logic treated
     * that flag as an active rework cycle on every page load, then rebuilt the rows from
     * assessment and reset done/locked back to 0. That is why the Quality Check modal
     * showed "No completed repair logs found" after returning from QC and completing
     * the repair again.
     *
     * Only apply the rework reset rules while the request is actually in For Repair or
     * Ongoing Repair, or while saving posted rework rows. Once the status is For Quality
     * Check, preserve the saved done rows so the QC modal can display them.
     */
    $hasReworkSignal = $postedHasRework || $savedHasRework || ($statusIsReworkStage && $actionLooksLikeQualityReturn) || isQualityCheckReworkActive($conn, $ris_id, $currentStatus, $actionTaken);
    $isQualityRework = $hasReworkSignal && ($statusIsReworkStage || !empty($postedRows));

    if ($isQualityRework) {
        // Quality Check return/rework rules:
        // 1) Do not merge old completed start logs. They belong to the previous repair cycle.
        // 2) Keep the same approved item list visible, but make it read-only in the UI.
        // 3) Skip inventory deduction for the rework cycle because the parts were already used once.
        // 4) If the request is already in Ongoing Repair, use the saved rework rows so the ongoing
        //    modal keeps the date/time/mechanic values and the checkbox state.
        if ($currentStatus === 'ongoing repair' && !empty($savedRows) && empty($postedRows)) {
            foreach ($savedRows as &$savedRow) {
                if (!is_array($savedRow))
                    continue;

                // Returned from Quality Check must behave like a fresh rework cycle.
                // Keep the parts/items visible as reference only, but do not carry over
                // the previous Done/Locked state. The Motorpool user must manually
                // tick the checkbox again after completing the rework.
                $savedRow['rework_returned'] = 1;
                $savedRow['skip_inventory_deduction'] = 1;
                if (trim((string) ($savedRow['rework_cycle_key'] ?? '')) === '')
                    $savedRow['rework_cycle_key'] = 'quality-return-' . $ris_id;
                $savedRow['checked'] = 0;
                $savedRow['done'] = 0;
                $savedRow['locked'] = 0;
                $savedRow['stage'] = 'ongoing';
                $savedRow['completion_date'] = '';
                $savedRow['completion_time'] = '';
                $savedRow['completion_mechanic'] = '';

                if (!empty($savedRow['parts_used']) && is_array($savedRow['parts_used'])) {
                    foreach ($savedRow['parts_used'] as &$savedPart) {
                        if (!is_array($savedPart))
                            continue;
                        $savedPart['skip_inventory_deduction'] = 1;
                        $savedPart['rework_returned'] = 1;
                    }
                    unset($savedPart);
                }
            }
            unset($savedRow);
            return $savedRows;
        }

        foreach ($baseRows as &$baseRow) {
            $baseRow['checked'] = 0;
            $baseRow['done'] = 0;
            $baseRow['locked'] = 0;
            $baseRow['stage'] = 'for_repair';
            $baseRow['rework_returned'] = 1;
            $baseRow['skip_inventory_deduction'] = 1;
            if (trim((string) ($baseRow['rework_cycle_key'] ?? '')) === '')
                $baseRow['rework_cycle_key'] = 'quality-return-' . $ris_id;
            $baseRow['start_date'] = '';
            $baseRow['start_time'] = '';
            $baseRow['end_time'] = '';
            $baseRow['end_date'] = '';
            $baseRow['completion_date'] = '';
            $baseRow['completion_time'] = '';
            $baseRow['mechanic'] = '';
            $baseRow['completion_mechanic'] = '';
            if (!empty($baseRow['parts_used']) && is_array($baseRow['parts_used'])) {
                foreach ($baseRow['parts_used'] as &$basePart) {
                    if (!is_array($basePart))
                        continue;
                    $basePart['skip_inventory_deduction'] = 1;
                    $basePart['rework_returned'] = 1;
                }
                unset($basePart);
            }
        }
        unset($baseRow);

        if (!empty($postedRows)) {
            $mergedRework = mergeRepairProgressRows($baseRows, $postedRows);
            foreach ($mergedRework as &$row) {
                if (!is_array($row))
                    continue;
                $row['rework_returned'] = 1;
                $row['skip_inventory_deduction'] = 1;
                if (trim((string) ($row['rework_cycle_key'] ?? '')) === '')
                    $row['rework_cycle_key'] = 'quality-return-' . $ris_id;
                if (!empty($row['checked']) && strtolower((string) ($row['stage'] ?? '')) !== 'done') {
                    $row['stage'] = 'ongoing';
                }
                if (!empty($row['parts_used']) && is_array($row['parts_used'])) {
                    foreach ($row['parts_used'] as &$part) {
                        if (!is_array($part))
                            continue;
                        $part['skip_inventory_deduction'] = 1;
                        $part['rework_returned'] = 1;
                    }
                    unset($part);
                }
            }
            unset($row);
            return $mergedRework;
        }

        return $baseRows;
    }

    $logRows = loadRepairProgressRowsFromStartLogs($conn, $ris_id);
    $merged = mergeRepairProgressRows($baseRows, $savedRows);
    $merged = mergeRepairProgressRows($merged, $logRows);
    if (!empty($postedRows))
        $merged = mergeRepairProgressRows($merged, $postedRows);
    return $merged;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return_vehicle_endorsement_v1') {
    $ris_id = (int) ($_POST['return_ris_id'] ?? 0);
    $return_remarks = trim((string) ($_POST['return_remarks'] ?? ''));

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif ($return_remarks === '') {
        $save_status = 'error';
        $save_message = 'Return remarks are required.';
    } else {
        $stmt = $conn->prepare("SELECT ris_id, ris_number, COALESCE(workflow_status, status) AS current_status FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare return request: ' . $conn->error;
        } else {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $ris = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ris) {
                $save_status = 'error';
                $save_message = 'RIS record was not found.';
            } elseif (trim((string) ($ris['current_status'] ?? '')) !== 'For Vehicle Endorsement') {
                $save_status = 'error';
                $save_message = 'Only requests under For Vehicle Endorsement can be returned to Branch Admin.';
            } else {
                $returnStatus = 'Returned to Branch Admin';
                $update = $conn->prepare("UPDATE motorpool_ris_requests
                    SET workflow_status = ?,
                        status = ?,
                        motorpool_return_remarks = ?,
                        motorpool_returned_by = ?,
                        motorpool_returned_at = NOW()
                    WHERE ris_id = ?");
                if (!$update) {
                    $save_status = 'error';
                    $save_message = 'Failed to prepare return save: ' . $conn->error;
                } else {
                    $update->bind_param('sssii', $returnStatus, $returnStatus, $return_remarks, $user_id, $ris_id);
                    if ($update->execute()) {
                        logRisWorkflowHistory($conn, $ris_id, 'Returned to Branch Admin', "Request returned by Motorpool.

Remarks: " . $return_remarks, '', $user_id);
                        $save_status = 'success';
                        $save_message = 'Request returned to Branch Admin successfully.';
                    } else {
                        $save_status = 'error';
                        $save_message = 'Failed to return request: ' . $update->error;
                    }
                    $update->close();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_vehicle_workflow_v3') {
    $ris_id = (int) ($_POST['receive_ris_id'] ?? 0);
    $received_by_name = trim($_POST['received_by_name'] ?? '');
    $received_date = trim($_POST['received_date'] ?? date('Y-m-d'));
    $received_time = trim($_POST['received_time'] ?? date('H:i'));

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif ($received_by_name === '') {
        $save_status = 'error';
        $save_message = 'Please enter who received the vehicle.';
    } else {
        try {
            $photos = uploadReceivedVehicleAnglePhotos('../uploads/motorpool/received_vehicle');
        } catch (Exception $e) {
            $save_status = 'error';
            $save_message = $e->getMessage();
            $photos = [];
        }
        if ($save_status === 'error') {
        } else {
            $stmt = $conn->prepare("SELECT r.ris_id, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no, rp.repair_progress_json AS existing_repair_progress_json FROM motorpool_ris_requests r LEFT JOIN motorpool_ris_repair_progress rp ON rp.ris_id = r.ris_id WHERE r.ris_id = ? LIMIT 1");
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $ris = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$ris) {
                $save_status = 'error';
                $save_message = 'RIS record was not found.';
            } else {
                $conn->begin_transaction();
                try {
                    $received_datetime = $received_date . ' ' . $received_time . ':00';
                    $receiptStmt = $conn->prepare("INSERT INTO motorpool_vehicle_receipts
                        (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, received_by_name, received_date, received_time, received_datetime, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE received_by_name = VALUES(received_by_name), received_date = VALUES(received_date), received_time = VALUES(received_time), received_datetime = VALUES(received_datetime)");
                    if (!$receiptStmt)
                        throw new Exception($conn->error);
                    $receiptStmt->bind_param('isissssssi', $ris_id, $ris['ris_number'], $ris['vehicle_db_id'], $ris['vehicle_id'], $ris['plate_no'], $received_by_name, $received_date, $received_time, $received_datetime, $user_id);
                    if (!$receiptStmt->execute())
                        throw new Exception($receiptStmt->error);
                    $receipt_id = $receiptStmt->insert_id;
                    $receiptStmt->close();
                    if ($receipt_id <= 0) {
                        $r = $conn->query("SELECT receipt_id FROM motorpool_vehicle_receipts WHERE ris_id = " . (int) $ris_id . " LIMIT 1");
                        $receipt_id = $r && ($rr = $r->fetch_assoc()) ? (int) $rr['receipt_id'] : 0;
                    }
                    $photoStmt = $conn->prepare("INSERT INTO motorpool_vehicle_receipt_photos (receipt_id, ris_id, photo_type, filename, timestamp_text, uploaded_at) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($photos as $p) {
                        $photoStmt->bind_param('iissss', $receipt_id, $ris_id, $p['photo_type'], $p['filename'], $p['timestamp_text'], $p['uploaded_at']);
                        if (!$photoStmt->execute())
                            throw new Exception($photoStmt->error);
                    }
                    $photoStmt->close();
                    setRisWorkflow($conn, $ris_id, 'For Assessment');
                    logRisWorkflowHistory($conn, $ris_id, 'For Vehicle Endorsement', 'Vehicle received by ' . $received_by_name . ' on ' . $received_date . ' at ' . $received_time . '.', json_encode($photos), $user_id);
                    logRisWorkflowHistory($conn, $ris_id, 'For Assessment', 'Vehicle is ready for assessment after endorsement.', '', $user_id);
                    $conn->commit();
                    $save_status = 'success';
                    $save_message = 'Vehicle received. Request is now for assessment.';
                } catch (Exception $e) {
                    $conn->rollback();
                    $save_status = 'error';
                    $save_message = $e->getMessage();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_assessment_workflow_v3') {
    $ris_id = (int) ($_POST['assessment_ris_id'] ?? 0);
    $assessment_json = trim($_POST['assessment_json'] ?? '');
    $assessment_assessed_by = trim($_POST['assessment_assessed_by'] ?? '');
    $assessment = json_decode($assessment_json, true);
    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif (!is_array($assessment) || empty($assessment)) {
        $save_status = 'error';
        $save_message = 'Please add at least one repair to make.';
    } elseif ($assessment_assessed_by === '') {
        $save_status = 'error';
        $save_message = 'Please enter Assessed By.';
    } else {
        $stmt = $conn->prepare("SELECT r.ris_number, r.requested_by, COALESCE(LOWER(TRIM(u.role)), '') AS requested_by_role
            FROM motorpool_ris_requests r
            LEFT JOIN users u ON u.user_id = r.requested_by
            WHERE r.ris_id = ?
            LIMIT 1");
        $stmt->bind_param('i', $ris_id);
        $stmt->execute();
        $ris = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ris) {
            $save_status = 'error';
            $save_message = 'RIS record was not found.';
        } else {
            $assessment = enrichAssessmentPartsWithInventoryCost($conn, $assessment);
            $assessment_json = json_encode($assessment, JSON_UNESCAPED_UNICODE);

            $repairs = [];
            $parts = [];
            $hasIncompletePart = false;
            $assessmentGrandTotalCost = 0.0;
            foreach ($assessment as $r) {
                $repair = trim((string) ($r['repair'] ?? ''));
                $repairCostRaw = trim((string) ($r['repair_cost'] ?? ($r['labor_cost'] ?? ($r['service_cost'] ?? ''))));
                $repairCostValue = is_numeric($repairCostRaw) ? (float) $repairCostRaw : 0.0;
                $repairCost = number_format($repairCostValue, 2, '.', '');
                $partsEstimatedTotalForRepair = 0.0;
                foreach (($r['parts'] ?? []) as $part) {
                    $itemNo = trim((string) ($part['item_no'] ?? ''));
                    $description = trim((string) ($part['description'] ?? ($part['name'] ?? '')));
                    $specification = trim((string) ($part['specification'] ?? ''));
                    $quantity = trim((string) ($part['quantity'] ?? ($part['qty'] ?? '')));
                    $unitCostRaw = trim((string) ($part['unit_cost'] ?? ''));
                    $unitCost = is_numeric($unitCostRaw) ? (float) $unitCostRaw : 0.0;
                    $estimatedCost = is_numeric($part['estimated_total_cost'] ?? null) ? (float) $part['estimated_total_cost'] : ((float) $quantity * $unitCost);
                    $partsEstimatedTotalForRepair += $estimatedCost;
                    $source = trim((string) ($part['cost_source'] ?? 'Manual'));
                    $hasAnyPartValue = ($itemNo !== '' || $description !== '' || $specification !== '' || $quantity !== '' || $unitCostRaw !== '');
                    $hasAllPartValue = ($itemNo !== '' && $description !== '' && $specification !== '' && $quantity !== '' && $unitCostRaw !== '');

                    if ($hasAnyPartValue && !$hasAllPartValue) {
                        $hasIncompletePart = true;
                    }

                    if ($hasAllPartValue) {
                        $parts[] = 'Item No.: ' . $itemNo
                            . ' | Description: ' . $description
                            . ' | Specification: ' . $specification
                            . ' | Quantity: ' . $quantity
                            . ' | Unit Cost: ' . number_format($unitCost, 2, '.', '')
                            . ' | Estimated Cost: ' . number_format($estimatedCost, 2, '.', '')
                            . ' | Cost Source: ' . $source;
                    }
                }
                if ($repair !== '') {
                    $repairTotalCostValue = $repairCostValue + $partsEstimatedTotalForRepair;
                    $assessmentGrandTotalCost += $repairTotalCostValue;
                    $repairs[] = $repair
                        . ' | Repair Cost: ' . number_format($repairCostValue, 2, '.', '')
                        . ' | Parts Estimated Cost: ' . number_format($partsEstimatedTotalForRepair, 2, '.', '')
                        . ' | Repair Total Cost: ' . number_format($repairTotalCostValue, 2, '.', '');
                }
            }
            if (empty($repairs)) {
                $save_status = 'error';
                $save_message = 'Please enter at least one repair to make.';
            } elseif ($hasIncompletePart) {
                $save_status = 'error';
                $save_message = 'Please complete all required parts fields: Item No., Description, Specification, Quantity, and Unit Cost.';
            } else {
                $repairs_summary = implode("\n", $repairs);
                $parts_summary = (!empty($parts) ? implode("\n", $parts) : 'No items/parts needed.') . "\n\nGrand Total Cost: " . number_format($assessmentGrandTotalCost, 2, '.', '') . "\nAssessed By: " . $assessment_assessed_by;
                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO motorpool_ris_assessments
                    (ris_id, ris_number, assessment_json, repairs_summary, parts_summary, assessed_by, assessed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE assessment_json = VALUES(assessment_json), repairs_summary = VALUES(repairs_summary), parts_summary = VALUES(parts_summary), assessed_by = VALUES(assessed_by), assessed_at = VALUES(assessed_at)");
                if (!$stmt) {
                    $save_status = 'error';
                    $save_message = $conn->error;
                } else {
                    $stmt->bind_param('issssis', $ris_id, $ris['ris_number'], $assessment_json, $repairs_summary, $parts_summary, $user_id, $now);
                    if ($stmt->execute()) {
                        $assessmentDetails = "Repairs to Make:
" . $repairs_summary . "

Items / Parts Needed:
" . $parts_summary;

                        $findings = 'Assessment prepared by Motorpool.';
                        $isMotorpoolOrigin = strtolower(trim((string) ($ris['requested_by_role'] ?? ''))) === 'motorpool';

                        if ($isMotorpoolOrigin) {
                            /*
                             * RIS created directly from the Motorpool account should not go back
                             * to Branch Admin approval. It is auto-approved and moved straight to
                             * For Parts Completion after assessment.
                             */
                            $autoRemarks = 'Auto-approved because the RIS was created by the Motorpool account.';
                            $up = $conn->prepare("UPDATE motorpool_ris_requests
                                SET workflow_status='For Parts Completion',
                                    status='For Parts Completion',
                                    branch_approval_status='Approved',
                                    branch_approval_by=?,
                                    branch_approval_at=NOW(),
                                    branch_approval_remarks=?,
                                    findings=?,
                                    action_taken='Assessment saved by Motorpool and sent directly to parts completion.',
                                    repairs_done=?,
                                    parts_replaced=?,
                                    repair_cost=?
                                WHERE ris_id=?");
                            if (!$up) {
                                $save_status = 'error';
                                $save_message = 'Failed to prepare assessment update: ' . $conn->error;
                            } else {
                                $up->bind_param('issssdi', $user_id, $autoRemarks, $findings, $repairs_summary, $parts_summary, $assessmentGrandTotalCost, $ris_id);
                                if ($up->execute()) {
                                    logRisWorkflowHistory($conn, $ris_id, 'For Assessment', $assessmentDetails, '', $user_id);
                                    logRisWorkflowHistory($conn, $ris_id, 'For Parts Completion', 'Motorpool-created RIS was assessed and auto-approved. Motorpool may now complete the required parts.' . "

" . $assessmentDetails, '', $user_id);
                                    $save_status = 'success';
                                    $save_message = 'Assessment saved. Since this RIS was created by Motorpool, it was moved directly to For Parts Completion.';
                                } else {
                                    $save_status = 'error';
                                    $save_message = 'Failed to update assessment workflow: ' . $up->error;
                                }
                                $up->close();
                            }
                        } else {
                            $up = $conn->prepare("UPDATE motorpool_ris_requests SET workflow_status='For Approval', status='For Approval', branch_approval_status='Pending', branch_approval_by=NULL, branch_approval_at=NULL, branch_approval_remarks=NULL, findings=?, action_taken='Assessment sent for branch approval.', repairs_done=?, parts_replaced=?, repair_cost=? WHERE ris_id=?");
                            if (!$up) {
                                $save_status = 'error';
                                $save_message = 'Failed to prepare assessment approval update: ' . $conn->error;
                            } else {
                                $up->bind_param('sssdi', $findings, $repairs_summary, $parts_summary, $assessmentGrandTotalCost, $ris_id);
                                if ($up->execute()) {
                                    logRisWorkflowHistory($conn, $ris_id, 'For Assessment', $assessmentDetails, '', $user_id);
                                    logRisWorkflowHistory($conn, $ris_id, 'For Approval', 'Assessment sent to Branch Admin for approval.' . "

" . $assessmentDetails, '', $user_id);
                                    $save_status = 'success';
                                    $save_message = 'Assessment saved. Request is now for approval.';
                                } else {
                                    $save_status = 'error';
                                    $save_message = 'Failed to send assessment for approval: ' . $up->error;
                                }
                                $up->close();
                            }
                        }
                    } else {
                        $save_status = 'error';
                        $save_message = $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_repair_workflow_v4') {
    $ris_id = (int) ($_POST['start_repair_ris_id'] ?? 0);
    $repair_progress_json = trim((string) ($_POST['start_repair_progress_json'] ?? '[]'));
    $repair_progress = json_decode($repair_progress_json, true);
    if (!is_array($repair_progress))
        $repair_progress = [];

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif (empty($repair_progress)) {
        $save_status = 'error';
        $save_message = 'Please add at least one repair log.';
    } else {
        $summaryLines = [];
        $totalRepairs = 0;
        $startedRepairs = 0;
        $firstStartDate = '';
        $firstMechanic = '';
        $hasValidationError = false;
        $validationMessage = '';

        foreach ($repair_progress as &$item) {
            $repair = trim((string) ($item['repair'] ?? ''));
            if ($repair === '')
                continue;

            $totalRepairs++;
            $checked = !empty($item['checked']) ? 1 : 0;
            $repairType = trim((string) ($item['repair_type'] ?? 'labor'));
            if (!in_array($repairType, ['labor', 'with_parts'], true))
                $repairType = 'labor';
            $startDate = trim((string) ($item['start_date'] ?? ($item['repair_date'] ?? '')));
            $startTime = trim((string) ($item['start_time'] ?? ''));
            $endTime = trim((string) ($item['end_time'] ?? ''));
            $mechanicName = trim((string) ($item['mechanic'] ?? ''));
            $partsUsed = $item['parts_used'] ?? [];
            if (!is_array($partsUsed))
                $partsUsed = [];

            if ($checked) {
                if ($startDate === '' || $startTime === '' || $endTime === '' || $mechanicName === '') {
                    $hasValidationError = true;
                    $validationMessage = 'Please complete date, start time, end time, and mechanic for every repair log.';
                    break;
                }

                if ($repairType === 'with_parts') {
                    $hasUsedPart = false;
                    foreach ($partsUsed as &$part) {
                        $usedQty = (float) ($part['used_quantity'] ?? 0);
                        $availableQty = (float) ($part['available_quantity'] ?? ($part['needed_quantity'] ?? 0));
                        $neededQty = (float) ($part['needed_quantity'] ?? 0);
                        $limitQty = $availableQty > 0 ? $availableQty : $neededQty;
                        if ($usedQty > 0)
                            $hasUsedPart = true;
                        if ($usedQty < 0 || ($limitQty > 0 && $usedQty > $limitQty)) {
                            $hasValidationError = true;
                            $validationMessage = 'Qty to use must not exceed the available quantity.';
                            break 2;
                        }
                    }
                    unset($part);
                    if (!$hasUsedPart) {
                        $hasValidationError = true;
                        $validationMessage = 'Please enter Qty To Use for at least one part when Repair Type is With Parts.';
                        break;
                    }
                }

                $startedRepairs++;
                if ($firstStartDate === '')
                    $firstStartDate = $startDate;
                if ($firstMechanic === '')
                    $firstMechanic = $mechanicName;
            }

            $item['checked'] = $checked;
            $item['repair_type'] = $repairType;
            $item['stage'] = $checked ? 'ongoing' : 'for_repair';
            $item['start_date'] = $startDate;
            $item['start_time'] = $startTime;
            $item['end_time'] = $endTime;
            $item['mechanic'] = $mechanicName;
            $item['parts_used'] = $partsUsed;

            $partsText = 'Labor only';
            if ($repairType === 'with_parts') {
                $partLines = [];
                foreach ($partsUsed as $part) {
                    $usedQty = trim((string) ($part['used_quantity'] ?? ''));
                    if ($usedQty === '' || (float) $usedQty <= 0)
                        continue;
                    $partLines[] = 'Item No.: ' . ($part['item_no'] ?? '-') . ' | Qty Used: ' . $usedQty;
                }
                $partsText = !empty($partLines) ? implode('; ', $partLines) : 'With parts, no quantity used yet';
            }

            $summaryLines[] = 'Repair: ' . $repair . ' | Type: ' . ($repairType === 'with_parts' ? 'With Parts' : 'Labor Only') . ' | Date: ' . ($checked ? ($startDate . ' ' . $startTime . ' - ' . $endTime) : 'Not logged') . ' | Mechanic: ' . ($mechanicName !== '' ? $mechanicName : '-') . ' | Parts: ' . $partsText;
        }
        unset($item);

        if ($hasValidationError) {
            $save_status = 'error';
            $save_message = $validationMessage;
        } elseif ($totalRepairs <= 0) {
            $save_status = 'error';
            $save_message = 'No repair item was found from the assessment.';
        } elseif ($startedRepairs <= 0) {
            $save_status = 'error';
            $save_message = 'Please select at least one repair to start.';
        } else {
            $stmt = $conn->prepare("SELECT r.ris_id, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no, rp.repair_progress_json AS existing_repair_progress_json FROM motorpool_ris_requests r LEFT JOIN motorpool_ris_repair_progress rp ON rp.ris_id = r.ris_id WHERE r.ris_id = ? LIMIT 1");
            if (!$stmt) {
                $save_status = 'error';
                $save_message = 'Failed to prepare repair log: ' . $conn->error;
            } else {
                $stmt->bind_param('i', $ris_id);
                $stmt->execute();
                $ris = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$ris) {
                    $save_status = 'error';
                    $save_message = 'RIS record was not found.';
                } else {
                    $conn->begin_transaction();
                    try {
                        $mergedRepairProgress = buildMergedRepairRowsForRis($conn, $ris_id, $repair_progress);

                        // Connect For Repair parts usage to Motorpool Inventory.
                        // This is done before saving the repair log so the saved parts JSON already includes cost details.
                        $existingPartsByHash = [];
                        $oldPartsStmt = $conn->prepare("SELECT repair_hash, parts_used_json FROM motorpool_repair_start_logs WHERE ris_id = ?");
                        if ($oldPartsStmt) {
                            $oldPartsStmt->bind_param('i', $ris_id);
                            $oldPartsStmt->execute();
                            $oldPartsResult = $oldPartsStmt->get_result();
                            while ($oldPartsRow = $oldPartsResult->fetch_assoc()) {
                                $decodedOldParts = json_decode((string) ($oldPartsRow['parts_used_json'] ?? '[]'), true);
                                $existingPartsByHash[(string) $oldPartsRow['repair_hash']] = is_array($decodedOldParts) ? $decodedOldParts : [];
                            }
                            $oldPartsStmt->close();
                        }

                        foreach ($mergedRepairProgress as &$inventoryRepairItem) {
                            if (empty($inventoryRepairItem['checked']))
                                continue;
                            $inventoryRepairDesc = trim((string) ($inventoryRepairItem['repair'] ?? ''));
                            if ($inventoryRepairDesc === '')
                                continue;
                            $isReworkReturnedInventory = !empty($inventoryRepairItem['rework_returned']) || !empty($inventoryRepairItem['skip_inventory_deduction']);
                            $inventoryHashSource = $inventoryRepairDesc;
                            if ($isReworkReturnedInventory) {
                                $inventoryHashSource .= '|rework|' . trim((string) ($inventoryRepairItem['rework_cycle_key'] ?? 'quality-return'));
                            }
                            $inventoryRepairHash = hash('sha256', mb_strtolower($inventoryHashSource));
                            if ($isReworkReturnedInventory) {
                                // Rework after Quality Check uses the same approved parts list.
                                // Do not deduct or return inventory again.
                                continue;
                            }
                            $oldPartsForInventory = $existingPartsByHash[$inventoryRepairHash] ?? [];
                            $isWithPartsForInventory = ((string) ($inventoryRepairItem['repair_type'] ?? 'labor') === 'with_parts');
                            $newPartsForInventory = $isWithPartsForInventory && is_array($inventoryRepairItem['parts_used'] ?? null) ? $inventoryRepairItem['parts_used'] : [];

                            // If this repair was previously saved with parts and is changed to labor only,
                            // the old parts are returned to inventory by sending an empty new parts list.
                            if ($isWithPartsForInventory || !empty($oldPartsForInventory)) {
                                $inventoryRepairItem['parts_used'] = motorpoolApplyInventoryDeltaForRepairParts(
                                    $conn,
                                    $ris_id,
                                    (string) $ris['ris_number'],
                                    $inventoryRepairHash,
                                    $inventoryRepairDesc,
                                    $newPartsForInventory,
                                    $oldPartsForInventory,
                                    $user_id
                                );
                            }
                        }
                        unset($inventoryRepairItem);

                        $pendingAfterMerge = 0;
                        $ongoingAfterMerge = 0;
                        $doneAfterMerge = 0;
                        $summaryLines = [];
                        $firstStartDate = '';
                        $firstMechanic = '';
                        foreach ($mergedRepairProgress as $mergedItem) {
                            $repairName = trim((string) ($mergedItem['repair'] ?? ''));
                            if ($repairName === '')
                                continue;
                            $stage = strtolower(trim((string) ($mergedItem['stage'] ?? '')));
                            if (!empty($mergedItem['done']) || $stage === 'done')
                                $doneAfterMerge++;
                            elseif (!empty($mergedItem['checked']) || $stage === 'ongoing')
                                $ongoingAfterMerge++;
                            else
                                $pendingAfterMerge++;
                            $startDate = trim((string) ($mergedItem['start_date'] ?? ''));
                            $startTime = trim((string) ($mergedItem['start_time'] ?? ''));
                            $mechanicName = trim((string) ($mergedItem['mechanic'] ?? ''));
                            if ($firstStartDate === '' && $startDate !== '')
                                $firstStartDate = $startDate;
                            if ($firstMechanic === '' && $mechanicName !== '')
                                $firstMechanic = $mechanicName;
                            $partsText = 'Labor only';
                            if ((string) ($mergedItem['repair_type'] ?? 'labor') === 'with_parts') {
                                $partLines = [];
                                foreach (($mergedItem['parts_used'] ?? []) as $part) {
                                    $usedQty = trim((string) ($part['used_quantity'] ?? ($part['qty_to_use'] ?? '')));
                                    if ($usedQty === '' || (float) $usedQty <= 0)
                                        continue;
                                    $partLines[] = 'Item No.: ' . ($part['item_no'] ?? '-') . ' | Qty Used: ' . $usedQty;
                                }
                                $partsText = !empty($partLines) ? implode('; ', $partLines) : 'With parts';
                            }
                            $summaryLines[] = 'Repair: ' . $repairName . ' | Type: ' . (((string) ($mergedItem['repair_type'] ?? 'labor') === 'with_parts') ? 'With Parts' : 'Labor Only') . ' | Start: ' . (($startDate !== '' || $startTime !== '') ? trim($startDate . ' ' . $startTime) : 'Not started') . ' | Mechanic: ' . ($mechanicName !== '' ? $mechanicName : '-') . ' | Status: ' . ucfirst(str_replace('_', ' ', $stage ?: 'for_repair')) . ' | Parts: ' . $partsText;
                        }
                        $normalized_json = json_encode($mergedRepairProgress, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                        $progressSummary = !empty($summaryLines) ? implode("\n", $summaryLines) : 'No repair log encoded yet.';
                        $allStarted = ($pendingAfterMerge === 0 && ($ongoingAfterMerge > 0 || $doneAfterMerge > 0));
                        $confirmed_complete = $allStarted ? 1 : 0;

                        $progressStmt = $conn->prepare("INSERT INTO motorpool_ris_repair_progress
                            (ris_id, ris_number, repair_progress_json, progress_summary, confirmed_complete, saved_by, saved_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE repair_progress_json = VALUES(repair_progress_json), progress_summary = VALUES(progress_summary), confirmed_complete = VALUES(confirmed_complete), saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
                        if (!$progressStmt)
                            throw new Exception('Failed to prepare repair log save: ' . $conn->error);
                        $progressStmt->bind_param('isssii', $ris_id, $ris['ris_number'], $normalized_json, $progressSummary, $confirmed_complete, $user_id);
                        if (!$progressStmt->execute())
                            throw new Exception('Failed to save repair log: ' . $progressStmt->error);
                        $progressStmt->close();

                        // Sync used motorpool inventory parts into orderproduct.php Sales Order tab.
                        syncRisPartsToSalesOrderForRequestHandler($conn, $ris, $mergedRepairProgress, $user_id);

                        $logStmt = $conn->prepare("INSERT INTO motorpool_repair_start_logs
                            (ris_id, ris_number, repair_hash, repair_description, repair_type, parts_used_json, start_datetime, mechanic, log_status, saved_by, saved_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ongoing', ?, NOW())
                            ON DUPLICATE KEY UPDATE repair_type = IF(log_status = 'done', repair_type, VALUES(repair_type)), parts_used_json = IF(log_status = 'done', parts_used_json, VALUES(parts_used_json)), start_datetime = IF(log_status = 'done', start_datetime, VALUES(start_datetime)), mechanic = IF(log_status = 'done', mechanic, VALUES(mechanic)), log_status = IF(log_status = 'done', 'done', 'ongoing'), saved_by = VALUES(saved_by), saved_at = NOW()");
                        if (!$logStmt)
                            throw new Exception('Failed to prepare repair start log: ' . $conn->error);
                        foreach ($mergedRepairProgress as $logItem) {
                            if (empty($logItem['checked']))
                                continue;
                            $repairDesc = trim((string) ($logItem['repair'] ?? ''));
                            if ($repairDesc === '')
                                continue;
                            $repairHashSource = $repairDesc;
                            if (!empty($logItem['rework_returned']) || !empty($logItem['skip_inventory_deduction'])) {
                                $repairHashSource .= '|rework|' . trim((string) ($logItem['rework_cycle_key'] ?? ('quality-return-' . $ris_id)));
                            }
                            $repairHash = hash('sha256', mb_strtolower($repairHashSource));
                            $repairTypeForLog = trim((string) ($logItem['repair_type'] ?? 'labor'));
                            if (!in_array($repairTypeForLog, ['labor', 'with_parts'], true))
                                $repairTypeForLog = 'labor';
                            $partsUsedForLog = json_encode($logItem['parts_used'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                            $startDateForLog = trim((string) ($logItem['start_date'] ?? ''));
                            $startTimeForLog = trim((string) ($logItem['start_time'] ?? ''));
                            $startDatetimeForLog = ($startDateForLog !== '' && $startTimeForLog !== '') ? ($startDateForLog . ' ' . $startTimeForLog . ':00') : null;
                            $mechanicForLog = trim((string) ($logItem['mechanic'] ?? ''));
                            $logStmt->bind_param('isssssssi', $ris_id, $ris['ris_number'], $repairHash, $repairDesc, $repairTypeForLog, $partsUsedForLog, $startDatetimeForLog, $mechanicForLog, $user_id);
                            if (!$logStmt->execute())
                                throw new Exception('Failed to save repair start log: ' . $logStmt->error);
                        }
                        $logStmt->close();

                        $nextStatus = 'Ongoing Repair';
                        $actionTaken = ($pendingAfterMerge > 0) ? 'Some repair logs started. Remaining repairs are still pending under the For Repair card.' : 'All available repair logs are started and moved to ongoing repair.';
                        $update = $conn->prepare("UPDATE motorpool_ris_requests
                            SET workflow_status = ?, status = ?, repair_start_date = COALESCE(NULLIF(?, ''), repair_start_date), mechanic = COALESCE(NULLIF(?, ''), mechanic), action_taken = ?
                            WHERE ris_id = ?");
                        if (!$update)
                            throw new Exception($conn->error);
                        $update->bind_param('sssssi', $nextStatus, $nextStatus, $firstStartDate, $firstMechanic, $actionTaken, $ris_id);
                        if (!$update->execute())
                            throw new Exception($update->error);
                        $update->close();

                        logRisWorkflowHistory($conn, $ris_id, 'For Repair', "Repair log saved.\n\n" . $progressSummary, '', $user_id);
                        if ($startedRepairs > 0) {
                            logRisWorkflowHistory($conn, $ris_id, 'Ongoing Repair', ($allStarted ? 'All repairs are now ongoing.' : 'Some repairs are now ongoing while remaining repairs stay under For Repair.') . "\n\n" . $progressSummary, '', $user_id);
                        }

                        $conn->commit();
                        $save_status = 'success';
                        $save_message = $allStarted
                            ? 'Repair log saved. All repairs are now under Ongoing Repair.'
                            : 'Repair log saved. Started repairs are under Ongoing Repair, while remaining repairs stay under For Repair.';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $save_status = 'error';
                        $save_message = $e->getMessage();
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ongoing_repair_workflow_v1') {
    $ris_id = (int) ($_POST['ongoing_repair_ris_id'] ?? 0);
    $submit_mode = trim((string) ($_POST['ongoing_repair_submit_mode'] ?? 'save'));
    $repair_progress_json = trim((string) ($_POST['ongoing_repair_progress_json'] ?? '[]'));
    $repair_progress = json_decode($repair_progress_json, true);
    if (!is_array($repair_progress))
        $repair_progress = [];
    $confirmed_complete = isset($_POST['ongoing_repair_confirmed']) ? 1 : 0;

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif (empty($repair_progress)) {
        $save_status = 'error';
        $save_message = 'Please review and save at least one ongoing repair log.';
    } else {
        $stmt = $conn->prepare("SELECT r.ris_id, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no, r.repair_start_date, r.mechanic, rp.repair_progress_json AS existing_repair_progress_json FROM motorpool_ris_requests r LEFT JOIN motorpool_ris_repair_progress rp ON rp.ris_id = r.ris_id WHERE r.ris_id = ? LIMIT 1");
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare ongoing repair progress: ' . $conn->error;
        } else {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $ris = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ris) {
                $save_status = 'error';
                $save_message = 'RIS record was not found.';
            } else {
                $existingProgress = buildMergedRepairRowsForRis($conn, $ris_id);

                $postedMap = [];
                $hasValidationError = false;
                $validationMessage = '';
                $completedNow = 0;

                foreach ($repair_progress as $postedItem) {
                    $repair = trim((string) ($postedItem['repair'] ?? ''));
                    if ($repair === '')
                        continue;
                    $postedStage = strtolower(trim((string) ($postedItem['stage'] ?? '')));
                    $done = !empty($postedItem['done']) || $postedStage === 'done' || !empty($postedItem['locked']);
                    $alreadyDone = !empty($postedItem['locked']);
                    $endDate = trim((string) ($postedItem['end_date'] ?? ($postedItem['completion_date'] ?? '')));
                    $endTime = trim((string) ($postedItem['end_time'] ?? ($postedItem['completion_time'] ?? '')));
                    $completionMechanic = trim((string) ($postedItem['completion_mechanic'] ?? ($postedItem['mechanic'] ?? '')));

                    if ($done && !$alreadyDone) {
                        if ($endDate === '') {
                            $hasValidationError = true;
                            $validationMessage = 'Please enter End Date for every repair marked as done.';
                            break;
                        }
                        if ($endTime === '') {
                            $hasValidationError = true;
                            $validationMessage = 'Please enter End Time for every repair marked as done.';
                            break;
                        }
                        if ($completionMechanic === '') {
                            $hasValidationError = true;
                            $validationMessage = 'Please enter Mechanic for every repair marked as done.';
                            break;
                        }
                        $completedNow++;
                    }

                    $postedMap[mb_strtolower($repair)] = [
                        'done' => $done ? 1 : 0,
                        'start_date' => trim((string) ($postedItem['start_date'] ?? '')),
                        'start_time' => trim((string) ($postedItem['start_time'] ?? '')),
                        'mechanic' => trim((string) ($postedItem['mechanic'] ?? '')),
                        'end_date' => $endDate,
                        'end_time' => $endTime,
                        'completion_date' => $endDate,
                        'completion_time' => $endTime,
                        'completion_mechanic' => $completionMechanic,
                        'repair_type' => trim((string) ($postedItem['repair_type'] ?? '')),
                        'parts_used' => is_array($postedItem['parts_used'] ?? null) ? $postedItem['parts_used'] : []
                    ];
                }

                if ($hasValidationError) {
                    $save_status = 'error';
                    $save_message = $validationMessage;
                } else {
                    $conn->begin_transaction();
                    try {
                        $mergedProgress = [];
                        $pendingRepairs = 0;
                        $ongoingRepairs = 0;
                        $doneRepairs = 0;
                        $summaryLines = [];
                        $lastEndDate = '';
                        $lastMechanic = '';

                        foreach ($existingProgress as $existingItem) {
                            $existingRepair = trim((string) ($existingItem['repair'] ?? ''));
                            if ($existingRepair === '')
                                continue;
                            $key = mb_strtolower($existingRepair);
                            $stage = strtolower((string) ($existingItem['stage'] ?? ''));
                            $isDoneBefore = !empty($existingItem['done']) || $stage === 'done';
                            $isStarted = !empty($existingItem['checked']) || $stage === 'ongoing' || $isDoneBefore;

                            if ($isDoneBefore) {
                                $existingItem['checked'] = 1;
                                $existingItem['done'] = 1;
                                $existingItem['stage'] = 'done';
                            } elseif (array_key_exists($key, $postedMap)) {
                                $posted = $postedMap[$key];
                                $existingItem['checked'] = 1;
                                if (!empty($posted['repair_type']) && in_array($posted['repair_type'], ['labor', 'with_parts'], true)) {
                                    $existingItem['repair_type'] = $posted['repair_type'];
                                }
                                if (!empty($posted['parts_used'])) {
                                    $existingItem['parts_used'] = normalizePartsUsedRows($posted['parts_used']);
                                }
                                if (trim((string) ($posted['start_date'] ?? '')) !== '') {
                                    $existingItem['start_date'] = $posted['start_date'];
                                }
                                if (trim((string) ($posted['start_time'] ?? '')) !== '') {
                                    $existingItem['start_time'] = $posted['start_time'];
                                }
                                if (trim((string) ($posted['mechanic'] ?? '')) !== '') {
                                    $existingItem['mechanic'] = $posted['mechanic'];
                                }
                                if (!empty($posted['done'])) {
                                    $existingItem['done'] = 1;
                                    $existingItem['stage'] = 'done';
                                    $existingItem['end_date'] = $posted['end_date'];
                                    $existingItem['end_time'] = $posted['end_time'];
                                    $existingItem['completion_date'] = $posted['completion_date'];
                                    $existingItem['completion_time'] = $posted['completion_time'];
                                    $existingItem['completion_mechanic'] = $posted['completion_mechanic'];
                                    $lastEndDate = $posted['end_date'];
                                    $lastMechanic = $posted['completion_mechanic'];
                                } else {
                                    $existingItem['done'] = 0;
                                    $existingItem['stage'] = 'ongoing';
                                }
                            } elseif (!$isStarted) {
                                $existingItem['checked'] = 0;
                                $existingItem['done'] = 0;
                                $existingItem['stage'] = 'for_repair';
                            }

                            $finalStage = strtolower((string) ($existingItem['stage'] ?? ''));
                            if ($finalStage === 'for_repair' || empty($existingItem['checked'])) {
                                $pendingRepairs++;
                            } elseif (!empty($existingItem['done']) || $finalStage === 'done') {
                                $doneRepairs++;
                            } else {
                                $ongoingRepairs++;
                            }

                            $typeText = ((string) ($existingItem['repair_type'] ?? 'labor') === 'with_parts') ? 'With Parts' : 'Labor Only';
                            $startText = trim((string) ($existingItem['start_date'] ?? '')) . ' ' . trim((string) ($existingItem['start_time'] ?? ''));
                            $endText = !empty($existingItem['done']) ? (trim((string) ($existingItem['end_date'] ?? $existingItem['completion_date'] ?? '')) . ' ' . trim((string) ($existingItem['end_time'] ?? $existingItem['completion_time'] ?? ''))) : '-';
                            $partsText = 'Labor only';
                            if ((string) ($existingItem['repair_type'] ?? 'labor') === 'with_parts') {
                                $partLines = [];
                                foreach (($existingItem['parts_used'] ?? []) as $part) {
                                    $usedQty = trim((string) ($part['used_quantity'] ?? ''));
                                    if ($usedQty === '' || (float) $usedQty <= 0)
                                        continue;
                                    $partLines[] = 'Item No.: ' . ($part['item_no'] ?? '-') . ' | Qty Used: ' . $usedQty;
                                }
                                $partsText = !empty($partLines) ? implode('; ', $partLines) : 'With parts';
                            }
                            $summaryLines[] = 'Repair: ' . $existingRepair . ' | Type: ' . $typeText . ' | Start: ' . (trim($startText) !== '' ? trim($startText) : '-') . ' | End: ' . (trim($endText) !== '' ? trim($endText) : '-') . ' | Mechanic: ' . (trim((string) ($existingItem['completion_mechanic'] ?? $existingItem['mechanic'] ?? '')) ?: '-') . ' | Status: ' . ucfirst(str_replace('_', ' ', $finalStage ?: 'ongoing')) . ' | Parts: ' . $partsText;

                            $mergedProgress[] = $existingItem;
                        }

                        if (empty($mergedProgress)) {
                            throw new Exception('No repair log was found for this RIS. Please start a repair first.');
                        }

                        $allCompleted = ($pendingRepairs === 0 && $ongoingRepairs === 0 && $doneRepairs > 0);
                        if ($submit_mode === 'proceed' && (!$confirmed_complete || !$allCompleted)) {
                            throw new Exception('Cannot proceed to For Release until all repairs under this RIS are marked as done.');
                        }

                        $finalConfirmed = ($allCompleted && $confirmed_complete) ? 1 : 0;
                        $normalizedOngoingJson = json_encode($mergedProgress, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                        $progressSummary = !empty($summaryLines) ? implode("
", $summaryLines) : 'No ongoing repair progress encoded yet.';
                        if ($pendingRepairs > 0)
                            $progressSummary .= "

Pending repairs still under For Repair: " . $pendingRepairs;
                        if ($ongoingRepairs > 0)
                            $progressSummary .= "
Ongoing repairs not yet done: " . $ongoingRepairs;
                        if ($doneRepairs > 0)
                            $progressSummary .= "
Completed repair logs: " . $doneRepairs;

                        $progressStmt = $conn->prepare("INSERT INTO motorpool_ris_repair_progress
                            (ris_id, ris_number, repair_progress_json, progress_summary, confirmed_complete, saved_by, saved_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE repair_progress_json = VALUES(repair_progress_json), progress_summary = VALUES(progress_summary), confirmed_complete = VALUES(confirmed_complete), saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
                        if (!$progressStmt)
                            throw new Exception('Failed to prepare ongoing repair progress save: ' . $conn->error);
                        $progressStmt->bind_param('isssii', $ris_id, $ris['ris_number'], $normalizedOngoingJson, $progressSummary, $finalConfirmed, $user_id);
                        if (!$progressStmt->execute())
                            throw new Exception('Failed to save ongoing repair progress: ' . $progressStmt->error);
                        $progressStmt->close();

                        $doneLogStmt = $conn->prepare("UPDATE motorpool_repair_start_logs SET end_datetime = ?, completion_mechanic = ?, log_status = 'done', saved_by = ?, saved_at = NOW() WHERE ris_id = ? AND repair_hash = ? AND log_status <> 'done'");
                        if ($doneLogStmt) {
                            foreach ($mergedProgress as $doneItem) {
                                if (empty($doneItem['done']) && (string) ($doneItem['stage'] ?? '') !== 'done')
                                    continue;
                                $doneRepair = trim((string) ($doneItem['repair'] ?? ''));
                                if ($doneRepair === '')
                                    continue;
                                $endDate = trim((string) ($doneItem['end_date'] ?? ($doneItem['completion_date'] ?? '')));
                                $endTime = trim((string) ($doneItem['end_time'] ?? ($doneItem['completion_time'] ?? '')));
                                if ($endDate === '' || $endTime === '')
                                    continue;
                                $endDateTime = $endDate . ' ' . $endTime . ':00';
                                $completionMechanic = trim((string) ($doneItem['completion_mechanic'] ?? ($doneItem['mechanic'] ?? '')));
                                $doneHashSource = $doneRepair;
                                if (!empty($doneItem['rework_returned']) || !empty($doneItem['skip_inventory_deduction'])) {
                                    $doneHashSource .= '|rework|' . trim((string) ($doneItem['rework_cycle_key'] ?? ('quality-return-' . $ris_id)));
                                }
                                $doneHash = hash('sha256', mb_strtolower($doneHashSource));
                                $doneLogStmt->bind_param('ssiis', $endDateTime, $completionMechanic, $user_id, $ris_id, $doneHash);
                                $doneLogStmt->execute();
                            }
                            $doneLogStmt->close();
                        }

                        if ($submit_mode === 'proceed' && $allCompleted) {
                            $forRelease = 'For Quality Check';
                            $done = $conn->prepare("UPDATE motorpool_ris_requests
                                SET workflow_status = ?, status = ?, repair_end_date = COALESCE(NULLIF(?, ''), repair_end_date), mechanic = COALESCE(NULLIF(?, ''), mechanic), action_taken = 'All repair logs completed and ready for quality check.'
                                WHERE ris_id = ?");
                            if (!$done)
                                throw new Exception($conn->error);
                            $done->bind_param('ssssi', $forRelease, $forRelease, $lastEndDate, $lastMechanic, $ris_id);
                            if (!$done->execute())
                                throw new Exception($done->error);
                            $done->close();

                            logRisWorkflowHistory($conn, $ris_id, 'Ongoing Repair', "All repair logs completed.

" . $progressSummary, '', $user_id);
                            logRisWorkflowHistory($conn, $ris_id, 'For Quality Check', 'All repairs are complete and ready for quality check.', '', $user_id);
                            $save_message = 'All repair logs completed. Request is now under For Quality Check.';
                        } else {
                            $stayStatus = 'Ongoing Repair';
                            $stayAction = ($pendingRepairs > 0) ? 'Ongoing repair log saved. Remaining repairs are still pending under the For Repair card.' : 'Ongoing repair log saved.';
                            $stay = $conn->prepare("UPDATE motorpool_ris_requests SET workflow_status = ?, status = ?, mechanic = COALESCE(NULLIF(?, ''), mechanic), action_taken = ? WHERE ris_id = ?");
                            if (!$stay)
                                throw new Exception($conn->error);
                            $stay->bind_param('ssssi', $stayStatus, $stayStatus, $lastMechanic, $stayAction, $ris_id);
                            if (!$stay->execute())
                                throw new Exception($stay->error);
                            $stay->close();

                            logRisWorkflowHistory($conn, $ris_id, 'Ongoing Repair', "Ongoing repair progress saved.

" . $progressSummary, '', $user_id);
                            $save_message = ($pendingRepairs > 0)
                                ? 'Ongoing repair log saved. Remaining unstarted repairs stayed under For Repair.'
                                : 'Ongoing repair log saved. The request is still under Ongoing Repair.';
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
        }
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return_quality_check_to_repair_v1') {
    $ris_id = (int) ($_POST['return_quality_ris_id'] ?? 0);
    $return_reason = trim((string) ($_POST['return_quality_reason'] ?? ''));

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif ($return_reason === '') {
        $save_status = 'error';
        $save_message = 'Return reason is required.';
    } else {
        $stmt = $conn->prepare("SELECT ris_id, ris_number, COALESCE(workflow_status, status) AS current_status FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare return to repair: ' . $conn->error;
        } else {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $ris = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ris) {
                $save_status = 'error';
                $save_message = 'RIS record was not found.';
            } elseif (strtolower(trim((string) ($ris['current_status'] ?? ''))) !== 'for quality check') {
                $save_status = 'error';
                $save_message = 'Only requests under For Quality Check can be returned to repair.';
            } else {
                $returnStatus = 'For Repair';
                $actionTaken = 'Returned from Quality Check for rework.';
                $conn->begin_transaction();
                try {
                    $up = $conn->prepare("UPDATE motorpool_ris_requests
                        SET workflow_status = ?,
                            status = ?,
                            action_taken = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE ris_id = ?");
                    if (!$up)
                        throw new Exception('Failed to prepare return update: ' . $conn->error);
                    $up->bind_param('sssi', $returnStatus, $returnStatus, $actionTaken, $ris_id);
                    if (!$up->execute())
                        throw new Exception('Failed to return to repair: ' . $up->error);
                    $up->close();

                    logRisWorkflowHistory($conn, $ris_id, 'For Quality Check', "Quality check returned the repair for rework.\n\nReason: " . $return_reason, '', $user_id);
                    logRisWorkflowHistory($conn, $ris_id, 'For Repair', "Returned from Quality Check for rework.\n\nReason: " . $return_reason, '', $user_id);

                    $conn->commit();
                    $save_status = 'success';
                    $save_message = 'Request returned to For Repair for rework.';
                } catch (Exception $e) {
                    $conn->rollback();
                    $save_status = 'error';
                    $save_message = $e->getMessage();
                }
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_quality_check_workflow_v1') {
    $ris_id = (int) ($_POST['quality_ris_id'] ?? 0);
    $quality_json = trim((string) ($_POST['quality_check_json'] ?? '[]'));
    $quality_items = json_decode($quality_json, true);
    if (!is_array($quality_items))
        $quality_items = [];
    $quality_date = trim((string) ($_POST['quality_check_date'] ?? date('Y-m-d')));
    $quality_time = trim((string) ($_POST['quality_check_time'] ?? date('H:i')));
    $quality_by = trim((string) ($_POST['quality_check_by'] ?? ''));
    $quality_remarks = trim((string) ($_POST['quality_remarks'] ?? ''));

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif ($quality_date === '' || $quality_time === '') {
        $save_status = 'error';
        $save_message = 'Please enter quality check date and time.';
    } elseif ($quality_by === '') {
        $save_status = 'error';
        $save_message = 'Please enter Quality Check By.';
    } else {
        $stmt = $conn->prepare("SELECT r.ris_id, r.ris_number, r.vehicle_db_id, r.vehicle_id, r.plate_no, rp.repair_progress_json FROM motorpool_ris_requests r LEFT JOIN motorpool_ris_repair_progress rp ON rp.ris_id = r.ris_id WHERE r.ris_id = ? LIMIT 1");
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare quality check: ' . $conn->error;
        } else {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $ris = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ris) {
                $save_status = 'error';
                $save_message = 'RIS record was not found.';
            } else {
                $progressRows = json_decode((string) ($ris['repair_progress_json'] ?? '[]'), true);
                if (!is_array($progressRows))
                    $progressRows = [];
                $doneRows = buildQualityCheckRowsForRis($conn, $ris_id);
                if (empty($doneRows)) {
                    foreach ($progressRows as $row) {
                        $repair = trim((string) ($row['repair'] ?? ''));
                        $stage = strtolower((string) ($row['stage'] ?? ''));
                        $hasEnd = trim((string) ($row['end_date'] ?? ($row['completion_date'] ?? ''))) !== '' || trim((string) ($row['end_time'] ?? ($row['completion_time'] ?? ''))) !== '';
                        if ($repair !== '' && (!empty($row['done']) || !empty($row['locked']) || $stage === 'done' || $hasEnd)) {
                            $row['done'] = 1;
                            $row['checked'] = 1;
                            $row['locked'] = 1;
                            $row['stage'] = 'done';
                            $doneRows[] = $row;
                        }
                    }
                }
                if (empty($doneRows) && !empty($quality_items)) {
                    foreach ($quality_items as $postedRow) {
                        $repair = trim((string) ($postedRow['repair'] ?? ''));
                        if ($repair !== '') {
                            $postedRow['done'] = 1;
                            $postedRow['checked'] = 1;
                            $postedRow['locked'] = 1;
                            $postedRow['stage'] = 'done';
                            $doneRows[] = $postedRow;
                        }
                    }
                }
                if (empty($doneRows)) {
                    $save_status = 'error';
                    $save_message = 'No completed repair log was found for quality check.';
                } else {
                    $summaryLines = [];
                    foreach ($doneRows as $row) {
                        $partsLines = [];
                        if ((string) ($row['repair_type'] ?? 'labor') === 'with_parts') {
                            foreach (($row['parts_used'] ?? []) as $part) {
                                $itemNo = trim((string) ($part['item_no'] ?? ''));
                                $qty = trim((string) ($part['used_quantity'] ?? ''));
                                if ($itemNo !== '' || $qty !== '')
                                    $partsLines[] = 'Item No.: ' . ($itemNo !== '' ? $itemNo : '-') . ' | Qty Used: ' . ($qty !== '' ? $qty : '0');
                            }
                        }
                        $summaryLines[] = 'Repair: ' . $row['repair'] . ' | Parts Used: ' . (!empty($partsLines) ? implode('; ', $partsLines) : 'None');
                    }
                    $quality_summary = "Quality Check Date/Time: " . $quality_date . ' ' . $quality_time . "\nQuality Check By: " . $quality_by . "\nRemarks: " . ($quality_remarks !== '' ? $quality_remarks : '-') . "\n\nCompleted Repairs Checked:\n" . implode("\n", $summaryLines);
                    $quality_datetime = $quality_date . ' ' . $quality_time . ':00';
                    $normalized_quality_json = json_encode($doneRows, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

                    $conn->begin_transaction();
                    try {
                        $q = $conn->prepare("INSERT INTO motorpool_ris_quality_checks
                            (ris_id, ris_number, quality_check_json, quality_summary, quality_check_by, quality_check_datetime, remarks, saved_by, saved_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE quality_check_json = VALUES(quality_check_json), quality_summary = VALUES(quality_summary), quality_check_by = VALUES(quality_check_by), quality_check_datetime = VALUES(quality_check_datetime), remarks = VALUES(remarks), saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
                        if (!$q)
                            throw new Exception('Failed to prepare quality check save: ' . $conn->error);
                        $q->bind_param('issssssi', $ris_id, $ris['ris_number'], $normalized_quality_json, $quality_summary, $quality_by, $quality_datetime, $quality_remarks, $user_id);
                        if (!$q->execute())
                            throw new Exception('Failed to save quality check: ' . $q->error);
                        $q->close();

                        $nextStatus = 'For Release';
                        $up = $conn->prepare("UPDATE motorpool_ris_requests
                            SET workflow_status = ?,
                                status = ?,
                                action_taken = 'Quality check completed. Ready for release.',
                                updated_at = CURRENT_TIMESTAMP
                            WHERE ris_id = ?");
                        if (!$up)
                            throw new Exception($conn->error);
                        $up->bind_param('ssi', $nextStatus, $nextStatus, $ris_id);
                        if (!$up->execute())
                            throw new Exception($up->error);
                        $up->close();

                        logRisWorkflowHistory($conn, $ris_id, 'For Quality Check', $quality_summary, '', $user_id);
                        logRisWorkflowHistory($conn, $ris_id, 'For Release', "Quality check completed. Vehicle is ready for release.

" . $quality_summary, '', $user_id);
                        $conn->commit();
                        $save_status = 'success';
                        $save_message = 'Quality check saved. Request is now under For Release.';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $save_status = 'error';
                        $save_message = $e->getMessage();
                    }
                }
            }
        }
    }
}


function buildReleaseDetailsFromRepairLogs(mysqli $conn, int $ris_id): array
{
    $details = [
        'mechanic' => '',
        'start_date' => '',
        'end_date' => '',
        'parts_replaced' => '',
        'repairs_done' => ''
    ];

    $mechanics = [];
    $repairs = [];
    $firstStart = '';
    $lastEnd = '';

    /*
     * IMPORTANT FIX:
     * Qty Used must come from ONE source only.
     * The same used parts can exist in:
     *  1) motorpool_repair_start_logs.parts_used_json
     *  2) motorpool_ris_repair_progress.repair_progress_json
     *  3) motorpool_ris_quality_checks.quality_check_json
     *
     * Previous versions summed all of them, so an actual Qty Used of 2 became
     * 6 in Vehicle Release because the same part was counted three times.
     *
     * Source priority for parts quantity:
     *  - repair_start_logs first, because this is the actual start repair log
     *  - repair_progress only if start logs have no parts
     *  - quality_check only if both above have no parts
     */
    $partsFromStartLogs = [];
    $partsFromProgress = [];
    $partsFromQuality = [];

    $addDateRange = function (string $startDate, string $endDate) use (&$firstStart, &$lastEnd): void {
        $startDate = trim(substr($startDate, 0, 10));
        $endDate = trim(substr($endDate, 0, 10));
        if ($startDate !== '' && ($firstStart === '' || $startDate < $firstStart))
            $firstStart = $startDate;
        if ($endDate !== '' && ($lastEnd === '' || $endDate > $lastEnd))
            $lastEnd = $endDate;
    };

    $addPartToMap = function (array &$map, array $part): void {
        $itemNo = trim((string) ($part['item_no'] ?? ($part['item_code'] ?? ($part['item_name'] ?? ($part['item'] ?? '')))));
        $qtyRaw = $part['used_quantity']
            ?? ($part['qty_used']
                ?? ($part['qty_to_use']
                    ?? ($part['quantity_to_use']
                        ?? ($part['quantity_used'] ?? ''))));

        // Do not use assessment/needed quantity as actual Qty Used.
        $qtyText = trim((string) $qtyRaw);
        if ($itemNo === '' && $qtyText === '')
            return;

        $qty = is_numeric($qtyText) ? (float) $qtyText : 0.0;
        if ($qty <= 0)
            return;

        $key = $itemNo !== '' ? strtolower($itemNo) : md5(json_encode($part));
        // Do not sum duplicated workflow copies of the same part.
        // The release modal must show the actual Qty Used entered for the part,
        // not the total of assessment/progress/quality records.
        $map[$key] = [
            'item_no' => $itemNo !== '' ? $itemNo : 'N/A',
            'quantity' => $qty
        ];
    };

    $partsSummaryFromMap = function (array $map): string {
        $lines = [];
        foreach ($map as $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            if ($qty <= 0)
                continue;
            $qtyText = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            $lines[] = 'Item No.: ' . (trim((string) ($row['item_no'] ?? '')) !== '' ? trim((string) $row['item_no']) : '-') . ' | Qty Used: ' . $qtyText;
        }
        return implode("\n", $lines);
    };

    if (tableExists($conn, 'motorpool_repair_start_logs')) {
        $stmt = $conn->prepare("SELECT repair_description, repair_type, parts_used_json, start_datetime, end_datetime, mechanic, completion_mechanic, log_status FROM motorpool_repair_start_logs WHERE ris_id = ? ORDER BY start_datetime ASC, log_id ASC");
        if ($stmt) {
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $repair = trim((string) ($row['repair_description'] ?? ''));
                if ($repair !== '')
                    $repairs[$repair] = true;

                $mechanic = trim((string) ($row['completion_mechanic'] ?? ''));
                if ($mechanic === '')
                    $mechanic = trim((string) ($row['mechanic'] ?? ''));
                if ($mechanic !== '')
                    $mechanics[$mechanic] = true;

                $addDateRange((string) ($row['start_datetime'] ?? ''), (string) ($row['end_datetime'] ?? ''));

                $partsRows = json_decode((string) ($row['parts_used_json'] ?? '[]'), true);
                if (is_array($partsRows)) {
                    foreach ($partsRows as $part) {
                        if (is_array($part))
                            $addPartToMap($partsFromStartLogs, $part);
                    }
                }
            }
            $stmt->close();
        }
    }

    if (tableExists($conn, 'motorpool_ris_repair_progress')) {
        $progressStmt = $conn->prepare("SELECT repair_progress_json FROM motorpool_ris_repair_progress WHERE ris_id = ? LIMIT 1");
        if ($progressStmt) {
            $progressStmt->bind_param('i', $ris_id);
            $progressStmt->execute();
            $progressRow = $progressStmt->get_result()->fetch_assoc();
            $progressStmt->close();
            $progressRows = json_decode((string) ($progressRow['repair_progress_json'] ?? '[]'), true);
            if (is_array($progressRows)) {
                foreach ($progressRows as $row) {
                    if (!is_array($row))
                        continue;
                    $stage = strtolower(trim((string) ($row['stage'] ?? '')));
                    $isDone = !empty($row['done']) || $stage === 'done' || !empty($row['locked']);
                    if (!$isDone)
                        continue;

                    $repair = trim((string) ($row['repair'] ?? ''));
                    if ($repair !== '')
                        $repairs[$repair] = true;

                    $mechanic = trim((string) ($row['completion_mechanic'] ?? ''));
                    if ($mechanic === '')
                        $mechanic = trim((string) ($row['mechanic'] ?? ''));
                    if ($mechanic !== '')
                        $mechanics[$mechanic] = true;

                    $addDateRange((string) ($row['start_date'] ?? ''), (string) ($row['end_date'] ?? ($row['completion_date'] ?? '')));

                    $partsRows = $row['parts_used'] ?? [];
                    if (is_array($partsRows)) {
                        foreach ($partsRows as $part) {
                            if (is_array($part))
                                $addPartToMap($partsFromProgress, $part);
                        }
                    }
                }
            }
        }
    }

    if (tableExists($conn, 'motorpool_ris_quality_checks')) {
        $qualityStmt = $conn->prepare("SELECT quality_check_json FROM motorpool_ris_quality_checks WHERE ris_id = ? LIMIT 1");
        if ($qualityStmt) {
            $qualityStmt->bind_param('i', $ris_id);
            $qualityStmt->execute();
            $qualityRow = $qualityStmt->get_result()->fetch_assoc();
            $qualityStmt->close();
            $qualityRows = json_decode((string) ($qualityRow['quality_check_json'] ?? '[]'), true);
            if (is_array($qualityRows)) {
                foreach ($qualityRows as $row) {
                    if (!is_array($row))
                        continue;
                    $repair = trim((string) ($row['repair'] ?? ''));
                    if ($repair !== '')
                        $repairs[$repair] = true;

                    $mechanic = trim((string) ($row['completion_mechanic'] ?? ''));
                    if ($mechanic === '')
                        $mechanic = trim((string) ($row['mechanic'] ?? ''));
                    if ($mechanic !== '')
                        $mechanics[$mechanic] = true;

                    $addDateRange((string) ($row['start_date'] ?? ''), (string) ($row['end_date'] ?? ($row['completion_date'] ?? '')));

                    $partsRows = $row['parts_used'] ?? [];
                    if (is_array($partsRows)) {
                        foreach ($partsRows as $part) {
                            if (is_array($part))
                                $addPartToMap($partsFromQuality, $part);
                        }
                    }
                }
            }
        }
    }

    $partsSummary = $partsSummaryFromMap($partsFromStartLogs);
    if ($partsSummary === '')
        $partsSummary = $partsSummaryFromMap($partsFromProgress);
    if ($partsSummary === '')
        $partsSummary = $partsSummaryFromMap($partsFromQuality);

    $details['mechanic'] = implode(', ', array_keys($mechanics));
    $details['start_date'] = $firstStart;
    $details['end_date'] = $lastEnd;
    $details['parts_replaced'] = $partsSummary !== '' ? $partsSummary : 'No parts replaced / used.';
    $details['repairs_done'] = !empty($repairs) ? implode("\n", array_keys($repairs)) : '';
    return $details;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_release_workflow_v15') {
    $ris_id = (int) ($_POST['release_ris_id'] ?? 0);
    $repair_date = safeDateOrNull((string) ($_POST['release_repair_date'] ?? date('Y-m-d')));
    $parts_replaced = trim((string) ($_POST['release_parts_replaced'] ?? ''));
    $mechanic = trim((string) ($_POST['release_mechanic'] ?? ''));
    $checked_received_by = trim((string) ($_POST['release_checked_received_by'] ?? ''));
    $received_date = trim((string) ($_POST['release_received_date'] ?? date('Y-m-d')));
    $received_time = trim((string) ($_POST['release_received_time'] ?? date('H:i')));
    $received_datetime = ($received_date !== '' && $received_time !== '') ? ($received_date . ' ' . $received_time . ':00') : '';
    if ($mechanic === '' && $ris_id > 0) {
        $mechanicLookup = $conn->prepare("SELECT mechanic, repair_progress_json FROM motorpool_ris_requests r LEFT JOIN motorpool_ris_repair_progress rp ON rp.ris_id = r.ris_id WHERE r.ris_id = ? LIMIT 1");
        if ($mechanicLookup) {
            $mechanicLookup->bind_param('i', $ris_id);
            $mechanicLookup->execute();
            $mechanicRow = $mechanicLookup->get_result()->fetch_assoc();
            $mechanicLookup->close();
            $mechanic = trim((string) ($mechanicRow['mechanic'] ?? ''));
            if ($mechanic === '') {
                $progressRows = json_decode((string) ($mechanicRow['repair_progress_json'] ?? '[]'), true);
                if (is_array($progressRows)) {
                    foreach ($progressRows as $progressRow) {
                        $candidateMechanic = trim((string) ($progressRow['mechanic'] ?? ''));
                        if ($candidateMechanic !== '') {
                            $mechanic = $candidateMechanic;
                            break;
                        }
                    }
                }
            }
        }
    }
    $start_date = safeDateOrNull((string) ($_POST['release_start_date'] ?? ''));
    $end_date = safeDateOrNull((string) ($_POST['release_end_date'] ?? date('Y-m-d')));

    if ($ris_id > 0) {
        $releaseAutoDetails = buildReleaseDetailsFromRepairLogs($conn, $ris_id);
        if ($parts_replaced === '')
            $parts_replaced = $releaseAutoDetails['parts_replaced'];
        if ($mechanic === '')
            $mechanic = $releaseAutoDetails['mechanic'];
        if ($start_date === null && $releaseAutoDetails['start_date'] !== '')
            $start_date = $releaseAutoDetails['start_date'];
        if ($end_date === null && $releaseAutoDetails['end_date'] !== '')
            $end_date = $releaseAutoDetails['end_date'];
    }

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } else {
        $uploadDir = '../uploads/motorpool';
        $releaseAttachment = uploadReleaseProof('release_attachment', $uploadDir);

        if ($repair_date === null || $parts_replaced === '' || $mechanic === '' || $start_date === null || $end_date === null || $checked_received_by === '' || $received_datetime === '' || $releaseAttachment === '') {
            $save_status = 'error';
            $save_message = 'Please complete all release fields, checked and received by, received date/time, and attach repair completion proof.';
        } else {
            $stmt = $conn->prepare("SELECT r.*, a.repairs_summary, a.parts_summary FROM motorpool_ris_requests r LEFT JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id WHERE r.ris_id = ? LIMIT 1");
            if (!$stmt) {
                $save_status = 'error';
                $save_message = 'Failed to prepare release record: ' . $conn->error;
            } else {
                $stmt->bind_param('i', $ris_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    $save_status = 'error';
                    $save_message = 'RIS record was not found.';
                } else {
                    try {
                        $conn->begin_transaction();

                        $repairsDone = trim((string) ($row['repairs_summary'] ?: ($row['repairs_done'] ?? '')));
                        $cost = (float) ($row['repair_cost'] ?? 0);

                        $historyIds = [];
                        $dup = $conn->prepare("SELECT repair_id FROM vehicle_repair_history WHERE ris_id = ? ORDER BY repair_id ASC");
                        if (!$dup)
                            throw new Exception('Failed to check repair history duplicates: ' . $conn->error);
                        $dup->bind_param('i', $ris_id);
                        $dup->execute();
                        $dupResult = $dup->get_result();
                        while ($dupRow = $dupResult->fetch_assoc()) {
                            $historyIds[] = (int) $dupRow['repair_id'];
                        }
                        $dup->close();

                        if (!empty($historyIds)) {
                            $keepRepairId = $historyIds[0];

                            if (count($historyIds) > 1) {
                                $cleanup = $conn->prepare("DELETE FROM vehicle_repair_history WHERE ris_id = ? AND repair_id <> ?");
                                if (!$cleanup)
                                    throw new Exception('Failed to prepare duplicate cleanup: ' . $conn->error);
                                $cleanup->bind_param('ii', $ris_id, $keepRepairId);
                                if (!$cleanup->execute())
                                    throw new Exception('Failed to remove duplicate repair history: ' . $cleanup->error);
                                $cleanup->close();
                            }

                            $history = $conn->prepare("UPDATE vehicle_repair_history SET ris_number=?, vehicle_db_id=?, vehicle_id=?, plate_no=?, repair_date=?, repairs_done=?, parts_replaced=?, mechanic=?, start_date=?, end_date=?, attachment=?, repair_cost=? WHERE repair_id=?");
                            if (!$history)
                                throw new Exception('Failed to prepare repair history update: ' . $conn->error);
                            $history->bind_param('sisssssssssdi', $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $repair_date, $repairsDone, $parts_replaced, $mechanic, $start_date, $end_date, $releaseAttachment, $cost, $keepRepairId);
                            if (!$history->execute())
                                throw new Exception('Failed to update repair history: ' . $history->error);
                            $history->close();
                        } else {
                            $history = $conn->prepare("INSERT INTO vehicle_repair_history (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, repair_date, repairs_done, parts_replaced, mechanic, start_date, end_date, attachment, repair_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if (!$history)
                                throw new Exception('Failed to prepare repair history: ' . $conn->error);
                            $history->bind_param('isisssssssssd', $ris_id, $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $repair_date, $repairsDone, $parts_replaced, $mechanic, $start_date, $end_date, $releaseAttachment, $cost);
                            if (!$history->execute())
                                throw new Exception('Failed to save repair history: ' . $history->error);
                            $history->close();
                        }

                        $release = $conn->prepare("INSERT INTO motorpool_repair_release_proofs (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, release_attachment, checked_received_by, received_datetime, released_by, released_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE release_attachment=VALUES(release_attachment), checked_received_by=VALUES(checked_received_by), received_datetime=VALUES(received_datetime), released_by=VALUES(released_by), released_at=NOW()");
                        if (!$release)
                            throw new Exception('Failed to prepare release proof: ' . $conn->error);
                        $release->bind_param('isisssssi', $ris_id, $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $releaseAttachment, $checked_received_by, $received_datetime, $user_id);
                        if (!$release->execute())
                            throw new Exception('Failed to save release proof: ' . $release->error);
                        $release->close();

                        $completed = 'Completed';
                        $done = $conn->prepare("UPDATE motorpool_ris_requests SET workflow_status=?, status=?, parts_replaced=?, mechanic=?, repair_start_date=?, repair_end_date=?, completed_by=?, completed_at=NOW(), action_taken='Repair completed and released to Branch Admin repair history.' WHERE ris_id=?");
                        if (!$done)
                            throw new Exception('Failed to prepare RIS completion: ' . $conn->error);
                        $done->bind_param('ssssssii', $completed, $completed, $parts_replaced, $mechanic, $start_date, $end_date, $user_id, $ris_id);
                        if (!$done->execute())
                            throw new Exception('Failed to complete RIS request: ' . $done->error);
                        $done->close();
                        $releaseDetails = 'Repair completed and released to Branch Admin repair history.' . "\n\nRepair Date: " . $repair_date . "\nParts Replaced: " . $parts_replaced . "\nMechanic: " . $mechanic . "\nStart Date: " . $start_date . "\nEnd Date: " . $end_date;
                        logRisWorkflowHistory($conn, $ris_id, 'For Release', $releaseDetails, $releaseAttachment, $user_id);

                        $conn->commit();
                        $save_status = 'success';
                        $save_message = 'Repair release completed. The record was added to Branch Admin repair history.';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $save_status = 'error';
                        $save_message = $e->getMessage();
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_parts_completion_v1') {
    $ris_id = (int) ($_POST['advance_ris_id'] ?? 0);
    $parts_available_json = trim($_POST['parts_completion_json'] ?? '[]');
    $parts_available = json_decode($parts_available_json, true);
    if (!is_array($parts_available))
        $parts_available = [];

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'Invalid RIS request.';
    } else {
        $previousRows = [];
        $prevStmt = $conn->prepare("SELECT parts_available_json FROM motorpool_ris_parts_completion WHERE ris_id = ? LIMIT 1");
        if ($prevStmt) {
            $prevStmt->bind_param('i', $ris_id);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStmt->close();
            $previousRows = json_decode((string) ($prevRow['parts_available_json'] ?? '[]'), true);
            if (!is_array($previousRows))
                $previousRows = [];
        }

        $parts_available = normalizePartsCompletionRows($conn, $ris_id, $parts_available, $previousRows);
        $confirmed_complete = partsCompletionAllComplete($parts_available) ? 1 : 0;
        $parts_available_json = json_encode($parts_available, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

        $summaryLines = [];
        foreach ($parts_available as $item) {
            $itemNo = trim((string) ($item['item_no'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $specification = trim((string) ($item['specification'] ?? ''));
            $neededQty = trim((string) ($item['needed_quantity'] ?? ''));
            $availableQty = trim((string) ($item['available_quantity'] ?? ''));
            $itemStatus = !empty($item['item_completed']) ? 'Complete / Locked' : 'Incomplete';
            $sourceLabel = trim((string) ($item['source_label'] ?? (($item['source_by'] ?? '') === 'branch' ? 'Branch Source' : 'Motorpool Source')));
            $motorpoolCost = trim((string) ($item['motorpool_billable_cost'] ?? $item['estimated_total_cost'] ?? '0.00'));
            if ($itemNo === '' && $description === '' && $specification === '' && $neededQty === '' && $availableQty === '')
                continue;
            $summaryLines[] = 'Item: ' . ($itemNo !== '' ? $itemNo : '-') . ' | Description: ' . ($description !== '' ? $description : '-') . ' | Specification: ' . ($specification !== '' ? $specification : '-') . ' | Needed Qty: ' . ($neededQty !== '' ? $neededQty : '-') . ' | Source: ' . ($sourceLabel !== '' ? $sourceLabel : '-') . ' | Motorpool Cost: PHP ' . ($motorpoolCost !== '' ? $motorpoolCost : '0.00') . ' | Available Qty: ' . ($availableQty !== '' ? $availableQty : '-') . ' | Status: ' . $itemStatus;
        }
        $completion_summary = !empty($summaryLines) ? implode("\n", $summaryLines) : 'No available parts quantity encoded yet.';

        $findRis = $conn->prepare("SELECT ris_number FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        $ris_number = '';
        if ($findRis) {
            $findRis->bind_param('i', $ris_id);
            $findRis->execute();
            $risRow = $findRis->get_result()->fetch_assoc();
            $ris_number = (string) ($risRow['ris_number'] ?? '');
            $findRis->close();
        }

        $stmt = $conn->prepare("INSERT INTO motorpool_ris_parts_completion
            (ris_id, ris_number, parts_available_json, completion_summary, confirmed_complete, saved_by, saved_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE parts_available_json = VALUES(parts_available_json), completion_summary = VALUES(completion_summary), confirmed_complete = VALUES(confirmed_complete), saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare parts completion save: ' . $conn->error;
        } else {
            $stmt->bind_param('isssii', $ris_id, $ris_number, $parts_available_json, $completion_summary, $confirmed_complete, $user_id);
            if ($stmt->execute()) {
                logRisWorkflowHistory($conn, $ris_id, 'For Parts Completion', "Parts availability saved. Completed items are now locked.\n\n" . $completion_summary, '', $user_id);
                $save_status = 'success';
                $save_message = $confirmed_complete ? 'All parts are complete and locked. You may now proceed to For Repair.' : 'Parts completion details saved. Complete items are locked, while incomplete items can still be edited.';
            } else {
                $save_status = 'error';
                $save_message = 'Failed to save parts completion details: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'advance_workflow_v3') {
    $ris_id = (int) ($_POST['advance_ris_id'] ?? 0);
    $next_status = trim($_POST['next_workflow_status'] ?? '');
    $allowed = ['For Parts Completion', 'For Repair', 'Ongoing Repair', 'For Quality Check', 'For Release'];
    if ($ris_id <= 0 || !in_array($next_status, $allowed, true)) {
        $save_status = 'error';
        $save_message = 'Invalid workflow action.';
    } else {
        $workflowDetails = 'Workflow advanced to ' . $next_status . '.';

        if ($next_status === 'For Repair') {
            $parts_available_json = trim($_POST['parts_completion_json'] ?? '[]');
            $parts_available = json_decode($parts_available_json, true);
            if (!is_array($parts_available))
                $parts_available = [];

            $previousRows = [];
            $prevStmt = $conn->prepare("SELECT parts_available_json FROM motorpool_ris_parts_completion WHERE ris_id = ? LIMIT 1");
            if ($prevStmt) {
                $prevStmt->bind_param('i', $ris_id);
                $prevStmt->execute();
                $prevRow = $prevStmt->get_result()->fetch_assoc();
                $prevStmt->close();
                $previousRows = json_decode((string) ($prevRow['parts_available_json'] ?? '[]'), true);
                if (!is_array($previousRows))
                    $previousRows = [];
            }

            $parts_available = normalizePartsCompletionRows($conn, $ris_id, $parts_available, $previousRows);
            if (!partsCompletionAllComplete($parts_available)) {
                $save_status = 'error';
                $save_message = 'Please complete all item quantities first. Available quantity must match the assessment quantity before proceeding to For Repair.';
            } else {
                $parts_available_json = json_encode($parts_available, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                $completionLines = [];
                foreach ($parts_available as $item) {
                    $itemNo = trim((string) ($item['item_no'] ?? ''));
                    $description = trim((string) ($item['description'] ?? ''));
                    $specification = trim((string) ($item['specification'] ?? ''));
                    $neededQty = trim((string) ($item['needed_quantity'] ?? ''));
                    $availableQty = trim((string) ($item['available_quantity'] ?? ''));
                    if ($itemNo === '' && $description === '' && $specification === '' && $neededQty === '' && $availableQty === '')
                        continue;
                    $completionLines[] = 'Item: ' . ($itemNo !== '' ? $itemNo : '-') . ' | Description: ' . ($description !== '' ? $description : '-') . ' | Specification: ' . ($specification !== '' ? $specification : '-') . ' | Needed Qty: ' . ($neededQty !== '' ? $neededQty : '-') . ' | Available Qty: ' . ($availableQty !== '' ? $availableQty : '-') . ' | Status: Complete / Locked';
                }
                $completionSummary = !empty($completionLines) ? implode("\n", $completionLines) : 'All listed parts confirmed complete.';
                $risNumberForCompletion = '';
                $findRisForCompletion = $conn->prepare("SELECT ris_number FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
                if ($findRisForCompletion) {
                    $findRisForCompletion->bind_param('i', $ris_id);
                    $findRisForCompletion->execute();
                    $risCompletionRow = $findRisForCompletion->get_result()->fetch_assoc();
                    $risNumberForCompletion = (string) ($risCompletionRow['ris_number'] ?? '');
                    $findRisForCompletion->close();
                }
                $completionStmt = $conn->prepare("INSERT INTO motorpool_ris_parts_completion
                    (ris_id, ris_number, parts_available_json, completion_summary, confirmed_complete, saved_by, saved_at)
                    VALUES (?, ?, ?, ?, 1, ?, NOW())
                    ON DUPLICATE KEY UPDATE parts_available_json = VALUES(parts_available_json), completion_summary = VALUES(completion_summary), confirmed_complete = 1, saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
                if ($completionStmt) {
                    $completionStmt->bind_param('isssi', $ris_id, $risNumberForCompletion, $parts_available_json, $completionSummary, $user_id);
                    $completionStmt->execute();
                    $completionStmt->close();
                }

                $assessmentStmt = $conn->prepare("SELECT repairs_summary, parts_summary FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
                if ($assessmentStmt) {
                    $assessmentStmt->bind_param('i', $ris_id);
                    $assessmentStmt->execute();
                    $assessmentRow = $assessmentStmt->get_result()->fetch_assoc();
                    $assessmentStmt->close();
                    if ($assessmentRow) {
                        $workflowDetails = "Parts have been verified complete. Vehicle is ready for repair.\n\nRepairs to Make:\n" . ($assessmentRow['repairs_summary'] ?? '') . "\n\nItems / Parts Needed:\n" . ($assessmentRow['parts_summary'] ?? '') . "\n\nAvailable / Received Parts:\n" . $completionSummary;
                    }
                }
            }
        }

        if ($save_status !== 'error') {
            if (setRisWorkflow($conn, $ris_id, $next_status)) {
                if ($next_status === 'For Repair') {
                    logRisWorkflowHistory($conn, $ris_id, 'For Parts Completion', $workflowDetails, '', $user_id);
                    logRisWorkflowHistory($conn, $ris_id, 'For Repair', 'Vehicle moved to repair stage after parts completion.', '', $user_id);
                } else {
                    logRisWorkflowHistory($conn, $ris_id, $next_status, $workflowDetails, '', $user_id);
                }
                $save_status = 'success';
                if ($next_status === 'For Release') {
                    $save_message = 'Workflow updated to For Release. Open the release modal to save the final repair history record.';
                } else {
                    $save_message = 'Workflow updated to ' . $next_status . '.';
                }
            } else {
                $save_status = 'error';
                $save_message = 'Failed to update workflow.';
            }
        }
    }
}

function workflowTabs(): array
{
    return ['For Vehicle Endorsement', 'For Assessment', 'For Approval', 'For Parts Completion', 'For Repair', 'Ongoing Repair', 'For Quality Check', 'For Release'];
}
function workflowBadge(string $status): string
{
    $map = [
        'For Vehicle Endorsement' => 'warning text-dark',
        'For Assessment' => 'info text-dark',
        'For Approval' => 'primary',
        'For Parts Completion' => 'secondary',
        'For Repair' => 'dark',
        'Ongoing Repair' => 'success',
        'For Quality Check' => 'info text-dark',
        'For Release' => 'success'
    ];
    return '<span class="badge bg-' . ($map[$status] ?? 'secondary') . '">' . h($status) . '</span>';
}
function nextActionHtml(array $r): string
{
    $status = $r['workflow_status'] ?: ($r['status'] ?? 'For Vehicle Endorsement');

    /*
     * Keep all request handler action buttons visually consistent.
     * Do not use btn-dark / btn-warning / btn-info here because those
     * override the Motorpool green palette and make some buttons black.
     */
    if ($status === 'For Vehicle Endorsement') {
        return '<div class="action-button-group">'
            . '<button type="button" class="btn btn-mp-action btn-sm btn-action-text" onclick="event.stopPropagation(); openReceiveVehicleModal(this.closest(\'tr\'))"><i class="bi bi-camera me-1"></i>Receive</button>'
            . '<button type="button" class="btn btn-mp-return btn-sm btn-action-text" onclick="event.stopPropagation(); openReturnVehicleModal(this.closest(\'tr\'))"><i class="bi bi-arrow-counterclockwise me-1"></i>Return</button>'
            . '</div>';
    }

    if ($status === 'For Assessment') {
        return '<button type="button" class="btn btn-mp-action btn-sm btn-action-text" onclick="event.stopPropagation(); openAssessmentModal(this.closest(\'tr\'))"><i class="bi bi-clipboard-plus me-1"></i>For Assessment</button>';
    }

    if ($status === 'For Approval') {
        return '<button type="button" class="btn btn-mp-action-outline btn-sm btn-action-text" disabled title="Waiting for Branch Admin approval"><i class="bi bi-hourglass-split me-1"></i>Waiting for Approval</button>';
    }

    if ($status === 'For Parts Completion') {
        return '<button type="button" class="btn btn-mp-action btn-sm btn-action-text" onclick="event.stopPropagation(); openPartsCompletionModalV4(this.closest(\'tr\'))"><i class="bi bi-box-seam me-1"></i>Parts Complete</button>';
    }

    if ($status === 'For Repair') {
        return '<button type="button" class="btn btn-mp-action btn-sm btn-action-text" onclick="event.stopPropagation(); openStartRepairModalV11(this.closest(\'tr\'))"><i class="bi bi-journal-plus me-1"></i>Log Repair</button>';
    }

    if ($status === 'Ongoing Repair') {
        return '<button type="button" class="btn btn-mp-action btn-sm btn-action-text" onclick="event.stopPropagation(); openOngoingRepairModalV1(this.closest(\'tr\'))"><i class="bi bi-check2-square me-1"></i>Repair Progress</button>';
    }

    if ($status === 'For Quality Check') {
        $ris = (int) ($r['ris_id'] ?? 0);
        return '<button type="button" class="btn btn-mp-action btn-sm btn-action-text js-quality-check-btn" data-ris-id="' . $ris . '"><i class="bi bi-patch-check me-1"></i>Quality Check</button>';
    }

    if ($status === 'For Release') {
        return '<button type="button" class="btn btn-mp-action btn-sm btn-action-text" onclick="event.stopPropagation(); openReleaseCompletionModalV15(this.closest(\'tr\'))"><i class="bi bi-truck me-1"></i>For Release</button>';
    }

    return '<button type="button" class="btn btn-mp-action-outline btn-sm btn-action-text" onclick="event.stopPropagation(); openRisDetailsModal(this.closest(\'tr\'))"><i class="bi bi-eye me-1"></i>View</button>';
}


function risDisplayBranchName(array $request): string
{
    $requestedRole = strtolower(trim((string) ($request['requested_by_role'] ?? '')));
    if ($requestedRole === 'motorpool') {
        return 'Motorpool Department';
    }
    $branchName = trim((string) ($request['branch_name'] ?? ''));
    if ($branchName !== '')
        return $branchName;
    $branchId = trim((string) ($request['branch_id'] ?? ''));
    return $branchId !== '' && $branchId !== '0' ? 'Branch #' . $branchId : 'N/A';
}

function fetchRisRequests(mysqli $conn): array
{
    $sql = "SELECT r.*, COALESCE(r.workflow_status, r.status, 'For Vehicle Endorsement') AS workflow_status,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS requested_by_name,
                   LOWER(TRIM(COALESCE(u.role, ''))) AS requested_by_role,
                   b.branch_name,
                   a.assessment_json, a.repairs_summary, a.parts_summary,
                   pc.parts_available_json, pc.completion_summary, pc.confirmed_complete,
                   rp.repair_progress_json, rp.progress_summary, rp.confirmed_complete AS repair_confirmed_complete,
                   qc.quality_check_json, qc.quality_summary, qc.quality_check_by, qc.quality_check_datetime, qc.remarks AS quality_remarks,
                   vr.receipt_id, vr.received_by_name, vr.received_datetime
            FROM motorpool_ris_requests r
            LEFT JOIN users u ON u.user_id = r.requested_by
            LEFT JOIN branches b ON b.branch_id = r.branch_id
            LEFT JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_parts_completion pc ON pc.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_repair_progress rp ON rp.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_quality_checks qc ON qc.ris_id = r.ris_id
            LEFT JOIN motorpool_vehicle_receipts vr ON vr.ris_id = r.ris_id
            WHERE COALESCE(r.workflow_status, r.status, '') <> 'Completed'
              AND r.completed_at IS NULL
            ORDER BY FIELD(COALESCE(r.workflow_status, r.status), 'For Vehicle Endorsement','For Assessment','For Approval','For Parts Completion','For Repair','Ongoing Repair','For Quality Check','For Release'), r.created_at DESC, r.ris_id DESC";
    $res = $conn->query($sql);
    $rows = [];
    if ($res)
        while ($row = $res->fetch_assoc())
            $rows[] = $row;
    return $rows;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_quality_check_modal_data_v1') {
    header('Content-Type: application/json; charset=utf-8');
    $ris_id = (int) ($_POST['ris_id'] ?? 0);
    if ($ris_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'RIS record was not found.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT r.*, COALESCE(r.workflow_status, r.status, 'For Vehicle Endorsement') AS workflow_status,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS requested_by_name,
                   LOWER(TRIM(COALESCE(u.role, ''))) AS requested_by_role,
                   b.branch_name,
                   a.assessment_json, a.repairs_summary, a.parts_summary,
                   pc.parts_available_json, pc.completion_summary, pc.confirmed_complete,
                   rp.repair_progress_json, rp.progress_summary, rp.confirmed_complete AS repair_confirmed_complete,
                   qc.quality_check_json, qc.quality_summary, qc.quality_check_by, qc.quality_check_datetime, qc.remarks AS quality_remarks,
                   vr.receipt_id, vr.received_by_name, vr.received_datetime
            FROM motorpool_ris_requests r
            LEFT JOIN users u ON u.user_id = r.requested_by
            LEFT JOIN branches b ON b.branch_id = r.branch_id
            LEFT JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_parts_completion pc ON pc.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_repair_progress rp ON rp.ris_id = r.ris_id
            LEFT JOIN motorpool_ris_quality_checks qc ON qc.ris_id = r.ris_id
            LEFT JOIN motorpool_vehicle_receipts vr ON vr.ris_id = r.ris_id
            WHERE r.ris_id = ?
            LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare Quality Check data: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('i', $ris_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'RIS record was not found.']);
        exit;
    }

    $row['quality_completed_rows_json'] = json_encode(buildQualityCheckRowsForRis($conn, $ris_id), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

$risRequests = fetchRisRequests($conn);
$displayRisRequests = [];
foreach ($risRequests as $request) {
    $displayRisRequests[] = $request;
    $status = $request['workflow_status'] ?: ($request['status'] ?? '');
    $progressRows = json_decode((string) ($request['repair_progress_json'] ?? '[]'), true);
    if (is_array($progressRows) && !empty($progressRows)) {
        $started = 0;
        $pending = 0;
        foreach ($progressRows as $progressRow) {
            if (trim((string) ($progressRow['repair'] ?? '')) === '')
                continue;
            $stage = strtolower(trim((string) ($progressRow['stage'] ?? '')));
            if (!empty($progressRow['checked']) || $stage === 'ongoing' || $stage === 'done')
                $started++;
            else
                $pending++;
        }
        if ($started > 0 && $pending > 0) {
            if ($status === 'For Repair') {
                $ongoingClone = $request;
                $ongoingClone['workflow_status'] = 'Ongoing Repair';
                $ongoingClone['status'] = 'Ongoing Repair';
                $ongoingClone['virtual_repair_stage'] = 'ongoing_started_repairs';
                $displayRisRequests[] = $ongoingClone;
            } elseif ($status === 'Ongoing Repair') {
                $forRepairClone = $request;
                $forRepairClone['workflow_status'] = 'For Repair';
                $forRepairClone['status'] = 'For Repair';
                $forRepairClone['virtual_repair_stage'] = 'pending_repairs_to_start';
                $displayRisRequests[] = $forRepairClone;
            }
        }
    }
}
$risRequests = $displayRisRequests;
$motorpoolInventoryOptions = fetchMotorpoolInventoryOptionsForRequestHandler($conn);
$counts = array_fill_keys(workflowTabs(), 0);
foreach ($risRequests as $r) {
    $s = $r['workflow_status'] ?: 'For Vehicle Endorsement';
    if (isset($counts[$s]))
        $counts[$s]++;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motorpool Request Handler</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/motorpoolv2.css">
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .form-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
        }

        .custom-table th {
            background: #052A47;
            color: #ffffff;
            white-space: nowrap;
        }

        .custom-table td {
            vertical-align: middle;
        }

        /* REQUEST HANDLER ACTION BUTTON FIX
   All buttons in the Action column use the Motorpool green palette.
   This removes the black/yellow/blue Bootstrap effect from request actions. */
        .btn-action-text,
        .btn-mp-action,
        .btn-mp-action-outline {
            white-space: nowrap !important;
            border-radius: 10px !important;
            font-weight: 600 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 4px !important;
            line-height: 1.2 !important;
        }

        .btn-mp-action {
            background: #047857 !important;
            border: 1px solid #047857 !important;
            color: #ffffff !important;
            box-shadow: none !important;
        }

        .btn-mp-action i {
            color: #ffffff !important;
        }

        .btn-mp-action:hover,
        .btn-mp-action:focus,
        .btn-mp-action:active {
            background: #059669 !important;
            border-color: #059669 !important;
            color: #ffffff !important;
            box-shadow: none !important;
        }

        .btn-mp-action-outline {
            background: #ecfdf5 !important;
            border: 1px solid #047857 !important;
            color: #047857 !important;
            box-shadow: none !important;
        }

        .btn-mp-action-outline i {
            color: #047857 !important;
        }

        .btn-mp-action-outline:hover,
        .btn-mp-action-outline:focus,
        .btn-mp-action-outline:active {
            background: #d1fae5 !important;
            border-color: #047857 !important;
            color: #047857 !important;
            box-shadow: none !important;
        }

        .btn-mp-action-outline:disabled,
        .btn-mp-action-outline.disabled {
            background: #f0fdf4 !important;
            border-color: #86efac !important;
            color: #047857 !important;
            opacity: 1 !important;
        }

        .btn-mp-return {
            background: #fff7ed !important;
            border: 1px solid #ea580c !important;
            color: #9a3412 !important;
            box-shadow: none !important;
        }

        .btn-mp-return i {
            color: #9a3412 !important;
        }

        .btn-mp-return:hover,
        .btn-mp-return:focus,
        .btn-mp-return:active {
            background: #ffedd5 !important;
            border-color: #c2410c !important;
            color: #9a3412 !important;
            box-shadow: none !important;
        }

        .action-button-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: nowrap;
            white-space: nowrap;
        }

        /* Safety override for old Bootstrap action button classes inside this page */
        .workflow-status-table-wrap .btn-action-text.btn-dark,
        .workflow-status-table-wrap .btn-action-text.btn-warning,
        .workflow-status-table-wrap .btn-action-text.btn-info,
        .workflow-status-table-wrap .btn-action-text.btn-primary,
        .workflow-status-table-wrap .btn-action-text.btn-success {
            background: #047857 !important;
            border-color: #047857 !important;
            color: #ffffff !important;
        }

        .workflow-status-table-wrap .btn-action-text.btn-outline-primary,
        .workflow-status-table-wrap .btn-action-text.btn-outline-success {
            background: #ecfdf5 !important;
            border-color: #047857 !important;
            color: #047857 !important;
        }

        .workflow-status-table-wrap .btn-action-text.text-dark {
            color: #ffffff !important;
        }

        .workflow-status-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 16px;
        }

        .workflow-status-card {
            background: #ffffff;
            border: 1px solid #d1fae5;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(5, 42, 71, .06);
        }

        .workflow-status-header {
            width: 100%;
            border: 0;
            background: #ffffff;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            text-align: left;
            color: #052A47;
        }

        .workflow-status-header:hover {
            background: #ecfdf5;
        }

        .workflow-status-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .workflow-status-count-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: auto;
        }

        .workflow-status-count {
            min-width: 42px;
            height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            background: #d1fae5;
            color: #047857;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.05rem;
        }

        .workflow-status-header .toggle-icon {
            color: #047857;
            font-size: 1.15rem;
            transition: transform .2s ease;
        }

        .workflow-status-header[aria-expanded="true"] .toggle-icon {
            transform: rotate(180deg);
        }

        .workflow-status-body {
            border-top: 1px solid #e3e8ef;
            padding: 0;
        }

        .workflow-status-table-wrap {
            padding: 14px;
        }

        .workflow-status-table-wrap .table-responsive {
            border-radius: 10px;
            overflow-x: hidden;
            overflow-y: visible;
        }

        .workflow-status-table-wrap .custom-table {
            margin-bottom: 0;
        }

        .workflow-status-table-wrap .workflow-ris-table {
            width: 100%;
            table-layout: fixed;
        }

        .workflow-status-table-wrap .workflow-ris-table th,
        .workflow-status-table-wrap .workflow-ris-table td {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }

        .workflow-status-table-wrap .workflow-ris-table th {
            font-size: .82rem;
            line-height: 1.25;
        }

        .workflow-status-table-wrap .workflow-ris-table td {
            font-size: .86rem;
            line-height: 1.35;
        }

        .workflow-status-table-wrap .workflow-ris-table .ris-number-cell {
            width: 15%;
            min-width: 190px;
            white-space: nowrap !important;
            overflow-wrap: normal !important;
            word-break: normal !important;
        }

        .workflow-status-table-wrap .workflow-ris-table .date-cell {
            width: 11%;
        }

        .workflow-status-table-wrap .workflow-ris-table .branch-cell {
            width: 12%;
        }

        .workflow-status-table-wrap .workflow-ris-table .plate-cell {
            width: 12%;
        }

        .workflow-status-table-wrap .workflow-ris-table .vehicle-cell {
            width: 14%;
        }

        .workflow-status-table-wrap .workflow-ris-table .concern-col {
            width: 17%;
        }

        .workflow-status-table-wrap .workflow-ris-table .action-col {
            width: 19%;
        }

        .workflow-status-table-wrap .workflow-ris-table td.ris-number-cell,
        .workflow-status-table-wrap .workflow-ris-table th.ris-number-cell,
        .workflow-status-table-wrap .workflow-ris-table .ris-number-cell strong {
            white-space: nowrap !important;
            overflow-wrap: normal !important;
            word-break: normal !important;
        }

        .workflow-status-table-wrap .concern-cell {
            max-width: 100%;
            white-space: normal !important;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.4;
        }

        .workflow-status-table-wrap .action-cell {
            white-space: nowrap !important;
            overflow-wrap: normal !important;
            word-break: normal !important;
        }

        .workflow-status-table-wrap .action-cell .btn,
        .workflow-status-table-wrap .action-cell .btn-action-text,
        .workflow-status-table-wrap .action-cell .btn-mp-action,
        .workflow-status-table-wrap .action-cell .btn-mp-action-outline,
        .workflow-status-table-wrap .action-cell .btn-mp-return {
            max-width: 100%;
        }

        @media (max-width: 992px) {
            .workflow-status-table-wrap {
                padding: 10px;
            }

            .workflow-status-table-wrap .workflow-ris-table th,
            .workflow-status-table-wrap .workflow-ris-table td {
                font-size: .78rem;
                padding-left: 6px;
                padding-right: 6px;
            }

            .workflow-status-table-wrap .btn-action-text,
            .workflow-status-table-wrap .btn-mp-action,
            .workflow-status-table-wrap .btn-mp-action-outline {
                font-size: .72rem !important;
                padding: 6px 8px !important;
            }
        }

        .ris-row {
            cursor: pointer;
        }

        .ris-row:hover td {
            background: #f4fbf6;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            column-gap: 24px;
            row-gap: 8px;
        }

        .info-item {
            border-bottom: 1px solid #eef2f6;
            padding: 7px 0;
        }

        .info-item small {
            display: block;
            color: #6c757d;
            font-size: .85rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .info-item strong {
            display: block;
            color: #212529;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .section-title {
            font-weight: 700;
            color: #052A47;
            border-bottom: 2px solid #07b83f;
            display: inline-block;
            padding-bottom: 5px;
            margin: 10px 0 12px;
        }

        .required-mark {
            color: #dc3545;
        }

        .empty-state {
            padding: 34px 20px;
            text-align: center;
            color: #64748b;
        }

        .modal-header.motorpool-green {
            background: #07b83f;
            color: #ffffff;
            border-bottom: 0;
        }

        .modal-header.motorpool-green .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        .motorpool-modal .modal-content {
            max-height: 94vh;
            display: flex;
            flex-direction: column;
        }

        .motorpool-modal form {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .motorpool-modal .modal-body {
            overflow-y: auto;
            min-height: 0;
        }

        .repair-block {
            border: 1px solid #e3e8ef;
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
            background: #ffffff;
        }


        /* Wider assessment modal so Items / Parts Needed table is visible without inner table scroll */
        .assessment-modal-xl {
            width: 96vw !important;
            max-width: 96vw !important;
        }

        .assessment-modal-xl .modal-content {
            min-height: 88vh;
            max-height: 94vh;
        }

        .assessment-modal-xl .modal-body {
            overflow-y: auto;
            overflow-x: hidden;
        }

        .assessment-modal-xl .assessment-parts-table-wrapper {
            overflow-x: visible !important;
            overflow-y: visible !important;
            max-height: none !important;
        }

        .assessment-modal-xl .assessment-parts-table-wide {
            min-width: 100%;
            table-layout: auto;
        }

        .assessment-modal-xl .assessment-parts-table-wide th,
        .assessment-modal-xl .assessment-parts-table-wide td {
            white-space: nowrap;
        }

        @media (max-width: 1200px) {
            .assessment-modal-xl {
                width: auto !important;
                max-width: none !important;
            }

            .assessment-modal-xl .assessment-parts-table-wrapper {
                overflow-x: auto !important;
            }
        }

        .parts-table th {
            background: #087f5b !important;
            color: #ffffff !important;
            white-space: nowrap;
        }

        .parts-table td {
            background: #ffffff;
        }

        .parts-table tr:nth-child(even) td {
            background: #e9fbf2;
        }

        .parts-table .btn-outline-danger {
            border-radius: 8px;
            padding: 6px 10px;
        }

        .assessment-detail-wrap {
            background: #f8fafc;
            border: 1px solid #e3e8ef;
            border-radius: 12px;
            padding: 12px;
        }

        .assessment-repair-card {
            border: 1px solid #e3e8ef;
            border-radius: 12px;
            background: #ffffff;
            padding: 12px;
            margin-bottom: 12px;
        }

        .assessment-repair-card:last-child {
            margin-bottom: 0;
        }

        .assessment-repair-title {
            font-weight: 800;
            color: #052A47;
            margin-bottom: 8px;
        }

        .assessment-repair-text {
            background: #f8fafc;
            border: 1px solid #e3e8ef;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 10px;
            color: #0f172a;
        }

        .assessment-parts-table th {
            background: #087f5b !important;
            color: #ffffff !important;
            white-space: nowrap;
            font-size: .85rem;
        }

        .assessment-parts-table td {
            vertical-align: middle;
            background: #ffffff;
        }

        .assessment-parts-table tbody tr:nth-child(even) td {
            background: #e9fbf2;
        }

        .add-part-row-wrap {
            display: flex;
            justify-content: flex-end;
            margin-top: 8px;
        }

        .parts-completion-summary {
            background: #f8fafc;
            border: 1px solid #e3e8ef;
            border-radius: 12px;
            padding: 12px;
        }

        .parts-completion-repair-title {
            font-weight: 700;
            color: #052A47;
            margin: 10px 0 8px;
        }

        .parts-completion-table th {
            background: #087f5b !important;
            color: #ffffff !important;
            white-space: nowrap;
        }

        .parts-completion-table td {
            vertical-align: middle;
        }

        .repair-progress-table th {
            background: #087f5b !important;
            color: #ffffff !important;
            white-space: nowrap;
        }

        .repair-progress-table td {
            vertical-align: middle;
        }

        .repair-progress-table tbody tr:nth-child(even) td {
            background: #e9fbf2;
        }

        /* Center only the Repairs To Make table inside the Ongoing Repair modal */
        #ongoingRepairModalV1 .ongoing-repairs-to-make-table th,
        #ongoingRepairModalV1 .ongoing-repairs-to-make-table td {
            text-align: center !important;
            vertical-align: middle !important;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .repair-to-make-cell {
            min-width: 230px;
            padding-left: 10px !important;
            padding-right: 10px !important;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .form-control,
        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .form-select {
            text-align: center !important;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .repair-check-cell {
            padding-left: 12px !important;
            padding-right: 12px !important;
        }


        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .ongoing-parts-used-list {
            min-width: 360px;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .ongoing-parts-used-row {
            background: #f8fafc;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .ongoing-parts-used-row:last-child {
            margin-bottom: 0 !important;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .ongoing-parts-used-row .form-label {
            display: block;
            text-align: center;
            font-weight: 700;
            color: #052A47;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .ongoing-parts-used-row .form-control {
            text-align: center !important;
            background: #ffffff;
        }


        #ongoingRepairModalV1 .ongoing-part-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 5px 8px;
            margin: 2px;
            border: 1px solid #d8e2ea;
            border-radius: 8px;
            background: #ffffff;
            font-size: 13px;
            color: #052A47;
        }

        #ongoingRepairModalV1 .ongoing-repairs-to-make-table .form-control[readonly] {
            background: #f3f6f8;
        }

        .repair-progress-table .repair-to-make-cell {
            min-width: 320px;
            padding-left: 16px;
        }

        .repair-progress-table .repair-check-cell {
            width: 140px;
            padding: 12px 28px !important;
        }

        .repair-check-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .repair-check-wrap .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            cursor: pointer;
        }

        .repair-progress-wrap {
            background: #f8fafc;
            border: 1px solid #e3e8ef;
            border-radius: 12px;
            padding: 12px;
        }

        .repair-completion-progress .progress {
            height: 22px;
            border-radius: 999px;
            overflow: hidden;
        }

        .repair-completion-progress .progress-bar {
            font-weight: 800;
        }

        .vehicle-received-proof-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .vehicle-received-proof-preview-grid img {
            width: 100%;
            height: 110px;
            object-fit: cover;
            border-radius: 9px;
            border: 1px solid #e3e8ef;
        }

        @media (max-width: 992px) {
            .info-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 576px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .workflow-status-header {
                padding: 15px 14px;
            }

            .workflow-status-title {
                font-size: .95rem;
            }
        }

        .quality-check-table th,
        .quality-check-table td {
            text-align: center;
            vertical-align: middle !important;
        }

        .quality-check-table .ongoing-parts-used-row {
            text-align: center;
        }

        .quality-check-table .form-control[readonly] {
            text-align: center;
        }


        /* v32: cost fields display only, no layout-breaking inputs */
        .assessment-parts-table th,
        .assessment-parts-table td,
        .parts-table th,
        .parts-table td {
            text-align: center;
            vertical-align: middle;
        }

        /* Clean total summary display.
   This removes the table-style border lines from TOTAL ESTIMATED COST rows. */
        .assessment-total-row,
        .assessment-total-row td,
        .assessment-total-label,
        .assessment-total-amount {
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
        }

        .assessment-total-row td {
            background: #eef8f0 !important;
        }

        .assessment-total-label,
        .assessment-total-amount {
            color: #047857 !important;
            font-weight: 700 !important;
        }

        /* New non-table total bar used by the assessment parts table */
        .assessment-total-bar-v80 {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 28px !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 16px 20px !important;
            background: #eef8f0 !important;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
        }

        .assessment-total-bar-v80,
        .assessment-total-bar-v80 * {
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
        }

        .assessment-total-bar-label-v80 {
            color: #047857 !important;
            font-weight: 800 !important;
            font-size: 1rem !important;
            text-align: right !important;
            text-transform: uppercase !important;
        }

        .assessment-total-bar-amount-v80 {
            color: #047857 !important;
            font-weight: 900 !important;
            font-size: 1.1rem !important;
            min-width: 170px !important;
            text-align: center !important;
            white-space: nowrap !important;
        }

        /* Bootstrap/table override for total areas */
        .assessment-parts-table tfoot,
        .assessment-parts-table tfoot tr,
        .assessment-parts-table tfoot td {
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
        }

        .parts-completion-readonly-text {
            display: inline-block;
            min-height: 24px;
            line-height: 24px;
            color: #052A47;
        }

        #partsCompletionAvailableBodyV4 .parts-completion-available {
            width: 86px !important;
            max-width: 86px !important;
            height: 34px !important;
            min-height: 34px !important;
            padding: 4px 8px !important;
            margin: 0 auto;
            text-align: center;
        }

        #partsCompletionAvailableBodyV4 td {
            padding-top: 10px !important;
            padding-bottom: 10px !important;
            vertical-align: middle !important;
        }

        #partsCompletionAvailableBodyV4 .parts-completion-available-wrap {
            min-height: 34px;
            align-items: center !important;
        }

        #partsCompletionAvailableBodyV4 .badge {
            white-space: nowrap;
        }

        #risDetailsModalV4 .modal-footer {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 10px !important;
            flex-wrap: nowrap !important;
        }

        .approval-proof-inline-v64 {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 8px !important;
            flex-wrap: nowrap !important;
            width: auto !important;
            flex: 0 0 auto !important;
            margin-left: auto !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
        }

        .approval-proof-inline-v64.d-none {
            display: none !important;
        }

        .approval-proof-inline-v64 input[type="file"] {
            width: 230px !important;
            max-width: 230px !important;
            min-width: 230px !important;
            height: 38px !important;
            flex: 0 0 230px !important;
        }

        .approval-proof-inline-v64 .btn {
            height: 38px !important;
            white-space: nowrap !important;
        }

        @media (max-width: 768px) {
            #risDetailsModalV4 .modal-footer {
                flex-wrap: wrap !important;
            }

            .approval-proof-inline-v64 {
                width: 100% !important;
                flex-wrap: nowrap !important;
                margin-left: 0 !important;
                justify-content: flex-end !important;
            }

            .approval-proof-inline-v64 input[type="file"] {
                width: 180px !important;
                max-width: 180px !important;
                min-width: 180px !important;
            }
        }


        .repair-total-footer-v53,
        .assessment-grand-total-v52 {
            margin-top: 12px;
            background: #f1fdf5;
            border: 1px solid #bbf7d0;
            border-top: 2px solid #047857;
            border-radius: 0 0 12px 12px;
            padding: 12px 16px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            color: #047857;
        }

        .repair-total-footer-v53 .repair-total-label {
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .repair-total-footer-v53 strong,
        .assessment-grand-total-v52 strong {
            color: #047857;
            font-size: 1.05rem;
        }


        /* FINAL FIX: RIS Details approval proof footer must stay in one compact line */
        #risDetailsModalV4 .modal-footer.approval-proof-footer-v70 {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 10px !important;
            flex-wrap: nowrap !important;
            min-height: 64px !important;
            height: auto !important;
            padding: 12px 16px !important;
        }

        #approvalProofFormV64.approval-proof-inline-v70 {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 8px !important;
            flex-wrap: nowrap !important;
            width: auto !important;
            max-width: none !important;
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            flex: 0 0 auto !important;
        }

        #approvalProofFormV64.approval-proof-inline-v70.d-none {
            display: none !important;
        }

        #approvalProofFileV64.approval-proof-file-v70 {
            display: block !important;
            width: 260px !important;
            min-width: 260px !important;
            max-width: 260px !important;
            height: 38px !important;
            min-height: 38px !important;
            max-height: 38px !important;
            line-height: 1.2 !important;
            padding: 6px 10px !important;
            margin: 0 !important;
            flex: 0 0 260px !important;
            box-sizing: border-box !important;
        }

        #approvalProofUploadBtnV70,
        #risDetailsCloseBtnV70 {
            height: 38px !important;
            min-height: 38px !important;
            white-space: nowrap !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 !important;
            flex: 0 0 auto !important;
        }

        @media (max-width:768px) {
            #risDetailsModalV4 .modal-footer.approval-proof-footer-v70 {
                flex-wrap: wrap !important;
            }

            #approvalProofFormV64.approval-proof-inline-v70 {
                width: 100% !important;
                justify-content: flex-end !important;
            }

            #approvalProofFileV64.approval-proof-file-v70 {
                width: 190px !important;
                min-width: 190px !important;
                max-width: 190px !important;
                flex: 0 0 190px !important;
            }
        }



        /* Repair Log compact card layout */
        .repair-log-card-list {
            display: block;
        }

        .repair-log-card {
            background: #fff;
            border-color: #d8e1ea !important;
        }

        .repair-log-labor-section {
            background: #f8fafc !important;
        }

        .repair-log-part-row .form-control-sm {
            min-height: 34px;
            height: 34px;
        }

        .repair-log-part-row small {
            font-size: .78rem;
        }

        .repair-log-part-row .row {
            align-items: flex-start !important;
        }

        .repair-log-part-row .form-label {
            height: 18px;
            display: flex;
            align-items: center;
            margin-bottom: 4px !important;
        }

        .repair-log-part-row .start-repair-part-used {
            width: 100%;
        }

        .repair-log-part-row .start-repair-qty-col {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }


        /* ===== RESPONSIVE LAYOUT + MOBILE NAVBAR (same behavior as branchdashboard) ===== */

        .mobile-menu-btn {
            display: none;
            background: #ffffff;
            border: 1px solid #dbeafe;
            border-radius: 12px;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            font-size: 1.45rem;
            color: #052A47;
            box-shadow: 0 2px 8px rgba(5, 42, 71, .08);
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            z-index: 1040;
            opacity: 0;
            visibility: hidden;
            transition: opacity .2s ease, visibility .2s ease;
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
            box-shadow: 0 -2px 12px rgba(0, 0, 0, .08);
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
            box-shadow: 0 4px 20px rgba(0, 0, 0, .15);
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

        .mobile-nav .dropdown-item:last-child {
            border-bottom: none;
        }

        .mobile-nav .dropdown-item:hover {
            background: #f9fafb;
        }

        .mobile-nav .dropdown-item.active {
            background: rgba(5, 150, 105, .1);
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

        @media (max-width: 992px) {
            body {
                overflow-x: hidden;
            }

            .mobile-menu-btn {
                display: inline-flex;
            }

            .mobile-toggle-btn {
                display: none !important;
            }

            .mobile-nav {
                display: block;
            }

            #appPage {
                min-height: 100vh;
            }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 14px !important;
                padding-bottom: 92px !important;
            }

            .sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                height: 100vh !important;
                z-index: 1050 !important;
                transform: translateX(-105%);
                transition: transform .25s ease;
                box-shadow: 12px 0 30px rgba(15, 23, 42, .18);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar.collapsed {
                transform: translateX(-105%) !important;
            }

            .form-card {
                padding: 14px;
                border-radius: 14px;
            }

            .workflow-status-header {
                padding: 14px;
                align-items: flex-start;
            }

            .workflow-status-title {
                font-size: .95rem;
            }

            .workflow-status-count-wrap {
                gap: 8px;
            }

            .workflow-status-table-wrap {
                padding: 10px;
            }

            .workflow-status-table-wrap .table-responsive {
                overflow: visible !important;
                border-radius: 0;
            }

            .workflow-status-table-wrap .workflow-ris-table,
            .workflow-status-table-wrap .workflow-ris-table tbody,
            .workflow-status-table-wrap .workflow-ris-table tr,
            .workflow-status-table-wrap .workflow-ris-table td {
                display: block;
                width: 100%;
            }

            .workflow-status-table-wrap .workflow-ris-table {
                min-width: 0 !important;
                border-collapse: separate;
                border-spacing: 0;
                table-layout: fixed;
            }

            .workflow-status-table-wrap .workflow-ris-table thead {
                display: none;
            }

            .workflow-status-table-wrap .workflow-ris-table tbody {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row {
                position: relative;
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                grid-template-areas:
                    "ris date"
                    "plate vehicle"
                    "branch branch"
                    "actions actions";
                gap: 8px 12px;
                padding: 14px;
                border: 1px solid #d1fae5;
                border-radius: 16px;
                background: #ffffff;
                box-shadow: 0 8px 22px rgba(15, 23, 42, .08);
                cursor: pointer;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row:hover {
                background: #f8fffb;
                border-color: #10b981;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td {
                padding: 0 !important;
                border: 0 !important;
                background: transparent !important;
                min-width: 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                font-size: .82rem;
                line-height: 1.25;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.ris-number-cell {
                grid-area: ris;
                font-size: .9rem;
                font-weight: 900;
                color: #052A47;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(2) {
                grid-area: date;
                text-align: right;
                color: #64748b;
                font-size: .76rem;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(2)::before {
                content: "Date: ";
                color: #047857;
                font-weight: 800;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.branch-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(3) {
                grid-area: branch;
                color: #334155;
                padding-right: 138px !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(3)::before {
                content: "Branch: ";
                color: #047857;
                font-weight: 800;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.plate-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(4) {
                grid-area: plate;
                color: #052A47;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(4) small {
                display: none;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(4)::before {
                content: "Plate: ";
                color: #047857;
                font-weight: 800;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.vehicle-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(5) {
                grid-area: vehicle;
                text-align: right;
                color: #334155;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.concern-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(6) {
                display: none !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.action-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(7) {
                grid-area: actions;
                display: flex !important;
                justify-content: flex-end;
                align-items: center;
                gap: 6px;
                padding-top: 4px !important;
                overflow: visible;
                white-space: normal;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.action-cell .btn,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(7) .btn,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.action-cell button,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(7) button {
                white-space: nowrap;
                flex: 0 0 auto;
                font-size: .74rem;
                padding: 6px 9px;
                border-radius: 999px;
            }

            .workflow-status-table-wrap .workflow-ris-table tr:not(.ris-row) {
                display: block;
                border: 1px dashed #d1fae5;
                border-radius: 14px;
                background: #fff;
            }

            .workflow-status-table-wrap .workflow-ris-table tr:not(.ris-row) td {
                display: block;
                border: 0 !important;
                white-space: normal;
                text-align: center;
            }

            .action-button-group {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
        }

        @media (max-width: 576px) {
            .navbar-top {
                align-items: flex-start;
                gap: .75rem;
            }

            .navbar-top .page-title h5 {
                font-size: 1rem;
            }

            .navbar-top .page-title small {
                font-size: .78rem;
                display: block;
            }

            .mobile-nav .nav-link {
                padding: 4px 7px;
            }

            .mobile-nav .nav-link i {
                font-size: 1.1rem;
            }

            .mobile-nav .nav-link span {
                font-size: .55rem;
            }

            .mobile-nav .more-dropdown {
                min-width: 180px;
            }

            .mobile-nav .dropdown-item {
                padding: 10px 12px;
                font-size: .78rem;
            }

            .workflow-status-table-wrap {
                padding: 8px;
            }

            .workflow-status-table-wrap .workflow-ris-table tbody {
                gap: 10px;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row {
                padding: 12px;
                grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr);
                gap: 7px 10px;
                border-radius: 14px;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.ris-number-cell {
                font-size: .82rem;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(2) {
                font-size: .7rem;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td {
                font-size: .76rem;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.action-cell .btn,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(7) .btn,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.action-cell button,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(7) button {
                font-size: .68rem;
                padding: 5px 7px;
            }
        }


        /* MOBILE CARD FINAL FIX: show complete RIS, Date, Plate, Vehicle Details, and Branch */
        @media (max-width: 992px) {
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row {
                grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr) !important;
                grid-template-areas:
                    "ris date"
                    "plate vehicle"
                    "branch branch"
                    "actions actions" !important;
                align-items: start !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td {
                min-width: 0 !important;
                max-width: 100% !important;
                width: auto !important;
                overflow: visible !important;
                text-overflow: clip !important;
                line-height: 1.25 !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.ris-number-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.ris-number-cell strong {
                display: block !important;
                min-width: 0 !important;
                max-width: 100% !important;
                white-space: nowrap !important;
                overflow: visible !important;
                text-overflow: clip !important;
                word-break: normal !important;
                color: #052A47 !important;
                font-size: clamp(.68rem, 2.75vw, .86rem) !important;
                font-weight: 600 !important;
                letter-spacing: -.02em !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.date-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(2) {
                grid-area: date !important;
                display: block !important;
                text-align: right !important;
                white-space: nowrap !important;
                overflow: visible !important;
                text-overflow: clip !important;
                color: #052A47 !important;
                font-size: clamp(.67rem, 2.65vw, .78rem) !important;
                letter-spacing: -.02em !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.plate-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(4) {
                grid-area: plate !important;
                display: block !important;
                text-align: left !important;
                white-space: nowrap !important;
                overflow: visible !important;
                text-overflow: clip !important;
                color: #052A47 !important;
                font-size: clamp(.74rem, 2.95vw, .86rem) !important;
                letter-spacing: -.01em !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.plate-cell strong,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(4) strong {
                font-weight: 700 !important;
                color: #052A47 !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.vehicle-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(5) {
                grid-area: vehicle !important;
                display: block !important;
                text-align: right !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                color: #052A47 !important;
                font-size: clamp(.72rem, 2.85vw, .84rem) !important;
                letter-spacing: -.01em !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.branch-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(3) {
                grid-area: branch !important;
                display: block !important;
                padding-right: 0 !important;
                white-space: nowrap !important;
                overflow: visible !important;
                text-overflow: clip !important;
                color: #052A47 !important;
                font-size: clamp(.74rem, 2.95vw, .86rem) !important;
                min-height: 18px !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.branch-cell:empty::after,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(3):empty::after {
                content: "N/A";
                color: #052A47;
                font-weight: 600;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.action-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(7) {
                grid-area: actions !important;
                justify-self: end !important;
                width: auto !important;
                max-width: 100% !important;
                margin-top: 6px !important;
            }
        }

        @media (max-width: 420px) {
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row {
                padding: 12px 10px !important;
                gap: 7px 8px !important;
                grid-template-columns: minmax(0, 1.12fr) minmax(0, .88fr) !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.ris-number-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.ris-number-cell strong {
                font-size: clamp(.62rem, 2.65vw, .76rem) !important;
            }

            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td.date-cell,
            .workflow-status-table-wrap .workflow-ris-table tr.ris-row td:nth-child(2) {
                font-size: clamp(.62rem, 2.55vw, .72rem) !important;
            }
        }



        /* =========================================================
   MOBILE MODAL FIX - keep all Bootstrap modals above mobile navbar
   ========================================================= */
        @media (max-width: 992px) {
            body.modal-open {
                overflow: hidden !important;
                padding-right: 0 !important;
            }

            body.modal-open .mobile-nav,
            body.modal-open .mobile-menu-btn,
            body.modal-open .mobile-sidebar-overlay,
            body.modal-open .sidebar-overlay {
                pointer-events: none !important;
            }

            .modal-backdrop {
                z-index: 19990 !important;
            }

            .modal,
            .modal.show {
                z-index: 20000 !important;
                padding-left: 8px !important;
                padding-right: 8px !important;
                padding-top: max(10px, env(safe-area-inset-top)) !important;
                padding-bottom: max(14px, env(safe-area-inset-bottom)) !important;
            }

            .modal-dialog,
            .modal-dialog-scrollable,
            .modal-dialog-centered {
                width: calc(100vw - 16px) !important;
                max-width: calc(100vw - 16px) !important;
                margin: 8px auto 14px auto !important;
            }

            .modal-dialog-scrollable {
                height: calc(100dvh - 28px) !important;
                max-height: calc(100dvh - 28px) !important;
            }

            .modal-dialog-scrollable .modal-content,
            .motorpool-modal .modal-content,
            .assessment-modal-xl .modal-content {
                max-height: calc(100dvh - 28px) !important;
                min-height: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                overflow: hidden !important;
            }

            .modal-dialog-scrollable .modal-body,
            .motorpool-modal .modal-body,
            .assessment-modal-xl .modal-body {
                overflow-y: auto !important;
                overflow-x: hidden !important;
                min-height: 0 !important;
                padding-bottom: 18px !important;
                -webkit-overflow-scrolling: touch;
            }

            .modal-footer {
                flex-shrink: 0 !important;
                padding-bottom: max(12px, env(safe-area-inset-bottom)) !important;
                background: #ffffff !important;
                position: relative !important;
                z-index: 2 !important;
            }

            .modal-header {
                flex-shrink: 0 !important;
                position: relative !important;
                z-index: 3 !important;
            }
        }

        @media (max-width: 576px) {

            .modal,
            .modal.show {
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .modal-dialog,
            .modal-dialog-scrollable,
            .modal-dialog-centered {
                width: calc(100vw - 12px) !important;
                max-width: calc(100vw - 12px) !important;
                margin: 6px auto 12px auto !important;
            }

            .modal-dialog-scrollable,
            .modal-dialog-scrollable .modal-content,
            .motorpool-modal .modal-content,
            .assessment-modal-xl .modal-content {
                max-height: calc(100dvh - 22px) !important;
                height: auto !important;
            }
        }
    

        /* ===== FINAL FIX: Mobile profile modal should not become fullscreen ===== */
        @media (max-width: 768px) {
            html,
            body {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
            }

            body.modal-open {
                overflow: hidden !important;
                padding-right: 0 !important;
            }

            body.modal-open #appPage,
            body.modal-open .main-content {
                overflow: hidden !important;
            }

            .mobile-nav {
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
                box-sizing: border-box !important;
            }

            .mobile-nav .nav {
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }

            #profileModal.modal,
            #profileModal.modal.show {
                overflow: hidden !important;
                padding: 10px 10px calc(82px + env(safe-area-inset-bottom)) 10px !important;
                z-index: 20000 !important;
            }

            #profileModal .modal-dialog,
            #profileModal .modal-dialog-centered {
                width: min(390px, calc(100vw - 20px)) !important;
                max-width: calc(100vw - 20px) !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                margin: 16px auto 0 auto !important;
                display: block !important;
                transform: none !important;
            }

            #profileModal .modal-content {
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: calc(100dvh - 120px) !important;
                overflow: hidden !important;
                border-radius: 22px !important;
                box-sizing: border-box !important;
            }

            #profileModal .modal-header {
                flex: 0 0 auto !important;
            }

            #profileModal .modal-body {
                max-height: calc(100dvh - 230px) !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                padding-bottom: 28px !important;
                -webkit-overflow-scrolling: touch;
            }
        }

        @media (max-width: 420px) {
            #profileModal .modal-dialog,
            #profileModal .modal-dialog-centered {
                width: calc(100vw - 22px) !important;
                max-width: calc(100vw - 22px) !important;
                margin-top: 14px !important;
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
                                        <a class="nav-link active" href="request_handler.php">
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
            <div class="navbar-top">
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Open menu">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Request for Inspection Slip</h2>
                    <small>Requests are grouped by current workflow status. Updated repair workflow with For Quality
                        Check stage.</small>
                </div>
            </div>

            <div class="form-card">

                <div class="workflow-status-list" id="workflowStatusList">
                    <?php foreach (workflowTabs() as $index => $status): ?>
                        <?php
                        $collapseId = 'workflowStatusCollapse' . $index;
                        $statusRows = [];
                        foreach ($risRequests as $request) {
                            $requestStatus = $request['workflow_status'] ?: ($request['status'] ?? 'For Vehicle Endorsement');
                            if ($requestStatus === $status) {
                                $statusRows[] = $request;
                            }
                        }
                        ?>
                        <div class="workflow-status-card">
                            <button class="workflow-status-header" type="button" data-bs-toggle="collapse"
                                data-bs-target="#<?php echo h($collapseId); ?>" aria-expanded="false"
                                aria-controls="<?php echo h($collapseId); ?>">
                                <span class="workflow-status-title">
                                    <i class="bi bi-folder2-open"></i>
                                    <?php echo h($status); ?>
                                </span>
                                <span class="workflow-status-count-wrap">
                                    <span class="workflow-status-count"><?php echo (int) $counts[$status]; ?></span>
                                    <i class="bi bi-chevron-down toggle-icon"></i>
                                </span>
                            </button>

                            <div class="collapse workflow-status-body" id="<?php echo h($collapseId); ?>">
                                <div class="workflow-status-table-wrap">
                                    <div class="table-responsive">
                                        <table class="table custom-table compact-table align-middle workflow-ris-table">
                                            <thead>
                                                <tr>
                                                    <th class="ris-number-cell">RIS No.</th>
                                                    <th class="date-cell">Date Requested</th>
                                                    <th class="branch-cell">Branch</th>
                                                    <th class="plate-cell">Plate No.</th>
                                                    <th class="vehicle-cell">Vehicle Details</th>
                                                    <th class="concern-col">Concerns</th>
                                                    <th class="text-end action-col">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($statusRows)): ?>
                                                    <tr>
                                                        <td colspan="7">
                                                            <div class="empty-state">
                                                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                                                No <?php echo h(strtolower($status)); ?> RIS requests found.
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($statusRows as $request): ?>
                                                        <?php
                                                        $request['display_branch_name'] = risDisplayBranchName($request);
                                                        $request['is_quality_rework'] = isQualityCheckReworkActive($conn, (int) ($request['ris_id'] ?? 0), (string) ($request['workflow_status'] ?? $request['status'] ?? ''), (string) ($request['action_taken'] ?? '')) ? 1 : 0;
                                                        $request['repair_progress_json'] = json_encode(buildMergedRepairRowsForRis($conn, (int) ($request['ris_id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                                                        $request['quality_completed_rows_json'] = json_encode(buildQualityCheckRowsForRis($conn, (int) ($request['ris_id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                                                        $request['release_details_json'] = json_encode(buildReleaseDetailsFromRepairLogs($conn, (int) ($request['ris_id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                                                        $payload = h(jsonText($request));
                                                        ?>
                                                        <tr class="ris-row" data-ris='<?php echo $payload; ?>'
                                                            onclick="openRisDetailsModal(this)">
                                                            <td class="ris-number-cell">
                                                                <strong><?php echo h($request['ris_number']); ?></strong></td>
                                                            <td class="date-cell"><?php echo h($request['date_requested']); ?></td>
                                                            <td class="branch-cell">
                                                                <?php echo h($request['display_branch_name'] ?? risDisplayBranchName($request)); ?>
                                                            </td>
                                                            <td class="plate-cell">
                                                                <strong><?php echo h($request['plate_no'] ?: '-'); ?></strong><br>
                                                                <small class="text-muted">Vehicle ID:
                                                                    <?php echo h($request['vehicle_id'] ?: '-'); ?></small>
                                                            </td>
                                                            <td class="vehicle-cell">
                                                                <?php echo h($request['vehicle_details'] ?: $request['vehicle_category'] ?: '-'); ?>
                                                            </td>
                                                            <td class="concern-cell">
                                                                <?php echo nl2br(h(cleanRisConcernText((string) $request['concerns']))); ?>
                                                            </td>
                                                            <td class="text-end action-cell"><?php echo nextActionHtml($request); ?>
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
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
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
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'companyMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Company</span>
                </a>
                <div class="more-dropdown" id="companyMobileMenu">
                    <a class="dropdown-item" href="chartofaccounts.php"><i class="bi bi-graph-up"></i><span>Chart of
                            Accounts</span></a>
                    <a class="dropdown-item active" href="request_handler.php"><i class="bi bi-clipboard"></i><span>RIS
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


    <div class="modal fade motorpool-modal" id="risDetailsModalV4" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header motorpool-green">
                    <h5 class="modal-title">
                        <i class="bi bi-eye me-2"></i>
                        <span id="detailsTitle">RIS Details</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="section-title">Request Information</div>
                    <div class="info-grid mb-3" id="detailsGrid"></div>

                    <label class="form-label">Concerns</label>
                    <textarea class="form-control" id="detailsConcerns" rows="3" readonly></textarea>

                    <div class="section-title mt-3">Assessment / Parts</div>
                    <div class="assessment-detail-wrap" id="detailsAssessment"></div>
                </div>
                <div class="modal-footer approval-proof-footer-v70">
                    <form method="POST" enctype="multipart/form-data" id="approvalProofFormV64"
                        class="approval-proof-inline-v70 d-none">
                        <input type="hidden" name="action" value="upload_motorpool_approval_proof_v64">
                        <input type="hidden" name="approval_proof_ris_id" id="approvalProofRisIdV64">
                        <input type="file" name="approval_proof_attachment" id="approvalProofFileV64"
                            class="form-control form-control-sm approval-proof-file-v70" accept="image/*,.pdf" required>
                        <button type="submit" class="btn btn-success btn-sm" id="approvalProofUploadBtnV70">
                            <i class="bi bi-upload me-1"></i>Upload Proof of Approval
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary" id="risDetailsCloseBtnV70"
                        data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade motorpool-modal" id="vehicleReceivedWorkflowModalV4" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" id="vehicleReceivedWorkflowFormV23">
                    <input type="hidden" name="action" value="receive_vehicle_workflow_v3">
                    <input type="hidden" name="receive_ris_id" id="receiveRisIdV4">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-camera me-2"></i>Received Vehicle
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="info-grid mb-3" id="receiveInfoGridV4"></div>

                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Received By <span class="required-mark">*</span></label>
                                <input class="form-control" name="received_by_name" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Date Received <span class="required-mark">*</span></label>
                                <input type="date" class="form-control" name="received_date" id="receiveDateV4" readonly
                                    required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Time Received <span class="required-mark">*</span></label>
                                <input type="time" class="form-control" name="received_time" id="receiveTimeV4" readonly
                                    required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Vehicle Photos with Timestamp <span
                                        class="required-mark">*</span></label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-1">Front <span
                                                class="required-mark">*</span></label>
                                        <input type="file" class="form-control receive-angle-photo"
                                            name="received_photo_front" id="receivePhotoFrontV23"
                                            data-preview="receivePreviewFrontV23" data-label="Front" accept="image/*"
                                            capture="environment" required>
                                        <div id="receivePreviewFrontV23"
                                            class="vehicle-received-proof-preview-grid mt-2"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-1">Left-side <span
                                                class="required-mark">*</span></label>
                                        <input type="file" class="form-control receive-angle-photo"
                                            name="received_photo_left" id="receivePhotoLeftV23"
                                            data-preview="receivePreviewLeftV23" data-label="Left-side" accept="image/*"
                                            capture="environment" required>
                                        <div id="receivePreviewLeftV23"
                                            class="vehicle-received-proof-preview-grid mt-2"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-1">Right-side <span
                                                class="required-mark">*</span></label>
                                        <input type="file" class="form-control receive-angle-photo"
                                            name="received_photo_right" id="receivePhotoRightV23"
                                            data-preview="receivePreviewRightV23" data-label="Right-side"
                                            accept="image/*" capture="environment" required>
                                        <div id="receivePreviewRightV23"
                                            class="vehicle-received-proof-preview-grid mt-2"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-1">Back <span
                                                class="required-mark">*</span></label>
                                        <input type="file" class="form-control receive-angle-photo"
                                            name="received_photo_back" id="receivePhotoBackV23"
                                            data-preview="receivePreviewBackV23" data-label="Back" accept="image/*"
                                            capture="environment" required>
                                        <div id="receivePreviewBackV23"
                                            class="vehicle-received-proof-preview-grid mt-2"></div>
                                    </div>
                                </div>
                                <div class="form-text mt-2">Each photo will be saved with upload timestamp.</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle me-1"></i>Save Received
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade motorpool-modal" id="returnVehicleModalV1" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" id="returnVehicleFormV1">
                    <input type="hidden" name="action" value="return_vehicle_endorsement_v1">
                    <input type="hidden" name="return_ris_id" id="returnRisIdV1">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Return Request
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="info-grid mb-3" id="returnInfoGridV1"></div>
                        <label class="form-label">Remarks <span class="required-mark">*</span></label>
                        <textarea class="form-control" name="return_remarks" id="returnRemarksV1" rows="4"
                            placeholder="Enter the reason why this request is being returned to Branch Admin."
                            required></textarea>
                        <div class="form-text mt-2">This request will be returned to Branch Admin for correction or
                            completion.</div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-send-exclamation me-1"></i>Return to Branch Admin
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade motorpool-modal" id="assessmentWorkflowModalV4" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-xl-down modal-dialog-centered assessment-modal-xl">
            <div class="modal-content">
                <form method="POST" id="assessmentWorkflowFormV4">
                    <input type="hidden" name="action" value="save_assessment_workflow_v3">
                    <input type="hidden" name="assessment_ris_id" id="assessmentRisIdV4">
                    <input type="hidden" name="assessment_json" id="assessmentJsonV4">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-clipboard-plus me-2"></i>Assessment: Repair Work Required and Items / Parts
                            Needed
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="info-grid mb-3" id="assessmentInfoGridV4"></div>
                        <div class="alert alert-info py-2 mb-3">
                            Items / parts are optional. Click Add Parts only when parts are needed. Once added, Item
                            No., Description, Specification, and Quantity are required.
                        </div>
                        <div id="repairBlocksV4"></div>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="addRepairBlockV4()">
                            <i class="bi bi-plus-circle me-1"></i>Add Another Repair
                        </button>
                        <div id="assessmentGrandTotalLiveV55" class="assessment-grand-total-v52 mt-3">
                            <span class="repair-total-label">Grand Total Cost</span>
                            <strong>₱0.00</strong>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">Assessed By <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="assessment_assessed_by"
                                id="assessmentAssessedByV4" placeholder="Mechanic / Assessor name" required>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-send-check me-1"></i>Send for Approval
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade motorpool-modal" id="partsCompletionModalV4" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" id="partsCompletionFormV4">
                    <input type="hidden" name="action" id="partsCompletionActionV4" value="save_parts_completion_v1">
                    <input type="hidden" name="advance_ris_id" id="partsCompletionRisIdV4">
                    <input type="hidden" name="next_workflow_status" value="For Repair">
                    <input type="hidden" name="parts_completion_json" id="partsCompletionJsonV4">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-box-seam me-2"></i>Parts Completion Check
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="info-grid mb-3" id="partsCompletionInfoGridV4"></div>

                        <div class="alert alert-warning py-2 mb-3">
                            Verify that all required items/parts for the repair are complete before proceeding to
                            <strong>For Repair</strong>.
                        </div>

                        <div class="section-title">Items / Parts Needed from Assessment</div>
                        <div id="partsCompletionAssessmentWrapV4" class="parts-completion-summary mb-3"></div>

                        <div class="section-title">Available / Received Items or Parts</div>
                        <div class="table-responsive">
                            <table class="table table-bordered parts-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Item No.</th>
                                        <th>Description</th>
                                        <th>Specification</th>
                                        <th>Needed Quantity</th>
                                        <th>Source</th>
                                        <th>Unit Cost</th>
                                        <th>Motorpool Cost</th>
                                        <th>Available Quantity</th>
                                    </tr>
                                </thead>
                                <tbody id="partsCompletionAvailableBodyV4">
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-3">No items/parts listed.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="parts-completion-progress mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong>Parts Completion Progress</strong>
                                <span id="partsCompletionProgressTextV4" class="text-muted small">0 of 0 items
                                    complete</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-danger" id="partsCompletionProgressBarV4" role="progressbar"
                                    style="width: 0%;">0%</div>
                            </div>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1" name="parts_completion_confirmed"
                                id="partsCompletionConfirmV4">
                            <label class="form-check-label" for="partsCompletionConfirmV4">
                                I confirm that all listed items/parts needed are complete.
                            </label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-outline-success" id="partsCompletionSaveBtnV4"
                            data-submit-mode="save">
                            <i class="bi bi-save me-1"></i>Save
                        </button>
                        <button type="submit" class="btn btn-success d-none" id="partsCompletionProceedBtnV4"
                            data-submit-mode="proceed">
                            <i class="bi bi-check2-circle me-1"></i>Proceed to For Repair
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade motorpool-modal" id="startRepairProofModalV11" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" id="startRepairProofFormV11">
                    <input type="hidden" name="action" value="start_repair_workflow_v4">
                    <input type="hidden" name="start_repair_ris_id" id="startRepairRisIdV11">
                    <input type="hidden" name="start_repair_progress_json" id="startRepairProgressJsonV11" value="[]">
                    <input type="hidden" name="start_repair_submit_mode" id="startRepairSubmitModeV11" value="save">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-journal-plus me-2"></i>Log Repair
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="info-grid mb-3" id="startRepairInfoGridV11"></div>

                        <div class="repair-progress-wrap mb-3">
                            <div class="section-title mt-0">Repair Log</div>
                            <div id="startRepairProgressBodyV11" class="repair-log-card-list"></div>

                            <div class="repair-completion-progress mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong>Repair Start Selection Progress</strong>
                                    <span id="startRepairProgressTextV11" class="text-muted small">0 of 0 repairs
                                        selected</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar bg-danger" id="startRepairProgressBarV11"
                                        role="progressbar" style="width: 0%;">0%</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1" name="start_repair_confirmed"
                                id="startRepairConfirmV11">
                            <label class="form-check-label" for="startRepairConfirmV11">
                                I confirm that the encoded repair log/s are ready to proceed to ongoing repair.
                            </label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-outline-success" id="startRepairSaveBtnV11">
                            <i class="bi bi-journal-plus me-1"></i>Log Repair
                        </button>
                        <button type="submit" class="btn btn-success d-none" id="startRepairProceedBtnV11">
                            <i class="bi bi-play-circle me-1"></i>Proceed to Ongoing Repair
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <div class="modal fade motorpool-modal" id="ongoingRepairModalV1" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" id="ongoingRepairFormV1">
                    <input type="hidden" name="action" value="ongoing_repair_workflow_v1">
                    <input type="hidden" name="ongoing_repair_ris_id" id="ongoingRepairRisIdV1">
                    <input type="hidden" name="ongoing_repair_progress_json" id="ongoingRepairProgressJsonV1"
                        value="[]">
                    <input type="hidden" name="ongoing_repair_submit_mode" id="ongoingRepairSubmitModeV1" value="save">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-check2-square me-2"></i>Ongoing Repair Progress
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="info-grid mb-3" id="ongoingRepairInfoGridV1"></div>

                        <div class="repair-progress-wrap mb-3">
                            <div class="section-title mt-0">Repair Log</div>
                            <div id="ongoingRepairProgressBodyV1" class="repair-log-card-list"></div>

                            <div class="repair-completion-progress mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong>Repair Completion Progress</strong>
                                    <span id="ongoingRepairProgressTextV1" class="text-muted small">0 of 0 repairs
                                        done</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar bg-danger" id="ongoingRepairProgressBarV1"
                                        role="progressbar" style="width: 0%;">0%</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1" name="ongoing_repair_confirmed"
                                id="ongoingRepairConfirmV1">
                            <label class="form-check-label" for="ongoingRepairConfirmV1">
                                I confirm that all listed repairs are already done and ready for quality check.
                            </label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-outline-success" id="ongoingRepairSaveBtnV1">
                            <i class="bi bi-save me-1"></i>Save Repair Progress
                        </button>
                        <button type="submit" class="btn btn-success d-none" id="ongoingRepairProceedBtnV1">
                            <i class="bi bi-patch-check me-1"></i>Proceed to Quality Check
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <div class="modal fade motorpool-modal" id="qualityCheckModalV1" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" id="qualityCheckFormV1">
                    <input type="hidden" name="action" value="save_quality_check_workflow_v1">
                    <input type="hidden" name="quality_ris_id" id="qualityRisIdV1">
                    <input type="hidden" name="quality_check_json" id="qualityCheckJsonV1" value="[]">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-patch-check me-2"></i>For Quality Check
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="info-grid mb-3" id="qualityInfoGridV1"></div>

                        <div class="section-title mt-0">Completed Repairs / Items Used</div>
                        <div class="table-responsive mb-3">
                            <table
                                class="table table-bordered repair-progress-table align-middle mb-0 quality-check-table">
                                <thead>
                                    <tr>
                                        <th>Repair</th>
                                        <th>Items Replaced / Used</th>
                                    </tr>
                                </thead>
                                <tbody id="qualityCheckBodyV1"></tbody>
                            </table>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Date <span class="required-mark">*</span></label>
                                <input type="date" class="form-control" name="quality_check_date"
                                    id="qualityCheckDateV1" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Time <span class="required-mark">*</span></label>
                                <input type="time" class="form-control" name="quality_check_time"
                                    id="qualityCheckTimeV1" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Quality Check By <span class="required-mark">*</span></label>
                                <input type="text" class="form-control" name="quality_check_by" id="qualityCheckByV1"
                                    required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="quality_remarks" id="qualityRemarksV1" rows="3"
                                    placeholder="Enter quality check remarks"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-warning" id="qualityReturnToRepairBtnV1"
                            onclick="openQualityReturnToRepairModalV1()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Return to Repair
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle me-1"></i>Save Quality Check
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <div class="modal fade motorpool-modal" id="qualityReturnToRepairModalV1" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" id="qualityReturnToRepairFormV1">
                    <input type="hidden" name="action" value="return_quality_check_to_repair_v1">
                    <input type="hidden" name="return_quality_ris_id" id="returnQualityRisIdV1">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Return to Repair</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning py-2">
                            This will return the request to <strong>For Repair</strong>. The approved items will stay
                            view-only during rework.
                        </div>
                        <label class="form-label">Return Reason <span class="required-mark">*</span></label>
                        <textarea class="form-control" name="return_quality_reason" id="returnQualityReasonV1" rows="4"
                            placeholder="Enter reason why the repair needs rework" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i
                                class="bi bi-arrow-counterclockwise me-1"></i>Return to Repair</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade motorpool-modal" id="releaseCompletionModalV15" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" id="releaseCompletionFormV15">
                    <input type="hidden" name="action" value="complete_release_workflow_v15">
                    <input type="hidden" name="release_ris_id" id="releaseRisIdV15">

                    <div class="modal-header motorpool-green">
                        <h5 class="modal-title">
                            <i class="bi bi-check2-square me-2"></i>Vehicle Release to Driver / Branch Representative
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="alert alert-info py-2 mb-3">
                            Review the completed repair details, then encode the person who checked and received the
                            vehicle before final release.
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Date <span class="required-mark">*</span></label>
                                <input type="date" class="form-control" name="release_repair_date"
                                    id="releaseRepairDateV15" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">RIS No.</label>
                                <input type="text" class="form-control" id="releaseRisNoV15" readonly>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Parts Replaced / Used</label>
                                <input type="hidden" name="release_parts_replaced" id="releasePartsReplacedV15"
                                    required>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm assessment-parts-table mb-0"
                                        id="releasePartsReplacedTableV15">
                                        <thead>
                                            <tr>
                                                <th>Item No.</th>
                                                <th>Qty Used</th>
                                                <th>Source</th>
                                                <th class="text-end">Item Cost</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td colspan="2" class="text-center text-muted py-3">No parts replaced /
                                                    used.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <div class="border rounded p-3 bg-light" id="releaseCostSummaryBoxV15">
                                    <div class="fw-semibold mb-3"><i class="bi bi-cash-coin me-1"></i>Release Cost
                                        Summary</div>
                                    <div class="row g-3">
                                        <div class="col-6 col-md-3">
                                            <div class="text-muted small">Repair Cost</div>
                                            <div class="fw-bold" id="releaseRepairCostV15">₱0.00</div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="text-muted small">Item Cost</div>
                                            <div class="fw-bold" id="releaseItemCostV15">₱0.00</div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="text-muted small">Miscellaneous Cost</div>
                                            <div class="fw-bold" id="releaseMiscCostV15">₱0.00</div>
                                            <div class="text-muted small" id="releaseMiscDescV15">No miscellaneous
                                                encoded.</div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="text-muted small">Grand Total</div>
                                            <div class="fw-bold text-success" id="releaseGrandTotalV15">₱0.00</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Mechanics <span class="required-mark">*</span></label>
                                <input type="text" class="form-control" name="release_mechanic" id="releaseMechanicV15"
                                    readonly required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Start Date <span class="required-mark">*</span></label>
                                <input type="date" class="form-control" name="release_start_date"
                                    id="releaseStartDateV15" readonly required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">End Date <span class="required-mark">*</span></label>
                                <input type="date" class="form-control" name="release_end_date" id="releaseEndDateV15"
                                    readonly required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Checked and Received By <span
                                        class="required-mark">*</span></label>
                                <input type="text" class="form-control" name="release_checked_received_by"
                                    id="releaseCheckedReceivedByV15" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Date Received <span class="required-mark">*</span></label>
                                <input type="date" class="form-control" name="release_received_date"
                                    id="releaseReceivedDateV15" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Time Received <span class="required-mark">*</span></label>
                                <input type="time" class="form-control" name="release_received_time"
                                    id="releaseReceivedTimeV15" required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Completion Proof Attachment <span
                                        class="required-mark">*</span></label>
                                <input type="file" class="form-control" name="release_attachment"
                                    id="releaseAttachmentV15" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" required>
                                <div class="form-text">Allowed files: JPG, PNG, WEBP, GIF, or PDF.</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle me-1"></i>Release Vehicle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const MOTORPOOL_INVENTORY_OPTIONS = <?= jsonText($motorpoolInventoryOptions ?? []) ?>;

        function normalizeLookupTextV24(value) {
            return String(value || '').trim().toLowerCase();
        }

        function findMotorpoolInventoryOptionV24(value, idValue) {
            const id = Number(idValue || 0) || 0;
            if (id > 0) {
                const byId = MOTORPOOL_INVENTORY_OPTIONS.find(item => Number(item.item_id || 0) === id);
                if (byId) return byId;
            }
            const text = normalizeLookupTextV24(value);
            if (!text) return null;
            return MOTORPOOL_INVENTORY_OPTIONS.find(function (item) {
                const values = [
                    item.item_code,
                    item.item_name,
                    item.barcode,
                    item.label,
                    (item.item_code || '') + ' - ' + (item.item_name || '')
                ].map(normalizeLookupTextV24);
                return values.includes(text);
            }) || null;
        }

        function renderMotorpoolInventoryDatalistV24() {
            if (!MOTORPOOL_INVENTORY_OPTIONS.length) return '';
            return MOTORPOOL_INVENTORY_OPTIONS.map(function (item) {
                const label = item.label || ((item.item_code || '') + ' - ' + (item.item_name || ''));
                return `<option value="${esc(label)}" data-id="${esc(item.item_id)}"></option>`;
            }).join('');
        }

        function applyMotorpoolInventorySelectionV24(input) {
            const row = input.closest('.start-repair-part-row');
            if (!row) return;
            const selected = findMotorpoolInventoryOptionV24(input.value, row.querySelector('.start-repair-part-inventory-id')?.value || '');
            const hiddenId = row.querySelector('.start-repair-part-inventory-id');
            const itemNoHidden = row.querySelector('.start-repair-part-item-no');
            const itemNameHidden = row.querySelector('.start-repair-part-item-name');
            const descriptionHidden = row.querySelector('.start-repair-part-description');
            const specificationHidden = row.querySelector('.start-repair-part-specification');
            const availableHidden = row.querySelector('.start-repair-part-available');
            const availableInput = row.querySelector('.start-repair-part-available-display');
            const costInput = row.querySelector('.start-repair-part-cost');
            const usedInput = row.querySelector('.start-repair-part-used');
            const costSource = row.querySelector('.start-repair-part-cost-source');

            if (selected) {
                if (hiddenId) hiddenId.value = selected.item_id || '';
                if (itemNoHidden) itemNoHidden.value = selected.item_code || '';
                if (itemNameHidden) itemNameHidden.value = selected.item_name || '';
                if (descriptionHidden && !descriptionHidden.value) descriptionHidden.value = selected.description || selected.item_name || '';
                if (availableHidden) availableHidden.value = selected.current_stock || 0;
                if (availableInput) availableInput.value = selected.current_stock || 0;
                if (usedInput) usedInput.max = selected.current_stock || 0;
                if (costInput) costInput.value = Number(selected.unit_cost || 0).toFixed(2);
                if (costSource) costSource.textContent = 'Auto cost from Motorpool Inventory';
            } else {
                if (hiddenId) hiddenId.value = '';
                if (itemNoHidden) itemNoHidden.value = input.value || '';
                if (itemNameHidden) itemNameHidden.value = input.value || '';
                if (availableHidden) availableHidden.value = '';
                if (availableInput) availableInput.value = '';
                if (usedInput) usedInput.removeAttribute('max');
                if (costSource) costSource.textContent = 'Manual item. Type cost if needed.';
            }
            updateStartRepairPartTotalV24(row);
        }

        function updateStartRepairPartTotalV24(row) {
            const qtyVal = Number(row.querySelector('.start-repair-part-used')?.value || 0) || 0;
            const costVal = Number(row.querySelector('.start-repair-part-cost')?.value || 0) || 0;
            const totalInput = row.querySelector('.start-repair-part-total-cost');
            if (totalInput) totalInput.value = (qtyVal * costVal).toFixed(2);
        }

        function esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (match) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[match];
            });
        }


        function moneyPhpStyleV32(value) {
            const num = Number(String(value ?? '0').replace(/,/g, '')) || 0;
            return '₱' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function qtyPhpStyleV32(value) {
            const num = Number(String(value ?? '0').replace(/,/g, '')) || 0;
            return Number.isInteger(num) ? num.toLocaleString() : num.toLocaleString(undefined, { maximumFractionDigits: 0, maximumFractionDigits: 2 });
        }
        function partQtyNeededV32(part) {
            return part?.needed_quantity ?? part?.quantity ?? part?.qty ?? '';
        }
        function partQtyUsedV32(part) {
            return part?.used_quantity ?? part?.qty_used ?? part?.qty_to_use ?? part?.quantity_to_use ?? part?.quantity_used ?? part?.qty ?? part?.quantity ?? '';
        }
        function partUnitCostV32(part) {
            const raw = part?.unit_cost ?? part?.cost ?? part?.price ?? 0;
            return Number(String(raw).replace(/,/g, '')) || 0;
        }
        function partEstimatedCostV32(part, preferUsedQty = false) {
            const saved = part?.estimated_total_cost ?? part?.estimated_cost ?? part?.total_cost;
            if (saved !== undefined && saved !== null && String(saved).trim() !== '') return Number(String(saved).replace(/,/g, '')) || 0;
            const qtyRaw = preferUsedQty ? partQtyUsedV32(part) : partQtyNeededV32(part);
            const qty = Number(String(qtyRaw).replace(/,/g, '')) || 0;
            return qty * partUnitCostV32(part);
        }
        function partSourceByV32(part) {
            const raw = String(part?.source_by || part?.parts_source_by || part?.source_type || part?.source || part?.purchased_by || '').toLowerCase().trim();
            const branchFlag = String(part?.branch_sourced || part?.branch_purchased || '').trim() === '1' || part?.branch_sourced === true || part?.branch_purchased === true;
            if (branchFlag || raw === 'branch' || raw === 'branch admin' || raw === 'branch_admin' || raw === 'branch sourced' || raw === 'branch_source' || raw === 'branch source' || raw === 'branch_sourced') return 'branch';
            return 'motorpool';
        }
        function partSourceLabelV32(part) {
            return partSourceByV32(part) === 'branch' ? 'Branch Source' : 'Motorpool Source';
        }
        function partCostSourceV32(part) {
            const sourceLabel = partSourceLabelV32(part);
            const base = part?.cost_source || (part?.inventory_item_id || part?.item_id ? 'Motorpool Inventory' : 'Manual');
            return sourceLabel + (base ? ' · ' + base : '');
        }
        function partMotorpoolBillableCostV32(part, preferUsedQty = false) {
            if (partSourceByV32(part) === 'branch') return 0;
            const explicit = part?.motorpool_billable_cost ?? part?.motorpool_cost;
            if (explicit !== undefined && explicit !== null && String(explicit).trim() !== '') return Number(String(explicit).replace(/,/g, '')) || 0;
            return partEstimatedCostV32(part, preferUsedQty);
        }

        function repairCostValueV52(repair) {
            const raw = repair?.repair_cost ?? repair?.labor_cost ?? repair?.service_cost ?? 0;
            return Number(String(raw || '0').replace(/,/g, '')) || 0;
        }
        function repairPartsEstimatedTotalV52(repair, preferUsedQty = false) {
            const parts = Array.isArray(repair?.parts) ? repair.parts : (Array.isArray(repair?.parts_used) ? repair.parts_used : []);
            return parts.reduce((sum, part) => sum + partMotorpoolBillableCostV32(part, preferUsedQty), 0);
        }
        function miscellaneousCostValueV1(item) {
            const raw = item?.miscellaneous_cost ?? item?.misc_cost ?? item?.other_cost ?? 0;
            return Number(String(raw || '0').replace(/,/g, '')) || 0;
        }
        function miscellaneousDescriptionValueV1(item) {
            return item?.miscellaneous_description || item?.miscellaneous || item?.misc_description || item?.other_description || '';
        }
        function renderMiscellaneousEditorV1(item, prefix, readOnly = false) {
            const desc = miscellaneousDescriptionValueV1(item);
            const cost = miscellaneousCostValueV1(item);
            return `
        <div class="repair-log-misc-section border rounded p-3 mt-3 bg-light">
            <div class="fw-semibold mb-2"><i class="bi bi-receipt-cutoff me-1"></i>Miscellaneous</div>
            <div class="row g-3">
                <div class="col-12 col-md-8">
                    <label class="form-label small mb-1">Miscellaneous Description</label>
                    <input type="text" class="form-control form-control-sm ${prefix}-field ${prefix}-misc-desc" value="${esc(desc)}" placeholder="Example: shop supplies, sealant, cleaning materials" ${readOnly ? 'readonly' : ''}>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1">Miscellaneous Cost</label>
                    <input type="number" min="0" step="0.01" class="form-control form-control-sm ${prefix}-field ${prefix}-misc-cost no-spinner" value="${cost > 0 ? esc(cost.toFixed(2)) : ''}" placeholder="0.00" ${readOnly ? 'readonly' : ''}>
                </div>
            </div>
            <small class="text-muted d-block mt-2">This is added per repair log, separate from item quantity and separate from the Repair Cost/Labor Cost.</small>
        </div>`;
        }
        function renderMiscellaneousViewV1(item) {
            const desc = miscellaneousDescriptionValueV1(item);
            const cost = miscellaneousCostValueV1(item);
            if (!desc && cost <= 0) return '<span class="text-muted">-</span>';
            return `<div><strong>${esc(desc || 'Miscellaneous')}</strong>${cost > 0 ? '<br><span class="text-success fw-semibold">' + moneyPhpStyleV32(cost) + '</span>' : ''}</div>`;
        }
        function repairGrandTotalV52(repair, preferUsedQty = false) {
            return repairCostValueV52(repair) + repairPartsEstimatedTotalV52(repair, preferUsedQty) + miscellaneousCostValueV1(repair);
        }
        function repairCostSummaryV52(repair, preferUsedQty = false) {
            const repairTotal = repairGrandTotalV52(repair, preferUsedQty);
            return `<div class="repair-total-footer-v53">
        <span class="repair-total-label">Total Cost</span>
        <strong>${moneyPhpStyleV32(repairTotal)}</strong>
    </div>`;
        }
        function partItemNoV32(part) {
            return part?.item_no || part?.item_code || part?.item_name || part?.item || part?.name || '';
        }
        function partDescriptionV32(part) {
            return part?.description || part?.part_description || part?.item_description || part?.name || part?.item_name || '';
        }
        function partSpecificationV32(part) {
            return part?.specification || part?.part_specification || part?.item_specification || part?.unit_type || '';
        }
        function costPartsHeaderV32(qtyLabel = 'Quantity') {
            return `<thead><tr><th>Item No.</th><th>Description</th><th>Specification</th><th>${qtyLabel}</th><th>Source</th><th>Unit Cost</th><th>Motorpool Cost</th></tr></thead>`;
        }
        function costPartRowV32(part, preferUsedQty = false) {
            const qtyValue = preferUsedQty ? partQtyUsedV32(part) : partQtyNeededV32(part);
            return `<tr><td>${esc(partItemNoV32(part))}</td><td>${esc(partDescriptionV32(part))}</td><td>${esc(partSpecificationV32(part))}</td><td>${esc(qtyValue)}</td><td><span class="badge ${partSourceByV32(part) === 'branch' ? 'bg-primary' : 'bg-success'}">${esc(partSourceLabelV32(part))}</span></td><td>${moneyPhpStyleV32(partUnitCostV32(part))}</td><td class="fw-semibold ${partSourceByV32(part) === 'branch' ? 'text-muted' : 'text-success'}">${moneyPhpStyleV32(partMotorpoolBillableCostV32(part, preferUsedQty))}</td></tr>`;
        }
        function assessmentCostFooterV32(total, colspan = 5) {
            return `<div class="assessment-total-bar-v80"><span class="assessment-total-bar-label-v80">TOTAL ESTIMATED COST</span><strong class="assessment-total-bar-amount-v80">${moneyPhpStyleV32(total)}</strong></div>`;
        }
        function renderCostPartsTableV32(parts, qtyLabel = 'Quantity', preferUsedQty = false) {
            const list = Array.isArray(parts) ? parts : [];
            let total = 0;
            let body = '';
            if (!list.length) {
                body = '<tr><td colspan="8" class="text-center text-muted py-3">No items/parts listed.</td></tr>';
            } else {
                list.forEach(function (part) { total += partMotorpoolBillableCostV32(part, preferUsedQty); body += costPartRowV32(part, preferUsedQty); });
            }
            return `<div class="table-responsive assessment-cost-table-wrap-v80"><table class="table table-bordered parts-table assessment-parts-table align-middle mb-0">${costPartsHeaderV32(qtyLabel)}<tbody>${body}</tbody></table>${list.length ? assessmentCostFooterV32(total, 4) : ''}</div>`;
        }

        function rowData(row) {
            try {
                if (!row) return {};
                if (row.getAttribute && row.getAttribute('data-ris')) {
                    return JSON.parse(row.getAttribute('data-ris') || '{}');
                }
                const parentRow = row.closest ? row.closest('tr.ris-row') : null;
                if (parentRow && parentRow.getAttribute('data-ris')) {
                    return JSON.parse(parentRow.getAttribute('data-ris') || '{}');
                }
                return {};
            } catch (error) {
                console.error('Unable to read RIS row data:', error);
                return {};
            }
        }

        function findRisRowByIdV1(risId) {
            const targetId = String(risId || '').trim();
            if (!targetId) return null;
            const rows = document.querySelectorAll('tr.ris-row[data-ris]');
            for (const row of rows) {
                const data = rowData(row);
                if (String(data.ris_id || '').trim() === targetId) return row;
            }
            return null;
        }

        function openQualityCheckModalV1ById(risId, btn) {
            const cleanRisId = String(risId || (btn && btn.dataset ? btn.dataset.risId : '') || '').trim();
            if (!cleanRisId) {
                Swal.fire({ icon: 'error', title: 'Unable to open Quality Check', text: 'RIS ID was not found.', confirmButtonColor: '#07b83f' });
                return false;
            }

            if (btn) {
                btn.disabled = true;
                btn.dataset.originalHtml = btn.dataset.originalHtml || btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading';
            }

            const formData = new FormData();
            formData.append('action', 'fetch_quality_check_modal_data_v1');
            formData.append('ris_id', cleanRisId);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Network error while loading Quality Check.');
                    return response.json();
                })
                .then(function (result) {
                    if (!result || !result.success) throw new Error((result && result.message) || 'Unable to load Quality Check data.');
                    openQualityCheckModalV1FromData(result.data || {});
                })
                .catch(function (error) {
                    console.error('Quality Check open error:', error);
                    const row = findRisRowByIdV1(cleanRisId) || (btn && btn.closest ? btn.closest('tr.ris-row') : null);
                    if (row) {
                        openQualityCheckModalV1(row);
                        return;
                    }
                    Swal.fire({ icon: 'error', title: 'Unable to open Quality Check', text: error.message || 'Please refresh the page and try again.', confirmButtonColor: '#07b83f' });
                })
                .finally(function () {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = btn.dataset.originalHtml || '<i class="bi bi-patch-check me-1"></i>Quality Check';
                    }
                });
            return false;
        }

        function infoGrid(data) {
            return `
        <div class="info-item"><small>RIS No.</small><strong>${esc(data.ris_number)}</strong></div>
        <div class="info-item"><small>Branch</small><strong>${esc(data.display_branch_name || data.branch_name || (data.branch_id ? 'Branch #' + data.branch_id : 'N/A'))}</strong></div>
        <div class="info-item"><small>Plate No.</small><strong>${esc(data.plate_no)}</strong></div>
        <div class="info-item"><small>Vehicle ID</small><strong>${esc(data.vehicle_id)}</strong></div>
        <div class="info-item"><small>Vehicle Details</small><strong>${esc(data.vehicle_details)}</strong></div>
        <div class="info-item"><small>Status</small><strong>${esc(data.workflow_status)}</strong></div>
    `;
        }

        function renderAssessmentPartsTableV26(data) {
            let assessment = [];
            try { assessment = JSON.parse(data.assessment_json || '[]'); } catch (error) { assessment = []; }

            if (!assessment.length) {
                if (!data.repairs_summary && !data.parts_summary) return '<div class="text-center text-muted py-3">No assessment yet.</div>';
                return `
            <div class="assessment-repair-card">
                <div class="assessment-repair-title">Repair Work Required</div>
                <div class="assessment-repair-text">${esc(data.repairs_summary || 'No repair work listed.')}</div>
                <div class="assessment-repair-title">Items / Parts Needed</div>
                <div class="assessment-repair-text mb-0">${esc(data.parts_summary || 'No items/parts listed.').replace(/\n/g, '<br>')}</div>
            </div>`;
            }

            let html = '';
            assessment.forEach(function (repairItem, index) {
                const repairTitle = repairItem.repair || `Repair ${index + 1}`;
                const parts = Array.isArray(repairItem.parts) ? repairItem.parts : [];
                html += `
            <div class="assessment-repair-card">
                <div class="assessment-repair-title">Repair Work Required #${index + 1}</div>
                <div class="assessment-repair-text">${esc(repairTitle)}</div>
                ${renderCostPartsTableV32(parts, 'Quantity', false)}
            </div>`;
            });
            const grandTotal = assessment.reduce((sum, repairItem) => sum + repairGrandTotalV52(repairItem, false), 0);
            html += `<div class="assessment-grand-total-v52 mt-3 text-end">Grand Total Cost: <strong>${moneyPhpStyleV32(grandTotal)}</strong></div>`;

            const assessedBy = assessment[0]?.assessed_by_global || assessment[0]?.assessed_by || '';
            if (assessedBy) {
                html += `<div class="mt-3"><label class="form-label">Assessed By</label><div class="form-control bg-light">${esc(assessedBy)}</div></div>`;
            }
            return html;
        }

        function openRisDetailsModal(row) {
            const data = rowData(row);

            document.getElementById('detailsTitle').textContent = 'RIS Details - ' + (data.ris_number || '');
            document.getElementById('detailsGrid').innerHTML = infoGrid(data) + `
        <div class="info-item"><small>Endorsed By</small><strong>${esc(data.endorsed_by)}</strong></div>
        <div class="info-item"><small>Received By</small><strong>${esc(data.received_by_name)}</strong></div>
        <div class="info-item"><small>Received Date/Time</small><strong>${esc(data.received_datetime)}</strong></div>
    `;
            document.getElementById('detailsConcerns').value = data.concerns || '';

            let detailsHtml = renderAssessmentPartsTableV26(data);
            const releaseInfo = collectReleaseDetailsV15(data);
            if ((String(data.workflow_status || data.status || '').toLowerCase().includes('release') || String(data.workflow_status || '').toLowerCase().includes('quality')) && releaseInfo) {
                const partsRows = Array.isArray(releaseInfo.parts_rows) ? releaseInfo.parts_rows : [];
                detailsHtml += `
            <div class="assessment-repair-card mt-3">
                <div class="assessment-repair-title">Parts Used / Replaced</div>
                <div class="table-responsive">
                    <table class="table table-bordered assessment-parts-table align-middle mb-0">
                        <thead><tr><th>Item No.</th><th>Quantity Used</th></tr></thead>
                        <tbody>${partsRows.length ? partsRows.map(part => `<tr><td>${esc(part.item_no)}</td><td>${esc(part.qty_used)}</td></tr>`).join('') : '<tr><td colspan="2" class="text-center text-muted py-3">No parts replaced / used.</td></tr>'}</tbody>
                    </table>
                </div>
            </div>`;
            }
            document.getElementById('detailsAssessment').innerHTML = detailsHtml;

            const approvalProofForm = document.getElementById('approvalProofFormV64');
            const approvalProofRisId = document.getElementById('approvalProofRisIdV64');
            const approvalProofFile = document.getElementById('approvalProofFileV64');
            const workflowStatus = String(data.workflow_status || data.status || '').trim().toLowerCase();
            if (approvalProofForm && approvalProofRisId) {
                if (workflowStatus === 'for approval') {
                    approvalProofForm.classList.remove('d-none');
                    approvalProofRisId.value = data.ris_id || '';
                    if (approvalProofFile) approvalProofFile.value = '';
                } else {
                    approvalProofForm.classList.add('d-none');
                    approvalProofRisId.value = '';
                    if (approvalProofFile) approvalProofFile.value = '';
                }
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('risDetailsModalV4')).show();
        }


        function openReturnVehicleModal(row) {
            const data = rowData(row);
            document.getElementById('returnRisIdV1').value = data.ris_id || '';
            document.getElementById('returnInfoGridV1').innerHTML = infoGrid(data);
            const remarks = document.getElementById('returnRemarksV1');
            if (remarks) remarks.value = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('returnVehicleModalV1')).show();
        }

        function openReceiveVehicleModal(row) {
            const data = rowData(row);
            const now = new Date();

            document.getElementById('receiveRisIdV4').value = data.ris_id || '';
            document.getElementById('receiveInfoGridV4').innerHTML = infoGrid(data);
            document.getElementById('receiveDateV4').value = now.toISOString().slice(0, 10);
            document.getElementById('receiveTimeV4').value = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
            ['receivePhotoFrontV23', 'receivePhotoLeftV23', 'receivePhotoRightV23', 'receivePhotoBackV23'].forEach(function (id) {
                const input = document.getElementById(id);
                if (input) input.value = '';
            });
            ['receivePreviewFrontV23', 'receivePreviewLeftV23', 'receivePreviewRightV23', 'receivePreviewBackV23'].forEach(function (id) {
                const preview = document.getElementById(id);
                if (preview) preview.innerHTML = '';
            });

            bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleReceivedWorkflowModalV4')).show();
        }

        document.querySelectorAll('.receive-angle-photo').forEach(function (input) {
            input.addEventListener('change', function () {
                const previewId = this.dataset.preview || '';
                const label = this.dataset.label || 'Vehicle Photo';
                const grid = document.getElementById(previewId);
                if (!grid) return;
                grid.innerHTML = '';

                const file = this.files && this.files[0] ? this.files[0] : null;
                if (!file || !file.type.startsWith('image/')) return;

                const url = URL.createObjectURL(file);
                grid.insertAdjacentHTML('beforeend', `
            <div class="vehicle-received-proof-preview-card">
                <img src="${url}" alt="${esc(label)} preview">
                <small class="text-muted d-block mt-1"><strong>${esc(label)}</strong><br>${esc(file.name)}<br>${new Date().toLocaleString()}</small>
            </div>
        `);
            });
        });


        document.getElementById('returnVehicleFormV1')?.addEventListener('submit', function (event) {
            const remarks = document.getElementById('returnRemarksV1');
            if (!remarks || remarks.value.trim() === '') {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Remarks required',
                    text: 'Please enter remarks before returning this request to Branch Admin.',
                    confirmButtonColor: '#07b83f'
                });
            }
        });

        document.getElementById('vehicleReceivedWorkflowFormV23')?.addEventListener('submit', function (event) {
            const requiredInputs = [
                ['receivePhotoFrontV23', 'Front photo'],
                ['receivePhotoLeftV23', 'Left-side photo'],
                ['receivePhotoRightV23', 'Right-side photo'],
                ['receivePhotoBackV23', 'Back photo']
            ];

            for (const [id, label] of requiredInputs) {
                const input = document.getElementById(id);
                if (!input || !input.files || input.files.length === 0) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Vehicle photo required',
                        text: label + ' is required before saving received vehicle.',
                        confirmButtonColor: '#07b83f'
                    });
                    return;
                }
            }
        });

        let repairIndexV4 = 0;

        function assessmentInventoryDatalistV26() {
            let list = document.getElementById('assessmentInventoryListV26');
            if (!list) {
                list = document.createElement('datalist');
                list.id = 'assessmentInventoryListV26';
                list.innerHTML = renderMotorpoolInventoryDatalistV24();
                document.body.appendChild(list);
            }
        }

        function normalizeAssessmentPartExistingV26(itemNo, description, specification, quantity, unitCost, inventoryId, itemLabel, availableQty, itemName) {
            const selected = findMotorpoolInventoryOptionV24(itemLabel || itemNo || itemName || '', inventoryId || '');
            const label = itemLabel || (selected ? (selected.label || ((selected.item_code || '') + ' - ' + (selected.item_name || ''))) : itemNo);
            const cost = unitCost !== undefined && unitCost !== null && String(unitCost).trim() !== '' ? unitCost : (selected ? selected.unit_cost : '');
            const available = availableQty !== undefined && availableQty !== null && String(availableQty).trim() !== '' ? availableQty : (selected ? selected.current_stock : '');
            return { selected, label, cost, available };
        }

        function updateAssessmentGrandTotalV55() {
            let grandTotal = 0;
            document.querySelectorAll('#repairBlocksV4 .repair-block').forEach(function (block) {
                const labor = Number(String(block.querySelector('.repair-cost-input')?.value || '0').replace(/,/g, '')) || 0;
                let partsTotal = 0;
                block.querySelectorAll('.part-estimated-cost').forEach(function (input) {
                    partsTotal += Number(String(input.value || '0').replace(/,/g, '')) || 0;
                });
                grandTotal += labor + partsTotal;
            });
            const grandEl = document.querySelector('#assessmentGrandTotalLiveV55 strong');
            if (grandEl) grandEl.textContent = moneyPhpStyleV32(grandTotal);
        }
        function updateAssessmentBlockTotalV52(block) {
            updateAssessmentGrandTotalV55();
        }
        function updateAssessmentPartEstimatedCostV26(row) {
            const qtyVal = Number(row.querySelector('.part-quantity')?.value || 0) || 0;
            const costVal = Number(row.querySelector('.part-unit-cost')?.value || 0) || 0;
            const totalInput = row.querySelector('.part-estimated-cost');
            if (totalInput) totalInput.value = (qtyVal * costVal).toFixed(2);
            updateAssessmentBlockTotalV52(row.closest('.repair-block'));
        }

        function applyAssessmentInventorySelectionV26(input) {
            const row = input.closest('.part-row-v4');
            if (!row) return;
            const selected = findMotorpoolInventoryOptionV24(input.value, row.querySelector('.part-inventory-id')?.value || '');
            const hiddenId = row.querySelector('.part-inventory-id');
            const itemCodeHidden = row.querySelector('.part-item-code');
            const itemNameHidden = row.querySelector('.part-item-name');
            const descriptionInput = row.querySelector('.part-description');
            const specificationInput = row.querySelector('.part-specification');
            const availableInput = row.querySelector('.part-available');
            const costInput = row.querySelector('.part-unit-cost');
            const sourceText = row.querySelector('.part-cost-source');

            if (selected) {
                if (hiddenId) hiddenId.value = selected.item_id || '';
                if (itemCodeHidden) itemCodeHidden.value = selected.item_code || '';
                if (itemNameHidden) itemNameHidden.value = selected.item_name || '';
                if (descriptionInput && !descriptionInput.value) descriptionInput.value = selected.description || selected.item_name || '';
                if (specificationInput && !specificationInput.value && selected.unit_type) specificationInput.value = selected.unit_type;
                if (availableInput) availableInput.value = selected.current_stock || 0;
                if (costInput) {
                    const autoCost = Number(selected.unit_cost || 0).toFixed(2);
                    const userEdited = costInput.dataset.userEdited === '1';
                    const previousAuto = costInput.dataset.autoCost || '';
                    if (!userEdited || !costInput.value || costInput.value === previousAuto) {
                        costInput.value = autoCost;
                        costInput.dataset.userEdited = '0';
                    }
                    costInput.dataset.autoCost = autoCost;
                }
                if (sourceText) sourceText.textContent = '';
            } else {
                if (hiddenId) hiddenId.value = '';
                if (itemCodeHidden) itemCodeHidden.value = input.value || '';
                if (itemNameHidden) itemNameHidden.value = input.value || '';
                if (availableInput) availableInput.value = '';
                if (sourceText) sourceText.textContent = '';
            }
            updateAssessmentPartEstimatedCostV26(row);
        }

        function bindAssessmentPartRowV26(row) {
            const itemInput = row.querySelector('.part-item-no');
            if (itemInput) {
                itemInput.addEventListener('input', function () { applyAssessmentInventorySelectionV26(this); });
                itemInput.addEventListener('change', function () { applyAssessmentInventorySelectionV26(this); });
                applyAssessmentInventorySelectionV26(itemInput);
            }
            row.querySelectorAll('.part-quantity, .part-unit-cost').forEach(function (input) {
                input.addEventListener('input', function () {
                    if (input.classList.contains('part-unit-cost')) input.dataset.userEdited = '1';
                    updateAssessmentPartEstimatedCostV26(row);
                });
            });
        }

        function partRowV4(itemNo = '', description = '', specification = '', quantity = '', unitCost = '', inventoryId = '', itemLabel = '', availableQty = '', itemName = '') {
            assessmentInventoryDatalistV26();
            const existing = normalizeAssessmentPartExistingV26(itemNo, description, specification, quantity, unitCost, inventoryId, itemLabel, availableQty, itemName);
            const totalCost = (Number(quantity || 0) || 0) * (Number(existing.cost || 0) || 0);
            return `
        <tr class="part-row-v4">
            <td style="min-width: 230px;">
                <input type="hidden" class="part-inventory-id" value="${esc(inventoryId || (existing.selected ? existing.selected.item_id : ''))}">
                <input type="hidden" class="part-item-code" value="${esc(itemNo || (existing.selected ? existing.selected.item_code : ''))}">
                <input type="hidden" class="part-item-name" value="${esc(itemName || (existing.selected ? existing.selected.item_name : ''))}">
                <input class="form-control part-item-no" list="assessmentInventoryListV26" placeholder="Select inventory item or type manually" value="${esc(existing.label)}" required>
            </td>
            <td style="min-width: 220px;">
                <input class="form-control part-description" placeholder="Description" value="${esc(description || (existing.selected ? existing.selected.description : ''))}" required>
            </td>
            <td style="min-width: 170px;">
                <input class="form-control part-specification" placeholder="Specification" value="${esc(specification || (existing.selected ? existing.selected.unit_type : ''))}" required>
            </td>
            <td style="min-width: 120px;">
                <input type="number" min="1" step="1" class="form-control part-quantity" placeholder="Qty Needed" value="${esc(quantity)}" required>
            </td>
            <td style="min-width: 120px;">
                <input type="number" class="form-control part-available" placeholder="Available" value="${esc(existing.available)}" readonly>
            </td>
            <td style="min-width: 120px;">
                <input type="number" min="0" step="0.01" class="form-control part-unit-cost" placeholder="Unit Cost" value="${esc(existing.cost)}" required>
                <small class="text-muted part-cost-source">${existing.selected ? 'Cost from Motorpool Inventory' : 'Manual item. Type unit cost.'}</small>
            </td>
            <td style="min-width: 130px;">
                <input type="number" class="form-control part-estimated-cost" value="${esc(totalCost.toFixed(2))}" readonly>
            </td>
            <td class="text-center" style="width: 95px;">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removePartRowV4(this)" title="Delete part row">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
        }

        function addRepairBlockV4(existing = null) {
            const repairId = repairIndexV4++;
            const repair = existing?.repair || '';
            const repairCost = existing?.repair_cost || existing?.labor_cost || existing?.service_cost || '';
            const parts = existing?.parts || [];
            let rows = '';

            parts.forEach(function (part) {
                rows += partRowV4(
                    part.item_no || part.item_code || '',
                    part.description || part.name || '',
                    part.specification || '',
                    part.quantity || part.qty || '',
                    part.unit_cost || '',
                    part.inventory_item_id || '',
                    part.item_label || '',
                    part.available_quantity || '',
                    part.item_name || ''
                );
            });

            document.getElementById('repairBlocksV4').insertAdjacentHTML('beforeend', `
        <div class="repair-block" data-repair-index="${repairId}">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">Repair Work Required</label>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.repair-block').remove(); updateAssessmentGrandTotalV55();">
                    <i class="bi bi-trash"></i>
                </button>
            </div>

            <textarea class="form-control repair-text mb-3" rows="2" placeholder="Describe repair to make" required>${esc(repair)}</textarea>

            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label mb-1">Repair Cost / Labor Cost</label>
                    <input type="number" min="0" step="0.01" class="form-control repair-cost-input" placeholder="Amount to pay for this repair" value="${esc(repairCost)}">
                </div>
            </div>

            <div class="table-responsive assessment-parts-table-wrapper">
                <table class="table table-bordered parts-table assessment-parts-table-wide align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item / Part</th>
                            <th>Description</th>
                            <th>Specification</th>
                            <th>Qty Needed</th>
                            <th>Qty Available</th>
                            <th>Unit Cost</th>
                            <th>Estimated Cost</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="partsBody${repairId}">
                        ${rows}
                    </tbody>
                </table>
            </div>


            <div class="add-part-row-wrap">
                <button type="button" class="btn btn-outline-success btn-sm" onclick="addPartRowV4(${repairId})">
                    <i class="bi bi-plus"></i> Add Parts
                </button>
            </div>
        </div>
    `);
            const block = document.querySelector(`#repairBlocksV4 .repair-block[data-repair-index="${repairId}"]`);
            if (block) {
                block.querySelectorAll('.part-row-v4').forEach(bindAssessmentPartRowV26);
                const repairCostInput = block.querySelector('.repair-cost-input');
                if (repairCostInput) repairCostInput.addEventListener('input', function () { updateAssessmentBlockTotalV52(block); });
                updateAssessmentBlockTotalV52(block);
            }
        }

        function addPartRowV4(repairId) {
            const body = document.getElementById('partsBody' + repairId);
            body.insertAdjacentHTML('beforeend', partRowV4());
            bindAssessmentPartRowV26(body.lastElementChild);
        }

        function removePartRowV4(button) {
            const block = button.closest('.repair-block');
            button.closest('tr').remove();
            updateAssessmentBlockTotalV52(block);
        }

        function openAssessmentModal(row) {
            const data = rowData(row);
            const wrapper = document.getElementById('repairBlocksV4');
            let existing = [];

            document.getElementById('assessmentRisIdV4').value = data.ris_id || '';
            document.getElementById('assessmentInfoGridV4').innerHTML = infoGrid(data);
            wrapper.innerHTML = '';
            document.getElementById('assessmentAssessedByV4').value = '';
            repairIndexV4 = 0;

            try {
                existing = JSON.parse(data.assessment_json || '[]');
            } catch (error) {
                existing = [];
            }

            if (existing.length) {
                const globalAssessedBy = existing[0]?.assessed_by_global || existing[0]?.assessed_by || '';
                document.getElementById('assessmentAssessedByV4').value = globalAssessedBy;
                existing.forEach(function (item) {
                    addRepairBlockV4(item);
                });
            } else {
                addRepairBlockV4();
            }
            updateAssessmentGrandTotalV55();

            bootstrap.Modal.getOrCreateInstance(document.getElementById('assessmentWorkflowModalV4')).show();
        }

        document.getElementById('assessmentWorkflowFormV4')?.addEventListener('submit', function (event) {
            const output = [];
            const assessedBy = document.getElementById('assessmentAssessedByV4').value.trim();

            document.querySelectorAll('#repairBlocksV4 .repair-block').forEach(function (block) {
                const repair = block.querySelector('.repair-text').value.trim();
                const repairCost = block.querySelector('.repair-cost-input')?.value.trim() || '';
                const parts = [];

                block.querySelectorAll('tbody tr').forEach(function (row) {
                    const itemInput = row.querySelector('.part-item-no');
                    if (itemInput) applyAssessmentInventorySelectionV26(itemInput);
                    const itemNo = row.querySelector('.part-item-code')?.value.trim() || itemInput?.value.trim() || '';
                    const itemLabel = itemInput?.value.trim() || '';
                    const itemName = row.querySelector('.part-item-name')?.value.trim() || '';
                    const inventoryId = row.querySelector('.part-inventory-id')?.value.trim() || '';
                    const description = row.querySelector('.part-description').value.trim();
                    const specification = row.querySelector('.part-specification').value.trim();
                    const quantity = row.querySelector('.part-quantity').value.trim();
                    const availableQuantity = row.querySelector('.part-available')?.value.trim() || '';
                    const unitCost = row.querySelector('.part-unit-cost')?.value.trim() || '';
                    const estimatedCost = row.querySelector('.part-estimated-cost')?.value.trim() || '';
                    if (itemNo || itemLabel || description || specification || quantity || unitCost) {
                        parts.push({
                            inventory_item_id: inventoryId,
                            item_no: itemNo,
                            item_name: itemName,
                            item_label: itemLabel,
                            description: description,
                            specification: specification,
                            quantity: quantity,
                            available_quantity: availableQuantity,
                            unit_cost: unitCost,
                            estimated_total_cost: estimatedCost,
                            total_cost: estimatedCost
                        });
                    }
                });

                if (repair) {
                    const repairCostNumber = Number(String(repairCost || '0').replace(/,/g, '')) || 0;
                    const partsEstimatedCost = parts.reduce(function (sum, part) {
                        return sum + (Number(String(part.estimated_total_cost || part.total_cost || '0').replace(/,/g, '')) || 0);
                    }, 0);
                    const repairTotalCost = repairCostNumber + partsEstimatedCost;
                    output.push({
                        repair,
                        repair_cost: repairCostNumber.toFixed(2),
                        labor_cost: repairCostNumber.toFixed(2),
                        parts_estimated_cost: partsEstimatedCost.toFixed(2),
                        repair_total_cost: repairTotalCost.toFixed(2),
                        total_cost: repairTotalCost.toFixed(2),
                        parts
                    });
                }
            });

            if (!output.length) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing assessment',
                    text: 'Please enter at least one repair to make.',
                    confirmButtonColor: '#07b83f'
                });
                return;
            }

            if (!assessedBy) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Assessed By',
                    text: 'Please enter the mechanic or assessor name.',
                    confirmButtonColor: '#07b83f'
                });
                return;
            }

            output.forEach(function (item) {
                item.assessed_by_global = assessedBy;
            });

            const hasInvalidParts = output.some(function (item) {
                if (!item.parts || !item.parts.length) return false;
                return item.parts.some(function (part) {
                    return !part.item_no || !part.description || !part.specification || !part.quantity || part.unit_cost === '';
                });
            });

            if (hasInvalidParts) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete parts needed',
                    text: 'Complete all required fields: Item, Description, Specification, Qty Needed, and Unit Cost.',
                    confirmButtonColor: '#07b83f'
                });
                return;
            }

            document.getElementById('assessmentJsonV4').value = JSON.stringify(output);
        });


        function renderPartsCompletionAssessmentV10(data) {
            let assessment = [];
            try { assessment = JSON.parse(data.assessment_json || '[]'); } catch (error) { assessment = []; }
            if (!assessment.length) return `<div class="alert alert-danger mb-0">No assessment details found. Please go back to assessment first.</div>`;

            let html = '';
            assessment.forEach(function (repairItem, index) {
                const repairTitle = repairItem.repair || `Repair ${index + 1}`;
                const repairCost = repairItem.repair_cost || repairItem.labor_cost || repairItem.service_cost || '';
                const parts = Array.isArray(repairItem.parts) ? repairItem.parts : [];
                const repairCostHtml = repairCost !== '' ? `<div class="col-md-4"><label class="form-label mb-1">Repair Cost</label><div class="form-control bg-light">${moneyPhpStyleV32(repairCost)}</div></div>` : '';
                html += `<div class="repair-block mb-3"><div class="row g-2 mb-2"><div class="${repairCostHtml ? 'col-md-8' : 'col-12'}"><label class="form-label mb-1">Repair Work Required</label><div class="form-control bg-light">${esc(repairTitle)}</div></div>${repairCostHtml}</div>${renderCostPartsTableV32(parts, 'Quantity', false)}</div>`;
            });
            const grandTotal = assessment.reduce((sum, repairItem) => sum + repairGrandTotalV52(repairItem, false), 0);
            html += `<div class="assessment-grand-total-v52 mt-3 text-end">Grand Total Cost: <strong>${moneyPhpStyleV32(grandTotal)}</strong></div>`;
            const assessedBy = assessment[0]?.assessed_by_global || assessment[0]?.assessed_by || '';
            if (assessedBy) html += `<div class="mt-3"><label class="form-label">Assessed By</label><div class="form-control bg-light">${esc(assessedBy)}</div></div>`;
            return html;
        }

        function getPartsNeededForCompletionV4(data) {
            let assessment = [];
            try { assessment = JSON.parse(data.assessment_json || '[]'); } catch (error) { assessment = []; }
            const rows = [];
            assessment.forEach(function (repairItem) {
                const parts = Array.isArray(repairItem.parts) ? repairItem.parts : [];
                parts.forEach(function (part) {
                    rows.push({
                        item_no: partItemNoV32(part),
                        description: partDescriptionV32(part),
                        specification: partSpecificationV32(part),
                        needed_quantity: partQtyNeededV32(part),
                        available_quantity: '',
                        unit_cost: partSourceByV32(part) === 'branch' ? 0 : partUnitCostV32(part),
                        estimated_total_cost: partMotorpoolBillableCostV32(part, false),
                        total_cost: partMotorpoolBillableCostV32(part, false),
                        original_estimated_total_cost: partEstimatedCostV32(part, false),
                        source_by: partSourceByV32(part),
                        source_label: partSourceLabelV32(part),
                        source_status: part.source_status || (partSourceByV32(part) === 'branch' ? 'pending_source' : ''),
                        branch_sourced: partSourceByV32(part) === 'branch' ? 1 : 0,
                        motorpool_billable_cost: partMotorpoolBillableCostV32(part, false),
                        cost_source: partCostSourceV32(part),
                        inventory_item_id: part.inventory_item_id || part.item_id || '',
                        item_code: part.item_code || part.item_no || ''
                    });
                });
            });
            return rows;
        }

        function partsCompletionNumberV4(value) {
            const num = parseFloat(String(value || '').replace(/,/g, ''));
            return isNaN(num) ? 0 : num;
        }

        function renderPartsCompletionAvailableTableV4(data) {
            const body = document.getElementById('partsCompletionAvailableBodyV4');
            if (!body) return;

            const neededRows = getPartsNeededForCompletionV4(data);
            let savedRows = [];
            try { savedRows = JSON.parse(data.parts_available_json || '[]'); } catch (error) { savedRows = []; }

            const rows = neededRows.length ? neededRows : savedRows;
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No items/parts listed.</td></tr>';
                updatePartsCompletionProgressV4();
                return;
            }

            body.innerHTML = rows.map(function (row, index) {
                const saved = savedRows[index] || {};
                const neededQty = row.needed_quantity || saved.needed_quantity || '';
                let availableQty = saved.available_quantity || row.available_quantity || '';
                const neededQtyNumber = partsCompletionNumberV4(neededQty);
                const availableQtyNumber = partsCompletionNumberV4(availableQty);
                const isLocked = String(saved.item_completed || row.item_completed || '') === '1' || (neededQtyNumber > 0 && availableQtyNumber >= neededQtyNumber && String(data.confirmed_complete || '') === '1');
                if (neededQtyNumber > 0 && availableQtyNumber > neededQtyNumber) availableQty = neededQty;
                const sourceBy = String(row.source_by || saved.source_by || '').toLowerCase() === 'branch' || String(row.branch_sourced || saved.branch_sourced || '') === '1' ? 'branch' : 'motorpool';
                const sourceLabel = sourceBy === 'branch' ? 'Branch Source' : 'Motorpool Source';
                const unitCostNumber = sourceBy === 'branch' ? 0 : (Number(row.unit_cost ?? saved.unit_cost ?? 0) || 0);
                const estimatedCostNumber = sourceBy === 'branch' ? 0 : (Number(row.motorpool_billable_cost ?? saved.motorpool_billable_cost ?? row.estimated_total_cost ?? row.estimated_cost ?? row.total_cost ?? saved.estimated_total_cost ?? saved.total_cost ?? (neededQtyNumber * unitCostNumber)) || 0);
                const costSource = row.cost_source || saved.cost_source || sourceLabel;
                const sourceBadge = `<span class="badge ${sourceBy === 'branch' ? 'bg-primary' : 'bg-success'}">${sourceLabel}</span>${sourceBy === 'branch' ? '<div class="small text-muted mt-1">No Motorpool cost</div>' : ''}`;
                const badge = isLocked ? '<span class="badge bg-success ms-2">Complete / Locked</span>' : '<span class="badge bg-warning text-dark ms-2">Editable</span>';
                const itemNo = row.item_no || saved.item_no || '';
                const description = row.description || saved.description || '';
                const specification = row.specification || saved.specification || '';
                return `
            <tr data-item-completed="${isLocked ? '1' : '0'}">
                <td><span class="parts-completion-readonly-text">${esc(itemNo)}</span><input type="hidden" class="parts-completion-item" data-field="item_no" value="${esc(itemNo)}"></td>
                <td><span class="parts-completion-readonly-text">${esc(description)}</span><input type="hidden" class="parts-completion-item" data-field="description" value="${esc(description)}"></td>
                <td><span class="parts-completion-readonly-text">${esc(specification)}</span><input type="hidden" class="parts-completion-item" data-field="specification" value="${esc(specification)}"></td>
                <td><span class="parts-completion-readonly-text">${esc(neededQty)}</span><input type="hidden" class="parts-completion-item parts-completion-needed" data-field="needed_quantity" value="${esc(neededQty)}"></td>
                <td>${sourceBadge}<input type="hidden" class="parts-completion-item" data-field="source_by" value="${esc(sourceBy)}"><input type="hidden" class="parts-completion-item" data-field="source_label" value="${esc(sourceLabel)}"><input type="hidden" class="parts-completion-item" data-field="branch_sourced" value="${sourceBy === 'branch' ? '1' : '0'}"></td>
                <td><span class="parts-completion-readonly-text">${moneyPhpStyleV32(unitCostNumber)}</span><input type="hidden" class="parts-completion-item" data-field="unit_cost" value="${esc(unitCostNumber.toFixed(2))}"></td>
                <td><span class="parts-completion-readonly-text fw-semibold ${sourceBy === 'branch' ? 'text-muted' : 'text-success'}">${moneyPhpStyleV32(estimatedCostNumber)}</span><input type="hidden" class="parts-completion-item" data-field="estimated_total_cost" value="${esc(estimatedCostNumber.toFixed(2))}"><input type="hidden" class="parts-completion-item" data-field="motorpool_billable_cost" value="${esc(estimatedCostNumber.toFixed(2))}"><input type="hidden" class="parts-completion-item" data-field="cost_source" value="${esc(costSource)}"></td>
                <td><div class="d-flex align-items-center gap-2 justify-content-center parts-completion-available-wrap"><input type="number" step="0.01" min="0" max="${esc(neededQty)}" class="form-control form-control-sm parts-completion-item parts-completion-available text-center no-spinner" data-field="available_quantity" value="${esc(availableQty)}" placeholder="Qty available" ${isLocked ? 'readonly' : ''}>${badge}</div></td>
            </tr>`;
            }).join('');
            updatePartsCompletionProgressV4();
        }

        function collectPartsCompletionAvailableV4() {
            const rows = [];
            document.querySelectorAll('#partsCompletionAvailableBodyV4 tr').forEach(function (tr) {
                const fields = tr.querySelectorAll('.parts-completion-item');
                if (!fields.length) return;
                const item = {};
                fields.forEach(function (field) {
                    if (field.dataset.field === 'available_quantity') {
                        const neededInput = tr.querySelector('[data-field="needed_quantity"]');
                        const neededQty = partsCompletionNumberV4(neededInput?.value || '0');
                        let availableQty = partsCompletionNumberV4(field.value || '0');
                        if (availableQty < 0) availableQty = 0;
                        if (neededQty > 0 && availableQty > neededQty) {
                            availableQty = neededQty;
                            field.value = neededQty;
                        }
                        item[field.dataset.field] = String(field.value).trim();
                    } else {
                        item[field.dataset.field] = field.value.trim();
                    }
                });
                const neededQty = partsCompletionNumberV4(item.needed_quantity || '0');
                const availableQty = partsCompletionNumberV4(item.available_quantity || '0');
                item.item_completed = (neededQty > 0 && availableQty >= neededQty) ? 1 : 0;
                rows.push(item);
            });
            document.getElementById('partsCompletionJsonV4').value = JSON.stringify(rows);
            updatePartsCompletionProgressV4(rows);
            return rows;
        }

        function updatePartsCompletionProgressV4(existingRows) {
            const rows = existingRows || Array.from(document.querySelectorAll('#partsCompletionAvailableBodyV4 tr')).map(function (tr) {
                const neededQty = partsCompletionNumberV4(tr.querySelector('[data-field="needed_quantity"]')?.value || '0');
                const availableQty = partsCompletionNumberV4(tr.querySelector('[data-field="available_quantity"]')?.value || '0');
                return { neededQty, availableQty };
            }).filter(function (row) { return row.neededQty > 0; });

            const total = rows.length;
            const complete = rows.filter(function (row) {
                const neededQty = partsCompletionNumberV4(row.needed_quantity || row.neededQty || '0');
                const availableQty = partsCompletionNumberV4(row.available_quantity || row.availableQty || '0');
                return neededQty > 0 && availableQty >= neededQty;
            }).length;
            const percent = total ? Math.round((complete / total) * 100) : 0;

            const text = document.getElementById('partsCompletionProgressTextV4');
            const bar = document.getElementById('partsCompletionProgressBarV4');
            if (text) text.textContent = `${complete} of ${total} items complete`;
            if (bar) {
                bar.style.width = `${percent}%`;
                bar.textContent = `${percent}%`;
                bar.classList.toggle('bg-danger', percent < 50);
                bar.classList.toggle('bg-warning', percent >= 50 && percent < 100);
                bar.classList.toggle('bg-success', percent === 100);
            }

            const confirm = document.getElementById('partsCompletionConfirmV4');
            if (confirm) {
                confirm.disabled = !(total > 0 && complete === total);
                if (confirm.disabled) confirm.checked = false;
            }
            updatePartsCompletionButtonsV4(total > 0 && complete === total);
        }

        function updatePartsCompletionButtonsV4(isComplete) {
            const complete = typeof isComplete === 'boolean' ? isComplete : !document.getElementById('partsCompletionConfirmV4')?.disabled;
            const confirmed = document.getElementById('partsCompletionConfirmV4')?.checked;
            document.getElementById('partsCompletionProceedBtnV4')?.classList.toggle('d-none', !(complete && confirmed));
        }

        function openPartsCompletionModalV4(row) {
            const data = rowData(row);

            document.getElementById('partsCompletionRisIdV4').value = data.ris_id || '';
            document.getElementById('partsCompletionActionV4').value = 'save_parts_completion_v1';
            document.getElementById('partsCompletionInfoGridV4').innerHTML = infoGrid(data);
            document.getElementById('partsCompletionAssessmentWrapV4').innerHTML = renderPartsCompletionAssessmentV10(data);
            renderPartsCompletionAvailableTableV4(data);
            document.getElementById('partsCompletionConfirmV4').checked = (String(data.confirmed_complete || '') === '1');
            collectPartsCompletionAvailableV4();
            updatePartsCompletionButtonsV4();

            bootstrap.Modal.getOrCreateInstance(document.getElementById('partsCompletionModalV4')).show();
        }

        document.getElementById('partsCompletionConfirmV4')?.addEventListener('change', function () {
            updatePartsCompletionButtonsV4();
        });
        document.addEventListener('input', function (event) {
            if (event.target.classList && event.target.classList.contains('parts-completion-item')) {
                if (event.target.dataset.field === 'available_quantity') {
                    const row = event.target.closest('tr');
                    const neededQty = partsCompletionNumberV4(row?.querySelector('[data-field="needed_quantity"]')?.value || '0');
                    let availableQty = partsCompletionNumberV4(event.target.value || '0');
                    if (availableQty < 0) event.target.value = 0;
                    if (neededQty > 0 && availableQty > neededQty) event.target.value = neededQty;
                }
                collectPartsCompletionAvailableV4();
            }
        });

        document.getElementById('partsCompletionSaveBtnV4')?.addEventListener('click', function () {
            document.getElementById('partsCompletionActionV4').value = 'save_parts_completion_v1';
        });

        document.getElementById('partsCompletionProceedBtnV4')?.addEventListener('click', function () {
            document.getElementById('partsCompletionActionV4').value = 'advance_workflow_v3';
        });

        document.getElementById('partsCompletionFormV4')?.addEventListener('submit', function (event) {
            const rows = collectPartsCompletionAvailableV4();
            const action = document.getElementById('partsCompletionActionV4').value;
            const confirmed = document.getElementById('partsCompletionConfirmV4').checked;
            const allComplete = rows.length > 0 && rows.every(function (row) { return String(row.item_completed) === '1'; });
            if (action === 'advance_workflow_v3' && (!confirmed || !allComplete)) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Parts not yet complete',
                    text: 'Please complete all item quantities first. Available quantity cannot exceed and must match the assessment quantity before proceeding.',
                    confirmButtonColor: '#07b83f'
                });
            }
        });

        function getAssessmentRepairItemsV11(data) {
            let assessment = [];
            try { assessment = JSON.parse(data.assessment_json || '[]'); } catch (error) { assessment = []; }
            if (Array.isArray(assessment) && assessment.length) {
                return assessment.map(function (item) {
                    return {
                        repair: String(item.repair || '').trim(),
                        repair_cost: item.repair_cost ?? item.labor_cost ?? item.service_cost ?? 0,
                        labor_cost: item.repair_cost ?? item.labor_cost ?? item.service_cost ?? 0,
                        parts: Array.isArray(item.parts) ? item.parts : []
                    };
                }).filter(item => item.repair !== '');
            }
            if (data.repairs_summary) {
                return String(data.repairs_summary).split(/\n+/).map(v => ({ repair: v.trim(), parts: [] })).filter(item => item.repair !== '');
            }
            return [];
        }

        function getSavedRepairMapV11(data) {
            let savedProgress = [];
            try { savedProgress = JSON.parse(data.repair_progress_json || '[]'); } catch (error) { savedProgress = []; }
            const map = {};
            if (Array.isArray(savedProgress)) {
                savedProgress.forEach(function (item) {
                    const key = String(item.repair || '').trim().toLowerCase();
                    if (key !== '') map[key] = item;
                });
            }
            return map;
        }

        function getAvailablePartsMapV11(data) {
            let completion = [];
            try { completion = JSON.parse(data.parts_available_json || '[]'); } catch (error) { completion = []; }
            const map = {};
            if (Array.isArray(completion)) {
                completion.forEach(function (item) {
                    const key = [item.item_no || '', item.description || '', item.specification || ''].join('|').toLowerCase();
                    map[key] = item;
                });
            }
            return map;
        }

        function startRepairSourceByV11(part) {
            const source = String((part && (part.source_by || part.parts_source_by || part.source_type || part.source || part.purchased_by)) || 'motorpool').trim().toLowerCase();
            const branchFlag = !!(part && (part.branch_sourced || part.branch_purchased));
            return (branchFlag || ['branch', 'branch admin', 'branch_admin', 'branch source', 'branch_source', 'branch sourced', 'branch_sourced'].includes(source)) ? 'branch' : 'motorpool';
        }

        function startRepairSourceBadgeV11(sourceBy) {
            return startRepairSourceByV11({ source_by: sourceBy }) === 'branch'
                ? '<span class="badge bg-info text-dark">Branch Source</span>'
                : '<span class="badge bg-success">Motorpool Source</span>';
        }

        function isReturnedFromQualityRepairV11(data) {
            const status = String(data.workflow_status || data.status || '').trim().toLowerCase();
            const action = String(data.action_taken || data.progress_summary || '').trim().toLowerCase();
            const hasQualityJson = String(data.quality_check_json || '').trim() !== '' && String(data.quality_check_json || '').trim() !== '[]';
            const serverFlag = String(data.is_quality_rework || '').trim() === '1' || data.is_quality_rework === true;
            return (status === 'for repair' || status === 'ongoing repair') && (
                serverFlag ||
                action.includes('returned from quality') ||
                (action.includes('quality') && (action.includes('return') || action.includes('rework'))) ||
                action.includes('quality failed') ||
                hasQualityJson
            );
        }

        function enrichRepairPartsV11(parts, savedParts, availableMap) {
            const savedMap = {};
            (Array.isArray(savedParts) ? savedParts : []).forEach(function (part) {
                const key = [part.item_no || '', part.description || '', part.specification || ''].join('|').toLowerCase();
                savedMap[key] = part;
            });

            return (Array.isArray(parts) ? parts : []).map(function (part) {
                const key = [part.item_no || '', part.description || '', part.specification || ''].join('|').toLowerCase();
                const saved = savedMap[key] || {};
                const available = availableMap[key] || {};
                const inventoryMatch = findMotorpoolInventoryOptionV24(
                    saved.inventory_item_id || saved.item_code || saved.item_no || part.item_no || part.item_name || part.description || available.item_no || '',
                    saved.inventory_item_id || ''
                );
                const neededQty = Number(part.quantity || part.qty || available.needed_quantity || saved.needed_quantity || 0) || 0;
                // Qty Available is only a reference from Motorpool Inventory / completed parts.
                // It must not auto-fill the quantity needed or quantity used.
                const availableQty = Number(
                    inventoryMatch ? inventoryMatch.current_stock : (saved.available_quantity || available.available_quantity || 0)
                ) || 0;
                const itemLabel = inventoryMatch
                    ? (inventoryMatch.label || ((inventoryMatch.item_code || '') + ' - ' + (inventoryMatch.item_name || '')))
                    : String(saved.item_label || saved.item_no || part.item_no || available.item_no || '').trim();
                return {
                    inventory_item_id: saved.inventory_item_id || (inventoryMatch ? inventoryMatch.item_id : ''),
                    item_no: String(saved.item_no || part.item_no || available.item_no || (inventoryMatch ? inventoryMatch.item_code : '') || '').trim(),
                    item_name: String(saved.item_name || (inventoryMatch ? inventoryMatch.item_name : '') || '').trim(),
                    item_label: itemLabel,
                    description: String(saved.description || part.description || part.name || available.description || (inventoryMatch ? inventoryMatch.description : '') || '').trim(),
                    specification: String(saved.specification || part.specification || available.specification || '').trim(),
                    needed_quantity: neededQty,
                    available_quantity: availableQty,
                    used_quantity: saved.used_quantity || part.used_quantity || part.quantity || part.qty || '',
                    source_by: startRepairSourceByV11(saved.source_by !== undefined ? saved : part),
                    branch_sourced: startRepairSourceByV11(saved.source_by !== undefined ? saved : part) === 'branch' ? 1 : 0,
                    source_label: startRepairSourceByV11(saved.source_by !== undefined ? saved : part) === 'branch' ? 'Branch Source' : 'Motorpool Source',
                    skip_inventory_deduction: saved.skip_inventory_deduction || part.skip_inventory_deduction || 0,
                    unit_cost: startRepairSourceByV11(saved.source_by !== undefined ? saved : part) === 'branch' ? 0 : (saved.unit_cost || (inventoryMatch ? inventoryMatch.unit_cost : '')),
                    total_cost: startRepairSourceByV11(saved.source_by !== undefined ? saved : part) === 'branch' ? 0 : (saved.total_cost || '')
                };
            });
        }

        function parseStartRepairRowsV11(data) {
            const repairItems = getAssessmentRepairItemsV11(data);
            const savedMap = getSavedRepairMapV11(data);
            const availableMap = getAvailablePartsMapV11(data);
            const returnedFromQuality = isReturnedFromQualityRepairV11(data);
            const reworkCycleKey = String(data.updated_at || data.motorpool_returned_at || data.action_taken || data.ris_id || '').replace(/[^A-Za-z0-9_\-]/g, '').slice(0, 80) || 'quality-return';

            const rows = repairItems.map(function (item) {
                const saved = savedMap[item.repair.toLowerCase()] || {};
                const savedParts = Array.isArray(saved.parts_used) ? saved.parts_used : [];
                const assessmentParts = Array.isArray(item.parts) ? item.parts : [];
                const parts = enrichRepairPartsV11(assessmentParts, savedParts, availableMap).map(function (part, idx) {
                    const savedPart = savedParts[idx] || {};
                    const sourceBy = startRepairSourceByV11(savedPart.source_by !== undefined ? savedPart : part);
                    const usedQty = savedPart.used_quantity || part.used_quantity || part.needed_quantity || '';
                    return Object.assign({}, part, savedPart, {
                        used_quantity: usedQty,
                        source_by: sourceBy,
                        source_label: sourceBy === 'branch' ? 'Branch Source' : 'Motorpool Source',
                        branch_sourced: sourceBy === 'branch' ? 1 : 0,
                        skip_inventory_deduction: returnedFromQuality ? 1 : (part.skip_inventory_deduction || savedPart.skip_inventory_deduction || 0),
                        rework_returned: returnedFromQuality ? 1 : 0,
                        unit_cost: sourceBy === 'branch' ? 0 : (savedPart.unit_cost || part.unit_cost || ''),
                        total_cost: sourceBy === 'branch' ? 0 : (savedPart.total_cost || part.total_cost || '')
                    });
                });

                return {
                    repair: item.repair,
                    repair_cost: saved.repair_cost ?? saved.labor_cost ?? item.repair_cost ?? item.labor_cost ?? 0,
                    labor_cost: saved.repair_cost ?? saved.labor_cost ?? item.repair_cost ?? item.labor_cost ?? 0,
                    checked: returnedFromQuality ? false : !!saved.checked,
                    repair_type: (parts.length || saved.repair_type === 'with_parts') ? 'with_parts' : (saved.repair_type || 'labor'),
                    start_date: returnedFromQuality ? '' : (saved.start_date || saved.repair_date || ''),
                    start_time: returnedFromQuality ? '' : (saved.start_time || ''),
                    end_time: returnedFromQuality ? '' : (saved.end_time || ''),
                    mechanic: returnedFromQuality ? '' : (saved.mechanic || ''),
                    miscellaneous_description: miscellaneousDescriptionValueV1(saved),
                    miscellaneous_cost: miscellaneousCostValueV1(saved).toFixed(2),
                    misc_description: miscellaneousDescriptionValueV1(saved),
                    misc_cost: miscellaneousCostValueV1(saved).toFixed(2),
                    parts: parts,
                    rework_returned: returnedFromQuality ? 1 : 0,
                    skip_inventory_deduction: returnedFromQuality ? 1 : 0,
                    rework_cycle_key: reworkCycleKey
                };
            });

            if (returnedFromQuality) return rows;
            return rows.filter(function (item) { return !item.checked; });
        }

        function renderStartRepairPartsInputsV11(parts, repairIndex, repairType, readOnlyItems = false) {
            if (repairType !== 'with_parts') {
                return '<div class="text-muted small py-2">No parts required for this repair.</div>';
            }

            const datalistId = `motorpoolInventoryPartsListV24_${repairIndex}`;
            const datalistHtml = `<datalist id="${datalistId}">${renderMotorpoolInventoryDatalistV24()}</datalist>`;

            const baseRows = Array.isArray(parts) && parts.length ? parts : [{
                inventory_item_id: '',
                item_no: '',
                item_name: '',
                item_label: '',
                description: '',
                specification: '',
                needed_quantity: '',
                available_quantity: '',
                used_quantity: '',
                unit_cost: '',
                total_cost: '',
                source_by: 'motorpool',
                source_label: 'Motorpool Source',
                branch_sourced: 0,
                skip_inventory_deduction: 0,
                rework_returned: 0
            }];

            const rowsHtml = baseRows.map(function (part, partIndex) {
                const selected = findMotorpoolInventoryOptionV24(part.inventory_item_id || part.item_label || part.item_no || part.item_name || '', part.inventory_item_id || '');
                const itemLabel = part.item_label || (selected ? (selected.label || ((selected.item_code || '') + ' - ' + (selected.item_name || ''))) : (part.item_no || part.item_name || ''));
                const maxQty = Number(selected ? selected.current_stock : (part.available_quantity || 0)) || 0;
                const neededQty = part.needed_quantity !== undefined && part.needed_quantity !== null ? part.needed_quantity : '';
                const unitCost = part.unit_cost !== '' && part.unit_cost !== undefined ? part.unit_cost : (selected ? selected.unit_cost : '');
                const usedQty = part.used_quantity || '';
                const totalCost = part.total_cost || ((Number(usedQty || 0) || 0) * (Number(unitCost || 0) || 0));
                const sourceBy = startRepairSourceByV11(part);
                const itemReadonly = readOnlyItems ? 'readonly' : '';
                const qtyReadonly = readOnlyItems ? 'readonly' : '';
                return `
            <div class="start-repair-part-row repair-log-part-row border rounded p-2 mb-2">
                <input type="hidden" class="start-repair-part-inventory-id" value="${esc(part.inventory_item_id || (selected ? selected.item_id : ''))}">
                <input type="hidden" class="start-repair-part-item-no" value="${esc(part.item_no || (selected ? selected.item_code : ''))}">
                <input type="hidden" class="start-repair-part-item-name" value="${esc(part.item_name || (selected ? selected.item_name : ''))}">
                <input type="hidden" class="start-repair-part-description" value="${esc(part.description || (selected ? selected.description : ''))}">
                <input type="hidden" class="start-repair-part-specification" value="${esc(part.specification || '')}">
                <input type="hidden" class="start-repair-part-available" value="${esc(maxQty)}">
                <input type="hidden" class="start-repair-part-cost" value="${esc(unitCost)}">
                <input type="hidden" class="start-repair-part-total-cost" value="${esc(Number(totalCost || 0).toFixed ? Number(totalCost || 0).toFixed(2) : totalCost)}">
                <input type="hidden" class="start-repair-part-needed" value="${esc(neededQty)}">
                <input type="hidden" class="start-repair-part-source-by" value="${esc(sourceBy)}">
                <input type="hidden" class="start-repair-part-branch-sourced" value="${sourceBy === 'branch' ? '1' : '0'}">
                <input type="hidden" class="start-repair-part-skip-inventory" value="${readOnlyItems ? '1' : (part.skip_inventory_deduction ? '1' : '0')}">
                <input type="hidden" class="start-repair-part-rework-returned" value="${readOnlyItems ? '1' : (part.rework_returned ? '1' : '0')}">
                <div class="row g-2 align-items-start">
                    <div class="col-12 col-md-8">
                        <label class="form-label small mb-1">Parts / Item Used</label>
                        <input type="text" class="form-control form-control-sm start-repair-field start-repair-part-item-input" list="${datalistId}" value="${esc(itemLabel)}" placeholder="Select from inventory or type item" ${itemReadonly}>
                        <div class="mt-1 mb-1">${startRepairSourceBadgeV11(sourceBy)}</div>
                        <small class="text-muted">Needed: ${esc(neededQty || 0)}${maxQty ? ' | Available: ' + esc(maxQty) : ''}</small>
                    </div>
                    <div class="col-12 col-md-4 start-repair-qty-col">
                        <label class="form-label small mb-1">Qty Used</label>
                        <input type="number" class="form-control form-control-sm start-repair-field start-repair-part-used no-spinner" min="0" ${maxQty > 0 ? 'max="' + esc(maxQty) + '"' : ''} step="1" value="${esc(usedQty)}" placeholder="Qty used" ${qtyReadonly}>
                    </div>
                </div>
            </div>
        `;
            }).join('');

            return datalistHtml + rowsHtml;
        }

        function getTodayDateValueV72() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function getCurrentTimeValueV72() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            return `${hours}:${minutes}`;
        }

        function renderStartRepairProgressRowsV11(data) {
            const body = document.getElementById('startRepairProgressBodyV11');
            const rows = parseStartRepairRowsV11(data);

            if (!rows.length) {
                body.innerHTML = '<div class="text-center text-muted py-3 border rounded">All repairs from this RIS already have repair logs.</div>';
                updateStartRepairProgressV11();
                return;
            }

            body.innerHTML = rows.map(function (item, index) {
                const type = (Array.isArray(item.parts) && item.parts.length) ? 'with_parts' : (item.repair_type || 'labor');
                const isRework = !!item.rework_returned || !!item.skip_inventory_deduction;
                const defaultStartDate = item.start_date || getTodayDateValueV72();
                const defaultStartTime = item.start_time || getCurrentTimeValueV72();
                return `
            <div class="start-repair-row repair-log-card border rounded p-3 mb-3 ${isRework ? 'border-warning bg-warning bg-opacity-10' : ''}">
                <input type="hidden" class="start-repair-type" value="${esc(type)}">
                <input type="hidden" class="start-repair-repair-cost" value="${esc(item.repair_cost ?? item.labor_cost ?? '')}">
                <input type="hidden" class="start-repair-labor-cost" value="${esc(item.repair_cost ?? item.labor_cost ?? '')}">
                <input type="hidden" class="start-repair-check" value="0">
                <input type="hidden" class="start-repair-rework-returned" value="${isRework ? '1' : '0'}">
                <input type="hidden" class="start-repair-skip-inventory" value="${isRework ? '1' : '0'}">
                <input type="hidden" class="start-repair-rework-cycle-key" value="${esc(item.rework_cycle_key || (isRework ? ('quality-return-' + (document.getElementById('startRepairRisIdV11')?.value || '')) : ''))}">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Repair Done</label>
                    <textarea class="form-control start-repair-field start-repair-repair" rows="2" placeholder="Enter repair done" ${isRework ? 'readonly' : ''}>${esc(item.repair)}</textarea>
                </div>

                <div class="repair-log-labor-section border rounded p-3 mb-3 bg-light">
                    <div class="fw-semibold mb-2"><i class="bi bi-person-gear me-1"></i>Labor Details</div>
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Date</label>
                            <input type="date" class="form-control form-control-sm start-repair-field start-repair-date" value="${esc(defaultStartDate)}">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Start Time</label>
                            <input type="time" class="form-control form-control-sm start-repair-field start-repair-time" value="${esc(defaultStartTime)}">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">End Time</label>
                            <input type="time" class="form-control form-control-sm start-repair-field start-repair-end-time" value="${esc(item.end_time || '')}">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Mechanic</label>
                            <input type="text" class="form-control form-control-sm start-repair-field start-repair-mechanic" value="${esc(item.mechanic || '')}" placeholder="Mechanic">
                        </div>
                    </div>
                </div>

                <div class="repair-log-parts-section">
                    <div class="fw-semibold mb-2"><i class="bi bi-box-seam me-1"></i>Parts / Items Used</div>
                    ${renderStartRepairPartsInputsV11(item.parts, index, type, isRework)}
                </div>

                ${renderMiscellaneousEditorV1(item, 'start-repair', false)}
            </div>
        `;
            }).join('');

            body.querySelectorAll('.start-repair-part-item-input').forEach(function (input) {
                if (input.hasAttribute('readonly')) return;
                input.addEventListener('input', function () { applyMotorpoolInventorySelectionV24(this); });
                input.addEventListener('change', function () { applyMotorpoolInventorySelectionV24(this); });
                applyMotorpoolInventorySelectionV24(input);
            });
            body.querySelectorAll('.start-repair-part-used').forEach(function (input) {
                input.addEventListener('input', function () {
                    const partRow = this.closest('.start-repair-part-row');
                    if (partRow) updateStartRepairPartTotalV24(partRow);
                });
            });

            updateStartRepairProgressV11();
        }

        function collectStartRepairProgressV11(includeSavedRows = false) {
            const rows = [];
            document.querySelectorAll('#startRepairProgressBodyV11 .start-repair-row').forEach(function (row) {
                const repairType = row.querySelector('.start-repair-type')?.value || 'labor';
                const partsUsed = [];
                let hasUsedPart = false;
                row.querySelectorAll('.start-repair-part-row').forEach(function (partRow) {
                    const availableQty = Number(partRow.querySelector('.start-repair-part-available')?.value || 0) || 0;
                    const usedInput = partRow.querySelector('.start-repair-part-used');
                    let usedQty = Number(usedInput?.value || 0) || 0;
                    if (usedQty < 0) usedQty = 0;
                    if (availableQty > 0 && usedQty > availableQty) {
                        usedQty = availableQty;
                        if (usedInput) usedInput.value = availableQty;
                    }
                    if (usedQty > 0) hasUsedPart = true;
                    const itemInput = partRow.querySelector('.start-repair-part-item-input');
                    if (itemInput && !itemInput.hasAttribute('readonly')) {
                        applyMotorpoolInventorySelectionV24(itemInput || partRow);
                    }
                    const costInput = partRow.querySelector('.start-repair-part-cost');
                    const itemNoHidden = partRow.querySelector('.start-repair-part-item-no');
                    const itemNameHidden = partRow.querySelector('.start-repair-part-item-name');
                    const inventoryId = partRow.querySelector('.start-repair-part-inventory-id')?.value || '';
                    const unitCost = Number(costInput?.value || 0) || 0;
                    partsUsed.push({
                        inventory_item_id: inventoryId,
                        item_no: itemNoHidden?.value || itemInput?.value || '',
                        item_name: itemNameHidden?.value || itemInput?.value || '',
                        item_label: itemInput?.value || '',
                        description: partRow.querySelector('.start-repair-part-description')?.value || '',
                        specification: partRow.querySelector('.start-repair-part-specification')?.value || '',
                        needed_quantity: partRow.querySelector('.start-repair-part-needed')?.value || '',
                        available_quantity: partRow.querySelector('.start-repair-part-available')?.value || '',
                        used_quantity: usedInput?.value || '',
                        source_by: partRow.querySelector('.start-repair-part-source-by')?.value || 'motorpool',
                        branch_sourced: partRow.querySelector('.start-repair-part-branch-sourced')?.value === '1' ? 1 : 0,
                        source_label: (partRow.querySelector('.start-repair-part-source-by')?.value || 'motorpool') === 'branch' ? 'Branch Source' : 'Motorpool Source',
                        skip_inventory_deduction: partRow.querySelector('.start-repair-part-skip-inventory')?.value === '1' ? 1 : 0,
                        rework_returned: partRow.querySelector('.start-repair-part-rework-returned')?.value === '1' ? 1 : 0,
                        unit_cost: (partRow.querySelector('.start-repair-part-source-by')?.value || 'motorpool') === 'branch' ? 0 : unitCost,
                        total_cost: (partRow.querySelector('.start-repair-part-source-by')?.value || 'motorpool') === 'branch' ? 0 : (usedQty * unitCost)
                    });
                });
                const repairText = row.querySelector('.start-repair-repair')?.value || '';
                const dateValue = row.querySelector('.start-repair-date')?.value || '';
                const startTimeValue = row.querySelector('.start-repair-time')?.value || '';
                const endTimeValue = row.querySelector('.start-repair-end-time')?.value || '';
                const mechanicValue = row.querySelector('.start-repair-mechanic')?.value || '';
                const miscellaneousDescription = row.querySelector('.start-repair-misc-desc')?.value || '';
                const miscellaneousCost = Number(row.querySelector('.start-repair-misc-cost')?.value || 0) || 0;
                const isReworkReturned = row.querySelector('.start-repair-rework-returned')?.value === '1';
                const skipInventory = row.querySelector('.start-repair-skip-inventory')?.value === '1';
                const reworkCycleKey = row.querySelector('.start-repair-rework-cycle-key')?.value || '';
                // Date and Start Time are auto-filled for convenience, so they must NOT
                // automatically mark every repair row as logged. Only the row where the
                // user actually encoded completion details or parts used should proceed.
                const hasLaborLog = !!(String(endTimeValue).trim() || String(mechanicValue).trim());
                const hasMiscLog = !!(String(miscellaneousDescription).trim() || miscellaneousCost > 0);
                const shouldLog = hasLaborLog || hasUsedPart || hasMiscLog;
                const checkedValue = shouldLog ? 1 : 0;
                const hiddenCheck = row.querySelector('.start-repair-check');
                if (hiddenCheck) hiddenCheck.value = String(checkedValue);
                rows.push({
                    repair: repairText,
                    checked: checkedValue,
                    repair_type: repairType,
                    parts_used: repairType === 'with_parts' ? partsUsed : [],
                    start_date: dateValue,
                    start_time: startTimeValue,
                    end_time: endTimeValue,
                    mechanic: mechanicValue,
                    repair_cost: row.querySelector('.start-repair-repair-cost')?.value || row.querySelector('.start-repair-labor-cost')?.value || '',
                    labor_cost: row.querySelector('.start-repair-repair-cost')?.value || row.querySelector('.start-repair-labor-cost')?.value || '',
                    miscellaneous_description: miscellaneousDescription,
                    miscellaneous_cost: miscellaneousCost.toFixed(2),
                    misc_description: miscellaneousDescription,
                    misc_cost: miscellaneousCost.toFixed(2),
                    stage: checkedValue ? 'ongoing' : 'for_repair',
                    rework_returned: isReworkReturned ? 1 : 0,
                    skip_inventory_deduction: skipInventory ? 1 : 0,
                    rework_cycle_key: reworkCycleKey
                });
            });
            if (includeSavedRows) {
                const currentKeys = {};
                rows.forEach(item => { currentKeys[String(item.repair || '').trim().toLowerCase()] = true; });
                (Array.isArray(startRepairSavedRowsV11) ? startRepairSavedRowsV11 : []).forEach(function (saved) {
                    const key = String(saved.repair || '').trim().toLowerCase();
                    if (key !== '' && !currentKeys[key] && saved.checked) {
                        rows.push(saved);
                    }
                });
            }
            document.getElementById('startRepairProgressJsonV11').value = JSON.stringify(rows);
            return rows;
        }

        function updateStartRepairProgressV11() {
            document.querySelectorAll('#startRepairProgressBodyV11 .start-repair-row').forEach(function (row) {
                const type = row.querySelector('.start-repair-type')?.value || 'labor';
                row.querySelectorAll('.start-repair-part-row').forEach(function (partRow) {
                    partRow.classList.toggle('d-none', type !== 'with_parts');
                });
            });

            const rows = collectStartRepairProgressV11();
            const total = rows.length;
            const done = rows.filter(item => item.checked).length;
            const percent = total > 0 ? Math.round((done / total) * 100) : 0;
            const bar = document.getElementById('startRepairProgressBarV11');
            const text = document.getElementById('startRepairProgressTextV11');
            const confirm = document.getElementById('startRepairConfirmV11');
            const proceedBtn = document.getElementById('startRepairProceedBtnV11');

            if (bar) {
                bar.style.width = percent + '%';
                bar.textContent = percent + '%';
                bar.className = 'progress-bar ' + (percent >= 100 ? 'bg-success' : (percent >= 50 ? 'bg-warning text-dark' : 'bg-danger'));
            }
            if (text) text.textContent = `${done} of ${total} repair log(s) ready`;

            if (confirm) {
                // Allow partial repair logging. At least one encoded repair is enough.
                confirm.disabled = !(total > 0 && done > 0);
                if (confirm.disabled) confirm.checked = false;
            }
            if (proceedBtn) {
                proceedBtn.classList.toggle('d-none', !(done > 0 && confirm && confirm.checked));
            }
        }

        let startRepairSavedRowsV11 = [];

        function parseExistingRepairProgressV11(data) {
            try {
                const saved = JSON.parse(data.repair_progress_json || '[]');
                return Array.isArray(saved) ? saved : [];
            } catch (error) {
                return [];
            }
        }

        function openStartRepairModalV11(row) {
            try {
                const data = rowData(row);
                const risInput = document.getElementById('startRepairRisIdV11');
                const infoGridEl = document.getElementById('startRepairInfoGridV11');
                const submitModeEl = document.getElementById('startRepairSubmitModeV11');
                const confirmEl = document.getElementById('startRepairConfirmV11');
                const modalEl = document.getElementById('startRepairProofModalV11');

                if (!modalEl || !risInput || !infoGridEl || !submitModeEl || !confirmEl) {
                    throw new Error('Start Repair modal elements are incomplete.');
                }

                risInput.value = data.ris_id || '';
                infoGridEl.innerHTML = infoGrid(data);
                submitModeEl.value = 'save';
                confirmEl.checked = (String(data.repair_confirmed_complete || '0') === '1');


                renderStartRepairProgressRowsV11(data);
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } catch (error) {
                console.error('Start Repair modal error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Start Repair Error',
                    text: 'Unable to open Start Repair modal. Please reload the page and try again.',
                    confirmButtonColor: '#07b83f'
                });
            }
        }

        document.addEventListener('input', function (event) {
            if (event.target.classList && event.target.classList.contains('start-repair-field')) {
                updateStartRepairProgressV11();
            }
        });

        document.addEventListener('change', function (event) {
            if (event.target.classList && event.target.classList.contains('start-repair-field')) {
                updateStartRepairProgressV11();
            }
        });

        document.getElementById('startRepairConfirmV11')?.addEventListener('change', updateStartRepairProgressV11);

        document.getElementById('startRepairSaveBtnV11')?.addEventListener('click', function () {
            document.getElementById('startRepairSubmitModeV11').value = 'save';
        });

        document.getElementById('startRepairProceedBtnV11')?.addEventListener('click', function () {
            document.getElementById('startRepairSubmitModeV11').value = 'proceed';
        });

        document.getElementById('startRepairProofFormV11')?.addEventListener('submit', function (event) {
            const mode = document.getElementById('startRepairSubmitModeV11').value;
            const rows = collectStartRepairProgressV11();
            const total = rows.length;
            const done = rows.filter(item => item.checked).length;
            const confirmed = document.getElementById('startRepairConfirmV11').checked;
            if (total <= 0) {
                event.preventDefault();
                Swal.fire({ icon: 'warning', title: 'No repair item', text: 'No pending repair item was found from the assessment.', confirmButtonColor: '#07b83f' });
                return;
            }
            if (done <= 0) {
                event.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Select repair to start', text: 'Please encode at least one repair log.', confirmButtonColor: '#07b83f' });
                return;
            }
            for (const row of rows.filter(item => item.checked)) {
                if (!row.start_date || !row.start_time || !row.end_time || !String(row.mechanic || '').trim()) {
                    event.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'Incomplete repair log', text: 'Please complete date, start time, end time, and mechanic for every repair log.', confirmButtonColor: '#07b83f' });
                    return;
                }
                if (row.repair_type === 'with_parts') {
                    const hasUsedPart = (row.parts_used || []).some(part => Number(part.used_quantity || 0) > 0);
                    if (!hasUsedPart) {
                        event.preventDefault();
                        Swal.fire({ icon: 'warning', title: 'Parts quantity needed', text: 'Please enter at least one parts quantity used for every selected With Parts repair.', confirmButtonColor: '#07b83f' });
                        return;
                    }
                }
            }
            if (mode === 'proceed' && !confirmed) {
                event.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Repair confirmation needed', text: 'Please confirm the encoded repair log before proceeding.', confirmButtonColor: '#07b83f' });
                return;
            }
            collectStartRepairProgressV11(true);
        });



        function getOngoingRepairUsedPartsV1(parts, repairType) {
            if (repairType !== 'with_parts') return [];
            return (Array.isArray(parts) ? parts : []).filter(function (part) {
                return String(part.item_no || '').trim() !== '' || Number(part.used_quantity || part.qty_to_use || part.quantity_to_use || part.quantity_used || part.qty_used || 0) > 0;
            }).map(function (part) {
                return {
                    item_no: String(part.item_no || '').trim() || '-',
                    used_quantity: Number(part.used_quantity || part.qty_to_use || part.quantity_to_use || part.quantity_used || part.qty_used || 0) || 0
                };
            });
        }

        function renderOngoingRepairPartItemsV1(parts, repairType) {
            const usedParts = getOngoingRepairUsedPartsV1(parts, repairType);
            if (!usedParts.length) return '<span class="badge bg-light text-dark border">Labor only</span>';
            return usedParts.map(function (part) {
                return '<div class="ongoing-part-pill">' + esc(part.item_no) + '</div>';
            }).join('');
        }

        function renderOngoingRepairPartQtyV1(parts, repairType) {
            const usedParts = getOngoingRepairUsedPartsV1(parts, repairType);
            if (!usedParts.length) return '<span class="text-muted">-</span>';
            return usedParts.map(function (part) {
                return '<div class="ongoing-part-pill">' + esc(part.used_quantity) + '</div>';
            }).join('');
        }

        function normalizeOngoingSavedRowV20(saved, data) {
            const stage = String(saved.stage || '').toLowerCase();
            const repairType = saved.repair_type === 'with_parts' ? 'with_parts' : 'labor';
            const isReworkReturned = !!saved.rework_returned || !!saved.skip_inventory_deduction || isReturnedFromQualityRepairV11(data);
            const reworkDone = isReworkReturned && (!!saved.done || stage === 'done');
            const partsUsed = Array.isArray(saved.parts_used) ? saved.parts_used.map(function (part) {
                const qty = part.used_quantity || part.qty_to_use || part.quantity_to_use || part.quantity_used || part.qty_used || part.qty || '';
                const sourceBy = startRepairSourceByV11(part);
                return {
                    inventory_item_id: part.inventory_item_id || part.item_id || '',
                    item_no: part.item_no || part.item_code || '',
                    item_name: part.item_name || '',
                    item_label: part.item_label || part.item_no || part.item_name || '',
                    description: part.description || '',
                    specification: part.specification || '',
                    needed_quantity: part.needed_quantity || part.quantity || part.qty || '',
                    available_quantity: part.available_quantity || part.needed_quantity || part.quantity || part.qty || '',
                    used_quantity: qty,
                    source_by: sourceBy,
                    source_label: sourceBy === 'branch' ? 'Branch Source' : 'Motorpool Source',
                    branch_sourced: sourceBy === 'branch' ? 1 : 0,
                    skip_inventory_deduction: (sourceBy === 'branch' || isReworkReturned || part.skip_inventory_deduction) ? 1 : 0,
                    rework_returned: isReworkReturned ? 1 : (part.rework_returned ? 1 : 0),
                    unit_cost: sourceBy === 'branch' ? 0 : (part.unit_cost || 0),
                    total_cost: sourceBy === 'branch' ? 0 : (part.total_cost || 0)
                };
            }) : [];

            return {
                repair: saved.repair || saved.repair_description || '',
                repair_cost: saved.repair_cost ?? saved.labor_cost ?? saved.service_cost ?? 0,
                labor_cost: saved.repair_cost ?? saved.labor_cost ?? saved.service_cost ?? 0,
                repair_type: repairType,
                parts_used: partsUsed,
                start_date: saved.start_date || saved.repair_date || '',
                start_time: saved.start_time || '',
                mechanic: saved.mechanic || '',
                end_date: saved.end_date || saved.completion_date || data.repair_end_date || '',
                end_time: saved.end_time || saved.completion_time || '',
                completion_mechanic: saved.completion_mechanic || saved.mechanic || getStartRepairMechanicFromProgressV11(data),
                miscellaneous_description: miscellaneousDescriptionValueV1(saved),
                miscellaneous_cost: miscellaneousCostValueV1(saved).toFixed(2),
                misc_description: miscellaneousDescriptionValueV1(saved),
                misc_cost: miscellaneousCostValueV1(saved).toFixed(2),
                checked: (!!saved.done || stage === 'done' || !!saved.locked) ? true : (isReworkReturned ? false : !!saved.checked),
                done: (!!saved.done || stage === 'done' || !!saved.locked) ? 1 : 0,
                locked: (!!saved.done || stage === 'done' || !!saved.locked) ? true : (isReworkReturned ? false : !!saved.locked),
                stage: (!!saved.done || stage === 'done' || !!saved.locked) ? 'done' : (isReworkReturned ? 'ongoing' : (stage || (saved.checked ? 'ongoing' : 'for_repair'))),
                rework_returned: isReworkReturned ? 1 : (saved.rework_returned ? 1 : 0),
                skip_inventory_deduction: isReworkReturned ? 1 : (saved.skip_inventory_deduction ? 1 : 0),
                rework_cycle_key: saved.rework_cycle_key || (isReworkReturned ? ('quality-return-' + (data.ris_id || '')) : '')
            };
        }

        function parseOngoingRepairRowsV1(data) {
            let savedProgress = [];
            try { savedProgress = JSON.parse(data.repair_progress_json || '[]'); } catch (error) { savedProgress = []; }

            // Source of truth for this modal is the saved/merged repair progress JSON.
            // It already contains the repairs started from For Repair, including repair type,
            // mechanic, start date/time, and parts used quantity. Do not rebuild this from
            // assessment rows only, because that can turn With Parts back into Labor Only.
            const rows = [];
            const seen = {};
            if (Array.isArray(savedProgress)) {
                savedProgress.forEach(function (saved) {
                    if (!saved || typeof saved !== 'object') return;
                    const repairName = String(saved.repair || saved.repair_description || '').trim();
                    if (repairName === '') return;
                    const stage = String(saved.stage || '').toLowerCase();
                    const started = !!saved.checked || stage === 'ongoing' || stage === 'done' || !!saved.done || !!saved.locked;
                    if (!started) return;
                    const key = repairName.toLowerCase();
                    seen[key] = true;
                    rows.push(normalizeOngoingSavedRowV20(saved, data));
                });
            }

            // Fallback only for old records where repair_progress_json is missing but assessment exists.
            if (!rows.length) {
                const savedMap = getSavedRepairMapV11(data);
                const repairItems = getAssessmentRepairItemsV11(data);
                repairItems.forEach(function (item) {
                    const saved = savedMap[item.repair.toLowerCase()] || {};
                    const stage = String(saved.stage || '').toLowerCase();
                    const started = !!saved.checked || stage === 'ongoing' || stage === 'done';
                    if (!started) return;
                    rows.push(normalizeOngoingSavedRowV20(Object.assign({}, saved, { repair: item.repair }), data));
                });
            }

            return rows.filter(item => String(item.repair || '').trim() !== '');
        }

        function renderOngoingRepairPartsInputsV75(parts, repairType, locked) {
            if (repairType !== 'with_parts') {
                return '<div class="text-muted small py-2">No parts required for this repair.</div>';
            }

            const baseRows = Array.isArray(parts) && parts.length ? parts : [{
                item_no: '',
                item_name: '',
                item_label: '',
                description: '',
                specification: '',
                needed_quantity: '',
                available_quantity: '',
                used_quantity: '',
                source_by: 'motorpool'
            }];

            return baseRows.map(function (part) {
                const itemLabel = part.item_label || part.item_no || part.item_name || '';
                const neededQty = part.needed_quantity !== undefined && part.needed_quantity !== null ? part.needed_quantity : '';
                const availableQty = part.available_quantity !== undefined && part.available_quantity !== null ? part.available_quantity : '';
                const usedQty = part.used_quantity || part.qty_used || part.qty_to_use || part.quantity_to_use || part.quantity_used || '';
                const sourceBy = startRepairSourceByV11(part);
                const branchSourced = sourceBy === 'branch' ? 1 : 0;
                const skipInventory = (branchSourced || part.skip_inventory_deduction || part.rework_returned) ? 1 : 0;
                return `
            <div class="ongoing-repair-part-row repair-log-part-row border rounded p-2 mb-2">
                <input type="hidden" class="ongoing-repair-part-inventory-id" value="${esc(part.inventory_item_id || part.item_id || '')}">
                <input type="hidden" class="ongoing-repair-part-item-no" value="${esc(part.item_no || part.item_code || '')}">
                <input type="hidden" class="ongoing-repair-part-item-name" value="${esc(part.item_name || '')}">
                <input type="hidden" class="ongoing-repair-part-description" value="${esc(part.description || '')}">
                <input type="hidden" class="ongoing-repair-part-specification" value="${esc(part.specification || '')}">
                <input type="hidden" class="ongoing-repair-part-needed" value="${esc(neededQty)}">
                <input type="hidden" class="ongoing-repair-part-available" value="${esc(availableQty)}">
                <input type="hidden" class="ongoing-repair-part-source-by" value="${esc(sourceBy)}">
                <input type="hidden" class="ongoing-repair-part-branch-sourced" value="${branchSourced}">
                <input type="hidden" class="ongoing-repair-part-skip-inventory" value="${skipInventory}">
                <input type="hidden" class="ongoing-repair-part-rework-returned" value="${part.rework_returned ? '1' : '0'}">
                <input type="hidden" class="ongoing-repair-part-unit-cost" value="${esc(sourceBy === 'branch' ? 0 : (part.unit_cost || 0))}">
                <input type="hidden" class="ongoing-repair-part-total-cost" value="${esc(sourceBy === 'branch' ? 0 : (part.total_cost || 0))}">
                <div class="row g-2 align-items-start">
                    <div class="col-12 col-md-8">
                        <label class="form-label small mb-1">Parts / Item Used</label>
                        <input type="text" class="form-control form-control-sm ongoing-repair-field ongoing-repair-part-item-input" value="${esc(itemLabel)}" placeholder="Parts / Item Used" ${locked ? 'readonly' : ''}>
                        <div class="mt-1">${startRepairSourceBadgeV11(sourceBy)}</div>
                        <small class="text-muted">Needed: ${esc(neededQty || 0)}${availableQty !== '' ? ' | Available: ' + esc(availableQty) : ''}</small>
                    </div>
                    <div class="col-12 col-md-4 ongoing-repair-qty-col">
                        <label class="form-label small mb-1">Qty Used</label>
                        <input type="number" class="form-control form-control-sm ongoing-repair-field ongoing-repair-part-used no-spinner" min="0" step="1" value="${esc(usedQty)}" placeholder="Qty used" ${locked ? 'readonly' : ''}>
                    </div>
                </div>
            </div>
        `;
            }).join('');
        }

        function renderOngoingRepairRowsV1(data) {
            const body = document.getElementById('ongoingRepairProgressBodyV1');
            const rows = parseOngoingRepairRowsV1(data);
            // On-going Repair fields must stay blank by default.
            // Do not auto-fill Start Date, Start Time, or End Time here.

            if (!rows.length) {
                body.innerHTML = '<div class="text-center text-muted py-3 border rounded">No ongoing repair logs found. Start at least one repair first.</div>';
                updateOngoingRepairProgressV1();
                return;
            }

            body.innerHTML = rows.map(function (item, index) {
                const stage = String(item.stage || '').toLowerCase();
                const reworkReturned = !!item.rework_returned || !!item.skip_inventory_deduction || isReturnedFromQualityRepairV11(data);
                const actuallyDone = !!item.locked || !!item.done || stage === 'done';
                const locked = actuallyDone;
                const readOnlyItems = locked || reworkReturned;
                // Rework rows coming back from Quality Check must start unchecked.
                // Once the rework is completed again, keep it checked/locked for Quality Check.
                const doneChecked = actuallyDone ? true : false;
                const type = item.repair_type === 'with_parts' ? 'with_parts' : 'labor';
                const startDateValue = locked ? (item.start_date || item.completion_date || '') : (item.start_date || '');
                const startTimeValue = locked ? (item.start_time || '') : (item.start_time || '');
                const endTimeValue = locked ? (item.end_time || item.completion_time || '') : (item.end_time || item.completion_time || '');
                const mechanicValue = item.completion_mechanic || item.mechanic || '';
                return `
            <div class="ongoing-repair-row repair-log-card border rounded p-3 mb-3 ${locked ? 'bg-success bg-opacity-10' : ''}">
                <input type="hidden" class="ongoing-repair-type" value="${esc(type)}">
                <input type="hidden" class="ongoing-repair-repair-cost" value="${esc(item.repair_cost ?? item.labor_cost ?? '')}">
                <input type="hidden" class="ongoing-repair-labor-cost" value="${esc(item.repair_cost ?? item.labor_cost ?? '')}">
                <input type="hidden" class="ongoing-repair-locked" value="${locked ? '1' : '0'}">
                <input type="hidden" class="ongoing-repair-check-hidden" value="${doneChecked ? '1' : '0'}">
                <input type="hidden" class="ongoing-repair-rework-returned" value="${reworkReturned ? '1' : '0'}">
                <input type="hidden" class="ongoing-repair-skip-inventory" value="${reworkReturned ? '1' : (item.skip_inventory_deduction ? '1' : '0')}">
                <input type="hidden" class="ongoing-repair-rework-cycle-key" value="${esc(item.rework_cycle_key || (reworkReturned ? ('quality-return-' + (data.ris_id || '')) : ''))}">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Repair Done</label>
                    <textarea class="form-control ongoing-repair-field ongoing-repair-repair" rows="2" placeholder="Enter repair done" ${readOnlyItems ? 'readonly' : ''}>${esc(item.repair || '')}</textarea>
                </div>

                <div class="repair-log-labor-section border rounded p-3 mb-3 bg-light">
                    <div class="fw-semibold mb-2"><i class="bi bi-person-gear me-1"></i>Labor Details</div>
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Start Date</label>
                            <input type="date" class="form-control form-control-sm ongoing-repair-field ongoing-repair-start-date" value="${esc(startDateValue)}" ${locked ? 'readonly' : ''}>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Start Time</label>
                            <input type="time" class="form-control form-control-sm ongoing-repair-field ongoing-repair-start-time" value="${esc(startTimeValue)}" placeholder="Start time" ${locked ? 'readonly' : ''}>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">End Time</label>
                            <input type="time" class="form-control form-control-sm ongoing-repair-field ongoing-repair-end-time" value="${esc(endTimeValue)}" ${locked ? 'readonly' : ''}>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Mechanic</label>
                            <input type="text" class="form-control form-control-sm ongoing-repair-field ongoing-repair-mechanic" value="${esc(mechanicValue)}" placeholder="Mechanic" ${locked ? 'readonly' : ''}>
                        </div>
                    </div>
                </div>

                <div class="repair-log-parts-section mb-3">
                    <div class="fw-semibold mb-2"><i class="bi bi-box-seam me-1"></i>Parts / Items Used</div>
                    ${renderOngoingRepairPartsInputsV75(item.parts_used, type, readOnlyItems)}
                </div>

                ${renderMiscellaneousEditorV1(item, 'ongoing-repair', locked)}

                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input ongoing-repair-field ongoing-repair-check" id="ongoingRepairDone_${index}" ${doneChecked ? 'checked' : ''} ${locked ? 'disabled' : ''}>
                    <label class="form-check-label" for="ongoingRepairDone_${index}">
                        I confirm this repair is done.
                    </label>
                    ${locked ? '<span class="badge bg-success ms-2">Done / Locked</span>' : (reworkReturned ? '<span class="badge bg-info text-dark ms-2">Rework</span>' : '<span class="badge bg-warning text-dark ms-2">Ongoing</span>')}
                </div>
            </div>
        `;
            }).join('');

            updateOngoingRepairProgressV1();
        }

        function collectOngoingRepairProgressV1() {
            const rows = [];
            document.querySelectorAll('#ongoingRepairProgressBodyV1 .ongoing-repair-row').forEach(function (row) {
                const locked = row.querySelector('.ongoing-repair-locked')?.value === '1';
                const isReworkReturned = row.querySelector('.ongoing-repair-rework-returned')?.value === '1';
                const checked = (!isReworkReturned && locked) || (row.querySelector('.ongoing-repair-check')?.checked ? true : false);
                const repairType = row.querySelector('.ongoing-repair-type')?.value || 'labor';
                const partsUsed = [];
                row.querySelectorAll('.ongoing-repair-part-row').forEach(function (partRow) {
                    const itemInput = partRow.querySelector('.ongoing-repair-part-item-input');
                    const usedInput = partRow.querySelector('.ongoing-repair-part-used');
                    const itemText = itemInput?.value || '';
                    const hiddenItemNo = partRow.querySelector('.ongoing-repair-part-item-no')?.value || '';
                    const usedQty = usedInput?.value || '';
                    if (String(itemText).trim() !== '' || Number(usedQty || 0) > 0) {
                        const sourceBy = partRow.querySelector('.ongoing-repair-part-source-by')?.value || 'motorpool';
                        const branchSourced = sourceBy === 'branch' || partRow.querySelector('.ongoing-repair-part-branch-sourced')?.value === '1';
                        const skipInventory = branchSourced || partRow.querySelector('.ongoing-repair-part-skip-inventory')?.value === '1' || partRow.querySelector('.ongoing-repair-part-rework-returned')?.value === '1';
                        const unitCost = Number(partRow.querySelector('.ongoing-repair-part-unit-cost')?.value || 0) || 0;
                        const qtyNumber = Number(usedQty || 0) || 0;
                        partsUsed.push({
                            inventory_item_id: partRow.querySelector('.ongoing-repair-part-inventory-id')?.value || '',
                            item_no: hiddenItemNo || itemText,
                            item_name: partRow.querySelector('.ongoing-repair-part-item-name')?.value || itemText,
                            item_label: itemText,
                            description: partRow.querySelector('.ongoing-repair-part-description')?.value || '',
                            specification: partRow.querySelector('.ongoing-repair-part-specification')?.value || '',
                            needed_quantity: partRow.querySelector('.ongoing-repair-part-needed')?.value || '',
                            available_quantity: partRow.querySelector('.ongoing-repair-part-available')?.value || '',
                            used_quantity: usedQty,
                            source_by: branchSourced ? 'branch' : 'motorpool',
                            source_label: branchSourced ? 'Branch Source' : 'Motorpool Source',
                            branch_sourced: branchSourced ? 1 : 0,
                            skip_inventory_deduction: skipInventory ? 1 : 0,
                            rework_returned: partRow.querySelector('.ongoing-repair-part-rework-returned')?.value === '1' ? 1 : 0,
                            unit_cost: branchSourced ? 0 : unitCost,
                            total_cost: branchSourced ? 0 : (qtyNumber * unitCost)
                        });
                    }
                });

                const startDate = row.querySelector('.ongoing-repair-start-date')?.value || '';
                const startTime = row.querySelector('.ongoing-repair-start-time')?.value || '';
                const endTime = row.querySelector('.ongoing-repair-end-time')?.value || '';
                const mechanic = row.querySelector('.ongoing-repair-mechanic')?.value || '';
                const miscellaneousDescription = row.querySelector('.ongoing-repair-misc-desc')?.value || '';
                const miscellaneousCost = Number(row.querySelector('.ongoing-repair-misc-cost')?.value || 0) || 0;
                const skipInventory = row.querySelector('.ongoing-repair-skip-inventory')?.value === '1';
                const reworkCycleKey = row.querySelector('.ongoing-repair-rework-cycle-key')?.value || '';
                rows.push({
                    repair: row.querySelector('.ongoing-repair-repair')?.value || '',
                    checked: checked ? 1 : 0,
                    started: 1,
                    done: checked ? 1 : 0,
                    locked: locked ? 1 : 0,
                    repair_type: repairType,
                    parts_used: repairType === 'with_parts' ? partsUsed : [],
                    start_date: startDate,
                    start_time: startTime,
                    end_date: checked ? startDate : '',
                    end_time: checked ? endTime : '',
                    completion_date: checked ? startDate : '',
                    completion_time: checked ? endTime : '',
                    mechanic: mechanic,
                    completion_mechanic: checked ? mechanic : '',
                    repair_cost: row.querySelector('.ongoing-repair-repair-cost')?.value || row.querySelector('.ongoing-repair-labor-cost')?.value || '',
                    labor_cost: row.querySelector('.ongoing-repair-repair-cost')?.value || row.querySelector('.ongoing-repair-labor-cost')?.value || '',
                    miscellaneous_description: miscellaneousDescription,
                    miscellaneous_cost: miscellaneousCost.toFixed(2),
                    misc_description: miscellaneousDescription,
                    misc_cost: miscellaneousCost.toFixed(2),
                    stage: checked ? 'done' : 'ongoing',
                    rework_returned: isReworkReturned ? 1 : 0,
                    skip_inventory_deduction: skipInventory ? 1 : 0,
                    rework_cycle_key: reworkCycleKey
                });
            });
            document.getElementById('ongoingRepairProgressJsonV1').value = JSON.stringify(rows);
            return rows;
        }

        function updateOngoingRepairProgressV1() {
            const rows = collectOngoingRepairProgressV1();
            const total = rows.length;
            const done = rows.filter(item => item.done).length;
            const percent = total > 0 ? Math.round((done / total) * 100) : 0;
            const bar = document.getElementById('ongoingRepairProgressBarV1');
            const text = document.getElementById('ongoingRepairProgressTextV1');
            const confirm = document.getElementById('ongoingRepairConfirmV1');
            const proceedBtn = document.getElementById('ongoingRepairProceedBtnV1');

            if (bar) {
                bar.style.width = percent + '%';
                bar.textContent = percent + '%';
                bar.className = 'progress-bar ' + (percent >= 100 ? 'bg-success' : (percent >= 50 ? 'bg-warning text-dark' : 'bg-danger'));
            }
            if (text) text.textContent = `${done} of ${total} ongoing repairs done`;

            if (confirm) {
                confirm.disabled = !(total > 0 && done === total);
                if (confirm.disabled) confirm.checked = false;
            }

            if (proceedBtn) {
                proceedBtn.classList.toggle('d-none', !(percent >= 100 && confirm && confirm.checked));
            }
        }

        function openOngoingRepairModalV1(row) {
            try {
                const data = rowData(row);
                const modalEl = document.getElementById('ongoingRepairModalV1');
                const risInput = document.getElementById('ongoingRepairRisIdV1');
                const infoGridEl = document.getElementById('ongoingRepairInfoGridV1');
                const submitModeEl = document.getElementById('ongoingRepairSubmitModeV1');
                const confirmEl = document.getElementById('ongoingRepairConfirmV1');

                if (!modalEl || !risInput || !infoGridEl || !submitModeEl || !confirmEl) {
                    throw new Error('Ongoing Repair modal elements are incomplete.');
                }

                risInput.value = data.ris_id || '';
                infoGridEl.innerHTML = infoGrid(data);
                submitModeEl.value = 'save';
                confirmEl.checked = false;
                renderOngoingRepairRowsV1(data);
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } catch (error) {
                console.error('Ongoing Repair modal error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Ongoing Repair Error',
                    text: 'Unable to open Ongoing Repair modal. Please reload the page and try again.',
                    confirmButtonColor: '#07b83f'
                });
            }
        }

        document.addEventListener('input', function (event) {
            if (event.target.classList && event.target.classList.contains('ongoing-repair-field')) {
                updateOngoingRepairProgressV1();
            }
        });

        document.addEventListener('change', function (event) {
            if (event.target.classList && event.target.classList.contains('ongoing-repair-field')) {
                updateOngoingRepairProgressV1();
            }
        });

        document.getElementById('ongoingRepairConfirmV1')?.addEventListener('change', updateOngoingRepairProgressV1);

        document.getElementById('ongoingRepairSaveBtnV1')?.addEventListener('click', function () {
            document.getElementById('ongoingRepairSubmitModeV1').value = 'save';
        });

        document.getElementById('ongoingRepairProceedBtnV1')?.addEventListener('click', function () {
            document.getElementById('ongoingRepairSubmitModeV1').value = 'proceed';
        });

        document.getElementById('ongoingRepairFormV1')?.addEventListener('submit', function (event) {
            const mode = document.getElementById('ongoingRepairSubmitModeV1').value;
            const rows = collectOngoingRepairProgressV1();
            const total = rows.length;
            const done = rows.filter(item => item.done).length;
            const confirmed = document.getElementById('ongoingRepairConfirmV1').checked;

            if (total <= 0) {
                event.preventDefault();
                Swal.fire({ icon: 'warning', title: 'No repair log', text: 'No ongoing repair log was found. Start at least one repair first.', confirmButtonColor: '#07b83f' });
                return;
            }

            const newlyDoneRows = rows.filter(item => item.done && !item.locked);

            // Save is allowed even if no repair is marked done yet. This lets Motorpool
            // keep an ongoing log for repairs that started today but will be completed later.
            // Only validate completion fields for rows that are actually confirmed done.
            for (const item of newlyDoneRows) {
                if (!item.end_date || !item.end_time || !item.completion_mechanic) {
                    event.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'Missing completion details', text: 'Please complete End Date, End Time, and Mechanic for each repair marked as done.', confirmButtonColor: '#07b83f' });
                    return;
                }
            }

            if (mode === 'proceed') {
                if (done < total || !confirmed) {
                    event.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'Repair completion needed', text: 'Cannot proceed to Quality Check until all ongoing repairs are marked as done and confirmed.', confirmButtonColor: '#07b83f' });
                    return;
                }
            }
        });




        function getQualityCheckRowsV1(data) {
            let directRows = [];
            try { directRows = JSON.parse(data.quality_completed_rows_json || '[]'); } catch (error) { directRows = []; }
            if (Array.isArray(directRows) && directRows.length) {
                return directRows.filter(function (item) {
                    const repair = String(item && (item.repair || item.repair_description || '') || '').trim();
                    return repair !== '';
                }).map(function (item) {
                    const stage = String(item.stage || '').toLowerCase();
                    item.repair = item.repair || item.repair_description || '';
                    item.checked = 1;
                    item.done = 1;
                    item.locked = 1;
                    item.stage = 'done';
                    if (!item.repair_type) item.repair_type = Array.isArray(item.parts_used) && item.parts_used.length ? 'with_parts' : 'labor';
                    return item;
                });
            }

            const rows = parseOngoingRepairRowsV1(data).filter(function (item) {
                const stage = String(item.stage || '').toLowerCase();
                return !!item.done || stage === 'done' || !!item.locked;
            });

            if (rows.length) return rows;

            // Last client-side fallback. Some rows may still have the rework flag after the
            // request has already returned to For Quality Check. If they have completion
            // date/time values, treat them as completed rows instead of showing an empty table.
            let savedProgress = [];
            try { savedProgress = JSON.parse(data.repair_progress_json || '[]'); } catch (error) { savedProgress = []; }
            if (Array.isArray(savedProgress)) {
                return savedProgress.filter(function (item) {
                    if (!item || typeof item !== 'object') return false;
                    const repair = String(item.repair || item.repair_description || '').trim();
                    const stage = String(item.stage || '').toLowerCase();
                    const hasEnd = String(item.end_date || item.completion_date || item.end_time || item.completion_time || '').trim() !== '';
                    return repair !== '' && (!!item.done || !!item.locked || stage === 'done' || hasEnd);
                }).map(function (item) {
                    item.repair = item.repair || item.repair_description || '';
                    item.done = 1;
                    item.checked = 1;
                    item.locked = 1;
                    item.stage = 'done';
                    return item;
                });
            }

            return [];
        }

        function renderQualityPartsV1(parts, repairType) {
            if (repairType !== 'with_parts') return '<span class="text-muted">None</span>';
            const usedParts = (Array.isArray(parts) ? parts : []).filter(function (part) {
                return String(part.item_no || part.item_label || '').trim() !== '' || Number(part.used_quantity || 0) > 0;
            });
            if (!usedParts.length) return '<span class="text-muted">None</span>';
            return `
        <div class="ongoing-parts-used-list">
            ${usedParts.map(function (part) {
                const qty = Number(part.used_quantity || 0) || 0;
                const sourceBy = startRepairSourceByV11(part);
                const label = part.item_label || part.item_no || part.item_name || '-';
                return `
                    <div class="ongoing-parts-used-row border rounded p-2 mb-2">
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-md-6">
                                <label class="form-label small mb-1">Parts / Item Used</label>
                                <input type="text" class="form-control form-control-sm" value="${esc(label)}" readonly>
                                <div class="mt-1">${startRepairSourceBadgeV11(sourceBy)}</div>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small mb-1">Qty Used</label>
                                <input type="number" class="form-control form-control-sm no-spinner" value="${esc(qty)}" readonly>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small mb-1">Cost Counted</label>
                                <input type="text" class="form-control form-control-sm" value="${sourceBy === 'branch' ? '₱0.00' : formatMoney((Number(part.total_cost || 0) || 0))}" readonly>
                            </div>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
        }


        function renderQualityRepairCostSummaryV1(item) {
            const laborCost = repairCostValueV52(item);
            const itemCost = repairPartsEstimatedTotalV52(item, true);
            const miscCost = miscellaneousCostValueV1(item);
            const totalCost = laborCost + itemCost + miscCost;
            return `
        <div class="quality-cost-summary border rounded p-2 mt-2 bg-light small">
            <div class="d-flex justify-content-between"><span>Repair Cost / Labor</span><strong>${moneyPhpStyleV32(laborCost)}</strong></div>
            <div class="d-flex justify-content-between"><span>Item Cost</span><strong>${moneyPhpStyleV32(itemCost)}</strong></div>
            <div class="d-flex justify-content-between"><span>Miscellaneous</span><strong>${moneyPhpStyleV32(miscCost)}</strong></div>
            <hr class="my-1">
            <div class="d-flex justify-content-between text-success"><span>Total Repair Cost</span><strong>${moneyPhpStyleV32(totalCost)}</strong></div>
        </div>`;
        }

        function openQualityCheckModalV1FromData(data) {
            if (!data || !data.ris_id) {
                Swal.fire({ icon: 'error', title: 'RIS data not found', text: 'Hindi mabasa ang data ng Quality Check. Please refresh the page and try again.', confirmButtonColor: '#07b83f' });
                return;
            }
            const today = new Date().toISOString().slice(0, 10);
            const nowTime = new Date().toTimeString().slice(0, 5);
            const rows = getQualityCheckRowsV1(data);

            document.getElementById('qualityRisIdV1').value = data.ris_id || '';
            document.getElementById('qualityInfoGridV1').innerHTML = infoGrid(data);
            document.getElementById('qualityCheckDateV1').value = today;
            document.getElementById('qualityCheckTimeV1').value = nowTime;
            document.getElementById('qualityCheckByV1').value = '';
            document.getElementById('qualityRemarksV1').value = '';
            document.getElementById('qualityCheckJsonV1').value = JSON.stringify(rows);

            const body = document.getElementById('qualityCheckBodyV1');
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3">No completed repair logs found.</td></tr>';
            } else {
                body.innerHTML = rows.map(function (item) {
                    return `
                <tr>
                    <td class="text-center"><strong>${esc(item.repair || '-')}</strong></td>
                    <td>
                        ${renderQualityPartsV1(item.parts_used, item.repair_type)}
                        <div class="mt-2 small"><span class="fw-semibold">Miscellaneous:</span> ${renderMiscellaneousViewV1(item)}</div>
                        ${renderQualityRepairCostSummaryV1(item)}
                    </td>
                </tr>
            `;
                }).join('');
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('qualityCheckModalV1')).show();
        }

        function openQualityCheckModalV1(row) {
            openQualityCheckModalV1FromData(rowData(row));
        }


        function openQualityReturnToRepairModalV1() {
            const risId = document.getElementById('qualityRisIdV1')?.value || '';
            if (!risId) {
                Swal.fire({ icon: 'warning', title: 'RIS not found', text: 'Please open the Quality Check modal again.', confirmButtonColor: '#07b83f' });
                return;
            }
            document.getElementById('returnQualityRisIdV1').value = risId;
            document.getElementById('returnQualityReasonV1').value = '';
            const qualityModalEl = document.getElementById('qualityCheckModalV1');
            const returnModalEl = document.getElementById('qualityReturnToRepairModalV1');
            bootstrap.Modal.getOrCreateInstance(returnModalEl).show();
            // Keep the quality modal behind it; Bootstrap handles the stacking.
        }

        document.getElementById('qualityCheckFormV1')?.addEventListener('submit', function (event) {
            const rows = JSON.parse(document.getElementById('qualityCheckJsonV1').value || '[]');
            if (!rows.length) {
                event.preventDefault();
                Swal.fire({ icon: 'warning', title: 'No completed repairs', text: 'No completed repair logs found for quality check.', confirmButtonColor: '#07b83f' });
                return;
            }
            if (!String(document.getElementById('qualityCheckDateV1').value || '').trim() || !String(document.getElementById('qualityCheckTimeV1').value || '').trim() || !String(document.getElementById('qualityCheckByV1').value || '').trim()) {
                event.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Missing quality check details', text: 'Please complete Date, Time, and Quality Check By.', confirmButtonColor: '#07b83f' });
            }
        });

        function getStartRepairMechanicFromProgressV11(data) {
            if (data.mechanic) return data.mechanic;
            try {
                const progress = JSON.parse(data.repair_progress_json || '[]');
                if (Array.isArray(progress)) {
                    for (const item of progress) {
                        const mechanic = String(item && item.mechanic ? item.mechanic : '').trim();
                        if (mechanic !== '') return mechanic;
                    }
                }
            } catch (error) { }
            return '';
        }


        function normalizeReleaseQtyV15(value) {
            const qty = Number(value || 0);
            if (!Number.isFinite(qty) || qty < 0) return 0;
            return qty;
        }

        function releaseNumberV15(value) {
            return Number(String(value ?? '0').replace(/,/g, '')) || 0;
        }

        function releasePartKeyV15(part) {
            return String(part?.item_no || part?.item_code || part?.item_name || part?.item || part?.name || '').trim().toLowerCase();
        }

        function buildReleaseAssessmentLookupV15(data) {
            let assessment = [];
            try { assessment = JSON.parse(data.assessment_json || '[]'); } catch (error) { assessment = []; }
            if (!Array.isArray(assessment)) assessment = [];
            const lookup = {};
            assessment.forEach(function (repair) {
                const parts = Array.isArray(repair?.parts) ? repair.parts : [];
                parts.forEach(function (part) {
                    const keys = [part?.item_no, part?.item_code, part?.item_name, part?.item_label, part?.description].map(function (v) { return String(v || '').trim().toLowerCase(); }).filter(Boolean);
                    keys.forEach(function (key) {
                        if (!lookup[key]) lookup[key] = part;
                    });
                });
            });
            return lookup;
        }

        function enrichReleasePartFromAssessmentV15(part, lookup) {
            const key = releasePartKeyV15(part);
            const assessed = key ? (lookup[key] || null) : null;
            if (!assessed) return part;
            const merged = Object.assign({}, assessed, part);
            if (!merged.unit_cost && assessed.unit_cost) merged.unit_cost = assessed.unit_cost;
            if (!merged.total_cost && !merged.estimated_total_cost && !merged.estimated_cost) {
                const qty = normalizeReleaseQtyV15(merged.used_quantity || merged.qty_used || merged.qty_to_use || merged.quantity_used || merged.quantity_to_use || merged.qty || merged.quantity || 0);
                const unit = partUnitCostV32(merged);
                if (qty > 0 && unit > 0) merged.total_cost = qty * unit;
            }
            if (!merged.source_by && assessed.source_by) merged.source_by = assessed.source_by;
            if (!merged.source_label && assessed.source_label) merged.source_label = assessed.source_label;
            if (!merged.branch_sourced && assessed.branch_sourced) merged.branch_sourced = assessed.branch_sourced;
            return merged;
        }

        function addReleasePartToMapV15(partsMap, part) {
            if (!part || typeof part !== 'object') return;
            const itemNo = String(part.item_no || part.item_code || part.item_name || part.item || part.name || '').trim();
            const qty = normalizeReleaseQtyV15(part.used_quantity || part.qty_used || part.qty_to_use || part.quantity_to_use || part.quantity_used || part.qty || part.quantity || 0);
            if (!itemNo && qty <= 0) return;
            const key = (itemNo || 'N/A').toLowerCase();
            const sourceBy = partSourceByV32(part);
            const itemCost = sourceBy === 'branch' ? 0 : partMotorpoolBillableCostV32(part, true);
            const next = {
                item_no: itemNo || 'N/A',
                qty_used: qty,
                source_by: sourceBy,
                source_label: partSourceLabelV32(part),
                unit_cost: partUnitCostV32(part),
                item_cost: itemCost
            };
            const existing = partsMap.get(key);
            if (!existing) {
                partsMap.set(key, next);
                return;
            }
            const existingCost = releaseNumberV15(existing.item_cost);
            const existingQty = releaseNumberV15(existing.qty_used);
            partsMap.set(key, {
                item_no: next.item_no || existing.item_no,
                qty_used: next.qty_used || existingQty,
                source_by: next.source_by || existing.source_by,
                source_label: next.source_label || existing.source_label,
                unit_cost: next.unit_cost || existing.unit_cost,
                item_cost: itemCost > 0 || existingCost <= 0 ? itemCost : existingCost
            });
        }

        function addReleaseLogToDetailsV15(item, details, forceInclude = false) {
            if (!item || typeof item !== 'object') return;

            const stage = String(item.stage || '').toLowerCase();
            const status = String(item.status || item.log_status || '').toLowerCase();
            const hasEndDate = String(item.end_date || item.completion_date || item.repair_end_date || item.end_datetime || '').trim() !== '';
            const isDone = forceInclude || !!item.done || stage === 'done' || !!item.locked || status === 'done' || status === 'completed' || hasEndDate;
            if (!isDone) return;

            const repair = String(item.repair || item.repair_description || '').trim();
            if (repair) details.repairs.add(repair);

            const mechanic = String(item.completion_mechanic || item.mechanic || '').trim();
            if (mechanic) details.mechanics.add(mechanic);

            const startDate = String(item.start_date || item.repair_start_date || item.start_datetime || '').trim().slice(0, 10);
            const endDate = String(item.end_date || item.completion_date || item.repair_end_date || item.end_datetime || '').trim().slice(0, 10);
            if (startDate && (!details.firstStartDate || startDate < details.firstStartDate)) details.firstStartDate = startDate;
            if (endDate && (!details.lastEndDate || endDate > details.lastEndDate)) details.lastEndDate = endDate;

            let parts = item.parts_used || item.parts || [];
            if (!Array.isArray(parts)) parts = [];
            parts.forEach(function (part) {
                addReleasePartToMapV15(details.partsMap, enrichReleasePartFromAssessmentV15(part, details.assessmentLookup || {}));
            });
        }

        function parseReleasePartsTextV15(text) {
            const rows = [];
            String(text || '').split(/\n|;/).forEach(function (line) {
                line = line.trim();
                if (!line || line.toLowerCase().includes('no parts')) return;
                let itemNo = '';
                let qty = 0;
                const itemMatch = line.match(/Item\s*No\.?\s*:\s*([^|]+)/i);
                const qtyMatch = line.match(/(?:Qty|Quantity)\s*(?:Used|To Use|Replaced)?\s*:\s*([0-9.]+)/i);
                if (itemMatch) itemNo = itemMatch[1].trim();
                if (qtyMatch) qty = Number(qtyMatch[1]) || 0;
                if (itemNo || qty > 0) rows.push({ item_no: itemNo || 'N/A', used_quantity: qty });
            });
            return rows;
        }

        function collectReleaseDetailsV15(data) {
            const details = {
                mechanics: new Set(),
                repairs: new Set(),
                partsMap: new Map(),
                firstStartDate: data.repair_start_date || '',
                lastEndDate: data.repair_end_date || '',
                assessmentLookup: buildReleaseAssessmentLookupV15(data)
            };

            let serverReleaseDetails = {};
            try {
                serverReleaseDetails = typeof data.release_details_json === 'string' ? JSON.parse(data.release_details_json || '{}') : (data.release_details_json || {});
            } catch (error) {
                serverReleaseDetails = {};
            }

            if (serverReleaseDetails && typeof serverReleaseDetails === 'object') {
                String(serverReleaseDetails.mechanic || '').split(',').forEach(function (mechanic) {
                    mechanic = mechanic.trim();
                    if (mechanic) details.mechanics.add(mechanic);
                });
                String(serverReleaseDetails.repairs_done || '').split(/\n+/).forEach(function (repair) {
                    repair = repair.trim();
                    if (repair) details.repairs.add(repair);
                });
                if (serverReleaseDetails.start_date && (!details.firstStartDate || serverReleaseDetails.start_date < details.firstStartDate)) details.firstStartDate = serverReleaseDetails.start_date;
                if (serverReleaseDetails.end_date && (!details.lastEndDate || serverReleaseDetails.end_date > details.lastEndDate)) details.lastEndDate = serverReleaseDetails.end_date;
                parseReleasePartsTextV15(serverReleaseDetails.parts_replaced || '').forEach(function (part) {
                    addReleasePartToMapV15(details.partsMap, part);
                });
            }

            // Always read progress and quality JSON so source/cost/miscellaneous values are available.
            // The Map replaces duplicates, so the same item is not double-counted.
            let progressLogs = [];
            try { progressLogs = JSON.parse(data.repair_progress_json || '[]'); } catch (error) { progressLogs = []; }
            if (!Array.isArray(progressLogs)) progressLogs = [];
            progressLogs.forEach(function (item) { addReleaseLogToDetailsV15(item, details, false); });

            let qualityLogs = [];
            try { qualityLogs = JSON.parse(data.quality_check_json || '[]'); } catch (error) { qualityLogs = []; }
            if (!Array.isArray(qualityLogs)) qualityLogs = [];
            qualityLogs.forEach(function (item) { addReleaseLogToDetailsV15(item, details, true); });

            if (!details.partsMap.size) {
                parseReleasePartsTextV15(data.parts_replaced || '').forEach(function (part) {
                    addReleasePartToMapV15(details.partsMap, part);
                });
            }

            if (!details.mechanics.size) {
                const fallbackMechanic = getStartRepairMechanicFromProgressV11(data);
                if (fallbackMechanic) details.mechanics.add(fallbackMechanic);
            }

            const partsRows = Array.from(details.partsMap.values()).map(function (part) {
                const qty = releaseNumberV15(part.qty_used);
                return {
                    item_no: part.item_no || 'N/A',
                    qty_used_raw: qty,
                    qty_used: qty.toLocaleString(undefined, { maximumFractionDigits: 2 }),
                    source_by: part.source_by || 'motorpool',
                    source_label: part.source_label || (part.source_by === 'branch' ? 'Branch Source' : 'Motorpool Source'),
                    item_cost: releaseNumberV15(part.item_cost)
                };
            });

            const costRowsSource = qualityLogs.length ? qualityLogs : progressLogs;
            const repairCostSeen = new Set();
            let repairCost = 0;
            let miscCost = 0;
            let miscDescriptions = [];
            costRowsSource.forEach(function (item) {
                if (!item || typeof item !== 'object') return;
                const stage = String(item.stage || '').toLowerCase();
                const status = String(item.status || item.log_status || '').toLowerCase();
                const hasEndDate = String(item.end_date || item.completion_date || item.repair_end_date || item.end_datetime || '').trim() !== '';
                const isDone = !!item.done || stage === 'done' || !!item.locked || status === 'done' || status === 'completed' || hasEndDate || qualityLogs.length;
                if (!isDone) return;
                const key = String(item.repair || item.repair_description || Math.random()).trim().toLowerCase();
                if (!repairCostSeen.has(key)) {
                    repairCost += repairCostValueV52(item);
                    miscCost += miscellaneousCostValueV1(item);
                    const miscDesc = miscellaneousDescriptionValueV1(item);
                    if (miscDesc) miscDescriptions.push(miscDesc);
                    repairCostSeen.add(key);
                }
            });
            const itemCost = partsRows.reduce(function (sum, part) { return sum + releaseNumberV15(part.item_cost); }, 0);
            const grandTotal = repairCost + itemCost + miscCost;

            const partsText = partsRows.length
                ? partsRows.map(function (part) { return 'Item No.: ' + part.item_no + ' | Qty Used: ' + part.qty_used + ' | Source: ' + (part.source_label || '') + ' | Item Cost: ' + moneyPhpStyleV32(part.item_cost || 0); }).join('\n')
                : 'No parts replaced / used.';

            return {
                mechanics: Array.from(details.mechanics).join(', '),
                repairs_done: Array.from(details.repairs).join('\n'),
                start_date: details.firstStartDate || '',
                end_date: details.lastEndDate || '',
                parts_rows: partsRows,
                parts_text: partsText,
                repair_cost: repairCost,
                item_cost: itemCost,
                miscellaneous_cost: miscCost,
                miscellaneous_description: miscDescriptions.join(', '),
                grand_total: grandTotal
            };
        }

        function renderReleasePartsTableV15(partsRows) {
            const tbody = document.querySelector('#releasePartsReplacedTableV15 tbody');
            if (!tbody) return;
            if (!partsRows || !partsRows.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No parts replaced / used.</td></tr>';
                return;
            }
            tbody.innerHTML = partsRows.map(function (part) {
                const sourceBy = String(part.source_by || '').toLowerCase() === 'branch' ? 'branch' : 'motorpool';
                const sourceLabel = part.source_label || (sourceBy === 'branch' ? 'Branch Source' : 'Motorpool Source');
                const badge = '<span class="badge ' + (sourceBy === 'branch' ? 'bg-primary' : 'bg-success') + '">' + esc(sourceLabel) + '</span>';
                return '<tr>'
                    + '<td>' + esc(part.item_no) + '</td>'
                    + '<td>' + esc(part.qty_used) + '</td>'
                    + '<td>' + badge + '</td>'
                    + '<td class="text-end fw-semibold">' + moneyPhpStyleV32(part.item_cost || 0) + '</td>'
                    + '</tr>';
            }).join('');
        }

        function renderReleaseCostSummaryV15(details) {
            const repair = releaseNumberV15(details.repair_cost);
            const item = releaseNumberV15(details.item_cost);
            const misc = releaseNumberV15(details.miscellaneous_cost);
            const grand = releaseNumberV15(details.grand_total);
            const setText = function (id, value) {
                const el = document.getElementById(id);
                if (el) el.textContent = value;
            };
            setText('releaseRepairCostV15', moneyPhpStyleV32(repair));
            setText('releaseItemCostV15', moneyPhpStyleV32(item));
            setText('releaseMiscCostV15', moneyPhpStyleV32(misc));
            setText('releaseGrandTotalV15', moneyPhpStyleV32(grand));
            const miscDesc = document.getElementById('releaseMiscDescV15');
            if (miscDesc) miscDesc.textContent = details.miscellaneous_description || (misc > 0 ? 'Miscellaneous encoded.' : 'No miscellaneous encoded.');
        }

        function openReleaseCompletionModalV15(row) {
            const data = rowData(row);
            const today = new Date().toISOString().slice(0, 10);
            const now = new Date();
            const currentTime = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
            const releaseDetails = collectReleaseDetailsV15(data);

            document.getElementById('releaseRisIdV15').value = data.ris_id || '';
            document.getElementById('releaseRepairDateV15').value = releaseDetails.end_date || data.repair_end_date || today;
            document.getElementById('releaseRisNoV15').value = data.ris_number || '';
            document.getElementById('releasePartsReplacedV15').value = releaseDetails.parts_text || 'No parts replaced / used.';
            renderReleasePartsTableV15(releaseDetails.parts_rows);
            renderReleaseCostSummaryV15(releaseDetails);
            document.getElementById('releaseMechanicV15').value = releaseDetails.mechanics || '';
            document.getElementById('releaseStartDateV15').value = releaseDetails.start_date || data.repair_start_date || '';
            document.getElementById('releaseEndDateV15').value = releaseDetails.end_date || data.repair_end_date || today;
            document.getElementById('releaseCheckedReceivedByV15').value = '';
            document.getElementById('releaseReceivedDateV15').value = today;
            document.getElementById('releaseReceivedTimeV15').value = currentTime;
            document.getElementById('releaseAttachmentV15').value = '';

            bootstrap.Modal.getOrCreateInstance(document.getElementById('releaseCompletionModalV15')).show();
        }

        document.getElementById('releaseCompletionFormV15')?.addEventListener('submit', function (event) {
            const requiredFields = [
                ['releaseRepairDateV15', 'Date'],
                ['releasePartsReplacedV15', 'Parts Replaced / Used'],
                ['releaseMechanicV15', 'Mechanics'],
                ['releaseStartDateV15', 'Start Date'],
                ['releaseEndDateV15', 'End Date'],
                ['releaseCheckedReceivedByV15', 'Checked and Received By'],
                ['releaseReceivedDateV15', 'Date Received'],
                ['releaseReceivedTimeV15', 'Time Received']
            ];

            for (const [id, label] of requiredFields) {
                const field = document.getElementById(id);
                if (!field || !String(field.value || '').trim()) {
                    event.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Missing ' + label,
                        text: 'Please complete all release details before saving to repair history.',
                        confirmButtonColor: '#07b83f'
                    });
                    return;
                }
            }

            const attachment = document.getElementById('releaseAttachmentV15');
            if (!attachment || !attachment.files || attachment.files.length === 0) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Completion proof required',
                    text: 'Please attach proof that the repair is complete before releasing the vehicle.',
                    confirmButtonColor: '#07b83f'
                });
            }
        });

        function logout() {
            Swal.fire({
                title: 'Logout?',
                text: 'Are you sure you want to logout?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#07b83f',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Logout',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.isConfirmed) {
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // ========== SIDEBAR FUNCTIONS copied/adapted from chartofaccounts(5).php ==========
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

        function toggleSidebarDropdown(event, targetId) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            const target = document.getElementById(targetId);
            const btn = event ? event.currentTarget : document.querySelector(`[onclick*="${targetId}"]`);
            const arrow = btn ? btn.querySelector('.dropdown-arrow') : null;
            const sidebar = document.getElementById('sidebar');

            if (!target) return false;

            if (sidebar && window.innerWidth > 992 && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                document.getElementById('mainContent')?.classList.remove('expanded');
                localStorage.setItem('sidebarCollapsed', 'false');

                setTimeout(function () {
                    document.querySelectorAll('.sidebar .collapse.show').forEach(function (collapse) {
                        if (collapse.id !== targetId) {
                            collapse.classList.remove('show');
                            const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                            setArrowState(otherBtn ? otherBtn.querySelector('.dropdown-arrow') : null, false);
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
                        const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                        setArrowState(otherBtn ? otherBtn.querySelector('.dropdown-arrow') : null, false);
                    }
                });

                target.classList.add('show');
                setArrowState(arrow, true);
            }

            return false;
        }

        window.toggleSidebar = function () {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('mainContent');
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
                            setTimeout(function () { overlay.remove(); }, 300);
                        });
                    }
                    setTimeout(function () { overlay.classList.add('active'); }, 10);
                } else if (overlay) {
                    overlay.classList.remove('active');
                    setTimeout(function () { overlay.remove(); }, 300);
                }
            } else {
                const wasCollapsed = sidebar.classList.contains('collapsed');
                sidebar.classList.toggle('collapsed');
                main?.classList.toggle('expanded', sidebar.classList.contains('collapsed'));
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
                                    setArrowState(parentLink ? parentLink.querySelector('.dropdown-arrow') : null, true);
                                }
                            }
                        });
                    }, 150);
                } else if (sidebar.classList.contains('collapsed')) {
                    document.querySelectorAll('.sidebar .collapse.show').forEach(function (collapse) {
                        collapse.classList.remove('show');
                        const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                        setArrowState(parentBtn ? parentBtn.querySelector('.dropdown-arrow') : null, false);
                    });
                }
            }
            return false;
        };

        function setActiveSidebarItem() {
            const currentPage = window.location.pathname.split('/').pop() || 'request_handler.php';

            document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
                link.classList.remove('active');
            });
            document.querySelectorAll('.sidebar .nav-item').forEach(function (item) {
                item.classList.remove('active');
            });

            document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
                const href = (link.getAttribute('href') || '').split('?')[0];
                if (href === currentPage) {
                    link.classList.add('active');
                    link.closest('.nav-item')?.classList.add('active');

                    const collapseDiv = link.closest('.collapse');
                    if (collapseDiv) {
                        collapseDiv.classList.add('show');
                        const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                        setArrowState(parentBtn ? parentBtn.querySelector('.dropdown-arrow') : null, true);
                    }
                }
            });

            const sidebar = document.getElementById('sidebar');
            if (sidebar && sidebar.classList.contains('collapsed')) {
                document.querySelectorAll('.dropdown-nav').forEach(function (dropdownNav) {
                    const hasActiveChild = dropdownNav.querySelector('.nav-link.active');
                    const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                    if (hasActiveChild && parentLink) parentLink.classList.add('active');
                    else if (parentLink) parentLink.classList.remove('active');
                });
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('mainContent');
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn') || document.getElementById('mobileToggleBtn');

            if (sidebar && window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    main?.classList.add('expanded');
                } else {
                    sidebar.classList.remove('collapsed');
                    main?.classList.remove('expanded');
                }
            }

            if (desktopToggleBtn && !desktopToggleBtn.dataset.sidebarBound) {
                desktopToggleBtn.dataset.sidebarBound = '1';
                desktopToggleBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.toggleSidebar();
                });
            }

            if (mobileMenuBtn && !mobileMenuBtn.dataset.sidebarBound) {
                mobileMenuBtn.dataset.sidebarBound = '1';
                mobileMenuBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.toggleSidebar();
                });
            }

            setActiveSidebarItem();

            document.addEventListener('click', function (e) {
                const mobileBtn = document.getElementById('mobileMenuBtn') || document.getElementById('mobileToggleBtn');
                if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') &&
                    !sidebar.contains(e.target) && (!mobileBtn || !mobileBtn.contains(e.target))) {
                    sidebar.classList.remove('active');
                    const overlay = document.querySelector('.sidebar-overlay');
                    if (overlay) overlay.remove();
                }
            });

            document.querySelectorAll('.sidebar .collapse').forEach(function (collapse) {
                if (collapse.dataset.sidebarCollapseBound) return;
                collapse.dataset.sidebarCollapseBound = '1';
                collapse.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            });

            window.addEventListener('resize', function () {
                const overlay = document.querySelector('.sidebar-overlay');
                if (!sidebar) return;

                if (window.innerWidth > 992) {
                    if (overlay) overlay.remove();
                    sidebar.classList.remove('active');
                    const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                    sidebar.classList.toggle('collapsed', savedCollapsed === 'true');
                    main?.classList.toggle('expanded', savedCollapsed === 'true');
                } else {
                    sidebar.classList.remove('collapsed');
                    main?.classList.remove('expanded');
                }
            });
        }



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


        document.addEventListener('DOMContentLoaded', function () {
            initializeSidebar();
            setActiveMobileNav();

            <?php if (!empty($save_message)): ?>
                Swal.fire({
                    icon: '<?php echo h($save_status === 'success' ? 'success' : 'error'); ?>',
                    title: '<?php echo h($save_status === 'success' ? 'Saved' : 'Error'); ?>',
                    text: '<?php echo h($save_message); ?>',
                    confirmButtonColor: '<?php echo h($save_status === 'success' ? '#07b83f' : '#dc3545'); ?>'
                }).then(function () {
                    <?php if ($save_status === 'success'): ?>
                        window.location.href = window.location.pathname;
                    <?php endif; ?>
                });
            <?php endif; ?>
        });


        // Quality Check button binding must be inside a normal inline script, not inside the external Bootstrap script tag.
        window.openQualityCheckModalV1ById = openQualityCheckModalV1ById;
        window.openQualityCheckModalV1 = openQualityCheckModalV1;
        window.openQualityCheckModalV1FromData = openQualityCheckModalV1FromData;

        document.addEventListener('click', function (event) {
            const btn = event.target.closest ? event.target.closest('.js-quality-check-btn') : null;
            if (!btn) return;
            event.preventDefault();
            event.stopPropagation();
            openQualityCheckModalV1ById(btn.dataset.risId || '', btn);
        });
    </script>

    <script>
        /* Final fallback Quality Check modal opener.
           This script is intentionally standalone. It does not depend on the large page script,
           so the Quality Check button still works even if another script block has an error. */
        (function () {
            function qEsc(value) {
                return String(value ?? '').replace(/[&<>'"]/g, function (ch) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ch]);
                });
            }
            function qMoney(value) {
                var amount = Number(String(value || 0).replace(/,/g, '')) || 0;
                return '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            function qParseJson(value, fallback) {
                try {
                    if (Array.isArray(value)) return value;
                    if (value && typeof value === 'object') return value;
                    var text = String(value || '').trim();
                    if (!text) return fallback;
                    return JSON.parse(text);
                } catch (e) {
                    return fallback;
                }
            }
            function qRowDataFromButton(btn) {
                var row = btn && btn.closest ? btn.closest('tr.ris-row') : null;
                if (!row) row = btn && btn.closest ? btn.closest('[data-ris]') : null;
                var data = qParseJson(row ? row.getAttribute('data-ris') : '', {});
                if ((!data || !data.ris_id) && btn && btn.dataset && btn.dataset.risId) {
                    data = data || {};
                    data.ris_id = btn.dataset.risId;
                }
                return data || {};
            }
            function qInfoGrid(data) {
                return '' +
                    '<div class="info-item"><small>RIS No.</small><strong>' + qEsc(data.ris_number || '-') + '</strong></div>' +
                    '<div class="info-item"><small>Branch</small><strong>' + qEsc(data.display_branch_name || data.branch_name || (data.branch_id ? 'Branch #' + data.branch_id : 'N/A')) + '</strong></div>' +
                    '<div class="info-item"><small>Plate No.</small><strong>' + qEsc(data.plate_no || '-') + '</strong></div>' +
                    '<div class="info-item"><small>Vehicle ID</small><strong>' + qEsc(data.vehicle_id || '-') + '</strong></div>' +
                    '<div class="info-item"><small>Vehicle Details</small><strong>' + qEsc(data.vehicle_details || data.vehicle_category || '-') + '</strong></div>' +
                    '<div class="info-item"><small>Status</small><strong>' + qEsc(data.workflow_status || data.status || '-') + '</strong></div>';
            }
            function qPartLabel(part) {
                return String(part.item_no || part.item_code || part.item_name || part.description || part.item || '-');
            }
            function qPartQty(part) {
                return String(part.used_quantity || part.qty_used || part.quantity_used || part.qty_to_use || part.quantity_to_use || part.quantity || part.qty || '');
            }
            function qRenderParts(parts, repairType) {
                var list = Array.isArray(parts) ? parts : [];
                if (!list.length || repairType === 'labor') {
                    return '<span class="text-muted">Labor only / No parts used.</span>';
                }
                return '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Parts / Item Used</th><th class="text-center">Qty Used</th><th class="text-end">Cost</th></tr></thead><tbody>' +
                    list.map(function (part) {
                        var source = String(part.source_by || part.parts_source_by || part.source || '').toLowerCase();
                        var isBranch = source.indexOf('branch') !== -1 || part.branch_sourced || part.branch_purchased;
                        var cost = isBranch ? '₱0.00' : qMoney(part.total_cost || part.estimated_total_cost || 0);
                        return '<tr><td>' + qEsc(qPartLabel(part)) + '</td><td class="text-center">' + qEsc(qPartQty(part) || '-') + '</td><td class="text-end">' + cost + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
            }
            function qNormalizeRows(data) {
                var directRows = qParseJson(data.quality_completed_rows_json, []);
                if (Array.isArray(directRows) && directRows.length) return directRows;

                var progress = qParseJson(data.repair_progress_json, []);
                var rows = [];
                if (Array.isArray(progress)) {
                    rows = progress.filter(function (item) {
                        var stage = String(item && item.stage || '').toLowerCase().trim();
                        return item && (item.done == 1 || item.done === true || item.locked == 1 || item.locked === true || stage === 'done' || stage === 'completed');
                    });
                }
                if (rows.length) return rows;

                var releaseRows = qParseJson(data.release_details_json, []);
                if (Array.isArray(releaseRows) && releaseRows.length) return releaseRows;
                return [];
            }
            function qOpenQualityModal(data) {
                var modalEl = document.getElementById('qualityCheckModalV1');
                if (!modalEl) {
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Quality Check modal not found', text: 'Missing #qualityCheckModalV1 in this page.', confirmButtonColor: '#07b83f' });
                    return;
                }
                var today = new Date().toISOString().slice(0, 10);
                var nowTime = new Date().toTimeString().slice(0, 5);
                var rows = qNormalizeRows(data);

                var risId = document.getElementById('qualityRisIdV1');
                var info = document.getElementById('qualityInfoGridV1');
                var date = document.getElementById('qualityCheckDateV1');
                var time = document.getElementById('qualityCheckTimeV1');
                var by = document.getElementById('qualityCheckByV1');
                var remarks = document.getElementById('qualityRemarksV1');
                var json = document.getElementById('qualityCheckJsonV1');
                var body = document.getElementById('qualityCheckBodyV1');

                if (risId) risId.value = data.ris_id || '';
                if (info) info.innerHTML = qInfoGrid(data);
                if (date) date.value = today;
                if (time) time.value = nowTime;
                if (by) by.value = '';
                if (remarks) remarks.value = '';
                if (json) json.value = JSON.stringify(rows);
                if (body) {
                    body.innerHTML = rows.length ? rows.map(function (item) {
                        return '<tr><td class="text-center"><strong>' + qEsc(item.repair || item.repair_description || '-') + '</strong></td><td>' + qRenderParts(item.parts_used || item.parts || [], item.repair_type || 'labor') + '</td></tr>';
                    }).join('') : '<tr><td colspan="2" class="text-center text-muted py-3">No completed repair logs found.</td></tr>';
                }
                if (window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                } else {
                    modalEl.classList.add('show');
                    modalEl.style.display = 'block';
                    modalEl.removeAttribute('aria-hidden');
                }
            }
            function qFetchAndOpen(btn) {
                var data = qRowDataFromButton(btn);
                // Open immediately from the row payload so the button always responds.
                // Then silently refresh with server data if available.
                qOpenQualityModal(data);

                var risId = (btn && btn.dataset && btn.dataset.risId) || data.ris_id || '';
                if (!risId || !window.fetch || !window.FormData) return;
                var fd = new FormData();
                fd.append('action', 'fetch_quality_check_modal_data_v1');
                fd.append('ris_id', risId);
                fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (result) {
                        if (result && result.success && result.data) qOpenQualityModal(result.data);
                    })
                    .catch(function () { });
            }

            window.openQualityCheckModalFixedFinal = qOpenQualityModal;

            document.addEventListener('click', function (event) {
                var target = event.target;
                var btn = target && target.closest ? target.closest('.js-quality-check-btn, button') : null;
                if (!btn) return;
                var text = (btn.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
                if (!btn.classList.contains('js-quality-check-btn') && text !== 'quality check') return;
                event.preventDefault();
                event.stopPropagation();
                if (event.stopImmediatePropagation) event.stopImmediatePropagation();
                qFetchAndOpen(btn);
                return false;
            }, true);
        })();
    </script>

</body>

</html>