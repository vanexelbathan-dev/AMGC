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
function redirectBackWithoutPost(string $fallback): void { $url = $_SERVER['REQUEST_URI'] ?? $fallback; $url = str_replace(["\r", "\n"], '', $url); header('Location: ' . $url); exit; }
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
        $stmt->bind_param('issssssiss', $request_id, $action, $status_from, $status_to, $priority_from, $priority_to, $notes, $changed_by_user_id, $changed_by_name);
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

$success_message = $_SESSION['it_request_success'] ?? '';
$error_message = $_SESSION['it_request_error'] ?? '';
unset($_SESSION['it_request_success'], $_SESSION['it_request_error']);
$source_account = 'motorpool';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_request') {
    try {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $priority = trim((string)($_POST['priority'] ?? 'medium'));
        if ($subject === '') throw new Exception('Subject is required.');
        if ($details === '') throw new Exception('Concern details are required.');
        if (!in_array($priority, ['low','medium','high','urgent'], true)) $priority = 'medium';
        $ticket_no = nextTicketNo($conn);
        $stmt = $conn->prepare("INSERT INTO it_requests (ticket_no, branch_id, source_account, requested_by_user_id, subject, details, priority, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
        $stmt->bind_param('sisisss', $ticket_no, $branch_id, $source_account, $user_id, $subject, $details, $priority);
        $stmt->execute();
        $request_id = (int)$conn->insert_id;
        $stmt->close();
        $saved_attachments = saveITRequestAttachments($conn, $request_id, $user_id);
        addITRequestHistory($conn, $request_id, 'Created Request', null, 'pending', null, $priority, 'Request submitted' . ($saved_attachments > 0 ? ' with ' . $saved_attachments . ' attachment(s)' : ''), $user_id, $user_name);
        $_SESSION['it_request_success'] = 'Request submitted successfully' . ($saved_attachments > 0 ? ' with attachment(s).' : '.');
        redirectBackWithoutPost('it_request.php');
    } catch (Throwable $e) { $_SESSION['it_request_error'] = $e->getMessage(); redirectBackWithoutPost('it_request.php'); }
}
$requests = [];
$stmt = $conn->prepare("SELECT ir.*, CONCAT(COALESCE(it.first_name,''), ' ', COALESCE(it.last_name,'')) AS handled_by FROM it_requests ir LEFT JOIN users it ON it.user_id=ir.handled_by_user_id WHERE ir.branch_id=? AND ir.source_account=? ORDER BY ir.created_at DESC, ir.request_id DESC");
if ($stmt) { $stmt->bind_param('is', $branch_id, $source_account); $stmt->execute(); $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); }
$counts = ['pending'=>0,'in_progress'=>0,'resolved'=>0,'cancelled'=>0];
foreach ($requests as $r) { if (isset($counts[$r['status']])) $counts[$r['status']]++; }
$request_ids = array_map(static fn($r) => (int)$r['request_id'], $requests);
$attachments_by_request = getITRequestAttachments($conn, $request_ids);
$history_by_request = getITRequestHistory($conn, $request_ids);
$total_requests = count($requests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IT Requests - Motorpool</title>
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

.stat-card-row{
    margin-bottom:1.5rem;
}
.stat-card{
    background:transparent!important;
    border:none!important;
    box-shadow:0 4px 10px rgba(0,0,0,.08)!important;
    min-height:auto!important;height:auto!important;
    padding:.8rem!important;
    transition:transform .2s ease,box-shadow .2s ease!important;
    cursor:default!important;
    display:flex!important;
    align-items:center!important;
    gap:.85rem!important;
    border-radius:14px!important;
    overflow:hidden!important;
}
.stat-card.total,.stat-card.pending,
.stat-card.complete,.stat-card.delivery{
    background:linear-gradient(135deg,#047857,#059669)!important;
    border:none!important;
}
.stat-card .stat-value,.stat-card .stat-label,
.stat-card .stat-content,.stat-card small,
.stat-card small i,.stat-card .badge{
    color:#fff!important;
}
.stat-card .stat-content,
.stat-card .stat-icon{
    background:transparent!important;
}
.stat-card:hover{
    transform:translateY(-2px)!important;
    box-shadow:0 6px 15px rgba(0,0,0,.15)!important;
}
.stat-card .stat-icon{
    font-size:1.65rem!important;
    color:#fff!important;
    opacity:.95!important;
    flex-shrink:0!important;
}
.stat-card .stat-value{
    font-size:1.35rem!important;
    line-height:1.1!important;
    margin:0!important;
}
.stat-card .stat-label{
    font-size:.78rem!important;
    text-transform:uppercase!important;
    letter-spacing:.35px!important;
    opacity:.95!important;
    margin:0!important;
}
.tab-card{
    background:#fff;
    border-radius:14px;
    padding:18px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
    margin-bottom:24px;
}
.employee-toolbar,.payroll-tab-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
}
.payroll-search-wrap{
    position:relative;
    max-width:420px;
    width:100%;
}
.payroll-search-wrap i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#64748b;
    z-index:1;
}
.payroll-search-wrap input{
    padding-left:40px;
    border:1px solid #e2e8f0;
    border-radius:12px;
    height:42px;
    font-size:.88rem;
}
.payroll-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.btn-payroll-green{
    background:linear-gradient(135deg,#047857,#44D34E)!important;
    color:#fff!important;
    border:0!important;
    border-radius:12px!important;
    padding:9px 14px!important;
    box-shadow:0 8px 18px rgba(4,120,87,.18)!important;
}
.btn-payroll-light{
    background:#f8fafc!important;
    color:#052A47!important;
    border:1px solid #e2e8f0!important;
    border-radius:12px!important;
    padding:9px 14px!important;
}
.employee-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:auto;
}
.employee-table thead th{
    background: #047857!important;
    color:#fff!important;
    font-weight:600;
    font-size:.82rem;
    padding:14px 12px;
    border:0;
    white-space:nowrap;
}
.employee-table thead th:first-child{
    border-top-left-radius:14px;
}
.employee-table thead th:last-child{
    border-top-right-radius:14px;
}
.employee-table tbody td{
    padding:14px 12px;
    border-bottom:1px solid #eef2f7;
    vertical-align:middle;
    color:#263238;
    font-size:.9rem;
}
.employee-table tbody tr:hover{
    background:#f8fbff;
}
.it-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    padding:5px 10px;
    font-size:.72rem;
    font-weight:600;
    text-transform:uppercase;
    white-space:nowrap;
}
.it-badge.pending{
    background:#fff7ed;
    color:#c2410c;
}
.it-badge.in_progress{
    background:#dbeafe;
    color:#1d4ed8;
}
.it-badge.resolved{
    background:#dcfce7;
    color:#047857;
}
.it-badge.cancelled{
    background:#f1f5f9;
    color:#475569;
}
.it-badge.low{
    background:#f1f5f9;
    color:#475569;
}
.it-badge.medium{
    background:#dcfce7;
    color:#047857;
}
.it-badge.high{
    background:#fef3c7;
    color:#b45309;
}
.it-badge.urgent{
    background:#fee2e2;
    color:#b91c1c;
}
.it-empty{
    text-align:center;
    padding:42px 12px;
    color:#64748b;
}
.it-empty i{
    font-size:2.4rem;
    color:#94a3b8;
    display:block;
    margin-bottom:8px;
}
.form-label{
    font-size:.76rem;
    text-transform:uppercase;
    letter-spacing:.35px;
    color: #64748b;
}
.form-control,.form-select{
    border-radius:8px;
    border-color:#d8dee6;
}
.form-control:focus,.form-select:focus{
    border-color:#10b981;
    box-shadow:0 0 0 .2rem rgba(16,185,129,.12);
}
@media(max-width:991px){
    .stat-card{
        aspect-ratio:1/1!important;
        display:flex!important;
        flex-direction:column!important;
        justify-content:center!important;
        align-items:center!important;
        text-align:center!important;
        padding:.5rem!important;
    }
    .stat-card .stat-icon{
        margin:0 auto .3rem auto!important;
        font-size:1.6rem!important;
    }
    .stat-card .stat-value{
        font-size:1.2rem!important;
        text-align:center!important;
        width:100%!important;
    }
    .stat-card .stat-label{
        font-size:.65rem!important;
        text-align:center!important;
        width:100%!important;
    }
    .stat-card-row .col{
        padding-left:.25rem!important;
        padding-right:.25rem!important;
    }
}
@media(max-width:576px){
    .tab-card{
        padding:12px
    }
    .employee-table{
        font-size:.8rem
    }
    .payroll-actions .btn{
        width:100%;
    }
    .payroll-search-wrap{
        max-width:100%;
    }
}

