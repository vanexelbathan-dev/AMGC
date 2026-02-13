<?php
require_once '../config/database.php';

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // UPDATE SALES ORDER
        if ($_POST['action'] === 'update_order') {
            $so_id = (int)$_POST['so_id'];
            $order_date = $_POST['order_date'];
            $order_status = $_POST['order_status'];
            $total_amount = (float)$_POST['total_amount'];
            
            $update_query = "UPDATE sales_orders 
                           SET order_date = ?, order_status = ?, total_amount = ?, updated_at = NOW() 
                           WHERE so_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssdi", $order_date, $order_status, $total_amount, $so_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update sales order');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Sales order updated successfully'
            ]);
            exit;
        }
        
        // DELETE SALES ORDER
        elseif ($_POST['action'] === 'delete_order') {
            $so_id = (int)$_POST['so_id'];
            
            // Check if order has related records
            $check_picklist_query = "SELECT COUNT(*) as count FROM pick_lists WHERE so_id = ?";
            $check_picklist_stmt = $conn->prepare($check_picklist_query);
            $check_picklist_stmt->bind_param("i", $so_id);
            $check_picklist_stmt->execute();
            $picklist_count = $check_picklist_stmt->get_result()->fetch_assoc()['count'];
            
            if ($picklist_count > 0) {
                throw new Exception('Cannot delete order with existing pick lists');
            }
            
            // Delete order items first
            $delete_items_query = "DELETE FROM sales_order_items WHERE so_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_query);
            $delete_items_stmt->bind_param("i", $so_id);
            $delete_items_stmt->execute();
            
            // Delete the order
            $delete_order_query = "DELETE FROM sales_orders WHERE so_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("i", $so_id);
            
            if (!$delete_order_stmt->execute()) {
                throw new Exception('Failed to delete sales order');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Sales order deleted successfully'
            ]);
            exit;
        }
        
        // GET SALES ORDER DETAILS
        elseif ($_POST['action'] === 'get_order') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    so.*,
                    c.customer_name,
                    c.customer_id,
                    COUNT(soi.so_item_id) as total_items,
                    SUM(soi.quantity_ordered) as total_quantity
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                WHERE so.so_id = ?
                GROUP BY so.so_id
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $so_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result->fetch_assoc();
            
            if ($order) {
                // Get order items
                $items_query = "
                    SELECT 
                        soi.*,
                        i.item_code,
                        i.item_name,
                        i.unit_type
                    FROM sales_order_items soi
                    JOIN items i ON soi.item_id = i.item_id
                    WHERE soi.so_id = ?
                ";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'order' => $order,
                    'items' => $items
                ]);
            } else {
                throw new Exception('Sales order not found');
            }
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

// FETCH SALES ORDERS WITH CUSTOMER AND ITEM COUNTS
$sales_query = "
    SELECT 
        so.so_id,
        so.so_number,
        so.order_date,
        so.total_amount,
        so.order_status,
        c.customer_name,
        c.customer_id,
        COUNT(soi.so_item_id) as total_items,
        SUM(soi.quantity_ordered) as total_quantity
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
    GROUP BY so.so_id
    ORDER BY so.order_date DESC, so.so_id DESC
";
$sales_result = $conn->query($sales_query);
$sales_orders = $sales_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA
$total_orders = count($sales_orders);
$pending_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'pending'));
$processing_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'processing'));
$ready_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'ready'));
$delivered_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'delivered'));
$cancelled_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'cancelled'));

// STAT CARD VALUES
$statTotalOrders = $total_orders;
$statPendingOrders = $pending_orders;
$statForDelivery = $ready_orders;
$statCompletedOrders = $delivered_orders;

// Get unique customers for filter
$customers_query = "SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
$customers = $customers_result->fetch_all(MYSQLI_ASSOC);

// Helper function for order status badge
function getOrderStatusBadge($status) {
    return match($status) {
        'pending' => 'badge bg-warning text-dark',
        'confirmed' => 'badge bg-info text-white',
        'processing' => 'badge bg-primary text-white',
        'ready' => 'badge bg-info text-white',
        'delivered' => 'badge bg-success text-white',
        'cancelled' => 'badge bg-danger text-white',
        default => 'badge bg-secondary text-white'
    };
}

function getOrderStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready' => 'For Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

