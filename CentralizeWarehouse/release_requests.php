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

function ensureCentralWarehouseAttachmentsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `central_warehouse_attachments` (
        `attachment_id` INT(11) NOT NULL AUTO_INCREMENT,
        `request_no` VARCHAR(80) NOT NULL,
        `request_id` INT(11) DEFAULT NULL,
        `original_name` VARCHAR(255) NOT NULL,
        `file_name` VARCHAR(255) NOT NULL,
        `file_path` VARCHAR(255) NOT NULL,
        `mime_type` VARCHAR(120) DEFAULT NULL,
        `file_size` INT(11) DEFAULT NULL,
        `uploaded_by` INT(11) DEFAULT NULL,
        `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`attachment_id`),
        KEY `idx_cw_attach_request_no` (`request_no`),
        KEY `idx_cw_attach_request_id` (`request_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
function saveCentralWarehouseAttachments(mysqli $conn, string $request_no, ?int $request_id, int $uploaded_by): void {
    if (empty($_FILES['release_attachments']) || !isset($_FILES['release_attachments']['name']) || !is_array($_FILES['release_attachments']['name'])) return;

    ensureCentralWarehouseAttachmentsTable($conn);

    $uploadDir = realpath(__DIR__ . '/../uploads');
    if ($uploadDir === false) {
        $baseUploadPath = __DIR__ . '/../uploads';
        if (!is_dir($baseUploadPath) && !mkdir($baseUploadPath, 0775, true)) {
            throw new Exception('Failed to create uploads folder.');
        }
        $uploadDir = realpath($baseUploadPath);
    }

    $targetDir = $uploadDir . DIRECTORY_SEPARATOR . 'centralwarehouse_attachments';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
        throw new Exception('Failed to create centralwarehouse_attachments folder.');
    }

    $allowedExt = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt'];
    $allowedMime = [
        'image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain',
        'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream'
    ];
    $maxSize = 10 * 1024 * 1024;
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;

    $stmt = $conn->prepare("INSERT INTO central_warehouse_attachments
        (request_no, request_id, original_name, file_name, file_path, mime_type, file_size, uploaded_by, uploaded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) throw new Exception('Failed to prepare attachment save.');

    foreach ($_FILES['release_attachments']['name'] as $idx => $originalName) {
        $error = (int)($_FILES['release_attachments']['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new Exception('Attachment upload failed.');

        $tmpName = $_FILES['release_attachments']['tmp_name'][$idx] ?? '';
        $size = (int)($_FILES['release_attachments']['size'][$idx] ?? 0);
        if ($size <= 0) throw new Exception('Uploaded attachment is empty.');
        if ($size > $maxSize) throw new Exception('Attachment must not exceed 10MB.');

        $safeOriginal = basename((string)$originalName);
        $ext = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new Exception('Invalid attachment file type.');

        $mime = $finfo ? (string)finfo_file($finfo, $tmpName) : ((string)($_FILES['release_attachments']['type'][$idx] ?? ''));
        if ($mime !== '' && !in_array($mime, $allowedMime, true)) throw new Exception('Invalid attachment file format.');

        $newFileName = 'cw_' . preg_replace('/[^A-Za-z0-9_-]/', '', $request_no) . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $destination = $targetDir . DIRECTORY_SEPARATOR . $newFileName;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new Exception('Failed to save attachment file.');
        }

        $relativePath = 'uploads/centralwarehouse_attachments/' . $newFileName;
        $stmt->bind_param('sissssii', $request_no, $request_id, $safeOriginal, $newFileName, $relativePath, $mime, $size, $uploaded_by);
        if (!$stmt->execute()) throw new Exception('Failed to save attachment record.');
    }

    if ($finfo) finfo_close($finfo);
    $stmt->close();
}
function initials(string $name): string { $out=''; foreach(explode(' ', trim($name)) as $p){ if($p!=='') $out .= strtoupper(substr($p,0,1)); } return $out ?: 'CW'; }
function badgeStatus($status){
    $status = strtolower((string)$status);
    if ($status === 'released' || $status === 'returned') return 'bg-success';
    if ($status === 'pending' || $status === 'pending_return') return 'bg-warning text-dark';
    if ($status === 'overdue') return 'bg-danger';
    return 'bg-secondary';
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
});
</script>
</body>
</html>
<?php } ?>

