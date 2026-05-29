<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) { die('Database connection failed: ' . mysqli_connect_error()); }

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));

if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($user_role !== 'motorpool') {
    if ($user_role === 'branch_admin') header('Location: ../Branch_Admin/branchdashboard.php');
    elseif ($user_role === 'admin') header('Location: ../Admin/dashboard.php');
    else header('Location: ../login.php');
    exit;
}

$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Motorpool';
$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
if ($user_initials === '') $user_initials = 'MP';

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function jsonText($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); }
function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}
function columnExists(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}
function safeDateOrNull(string $value): ?string { return trim($value) === '' ? null : trim($value); }

function uploadReceivedPhotos(string $field, string $uploadDir): array {
    $saved = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return $saved;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $allowed = ['jpg','jpeg','png','webp','gif'];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (empty($name) || !is_uploaded_file($_FILES[$field]['tmp_name'][$i])) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $timestamp = date('YmdHis');
        $filename = 'received_vehicle_' . $timestamp . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = rtrim($uploadDir, '/') . '/' . $filename;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], $target)) {
            $saved[] = ['filename' => 'received_vehicle/' . $filename, 'timestamp_text' => date('M d, Y h:i A'), 'uploaded_at' => date('Y-m-d H:i:s')];
        }
    }
    return $saved;
}

