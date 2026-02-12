<?php
require_once '../config/database.php';

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
        SUM(poi.quantity_ordered) as total_quantity
    FROM purchase_orders po
    LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
    GROUP BY po.po_id
    ORDER BY po.created_at DESC, po.po_id DESC
";
$po_result = $conn->query($po_query);
$purchase_orders = $po_result->fetch_all(MYSQLI_ASSOC);

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
        'approved' => 'status-processing',
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
                        <button class="btn btn-outline-primary" onclick="exportToExcel()">
                            <i class="bi bi-download me-1"></i> Export
                        </button>
                        <button class="btn btn-primary" onclick="showNewPOModal()">
                            <i class="bi bi-plus-circle me-1"></i> New PO
                        </button>
                    </div>
                </div>

                <!-- Table Container - WITHOUT CHECKBOX COLUMN -->
                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table po-table">
                            <thead>
                                <tr>
                                    <!-- CHECKBOX HEADER REMOVED -->
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
                                        data-po-number="<?= htmlspecialchars($po['po_number']) ?>"
                                        data-supplier="<?= htmlspecialchars($po['supplier_name'] ?? '') ?>"
                                        data-status="<?= $po['po_status'] ?>"
                                        data-date="<?= $po['order_date'] ?>">
                                        <!-- CHECKBOX CELL REMOVED -->
                                        <td class="col-po">
                                            <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                        </td>
                                        <td class="col-supplier">
                                            <?= htmlspecialchars($po['supplier_name'] ?? 'N/A') ?>
                                        </td>
                                        <td class="col-date"><?= formatDate($po['order_date']) ?></td>
                                        <td class="col-items"><?= $po['total_items'] ?? 0 ?></td>
                                        <td class="col-qty"><?= $po['total_quantity'] ?? 0 ?></td>
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
                                                <button class="table-btn btn-edit" onclick="editPO(<?= $po['po_id'] ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="table-btn btn-delete" onclick="deletePO(<?= $po['po_id'] ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
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

                <!-- PAGINATION - REMOVED COMPLETELY -->
                
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
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

    // ========== PURCHASE ORDER FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Purchase Orders - Live Database Mode");
        
        initializeSidebar();
        
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

        // Load sidebar preference
        const savedCollapsed = localStorage.getItem('sidebarCollapsed');
        if (savedCollapsed === 'true' && window.innerWidth > 992) {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.add('collapsed');
            document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
        }
    });

    // Filter table function
    function filterTable() {
        const statusFilter = document.getElementById('filterStatus').value;
        const supplierFilter = document.getElementById('filterSupplier').value;
        const monthFilter = document.getElementById('filterMonth').value;
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        
        const rows = document.querySelectorAll('.po-row');
        
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
            } else {
                row.style.display = 'none';
            }
        });
    }

    // TOGGLE SELECT ALL - REMOVED (no checkboxes)

    // GET SELECTED PO IDs - REMOVED (no checkboxes)

    // CRUD Operations
    function viewPO(id) {
        alert('View PO ' + id + ' - AJAX implementation needed');
    }

    function editPO(id) {
        alert('Edit PO ' + id + ' - AJAX implementation needed');
    }

    function deletePO(id) {
        if (confirm('Are you sure you want to delete this purchase order?')) {
            alert('Delete PO ' + id + ' - AJAX implementation needed');
        }
    }

    // DELETE SELECTED POS - REMOVED (no checkboxes)

    // Show new PO modal
    function showNewPOModal() {
        alert('New PO modal - AJAX implementation needed');
    }

    // Export to Excel (CSV)
    function exportToExcel() {
        const rows = document.querySelectorAll('.po-row:not([style*="display: none"])');
        if (rows.length === 0) {
            alert('No purchase orders to export');
            return;
        }
        
        let csv = 'PO Number,Supplier,Order Date,Expected Delivery,Status,Items,Quantity,Total Amount\n';
        
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                // ADJUSTED CELL INDEXES - checkbox column removed
                const poNumber = cells[0]?.innerText || '';
                const supplier = cells[1]?.innerText || '';
                const orderDate = cells[2]?.innerText || '';
                const items = cells[3]?.innerText || '0';
                const qty = cells[4]?.innerText || '0';
                const amount = cells[5]?.innerText.replace('₱', '').replace(',', '') || '0';
                const status = cells[6]?.innerText || '';
                const expectedDate = cells[7]?.innerText || '';
                
                csv += `${poNumber},"${supplier}",${orderDate},${expectedDate},${status},${items},${qty},${amount}\n`;
            }
        });
        
        const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `purchase_orders_export_<?= date('Ymd') ?>.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        alert('Export completed!');
    }

    // Logout Function
    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            localStorage.removeItem('sidebarCollapsed');
            window.location.href = 'login.php';
        }
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
    
    /* Text alignment utilities */
    .text-center {
        text-align: center;
    }
    
    .text-end {
        text-align: right;
    }
    
    /* CHECKBOX STYLING - REMOVED */
    
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
</body>
</html>