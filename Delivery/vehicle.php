<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim(($_SESSION['first_name'] ?? 'Driver') . ' ' . ($_SESSION['last_name'] ?? 'User'));
$user_role = strtolower(trim((string)($_SESSION['role'] ?? 'delivery')));
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

if ($user_name === '') $user_name = 'Driver User';
if ($user_role !== 'delivery') {
    header('Location: ../login.php');
    exit();
}

function h($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}
function columnExists(mysqli $conn, string $table, string $column): bool {
    if (!tableExists($conn, $table)) return false;
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}
function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    if (!columnExists($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}
function jsonResponse(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}
function saveFuelAttachment(string $field): string {
    if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    $uploadDir = __DIR__ . '/../uploads/motorpool/fuel_monitoring/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) return '';

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';

    $filename = 'driver_fuel_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $uploadDir . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) return '';
    return 'fuel_monitoring/' . $filename;
}

$user_initials = '';
foreach (explode(' ', $user_name) as $part) {
    if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
}
if ($user_initials === '') $user_initials = 'DV';

$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0 && tableExists($conn, 'branches')) {
    $stmt = $conn->prepare('SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) $branch_name = (string)$row['branch_name'];
        $stmt->close();
    }
}

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$driver_info = null;
if ($driver_id <= 0 && tableExists($conn, 'users') && columnExists($conn, 'users', 'driver_id')) {
    $stmt = $conn->prepare('SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $driver_id = (int)$row['driver_id'];
            $_SESSION['driver_id'] = $driver_id;
        }
        $stmt->close();
    }
}
if ($driver_id > 0 && tableExists($conn, 'drivers')) {
    $stmt = $conn->prepare('SELECT * FROM drivers WHERE driver_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $driver_id);
        $stmt->execute();
        $driver_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
$driver_display_name = trim((string)($driver_info['driver_name'] ?? $user_name));
if ($driver_display_name === '') $driver_display_name = $user_name;

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_vehicles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` VARCHAR(50) UNIQUE NOT NULL,
    `plate_no` VARCHAR(100) DEFAULT NULL,
    `vehicle_type` VARCHAR(100) DEFAULT NULL,
    `vehicle_category` VARCHAR(100) DEFAULT NULL,
    `make_brand` VARCHAR(100) DEFAULT NULL,
    `color` VARCHAR(50) DEFAULT NULL,
    `year_model` VARCHAR(50) DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `business_unit` VARCHAR(150) DEFAULT NULL,
    `status` VARCHAR(30) DEFAULT 'active',
    `vehicle_image` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_branch_id` (`branch_id`),
    KEY `idx_plate_no` (`plate_no`)
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
addColumnIfMissing($conn, 'motorpool_fuel_monitoring', 'driver_id', '`driver_id` INT DEFAULT NULL AFTER `encoded_by`');
addColumnIfMissing($conn, 'motorpool_fuel_monitoring', 'driver_name', '`driver_name` VARCHAR(255) DEFAULT NULL AFTER `driver_id`');
addColumnIfMissing($conn, 'motorpool_fuel_monitoring', 'fuel_price', '`fuel_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `fuel_efficiency`');
addColumnIfMissing($conn, 'motorpool_fuel_monitoring', 'fuel_attachment', '`fuel_attachment` VARCHAR(255) DEFAULT NULL AFTER `fuel_price`');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_driver_fuel_monitoring') {
    $vehicle_db_id = (int)($_POST['vehicle_db_id'] ?? 0);
    $vehicle_id_value = trim((string)($_POST['vehicle_id'] ?? ''));
    $plate_no = trim((string)($_POST['plate_no'] ?? ''));
    $fuel_date = trim((string)($_POST['fuel_date'] ?? date('Y-m-d')));
    $current_odometer = (float)($_POST['current_odometer'] ?? 0);
    $previous_odometer = (float)($_POST['previous_odometer'] ?? 0);
    $distance_covered = (float)($_POST['distance_covered'] ?? 0);
    $liters_consumed = (float)($_POST['liters_consumed'] ?? 0);
    $fuel_efficiency = (float)($_POST['fuel_efficiency'] ?? 0);
    $fuel_price = (float)($_POST['fuel_price'] ?? 0);

    if ($vehicle_db_id <= 0) jsonResponse(['success' => false, 'message' => 'Vehicle record was not found.']);
    if ($fuel_date === '') jsonResponse(['success' => false, 'message' => 'Date is required.']);
    if ($current_odometer < $previous_odometer) jsonResponse(['success' => false, 'message' => 'Current odometer must be greater than or equal to previous odometer.']);
    if ($distance_covered <= 0) $distance_covered = max(0, $current_odometer - $previous_odometer);
    if ($liters_consumed <= 0) jsonResponse(['success' => false, 'message' => 'Liters consumed is required.']);
    if ($fuel_efficiency <= 0 && $liters_consumed > 0) $fuel_efficiency = $distance_covered / $liters_consumed;
    if ($fuel_price <= 0) jsonResponse(['success' => false, 'message' => 'Fuel price is required.']);

    $attachment = saveFuelAttachment('fuel_attachment');
    if ($attachment === '') jsonResponse(['success' => false, 'message' => 'Attachment is required.']);

    $allowed = false;
    $stmt = $conn->prepare("SELECT id FROM motorpool_vehicles WHERE id = ? AND (plate_no = ? OR vehicle_id = ?) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iss', $vehicle_db_id, $plate_no, $vehicle_id_value);
        $stmt->execute();
        $allowed = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$allowed) jsonResponse(['success' => false, 'message' => 'Selected vehicle is not registered in Motorpool.']);

    $stmt = $conn->prepare("INSERT INTO motorpool_fuel_monitoring
        (vehicle_db_id, vehicle_id, plate_no, fuel_date, current_odometer, previous_odometer, distance_covered, liters_consumed, fuel_efficiency, fuel_price, fuel_attachment, branch_id, encoded_by, driver_id, driver_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) jsonResponse(['success' => false, 'message' => 'Failed to prepare fuel monitoring save: ' . $conn->error]);
    $stmt->bind_param('isssddddddsiiis', $vehicle_db_id, $vehicle_id_value, $plate_no, $fuel_date, $current_odometer, $previous_odometer, $distance_covered, $liters_consumed, $fuel_efficiency, $fuel_price, $attachment, $branch_id, $user_id, $driver_id, $driver_display_name);
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
            'fuel_efficiency' => number_format($fuel_efficiency, 2, '.', ''),
            'fuel_price' => number_format($fuel_price, 2, '.', ''),
            'fuel_attachment' => $attachment,
            'driver_name' => $driver_display_name,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    jsonResponse(['success' => false, 'message' => 'Failed to save fuel monitoring record: ' . $stmt->error]);
}

$assigned_vehicle_ids = [];
$assigned_plates = [];
if ($driver_id > 0 && tableExists($conn, 'trip_tickets')) {
    $hasVehicleId = columnExists($conn, 'trip_tickets', 'vehicle_id');
    $hasDriverId = columnExists($conn, 'trip_tickets', 'driver_id');
    if ($hasVehicleId && $hasDriverId) {
        $stmt = $conn->prepare("SELECT DISTINCT vehicle_id FROM trip_tickets WHERE driver_id = ? AND vehicle_id IS NOT NULL AND vehicle_id > 0");
        if ($stmt) {
            $stmt->bind_param('i', $driver_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $assigned_vehicle_ids[] = (int)$row['vehicle_id'];
            $stmt->close();
        }
    }
}
if ($driver_info) {
    $plate = trim((string)($driver_info['vehicle_plate_number'] ?? ''));
    if ($plate !== '') $assigned_plates[] = $plate;
}

$vehicles = [];
$whereParts = [];
$params = [];
$types = '';
if (!empty($assigned_vehicle_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_vehicle_ids), '?'));
    $whereParts[] = "id IN ($placeholders)";
    foreach ($assigned_vehicle_ids as $vid) { $types .= 'i'; $params[] = $vid; }
}
if (!empty($assigned_plates)) {
    $placeholders = implode(',', array_fill(0, count($assigned_plates), '?'));
    $whereParts[] = "plate_no IN ($placeholders)";
    foreach ($assigned_plates as $plate) { $types .= 's'; $params[] = $plate; }
}

if (!empty($whereParts)) {
    $branchFilter = '';
    if (!$view_all_branches && $branch_id > 0) $branchFilter = ' AND (branch_id = ' . (int)$branch_id . ' OR branch_id IS NULL OR branch_id = 0)';
    $sql = "SELECT * FROM motorpool_vehicles WHERE (" . implode(' OR ', $whereParts) . ") $branchFilter ORDER BY plate_no ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $vehicles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$vehicle_ids_for_history = array_map(fn($v) => (int)($v['id'] ?? 0), $vehicles);
$fuel_histories = [];
if (!empty($vehicle_ids_for_history)) {
    $ids = implode(',', array_map('intval', $vehicle_ids_for_history));
    $result = $conn->query("SELECT fuel_id, vehicle_db_id, vehicle_id, plate_no, fuel_date, current_odometer, previous_odometer, distance_covered, liters_consumed, fuel_efficiency, fuel_price, fuel_attachment, driver_name, created_at
                            FROM motorpool_fuel_monitoring
                            WHERE vehicle_db_id IN ($ids)
                            ORDER BY fuel_date DESC, fuel_id DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $fuel_histories[(int)$row['vehicle_db_id']][] = $row;
        }
    }
}
$totalFuelRecords = array_sum(array_map('count', $fuel_histories));
$totalLiters = 0;
$totalFuelCost = 0;
foreach ($fuel_histories as $rows) {
    foreach ($rows as $r) {
        $totalLiters += (float)($r['liters_consumed'] ?? 0);
        $totalFuelCost += (float)($r['fuel_price'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle - Delivery</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/delivery.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>

          /* ===== QUICK STATS CARDS ===== */
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

.stat-card.total {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.complete {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card.purple-gradient {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.stat-card .stat-value,
.stat-card .stat-label,
.stat-card .stat-content,
.stat-card small,
.stat-card small i,
.stat-card .badge {
    color: white !important;
}

.stat-card .stat-content,
.stat-card .stat-icon {
    background: transparent !important;
}

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
    
    .stat-card .badge {
        display: inline-block !important;
        font-size: 0.5rem !important;
        padding: 0.2rem 0.4rem !important;
        margin-top: 0.2rem !important;
        text-align: center !important;
    }
}

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

.stat-card-row {
    margin-bottom: 1.5rem;
}

.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}

        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 22px;
            box-shadow: 0 10px 24px rgba(0, 107, 79, .06);
            margin-top: 16px;
        }

        .section-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }

        .section-toolbar h5 {
            margin: 0;
            color: #06233a;
            font-size: 1.25rem;
        }

        .section-toolbar small {
            color: #64748b !important;
        }

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }

        .custom-table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .custom-table thead th {
            background: #006B4F;
            color: #ffffff;
            border: 0;
            padding: 14px 14px;
            font-size: .88rem;
            text-transform: uppercase;
            letter-spacing: .02em;
            white-space: nowrap;
        }

        .custom-table tbody td {
            padding: 14px;
            border-bottom: 1px solid #c8f7d6;
            vertical-align: middle;
            color: #0f172a;
            background: #ffffff;
        }

        .custom-table tbody tr:hover td {
            background: #ecfdf5;
        }

        .custom-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .vehicle-image {
            width: 62px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #c8f7d6;
            background: #f8fafc;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #E8F9EA;
            color: #047857;
            border: 1px solid #b7f3ca;
            font-size: .78rem;
        }

        .btn-success,
        .btn-outline-success:hover {
            background: #44D34E !important;
            border-color: #44D34E !important;
            color: #073452 !important;
            font-weight: 800;
        }

        .btn-outline-success {
            border-color: #14b85a !important;
            color: #047857 !important;
            background: #ecfdf5 !important;
        }

        .btn-outline-success:hover {
            color: #ffffff !important;
            background: #047857 !important;
            border-color: #047857 !important;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .detail-box {
            border: 1px solid #b7f3ca;
            border-radius: 10px;
            padding: 14px;
            background: #ffffff;
        }

        .detail-box .label {
            font-size: .78rem;
            color: #64748b;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .detail-box .value {
            margin-top: 5px;
            color: #0f172a;
            font-weight: 800;
        }

        .modal-content {
            border: 0;
            border-radius: 12px;
            overflow: hidden;
        }

        .modal-header {
            background: #006B4F;
            color: #ffffff;
            border: 0;
            padding: 18px 22px;
        }

        .modal-title {
            font-weight: 850;
        }

        .modal-header .btn-close {
            filter: invert(1);
            opacity: 1;
        }

        .modal-body {
            padding: 22px;
        }

        .modal-footer {
            border-top: 1px solid #e2e8f0;
            padding: 16px 22px;
        }

        .form-label {
            color: #0f172a;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            border-color: #cbd5e1;
            padding: 10px 12px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #44D34E;
            box-shadow: 0 0 0 .2rem rgba(68, 211, 78, .18);
        }

        .required-mark {
            color: #ef4444;
        }

        .fuel-table thead th {
            background: #006B4F;
            color: #ffffff;
            border: 0;
            padding: 13px;
            font-size: .82rem;
            text-transform: uppercase;
        }

        .fuel-table tbody td {
            border-bottom: 1px solid #c8f7d6;
            padding: 13px;
        }

        .fuel-table tbody tr:nth-child(even) td {
            background: #ecfdf5;
        }

        .attachment-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #14b85a;
            color: #047857;
            background: #ecfdf5;
            padding: 6px 10px;
            border-radius: 8px;
            text-decoration: none;
        }

        .attachment-link:hover {
            color: #ffffff;
            background: #047857;
            border-color: #047857;
        }

        .mobile-nav {
            display: none;
        }

        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content,
            .main-content.expanded {
                margin-left: 0;
                padding: 14px 14px 86px;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .section-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .mobile-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                background: #071E32;
                border-top: 1px solid rgba(255,255,255,.12);
                display: block;
                z-index: 1030;
            }

            .mobile-nav .nav {
                display: flex;
                justify-content: space-around;
            }

            .mobile-nav .nav-link {
                display: flex;
                flex-direction: column;
                gap: 2px;
                margin: 0;
                border-radius: 0;
                min-height: 58px;
                padding: 8px 10px;
                font-size: .72rem;
            }

            .mobile-nav .nav-link i {
                font-size: 1.25rem;
            }

            .navbar-top {
                border-radius: 10px;
            }

            .stat-card {
                min-height: 118px;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
<div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Delivery</span>
                </h3>
            </div>
            
           <div class="sidebar-menu">
                <ul class="nav flex-column">
                     <li class="nav-item">
                        <a class="nav-link" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_collections.php">
                            <i class="bi bi-cash-stack"></i>
                            <span class="nav-text">Collections</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="vehicle.php">
                            <i class="bi bi-car-front"></i>
                            <span class="nav-text">Vehicle</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="rejecteddelivery.php">
                            <i class="bi bi-exclamation-circle"></i>
                            <span class="nav-text">Rejected Delivery</span>
                        </a>
                    </li>
                </ul>
            </div>
            <hr class="sidebar-divider">
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
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
        <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
        <div class="page-title"><h2>Vehicle</h2><p>View your assigned vehicle and record fuel monitoring.</p></div>
    </div>

<!-- Quick Stats -->
<div class="row stat-card-row g-1 g-sm-2 mb-4">
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-car-front stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= count($vehicles); ?></div>
                <div class="stat-label">Assigned Vehicle</div>
                <small class="d-block">Active fleet units</small>
            </div>
        </div>
    </div>
    
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-fuel-pump stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value"><?= (int)$totalFuelRecords; ?></div>
                <div class="stat-label">Fuel Records</div>
                <small class="d-block">Total transactions</small>
            </div>
        </div>
    </div>
    
    <div class="col">
        <div class="stat-card purple-gradient">
            <i class="bi bi-cash-coin stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value">₱<?= number_format($totalFuelCost, 2); ?></div>
                <div class="stat-label">Total Fuel Cost</div>
                <small class="d-block">Accumulated expenses</small>
            </div>
        </div>
    </div>
</div>

    <div class="form-card">
        <div class="section-toolbar">
            <div><h5><i class="bi bi-car-front me-2"></i>Assigned Vehicle</h5><small class="text-muted">Only vehicles assigned to your driver account are shown here.</small></div>
        </div>
        <div class="table-responsive">
            <table class="table custom-table align-middle">
                <thead><tr><th>Image</th><th>Plate No.</th><th>Make/Brand</th><th>Vehicle Type</th><th>Category</th><th>Business Unit</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (empty($vehicles)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No assigned vehicle found.</td></tr>
                <?php else: foreach ($vehicles as $vehicle):
                    $vehicleDbId = (int)($vehicle['id'] ?? 0);
                    $fuelJson = json_encode($fuel_histories[$vehicleDbId] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT);
                    $image = trim((string)($vehicle['vehicle_image'] ?? ''));
                    $imageSrc = $image !== '' ? '../uploads/motorpool/' . ltrim($image, '/') : '';
                ?>
                    <tr class="vehicle-row"
                        data-db-id="<?= h($vehicleDbId); ?>"
                        data-vehicle-id="<?= h($vehicle['vehicle_id'] ?? ''); ?>"
                        data-plate-no="<?= h($vehicle['plate_no'] ?? ''); ?>"
                        data-make-brand="<?= h($vehicle['make_brand'] ?? ''); ?>"
                        data-vehicle-type="<?= h($vehicle['vehicle_type'] ?? ''); ?>"
                        data-vehicle-category="<?= h($vehicle['vehicle_category'] ?? ''); ?>"
                        data-business-unit="<?= h($vehicle['business_unit'] ?? ''); ?>"
                        data-color="<?= h($vehicle['color'] ?? ''); ?>"
                        data-year-model="<?= h($vehicle['year_model'] ?? ''); ?>"
                        data-fuel-history="<?= h($fuelJson); ?>"
                        onclick="openVehicleDetails(this)">
                        <td><?php if ($imageSrc): ?><img src="<?= h($imageSrc); ?>" class="vehicle-image" alt="Vehicle"><?php else: ?><span class="badge-soft">No Image</span><?php endif; ?></td>
                        <td><strong><?= h($vehicle['plate_no'] ?? ''); ?></strong></td>
                        <td><?= h($vehicle['make_brand'] ?? ''); ?></td>
                        <td><?= h($vehicle['vehicle_type'] ?? ''); ?></td>
                        <td><?= h($vehicle['vehicle_category'] ?? ''); ?></td>
                        <td><?= h($vehicle['business_unit'] ?? ''); ?></td>
                        <td><button class="btn btn-outline-success btn-sm" onclick="event.stopPropagation(); openFuelMonitoringModal(this.closest('tr'))"><i class="bi bi-fuel-pump me-1"></i>Fuel</button></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link active" href="fordelivery.php">
                    <i class="bi bi-truck"></i>
                    <span>Delivery</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="rejecteddelivery.php">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>Rejected</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo $user_initials; ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

<div class="modal fade" id="vehicleDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-car-front me-2"></i>Vehicle Details</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="d-flex justify-content-between align-items-start mb-3 gap-2 flex-wrap"><div><h4 class="mb-1" id="detailsVehicleTitle">Vehicle</h4><div class="text-muted" id="detailsVehicleSubtitle"></div></div><button type="button" class="btn btn-success" id="detailsFuelBtn"><i class="bi bi-fuel-pump me-1"></i>Add Fuel Record</button></div>
            <div class="detail-grid mb-3" id="vehicleDetailsGrid"></div>
            <ul class="nav nav-tabs" role="tablist"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#fuelTab" type="button">Fuel Monitoring</button></li></ul>
            <div class="tab-content pt-3"><div class="tab-pane fade show active" id="fuelTab"><div class="table-responsive"><table class="table fuel-table align-middle"><thead><tr><th>Date</th><th>Driver</th><th>Current Odometer</th><th>Previous Odometer</th><th>Distance Covered (km)</th><th>Liters Consumed</th><th>Fuel Efficiency (km/L)</th><th>Price</th><th>Attachment</th></tr></thead><tbody id="fuelHistoryBody"></tbody></table></div></div></div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="modal fade" id="fuelMonitoringModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <form id="fuelMonitoringForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_driver_fuel_monitoring">
            <input type="hidden" name="vehicle_db_id" id="fuelVehicleDbId">
            <input type="hidden" name="vehicle_id" id="fuelVehicleCode">
            <input type="hidden" name="plate_no" id="fuelPlateNo">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-fuel-pump me-2"></i>Fuel Monitoring</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-light border"><strong id="fuelVehicleTitle">Vehicle</strong><div class="small text-muted" id="fuelVehicleSubtitle">Fuel monitoring record</div></div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Date <span class="required-mark">*</span></label><input type="date" class="form-control" name="fuel_date" id="fuelDate" required></div>
                    <div class="col-md-4"><label class="form-label">Current Odometer Reading <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="current_odometer" id="fuelCurrentOdometer" required></div>
                    <div class="col-md-4"><label class="form-label">Previous Odometer Reading <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="previous_odometer" id="fuelPreviousOdometer" required></div>
                    <div class="col-md-4"><label class="form-label">Distance Covered (km) <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="distance_covered" id="fuelDistanceCovered" required></div>
                    <div class="col-md-4"><label class="form-label">Liters Consumed <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control fuel-calc-field" name="liters_consumed" id="fuelLitersConsumed" required></div>
                    <div class="col-md-4"><label class="form-label">Fuel Efficiency (km/L) <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control" name="fuel_efficiency" id="fuelEfficiency" required></div>
                    <div class="col-md-6"><label class="form-label">Price <span class="required-mark">*</span></label><input type="number" step="0.01" min="0" class="form-control" name="fuel_price" id="fuelPrice" required></div>
                    <div class="col-md-6"><label class="form-label">Attachment <span class="required-mark">*</span></label><input type="file" class="form-control" name="fuel_attachment" id="fuelAttachment" accept="image/*,.pdf" required></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Save Fuel Record</button></div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let selectedVehicleRow = null;
function escapeHtml(value){return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
function today(){return new Date().toISOString().slice(0,10);}
function rowData(row, key){return row ? (row.dataset[key] || '') : '';}
function parseFuelHistory(row){try{return JSON.parse(rowData(row,'fuelHistory') || '[]') || [];}catch(e){return [];}}
function money(v){const n=parseFloat(v||0);return '₱' + n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function attachmentUrl(path){ if(!path) return ''; if(path.startsWith('../') || path.startsWith('uploads/') || path.startsWith('/')) return path; return '../uploads/motorpool/' + path.replace(/^\/+/, ''); }
function renderFuelHistory(row){
    const body=document.getElementById('fuelHistoryBody'); if(!body) return;
    const history=parseFuelHistory(row);
    if(!history.length){body.innerHTML='<tr><td colspan="9" class="text-center text-muted py-4">No fuel monitoring records yet.</td></tr>';return;}
    body.innerHTML=history.map(item=>{
        const link=attachmentUrl(item.fuel_attachment || '');
        return '<tr><td>'+escapeHtml(item.fuel_date||'')+'</td><td>'+escapeHtml(item.driver_name||'')+'</td><td>'+escapeHtml(item.current_odometer||'')+'</td><td>'+escapeHtml(item.previous_odometer||'')+'</td><td>'+escapeHtml(item.distance_covered||'')+'</td><td>'+escapeHtml(item.liters_consumed||'')+'</td><td>'+escapeHtml(item.fuel_efficiency||'')+'</td><td>'+money(item.fuel_price||0)+'</td><td>'+(link?'<a class="attachment-link" href="'+escapeHtml(link)+'" target="_blank"><i class="bi bi-paperclip"></i>View</a>':'')+'</td></tr>';
    }).join('');
}
function openVehicleDetails(row){
    selectedVehicleRow=row;
    document.getElementById('detailsVehicleTitle').textContent=[rowData(row,'makeBrand'),rowData(row,'vehicleType')].filter(Boolean).join(' - ') || 'Vehicle';
    document.getElementById('detailsVehicleSubtitle').textContent=rowData(row,'plateNo') ? 'Plate No.: '+rowData(row,'plateNo') : '';
    document.getElementById('vehicleDetailsGrid').innerHTML=[['Plate No.',rowData(row,'plateNo')],['Vehicle ID',rowData(row,'vehicleId')],['Make/Brand',rowData(row,'makeBrand')],['Vehicle Type',rowData(row,'vehicleType')],['Category',rowData(row,'vehicleCategory')],['Business Unit',rowData(row,'businessUnit')],['Color',rowData(row,'color')],['Year Model',rowData(row,'yearModel')]].map(pair=>'<div class="detail-box"><div class="label">'+escapeHtml(pair[0])+'</div><div class="value">'+escapeHtml(pair[1]||'N/A')+'</div></div>').join('');
    renderFuelHistory(row);
    document.getElementById('detailsFuelBtn').onclick=function(){openFuelMonitoringModal(row)};
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleDetailsModal')).show();
}
function updateFuelCalculations(changedId){
    const current=parseFloat(document.getElementById('fuelCurrentOdometer').value||0);
    const previous=parseFloat(document.getElementById('fuelPreviousOdometer').value||0);
    const liters=parseFloat(document.getElementById('fuelLitersConsumed').value||0);
    const distanceInput=document.getElementById('fuelDistanceCovered');
    const efficiencyInput=document.getElementById('fuelEfficiency');
    if(changedId==='fuelCurrentOdometer'||changedId==='fuelPreviousOdometer'){
        const distance=Math.max(0,current-previous);
        distanceInput.value=distance>0?distance.toFixed(2):'';
    }
    const distance=parseFloat(distanceInput.value||0);
    if(liters>0 && distance>0){efficiencyInput.value=(distance/liters).toFixed(2);} else {efficiencyInput.value='';}
}
document.addEventListener('input',function(e){if(e.target.classList && e.target.classList.contains('fuel-calc-field')) updateFuelCalculations(e.target.id);});
function openFuelMonitoringModal(row){
    selectedVehicleRow=row;
    const form=document.getElementById('fuelMonitoringForm'); if(form) form.reset();
    document.getElementById('fuelVehicleDbId').value=rowData(row,'dbId');
    document.getElementById('fuelVehicleCode').value=rowData(row,'vehicleId');
    document.getElementById('fuelPlateNo').value=rowData(row,'plateNo');
    document.getElementById('fuelDate').value=today();
    document.getElementById('fuelVehicleTitle').textContent=[rowData(row,'makeBrand'),rowData(row,'vehicleType')].filter(Boolean).join(' - ') || 'Vehicle';
    document.getElementById('fuelVehicleSubtitle').textContent=rowData(row,'plateNo') ? 'Plate No.: '+rowData(row,'plateNo') : 'Fuel monitoring record';
    const history=parseFuelHistory(row);
    if(history.length && history[0].current_odometer) document.getElementById('fuelPreviousOdometer').value=history[0].current_odometer;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('fuelMonitoringModal')).show();
}
document.getElementById('fuelMonitoringForm').addEventListener('submit',function(e){
    e.preventDefault();
    const form=this;
    fetch(window.location.href,{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(data=>{
        if(!data.success){Swal.fire('Unable to Save',data.message||'Failed to save fuel monitoring record.','error');return;}
        bootstrap.Modal.getOrCreateInstance(document.getElementById('fuelMonitoringModal')).hide();
        if(selectedVehicleRow){
            const history=parseFuelHistory(selectedVehicleRow);
            history.unshift(data);
            selectedVehicleRow.dataset.fuelHistory=JSON.stringify(history);
            renderFuelHistory(selectedVehicleRow);
        }
        Swal.fire('Saved','Fuel monitoring record saved successfully.','success');
    }).catch(err=>{console.error(err);Swal.fire('Error','Failed to save fuel monitoring record.','error');});
});
document.getElementById('desktopToggleBtn')?.addEventListener('click',function(){document.getElementById('sidebar').classList.toggle('collapsed');document.getElementById('mainContent').classList.toggle('expanded');});
document.getElementById('mobileToggleBtn')?.addEventListener('click',function(){document.getElementById('sidebar').classList.toggle('show');});
function logout(){Swal.fire({title:'Logout?',text:'Are you sure you want to logout?',icon:'question',showCancelButton:true,confirmButtonColor:'#047857',cancelButtonColor:'#64748b',confirmButtonText:'Yes, logout'}).then(r=>{if(r.isConfirmed) window.location.href='../logout.php';});}
</script>
</body>
</html>
