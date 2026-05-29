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
.new-item-fields { display:none; border:1px solid #e9ecef; border-radius:12px; padding:14px; background:#f8fafc; }
.image-preview-wrap { display:none; align-items:center; gap:10px; margin-top:8px; }
.image-preview-wrap img { width:58px; height:58px; border-radius:10px; object-fit:cover; border:1px solid #e5e7eb; background:#f8fafc; }
.image-preview-wrap span { font-size:.85rem; color:#6c757d; font-weight:600; }
.stocks-filter-bar { display:grid; grid-template-columns:1.1fr 1fr 1fr auto; gap:12px; align-items:end; margin-bottom:14px; }
.stocks-filter-bar .form-label { font-size:.82rem; font-weight:700; color:#052A47; margin-bottom:5px; }
.stock-row { cursor:pointer; }
.stock-row:hover { background:#f8fafc; }
.stock-details-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px 16px; }
.stock-details-item { border-bottom:1px solid #eef2f6; padding-bottom:8px; min-width:0; }
.stock-details-label { display:block; font-size:.78rem; color:#6c757d; margin-bottom:2px; }
.stock-details-value { font-weight:700; color:#052A47; word-break:break-word; }
.no-filter-result { display:none; }
input.no-spinner::-webkit-outer-spin-button, input.no-spinner::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input.no-spinner[type=number] { -moz-appearance: textfield; appearance: textfield; }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 998; opacity: 0; transition: opacity .25s ease; }
.sidebar-overlay.active { opacity: 1; }
.dropdown-arrow { margin-left: auto; transition: transform .2s ease; }
@media (max-width: 992px) { .profile-summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } .sidebar { transform: translateX(-100%); transition: transform .25s ease; z-index: 999; } .sidebar.active, .sidebar.show { transform: translateX(0); } }
@media (max-width: 768px) { .stocks-filter-bar { grid-template-columns:1fr; } .stock-details-grid { grid-template-columns:1fr; } }
@media (max-width: 576px) { .profile-summary-grid { grid-template-columns:1fr; } .navbar-top { align-items:flex-start; gap:10px; } .header-actions { width:100%; justify-content:flex-start; margin-left:0; } }

/* Skeleton Loading */
body.page-loading { overflow:hidden; }
.page-skeleton-overlay {
    position: fixed;
    inset: 0;
    background: #f6f8fb;
    z-index: 3000;
    display: flex;
    pointer-events: auto;
    transition: opacity .25s ease, visibility .25s ease;
}
.page-skeleton-overlay.hide {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}
.skeleton-sidebar {
    width: 260px;
    min-height: 100vh;
    background: #052A47;
    padding: 22px 16px;
}
.skeleton-main {
    flex: 1;
    padding: 22px;
    overflow: hidden;
}
.skeleton-line,
.skeleton-block,
.skeleton-card,
.skeleton-circle,
.skeleton-input,
.skeleton-table-row {
    position: relative;
    overflow: hidden;
    background: #e9eef5;
    border-radius: 10px;
}
.skeleton-sidebar .skeleton-line,
.skeleton-sidebar .skeleton-circle {
    background: rgba(255,255,255,.16);
}
.skeleton-line::after,
.skeleton-block::after,
.skeleton-card::after,
.skeleton-circle::after,
.skeleton-input::after,
.skeleton-table-row::after {
    content: "";
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.5), transparent);
    animation: skeletonShimmer 1.15s infinite;
}
.skeleton-sidebar .skeleton-line::after,
.skeleton-sidebar .skeleton-circle::after {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.18), transparent);
}
.skeleton-brand { display:flex; align-items:center; gap:12px; margin-bottom:28px; }
.skeleton-circle { width:42px; height:42px; border-radius:50%; }
.skeleton-nav-line { height:18px; margin:18px 0; }
.skeleton-title { height:30px; width:260px; margin-bottom:8px; }
.skeleton-subtitle { height:16px; width:340px; margin-bottom:22px; }
.skeleton-card { background:#fff; border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:18px; }
.skeleton-card-title { height:22px; width:230px; margin-bottom:18px; }
.skeleton-form-grid {
    display:grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap:14px;
}
.skeleton-input { height:42px; }
.skeleton-table-header { height:42px; background:#dce5ee; border-radius:10px; margin-bottom:10px; }
.skeleton-table-row { height:54px; margin-bottom:10px; }
.inline-skeleton {
    display:none;
    gap:10px;
    align-items:center;
    margin-top:10px;
}
.inline-skeleton.active { display:flex; }
.inline-skeleton span {
    height:12px;
    background:#e9eef5;
    border-radius:999px;
    position:relative;
    overflow:hidden;
}
.inline-skeleton span::after {
    content:"";
    position:absolute;
    inset:0;
    transform:translateX(-100%);
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.7), transparent);
    animation:skeletonShimmer 1.15s infinite;
}
.inline-skeleton .dot { width:34px; height:34px; border-radius:50%; }
.inline-skeleton .short { width:90px; }
.inline-skeleton .long { width:150px; }
.table-loading-wrap { position:relative; }
.table-skeleton {
    display:none;
    background:#fff;
    border-radius:12px;
    padding:12px;
    border:1px solid #eef2f6;
}
.table-skeleton.active { display:block; }
.table-skeleton .skeleton-table-row { height:48px; }
.table-loading-wrap.loading .table-responsive { display:none; }
.table-loading-wrap.loading .table-skeleton { display:block; }
.select-loading {
    background-image:
        linear-gradient(90deg, transparent, rgba(255,255,255,.75), transparent),
        linear-gradient(#e9eef5, #e9eef5) !important;
    background-size: 220px 100%, 100% 100%;
    background-position: -220px 0, 0 0;
    background-repeat: no-repeat;
    animation: selectSkeleton 1.1s infinite;
    color: transparent !important;
}
@keyframes skeletonShimmer { 100% { transform: translateX(100%); } }
@keyframes selectSkeleton { 100% { background-position: calc(100% + 220px) 0, 0 0; } }

@media (max-width: 992px) {
    .skeleton-sidebar { display:none; }
    .skeleton-main { padding:18px; }
    .skeleton-form-grid { grid-template-columns:repeat(2, minmax(0,1fr)); }
}
@media (max-width: 576px) {
    .skeleton-form-grid { grid-template-columns:1fr; }
    .skeleton-title { width:80%; }
    .skeleton-subtitle { width:90%; }
}

</style>
</head>
<body class="page-loading">

<div class="page-skeleton-overlay" id="pageSkeletonOverlay" aria-hidden="true">
    <div class="skeleton-sidebar">
        <div class="skeleton-brand">
            <div class="skeleton-circle"></div>
            <div class="skeleton-line" style="height:22px;width:150px;"></div>
        </div>
        <div class="skeleton-line skeleton-nav-line" style="width:86%;"></div>
        <div class="skeleton-line skeleton-nav-line" style="width:92%;"></div>
        <div class="skeleton-line skeleton-nav-line" style="width:78%;"></div>
        <div class="skeleton-line skeleton-nav-line" style="width:88%;"></div>
    </div>
    <div class="skeleton-main">
        <div class="skeleton-title skeleton-line"></div>
        <div class="skeleton-subtitle skeleton-line"></div>
        <div class="skeleton-card">
            <div class="skeleton-card-title skeleton-line"></div>
            <div class="skeleton-form-grid">
                <div class="skeleton-input"></div>
                <div class="skeleton-input"></div>
                <div class="skeleton-input"></div>
                <div class="skeleton-input"></div>
                <div class="skeleton-input"></div>
                <div class="skeleton-input"></div>
            </div>
        </div>
        <div class="skeleton-card">
            <div class="skeleton-card-title skeleton-line" style="width:260px;"></div>
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
    function hidePageSkeleton() {
        const overlay = document.getElementById('pageSkeletonOverlay');
        if (overlay) {
            overlay.classList.add('hide');
            setTimeout(() => {
                overlay.style.display = 'none';
            }, 300);
        }
        document.body.classList.remove('page-loading');
    }

    window.addEventListener('load', function () {
        setTimeout(hidePageSkeleton, 250);
    });

    setTimeout(hidePageSkeleton, 1800);
})();