// Payment status derived from order status
function getPaymentStatus($order_status) {
    if ($order_status === 'delivered') return ['status' => 'Paid', 'class' => 'badge-success'];
    if ($order_status === 'cancelled') return ['status' => 'Cancelled', 'class' => 'badge-danger'];
    return ['status' => 'Pending', 'class' => 'badge-warning'];
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
    <title>Sales Orders</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
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
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="sales_order.php">
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
                        <a class="nav-link" href="purchase_order.php">
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
            <!-- SALES ORDER CONTENT -->
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <!-- MOBILE MENU BUTTON -->
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    
                    <div class="page-title">
                        <h2><i class="bi bi-bag me-2"></i>Sales Orders</h2>
                        <p id="dashboardSubtitle">Manage and track all sales orders</p>
                    </div>
                </div>

                <!-- Quick Stats - REAL DATA FROM DATABASE -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <i class="bi bi-cart-check stat-icon"></i>
                            <div class="stat-value"><?= $statTotalOrders ?></div>
                            <div class="stat-label">Total Orders</div>
                            <small class="d-block mt-2">All time sales orders</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value"><?= $statPendingOrders ?></div>
                            <div class="stat-label">Pending</div>
                            <small class="d-block mt-2">Awaiting confirmation</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value"><?= $statForDelivery ?></div>
                            <div class="stat-label">For Delivery</div>
                            <small class="d-block mt-2">Ready to ship</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $statCompletedOrders ?></div>
                            <div class="stat-label">Completed</div>
                            <small class="d-block mt-2">Successfully delivered</small>
                        </div>
                    </div>
                </div>

                <!-- Search and Filter - AUTO FILTER ON CHANGE -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" id="searchInput" placeholder="Search by order number or customer..." onkeyup="filterTable()">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="statusFilter" onchange="filterTable()">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="processing">Processing</option>
                                        <option value="ready">For Delivery</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="customerFilter" onchange="filterTable()">
                                        <option value="">All Customers</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                <?= htmlspecialchars($customer['customer_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sales Orders Table - REAL DATA FROM DATABASE -->
                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Sales Orders</h5>
                        <div class="d-flex gap-2">
                            <span class="text-muted me-2">Total: ₱<?= number_format(array_sum(array_column($sales_orders, 'total_amount')), 2) ?></span>
                            <button class="btn btn-sm btn-outline-primary" onclick="printReport()">
                                <i class="bi bi-printer me-1"></i> Print
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="exportToExcel()">
                                <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table" id="salesOrdersTable">
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Qty</th>
                                    <th>Total Amount</th>
                                    <th>Payment Status</th>
                                    <th>Order Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="salesOrdersTableBody">
                                <?php if (empty($sales_orders)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
                                        <p class="text-muted mb-0">No sales orders found</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($sales_orders as $order): 
                                        $payment = getPaymentStatus($order['order_status']);
                                    ?>
                                    <tr class="sales-order-row" 
                                        data-id="<?= $order['so_id'] ?>"
                                        data-order-number="<?= htmlspecialchars($order['so_number']) ?>"
                                        data-customer="<?= htmlspecialchars($order['customer_name']) ?>"
                                        data-status="<?= $order['order_status'] ?>"
                                        data-date="<?= $order['order_date'] ?>"
                                        data-amount="<?= $order['total_amount'] ?>"
                                        data-items="<?= $order['total_items'] ?? 0 ?>"
                                        data-qty="<?= $order['total_quantity'] ?? 0 ?>">
                                        <td><strong><?= htmlspecialchars($order['so_number']) ?></strong></td>
                                        <td><?= formatDate($order['order_date']) ?></td>
                                        <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                        <td class="text-center"><?= $order['total_items'] ?? 0 ?></td>
                                        <td class="text-center"><?= $order['total_quantity'] ?? 0 ?></td>
                                        <td class="text-end">₱<?= number_format($order['total_amount'] ?? 0, 2) ?></td>
                                        <td>
                                            <span class="badge <?= $payment['class'] ?>"><?= $payment['status'] ?></span>
                                        </td>
                                        <td>
                                            <span class="<?= getOrderStatusBadge($order['order_status']) ?>">
                                                <?= getOrderStatusText($order['order_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewOrder(<?= $order['so_id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning" onclick="editOrder(<?= $order['so_id'] ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteOrder(<?= $order['so_id'] ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW ORDER MODAL -->
    <div class="modal fade" id="viewOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Sales Order Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="viewOrderContent">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" onclick="editFromView()" id="editFromViewBtn">Edit Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT ORDER MODAL -->
    <div class="modal fade" id="editOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Sales Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editOrderForm">
                        <input type="hidden" id="editOrderId">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editOrderNumber" class="form-label">Order Number</label>
                                <input type="text" class="form-control" id="editOrderNumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="editOrderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editCustomerName" class="form-label">Customer</label>
                                <input type="text" class="form-control" id="editCustomerName" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderStatus" class="form-label">Order Status *</label>
                                <select class="form-select" id="editOrderStatus" required>
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="processing">Processing</option>
                                    <option value="ready">For Delivery</option>
                                    <option value="delivered">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalItems" class="form-label">Items</label>
                                <input type="number" class="form-control" id="editTotalItems" readonly>
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalQty" class="form-label">Total Quantity</label>
                                <input type="number" class="form-control" id="editTotalQty" readonly>
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalAmount" class="form-label">Total Amount (₱) *</label>
                                <input type="number" class="form-control" id="editTotalAmount" step="0.01" min="0" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateOrder()">Update Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this sales order?</p>
                    <p class="fw-bold" id="deleteOrderNumber"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone and will remove all associated order items.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentOrderId = null;
    
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

    // ========== SALES ORDER FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Sales Orders - Live Database Mode");
        
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
    });

    // ========== MODAL FUNCTIONS ==========
    
    // View Order
    function viewOrder(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_order');
        formData.append('so_id', id);
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const order = data.order;
                const items = data.items;
                
                // Format date
                const orderDate = new Date(order.order_date);
                const formattedDate = orderDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                const statusBadge = getStatusBadge(order.order_status);
                const statusText = getStatusText(order.order_status);
                const paymentStatus = getPaymentStatusText(order.order_status);
                const paymentClass = getPaymentStatusClass(order.order_status);
                
                // Build items table
                let itemsHtml = '';
                if (items && items.length > 0) {
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
                
                const content = document.getElementById('viewOrderContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">Order Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td width="40%">Order Number:</td>
                                            <td><strong>${order.so_number}</strong></td>
                                        </tr>
                                        <tr>
                                            <td>Order Date:</td>
                                            <td>${formattedDate}</td>
                                        </tr>
                                        <tr>
                                            <td>Customer:</td>
                                            <td>${order.customer_name}</td>
                                        </tr>
                                        <tr>
                                            <td>Order Status:</td>
                                            <td><span class="${statusBadge}">${statusText}</span></td>
                                        </tr>
                                        <tr>
                                            <td>Payment Status:</td>
                                            <td><span class="badge ${paymentClass}">${paymentStatus}</span></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">Order Summary</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td width="40%">Total Items:</td>
                                            <td>${order.total_items || 0}</td>
                                        </tr>
                                        <tr>
                                            <td>Total Quantity:</td>
                                            <td>${order.total_quantity || 0}</td>
                                        </tr>
                                        <tr>
                                            <td>Total Amount:</td>
                                            <td class="fw-bold fs-5">₱${Number(order.total_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                        </tr>
                                        <tr>
                                            <td>Created At:</td>
                                            <td>${order.created_at ? new Date(order.created_at).toLocaleString() : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <td>Last Updated:</td>
                                            <td>${order.updated_at ? new Date(order.updated_at).toLocaleString() : 'N/A'}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    ${itemsHtml}
                `;
                
                currentOrderId = id;
                
                // Show/hide edit button based on status
                const editBtn = document.getElementById('editFromViewBtn');
                if (order.order_status !== 'delivered' && order.order_status !== 'cancelled') {
                    editBtn.style.display = 'inline-block';
                } else {
                    editBtn.style.display = 'none';
                }
                
                new bootstrap.Modal(document.getElementById('viewOrderModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching order details', 'error');
        });
    }

    // Edit from View Modal
    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewOrderModal')).hide();
        setTimeout(() => {
            editOrder(currentOrderId);
        }, 300);
    }

    // Edit Order
    function editOrder(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_order');
        formData.append('so_id', id);
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const order = data.order;
                
                // Format date for input
                const orderDate = order.order_date.split(' ')[0];
                
                document.getElementById('editOrderId').value = order.so_id;
                document.getElementById('editOrderNumber').value = order.so_number;
                document.getElementById('editOrderDate').value = orderDate;
                document.getElementById('editCustomerName').value = order.customer_name;
                document.getElementById('editOrderStatus').value = order.order_status;
                document.getElementById('editTotalItems').value = order.total_items || 0;
                document.getElementById('editTotalQty').value = order.total_quantity || 0;
                document.getElementById('editTotalAmount').value = order.total_amount;
                
                currentOrderId = id;
                new bootstrap.Modal(document.getElementById('editOrderModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching order details', 'error');
        });
    }

    // Update Order
    function updateOrder() {
        const orderId = document.getElementById('editOrderId').value;
        const orderDate = document.getElementById('editOrderDate').value;
        const orderStatus = document.getElementById('editOrderStatus').value;
        const totalAmount = document.getElementById('editTotalAmount').value;
        
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
        formData.append('action', 'update_order');
        formData.append('so_id', orderId);
        formData.append('order_date', orderDate);
        formData.append('order_status', orderStatus);
        formData.append('total_amount', totalAmount);
        
        fetch('sales_order.php', {
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
                    bootstrap.Modal.getInstance(document.getElementById('editOrderModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while updating the order', 'error');
        });
    }

    // Delete Order
    function deleteOrder(id) {
        const row = document.querySelector(`.sales-order-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteOrderNumber').textContent = row.dataset.orderNumber;
        currentOrderId = id;
        new bootstrap.Modal(document.getElementById('deleteOrderModal')).show();
    }

    // Confirm Delete
    function confirmDelete() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_order');
        formData.append('so_id', currentOrderId);
        
        fetch('sales_order.php', {
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
                    bootstrap.Modal.getInstance(document.getElementById('deleteOrderModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while deleting the order', 'error');
        });
    }

    // ========== FILTER FUNCTIONS ==========
    
    // Filter table function
    function filterTable() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const customerFilter = document.getElementById('customerFilter').value;
        
        const rows = document.querySelectorAll('.sales-order-row');
        
        rows.forEach(row => {
            const orderNumber = row.dataset.orderNumber?.toLowerCase() || '';
            const customer = row.dataset.customer?.toLowerCase() || '';
            const status = row.dataset.status || '';
            
            let matchesSearch = searchTerm === '' || 
                orderNumber.includes(searchTerm) || 
                customer.includes(searchTerm);
            
            let matchesStatus = statusFilter === '' || status === statusFilter;
            let matchesCustomer = customerFilter === '' || row.dataset.customer === customerFilter;
            
            if (matchesSearch && matchesStatus && matchesCustomer) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // ========== UTILITY FUNCTIONS ==========
    
    // Format Date
    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // Get Status Badge
    function getStatusBadge(status) {
        const classes = {
            'pending': 'badge bg-warning text-dark',
            'confirmed': 'badge bg-info text-white',
            'processing': 'badge bg-primary text-white',
            'ready': 'badge bg-info text-white',
            'delivered': 'badge bg-success text-white',
            'cancelled': 'badge bg-danger text-white'
        };
        return classes[status] || 'badge bg-secondary text-white';
    }

    // Get Status Text
    function getStatusText(status) {
        const texts = {
            'pending': 'Pending',
            'confirmed': 'Confirmed',
            'processing': 'Processing',
            'ready': 'For Delivery',
            'delivered': 'Delivered',
            'cancelled': 'Cancelled'
        };
        return texts[status] || status;
    }

    // Get Payment Status
    function getPaymentStatusText(status) {
        if (status === 'delivered') return 'Paid';
        if (status === 'cancelled') return 'Cancelled';
        return 'Pending';
    }

    // Get Payment Status Class
    function getPaymentStatusClass(status) {
        if (status === 'delivered') return 'badge-success';
        if (status === 'cancelled') return 'badge-danger';
        return 'badge-warning';
    }

    // ========== EXPORT FUNCTIONS ==========
    
    // Print Report
    function printReport() {
        window.print();
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.sales-order-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No orders to export', 'warning');
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        excelData.push([
            'Order Number',
            'Date',
            'Customer',
            'Items',
            'Quantity',
            'Total Amount (₱)',
            'Payment Status',
            'Order Status'
        ]);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                const orderNo = cells[0]?.innerText || '';
                const date = cells[1]?.innerText || '';
                const customer = cells[2]?.innerText || '';
                const items = parseInt(cells[3]?.innerText) || 0;
                const qty = parseInt(cells[4]?.innerText) || 0;
                const amount = parseFloat(cells[5]?.innerText.replace('₱', '').replace(/,/g, '')) || 0;
                const paymentStatus = cells[6]?.innerText || '';
                const orderStatus = cells[7]?.innerText || '';
                
                excelData.push([
                    orderNo,
                    date,
                    customer,
                    items,
                    qty,
                    amount,
                    paymentStatus,
                    orderStatus
                ]);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        ws['!cols'] = [
            { wch: 15 }, // Order Number
            { wch: 15 }, // Date
            { wch: 25 }, // Customer
            { wch: 10 }, // Items
            { wch: 12 }, // Quantity
            { wch: 18 }, // Total Amount
            { wch: 15 }, // Payment Status
            { wch: 15 }  // Order Status
        ];

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Sales Orders');

        // Generate filename with current date
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        const filename = `Sales_Orders_${dateStr}.xlsx`;

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

    // ========== LOGOUT FUNCTION ==========
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
                window.location.href = '../login.php';
            }
        });
    }

    // ========== KEYBOARD SHORTCUTS ==========
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput')?.focus();
        }
    });
    </script>
</body>
</html>