.it-form-card,.it-table-card{
    background:#fff;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,.06);
    border:1px solid rgba(7,216,38,.12);
    overflow:hidden;
    margin-bottom:24px;
}
.it-card-header{
    background:#059669;
    color:#fff;
    padding:16px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.it-card-header h5{
    margin:0;
    font-weight:800;
    font-size:1.05rem;
}
.it-card-body{
    padding:18px;
}
.it-table{
    width:100%;
    border-collapse:collapse;
    margin:0;
}
.it-table thead th{
    background: #047857;
    color:#fff;
    text-transform:uppercase;
    font-size:.74rem;
    font-weight:800;
    letter-spacing:.35px;
    padding:13px 12px;
    white-space:nowrap;
}
.it-table tbody td{
    padding:13px 12px;
    border-bottom:1px solid #e5e7eb;
    font-size:.84rem;
    vertical-align:middle;
}
.it-table tbody tr:hover{
    background:#f8fafc;
}
.it-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    padding:5px 10px;
    font-size:.72rem;
    font-weight:600;
    text-transform:uppercase;
}
.it-badge.pending{
    background:#fff7ed;
    color:#c2410c;
}
.it-badge.in_progress{
    background:#dbeafe;
    color:#1d4ed8;
}
.it-badge.resolved{
    background:#dcfce7;
    color:#047857;
}
.it-badge.cancelled{
    background:#f1f5f9;
    color:#475569;
}
.it-badge.low{
    background:#f1f5f9;
    color:#475569;
}
.it-badge.medium{
    background:#dcfce7;
    color:#047857;
}
.it-badge.high{
    background:#fef3c7;
    color:#b45309;
}
.it-badge.urgent{
    background:#fee2e2;
    color:#b91c1c;
}
.it-empty{
    text-align:center;
    padding:42px 12px;
    color:#64748b;
}
.it-empty i{
    font-size:2.4rem;
    color:#94a3b8;
    display:block;
    margin-bottom:8px;
}
.it-search-wrapper{
    width:320px;
    max-width:100%;
}
.it-search-wrapper .input-group{
    border-radius:8px;
    overflow:hidden;
    border:1px solid #e2e8f0;
    background:#f8fafc;
}
.it-search-wrapper .input-group-text,
.it-search-wrapper .form-control{
    border:0;
    background:#f8fafc;
}
.it-search-wrapper .form-control:focus{
    box-shadow:none;
    background:#fff;
}
.btn-success{
    background:#10b981!important;
    border-color:#10b981!important;
}
.btn-dark{
    background:#1f2937!important;
    border-color:#1f2937!important;
}
.form-label{
    font-size:.76rem;
    text-transform:uppercase;
    letter-spacing:.35px;
    color:#64748b;
    font-weight:600;
}
.form-control,.form-select{
    border-radius:8px;
    border-color:#d8dee6;
}
.form-control:focus,.form-select:focus{
    border-color:#10b981;
    box-shadow:0 0 0 .2rem rgba(16,185,129,.12);
}
@media(max-width:768px){
    .it-card-header{
        flex-direction:column;
        align-items:stretch;
    }
    .it-search-wrapper{
        width:100%;
    }
}
.clickable-request-row{
    cursor:pointer!important;
}
.clickable-request-row:hover{
    background:#f0fdf4!important;
}
.history-row-clickable{
    cursor:pointer;
}
.history-row-clickable:hover{
    background:#f8fafc;
}
.request-detail-card{
    border:1px solid #eef2f7;
    border-radius:14px;
    padding:14px;
    background:#fff;
}
.request-history-table thead th{
    background: #047857!important;
    color:#fff!important;
    font-size:.75rem;
    text-transform:uppercase;
    white-space:nowrap;
}
.request-history-table tbody td{
    font-size:.84rem;
    vertical-align:middle;
}
.attachment-preview-img{
    max-width:100%;
    max-height:260px;
    border-radius:12px;
    border:1px solid #e5e7eb;
    object-fit:contain;
    background:#f8fafc;
}