function logout() {
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = '../logout.php';
    }
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

            overlay.addEventListener('click', () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            });
        }

        overlay.classList.toggle(
            'active',
            sidebar.classList.contains('active')
        );

    } else {
        sidebar.classList.toggle('collapsed');

        if (mainContent) {
            mainContent.classList.toggle(
                'expanded',
                sidebar.classList.contains('collapsed')
            );
        }

        localStorage.setItem(
            'sidebarCollapsed',
            sidebar.classList.contains('collapsed') ? 'true' : 'false'
        );
    }
}

document.addEventListener('DOMContentLoaded', function () {

    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');

    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            toggleSidebar();
        });
    }

    if (mobileToggleBtn) {
        mobileToggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            toggleSidebar();
        });
    }

    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (
        window.innerWidth > 992 &&
        localStorage.getItem('sidebarCollapsed') === 'true' &&
        sidebar
    ) {
        sidebar.classList.add('collapsed');

        if (mainContent) {
            mainContent.classList.add('expanded');
        }
    }
});
</script>
</body>
</html>
<?php } ?>

<?php
function jsonResponse(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function uploadItemImage(string $fieldName, string &$error): string {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'Failed to upload item image.';
        return '';
    }

    $maxSize = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        $error = 'Item image must not exceed 5MB.';
        return '';
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $error = 'Invalid item image upload.';
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
        $error = 'Only JPG, PNG, WEBP, or GIF images are allowed.';
        return '';
    }

    $uploadDir = dirname(__DIR__) . '/uploads/items';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        $error = 'Unable to create item image upload folder.';
        return '';
    }

    $filename = 'item_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        $error = 'Unable to save item image.';
        return '';
    }

    return $filename;
}