<?php
$message=''; $error='';
ensureCentralWarehouseAttachmentsTable($conn);

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='release_request') {
    $request_no = trim($_POST['request_no'] ?? '');
    $release_remarks = trim($_POST['release_remarks'] ?? '');
    $release_qty_inputs = $_POST['release_qty'] ?? [];

    if ($request_no === '') {
        $error = 'Invalid request number.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT r.*, cws.current_stock, i.item_name
                                    FROM central_warehouse_atw_requests r
                                    INNER JOIN central_warehouse_stocks cws ON cws.central_stock_id = r.central_stock_id
                                    INNER JOIN items i ON i.item_id = r.item_id
                                    WHERE r.request_no = ?
                                    FOR UPDATE");
            if (!$stmt) throw new Exception('Failed to prepare release lookup.');
            $stmt->bind_param('s', $request_no);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($rows)) throw new Exception('Request not found.');

            foreach ($rows as $row) {
                $rid = (int)$row['request_id'];
                $requested_qty = (float)$row['requested_qty'];
                $release_qty = isset($release_qty_inputs[$rid]) ? (float)$release_qty_inputs[$rid] : $requested_qty;

                if ($row['status'] !== 'pending') {
                    throw new Exception('This request is already processed.');
                }
                if ($release_qty <= 0) {
                    throw new Exception('Qty Release must be greater than zero for ' . $row['item_name'] . '.');
                }
                if ($release_qty > $requested_qty) {
                    throw new Exception('Qty Release cannot exceed requested qty for ' . $row['item_name'] . '.');
                }
                if ((float)$row['current_stock'] < $release_qty) {
                    throw new Exception('Insufficient stock for ' . $row['item_name'] . '.');
                }
            }

            foreach ($rows as $row) {
                $rid = (int)$row['request_id'];
                $qty = isset($release_qty_inputs[$rid]) ? (float)$release_qty_inputs[$rid] : (float)$row['requested_qty'];
                $stock_id = (int)$row['central_stock_id'];
                $toReturn = (int)$row['to_be_returned'] === 1 ? 'pending_return' : 'not_required';
                $recipient = $row['authorized_recipient'] ?: $row['requested_by'];

                $up = $conn->prepare("UPDATE central_warehouse_stocks
                                      SET current_stock = current_stock - ?, updated_at = NOW()
                                      WHERE central_stock_id = ? AND current_stock >= ?");
                if (!$up) throw new Exception('Failed to prepare stock deduction.');
                $up->bind_param('did', $qty, $stock_id, $qty);
                $up->execute();
                if ($up->affected_rows <= 0) throw new Exception('Stock deduction failed.');
                $up->close();

                $ur = $conn->prepare("UPDATE central_warehouse_atw_requests
                                      SET status = 'released',
                                          requested_qty = ?,
                                          approved_qty = ?,
                                          released_by = ?,
                                          released_at = NOW(),
                                          received_by = ?,
                                          withdrawn_by = ?,
                                          return_status = ?,
                                          release_remarks = ?
                                      WHERE request_id = ? AND status = 'pending'");
                if (!$ur) throw new Exception('Failed to prepare release update.');
                $ur->bind_param('ddissssi', $qty, $qty, $user_id, $recipient, $recipient, $toReturn, $release_remarks, $rid);
                $ur->execute();
                if ($ur->affected_rows <= 0) throw new Exception('Release update failed.');
                $ur->close();
            }

            saveCentralWarehouseAttachments($conn, $request_no, null, $user_id);

            $conn->commit();
            $message = 'ATW request released successfully.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$requestsResult = $conn->query("SELECT r.request_no,
        MAX(r.request_date) request_date,
        MAX(r.business_unit) business_unit,
        MAX(b.branch_name) branch_name,
        MAX(r.requested_by) requested_by,
        MAX(r.authorized_recipient) authorized_recipient,
        MAX(r.purpose) purpose,
        COUNT(*) item_count,
        GROUP_CONCAT(CONCAT(i.item_name,' (',FORMAT(r.requested_qty,2),' ',COALESCE(ut.unit_type_name,i.unit_type,'Piece'),')') ORDER BY i.item_name SEPARATOR '<br>') items_summary
    FROM central_warehouse_atw_requests r
    INNER JOIN items i ON i.item_id = r.item_id
    LEFT JOIN unit_types ut ON ut.unit_type_id = r.unit_type_id
    LEFT JOIN branches b ON b.branch_id = r.branch_id
    WHERE r.status = 'pending'
    GROUP BY r.request_no
    ORDER BY MAX(r.created_at) DESC");

$requests = [];
$pendingRequestNos = [];
if ($requestsResult) {
    while ($row = $requestsResult->fetch_assoc()) {
        $requests[] = $row;
        $pendingRequestNos[] = $row['request_no'];
    }
}

$releasedResult = $conn->query("SELECT r.request_no,
        MAX(r.request_date) request_date,
        MAX(r.released_at) released_at,
        MAX(r.business_unit) business_unit,
        MAX(b.branch_name) branch_name,
        MAX(r.requested_by) requested_by,
        MAX(r.authorized_recipient) authorized_recipient,
        MAX(r.withdrawn_by) withdrawn_by,
        MAX(r.received_by) received_by,
        MAX(r.purpose) purpose,
        MAX(r.release_remarks) release_remarks,
        COUNT(*) item_count,
        GROUP_CONCAT(CONCAT(i.item_name,' (',FORMAT(COALESCE(r.approved_qty,r.requested_qty),2),' ',COALESCE(ut.unit_type_name,i.unit_type,'Piece'),')') ORDER BY i.item_name SEPARATOR '<br>') items_summary
    FROM central_warehouse_atw_requests r
    INNER JOIN items i ON i.item_id = r.item_id
    LEFT JOIN unit_types ut ON ut.unit_type_id = r.unit_type_id
    LEFT JOIN branches b ON b.branch_id = r.branch_id
    WHERE r.status = 'released'
    GROUP BY r.request_no
    ORDER BY MAX(r.released_at) DESC, MAX(r.created_at) DESC");

$releasedRequests = [];
$releasedRequestNos = [];
if ($releasedResult) {
    while ($row = $releasedResult->fetch_assoc()) {
        $releasedRequests[] = $row;
        $releasedRequestNos[] = $row['request_no'];
    }
}

$allRequestNos = array_values(array_unique(array_merge($pendingRequestNos, $releasedRequestNos)));

$requestItems = [];
if (!empty($allRequestNos)) {
    $placeholders = implode(',', array_fill(0, count($allRequestNos), '?'));
    $types = str_repeat('s', count($allRequestNos));
    $sqlItems = "SELECT r.request_id,
                        r.request_no,
                        r.requested_qty,
                        COALESCE(r.approved_qty, r.requested_qty) AS final_qty,
                        r.business_unit,
                        r.authorized_recipient,
                        r.status,
                        r.released_at,
                        i.item_name,
                        COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_name,
                        b.branch_name
                 FROM central_warehouse_atw_requests r
                 INNER JOIN items i ON i.item_id = r.item_id
                 LEFT JOIN unit_types ut ON ut.unit_type_id = r.unit_type_id
                 LEFT JOIN branches b ON b.branch_id = r.branch_id
                 WHERE r.request_no IN ($placeholders)
                 ORDER BY r.request_no, i.item_name";
    $stmtItems = $conn->prepare($sqlItems);
    if ($stmtItems) {
        $stmtItems->bind_param($types, ...$allRequestNos);
        $stmtItems->execute();
        $resItems = $stmtItems->get_result();
        while ($item = $resItems->fetch_assoc()) {
            $requestItems[$item['request_no']][] = $item;
        }
        $stmtItems->close();
    }
}

$requestAttachments = [];
if (!empty($allRequestNos)) {
    $placeholders = implode(',', array_fill(0, count($allRequestNos), '?'));
    $types = str_repeat('s', count($allRequestNos));
    $attSql = "SELECT attachment_id, request_no, original_name, file_name, file_path, mime_type, file_size, uploaded_at
               FROM central_warehouse_attachments
               WHERE request_no IN ($placeholders)
               ORDER BY uploaded_at DESC, attachment_id DESC";
    $attStmt = $conn->prepare($attSql);
    if ($attStmt) {
        $attStmt->bind_param($types, ...$allRequestNos);
        $attStmt->execute();
        $attRes = $attStmt->get_result();
        while ($att = $attRes->fetch_assoc()) {
            $requestAttachments[$att['request_no']][] = $att;
        }
        $attStmt->close();
    }
}

pageHeader('Release Requests','Pending Authority to Withdraw Requests','release');
?>
<?php if($message): showPageSweetAlert('success', $message); endif; ?>
<?php if($error): showPageSweetAlert('error', $error); endif; ?>
<ul class="nav nav-tabs dashboard-tabs mb-0" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pendingPane" type="button" role="tab">
            Pending ATW Requests
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="released-tab" data-bs-toggle="tab" data-bs-target="#releasedPane" type="button" role="tab">
            Released History
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pendingPane" role="tabpanel" aria-labelledby="pending-tab">
        <div class="tab-card">
            <div class="section-title"><h5>Pending ATW Requests</h5></div>
            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle">
                    <thead>
                        <tr>
                            <th>ATW No.</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Items</th>
                            <th>Authorized Recipient</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No pending ATW requests.</td></tr>
                    <?php else: foreach ($requests as $r): ?>
                        <tr class="clickable-atw-row">
                            <td><strong><?= h($r['request_no']) ?></strong></td>
                            <td><?= h($r['request_date'] ?? '-') ?></td>
                            <td><?= h($r['branch_name'] ?? '-') ?><br><small class="text-muted"><?= h($r['business_unit'] ?? '') ?></small></td>
                            <td><?= $r['items_summary'] ?></td>
                            <td><?= h($r['authorized_recipient'] ?: '-') ?></td>
                            <td>
                                <button class="btn btn-success btn-sm btn-action-text" data-bs-toggle="modal" data-bs-target="#releaseModal<?= h(md5($r['request_no'])) ?>">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>Release
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="releasedPane" role="tabpanel" aria-labelledby="released-tab">
        <div class="tab-card">
            <div class="section-title"><h5>Released History</h5></div>
            <div class="table-responsive">
                <table class="table custom-table compact-table align-middle">
                    <thead>
                        <tr>
                            <th>ATW No.</th>
                            <th>Released Date</th>
                            <th>Branch</th>
                            <th>Items</th>
                            <th>Authorized Recipient</th>
                            <th>Released By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($releasedRequests)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No released ATW history yet.</td></tr>
                    <?php else: foreach ($releasedRequests as $hr): ?>
                        <tr class="clickable-atw-row" data-bs-toggle="modal" data-bs-target="#historyModal<?= h(md5($hr['request_no'])) ?>">
                            <td><strong><?= h($hr['request_no']) ?></strong></td>
                            <td><?= h($hr['released_at'] ?: '-') ?></td>
                            <td><?= h($hr['branch_name'] ?? '-') ?><br><small class="text-muted"><?= h($hr['business_unit'] ?? '') ?></small></td>
                            <td><?= $hr['items_summary'] ?></td>
                            <td><?= h($hr['authorized_recipient'] ?: '-') ?></td>
                            <td><?= h(($hr['withdrawn_by'] ?: $hr['received_by']) ?: '-') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php foreach ($requests as $r): ?>
<div class="modal fade release-atw-modal" id="releaseModal<?= h(md5($r['request_no'])) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:#067857;color:#fff;">
                <h5 class="modal-title">Release ATW Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="release_request">
                    <input type="hidden" name="request_no" value="<?= h($r['request_no']) ?>">

                    <div class="mb-3 p-3 rounded" style="background:#f8fafc;border:1px solid #e9ecef;">
                        <div class="profile-info-label">Authorized Recipient</div>
                        <div class="profile-info-value fs-6"><?= h($r['authorized_recipient'] ?: '-') ?></div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table custom-table compact-table align-middle">
                            <thead>
                                <tr>
                                    <th>ATW No.</th>
                                    <th>Items</th>
                                    <th>Branch</th>
                                    <th>Qty</th>
                                    <th>Qty Released</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (($requestItems[$r['request_no']] ?? []) as $item): ?>
                                <tr>
                                    <td><strong><?= h($item['request_no']) ?></strong></td>
                                    <td><?= h($item['item_name']) ?></td>
                                    <td><?= h($item['branch_name'] ?: '-') ?><br><small class="text-muted"><?= h($item['business_unit'] ?: '') ?></small></td>
                                    <td><?= h(number_format((float)$item['requested_qty'], 2)) ?> <?= h($item['unit_name']) ?></td>
                                    <td>
                                        <input type="number"
                                               class="form-control"
                                               name="release_qty[<?= h($item['request_id']) ?>]"
                                               value="<?= h((float)$item['requested_qty']) ?>"
                                               min="0.01"
                                               max="<?= h((float)$item['requested_qty']) ?>"
                                               step="0.01"
                                               required>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Attachments</label>
                        <input type="file" name="release_attachments[]" class="form-control release-attachment-input" multiple
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                        <small class="text-muted">Upload attachments. Allowed: JPG, PNG, WEBP, GIF. Maximum 5MB.</small>
                        <div class="attachment-preview-grid mt-2"></div>
                    </div>

                    <label class="form-label">Release Remarks</label>
                    <textarea name="release_remarks" class="form-control" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Release</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php foreach ($releasedRequests as $hr): ?>
<div class="modal fade" id="historyModal<?= h(md5($hr['request_no'])) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:#067857;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Released ATW Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="profile-summary-grid">
                    <div class="profile-summary-item">
                        <div class="profile-info-label">ATW No.</div>
                        <div class="profile-info-value"><?= h($hr['request_no']) ?></div>
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
                        <div class="profile-info-label">Released By</div>
                        <div class="profile-info-value"><?= h(($hr['withdrawn_by'] ?: $hr['received_by']) ?: '-') ?></div>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table custom-table compact-table align-middle">
                        <thead>
                            <tr>
                                <th>ATW No.</th>
                                <th>Items</th>
                                <th>Branch</th>
                                <th>Qty Released</th>
                                <th>Released Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($requestItems[$hr['request_no']] ?? []) as $item): ?>
                            <tr>
                                <td><strong><?= h($item['request_no']) ?></strong></td>
                                <td><?= h($item['item_name']) ?></td>
                                <td><?= h($item['branch_name'] ?: '-') ?><br><small class="text-muted"><?= h($item['business_unit'] ?: '') ?></small></td>
                                <td><?= h(number_format((float)$item['final_qty'], 2)) ?> <?= h($item['unit_name']) ?></td>
                                <td><?= h($item['released_at'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($hr['release_remarks'])): ?>
                    <div class="mb-3">
                        <div class="profile-info-label">Release Remarks</div>
                        <div class="profile-info-value p-2 rounded" style="background:#f8fafc;border:1px solid #e9ecef;"><?= nl2br(h($hr['release_remarks'])) ?></div>
                    </div>
                <?php endif; ?>

                <div class="mb-2">
                    <div class="profile-info-label mb-2">Attachments</div>
                    <?php if (empty($requestAttachments[$hr['request_no']])): ?>
                        <div class="text-muted">No attachments uploaded.</div>
                    <?php else: ?>
                        <div class="attachment-preview-grid">
                            <?php foreach ($requestAttachments[$hr['request_no']] as $att):
                                $attUrl = '../' . ltrim((string)$att['file_path'], '/');
                                $mime = (string)($att['mime_type'] ?? '');
                                $isImage = strpos($mime, 'image/') === 0;
                                $isPdf = $mime === 'application/pdf';
                            ?>
                                <div class="attachment-preview-card" onclick="openAttachmentPreview('<?= h($attUrl) ?>', '<?= h($att['original_name']) ?>', '<?= h($mime) ?>')">
                                    <?php if ($isImage): ?>
                                        <img src="<?= h($attUrl) ?>" alt="<?= h($att['original_name']) ?>">
                                    <?php elseif ($isPdf): ?>
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    <?php else: ?>
                                        <i class="bi bi-file-earmark-text"></i>
                                    <?php endif; ?>
                                    <div class="attachment-preview-name"><?= h($att['original_name']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="attachmentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:#067857;color:#fff;">
                <h5 class="modal-title" id="attachmentPreviewTitle">Attachment Preview</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center" id="attachmentPreviewBody"></div>
        </div>
    </div>
</div>

<style>
/* Scrollable Release ATW Request modal body */
.release-atw-modal .modal-dialog {
    height: calc(100vh - 3.5rem);
    margin-top: 1.75rem;
    margin-bottom: 1.75rem;
}
.release-atw-modal .modal-content {
    max-height: 100%;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.release-atw-modal form {
    min-height: 0;
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
}
.release-atw-modal .modal-body {
    overflow-y: auto;
    max-height: none;
    min-height: 0;
}
.release-atw-modal .modal-header,
.release-atw-modal .modal-footer {
    flex: 0 0 auto;
}
@media (max-width: 576px) {
    .release-atw-modal .modal-dialog {
        height: calc(100vh - 1rem);
        margin: .5rem;
    }
}

.attachment-preview-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(150px, 150px));
    gap:12px;
    align-items:start;
}
.attachment-preview-card {
    border:1px solid #e9ecef;
    border-radius:10px;
    padding:8px;
    background:#fff;
    cursor:pointer;
    width:150px;
    height:150px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
    text-align:center;
    transition:.2s ease;
    overflow:hidden;
}
.attachment-preview-card:hover {
    box-shadow:0 4px 12px rgba(0,0,0,.08);
    transform:translateY(-1px);
}
.attachment-preview-card img {
    width:132px;
    height:95px;
    object-fit:cover;
    border-radius:8px;
    margin-bottom:7px;
    flex:0 0 auto;
    background:#f8fafc;
}
.attachment-preview-card i {
    width:132px;
    height:95px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:2.2rem;
    color:#067857;
    background:#f8fafc;
    border-radius:8px;
    margin-bottom:7px;
    flex:0 0 auto;
}
.attachment-preview-name {
    width:100%;
    font-size:.78rem;
    line-height:1.15;
    font-weight:600;
    color:#052A47;
    word-break:break-word;
    overflow:hidden;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
}
#attachmentPreviewBody img {
    max-width:100%;
    max-height:75vh;
    object-fit:contain;
}
#attachmentPreviewBody iframe {
    width:100%;
    height:75vh;
    border:0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.release-attachment-input').forEach(function(input){
        input.addEventListener('change', function(){
            const grid = input.closest('.mb-3').querySelector('.attachment-preview-grid');
            if (!grid) return;
            grid.innerHTML = '';
            Array.from(input.files || []).forEach(function(file){
                const url = URL.createObjectURL(file);
                const card = document.createElement('div');
                card.className = 'attachment-preview-card';
                card.dataset.url = url;
                card.dataset.name = file.name;
                card.dataset.type = file.type || '';

                if ((file.type || '').startsWith('image/')) {
                    card.innerHTML = '<img src="' + url + '" alt=""><div class="attachment-preview-name"></div>';
                } else if ((file.type || '') === 'application/pdf') {
                    card.innerHTML = '<i class="bi bi-file-earmark-pdf"></i><div class="attachment-preview-name"></div>';
                } else {
                    card.innerHTML = '<i class="bi bi-file-earmark-text"></i><div class="attachment-preview-name"></div>';
                }
                card.querySelector('.attachment-preview-name').textContent = file.name;
                card.addEventListener('click', function(){ openAttachmentPreview(url, file.name, file.type || ''); });
                grid.appendChild(card);
            });
        });
    });
});

function openAttachmentPreview(url, name, type) {
    const title = document.getElementById('attachmentPreviewTitle');
    const body = document.getElementById('attachmentPreviewBody');
    if (!title || !body) return;
    title.textContent = name || 'Attachment Preview';
    if ((type || '').startsWith('image/')) {
        body.innerHTML = '<img src="' + url + '" alt="Attachment preview">';
    } else if ((type || '') === 'application/pdf') {
        body.innerHTML = '<iframe src="' + url + '"></iframe>';
    } else {
        body.innerHTML = '<div class="py-5"><i class="bi bi-file-earmark-text" style="font-size:4rem;color:#067857;"></i><h5 class="mt-3">' + escapeHtmlForPreview(name || 'Attachment') + '</h5><p class="text-muted">Preview is not available for this file type, but it will still be uploaded and saved.</p></div>';
    }
    new bootstrap.Modal(document.getElementById('attachmentPreviewModal')).show();
}

function escapeHtmlForPreview(value) {
    return String(value || '').replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}
</script>

<?php pageFooter(); ?>
