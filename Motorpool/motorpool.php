<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($user_role !== 'motorpool') {
    if ($user_role === 'branch_admin') {
        header('Location: ../Branch_Admin/branchdashboard.php');
    } elseif ($user_role === 'admin') {
        header('Location: ../Admin/dashboard.php');
    } else {
        header('Location: ../login.php');
    }
    exit;
}

$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Motorpool';
$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
}
if ($user_initials === '') $user_initials = 'MP';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

function getColumns(mysqli $conn, string $table): array {
    $columns = [];
    if (!tableExists($conn, $table)) return $columns;
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[] = $row['Field'];
    }
    return $columns;
}

function firstExisting(array $columns, array $choices): ?string {
    foreach ($choices as $choice) {
        if (in_array($choice, $columns, true)) return $choice;
    }
    return null;
}

function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    $columns = getColumns($conn, $table);
    if (!in_array($column, $columns, true)) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function uploadMotorpoolFile(string $field, string $uploadDir): string {
    if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    if (!in_array($ext, $allowed, true)) return '';
    $filename = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim($uploadDir, '/') . '/' . $filename;
    return move_uploaded_file($_FILES[$field]['tmp_name'], $target) ? $filename : '';
}

function uploadMultipleMotorpoolFiles(string $field, string $uploadDir): array {
    $saved = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return $saved;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
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

function generateNextVehicleId(mysqli $conn, string $table, array $columns): string {
    if (in_array('id', $columns, true)) {
        $result = $conn->query("SELECT MAX(`id`) AS max_id FROM `$table`");
        $maxId = 0;
        if ($result && ($row = $result->fetch_assoc())) $maxId = (int)($row['max_id'] ?? 0);
        return (string)($maxId + 1);
    }

    $vehicleCol = firstExisting($columns, ['vehicle_id', 'vehicle_code', 'vehicle_no']);
    if ($vehicleCol) {
        $result = $conn->query("SELECT MAX(CAST(`$vehicleCol` AS UNSIGNED)) AS max_vehicle_id FROM `$table`");
        $maxId = 0;
        if ($result && ($row = $result->fetch_assoc())) $maxId = (int)($row['max_vehicle_id'] ?? 0);
        return (string)($maxId + 1);
    }

    return (string)time();
}

function jsonResponse(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$vehicle_table = 'motorpool_vehicles';
$vehicle_table_exists = tableExists($conn, $vehicle_table);

if (!$vehicle_table_exists) {
    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_vehicles` (
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
        `business_unit` VARCHAR(150),
        `created_by` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_branch_id` (`branch_id`),
        KEY `idx_vehicle_id` (`vehicle_id`),
        KEY `idx_plate_no` (`plate_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $vehicle_table_exists = tableExists($conn, $vehicle_table);
}

if ($vehicle_table_exists) {
    addColumnIfMissing($conn, $vehicle_table, 'vehicle_image', '`vehicle_image` VARCHAR(255) NULL AFTER `status`');
    addColumnIfMissing($conn, $vehicle_table, 'business_unit', '`business_unit` VARCHAR(150) NULL AFTER `branch_id`');
}

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

$vehicle_columns = $vehicle_table_exists ? getColumns($conn, $vehicle_table) : [];

$fieldMap = [
    'vehicle_id' => ['vehicle_id', 'vehicle_code', 'vehicle_no'],
    'lto_cr_no' => ['lto_cr_no', 'cr_no'],
    'date_registration' => ['date_registration', 'registration_date', 'date_of_registration'],
    'plate_no' => ['plate_no', 'plate_number'],
    'engine_no' => ['engine_no', 'engine_number'],
    'chassis_no' => ['chassis_no', 'chassis_number'],
    'vin' => ['vin'],
    'file_no' => ['file_no'],
    'vehicle_type' => ['vehicle_type', 'type'],
    'vehicle_category' => ['vehicle_category', 'category'],
    'make_brand' => ['make_brand', 'make', 'brand'],
    'passenger_capacity' => ['passenger_capacity'],
    'color' => ['color'],
    'type_of_fuel' => ['type_of_fuel', 'fuel_type'],
    'classification' => ['classification'],
    'body_type' => ['body_type'],
    'series' => ['series'],
    'gross_weight' => ['gross_weight'],
    'net_weight' => ['net_weight'],
    'year_model' => ['year_model'],
    'year_rebuilt' => ['year_rebuilt'],
    'piston_displacement' => ['piston_displacement'],
    'max_power_kw' => ['max_power_kw', 'max_power'],
    'vehicle_image' => ['vehicle_image'],
    'cr_vehicle_images' => ['cr_vehicle_images', 'attachments', 'vehicle_images'],
    'reg_date' => ['reg_date', 'registration_history_date'],
    'or_no' => ['or_no'],
    'next_renewal' => ['next_renewal'],
    'or_attachment' => ['or_attachment'],
    'branch_id' => ['branch_id'],
    'business_unit' => ['business_unit', 'business_unit_name'],
    'created_by' => ['created_by', 'encoded_by'],
    'created_at' => ['created_at', 'date_created']
];

function fetchBranches(mysqli $conn): array {
    if (!tableExists($conn, 'branches')) return [];
    $columns = getColumns($conn, 'branches');
    $idCol = firstExisting($columns, ['branch_id', 'id']);
    $nameCol = firstExisting($columns, ['branch_name', 'name', 'branch']);
    $buCol = firstExisting($columns, ['business_unit', 'business_unit_name', 'bu_name', 'company', 'company_name']);
    if (!$idCol || !$nameCol) return [];

    $select = "`$idCol` AS branch_id, `$nameCol` AS branch_name";
    if ($buCol) $select .= ", `$buCol` AS business_unit";
    else $select .= ", '' AS business_unit";

    $result = $conn->query("SELECT $select FROM `branches` ORDER BY `$nameCol` ASC");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

function fetchBusinessUnits(mysqli $conn, array $branches): array {
    $units = [];
    foreach ($branches as $branch) {
        $unit = trim((string)($branch['business_unit'] ?? ''));
        if ($unit !== '') $units[$unit] = $unit;
    }

    foreach (['business_units', 'business_unit'] as $table) {
        if (!tableExists($conn, $table)) continue;
        $columns = getColumns($conn, $table);
        $nameCol = firstExisting($columns, ['business_unit', 'business_unit_name', 'name', 'unit_name', 'company_name']);
        if (!$nameCol) continue;
        $result = $conn->query("SELECT DISTINCT `$nameCol` AS business_unit FROM `$table` WHERE `$nameCol` IS NOT NULL AND `$nameCol` != '' ORDER BY `$nameCol` ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $unit = trim((string)$row['business_unit']);
                if ($unit !== '') $units[$unit] = $unit;
            }
        }
    }

    return array_values($units);
}

function branchNameMap(array $branches): array {
    $map = [];
    foreach ($branches as $branch) {
        $map[(int)$branch['branch_id']] = (string)$branch['branch_name'];
    }
    return $map;
}

function branchBusinessUnitMap(array $branches): array {
    $map = [];
    foreach ($branches as $branch) {
        $map[(int)$branch['branch_id']] = (string)($branch['business_unit'] ?? '');
    }
    return $map;
}

$branches = fetchBranches($conn);
$businessUnits = fetchBusinessUnits($conn, $branches);
$branchNames = branchNameMap($branches);
$branchBusinessUnits = branchBusinessUnitMap($branches);

$save_status = '';
$save_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    if (!$vehicle_table_exists) {
        $save_status = 'error';
        $save_message = 'motorpool_vehicles table was not found. Please check database permissions.';
    } else {
        $uploadDir = '../uploads/motorpool';
        $vehicleImage = uploadMotorpoolFile('vehicle_image', $uploadDir);
        $crImages = uploadMultipleMotorpoolFiles('cr_vehicle_images', $uploadDir);
        $orAttachment = uploadMotorpoolFile('or_attachment', $uploadDir);

        $selectedBranchId = (int)($_POST['branch_id'] ?? 0);
        $selectedBusinessUnit = trim($_POST['business_unit'] ?? '');

        if ($selectedBranchId <= 0) {
            $save_status = 'error';
            $save_message = 'Please select a branch.';
        } elseif ($selectedBusinessUnit === '') {
            $save_status = 'error';
            $save_message = 'Please select or enter a business unit.';
        } else {
            $data = [];
            foreach ($fieldMap as $formField => $choices) {
                $col = firstExisting($vehicle_columns, $choices);
                if (!$col) continue;
                if ($formField === 'vehicle_image') $data[$col] = $vehicleImage;
                elseif ($formField === 'cr_vehicle_images') $data[$col] = json_encode($crImages);
                elseif ($formField === 'or_attachment') $data[$col] = $orAttachment;
                elseif ($formField === 'branch_id') $data[$col] = (string)$selectedBranchId;
                elseif ($formField === 'business_unit') $data[$col] = $selectedBusinessUnit;
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

        $selectedBranchId = (int)($_POST['branch_id'] ?? 0);
        $selectedBusinessUnit = trim($_POST['business_unit'] ?? '');

        if ($selectedBranchId <= 0) {
            $save_status = 'error';
            $save_message = 'Please select a branch.';
        } elseif ($selectedBusinessUnit === '') {
            $save_status = 'error';
            $save_message = 'Please select or enter a business unit.';
        } else {
            $data = [];
            foreach ($fieldMap as $formField => $choices) {
                if (in_array($formField, ['vehicle_id', 'created_by', 'created_at'], true)) continue;
                $col = firstExisting($vehicle_columns, $choices);
                if (!$col) continue;
                if ($formField === 'vehicle_image') {
                    if ($vehicleImage !== '') $data[$col] = $vehicleImage;
                } elseif ($formField === 'cr_vehicle_images') {
                    if (!empty($crImages)) $data[$col] = json_encode($crImages);
                } elseif ($formField === 'or_attachment') {
                    if ($orAttachment !== '') $data[$col] = $orAttachment;
                } elseif ($formField === 'branch_id') {
                    $data[$col] = (string)$selectedBranchId;
                } elseif ($formField === 'business_unit') {
                    $data[$col] = $selectedBusinessUnit;
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

    $vehicleBranchId = 0;
    if ($vehicle_table_exists && in_array('branch_id', $vehicle_columns, true)) {
        $branchStmt = $conn->prepare("SELECT branch_id FROM `$vehicle_table` WHERE id = ? LIMIT 1");
        if ($branchStmt) {
            $branchStmt->bind_param('i', $vehicle_db_id);
            $branchStmt->execute();
            $branchResult = $branchStmt->get_result();
            if ($branchResult && ($branchRow = $branchResult->fetch_assoc())) $vehicleBranchId = (int)($branchRow['branch_id'] ?? 0);
            $branchStmt->close();
        }
    }

    $stmt = $conn->prepare("INSERT INTO motorpool_registration_history
        (vehicle_db_id, vehicle_id, plate_no, or_no, reg_date, next_renewal, or_attachment, branch_id, encoded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Failed to prepare registration renewal: ' . $conn->error]);
    }
    $stmt->bind_param('issssssii', $vehicle_db_id, $vehicle_id_value, $plate_no, $or_no_value, $reg_date_value, $next_renewal_value, $orAttachment, $vehicleBranchId, $user_id);
    if (!$stmt->execute()) {
        jsonResponse(['success' => false, 'message' => 'Failed to save registration renewal: ' . $stmt->error]);
    }
    $stmt->close();

    $updateData = [];
    $orCol = firstExisting($vehicle_columns, ['or_no']);
    $regCol = firstExisting($vehicle_columns, ['reg_date', 'registration_history_date']);
    $renewCol = firstExisting($vehicle_columns, ['next_renewal']);
    $attachCol = firstExisting($vehicle_columns, ['or_attachment']);
    if ($orCol) $updateData[$orCol] = $or_no_value;
    if ($regCol) $updateData[$regCol] = $reg_date_value;
    if ($renewCol) $updateData[$renewCol] = $next_renewal_value;
    if ($attachCol) $updateData[$attachCol] = $orAttachment;

    if (!empty($updateData)) {
        $setParts = [];
        foreach (array_keys($updateData) as $col) $setParts[] = "`$col` = ?";
        $types = str_repeat('s', count($updateData)) . 'i';
        $sql = "UPDATE `$vehicle_table` SET " . implode(', ', $setParts) . " WHERE id = ?";
        $updateStmt = $conn->prepare($sql);
        if ($updateStmt) {
            $values = array_values($updateData);
            $values[] = $vehicle_db_id;
            $updateStmt->bind_param($types, ...$values);
            $updateStmt->execute();
            $updateStmt->close();
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

function v(array $row, array $columns, array $choices): string {
    $col = firstExisting($columns, $choices);
    return $col && isset($row[$col]) ? (string)$row[$col] : '';
}

function fetchVehicles(mysqli $conn, string $table, bool $tableExists, array $columns): array {
    if (!$tableExists) return [];
    $orderCol = in_array('created_at', $columns, true) ? 'created_at' : (in_array('id', $columns, true) ? 'id' : $columns[0]);
    $sql = "SELECT * FROM `$table` ORDER BY `$orderCol` DESC";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function fetchVehicleRepairHistories(mysqli $conn, array $vehicles): array {
    $histories = [];
    $ids = [];
    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return $histories;

    $idList = implode(',', array_map('intval', $ids));
    $sql = "SELECT vehicle_db_id, ris_number, repair_date, repairs_done, parts_replaced, mechanic, start_date, end_date, attachment, repair_cost, created_at
            FROM vehicle_repair_history
            WHERE vehicle_db_id IN ($idList)
            ORDER BY COALESCE(repair_date, DATE(created_at)) DESC, repair_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $histories[(int)$row['vehicle_db_id']][] = $row;
        }
    }
    return $histories;
}


function fetchVehicleWorkflowHistories(mysqli $conn, array $vehicles): array {
    $histories = [];
    $ids = [];
    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids) || !tableExists($conn, 'motorpool_ris_workflow_history')) return $histories;

    $idList = implode(',', array_map('intval', $ids));
    $sql = "SELECT h.*, CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS processed_by_name
            FROM motorpool_ris_workflow_history h
            LEFT JOIN users u ON u.user_id = h.processed_by
            WHERE h.vehicle_db_id IN ($idList)
            ORDER BY h.processed_at ASC, h.history_id ASC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $histories[(int)$row['vehicle_db_id']][] = $row;
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
            $histories[(int)$row['vehicle_db_id']][] = $row;
        }
    }
    return $histories;
}

function motorpoolImageCell(string $filename, string $alt = 'Vehicle Image'): string {
    $filename = trim($filename);
    if ($filename === '') {
        return '<div class="item-thumbnail"><i class="bi bi-image text-muted"></i></div>';
    }
    $src = '../uploads/motorpool/' . h($filename);
    return '<div class="item-thumbnail"><img src="' . $src . '" alt="' . h($alt) . '" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<i class=&quot;bi bi-image text-muted&quot;></i>\';"></div>';
}

$vehicles = fetchVehicles($conn, $vehicle_table, $vehicle_table_exists, $vehicle_columns);
$vehicleRepairHistories = fetchVehicleRepairHistories($conn, $vehicles);
$vehicleWorkflowHistories = fetchVehicleWorkflowHistories($conn, $vehicles);
$vehicleRegistrationHistories = fetchVehicleRegistrationHistories($conn, $vehicles);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vehicle Profile - Motorpool</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<style>
.form-card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
.custom-table th{background:#052A47;color:#fff;white-space:nowrap}.custom-table td{vertical-align:middle}.btn-action-text{white-space:nowrap;border-radius:8px}.required-mark{color:#dc3545}.section-title{font-weight:700;color:#052A47;margin:18px 0 10px}.vehicle-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}.item-thumbnail{width:48px;height:48px;border-radius:8px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;overflow:hidden;margin:0 auto}.item-thumbnail img{width:100%;height:100%;object-fit:cover}.custom-table tbody tr.vehicle-click-row{cursor:pointer;transition:background-color .18s ease}.custom-table tbody tr.vehicle-click-row:hover td{background:#f4fbf6}.custom-table .col-image{width:78px;text-align:center}.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:998;opacity:0;transition:opacity .25s ease}.sidebar-overlay.active{opacity:1}.dropdown-arrow{margin-left:auto;transition:transform .2s ease}@media(max-width:992px){.sidebar{transform:translateX(-100%);transition:transform .25s ease;z-index:999}.sidebar.active,.sidebar.show{transform:translateX(0)}}
#vehicleModal .modal-dialog{max-width:1240px;margin-top:12px;margin-bottom:12px}#vehicleModal .modal-content{max-height:calc(100vh - 24px);display:flex;flex-direction:column;border:1px solid #dfe6ec;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.12)}#vehicleModal .modal-header{background:#07b83f;color:#fff;border-bottom:0;padding:14px 18px;flex-shrink:0}#vehicleModal .btn-close{filter:invert(1) grayscale(100%) brightness(200%);opacity:.9}#vehicleModal .modal-body{overflow-y:auto;max-height:calc(100vh - 155px);padding:16px;background:#f8fafc}#vehicleModal .modal-footer{flex-shrink:0;background:#fff;border-top:1px solid #dee2e6;padding:12px 18px}#vehicleModal .btn-success{background:#07b83f;border-color:#07b83f}#vehicleModal .btn-success:hover{background:#069d36;border-color:#069d36}.motorpool-form-panel{background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:14px 16px;margin-bottom:14px}.motorpool-panel-title{display:inline-flex;align-items:center;gap:4px;color:#1f2937;font-weight:600;padding-bottom:8px;margin-bottom:14px;border-bottom:2px solid #0d6efd}.motorpool-form-panel .form-label{font-size:.86rem;font-weight:600;color:#374151;margin-bottom:5px}.motorpool-form-panel .form-control,.motorpool-form-panel .form-select{min-height:38px;border:1px solid #d8e0ea;border-radius:9px;font-size:.9rem;background:#fff}.motorpool-form-panel .form-control:focus,.motorpool-form-panel .form-select:focus{border-color:#07b83f;box-shadow:0 0 0 .15rem rgba(7,184,63,.15)}
.vehicle-detail-hero{display:flex;gap:18px;align-items:center;padding:16px;background:#f8fafc;border:1px solid #e3e8ef;border-radius:12px;margin-bottom:16px}.vehicle-detail-image{width:120px;height:120px;border-radius:14px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}.vehicle-detail-image img{width:100%;height:100%;object-fit:cover}.vehicle-detail-title h4{margin:0 0 6px;font-weight:700;color:#1f2937}.vehicle-detail-tabs .nav-link{color:#495057;font-weight:600;border-radius:8px 8px 0 0}.vehicle-detail-tabs .nav-link.active{color:#07b83f;border-color:#dee2e6 #dee2e6 #fff}.detail-info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));column-gap:28px;row-gap:10px}.detail-info-item{display:grid;grid-template-columns:145px minmax(0,1fr);align-items:start;gap:8px;padding:6px 0;border-bottom:1px solid #eef2f6;background:transparent}.detail-info-item small{color:#6c757d;font-size:.82rem;line-height:1.25}.detail-info-item strong{color:#212529;font-weight:600;line-height:1.25;word-break:break-word}.vehicle-image-preview-wrap{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}.vehicle-image-preview{border:1px solid #e3e8ef;border-radius:12px;background:#fff;padding:10px}.vehicle-image-preview img{width:100%;height:130px;object-fit:cover;border-radius:8px;background:#f1f3f5}.vehicle-image-preview a{display:block;margin-top:7px;font-size:.85rem;color:#07b83f;font-weight:600;text-decoration:none}.history-table thead th{background:#07b83f!important;color:#fff!important;border-color:#07b83f!important;white-space:nowrap}.history-table td{border-color:#e3e8ef!important;vertical-align:middle}
#renewRegistrationModal .form-label{font-weight:600;color:#374151}#renewRegistrationModal .form-control:focus{border-color:#07b83f;box-shadow:0 0 0 .15rem rgba(7,184,63,.15)}
#motorpoolFilePreviewModal{padding:0!important}#motorpoolFilePreviewModal .modal-dialog{position:fixed;inset:0;margin:0!important;width:100vw;height:100vh;max-width:100vw!important;display:flex;align-items:center;justify-content:center}#motorpoolFilePreviewModal .modal-content{background:transparent!important;border:none!important;box-shadow:none!important;overflow:visible!important}#motorpoolFilePreviewModal .modal-body{padding:0!important;margin:0!important;overflow:visible!important;display:flex;align-items:center;justify-content:center}.attachment-wrapper{position:relative;display:inline-block;line-height:0}.attachment-content img{display:block;max-width:92vw;max-height:92vh;width:auto;height:auto;object-fit:contain;border-radius:10px}.attachment-content embed{display:block;width:92vw;height:92vh;border-radius:10px;background:#fff}.btn-close-attachment,.btn-download-attachment{position:absolute;right:10px;width:34px;height:34px;border-radius:50%;background:rgba(0,0,0,.7);color:#fff;z-index:9999;display:flex!important;align-items:center;justify-content:center;text-decoration:none;border:0}.btn-close-attachment{top:10px}.btn-download-attachment{bottom:10px}.btn-close-attachment:hover,.btn-download-attachment:hover{background:rgba(0,0,0,.9);color:#fff}.modal{overflow-y:hidden!important}body.modal-open{overflow:hidden!important;padding-right:0!important}
@media(max-width:768px){#vehicleModal .modal-dialog{margin:6px}.detail-info-grid{grid-template-columns:1fr}.detail-info-item{grid-template-columns:130px minmax(0,1fr)}.vehicle-detail-hero{align-items:flex-start;flex-direction:column}}

.repair-timeline{position:relative;padding:6px 0 6px 24px}.repair-timeline:before{content:'';position:absolute;left:8px;top:8px;bottom:8px;width:2px;background:#dbe7e0}.timeline-item{position:relative;margin-bottom:14px;padding:12px 14px;background:#fff;border:1px solid #e3e8ef;border-radius:12px}.timeline-item:before{content:'';position:absolute;left:-22px;top:16px;width:12px;height:12px;border-radius:50%;background:#07b83f;border:2px solid #fff;box-shadow:0 0 0 2px #07b83f}.timeline-status{font-weight:700;color:#052A47}.timeline-meta{font-size:.85rem;color:#64748b;margin-top:2px}.timeline-details{margin-top:8px;white-space:pre-wrap}.timeline-empty{padding:28px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc}.repair-history-click-row{cursor:pointer}.repair-history-click-row:hover td{background:#f4fbf6!important}

.main-content, .form-card, .modal, .modal * { pointer-events: auto; }

.parts-replaced-mini-table-wrap {
    min-width: 420px;
}
.parts-replaced-mini-table th {
    background: #eaf8ef !important;
    color: #212529 !important;
    font-size: .78rem;
    white-space: nowrap;
}
.parts-replaced-mini-table td {
    font-size: .82rem;
    white-space: normal;
    vertical-align: top;
}
.repair-history-click-row {
    cursor: pointer;
}
.repair-history-click-row:hover td {
    background: #f4fbf6;
}

</style>
</head>
<body>
<div id="appPage">
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>
                <button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list" id="toggleIcon"></i></button>
                <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon">
                <span class="nav-text">Motorpool</span>
            </h3>
        </div>
        <div class="sidebar-content">
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="request_handler.php"><i class="bi bi-clipboard-check"></i><span class="nav-text">RIS Requests</span></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="motorpool.php"><i class="bi bi-truck"></i><span class="nav-text">Vehicle Profile</span></a>
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
            <button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button>
        </div>
    </div>

    <main class="main-content" id="mainContent">
        <div id="dashboardContent" class="page-content active">
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
                <div class="page-title">
                    <h2>Vehicle Profile</h2>
                    <p id="dashboardSubtitle">Registered vehicles across all branches and business units</p>
                </div>
            </div>

            <div class="form-card">
                <div class="vehicle-toolbar">
                    <div>
                        <h5 class="mb-1">Registered Vehicles</h5>
                        <small class="text-muted">All motorpool vehicles across branches are listed here.</small>
                    </div>
                    <button type="button" class="btn btn-success btn-action-text" onclick="openVehicleModal(); return false;" data-mp-action="open-vehicle-modal">
                        <i class="bi bi-plus-circle me-1"></i>Add Vehicle
                    </button>
                </div>

                <?php if (!$vehicle_table_exists): ?>
                    <div class="alert alert-warning mb-3">The <strong>motorpool_vehicles</strong> table could not be created. Please check your database permissions.</div>
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
                                <th>Branch</th>
                                <th>Business Unit</th>
                                <th>Year Model</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($vehicles)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No registered vehicles found.</td></tr>
                        <?php else: foreach ($vehicles as $vehicle):
                            $vehicleDbId = (int)($vehicle['id'] ?? 0);
                            $vehicleId = v($vehicle, $vehicle_columns, ['vehicle_id', 'vehicle_code', 'vehicle_no', 'id']);
                            $plateNo = v($vehicle, $vehicle_columns, ['plate_no', 'plate_number']);
                            $makeBrand = v($vehicle, $vehicle_columns, ['make_brand', 'make', 'brand']);
                            $vehicleType = v($vehicle, $vehicle_columns, ['vehicle_type', 'type']);
                            $vehicleCategory = v($vehicle, $vehicle_columns, ['vehicle_category', 'category']);
                            $yearModel = v($vehicle, $vehicle_columns, ['year_model']);
                            $vehicleImage = v($vehicle, $vehicle_columns, ['vehicle_image']);
                            $rowBranchId = (int)v($vehicle, $vehicle_columns, ['branch_id']);
                            $branchName = $branchNames[$rowBranchId] ?? ($rowBranchId > 0 ? 'Branch #' . $rowBranchId : 'Not assigned');
                            $businessUnit = v($vehicle, $vehicle_columns, ['business_unit', 'business_unit_name']);
                            if ($businessUnit === '' && $rowBranchId > 0) $businessUnit = $branchBusinessUnits[$rowBranchId] ?? '';
                            $dataAttrs = ' data-db-id="' . h($vehicleDbId) . '"';
                            foreach ($fieldMap as $formField => $choices) {
                                $dataAttrs .= ' data-' . h(str_replace('_', '-', $formField)) . '="' . h(v($vehicle, $vehicle_columns, $choices)) . '"';
                            }
                            $dataAttrs .= ' data-branch-name="' . h($branchName) . '"';
                            $dataAttrs .= ' data-business-unit-display="' . h($businessUnit) . '"';
                            $dataAttrs .= ' data-repair-history="' . h(json_encode($vehicleRepairHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
                            $dataAttrs .= ' data-workflow-history="' . h(json_encode($vehicleWorkflowHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
                            $dataAttrs .= ' data-registration-history="' . h(json_encode($vehicleRegistrationHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
                        ?>
                            <tr class="vehicle-click-row js-view-vehicle" data-mp-action="view-vehicle-details" onclick="viewVehicleDetails(this)"<?php echo $dataAttrs; ?>>
                                <td class="col-image"><?php echo motorpoolImageCell($vehicleImage, $plateNo); ?></td>
                                <td><strong><?php echo h($plateNo); ?></strong><br><small class="text-muted">Vehicle ID: <?php echo h($vehicleId); ?></small></td>
                                <td><?php echo h($makeBrand); ?></td>
                                <td><?php echo h($vehicleType); ?></td>
                                <td><?php echo h($vehicleCategory); ?></td>
                                <td><?php echo h($branchName); ?></td>
                                <td><?php echo h($businessUnit !== '' ? $businessUnit : 'N/A'); ?></td>
                                <td><?php echo h($yearModel); ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-outline-success btn-sm btn-action-text" onclick="event.stopPropagation(); editVehicleFromRow(this.closest('tr'))" data-mp-action="edit-vehicle-row"><i class="bi bi-pencil-square me-1"></i>Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="vehicleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
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
                        <div class="motorpool-panel-title"><i class="bi bi-building me-2"></i>Assignment Details</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Branch <span class="required-mark">*</span></label>
                                <select class="form-select" name="branch_id" id="branch_id" required onchange="syncBusinessUnitFromBranch()">
                                    <option value="">Select branch</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo h($branch['branch_id']); ?>" data-business-unit="<?php echo h($branch['business_unit'] ?? ''); ?>"><?php echo h($branch['branch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Business Unit <span class="required-mark">*</span></label>
                                <input class="form-control" name="business_unit" id="business_unit" list="businessUnitOptions" required placeholder="Business unit">
                                <datalist id="businessUnitOptions">
                                    <?php foreach ($businessUnits as $unit): ?>
                                        <option value="<?php echo h($unit); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                    </div>

                    <div class="motorpool-form-panel">
                        <div class="motorpool-panel-title"><i class="bi bi-info-circle me-2"></i>Vehicle Information</div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">Plate No. <span class="required-mark">*</span></label><input class="form-control" name="plate_no" id="plate_no" required></div>
                            <div class="col-md-4"><label class="form-label">Make/Brand</label><input class="form-control" name="make_brand" id="make_brand"></div>
                            <div class="col-md-4"><label class="form-label">Vehicle Type</label><input class="form-control" name="vehicle_type" id="vehicle_type"></div>
                            <div class="col-md-4"><label class="form-label">Vehicle Category</label><input class="form-control" name="vehicle_category" id="vehicle_category"></div>
                            <div class="col-md-4"><label class="form-label">Classification</label><input class="form-control" name="classification" id="classification"></div>
                            <div class="col-md-4"><label class="form-label">Body Type</label><input class="form-control" name="body_type" id="body_type"></div>
                            <div class="col-md-4"><label class="form-label">Color</label><input class="form-control" name="color" id="color"></div>
                            <div class="col-md-4"><label class="form-label">Fuel Type</label><input class="form-control" name="type_of_fuel" id="type_of_fuel"></div>
                            <div class="col-md-4"><label class="form-label">Year Model</label><input class="form-control" name="year_model" id="year_model"></div>
                            <div class="col-md-4"><label class="form-label">Series</label><input class="form-control" name="series" id="series"></div>
                            <div class="col-md-4"><label class="form-label">Passenger Capacity</label><input class="form-control" name="passenger_capacity" id="passenger_capacity"></div>
                            <div class="col-md-4"><label class="form-label">Max Power KW</label><input class="form-control" name="max_power_kw" id="max_power_kw"></div>
                        </div>
                    </div>

                    <div class="motorpool-form-panel">
                        <div class="motorpool-panel-title"><i class="bi bi-card-checklist me-2"></i>Registration and Technical Details</div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">LTO CR No.</label><input class="form-control" name="lto_cr_no" id="lto_cr_no"></div>
                            <div class="col-md-4"><label class="form-label">Date Registration</label><input type="date" class="form-control" name="date_registration" id="date_registration"></div>
                            <div class="col-md-4"><label class="form-label">File No.</label><input class="form-control" name="file_no" id="file_no"></div>
                            <div class="col-md-4"><label class="form-label">Engine No.</label><input class="form-control" name="engine_no" id="engine_no"></div>
                            <div class="col-md-4"><label class="form-label">Chassis No.</label><input class="form-control" name="chassis_no" id="chassis_no"></div>
                            <div class="col-md-4"><label class="form-label">VIN</label><input class="form-control" name="vin" id="vin"></div>
                            <div class="col-md-4"><label class="form-label">Gross Weight</label><input class="form-control" name="gross_weight" id="gross_weight"></div>
                            <div class="col-md-4"><label class="form-label">Net Weight</label><input class="form-control" name="net_weight" id="net_weight"></div>
                            <div class="col-md-4"><label class="form-label">Year Rebuilt</label><input class="form-control" name="year_rebuilt" id="year_rebuilt"></div>
                            <div class="col-md-4"><label class="form-label">Piston Displacement</label><input class="form-control" name="piston_displacement" id="piston_displacement"></div>
                        </div>
                    </div>

                    <div class="motorpool-form-panel">
                        <div class="motorpool-panel-title"><i class="bi bi-calendar-check me-2"></i>Current Registration</div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">OR No.</label><input class="form-control" name="or_no" id="or_no"></div>
                            <div class="col-md-4"><label class="form-label">Registration Date</label><input type="date" class="form-control" name="reg_date" id="reg_date"></div>
                            <div class="col-md-4"><label class="form-label">Next Renewal</label><input type="date" class="form-control" name="next_renewal" id="next_renewal"></div>
                            <div class="col-md-6"><label class="form-label">Vehicle Image</label><input type="file" class="form-control" name="vehicle_image" id="vehicle_image" accept=".jpg,.jpeg,.png,.webp,.gif"></div>
                            <div class="col-md-6"><label class="form-label">CR / Vehicle Images</label><input type="file" class="form-control" name="cr_vehicle_images[]" id="cr_vehicle_images" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" multiple></div>
                            <div class="col-md-6"><label class="form-label">OR Attachment</label><input type="file" class="form-control" name="or_attachment" id="or_attachment" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-action-text" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success btn-action-text"><i class="bi bi-save me-1"></i>Save Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="vehicleDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-truck-front me-2"></i>Vehicle Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="vehicle-detail-hero">
                    <div class="vehicle-detail-image" id="detailVehicleImage"><i class="bi bi-image text-muted fs-1"></i></div>
                    <div class="vehicle-detail-title">
                        <h4 id="detailPlateTitle"></h4>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge bg-success" id="detailVehicleIdBadge"></span>
                            <span class="badge bg-primary" id="detailBranchBadge"></span>
                            <span class="badge bg-secondary" id="detailBusinessUnitBadge"></span>
                        </div>
                    </div>
                </div>
                <ul class="nav nav-tabs vehicle-detail-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#vehicleInfoTab" type="button">Vehicle Information</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleRegistrationTab" type="button">Registration</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleAttachmentsTab" type="button">Attachments</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleRepairTab" type="button">Repair History</button></li>
                </ul>
                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="vehicleInfoTab"><div class="detail-info-grid" id="vehicleInfoGrid"></div></div>
                    <div class="tab-pane fade" id="vehicleRegistrationTab">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Registration History</h6>
                            <button type="button" class="btn btn-success btn-sm btn-action-text" onclick="openRenewRegistrationModal()" data-mp-action="open-renew-registration"><i class="bi bi-calendar-plus me-1"></i>Renew Registration</button>
                        </div>
                        <div class="table-responsive"><table class="table history-table align-middle"><thead><tr><th>OR No.</th><th>Registration Date</th><th>Next Renewal</th><th>Attachment</th><th>Encoded At</th></tr></thead><tbody id="registrationHistoryBody"></tbody></table></div>
                    </div>
                    <div class="tab-pane fade" id="vehicleAttachmentsTab"><div class="vehicle-image-preview-wrap" id="vehicleAttachmentsWrap"></div></div>
                    <div class="tab-pane fade" id="vehicleRepairTab">
                        <div class="table-responsive"><table class="table history-table align-middle"><thead><tr><th>Date</th><th>RIS No.</th><th>Repairs Done</th><th>Parts Replaced</th><th>Mechanic</th><th>Start Date</th><th>End Date</th><th>Attachment</th></tr></thead><tbody id="repairHistoryBody"></tbody></table></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-action-text" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success btn-action-text" onclick="editCurrentVehicle()" data-mp-action="edit-current-vehicle"><i class="bi bi-pencil-square me-1"></i>Edit Vehicle</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="repairWorkflowModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Detailed Repair Workflow</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="fw-bold" id="repairWorkflowTitle">Repair Workflow</div>
                    <small class="text-muted" id="repairWorkflowSubtitle"></small>
                </div>
                <div class="repair-timeline" id="repairWorkflowTimelineBody"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-action-text" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="renewRegistrationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="renewRegistrationForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="renew_registration">
                <input type="hidden" name="vehicle_db_id" id="renew_vehicle_db_id">
                <input type="hidden" name="vehicle_id" id="renew_vehicle_id">
                <input type="hidden" name="plate_no" id="renew_plate_no">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Renew Registration</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">OR No. <span class="required-mark">*</span></label><input class="form-control" name="or_no" required></div>
                    <div class="mb-3"><label class="form-label">Registration Date <span class="required-mark">*</span></label><input type="date" class="form-control" name="reg_date" required></div>
                    <div class="mb-3"><label class="form-label">Next Renewal <span class="required-mark">*</span></label><input type="date" class="form-control" name="next_renewal" required></div>
                    <div class="mb-3"><label class="form-label">OR Attachment <span class="required-mark">*</span></label><input type="file" class="form-control" name="or_attachment" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" required></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-action-text" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success btn-action-text"><i class="bi bi-save me-1"></i>Save Renewal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="motorpoolFilePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-body"><div class="attachment-wrapper"><button type="button" class="btn-close-attachment" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button><a class="btn-download-attachment" id="previewDownloadLink" href="#" download><i class="bi bi-download"></i></a><div class="attachment-content" id="previewContent"></div></div></div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const branchBusinessUnits = <?php $branchBusinessUnitsJson = json_encode($branchBusinessUnits, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); echo $branchBusinessUnitsJson ?: '{}'; ?>;
let currentVehicleRow = null;
let currentVehicleData = {};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, match => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[match]));
}

function getRowData(row) {
    const data = {};
    if (!row) return data;
    Array.from(row.attributes).forEach(attr => {
        if (attr.name.startsWith('data-')) {
            const key = attr.name.substring(5).replace(/-/g, '_');
            data[key] = attr.value;
        }
    });
    return data;
}

function syncBusinessUnitFromBranch() {
    const branchSelect = document.getElementById('branch_id');
    const businessInput = document.getElementById('business_unit');
    const selected = branchSelect.options[branchSelect.selectedIndex];
    const unit = selected ? (selected.getAttribute('data-business-unit') || '') : '';
    if (unit !== '') businessInput.value = unit;
}

function openVehicleModal() {
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicleFormAction').value = 'add_vehicle';
    document.getElementById('vehicle_db_id').value = '';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-truck-front me-2"></i>Add Vehicle Profile';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleModal')).show();
}

function fillVehicleForm(data) {
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicleFormAction').value = 'edit_vehicle';
    document.getElementById('vehicle_db_id').value = data.db_id || '';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Vehicle Profile';

    const fields = ['branch_id','business_unit','plate_no','make_brand','vehicle_type','vehicle_category','classification','body_type','color','type_of_fuel','year_model','series','passenger_capacity','max_power_kw','lto_cr_no','date_registration','file_no','engine_no','chassis_no','vin','gross_weight','net_weight','year_rebuilt','piston_displacement','or_no','reg_date','next_renewal'];
    fields.forEach(field => {
        const el = document.getElementById(field);
        if (el) el.value = data[field] || '';
    });
    if (document.getElementById('business_unit') && !document.getElementById('business_unit').value) {
        document.getElementById('business_unit').value = data.business_unit_display || '';
    }
}

function editVehicleFromRow(row) {
    currentVehicleRow = row;
    currentVehicleData = getRowData(row);
    fillVehicleForm(currentVehicleData);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleModal')).show();
}

function editCurrentVehicle() {
    if (!currentVehicleData || !currentVehicleData.db_id) return;
    fillVehicleForm(currentVehicleData);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleDetailsModal')).hide();
    setTimeout(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleModal')).show(), 250);
}

function parseJson(value, fallback) {
    try { return JSON.parse(value || ''); } catch (e) { return fallback; }
}

function detailItem(label, value) {
    return '<div class="detail-info-item"><small>' + escapeHtml(label) + '</small><strong>' + escapeHtml(value || 'N/A') + '</strong></div>';
}

function viewVehicleDetails(row) {
    currentVehicleRow = row;
    currentVehicleData = getRowData(row);
    const d = currentVehicleData;
    const image = d.vehicle_image ? '../uploads/motorpool/' + encodeURIComponent(d.vehicle_image) : '';

    document.getElementById('detailPlateTitle').textContent = d.plate_no || 'Vehicle Details';
    document.getElementById('detailVehicleIdBadge').textContent = 'Vehicle ID: ' + (d.vehicle_id || d.db_id || 'N/A');
    document.getElementById('detailBranchBadge').textContent = d.branch_name || 'Branch: N/A';
    document.getElementById('detailBusinessUnitBadge').textContent = d.business_unit_display || d.business_unit || 'Business Unit: N/A';
    document.getElementById('detailVehicleImage').innerHTML = image ? '<img src="' + image + '" alt="Vehicle Image">' : '<i class="bi bi-image text-muted fs-1"></i>';

    const info = [
        ['Branch', d.branch_name], ['Business Unit', d.business_unit_display || d.business_unit], ['Plate No.', d.plate_no],
        ['Make/Brand', d.make_brand], ['Vehicle Type', d.vehicle_type], ['Vehicle Category', d.vehicle_category],
        ['Classification', d.classification], ['Body Type', d.body_type], ['Color', d.color], ['Fuel Type', d.type_of_fuel],
        ['Year Model', d.year_model], ['Series', d.series], ['Passenger Capacity', d.passenger_capacity], ['Max Power KW', d.max_power_kw],
        ['LTO CR No.', d.lto_cr_no], ['Date Registration', d.date_registration], ['File No.', d.file_no],
        ['Engine No.', d.engine_no], ['Chassis No.', d.chassis_no], ['VIN', d.vin],
        ['Gross Weight', d.gross_weight], ['Net Weight', d.net_weight], ['Year Rebuilt', d.year_rebuilt], ['Piston Displacement', d.piston_displacement]
    ];
    document.getElementById('vehicleInfoGrid').innerHTML = info.map(item => detailItem(item[0], item[1])).join('');

    renderRegistrationHistory(d);
    renderAttachments(d);
    renderRepairHistory(d);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleDetailsModal')).show();
}

function renderRegistrationHistory(d) {
    const body = document.getElementById('registrationHistoryBody');
    const histories = parseJson(d.registration_history, []);
    const rows = [];
    if (d.or_no || d.reg_date || d.next_renewal || d.or_attachment) {
        rows.push({or_no:d.or_no, reg_date:d.reg_date, next_renewal:d.next_renewal, or_attachment:d.or_attachment, created_at:'Current'});
    }
    histories.forEach(item => rows.push(item));
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No registration history found.</td></tr>';
        return;
    }
    body.innerHTML = rows.map(item => {
        const attachment = item.or_attachment ? '<button type="button" class="btn btn-outline-success btn-sm" data-preview-file="' + escapeHtml(item.or_attachment) + '">View</button>' : 'N/A';
        return '<tr><td>' + escapeHtml(item.or_no || '') + '</td><td>' + escapeHtml(item.reg_date || '') + '</td><td>' + escapeHtml(item.next_renewal || '') + '</td><td>' + attachment + '</td><td>' + escapeHtml(item.created_at || '') + '</td></tr>';
    }).join('');
}

function renderAttachments(d) {
    const wrap = document.getElementById('vehicleAttachmentsWrap');
    const attachments = [];
    if (d.vehicle_image) attachments.push({label:'Vehicle Image', file:d.vehicle_image});
    if (d.or_attachment) attachments.push({label:'OR Attachment', file:d.or_attachment});
    const crFiles = parseJson(d.cr_vehicle_images, []);
    if (Array.isArray(crFiles)) crFiles.forEach((file, i) => attachments.push({label:'CR / Vehicle Image ' + (i + 1), file:file}));
    if (!attachments.length) {
        wrap.innerHTML = '<div class="text-muted">No attachments uploaded.</div>';
        return;
    }
    wrap.innerHTML = attachments.map(item => {
        const ext = String(item.file).split('.').pop().toLowerCase();
        const src = buildMotorpoolUploadUrl(item.file);
        const preview = ['jpg','jpeg','png','webp','gif'].includes(ext) ? '<img src="' + src + '" alt="' + escapeHtml(item.label) + '">' : '<div class="text-center py-4"><i class="bi bi-file-earmark-pdf fs-1 text-danger"></i></div>';
        return '<div class="vehicle-image-preview">' + preview + '<a href="javascript:void(0)" data-preview-file="' + escapeHtml(item.file) + '">' + escapeHtml(item.label) + '</a></div>';
    }).join('');
}


function parsePartsReplacedRowsV21(value) {
    const rows = [];
    String(value || '').split(/\n+/).forEach(function (line) {
        const current = { quantity: '', item: '', description: '', specification: '' };
        line.split('|').forEach(function (segment) {
            const pair = segment.split(':');
            const key = String(pair.shift() || '').trim().toLowerCase();
            const val = pair.join(':').trim();
            if (key === 'quantity' || key === 'qty') current.quantity = val;
            if (key === 'item' || key === 'item no.' || key === 'item no') current.item = val;
            if (key === 'description') current.description = val;
            if (key === 'specification' || key === 'specs') current.specification = val;
        });
        if (current.quantity || current.item || current.description || current.specification) rows.push(current);
    });
    return rows;
}

function renderPartsReplacedColumnsV21(value) {
    const rows = parsePartsReplacedRowsV21(value);
    if (!rows.length) return escapeHtml(value || '');

    return '<div class="table-responsive parts-replaced-mini-table-wrap">'
        + '<table class="table table-bordered table-sm align-middle mb-0 parts-replaced-mini-table">'
        + '<thead><tr>'
        + '<th>Quantity</th>'
        + '<th>Item</th>'
        + '<th>Description</th>'
        + '<th>Specification</th>'
        + '</tr></thead><tbody>'
        + rows.map(function (part) {
            return '<tr>'
                + '<td>' + escapeHtml(part.quantity || '') + '</td>'
                + '<td>' + escapeHtml(part.item || '') + '</td>'
                + '<td>' + escapeHtml(part.description || '') + '</td>'
                + '<td>' + escapeHtml(part.specification || '') + '</td>'
                + '</tr>';
        }).join('')
        + '</tbody></table></div>';
}

function partsReplacedTextForTimelineV21(value) {
    const rows = parsePartsReplacedRowsV21(value);
    if (!rows.length) return value || '';
    return rows.map(function (part, index) {
        return 'Part ' + (index + 1) + ': Quantity: ' + (part.quantity || 'N/A')
            + ' | Item: ' + (part.item || 'N/A')
            + ' | Description: ' + (part.description || 'N/A')
            + ' | Specification: ' + (part.specification || 'N/A');
    }).join('\n');
}

function renderRepairHistory(d) {
    const body = document.getElementById('repairHistoryBody');
    const histories = parseJson(d.repair_history, []);
    if (!histories.length) {
        body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No repair history found.</td></tr>';
        return;
    }
    body.innerHTML = histories.map(item => {
        const attachment = item.attachment ? '<button type="button" class="btn btn-outline-success btn-sm" data-preview-file="' + escapeHtml(item.attachment) + '">View</button>' : 'N/A';
        const risNumber = escapeHtml(item.ris_number || '');
        return '<tr class="repair-history-click-row" data-repair-workflow-ris="' + risNumber + '" title="Click to view detailed repair workflow">'
            + '<td>' + escapeHtml(item.repair_date || '') + '</td>'
            + '<td>' + escapeHtml(item.ris_number || '') + '</td>'
            + '<td>' + escapeHtml(item.repairs_done || '') + '</td>'
            + '<td>' + renderPartsReplacedColumnsV21(item.parts_replaced || '') + '</td>'
            + '<td>' + escapeHtml(item.mechanic || '') + '</td>'
            + '<td>' + escapeHtml(item.start_date || '') + '</td>'
            + '<td>' + escapeHtml(item.end_date || '') + '</td>'
            + '<td>' + attachment + '</td>'
            + '</tr>';
    }).join('');
}


function renderTimelineAttachmentButtons(attachment) {
    if (!attachment) return '';
    const raw = String(attachment).trim();
    if (!raw) return '';

    if (raw.startsWith('[') || raw.startsWith('{')) {
        const parsed = parseJson(raw, null);
        const list = Array.isArray(parsed) ? parsed : (parsed ? [parsed] : []);
        const links = list.map((p, index) => {
            const file = p.filename || p.proof_photo || p.release_attachment || p.attachment || p.file || '';
            return file ? '<button type="button" class="btn btn-outline-success btn-sm me-1 mt-2" data-preview-file="' + escapeHtml(file) + '">Attachment ' + (index + 1) + '</button>' : '';
        }).join('');
        return links ? '<div>' + links + '</div>' : '';
    }

    return '<div><button type="button" class="btn btn-outline-success btn-sm mt-2" data-preview-file="' + escapeHtml(raw) + '">View Attachment</button></div>';
}

function buildFallbackTimelineFromRepairHistory(d) {
    const repairHistories = parseJson(d.repair_history, []);
    if (!Array.isArray(repairHistories) || !repairHistories.length) return [];

    return repairHistories.map(item => {
        const details = [];
        if (item.repairs_done) details.push('Repairs Done: ' + item.repairs_done);
        if (item.parts_replaced) details.push('Parts Replaced:\n' + partsReplacedTextForTimelineV21(item.parts_replaced));
        if (item.mechanic) details.push('Mechanic: ' + item.mechanic);
        if (item.start_date || item.end_date) details.push('Repair Period: ' + (item.start_date || 'N/A') + ' to ' + (item.end_date || 'N/A'));

        return {
            workflow_status: 'For Release',
            processed_at: item.created_at || item.repair_date || '',
            processed_by_name: item.mechanic || 'Motorpool',
            ris_number: item.ris_number || '',
            details: details.join('\n'),
            attachment: item.attachment || ''
        };
    });
}

function normalizeWorkflowStatus(status) {
    const value = String(status || '').trim().toLowerCase().replace(/[\s\-]+/g, ' ');
    if (value.includes('endorsement')) return 'For Vehicle Endorsement';
    if (value.includes('assessment')) return 'For Assessment';
    if (value.includes('approval')) return 'For Approval';
    if (value.includes('parts completion')) return 'For Parts Completion';
    if (value === 'for repair' || value.includes('for repair')) return 'For Repair';
    if (value.includes('ongoing repair') || value.includes('on going repair') || value.includes('on-going repair')) return 'On-going Repair';
    if (value.includes('release') || value.includes('completed repair')) return 'For Release';
    return status || '';
}

function renderWorkflowTimeline(d) {
    const body = document.getElementById('workflowTimelineBody');
    if (!body) return;

    const workflowStages = [
        'For Vehicle Endorsement',
        'For Assessment',
        'For Approval',
        'For Parts Completion',
        'For Repair',
        'On-going Repair',
        'For Release'
    ];

    let histories = parseJson(d.workflow_history, []);
    if (!Array.isArray(histories)) histories = [];

    if (!histories.length) {
        histories = buildFallbackTimelineFromRepairHistory(d);
    }

    const grouped = {};
    histories.forEach(item => {
        const key = normalizeWorkflowStatus(item.workflow_status || item.status || '');
        if (!key) return;
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(item);
    });

    body.innerHTML = workflowStages.map(stage => {
        const rows = grouped[stage] || [];
        const isDone = rows.length > 0;
        const cards = rows.length ? rows.map(item => {
            const attachmentHtml = renderTimelineAttachmentButtons(item.attachment || '');
            const processedBy = (item.processed_by_name || '').trim() || (item.processed_by ? 'User #' + item.processed_by : 'System');
            const rawDetails = String(item.details || '').replace(/\n/g, '<br>');
            const details = rawDetails || 'No additional details recorded.';
            const risNo = item.ris_number ? ' • RIS No.: ' + escapeHtml(item.ris_number) : '';

            return '<div class="timeline-subrecord">'
                + '<div class="timeline-meta">' + escapeHtml(item.processed_at || '') + ' • Processed by: ' + escapeHtml(processedBy) + risNo + '</div>'
                + '<div class="timeline-details">' + details + '</div>'
                + attachmentHtml
                + '</div>';
        }).join('') : '<div class="timeline-meta text-muted">No record yet for this step.</div>';

        return '<div class="timeline-item ' + (isDone ? 'timeline-done' : 'timeline-pending') + '">'
            + '<div class="timeline-status">' + escapeHtml(stage) + '</div>'
            + cards
            + '</div>';
    }).join('');
}

function getWorkflowRowsForRis(d, risNumber) {
    let histories = parseJson(d.workflow_history, []);
    if (!Array.isArray(histories)) histories = [];

    if (!histories.length) {
        histories = buildFallbackTimelineFromRepairHistory(d);
    }

    const wantedRis = String(risNumber || '').trim();
    if (!wantedRis) return histories;

    const filtered = histories.filter(item => String(item.ris_number || '').trim() === wantedRis);
    return filtered.length ? filtered : histories;
}

function renderWorkflowTimelineToElement(targetId, d, risNumber) {
    const body = document.getElementById(targetId);
    if (!body) return;

    const workflowStages = [
        'For Vehicle Endorsement',
        'For Assessment',
        'For Approval',
        'For Parts Completion',
        'For Repair',
        'On-going Repair',
        'For Release'
    ];

    const histories = getWorkflowRowsForRis(d, risNumber);
    const grouped = {};

    histories.forEach(item => {
        const key = normalizeWorkflowStatus(item.workflow_status || item.status || '');
        if (!key) return;
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(item);
    });

    body.innerHTML = workflowStages.map(stage => {
        const rows = grouped[stage] || [];
        const isDone = rows.length > 0;
        const cards = rows.length ? rows.map(item => {
            const attachmentHtml = renderTimelineAttachmentButtons(item.attachment || '');
            const processedBy = (item.processed_by_name || '').trim() || (item.processed_by ? 'User #' + item.processed_by : 'System');
            const details = String(item.details || '').replace(/\n/g, '<br>') || 'No additional details recorded.';
            const risNo = item.ris_number ? ' • RIS No.: ' + escapeHtml(item.ris_number) : '';

            return '<div class="timeline-subrecord">'
                + '<div class="timeline-meta">' + escapeHtml(item.processed_at || '') + ' • Processed by: ' + escapeHtml(processedBy) + risNo + '</div>'
                + '<div class="timeline-details">' + details + '</div>'
                + attachmentHtml
                + '</div>';
        }).join('') : '<div class="timeline-meta text-muted">No record yet for this step.</div>';

        return '<div class="timeline-item ' + (isDone ? 'timeline-done' : 'timeline-pending') + '">'
            + '<div class="timeline-status">' + escapeHtml(stage) + '</div>'
            + cards
            + '</div>';
    }).join('');
}

function openRepairWorkflowModal(risNumber) {
    if (!currentVehicleData) return;
    const d = currentVehicleData;
    const title = document.getElementById('repairWorkflowTitle');
    const subtitle = document.getElementById('repairWorkflowSubtitle');

    if (title) title.textContent = risNumber ? 'Detailed Repair Workflow - ' + risNumber : 'Detailed Repair Workflow';
    if (subtitle) subtitle.textContent = (d.plate_no ? 'Plate No.: ' + d.plate_no : '') + (d.branch_name ? ' • Branch: ' + d.branch_name : '');

    renderWorkflowTimelineToElement('repairWorkflowTimelineBody', d, risNumber);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('repairWorkflowModal')).show();
}

function openRenewRegistrationModal() {
    if (!currentVehicleData || !currentVehicleData.db_id) return;
    document.getElementById('renewRegistrationForm').reset();
    document.getElementById('renew_vehicle_db_id').value = currentVehicleData.db_id || '';
    document.getElementById('renew_vehicle_id').value = currentVehicleData.vehicle_id || '';
    document.getElementById('renew_plate_no').value = currentVehicleData.plate_no || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('renewRegistrationModal')).show();
}

function buildMotorpoolUploadUrl(filename) {
    return '../uploads/motorpool/' + String(filename || '').split('/').map(encodeURIComponent).join('/');
}

function previewMotorpoolFile(filename) {
    const ext = String(filename).split('.').pop().toLowerCase();
    const src = buildMotorpoolUploadUrl(filename);
    document.getElementById('previewDownloadLink').href = src;
    document.getElementById('previewDownloadLink').setAttribute('download', filename);
    if (['jpg','jpeg','png','webp','gif'].includes(ext)) {
        document.getElementById('previewContent').innerHTML = '<img src="' + src + '" alt="Preview">';
    } else if (ext === 'pdf') {
        document.getElementById('previewContent').innerHTML = '<embed src="' + src + '" type="application/pdf">';
    } else {
        document.getElementById('previewContent').innerHTML = '<div class="alert alert-light">Preview is not available for this file type.</div>';
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('motorpoolFilePreviewModal')).show();
}

function toggleSidebarDropdown(event, menuId) {
    event.preventDefault();
    const target = document.getElementById(menuId);
    if (!target) return;
    bootstrap.Collapse.getOrCreateInstance(target, {toggle:false}).toggle();
}

function logout() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({title:'Logout?',text:'Are you sure you want to logout?',icon:'question',showCancelButton:true,confirmButtonColor:'#07b83f',cancelButtonColor:'#6c757d',confirmButtonText:'Yes, logout'}).then(result => { if (result.isConfirmed) window.location.href = '../logout.php'; });
    } else {
        if (confirm('Are you sure you want to logout?')) window.location.href = '../logout.php';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (event) {
        const previewBtn = event.target.closest('[data-preview-file]');
        if (previewBtn) {
            event.preventDefault();
            event.stopPropagation();
            previewMotorpoolFile(previewBtn.getAttribute('data-preview-file'));
            return;
        }

        const repairRow = event.target.closest('[data-repair-workflow-ris]');
        if (repairRow) {
            event.preventDefault();
            openRepairWorkflowModal(repairRow.getAttribute('data-repair-workflow-ris'));
            return;
        }

        const actionEl = event.target.closest('[data-mp-action]');
        if (!actionEl) return;

        const action = actionEl.getAttribute('data-mp-action');
        if (action === 'open-vehicle-modal') {
            event.preventDefault();
            openVehicleModal();
            return;
        }
        if (action === 'view-vehicle-details') {
            event.preventDefault();
            viewVehicleDetails(actionEl.closest('tr'));
            return;
        }
        if (action === 'edit-vehicle-row') {
            event.preventDefault();
            event.stopPropagation();
            editVehicleFromRow(actionEl.closest('tr'));
            return;
        }
        if (action === 'open-renew-registration') {
            event.preventDefault();
            openRenewRegistrationModal();
            return;
        }
        if (action === 'edit-current-vehicle') {
            event.preventDefault();
            editCurrentVehicle();
            return;
        }
    }, true);

    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');

    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });
    }

    if (mobileToggleBtn) {
        mobileToggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('active');
        });
    }

    document.addEventListener('click', function (event) {
        if (window.innerWidth <= 992 && sidebar && mobileToggleBtn) {
            if (!sidebar.contains(event.target) && !mobileToggleBtn.contains(event.target)) sidebar.classList.remove('active');
        }
    });

    const renewForm = document.getElementById('renewRegistrationForm');
    if (renewForm) {
        renewForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(renewForm);
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    Swal.fire({icon: data.success ? 'success' : 'error', title: data.success ? 'Saved' : 'Error', text: data.message || '', confirmButtonColor: data.success ? '#07b83f' : '#dc3545'}).then(() => {
                        if (data.success) window.location.reload();
                    });
                })
                .catch(() => Swal.fire({icon:'error',title:'Error',text:'Unable to save registration renewal.',confirmButtonColor:'#dc3545'}));
        });
    }

    <?php if (!empty($save_message)): ?>
    if (typeof Swal !== 'undefined') {
        Swal.fire({icon:'<?php echo h($save_status === 'success' ? 'success' : 'error'); ?>',title:'<?php echo h($save_status === 'success' ? 'Saved' : 'Error'); ?>',text:'<?php echo h($save_message); ?>',confirmButtonColor:'<?php echo h($save_status === 'success' ? '#07b83f' : '#dc3545'); ?>'}).then(function () {
            <?php if ($save_status === 'success'): ?>window.location.href = window.location.pathname;<?php endif; ?>
        });
    } else {
        alert('<?php echo h($save_message); ?>');
        <?php if ($save_status === 'success'): ?>window.location.href = window.location.pathname;<?php endif; ?>
    }
    <?php endif; ?>
});
</script>
</body>
</html>
