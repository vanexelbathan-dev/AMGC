<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) { die('Database connection failed: ' . mysqli_connect_error()); }

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tableExists(mysqli $conn, string $table): bool { $table = $conn->real_escape_string($table); $res = $conn->query("SHOW TABLES LIKE '{$table}'"); return $res && $res->num_rows > 0; }
function columnExists(mysqli $conn, string $table, string $column): bool { $table = $conn->real_escape_string($table); $column = $conn->real_escape_string($column); $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"); return $res && $res->num_rows > 0; }
function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void { if (tableExists($conn, $table) && !columnExists($conn, $table, $column)) { @$conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}"); } }

function indexExists(mysqli $conn, string $table, string $index): bool { $table = $conn->real_escape_string($table); $index = $conn->real_escape_string($index); $res = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'"); return $res && $res->num_rows > 0; }
function dropIndexIfExists(mysqli $conn, string $table, string $index): void { if (tableExists($conn, $table) && indexExists($conn, $table, $index)) { @$conn->query("ALTER TABLE `{$table}` DROP INDEX `{$index}`"); } }

addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'authorized_recipient', "authorized_recipient VARCHAR(150) DEFAULT NULL AFTER requested_by");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'withdrawn_by', "withdrawn_by VARCHAR(150) DEFAULT NULL AFTER authorized_recipient");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'atw_group_note', "atw_group_note VARCHAR(80) DEFAULT NULL AFTER withdrawn_by");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'to_be_returned', "to_be_returned TINYINT(1) NOT NULL DEFAULT 0 AFTER atw_group_note");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'return_date', "return_date DATE DEFAULT NULL AFTER to_be_returned");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'return_status', "return_status ENUM('not_required','pending_return','returned','overdue') NOT NULL DEFAULT 'not_required' AFTER return_date");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'returned_at', "returned_at DATETIME DEFAULT NULL AFTER return_status");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'returned_by', "returned_by VARCHAR(150) DEFAULT NULL AFTER returned_at");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'return_remarks', "return_remarks TEXT DEFAULT NULL AFTER returned_by");
dropIndexIfExists($conn, 'central_warehouse_atw_requests', 'uk_atw_request_no');

function getBranchInfo(mysqli $conn, int $branch_id): array {
    $info = ['branch_name' => '', 'business_unit' => ''];
    if ($branch_id <= 0) return $info;
    $stmt = $conn->prepare("SELECT branch_name, business_unit FROM branches WHERE branch_id = ? LIMIT 1");
    if (!$stmt) return $info;
    $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) { $info['branch_name'] = $row['branch_name'] ?? ''; $info['business_unit'] = $row['business_unit'] ?? ''; }
    $stmt->close();
    return $info;
}

function fetchCentralWarehouseItems(mysqli $conn, int $branch_id, string $business_unit, bool $view_all_branches): array {
    if (!tableExists($conn, 'central_warehouse_stocks')) return [];
    $sql = "SELECT cws.central_stock_id, cws.business_unit, cws.branch_id, cws.item_id, cws.unit_type_id, cws.current_stock AS quantity_on_hand,
                   i.item_code, COALESCE(i.barcode, '') AS barcode, i.item_name, COALESCE(i.description, '') AS description,
                   COALESCE(i.category, 'Uncategorized') AS category, COALESCE(i.principal, 'No Principal') AS principal,
                   COALESCE(i.product_image_url, '') AS product_image_url, COALESCE(i.reorder_level, 0) AS reorder_level,
                   COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_type, COALESCE(i.unit_price, 0) AS unit_price, b.branch_name
            FROM central_warehouse_stocks cws
            INNER JOIN items i ON i.item_id = cws.item_id
            LEFT JOIN unit_types ut ON ut.unit_type_id = cws.unit_type_id
            LEFT JOIN branches b ON b.branch_id = cws.branch_id
            WHERE cws.status = 'active' AND i.status = 'active' AND cws.current_stock > 0";
    $types = ''; $params = [];
    if (!$view_all_branches) {
        $sql .= " AND cws.branch_id = ?"; $types .= 'i'; $params[] = $branch_id;
        if (trim($business_unit) !== '') { $sql .= " AND cws.business_unit = ?"; $types .= 's'; $params[] = $business_unit; }
    }
    $sql .= " ORDER BY i.item_name ASC";
    $stmt = $conn->prepare($sql); if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute(); $res = $stmt->get_result(); $items = [];
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close();
    return $items;
}

