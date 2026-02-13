<?php
require_once '../config/database.php';

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // CREATE PURCHASE ORDER
        if ($_POST['action'] === 'create_po') {
            $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
            $supplier_name = $_POST['supplier_name'];
            $order_date = $_POST['order_date'];
            $expected_delivery = $_POST['expected_delivery'] ?? null;
            $total_amount = (float)$_POST['total_amount'];
            $po_status = $_POST['po_status'] ?? 'draft';
            
            $insert_query = "INSERT INTO purchase_orders (po_number, supplier_name, order_date, expected_delivery, total_amount, po_status, created_at, updated_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssssds", $po_number, $supplier_name, $order_date, $expected_delivery, $total_amount, $po_status);
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to create purchase order');
            }
            
            $po_id = $conn->insert_id;
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'po_id' => $po_id,
                'po_number' => $po_number
            ]);
            exit;
        }
        
        // UPDATE PURCHASE ORDER
        elseif ($_POST['action'] === 'update_po') {
            $po_id = (int)$_POST['po_id'];
            $supplier_name = $_POST['supplier_name'];
            $order_date = $_POST['order_date'];
            $expected_delivery = $_POST['expected_delivery'] ?? null;
            $total_amount = (float)$_POST['total_amount'];
            $po_status = $_POST['po_status'];
            
            $update_query = "UPDATE purchase_orders 
                           SET supplier_name = ?, order_date = ?, expected_delivery = ?, total_amount = ?, po_status = ?, updated_at = NOW() 
                           WHERE po_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sssdsi", $supplier_name, $order_date, $expected_delivery, $total_amount, $po_status, $po_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update purchase order');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order updated successfully'
            ]);
            exit;
        }
        
        // DELETE PURCHASE ORDER
        elseif ($_POST['action'] === 'delete_po') {
            $po_id = (int)$_POST['po_id'];
            
            // Delete order items first
            $delete_items_query = "DELETE FROM purchase_order_items WHERE po_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_query);
            $delete_items_stmt->bind_param("i", $po_id);
            $delete_items_stmt->execute();
            
            // Delete the order
            $delete_order_query = "DELETE FROM purchase_orders WHERE po_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("i", $po_id);
            
            if (!$delete_order_stmt->execute()) {
                throw new Exception('Failed to delete purchase order');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order deleted successfully'
            ]);
            exit;
        }
        
        // GET PURCHASE ORDER DETAILS
        elseif ($_POST['action'] === 'get_po') {
            $po_id = (int)$_POST['po_id'];
            
            $query = "
                SELECT 
                    po.*,
                    COUNT(poi.po_item_id) as total_items,
                    SUM(poi.quantity_ordered) as total_quantity
                FROM purchase_orders po
                LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
                WHERE po.po_id = ?
                GROUP BY po.po_id
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $po_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $po = $result->fetch_assoc();
            
            if ($po) {
                // Get PO items
                $items_query = "
                    SELECT 
                        poi.*,
                        i.item_code,
                        i.item_name,
                        i.unit_type
                    FROM purchase_order_items poi
                    JOIN items i ON poi.item_id = i.item_id
                    WHERE poi.po_id = ?
                ";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $po_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'po' => $po,
                    'items' => $items
                ]);
            } else {
                throw new Exception('Purchase order not found');
            }
            exit;
        }
        
        // ADD PO ITEM
        elseif ($_POST['action'] === 'add_po_item') {
            $po_id = (int)$_POST['po_id'];
            $item_id = (int)$_POST['item_id'];
            $quantity_ordered = (int)$_POST['quantity_ordered'];
            $unit_price = (float)$_POST['unit_price'];
            
            $insert_query = "INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_price, created_at) 
                           VALUES (?, ?, ?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("iiid", $po_id, $item_id, $quantity_ordered, $unit_price);
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to add item to purchase order');
            }
            
            // Update PO total amount
            $update_total_query = "UPDATE purchase_orders 
                                  SET total_amount = (SELECT SUM(quantity_ordered * unit_price) FROM purchase_order_items WHERE po_id = ?)
                                  WHERE po_id = ?";
            $update_total_stmt = $conn->prepare($update_total_query);
            $update_total_stmt->bind_param("ii", $po_id, $po_id);
            $update_total_stmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item added successfully'
            ]);
            exit;
        }
        
        // DELETE PO ITEM
        elseif ($_POST['action'] === 'delete_po_item') {
            $po_item_id = (int)$_POST['po_item_id'];
            $po_id = (int)$_POST['po_id'];
            
            $delete_query = "DELETE FROM purchase_order_items WHERE po_item_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("i", $po_item_id);
            
            if (!$delete_stmt->execute()) {
                throw new Exception('Failed to delete item');
            }
            
            // Update PO total amount
            $update_total_query = "UPDATE purchase_orders 
                                  SET total_amount = (SELECT IFNULL(SUM(quantity_ordered * unit_price), 0) FROM purchase_order_items WHERE po_id = ?)
                                  WHERE po_id = ?";
            $update_total_stmt = $conn->prepare($update_total_query);
            $update_total_stmt->bind_param("ii", $po_id, $po_id);
            $update_total_stmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item deleted successfully'
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH PURCHASE ORDERS FROM DATABASE
$po_query = "
    SELECT 
        po.po_id,
        po.po_number,
        po.order_date,
        po.expected_delivery,
        po.total_amount,
        po.po_status,
        po.supplier_name,
        po.created_at,
        po.updated_at,
        COUNT(poi.po_item_id) as total_items,
        IFNULL(SUM(poi.quantity_ordered), 0) as total_quantity
    FROM purchase_orders po
    LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
    GROUP BY po.po_id
    ORDER BY po.created_at DESC, po.po_id DESC
";
$po_result = $conn->query($po_query);
$purchase_orders = $po_result->fetch_all(MYSQLI_ASSOC);

// FETCH ALL ITEMS FOR DROPDOWN
$items_query = "SELECT item_id, item_code, item_name, unit_price, unit_type, stock FROM items WHERE status = 'active' ORDER BY item_name";
$items_result = $conn->query($items_query);
$items_list = $items_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA
$total_po = count($purchase_orders);
$draft_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'draft'));
$submitted_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'submitted'));
$approved_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'approved'));
$received_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'received'));
$cancelled_po = count(array_filter($purchase_orders, fn($po) => $po['po_status'] === 'cancelled'));

