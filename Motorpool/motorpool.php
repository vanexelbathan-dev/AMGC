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
// Branch variables
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$branch_name = $_SESSION['branch_name'] ?? '';
$view_all_branches = ($_SESSION['view_all_branches'] ?? false);

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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_ris') {
    $vehicle_db_id = (int)($_POST['vehicle_db_id'] ?? 0);
    $vehicle_id_value = trim((string)($_POST['vehicle_id'] ?? ''));
    $plate_no = trim((string)($_POST['plate_no'] ?? ''));
    $vehicle_details = trim((string)($_POST['vehicle_details'] ?? ''));
    $vehicle_category = trim((string)($_POST['vehicle_category'] ?? ''));
    $concerns = trim((string)($_POST['concerns'] ?? ''));
    $endorsed_by = trim((string)($_POST['endorsed_by'] ?? ''));
    $endorsed_signature = trim((string)($_POST['signature'] ?? ''));
    $date_requested = trim((string)($_POST['date_requested'] ?? date('Y-m-d')));

    if ($vehicle_db_id <= 0) jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    if ($concerns === '') jsonResponse(['success' => false, 'message' => 'Concern/s is required.']);
    if ($endorsed_by === '') jsonResponse(['success' => false, 'message' => 'Endorsed by is required.']);

    $vehicleBranchId = 0;
    if (tableExists($conn, 'motorpool_vehicles')) {
        $branchStmt = $conn->prepare("SELECT branch_id FROM motorpool_vehicles WHERE id = ? LIMIT 1");
        if ($branchStmt) {
            $branchStmt->bind_param('i', $vehicle_db_id);
            $branchStmt->execute();
            $branchRow = $branchStmt->get_result()->fetch_assoc();
            $branchStmt->close();
            $vehicleBranchId = (int)($branchRow['branch_id'] ?? 0);
        }
    }

    $make_brand_value = trim((string)($_POST['make_brand'] ?? ''));
    $vehicle_type_value = trim((string)($_POST['vehicle_type'] ?? ''));
    $classification_value = trim((string)($_POST['classification'] ?? ''));
    $body_type_value = trim((string)($_POST['body_type'] ?? ''));
    $color_value = trim((string)($_POST['color'] ?? ''));
    $fuel_type_value = trim((string)($_POST['type_of_fuel'] ?? ''));
    $year_model_value = trim((string)($_POST['year_model'] ?? ''));
    $series_value = trim((string)($_POST['series'] ?? ''));
    $passenger_capacity_value = trim((string)($_POST['passenger_capacity'] ?? ''));
    $max_power_value = trim((string)($_POST['max_power_kw'] ?? ''));
    $lto_cr_no_value = trim((string)($_POST['lto_cr_no'] ?? ''));
    $date_registration_value = trim((string)($_POST['date_registration'] ?? ''));
    $file_no_value = trim((string)($_POST['file_no'] ?? ''));
    $engine_no_value = trim((string)($_POST['engine_no'] ?? ''));
    $chassis_no_value = trim((string)($_POST['chassis_no'] ?? ''));
    $vin_value = trim((string)($_POST['vin'] ?? ''));
    $gross_weight_value = trim((string)($_POST['gross_weight'] ?? ''));
    $net_weight_value = trim((string)($_POST['net_weight'] ?? ''));
    $year_rebuilt_value = trim((string)($_POST['year_rebuilt'] ?? ''));
    $piston_displacement_value = trim((string)($_POST['piston_displacement'] ?? ''));

    $ris_number = generateRisNumber($conn);
    $stmt = $conn->prepare("INSERT INTO motorpool_ris_requests
        (ris_number, vehicle_db_id, vehicle_id, plate_no, vehicle_details, vehicle_category, branch_id, requested_by, concerns, endorsed_by, endorsed_signature, date_requested, status, workflow_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'For Vehicle Endorsement', 'For Vehicle Endorsement')");
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Failed to prepare RIS request: ' . $conn->error]);
    $stmt->bind_param('sissssiissss', $ris_number, $vehicle_db_id, $vehicle_id_value, $plate_no, $vehicle_details, $vehicle_category, $vehicleBranchId, $user_id, $concerns, $endorsed_by, $endorsed_signature, $date_requested);

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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_scheduled_maintenance') {
    $vehicle_db_id = (int)($_POST['maintenance_vehicle_db_id'] ?? 0);
    $vehicle_id_value = trim((string)($_POST['maintenance_vehicle_id'] ?? ''));
    $plate_no = trim((string)($_POST['maintenance_plate_no'] ?? ''));
    $vehicle_details = trim((string)($_POST['maintenance_vehicle_details'] ?? ''));
    $vehicle_category = trim((string)($_POST['maintenance_vehicle_category'] ?? ''));
    $branch_id_value = (int)($_POST['maintenance_branch_id'] ?? 0);
    $business_unit_value = trim((string)($_POST['maintenance_business_unit'] ?? ''));
    $maintenance_description = trim((string)($_POST['maintenance_description'] ?? ''));
    $scheduled_date = trim((string)($_POST['maintenance_schedule_date'] ?? ''));
    $estimated_cost = (float)str_replace([',', '₱', ' '], '', (string)($_POST['maintenance_estimated_cost'] ?? 0));
    $current_odometer_value = trim(str_replace([',', 'km', 'KM', ' '], '', (string)($_POST['maintenance_current_odometer'] ?? '')));
    $current_odometer = ($current_odometer_value !== '' && is_numeric($current_odometer_value)) ? (float)$current_odometer_value : null;
    $remarks = trim((string)($_POST['maintenance_remarks'] ?? ''));

    if ($vehicle_db_id <= 0) jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    if ($maintenance_description === '') jsonResponse(['success' => false, 'message' => 'Please enter the maintenance to be done.']);
    if ($scheduled_date === '') jsonResponse(['success' => false, 'message' => 'Please select the schedule date.']);
    if ($estimated_cost < 0) jsonResponse(['success' => false, 'message' => 'Estimated cost is invalid.']);

    /*
     * Permanent odometer snapshot fix:
     * - Current Odometer comes from the latest fuel monitoring record first.
     * - Last Maintenance Odometer is the SAME value at the exact time the schedule is created.
     * - After saving, last_odometer is locked in motorpool_scheduled_maintenance and will not change
     *   when the live/current odometer changes later.
     */

    if (tableExists($conn, 'motorpool_vehicles')) {
        $vehicleColsForOdo = getColumns($conn, 'motorpool_vehicles');
        $selectParts = ['vehicle_id', 'plate_no', 'make_brand', 'vehicle_type', 'vehicle_category', 'branch_id', 'business_unit'];
        foreach (['odometer_reading', 'odometer', 'mileage', 'current_mileage', 'current_odometer'] as $odoCol) {
            if (in_array($odoCol, $vehicleColsForOdo, true)) $selectParts[] = $odoCol;
        }
        $selectParts = array_values(array_unique(array_filter($selectParts, fn($col) => in_array($col, $vehicleColsForOdo, true))));
        if (!empty($selectParts)) {
            $sql = "SELECT `" . implode("`, `", $selectParts) . "` FROM motorpool_vehicles WHERE id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $vehicle_db_id);
                $stmt->execute();
                $vehicleRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($vehicleRow) {
                    if ($vehicle_id_value === '') $vehicle_id_value = trim((string)($vehicleRow['vehicle_id'] ?? ''));
                    if ($plate_no === '') $plate_no = trim((string)($vehicleRow['plate_no'] ?? ''));
                    if ($vehicle_details === '') $vehicle_details = trim((string)(($vehicleRow['make_brand'] ?? '') . ' ' . ($vehicleRow['vehicle_type'] ?? '')));
                    if ($vehicle_category === '') $vehicle_category = trim((string)($vehicleRow['vehicle_category'] ?? ''));
                    if ($branch_id_value <= 0) $branch_id_value = (int)($vehicleRow['branch_id'] ?? 0);
                    if ($business_unit_value === '') $business_unit_value = trim((string)($vehicleRow['business_unit'] ?? ''));
                }
            }
        }
    }

    // 1) Use latest Fuel Monitoring odometer as the primary source.
    if (tableExists($conn, 'motorpool_fuel_monitoring')) {
        $fuelColsForOdo = getColumns($conn, 'motorpool_fuel_monitoring');
        if (in_array('vehicle_db_id', $fuelColsForOdo, true) && in_array('current_odometer', $fuelColsForOdo, true)) {
            $fuelOrderParts = [];
            foreach (['fuel_date', 'date_created', 'created_at', 'updated_at'] as $fuelOrderCol) {
                if (in_array($fuelOrderCol, $fuelColsForOdo, true)) $fuelOrderParts[] = "`$fuelOrderCol` DESC";
            }
            foreach (['fuel_id', 'id', 'monitoring_id'] as $fuelOrderCol) {
                if (in_array($fuelOrderCol, $fuelColsForOdo, true)) { $fuelOrderParts[] = "`$fuelOrderCol` DESC"; break; }
            }
            if (empty($fuelOrderParts)) $fuelOrderParts[] = '`vehicle_db_id` DESC';

            $fuelStmt = $conn->prepare("SELECT current_odometer FROM motorpool_fuel_monitoring WHERE vehicle_db_id = ? AND current_odometer IS NOT NULL AND TRIM(CAST(current_odometer AS CHAR)) <> '' ORDER BY " . implode(', ', $fuelOrderParts) . " LIMIT 1");
            if ($fuelStmt) {
                $fuelStmt->bind_param('i', $vehicle_db_id);
                $fuelStmt->execute();
                $fuelRow = $fuelStmt->get_result()->fetch_assoc();
                $fuelStmt->close();
                $fuelOdoRaw = trim(str_replace([',', 'km', 'KM', ' '], '', (string)($fuelRow['current_odometer'] ?? '')));
                if ($fuelOdoRaw !== '' && is_numeric($fuelOdoRaw)) $current_odometer = (float)$fuelOdoRaw;
            }
        }
    }

    // 2) Fallback to vehicle profile odometer only if there is no fuel monitoring odometer.
    if ($current_odometer === null && tableExists($conn, 'motorpool_vehicles')) {
        $vehicleColsForOdo = getColumns($conn, 'motorpool_vehicles');
        $odoSelectCols = [];
        foreach (['odometer_reading', 'odometer', 'mileage', 'current_mileage', 'current_odometer'] as $odoCol) {
            if (in_array($odoCol, $vehicleColsForOdo, true)) $odoSelectCols[] = $odoCol;
        }
        if (!empty($odoSelectCols)) {
            $sql = "SELECT `" . implode("`, `", $odoSelectCols) . "` FROM motorpool_vehicles WHERE id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $vehicle_db_id);
                $stmt->execute();
                $vehicleOdoRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($vehicleOdoRow) {
                    foreach ($odoSelectCols as $odoCol) {
                        $odoValue = trim(str_replace([',', 'km', 'KM', ' '], '', (string)($vehicleOdoRow[$odoCol] ?? '')));
                        if ($odoValue !== '' && is_numeric($odoValue)) {
                            $current_odometer = (float)$odoValue;
                            break;
                        }
                    }
                }
            }
        }
    }

    // This is the locked snapshot. Do not query previous maintenance here.
    $last_odometer = $current_odometer;

    $stmt = $conn->prepare("INSERT INTO motorpool_scheduled_maintenance
        (vehicle_db_id, vehicle_id, plate_no, vehicle_details, vehicle_category, branch_id, business_unit, maintenance_description, scheduled_date, estimated_cost, last_odometer, current_odometer, remarks, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled', ?)");
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Failed to prepare scheduled maintenance: ' . $conn->error]);
    $stmt->bind_param('issssisssdddsi', $vehicle_db_id, $vehicle_id_value, $plate_no, $vehicle_details, $vehicle_category, $branch_id_value, $business_unit_value, $maintenance_description, $scheduled_date, $estimated_cost, $last_odometer, $current_odometer, $remarks, $user_id);
    if (!$stmt->execute()) jsonResponse(['success' => false, 'message' => 'Failed to save scheduled maintenance: ' . $stmt->error]);
    $maintenance_id = $stmt->insert_id;
    $stmt->close();

    // Keep the vehicle profile current odometer updated, but this will not change the locked schedule snapshot.
    if ($current_odometer !== null && tableExists($conn, 'motorpool_vehicles')) {
        $vehicleColsForOdoUpdate = getColumns($conn, 'motorpool_vehicles');
        $odoUpdateCol = firstExisting($vehicleColsForOdoUpdate, ['odometer_reading', 'odometer', 'mileage', 'current_mileage', 'current_odometer']);
        if ($odoUpdateCol) {
            $updateOdo = $conn->prepare("UPDATE motorpool_vehicles SET `$odoUpdateCol` = ? WHERE id = ?");
            if ($updateOdo) {
                $updateOdo->bind_param('di', $current_odometer, $vehicle_db_id);
                $updateOdo->execute();
                $updateOdo->close();
            }
        }
    }

    jsonResponse([
        'success' => true,
        'message' => 'Scheduled maintenance saved successfully.',
        'maintenance_id' => $maintenance_id,
        'scheduled_date' => $scheduled_date,
        'estimated_cost' => number_format($estimated_cost, 2, '.', ''),
        'last_odometer' => $last_odometer !== null ? number_format((float)$last_odometer, 2, '.', '') : '',
        'current_odometer' => $current_odometer !== null ? number_format((float)$current_odometer, 2, '.', '') : ''
    ]);
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
        `vehicle_owner` VARCHAR(150),
        `business_unit` VARCHAR(150),
        `current_odometer` DECIMAL(12,2) DEFAULT NULL,
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
    addColumnIfMissing($conn, $vehicle_table, 'vehicle_owner', '`vehicle_owner` VARCHAR(150) NULL AFTER `branch_id`');
    addColumnIfMissing($conn, $vehicle_table, 'business_unit', '`business_unit` VARCHAR(150) NULL AFTER `vehicle_owner`');
    addColumnIfMissing($conn, $vehicle_table, 'current_odometer', '`current_odometer` DECIMAL(12,2) DEFAULT NULL AFTER `business_unit`');
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
    `endorsed_signature` LONGTEXT DEFAULT NULL,
    `date_requested` DATE DEFAULT NULL,
    `status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement',
    `workflow_status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement',
    `branch_approval_status` VARCHAR(30) DEFAULT 'Pending',
    `branch_approval_by` INT DEFAULT NULL,
    `branch_approval_at` DATETIME DEFAULT NULL,
    `branch_approval_remarks` TEXT DEFAULT NULL,
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
    KEY `idx_workflow_status` (`workflow_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (tableExists($conn, 'motorpool_ris_requests')) {
    addColumnIfMissing($conn, 'motorpool_ris_requests', 'endorsed_signature', '`endorsed_signature` LONGTEXT NULL AFTER `endorsed_by`');
    addColumnIfMissing($conn, 'motorpool_ris_requests', 'workflow_status', "`workflow_status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement' AFTER `status`");
    addColumnIfMissing($conn, 'motorpool_ris_requests', 'branch_approval_status', "`branch_approval_status` VARCHAR(30) DEFAULT 'Pending' AFTER `workflow_status`");
    addColumnIfMissing($conn, 'motorpool_ris_requests', 'branch_approval_by', '`branch_approval_by` INT DEFAULT NULL AFTER `branch_approval_status`');
    addColumnIfMissing($conn, 'motorpool_ris_requests', 'branch_approval_at', '`branch_approval_at` DATETIME DEFAULT NULL AFTER `branch_approval_by`');
    addColumnIfMissing($conn, 'motorpool_ris_requests', 'branch_approval_remarks', '`branch_approval_remarks` TEXT DEFAULT NULL AFTER `branch_approval_at`');
    addColumnIfMissing($conn, 'motorpool_ris_requests', 'ris_attachment', '`ris_attachment` VARCHAR(255) DEFAULT NULL AFTER `repair_cost`');
    @$conn->query("ALTER TABLE `motorpool_ris_requests` MODIFY COLUMN `status` VARCHAR(60) DEFAULT 'For Vehicle Endorsement'");
}

function generateRisNumber(mysqli $conn): string {
    $prefix = 'RIS-' . date('Ymd') . '-';
    $result = $conn->query("SELECT ris_number FROM motorpool_ris_requests WHERE ris_number LIKE '" . $conn->real_escape_string($prefix) . "%' ORDER BY ris_id DESC LIMIT 1");
    $next = 1;
    if ($result && ($row = $result->fetch_assoc())) {
        $num = (int)substr((string)$row['ris_number'], -4);
        $next = $num + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
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



$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_scheduled_maintenance` (
    `maintenance_id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_db_id` INT NOT NULL,
    `vehicle_id` VARCHAR(50) DEFAULT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `vehicle_details` VARCHAR(255) DEFAULT NULL,
    `vehicle_category` VARCHAR(150) DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `business_unit` VARCHAR(150) DEFAULT NULL,
    `maintenance_description` TEXT NOT NULL,
    `scheduled_date` DATE NOT NULL,
    `estimated_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `last_odometer` DECIMAL(12,2) DEFAULT NULL,
    `current_odometer` DECIMAL(12,2) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'Scheduled',
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vehicle_db_id` (`vehicle_db_id`),
    KEY `idx_scheduled_date` (`scheduled_date`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (tableExists($conn, 'motorpool_scheduled_maintenance')) {
    addColumnIfMissing($conn, 'motorpool_scheduled_maintenance', 'last_odometer', '`last_odometer` DECIMAL(12,2) DEFAULT NULL AFTER `estimated_cost`');
    addColumnIfMissing($conn, 'motorpool_scheduled_maintenance', 'current_odometer', '`current_odometer` DECIMAL(12,2) DEFAULT NULL AFTER `last_odometer`');
    addColumnIfMissing($conn, 'motorpool_scheduled_maintenance', 'ris_number', '`ris_number` VARCHAR(50) DEFAULT NULL AFTER `status`');
}

// One-time self-heal for old scheduled maintenance records that were saved before the odometer snapshot fix.
// This only fills blank odometer snapshots. It does not overwrite existing locked odometer values.
if (tableExists($conn, 'motorpool_scheduled_maintenance') && tableExists($conn, 'motorpool_fuel_monitoring')) {
    $smColsForBackfill = getColumns($conn, 'motorpool_scheduled_maintenance');
    $fuelColsForBackfill = getColumns($conn, 'motorpool_fuel_monitoring');
    if (in_array('vehicle_db_id', $smColsForBackfill, true)
        && in_array('last_odometer', $smColsForBackfill, true)
        && in_array('current_odometer', $smColsForBackfill, true)
        && in_array('vehicle_db_id', $fuelColsForBackfill, true)
        && in_array('current_odometer', $fuelColsForBackfill, true)) {

        $fuelOrderParts = [];
        foreach (['fuel_date', 'date_created', 'created_at', 'updated_at'] as $fuelOrderCol) {
            if (in_array($fuelOrderCol, $fuelColsForBackfill, true)) $fuelOrderParts[] = "fm.`$fuelOrderCol` DESC";
        }
        foreach (['fuel_id', 'id', 'monitoring_id'] as $fuelOrderCol) {
            if (in_array($fuelOrderCol, $fuelColsForBackfill, true)) { $fuelOrderParts[] = "fm.`$fuelOrderCol` DESC"; break; }
        }
        if (empty($fuelOrderParts)) $fuelOrderParts[] = 'fm.`vehicle_db_id` DESC';

        $selectFuelOdo = "(SELECT fm.current_odometer
            FROM motorpool_fuel_monitoring fm
            WHERE fm.vehicle_db_id = motorpool_scheduled_maintenance.vehicle_db_id
              AND fm.current_odometer IS NOT NULL
              AND TRIM(CAST(fm.current_odometer AS CHAR)) <> ''
            ORDER BY " . implode(', ', $fuelOrderParts) . "
            LIMIT 1)";

        @$conn->query("UPDATE motorpool_scheduled_maintenance
            SET last_odometer = COALESCE(last_odometer, $selectFuelOdo),
                current_odometer = COALESCE(current_odometer, $selectFuelOdo)
            WHERE last_odometer IS NULL OR current_odometer IS NULL");
    }
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
    'vehicle_owner' => ['vehicle_owner', 'owner', 'assigned_to', 'vehicle_assignee'],
    'business_unit' => ['business_unit', 'business_unit_name'],
    'current_odometer' => ['odometer_reading', 'odometer', 'mileage', 'current_mileage', 'current_odometer'],
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
$cuencaVehicleOwners = [
    'Jeca',
    'A.Macalindong',
    'Katong Trucking',
    'Anne Trucking',
    'C.oil Lipa',
    'C.oil Trucking',
    'Cement',
    'Minidump',
    'Equipment Rental',
    'Dumptruck'
];

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
    /*
     * Return the first EXISTING column that actually has a value.
     * Important for odometer:
     * Some databases already use odometer_reading / odometer / mileage / current_mileage.
     * This file also creates current_odometer for compatibility, but that new column can be empty.
     * The old version used firstExisting(), so it always picked current_odometer and showed N/A
     * even when odometer_reading already had the real value.
     */
    $fallback = '';
    foreach ($choices as $choice) {
        if (!in_array($choice, $columns, true)) continue;
        if (!array_key_exists($choice, $row)) continue;
        $value = trim((string)$row[$choice]);
        if ($value !== '') return (string)$row[$choice];
        if ($fallback === '') $fallback = (string)$row[$choice];
    }
    return $fallback;
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

function motorpoolNumericOdometerValue($value): string {
    $raw = trim((string)$value);
    if ($raw === '' || strtolower($raw) === 'null') return '';
    $clean = str_replace([',', 'km', 'KM', ' '], '', $raw);
    return is_numeric($clean) ? $clean : '';
}

function motorpoolLatestFuelOdometers(mysqli $conn): array {
    $map = [];
    if (!tableExists($conn, 'motorpool_fuel_monitoring')) return $map;
    $columns = getColumns($conn, 'motorpool_fuel_monitoring');
    if (!in_array('vehicle_db_id', $columns, true) || !in_array('current_odometer', $columns, true)) return $map;

    $orderParts = [];
    foreach (['fuel_date', 'date_created', 'created_at', 'updated_at'] as $col) {
        if (in_array($col, $columns, true)) $orderParts[] = "`$col` DESC";
    }
    foreach (['fuel_id', 'id', 'monitoring_id'] as $col) {
        if (in_array($col, $columns, true)) { $orderParts[] = "`$col` DESC"; break; }
    }
    if (empty($orderParts)) $orderParts[] = '`vehicle_db_id` DESC';

    $sql = "SELECT vehicle_db_id, current_odometer FROM motorpool_fuel_monitoring
            WHERE current_odometer IS NOT NULL AND TRIM(CAST(current_odometer AS CHAR)) <> ''
            ORDER BY vehicle_db_id ASC, " . implode(', ', $orderParts);
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $vehicleId = (int)($row['vehicle_db_id'] ?? 0);
            if ($vehicleId <= 0 || isset($map[$vehicleId])) continue;
            $odo = motorpoolNumericOdometerValue($row['current_odometer'] ?? '');
            if ($odo !== '') $map[$vehicleId] = $odo;
        }
    }
    return $map;
}

function fetchVehicleCurrentOdometers(mysqli $conn, array $vehicles, array $vehicle_columns): array {
    $map = motorpoolLatestFuelOdometers($conn);
    foreach ($vehicles as $vehicle) {
        $vehicleDbId = (int)($vehicle['id'] ?? 0);
        if ($vehicleDbId <= 0 || isset($map[$vehicleDbId])) continue;
        foreach (['odometer_reading', 'odometer', 'mileage', 'current_mileage', 'current_odometer'] as $col) {
            if (!in_array($col, $vehicle_columns, true)) continue;
            $odo = motorpoolNumericOdometerValue($vehicle[$col] ?? '');
            if ($odo !== '') {
                $map[$vehicleDbId] = $odo;
                break;
            }
        }
    }
    return $map;
}

function fetchLastMaintenanceOdometers(mysqli $conn): array {
    $map = [];
    if (!tableExists($conn, 'motorpool_scheduled_maintenance')) return $map;
    $columns = getColumns($conn, 'motorpool_scheduled_maintenance');
    if (!in_array('vehicle_db_id', $columns, true)) return $map;

    $odoCandidates = [];
    foreach (['maintenance_odometer', 'locked_odometer', 'current_odometer', 'last_odometer', 'odometer_reading', 'odometer'] as $col) {
        if (in_array($col, $columns, true)) $odoCandidates[] = $col;
    }
    if (empty($odoCandidates)) return $map;

    $selectOdo = [];
    foreach ($odoCandidates as $col) $selectOdo[] = "`$col` AS `$col`";

    $orderParts = [];
    foreach (['scheduled_date', 'created_at', 'updated_at'] as $col) {
        if (in_array($col, $columns, true)) $orderParts[] = "`$col` DESC";
    }
    foreach (['maintenance_id', 'id', 'schedule_id'] as $col) {
        if (in_array($col, $columns, true)) { $orderParts[] = "`$col` DESC"; break; }
    }
    if (empty($orderParts)) $orderParts[] = '`vehicle_db_id` DESC';

    $whereOdo = [];
    foreach ($odoCandidates as $col) $whereOdo[] = "(`$col` IS NOT NULL AND TRIM(CAST(`$col` AS CHAR)) <> '')";

    $sql = "SELECT vehicle_db_id, " . implode(', ', $selectOdo) . "
            FROM motorpool_scheduled_maintenance
            WHERE " . implode(' OR ', $whereOdo) . "
            ORDER BY vehicle_db_id ASC, " . implode(', ', $orderParts);
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $vehicleId = (int)($row['vehicle_db_id'] ?? 0);
            if ($vehicleId <= 0 || isset($map[$vehicleId])) continue;
            foreach ($odoCandidates as $col) {
                $odo = motorpoolNumericOdometerValue($row[$col] ?? '');
                if ($odo !== '') {
                    $map[$vehicleId] = $odo;
                    break;
                }
            }
        }
    }
    return $map;
}

function motorpoolOdometerDisplay($value): string {
    $raw = trim((string)$value);
    if ($raw === '') return 'N/A';
    $clean = str_replace(',', '', $raw);
    if (is_numeric($clean)) return number_format((float)$clean, 0) . ' km';
    return $raw;
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
    $value = $part['source_label'] ?? ($part['source_by'] ?? ($part['parts_source_by'] ?? ($part['source_type'] ?? ($part['cost_source'] ?? ($part['costSource'] ?? ($part['source'] ?? ''))))));
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
            // keep actual latest qty/cost per item, not duplicate repeated workflow rows
            if ($qty > 0) $partMap[$key]['quantity'] = $qty;
            if ($unit > 0) $partMap[$key]['unit_cost'] = $unit;
            if ($total > 0) $partMap[$key]['total_cost'] = $total;
            if ($source !== '') $partMap[$key]['source'] = $source;
        }
    }

    foreach ($partMap as $part) {
        $result['item_cost'] += (float)($part['total_cost'] ?? 0);
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
                    'repair_cost' => '',
                    'cost_source' => '',
                    'source' => ''
                ];
            }
            if ($map[$key]['item_no'] === '' && $itemNo !== '') $map[$key]['item_no'] = $itemNo;
            if ($map[$key]['description'] === '' && $description !== '') $map[$key]['description'] = $description;
            if ($map[$key]['specification'] === '' && $specification !== '') $map[$key]['specification'] = $specification;
            if ($map[$key]['quantity'] === '' && $quantity !== '') $map[$key]['quantity'] = $quantity;
            if ($map[$key]['unit_cost'] === '' && $unitCost !== '') $map[$key]['unit_cost'] = $unitCost;
            if ($map[$key]['estimated_cost'] === '' && $estimatedCost !== '') $map[$key]['estimated_cost'] = $estimatedCost;
            if ($map[$key]['repair_cost'] === '' && $repairCost !== '') $map[$key]['repair_cost'] = $repairCost;
            if ($map[$key]['cost_source'] === '' && $costSource !== '') $map[$key]['cost_source'] = $costSource;
        }
    };

    $assessment = json_decode((string)($row['assessment_json'] ?? '[]'), true);
    if (is_array($assessment)) {
        foreach ($assessment as $repair) {
            if (!is_array($repair)) continue;
            $repairCostValue = (string)($repair['repair_cost'] ?? ($repair['labor_cost'] ?? ($repair['service_cost'] ?? '')));
            $parts = $repair['parts'] ?? [];
            if (!is_array($parts)) continue;
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
                    $repairCostValue
                );
            }
        }
    }

    foreach (preg_split('/\R+/', (string)($row['parts_summary'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Assessed By:') === 0) continue;
        $itemNo = $description = $specification = $quantity = $unitCost = $estimatedCost = $costSource = $repairCost = '';
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
            elseif (in_array($key, ['repair cost', 'repair_cost', 'labor cost', 'labor_cost'], true)) $repairCost = $val;
            elseif (in_array($key, ['cost source', 'cost_source', 'source'], true)) $costSource = $val;
        }
        $add($itemNo, $description, $specification, $quantity, $unitCost, $estimatedCost, $costSource, $repairCost);
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
            'repair_cost' => '',
            'cost_source' => '',
                'source' => ''
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
    $repairCost = trim((string)($part['repair_cost'] ?? ($part['labor_cost'] ?? ($part['service_cost'] ?? ''))));

    if ($unitCost === '' && !empty($assessment['unit_cost'])) $unitCost = (string)$assessment['unit_cost'];
    if ($estimatedCost === '' && !empty($assessment['estimated_cost'])) $estimatedCost = (string)$assessment['estimated_cost'];
    if ($costSource === '' && !empty($assessment['cost_source'])) $costSource = (string)$assessment['cost_source'];
    if ($repairCost === '' && !empty($assessment['repair_cost'])) $repairCost = (string)$assessment['repair_cost'];

    if ($estimatedCost === '' && is_numeric($qty) && is_numeric($unitCost)) {
        $estimatedCost = (string)((float)$qty * (float)$unitCost);
    }

    if ($map[$key]['description'] === '' && $description !== '') $map[$key]['description'] = $description;
    if ($map[$key]['specification'] === '' && $specification !== '') $map[$key]['specification'] = $specification;
    if ($map[$key]['unit_cost'] === '' && $unitCost !== '') $map[$key]['unit_cost'] = motorpoolMoneyText($unitCost);
    if ($map[$key]['estimated_cost'] === '' && $estimatedCost !== '') $map[$key]['estimated_cost'] = motorpoolMoneyText($estimatedCost);
    if ($map[$key]['repair_cost'] === '' && $repairCost !== '') $map[$key]['repair_cost'] = motorpoolMoneyText($repairCost);
    if ($map[$key]['cost_source'] === '' && $costSource !== '') $map[$key]['cost_source'] = $costSource;

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

        if (trim((string)($row['unit_cost'] ?? '')) !== '') {
            $line .= ' | Unit Cost: ' . motorpoolMoneyText($row['unit_cost']);
        }
        if (trim((string)($row['estimated_cost'] ?? '')) !== '') {
            $line .= ' | Estimated Cost: ' . motorpoolMoneyText($row['estimated_cost']);
        }
        $sourceValue = trim((string)($row['source'] ?? ($row['cost_source'] ?? '')));
        if ($sourceValue !== '') {
            $line .= ' | Source: ' . $sourceValue;
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
        $part = ['item_no' => '', 'used_quantity' => '', 'description' => '', 'specification' => '', 'unit_cost' => '', 'estimated_cost' => '', 'repair_cost' => '', 'cost_source' => ''];
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
            elseif (in_array($key, ['repair cost', 'repair_cost', 'labor cost', 'labor_cost'], true)) $part['repair_cost'] = $val;
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


function motorpoolCleanRepairsDoneText(string $value): string {
    $lines = [];
    foreach (preg_split('/\R+/', trim($value)) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $line = preg_replace('/^Repairs\s+to\s+Make\s*:\s*/i', '', $line);
        $line = preg_replace('/^Repairs\s+Done\s*:\s*/i', '', $line);
        $line = preg_replace('/^Repair\s*:\s*/i', '', $line);
        $parts = explode('|', $line);
        $repairName = trim((string)($parts[0] ?? ''));
        if ($repairName !== '' && strtolower($repairName) !== 'n/a') $lines[] = $repairName;
    }
    return implode("\n", array_values(array_unique($lines)));
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

    $sql = "SELECT h.repair_id, h.vehicle_db_id, h.ris_id, h.ris_number, h.repair_date, h.repairs_done, h.parts_replaced, h.mechanic, h.start_date, h.end_date, h.attachment, h.repair_cost, h.created_at,
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
            $risIdForCosts = (int)($row['ris_id'] ?? 0);
            $row['repairs_done'] = motorpoolCleanRepairsDoneText((string)($row['repairs_done'] ?? ''));
            $row['parts_replaced'] = motorpoolBuildPartsReplacedSummaryForRis($conn, $risIdForCosts, (string)($row['parts_replaced'] ?? ''));

            // Accurate repair total per RIS:
            // includes labor/repair cost + all used items/parts cost + miscellaneous cost.
            // Parts/items are included regardless of source (Motorpool Source or Branch Source).
            $costBreakdown = motorpoolBuildCostsForRis($conn, $risIdForCosts);
            $grandTotal = (float)$costBreakdown['repair_cost'] + (float)$costBreakdown['item_cost'] + (float)$costBreakdown['misc_cost'];

            // Fallback for old records that only have vehicle_repair_history.repair_cost.
            if ($grandTotal <= 0 && motorpoolCostNumber($row['repair_cost'] ?? 0) > 0) {
                $grandTotal = motorpoolCostNumber($row['repair_cost'] ?? 0);
                $costBreakdown['repair_cost'] = $grandTotal;
            }

            $row['repair_cost_amount'] = number_format((float)$costBreakdown['repair_cost'], 2, '.', '');
            $row['item_cost_amount'] = number_format((float)$costBreakdown['item_cost'], 2, '.', '');
            $row['misc_cost_amount'] = number_format((float)$costBreakdown['misc_cost'], 2, '.', '');
            $row['grand_total_amount'] = number_format($grandTotal, 2, '.', '');
            $row['grand_total_display'] = '₱' . number_format($grandTotal, 2);
            $row['cost_summary'] = motorpoolBuildCostSummaryTextForRis($conn, $risIdForCosts);

            if (trim((string)$row['cost_summary']) === '' && $grandTotal > 0) {
                $row['cost_summary'] = "Cost Summary:
"
                    . 'Repair Cost: ' . $row['repair_cost_amount'] . "
"
                    . 'Item Cost: ' . $row['item_cost_amount'] . "
"
                    . 'Miscellaneous Cost: ' . $row['misc_cost_amount'] . "
"
                    . 'Grand Total: ' . $row['grand_total_amount'];
            }

            $histories[(int)$row['vehicle_db_id']][] = $row;
        }
    }
    return $histories;
}




function fetchVehicleRepairPaymentHistories(mysqli $conn, array $vehicles): array {
    $histories = [];
    if (!tableExists($conn, 'repair_payment_history')) return $histories;

    $ids = [];
    foreach ($vehicles as $vehicle) {
        if (!empty($vehicle['id'])) $ids[] = (int)$vehicle['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return $histories;

    $idList = implode(',', array_map('intval', $ids));

    $columns = getColumns($conn, 'repair_payment_history');
    $select = [
        'payment_id',
        'ris_id',
        'ris_number',
        'vehicle_db_id',
        'vehicle_id',
        'plate_no',
        'repair_date',
        'total_cost',
        'amount_paid',
        'payment_date',
        'payment_method',
        'reference_no',
        'remarks',
        'attachment',
        'created_at'
    ];

    foreach (['expense_account_name', 'bank_account_name', 'check_date', 'bank_name', 'bank_branch', 'check_number'] as $optionalColumn) {
        if (in_array($optionalColumn, $columns, true)) $select[] = $optionalColumn;
    }

    $safeSelect = [];
    foreach ($select as $column) {
        if (in_array($column, $columns, true)) {
            $safeSelect[] = "`$column`";
        }
    }
    if (empty($safeSelect)) return $histories;

    $sql = "SELECT " . implode(', ', $safeSelect) . "
            FROM repair_payment_history
            WHERE vehicle_db_id IN ($idList)
            ORDER BY COALESCE(payment_date, DATE(created_at)) DESC, payment_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $vehicleId = (int)($row['vehicle_db_id'] ?? 0);
            if ($vehicleId <= 0) continue;
            $histories[$vehicleId][] = $row;
        }
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
                        'details' => $approvalDetails . "\nMotorpool may now complete the required parts." . (!empty($r['parts_summary']) ? "\n\nItems / Parts Needed:\n" . (string)$r['parts_summary'] : ''),
                        'attachment' => '',
                        'processed_by_name' => trim((string)($r['approved_by_name'] ?? '')) ?: 'Branch Admin',
                        'processed_at' => $r['branch_approval_at'] ?? $r['updated_at'] ?? ''
                    ];
                }
            }

            if (!empty($r['quality_summary']) && !workflowKeyExists($histories[$vid], $risId, 'For Quality Check')) {
                $histories[$vid][] = $common + [
                    'workflow_status' => 'For Quality Check',
                    'details' => (string)$r['quality_summary'],
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
                    'details' => $releaseDetails,
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
        foreach ($items as $idx => $historyRow) {
            $risForCostSummary = (int)($historyRow['ris_id'] ?? 0);
            $stageForCostSummary = normalizeWorkflowStatusPHP((string)($historyRow['workflow_status'] ?? ''));
            if ($risForCostSummary > 0 && in_array($stageForCostSummary, ['For Repair', 'On-going Repair', 'For Quality Check', 'For Release'], true)) {
                $items[$idx]['details'] = motorpoolAppendCostSummaryForRis($conn, $risForCostSummary, (string)($historyRow['details'] ?? ''));
            }
        }
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
$vehicleCurrentOdometers = fetchVehicleCurrentOdometers($conn, $vehicles, $vehicle_columns);
$vehicleLastMaintenanceOdometers = fetchLastMaintenanceOdometers($conn);
$vehicleRepairHistories = fetchVehicleRepairHistories($conn, $vehicles);
$vehicleRepairPaymentHistories = fetchVehicleRepairPaymentHistories($conn, $vehicles);
$vehicleWorkflowHistories = fetchVehicleWorkflowHistories($conn, $vehicles);
$vehicleRegistrationHistories = fetchVehicleRegistrationHistories($conn, $vehicles);

$motorpoolRepairReportRows = [];
foreach ($vehicles as $vehicle) {
    $vehicleDbId = (int)($vehicle['id'] ?? 0);
    if ($vehicleDbId <= 0) continue;

    $vehicleIdValue = v($vehicle, $vehicle_columns, ['vehicle_id', 'vehicle_code', 'vehicle_no']);
    $plateValue = v($vehicle, $vehicle_columns, ['plate_no', 'plate_number']);
    $makeBrandValue = v($vehicle, $vehicle_columns, ['make_brand', 'make', 'brand']);
    $vehicleTypeValue = v($vehicle, $vehicle_columns, ['vehicle_type', 'type']);
    $vehicleDetailsValue = trim($makeBrandValue . ' ' . $vehicleTypeValue);
    $vehicleCategoryValue = v($vehicle, $vehicle_columns, ['vehicle_category', 'category']);
    $branchIdValue = (int)v($vehicle, $vehicle_columns, ['branch_id']);
    $branchNameValue = $branchNames[$branchIdValue] ?? '';
    $businessUnitValue = v($vehicle, $vehicle_columns, ['business_unit', 'business_unit_name']);
    if ($businessUnitValue === '' && $branchIdValue > 0) $businessUnitValue = $branchBusinessUnits[$branchIdValue] ?? '';

    foreach (($vehicleRepairHistories[$vehicleDbId] ?? []) as $repairRow) {
        $repairCostValue = (float)motorpoolCostNumber($repairRow['repair_cost_amount'] ?? ($repairRow['repair_cost'] ?? 0));
        $itemCostValue = (float)motorpoolCostNumber($repairRow['item_cost_amount'] ?? 0);
        $miscCostValue = (float)motorpoolCostNumber($repairRow['misc_cost_amount'] ?? 0);
        $grandTotalValue = (float)motorpoolCostNumber($repairRow['grand_total_amount'] ?? 0);
        if ($grandTotalValue <= 0) $grandTotalValue = $repairCostValue + $itemCostValue + $miscCostValue;
        if ($grandTotalValue <= 0) $grandTotalValue = (float)motorpoolCostNumber($repairRow['repair_cost'] ?? 0);

        $repairDateValue = trim((string)($repairRow['repair_date'] ?? ''));
        if ($repairDateValue === '') $repairDateValue = substr((string)($repairRow['created_at'] ?? ''), 0, 10);

        $motorpoolRepairReportRows[] = [
            'repair_id' => (int)($repairRow['repair_id'] ?? 0),
            'ris_id' => (int)($repairRow['ris_id'] ?? 0),
            'ris_number' => (string)($repairRow['ris_number'] ?? ''),
            'vehicle_db_id' => $vehicleDbId,
            'vehicle_id' => $vehicleIdValue,
            'plate_no' => $plateValue,
            'vehicle_details' => $vehicleDetailsValue,
            'vehicle_category' => $vehicleCategoryValue,
            'branch_id' => $branchIdValue,
            'branch_name' => $branchNameValue,
            'business_unit' => $businessUnitValue,
            'repair_date' => $repairDateValue,
            'start_date' => (string)($repairRow['start_date'] ?? ''),
            'end_date' => (string)($repairRow['end_date'] ?? ''),
            'repairs_done' => (string)($repairRow['repairs_done'] ?? ''),
            'parts_replaced' => (string)($repairRow['parts_replaced'] ?? ''),
            'mechanic' => (string)($repairRow['mechanic'] ?? ''),
            'attachment' => (string)($repairRow['attachment'] ?? ''),
            'repair_cost_amount' => number_format($repairCostValue, 2, '.', ''),
            'item_cost_amount' => number_format($itemCostValue, 2, '.', ''),
            'misc_cost_amount' => number_format($miscCostValue, 2, '.', ''),
            'grand_total_amount' => number_format($grandTotalValue, 2, '.', ''),
            'cost_summary' => (string)($repairRow['cost_summary'] ?? ''),
            'status' => trim((string)($repairRow['checked_received_by'] ?? '')) !== '' ? 'Released / Received' : 'Completed',
            'received_by' => (string)($repairRow['checked_received_by'] ?? ''),
            'received_datetime' => (string)($repairRow['received_datetime'] ?? ''),
            'created_at' => (string)($repairRow['created_at'] ?? '')
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vehicle Profile - Motorpool</title>
<!-- v24 fix: Available / Received Parts quantity now reads available_quantity/available_qty/received_quantity keys -->
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/motorpoolv2.css">
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<style>
.form-card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
.custom-table th{background:#052A47;color:#fff;white-space:nowrap}.custom-table td{vertical-align:middle}
.custom-table th,
.custom-table td{
    text-align:center!important;
    vertical-align:middle!important;
}
.custom-table td .d-flex{
    justify-content:center!important;
}
.btn-action-text{white-space:nowrap;border-radius:8px}.required-mark{color:#dc3545}.section-title{font-weight:700;color:#052A47;margin:18px 0 10px}.vehicle-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}.item-thumbnail{width:48px;height:48px;border-radius:8px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;overflow:hidden;margin:0 auto}.item-thumbnail img{width:100%;height:100%;object-fit:cover}.custom-table tbody tr.vehicle-click-row{cursor:pointer;transition:background-color .18s ease}.custom-table tbody tr.vehicle-click-row:hover td{background:#f4fbf6}.custom-table .col-image{width:78px;text-align:center}.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:998;opacity:0;transition:opacity .25s ease}.sidebar-overlay.active{opacity:1}.dropdown-arrow{margin-left:auto;transition:transform .2s ease}@media(max-width:992px){.sidebar{transform:translateX(-100%);transition:transform .25s ease;z-index:999}.sidebar.active,.sidebar.show{transform:translateX(0)}}
#vehicleModal .modal-dialog{max-width:1240px;margin-top:12px;margin-bottom:12px}#vehicleModal .modal-content{max-height:calc(100vh - 24px);display:flex;flex-direction:column;border:1px solid #dfe6ec;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.12)}#vehicleModal .modal-header{background:#07b83f;color:#fff;border-bottom:0;padding:14px 18px;flex-shrink:0}#vehicleModal .btn-close{opacity:1!important;visibility:visible!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;background:none!important;background-image:none!important;filter:none!important;-webkit-filter:none!important;position:relative!important;width:34px!important;height:34px!important;border-radius:50%!important;border:0!important;margin:0!important;padding:0!important;flex:0 0 34px!important}#vehicleModal .btn-close::before,#vehicleModal .btn-close::after{content:""!important;position:absolute!important;width:16px!important;height:2px!important;background:#052A47!important;border-radius:999px!important;top:50%!important;left:50%!important;transform-origin:center!important}#vehicleModal .btn-close::before{transform:translate(-50%,-50%) rotate(45deg)!important}#vehicleModal .btn-close::after{transform:translate(-50%,-50%) rotate(-45deg)!important}#vehicleModal .btn-close:hover{background:rgba(255,255,255,.18)!important;opacity:1!important}#vehicleModal .modal-body{overflow-y:auto;max-height:calc(100vh - 155px);padding:16px;background:#f8fafc}#vehicleModal .modal-footer{flex-shrink:0;background:#fff;border-top:1px solid #dee2e6;padding:12px 18px}#vehicleModal .btn-success{background:#07b83f;border-color:#07b83f}#vehicleModal .btn-success:hover{background:#069d36;border-color:#069d36}.motorpool-form-panel{background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:14px 16px;margin-bottom:14px}.motorpool-panel-title{display:inline-flex;align-items:center;gap:4px;color:#1f2937;font-weight:600;padding-bottom:8px;margin-bottom:14px;border-bottom:2px solid #0d6efd}.motorpool-form-panel .form-label{font-size:.86rem;font-weight:600;color:#374151;margin-bottom:5px}.motorpool-form-panel .form-control,.motorpool-form-panel .form-select{min-height:38px;border:1px solid #d8e0ea;border-radius:9px;font-size:.9rem;background:#fff}.motorpool-form-panel .form-control:focus,.motorpool-form-panel .form-select:focus{border-color:#07b83f;box-shadow:0 0 0 .15rem rgba(7,184,63,.15)}
.vehicle-detail-hero{display:flex;gap:18px;align-items:center;padding:16px;background:#f8fafc;border:1px solid #e3e8ef;border-radius:12px;margin-bottom:16px}.vehicle-detail-image{width:120px;height:120px;border-radius:14px;background:#f1f3f5;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}.vehicle-detail-image img{width:100%;height:100%;object-fit:cover}.vehicle-detail-title h4{margin:0 0 6px;font-weight:700;color:#1f2937}.vehicle-detail-tabs .nav-link{color:#495057;font-weight:600;border-radius:8px 8px 0 0}.vehicle-detail-tabs .nav-link.active{color:#07b83f;border-color:#dee2e6 #dee2e6 #fff}.detail-info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));column-gap:28px;row-gap:10px}.detail-info-item{display:grid;grid-template-columns:145px minmax(0,1fr);align-items:start;gap:8px;padding:6px 0;border-bottom:1px solid #eef2f6;background:transparent}.detail-info-item small{color:#6c757d;font-size:.82rem;line-height:1.25}.detail-info-item strong{color:#212529;font-weight:600;line-height:1.25;word-break:break-word}.vehicle-image-preview-wrap{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}.vehicle-image-preview{border:1px solid #e3e8ef;border-radius:12px;background:#fff;padding:10px}.vehicle-image-preview img{width:100%;height:130px;object-fit:cover;border-radius:8px;background:#f1f3f5}.vehicle-image-preview a{display:block;margin-top:7px;font-size:.85rem;color:#07b83f;font-weight:600;text-decoration:none}.history-table thead th{background:#07b83f!important;color:#fff!important;border-color:#07b83f!important;white-space:nowrap}.history-table td{border-color:#e3e8ef!important;vertical-align:middle}
#renewRegistrationModal .form-label{font-weight:600;color:#374151}#renewRegistrationModal .form-control:focus{border-color:#07b83f;box-shadow:0 0 0 .15rem rgba(7,184,63,.15)}
#motorpoolFilePreviewModal{padding:0!important;background:transparent!important;pointer-events:none;}
#motorpoolFilePreviewModal.show{display:flex!important;align-items:center;justify-content:center;}
#motorpoolFilePreviewModal .modal-dialog{margin:0!important;width:auto;max-width:96vw!important;height:auto;pointer-events:auto;}
#motorpoolFilePreviewModal .modal-content{background:transparent!important;border:none!important;box-shadow:none!important;overflow:visible!important;}
#motorpoolFilePreviewModal .modal-body{padding:0!important;margin:0!important;overflow:visible!important;display:flex;align-items:center;justify-content:center;background:transparent!important;}
#motorpoolFilePreviewModal + .modal-backdrop,
.modal-backdrop.motorpool-preview-backdrop,
.modal-backdrop.show{
    display:block!important;
    opacity:.72!important;
    background:#000!important;
}
.attachment-wrapper{position:relative;display:inline-block;line-height:0;max-width:96vw;max-height:94vh;}
.attachment-content img{display:block;max-width:96vw;max-height:94vh;width:auto;height:auto;object-fit:contain;border-radius:10px;box-shadow:0 14px 40px rgba(0,0,0,.25);}
.attachment-content embed{display:block;width:92vw;height:90vh;border-radius:10px;background:#fff;box-shadow:0 14px 40px rgba(0,0,0,.25);}
.btn-close-attachment,.btn-download-attachment{position:absolute;right:10px;width:34px;height:34px;border-radius:50%;background:rgba(0,0,0,.70);color:#fff;z-index:9999;display:flex!important;align-items:center;justify-content:center;text-decoration:none;border:0;line-height:1;}
.btn-close-attachment{top:10px;}
.btn-download-attachment{bottom:10px;}
.btn-close-attachment:hover,.btn-download-attachment:hover{background:rgba(0,0,0,.9);color:#fff;}
body.motorpool-preview-open{overflow:hidden!important;padding-right:0!important;}
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


.repair-history-text {
    white-space: pre-wrap;
    line-height: 1.45;
    min-width: 220px;
}
.repair-history-table th,
.repair-history-table td {
    text-align: center;
    vertical-align: middle;
}
.repair-history-table .repair-history-text {
    text-align: left;
}
.repair-progress-table-wrap {
    min-width: 520px;
}
.repair-progress-table th {
    background: #eaf8ef !important;
    color: #212529 !important;
    font-size: .78rem;
    white-space: nowrap;
}
.repair-progress-table td {
    font-size: .86rem;
    vertical-align: middle;
}



.compact-ris-info {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px 16px;
}
#risModal .compact-ris-info .detail-info-item {
    grid-template-columns:130px minmax(0,1fr);
    border-bottom:1px solid #eef2f6;
}
.signature-preview-box {
    min-height:120px;
    border:1px dashed #b8c7d3;
    border-radius:12px;
    background:#f8fafc;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:10px;
}
.signature-preview-empty { color:#64748b; font-size:.9rem; }
.signature-preview-image { max-width:100%; max-height:120px; object-fit:contain; }
.signature-pad-box {
    width:100%;
    min-height:260px;
    border:1px solid #d8e0ea;
    border-radius:12px;
    background:#fff;
    overflow:hidden;
}
.signature-pad-canvas {
    width:100%;
    height:260px;
    display:block;
    touch-action:none;
}
@media(max-width:992px){.compact-ris-info{grid-template-columns:1fr}}


.ongoing-workflow-table-v29 th,
.ongoing-workflow-table-v29 td {
    text-align: center;
    vertical-align: middle !important;
}
.ongoing-workflow-table-v29 .parts-replaced-mini-table-wrap {
    min-width: 360px;
    margin: 0 !important;
}
.ongoing-workflow-table-v29 .parts-replaced-mini-table th,
.ongoing-workflow-table-v29 .parts-replaced-mini-table td {
    text-align: center;
}


.ongoing-workflow-table-v31 th,
.ongoing-workflow-table-v31 td {
    text-align: center;
    vertical-align: middle !important;
}
.ongoing-workflow-table-v31 td:first-child,
.ongoing-workflow-table-v31 th:first-child {
    text-align: left;
}
.parts-replaced-mini-table td,
.parts-replaced-mini-table th {
    vertical-align: middle !important;
}
#detailVehicleIdBadge,
#detailBranchBadge,
#detailBusinessUnitBadge{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:8px 14px !important;
    border-radius:50px !important;
    font-size:.82rem !important;
    font-weight:600 !important;
    margin-right:6px !important;
    border:1.5px solid transparent !important;
    background:#fff !important;
    transition:.2s ease;
}

/* Vehicle ID */
#detailVehicleIdBadge{
    border-color:#198754 !important;
    background:rgba(25,135,84,.10) !important;
    color:#198754 !important;
}

/* Branch */
#detailBranchBadge{
    border-color:#0d6efd !important;
    background:rgba(13,110,253,.10) !important;
    color:#0d6efd !important;
}

/* Business Unit */
#detailBusinessUnitBadge{
    border-color:#6f42c1 !important;
    background:rgba(111,66,193,.10) !important;
    color:#6f42c1 !important;
}

/* Hover */
#detailVehicleIdBadge:hover{
    background:rgba(25,135,84,.18) !important;
}

#detailBranchBadge:hover{
    background:rgba(13,110,253,.18) !important;
}

#detailBusinessUnitBadge:hover{
    background:rgba(111,66,193,.18) !important;
}

.motorpool-report-modal .modal-dialog{max-width:96vw;}
.motorpool-report-header{
    background:linear-gradient(135deg, rgba(25,135,84,.12), rgba(13,110,253,.08));
    border:1px solid rgba(25,135,84,.15);
    border-radius:18px;
    padding:16px;
}
.motorpool-report-filter{
    border:1px solid rgba(0,0,0,.08);
    border-radius:14px;
    padding:12px;
    background:#fff;
}
.motorpool-report-card{
    border:1px solid rgba(0,0,0,.08);
    border-radius:16px;
    background:#fff;
    padding:14px;
    min-height:98px;
    box-shadow:0 8px 20px rgba(0,0,0,.04);
}
.motorpool-report-card small{display:block;color:#6c757d;font-weight:600;margin-bottom:7px;}
.motorpool-report-card strong{font-size:1.25rem;color:#198754;}
.motorpool-report-chart-card{
    border:1px solid rgba(0,0,0,.08);
    border-radius:16px;
    background:#fff;
    padding:14px;
    min-height:320px;
}
.motorpool-report-chart-wrap{height:260px;position:relative;}
.motorpool-report-table-wrap{max-height:52vh;overflow:auto;border:1px solid rgba(0,0,0,.08);border-radius:14px;}
.motorpool-report-table th{position:sticky;top:0;background:#f8f9fa;z-index:2;white-space:nowrap;}
.motorpool-report-table td{vertical-align:top;font-size:.86rem;}
.motorpool-report-detail-text{max-width:300px;white-space:pre-line;}
.motorpool-report-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;}
@media print{
    body *{visibility:hidden!important;}
    #motorpoolRepairReportPrintable, #motorpoolRepairReportPrintable *{visibility:visible!important;}
    #motorpoolRepairReportPrintable{position:absolute;left:0;top:0;width:100%;background:#fff;}
    .modal-backdrop,.motorpool-report-actions,.btn,.form-select,.form-control,label{display:none!important;}
    .motorpool-report-table-wrap{max-height:none!important;overflow:visible!important;border:0!important;}
    .motorpool-report-table th{position:static!important;}
}

</style>

<style>
.motorpool-filter-panel{background:#fff;border:1px solid #e4ece7;border-radius:14px;padding:0;margin:12px 0 16px;box-shadow:0 6px 18px rgba(5,42,71,.05);overflow:hidden}
.motorpool-filter-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 15px;cursor:pointer;background:#f8fbf9;border-bottom:1px solid transparent}
.motorpool-filter-header h5{margin:0;font-size:1rem;font-weight:800;color:#052A47;display:flex;align-items:center;gap:8px}
.motorpool-filter-toggle-btn{background:none;border:0;color:#198754;font-size:1.15rem;line-height:1;padding:4px 6px;border-radius:8px}
.motorpool-filter-toggle-btn:hover{background:#e9f7ef}
.motorpool-filter-content{padding:14px;transition:all .25s ease;overflow:hidden}
.motorpool-filter-content.collapsed{display:none}
.motorpool-filter-panel.is-open .motorpool-filter-header{border-bottom-color:#dceee2}
.motorpool-filter-panel .form-label{font-size:.78rem;font-weight:700;color:#052A47;margin-bottom:5px}
.motorpool-filter-panel .input-group-text{background:#fff;border-color:#d7e7dc;color:#198754}
.motorpool-filter-panel .form-control,.motorpool-filter-panel .form-select{border-color:#d7e7dc;border-radius:9px;min-height:39px}
.motorpool-filter-panel .input-group .form-control{border-left:0;border-radius:0 9px 9px 0}
.motorpool-filter-panel .filter-count-badge{background:#e9f7ef;color:#146c43;border:1px solid #ccebd8;border-radius:999px;padding:6px 10px;font-size:.78rem;font-weight:700;white-space:nowrap}
.motorpool-filter-panel .btn-reset-filter{border-radius:9px;min-height:39px;white-space:nowrap}
@media(max-width:768px){.motorpool-filter-panel .motorpool-filter-content{padding:12px}.motorpool-filter-panel .btn-reset-filter{width:100%}}

.motorpool-vehicle-print-area{display:none}
@media print{
    body *{visibility:hidden!important;}
    #motorpoolVehiclePrintArea,#motorpoolVehiclePrintArea *{visibility:visible!important;}
    #motorpoolVehiclePrintArea{display:block!important;position:absolute;left:0;top:0;width:100%;background:#fff;color:#111;font-family:Arial,Helvetica,sans-serif;}
    #appPage,.modal-backdrop,.swal2-container{display:none!important;}
    @page{size:A4;margin:12mm;}
    .mp-print-page{page-break-after:always;padding:0;}
    .mp-print-page:last-child{page-break-after:auto;}
    .mp-print-header{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #198754;padding-bottom:10px;margin-bottom:12px;}
    .mp-print-company{display:flex;gap:10px;align-items:center;}
    .mp-print-logo{width:58px;height:58px;object-fit:contain;}
    .mp-print-title h2{font-size:18px;margin:0;color:#052A47;font-weight:800;}
    .mp-print-title small{font-size:11px;color:#555;}
    .mp-print-meta{text-align:right;font-size:11px;color:#444;}
    .mp-print-hero{display:grid;grid-template-columns:160px 1fr;gap:14px;margin:12px 0 14px;align-items:start;}
    .mp-print-main-img{width:160px;height:130px;object-fit:cover;border:1px solid #bbb;border-radius:8px;}
    .mp-print-no-img{width:160px;height:130px;border:1px dashed #aaa;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#777;font-size:12px;}
    .mp-print-plate{font-size:24px;font-weight:800;color:#052A47;margin:0 0 4px;}
    .mp-print-sub{font-size:13px;color:#333;margin-bottom:8px;}
    .mp-print-badges{display:flex;gap:6px;flex-wrap:wrap;}
    .mp-print-badge{border:1px solid #198754;background:#eefaf2;color:#146c43;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:700;}
    .mp-print-section{margin-top:12px;}
    .mp-print-section-title{background:#198754;color:#fff;font-weight:800;font-size:12px;padding:7px 9px;border-radius:6px 6px 0 0;letter-spacing:.2px;}
    .mp-print-grid{display:grid;grid-template-columns:repeat(3,1fr);border-left:1px solid #d8d8d8;border-top:1px solid #d8d8d8;}
    .mp-print-item{padding:7px 8px;border-right:1px solid #d8d8d8;border-bottom:1px solid #d8d8d8;min-height:42px;}
    .mp-print-item small{display:block;font-size:9.5px;color:#666;text-transform:uppercase;font-weight:700;margin-bottom:3px;}
    .mp-print-item strong{font-size:12px;color:#111;font-weight:700;word-break:break-word;}
    .mp-print-table{width:100%;border-collapse:collapse;font-size:10.5px;}
    .mp-print-table th{background:#f0f7f2;color:#052A47;font-weight:800;}
    .mp-print-table th,.mp-print-table td{border:1px solid #ccc;padding:6px;vertical-align:top;}
    .mp-print-images{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:8px;}
    .mp-print-image-card{border:1px solid #ccc;border-radius:7px;padding:6px;min-height:120px;}
    .mp-print-image-card img{width:100%;height:105px;object-fit:cover;border-radius:5px;}
    .mp-print-image-card .mp-print-file{font-size:9px;color:#555;margin-top:4px;word-break:break-all;}
    .mp-print-file-only{height:105px;display:flex;align-items:center;justify-content:center;background:#f6f6f6;border-radius:5px;color:#555;font-size:11px;text-align:center;padding:8px;}
    .mp-print-footer{margin-top:14px;border-top:1px solid #ccc;padding-top:8px;font-size:10px;color:#555;display:flex;justify-content:space-between;}
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
<div id="motorpoolVehiclePrintArea" class="motorpool-vehicle-print-area"></div>
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
                                <a class="nav-link" href="request_handler.php">
                                    <i class="bi bi-clipboard"></i>
                                    <span class="nav-text">RIS Monitoring</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link active" href="motorpool.php">
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
                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                        <button type="button" class="btn btn-outline-success btn-action-text" onclick="printFilteredVehicleProfiles(); return false;" data-mp-action="print-filtered-vehicles">
                            <i class="bi bi-printer me-1"></i>Print List
                        </button>
                        <button type="button" class="btn btn-outline-success btn-action-text" onclick="openMotorpoolRepairReportModal(); return false;" data-mp-action="open-repair-report">
                            <i class="bi bi-bar-chart-line me-1"></i>Report
                        </button>
                        <button type="button" class="btn btn-success btn-action-text" onclick="openVehicleModal(); return false;" data-mp-action="open-vehicle-modal">
                            <i class="bi bi-plus-circle me-1"></i>Add Vehicle
                        </button>
                    </div>
                </div>

                <?php if (!$vehicle_table_exists): ?>
                    <div class="alert alert-warning mb-3">The <strong>motorpool_vehicles</strong> table could not be created. Please check your database permissions.</div>
                <?php endif; ?>

                <div class="motorpool-filter-panel" id="vehicleFilterCard">
                    <div class="motorpool-filter-header" id="vehicleFilterHeader">
                        <h5><i class="bi bi-funnel"></i> Filter Vehicles</h5>
                        <button class="motorpool-filter-toggle-btn" type="button" id="vehicleFilterToggleBtn" aria-expanded="false">
                            <i class="bi bi-chevron-down" id="vehicleFilterIcon"></i>
                        </button>
                    </div>
                    <div class="motorpool-filter-content collapsed" id="vehicleFilterContent">
                        <div class="row g-2 align-items-end">
                            <div class="col-lg-4 col-md-6">
                                <label class="form-label" for="vehicleSearchInput">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="vehicleSearchInput" placeholder="Search plate, vehicle ID, type, owner...">
                                </div>
                            </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="vehicleBranchFilter">Branch</label>
                            <select class="form-select" id="vehicleBranchFilter">
                                <option value="">All Branches</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="vehicleBusinessUnitFilter">Business Unit</label>
                            <select class="form-select" id="vehicleBusinessUnitFilter">
                                <option value="">All Business Units</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="vehicleCategoryFilter">Category</label>
                            <select class="form-select" id="vehicleCategoryFilter">
                                <option value="">All Categories</option>
                            </select>
                        </div>
                        <div class="col-lg-1 col-md-6">
                            <label class="form-label" for="vehicleStatusFilter">Status</label>
                            <select class="form-select" id="vehicleStatusFilter">
                                <option value="">All Status</option>
                            </select>
                        </div>
                        <div class="col-lg-1 col-md-6 d-grid">
                            <button type="button" class="btn btn-outline-secondary btn-reset-filter" id="vehicleResetFilterBtn"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table custom-table compact-table align-middle" id="vehicleProfileTable">
                        <thead>
                            <tr>
                                <th class="col-image">Image</th>
                                <th>Plate No.</th>
                                <th>Vehicle Type</th>
                                <th>Branch</th>
                                <th>Vehicle Owner</th>
                                <th>Maintenance Odometer</th>
                                <th>Current Odometer</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="vehicleProfileTableBody">
                        <?php if (empty($vehicles)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No registered vehicles found.</td></tr>
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
                            $vehicleOwner = v($vehicle, $vehicle_columns, ['vehicle_owner', 'owner', 'assigned_to', 'vehicle_assignee']);
                            $businessUnit = v($vehicle, $vehicle_columns, ['business_unit', 'business_unit_name']);
                            if ($businessUnit === '' && $rowBranchId > 0) $businessUnit = $branchBusinessUnits[$rowBranchId] ?? '';
                            $currentOdometer = $vehicleCurrentOdometers[$vehicleDbId] ?? v($vehicle, $vehicle_columns, ['odometer_reading', 'odometer', 'mileage', 'current_mileage', 'current_odometer']);
                            $lastMaintenanceOdometer = $vehicleLastMaintenanceOdometers[$vehicleDbId] ?? '';
                            $dataAttrs = ' data-db-id="' . h($vehicleDbId) . '"';
                            foreach ($fieldMap as $formField => $choices) {
                                if ($formField === 'current_odometer') continue;
                                $dataAttrs .= ' data-' . h(str_replace('_', '-', $formField)) . '="' . h(v($vehicle, $vehicle_columns, $choices)) . '"';
                            }
                            $vehicleStatus = v($vehicle, $vehicle_columns, ['status']);
                            if ($vehicleStatus === '') $vehicleStatus = 'Active';
                            $filterSearchText = trim($vehicleId . ' ' . $plateNo . ' ' . $makeBrand . ' ' . $vehicleType . ' ' . $vehicleCategory . ' ' . $branchName . ' ' . $businessUnit . ' ' . $vehicleOwner . ' ' . $vehicleStatus);
                            $dataAttrs .= ' data-current-odometer="' . h($currentOdometer) . '"';
                            $dataAttrs .= ' data-branch-name="' . h($branchName) . '"';
                            $dataAttrs .= ' data-business-unit-display="' . h($businessUnit) . '"';
                            $dataAttrs .= ' data-filter-branch="' . h($branchName) . '"';
                            $dataAttrs .= ' data-filter-business-unit="' . h($businessUnit) . '"';
                            $dataAttrs .= ' data-filter-category="' . h($vehicleCategory) . '"';
                            $dataAttrs .= ' data-filter-status="' . h($vehicleStatus) . '"';
                            $dataAttrs .= ' data-filter-search="' . h($filterSearchText) . '"';
                            $dataAttrs .= ' data-last-maintenance-odometer="' . h($lastMaintenanceOdometer) . '"';
                            $dataAttrs .= ' data-repair-history="' . h(json_encode($vehicleRepairHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
                            $dataAttrs .= ' data-repair-payment-history="' . h(json_encode($vehicleRepairPaymentHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
                            $dataAttrs .= ' data-workflow-history="' . h(json_encode($vehicleWorkflowHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
                            $dataAttrs .= ' data-registration-history="' . h(json_encode($vehicleRegistrationHistories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
                        ?>
                            <tr class="vehicle-click-row js-view-vehicle" data-mp-action="view-vehicle-details"<?php echo $dataAttrs; ?>>
                                <td class="col-image"><?php echo motorpoolImageCell($vehicleImage, $plateNo); ?></td>
                                <td><strong><?php echo h($plateNo); ?></strong><br><small class="text-muted">Vehicle ID: <?php echo h($vehicleId); ?></small></td>
                                <td><?php echo h($vehicleType); ?></td>
                                <td><?php echo h($branchName); ?></td>
                                <td><?php echo h($vehicleOwner !== '' ? $vehicleOwner : 'N/A'); ?></td>
                                <td><?php echo h(motorpoolOdometerDisplay($lastMaintenanceOdometer)); ?></td>
                                <td><?php echo h(motorpoolOdometerDisplay($currentOdometer)); ?></td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                                        <button type="button" class="btn btn-success btn-sm btn-action-text" data-mp-action="open-ris"><i class="bi bi-clipboard-check me-1"></i>RIS</button>
                                        <button type="button" class="btn btn-outline-success btn-sm btn-action-text" data-mp-action="open-maintenance"><i class="bi bi-tools me-1"></i>Maint.</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                            <tr id="vehicleNoFilterResultsRow" style="display:none;"><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No vehicles matched your search or filters.</td></tr>
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
                                <select class="form-select" name="branch_id" id="branch_id" required onchange="syncBusinessUnitFromBranch(); updateVehicleOwnerOptions();">
                                    <option value="">Select branch</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo h($branch['branch_id']); ?>" data-business-unit="<?php echo h($branch['business_unit'] ?? ''); ?>" data-branch-name="<?php echo h($branch['branch_name']); ?>"><?php echo h($branch['branch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vehicle Owner / Assigned To</label>
                                <input class="form-control" name="vehicle_owner" id="vehicle_owner" placeholder="Vehicle owner / assigned to">
                                <datalist id="cuencaVehicleOwnerOptions">
                                    <?php foreach ($cuencaVehicleOwners as $owner): ?>
                                        <option value="<?php echo h($owner); ?>">
                                    <?php endforeach; ?>
                                </datalist>
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
                            <div class="col-md-4"><label class="form-label">Current Odometer</label><input type="number" step="0.01" min="0" class="form-control" name="current_odometer" id="current_odometer" placeholder="Current odometer reading"></div>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="vehicle-detail-hero">
                    <div class="vehicle-detail-image" id="detailVehicleImage"><i class="bi bi-image text-muted fs-1"></i></div>
                    <div class="vehicle-detail-title">
                        <h4 id="detailPlateTitle"></h4>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="detail-badge detail-badge-vehicle" id="detailVehicleIdBadge"></span>
                            <span class="detail-badge detail-badge-branch" id="detailBranchBadge"></span>
                            <span class="detail-badge detail-badge-unit" id="detailBusinessUnitBadge"></span>
                        </div>
                    </div>
                </div>
                <ul class="nav nav-tabs vehicle-detail-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#vehicleInfoTab" type="button">Vehicle Information</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleRegistrationTab" type="button">Registration</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleAttachmentsTab" type="button">Attachments</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehicleRepairTab" type="button">Repair History</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vehiclePaymentHistoryTab" type="button">Payment History</button></li>
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
                        <div class="row g-3 mb-3" id="repairHistoryGrandTotalWrap"></div>
                        <div class="table-responsive"><table class="table history-table repair-history-table align-middle"><thead><tr><th>Repair Date</th><th>RIS No.</th><th>Repairs Done</th><th>Parts Replaced / Used</th><th>Grand Total</th><th>Mechanic</th><th>Attachment</th></tr></thead><tbody id="repairHistoryBody"></tbody></table></div>
                    </div>
                    <div class="tab-pane fade" id="vehiclePaymentHistoryTab">
                        <div class="row g-3 mb-3" id="paymentHistorySummaryCards"></div>
                        <div class="table-responsive">
                            <table class="table history-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Repair Date</th>
                                        <th>RIS No.</th>
                                        <th class="text-end">Repair Total</th>
                                        <th class="text-end">Total Paid</th>
                                        <th class="text-end">Balance</th>
                                        <th>Status</th>
                                        <th>Payment Records</th>
                                    </tr>
                                </thead>
                                <tbody id="paymentHistoryBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-action-text" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary btn-action-text" onclick="printCurrentVehicleProfile()" data-mp-action="print-current-vehicle"><i class="bi bi-printer me-1"></i>Print</button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="fw-bold" id="repairWorkflowTitle">Repair Workflow</div>
                    <small class="text-muted" id="repairWorkflowSubtitle"></small>
                </div>
                
                <!-- ===== PROGRESS BAR - ITO ANG KULANG ===== -->
                <div class="workflow-progress-container">
                    <div class="progress-steps">
                        <div class="step">
                            <div class="step-circle"><span>1</span></div>
                            <div class="step-label">Vehicle<br>Endorsement</div>
                        </div>
                        <div class="step">
                            <div class="step-circle"><span>2</span></div>
                            <div class="step-label">For<br>Assessment</div>
                        </div>
                        <div class="step">
                            <div class="step-circle"><span>3</span></div>
                            <div class="step-label">For<br>Approval</div>
                        </div>
                        <div class="step">
                            <div class="step-circle"><span>4</span></div>
                            <div class="step-label">For Parts<br>Completion</div>
                        </div>
                        <div class="step">
                            <div class="step-circle"><span>5</span></div>
                            <div class="step-label">For<br>Repair</div>
                        </div>
                        <div class="step">
                            <div class="step-circle"><span>6</span></div>
                            <div class="step-label">On-going<br>Repair</div>
                        </div>
                        <div class="step">
                            <div class="step-circle"><span>7</span></div>
                            <div class="step-label">Quality<br>Check</div>
                        </div>
                        <div class="step">
                            <div class="step-circle"><span>8</span></div>
                            <div class="step-label">For<br>Release</div>
                        </div>
                    </div>
                </div>
                <!-- ===== END OF PROGRESS BAR ===== -->
                
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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


<div class="modal fade" id="risModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:95%;">
    <div class="modal-content">
      <div class="modal-header bg-success text-white sticky-top" style="z-index:10;border-bottom:1px solid #dee2e6;">
        <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i>Request for Inspection Slip</h5>
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
                <button type="button" class="btn btn-outline-success btn-sm mt-2" onclick="openSignatureModal()"><i class="bi bi-pencil-square me-1"></i>Add Signature</button>
                <button type="button" class="btn btn-outline-danger btn-sm mt-2 ms-1 d-none" id="removeSignatureBtn" onclick="removeSavedSignature()"><i class="bi bi-trash me-1"></i>Remove</button>
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


<div class="modal fade" id="maintenanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-tools me-2"></i>Schedule Maintenance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="maintenanceForm">
        <input type="hidden" name="action" value="save_scheduled_maintenance">
        <input type="hidden" name="maintenance_vehicle_db_id" id="maintenanceVehicleDbId">
        <input type="hidden" name="maintenance_vehicle_id" id="maintenanceVehicleId">
        <input type="hidden" name="maintenance_plate_no" id="maintenancePlateNo">
        <input type="hidden" name="maintenance_vehicle_details" id="maintenanceVehicleDetails">
        <input type="hidden" name="maintenance_vehicle_category" id="maintenanceVehicleCategory">
        <input type="hidden" name="maintenance_branch_id" id="maintenanceBranchId">
        <input type="hidden" name="maintenance_business_unit" id="maintenanceBusinessUnit">
        <input type="hidden" name="maintenance_current_odometer" id="maintenanceCurrentOdometer">
        <div class="modal-body">
          <div class="motorpool-form-panel mb-3">
            <div class="motorpool-panel-title"><i class="bi bi-truck-front me-2"></i>Vehicle Information</div>
            <div class="detail-info-grid compact-ris-info" id="maintenanceVehicleDetailsGrid"></div>
          </div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Maintenance to be done <span class="required-mark">*</span></label>
              <textarea class="form-control" name="maintenance_description" id="maintenanceDescription" rows="4" placeholder="Enter maintenance details" required></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Schedule Date <span class="required-mark">*</span></label>
              <input type="date" class="form-control" name="maintenance_schedule_date" id="maintenanceScheduleDate" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Estimated Cost</label>
              <input type="number" min="0" step="0.01" class="form-control" name="maintenance_estimated_cost" id="maintenanceEstimatedCost" placeholder="0.00">
            </div>
            <div class="col-12">
              <label class="form-label">Remarks</label>
              <textarea class="form-control" name="maintenance_remarks" id="maintenanceRemarks" rows="2" placeholder="Optional remarks"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-white">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-success" onclick="saveScheduledMaintenance()"><i class="bi bi-check-circle me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="signatureModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Driver/Operator Signature</h5>
        <button type="button" class="btn-close" onclick="cancelSignatureModal()"></button>
      </div>
      <div class="modal-body">
        <div class="signature-pad-box"><canvas id="signaturePad" class="signature-pad-canvas"></canvas></div>
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

<div class="modal fade" id="motorpoolFilePreviewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-body"><div class="attachment-wrapper"><button type="button" class="btn-close-attachment" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button><a class="btn-download-attachment" id="previewDownloadLink" href="#" download><i class="bi bi-download"></i></a><div class="attachment-content" id="previewContent"></div></div></div></div></div>
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
                    <a class="dropdown-item" href="request_handler.php"><i class="bi bi-clipboard"></i><span>RIS
                            Monitoring</span></a>
                    <a class="dropdown-item active" href="motorpool.php"><i class="bi bi-truck"></i><span>Vehicle
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

<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="user-avatar-large mb-3"><?php echo h($user_initials); ?></div>
                <h5 class="mb-1"><?php echo h($user_name); ?></h5>
                <div class="branch-info mb-4"><?php echo h(ucfirst($user_role)); ?></div>
                <button type="button" class="btn btn-danger w-100" onclick="confirmLogout()">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade motorpool-report-modal" id="motorpoolRepairReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-xl-down modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0"><i class="bi bi-bar-chart-line me-2"></i>Motorpool Repair Report</h5>
                    <small class="text-muted">Daily, weekly, monthly, or custom repair cost report with complete repair details.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="motorpoolRepairReportPrintable">
                <div class="motorpool-report-header mb-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Filter Type</label>
                            <select class="form-select" id="repairReportFilterType" onchange="motorpoolRepairReportToggleFilters(); motorpoolApplyRepairReportFilter();">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="custom">Custom Range</option>
                                <option value="all">All Records</option>
                            </select>
                        </div>
                        <div class="col-md-2 repair-report-date-filter" id="repairReportSingleDateWrap">
                            <label class="form-label small fw-semibold">Date</label>
                            <input type="date" class="form-control" id="repairReportDate" onchange="motorpoolApplyRepairReportFilter()">
                        </div>
                        <div class="col-md-2 d-none" id="repairReportMonthWrap">
                            <label class="form-label small fw-semibold">Month</label>
                            <input type="month" class="form-control" id="repairReportMonth" onchange="motorpoolApplyRepairReportFilter()">
                        </div>
                        <div class="col-md-2 d-none" id="repairReportStartWrap">
                            <label class="form-label small fw-semibold">Start Date</label>
                            <input type="date" class="form-control" id="repairReportStartDate" onchange="motorpoolApplyRepairReportFilter()">
                        </div>
                        <div class="col-md-2 d-none" id="repairReportEndWrap">
                            <label class="form-label small fw-semibold">End Date</label>
                            <input type="date" class="form-control" id="repairReportEndDate" onchange="motorpoolApplyRepairReportFilter()">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Branch</label>
                            <select class="form-select" id="repairReportBranchFilter" onchange="motorpoolApplyRepairReportFilter()">
                                <option value="">All Branches</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Search</label>
                            <input type="text" class="form-control" id="repairReportSearch" placeholder="RIS, plate, vehicle..." oninput="motorpoolApplyRepairReportFilter()">
                        </div>
                        <div class="col-md-2 motorpool-report-actions">
                            <button type="button" class="btn btn-outline-secondary" onclick="motorpoolPrintRepairReport()"><i class="bi bi-printer me-1"></i>Print</button>
                            <button type="button" class="btn btn-outline-success" onclick="motorpoolExportRepairReportCsv()"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel/CSV</button>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3" id="repairReportSummaryCards"></div>

                <div class="row g-3 mb-3">
                    <div class="col-lg-8">
                        <div class="motorpool-report-chart-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <h6 class="mb-0">Repair Cost Trend</h6>
                                    <small class="text-muted">Grand total per selected period</small>
                                </div>
                            </div>
                            <div class="motorpool-report-chart-wrap"><canvas id="repairReportTrendChart"></canvas></div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="motorpool-report-chart-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <h6 class="mb-0">Repair Cost by Branch</h6>
                                    <small class="text-muted">Grand total grouped by branch</small>
                                </div>
                            </div>
                            <div class="motorpool-report-chart-wrap"><canvas id="repairReportBranchChart"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h6 class="mb-0">Complete Repair Details</h6>
                        <small class="text-muted" id="repairReportRangeText">Showing selected repair records.</small>
                    </div>
                    <span class="badge bg-success" id="repairReportRecordCount">0 records</span>
                </div>
                <div class="motorpool-report-table-wrap">
                    <table class="table table-sm table-hover motorpool-report-table mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>RIS No.</th>
                                <th>Vehicle</th>
                                <th>Plate No.</th>
                                <th>Branch</th>
                                <th>Category</th>
                                <th>Mechanic</th>
                                <th>Repairs Done</th>
                                <th>Parts / Items Used</th>
                                <th class="text-end">Labor / Repair</th>
                                <th class="text-end">Items / Parts</th>
                                <th class="text-end">Misc.</th>
                                <th class="text-end">Grand Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="repairReportTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" onclick="motorpoolApplyRepairReportFilter()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh Report</button>
            </div>
        </div>
    </div>
</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>


function normalizeMotorpoolFilterText(value) {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
}

function populateVehicleFilterOptions(selectEl, values) {
    if (!selectEl) return;
    const firstLabel = selectEl.options.length ? selectEl.options[0].textContent : 'All';
    selectEl.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = firstLabel;
    selectEl.appendChild(defaultOption);

    Array.from(values)
        .filter(function (value) { return String(value || '').trim() !== ''; })
        .sort(function (a, b) { return String(a).localeCompare(String(b)); })
        .forEach(function (value) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            selectEl.appendChild(option);
        });
}

function initializeVehicleFilterToggle() {
    const card = document.getElementById('vehicleFilterCard');
    const header = document.getElementById('vehicleFilterHeader');
    const content = document.getElementById('vehicleFilterContent');
    const toggleBtn = document.getElementById('vehicleFilterToggleBtn');
    const icon = document.getElementById('vehicleFilterIcon');
    if (!header || !content) return;

    function setExpanded(expanded) {
        content.classList.toggle('collapsed', !expanded);
        if (card) card.classList.toggle('is-open', expanded);
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (icon) {
            icon.classList.toggle('bi-chevron-down', !expanded);
            icon.classList.toggle('bi-chevron-up', expanded);
        }
    }

    setExpanded(false);
    header.addEventListener('click', function () {
        setExpanded(content.classList.contains('collapsed'));
    });
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            setExpanded(content.classList.contains('collapsed'));
        });
    }
}

function initializeVehicleSearchAndFilters() {
    initializeVehicleFilterToggle();
    const tableBody = document.getElementById('vehicleProfileTableBody');
    if (!tableBody) return;

    const rows = Array.from(tableBody.querySelectorAll('tr.vehicle-click-row'));
    const searchInput = document.getElementById('vehicleSearchInput');
    const branchFilter = document.getElementById('vehicleBranchFilter');
    const businessUnitFilter = document.getElementById('vehicleBusinessUnitFilter');
    const categoryFilter = document.getElementById('vehicleCategoryFilter');
    const statusFilter = document.getElementById('vehicleStatusFilter');
    const resetBtn = document.getElementById('vehicleResetFilterBtn');
    const countBadge = document.getElementById('vehicleFilterCount');
    const noResultsRow = document.getElementById('vehicleNoFilterResultsRow');

    populateVehicleFilterOptions(branchFilter, new Set(rows.map(function (row) { return row.dataset.filterBranch || ''; })));
    populateVehicleFilterOptions(businessUnitFilter, new Set(rows.map(function (row) { return row.dataset.filterBusinessUnit || ''; })));
    populateVehicleFilterOptions(categoryFilter, new Set(rows.map(function (row) { return row.dataset.filterCategory || ''; })));
    populateVehicleFilterOptions(statusFilter, new Set(rows.map(function (row) { return row.dataset.filterStatus || ''; })));

    function applyVehicleFilters() {
        const keyword = normalizeMotorpoolFilterText(searchInput ? searchInput.value : '');
        const selectedBranch = normalizeMotorpoolFilterText(branchFilter ? branchFilter.value : '');
        const selectedBusinessUnit = normalizeMotorpoolFilterText(businessUnitFilter ? businessUnitFilter.value : '');
        const selectedCategory = normalizeMotorpoolFilterText(categoryFilter ? categoryFilter.value : '');
        const selectedStatus = normalizeMotorpoolFilterText(statusFilter ? statusFilter.value : '');
        let visibleCount = 0;

        rows.forEach(function (row) {
            const rowSearch = normalizeMotorpoolFilterText(row.dataset.filterSearch || row.textContent);
            const rowBranch = normalizeMotorpoolFilterText(row.dataset.filterBranch || '');
            const rowBusinessUnit = normalizeMotorpoolFilterText(row.dataset.filterBusinessUnit || '');
            const rowCategory = normalizeMotorpoolFilterText(row.dataset.filterCategory || '');
            const rowStatus = normalizeMotorpoolFilterText(row.dataset.filterStatus || '');

            const matched = (!keyword || rowSearch.includes(keyword))
                && (!selectedBranch || rowBranch === selectedBranch)
                && (!selectedBusinessUnit || rowBusinessUnit === selectedBusinessUnit)
                && (!selectedCategory || rowCategory === selectedCategory)
                && (!selectedStatus || rowStatus === selectedStatus);

            row.style.display = matched ? '' : 'none';
            if (matched) visibleCount++;
        });

        if (noResultsRow) noResultsRow.style.display = rows.length > 0 && visibleCount === 0 ? '' : 'none';
        if (countBadge) countBadge.textContent = 'Showing ' + visibleCount + ' of ' + rows.length + ' vehicle' + (rows.length === 1 ? '' : 's');
    }

    [searchInput, branchFilter, businessUnitFilter, categoryFilter, statusFilter].forEach(function (el) {
        if (!el || el.dataset.vehicleFilterBound) return;
        el.dataset.vehicleFilterBound = '1';
        el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', applyVehicleFilters);
    });

    if (resetBtn && !resetBtn.dataset.vehicleFilterBound) {
        resetBtn.dataset.vehicleFilterBound = '1';
        resetBtn.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            if (branchFilter) branchFilter.value = '';
            if (businessUnitFilter) businessUnitFilter.value = '';
            if (categoryFilter) categoryFilter.value = '';
            if (statusFilter) statusFilter.value = '';
            applyVehicleFilters();
        });
    }

    applyVehicleFilters();
}

document.addEventListener('DOMContentLoaded', initializeVehicleSearchAndFilters);

const motorpoolRepairReportRows = <?php $motorpoolRepairReportRowsJson = json_encode($motorpoolRepairReportRows, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); echo $motorpoolRepairReportRowsJson ?: "[]"; ?>;
const branchBusinessUnits = <?php $branchBusinessUnitsJson = json_encode($branchBusinessUnits, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); echo $branchBusinessUnitsJson ?: '{}'; ?>;
let currentVehicleRow = null;
let currentVehicleData = {};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, match => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[match]));
}


function buildRisDetailItem(label, value) {
    return `<div class="detail-info-item"><small>${escapeHtml(label)}</small><strong>${escapeHtml(value || 'N/A')}</strong></div>`;
}
function today() { return new Date().toISOString().slice(0, 10); }
function setValue(id, value) { const el = document.getElementById(id); if (el) el.value = value || ''; }
function showModal(id) { const el = document.getElementById(id); if (el) bootstrap.Modal.getOrCreateInstance(el).show(); }
function hideModal(id) { const el = document.getElementById(id); if (el) bootstrap.Modal.getOrCreateInstance(el).hide(); }

/* ===== MOTORPOOL CONSISTENT NESTED MODAL HANDLER =====
   Lahat ng modal na bubuksan mula sa loob ng ibang modal ay dadaan dito.
   Parent modal muna ang itatago, child modal ang lalabas, tapos babalik ang parent pag sinara ang child.
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

/*
   UNIVERSAL MOTORPOOL NESTED MODAL HANDLER

   Example flow:
   Vehicle Details Modal -> Detailed Repair Workflow Modal
   - Vehicle Details will be hidden first.
   - Detailed Repair Workflow will be shown.
   - When Detailed Repair Workflow is closed, Vehicle Details will show again.

   Use motorpoolShowModalFromCurrent('childModalId', {parentModalId:'parentModalId'})
   for every modal opened from inside another modal.
*/
function motorpoolShowModalFromCurrent(targetModalId, options) {
    options = options || {};

    const targetModalElement = document.getElementById(targetModalId);
    if (!targetModalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;

    const explicitParentId = options.parentModalId || '';
    let parentModalElement = null;

    if (explicitParentId) {
        parentModalElement = document.getElementById(explicitParentId);
    } else {
        parentModalElement = motorpoolGetVisibleModal(targetModalId);
    }

    targetModalElement.removeEventListener('hidden.bs.modal', motorpoolRestorePreviousModal);

    if (parentModalElement && parentModalElement.id && parentModalElement.id !== targetModalId) {
        targetModalElement.dataset.motorpoolReturnModalId = parentModalElement.id;
        targetModalElement.addEventListener('hidden.bs.modal', motorpoolRestorePreviousModal);

        const showChildModal = function() {
            bootstrap.Modal.getOrCreateInstance(targetModalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            }).show();
            document.body.classList.add('modal-open');
        };

        if (parentModalElement.classList.contains('show')) {
            parentModalElement.addEventListener('hidden.bs.modal', showChildModal, {once:true});
            /*
               Prevent the parent modal's own return handler from firing while it is
               being hidden only to open another child modal.
            */
            parentModalElement.dataset.motorpoolSuppressRestore = '1';
            bootstrap.Modal.getOrCreateInstance(parentModalElement).hide();
        } else {
            setTimeout(showChildModal, options.delay || 80);
        }
    } else {
        targetModalElement.removeAttribute('data-motorpool-return-modal-id');
        bootstrap.Modal.getOrCreateInstance(targetModalElement, {
            backdrop: true,
            keyboard: true,
            focus: true
        }).show();
        document.body.classList.add('modal-open');
    }
}

function motorpoolRestorePreviousModal(event) {
    const closedModalElement = event && event.target ? event.target : this;
    if (!closedModalElement) return;

    /*
       Kapag parent modal ay pansamantalang hinide para mag-open ng child modal,
       huwag muna siyang mag-restore ng sarili niyang parent.
       Example:
       Vehicle Details -> Detailed Repair Workflow -> Image Preview
       Hiding Detailed Repair Workflow to show Image Preview must NOT restore Vehicle Details yet.
    */
    if (closedModalElement.dataset && closedModalElement.dataset.motorpoolSuppressRestore === '1') {
        closedModalElement.removeAttribute('data-motorpool-suppress-restore');
        document.body.classList.add('modal-open');
        return;
    }

    const returnModalId = closedModalElement.dataset ? (closedModalElement.dataset.motorpoolReturnModalId || '') : '';
    if (closedModalElement.dataset) closedModalElement.removeAttribute('data-motorpool-return-modal-id');

    setTimeout(function() {
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
            backdrop.remove();
        });

        if (returnModalId) {
            const returnModalElement = document.getElementById(returnModalId);
            if (returnModalElement) {
                bootstrap.Modal.getOrCreateInstance(returnModalElement, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                }).show();
                document.body.classList.add('modal-open');
                return;
            }
        }

        motorpoolCleanupModalState();
    }, 160);
}

function motorpoolOpenChildModal(childModalId, parentModalId) {
    motorpoolShowModalFromCurrent(childModalId, parentModalId ? {parentModalId: parentModalId} : {});
}

function openScheduleMaintenanceModal(row) {
    const d = getRowData(row);
    const vehicleName = [d.make_brand, d.vehicle_type].filter(Boolean).join(' - ');
    const form = document.getElementById('maintenanceForm');
    if (form) form.reset();
    setValue('maintenanceVehicleDbId', d.db_id);
    setValue('maintenanceVehicleId', d.vehicle_id);
    setValue('maintenancePlateNo', d.plate_no);
    setValue('maintenanceVehicleDetails', vehicleName);
    setValue('maintenanceVehicleCategory', d.vehicle_category);
    setValue('maintenanceBranchId', d.branch_id || '');
    setValue('maintenanceBusinessUnit', d.business_unit_display || d.business_unit || '');
    setValue('maintenanceCurrentOdometer', d.current_odometer || '');
    setValue('maintenanceScheduleDate', today());
    setValue('maintenanceEstimatedCost', '');
    setValue('maintenanceDescription', '');
    setValue('maintenanceRemarks', '');

    const grid = document.getElementById('maintenanceVehicleDetailsGrid');
    if (grid) {
        grid.innerHTML = [
            ['Plate No.', d.plate_no],
            ['Vehicle ID', d.vehicle_id || d.db_id],
            ['Vehicle Details', vehicleName],
            ['Category', d.vehicle_category],
            ['Branch', d.branch_name],
            ['Last Maintenance Odometer', formatOdometer(d.last_maintenance_odometer)],
            ['Current Odometer', formatOdometer(d.current_odometer)],
            ['Business Unit', d.business_unit_display || d.business_unit]
        ].map(([label, value]) => buildRisDetailItem(label, value)).join('');
    }
    showModal('maintenanceModal');
}

function saveScheduledMaintenance() {
    const form = document.getElementById('maintenanceForm');
    const description = document.getElementById('maintenanceDescription')?.value.trim() || '';
    const scheduleDate = document.getElementById('maintenanceScheduleDate')?.value.trim() || '';
    if (!description) {
        Swal.fire({icon:'warning', title:'Required', text:'Please enter the maintenance to be done.', confirmButtonColor:'#07b83f'});
        return;
    }
    if (!scheduleDate) {
        Swal.fire({icon:'warning', title:'Required', text:'Please select the schedule date.', confirmButtonColor:'#07b83f'});
        return;
    }
    const formData = new FormData(form);
    fetch(window.location.href, {method:'POST', body:formData})
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire({icon:'error', title:'Error', text:data.message || 'Failed to save scheduled maintenance.', confirmButtonColor:'#dc3545'});
                return;
            }
            hideModal('maintenanceModal');
            Swal.fire({icon:'success', title:'Saved', text:data.message || 'Scheduled maintenance saved successfully.', confirmButtonColor:'#07b83f'}).then(() => window.location.reload());
        })
        .catch(() => Swal.fire({icon:'error', title:'Error', text:'Failed to save scheduled maintenance. Please try again.', confirmButtonColor:'#dc3545'}));
}

function openRisModal(row) {
    const d = getRowData(row);
    const vehicleName = [d.make_brand, d.vehicle_type].filter(Boolean).join(' - ');
    setValue('risVehicleDbId', d.db_id);
    setValue('risVehicleCode', d.vehicle_id);
    setValue('risVehicleName', vehicleName);
    setValue('risPlateNo', d.plate_no);
    setValue('risCategory', d.vehicle_category);
    setValue('risMakeBrand', d.make_brand);
    setValue('risVehicleType', d.vehicle_type);
    setValue('risClassification', d.classification);
    setValue('risBodyType', d.body_type);
    setValue('risColor', d.color);
    setValue('risFuelType', d.type_of_fuel);
    setValue('risYearModel', d.year_model);
    setValue('risSeries', d.series);
    setValue('risPassengerCapacity', d.passenger_capacity);
    setValue('risMaxPower', d.max_power_kw);
    setValue('risLtoCrNo', d.lto_cr_no);
    setValue('risDateRegistration', d.date_registration);
    setValue('risFileNo', d.file_no);
    setValue('risEngineNo', d.engine_no);
    setValue('risChassisNo', d.chassis_no);
    setValue('risVin', d.vin);
    setValue('risGrossWeight', d.gross_weight);
    setValue('risNetWeight', d.net_weight);
    setValue('risYearRebuilt', d.year_rebuilt);
    setValue('risPistonDisplacement', d.piston_displacement);

    const grid = document.getElementById('risVehicleDetailsGrid');
    if (grid) {
        grid.innerHTML = [
            ['Plate No.', d.plate_no], ['Make/Brand', d.make_brand], ['Vehicle Type', d.vehicle_type],
            ['Vehicle Category', d.vehicle_category], ['Branch', d.branch_name], ['Vehicle Owner / Assigned To', d.vehicle_owner], ['Business Unit', d.business_unit_display], ['Last Maintenance Odometer', formatOdometer(d.last_maintenance_odometer)], ['Current Odometer', formatOdometer(d.current_odometer)],
            ['Classification', d.classification], ['Body Type', d.body_type], ['Color', d.color],
            ['Type of Fuel', d.type_of_fuel], ['Year Model', d.year_model], ['Series', d.series],
            ['Passenger Capacity', d.passenger_capacity], ['Max Power (KW)', d.max_power_kw], ['LTO CR No.', d.lto_cr_no],
            ['Date of Registration', d.date_registration], ['File No.', d.file_no], ['Engine No.', d.engine_no],
            ['Chassis No.', d.chassis_no], ['VIN', d.vin], ['Gross Weight', d.gross_weight],
            ['Net Weight', d.net_weight], ['Year Rebuilt', d.year_rebuilt], ['Piston Displacement', d.piston_displacement]
        ].map(([label, value]) => buildRisDetailItem(label, value)).join('');
    }
    setValue('risConcerns', '');
    setValue('risEndorsedBy', '');
    setValue('risDate', today());
    clearSignature();
    showModal('risModal');
    setTimeout(resizeSignatureCanvas, 250);
}

function buildRisPrintHtml(data) {
    return `<!doctype html><html><head><title>${escapeHtml(data.ris_number || 'RIS')}</title><style>
    body{font-family:Arial,sans-serif;margin:24px;color:#111}.header{text-align:center;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:18px}h2{margin:0 0 4px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}.box{border:1px solid #222;padding:8px}.label{font-size:12px;color:#555;margin-bottom:3px}.value{font-weight:700;min-height:18px}.concern{border:1px solid #222;padding:10px;min-height:90px;white-space:pre-wrap}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:50px}.sig{text-align:center;border-top:1px solid #111;padding-top:6px}.sig img{max-width:220px;max-height:90px;display:block;margin:-95px auto 8px;object-fit:contain}@media print{button{display:none}}
    </style></head><body><div class="header"><h2>Request for Inspection Slip</h2><div>RIS No.: <strong>${escapeHtml(data.ris_number)}</strong></div></div><div class="meta">
    <div class="box"><div class="label">Date Requested</div><div class="value">${escapeHtml(data.date_requested)}</div></div>
    <div class="box"><div class="label">Status</div><div class="value">For Vehicle Endorsement</div></div>
    <div class="box"><div class="label">Vehicle ID</div><div class="value">${escapeHtml(data.vehicle_id)}</div></div>
    <div class="box"><div class="label">Plate No.</div><div class="value">${escapeHtml(data.plate_no)}</div></div>
    <div class="box"><div class="label">Vehicle Details</div><div class="value">${escapeHtml(data.vehicle_details)}</div></div>
    <div class="box"><div class="label">Category</div><div class="value">${escapeHtml(data.vehicle_category)}</div></div>
    </div><div class="label">Concern/s</div><div class="concern">${escapeHtml(data.concerns)}</div><div class="signatures"><div class="sig">${data.endorsed_signature ? `<img src="${data.endorsed_signature}" alt="Signature">` : ``}Endorsed by: ${escapeHtml(data.endorsed_by)}</div><div class="sig">Received by Motorpool</div></div><script>window.onload=function(){window.print();};<\/script></body></html>`;
}

function sendAndPrintRis() {
    const form = document.getElementById('risForm');
    const concerns = document.getElementById('risConcerns')?.value.trim() || '';
    const endorsedBy = document.getElementById('risEndorsedBy')?.value.trim() || '';
    if (!concerns) { Swal.fire({icon:'warning', title:'Required', text:'Please enter concern/s.', confirmButtonColor:'#07b83f'}); return; }
    if (!endorsedBy) { Swal.fire({icon:'warning', title:'Required', text:'Please enter endorsed by.', confirmButtonColor:'#07b83f'}); return; }
    const formData = new FormData(form);
    fetch(window.location.href, {method:'POST', body:formData})
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire({icon:'error', title:'Error', text:data.message || 'Failed to send RIS request.', confirmButtonColor:'#dc3545'});
                return;
            }
            hideModal('risModal');
            Swal.fire({icon:'success', title:'Request Sent', text:'RIS request successfully sent to Motorpool account.', confirmButtonColor:'#07b83f', showCancelButton:true, confirmButtonText:'Print RIS', cancelButtonText:'Close'}).then(result => {
                if (result.isConfirmed) {
                    const w = window.open('', '_blank');
                    if (w) { w.document.open(); w.document.write(buildRisPrintHtml(data)); w.document.close(); }
                }
                window.location.reload();
            });
        })
        .catch(() => Swal.fire({icon:'error', title:'Error', text:'Failed to send RIS request. Please try again.', confirmButtonColor:'#dc3545'}));
}

let signatureCanvas = null, signatureCtx = null, signatureDrawing = false, signatureHasInk = false;
function resizeSignatureCanvas() {
    signatureCanvas = document.getElementById('signaturePad');
    if (!signatureCanvas) return;
    const rect = signatureCanvas.getBoundingClientRect();
    const ratio = window.devicePixelRatio || 1;
    signatureCanvas.width = Math.max(1, Math.floor(rect.width * ratio));
    signatureCanvas.height = Math.max(1, Math.floor(rect.height * ratio));
    signatureCtx = signatureCanvas.getContext('2d');
    signatureCtx.setTransform(ratio, 0, 0, ratio, 0, 0);
    signatureCtx.lineWidth = 2;
    signatureCtx.lineCap = 'round';
    signatureCtx.strokeStyle = '#111827';
}
function sigPoint(e) {
    const rect = signatureCanvas.getBoundingClientRect();
    const t = e.touches && e.touches[0] ? e.touches[0] : e;
    return {x:t.clientX - rect.left, y:t.clientY - rect.top};
}
function startSig(e) { if (!signatureCtx) resizeSignatureCanvas(); signatureDrawing = true; const p = sigPoint(e); signatureCtx.beginPath(); signatureCtx.moveTo(p.x,p.y); e.preventDefault(); }
function moveSig(e) { if (!signatureDrawing || !signatureCtx) return; const p = sigPoint(e); signatureCtx.lineTo(p.x,p.y); signatureCtx.stroke(); signatureHasInk = true; e.preventDefault(); }
function endSig() { signatureDrawing = false; }
function initSignaturePad() {
    signatureCanvas = document.getElementById('signaturePad');
    if (!signatureCanvas || signatureCanvas.dataset.ready === '1') return;
    signatureCanvas.dataset.ready = '1';
    signatureCanvas.addEventListener('mousedown', startSig);
    signatureCanvas.addEventListener('mousemove', moveSig);
    window.addEventListener('mouseup', endSig);
    signatureCanvas.addEventListener('touchstart', startSig, {passive:false});
    signatureCanvas.addEventListener('touchmove', moveSig, {passive:false});
    window.addEventListener('touchend', endSig);
}
function openSignatureModal() { showModal('signatureModal'); setTimeout(() => { resizeSignatureCanvas(); initSignaturePad(); }, 200); }
function cancelSignatureModal() { hideModal('signatureModal'); }
function clearSignaturePadOnly() { if (!signatureCtx) resizeSignatureCanvas(); if (signatureCanvas && signatureCtx) signatureCtx.clearRect(0,0,signatureCanvas.width,signatureCanvas.height); signatureHasInk=false; }
function saveSignatureFromModal() {
    if (!signatureCanvas || !signatureHasInk) { Swal.fire({icon:'warning', title:'No Signature', text:'Please draw the signature first.', confirmButtonColor:'#07b83f'}); return; }
    const dataUrl = signatureCanvas.toDataURL('image/png');
    const input = document.getElementById('signatureInput');
    const img = document.getElementById('signaturePreviewImage');
    const empty = document.getElementById('signaturePreviewEmpty');
    const removeBtn = document.getElementById('removeSignatureBtn');
    if (input) input.value = dataUrl;
    if (img) { img.src = dataUrl; img.classList.remove('d-none'); }
    if (empty) empty.classList.add('d-none');
    if (removeBtn) removeBtn.classList.remove('d-none');
    hideModal('signatureModal');
}
function clearSignature() { setValue('signatureInput',''); const img = document.getElementById('signaturePreviewImage'); const empty = document.getElementById('signaturePreviewEmpty'); const removeBtn = document.getElementById('removeSignatureBtn'); if (img) { img.src=''; img.classList.add('d-none'); } if (empty) empty.classList.remove('d-none'); if (removeBtn) removeBtn.classList.add('d-none'); }
function removeSavedSignature() { clearSignature(); clearSignaturePadOnly(); }

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

function updateVehicleOwnerOptions() {
    const branchSelect = document.getElementById('branch_id');
    const ownerInput = document.getElementById('vehicle_owner');
    const hint = document.getElementById('cuencaOwnerHint');
    if (!branchSelect || !ownerInput) return;
    const selected = branchSelect.options[branchSelect.selectedIndex];
    const branchName = selected ? (selected.getAttribute('data-branch-name') || selected.textContent || '') : '';
    const isCuenca = branchName.toLowerCase().includes('cuenca');
    if (isCuenca) {
        ownerInput.setAttribute('list', 'cuencaVehicleOwnerOptions');
        if (hint) hint.classList.remove('d-none');
    } else {
        ownerInput.removeAttribute('list');
        if (hint) hint.classList.add('d-none');
    }
}

function openVehicleModal() {
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicleFormAction').value = 'add_vehicle';
    document.getElementById('vehicle_db_id').value = '';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-truck-front me-2"></i>Add Vehicle Profile';
    updateVehicleOwnerOptions();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleModal')).show();
}

function fillVehicleForm(data) {
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicleFormAction').value = 'edit_vehicle';
    document.getElementById('vehicle_db_id').value = data.db_id || '';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Vehicle Profile';

    const fields = ['branch_id','vehicle_owner','business_unit','plate_no','make_brand','vehicle_type','vehicle_category','classification','body_type','color','type_of_fuel','year_model','current_odometer','series','passenger_capacity','max_power_kw','lto_cr_no','date_registration','file_no','engine_no','chassis_no','vin','gross_weight','net_weight','year_rebuilt','piston_displacement','or_no','reg_date','next_renewal'];
    fields.forEach(field => {
        const el = document.getElementById(field);
        if (el) el.value = data[field] || '';
    });
    if (document.getElementById('business_unit') && !document.getElementById('business_unit').value) {
        document.getElementById('business_unit').value = data.business_unit_display || '';
    }
    updateVehicleOwnerOptions();
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
    motorpoolShowModalFromCurrent('vehicleModal', {parentModalId:'vehicleDetailsModal'});
}

function parseJson(value, fallback) {
    try { return JSON.parse(value || ''); } catch (e) { return fallback; }
}

function formatOdometer(value) {
    const raw = (value || '').toString().replace(/,/g, '').trim();
    if (!raw) return 'N/A';
    const num = Number(raw);
    if (!Number.isNaN(num)) return num.toLocaleString(undefined, {maximumFractionDigits: 0}) + ' km';
    return value;
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
        ['Branch', d.branch_name], ['Vehicle Owner / Assigned To', d.vehicle_owner], ['Business Unit', d.business_unit_display || d.business_unit], ['Last Maintenance Odometer', formatOdometer(d.last_maintenance_odometer)], ['Current Odometer', formatOdometer(d.current_odometer)], ['Plate No.', d.plate_no],
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
    renderPaymentHistory(d);
    motorpoolShowModalFromCurrent('vehicleDetailsModal');
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
        const parsed = parseJson(rawValue, null);
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

function formatPeso(value) {
    return formatPesoV41(value);
}


function renderPartsTableV23(rows, options) {
    if (!rows || !rows.length) return '';

    options = options || {};
    const showCosts = !!options.showCosts;

    // Default view is still the clean Repair History table.
    // Detailed Workflow History passes showCosts:true so audit details stay complete.
    const bodyRows = rows.map(function (part) {
        let row = '<tr>'
            + '<td>' + escapeHtml(part.quantity || '') + '</td>'
            + '<td>' + escapeHtml(part.item || '') + '</td>'
            + '<td>' + escapeHtml(part.description || '') + '</td>'
            + '<td>' + escapeHtml(part.specification || '') + '</td>';

        if (showCosts) {
            row += '<td>' + escapeHtml(formatPesoV41(part.unit_cost || '')) + '</td>'
                + '<td>' + escapeHtml(formatPesoV41(part.estimated_cost || '')) + '</td>'
                + '<td>' + escapeHtml(formatPesoV41(part.repair_cost || '')) + '</td>'
                + '<td>' + escapeHtml(part.cost_source || '') + '</td>';
        }

        return row + '</tr>';
    }).join('');

    return '<div class="table-responsive parts-replaced-mini-table-wrap mt-2 mb-2">'
        + '<table class="table table-bordered table-sm align-middle mb-0 parts-replaced-mini-table">'
        + '<thead><tr>'
        + '<th>Quantity</th>'
        + '<th>Item</th>'
        + '<th>Description</th>'
        + '<th>Specification</th>'
        + (showCosts ? '<th>Unit Cost</th><th>Estimated Cost</th><th>Repair Cost</th><th>Source</th>' : '')
        + '</tr></thead><tbody>'
        + bodyRows
        + '</tbody>'
        + '</table></div>';
}

function extractGrandTotalFromCostSummaryV45(summary, partsText, repairCost) {
    const candidates = [];
    function addCandidate(value) {
        value = String(value || '').replace(/[₱,]/g, '').trim();
        if (value !== '' && !isNaN(Number(value))) candidates.push(Number(value));
    }

    String(summary || '').split(/\n+/).forEach(function(line) {
        const text = String(line || '').trim();
        const match = text.match(/grand\s*total\s*:\s*₱?\s*([0-9,]+(?:\.\d+)?)/i);
        if (match) addCandidate(match[1]);
    });
    if (candidates.length) return formatPesoV41(candidates[candidates.length - 1].toFixed(2));

    let total = 0;
    let hasCost = false;
    const parts = parsePartsReplacedRowsV21(partsText || '');
    parts.forEach(function(part) {
        const estimated = String(part.estimated_cost || '').replace(/[₱,]/g, '').trim();
        const computed = computeEstimatedCostFromQtyAndUnitV44(part.quantity || '', part.unit_cost || '');
        const value = estimated || computed;
        if (value !== '' && !isNaN(Number(value))) {
            total += Number(value);
            hasCost = true;
        }
    });

    const repair = String(repairCost || '').replace(/[₱,]/g, '').trim();
    if (repair !== '' && !isNaN(Number(repair))) {
        total += Number(repair);
        hasCost = true;
    }

    if (!hasCost) return '₱0.00';
    return formatPesoV41(total.toFixed(2));
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
        if (part.cost_source) line += ' | Source: ' + part.cost_source;
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
    const allRepairLogsDone = rows.every(function (row) {
        const statusText = String(row.status || '').toLowerCase().trim();
        return statusText.includes('done') || statusText.includes('complete');
    });

    const progressSeparator = allRepairLogsDone
        ? ''
        : '<div class="repair-workflow-table-progress" aria-hidden="true"><span></span></div>';

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
        + '</tbody></table></div>'
        + progressSeparator;
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
                : renderPartsTableV23(row.parts_rows, { showCosts: true });
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
        + '<thead><tr>'
        + '<th>Description</th>'
        + '<th>Repair</th>'
        + '<th>Cost</th>'
        + '</tr></thead><tbody>'
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
    const normalizedStageForDetails = normalizeWorkflowStatus(workflowStage || '');
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
        if (rows.length) html.push(renderPartsTableV23(rows, { is_for_release: normalizedStageForDetails === 'For Release', showCosts: true }));
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

        // Hide the separate Miscellaneous Details table ONLY in For Release.
        // For Release should still show Miscellaneous Cost and Grand Total in the
        // Cost Summary table, but not the detailed Description / Repair / Cost table.
        const hideMiscellaneousDetailsTable = normalizedStageForDetails === 'For Release';

        if (parsed.miscItems.length && !hideMiscellaneousDetailsTable) {
            html.push(renderMiscellaneousTableV50(parsed.miscItems));
        }
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

        if (lower === 'cost summary:') {
            flushParts();
            flushRepairProgress();
            flushQualityCheckRows();
            flushRepairsToMakeRows();
            captureCostSummaryRows = true;
            costSummaryBuffer.push(current);
            return;
        }

        if (isCostSummaryLineV50(current)) {
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

let motorpoolRepairReportFilteredRows = [];
let motorpoolRepairReportTrendChart = null;
let motorpoolRepairReportBranchChart = null;

function motorpoolReportNumber(value) {
    const raw = String(value ?? '').replace(/[₱,\s]/g, '');
    const parsed = parseFloat(raw);
    return Number.isFinite(parsed) ? parsed : 0;
}

function motorpoolReportPeso(value) {
    const amount = motorpoolReportNumber(value);
    return '₱' + amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function motorpoolReportDateValue(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    return raw.substring(0, 10);
}

function motorpoolReportStartOfWeek(dateValue) {
    const date = new Date(dateValue + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return ['', ''];
    const day = date.getDay();
    const diffToMonday = day === 0 ? -6 : 1 - day;
    const start = new Date(date);
    start.setDate(date.getDate() + diffToMonday);
    const end = new Date(start);
    end.setDate(start.getDate() + 6);
    return [start.toISOString().slice(0, 10), end.toISOString().slice(0, 10)];
}

function motorpoolReportCleanText(value) {
    return String(value || '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function motorpoolPopulateRepairReportBranchFilter() {
    const select = document.getElementById('repairReportBranchFilter');
    if (!select || select.dataset.loaded === '1') return;
    const branches = {};
    (Array.isArray(motorpoolRepairReportRows) ? motorpoolRepairReportRows : []).forEach(row => {
        const name = String(row.branch_name || '').trim() || 'No Branch';
        branches[name] = name;
    });
    Object.keys(branches).sort().forEach(name => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        select.appendChild(option);
    });
    select.dataset.loaded = '1';
}

function motorpoolRepairReportToggleFilters() {
    const type = document.getElementById('repairReportFilterType')?.value || 'monthly';
    const singleWrap = document.getElementById('repairReportSingleDateWrap');
    const monthWrap = document.getElementById('repairReportMonthWrap');
    const startWrap = document.getElementById('repairReportStartWrap');
    const endWrap = document.getElementById('repairReportEndWrap');

    if (singleWrap) singleWrap.classList.toggle('d-none', !(type === 'daily' || type === 'weekly'));
    if (monthWrap) monthWrap.classList.toggle('d-none', type !== 'monthly');
    if (startWrap) startWrap.classList.toggle('d-none', type !== 'custom');
    if (endWrap) endWrap.classList.toggle('d-none', type !== 'custom');
}

function motorpoolRepairReportDefaultDates() {
    const todayValue = new Date().toISOString().slice(0, 10);
    const monthValue = todayValue.slice(0, 7);
    const dateInput = document.getElementById('repairReportDate');
    const monthInput = document.getElementById('repairReportMonth');
    const startInput = document.getElementById('repairReportStartDate');
    const endInput = document.getElementById('repairReportEndDate');
    if (dateInput && !dateInput.value) dateInput.value = todayValue;
    if (monthInput && !monthInput.value) monthInput.value = monthValue;
    if (startInput && !startInput.value) startInput.value = monthValue + '-01';
    if (endInput && !endInput.value) endInput.value = todayValue;
}

function motorpoolGetRepairReportRange() {
    const type = document.getElementById('repairReportFilterType')?.value || 'monthly';
    const selectedDate = document.getElementById('repairReportDate')?.value || new Date().toISOString().slice(0, 10);
    const selectedMonth = document.getElementById('repairReportMonth')?.value || new Date().toISOString().slice(0, 7);
    if (type === 'all') return {type, start: '', end: '', label: 'All repair records'};
    if (type === 'daily') return {type, start: selectedDate, end: selectedDate, label: 'Daily report for ' + selectedDate};
    if (type === 'weekly') {
        const [start, end] = motorpoolReportStartOfWeek(selectedDate);
        return {type, start, end, label: 'Weekly report from ' + start + ' to ' + end};
    }
    if (type === 'custom') {
        const start = document.getElementById('repairReportStartDate')?.value || '';
        const end = document.getElementById('repairReportEndDate')?.value || '';
        return {type, start, end, label: 'Custom report from ' + (start || 'start') + ' to ' + (end || 'end')};
    }
    const start = selectedMonth + '-01';
    const temp = new Date(start + 'T00:00:00');
    temp.setMonth(temp.getMonth() + 1);
    temp.setDate(0);
    const end = temp.toISOString().slice(0, 10);
    return {type, start, end, label: 'Monthly report for ' + selectedMonth};
}

function openMotorpoolRepairReportModal() {
    motorpoolRepairReportDefaultDates();
    motorpoolPopulateRepairReportBranchFilter();
    motorpoolRepairReportToggleFilters();
    motorpoolApplyRepairReportFilter();
    const modalEl = document.getElementById('motorpoolRepairReportModal');
    if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function motorpoolApplyRepairReportFilter() {
    const range = motorpoolGetRepairReportRange();
    const branch = String(document.getElementById('repairReportBranchFilter')?.value || '').trim().toLowerCase();
    const search = String(document.getElementById('repairReportSearch')?.value || '').trim().toLowerCase();

    motorpoolRepairReportFilteredRows = (Array.isArray(motorpoolRepairReportRows) ? motorpoolRepairReportRows : []).filter(row => {
        const date = motorpoolReportDateValue(row.repair_date || row.created_at);
        if (range.type !== 'all') {
            if (range.start && date < range.start) return false;
            if (range.end && date > range.end) return false;
        }

        const rowBranch = String(row.branch_name || '').trim().toLowerCase() || 'no branch';
        if (branch && rowBranch !== branch) return false;

        if (search) {
            const haystack = [
                row.ris_number, row.vehicle_id, row.vehicle_details, row.plate_no,
                row.branch_name, row.business_unit, row.vehicle_category, row.mechanic,
                row.repairs_done, row.parts_replaced, row.status
            ].map(v => motorpoolReportCleanText(v).toLowerCase()).join(' ');
            if (!haystack.includes(search)) return false;
        }
        return true;
    });

    motorpoolRenderRepairReport(range);
}

function motorpoolRenderRepairReport(range) {
    const rows = motorpoolRepairReportFilteredRows;
    const totalRepair = rows.reduce((sum, row) => sum + motorpoolReportNumber(row.repair_cost_amount), 0);
    const totalItems = rows.reduce((sum, row) => sum + motorpoolReportNumber(row.item_cost_amount), 0);
    const totalMisc = rows.reduce((sum, row) => sum + motorpoolReportNumber(row.misc_cost_amount), 0);
    const grandTotal = rows.reduce((sum, row) => sum + motorpoolReportNumber(row.grand_total_amount), 0);
    const vehicleSet = new Set(rows.map(row => String(row.vehicle_db_id || row.plate_no || row.vehicle_id || '')).filter(Boolean));
    const branchSet = new Set(rows.map(row => String(row.branch_name || '')).filter(Boolean));

    const cards = document.getElementById('repairReportSummaryCards');
    if (cards) {
        cards.innerHTML =
            '<div class="col-md-2"><div class="motorpool-report-card"><small>Grand Total Repairs</small><strong>' + escapeHtml(motorpoolReportPeso(grandTotal)) + '</strong></div></div>' +
            '<div class="col-md-2"><div class="motorpool-report-card"><small>Labor / Repair</small><strong>' + escapeHtml(motorpoolReportPeso(totalRepair)) + '</strong></div></div>' +
            '<div class="col-md-2"><div class="motorpool-report-card"><small>Items / Parts</small><strong>' + escapeHtml(motorpoolReportPeso(totalItems)) + '</strong></div></div>' +
            '<div class="col-md-2"><div class="motorpool-report-card"><small>Miscellaneous</small><strong>' + escapeHtml(motorpoolReportPeso(totalMisc)) + '</strong></div></div>' +
            '<div class="col-md-2"><div class="motorpool-report-card"><small>Total Repairs</small><strong>' + rows.length.toLocaleString() + '</strong></div></div>' +
            '<div class="col-md-2"><div class="motorpool-report-card"><small>Vehicles / Branches</small><strong>' + vehicleSet.size.toLocaleString() + ' / ' + branchSet.size.toLocaleString() + '</strong></div></div>';
    }

    const rangeText = document.getElementById('repairReportRangeText');
    if (rangeText) rangeText.textContent = range.label;
    const count = document.getElementById('repairReportRecordCount');
    if (count) count.textContent = rows.length.toLocaleString() + ' records';

    motorpoolRenderRepairReportTable(rows);
    motorpoolRenderRepairReportCharts(rows, range);
}

function motorpoolRenderRepairReportTable(rows) {
    const body = document.getElementById('repairReportTableBody');
    if (!body) return;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="14" class="text-center text-muted py-4">No repair records found for the selected filter.</td></tr>';
        return;
    }

    body.innerHTML = rows.map(row => {
        const vehicle = [row.vehicle_id, row.vehicle_details].filter(Boolean).join(' - ') || 'N/A';
        return '<tr>' +
            '<td>' + escapeHtml(motorpoolReportDateValue(row.repair_date || row.created_at) || 'N/A') + '</td>' +
            '<td>' + escapeHtml(row.ris_number || 'N/A') + '</td>' +
            '<td>' + escapeHtml(vehicle) + '</td>' +
            '<td>' + escapeHtml(row.plate_no || 'N/A') + '</td>' +
            '<td>' + escapeHtml(row.branch_name || 'No Branch') + '</td>' +
            '<td>' + escapeHtml(row.vehicle_category || 'N/A') + '</td>' +
            '<td>' + escapeHtml(row.mechanic || 'N/A') + '</td>' +
            '<td><div class="motorpool-report-detail-text">' + nl2brEscapeV38(row.repairs_done || 'N/A') + '</div></td>' +
            '<td><div class="motorpool-report-detail-text">' + renderPartsReplacedColumnsV21(row.parts_replaced || '', row.repair_cost_amount || '') + '</div></td>' +
            '<td class="text-end">' + escapeHtml(motorpoolReportPeso(row.repair_cost_amount)) + '</td>' +
            '<td class="text-end">' + escapeHtml(motorpoolReportPeso(row.item_cost_amount)) + '</td>' +
            '<td class="text-end">' + escapeHtml(motorpoolReportPeso(row.misc_cost_amount)) + '</td>' +
            '<td class="text-end fw-semibold text-success">' + escapeHtml(motorpoolReportPeso(row.grand_total_amount)) + '</td>' +
            '<td>' + escapeHtml(row.status || 'Completed') + '</td>' +
            '</tr>';
    }).join('');
}

function motorpoolGroupReportRows(rows, mode) {
    const grouped = {};
    rows.forEach(row => {
        let key = 'No Date';
        const date = motorpoolReportDateValue(row.repair_date || row.created_at);
        if (mode === 'branch') key = String(row.branch_name || '').trim() || 'No Branch';
        else if (mode === 'monthly') key = date ? date.slice(0, 7) : 'No Date';
        else key = date || 'No Date';
        grouped[key] = (grouped[key] || 0) + motorpoolReportNumber(row.grand_total_amount);
    });
    return Object.keys(grouped).sort().map(key => ({label: key, value: grouped[key]}));
}

function motorpoolRenderRepairReportCharts(rows, range) {
    if (typeof Chart === 'undefined') return;
    const trendCanvas = document.getElementById('repairReportTrendChart');
    const branchCanvas = document.getElementById('repairReportBranchChart');
    const trendMode = range.type === 'monthly' || range.type === 'all' ? 'monthly' : 'daily';
    const trendData = motorpoolGroupReportRows(rows, trendMode);
    const branchData = motorpoolGroupReportRows(rows, 'branch').sort((a, b) => b.value - a.value).slice(0, 10);

    if (motorpoolRepairReportTrendChart) motorpoolRepairReportTrendChart.destroy();
    if (motorpoolRepairReportBranchChart) motorpoolRepairReportBranchChart.destroy();

    if (trendCanvas) {
        motorpoolRepairReportTrendChart = new Chart(trendCanvas, {
            type: 'bar',
            data: {
                labels: trendData.map(item => item.label),
                datasets: [{ label: 'Grand Total', data: trendData.map(item => item.value), borderWidth: 1 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => motorpoolReportPeso(ctx.raw) } } },
                scales: { y: { beginAtZero: true, ticks: { callback: value => '₱' + Number(value).toLocaleString() } } }
            }
        });
    }

    if (branchCanvas) {
        motorpoolRepairReportBranchChart = new Chart(branchCanvas, {
            type: 'bar',
            data: {
                labels: branchData.map(item => item.label),
                datasets: [{ label: 'Grand Total', data: branchData.map(item => item.value), borderWidth: 1 }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => motorpoolReportPeso(ctx.raw) } } },
                scales: { x: { beginAtZero: true, ticks: { callback: value => '₱' + Number(value).toLocaleString() } } }
            }
        });
    }
}

function motorpoolPrintRepairReport() {
    motorpoolApplyRepairReportFilter();
    window.print();
}

function motorpoolCsvEscape(value) {
    const text = motorpoolReportCleanText(value);
    return '"' + text.replace(/"/g, '""') + '"';
}

function motorpoolExportRepairReportCsv() {
    motorpoolApplyRepairReportFilter();
    const rows = motorpoolRepairReportFilteredRows;
    const header = [
        'Date','RIS No','Vehicle ID','Vehicle Details','Plate No','Branch','Business Unit','Category','Mechanic',
        'Repairs Done','Parts / Items Used','Labor / Repair Cost','Items / Parts Cost','Miscellaneous Cost','Grand Total','Status'
    ];
    const lines = [header.map(motorpoolCsvEscape).join(',')];
    rows.forEach(row => {
        lines.push([
            motorpoolReportDateValue(row.repair_date || row.created_at),
            row.ris_number || '',
            row.vehicle_id || '',
            row.vehicle_details || '',
            row.plate_no || '',
            row.branch_name || '',
            row.business_unit || '',
            row.vehicle_category || '',
            row.mechanic || '',
            row.repairs_done || '',
            row.parts_replaced || '',
            motorpoolReportNumber(row.repair_cost_amount).toFixed(2),
            motorpoolReportNumber(row.item_cost_amount).toFixed(2),
            motorpoolReportNumber(row.misc_cost_amount).toFixed(2),
            motorpoolReportNumber(row.grand_total_amount).toFixed(2),
            row.status || ''
        ].map(motorpoolCsvEscape).join(','));
    });

    const blob = new Blob([lines.join('\n')], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const range = motorpoolGetRepairReportRange();
    link.href = url;
    link.download = 'motorpool_repair_report_' + (range.start || 'all') + '_' + (range.end || 'records') + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}


function renderRepairHistory(d) {
    const body = document.getElementById('repairHistoryBody');
    const grandWrap = document.getElementById('repairHistoryGrandTotalWrap');
    const histories = parseJson(d.repair_history, []);
    if (!histories.length) {
        if (grandWrap) grandWrap.innerHTML = '';
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No repair history found.</td></tr>';
        return;
    }

    let overallGrandTotal = 0;
    body.innerHTML = histories.map(item => {
        const attachment = item.attachment ? '<button type="button" class="btn btn-outline-success btn-sm" data-preview-file="' + escapeHtml(item.attachment) + '">View</button>' : 'N/A';
        const risNumber = escapeHtml(item.ris_number || '');

        // Prefer the server-computed total because it includes all repair/labor,
        // miscellaneous, and item/parts costs from every source, including branch source.
        const serverGrandTotal = motorpoolNumberValue(item.grand_total_amount || 0);
        const fallbackGrandTotalText = extractGrandTotalFromCostSummaryV45(item.cost_summary || '', item.parts_replaced || '', item.repair_cost || '');
        const rowGrandTotal = serverGrandTotal > 0 ? serverGrandTotal : motorpoolNumberValue(fallbackGrandTotalText || item.repair_cost || 0);
        overallGrandTotal += rowGrandTotal;
        const grandTotal = formatPesoV41(rowGrandTotal);

        return '<tr class="repair-history-click-row" data-repair-workflow-ris="' + risNumber + '" onclick="if (!event.target.closest(\'button, a, [data-preview-file]\')) { openRepairWorkflowModal(this.getAttribute(\'data-repair-workflow-ris\')); }" title="Click to view detailed repair workflow">'
            + '<td>' + escapeHtml(item.repair_date || '') + '</td>'
            + '<td>' + escapeHtml(item.ris_number || '') + '</td>'
            + '<td><div class="repair-history-text">' + nl2brEscapeV38(item.repairs_done || '') + '</div></td>'
            + '<td>' + renderPartsReplacedColumnsV21(item.parts_replaced || '', item.repair_cost || '') + '</td>'
            + '<td class="fw-semibold text-success">' + escapeHtml(grandTotal) + '</td>'
            + '<td>' + escapeHtml(item.mechanic || '') + '</td>'
            + '<td>' + attachment + '</td>'
            + '</tr>';
    }).join('');

    if (grandWrap) {
        grandWrap.innerHTML = '<div class="col-md-4">'
            + '<div class="payment-summary-card">'
            + '<div class="payment-summary-label">Grand Total Repairs</div>'
            + '<div class="payment-summary-value text-success">' + escapeHtml(formatPesoV41(overallGrandTotal)) + '</div>'
            + '<div class="small text-muted mt-1">All repair history totals combined</div>'
            + '</div>'
            + '</div>';
    }
}



function repairPaymentHistoryKey(item) {
    return String((item && item.ris_id) || '').trim() || String((item && item.ris_number) || '').trim();
}

function groupRepairPaymentHistoryByRis(payments) {
    const grouped = {};
    (Array.isArray(payments) ? payments : []).forEach(function(payment) {
        const key = repairPaymentHistoryKey(payment);
        if (!key) return;
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(payment);
    });
    return grouped;
}

function paymentHistoryStatusBadge(paid, balance) {
    paid = Number(paid || 0);
    balance = Number(balance || 0);
    if (paid > 0 && balance <= 0.009) return '<span class="badge bg-success">Fully Paid</span>';
    if (paid > 0) return '<span class="badge bg-warning text-dark">Partial</span>';
    return '<span class="badge bg-secondary">Unpaid</span>';
}

function paymentHistoryRecordList(records) {
    if (!Array.isArray(records) || !records.length) return '<span class="text-muted">No payment yet</span>';
    return records.map(function(payment) {
        const amount = formatPesoV41(motorpoolNumberValue(payment.amount_paid || 0));
        const date = escapeHtml(payment.payment_date || payment.created_at || '');
        const method = escapeHtml(payment.payment_method || 'Payment');
        const ref = payment.reference_no ? ' • Ref: ' + escapeHtml(payment.reference_no) : '';
        const checkNo = payment.check_number ? ' • Check: ' + escapeHtml(payment.check_number) : '';
        return '<div class="small mb-1"><strong>' + amount + '</strong> • ' + date + ' • ' + method + ref + checkNo + '</div>';
    }).join('');
}

function motorpoolNumberValue(value) {
    const raw = String(value ?? '').replace(/[₱,\s]/g, '');
    const numberValue = parseFloat(raw);
    return isNaN(numberValue) ? 0 : numberValue;
}

function renderPaymentHistory(d) {
    const body = document.getElementById('paymentHistoryBody');
    const cards = document.getElementById('paymentHistorySummaryCards');
    if (!body) return;

    const repairs = parseJson(d.repair_history, []);
    const payments = parseJson(d.repair_payment_history, []);
    const repairList = Array.isArray(repairs) ? repairs : [];
    const paymentList = Array.isArray(payments) ? payments : [];

    if (!repairList.length) {
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No repair history found.</td></tr>';
        if (cards) cards.innerHTML = '';
        return;
    }

    const groupedPayments = groupRepairPaymentHistoryByRis(paymentList);
    let overallRepairTotal = 0;
    let overallPaid = 0;

    const rows = repairList.map(function(item) {
        const key = repairPaymentHistoryKey(item);
        const records = groupedPayments[key] || [];
        const repairTotalText = extractGrandTotalFromCostSummaryV45(item.cost_summary || '', item.parts_replaced || '', item.repair_cost || '');
        const repairTotal = motorpoolNumberValue(repairTotalText || item.repair_cost || 0);
        let paid = 0;
        records.forEach(function(payment) {
            paid += motorpoolNumberValue(payment.amount_paid || 0);
        });
        const balance = Math.max(0, repairTotal - paid);
        overallRepairTotal += repairTotal;
        overallPaid += paid;

        return '<tr>'
            + '<td>' + escapeHtml(item.repair_date || '') + '</td>'
            + '<td>' + escapeHtml(item.ris_number || '') + '</td>'
            + '<td class="text-end fw-semibold">' + escapeHtml(formatPesoV41(repairTotal)) + '</td>'
            + '<td class="text-end text-success fw-semibold">' + escapeHtml(formatPesoV41(paid)) + '</td>'
            + '<td class="text-end fw-semibold">' + escapeHtml(formatPesoV41(balance)) + '</td>'
            + '<td>' + paymentHistoryStatusBadge(paid, balance) + '</td>'
            + '<td>' + paymentHistoryRecordList(records) + '</td>'
            + '</tr>';
    }).join('');

    const overallBalance = Math.max(0, overallRepairTotal - overallPaid);
    body.innerHTML = rows || '<tr><td colspan="7" class="text-center text-muted py-3">No payment history found.</td></tr>';

    if (cards) {
        cards.innerHTML =
            '<div class="col-md-4"><div class="summary-card"><span>Total Repair</span><strong>' + escapeHtml(formatPesoV41(overallRepairTotal)) + '</strong></div></div>' +
            '<div class="col-md-4"><div class="summary-card"><span>Total Paid</span><strong class="text-success">' + escapeHtml(formatPesoV41(overallPaid)) + '</strong></div></div>' +
            '<div class="col-md-4"><div class="summary-card"><span>Remaining Balance</span><strong class="' + (overallBalance <= 0.009 ? 'text-success' : 'text-danger') + '">' + escapeHtml(formatPesoV41(overallBalance)) + '</strong></div></div>';
    }
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
    if (value.includes('quality check')) return 'For Quality Check';
    if (value.includes('release') || value.includes('completed repair') || value.includes('completed')) return 'For Release';
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
        'For Quality Check',
        'For Release'
    ];

    let histories = parseJson(d.workflow_history, []);
    if (!Array.isArray(histories)) histories = [];

    if (!histories.length) {
        histories = buildFallbackTimelineFromRepairHistory(d);
    }

    const repairHistoriesForLookup = parseJson(d.repair_history, []);
    const grouped = {};
    histories.forEach(item => {
        const key = normalizeWorkflowStatus(item.workflow_status || item.status || '');
        if (!key) return;
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(item);
    });

    const partLookupV24 = buildWorkflowPartLookupV24(histories, '', Array.isArray(repairHistoriesForLookup) ? repairHistoriesForLookup : []);

    body.innerHTML = workflowStages.map(stage => {
        const rows = grouped[stage] || [];
        const isDone = rows.length > 0;
        let releaseAttachmentHtml = '';

        const cards = rows.length ? rows.map(item => {
            const processedBy = (item.processed_by_name || '').trim() || (item.processed_by ? 'User #' + item.processed_by : 'System');
            const details = formatWorkflowDetailsWithLookupV24(item.details || '', partLookupV24, stage);
            const risNo = item.ris_number ? ' • RIS No.: ' + escapeHtml(item.ris_number) : '';

            let attachmentHtml = '';
            if (stage === 'For Release') {
                if (!releaseAttachmentHtml) releaseAttachmentHtml = renderTimelineAttachmentButtons(item.attachment || '');
            } else {
                attachmentHtml = renderTimelineAttachmentButtons(item.attachment || '');
            }

            return '<div class="timeline-subrecord">'
                + '<div class="timeline-meta">' + escapeHtml(item.processed_at || '') + ' • Processed by: ' + escapeHtml(processedBy) + risNo + '</div>'
                + '<div class="timeline-details">' + details + '</div>'
                + attachmentHtml
                + '</div>';
        }).join('') : '<div class="timeline-meta text-muted">No record yet for this step.</div>';

        const releaseBottomAttachment = (stage === 'For Release' && releaseAttachmentHtml)
            ? '<div class="timeline-subrecord mt-2"><div class="fw-semibold mb-1">Attachment:</div>' + releaseAttachmentHtml + '</div>'
            : '';

        return '<div class="timeline-item ' + (isDone ? 'timeline-done' : 'timeline-pending') + '">'
            + '<div class="timeline-status">' + escapeHtml(stage) + '</div>'
            + cards
            + releaseBottomAttachment
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


function buildCanonicalReleaseRowsForRisV27(d, risNumber, releaseRows) {
    const wantedRis = String(risNumber || '').trim();
    const repairHistories = parseJson(d.repair_history, []);
    const matches = (Array.isArray(repairHistories) ? repairHistories : []).filter(function (item) {
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

        // Keep the release stage auditable. Repair History table stays clean,
        // but Detailed Workflow History must still show the full final cost
        // breakdown, especially Grand Total Cost.
        const releaseCostSummary = String(item.cost_summary || '').trim();
        if (releaseCostSummary) {
            details.push(releaseCostSummary.replace(/Grand\s+Total\s*:/i, 'Grand Total Cost:'));
        } else {
            const computedGrandTotal = extractGrandTotalFromCostSummaryV45('', item.parts_replaced || '', item.repair_cost || '');
            if (computedGrandTotal && computedGrandTotal !== '₱0.00') {
                details.push('Cost Summary:\nGrand Total Cost: ' + computedGrandTotal);
            }
        }

        return [{
            workflow_status: 'For Release',
            ris_number: item.ris_number || wantedRis,
            processed_at: item.created_at || item.repair_date || '',
            processed_by_name: 'Motorpool',
            details: details.join('\n'),
            attachment: item.attachment || ''
        }];
    }

    const rows = Array.isArray(releaseRows) ? releaseRows.slice() : [];
    if (!rows.length) return rows;

    // If repair history is not available yet, keep only the cleanest release row.
    // Prefer the row that came from motorpool_repair_release_proofs because it has
    // the correct parts used quantity and the release attachment.
    rows.sort(function (a, b) {
        function score(row) {
            const details = String(row.details || '').toLowerCase();
            let s = 0;
            if (details.includes('parts replaced / used')) s += 10;
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

function findReleaseAttachmentForRisV25(d, risNumber, rows) {
    const wantedRis = String(risNumber || '').trim();
    const candidates = [];

    (Array.isArray(rows) ? rows : []).forEach(function (item) {
        const file = String(item.attachment || item.release_attachment || item.proof_photo || '').trim();
        if (file) candidates.push(file);
    });

    const repairHistories = parseJson(d.repair_history, []);
    (Array.isArray(repairHistories) ? repairHistories : []).forEach(function (item) {
        if (wantedRis && String(item.ris_number || '').trim() !== wantedRis) return;
        const file = String(item.attachment || item.release_attachment || item.proof_photo || '').trim();
        if (file) candidates.push(file);
    });

    const unique = [];
    const seen = {};
    candidates.forEach(function (file) {
        if (!file || seen[file]) return;
        seen[file] = true;
        unique.push(file);
    });

    return unique.length ? unique[unique.length - 1] : '';
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
        'For Quality Check',
        'For Release'
    ];

    const histories = getWorkflowRowsForRis(d, risNumber);
    const repairHistoriesForLookup = parseJson(d.repair_history, []);
    const partLookupV24 = buildWorkflowPartLookupV24(histories, risNumber || '', Array.isArray(repairHistoriesForLookup) ? repairHistoriesForLookup : []);
    const grouped = {};

    histories.forEach(item => {
        const key = normalizeWorkflowStatus(item.workflow_status || item.status || '');
        if (!key) return;
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(item);
    });

    if (grouped['For Release'] && grouped['For Release'].length) {
        grouped['For Release'] = buildCanonicalReleaseRowsForRisV27(d, risNumber, grouped['For Release']);
    }

    body.innerHTML = workflowStages.map(stage => {
        const rows = grouped[stage] || [];
        const isDone = rows.length > 0;
        const releaseAttachmentFile = stage === 'For Release' ? findReleaseAttachmentForRisV25(d, risNumber, rows) : '';
        const releaseBottomAttachment = (stage === 'For Release' && releaseAttachmentFile)
            ? '<div class="timeline-subrecord mt-2"><div class="fw-semibold mb-1">Attachment:</div>' + renderTimelineAttachmentButtons(releaseAttachmentFile) + '</div>'
            : '';

        const cards = rows.length ? rows.map(item => {
            const attachmentHtml = (stage === 'For Release') ? '' : renderTimelineAttachmentButtons(item.attachment || '');
            const processedBy = (item.processed_by_name || '').trim() || (item.processed_by ? 'User #' + item.processed_by : 'System');
            const details = formatWorkflowDetailsWithLookupV24(item.details || '', partLookupV24, stage);
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
            + releaseBottomAttachment
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
    
    // IDAGDAG ITO - i-update ang progress bar
    const histories = getWorkflowRowsForRis(d, risNumber);
    updateWorkflowProgressFromStatuses(histories);
    
    motorpoolOpenChildModal('repairWorkflowModal', 'vehicleDetailsModal');
}

function openRenewRegistrationModal() {
    if (!currentVehicleData || !currentVehicleData.db_id) return;
    document.getElementById('renewRegistrationForm').reset();
    document.getElementById('renew_vehicle_db_id').value = currentVehicleData.db_id || '';
    document.getElementById('renew_vehicle_id').value = currentVehicleData.vehicle_id || '';
    document.getElementById('renew_plate_no').value = currentVehicleData.plate_no || '';
    motorpoolOpenChildModal('renewRegistrationModal', 'vehicleDetailsModal');
}

function buildMotorpoolUploadUrl(filename) {
    return '../uploads/motorpool/' + String(filename || '').split('/').map(encodeURIComponent).join('/');
}

let motorpoolPreviewParentModal = null;

function cleanupMotorpoolPreviewBackdrop() {
    const hasOpenModal = document.querySelector('.modal.show');
    if (hasOpenModal) {
        document.body.classList.add('modal-open');
        return;
    }
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
}

function markMotorpoolPreviewBackdrop() {
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.classList.add('motorpool-preview-backdrop');
    });
}

function previewMotorpoolFile(filename) {
    const ext = String(filename).split('.').pop().toLowerCase();
    const src = buildMotorpoolUploadUrl(filename);
    const previewModalEl = document.getElementById('motorpoolFilePreviewModal');
    if (!previewModalEl) return;

    const openedParent = motorpoolGetVisibleModal('motorpoolFilePreviewModal');
    motorpoolPreviewParentModal = openedParent ? openedParent.id : null;

    document.getElementById('previewDownloadLink').href = src;
    document.getElementById('previewDownloadLink').setAttribute('download', filename);

    if (['jpg','jpeg','png','webp','gif'].includes(ext)) {
        document.getElementById('previewContent').innerHTML = '<img src="' + src + '" alt="Preview">';
    } else if (ext === 'pdf') {
        document.getElementById('previewContent').innerHTML = '<embed src="' + src + '" type="application/pdf">';
    } else {
        document.getElementById('previewContent').innerHTML = '<div class="alert alert-light shadow-sm">Preview is not available for this file type.</div>';
    }

    document.body.classList.add('motorpool-preview-open');
    previewModalEl.addEventListener('shown.bs.modal', markMotorpoolPreviewBackdrop, {once:true});
    motorpoolShowModalFromCurrent('motorpoolFilePreviewModal', {
        parentModalId: motorpoolPreviewParentModal || '',
        delay: motorpoolPreviewParentModal ? 220 : 0
    });
}

document.getElementById('motorpoolFilePreviewModal')?.addEventListener('hidden.bs.modal', function () {
    document.getElementById('previewContent').innerHTML = '';
    document.body.classList.remove('motorpool-preview-open');
    motorpoolPreviewParentModal = null;
});


function motorpoolPrintEscape(value) {
    return escapeHtml(value || 'N/A');
}

function motorpoolPrintValue(d, key) {
    const value = (d && d[key] !== undefined && String(d[key]).trim() !== '') ? d[key] : 'N/A';
    return motorpoolPrintEscape(value);
}

function motorpoolIsImageFile(file) {
    return /\.(jpg|jpeg|png|webp|gif)$/i.test(String(file || '').split('?')[0]);
}

function motorpoolParseFileList(value) {
    const raw = String(value || '').trim();
    if (!raw) return [];
    if (raw.startsWith('[') || raw.startsWith('{')) {
        const parsed = parseJson(raw, []);
        const arr = Array.isArray(parsed) ? parsed : [parsed];
        return arr.map(function(item) {
            if (!item) return '';
            if (typeof item === 'string') return item;
            return item.filename || item.file || item.attachment || item.proof_photo || item.release_attachment || '';
        }).filter(Boolean);
    }
    return [raw];
}

function motorpoolPrintImageCard(file, label) {
    if (!file) return '';
    const url = buildMotorpoolUploadUrl(file);
    const safeFile = escapeHtml(file);
    const safeLabel = escapeHtml(label || 'Attachment');
    if (motorpoolIsImageFile(file)) {
        return '<div class="mp-print-image-card"><img src="' + url + '" alt="' + safeLabel + '" onerror="this.outerHTML=\'<div class=&quot;mp-print-file-only&quot;>Image not found</div>\';"><div class="mp-print-file">' + safeLabel + ': ' + safeFile + '</div></div>';
    }
    return '<div class="mp-print-image-card"><div class="mp-print-file-only">PDF / File Attachment<br>' + safeFile + '</div><div class="mp-print-file">' + safeLabel + '</div></div>';
}

function motorpoolPrintInfoItem(label, value) {
    const display = (value !== undefined && value !== null && String(value).trim() !== '') ? value : 'N/A';
    return '<div class="mp-print-item"><small>' + escapeHtml(label) + '</small><strong>' + escapeHtml(display) + '</strong></div>';
}

function motorpoolPrintRepairRows(d) {
    const repairList = parseJson(d.repair_history, []);
    if (!Array.isArray(repairList) || !repairList.length) {
        return '<tr><td colspan="6" style="text-align:center;color:#777;">No repair history found.</td></tr>';
    }
    return repairList.map(function(item) {
        const total = extractGrandTotalFromCostSummaryV45(item.cost_summary || '', item.parts_replaced || '', item.repair_cost || '');
        return '<tr>'
            + '<td>' + escapeHtml(item.repair_date || '') + '</td>'
            + '<td>' + escapeHtml(item.ris_number || '') + '</td>'
            + '<td>' + escapeHtml(item.repairs_done || '') + '</td>'
            + '<td>' + escapeHtml(partsReplacedTextForTimelineV21(item.parts_replaced || '')) + '</td>'
            + '<td>' + escapeHtml(total || item.repair_cost || '') + '</td>'
            + '<td>' + escapeHtml(item.mechanic || '') + '</td>'
            + '</tr>';
    }).join('');
}

function motorpoolPrintRegistrationRows(d) {
    const histories = parseJson(d.registration_history, []);
    const rows = [];
    if (d.or_no || d.reg_date || d.next_renewal || d.or_attachment) {
        rows.push({or_no:d.or_no, reg_date:d.reg_date, next_renewal:d.next_renewal, created_at:'Current Record'});
    }
    if (Array.isArray(histories)) histories.forEach(function(item){ rows.push(item); });
    if (!rows.length) return '<tr><td colspan="4" style="text-align:center;color:#777;">No registration history found.</td></tr>';
    return rows.map(function(item) {
        return '<tr><td>' + escapeHtml(item.or_no || '') + '</td><td>' + escapeHtml(item.reg_date || '') + '</td><td>' + escapeHtml(item.next_renewal || '') + '</td><td>' + escapeHtml(item.created_at || '') + '</td></tr>';
    }).join('');
}

function buildVehiclePrintHtml(d, index, total) {
    const vehicleName = [d.make_brand, d.vehicle_type].filter(Boolean).join(' - ') || 'N/A';
    const mainImage = d.vehicle_image ? '<img class="mp-print-main-img" src="' + buildMotorpoolUploadUrl(d.vehicle_image) + '" alt="Vehicle Image" onerror="this.outerHTML=\'<div class=&quot;mp-print-no-img&quot;>No Image</div>\';">' : '<div class="mp-print-no-img">No Vehicle Image</div>';
    const files = [];
    motorpoolParseFileList(d.vehicle_image).forEach(function(file){ files.push({file:file,label:'Vehicle Image'}); });
    motorpoolParseFileList(d.cr_vehicle_images).forEach(function(file, idx){ files.push({file:file,label:'CR / Vehicle Image ' + (idx + 1)}); });
    motorpoolParseFileList(d.or_attachment).forEach(function(file){ files.push({file:file,label:'OR Attachment'}); });
    const repairs = parseJson(d.repair_history, []);
    if (Array.isArray(repairs)) {
        repairs.forEach(function(item, idx) {
            motorpoolParseFileList(item.attachment || item.release_attachment || item.proof_photo || '').forEach(function(file) {
                files.push({file:file,label:'Repair Attachment ' + (idx + 1)});
            });
        });
    }
    const imageCards = files.length ? files.map(function(item){ return motorpoolPrintImageCard(item.file, item.label); }).join('') : '<div class="mp-print-file-only">No attachments uploaded.</div>';

    return '<div class="mp-print-page">'
        + '<div class="mp-print-header"><div class="mp-print-company"><img class="mp-print-logo" src="../Pictures/amgc3DLogo.png" alt="AMGC"><div class="mp-print-title"><h2>Motorpool Vehicle Profile</h2><small>AMGC Motorpool Management System</small></div></div><div class="mp-print-meta">Printed: ' + escapeHtml(new Date().toLocaleString()) + '<br>Record ' + (index || 1) + ' of ' + (total || 1) + '</div></div>'
        + '<div class="mp-print-hero"><div>' + mainImage + '</div><div><h1 class="mp-print-plate">' + motorpoolPrintValue(d, 'plate_no') + '</h1><div class="mp-print-sub">' + escapeHtml(vehicleName) + '</div><div class="mp-print-badges"><span class="mp-print-badge">Vehicle ID: ' + motorpoolPrintValue(d, 'vehicle_id') + '</span><span class="mp-print-badge">Branch: ' + motorpoolPrintValue(d, 'branch_name') + '</span><span class="mp-print-badge">Business Unit: ' + escapeHtml(d.business_unit_display || d.business_unit || 'N/A') + '</span></div></div></div>'
        + '<div class="mp-print-section"><div class="mp-print-section-title">Vehicle Information</div><div class="mp-print-grid">'
        + motorpoolPrintInfoItem('Vehicle Owner / Assigned To', d.vehicle_owner)
        + motorpoolPrintInfoItem('Status', d.status || 'Active')
        + motorpoolPrintInfoItem('Vehicle Category', d.vehicle_category)
        + motorpoolPrintInfoItem('Make / Brand', d.make_brand)
        + motorpoolPrintInfoItem('Vehicle Type', d.vehicle_type)
        + motorpoolPrintInfoItem('Classification', d.classification)
        + motorpoolPrintInfoItem('Body Type', d.body_type)
        + motorpoolPrintInfoItem('Color', d.color)
        + motorpoolPrintInfoItem('Fuel Type', d.type_of_fuel)
        + motorpoolPrintInfoItem('Year Model', d.year_model)
        + motorpoolPrintInfoItem('Series', d.series)
        + motorpoolPrintInfoItem('Passenger Capacity', d.passenger_capacity)
        + motorpoolPrintInfoItem('Last Maintenance Odometer', formatOdometer(d.last_maintenance_odometer))
        + motorpoolPrintInfoItem('Current Odometer', formatOdometer(d.current_odometer))
        + motorpoolPrintInfoItem('Max Power KW', d.max_power_kw)
        + '</div></div>'
        + '<div class="mp-print-section"><div class="mp-print-section-title">Registration and Technical Details</div><div class="mp-print-grid">'
        + motorpoolPrintInfoItem('LTO CR No.', d.lto_cr_no)
        + motorpoolPrintInfoItem('Date Registration', d.date_registration)
        + motorpoolPrintInfoItem('File No.', d.file_no)
        + motorpoolPrintInfoItem('Engine No.', d.engine_no)
        + motorpoolPrintInfoItem('Chassis No.', d.chassis_no)
        + motorpoolPrintInfoItem('VIN', d.vin)
        + motorpoolPrintInfoItem('Gross Weight', d.gross_weight)
        + motorpoolPrintInfoItem('Net Weight', d.net_weight)
        + motorpoolPrintInfoItem('Year Rebuilt', d.year_rebuilt)
        + motorpoolPrintInfoItem('Piston Displacement', d.piston_displacement)
        + motorpoolPrintInfoItem('OR No.', d.or_no)
        + motorpoolPrintInfoItem('Next Renewal', d.next_renewal)
        + '</div></div>'
        + '<div class="mp-print-section"><div class="mp-print-section-title">Registration History</div><table class="mp-print-table"><thead><tr><th>OR No.</th><th>Registration Date</th><th>Next Renewal</th><th>Encoded At</th></tr></thead><tbody>' + motorpoolPrintRegistrationRows(d) + '</tbody></table></div>'
        + '<div class="mp-print-section"><div class="mp-print-section-title">Repair History</div><table class="mp-print-table"><thead><tr><th>Repair Date</th><th>RIS No.</th><th>Repairs Done</th><th>Parts Replaced / Used</th><th>Grand Total</th><th>Mechanic</th></tr></thead><tbody>' + motorpoolPrintRepairRows(d) + '</tbody></table></div>'
        + '<div class="mp-print-section"><div class="mp-print-section-title">Pictures and Attachments</div><div class="mp-print-images">' + imageCards + '</div></div>'
        + '<div class="mp-print-footer"><span>Generated from Motorpool Vehicle Profile</span><span>Plate No.: ' + motorpoolPrintValue(d, 'plate_no') + '</span></div>'
        + '</div>';
}

function runMotorpoolVehiclePrint(rows) {
    rows = Array.isArray(rows) ? rows.filter(Boolean) : [];
    if (!rows.length) {
        if (typeof Swal !== 'undefined') Swal.fire({icon:'info', title:'No records to print', text:'No vehicle records matched the current selection.', confirmButtonColor:'#198754'});
        return;
    }
    const printArea = document.getElementById('motorpoolVehiclePrintArea');
    if (!printArea) return;
    const dataList = rows.map(getRowData);
    printArea.innerHTML = dataList.map(function(d, idx){ return buildVehiclePrintHtml(d, idx + 1, dataList.length); }).join('');
    setTimeout(function(){ window.print(); }, 250);
}

function printVehicleProfileFromRow(row) {
    if (!row) return;
    runMotorpoolVehiclePrint([row]);
}

function printCurrentVehicleProfile() {
    if (currentVehicleRow) {
        printVehicleProfileFromRow(currentVehicleRow);
        return;
    }
    if (currentVehicleData) {
        const printArea = document.getElementById('motorpoolVehiclePrintArea');
        printArea.innerHTML = buildVehiclePrintHtml(currentVehicleData, 1, 1);
        setTimeout(function(){ window.print(); }, 250);
    }
}

function printFilteredVehicleProfiles() {
    const rows = Array.from(document.querySelectorAll('#vehicleProfileTableBody tr.vehicle-click-row')).filter(function(row) {
        return row.style.display !== 'none';
    });
    runMotorpoolVehiclePrint(rows);
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

    document.querySelectorAll('.vehicle-click-row.js-view-vehicle').forEach(function(row) {
        row.style.cursor = 'pointer';
    });

    document.addEventListener('click', function(e) {
        const row = e.target.closest('.vehicle-click-row.js-view-vehicle');
        if (!row) return;
        if (e.target.closest('button, a, [data-preview-file], input, select, textarea, label')) return;
        e.preventDefault();
        viewVehicleDetails(row);
    });
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
        if (action === 'open-ris') {
            event.preventDefault();
            event.stopPropagation();
            openRisModal(actionEl.closest('tr'));
            return;
        }
        if (action === 'open-maintenance') {
            event.preventDefault();
            event.stopPropagation();
            openScheduleMaintenanceModal(actionEl.closest('tr'));
            return;
        }
        if (action === 'print-vehicle') {
            event.preventDefault();
            event.stopPropagation();
            printVehicleProfileFromRow(actionEl.closest('tr'));
            return;
        }
        if (action === 'print-filtered-vehicles') {
            event.preventDefault();
            event.stopPropagation();
            printFilteredVehicleProfiles();
            return;
        }
        if (action === 'print-current-vehicle') {
            event.preventDefault();
            event.stopPropagation();
            printCurrentVehicleProfile();
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
function updateWorkflowProgressFromStatuses(histories) {
    const workflowOrder = [
        'For Vehicle Endorsement',
        'For Assessment',
        'For Approval',
        'For Parts Completion',
        'For Repair',
        'On-going Repair',
        'For Quality Check',
        'For Release'
    ];

    if (!Array.isArray(histories)) histories = [];

    const normalizedRows = histories.map(item => {
        const status = normalizeWorkflowStatus(item.workflow_status || item.status || '');
        const details = String(item.details || item.remarks || item.description || '').toLowerCase();
        const processedAt = String(item.processed_at || item.created_at || item.updated_at || '');
        return { item, status, details, processedAt };
    }).filter(row => row.status);

    const hasStatus = (status) => normalizedRows.some(row => row.status === status);

    const hasReleaseProof = normalizedRows.some(row => {
        const text = row.details;
        return row.status === 'For Release' && (
            /ready for release|quality check completed|released|received by|completion photo|checked\s*&?\s*received|repair is ready for release/i.test(text) ||
            String(row.item.attachment || '').trim() !== ''
        );
    });

    const hasReleaseStatus = hasStatus('For Release');

    /*
     * IMPORTANT FIX:
     * If the RIS already has a valid For Release row, do not let older
     * On-going Repair history pull the progress bar backward.
     * This happened because workflow_history keeps previous stages too.
     */
    const hasOngoingRepairLog = normalizedRows.some(row => {
        const text = row.details;
        const isRepairStage = row.status === 'For Repair' || row.status === 'On-going Repair';
        const saysOngoing = /\bongoing\b|\bon-going\b|\bon going\b/.test(text);
        const saysDone = /\bdone\b|all repair logs completed|completed repair|quality check completed|ready for release|released|received by|repair is ready for release/.test(text);
        return !hasReleaseStatus && isRepairStage && saysOngoing && !saysDone;
    });

    let currentStatus = '';

    if (hasReleaseProof || hasReleaseStatus) {
        currentStatus = 'For Release';
    } else if (hasOngoingRepairLog) {
        currentStatus = 'On-going Repair';
    } else if (hasStatus('For Quality Check')) {
        currentStatus = 'For Quality Check';
    } else if (hasStatus('On-going Repair')) {
        currentStatus = 'On-going Repair';
    } else {
        for (let i = workflowOrder.length - 1; i >= 0; i--) {
            if (hasStatus(workflowOrder[i])) {
                currentStatus = workflowOrder[i];
                break;
            }
        }
    }

    let currentIndex = workflowOrder.indexOf(currentStatus);
    if (currentIndex < 0) currentIndex = -1;

    const steps = document.querySelectorAll('#repairWorkflowModal .step');

    /*
     * The fill line should not make For Release look completed while the current
     * stage is still On-going Repair. Use the active step position only.
     */
    const progressPercent = currentIndex >= 0
        ? ((currentIndex + 1) / workflowOrder.length) * 100
        : 0;

    let fillLine = document.querySelector('#repairWorkflowModal .progress-fill-line');
    if (!fillLine && steps.length) {
        const stepsContainer = document.querySelector('#repairWorkflowModal .progress-steps');
        if (stepsContainer) {
            fillLine = document.createElement('div');
            fillLine.className = 'progress-fill-line';
            stepsContainer.style.position = 'relative';
            stepsContainer.insertBefore(fillLine, stepsContainer.firstChild);
        }
    }

    const isWorkflowDone = currentStatus === 'For Release' && hasReleaseProof;

    if (fillLine) {
        if (isWorkflowDone) {
            fillLine.style.display = 'none';
            fillLine.style.width = '0%';
        } else {
            fillLine.style.display = '';
            fillLine.style.width = `${progressPercent}%`;
        }
    }

    steps.forEach((step, index) => {
        step.classList.remove('completed', 'active', 'pending');

        if (isWorkflowDone) {
            step.classList.add('completed');
        } else if (index < currentIndex) {
            step.classList.add('completed');
        } else if (index === currentIndex) {
            step.classList.add('active');
        } else {
            step.classList.add('pending');
        }
    });
}
function showProfileModal() {
    const modalEl = document.getElementById('profileModal');
    if (!modalEl) {
        logout();
        return;
    }
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}


/* =========================================================
   MOTORPOOL SIDEBAR ACTIVE FUNCTION + AUTO SCROLL
   Pattern copied from chartofaccounts.php sidebar behavior.
   ========================================================= */
(function () {
    function setArrowState(arrowElement, isOpen) {
        if (!arrowElement) return;
        arrowElement.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
        arrowElement.style.willChange = isOpen ? 'transform' : '';
    }

    window.toggleSidebarDropdown = function (event, targetId) {
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
                scrollActiveSidebarLinkIntoView();
            }, 80);
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

        setTimeout(scrollActiveSidebarLinkIntoView, 120);
        return false;
    };

    window.toggleSidebar = function () {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
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
                        setTimeout(function () { overlay.remove(); }, 250);
                    });
                }
                setTimeout(function () { overlay.classList.add('active'); }, 10);
            } else if (overlay) {
                overlay.classList.remove('active');
                setTimeout(function () { overlay.remove(); }, 250);
            }
        } else {
            const wasCollapsed = sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed');
            mainContent?.classList.toggle('expanded', sidebar.classList.contains('collapsed'));
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');

            if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
                setTimeout(function () {
                    openDropdownForActiveLink();
                    scrollActiveSidebarLinkIntoView();
                }, 180);
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

    function normalizeHref(href) {
        if (!href || href === '#') return '';
        try {
            const url = new URL(href, window.location.href);
            return url.pathname.split('/').pop().toLowerCase();
        } catch (e) {
            return href.split('?')[0].split('#')[0].split('/').pop().toLowerCase();
        }
    }

    function openDropdownForActiveLink() {
        const activeLink = document.querySelector('.sidebar .nav-link.active');
        if (!activeLink) return;

        const collapseDiv = activeLink.closest('.collapse');
        if (collapseDiv) {
            collapseDiv.classList.add('show');
            const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
            if (parentBtn) {
                parentBtn.classList.add('active-parent');
                setArrowState(parentBtn.querySelector('.dropdown-arrow'), true);
            }
        }
    }

    function scrollActiveSidebarLinkIntoView() {
        const sidebarContent = document.querySelector('.sidebar-content');
        const activeLink = document.querySelector('.sidebar .nav-link.active');
        if (!sidebarContent || !activeLink || !sidebarContent.contains(activeLink)) return;

        const contentRect = sidebarContent.getBoundingClientRect();
        const linkRect = activeLink.getBoundingClientRect();
        const isAbove = linkRect.top < contentRect.top + 20;
        const isBelow = linkRect.bottom > contentRect.bottom - 20;

        if (isAbove || isBelow) {
            const offset = (linkRect.top - contentRect.top) - (sidebarContent.clientHeight / 2) + (activeLink.clientHeight / 2);
            sidebarContent.scrollTo({
                top: sidebarContent.scrollTop + offset,
                behavior: 'smooth'
            });
        }
    }

    window.setActiveSidebarItem = function () {
        const currentPage = window.location.pathname.split('/').pop().toLowerCase() || 'motorpool.php';

        document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
            link.classList.remove('active', 'active-parent');
            const li = link.closest('.nav-item');
            if (li) li.classList.remove('active');
        });

        let matchedLink = null;
        document.querySelectorAll('.sidebar .nav-link[href]').forEach(function (link) {
            const linkPage = normalizeHref(link.getAttribute('href'));
            if (linkPage && linkPage === currentPage) {
                matchedLink = link;
            }
        });

        if (!matchedLink) {
            const aliases = {
                'request_handler.php': ['ris_monitoring.php', 'request_handler.php'],
                'motorpool.php': ['motorpool.php', 'vehicle_profile.php']
            };
            Object.keys(aliases).some(function (page) {
                if (!aliases[page].includes(currentPage)) return false;
                matchedLink = Array.from(document.querySelectorAll('.sidebar .nav-link[href]')).find(function (link) {
                    return normalizeHref(link.getAttribute('href')) === page;
                });
                return !!matchedLink;
            });
        }

        if (matchedLink) {
            matchedLink.classList.add('active');
            matchedLink.closest('.nav-item')?.classList.add('active');
            openDropdownForActiveLink();
        }

        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('collapsed')) {
            document.querySelectorAll('.dropdown-nav').forEach(function (dropdownNav) {
                const hasActiveChild = dropdownNav.querySelector('.collapse .nav-link.active');
                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                if (hasActiveChild && parentLink) parentLink.classList.add('active');
            });
        }

        setTimeout(scrollActiveSidebarLinkIntoView, 180);
        setTimeout(scrollActiveSidebarLinkIntoView, 450);
    };

    window.initializeMotorpoolSidebar = function () {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        const mobileToggleBtn = document.getElementById('mobileToggleBtn') || document.getElementById('mobileMenuBtn');

        if (sidebar && window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            sidebar.classList.toggle('collapsed', savedCollapsed === 'true');
            mainContent?.classList.toggle('expanded', savedCollapsed === 'true');
        }

        if (desktopToggleBtn && !desktopToggleBtn.dataset.sidebarActiveBound) {
            desktopToggleBtn.dataset.sidebarActiveBound = '1';
            desktopToggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                window.toggleSidebar();
            }, true);
        }

        if (mobileToggleBtn && !mobileToggleBtn.dataset.sidebarActiveBound) {
            mobileToggleBtn.dataset.sidebarActiveBound = '1';
            mobileToggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                window.toggleSidebar();
            }, true);
        }

        document.querySelectorAll('.sidebar .collapse').forEach(function (collapse) {
            if (!collapse.dataset.sidebarStopBound) {
                collapse.dataset.sidebarStopBound = '1';
                collapse.addEventListener('click', function (e) { e.stopPropagation(); });
            }
        });

        window.setActiveSidebarItem();
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.initializeMotorpoolSidebar();
    });
})();

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