$branch_info = getBranchInfo($conn, $branch_id);
$branch_name = $branch_info['branch_name'];
$branch_business_unit = $branch_info['business_unit'];
$central_stock_table_ready = tableExists($conn, 'central_warehouse_stocks');
$central_atw_table_ready = tableExists($conn, 'central_warehouse_atw_requests');

function generateAtwRequestNo(mysqli $conn, int $user_id): string {
    $base = 'ATW-' . date('YmdHis') . '-' . $user_id;
    if (!tableExists($conn, 'central_warehouse_atw_requests')) return $base;
    $request_no = $base;
    $try = 0;
    while ($try < 20) {
        $stmt = $conn->prepare("SELECT request_id FROM central_warehouse_atw_requests WHERE request_no = ? LIMIT 1");
        if (!$stmt) return $request_no;
        $stmt->bind_param('s', $request_no);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) return $request_no;
        $try++;
        $request_no = $base . '-' . $try;
    }
    return $base . '-' . bin2hex(random_bytes(2));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_multi_atw') {
    header('Content-Type: application/json');
    if (!$central_stock_table_ready || !$central_atw_table_ready) { echo json_encode(['success'=>false,'message'=>'Missing central warehouse database tables.']); exit; }

    $cart_json = $_POST['cart'] ?? '[]';
    $cart = json_decode($cart_json, true);
    $authorized_recipient = trim($_POST['authorized_recipient'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $to_be_returned = isset($_POST['to_be_returned']) && (string)$_POST['to_be_returned'] === '1' ? 1 : 0;
    $return_date = trim($_POST['return_date'] ?? '');
    $request_date = date('Y-m-d');

    if (!is_array($cart) || empty($cart)) { echo json_encode(['success'=>false,'message'=>'Please add at least one item to the ATW cart.']); exit; }
    if ($authorized_recipient === '') { echo json_encode(['success'=>false,'message'=>'Please enter the authorized recipient.']); exit; }
    if ($purpose === '') { echo json_encode(['success'=>false,'message'=>'Please enter the purpose.']); exit; }
    if ($to_be_returned === 1 && $return_date === '') { echo json_encode(['success'=>false,'message'=>'Please select the return date.']); exit; }
    if ($to_be_returned === 1 && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_date)) { echo json_encode(['success'=>false,'message'=>'Invalid return date format.']); exit; }
    if ($to_be_returned === 0) { $return_date = null; }
    $return_status = $to_be_returned === 1 ? 'pending_return' : 'not_required';

    $request_no = generateAtwRequestNo($conn, $user_id);
    $status = 'pending';
    $group_note = 'MULTI_ITEM_ATW';

    $conn->begin_transaction();
    try {
        $stock_stmt = $conn->prepare("SELECT central_stock_id, business_unit, branch_id, item_id, unit_type_id, current_stock FROM central_warehouse_stocks WHERE central_stock_id = ? AND status = 'active' LIMIT 1");
        $insert = $conn->prepare("INSERT INTO central_warehouse_atw_requests
            (request_no, central_stock_id, business_unit, branch_id, item_id, unit_type_id, requested_qty, requested_by, authorized_recipient, atw_group_note, to_be_returned, return_date, return_status, requested_by_user_id, request_date, purpose, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        if (!$stock_stmt || !$insert) throw new Exception('Failed to prepare ATW request save.');

        $saved = 0;
        foreach ($cart as $line) {
            $central_stock_id = (int)($line['central_stock_id'] ?? 0);
            $qty = (float)($line['qty'] ?? 0);
            if ($central_stock_id <= 0 || $qty <= 0) throw new Exception('Invalid item or quantity found in cart.');

            $stock_stmt->bind_param('i', $central_stock_id);
            $stock_stmt->execute();
            $stock = $stock_stmt->get_result()->fetch_assoc();
            if (!$stock) throw new Exception('One of the selected stock records was not found.');

            if (!$view_all_branches && ((int)$stock['branch_id'] !== $branch_id || (trim($branch_business_unit) !== '' && $stock['business_unit'] !== $branch_business_unit))) {
                throw new Exception('One of the selected items is not assigned to your branch/business unit.');
            }
            if ($qty > (float)$stock['current_stock']) throw new Exception('Requested quantity cannot exceed available stock.');

            $central_stock_id_db = (int)$stock['central_stock_id'];
            $business_unit_db = (string)$stock['business_unit'];
            $branch_id_db = (int)$stock['branch_id'];
            $item_id_db = (int)$stock['item_id'];
            $unit_type_id_db = $stock['unit_type_id'] === null ? 0 : (int)$stock['unit_type_id'];

            $insert->bind_param('sisiiidsssississs', $request_no, $central_stock_id_db, $business_unit_db, $branch_id_db, $item_id_db, $unit_type_id_db, $qty, $user_name, $authorized_recipient, $group_note, $to_be_returned, $return_date, $return_status, $user_id, $request_date, $purpose, $status);
            if (!$insert->execute()) throw new Exception('Failed to save one of the ATW request items.');
            $saved++;
        }
        $conn->commit();
        echo json_encode(['success'=>true,'message'=>'ATW request submitted successfully. Waiting for warehouse release.','request_no'=>$request_no,'item_count'=>$saved]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) { if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1)); }
if ($user_initials === '') $user_initials = 'BA';

$items = fetchCentralWarehouseItems($conn, $branch_id, $branch_business_unit, (bool)$view_all_branches);
$preselected_stock_id = (int)($_GET['stock_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Authority to Withdraw - Branch Admin</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<style>
/* Page cards */
.form-card,
.cart-card {
    background: #fff;
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
}

.atw-layout {
    align-items: flex-start;
}

/* Items area */
.items-panel {
    min-height: 0;
}

.items-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.search-box {
    width: min(360px, 100%);
}

.table-note {
    font-size: .86rem;
    color: #6c757d;
}

.items-scroll {
    max-height: calc(100vh - 245px);
    overflow: auto;
    border: 1px solid #e9ecef;
    border-radius: 12px;
}

.items-scroll .table {
    margin-bottom: 0;
}

.items-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 5;
}

.custom-table th {
    background: #052A47;
    color: #fff;
    white-space: nowrap;
}

.custom-table td {
    vertical-align: middle;
}

.item-thumbnail {
    width: 46px;
    height: 46px;
    border-radius: 8px;
    background: #f1f3f5;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.item-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.btn-action-text {
    white-space: nowrap;
    border-radius: 8px;
}

/* ATW Cart */
.cart-sticky-wrap {
    position: sticky;
    top: 16px;
}

.cart-card {
    max-height: calc(100vh - 120px);
    display: flex;
    flex-direction: column;
}

.cart-header,
.cart-footer {
    flex: 0 0 auto;
}

.cart-body {
    flex: 1 1 auto;
    overflow-y: auto;
    min-height: 120px;
    max-height: 330px;
    padding-right: 4px;
}

.cart-footer {
    border-top: 1px solid #e9ecef;
    padding-top: 14px;
    background: #fff;
}

.cart-line {
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 10px;
    background: #fff;
}

.cart-line-title {
    font-weight: 700;
    color: #052A47;
    line-height: 1.25;
}

.qty-input {
    max-width: 110px;
}

.summary-box {
    background: #f8fafc;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 12px;
}

.form-check-input:checked {
    background-color: #07d826;
    border-color: #07d826;
}

/* Mobile helper */
.cart-mobile-bar {
    display: none;
}

/* Sidebar support */
.sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .35);
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

.items-scroll::-webkit-scrollbar,
.cart-body::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.items-scroll::-webkit-scrollbar-thumb,
.cart-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 99px;
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

    .items-scroll {
        max-height: none;
        overflow: visible;
        border: 0;
    }

    .items-scroll thead th {
        position: static;
    }

    .cart-sticky-wrap {
        position: static;
    }

    .cart-card {
        max-height: none;
    }

    .cart-body {
        max-height: none;
        overflow: visible;
    }

    .cart-mobile-bar {
        display: flex;
        position: sticky;
        top: 0;
        z-index: 20;
        background: #052A47;
        color: #fff;
        border-radius: 12px;
        padding: 10px 12px;
        margin-bottom: 12px;
        align-items: center;
        justify-content: space-between;
    }

    .cart-mobile-bar .btn {
        border-color: #fff;
        color: #fff;
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
                                    <li class="nav-item"><a class="nav-link active" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
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
                <!-- Users -->
                <li class="nav-item">
                    <a class="nav-link" href="drivers.php">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Users</span>
                    </a>
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
                <a class="nav-link" href="motorpool.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">Motorpool</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="central_warehouse.php">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-text">Central Warehouse</span>
                </a>
            </li>
        </ul>
    </div>
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
        <h2>Authority to Withdraw</h2>
        <p id="dashboardSubtitle">Create ATW Request / Multiple Items</p>
      </div>
    </div>

    <?php if (!$central_stock_table_ready || !$central_atw_table_ready): ?>
      <div class="alert alert-warning">Central Warehouse tables are not yet created. Please run the SQL patch first before using this page.</div>
    <?php endif; ?>

    <div class="row g-3 atw-layout">
      <div class="col-lg-8 items-panel">
        <div class="cart-mobile-bar">
          <span><i class="bi bi-cart-check me-1"></i>ATW Cart: <strong id="mobileCartCount">0</strong> item(s)</span>
          <button type="button" class="btn btn-sm" onclick="scrollToAtwCart()">View Cart</button>
        </div>
        <div class="form-card">
          <div class="items-toolbar">
            <div>
              <h5 class="mb-1" style="color:#052A47;font-weight:700"><i class="bi bi-box-seam me-2"></i>Available Central Warehouse Items</h5>
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
              <input type="text" class="form-control form-control-sm search-box" id="itemSearch" placeholder="Search item, code, category, principal..." oninput="filterItemsTable()">
            </div>
          </div>
          <div class="table-responsive items-scroll">
            <table class="table custom-table compact-table align-middle" id="itemsTable">
              <thead><tr><th>Image</th><th>Item Name</th><th>Category</th><th>Principal</th><th>Available Stock</th><th class="text-end">Action</th></tr></thead>
              <tbody>
              <?php if (empty($items)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No central warehouse items assigned to your branch/business unit.</td></tr>
              <?php else: foreach ($items as $item): ?>
                <tr data-stock-id="<?= h($item['central_stock_id']) ?>" data-code="<?= h($item['item_code']) ?>" data-name="<?= h($item['item_name']) ?>" data-category="<?= h($item['category']) ?>" data-principal="<?= h($item['principal']) ?>" data-stock="<?= h(number_format((float)$item['quantity_on_hand'], 2)) ?>" data-stock-raw="<?= h((float)$item['quantity_on_hand']) ?>" data-unit="<?= h($item['unit_type']) ?>">
                  <td><div class="item-thumbnail"><?php if (!empty($item['product_image_url'])): ?><img src="../uploads/products/<?= h($item['product_image_url']) ?>" alt="<?= h($item['item_name']) ?>"><?php else: ?><i class="bi bi-image text-muted"></i><?php endif; ?></div></td>
                  <td><strong><?= h($item['item_name']) ?></strong><br><small class="text-muted"><?= h($item['item_code']) ?></small></td>
                  <td><?= h($item['category']) ?></td>
                  <td><?= h($item['principal']) ?></td>
                  <td><span class="fw-semibold"><?= h(number_format((float)$item['quantity_on_hand'], 2)) ?> <?= h($item['unit_type']) ?></span></td>
                  <td class="text-end"><button type="button" class="btn btn-success btn-sm btn-action-text" onclick="addToCartFromRow(this)"><i class="bi bi-cart-plus me-1"></i>Add to ATW</button></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4" id="atwCartSection">
        <div class="cart-sticky-wrap">
        <div class="cart-card">
          <div class="cart-header">
            <h5 class="mb-3" style="color:#052A47;font-weight:700"><i class="bi bi-cart-check me-2"></i>ATW Cart</h5>
            <div class="summary-box mb-3">
              <div class="d-flex justify-content-between"><span>Total Items</span><strong id="cartCount">0</strong></div>
            </div>
          </div>
          <div id="cartList" class="cart-body mb-3"><div class="text-center text-muted py-4">No item added yet.</div></div>
          <div class="cart-footer">
            <div class="mb-3"><label class="form-label">Authorized Recipient</label><input type="text" class="form-control" id="authorizedRecipient" placeholder="Enter authorized recipient"></div>
            <div class="mb-3"><label class="form-label">Purpose</label><textarea class="form-control" id="purpose" rows="3" placeholder="Enter purpose of withdrawal"></textarea></div>
            <div class="mb-3">
              <div class="form-check form-switch d-flex align-items-center gap-2">
                <input class="form-check-input" type="checkbox" role="switch" id="toBeReturnedSwitch" onchange="toggleReturnDate()">
                <label class="form-check-label fw-semibold" for="toBeReturnedSwitch">To be returned</label>
              </div>
            </div>
            <div class="mb-3" id="returnDateWrap" style="display:none;">
              <label class="form-label">Return Date</label>
              <input type="date" class="form-control" id="returnDate" disabled>
            </div>
            <button type="button" class="btn btn-success w-100" onclick="submitAtwCart()"><i class="bi bi-send me-1"></i>Submit ATW Request</button>
          </div>
        </div>
        </div>
      </div>
    </div>
  </div>
</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const preselectedStockId = <?= (int)$preselected_stock_id ?>;
let atwCart = [];

function moneyQty(value) {
    const num = parseFloat(value || 0);
    return Number.isFinite(num) ? num.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
}

function addToCartFromRow(btn) {
    const tr = btn.closest('tr');
    if (!tr) return;
    const stockId = tr.dataset.stockId;
    const existing = atwCart.find(item => item.central_stock_id === stockId);
    if (existing) {
        Swal.fire('Already added', 'This item is already in the ATW cart. You can update its quantity in the cart.', 'info');
        return;
    }
    atwCart.push({
        central_stock_id: stockId,
        code: tr.dataset.code || '',
        name: tr.dataset.name || '',
        unit: tr.dataset.unit || '',
        available: parseFloat(tr.dataset.stockRaw || '0'),
        qty: 1
    });
    renderCart();
}

function updateCartQty(stockId, value) {
    const item = atwCart.find(i => i.central_stock_id === stockId);
    if (!item) return;
    let qty = parseFloat(value || '0');
    if (!Number.isFinite(qty) || qty < 1) qty = 1;
    if (qty > item.available) qty = item.available;
    item.qty = qty;
    renderCart();
}

function removeCartItem(stockId) {
    atwCart = atwCart.filter(i => i.central_stock_id !== stockId);
    renderCart();
}

function renderCart() {
    const list = document.getElementById('cartList');
    document.getElementById('cartCount').textContent = atwCart.length;
    const mobileCount = document.getElementById('mobileCartCount');
    if (mobileCount) mobileCount.textContent = atwCart.length;
    if (!atwCart.length) {
        list.innerHTML = '<div class="text-center text-muted py-4">No item added yet.</div>';
        return;
    }
    list.innerHTML = atwCart.map(item => `
        <div class="cart-line">
            <div class="d-flex justify-content-between gap-2">
                <div>
                    <div class="cart-line-title">${escapeHtml(item.name)}</div>
                    <small class="text-muted">${escapeHtml(item.code)} • Available: ${moneyQty(item.available)} ${escapeHtml(item.unit)}</small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeCartItem('${item.central_stock_id}')"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="mt-2 d-flex align-items-center gap-2">
                <label class="small text-muted mb-0">Qty</label>
                <input type="number" min="1" max="${item.available}" step="1" class="form-control form-control-sm qty-input" value="${item.qty}" onchange="updateCartQty('${item.central_stock_id}', this.value)">
                <span class="small">${escapeHtml(item.unit)}</span>
            </div>
        </div>
    `).join('');
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

function scrollToAtwCart() {
    const cart = document.getElementById('atwCartSection');
    if (cart) cart.scrollIntoView({behavior: 'smooth', block: 'start'});
}

function filterItemsTable() {
    const input = document.getElementById('itemSearch');
    const query = (input ? input.value : '').toLowerCase().trim();
    document.querySelectorAll('#itemsTable tbody tr[data-stock-id]').forEach(row => {
        const text = [row.dataset.code, row.dataset.name, row.dataset.category, row.dataset.principal].join(' ').toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
}

function toggleReturnDate() {
    const toggle = document.getElementById('toBeReturnedSwitch');
    const wrap = document.getElementById('returnDateWrap');
    const input = document.getElementById('returnDate');
    const enabled = !!(toggle && toggle.checked);
    if (wrap) wrap.style.display = enabled ? '' : 'none';
    if (input) {
        input.disabled = !enabled;
        if (!enabled) input.value = '';
    }
}

function submitAtwCart() {
    const authorizedRecipient = document.getElementById('authorizedRecipient').value.trim();
    const purpose = document.getElementById('purpose').value.trim();
    const toBeReturned = document.getElementById('toBeReturnedSwitch')?.checked ? 1 : 0;
    const returnDate = document.getElementById('returnDate')?.value || '';
    if (!atwCart.length) { Swal.fire('No item', 'Please add at least one item to the ATW cart.', 'warning'); return; }
    if (!authorizedRecipient) { Swal.fire('Missing recipient', 'Please enter the authorized recipient.', 'warning'); return; }
    if (!purpose) { Swal.fire('Missing purpose', 'Please enter the purpose.', 'warning'); return; }
    if (toBeReturned && !returnDate) { Swal.fire('Missing return date', 'Please select the return date.', 'warning'); return; }

    const invalid = atwCart.find(item => !item.qty || item.qty <= 0 || item.qty > item.available);
    if (invalid) { Swal.fire('Invalid quantity', 'Please check the requested quantity of each item.', 'warning'); return; }

    const formData = new FormData();
    formData.append('action', 'submit_multi_atw');
    formData.append('cart', JSON.stringify(atwCart.map(item => ({central_stock_id: item.central_stock_id, qty: item.qty}))));
    formData.append('authorized_recipient', authorizedRecipient);
    formData.append('purpose', purpose);
    formData.append('to_be_returned', String(toBeReturned));
    formData.append('return_date', returnDate);

    fetch(window.location.href, {method:'POST', body:formData})
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Submitted', `${data.message}<br><strong>${data.request_no}</strong>`, 'success').then(() => window.location.href = 'central_warehouse.php');
            } else {
                Swal.fire('Not saved', data.message || 'Failed to submit ATW request.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Something went wrong while submitting the request.', 'error'));
}

document.addEventListener('DOMContentLoaded', function() {
    if (preselectedStockId > 0) {
        const row = document.querySelector(`tr[data-stock-id="${preselectedStockId}"]`);
        if (row) {
            const btn = row.querySelector('button');
            if (btn) addToCartFromRow(btn);
        }
    }
});

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

    const targetMenu = menuMap[currentFile] || 'sharedServicesMenu';
    openOnlySidebarMenu(targetMenu);

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

function initSidebarHoverBehavior() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar || window.innerWidth <= 992) return;

    sidebar.addEventListener('mouseleave', function() {
        if (!isSidebarPinned && sidebar.classList.contains('collapsed')) {
            sidebar.style.width = '';
        }
    });

    sidebar.addEventListener('click', function(e) {
        const navLink = e.target.closest('.nav-link');
        if (navLink && sidebar.classList.contains('collapsed')) {
            isSidebarPinned = true;
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');

            const mainContent = document.getElementById('mainContent');
            if (mainContent) mainContent.classList.remove('expanded');
        }
    });
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

// Initialize sidebar when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initSidebarButtons();
    initSidebarState();
    initSidebarHoverBehavior();
});
</script>
</body>
</html>