// STAT CARD VALUES
$statTotalPO = $total_po;
$statProcessingPO = $submitted_po + $approved_po;
$statDeliveredPO = $received_po;
$statReturnedPO = 0;

// Get unique suppliers for filter
$suppliers_query = "SELECT DISTINCT supplier_name FROM purchase_orders WHERE supplier_name IS NOT NULL AND supplier_name != '' ORDER BY supplier_name";
$suppliers_result = $conn->query($suppliers_query);
$suppliers = $suppliers_result->fetch_all(MYSQLI_ASSOC);

// Helper function for PO status badge
function getPOStatusClass($status) {
    return match($status) {
        'draft' => 'status-draft',
        'submitted' => 'status-processing',
        'approved' => 'status-approved',
        'received' => 'status-delivered',
        'cancelled' => 'status-cancelled',
        default => 'status-draft'
    };
}

function getPOStatusText($status) {
    return match($status) {
        'draft' => 'Draft',
        'submitted' => 'Processing',
        'approved' => 'Approved',
        'received' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatDateTime($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/purchase_order.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Main layout */
        .main-content {
            padding: 20px 30px;
            transition: margin-left 0.3s ease;
        }
        
        /* Filter controls layout */
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding: 16px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .filter-select {
            width: 160px;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background-color: white;
            height: 40px;
        }
        
        .filter-search {
            position: relative;
            flex: 0 0 240px;
        }
        
        .filter-search i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 15px;
            z-index: 10;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .filter-search input {
            width: 100%;
            padding: 8px 12px 8px 38px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            height: 40px;
            font-size: 14px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }
        
        .filter-buttons .btn {
            height: 40px;
            padding: 8px 16px;
            font-size: 14px;
        }
        
        /* Table wrapper - adds margins on both sides */
        .table-wrapper {
            margin: 0 0 30px 0;
            width: 100%;
        }
        
        /* Table container */
        .table-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }
        
        /* Table styling */
        .po-table {
            margin-bottom: 0;
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        /* Column width definitions - CHECKBOX COLUMN REMOVED */
        .col-po { width: 11%; }
        .col-supplier { width: 13%; }
        .col-date { width: 10%; }
        .col-items { width: 7%; }
        .col-qty { width: 8%; }
        .col-amount { width: 12%; }
        .col-status { width: 10%; }
        .col-expected { width: 12%; }
        .col-actions { width: 12%; }
        
        /* Table header styling */
        .po-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 16px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        /* Table cell styling */
        .po-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Column-specific alignments */
        .col-items,
        .col-qty {
            text-align: center !important;
        }
        
        .col-items th,
        .col-qty th {
            text-align: center !important;
        }
        
        .col-amount {
            text-align: right !important;
        }
        
        .col-amount th {
            text-align: right !important;
            padding-right: 20px !important;
        }
        
        .col-actions {
            text-align: center !important;
        }
        
        .col-actions th {
            text-align: center !important;
        }
        
        /* Hover effect */
        .po-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Status badge styling */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
            white-space: nowrap;
        }
        
        .status-draft {
            background-color: #e9ecef;
            color: #495057;
        }
        
        .status-processing {
            background-color: #cfe2ff;
            color: #084298;
        }
        
        .status-approved {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-delivered {
            background-color: #d1e7dd;
            color: #0a3622;
        }
        
        .status-cancelled {
            background-color: #f8d7da;
            color: #58151c;
        }
        
        /* Action buttons styling */
        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
            align-items: center;
        }
        
        .table-btn {
            background: none;
            border: none;
            padding: 6px;
            border-radius: 4px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
        }
        
        .table-btn:hover {
            background-color: #e9ecef;
        }
        
        .btn-view { color: #0d6efd; }
        .btn-edit { color: #ffc107; }
        .btn-delete { color: #dc3545; }
        
        /* Select2 customization */
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            padding-left: 12px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        /* PO Details styling */
        .po-details-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
        }
        
        /* Text alignment utilities */
        .text-center {
            text-align: center;
        }
        
        .text-end {
            text-align: right;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1600px) {
            .col-po { width: 11%; }
            .col-supplier { width: 13%; }
            .col-amount { width: 12%; }
        }
        
        @media (max-width: 1400px) {
            .filter-select { width: 140px; }
            .filter-search { flex: 0 0 200px; }
            
            .col-po { width: 11%; }
            .col-supplier { width: 12%; }
            .col-amount { width: 12%; }
            .col-expected { width: 11%; }
        }
        
        @media (max-width: 1200px) {
            .po-table { table-layout: auto; }
            .table-container { overflow-x: auto; }
        }
    </style>
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                 <h3>
                 <button class="desktop-toggle-btn" id="desktopToggleBtn">
                    <i class="bi bi-list"></i>
                </button>    
                 <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
            <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar">AD</div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar">Quality Control</span>
                        <span class="user-role-sidebar">QC Officer</span>
                    </div>
                </div>
                
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- PURCHASE ORDERS CONTENT -->
            <div id="dashboardContent" class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Purchase Orders</h2>
                        <p id="dashboardSubtitle">Manage and track all purchase orders</p>
                    </div>
                </div>

                <!-- Stats Section - WITH PROPER ICONS -->
                <div class="stats-row">
                    <div class="stat-card total">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="totalPO"><?= $statTotalPO ?></div>
                            <div class="stat-label">Total POs</div>
                        </div>
                    </div>
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="processingPO"><?= $statProcessingPO ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                    <div class="stat-card processing">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="deliveredPO"><?= $statDeliveredPO ?></div>
                            <div class="stat-label">Delivered</div>
                        </div>
                    </div>
                    <div class="stat-card rejected">
                        <div class="stat-icon">
                            <i class="bi bi-arrow-return-left"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="returnedPO"><?= $statReturnedPO ?></div>
                            <div class="stat-label">Returned</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Controls -->
                <div class="filter-controls">
                    <select class="filter-select" id="filterStatus" onchange="filterTable()">
                        <option value="all">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">Processing</option>
                        <option value="approved">Approved</option>
                        <option value="received">Delivered</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    
                    <select class="filter-select" id="filterSupplier" onchange="filterTable()">
                        <option value="all">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php if (!empty($supplier['supplier_name'])): ?>
                                <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>">
                                    <?= htmlspecialchars($supplier['supplier_name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    
                    <select class="filter-select" id="filterMonth" onchange="filterTable()">
                        <option value="all">All Months</option>
                        <option value="1">January</option>
                        <option value="2">February</option>
                        <option value="3">March</option>
                        <option value="4">April</option>
                        <option value="5">May</option>
                        <option value="6">June</option>
                        <option value="7">July</option>
                        <option value="8">August</option>
                        <option value="9">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                    
                    <div class="filter-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" placeholder="Search PO number, supplier..." onkeyup="filterTable()">
                    </div>
                    
                    <div class="filter-buttons">
                        <button class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                        </button>
                        <button class="btn btn-primary" onclick="showNewPOModal()">
                            <i class="bi bi-plus-circle me-1"></i> New PO
                        </button>
                    </div>
                </div>

                <!-- Table Container - WITHOUT CHECKBOX COLUMN -->
                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table po-table" id="poTable">
                            <thead>
                                <tr>
                                    <th class="col-po">PO NUMBER</th>
                                    <th class="col-supplier">SUPPLIER</th>
                                    <th class="col-date">ORDER DATE</th>
                                    <th class="col-items">ITEMS</th>
                                    <th class="col-qty">QUANTITY</th>
                                    <th class="col-amount">TOTAL AMOUNT</th>
                                    <th class="col-status">STATUS</th>
                                    <th class="col-expected">EXPECTED DELIVERY</th>
                                    <th class="col-actions">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody id="poTableBody">
                                <?php if (empty($purchase_orders)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-3"></i>
                                        <h5>No Purchase Orders Found</h5>
                                        <p class="text-muted mb-0">No purchase orders in the database.</p>
                                        <p class="text-muted">Click "New PO" to create your first purchase order.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($purchase_orders as $po): ?>
                                    <tr class="po-row" 
                                        data-id="<?= $po['po_id'] ?>"
                                        data-po-number="<?= htmlspecialchars($po['po_number']) ?>"
                                        data-supplier="<?= htmlspecialchars($po['supplier_name'] ?? '') ?>"
                                        data-status="<?= $po['po_status'] ?>"
                                        data-date="<?= $po['order_date'] ?>">
                                        <td class="col-po">
                                            <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                        </td>
                                        <td class="col-supplier">
                                            <?= htmlspecialchars($po['supplier_name'] ?? 'N/A') ?>
                                        </td>
                                        <td class="col-date"><?= formatDate($po['order_date']) ?></td>
                                        <td class="col-items"><?= $po['total_items'] ?? 0 ?></td>
                                        <td class="col-qty"><?= number_format($po['total_quantity'] ?? 0) ?></td>
                                        <td class="col-amount">₱<?= number_format($po['total_amount'] ?? 0, 2) ?></td>
                                        <td class="col-status">
                                            <span class="status-badge <?= getPOStatusClass($po['po_status']) ?>">
                                                <?= getPOStatusText($po['po_status']) ?>
                                            </span>
                                        </td>
                                        <td class="col-expected"><?= formatDate($po['expected_delivery']) ?></td>
                                        <td class="col-actions">
                                            <div class="action-buttons">
                                                <button class="table-btn btn-view" onclick="viewPO(<?= $po['po_id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($po['po_status'] !== 'received' && $po['po_status'] !== 'cancelled'): ?>
                                                <button class="table-btn btn-edit" onclick="editPO(<?= $po['po_id'] ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="table-btn btn-delete" onclick="deletePO(<?= $po['po_id'] ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                        <!-- Empty State (hidden if there are items) -->
                        <div class="empty-state" id="emptyState" style="display: none;">
                            <div class="empty-state-icon">
                                <i class="bi bi-inbox"></i>
                            </div>
                            <h4>No Purchase Orders Found</h4>
                            <p class="text-muted mb-4">Try adjusting your filters or create a new purchase order</p>
                            <button class="btn btn-primary" onclick="showNewPOModal()">
                                <i class="bi bi-plus-circle me-1"></i> Create New PO
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- NEW PO MODAL -->
    <div class="modal fade" id="newPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create New Purchase Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="newPOForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="supplierName" class="form-label">Supplier Name *</label>
                                <input type="text" class="form-control" id="supplierName" required>
                            </div>
                            <div class="col-md-6">
                                <label for="poStatus" class="form-label">Status *</label>
                                <select class="form-select" id="poStatus" required>
                                    <option value="draft">Draft</option>
                                    <option value="submitted">Submit for Approval</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="orderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="orderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="expectedDelivery" class="form-label">Expected Delivery</label>
                                <input type="date" class="form-control" id="expectedDelivery">
                            </div>
                            <div class="col-md-6">
                                <label for="poTotalAmount" class="form-label">Total Amount *</label>
                                <input type="number" class="form-control" id="poTotalAmount" min="0" step="0.01" value="0" required>
                            </div>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            You can add items to this purchase order after creation.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createPurchaseOrder()">Create PO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW PO MODAL -->
    <div class="modal fade" id="viewPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Purchase Order Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="poDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" id="editFromViewBtn" onclick="editPOFromView()" style="display: none;">Edit PO</button>
                    <button type="button" class="btn btn-primary" onclick="printPODetails()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT PO MODAL -->
    <div class="modal fade" id="editPOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editPOForm">
                        <input type="hidden" id="editPOId">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editPONumber" class="form-label">PO Number</label>
                                <input type="text" class="form-control" id="editPONumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editPOStatus" class="form-label">Status *</label>
                                <select class="form-select" id="editPOStatus" required>
                                    <option value="draft">Draft</option>
                                    <option value="submitted">Processing</option>
                                    <option value="approved">Approved</option>
                                    <option value="received">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editSupplierName" class="form-label">Supplier Name *</label>
                                <input type="text" class="form-control" id="editSupplierName" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="editOrderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editExpectedDelivery" class="form-label">Expected Delivery</label>
                                <input type="date" class="form-control" id="editExpectedDelivery">
                            </div>
                            <div class="col-md-6">
                                <label for="editTotalAmount" class="form-label">Total Amount (₱) *</label>
                                <input type="number" class="form-control" id="editTotalAmount" min="0" step="0.01" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updatePurchaseOrder()">Update PO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deletePOModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this purchase order?</p>
                    <p class="fw-bold" id="deletePONumber"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone and will remove all associated items.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeletePO()">Delete PO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentPOId = null;
    let itemsList = <?= json_encode($items_list) ?>;
    
    // ========== SIDEBAR FUNCTIONS ==========
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            sidebar.classList.toggle('active');
            if (!document.querySelector('.sidebar-overlay')) {
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', closeMobileSidebar);
                setTimeout(() => overlay.classList.add('active'), 10);
            }
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
            });
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('active');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        }
    }

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
            }
        }
    }

    // ========== SHOW LOADING ==========
    function showLoading() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    // ========== PURCHASE ORDER FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Purchase Orders - Live Database Mode");
        
        initializeSidebar();
        
        // Set default date for new PO modal
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        document.getElementById('orderDate').value = formattedDate;
        
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                }
            } else {
                toggleSidebar();
            }
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) closeMobileSidebar();
            });
        });

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });

        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) overlay.remove();
                sidebar.classList.remove('active');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
            }
        });
    });

    // Filter table function
    function filterTable() {
        const statusFilter = document.getElementById('filterStatus').value;
        const supplierFilter = document.getElementById('filterSupplier').value;
        const monthFilter = document.getElementById('filterMonth').value;
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        
        const rows = document.querySelectorAll('.po-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const poNumber = row.dataset.poNumber?.toLowerCase() || '';
            const supplier = row.dataset.supplier?.toLowerCase() || '';
            const status = row.dataset.status || '';
            const dateStr = row.dataset.date || '';
            
            let matchesStatus = statusFilter === 'all' || status === statusFilter;
            let matchesSupplier = supplierFilter === 'all' || row.dataset.supplier === supplierFilter;
            
            let matchesMonth = true;
            if (monthFilter !== 'all' && dateStr) {
                const poMonth = new Date(dateStr).getMonth() + 1;
                matchesMonth = poMonth === parseInt(monthFilter);
            }
            
            let matchesSearch = searchTerm === '' || 
                poNumber.includes(searchTerm) || 
                supplier.includes(searchTerm);
            
            if (matchesStatus && matchesSupplier && matchesMonth && matchesSearch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show empty state if no rows visible
        const emptyState = document.getElementById('emptyState');
        const tableBody = document.getElementById('poTableBody');
        if (visibleCount === 0) {
            if (emptyState) emptyState.style.display = 'block';
            if (tableBody) tableBody.style.display = 'none';
        } else {
            if (emptyState) emptyState.style.display = 'none';
            if (tableBody) tableBody.style.display = 'table-row-group';
        }
    }

    // Show new PO modal
    function showNewPOModal() {
        // Reset form
        document.getElementById('newPOForm').reset();
        
        // Set default date
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        document.getElementById('orderDate').value = formattedDate;
        document.getElementById('poTotalAmount').value = 0;
        
        new bootstrap.Modal(document.getElementById('newPOModal')).show();
    }

    // Create Purchase Order
    function createPurchaseOrder() {
        const supplierName = document.getElementById('supplierName').value;
        const orderDate = document.getElementById('orderDate').value;
        const expectedDelivery = document.getElementById('expectedDelivery').value;
        const totalAmount = document.getElementById('poTotalAmount').value;
        const poStatus = document.getElementById('poStatus').value;
        
        if (!supplierName) {
            Swal.fire('Warning', 'Supplier Name is required', 'warning');
            return;
        }
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (!totalAmount || totalAmount < 0) {
            Swal.fire('Warning', 'Valid Total Amount is required', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'create_po');
        formData.append('supplier_name', supplierName);
        formData.append('order_date', orderDate);
        formData.append('expected_delivery', expectedDelivery);
        formData.append('total_amount', totalAmount);
        formData.append('po_status', poStatus);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('newPOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while creating purchase order', 'error');
        });
    }

    // View Purchase Order
    function viewPO(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_po');
        formData.append('po_id', id);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const po = data.po;
                const items = data.items || [];
                
                // Format dates
                const orderDate = po.order_date ? new Date(po.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A';
                const expectedDate = po.expected_delivery ? new Date(po.expected_delivery).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A';
                
                // Build items table
                let itemsHtml = '';
                if (items.length > 0) {
                    itemsHtml = '<h6 class="mt-4 mb-3">Order Items</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Item Code</th><th>Item Name</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr></thead><tbody>';
                    items.forEach(item => {
                        const subtotal = item.quantity_ordered * item.unit_price;
                        itemsHtml += `<tr>
                            <td>${item.item_code}</td>
                            <td>${item.item_name}</td>
                            <td class="text-center">${item.quantity_ordered}</td>
                            <td class="text-end">₱${Number(item.unit_price).toFixed(2)}</td>
                            <td class="text-end">₱${Number(subtotal).toFixed(2)}</td>
                        </tr>`;
                    });
                    itemsHtml += '</tbody></table></div>';
                }
                
                const content = document.getElementById('poDetailsContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="po-details-card">
                                <h6 class="fw-bold mb-3">Purchase Order Information</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="detail-label">PO Number:</td>
                                        <td class="detail-value">${po.po_number}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Supplier:</td>
                                        <td>${po.supplier_name || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Order Date:</td>
                                        <td>${orderDate}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Expected Delivery:</td>
                                        <td>${expectedDate}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Status:</td>
                                        <td><span class="status-badge ${getStatusClass(po.po_status)}">${getStatusText(po.po_status)}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="po-details-card">
                                <h6 class="fw-bold mb-3">Order Summary</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="detail-label">Total Items:</td>
                                        <td>${po.total_items || 0}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Total Quantity:</td>
                                        <td>${po.total_quantity || 0}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Total Amount:</td>
                                        <td class="fw-bold fs-5">₱${Number(po.total_amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Created At:</td>
                                        <td>${po.created_at ? new Date(po.created_at).toLocaleString() : 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td class="detail-label">Last Updated:</td>
                                        <td>${po.updated_at ? new Date(po.updated_at).toLocaleString() : 'N/A'}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    ${itemsHtml}
                `;
                
                currentPOId = id;
                
                // Show/hide edit button based on status
                const editBtn = document.getElementById('editFromViewBtn');
                if (po.po_status !== 'received' && po.po_status !== 'cancelled') {
                    editBtn.style.display = 'inline-block';
                } else {
                    editBtn.style.display = 'none';
                }
                
                new bootstrap.Modal(document.getElementById('viewPOModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching purchase order details', 'error');
        });
    }

    // Edit from View Modal
    function editPOFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewPOModal')).hide();
        setTimeout(() => {
            editPO(currentPOId);
        }, 300);
    }

    // Edit Purchase Order
    function editPO(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_po');
        formData.append('po_id', id);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const po = data.po;
                
                // Format dates for input
                const orderDate = po.order_date ? po.order_date.split(' ')[0] : '';
                const expectedDate = po.expected_delivery ? po.expected_delivery.split(' ')[0] : '';
                
                document.getElementById('editPOId').value = po.po_id;
                document.getElementById('editPONumber').value = po.po_number;
                document.getElementById('editSupplierName').value = po.supplier_name || '';
                document.getElementById('editOrderDate').value = orderDate;
                document.getElementById('editExpectedDelivery').value = expectedDate;
                document.getElementById('editTotalAmount').value = po.total_amount || 0;
                document.getElementById('editPOStatus').value = po.po_status;
                
                currentPOId = id;
                new bootstrap.Modal(document.getElementById('editPOModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching purchase order details', 'error');
        });
    }

    // Update Purchase Order
    function updatePurchaseOrder() {
        const poId = document.getElementById('editPOId').value;
        const supplierName = document.getElementById('editSupplierName').value;
        const orderDate = document.getElementById('editOrderDate').value;
        const expectedDelivery = document.getElementById('editExpectedDelivery').value;
        const totalAmount = document.getElementById('editTotalAmount').value;
        const poStatus = document.getElementById('editPOStatus').value;
        
        if (!supplierName) {
            Swal.fire('Warning', 'Supplier Name is required', 'warning');
            return;
        }
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (!totalAmount || totalAmount < 0) {
            Swal.fire('Warning', 'Valid Total Amount is required', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_po');
        formData.append('po_id', poId);
        formData.append('supplier_name', supplierName);
        formData.append('order_date', orderDate);
        formData.append('expected_delivery', expectedDelivery);
        formData.append('total_amount', totalAmount);
        formData.append('po_status', poStatus);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('editPOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while updating purchase order', 'error');
        });
    }

    // Delete Purchase Order
    function deletePO(id) {
        const row = document.querySelector(`.po-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deletePONumber').textContent = row.dataset.poNumber;
        currentPOId = id;
        new bootstrap.Modal(document.getElementById('deletePOModal')).show();
    }

    // Confirm Delete
    function confirmDeletePO() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_po');
        formData.append('po_id', currentPOId);
        
        fetch('purchase_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deletePOModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while deleting purchase order', 'error');
        });
    }

    // Print PO Details
    function printPODetails() {
        const content = document.getElementById('poDetailsContent').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Purchase Order Details</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; }
                        .status-badge { display: inline-block; padding: 5px 12px; font-size: 12px; border-radius: 20px; }
                        .status-draft { background-color: #e9ecef; color: #495057; }
                        .status-processing { background-color: #cfe2ff; color: #084298; }
                        .status-approved { background-color: #cce5ff; color: #004085; }
                        .status-delivered { background-color: #d1e7dd; color: #0a3622; }
                        .status-cancelled { background-color: #f8d7da; color: #58151c; }
                        .po-details-card { background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
                        .detail-label { font-size: 12px; color: #6c757d; }
                        .detail-value { font-size: 16px; font-weight: 600; }
                    </style>
                </head>
                <body>
                    <h2 class="mb-4">Purchase Order Details</h2>
                    ${content}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.po-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No purchase orders to export', 'warning');
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        excelData.push([
            'PO Number',
            'Supplier',
            'Order Date',
            'Items',
            'Quantity',
            'Total Amount (₱)',
            'Status',
            'Expected Delivery'
        ]);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                const poNumber = cells[0]?.innerText || '';
                const supplier = cells[1]?.innerText || '';
                const orderDate = cells[2]?.innerText || '';
                const items = parseInt(cells[3]?.innerText) || 0;
                const qty = parseInt(cells[4]?.innerText.replace(/,/g, '')) || 0;
                const amount = parseFloat(cells[5]?.innerText.replace('₱', '').replace(/,/g, '')) || 0;
                const status = cells[6]?.innerText || '';
                const expectedDate = cells[7]?.innerText || '';
                
                excelData.push([
                    poNumber,
                    supplier,
                    orderDate,
                    items,
                    qty,
                    amount,
                    status,
                    expectedDate
                ]);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        ws['!cols'] = [
            { wch: 15 }, // PO Number
            { wch: 25 }, // Supplier
            { wch: 15 }, // Order Date
            { wch: 10 }, // Items
            { wch: 12 }, // Quantity
            { wch: 18 }, // Total Amount
            { wch: 15 }, // Status
            { wch: 15 }  // Expected Delivery
        ];

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Purchase Orders');

        // Generate filename with current date
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        const filename = `Purchase_Orders_${dateStr}.xlsx`;

        // Export Excel file
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            text: 'Excel export completed successfully!',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // Helper functions
    function getStatusClass(status) {
        const classes = {
            'draft': 'status-draft',
            'submitted': 'status-processing',
            'approved': 'status-approved',
            'received': 'status-delivered',
            'cancelled': 'status-cancelled'
        };
        return classes[status] || 'status-draft';
    }

    function getStatusText(status) {
        const texts = {
            'draft': 'Draft',
            'submitted': 'Processing',
            'approved': 'Approved',
            'received': 'Delivered',
            'cancelled': 'Cancelled'
        };
        return texts[status] || status;
    }

    // Logout Function
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = 'login.php';
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput')?.focus();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showNewPOModal();
        }
    });
    </script>
</body>
</html>