function uploadStartRepairPhotos(string $field, string $uploadDir): array {
    $saved = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return $saved;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $allowed = ['jpg','jpeg','png','webp','gif'];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (empty($name) || !is_uploaded_file($_FILES[$field]['tmp_name'][$i])) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
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

function uploadReleaseProof(string $field, string $uploadDir): string {
    if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif','pdf'];
    if (!in_array($ext, $allowed, true)) return '';
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
    `filename` VARCHAR(255) NOT NULL,
    `timestamp_text` VARCHAR(100) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_receipt_id` (`receipt_id`), KEY `idx_ris_id` (`ris_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


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
        $update = $conn->prepare("UPDATE motorpool_ris_workflow_history SET details = ?, attachment = ?, processed_by = ?, processed_at = NOW() WHERE history_id = ?");
        if ($update) {
            $update->bind_param('ssii', $details, $attachment, $processed_by, $existing_id);
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

function setRisWorkflow(mysqli $conn, int $ris_id, string $status): bool {
    $stmt = $conn->prepare("UPDATE motorpool_ris_requests SET workflow_status = ?, status = ? WHERE ris_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ssi', $status, $status, $ris_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_vehicle_workflow_v3') {
    $ris_id = (int)($_POST['receive_ris_id'] ?? 0);
    $received_by_name = trim($_POST['received_by_name'] ?? '');
    $received_date = trim($_POST['received_date'] ?? date('Y-m-d'));
    $received_time = trim($_POST['received_time'] ?? date('H:i'));

    if ($ris_id <= 0) { $save_status = 'error'; $save_message = 'RIS record was not found.'; }
    elseif ($received_by_name === '') { $save_status = 'error'; $save_message = 'Please enter who received the vehicle.'; }
    else {
        $photos = uploadReceivedPhotos('received_photos', '../uploads/motorpool/received_vehicle');
        if (empty($photos)) { $save_status = 'error'; $save_message = 'Please upload at least one received vehicle photo.'; }
        else {
            $stmt = $conn->prepare("SELECT ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
            $stmt->bind_param('i', $ris_id);
            $stmt->execute();
            $ris = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$ris) { $save_status = 'error'; $save_message = 'RIS record was not found.'; }
            else {
                $conn->begin_transaction();
                try {
                    $received_datetime = $received_date . ' ' . $received_time . ':00';
                    $receiptStmt = $conn->prepare("INSERT INTO motorpool_vehicle_receipts
                        (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, received_by_name, received_date, received_time, received_datetime, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE received_by_name = VALUES(received_by_name), received_date = VALUES(received_date), received_time = VALUES(received_time), received_datetime = VALUES(received_datetime)");
                    if (!$receiptStmt) throw new Exception($conn->error);
                    $receiptStmt->bind_param('isissssssi', $ris_id, $ris['ris_number'], $ris['vehicle_db_id'], $ris['vehicle_id'], $ris['plate_no'], $received_by_name, $received_date, $received_time, $received_datetime, $user_id);
                    if (!$receiptStmt->execute()) throw new Exception($receiptStmt->error);
                    $receipt_id = $receiptStmt->insert_id;
                    $receiptStmt->close();
                    if ($receipt_id <= 0) {
                        $r = $conn->query("SELECT receipt_id FROM motorpool_vehicle_receipts WHERE ris_id = " . (int)$ris_id . " LIMIT 1");
                        $receipt_id = $r && ($rr = $r->fetch_assoc()) ? (int)$rr['receipt_id'] : 0;
                    }
                    $photoStmt = $conn->prepare("INSERT INTO motorpool_vehicle_receipt_photos (receipt_id, ris_id, filename, timestamp_text, uploaded_at) VALUES (?, ?, ?, ?, ?)");
                    foreach ($photos as $p) {
                        $photoStmt->bind_param('iisss', $receipt_id, $ris_id, $p['filename'], $p['timestamp_text'], $p['uploaded_at']);
                        if (!$photoStmt->execute()) throw new Exception($photoStmt->error);
                    }
                    $photoStmt->close();
                    setRisWorkflow($conn, $ris_id, 'For Assessment');
                    logRisWorkflowHistory($conn, $ris_id, 'For Vehicle Endorsement', 'Vehicle received by ' . $received_by_name . ' on ' . $received_date . ' at ' . $received_time . '.', json_encode($photos), $user_id);
                    logRisWorkflowHistory($conn, $ris_id, 'For Assessment', 'Vehicle is ready for assessment after endorsement.', '', $user_id);
                    $conn->commit();
                    $save_status = 'success'; $save_message = 'Vehicle received. Request is now for assessment.';
                } catch (Exception $e) { $conn->rollback(); $save_status = 'error'; $save_message = $e->getMessage(); }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_assessment_workflow_v3') {
    $ris_id = (int)($_POST['assessment_ris_id'] ?? 0);
    $assessment_json = trim($_POST['assessment_json'] ?? '');
    $assessment_assessed_by = trim($_POST['assessment_assessed_by'] ?? '');
    $assessment = json_decode($assessment_json, true);
    if ($ris_id <= 0) { $save_status = 'error'; $save_message = 'RIS record was not found.'; }
    elseif (!is_array($assessment) || empty($assessment)) { $save_status = 'error'; $save_message = 'Please add at least one repair to make.'; }
    elseif ($assessment_assessed_by === '') { $save_status = 'error'; $save_message = 'Please enter Assessed By.'; }
    else {
        $stmt = $conn->prepare("SELECT ris_number FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        $stmt->bind_param('i', $ris_id); $stmt->execute(); $ris = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$ris) { $save_status = 'error'; $save_message = 'RIS record was not found.'; }
        else {
            $repairs = [];
            $parts = [];
            $hasIncompletePart = false;
            foreach ($assessment as $r) {
                $repair = trim((string)($r['repair'] ?? ''));
                if ($repair !== '') $repairs[] = $repair;
                foreach (($r['parts'] ?? []) as $part) {
                    $itemNo = trim((string)($part['item_no'] ?? ''));
                    $description = trim((string)($part['description'] ?? ($part['name'] ?? '')));
                    $specification = trim((string)($part['specification'] ?? ''));
                    $quantity = trim((string)($part['quantity'] ?? ($part['qty'] ?? '')));
                    $purpose = trim((string)($part['purpose'] ?? ''));

                    $hasAnyPartValue = ($itemNo !== '' || $description !== '' || $specification !== '' || $quantity !== '' || $purpose !== '');
                    $hasAllPartValue = ($itemNo !== '' && $description !== '' && $specification !== '' && $quantity !== '' && $purpose !== '');

                    if ($hasAnyPartValue && !$hasAllPartValue) {
                        $hasIncompletePart = true;
                    }

                    if ($hasAllPartValue) {
                        $parts[] = 'Item No.: ' . $itemNo . ' | Description: ' . $description . ' | Specification: ' . $specification . ' | Quantity: ' . $quantity . ' | Purpose: ' . $purpose;
                    }
                }
            }
            if (empty($repairs)) { $save_status = 'error'; $save_message = 'Please enter at least one repair to make.'; }
            elseif ($hasIncompletePart || empty($parts)) { $save_status = 'error'; $save_message = 'Please complete all required parts fields: Item No., Description, Specification, Quantity, and Purpose.'; }
            else {
                $repairs_summary = implode("\n", $repairs);
                $parts_summary = implode("\n", $parts) . "\n\nAssessed By: " . $assessment_assessed_by;
                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO motorpool_ris_assessments
                    (ris_id, ris_number, assessment_json, repairs_summary, parts_summary, assessed_by, assessed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE assessment_json = VALUES(assessment_json), repairs_summary = VALUES(repairs_summary), parts_summary = VALUES(parts_summary), assessed_by = VALUES(assessed_by), assessed_at = VALUES(assessed_at)");
                if (!$stmt) { $save_status = 'error'; $save_message = $conn->error; }
                else {
                    $stmt->bind_param('issssis', $ris_id, $ris['ris_number'], $assessment_json, $repairs_summary, $parts_summary, $user_id, $now);
                    if ($stmt->execute()) {
                        $up = $conn->prepare("UPDATE motorpool_ris_requests SET workflow_status='For Approval', status='For Approval', branch_approval_status='Pending', branch_approval_by=NULL, branch_approval_at=NULL, branch_approval_remarks=NULL, findings=?, action_taken='Assessment sent for branch approval.', repairs_done=?, parts_replaced=? WHERE ris_id=?");
                        $findings = 'Assessment prepared by Motorpool.';
                        $up->bind_param('sssi', $findings, $repairs_summary, $parts_summary, $ris_id);
                        $up->execute(); $up->close();
                        $assessmentDetails = "Repairs to Make:
" . $repairs_summary . "

Items / Parts Needed:
" . $parts_summary;
                        logRisWorkflowHistory($conn, $ris_id, 'For Assessment', $assessmentDetails, '', $user_id);
                        logRisWorkflowHistory($conn, $ris_id, 'For Approval', 'Assessment sent to Branch Admin for approval.' . "

" . $assessmentDetails, '', $user_id);
                        $save_status = 'success'; $save_message = 'Assessment saved. Request is now for approval.';
                    } else { $save_status = 'error'; $save_message = $stmt->error; }
                    $stmt->close();
                }
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_repair_workflow_v4') {
    $ris_id = (int)($_POST['start_repair_ris_id'] ?? 0);
    $uploadDir = '../uploads/motorpool';
    $photos = uploadStartRepairPhotos('start_repair_photos', $uploadDir);

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } elseif (empty($photos)) {
        $save_status = 'error';
        $save_message = 'Please attach at least one image proof before starting the repair.';
    } else {
        $stmt = $conn->prepare("SELECT ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no FROM motorpool_ris_requests WHERE ris_id = ? LIMIT 1");
        if (!$stmt) {
            $save_status = 'error';
            $save_message = 'Failed to prepare repair start: ' . $conn->error;
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
                    $photoStmt = $conn->prepare("INSERT INTO motorpool_start_repair_proofs
                        (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, proof_photo, timestamp_text, uploaded_at, started_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$photoStmt) throw new Exception($conn->error);

                    foreach ($photos as $photo) {
                        $photoStmt->bind_param(
                            'isisssssi',
                            $ris_id,
                            $ris['ris_number'],
                            $ris['vehicle_db_id'],
                            $ris['vehicle_id'],
                            $ris['plate_no'],
                            $photo['filename'],
                            $photo['timestamp_text'],
                            $photo['uploaded_at'],
                            $user_id
                        );
                        if (!$photoStmt->execute()) throw new Exception($photoStmt->error);
                    }
                    $photoStmt->close();

                    $update = $conn->prepare("UPDATE motorpool_ris_requests
                        SET workflow_status = 'Ongoing Repair', status = 'Ongoing Repair', repair_start_date = COALESCE(repair_start_date, CURDATE()), action_taken = 'Repair started with image proof.'
                        WHERE ris_id = ?");
                    if (!$update) throw new Exception($conn->error);
                    $update->bind_param('i', $ris_id);
                    if (!$update->execute()) throw new Exception($update->error);
                    $update->close();
                    logRisWorkflowHistory($conn, $ris_id, 'Ongoing Repair', 'Repair started with image proof.', json_encode($photos), $user_id);

                    $conn->commit();
                    $save_status = 'success';
                    $save_message = 'Repair started. Image proof has been saved.';
                } catch (Exception $e) {
                    $conn->rollback();
                    $save_status = 'error';
                    $save_message = $e->getMessage();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_release_workflow_v15') {
    $ris_id = (int)($_POST['release_ris_id'] ?? 0);
    $repair_date = safeDateOrNull((string)($_POST['release_repair_date'] ?? date('Y-m-d')));
    $parts_replaced = trim((string)($_POST['release_parts_replaced'] ?? ''));
    $mechanic = trim((string)($_POST['release_mechanic'] ?? ''));
    $start_date = safeDateOrNull((string)($_POST['release_start_date'] ?? ''));
    $end_date = safeDateOrNull((string)($_POST['release_end_date'] ?? date('Y-m-d')));

    if ($ris_id <= 0) {
        $save_status = 'error';
        $save_message = 'RIS record was not found.';
    } else {
        $uploadDir = '../uploads/motorpool';
        $releaseAttachment = uploadReleaseProof('release_attachment', $uploadDir);

        if ($repair_date === null || $parts_replaced === '' || $mechanic === '' || $start_date === null || $end_date === null || $releaseAttachment === '') {
            $save_status = 'error';
            $save_message = 'Please complete all release fields and attach repair completion proof.';
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

                        $repairsDone = trim((string)($row['repairs_summary'] ?: ($row['repairs_done'] ?? '')));
                        $cost = (float)($row['repair_cost'] ?? 0);

                        $historyIds = [];
                        $dup = $conn->prepare("SELECT repair_id FROM vehicle_repair_history WHERE ris_id = ? ORDER BY repair_id ASC");
                        if (!$dup) throw new Exception('Failed to check repair history duplicates: ' . $conn->error);
                        $dup->bind_param('i', $ris_id);
                        $dup->execute();
                        $dupResult = $dup->get_result();
                        while ($dupRow = $dupResult->fetch_assoc()) {
                            $historyIds[] = (int)$dupRow['repair_id'];
                        }
                        $dup->close();

                        if (!empty($historyIds)) {
                            $keepRepairId = $historyIds[0];

                            if (count($historyIds) > 1) {
                                $cleanup = $conn->prepare("DELETE FROM vehicle_repair_history WHERE ris_id = ? AND repair_id <> ?");
                                if (!$cleanup) throw new Exception('Failed to prepare duplicate cleanup: ' . $conn->error);
                                $cleanup->bind_param('ii', $ris_id, $keepRepairId);
                                if (!$cleanup->execute()) throw new Exception('Failed to remove duplicate repair history: ' . $cleanup->error);
                                $cleanup->close();
                            }

                            $history = $conn->prepare("UPDATE vehicle_repair_history SET ris_number=?, vehicle_db_id=?, vehicle_id=?, plate_no=?, repair_date=?, repairs_done=?, parts_replaced=?, mechanic=?, start_date=?, end_date=?, attachment=?, repair_cost=? WHERE repair_id=?");
                            if (!$history) throw new Exception('Failed to prepare repair history update: ' . $conn->error);
                            $history->bind_param('sisssssssssdi', $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $repair_date, $repairsDone, $parts_replaced, $mechanic, $start_date, $end_date, $releaseAttachment, $cost, $keepRepairId);
                            if (!$history->execute()) throw new Exception('Failed to update repair history: ' . $history->error);
                            $history->close();
                        } else {
                            $history = $conn->prepare("INSERT INTO vehicle_repair_history (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, repair_date, repairs_done, parts_replaced, mechanic, start_date, end_date, attachment, repair_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if (!$history) throw new Exception('Failed to prepare repair history: ' . $conn->error);
                            $history->bind_param('isisssssssssd', $ris_id, $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $repair_date, $repairsDone, $parts_replaced, $mechanic, $start_date, $end_date, $releaseAttachment, $cost);
                            if (!$history->execute()) throw new Exception('Failed to save repair history: ' . $history->error);
                            $history->close();
                        }

                        $release = $conn->prepare("INSERT INTO motorpool_repair_release_proofs (ris_id, ris_number, vehicle_db_id, vehicle_id, plate_no, release_attachment, released_by, released_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE release_attachment=VALUES(release_attachment), released_by=VALUES(released_by), released_at=NOW()");
                        if (!$release) throw new Exception('Failed to prepare release proof: ' . $conn->error);
                        $release->bind_param('isisssi', $ris_id, $row['ris_number'], $row['vehicle_db_id'], $row['vehicle_id'], $row['plate_no'], $releaseAttachment, $user_id);
                        if (!$release->execute()) throw new Exception('Failed to save release proof: ' . $release->error);
                        $release->close();

                        $completed = 'Completed';
                        $done = $conn->prepare("UPDATE motorpool_ris_requests SET workflow_status=?, status=?, parts_replaced=?, mechanic=?, repair_start_date=?, repair_end_date=?, completed_by=?, completed_at=NOW(), action_taken='Repair completed and released to Branch Admin repair history.' WHERE ris_id=?");
                        if (!$done) throw new Exception('Failed to prepare RIS completion: ' . $conn->error);
                        $done->bind_param('ssssssii', $completed, $completed, $parts_replaced, $mechanic, $start_date, $end_date, $user_id, $ris_id);
                        if (!$done->execute()) throw new Exception('Failed to complete RIS request: ' . $done->error);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'advance_workflow_v3') {
    $ris_id = (int)($_POST['advance_ris_id'] ?? 0);
    $next_status = trim($_POST['next_workflow_status'] ?? '');
    $allowed = ['For Parts Completion', 'For Repair', 'Ongoing Repair', 'For Release'];
    if ($ris_id <= 0 || !in_array($next_status, $allowed, true)) { $save_status = 'error'; $save_message = 'Invalid workflow action.'; }
    else {
        if (setRisWorkflow($conn, $ris_id, $next_status)) {
            $workflowDetails = 'Workflow advanced to ' . $next_status . '.';
            if ($next_status === 'For Repair') {
                $assessmentStmt = $conn->prepare("SELECT repairs_summary, parts_summary FROM motorpool_ris_assessments WHERE ris_id = ? LIMIT 1");
                if ($assessmentStmt) {
                    $assessmentStmt->bind_param('i', $ris_id);
                    $assessmentStmt->execute();
                    $assessmentRow = $assessmentStmt->get_result()->fetch_assoc();
                    $assessmentStmt->close();
                    if ($assessmentRow) {
                        $workflowDetails = "Parts have been verified complete. Vehicle is ready for repair.

Repairs to Make:
" . ($assessmentRow['repairs_summary'] ?? '') . "

Items / Parts Needed:
" . ($assessmentRow['parts_summary'] ?? '');
                    }
                }
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
        } else { $save_status = 'error'; $save_message = 'Failed to update workflow.'; }
    }
}

function workflowTabs(): array {
    return ['For Vehicle Endorsement', 'For Assessment', 'For Approval', 'For Parts Completion', 'For Repair', 'Ongoing Repair', 'For Release'];
}
function workflowBadge(string $status): string {
    $map = [
        'For Vehicle Endorsement' => 'warning text-dark',
        'For Assessment' => 'info text-dark',
        'For Approval' => 'primary',
        'For Parts Completion' => 'secondary',
        'For Repair' => 'dark',
        'Ongoing Repair' => 'success',
        'For Release' => 'success'
    ];
    return '<span class="badge bg-' . ($map[$status] ?? 'secondary') . '">' . h($status) . '</span>';
}
function nextActionHtml(array $r): string {
    $status = $r['workflow_status'] ?: ($r['status'] ?? 'For Vehicle Endorsement');
    $ris = (int)$r['ris_id'];
    if ($status === 'For Vehicle Endorsement') return '<button type="button" class="btn btn-success btn-sm btn-action-text" onclick="event.stopPropagation(); openReceiveVehicleModal(this.closest(\'tr\'))"><i class="bi bi-camera me-1"></i>Receive Vehicle</button>';
    if ($status === 'For Assessment') return '<button type="button" class="btn btn-primary btn-sm btn-action-text" onclick="event.stopPropagation(); openAssessmentModal(this.closest(\'tr\'))"><i class="bi bi-clipboard-plus me-1"></i>Seek for Approval</button>';
    if ($status === 'For Approval') return '<button type="button" class="btn btn-outline-primary btn-sm btn-action-text" disabled title="Waiting for Branch Admin approval"><i class="bi bi-hourglass-split me-1"></i>Waiting for Approval</button>';
    if ($status === 'For Parts Completion') return '<button type="button" class="btn btn-dark btn-sm btn-action-text" onclick="event.stopPropagation(); openPartsCompletionModalV4(this.closest(\'tr\'))"><i class="bi bi-box-seam me-1"></i>Parts Complete</button>';
    if ($status === 'For Repair') return '<button type="button" class="btn btn-success btn-sm btn-action-text" onclick="event.stopPropagation(); openStartRepairModalV11(this.closest(\'tr\'))"><i class="bi bi-tools me-1"></i>Start Repair</button>';
    if ($status === 'Ongoing Repair') return '<button type="button" class="btn btn-success btn-sm btn-action-text" onclick="event.stopPropagation(); openReleaseCompletionModalV15(this.closest(\'tr\'))"><i class="bi bi-truck me-1"></i>For Release</button>';
    return '<button type="button" class="btn btn-outline-success btn-sm btn-action-text" onclick="event.stopPropagation(); openRisDetailsModal(this.closest(\'tr\'))"><i class="bi bi-eye me-1"></i>View</button>';
}

function fetchRisRequests(mysqli $conn): array {
    $sql = "SELECT r.*, COALESCE(r.workflow_status, r.status, 'For Vehicle Endorsement') AS workflow_status,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS requested_by_name,
                   b.branch_name,
                   a.assessment_json, a.repairs_summary, a.parts_summary,
                   vr.receipt_id, vr.received_by_name, vr.received_datetime
            FROM motorpool_ris_requests r
            LEFT JOIN users u ON u.user_id = r.requested_by
            LEFT JOIN branches b ON b.branch_id = r.branch_id
            LEFT JOIN motorpool_ris_assessments a ON a.ris_id = r.ris_id
            LEFT JOIN motorpool_vehicle_receipts vr ON vr.ris_id = r.ris_id
            WHERE COALESCE(r.workflow_status, r.status, '') <> 'Completed'
              AND r.completed_at IS NULL
            ORDER BY FIELD(COALESCE(r.workflow_status, r.status), 'For Vehicle Endorsement','For Assessment','For Approval','For Parts Completion','For Repair','Ongoing Repair','For Release'), r.created_at DESC, r.ris_id DESC";
    $res = $conn->query($sql);
    $rows = [];
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

$risRequests = fetchRisRequests($conn);
$counts = array_fill_keys(workflowTabs(), 0);
foreach ($risRequests as $r) { $s = $r['workflow_status'] ?: 'For Vehicle Endorsement'; if (isset($counts[$s])) $counts[$s]++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Motorpool Request Handler</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
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

.btn-action-text {
    white-space: nowrap;
    border-radius: 8px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}

.stat-card {
    background: #ffffff;
    border: 1px solid #e3e8ef;
    border-radius: 14px;
    padding: 16px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, .04);
}

.stat-card small {
    color: #64748b;
    font-weight: 600;
}

.stat-card h3 {
    margin: 6px 0 0;
    color: #052A47;
    font-weight: 800;
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
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .info-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .stats-grid,
    .info-grid {
        grid-template-columns: 1fr;
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
                <span class="nav-text">Motorpool</span>
            </h3>
        </div>

        <div class="sidebar-content">
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="request_handler_english_final_v14.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="nav-text">RIS Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="motorpool.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Vehicle Profile</span>
                        </a>
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
            <button class="logout-btn-sidebar" onclick="logout()">
                <i class="bi bi-box-arrow-right"></i>
                <span class="logout-text">Logout</span>
            </button>
        </div>
    </div>

    <main class="main-content" id="mainContent">
        <div class="navbar-top">
            <button class="mobile-toggle-btn" id="mobileToggleBtn">
                <i class="bi bi-list"></i>
            </button>
            <div class="page-title">
                <h2>Motorpool Account</h2>
                <p>Sequential RIS workflow, assessment, parts, repair, and release</p>
            </div>
        </div>

        <div class="stats-grid">
            <?php foreach (workflowTabs() as $tab): ?>
                <div class="stat-card">
                    <small><?php echo h($tab); ?></small>
                    <h3><?php echo (int)$counts[$tab]; ?></h3>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Request for Inspection Slip</h5>
                    <small class="text-muted">All statuses are shown in one table.</small>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle">
                    <thead>
                        <tr>
                            <th>RIS No.</th>
                            <th>Date Requested</th>
                            <th>Branch</th>
                            <th>Plate No.</th>
                            <th>Vehicle Details</th>
                            <th>Concerns</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($risRequests)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                        No RIS requests found.
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($risRequests as $request): ?>
                                <?php $payload = h(jsonText($request)); ?>
                                <tr class="ris-row" data-ris='<?php echo $payload; ?>' onclick="openRisDetailsModal(this)">
                                    <td>
                                        <strong><?php echo h($request['ris_number']); ?></strong>
                                    </td>
                                    <td><?php echo h($request['date_requested']); ?></td>
                                    <td>
                                        <?php echo h(($request['branch_name'] ?? '') ?: ('Branch #' . ($request['branch_id'] ?? ''))); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo h($request['plate_no']); ?></strong><br>
                                        <small class="text-muted">Vehicle ID: <?php echo h($request['vehicle_id']); ?></small>
                                    </td>
                                    <td><?php echo h($request['vehicle_details'] ?: $request['vehicle_category']); ?></td>
                                    <td style="max-width: 280px;">
                                        <?php echo h(mb_strimwidth((string)$request['concerns'], 0, 80, '...')); ?>
                                    </td>
                                    <td><?php echo workflowBadge($request['workflow_status']); ?></td>
                                    <td class="text-end"><?php echo nextActionHtml($request); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
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
                <pre class="bg-light border rounded p-3" id="detailsAssessment"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade motorpool-modal" id="vehicleReceivedWorkflowModalV4" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
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
                            <input type="date" class="form-control" name="received_date" id="receiveDateV4" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Time Received <span class="required-mark">*</span></label>
                            <input type="time" class="form-control" name="received_time" id="receiveTimeV4" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Vehicle Photos with Timestamp <span class="required-mark">*</span></label>
                            <input type="file" class="form-control" name="received_photos[]" id="receivePhotosV4" accept="image/*" capture="environment" multiple required>
                            <div class="form-text">You may upload multiple photos. Each photo will be saved with an upload timestamp.</div>
                            <div id="receivePreviewV4" class="vehicle-received-proof-preview-grid"></div>
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

<div class="modal fade motorpool-modal" id="assessmentWorkflowModalV4" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="assessmentWorkflowFormV4">
                <input type="hidden" name="action" value="save_assessment_workflow_v3">
                <input type="hidden" name="assessment_ris_id" id="assessmentRisIdV4">
                <input type="hidden" name="assessment_json" id="assessmentJsonV4">

                <div class="modal-header motorpool-green">
                    <h5 class="modal-title">
                        <i class="bi bi-clipboard-plus me-2"></i>Assessment: Repair Work Required and Items / Parts Needed
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="info-grid mb-3" id="assessmentInfoGridV4"></div>
                    <div class="alert alert-info py-2 mb-3">
                        Each repair starts with three required item/part rows. Complete Item No., Description, Specification, Quantity, and Purpose before sending for approval.
                    </div>
                    <div id="repairBlocksV4"></div>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="addRepairBlockV4()">
                        <i class="bi bi-plus-circle me-1"></i>Add Another Repair
                    </button>
                    <div class="mt-3">
                        <label class="form-label">Assessed By <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="assessment_assessed_by" id="assessmentAssessedByV4" placeholder="Mechanic / Assessor name" required>
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
                <input type="hidden" name="action" value="advance_workflow_v3">
                <input type="hidden" name="advance_ris_id" id="partsCompletionRisIdV4">
                <input type="hidden" name="next_workflow_status" value="For Repair">

                <div class="modal-header motorpool-green">
                    <h5 class="modal-title">
                        <i class="bi bi-box-seam me-2"></i>Parts Completion Check
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="info-grid mb-3" id="partsCompletionInfoGridV4"></div>

                    <div class="alert alert-warning py-2 mb-3">
                        Verify that all required items/parts for the repair are complete before proceeding to <strong>For Repair</strong>.
                    </div>

                    <div class="section-title">Items / Parts Needed from Assessment</div>
                    <div id="partsCompletionAssessmentWrapV4" class="parts-completion-summary"></div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" value="1" id="partsCompletionConfirmV4" required>
                        <label class="form-check-label" for="partsCompletionConfirmV4">
                            I confirm that all listed items/parts needed are complete.
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">
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

                <div class="modal-header motorpool-green">
                    <h5 class="modal-title">
                        <i class="bi bi-tools me-2"></i>Start Repair Proof
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="info-grid mb-3" id="startRepairInfoGridV11"></div>

                    <div class="alert alert-warning py-2 mb-3">
                        Upload image proof before starting the repair. This is required before the status can move to <strong>Ongoing Repair</strong>.
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Image Proof/s <span class="required-mark">*</span></label>
                        <input type="file" class="form-control" name="start_repair_photos[]" id="startRepairPhotosV11" accept="image/*" capture="environment" multiple required>
                        <div class="form-text">You may upload multiple photos. Each photo will be saved with an upload timestamp.</div>
                    </div>

                    <div id="startRepairPreviewV11" class="vehicle-received-proof-preview-grid"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-play-circle me-1"></i>Start Repair
                    </button>
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
                        <i class="bi bi-check2-square me-2"></i>Repair Completion and Release
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-info py-2 mb-3">
                        Review the details that will be saved to the Branch Admin repair history, then attach proof that the repair is complete.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Date <span class="required-mark">*</span></label>
                            <input type="date" class="form-control" name="release_repair_date" id="releaseRepairDateV15" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">RIS No.</label>
                            <input type="text" class="form-control" id="releaseRisNoV15" readonly>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Parts Replaced <span class="required-mark">*</span></label>
                            <textarea class="form-control" name="release_parts_replaced" id="releasePartsReplacedV15" rows="3" required></textarea>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Mechanics <span class="required-mark">*</span></label>
                            <input type="text" class="form-control" name="release_mechanic" id="releaseMechanicV15" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Start Date <span class="required-mark">*</span></label>
                            <input type="date" class="form-control" name="release_start_date" id="releaseStartDateV15" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">End Date <span class="required-mark">*</span></label>
                            <input type="date" class="form-control" name="release_end_date" id="releaseEndDateV15" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Completion Proof Attachment <span class="required-mark">*</span></label>
                            <input type="file" class="form-control" name="release_attachment" id="releaseAttachmentV15" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" required>
                            <div class="form-text">Allowed files: JPG, PNG, WEBP, GIF, or PDF.</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check2-circle me-1"></i>Save to Repair History
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
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

function rowData(row) {
    try {
        return JSON.parse(row.getAttribute('data-ris') || '{}');
    } catch (error) {
        return {};
    }
}

function infoGrid(data) {
    return `
        <div class="info-item"><small>RIS No.</small><strong>${esc(data.ris_number)}</strong></div>
        <div class="info-item"><small>Branch</small><strong>${esc(data.branch_name || (data.branch_id ? 'Branch #' + data.branch_id : ''))}</strong></div>
        <div class="info-item"><small>Plate No.</small><strong>${esc(data.plate_no)}</strong></div>
        <div class="info-item"><small>Vehicle ID</small><strong>${esc(data.vehicle_id)}</strong></div>
        <div class="info-item"><small>Vehicle Details</small><strong>${esc(data.vehicle_details)}</strong></div>
        <div class="info-item"><small>Status</small><strong>${esc(data.workflow_status)}</strong></div>
    `;
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

    let assessmentText = 'No assessment yet.';
    if (data.repairs_summary || data.parts_summary) {
        assessmentText = 'Repair Work Required:\n' + (data.repairs_summary || '') + '\n\nItems / Parts Needed:\n' + (data.parts_summary || '');
    }
    document.getElementById('detailsAssessment').textContent = assessmentText;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('risDetailsModalV4')).show();
}

function openReceiveVehicleModal(row) {
    const data = rowData(row);
    const now = new Date();

    document.getElementById('receiveRisIdV4').value = data.ris_id || '';
    document.getElementById('receiveInfoGridV4').innerHTML = infoGrid(data);
    document.getElementById('receiveDateV4').value = now.toISOString().slice(0, 10);
    document.getElementById('receiveTimeV4').value = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    document.getElementById('receivePreviewV4').innerHTML = '';
    document.getElementById('receivePhotosV4').value = '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('vehicleReceivedWorkflowModalV4')).show();
}

document.getElementById('receivePhotosV4')?.addEventListener('change', function () {
    const grid = document.getElementById('receivePreviewV4');
    grid.innerHTML = '';

    [...this.files].forEach(function (file) {
        const url = URL.createObjectURL(file);
        grid.insertAdjacentHTML('beforeend', `
            <div>
                <img src="${url}" alt="Preview">
                <small class="text-muted d-block mt-1">${esc(file.name)}</small>
            </div>
        `);
    });
});

let repairIndexV4 = 0;

function partRowV4(itemNo = '', description = '', specification = '', quantity = '', purpose = '') {
    return `
        <tr class="part-row-v4">
            <td style="min-width: 120px;">
                <input class="form-control part-item-no" placeholder="Item No." value="${esc(itemNo)}" required>
            </td>
            <td style="min-width: 220px;">
                <input class="form-control part-description" placeholder="Description" value="${esc(description)}" required>
            </td>
            <td style="min-width: 220px;">
                <input class="form-control part-specification" placeholder="Specification" value="${esc(specification)}" required>
            </td>
            <td style="min-width: 120px;">
                <input type="number" min="1" step="1" class="form-control part-quantity" placeholder="Quantity" value="${esc(quantity)}" required>
            </td>
            <td style="min-width: 230px;">
                <input class="form-control part-purpose" placeholder="Purpose" value="${esc(purpose)}" required>
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
    const parts = existing?.parts || [];
    let rows = '';

    for (let i = 0; i < Math.max(3, parts.length); i++) {
        const part = parts[i] || {};
        rows += partRowV4(part.item_no || '', part.description || part.name || '', part.specification || '', part.quantity || part.qty || '', part.purpose || '');
    }

    document.getElementById('repairBlocksV4').insertAdjacentHTML('beforeend', `
        <div class="repair-block" data-repair-index="${repairId}">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">Repair Work Required</label>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.repair-block').remove()">
                    <i class="bi bi-trash"></i>
                </button>
            </div>

            <textarea class="form-control repair-text mb-3" rows="2" placeholder="Describe repair to make" required>${esc(repair)}</textarea>

            <div class="table-responsive">
                <table class="table table-bordered parts-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item No.</th>
                            <th>Description</th>
                            <th>Specification</th>
                            <th>Quantity</th>
                            <th>Purpose</th>
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
}

function addPartRowV4(repairId) {
    document.getElementById('partsBody' + repairId).insertAdjacentHTML('beforeend', partRowV4());
}

function removePartRowV4(button) {
    const tbody = button.closest('tbody');
    const rows = tbody ? tbody.querySelectorAll('tr') : [];

    if (rows.length <= 1) {
        Swal.fire({
            icon: 'warning',
            title: 'Cannot delete all rows',
            text: 'At least one item/part row must remain for this repair.',
            confirmButtonColor: '#07b83f'
        });
        return;
    }

    button.closest('tr').remove();
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

    bootstrap.Modal.getOrCreateInstance(document.getElementById('assessmentWorkflowModalV4')).show();
}

document.getElementById('assessmentWorkflowFormV4')?.addEventListener('submit', function (event) {
    const output = [];
    const assessedBy = document.getElementById('assessmentAssessedByV4').value.trim();

    document.querySelectorAll('#repairBlocksV4 .repair-block').forEach(function (block) {
        const repair = block.querySelector('.repair-text').value.trim();
        const parts = [];

        block.querySelectorAll('tbody tr').forEach(function (row) {
            const itemNo = row.querySelector('.part-item-no').value.trim();
            const description = row.querySelector('.part-description').value.trim();
            const specification = row.querySelector('.part-specification').value.trim();
            const quantity = row.querySelector('.part-quantity').value.trim();
            const purpose = row.querySelector('.part-purpose').value.trim();

            if (itemNo || description || specification || quantity || purpose) {
                parts.push({
                    item_no: itemNo,
                    description: description,
                    specification: specification,
                    quantity: quantity,
                    purpose: purpose
                });
            }
        });

        if (repair) {
            output.push({ repair, parts });
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
        if (!item.parts || !item.parts.length) return true;
        return item.parts.some(function (part) {
            return !part.item_no || !part.description || !part.specification || !part.quantity || !part.purpose;
        });
    });

    if (hasInvalidParts) {
        event.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Incomplete parts needed',
            text: 'Complete all required fields: Item No., Description, Specification, Quantity, and Purpose.',
            confirmButtonColor: '#07b83f'
        });
        return;
    }

    document.getElementById('assessmentJsonV4').value = JSON.stringify(output);
});


function renderPartsCompletionAssessmentV10(data) {
    let assessment = [];
    try {
        assessment = JSON.parse(data.assessment_json || '[]');
    } catch (error) {
        assessment = [];
    }

    if (!assessment.length) {
        return `
            <div class="alert alert-danger mb-0">
                No assessment details found. Please go back to assessment first.
            </div>
        `;
    }

    let html = '';

    assessment.forEach(function (repairItem, index) {
        const repairTitle = repairItem.repair || `Repair ${index + 1}`;
        const parts = Array.isArray(repairItem.parts) ? repairItem.parts : [];

        html += `
            <div class="repair-block mb-3">
                <div class="mb-2">
                    <label class="form-label mb-1">Repair Work Required</label>
                    <div class="form-control bg-light">${esc(repairTitle)}</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered parts-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Item No.</th>
                                <th>Description</th>
                                <th>Specification</th>
                                <th>Quantity</th>
                                <th>Purpose</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        if (!parts.length) {
            html += `
                <tr>
                    <td colspan="5" class="text-center text-muted py-3">No items/parts listed.</td>
                </tr>
            `;
        } else {
            parts.forEach(function (part) {
                html += `
                    <tr>
                        <td>${esc(part.item_no || '')}</td>
                        <td>${esc(part.description || part.name || '')}</td>
                        <td>${esc(part.specification || '')}</td>
                        <td>${esc(part.quantity || part.qty || '')}</td>
                        <td>${esc(part.purpose || '')}</td>
                    </tr>
                `;
            });
        }

        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    const assessedBy = assessment[0]?.assessed_by_global || assessment[0]?.assessed_by || '';
    if (assessedBy) {
        html += `
            <div class="mt-3">
                <label class="form-label">Assessed By</label>
                <div class="form-control bg-light">${esc(assessedBy)}</div>
            </div>
        `;
    }

    return html;
}

function openPartsCompletionModalV4(row) {
    const data = rowData(row);

    document.getElementById('partsCompletionRisIdV4').value = data.ris_id || '';
    document.getElementById('partsCompletionInfoGridV4').innerHTML = infoGrid(data);
    document.getElementById('partsCompletionAssessmentWrapV4').innerHTML = renderPartsCompletionAssessmentV10(data);
    document.getElementById('partsCompletionConfirmV4').checked = false;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('partsCompletionModalV4')).show();
}

document.getElementById('partsCompletionFormV4')?.addEventListener('submit', function (event) {
    const confirmed = document.getElementById('partsCompletionConfirmV4').checked;
    if (!confirmed) {
        event.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Confirmation needed',
            text: 'Please confirm that all required items/parts are complete before proceeding to For Repair.',
            confirmButtonColor: '#07b83f'
        });
    }
});

function openStartRepairModalV11(row) {
    const data = rowData(row);
    document.getElementById('startRepairRisIdV11').value = data.ris_id || '';
    document.getElementById('startRepairInfoGridV11').innerHTML = infoGrid(data);
    document.getElementById('startRepairPhotosV11').value = '';
    document.getElementById('startRepairPreviewV11').innerHTML = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('startRepairProofModalV11')).show();
}

document.getElementById('startRepairPhotosV11')?.addEventListener('change', function () {
    const preview = document.getElementById('startRepairPreviewV11');
    preview.innerHTML = '';
    Array.from(this.files || []).forEach(function (file) {
        if (!file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = function (event) {
            const card = document.createElement('div');
            card.className = 'vehicle-received-proof-preview-card';
            card.innerHTML = `
                <img src="${event.target.result}" alt="Start repair proof">
                <small>${esc(file.name)}<br>${new Date().toLocaleString()}</small>
            `;
            preview.appendChild(card);
        };
        reader.readAsDataURL(file);
    });
});

document.getElementById('startRepairProofFormV11')?.addEventListener('submit', function (event) {
    const photos = document.getElementById('startRepairPhotosV11');
    if (!photos.files || photos.files.length === 0) {
        event.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Image proof required',
            text: 'Please attach image proof before starting the repair.',
            confirmButtonColor: '#07b83f'
        });
    }
});



function openReleaseCompletionModalV15(row) {
    const data = rowData(row);
    const today = new Date().toISOString().slice(0, 10);

    document.getElementById('releaseRisIdV15').value = data.ris_id || '';
    document.getElementById('releaseRepairDateV15').value = data.repair_end_date || today;
    document.getElementById('releaseRisNoV15').value = data.ris_number || '';
    document.getElementById('releasePartsReplacedV15').value = data.parts_replaced || data.parts_summary || '';
    document.getElementById('releaseMechanicV15').value = data.mechanic || '';
    document.getElementById('releaseStartDateV15').value = data.repair_start_date || '';
    document.getElementById('releaseEndDateV15').value = data.repair_end_date || today;
    document.getElementById('releaseAttachmentV15').value = '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('releaseCompletionModalV15')).show();
}

document.getElementById('releaseCompletionFormV15')?.addEventListener('submit', function (event) {
    const requiredFields = [
        ['releaseRepairDateV15', 'Date'],
        ['releasePartsReplacedV15', 'Parts Replaced'],
        ['releaseMechanicV15', 'Mechanics'],
        ['releaseStartDateV15', 'Start Date'],
        ['releaseEndDateV15', 'End Date']
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
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#07b83f',
        confirmButtonText: 'Yes, logout'
    }).then(function (result) {
        if (result.isConfirmed) {
            window.location.href = '../logout.php';
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('mainContent');
    const desktopToggle = document.getElementById('desktopToggleBtn');
    const mobileToggle = document.getElementById('mobileToggleBtn');

    desktopToggle?.addEventListener('click', function () {
        sidebar.classList.toggle('collapsed');
        main.classList.toggle('expanded');
    });

    mobileToggle?.addEventListener('click', function () {
        sidebar.classList.toggle('active');
    });

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
</script>
</body>
</html>