function saveItemImageRecord(mysqli $conn, int $itemId, string $filename): void {
    if ($itemId <= 0 || $filename === '') return;

    if (columnExists($conn, 'items', 'product_image_url')) {
        $stmt = $conn->prepare("UPDATE items SET product_image_url=?, updated_at=NOW() WHERE item_id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $filename, $itemId);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (tableExists($conn, 'item_images')) {
        $stmt = $conn->prepare("UPDATE item_images SET is_primary=0 WHERE item_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("INSERT INTO item_images (item_id, image_path, image_order, is_primary) VALUES (?, ?, 0, 1)");
        if ($stmt) {
            $stmt->bind_param('is', $itemId, $filename);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    if ($ajax === 'branches') {
        $business_unit = trim($_GET['business_unit'] ?? '');
        $stmt = $conn->prepare("SELECT branch_id, branch_name, branch_code, business_unit FROM branches WHERE status='active' AND business_unit=? ORDER BY branch_name ASC");
        $stmt->bind_param('s', $business_unit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        jsonResponse(['success'=>true, 'branches'=>$rows]);
    }

    if ($ajax === 'items') {
        $business_unit = trim($_GET['business_unit'] ?? '');
        $branch_id = (int)($_GET['branch_id'] ?? 0);

        // IMPORTANT:
        // The item dropdown must only show items that already exist in the
        // Central Warehouse Stocks table for the selected Branch + Business Unit.
        // This prevents the encoder from selecting unrelated items that are not
        // assigned/encoded yet for that branch.
        $stmt = $conn->prepare("SELECT DISTINCT
                                    i.item_id,
                                    i.item_code,
                                    i.item_name,
                                    i.category,
                                    i.principal,
                                    i.unit_type,
                                    i.default_unit_type_id
                                FROM central_warehouse_stocks cws
                                INNER JOIN items i ON i.item_id = cws.item_id
                                WHERE cws.status = 'active'
                                  AND i.status = 'active'
                                  AND cws.business_unit = ?
                                  AND cws.branch_id = ?
                                ORDER BY i.item_name ASC");
        $stmt->bind_param('si', $business_unit, $branch_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        jsonResponse(['success'=>true, 'items'=>$rows]);
    }

    if ($ajax === 'units') {
        $business_unit = trim($_GET['business_unit'] ?? '');
        $branch_id = (int)($_GET['branch_id'] ?? 0);
        $item_id = (int)($_GET['item_id'] ?? 0);

        $stmt = $conn->prepare("SELECT i.item_id, i.unit_type, i.default_unit_type_id, i.default_uom_id, i.smallest_uom_id
                                FROM items i
                                LEFT JOIN branches b ON b.branch_id=i.branch_id
                                WHERE i.status='active'
                                  AND i.item_id=?
                                  AND (
                                        (b.status='active' AND b.business_unit=? AND i.branch_id=?)
                                        OR i.branch_id=0
                                        OR i.branch_id IS NULL
                                  )
                                LIMIT 1");
        $stmt->bind_param('isi', $item_id, $business_unit, $branch_id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $units = [];
        if ($item) {
            $addedIds = [];
            $addedNames = [];
            $unitIds = [];
            foreach (['default_unit_type_id','default_uom_id','smallest_uom_id'] as $key) {
                $id = (int)($item[$key] ?? 0);
                if ($id > 0) $unitIds[] = $id;
            }
            $unitIds = array_values(array_unique($unitIds));

            if (!empty($unitIds)) {
                $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
                $types = str_repeat('i', count($unitIds));
                $sql = "SELECT unit_type_id, unit_type_name FROM unit_types WHERE status='active' AND unit_type_id IN ($placeholders) ORDER BY FIELD(unit_type_id, $placeholders)";
                $stmt = $conn->prepare($sql);
                $bindValues = array_merge($unitIds, $unitIds);
                $stmt->bind_param($types . $types, ...$bindValues);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($u = $res->fetch_assoc()) {
                    $uid = (int)$u['unit_type_id'];
                    $nameKey = strtolower(trim($u['unit_type_name']));
                    $addedIds[$uid] = true;
                    $addedNames[$nameKey] = true;
                    $units[] = ['unit_type_id'=>$uid, 'unit_type_name'=>$u['unit_type_name']];
                }
                $stmt->close();
            }

            if (!empty($item['unit_type'])) {
                $unitName = trim($item['unit_type']);
                $nameKey = strtolower($unitName);
                if ($unitName !== '' && !isset($addedNames[$nameKey])) {
                    $matchedId = 0;
                    $stmt = $conn->prepare("SELECT unit_type_id, unit_type_name FROM unit_types WHERE status='active' AND unit_type_name=? AND (branch_id=? OR branch_id=0 OR branch_id IS NULL) ORDER BY branch_id DESC LIMIT 1");
                    $stmt->bind_param('si', $unitName, $branch_id);
                    $stmt->execute();
                    $matched = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($matched) {
                        $matchedId = (int)$matched['unit_type_id'];
                        $unitName = $matched['unit_type_name'];
                    }
                    $units[] = ['unit_type_id'=>$matchedId, 'unit_type_name'=>$unitName];
                }
            }
        }

        jsonResponse(['success'=>true, 'units'=>$units]);
    }

    jsonResponse(['success'=>false, 'message'=>'Invalid request.']);
}

$message=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save_stock') {
    $business_unit=trim($_POST['business_unit'] ?? '');
    $branch_id=(int)($_POST['branch_id'] ?? 0);
    $item_mode=trim($_POST['item_id'] ?? '');
    $item_id=(int)$item_mode;
    $unit_type_id=(int)($_POST['unit_type_id'] ?? 0);
    $qty=(float)str_replace(',', '', $_POST['current_stock'] ?? 0);
    $as_of_date=$_POST['as_of_date'] ?: date('Y-m-d');
    $remarks=trim($_POST['remarks'] ?? '');
    $uploaded_item_image = uploadItemImage('item_image', $error);

    if($error !== ''){
        // Keep upload validation error.
    } elseif($business_unit==='' || $branch_id<=0 || $qty<0){
        $error='Please complete the required fields.';
    } else {
        $stmt=$conn->prepare("SELECT branch_id FROM branches WHERE branch_id=? AND business_unit=? AND status='active' LIMIT 1");
        $stmt->bind_param('is',$branch_id,$business_unit);
        $stmt->execute();
        $validBranch=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$validBranch){
            $error='Selected branch does not belong to the selected Business Unit.';
        } else {
            if ($item_mode === '__new') {
                $new_item_code = trim($_POST['new_item_code'] ?? '');
                $new_item_name = trim($_POST['new_item_name'] ?? '');
                $new_category = trim($_POST['new_category'] ?? '');
                $new_principal = trim($_POST['new_principal'] ?? '');
                $new_unit_type_name = trim($_POST['new_unit_type_name'] ?? '');

                if ($new_item_name === '' || $new_unit_type_name === '') {
                    $error = 'Please enter the new Item Name and Unit Type.';
                } else {
                    if ($new_item_code === '') {
                        $prefix = 'CW'.date('ymd');
                        $res = $conn->query("SELECT COUNT(*) AS total FROM items WHERE DATE(created_at)=CURDATE()");
                        $count = $res ? ((int)$res->fetch_assoc()['total'] + 1) : 1;
                        $new_item_code = $prefix . '-' . str_pad((string)$count, 3, '0', STR_PAD_LEFT);
                    }

                    $stmt=$conn->prepare("SELECT item_id FROM items WHERE item_code=? AND (branch_id=0 OR branch_id IS NULL) AND status='active' LIMIT 1");
                    $stmt->bind_param('s',$new_item_code);
                    $stmt->execute();
                    $duplicateItem=$stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($duplicateItem) {
                        $error = 'Item Code already exists for the selected Branch.';
                    } else {
                        $stmt=$conn->prepare("SELECT unit_type_id FROM unit_types WHERE unit_type_name=? AND (branch_id=? OR branch_id=0 OR branch_id IS NULL) AND status='active' ORDER BY branch_id DESC LIMIT 1");
                        $stmt->bind_param('si',$new_unit_type_name,$branch_id);
                        $stmt->execute();
                        $existingUnit=$stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if ($existingUnit) {
                            $unit_type_id = (int)$existingUnit['unit_type_id'];
                        } else {
                            $stmt=$conn->prepare("INSERT INTO unit_types (unit_type_name, uom_initial, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES (?, '', 1, 1, 1.00, 0, 'active')");
                            $stmt->bind_param('s',$new_unit_type_name);
                            if($stmt && $stmt->execute()) $unit_type_id = (int)$stmt->insert_id; else $error='Failed to save new unit type: '.($stmt?$stmt->error:$conn->error);
                            if($stmt) $stmt->close();
                        }

                        if ($error === '') {
                            // IMPORTANT:
                            // Stocks encoded here are Central Warehouse stocks only.
                            // Do not mirror the encoded quantity to items.stock/base_stock/stock_in_default_uom,
                            // because BranchAdmin/current_inventory.php reads from the branch item inventory.
                            // The actual encoded quantity is saved below in central_warehouse_stocks.
                            $base_stock = 0;
                            $stock_in_default = 0;
                            $central_item_branch_id = 0;
                            $stmt=$conn->prepare("INSERT INTO items (item_code, item_name, description, category, principal, stock, base_stock, base_unit_type, unit_type, default_unit_type_id, default_uom_id, smallest_uom_id, status, created_by, branch_id, stock_in_default_uom) VALUES (?, ?, '', ?, ?, ?, 0, 'Piece', ?, ?, ?, ?, 'active', ?, ?, ?)");
                            $stmt->bind_param('ssssdsiiiiid', $new_item_code, $new_item_name, $new_category, $new_principal, $base_stock, $new_unit_type_name, $unit_type_id, $unit_type_id, $unit_type_id, $user_id, $central_item_branch_id, $stock_in_default);
                            if($stmt && $stmt->execute()) {
                                $item_id = (int)$stmt->insert_id;
                                if ($uploaded_item_image !== '') saveItemImageRecord($conn, $item_id, $uploaded_item_image);
                            } else {
                                $error='Failed to save new item: '.($stmt?$stmt->error:$conn->error);
                            }
                            if($stmt) $stmt->close();
                        }
                    }
                }
            } else {
                if ($item_id <= 0) {
                    $error='Please select an item.';
                } else {
                    // Existing item must already be visible in the Central Warehouse Stocks
                    // list for this selected Branch + Business Unit.
                    $stmt=$conn->prepare("SELECT cws.central_stock_id
                                          FROM central_warehouse_stocks cws
                                          INNER JOIN items i ON i.item_id = cws.item_id
                                          WHERE cws.item_id=?
                                            AND cws.branch_id=?
                                            AND cws.business_unit=?
                                            AND cws.status='active'
                                            AND i.status='active'
                                          LIMIT 1");
                    $stmt->bind_param('iis',$item_id,$branch_id,$business_unit);
                    $stmt->execute();
                    $validItem=$stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if(!$validItem){
                        $error='Selected item is not listed in Central Warehouse Stocks for the selected Branch.';
                    } elseif ($uploaded_item_image !== '') {
                        saveItemImageRecord($conn, $item_id, $uploaded_item_image);
                    }
                }
            }

            if ($error === '' && $item_id > 0) {
                if ($unit_type_id <= 0 && $item_mode !== '__new') {
                    $stmt=$conn->prepare("SELECT default_unit_type_id, default_uom_id, smallest_uom_id FROM items WHERE item_id=? LIMIT 1");
                    $stmt->bind_param('i',$item_id);
                    $stmt->execute();
                    $fallback=$stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    foreach(['default_unit_type_id','default_uom_id','smallest_uom_id'] as $key){
                        if((int)($fallback[$key] ?? 0)>0){ $unit_type_id=(int)$fallback[$key]; break; }
                    }
                }

                $stmt=$conn->prepare("SELECT central_stock_id FROM central_warehouse_stocks WHERE branch_id=? AND item_id=? AND business_unit=? AND IFNULL(unit_type_id,0)=? AND status='active' LIMIT 1");
                $stmt->bind_param('iisi',$branch_id,$item_id,$business_unit,$unit_type_id);
                $stmt->execute();
                $existing=$stmt->get_result()->fetch_assoc();
                $stmt->close();

                if($existing){
                    $id=(int)$existing['central_stock_id'];
                    $stmt=$conn->prepare("UPDATE central_warehouse_stocks SET current_stock=COALESCE(current_stock,0)+?, as_of_date=?, remarks=?, received_by=?, encoded_by=?, updated_at=NOW() WHERE central_stock_id=?");
                    $stmt->bind_param('dsssii',$qty,$as_of_date,$remarks,$user_name,$user_id,$id);
                } else {
                    $stmt=$conn->prepare("INSERT INTO central_warehouse_stocks (business_unit, branch_id, item_id, unit_type_id, current_stock, as_of_date, remarks, received_by, status, encoded_by) VALUES (?,?,?,?,?,?,?,?, 'active', ?)");
                    $unitParam=$unit_type_id>0?$unit_type_id:null;
                    $stmt->bind_param('siiidsssi',$business_unit,$branch_id,$item_id,$unitParam,$qty,$as_of_date,$remarks,$user_name,$user_id);
                }
                if($stmt && $stmt->execute()) $message=($existing ? 'Stock quantity added successfully.' : 'Stock saved successfully.'); else $error='Failed to save stock: '.($stmt?$stmt->error:$conn->error);
                if($stmt) $stmt->close();
            }
        }
    }
}

$businessUnits=$conn->query("SELECT DISTINCT business_unit FROM branches WHERE status='active' AND business_unit IS NOT NULL AND TRIM(business_unit)<>'' ORDER BY business_unit ASC");
$stocksResult=$conn->query("SELECT cws.*, i.item_code, i.item_name, i.category, i.principal,
                            COALESCE(img.image_path, '') AS item_image_path,
                            COALESCE(i.product_image_url, '') AS product_image_url,
                            b.branch_name,
                            COALESCE(ut.unit_type_name, i.unit_type, 'Piece') unit_name,
                            COALESCE(total_stock.total_qty, cws.current_stock, 0) AS total_item_stock
                     FROM central_warehouse_stocks cws
                     INNER JOIN items i ON i.item_id=cws.item_id
                     LEFT JOIN branches b ON b.branch_id=cws.branch_id
                     LEFT JOIN unit_types ut ON ut.unit_type_id=cws.unit_type_id
                     LEFT JOIN (
                         SELECT item_id, IFNULL(unit_type_id,0) AS unit_key, SUM(current_stock) AS total_qty
                         FROM central_warehouse_stocks
                         WHERE status='active'
                         GROUP BY item_id, IFNULL(unit_type_id,0)
                     ) total_stock ON total_stock.item_id = cws.item_id AND total_stock.unit_key = IFNULL(cws.unit_type_id,0)
                     LEFT JOIN item_images img ON img.image_id = (
                         SELECT ii.image_id
                         FROM item_images ii
                         WHERE ii.item_id = i.item_id
                         ORDER BY ii.is_primary DESC, ii.image_order ASC, ii.image_id ASC
                         LIMIT 1
                     )
                     WHERE cws.status='active'
                     ORDER BY cws.updated_at DESC, cws.central_stock_id DESC");
$stockRows = [];
$stockBranches = [];
$stockBusinessUnits = [];
if ($stocksResult) {
    while ($row = $stocksResult->fetch_assoc()) {
        $stockRows[] = $row;
        $branchName = trim((string)($row['branch_name'] ?? ''));
        $businessUnitName = trim((string)($row['business_unit'] ?? ''));
        if ($branchName !== '') $stockBranches[$branchName] = $branchName;
        if ($businessUnitName !== '') $stockBusinessUnits[$businessUnitName] = $businessUnitName;
    }
}
ksort($stockBranches, SORT_NATURAL | SORT_FLAG_CASE);
ksort($stockBusinessUnits, SORT_NATURAL | SORT_FLAG_CASE);
pageHeader('Encode Stocks','Central Warehouse Stock Encoding','stock');
?>
<?php if($message): showPageSweetAlert('success', $message); endif; ?>
<?php if($error): showPageSweetAlert('error', $error); endif; ?>
<div class="form-card mb-3">
    <div class="section-title"><h5><i class="bi bi-pencil-square me-1"></i>Encode / Update Stock</h5></div>
    <form method="POST" enctype="multipart/form-data" class="row g-3" id="encodeStockForm">
        <input type="hidden" name="action" value="save_stock">
        <div class="col-md-4">
            <label class="form-label">Business Unit</label>
            <select name="business_unit" id="businessUnitSelect" class="form-select" required>
                <option value="">Select Business Unit</option>
                <?php if($businessUnits): while($bu=$businessUnits->fetch_assoc()): ?>
                    <option value="<?= h($bu['business_unit']) ?>"><?= h($bu['business_unit']) ?></option>
                <?php endwhile; endif; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Branch</label>
            <select name="branch_id" id="branchSelect" class="form-select" required disabled>
                <option value="">Select Business Unit first</option>
            </select>
            <div class="inline-skeleton" id="branchInlineSkeleton"><span class="dot"></span><span class="long"></span></div>
        </div>
        <div class="col-md-4"><label class="form-label">As of Date</label><input type="date" name="as_of_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required></div>
        <div class="col-md-6">
            <label class="form-label">Item</label>
            <select name="item_id" id="itemSelect" class="form-select" required disabled>
                <option value="">Select Branch first</option>
            </select>
            <div class="inline-skeleton" id="itemInlineSkeleton"><span class="dot"></span><span class="long"></span></div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Unit Type</label>
            <select name="unit_type_id" id="unitTypeSelect" class="form-select" disabled>
                <option value="0">Select Item first</option>
            </select>
            <div class="inline-skeleton" id="unitInlineSkeleton"><span class="dot"></span><span class="short"></span></div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Current Stock</label>
            <input
                type="number"
                step="0.01"
                min="0"
                inputmode="decimal"
                name="current_stock"
                class="form-control no-spinner numbers-only"
                required
                oninput="this.value=this.value.replace(/[^0-9.]/g,''); const parts=this.value.split('.'); if(parts.length>2){this.value=parts[0]+'.'+parts.slice(1).join('');}"
            >
        </div>
        <div class="col-md-3">
            <label class="form-label">Item Image <small class="text-muted">(optional)</small></label>
            <input type="file" name="item_image" id="itemImageInput" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
            <div class="image-preview-wrap" id="itemImagePreviewWrap">
                <img src="" alt="Item image preview" id="itemImagePreview">
                <span>Image selected</span>
            </div>
        </div>
        <div class="col-md-12">
            <div class="new-item-fields" id="newItemFields">
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">New Item Code <small class="text-muted">(optional)</small></label><input type="text" name="new_item_code" id="newItemCode" class="form-control" placeholder="Auto if blank"></div>
                    <div class="col-md-3"><label class="form-label">New Item Name</label><input type="text" name="new_item_name" id="newItemName" class="form-control" placeholder="Enter item name"></div>
                    <div class="col-md-2"><label class="form-label">New Unit Type</label><input type="text" name="new_unit_type_name" id="newUnitTypeName" class="form-control" placeholder="Piece, Bag, Box"></div>
                    <div class="col-md-2"><label class="form-label">Category</label><input type="text" name="new_category" class="form-control" placeholder="Optional"></div>
                    <div class="col-md-2"><label class="form-label">Principal</label><input type="text" name="new_principal" class="form-control" placeholder="Optional"></div>
                </div>
            </div>
        </div>
        <div class="col-md-12"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
        <div class="col-md-12 text-end"><button class="btn btn-success btn-action-text"><i class="bi bi-save me-1"></i>Save Stock</button></div>
    </form>
</div>
<div class="tab-card">
    <div class="section-title">
        <h5>Central Warehouse Stocks</h5>
    </div>

    <div class="stocks-filter-bar">
        <div>
            <label class="form-label" for="stockGlobalSearch">Search</label>
            <input type="text" id="stockGlobalSearch" class="form-control" placeholder="Search item code, item name, branch, business unit...">
        </div>
        <div>
            <label class="form-label" for="stockBranchFilter">Filter by Branch</label>
            <select id="stockBranchFilter" class="form-select">
                <option value="">All Branches</option>
                <?php foreach($stockBranches as $branchOption): ?>
                    <option value="<?= h($branchOption) ?>"><?= h($branchOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="stockBusinessUnitFilter">Filter by Business Unit</label>
            <select id="stockBusinessUnitFilter" class="form-select">
                <option value="">All Business Units</option>
                <?php foreach($stockBusinessUnits as $businessUnitOption): ?>
                    <option value="<?= h($businessUnitOption) ?>"><?= h($businessUnitOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
        </div>
    </div>

    <div class="table-loading-wrap" id="stocksTableLoadingWrap">
        <div class="table-skeleton" id="stocksTableSkeleton" aria-hidden="true">
            <div class="skeleton-table-header"></div>
            <div class="skeleton-table-row"></div>
            <div class="skeleton-table-row"></div>
            <div class="skeleton-table-row"></div>
            <div class="skeleton-table-row"></div>
        </div>
        <div class="table-responsive">
        <table class="table custom-table compact-table align-middle" id="centralWarehouseStocksTable">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Branch</th>
                    <th>Business Unit</th>
                    <th>Allocated Stock</th>
                    <th>Total Stock</th>
                    <th>As of Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($stockRows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No stock records yet.</td></tr>
                <?php else: foreach($stockRows as $s): ?>
                <?php
                    $itemImage = trim((string)($s['item_image_path'] ?? ''));
                    $productImage = trim((string)($s['product_image_url'] ?? ''));
                    $itemImageSrc = '';
                    if ($itemImage !== '') {
                        $itemImageSrc = '../uploads/items/' . $itemImage;
                    } elseif ($productImage !== '') {
                        $itemImageSrc = '../uploads/products/' . $productImage;
                    }

                    $itemMeta = trim(($s['category'] ?? '').' '.($s['principal'] ?? ''));
                    $allocatedStockText = number_format((float)$s['current_stock'], 2).' '.$s['unit_name'];
                    $totalStockText = number_format((float)($s['total_item_stock'] ?? $s['current_stock']), 2).' '.$s['unit_name'];
                    $branchText = $s['branch_name'] ?? '-';
                    $businessUnitText = $s['business_unit'] ?? '-';
                    $asOfDateText = $s['as_of_date'] ?? '-';
                    $receivedByText = $s['received_by'] ?? $user_name;
                    $remarksText = $s['remarks'] ?? '-';
                    $searchText = strtolower(trim(
                        ($s['item_code'] ?? '').' '.
                        ($s['item_name'] ?? '').' '.
                        ($s['category'] ?? '').' '.
                        ($s['principal'] ?? '').' '.
                        $branchText.' '.
                        $businessUnitText.' '.
                        $allocatedStockText.' '.
                        $totalStockText.' '.
                        $asOfDateText.' '.
                        $remarksText
                    ));
                ?>
                <tr class="stock-row"
                    data-branch="<?= h(strtolower($branchText)) ?>"
                    data-business-unit="<?= h(strtolower($businessUnitText)) ?>"
                    data-search="<?= h($searchText) ?>"
                    data-image="<?= h($itemImageSrc) ?>"
                    data-item-code="<?= h($s['item_code'] ?? '-') ?>"
                    data-item-name="<?= h($s['item_name'] ?? '-') ?>"
                    data-category="<?= h($s['category'] ?? '-') ?>"
                    data-principal="<?= h($s['principal'] ?? '-') ?>"
                    data-branch-name="<?= h($branchText) ?>"
                    data-business-unit-name="<?= h($businessUnitText) ?>"
                    data-allocated-stock="<?= h($allocatedStockText) ?>"
                    data-total-stock="<?= h($totalStockText) ?>"
                    data-unit-name="<?= h($s['unit_name'] ?? '-') ?>"
                    data-as-of-date="<?= h($asOfDateText) ?>"
                    data-received-by="<?= h($receivedByText) ?>"
                    data-remarks="<?= h($remarksText) ?>">
                    <td>
                        <div class="item-thumbnail">
                            <?php if($itemImageSrc !== ''): ?>
                                <img src="<?= h($itemImageSrc) ?>" alt="<?= h($s['item_name']) ?>">
                            <?php else: ?>
                                <i class="bi bi-image text-muted"></i>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= h($s['item_code']) ?></td>
                    <td>
                        <strong><?= h($s['item_name']) ?></strong><br>
                        <small class="text-muted"><?= h($itemMeta) ?></small>
                    </td>
                    <td><?= h($branchText) ?></td>
                    <td><?= h($businessUnitText) ?></td>
                    <td><span class="fw-semibold"><?= h($allocatedStockText) ?></span></td>
                    <td><span class="fw-semibold text-success"><?= h($totalStockText) ?></span></td>
                    <td><?= h($asOfDateText) ?></td>
                </tr>
                <?php endforeach; endif; ?>
                <tr class="no-filter-result">
                    <td colspan="8" class="text-center text-muted py-4">No matching stock records found.</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="modal fade" id="stockDetailsModal" tabindex="-1" aria-labelledby="stockDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#052A47;color:#fff;">
                <h5 class="modal-title" id="stockDetailsModalLabel">
                    <i class="bi bi-box-seam me-1"></i>Stock Full Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="item-thumbnail" style="width:76px;height:76px;" id="stockModalImageBox">
                        <i class="bi bi-image text-muted fs-3"></i>
                    </div>
                    <div>
                        <h5 class="mb-1 fw-bold text-dark" id="stockModalItemName">-</h5>
                        <div class="text-muted" id="stockModalItemCode">-</div>
                    </div>
                </div>

                <div class="stock-details-grid">
                    <div class="stock-details-item">
                        <span class="stock-details-label">Category</span>
                        <span class="stock-details-value" id="stockModalCategory">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Principal</span>
                        <span class="stock-details-value" id="stockModalPrincipal">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Branch</span>
                        <span class="stock-details-value" id="stockModalBranch">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Business Unit</span>
                        <span class="stock-details-value" id="stockModalBusinessUnit">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Allocated Stock</span>
                        <span class="stock-details-value" id="stockModalAllocatedStock">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Total Stock</span>
                        <span class="stock-details-value text-success" id="stockModalTotalStock">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Unit Type</span>
                        <span class="stock-details-value" id="stockModalUnitName">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">As of Date</span>
                        <span class="stock-details-value" id="stockModalAsOfDate">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Received / Encoded By</span>
                        <span class="stock-details-value" id="stockModalReceivedBy">-</span>
                    </div>
                    <div class="stock-details-item">
                        <span class="stock-details-label">Remarks</span>
                        <span class="stock-details-value" id="stockModalRemarks">-</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const businessUnitSelect = document.getElementById('businessUnitSelect');
    const branchSelect = document.getElementById('branchSelect');
    const itemSelect = document.getElementById('itemSelect');
    const unitTypeSelect = document.getElementById('unitTypeSelect');
    const newItemFields = document.getElementById('newItemFields');
    const newItemName = document.getElementById('newItemName');
    const newUnitTypeName = document.getElementById('newUnitTypeName');
    const itemImageInput = document.getElementById('itemImageInput');
    const itemImagePreviewWrap = document.getElementById('itemImagePreviewWrap');
    const itemImagePreview = document.getElementById('itemImagePreview');
    const branchInlineSkeleton = document.getElementById('branchInlineSkeleton');
    const itemInlineSkeleton = document.getElementById('itemInlineSkeleton');
    const unitInlineSkeleton = document.getElementById('unitInlineSkeleton');
    const stocksTableLoadingWrap = document.getElementById('stocksTableLoadingWrap');

    function setSelectLoading(select, skeleton, isLoading, loadingText) {
        if (!select) return;
        if (isLoading) {
            select.classList.add('select-loading');
            select.disabled = true;
            select.innerHTML = `<option value="">${loadingText || 'Loading...'}</option>`;
            if (skeleton) skeleton.classList.add('active');
        } else {
            select.classList.remove('select-loading');
            if (skeleton) skeleton.classList.remove('active');
        }
    }

    function showTableSkeleton(milliseconds = 350) {
        if (!stocksTableLoadingWrap) return;
        stocksTableLoadingWrap.classList.add('loading');
        setTimeout(() => stocksTableLoadingWrap.classList.remove('loading'), milliseconds);
    }

    document.querySelectorAll('input[type="number"], .numbers-only').forEach(input => {
        input.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9.]/g, '');
            const parts = this.value.split('.');
            if (parts.length > 2) {
                this.value = parts[0] + '.' + parts.slice(1).join('');
            }
        });

        input.addEventListener('keydown', function (e) {
            if (['e', 'E', '+', '-'].includes(e.key)) {
                e.preventDefault();
            }
        });
    });


    if (itemImageInput) {
        itemImageInput.addEventListener('change', function () {
            const file = this.files && this.files[0] ? this.files[0] : null;
            if (!file) {
                if (itemImagePreviewWrap) itemImagePreviewWrap.style.display = 'none';
                if (itemImagePreview) itemImagePreview.src = '';
                return;
            }

            if (!file.type.startsWith('image/')) {
                alert('Please select a valid image file.');
                this.value = '';
                if (itemImagePreviewWrap) itemImagePreviewWrap.style.display = 'none';
                if (itemImagePreview) itemImagePreview.src = '';
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                alert('Item image must not exceed 5MB.');
                this.value = '';
                if (itemImagePreviewWrap) itemImagePreviewWrap.style.display = 'none';
                if (itemImagePreview) itemImagePreview.src = '';
                return;
            }

            if (itemImagePreview) itemImagePreview.src = URL.createObjectURL(file);
            if (itemImagePreviewWrap) itemImagePreviewWrap.style.display = 'flex';
        });
    }

    function setOptions(select, placeholder, rows, valueKey, labelBuilder, addNewItemOption = false) {
        select.innerHTML = '';

        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);

        if (addNewItemOption) {
            const addOpt = document.createElement('option');
            addOpt.value = '__new';
            addOpt.textContent = '+ Add New Item';
            select.appendChild(addOpt);
        }

        rows.forEach(row => {
            const opt = document.createElement('option');
            opt.value = row[valueKey];
            opt.textContent = labelBuilder(row);
            select.appendChild(opt);
        });

        select.disabled = rows.length === 0 && !addNewItemOption;
    }

    function resetBranch() { setOptions(branchSelect, 'Select Business Unit first', [], 'branch_id', r => r.branch_name); }
    function resetItem() { setOptions(itemSelect, 'Select Branch first', [], 'item_id', r => r.item_name); }
    function resetUnit() {
        unitTypeSelect.innerHTML = '<option value="0">Select Item first</option>';
        unitTypeSelect.disabled = true;
    }

    function toggleNewItemFields(show) {
        if (!newItemFields) return;
        newItemFields.style.display = show ? 'block' : 'none';
        if (newItemName) newItemName.required = show;
        if (newUnitTypeName) newUnitTypeName.required = show;
        if (show) {
            unitTypeSelect.innerHTML = '<option value="0">Use new Unit Type below</option>';
            unitTypeSelect.disabled = true;
        }
    }

    async function fetchJson(url) {
        const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        return await response.json();
    }

    businessUnitSelect.addEventListener('change', async function(){
        resetBranch(); resetItem(); resetUnit(); toggleNewItemFields(false);
        const bu = this.value;
        if (!bu) return;
        setSelectLoading(branchSelect, branchInlineSkeleton, true, 'Loading branches...');
        try {
            const data = await fetchJson(`encode_stock.php?ajax=branches&business_unit=${encodeURIComponent(bu)}`);
            setSelectLoading(branchSelect, branchInlineSkeleton, false);
            setOptions(branchSelect, 'Select Branch', data.branches || [], 'branch_id', r => `${r.branch_name}${r.branch_code ? ' (' + r.branch_code + ')' : ''}`);
        } catch (e) {
            setSelectLoading(branchSelect, branchInlineSkeleton, false);
            setOptions(branchSelect, 'Failed to load branches', [], 'branch_id', r => r.branch_name);
        }
    });

    branchSelect.addEventListener('change', async function(){
        resetItem(); resetUnit(); toggleNewItemFields(false);
        const bu = businessUnitSelect.value;
        const branchId = this.value;
        if (!bu || !branchId) return;
        setSelectLoading(itemSelect, itemInlineSkeleton, true, 'Loading items...');
        try {
            const data = await fetchJson(`encode_stock.php?ajax=items&business_unit=${encodeURIComponent(bu)}&branch_id=${encodeURIComponent(branchId)}`);
            setSelectLoading(itemSelect, itemInlineSkeleton, false);
            setOptions(itemSelect, 'Select Item', data.items || [], 'item_id', r => `${r.item_code} - ${r.item_name}`, true);
        } catch (e) {
            setSelectLoading(itemSelect, itemInlineSkeleton, false);
            setOptions(itemSelect, 'Failed to load items', [], 'item_id', r => r.item_name);
        }
    });

    itemSelect.addEventListener('change', async function(){
        resetUnit();
        const selectedItem = this.value;
        if (selectedItem === '__new') {
            toggleNewItemFields(true);
            return;
        }
        toggleNewItemFields(false);
        const bu = businessUnitSelect.value;
        const branchId = branchSelect.value;
        const itemId = this.value;
        if (!bu || !branchId || !itemId) return;
        setSelectLoading(unitTypeSelect, unitInlineSkeleton, true, 'Loading units...');
        try {
            const data = await fetchJson(`encode_stock.php?ajax=units&business_unit=${encodeURIComponent(bu)}&branch_id=${encodeURIComponent(branchId)}&item_id=${encodeURIComponent(itemId)}`);
            setSelectLoading(unitTypeSelect, unitInlineSkeleton, false);
            const rows = data.units || [];
            unitTypeSelect.innerHTML = '';
            if (rows.length === 0) {
                unitTypeSelect.innerHTML = '<option value="0">Default Unit</option>';
            } else {
                rows.forEach(row => {
                    const opt = document.createElement('option');
                    opt.value = row.unit_type_id || 0;
                    opt.textContent = row.unit_type_name;
                    unitTypeSelect.appendChild(opt);
                });
            }
            unitTypeSelect.disabled = false;
        } catch (e) {
            setSelectLoading(unitTypeSelect, unitInlineSkeleton, false);
            unitTypeSelect.innerHTML = '<option value="0">Default Unit</option>';
            unitTypeSelect.disabled = false;
        }
    });

    const stockGlobalSearch = document.getElementById('stockGlobalSearch');
    const stockBranchFilter = document.getElementById('stockBranchFilter');
    const stockBusinessUnitFilter = document.getElementById('stockBusinessUnitFilter');
    const resetStockFilters = document.getElementById('resetStockFilters');
    const stockRows = Array.from(document.querySelectorAll('#centralWarehouseStocksTable tbody tr.stock-row'));
    const noFilterResult = document.querySelector('#centralWarehouseStocksTable tbody tr.no-filter-result');
    const stockDetailsModalEl = document.getElementById('stockDetailsModal');
    const stockDetailsModal = stockDetailsModalEl ? new bootstrap.Modal(stockDetailsModalEl) : null;

    function normalizeFilterValue(value) {
        return (value || '').toString().trim().toLowerCase();
    }

    function applyStockFilters() {
        showTableSkeleton(180);
        const searchValue = normalizeFilterValue(stockGlobalSearch ? stockGlobalSearch.value : '');
        const branchValue = normalizeFilterValue(stockBranchFilter ? stockBranchFilter.value : '');
        const businessUnitValue = normalizeFilterValue(stockBusinessUnitFilter ? stockBusinessUnitFilter.value : '');
        let visibleCount = 0;

        stockRows.forEach(row => {
            const rowSearch = row.dataset.search || '';
            const rowBranch = row.dataset.branch || '';
            const rowBusinessUnit = row.dataset.businessUnit || '';

            const matchesSearch = !searchValue || rowSearch.includes(searchValue);
            const matchesBranch = !branchValue || rowBranch === branchValue;
            const matchesBusinessUnit = !businessUnitValue || rowBusinessUnit === businessUnitValue;

            const shouldShow = matchesSearch && matchesBranch && matchesBusinessUnit;
            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) visibleCount++;
        });

        if (noFilterResult) {
            noFilterResult.style.display = stockRows.length > 0 && visibleCount === 0 ? 'table-row' : 'none';
        }
    }

    function setStockModalText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value && value.trim() !== '' ? value : '-';
    }

    stockRows.forEach(row => {
        row.addEventListener('click', function () {
            const imageBox = document.getElementById('stockModalImageBox');
            const imageSrc = this.dataset.image || '';

            if (imageBox) {
                if (imageSrc) {
                    imageBox.innerHTML = `<img src="${imageSrc}" alt="Item image" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">`;
                } else {
                    imageBox.innerHTML = '<i class="bi bi-image text-muted fs-3"></i>';
                }
            }

            setStockModalText('stockModalItemName', this.dataset.itemName || '-');
            setStockModalText('stockModalItemCode', this.dataset.itemCode ? `Item Code: ${this.dataset.itemCode}` : 'Item Code: -');
            setStockModalText('stockModalCategory', this.dataset.category || '-');
            setStockModalText('stockModalPrincipal', this.dataset.principal || '-');
            setStockModalText('stockModalBranch', this.dataset.branchName || '-');
            setStockModalText('stockModalBusinessUnit', this.dataset.businessUnitName || '-');
            setStockModalText('stockModalAllocatedStock', this.dataset.allocatedStock || '-');
            setStockModalText('stockModalTotalStock', this.dataset.totalStock || '-');
            setStockModalText('stockModalUnitName', this.dataset.unitName || '-');
            setStockModalText('stockModalAsOfDate', this.dataset.asOfDate || '-');
            setStockModalText('stockModalReceivedBy', this.dataset.receivedBy || '-');
            setStockModalText('stockModalRemarks', this.dataset.remarks || '-');

            if (stockDetailsModal) stockDetailsModal.show();
        });
    });

    if (stockGlobalSearch) stockGlobalSearch.addEventListener('input', applyStockFilters);
    if (stockBranchFilter) stockBranchFilter.addEventListener('change', applyStockFilters);
    if (stockBusinessUnitFilter) stockBusinessUnitFilter.addEventListener('change', applyStockFilters);
    if (resetStockFilters) {
        resetStockFilters.addEventListener('click', function () {
            if (stockGlobalSearch) stockGlobalSearch.value = '';
            if (stockBranchFilter) stockBranchFilter.value = '';
            if (stockBusinessUnitFilter) stockBusinessUnitFilter.value = '';
            applyStockFilters();
        });
    }

    const encodeStockForm = document.getElementById('encodeStockForm');
    if (encodeStockForm) {
        encodeStockForm.addEventListener('submit', function () {
            const submitBtn = this.querySelector('button[type="submit"], button.btn-success');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Saving...';
            }

            const overlay = document.getElementById('pageSkeletonOverlay');
            if (overlay) {
                overlay.style.display = 'flex';
                overlay.classList.remove('hide');
                document.body.classList.add('page-loading');
            }
        });
    }

    applyStockFilters();
});
</script>
<?php pageFooter(); ?>
