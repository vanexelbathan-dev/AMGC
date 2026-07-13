<?php
if (isset($_POST['action'])) {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}
require_once '../config/database.php';
require_once '../config/session_handler.php';
if (function_exists('date_default_timezone_set')) date_default_timezone_set('Asia/Manila');
if ($conn) $conn->query("SET time_zone = '+08:00'");
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$user_name = trim((string)(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;
if ($user_id <= 0) { header('Location: ../login.php'); exit; }
if ($user_name === '') $user_name = 'User';
$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) { if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1)); }
if ($user_initials === '') $user_initials = 'U';
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function jsonResponse(array $response): void { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($response); exit; }
function tableExists(mysqli $conn, string $table): bool { $safe = $conn->real_escape_string($table); $res = $conn->query("SHOW TABLES LIKE '$safe'"); return $res && $res->num_rows > 0; }
function ensureITRequestTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS it_requests (request_id INT AUTO_INCREMENT PRIMARY KEY,ticket_no VARCHAR(40) NOT NULL UNIQUE,branch_id INT NULL,source_account ENUM('branch_admin','motorpool') NOT NULL DEFAULT 'branch_admin',requested_by_user_id INT NULL,handled_by_user_id INT NULL,subject VARCHAR(255) NOT NULL,details TEXT NOT NULL,priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',status ENUM('pending','in_progress','resolved','cancelled') NOT NULL DEFAULT 'pending',it_notes TEXT NULL,resolved_at DATETIME NULL,created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_it_branch (branch_id),INDEX idx_it_status (status),INDEX idx_it_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS it_request_attachments (attachment_id INT AUTO_INCREMENT PRIMARY KEY,request_id INT NOT NULL,original_name VARCHAR(255) NOT NULL,stored_name VARCHAR(255) NOT NULL,file_path VARCHAR(500) NOT NULL,mime_type VARCHAR(120) NULL,file_size INT NULL,uploaded_by_user_id INT NULL,created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_ira_request (request_id),CONSTRAINT fk_ira_request FOREIGN KEY (request_id) REFERENCES it_requests(request_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS it_request_history (history_id INT AUTO_INCREMENT PRIMARY KEY,request_id INT NOT NULL,action VARCHAR(80) NOT NULL,status_from VARCHAR(40) NULL,status_to VARCHAR(40) NULL,priority_from VARCHAR(40) NULL,priority_to VARCHAR(40) NULL,notes TEXT NULL,changed_by_user_id INT NULL,changed_by_name VARCHAR(180) NULL,created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_irh_request (request_id),CONSTRAINT fk_irh_request FOREIGN KEY (request_id) REFERENCES it_requests(request_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensureITRequestTable($conn);

function addITRequestHistory(mysqli $conn, int $request_id, string $action, ?string $status_from, ?string $status_to, ?string $priority_from, ?string $priority_to, ?string $notes, int $changed_by_user_id, string $changed_by_name): void {
    $stmt = $conn->prepare("INSERT INTO it_request_history (request_id, action, status_from, status_to, priority_from, priority_to, notes, changed_by_user_id, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param('issssssis', $request_id, $action, $status_from, $status_to, $priority_from, $priority_to, $notes, $changed_by_user_id, $changed_by_name);
        $stmt->execute();
        $stmt->close();
    }
}
function saveITRequestAttachments(mysqli $conn, int $request_id, int $uploaded_by_user_id, string $field_name = 'attachments'): int {
    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) return 0;
    $upload_dir = realpath(__DIR__ . '/..');
    if ($upload_dir === false) $upload_dir = dirname(__DIR__);
    $target_dir = $upload_dir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'it_requests';
    if (!is_dir($target_dir)) mkdir($target_dir, 0775, true);
    $allowed_ext = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt','csv','zip','rar'];
    $names = is_array($_FILES[$field_name]['name']) ? $_FILES[$field_name]['name'] : [$_FILES[$field_name]['name']];
    $tmp_names = is_array($_FILES[$field_name]['tmp_name']) ? $_FILES[$field_name]['tmp_name'] : [$_FILES[$field_name]['tmp_name']];
    $errors = is_array($_FILES[$field_name]['error']) ? $_FILES[$field_name]['error'] : [$_FILES[$field_name]['error']];
    $sizes = is_array($_FILES[$field_name]['size']) ? $_FILES[$field_name]['size'] : [$_FILES[$field_name]['size']];
    $types = is_array($_FILES[$field_name]['type']) ? $_FILES[$field_name]['type'] : [$_FILES[$field_name]['type']];
    $saved = 0;
    for ($i = 0; $i < count($names); $i++) {
        if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || trim((string)$names[$i]) === '') continue;
        if (($errors[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new Exception('Attachment upload failed: ' . $names[$i]);
        if ((int)$sizes[$i] > 10 * 1024 * 1024) throw new Exception('Attachment is too large. Maximum is 10MB per file.');
        $original = basename((string)$names[$i]);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed_ext, true)) throw new Exception('File type not allowed: ' . $original);
        $safe_base = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($original, PATHINFO_FILENAME));
        $stored = 'ITREQ_' . $request_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safe_base . '.' . $ext;
        $destination = $target_dir . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file((string)$tmp_names[$i], $destination)) throw new Exception('Unable to save attachment: ' . $original);
        $web_path = '../uploads/it_requests/' . $stored;
        $mime = (string)($types[$i] ?? 'application/octet-stream');
        $size = (int)($sizes[$i] ?? 0);
        $stmt = $conn->prepare("INSERT INTO it_request_attachments (request_id, original_name, stored_name, file_path, mime_type, file_size, uploaded_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('issssii', $request_id, $original, $stored, $web_path, $mime, $size, $uploaded_by_user_id);
            $stmt->execute();
            $stmt->close();
        }
        $saved++;
    }
    return $saved;
}
function getITRequestAttachments(mysqli $conn, array $request_ids): array {
    $out = [];
    $ids = array_values(array_filter(array_map('intval', $request_ids)));
    if (!$ids) return $out;
    $in = implode(',', $ids);
    $res = $conn->query("SELECT * FROM it_request_attachments WHERE request_id IN ($in) ORDER BY created_at ASC, attachment_id ASC");
    if ($res) while ($row = $res->fetch_assoc()) $out[(int)$row['request_id']][] = $row;
    return $out;
}
function getITRequestHistory(mysqli $conn, array $request_ids): array {
    $out = [];
    $ids = array_values(array_filter(array_map('intval', $request_ids)));
    if (!$ids) return $out;
    $in = implode(',', $ids);
    $res = $conn->query("SELECT * FROM it_request_history WHERE request_id IN ($in) ORDER BY created_at DESC, history_id DESC");
    if ($res) while ($row = $res->fetch_assoc()) $out[(int)$row['request_id']][] = $row;
    return $out;
}
function formatBytes($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function nextTicketNo(mysqli $conn): string {
    $prefix = 'IT-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT ticket_no FROM it_requests WHERE ticket_no LIKE CONCAT(?, '%') ORDER BY request_id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = 1;
    if ($row && !empty($row['ticket_no'])) $next = ((int)substr($row['ticket_no'], -4)) + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}
$branch_name = 'All Branches';
if ($branch_id > 0 && tableExists($conn, 'branches')) {
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
    if ($stmt) { $stmt->bind_param('i', $branch_id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); if ($row) $branch_name = $row['branch_name']; $stmt->close(); }
}
?>
<?php
if (!in_array($user_role, ['it','admin','super_duper_admin'], true)) { header('Location: ../login.php'); exit; }
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_request') {
    try {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'pending'));
        $priority = trim((string)($_POST['priority'] ?? 'medium'));
        $it_notes = trim((string)($_POST['it_notes'] ?? ''));
        if (!in_array($status, ['pending','in_progress','resolved','cancelled'], true)) $status = 'pending';
        if (!in_array($priority, ['low','medium','high','urgent'], true)) $priority = 'medium';
        if ($request_id <= 0) throw new Exception('Invalid request.');
        $old_stmt = $conn->prepare("SELECT status, priority, it_notes FROM it_requests WHERE request_id=? LIMIT 1");
        $old_stmt->bind_param('i', $request_id);
        $old_stmt->execute();
        $old_request = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();
        if (!$old_request) throw new Exception('Request not found.');
        if ($status === 'resolved') {
            $stmt = $conn->prepare("UPDATE it_requests SET status=?, priority=?, it_notes=?, handled_by_user_id=?, resolved_at=COALESCE(resolved_at, NOW()), updated_at=NOW() WHERE request_id=?");
            $stmt->bind_param('sssii', $status, $priority, $it_notes, $user_id, $request_id);
        } else {
            $stmt = $conn->prepare("UPDATE it_requests SET status=?, priority=?, it_notes=?, handled_by_user_id=?, resolved_at=NULL, updated_at=NOW() WHERE request_id=?");
            $stmt->bind_param('sssii', $status, $priority, $it_notes, $user_id, $request_id);
        }
        $stmt->execute();
        $stmt->close();
        $history_notes = 'Status/Priority updated';
        if ($it_notes !== trim((string)($old_request['it_notes'] ?? ''))) $history_notes .= ($history_notes ? '; ' : '') . 'IT notes updated';
        addITRequestHistory($conn, $request_id, 'Updated Request', (string)$old_request['status'], $status, (string)$old_request['priority'], $priority, $history_notes, $user_id, $user_name);
        $success_message = 'Request updated successfully.';
    } catch (Throwable $e) { $error_message = $e->getMessage(); }
}
$status_filter = trim((string)($_GET['status'] ?? ''));
$branch_filter = (int)($_GET['branch_id'] ?? 0);
$source_filter = trim((string)($_GET['source'] ?? ''));
$where = [];$params = [];$types = '';
if ($status_filter !== '' && in_array($status_filter, ['pending','in_progress','resolved','cancelled'], true)) { $where[]='ir.status=?'; $params[]=$status_filter; $types.='s'; }
if ($branch_filter > 0) { $where[]='ir.branch_id=?'; $params[]=$branch_filter; $types.='i'; }
if ($source_filter !== '' && in_array($source_filter, ['branch_admin','motorpool'], true)) { $where[]='ir.source_account=?'; $params[]=$source_filter; $types.='s'; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$requests = [];$branches=[];$counts=['pending'=>0,'in_progress'=>0,'resolved'=>0,'cancelled'=>0];
if (tableExists($conn, 'branches')) { $res=$conn->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name ASC"); if($res){while($row=$res->fetch_assoc())$branches[]=$row;} }
$sql = "SELECT ir.*, b.branch_name, CONCAT(COALESCE(req.first_name,''),' ',COALESCE(req.last_name,'')) AS requested_by, CONCAT(COALESCE(it.first_name,''),' ',COALESCE(it.last_name,'')) AS handled_by FROM it_requests ir LEFT JOIN branches b ON b.branch_id=ir.branch_id LEFT JOIN users req ON req.user_id=ir.requested_by_user_id LEFT JOIN users it ON it.user_id=ir.handled_by_user_id $where_sql ORDER BY FIELD(ir.status,'pending','in_progress','resolved','cancelled'), ir.created_at DESC, ir.request_id DESC";
$stmt=$conn->prepare($sql);
if($stmt){if($types!=='')$stmt->bind_param($types,...$params);$stmt->execute();$requests=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();}
$res=$conn->query("SELECT status, COUNT(*) total FROM it_requests GROUP BY status");
if($res){while($row=$res->fetch_assoc()){if(isset($counts[$row['status']]))$counts[$row['status']]=(int)$row['total'];}}
$request_ids = array_map(static fn($r) => (int)$r['request_id'], $requests);
$attachments_by_request = getITRequestAttachments($conn, $request_ids);
$history_by_request = getITRequestHistory($conn, $request_ids);
$total_requests = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IT Request Dashboard - AMGC</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
<link rel="shortcut icon" href="../Pictures/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
<link rel="manifest" href="../Pictures/site.webmanifest" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../js/session-checker.js"></script>
<style>

.stat-card-row{margin-bottom:1.5rem;}
.stat-card{background:transparent!important;border:none!important;box-shadow:0 4px 10px rgba(0,0,0,.08)!important;min-height:auto!important;height:auto!important;padding:.8rem!important;transition:transform .2s ease,box-shadow .2s ease!important;cursor:default!important;display:flex!important;align-items:center!important;gap:.85rem!important;border-radius:14px!important;overflow:hidden!important;}
.stat-card.total,.stat-card.pending,.stat-card.complete,.stat-card.delivery{background:linear-gradient(135deg,#047857,#059669)!important;border:none!important;}
.stat-card .stat-value,.stat-card .stat-label,.stat-card .stat-content,.stat-card small,.stat-card small i,.stat-card .badge{color:#fff!important;}
.stat-card .stat-content,.stat-card .stat-icon{background:transparent!important;}
.stat-card:hover{transform:translateY(-2px)!important;box-shadow:0 6px 15px rgba(0,0,0,.15)!important;}
.stat-card .stat-icon{font-size:1.65rem!important;color:#fff!important;opacity:.95!important;flex-shrink:0!important;}
.stat-card .stat-value{font-size:1.35rem!important;font-weight:900!important;line-height:1.1!important;margin:0!important;}
.stat-card .stat-label{font-size:.78rem!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:.35px!important;opacity:.95!important;margin:0!important;}
.tab-card{background:#fff;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:24px;}
.employee-toolbar,.payroll-tab-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.payroll-search-wrap{position:relative;max-width:420px;width:100%;}
.payroll-search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;z-index:1;}
.payroll-search-wrap input{padding-left:40px;border:1px solid #e2e8f0;border-radius:12px;height:42px;font-size:.88rem;}
.payroll-actions{display:flex;gap:8px;flex-wrap:wrap;}
.btn-payroll-green{background:linear-gradient(135deg,#047857,#44D34E)!important;color:#fff!important;border:0!important;border-radius:12px!important;padding:9px 14px!important;font-weight:600!important;box-shadow:0 8px 18px rgba(4,120,87,.18)!important;}
.btn-payroll-light{background:#f8fafc!important;color:#052A47!important;border:1px solid #e2e8f0!important;border-radius:12px!important;padding:9px 14px!important;font-weight:800!important;}
.employee-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:auto;}
.employee-table thead th{background:#052A47!important;color:#fff!important;font-weight:600;font-size:.82rem;padding:14px 12px;border:0;white-space:nowrap;}
.employee-table thead th:first-child{border-top-left-radius:14px;}
.employee-table thead th:last-child{border-top-right-radius:14px;}
.employee-table tbody td{padding:14px 12px;border-bottom:1px solid #eef2f7;vertical-align:middle;color:#263238;font-size:.9rem;}
.employee-table tbody tr:hover{background:#f8fbff;}
.it-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 10px;font-size:.72rem;font-weight:800;text-transform:uppercase;white-space:nowrap;}
.it-badge.pending{background:#fff7ed;color:#c2410c;}
.it-badge.in_progress{background:#dbeafe;color:#1d4ed8;}
.it-badge.resolved{background:#dcfce7;color:#047857;}
.it-badge.cancelled{background:#f1f5f9;color:#475569;}
.it-badge.low{background:#f1f5f9;color:#475569;}
.it-badge.medium{background:#dcfce7;color:#047857;}
.it-badge.high{background:#fef3c7;color:#b45309;}
.it-badge.urgent{background:#fee2e2;color:#b91c1c;}
.it-empty{text-align:center;padding:42px 12px;color:#64748b;}
.it-empty i{font-size:2.4rem;color:#94a3b8;display:block;margin-bottom:8px;}
.form-label{font-size:.76rem;text-transform:uppercase;letter-spacing:.35px;color:#64748b;font-weight:800;}
.form-control,.form-select{border-radius:8px;border-color:#d8dee6;}
.form-control:focus,.form-select:focus{border-color:#10b981;box-shadow:0 0 0 .2rem rgba(16,185,129,.12);}
@media(max-width:991px){.stat-card{aspect-ratio:1/1!important;display:flex!important;flex-direction:column!important;justify-content:center!important;align-items:center!important;text-align:center!important;padding:.5rem!important;}.stat-card .stat-icon{margin:0 auto .3rem auto!important;font-size:1.6rem!important;}.stat-card .stat-value{font-size:1.2rem!important;text-align:center!important;width:100%!important;}.stat-card .stat-label{font-size:.65rem!important;text-align:center!important;width:100%!important;}.stat-card-row .col{padding-left:.25rem!important;padding-right:.25rem!important;}}
@media(max-width:576px){.tab-card{padding:12px}.employee-table{font-size:.8rem}.payroll-actions .btn{width:100%;}.payroll-search-wrap{max-width:100%;}}

.it-form-card,.it-table-card{background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.06);border:1px solid rgba(7,216,38,.12);overflow:hidden;margin-bottom:24px}.it-card-header{background:#059669;color:#fff;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}.it-card-header h5{margin:0;font-weight:800;font-size:1.05rem}.it-card-body{padding:18px}.it-table{width:100%;border-collapse:collapse;margin:0}.it-table thead th{background:#047857;color:#fff;text-transform:uppercase;font-size:.74rem;font-weight:800;letter-spacing:.35px;padding:13px 12px;white-space:nowrap}.it-table tbody td{padding:13px 12px;border-bottom:1px solid #e5e7eb;font-size:.84rem;vertical-align:middle}.it-table tbody tr:hover{background:#f8fafc}.it-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 10px;font-size:.72rem;font-weight:800;text-transform:uppercase}.it-badge.pending{background:#fff7ed;color:#c2410c}.it-badge.in_progress{background:#dbeafe;color:#1d4ed8}.it-badge.resolved{background:#dcfce7;color:#047857}.it-badge.cancelled{background:#f1f5f9;color:#475569}.it-badge.low{background:#f1f5f9;color:#475569}.it-badge.medium{background:#dcfce7;color:#047857}.it-badge.high{background:#fef3c7;color:#b45309}.it-badge.urgent{background:#fee2e2;color:#b91c1c}.it-empty{text-align:center;padding:42px 12px;color:#64748b}.it-empty i{font-size:2.4rem;color:#94a3b8;display:block;margin-bottom:8px}.it-search-wrapper{width:320px;max-width:100%}.it-search-wrapper .input-group{border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc}.it-search-wrapper .input-group-text,.it-search-wrapper .form-control{border:0;background:#f8fafc}.it-search-wrapper .form-control:focus{box-shadow:none;background:#fff}.btn-success{background:#10b981!important;border-color:#10b981!important}.btn-dark{background:#1f2937!important;border-color:#1f2937!important}.form-label{font-size:.76rem;text-transform:uppercase;letter-spacing:.35px;color:#64748b;font-weight:800}.form-control,.form-select{border-radius:8px;border-color:#d8dee6}.form-control:focus,.form-select:focus{border-color:#10b981;box-shadow:0 0 0 .2rem rgba(16,185,129,.12)}@media(max-width:768px){.it-card-header{flex-direction:column;align-items:stretch}.it-search-wrapper{width:100%}}
.clickable-request-row{cursor:pointer!important}.clickable-request-row:hover{background:#f0fdf4!important}

/* System themed modal palette for clickable IT request rows */
.request-system-modal{border:0!important;border-radius:18px!important;overflow:hidden!important;box-shadow:0 18px 55px rgba(5,42,71,.22)!important;background:#fff!important;}
.request-system-modal .modal-header{background:linear-gradient(135deg,#047857,#07d826)!important;color:#fff!important;border-bottom:0!important;padding:18px 22px!important;}
.request-system-modal .modal-title{color:#fff!important;font-weight:900!important;letter-spacing:.25px!important;display:flex!important;align-items:center!important;gap:8px!important;}
.request-system-modal .modal-title:before{content:'\F3E2';font-family:'bootstrap-icons';font-weight:400!important;}
.request-system-modal .modal-header small{color:rgba(255,255,255,.88)!important;font-weight:600!important;}
.request-system-modal .btn-close{filter:brightness(0) invert(1)!important;opacity:.95!important;}
.request-system-modal .modal-body{background:#f8fafc!important;padding:20px!important;}
.request-system-modal .modal-footer{background:#fff!important;border-top:1px solid #e5e7eb!important;padding:14px 20px!important;}
.request-system-modal .request-detail-card{background:#fff!important;border:1px solid rgba(7,216,38,.14)!important;border-radius:16px!important;box-shadow:0 4px 18px rgba(5,42,71,.06)!important;padding:16px!important;}
.request-system-modal .request-detail-card strong{color:#052A47!important;font-weight:900!important;text-transform:uppercase!important;font-size:.82rem!important;letter-spacing:.35px!important;}
.request-system-modal h5{color:#052A47!important;font-weight:900!important;}
.request-system-modal .form-label{color:#047857!important;font-weight:900!important;}
.request-system-modal .form-control,.request-system-modal .form-select{border-radius:10px!important;border:1px solid #d8dee6!important;background:#fff!important;}
.request-system-modal .form-control[readonly],.request-system-modal textarea[readonly]{background:#f8fafc!important;color:#334155!important;}
.request-system-modal .list-group-item{border:1px solid #e5e7eb!important;border-radius:12px!important;margin-bottom:8px!important;transition:all .2s ease!important;}
.request-system-modal .list-group-item:hover{background:#f0fdf4!important;border-color:#10b981!important;transform:translateY(-1px)!important;}
.request-system-modal .request-history-table{border-collapse:separate!important;border-spacing:0!important;overflow:hidden!important;border-radius:14px!important;}
.request-system-modal .request-history-table thead th{background:linear-gradient(135deg,#047857,#059669)!important;color:#fff!important;border:0!important;padding:11px 10px!important;}
.request-system-modal .request-history-table tbody td{background:#fff!important;border-bottom:1px solid #edf2f7!important;padding:11px 10px!important;}
.request-system-modal .request-history-table tbody tr:hover td{background:#f0fdf4!important;}
.request-system-modal .btn-light{background:#f8fafc!important;border:1px solid #e2e8f0!important;color:#052A47!important;border-radius:12px!important;font-weight:800!important;padding:9px 16px!important;}
.request-system-modal .btn-payroll-green,.request-system-modal .btn-success{background:linear-gradient(135deg,#047857,#07d826)!important;border:0!important;color:#fff!important;border-radius:12px!important;font-weight:800!important;padding:9px 16px!important;box-shadow:0 8px 18px rgba(4,120,87,.18)!important;}
.request-system-modal .attachment-preview-img{box-shadow:0 5px 16px rgba(5,42,71,.10)!important;}

.clickable-request-row{transition:background .2s ease!important}


.history-detail-modal .modal-content{border:0!important;border-radius:18px!important;overflow:hidden!important;box-shadow:0 20px 55px rgba(5,42,71,.22)!important;}
.history-detail-modal .modal-header{background:linear-gradient(135deg,#047857,#059669)!important;color:#fff!important;border:0!important;padding:16px 20px!important;}
.history-detail-modal .modal-title{font-weight:900!important;font-size:1rem!important;letter-spacing:.2px!important;}
.history-detail-modal .modal-body{background:#fff!important;padding:18px!important;}
.history-detail-box{border:1px solid #e5e7eb!important;border-radius:16px!important;overflow:hidden!important;background:#fff!important;}
.history-detail-item{display:grid!important;grid-template-columns:130px 1fr!important;gap:12px!important;padding:13px 15px!important;border-bottom:1px solid #edf2f7!important;align-items:start!important;}
.history-detail-item:last-child{border-bottom:0!important;}
.history-detail-item span{font-size:.74rem!important;font-weight:900!important;text-transform:uppercase!important;letter-spacing:.35px!important;color:#64748b!important;}
.history-detail-item strong,.history-detail-item div{font-size:.92rem!important;color:#052A47!important;font-weight:800!important;word-break:break-word!important;}
.history-detail-item.notes{display:block!important;}
.history-detail-item.notes span{display:block!important;margin-bottom:8px!important;}
.history-detail-item.notes div{background:#f8fafc!important;border:1px solid #e5e7eb!important;border-radius:12px!important;padding:12px!important;min-height:42px!important;font-weight:600!important;}
@media(max-width:576px){.history-detail-item{grid-template-columns:1fr!important;gap:4px!important;}}
.history-inline-detail{display:none;margin-top:14px;border:1px solid rgba(4,120,87,.18)!important;border-radius:16px!important;overflow:hidden!important;background:#f8fafc!important;box-shadow:0 8px 22px rgba(5,42,71,.08)!important;}
.history-inline-detail.active{display:block!important;}
.history-inline-header{background:linear-gradient(135deg,#047857,#059669)!important;color:#fff!important;padding:12px 14px!important;font-weight:900!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:10px!important;}
.history-inline-close{border:0!important;background:rgba(255,255,255,.18)!important;color:#fff!important;border-radius:999px!important;width:28px!important;height:28px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;}
.history-inline-body{padding:14px!important;background:#fff!important;}
.history-inline-grid{display:grid!important;grid-template-columns:120px 1fr!important;gap:8px 12px!important;}
.history-inline-grid span{font-size:.72rem!important;font-weight:900!important;text-transform:uppercase!important;letter-spacing:.35px!important;color:#64748b!important;}
.history-inline-grid strong{font-size:.9rem!important;color:#052A47!important;font-weight:800!important;word-break:break-word!important;}
.history-inline-notes{margin-top:12px!important;background:#f8fafc!important;border:1px solid #e5e7eb!important;border-radius:12px!important;padding:12px!important;min-height:42px!important;color:#052A47!important;font-weight:600!important;white-space:pre-wrap!important;}
@media(max-width:576px){.history-inline-grid{grid-template-columns:1fr!important;gap:4px!important;}}



.attachment-viewer-modal .modal-content{border:0!important;border-radius:18px!important;overflow:hidden!important;box-shadow:0 18px 45px rgba(5,42,71,.22)!important;}
.attachment-viewer-modal .modal-header{background:linear-gradient(135deg,#047857,#07d826)!important;color:#fff!important;border:0!important;padding:16px 18px!important;}
.attachment-viewer-modal .modal-title{font-weight:900!important;font-size:1rem!important;}
.attachment-viewer-modal .btn-close{filter:brightness(0) invert(1)!important;opacity:.9!important;}
.attachment-viewer-modal .modal-body{background:#fff!important;padding:18px!important;}
.attachment-viewer-frame{width:100%;height:70vh;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc;}
.attachment-viewer-img{display:block;max-width:100%;max-height:70vh;margin:0 auto;border-radius:14px;border:1px solid #e5e7eb;background:#f8fafc;object-fit:contain;}
.attachment-viewer-file{border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;padding:34px;text-align:center;color:#052A47;}
.attachment-viewer-file i{font-size:3rem;color:#047857;display:block;margin-bottom:10px;}
.attachment-open-btn{cursor:pointer!important;position:relative!important;z-index:5!important;}
.attachment-open-btn:hover{background:#f0fdf4!important;border-color:rgba(4,120,87,.25)!important;}
.attachment-open-btn *{pointer-events:none!important;}
.attachment-viewer-modal{z-index:1085!important;}
.attachment-viewer-modal + .modal-backdrop{z-index:1080!important;}

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
            <span class="nav-text">IT Account</span>
        </h3>
    </div>
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" href="itdashboard.php">
                        <i class="bi bi-headset"></i>
                        <span class="nav-text">IT Requests</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar"><?php echo htmlspecialchars($user_initials); ?></div>
            <div class="user-details-sidebar">
                <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="user-role-sidebar"><?php echo htmlspecialchars(ucfirst($user_role)); ?></span>
            </div>
        </div>
        <button class="logout-btn-sidebar" onclick="logout()">
            <i class="bi bi-box-arrow-right"></i>
            <span class="logout-text">Logout</span>
        </button>
    </div>
</div>
<div class="main-content" id="mainContent">
    <div class="page-content active">
        <div class="navbar-top">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>IT Request Dashboard</h2>
                <p>Monitor and update service requests</p>
            </div>
        </div>
        <?php if (!empty($success_message)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: <?= json_encode($success_message) ?>,
                confirmButtonColor: '#047857',
                timer: 2200,
                showConfirmButton: false
            });
        });
        </script>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?= json_encode($error_message) ?>,
                confirmButtonColor: '#dc3545'
            });
        });
        </script>
        <?php endif; ?>
        <div class="row stat-card-row g-1 g-sm-2 mb-4">
            <div class="col"><div class="stat-card total"><i class="bi bi-ticket-detailed stat-icon"></i><div class="stat-content"><div class="stat-value"><?= (int)$total_requests ?></div><div class="stat-label">Total Requests</div></div></div></div>
            <div class="col"><div class="stat-card pending"><i class="bi bi-hourglass-split stat-icon"></i><div class="stat-content"><div class="stat-value"><?= (int)$counts['pending'] ?></div><div class="stat-label">Pending</div></div></div></div>
            <div class="col"><div class="stat-card delivery"><i class="bi bi-tools stat-icon"></i><div class="stat-content"><div class="stat-value"><?= (int)$counts['in_progress'] ?></div><div class="stat-label">In Progress</div></div></div></div>
            <div class="col"><div class="stat-card complete"><i class="bi bi-check2-circle stat-icon"></i><div class="stat-content"><div class="stat-value"><?= (int)$counts['resolved'] ?></div><div class="stat-label">Resolved</div></div></div></div>
        </div>
        <div class="mb-4"><div class="tab-card">
            <div class="payroll-tab-toolbar"><div><strong><i class="bi bi-funnel me-2"></i>Filters</strong></div></div>
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-lg-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All Status</option><option value="pending" <?= $status_filter==='pending'?'selected':'' ?>>Pending</option><option value="in_progress" <?= $status_filter==='in_progress'?'selected':'' ?>>In Progress</option><option value="resolved" <?= $status_filter==='resolved'?'selected':'' ?>>Resolved</option><option value="cancelled" <?= $status_filter==='cancelled'?'selected':'' ?>>Cancelled</option></select></div>
                    <div class="col-lg-3"><label class="form-label">Branch</label><select name="branch_id" class="form-select"><option value="0">All Branches</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['branch_id'] ?>" <?= $branch_filter===(int)$branch['branch_id']?'selected':'' ?>><?= h($branch['branch_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-lg-3"><label class="form-label">Source</label><select name="source" class="form-select"><option value="">All Sources</option><option value="branch_admin" <?= $source_filter==='branch_admin'?'selected':'' ?>>Branch Admin</option><option value="motorpool" <?= $source_filter==='motorpool'?'selected':'' ?>>Motorpool</option></select></div>
                    <div class="col-lg-3 d-flex gap-2"><button type="submit" class="btn btn-success flex-fill"><i class="bi bi-funnel me-1"></i>Apply</button><a href="itdashboard.php" class="btn btn-payroll-light"><i class="bi bi-arrow-clockwise"></i></a></div>
                </form>
        </div></div>
        <div class="mb-4"><div class="tab-card">
            <div class="payroll-tab-toolbar">
                <div><strong><i class="bi bi-list-check me-1"></i>Requests</strong></div>
                <div class="payroll-search-wrap"><i class="bi bi-search"></i><input type="text" class="form-control" id="requestSearch" placeholder="Search requests"></div>
            </div>
            <div class="table-responsive">
                <table class="employee-table" id="requestTable">
                    <thead><tr><th>Ticket</th><th>Date</th><th>Branch</th><th>Source</th><th>Requested By</th><th>Subject</th><th>Priority</th><th>Status</th><th>Handled By</th><th>Files</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="11"><div class="it-empty"><i class="bi bi-inbox"></i>No requests found.</div></td></tr>
                    <?php else: foreach ($requests as $request): ?>
                        <tr class="clickable-request-row" data-bs-toggle="modal" data-bs-target="#requestModal<?= (int)$request['request_id'] ?>" title="Click to view request details and history">
                            <td class="fw-bold text-success"><?= h($request['ticket_no'] ?? '') ?></td>
                            <td><?= !empty($request['created_at']) ? h(date('M d, Y h:i A', strtotime($request['created_at']))) : '-' ?></td>
                            <td><?= h($request['branch_name'] ?: ('Branch #' . (int)$request['branch_id'])) ?></td>
                            <td><?= h($request['source_account']==='motorpool' ? 'Motorpool' : 'Branch Admin') ?></td>
                            <td><?= h(trim((string)($request['requested_by'] ?? '')) ?: 'User') ?></td>
                            <td><div class="fw-bold"><?= h($request['subject'] ?? '') ?></div><div class="text-muted small"><?= h($request['details'] ?? '') ?></div></td>
                            <td><span class="it-badge <?= h($request['priority'] ?? 'medium') ?>"><?= h($request['priority'] ?? 'medium') ?></span></td>
                            <td><span class="it-badge <?= h($request['status'] ?? 'pending') ?>"><?= h(str_replace('_',' ', $request['status'] ?? 'pending')) ?></span></td>
                            <td><?= h(trim((string)($request['handled_by'] ?? '')) ?: '-') ?></td>
                            <td><?php $ac = count($attachments_by_request[(int)$request['request_id']] ?? []); ?><span class="it-badge <?= $ac > 0 ? 'medium' : 'low' ?>"><i class="bi bi-paperclip me-1"></i><?= $ac ?></span></td>
                            <td><button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#requestModal<?= (int)$request['request_id'] ?>" onclick="event.stopPropagation();"><i class="bi bi-eye"></i></button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php foreach ($requests as $request): ?>
<div class="modal fade" id="requestModal<?= (int)$request['request_id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content request-system-modal">
            <form method="POST" class="sweet-submit-form">
                <input type="hidden" name="action" value="update_request">
                <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1"><?= h($request['ticket_no'] ?? '') ?></h5>
                        <small class="text-muted">Requested by <?= h(trim((string)($request['requested_by'] ?? '')) ?: 'User') ?> • <?= !empty($request['created_at']) ? h(date('M d, Y h:i A', strtotime($request['created_at']))) : '-' ?></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="tab-card mb-3">
                                <h5><?= h($request['subject'] ?? '') ?></h5>
                                <p class="text-muted mb-0"><?= nl2br(h($request['details'] ?? '')) ?></p>
                            </div>
                            <div class="tab-card mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <strong><i class="bi bi-paperclip me-1"></i>Attachments</strong>
                                    <span class="it-badge medium"><?= count($attachments_by_request[(int)$request['request_id']] ?? []) ?> file(s)</span>
                                </div>
                                <?php $files = $attachments_by_request[(int)$request['request_id']] ?? []; ?>
                                <?php if (empty($files)): ?>
                                    <div class="text-muted small">No attachment uploaded.</div>
                                <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach ($files as $file): ?>
                                            <button type="button" class="list-group-item list-group-item-action attachment-open-btn d-flex justify-content-between align-items-center" data-file-url="<?= h($file['file_path'] ?? '#') ?>" data-file-name="<?= h($file['original_name'] ?? 'Attachment') ?>" data-file-mime="<?= h($file['mime_type'] ?? '') ?>">
                                                <span><i class="bi bi-file-earmark me-2 text-success"></i><?= h($file['original_name'] ?? 'Attachment') ?></span>
                                                <small class="text-muted"><?= h(formatBytes($file['file_size'] ?? 0)) ?></small>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-card mb-0">
                                <strong class="d-block mb-3"><i class="bi bi-clock-history me-1"></i>Request History</strong>
                                <?php $histories = $history_by_request[(int)$request['request_id']] ?? []; ?>
                                <?php if (empty($histories)): ?>
                                    <div class="text-muted small">No history yet.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead><tr><th>Date</th><th>Action</th><th>Status</th><th>Priority</th><th>Updated By</th><th>Notes</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($histories as $history): ?>
                                                <tr>
                                                    <td><?= !empty($history['created_at']) ? h(date('M d, Y h:i A', strtotime($history['created_at']))) : '-' ?></td>
                                                    <td class="fw-bold"><?= h($history['action'] ?? '') ?></td>
                                                    <td><?= h(($history['status_from'] ?: '-') . ' → ' . ($history['status_to'] ?: '-')) ?></td>
                                                    <td><?= h(($history['priority_from'] ?: '-') . ' → ' . ($history['priority_to'] ?: '-')) ?></td>
                                                    <td><?= h($history['changed_by_name'] ?: 'User') ?></td>
                                                    <td><?= h($history['notes'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Status</label><select name="status" class="form-select"><option value="pending" <?= ($request['status'] ?? '')==='pending'?'selected':'' ?>>Pending</option><option value="in_progress" <?= ($request['status'] ?? '')==='in_progress'?'selected':'' ?>>In Progress</option><option value="resolved" <?= ($request['status'] ?? '')==='resolved'?'selected':'' ?>>Resolved</option><option value="cancelled" <?= ($request['status'] ?? '')==='cancelled'?'selected':'' ?>>Cancelled</option></select></div>
                                <div class="col-md-6"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low" <?= ($request['priority'] ?? '')==='low'?'selected':'' ?>>Low</option><option value="medium" <?= ($request['priority'] ?? '')==='medium'?'selected':'' ?>>Medium</option><option value="high" <?= ($request['priority'] ?? '')==='high'?'selected':'' ?>>High</option><option value="urgent" <?= ($request['priority'] ?? '')==='urgent'?'selected':'' ?>>Urgent</option></select></div>
                                <div class="col-12"><label class="form-label">Resolved At</label><input type="text" class="form-control" value="<?= !empty($request['resolved_at']) ? h(date('M d, Y h:i A', strtotime($request['resolved_at']))) : 'Not resolved yet' ?>" readonly></div>
                                <div class="col-12"><label class="form-label">Last Updated By</label><input type="text" class="form-control" value="<?= h(trim((string)($request['handled_by'] ?? '')) ?: '-') ?>" readonly></div>
                                <div class="col-12"><label class="form-label">IT Notes</label><textarea name="it_notes" class="form-control" rows="8"><?= h($request['it_notes'] ?? '') ?></textarea></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-payroll-green"><i class="bi bi-save me-1"></i>Save Update</button></div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade attachment-viewer-modal" id="attachmentViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i><span id="attachmentViewerTitle">Attachment</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="attachmentViewerBody"></div>
            </div>
            <div class="modal-footer">
                <a href="#" id="attachmentViewerDownload" class="btn btn-payroll-green" download><i class="bi bi-download me-1"></i>Download</a>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

function openAttachmentModal(fileUrl, fileName, mimeType){
    const modalEl = document.getElementById('attachmentViewerModal');
    const titleEl = document.getElementById('attachmentViewerTitle');
    const bodyEl = document.getElementById('attachmentViewerBody');
    const downloadEl = document.getElementById('attachmentViewerDownload');
    if(!modalEl || !bodyEl) return;
    fileUrl = fileUrl || '#';
    fileName = fileName || 'Attachment';
    mimeType = (mimeType || '').toLowerCase();
    titleEl.textContent = fileName;
    downloadEl.href = fileUrl;
    downloadEl.setAttribute('download', fileName);
    bodyEl.innerHTML = '';
    if(mimeType.startsWith('image/')){
        const img = document.createElement('img');
        img.src = fileUrl;
        img.alt = fileName;
        img.className = 'attachment-viewer-img';
        bodyEl.appendChild(img);
    }else if(mimeType === 'application/pdf' || fileName.toLowerCase().endsWith('.pdf')){
        const frame = document.createElement('iframe');
        frame.src = fileUrl;
        frame.className = 'attachment-viewer-frame';
        frame.title = fileName;
        bodyEl.appendChild(frame);
    }else{
        bodyEl.innerHTML = '<div class="attachment-viewer-file"><i class="bi bi-file-earmark-text"></i><h5 class="mb-2"></h5><p class="text-muted mb-0">Preview is not available for this file type. Use the Download button below.</p></div>';
        bodyEl.querySelector('h5').textContent = fileName;
    }
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

document.addEventListener('click', function(e){
    const btn = e.target.closest('.attachment-open-btn');
    if(!btn) return;
    e.preventDefault();
    e.stopPropagation();
    openAttachmentModal(btn.dataset.fileUrl || '#', btn.dataset.fileName || 'Attachment', btn.dataset.fileMime || '');
});

function toggleSidebar(){
    const sidebar=document.getElementById('sidebar');
    if(!sidebar)return;
    if(window.innerWidth<=992){sidebar.classList.toggle('active');}else{sidebar.classList.toggle('collapsed');localStorage.setItem('sidebarCollapsed',sidebar.classList.contains('collapsed')?'true':'false');}
}
function toggleSidebarDropdown(event,targetId){
    event.preventDefault();event.stopPropagation();
    const target=document.getElementById(targetId);const btn=event.currentTarget;const arrow=btn.querySelector('.dropdown-arrow');const sidebar=document.getElementById('sidebar');
    if(!target)return false;
    if(sidebar&&sidebar.classList.contains('collapsed')){sidebar.classList.remove('collapsed');localStorage.setItem('sidebarCollapsed','false');}
    document.querySelectorAll('.sidebar .collapse.show').forEach(collapse=>{if(collapse.id!==targetId){collapse.classList.remove('show');const otherBtn=document.querySelector(`[onclick*="${collapse.id}"]`);const otherArrow=otherBtn?otherBtn.querySelector('.dropdown-arrow'):null;if(otherArrow)otherArrow.style.transform='translateY(-50%) rotate(0deg)';}});
    target.classList.toggle('show');
    if(arrow)arrow.style.transform=target.classList.contains('show')?'translateY(-50%) rotate(180deg)':'translateY(-50%) rotate(0deg)';
    return false;
}
function restoreSidebarState(){
    const sidebar=document.getElementById('sidebar');
    if(sidebar&&window.innerWidth>992&&localStorage.getItem('sidebarCollapsed')==='true')sidebar.classList.add('collapsed');
    document.querySelectorAll('.sidebar .nav-link.active').forEach(link=>{const collapseDiv=link.closest('.collapse');if(collapseDiv){collapseDiv.classList.add('show');const parentBtn=document.querySelector(`[onclick*="${collapseDiv.id}"]`);const arrow=parentBtn?parentBtn.querySelector('.dropdown-arrow'):null;if(arrow)arrow.style.transform='translateY(-50%) rotate(180deg)';}});
}
function confirmLogout(){
    Swal.fire({title:'Are you sure?',text:'You will be logged out of the system',icon:'question',showCancelButton:true,confirmButtonColor:'#07d826',cancelButtonColor:'#6c757d',confirmButtonText:'Yes, logout'}).then((result)=>{if(result.isConfirmed){localStorage.removeItem('sidebarCollapsed');window.location.href='../logout.php';}});
}
function logout(){confirmLogout();}
document.addEventListener('DOMContentLoaded',function(){
    restoreSidebarState();
    document.getElementById('desktopToggleBtn')?.addEventListener('click',toggleSidebar);
    document.getElementById('mobileMenuBtn')?.addEventListener('click',toggleSidebar);
    document.getElementById('requestSearch')?.addEventListener('input',function(){const term=this.value.toLowerCase().trim();document.querySelectorAll('#requestTable tbody tr').forEach(row=>{row.style.display=row.innerText.toLowerCase().includes(term)?'':'none';});});
});
</script>

<script>
document.querySelectorAll('.sweet-submit-form').forEach(function(form){
    form.addEventListener('submit', function(){
        Swal.fire({
            title: 'Please wait...',
            text: 'Processing request...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: function(){ Swal.showLoading(); }
        });
    });
});
</script>
</body>
</html>
