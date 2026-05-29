<?php
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

function cleanupDuplicateRepairHistory(mysqli $conn): void {
    if (!tableExists($conn, 'vehicle_repair_history')) {
        return;
    }

    $result = $conn->query("SELECT ris_id
                            FROM vehicle_repair_history
                            WHERE ris_id IS NOT NULL AND ris_id > 0
                            GROUP BY ris_id
                            HAVING COUNT(*) > 1");

    if (!$result) {
        return;
    }

    while ($group = $result->fetch_assoc()) {
        $risId = (int)($group['ris_id'] ?? 0);
        if ($risId <= 0) {
            continue;
        }

        $stmt = $conn->prepare("SELECT repair_id, repair_date, repairs_done, parts_replaced, mechanic, start_date, end_date, attachment, repair_cost, created_at
                                FROM vehicle_repair_history
                                WHERE ris_id = ?");
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('i', $risId);
        $stmt->execute();
        $rows = $stmt->get_result();

        $keepId = 0;
        $bestScore = -1;

        while ($row = $rows->fetch_assoc()) {
            $score = 0;
            $score += trim((string)($row['repair_date'] ?? '')) !== '' ? 1 : 0;
            $score += trim((string)($row['repairs_done'] ?? '')) !== '' ? 2 : 0;
            $score += trim((string)($row['parts_replaced'] ?? '')) !== '' ? 2 : 0;
            $score += trim((string)($row['mechanic'] ?? '')) !== '' ? 2 : 0;
            $score += trim((string)($row['start_date'] ?? '')) !== '' ? 1 : 0;
            $score += trim((string)($row['end_date'] ?? '')) !== '' ? 1 : 0;
            $score += trim((string)($row['attachment'] ?? '')) !== '' ? 3 : 0;
            $score += (float)($row['repair_cost'] ?? 0) > 0 ? 1 : 0;

            $repairId = (int)($row['repair_id'] ?? 0);
            if ($score > $bestScore || ($score === $bestScore && $repairId > $keepId)) {
                $bestScore = $score;
                $keepId = $repairId;
            }
        }

        $stmt->close();

        if ($keepId > 0) {
            $deleteStmt = $conn->prepare("DELETE FROM vehicle_repair_history
                                          WHERE ris_id = ?
                                            AND repair_id <> ?");
            if ($deleteStmt) {
                $deleteStmt->bind_param('ii', $risId, $keepId);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'branch_review_motorpool_assessment') {
    $ris_id = (int)($_POST['approval_ris_id'] ?? 0);
    $decision = strtolower(trim($_POST['approval_decision'] ?? ''));
    $remarks = trim($_POST['approval_remarks'] ?? '');

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
            $sql = "UPDATE motorpool_ris_requests
                    SET workflow_status = 'For Parts Completion',
                        status = 'For Parts Completion',
                        branch_approval_status = 'Approved',
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
                    $save_message = 'Motorpool assessment approved. Request is now for parts completion.';
                } else {
                    $save_status = 'error';
                    $save_message = 'Unable to approve. The request may have already been updated.';
                }
                $stmt->close();
            } else {
                $save_status = 'error';
                $save_message = 'Failed to prepare approval: ' . $conn->error;
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

function fetchVehicleRepairHistories(mysqli $conn, array $vehicles, int $branch_id, bool $view_all_branches): array {
    $histories = [];
    $ids = [];

    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) {
            $ids[] = (int)$vehicle['id'];
        }
    }

    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) {
        return $histories;
    }

    $idList = implode(',', array_map('intval', $ids));

    /*
     * Repair history must come from vehicle_repair_history only.
     * Do not also pull completed motorpool_ris_requests because that creates
     * duplicate history rows for the same RIS.
     */
    $sql = "SELECT repair_id, vehicle_db_id, ris_id, ris_number, repair_date, repairs_done, parts_replaced, mechanic, start_date, end_date, attachment, repair_cost, created_at
            FROM vehicle_repair_history
            WHERE vehicle_db_id IN ($idList)
            ORDER BY COALESCE(repair_date, DATE(created_at)) DESC, repair_id DESC";

    $result = $conn->query($sql);
    $seenRis = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $vid = (int)($row['vehicle_db_id'] ?? 0);
            $risKey = trim((string)($row['ris_id'] ?? ''));

            if ($risKey !== '') {
                if (isset($seenRis[$risKey])) {
                    continue;
                }
                $seenRis[$risKey] = true;
            }

            $histories[$vid][] = $row;
        }
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
$vehicleRegistrationHistories = fetchVehicleRegistrationHistories($conn, $vehicles);
$motorpoolApprovalRequests = fetchMotorpoolAssessmentsForApproval($conn, (int)$branch_id, (bool)$view_all_branches);
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
.approval-parts-table td {
    vertical-align: top;
    word-break: break-word;
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
            <span class="nav-text">Branch Admin</span>
        </h3>
    </div>
    
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                            <li class="nav-item">
                <a class="nav-link" href="branchdashboard.php">
                    <i class="bi bi-speedometer2"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
                    </li>
                <!-- Warehouse Dropdown - walang dropdown-toggle class -->
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
            
                                <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
        </ul>
    </div>
</li>

                
                <!-- Supplier Dropdown - walang dropdown-toggle class -->
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
                    <span class="nav-text">Receive Inventory</span>
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

<li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                                <i class="bi bi-people"></i><span class="nav-text">Customer</span><i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="customerMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="customer_list.php"><i class="bi bi-person-badge"></i><span class="nav-text">Customer List</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="approve_credit_requests.php"><i class="bi bi-pencil-square"></i><span class="nav-text">Approve Credit Request</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span class="nav-text">Sales Order</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                                </ul>
                            </div>
                        </li>

<!-- Delivery Dropdown - walang dropdown-toggle class -->
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
                                    <a class="nav-link" href="expenses.php">
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


<main class="main-content" id="mainContent">
    <div id="dashboardContent" class="page-content active">
        <div class="navbar-top">
            <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>Motorpool</h2>
                <p id="dashboardSubtitle">Registered Vehicle List / Profile</p>
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
                        <tr><td colspan="8" class="text-center text-muted py-4">No registered vehicles found.</td></tr>
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
                        $registrationHistoryJson = json_encode($vehicleRegistrationHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT);
                        $dataAttrs .= ' data-registration-history="' . h($registrationHistoryJson) . '"';
                    ?>
                        <tr class="vehicle-click-row" onclick="viewVehicleDetails(this)"<?= $dataAttrs; ?>>
                            <td class="col-image"><?= motorpoolImageCell($vehicleImage, $plateNo); ?></td>
                            <td><strong><?= h($plateNo); ?></strong><br><small class="text-muted">Vehicle ID: <?= h($vehicleId); ?></small></td>
                            <td><?= h($makeBrand); ?></td>
                            <td><?= h($vehicleType); ?></td>
                            <td><?= h($vehicleCategory); ?></td>
                            <td><?= h($color); ?></td>
                            <td><?= h($yearModel); ?></td>
                            <td class="action-col text-end">
                                <button type="button" class="btn btn-success btn-sm btn-action-text" onclick="event.stopPropagation(); openRisModal(this)"><i class="bi bi-clipboard-check me-1"></i>Request for inspection</button>
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
            <div class="tab-pane fade" id="vehicleUsageTab" role="tabpanel"><div class="table-responsive"><table class="table table-bordered history-table mb-0"><thead><tr><th>Date</th><th>Transaction</th><th>Business Unit</th><th>Branch</th><th>Customer</th><th>Driver</th><th>Starting Odometer</th><th>Ending Odometer</th></tr></thead><tbody><tr><td colspan="8" class="text-muted text-center py-3">Usage history will appear here once available.</td></tr></tbody></table></div></div>
            <div class="tab-pane fade" id="vehicleRepairTab" role="tabpanel"><div class="table-responsive"><table class="table table-bordered history-table mb-0"><thead><tr><th>Date</th><th>RIS No.</th><th>Repairs Done</th><th>Parts Replaced</th><th>Mechanics</th><th>Start Date</th><th>End Date</th><th>Attachment/s</th></tr></thead><tbody id="detailRepairHistoryBody"><tr><td colspan="8" class="text-muted text-center py-3">Repair history will appear here once available.</td></tr></tbody></table></div></div>
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
    bootstrap.Modal.getOrCreateInstance(document.getElementById('branchApprovalModal')).show();
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

function renderApprovalAssessmentDetails(assessment, data) {
    if (!Array.isArray(assessment) || assessment.length === 0) {
        return '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>No detailed assessment rows were found. Please check the Motorpool assessment record.</div>';
    }

    const detailsHtml = assessment.map(function(repair, index) {
        const repairText = getRepairTextForApproval(repair);
        const parts = normalizeApprovalParts(repair);
        let partsRows = '';

        if (!parts.length) {
            partsRows = '<tr><td colspan="5" class="text-muted text-center">No item or part details listed for this repair.</td></tr>';
        } else {
            partsRows = parts.map(function(part, partIndex) {
                const itemNo = part.item_no || part.itemNo || part.item_number || part.item || part.part_no || (partIndex + 1);
                const description = part.description || part.name || part.part || part.part_name || part.item_description || '';
                const specification = part.specification || part.specs || part.spec || '';
                const quantity = part.quantity || part.qty || '';
                const purpose = part.purpose || part.reason || part.usage || '';

                return '<tr>'
                    + '<td>' + branchApprovalEscape(itemNo) + '</td>'
                    + '<td>' + branchApprovalEscape(description) + '</td>'
                    + '<td>' + branchApprovalEscape(specification) + '</td>'
                    + '<td>' + branchApprovalEscape(quantity) + '</td>'
                    + '<td>' + branchApprovalEscape(purpose) + '</td>'
                    + '</tr>';
            }).join('');
        }

        return '<div class="assessment-repair-card mb-3">'
            + '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
            + '<div class="fw-bold text-success">Repair No. ' + (index + 1) + '</div>'
            + '<span class="badge bg-light text-dark border">' + parts.length + ' item(s)</span>'
            + '</div>'
            + '<div class="border rounded bg-light p-2 mb-2 repair-work-text">' + branchApprovalEscape(repairText || 'No repair description') + '</div>'
            + '<div class="table-responsive">'
            + '<table class="table table-bordered align-middle mb-0 approval-parts-table">'
            + '<thead><tr>'
            + '<th style="width:120px;">Item No.</th>'
            + '<th>Description</th>'
            + '<th>Specification</th>'
            + '<th style="width:110px;">Quantity</th>'
            + '<th>Purpose</th>'
            + '</tr></thead>'
            + '<tbody>' + partsRows + '</tbody>'
            + '</table>'
            + '</div>'
            + '</div>';
    }).join('');

    return detailsHtml;
}

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

    document.getElementById('approvalDecision').value = decision;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: decision === 'approved' ? 'Approve assessment?' : 'Return for revision?',
            text: decision === 'approved'
                ? 'This will send the request to Motorpool for parts completion.'
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
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleModal')).show();
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
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleDetailsModal')).hide();
    fillVehicleFormFromRow(selectedVehicleRow);
    document.getElementById('vehicleFormAction').value = 'edit_vehicle';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Vehicle Profile';
    document.getElementById('vehicleSaveBtn').innerHTML = '<i class="bi bi-save me-1"></i>Update Vehicle';
    setTimeout(function(){
        bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleModal')).show();
    }, 200);
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
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleDetailsModal')).show();
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
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleDetailsModal')).hide();
    setTimeout(function(){
        bootstrap.Modal.getOrCreateInstance(document.getElementById('renewRegistrationModal')).show();
    }, 200);
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
                bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleDetailsModal')).show();
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

function renderVehicleRepairHistory(row) {
    const tbody = document.getElementById('detailRepairHistoryBody');
    if (!tbody) return;

    let history = [];
    const raw = dataValue(row, 'repairHistory');
    if (raw) {
        try {
            history = JSON.parse(raw);
        } catch (e) {
            history = [];
        }
    }

    if (!Array.isArray(history) || history.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">Repair history will appear here once available.</td></tr>';
        return;
    }

    tbody.innerHTML = history.map(item => {
        const attachment = item.attachment || item.ris_attachment || '';
        const attachmentHtml = attachment
            ? `<button type="button" class="btn btn-link p-0 text-success fw-semibold text-decoration-none" onclick="openMotorpoolFilePreview('${escapeHtml(attachment)}', 'Repair Attachment')"><i class="bi bi-paperclip me-1"></i>View</button>`
            : 'N/A';
        return `<tr>
            <td>${escapeHtml(item.repair_date || '')}</td>
            <td>${escapeHtml(item.ris_number || '')}</td>
            <td>${escapeHtml(item.repairs_done || '')}</td>
            <td>${escapeHtml(item.parts_replaced || '')}</td>
            <td>${escapeHtml(item.mechanic || '')}</td>
            <td>${escapeHtml(item.start_date || '')}</td>
            <td>${escapeHtml(item.end_date || '')}</td>
            <td>${attachmentHtml}</td>
        </tr>`;
    }).join('');
}


function getRowData(btn, key){
    const tr = btn.closest('tr');
    return tr ? (tr.dataset[key] || '') : '';
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
    bootstrap.Modal.getOrCreateInstance(risModalElement).show();
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

    signatureDraftValue = signatureInput ? signatureInput.value : '';
    signatureReturnScrollTop = risModalBody ? risModalBody.scrollTop : 0;

    bootstrap.Modal.getOrCreateInstance(risModalElement).hide();

    setTimeout(function() {
        bootstrap.Modal.getOrCreateInstance(signatureModalElement).show();
        setTimeout(function() {
            resizeSignatureCanvas();
            clearSignaturePadOnly();
            if (signatureDraftValue) {
                drawSignatureImage(signatureDraftValue);
            }
        }, 180);
    }, 180);
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

    bootstrap.Modal.getOrCreateInstance(signatureModalElement).hide();

    setTimeout(function() {
        const risInstance = bootstrap.Modal.getOrCreateInstance(risModalElement);
        risInstance.show();

        risModalElement.addEventListener('shown.bs.modal', restoreRisModalScrollPosition, { once: true });
        setTimeout(restoreRisModalScrollPosition, 260);
    }, 180);
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
</script>
</body>
</html>
