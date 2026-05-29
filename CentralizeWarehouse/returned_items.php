<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../config/database.php';
@include_once '../config/session_handler.php';

if (!$conn) { die('Database connection failed: ' . mysqli_connect_error()); }

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
$user_name = trim(($_SESSION['first_name'] ?? 'Central') . ' ' . ($_SESSION['last_name'] ?? 'Warehouse'));
if ($user_name === '') $user_name = 'Central Warehouse';
$allowed_roles = ['warehouseman','centralwarehouse','central_warehouse','central warehouse','central_warehouse_admin'];
if (!in_array(strtolower(trim((string)$user_role)), $allowed_roles, true)) { header('Location: ../index.php'); exit; }

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function showPageSweetAlert(string $type, string $message): void {
    if (trim($message) === '') return;
    $icon = $type === 'success' ? 'success' : 'error';
    $title = $type === 'success' ? 'Success' : 'Error';
    echo "<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: " . json_encode($icon) . ",
            title: " . json_encode($title) . ",
            text: " . json_encode($message) . ",
            confirmButtonColor: '#047857'
        });
    }
});
</script>";
}
function tableExists(mysqli $conn, string $table): bool { $table=$conn->real_escape_string($table); $res=$conn->query("SHOW TABLES LIKE '{$table}'"); return $res && $res->num_rows>0; }
function columnExists(mysqli $conn, string $table, string $column): bool { $table=$conn->real_escape_string($table); $column=$conn->real_escape_string($column); $res=$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"); return $res && $res->num_rows>0; }
function initials(string $name): string { $out=''; foreach(explode(' ', trim($name)) as $p){ if($p!=='') $out .= strtoupper(substr($p,0,1)); } return $out ?: 'CW'; }
function badgeStatus($status){
    $status = strtolower((string)$status);
    if ($status === 'released' || $status === 'returned') return 'bg-success';
    if ($status === 'pending' || $status === 'pending_return') return 'bg-warning text-dark';
    if ($status === 'overdue') return 'bg-danger';
    return 'bg-secondary';
}

function ensureReturnAttachmentColumns(mysqli $conn): void {
    if (!tableExists($conn, 'central_warehouse_atw_requests')) return;

    if (!columnExists($conn, 'central_warehouse_atw_requests', 'return_attachment')) {
        @$conn->query("ALTER TABLE central_warehouse_atw_requests ADD COLUMN return_attachment varchar(255) DEFAULT NULL AFTER return_remarks");
    }

    if (!columnExists($conn, 'central_warehouse_atw_requests', 'return_attachment_original')) {
        @$conn->query("ALTER TABLE central_warehouse_atw_requests ADD COLUMN return_attachment_original varchar(255) DEFAULT NULL AFTER return_attachment");
    }
}

function uploadReturnAttachment(string $fieldName, string $requestNo, string &$originalName, string &$error): string {
    $originalName = '';

    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Return item attachment is required.';
        return '';
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'Failed to upload return item attachment.';
        return '';
    }

    $maxSize = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        $error = 'Return item attachment must not exceed 5MB.';
        return '';
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $error = 'Invalid return item attachment upload.';
        return '';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmpName) : '';
    if ($finfo) finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];

    if (!isset($allowed[$mime])) {
        $error = 'Only JPG, PNG, WEBP, or GIF images are allowed for returned item attachment.';
        return '';
    }

    $uploadDir = dirname(__DIR__) . '/uploads/ReturnedItems';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        $error = 'Unable to create ReturnedItems upload folder.';
        return '';
    }

    $safeRequestNo = preg_replace('/[^A-Za-z0-9_-]/', '_', $requestNo);
    $filename = 'return_' . $safeRequestNo . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        $error = 'Unable to save return item attachment.';
        return '';
    }

    $originalName = basename((string)($file['name'] ?? 'return_attachment'));
    return $filename;
}
function pageHeader(string $title, string $subtitle, string $active='dashboard'){
    global $user_name, $user_role;
    $user_initials = initials($user_name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> - Central Warehouse</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.form-card { background:#fff; border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,.05) }
.custom-table th { background:#052A47; color:#fff; white-space:nowrap }
.custom-table td { vertical-align:middle }
.clickable-item-row, .clickable-atw-row { cursor:pointer; }
.clickable-item-row:hover, .clickable-atw-row:hover { background:#f8fafc; }
.dashboard-tabs .nav-link { color: #047857; font-weight:700; border-radius:10px 10px 0 0; }
.dashboard-tabs .nav-link.active { background: #047857; color: #fff; border-color: #047857; }
.tab-card { background:#fff; border-radius:0 14px 14px 14px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,.05); }
.status-card { background:#fff; border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-top:18px; }
.section-title { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; }
.section-title h5 { margin:0; font-weight:700; color:#052A47; }
.header-actions { margin-left:auto; display:flex; align-items:center; gap:10px; }
.header-actions .btn-action-text { padding:8px 14px; }
.btn-action-text { white-space:nowrap; border-radius:8px; }
a { text-decoration:none; }
.stat-card { background:#fff; border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,.05); height:100%; border-left:5px solid #047857; }
.stat-card .icon { width:44px; height:44px; border-radius:12px; background:#052A47; color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.4rem; }
.stat-card h3 { color:#052A47; font-weight:800; margin:10px 0 0; }
.stat-card p { color:#6c757d; margin:0; font-weight:600; }
.profile-summary-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px 16px; margin-bottom:16px; }
.profile-summary-item { border-bottom:1px solid #eef2f6; padding-bottom:8px; min-width:0; }
.profile-info-label { font-size:.78rem; color:#6c757d; }
.profile-info-value { font-weight:700; color:#052A47; white-space:normal; word-break:break-word; }
.item-thumbnail { width:46px; height:46px; border-radius:8px; background:#f1f3f5; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.item-thumbnail img { width:100%; height:100%; object-fit:cover }
.return-attachment-preview { width:100%; max-width:260px; min-height:150px; border:2px dashed #cbd5e1; border-radius:12px; background:#f8fafc; display:none; align-items:center; justify-content:center; overflow:hidden; color:#64748b; font-weight:700; }
.return-attachment-preview.active { display:flex; }
.return-attachment-preview img { width:100%; height:180px; object-fit:cover; display:block; }
.return-attachment-link { display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:8px; background:#ecfdf5; color:#047857; font-weight:700; }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 998; opacity: 0; transition: opacity .25s ease; }
.sidebar-overlay.active { opacity: 1; }
.dropdown-arrow { margin-left: auto; transition: transform .2s ease; }
@media (max-width: 992px) { .profile-summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } .sidebar { transform: translateX(-100%); transition: transform .25s ease; z-index: 999; } .sidebar.active, .sidebar.show { transform: translateX(0); } }
@media (max-width: 576px) { .profile-summary-grid { grid-template-columns:1fr; } .navbar-top { align-items:flex-start; gap:10px; } .header-actions { width:100%; justify-content:flex-start; margin-left:0; } }


/* Skeleton Loading */
body.page-loading { overflow:hidden; }
.page-skeleton-overlay { position:fixed; inset:0; background:#f6f8fb; z-index:3000; display:flex; pointer-events:auto; transition:opacity .25s ease, visibility .25s ease; }
.page-skeleton-overlay.hide { opacity:0; visibility:hidden; pointer-events:none; }
.skeleton-sidebar { width:260px; min-height:100vh; background:#052A47; padding:22px 16px; }
.skeleton-main { flex:1; padding:22px; overflow:hidden; }
.skeleton-line, .skeleton-card, .skeleton-circle, .skeleton-input, .skeleton-table-row { position:relative; overflow:hidden; background:#e9eef5; border-radius:10px; }
.skeleton-sidebar .skeleton-line, .skeleton-sidebar .skeleton-circle { background:rgba(255,255,255,.16); }
.skeleton-line::after, .skeleton-card::after, .skeleton-circle::after, .skeleton-input::after, .skeleton-table-row::after { content:""; position:absolute; inset:0; transform:translateX(-100%); background:linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent); animation:skeletonShimmer 1.15s infinite; }
.skeleton-sidebar .skeleton-line::after, .skeleton-sidebar .skeleton-circle::after { background:linear-gradient(90deg, transparent, rgba(255,255,255,.18), transparent); }
.skeleton-brand { display:flex; align-items:center; gap:12px; margin-bottom:28px; }
.skeleton-circle { width:42px; height:42px; border-radius:50%; }
.skeleton-nav-line { height:18px; margin:18px 0; }
.skeleton-title { height:30px; width:260px; margin-bottom:8px; }
.skeleton-subtitle { height:16px; width:340px; margin-bottom:22px; }
.skeleton-tab-row { display:flex; gap:10px; margin-bottom:0; }
.skeleton-tab { width:170px; height:44px; border-radius:10px 10px 0 0; }
.skeleton-card { background:#fff; border-radius:0 14px 14px 14px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:18px; }
.skeleton-card-title { height:22px; width:230px; margin-bottom:18px; }
.skeleton-table-header { height:42px; background:#dce5ee; border-radius:10px; margin-bottom:10px; }
.skeleton-table-row { height:54px; margin-bottom:10px; }
.btn-loading { pointer-events:none; opacity:.85; }
@keyframes skeletonShimmer { 100% { transform:translateX(100%); } }
@media (max-width: 992px) { .skeleton-sidebar { display:none; } .skeleton-main { padding:18px; } }
@media (max-width: 576px) { .skeleton-title { width:80%; } .skeleton-subtitle { width:90%; } .skeleton-tab { width:50%; } }

</style>
</head>
<body class="page-loading">

<div class="page-skeleton-overlay" id="pageSkeletonOverlay" aria-hidden="true">
    <div class="skeleton-sidebar">
        <div class="skeleton-brand"><div class="skeleton-circle"></div><div class="skeleton-line" style="height:22px;width:150px;"></div></div>
        <div class="skeleton-line skeleton-nav-line" style="width:86%;"></div>
        <div class="skeleton-line skeleton-nav-line" style="width:92%;"></div>
        <div class="skeleton-line skeleton-nav-line" style="width:78%;"></div>
    </div>
    <div class="skeleton-main">
        <div class="skeleton-title skeleton-line"></div>
        <div class="skeleton-subtitle skeleton-line"></div>
        <div class="skeleton-tab-row"><div class="skeleton-line skeleton-tab"></div><div class="skeleton-line skeleton-tab"></div></div>
        <div class="skeleton-card">
            <div class="skeleton-card-title skeleton-line"></div>
            <div class="skeleton-table-header"></div>
            <div class="skeleton-table-row"></div>
            <div class="skeleton-table-row"></div>
            <div class="skeleton-table-row"></div>
            <div class="skeleton-table-row"></div>
        </div>
    </div>
</div>

<div id="appPage">
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>
            <button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list" id="toggleIcon"></i></button>
            <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon">
            <span class="nav-text">Central Warehouse</span>
        </h3>
    </div>
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?= $active==='stock'?'active':'' ?>" href="encode_stock.php">
                        <i class="bi bi-pencil-square"></i>
                        <span class="nav-text">Encode Stocks</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active==='release'?'active':'' ?>" href="release_requests.php">
                        <i class="bi bi-box-arrow-up-right"></i>
                        <span class="nav-text">Release Requests</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active==='return'?'active':'' ?>" href="returned_items.php">
                        <i class="bi bi-arrow-return-left"></i>
                        <span class="nav-text">Returned Items</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar"><?= h($user_initials) ?></div>
            <div class="user-details-sidebar"><span class="user-name-sidebar"><?= h($user_name) ?></span><span class="user-role-sidebar"><?= h(ucfirst($user_role)) ?></span></div>
        </div>
        <button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button>
    </div>
</div>
<main class="main-content" id="mainContent">
<div id="dashboardContent" class="page-content active">
    <div class="navbar-top">
        <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
        <div class="page-title"><h2><?= h($title) ?></h2><p><?= h($subtitle) ?></p></div>
    </div>
<?php }
function pageFooter(){ ?>
</div>
</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

(function () {
    function showPageSkeleton() {
        const overlay = document.getElementById('pageSkeletonOverlay');
        if (overlay) { overlay.style.display = 'flex'; overlay.classList.remove('hide'); }
        document.body.classList.add('page-loading');
    }
    function hidePageSkeleton() {
        const overlay = document.getElementById('pageSkeletonOverlay');
        if (overlay) { overlay.classList.add('hide'); setTimeout(function () { overlay.style.display = 'none'; }, 300); }
        document.body.classList.remove('page-loading');
    }
    window.addEventListener('load', function () { setTimeout(hidePageSkeleton, 250); });
    setTimeout(hidePageSkeleton, 1800);
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('a.nav-link, .sidebar-menu a, a[href]:not([href^="#"]):not([target="_blank"])').forEach(function (link) {
            link.addEventListener('click', function () {
                const href = link.getAttribute('href') || '';
                if (href && href !== '#' && !href.startsWith('javascript:') && !link.hasAttribute('data-bs-toggle')) showPageSkeleton();
            });
        });
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                const submitBtn = form.querySelector('button[type="submit"], button.btn-success');
                if (submitBtn) {
                    submitBtn.classList.add('btn-loading');
                    submitBtn.disabled = true;
                    submitBtn.dataset.originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Loading...';
                }
                showPageSkeleton();
            });
        });
    });
})();

function logout(){ if(confirm('Are you sure you want to logout?')) window.location.href='../logout.php'; }
function toggleSidebar(){
    const sidebar=document.getElementById('sidebar'); const mainContent=document.getElementById('mainContent'); if(!sidebar) return;
    if(window.innerWidth<=992){ sidebar.classList.toggle('active'); let overlay=document.querySelector('.sidebar-overlay'); if(!overlay){ overlay=document.createElement('div'); overlay.className='sidebar-overlay'; document.body.appendChild(overlay); overlay.addEventListener('click',()=>{sidebar.classList.remove('active'); overlay.classList.remove('active');}); } overlay.classList.toggle('active', sidebar.classList.contains('active')); }
    else { sidebar.classList.toggle('collapsed'); if(mainContent) mainContent.classList.toggle('expanded', sidebar.classList.contains('collapsed')); localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed')?'true':'false'); }
}
document.addEventListener('DOMContentLoaded', function(){
    const desktopToggleBtn=document.getElementById('desktopToggleBtn'); const mobileToggleBtn=document.getElementById('mobileToggleBtn');
    if(desktopToggleBtn) desktopToggleBtn.addEventListener('click', function(e){ e.preventDefault(); toggleSidebar(); });
    if(mobileToggleBtn) mobileToggleBtn.addEventListener('click', function(e){ e.preventDefault(); toggleSidebar(); });
    const sidebar=document.getElementById('sidebar'); const mainContent=document.getElementById('mainContent');
    if(window.innerWidth>992 && localStorage.getItem('sidebarCollapsed')==='true' && sidebar){ sidebar.classList.add('collapsed'); if(mainContent) mainContent.classList.add('expanded'); }

    document.querySelectorAll('.return-attachment-input').forEach(function(input){
        input.addEventListener('change', function(){
            const attachmentWrap = input.closest('.return-attachment-field');
            const preview = attachmentWrap ? attachmentWrap.querySelector('.return-attachment-preview') : null;
            if (!preview) return;

            const file = input.files && input.files[0] ? input.files[0] : null;
            preview.classList.remove('active');
            preview.innerHTML = '';

            if (!file) return;

            preview.classList.add('active');

            if (!file.type.match(/^image\//)) {
                preview.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Invalid image file</span>';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e){
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Return attachment preview">';
            };
            reader.readAsDataURL(file);
        });
    });
});
</script>
</body>
</html>
<?php } ?>

<?php
$message=''; $error='';
ensureReturnAttachmentColumns($conn);
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='mark_returned') {
    $request_no=trim($_POST['request_no'] ?? '');
    $return_remarks=trim($_POST['return_remarks'] ?? '');
    $return_attachment_original = '';

    if($request_no==='') {
        $error='Invalid request number.';
    } else {
        $return_attachment = uploadReturnAttachment('return_attachment', $request_no, $return_attachment_original, $error);

        if ($error === '') {
            $conn->begin_transaction();
            try{
                $stmt=$conn->prepare("SELECT * FROM central_warehouse_atw_requests WHERE request_no=? AND status='released' AND return_status='pending_return' FOR UPDATE");
                $stmt->bind_param('s',$request_no); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
                if(empty($rows)) throw new Exception('No pending return found for this request.');
                foreach($rows as $row){
                    $qty=(float)($row['approved_qty'] ?? $row['requested_qty']);
                    if ($qty <= 0) $qty = (float)$row['requested_qty'];
                    $stock_id=(int)$row['central_stock_id'];
                    $rid=(int)$row['request_id'];
                    $up=$conn->prepare("UPDATE central_warehouse_stocks SET current_stock=current_stock+?, updated_at=NOW() WHERE central_stock_id=?"); $up->bind_param('di',$qty,$stock_id); $up->execute(); $up->close();
                    $ur=$conn->prepare("UPDATE central_warehouse_atw_requests SET return_status='returned', returned_by=?, returned_at=NOW(), return_remarks=?, return_attachment=?, return_attachment_original=? WHERE request_id=? AND return_status='pending_return'");
                    $ur->bind_param('isssi',$user_id,$return_remarks,$return_attachment,$return_attachment_original,$rid); $ur->execute(); $ur->close();
                }
                $conn->commit(); $message='Returned items marked successfully, attachment was saved, and stocks were added back.';
            } catch(Exception $e){
                $conn->rollback();
                if (!empty($return_attachment)) {
                    $savedFile = dirname(__DIR__) . '/uploads/ReturnedItems/' . $return_attachment;
                    if (is_file($savedFile)) @unlink($savedFile);
                }
                $error=$e->getMessage();
            }
        }
    }
}
$pendingResult = $conn->query("SELECT r.request_no,
        MAX(r.return_date) return_date,
        MAX(r.released_at) released_at,
        MAX(r.business_unit) business_unit,
        MAX(b.branch_name) branch_name,
        MAX(r.authorized_recipient) authorized_recipient,
        COUNT(*) item_count,
        GROUP_CONCAT(CONCAT(i.item_name,' (',FORMAT(COALESCE(r.approved_qty,r.requested_qty),2),' ',COALESCE(ut.unit_type_name,i.unit_type,'Piece'),')') ORDER BY i.item_name SEPARATOR '<br>') items_summary
    FROM central_warehouse_atw_requests r
    INNER JOIN items i ON i.item_id=r.item_id
    LEFT JOIN unit_types ut ON ut.unit_type_id=r.unit_type_id
    LEFT JOIN branches b ON b.branch_id=r.branch_id
    WHERE r.status='released' AND r.return_status='pending_return'
    GROUP BY r.request_no
    ORDER BY MAX(r.return_date) ASC, MAX(r.released_at) DESC");

$pendingReturns = [];
$pendingRequestNos = [];
if ($pendingResult) {
    while ($row = $pendingResult->fetch_assoc()) {
        $pendingReturns[] = $row;
        $pendingRequestNos[] = $row['request_no'];
    }
}

$historyResult = $conn->query("SELECT r.request_no,
        MAX(r.return_date) return_date,
        MAX(r.released_at) released_at,
        MAX(r.returned_at) returned_at,
        MAX(r.business_unit) business_unit,
        MAX(b.branch_name) branch_name,
        MAX(r.authorized_recipient) authorized_recipient,
        MAX(r.returned_by) returned_by,
        MAX(r.return_remarks) return_remarks,
        MAX(r.return_attachment) return_attachment,
        MAX(r.return_attachment_original) return_attachment_original,
        COUNT(*) item_count,
        GROUP_CONCAT(CONCAT(i.item_name,' (',FORMAT(COALESCE(r.approved_qty,r.requested_qty),2),' ',COALESCE(ut.unit_type_name,i.unit_type,'Piece'),')') ORDER BY i.item_name SEPARATOR '<br>') items_summary
    FROM central_warehouse_atw_requests r
    INNER JOIN items i ON i.item_id=r.item_id
    LEFT JOIN unit_types ut ON ut.unit_type_id=r.unit_type_id
    LEFT JOIN branches b ON b.branch_id=r.branch_id
    WHERE r.status='released' AND r.return_status='returned'
    GROUP BY r.request_no
    ORDER BY MAX(r.returned_at) DESC, MAX(r.released_at) DESC");

$returnHistory = [];
$historyRequestNos = [];
if ($historyResult) {
    while ($row = $historyResult->fetch_assoc()) {
        $returnHistory[] = $row;
        $historyRequestNos[] = $row['request_no'];
    }
}

$allRequestNos = array_values(array_unique(array_merge($pendingRequestNos, $historyRequestNos)));
$requestItems = [];

if (!empty($allRequestNos)) {
    $placeholders = implode(',', array_fill(0, count($allRequestNos), '?'));
    $types = str_repeat('s', count($allRequestNos));

    $itemSql = "SELECT r.request_id,
                       r.request_no,
                       r.requested_qty,
                       COALESCE(r.approved_qty, r.requested_qty) AS final_qty,
                       r.business_unit,
                       r.authorized_recipient,
                       r.return_date,
                       r.released_at,
                       r.returned_at,
                       r.return_status,
                       r.return_attachment,
                       r.return_attachment_original,
                       i.item_name,
                       COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_name,
                       b.branch_name
                FROM central_warehouse_atw_requests r
                INNER JOIN items i ON i.item_id = r.item_id
                LEFT JOIN unit_types ut ON ut.unit_type_id = r.unit_type_id
                LEFT JOIN branches b ON b.branch_id = r.branch_id
                WHERE r.request_no IN ($placeholders)
                ORDER BY r.request_no, i.item_name";

    $itemStmt = $conn->prepare($itemSql);
    if ($itemStmt) {
        $itemStmt->bind_param($types, ...$allRequestNos);
        $itemStmt->execute();
        $itemRes = $itemStmt->get_result();

        while ($item = $itemRes->fetch_assoc()) {
            $requestItems[$item['request_no']][] = $item;
        }

        $itemStmt->close();
    }
}

pageHeader('Returned Items','Pending Returned ATW Items','return');
?>
<?php if($message): showPageSweetAlert('success', $message); endif; ?>
<?php if($error): showPageSweetAlert('error', $error); endif; ?>

<ul class="nav nav-tabs dashboard-tabs mb-0" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="pending-return-tab" data-bs-toggle="tab" data-bs-target="#pendingReturnPane" type="button" role="tab">
            Pending Returns
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="return-history-tab" data-bs-toggle="tab" data-bs-target="#returnHistoryPane" type="button" role="tab">
            Return History
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pendingReturnPane" role="tabpanel" aria-labelledby="pending-return-tab">
        <div class="tab-card">
            <div class="section-title"><h5>Pending Returns</h5></div>
            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle">
                    <thead>
                        <tr>
                            <th>ATW No.</th>
                            <th>Branch</th>
                            <th>Items</th>
                            <th>Authorized Recipient</th>
                            <th>Return Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($pendingReturns)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No items pending for return.</td></tr>
                    <?php else: foreach($pendingReturns as $r): 
                        $returnDate = $r['return_date'] ?? '';
                        $dateBadge = strtotime($returnDate ?: '2999-01-01') < strtotime(date('Y-m-d')) ? 'bg-danger' : 'bg-warning text-dark';
                        $modalId = 'returnModal' . md5($r['request_no']);
                    ?>
                        <tr>
                            <td><strong><?= h($r['request_no']) ?></strong></td>
                            <td><?= h($r['branch_name'] ?? '-') ?><br><small class="text-muted"><?= h($r['business_unit'] ?? '') ?></small></td>
                            <td><?= $r['items_summary'] ?></td>
                            <td><?= h($r['authorized_recipient'] ?: '-') ?></td>
                            <td><span class="badge <?= h($dateBadge) ?>"><?= h($returnDate ?: '-') ?></span></td>
                            <td>
                                <button type="button" class="btn btn-success btn-sm btn-action-text" data-bs-toggle="modal" data-bs-target="#<?= h($modalId) ?>">
                                    <i class="bi bi-check2-circle me-1"></i>Mark Returned
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="returnHistoryPane" role="tabpanel" aria-labelledby="return-history-tab">
        <div class="tab-card">
            <div class="section-title"><h5>Return History</h5></div>
            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle">
                    <thead>
                        <tr>
                            <th>ATW No.</th>
                            <th>Returned Date</th>
                            <th>Branch</th>
                            <th>Items</th>
                            <th>Authorized Recipient</th>
                            <th>Returned By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($returnHistory)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No return history yet.</td></tr>
                    <?php else: foreach($returnHistory as $hr): 
                        $historyModalId = 'returnHistoryModal' . md5($hr['request_no']);
                    ?>
                        <tr class="clickable-atw-row" data-bs-toggle="modal" data-bs-target="#<?= h($historyModalId) ?>">
                            <td><strong><?= h($hr['request_no']) ?></strong></td>
                            <td><?= h($hr['returned_at'] ?: '-') ?></td>
                            <td><?= h($hr['branch_name'] ?? '-') ?><br><small class="text-muted"><?= h($hr['business_unit'] ?? '') ?></small></td>
                            <td><?= $hr['items_summary'] ?></td>
                            <td><?= h($hr['authorized_recipient'] ?: '-') ?></td>
                            <td><?= h($hr['returned_by'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php foreach($pendingReturns as $r): 
    $modalId = 'returnModal' . md5($r['request_no']);
?>
<div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content return-modal-content">
            <div class="modal-header" style="background:#067857;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-arrow-return-left me-2"></i>Mark as Returned</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body return-modal-body">
                    <input type="hidden" name="action" value="mark_returned">
                    <input type="hidden" name="request_no" value="<?= h($r['request_no']) ?>">

                    <div class="profile-summary-grid">
                        <div class="profile-summary-item">
                            <div class="profile-info-label">ATW No.</div>
                            <div class="profile-info-value"><?= h($r['request_no']) ?></div>
                        </div>
                        <div class="profile-summary-item">
                            <div class="profile-info-label">Branch</div>
                            <div class="profile-info-value"><?= h($r['branch_name'] ?? '-') ?></div>
                        </div>
                        <div class="profile-summary-item">
                            <div class="profile-info-label">Business Unit</div>
                            <div class="profile-info-value"><?= h($r['business_unit'] ?? '-') ?></div>
                        </div>
                        <div class="profile-summary-item">
                            <div class="profile-info-label">Authorized Recipient</div>
                            <div class="profile-info-value"><?= h($r['authorized_recipient'] ?: '-') ?></div>
                        </div>
                        <div class="profile-summary-item">
                            <div class="profile-info-label">Released Date</div>
                            <div class="profile-info-value"><?= h($r['released_at'] ?: '-') ?></div>
                        </div>
                        <div class="profile-summary-item">
                            <div class="profile-info-label">Return Date</div>
                            <div class="profile-info-value"><?= h($r['return_date'] ?: '-') ?></div>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table custom-table compact-table align-middle">
                            <thead>
                                <tr>
                                    <th>ATW No.</th>
                                    <th>Item</th>
                                    <th>Branch</th>
                                    <th>Qty to Return</th>
                                    <th>Return Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (($requestItems[$r['request_no']] ?? []) as $item): ?>
                                <tr>
                                    <td><strong><?= h($item['request_no']) ?></strong></td>
                                    <td><?= h($item['item_name']) ?></td>
                                    <td><?= h($item['branch_name'] ?: '-') ?><br><small class="text-muted"><?= h($item['business_unit'] ?: '') ?></small></td>
                                    <td><?= h(number_format((float)$item['final_qty'], 2)) ?> <?= h($item['unit_name']) ?></td>
                                    <td><span class="badge bg-warning text-dark">Pending Return</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 return-attachment-field">
                        <label class="form-label">Returned Item Attachment <span class="text-danger">*</span></label>
                        <input type="file" name="return_attachment" class="form-control return-attachment-input" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <small class="text-muted d-block mt-1">Upload attachments. Allowed: JPG, PNG, WEBP, GIF. Maximum 5MB.</small>
                        <div class="return-attachment-preview mt-2"></div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Return Remarks</label>
                        <textarea name="return_remarks" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success">Confirm Returned</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php foreach($returnHistory as $hr): 
    $historyModalId = 'returnHistoryModal' . md5($hr['request_no']);
?>
<div class="modal fade" id="<?= h($historyModalId) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content return-modal-content">
            <div class="modal-header" style="background:#067857;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Return History Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body return-modal-body">
                <div class="profile-summary-grid">
                    <div class="profile-summary-item">
                        <div class="profile-info-label">ATW No.</div>
                        <div class="profile-info-value"><?= h($hr['request_no']) ?></div>
                    </div>
                    <div class="profile-summary-item">
                        <div class="profile-info-label">Branch</div>
                        <div class="profile-info-value"><?= h($hr['branch_name'] ?? '-') ?></div>
                    </div>
                    <div class="profile-summary-item">
                        <div class="profile-info-label">Business Unit</div>
                        <div class="profile-info-value"><?= h($hr['business_unit'] ?? '-') ?></div>
                    </div>
                    <div class="profile-summary-item">
                        <div class="profile-info-label">Authorized Recipient</div>
                        <div class="profile-info-value"><?= h($hr['authorized_recipient'] ?: '-') ?></div>
                    </div>
                    <div class="profile-summary-item">
                        <div class="profile-info-label">Released Date</div>
                        <div class="profile-info-value"><?= h($hr['released_at'] ?: '-') ?></div>
                    </div>
                    <div class="profile-summary-item">
                        <div class="profile-info-label">Return Date</div>
                        <div class="profile-info-value"><?= h($hr['return_date'] ?: '-') ?></div>
                    </div>
                    <div class="profile-summary-item">
                        <div class="profile-info-label">Returned Date</div>
                        <div class="profile-info-value"><?= h($hr['returned_at'] ?: '-') ?></div>
                    </div>
                    <div class="profile-summary-item">
                        <div class="profile-info-label">Returned By</div>
                        <div class="profile-info-value"><?= h($hr['returned_by'] ?: '-') ?></div>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table custom-table compact-table align-middle">
                        <thead>
                            <tr>
                                <th>ATW No.</th>
                                <th>Item</th>
                                <th>Branch</th>
                                <th>Qty Returned</th>
                                <th>Returned Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($requestItems[$hr['request_no']] ?? []) as $item): ?>
                            <tr>
                                <td><strong><?= h($item['request_no']) ?></strong></td>
                                <td><?= h($item['item_name']) ?></td>
                                <td><?= h($item['branch_name'] ?: '-') ?><br><small class="text-muted"><?= h($item['business_unit'] ?: '') ?></small></td>
                                <td><?= h(number_format((float)$item['final_qty'], 2)) ?> <?= h($item['unit_name']) ?></td>
                                <td><?= h($item['returned_at'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($hr['return_attachment'])): ?>
                    <div class="mb-3">
                        <div class="profile-info-label">Returned Item Attachment</div>
                        <a class="return-attachment-link mt-1" href="../uploads/ReturnedItems/<?= h($hr['return_attachment']) ?>" target="_blank">
                            <i class="bi bi-paperclip"></i><?= h($hr['return_attachment_original'] ?: $hr['return_attachment']) ?>
                        </a>
                        <div class="mt-2">
                            <img src="../uploads/ReturnedItems/<?= h($hr['return_attachment']) ?>" alt="Returned Item Attachment" style="max-width:260px;width:100%;height:180px;object-fit:cover;border-radius:12px;border:1px solid #e9ecef;">
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($hr['return_remarks'])): ?>
                    <div class="mb-2">
                        <div class="profile-info-label">Return Remarks</div>
                        <div class="profile-info-value p-2 rounded" style="background:#f8fafc;border:1px solid #e9ecef;"><?= nl2br(h($hr['return_remarks'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<style>
.return-modal-content {
    max-height: 90vh;
    overflow: hidden;
}
.return-modal-body {
    max-height: calc(90vh - 140px);
    overflow-y: auto;
}
</style>

<?php pageFooter(); ?>