/* System themed modal palette for clickable IT request rows */
.request-system-modal{
    border:0!important;
    border-radius:18px!important;
    overflow:hidden!important;
    box-shadow:0 18px 55px rgba(5,42,71,.22)!important;
    background:#fff!important;
}
.request-system-modal .modal-header{
    background:linear-gradient(135deg,#047857,#07d826)!important;
    color:#fff!important;
    border-bottom:0!important;
    padding:18px 22px!important;
}
.request-system-modal .modal-title{
    color:#fff!important;
    font-weight:600!important;
    letter-spacing:.25px!important;
    display:flex!important;
    align-items:center!important;
    gap:8px!important;
}
.request-system-modal .modal-title:before{
    content:'\F3E2';
    font-family:'bootstrap-icons';
    font-weight:400!important;
}
.request-system-modal .modal-header small{
    color:rgba(255,255,255,.88)!important;
    font-weight:600!important;
}
.request-system-modal .btn-close{
    filter:brightness(0) invert(1)!important;
    opacity:.95!important;
}
.request-system-modal .modal-body{
    background:#f8fafc!important;
    padding:20px!important;
}
.request-system-modal .modal-footer{
    background:#fff!important;
    border-top:1px solid #e5e7eb!important;
    padding:14px 20px!important;
}
.request-system-modal .request-detail-card{
    background:#fff!important;
    border:1px solid rgba(7,216,38,.14)!important;
    border-radius:16px!important;
    box-shadow:0 4px 18px rgba(5,42,71,.06)!important;
    padding:16px!important;
}
.request-system-modal .request-detail-card strong{
    color:#052A47!important;
    font-weight:600!important;
    text-transform:uppercase!important;
    font-size:.82rem!important;
    letter-spacing:.35px!important;
}
.request-system-modal h5{
    color:#052A47!important;
    font-weight:600!important;
}
.request-system-modal .form-label{
    color:#047857!important;
    font-weight:600!important;
}
.request-system-modal .form-control,
.request-system-modal .form-select{
    border-radius:10px!important;
    border:1px solid #d8dee6!important;
    background:#fff!important;
}
.request-system-modal .form-control[readonly],
.request-system-modal textarea[readonly]{
    background:#f8fafc!important;
    color:#334155!important;
}
.request-system-modal .list-group-item{
    border:1px solid #e5e7eb!important;
    border-radius:12px!important;
    margin-bottom:8px!important;
    transition:all .2s ease!important;
}
.request-system-modal .list-group-item:hover{
    background:#f0fdf4!important;
    border-color:#10b981!important;
    transform:translateY(-1px)!important;
}
.request-system-modal .request-history-table{
    border-collapse:separate!important;
    border-spacing:0!important;
    overflow:hidden!important;
    border-radius:14px!important;
}
.request-system-modal .request-history-table thead th{
    background:linear-gradient(135deg,#047857,#059669)!important;
    color:#fff!important;
    border:0!important;
    padding:11px 10px!important;
}
.request-system-modal .request-history-table tbody td{
    background:#fff!important;
    border-bottom:1px solid #edf2f7!important;
    padding:11px 10px!important;
}
.request-system-modal .request-history-table tbody tr:hover td{
    background:#f0fdf4!important;
}
.request-system-modal .btn-light{
    background:#f8fafc!important;
    border:1px solid #e2e8f0!important;
    color:#052A47!important;
    border-radius:12px!important;
    font-weight:600!important;
    padding:9px 16px!important;
}
.request-system-modal .btn-payroll-green,
.request-system-modal .btn-success{
    background:linear-gradient(135deg,#047857,#07d826)!important;
    border:0!important;
    color:#fff!important;
    border-radius:12px!important;
    font-weight:600!important;
    padding:9px 16px!important;
    box-shadow:0 8px 18px rgba(4,120,87,.18)!important;
}
.request-system-modal .attachment-preview-img{
    box-shadow:0 5px 16px rgba(5,42,71,.10)!important;
}
.history-row-clickable{
    cursor:pointer!important;
    transition:all .2s ease!important;
}
.history-row-clickable:hover{
    transform:translateX(2px)!important;
}
.clickable-request-row{
    transition:background .2s ease!important;
}


/* Attachment preview overlay - same behavior as Motorpool view attachment */
.it-attachment-preview-btn{
    width:100%;
    text-align:left;
    background:#fff;
    cursor:pointer;
}
.it-attachment-preview-overlay{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.78);
    z-index:99999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
}
.it-attachment-preview-overlay.active{
    display:flex;
}
.it-attachment-preview-box{
    position:relative;
    max-width:96vw;
    max-height:94vh;
    display:flex;
    align-items:center;
    justify-content:center;
}
.it-attachment-preview-content{
    display:flex;
    align-items:center;
    justify-content:center;
    max-width:96vw;
    max-height:94vh;
}
.it-attachment-preview-content img{
    display:block;
    max-width:96vw;
    max-height:94vh;
    width:auto;
    height:auto;
    object-fit:contain;
    background:#fff;
    border-radius:12px;
    box-shadow:0 14px 45px rgba(0,0,0,.35);
}
.it-attachment-preview-content iframe,
.it-attachment-preview-content embed{
    width:92vw;
    height:90vh;
    border:0;
    border-radius:12px;
    background:#fff;
    box-shadow:0 14px 45px rgba(0,0,0,.35);
}
.it-attachment-file-card{
    width:min(460px,92vw);
    background:#fff;
    border-radius:16px;
    padding:24px;
    text-align:center;
    box-shadow:0 14px 45px rgba(0,0,0,.35);
}
.it-attachment-file-card i{
    font-size:3rem;
    color:#047857;
    display:block;
    margin-bottom:10px;
}
.it-attachment-file-card h5{
    color:#052A47!important;
    word-break:break-word;
    margin-bottom:10px;
}
.it-attachment-close,
.it-attachment-download{
    position:absolute;
    right:10px;
    width:38px;
    height:38px;
    border-radius:50%;
    background:rgba(0,0,0,.72);
    color:#fff!important;
    border:0;
    z-index:100000;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    line-height:1;
}
.it-attachment-close{
    top:10px;
}
.it-attachment-download{
    bottom:10px;
}
.it-attachment-close:hover,
.it-attachment-download:hover{
    background:rgba(0,0,0,.92);
    color:#fff!important;
}
body.it-attachment-preview-open{
    overflow:hidden!important;
}
@media(max-width:768px){
    .it-attachment-preview-content iframe,
    .it-attachment-preview-content embed{
        width:94vw;
        height:82vh;
    }
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
                    <a class="nav-link active" href="it_request.php">
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

        <div class="main-content" id="mainContent">
    <div class="page-content active">
        <div class="navbar-top">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title">
                <h2>IT Requests</h2>
                <p>Submit and monitor service requests</p>
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
            <div class="payroll-tab-toolbar">
                <div><strong><i class="bi bi-plus-circle me-1"></i>New IT Request</strong></div>
                <div><strong><?= h($branch_name) ?></strong></div>
            </div>
                <form method="POST" enctype="multipart/form-data" class="sweet-submit-form">
                    <input type="hidden" name="action" value="create_request">
                    <div class="row g-3">
                        <div class="col-lg-8"><label class="form-label">Subject</label><input type="text" name="subject" class="form-control" placeholder="Enter request subject" required></div>
                        <div class="col-lg-4"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
                        <div class="col-12"><label class="form-label">Concern Details</label><textarea name="details" class="form-control" rows="4" placeholder="Describe the issue, request, or needed support" required></textarea></div>
                        <div class="col-12"><label class="form-label">Attachments</label><input type="file" name="attachments[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip,.rar"><small class="text-muted">Optional. You can upload screenshots, PDF, Excel, Word, ZIP, or image files. Max 10MB each.</small></div>
                        <div class="col-12 text-end"><button type="submit" class="btn btn-payroll-green"><i class="bi bi-send me-1"></i>Submit Request</button></div>
                    </div>
                </form>
        </div></div>
        <div class="mb-4"><div class="tab-card">
            <div class="payroll-tab-toolbar">
                <div><strong><i class="bi bi-list-check me-1"></i>Request History</strong></div>
                <div class="payroll-search-wrap"><i class="bi bi-search"></i><input type="text" class="form-control" id="requestSearch" placeholder="Search requests"></div>
            </div>
            <div class="table-responsive">
                <table class="employee-table" id="requestTable">
                    <thead><tr><th>Ticket</th><th>Date</th><th>Subject</th><th>Priority</th><th>Status</th><th>Handled By</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="7"><div class="it-empty"><i class="bi bi-inbox"></i>No requests found.</div></td></tr>
                    <?php else: foreach ($requests as $request): ?>
                        <tr class="clickable-request-row" data-bs-toggle="modal" data-bs-target="#requestModal<?= (int)$request['request_id'] ?>" title="Click to view request details and history">
                            <td class="fw-bold text-success"><?= h($request['ticket_no'] ?? '') ?></td>
                            <td><?= !empty($request['created_at']) ? h(date('M d, Y h:i A', strtotime($request['created_at']))) : '-' ?></td>
                            <td><div class="fw-bold"><?= h($request['subject'] ?? '') ?></div><div class="text-muted small"><?= h($request['details'] ?? '') ?></div></td>
                            <td><span class="it-badge <?= h($request['priority'] ?? 'medium') ?>"><?= h($request['priority'] ?? 'medium') ?></span></td>
                            <td><span class="it-badge <?= h($request['status'] ?? 'pending') ?>"><?= h(str_replace('_',' ', $request['status'] ?? 'pending')) ?></span></td>
                            <td><?= h(trim((string)($request['handled_by'] ?? '')) ?: '-') ?></td>
                            <td><?= h($request['it_notes'] ?? '-') ?></td>
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
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1"><?= h($request['ticket_no'] ?? '') ?></h5>
                    <small class="text-muted">Created <?= !empty($request['created_at']) ? h(date('M d, Y h:i A', strtotime($request['created_at']))) : '-' ?></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="request-detail-card mb-3">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="it-badge <?= h($request['priority'] ?? 'medium') ?>"><?= h($request['priority'] ?? 'medium') ?></span>
                                <span class="it-badge <?= h($request['status'] ?? 'pending') ?>"><?= h(str_replace('_',' ', $request['status'] ?? 'pending')) ?></span>
                            </div>
                            <h5 class="mb-2"><?= h($request['subject'] ?? '') ?></h5>
                            <p class="text-muted mb-0"><?= nl2br(h($request['details'] ?? '')) ?></p>
                        </div>
                        <div class="request-detail-card mb-3">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Handled By</label><input type="text" class="form-control" value="<?= h(trim((string)($request['handled_by'] ?? '')) ?: '-') ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label">Resolved At</label><input type="text" class="form-control" value="<?= !empty($request['resolved_at']) ? h(date('M d, Y h:i A', strtotime($request['resolved_at']))) : 'Not resolved yet' ?>" readonly></div>
                                <div class="col-12"><label class="form-label">IT Notes</label><textarea class="form-control" rows="4" readonly><?= h($request['it_notes'] ?? '') ?></textarea></div>
                            </div>
                        </div>
                        <div class="request-detail-card">
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
                                        <?php $isImage = preg_match('/^image\//', (string)($file['mime_type'] ?? '')); ?>
                                        <button type="button"
                                            class="list-group-item list-group-item-action it-attachment-preview-btn"
                                            data-preview-src="<?= h($file['file_path'] ?? '#') ?>"
                                            data-preview-name="<?= h($file['original_name'] ?? 'Attachment') ?>"
                                            onclick="event.stopPropagation();">
                                            <div class="d-flex justify-content-between align-items-center gap-2">
                                                <span><i class="bi bi-file-earmark me-2 text-success"></i><?= h($file['original_name'] ?? 'Attachment') ?></span>
                                                <small class="text-muted"><?= h(formatBytes($file['file_size'] ?? 0)) ?></small>
                                            </div>
                                            <?php if ($isImage): ?><img src="<?= h($file['file_path'] ?? '#') ?>" class="attachment-preview-img mt-2" alt="Attachment preview"><?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="request-detail-card">
                            <strong class="d-block mb-3"><i class="bi bi-clock-history me-1"></i>Recent Status History</strong>
                            <?php $histories = $history_by_request[(int)$request['request_id']] ?? []; ?>
                            <?php if (empty($histories)): ?>
                                <div class="text-muted small">No history yet.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm request-history-table align-middle mb-0">
                                        <thead><tr><th>Date</th><th>Action</th><th>Status</th><th>Updated By</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($histories as $history): ?>
                                            <tr class="history-row-clickable" onclick="Swal.fire({title: <?= json_encode($history['action'] ?? '') ?>, html: <?= json_encode('<div class=\'text-start\'><b>Date:</b> ' . (!empty($history['created_at']) ? date('M d, Y h:i A', strtotime($history['created_at'])) : '-') . '<br><b>Status:</b> ' . (($history['status_from'] ?: '-') . ' → ' . ($history['status_to'] ?: '-')) . '<br><b>Priority:</b> ' . (($history['priority_from'] ?: '-') . ' → ' . ($history['priority_to'] ?: '-')) . '<br><b>Updated By:</b> ' . ($history['changed_by_name'] ?: 'User') . '<br><b>Notes:</b> ' . h($history['notes'] ?? '') . '</div>') ?>, icon: 'info', confirmButtonColor: '#047857'});">
                                                <td><?= !empty($history['created_at']) ? h(date('M d, Y h:i A', strtotime($history['created_at']))) : '-' ?></td>
                                                <td class="fw-bold"><?= h($history['action'] ?? '') ?></td>
                                                <td><?= h(($history['status_from'] ?: '-') . ' → ' . ($history['status_to'] ?: '-')) ?></td>
                                                <td><?= h($history['changed_by_name'] ?: 'User') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-muted d-block mt-2">Click any history row to view full details.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="it-attachment-preview-overlay" id="itAttachmentPreviewOverlay" aria-hidden="true">
    <div class="it-attachment-preview-box" role="dialog" aria-modal="true" aria-label="Attachment preview">
        <button type="button" class="it-attachment-close" id="itAttachmentCloseBtn" aria-label="Close attachment preview"><i class="bi bi-x-lg"></i></button>
        <a href="#" class="it-attachment-download" id="itAttachmentDownloadBtn" download title="Download attachment"><i class="bi bi-download"></i></a>
        <div class="it-attachment-preview-content" id="itAttachmentPreviewContent"></div>
    </div>
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
                <a class="nav-link active" href="it_request.php">
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


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
    const overlay = document.getElementById('itAttachmentPreviewOverlay');
    const content = document.getElementById('itAttachmentPreviewContent');
    const closeBtn = document.getElementById('itAttachmentCloseBtn');
    const downloadBtn = document.getElementById('itAttachmentDownloadBtn');

    function escapeHtml(value){
        return String(value || '').replace(/[&<>'"]/g, function(ch){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'})[ch];
        });
    }

    function extensionOf(path){
        const clean = String(path || '').split('?')[0].split('#')[0];
        const last = clean.substring(clean.lastIndexOf('/') + 1);
        const dot = last.lastIndexOf('.');
        return dot >= 0 ? last.substring(dot + 1).toLowerCase() : '';
    }

    function openAttachmentPreview(src, name){
        if (!overlay || !content || !src || src === '#') return;
        const ext = extensionOf(src);
        const imgExts = ['jpg','jpeg','png','gif','webp','bmp','svg'];
        content.innerHTML = '';
        if (downloadBtn) {
            downloadBtn.href = src;
            downloadBtn.setAttribute('download', name || 'attachment');
        }

        if (imgExts.includes(ext)) {
            const img = document.createElement('img');
            img.src = src;
            img.alt = name || 'Attachment preview';
            content.appendChild(img);
        } else if (ext === 'pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = src;
            iframe.title = name || 'PDF attachment preview';
            content.appendChild(iframe);
        } else {
            content.innerHTML = '<div class="it-attachment-file-card"><i class="bi bi-file-earmark-arrow-down"></i><h5>' + escapeHtml(name || 'Attachment') + '</h5><p class="text-muted mb-3">Preview is not available for this file type. Click the download button to open/download the attachment.</p><a class="btn btn-payroll-green" href="' + escapeHtml(src) + '" download><i class="bi bi-download me-1"></i>Download File</a></div>';
        }

        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('it-attachment-preview-open');
    }

    function closeAttachmentPreview(){
        if (!overlay || !content) return;
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
        content.innerHTML = '';
        document.body.classList.remove('it-attachment-preview-open');
    }

    document.addEventListener('click', function(event){
        const btn = event.target.closest('.it-attachment-preview-btn');
        if (btn) {
            event.preventDefault();
            event.stopPropagation();
            openAttachmentPreview(btn.getAttribute('data-preview-src'), btn.getAttribute('data-preview-name'));
            return;
        }
        if (event.target === overlay) closeAttachmentPreview();
    });

    closeBtn?.addEventListener('click', function(event){
        event.preventDefault();
        closeAttachmentPreview();
    });

    document.addEventListener('keydown', function(event){
        if (event.key === 'Escape' && overlay && overlay.classList.contains('active')) closeAttachmentPreview();
    });
})();
</script>

<script>
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
