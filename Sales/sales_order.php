<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get user ID for filtering
$user_id = getUserId();
$branch_id = getUserBranchId();

// Handle order status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'update_status' && isset($_POST['order_id']) && isset($_POST['status'])) {
        $order_id = (int)$_POST['order_id'];
        $status = $_POST['status'];
        
        // Validate status
        $valid_statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            die(json_encode(['success' => false, 'message' => 'Invalid status']));
        }
        
        // First, verify the order exists and belongs to user's branch
        $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('ii', $order_id, $branch_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            die(json_encode(['success' => false, 'message' => 'Order not found or access denied']));
        }
        
        // Get current status before update
        $current_sql = "SELECT order_status FROM sales_orders WHERE so_id = ?";
        $current_stmt = $conn->prepare($current_sql);
        $current_stmt->bind_param('i', $order_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        $current_row = $current_result->fetch_assoc();
        $old_status = $current_row['order_status'];
        
        // Update order status
        $sql = "UPDATE sales_orders SET order_status = ? WHERE so_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $status, $order_id);
        
        if ($stmt->execute()) {
            // Log the status change (check if order_status_logs table exists)
            $log_sql = "INSERT INTO order_status_logs (so_id, user_id, old_status, new_status, notes) 
                       VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            
            $notes = isset($_POST['notes']) ? $_POST['notes'] : "Status updated via sales portal";
            $log_stmt->bind_param('iisss', $order_id, $user_id, $old_status, $status, $notes);
            $log_stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Order status updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
        exit;
    }
    
    if ($action === 'get_order_details' && isset($_POST['order_id'])) {
        $order_id = (int)$_POST['order_id'];
        
        // First verify order belongs to user's branch
        $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('ii', $order_id, $branch_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
            exit;
        }
        
        // Get order details
        $sql = "SELECT 
                    so.so_id,
                    so.so_number,
                    so.order_date,
                    so.total_amount,
                    so.order_status,
                    so.notes,
                    c.customer_name,
                    c.email,
                    c.phone_number,
                    c.address,
                    u.username as created_by
                FROM sales_orders so
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN users u ON so.created_by = u.user_id
                WHERE so.so_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        $order = $result->fetch_assoc();
        
        // Get order items - USING YOUR ACTUAL COLUMN NAMES
        $items_sql = "SELECT 
                        soi.so_item_id,
                        soi.so_id,
                        soi.item_id,
                        soi.quantity_ordered,
                        soi.quantity_delivered,
                        soi.unit_price,
                        soi.line_total,
                        i.item_name,
                        i.item_code,
                        i.unit_type
                     FROM sales_order_items soi
                     JOIN items i ON soi.item_id = i.item_id
                     WHERE soi.so_id = ?
                     ORDER BY soi.so_item_id";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param('i', $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items = $items_result->fetch_all(MYSQLI_ASSOC);
        
        // Get status history (check if table exists first)
        $history = [];
        try {
            $history_sql = "SELECT 
                              osl.*,
                              u.username as changed_by
                           FROM order_status_logs osl
                           LEFT JOIN users u ON osl.user_id = u.user_id
                           WHERE osl.so_id = ?
                           ORDER BY osl.changed_at DESC";
            $history_stmt = $conn->prepare($history_sql);
            $history_stmt->bind_param('i', $order_id);
            $history_stmt->execute();
            $history_result = $history_stmt->get_result();
            $history = $history_result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            // Table might not exist, that's okay
            error_log("order_status_logs table not found: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items,
            'history' => $history
        ]);
        exit;
    }
}

// Handle search and filters
$where_conditions = ["1=1"];
$params = [];
$param_types = "";

// Branch filter
if ($branch_id > 0) {
    $where_conditions[] = "so.branch_id = ?";
    $params[] = $branch_id;
    $param_types .= "i";
}

// Status filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] !== 'all') {
    $where_conditions[] = "so.order_status = ?";
    $params[] = $_GET['status'];
    $param_types .= "s";
}

// Date range filter
if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $where_conditions[] = "DATE(so.order_date) >= ?";
    $params[] = $_GET['start_date'];
    $param_types .= "s";
}

if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $where_conditions[] = "DATE(so.order_date) <= ?";
    $params[] = $_GET['end_date'];
    $param_types .= "s";
}

// Search by order number or customer name
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where_conditions[] = "(so.so_number LIKE ? OR c.customer_name LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $param_types .= "ss";
}

// Build query
$sql = "SELECT 
            so.so_id,
            so.so_number,
            so.order_date,
            so.total_amount,
            so.order_status,
            c.customer_name,
            (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.so_id) as item_count
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        WHERE " . implode(" AND ", $where_conditions) . "
        ORDER BY so.order_date DESC";

// Prepare and execute
$stmt = $conn->prepare($sql);

// Bind parameters dynamically if we have any
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);

// Get order statistics
$stats_sql = "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN order_status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                COALESCE(SUM(total_amount), 0) as total_revenue
              FROM sales_orders 
              WHERE branch_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param('i', $branch_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Orders - Sales Dashboard</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <!-- MOBILE MENU BUTTON -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="bi bi-list"></i>
    </button>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3><i class="bi bi-shop logo-icon"></i> <span class="nav-text">Sales</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="currentinventory.php">
                            <i class="bi bi-boxes"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="orderproduct.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Order Product</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customer.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Customer</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="returnedmerchandise.php">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="nav-text">Returned Merchandise</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <div class="page-title">
                    <h2><i class="bi bi-list-check me-2"></i>Sales Orders</h2>
                    <p>View and manage customer orders</p>
                </div>
                
                <div class="user-info-top">
                    <div class="user-profile-top">
                        <div class="user-avatar-top" id="userAvatar"><?php echo substr(getUserName(), 0, 2); ?></div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName"><?php echo getUserName(); ?></span>
                            <span class="user-role-top" id="userRole"><?php echo ucfirst(str_replace('_', ' ', getUserRole())); ?></span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="window.location.href='../logout.php'">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <!-- Statistics Cards - Using same design as current inventory -->
            <div class="row g-3 mb-4">
                <!-- Total Orders -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_orders'] ?? 0; ?></div>
                            <div class="stat-label">Total Orders</div>
                        </div>
                    </div>
                </div>

                <!-- Pending Orders -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>

                <!-- Processing Orders -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-gear"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['processing'] ?? 0; ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                </div>

                <!-- Total Revenue -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div>
                            <div class="stat-value">₱<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter - Same design as inventory -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="all" <?php echo (!isset($_GET['status']) || $_GET['status'] === 'all') ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo (isset($_GET['status']) && $_GET['status'] === 'processing') ? 'selected' : ''; ?>>Processing</option>
                                <option value="shipped" <?php echo (isset($_GET['status']) && $_GET['status'] === 'shipped') ? 'selected' : ''; ?>>Shipped</option>
                                <option value="delivered" <?php echo (isset($_GET['status']) && $_GET['status'] === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="date" class="form-control" name="start_date" id="startDate" 
                                   value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <input type="date" class="form-control" name="end_date" id="endDate" 
                                   value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search orders or customers...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Orders Table - Similar design -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="ordersTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                                            No orders found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($order['so_number']); ?></span></td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($order['order_date'])); ?><br>
                                                <small class="text-muted"><?php echo date('h:i A', strtotime($order['order_date'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td><span class="badge bg-info"><?php echo $order['item_count']; ?> items</span></td>
                                            <td><strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <?php
                                                $status_badge = [
                                                    'pending' => 'bg-warning',
                                                    'processing' => 'bg-info',
                                                    'shipped' => 'bg-primary',
                                                    'delivered' => 'bg-success',
                                                    'cancelled' => 'bg-danger'
                                                ];
                                                $status_text = [
                                                    'pending' => 'Pending',
                                                    'processing' => 'Processing',
                                                    'shipped' => 'Shipped',
                                                    'delivered' => 'Delivered',
                                                    'cancelled' => 'Cancelled'
                                                ];
                                                ?>
                                                <span class="badge <?php echo $status_badge[$order['order_status']] ?? 'bg-secondary'; ?>">
                                                    <?php echo $status_text[$order['order_status']] ?? ucfirst($order['order_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewOrderDetails(<?php echo $order['so_id']; ?>)" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if (in_array($order['order_status'], ['pending', 'processing'])): ?>
                                                        <button class="btn btn-sm btn-outline-warning" onclick="updateOrderStatus(<?php echo $order['so_id']; ?>)" title="Update Status">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-sm btn-outline-success" onclick="printOrder(<?php echo $order['so_id']; ?>)" title="Print Order">
                                                        <i class="bi bi-printer"></i>
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

    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Order Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="updateOrderId">
                    <div class="mb-3">
                        <label class="form-label">Select New Status</label>
                        <select class="form-select" id="newStatus">
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="shipped">Shipped</option>
                            <option value="delivered">Delivered</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="statusNotes" rows="2" placeholder="Add any notes about this status change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveStatusUpdate()">Update Status</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables -->
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

       
            
            // Set default date range (last 30 days)
            if (!document.getElementById('startDate').value) {
                const endDate = new Date();
                const startDate = new Date();
                startDate.setDate(startDate.getDate() - 30);
                
                document.getElementById('startDate').value = startDate.toISOString().split('T')[0];
                document.getElementById('endDate').value = endDate.toISOString().split('T')[0];
            };

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#ordersTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Status filter
        document.getElementById('statusFilter').addEventListener('change', function() {
            const filter = this.value;
            const rows = document.querySelectorAll('#ordersTable tbody tr');
            
            rows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                    return;
                }
                
                const statusBadge = row.querySelector('.badge');
                const statusText = statusBadge.textContent.toLowerCase();
                row.style.display = (statusText.includes(filter)) ? '' : 'none';
            });
        });

        // Date filters
        document.getElementById('startDate').addEventListener('change', filterByDate);
        document.getElementById('endDate').addEventListener('change', filterByDate);

        function filterByDate() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const rows = document.querySelectorAll('#ordersTable tbody tr');
            
            rows.forEach(row => {
                const dateCell = row.cells[1];
                const dateText = dateCell.querySelector('small').textContent;
                const rowDate = new Date(dateText);
                
                if ((!startDate || rowDate >= new Date(startDate)) && 
                    (!endDate || rowDate <= new Date(endDate))) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // View order details
        function viewOrderDetails(orderId) {
            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            
            // Show loading
            document.getElementById('orderDetailsContent').innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Loading order details...</p>
                </div>
            `;
            
            modal.show();
            
            // Fetch order details via AJAX
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_order_details&order_id=' + orderId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const order = data.order;
                    const items = data.items;
                    const history = data.history;
                    
                    let itemsHtml = '';
                    if (items && items.length > 0) {
                        itemsHtml = items.map(item => `
                            <tr>
                                <td>${item.item_name}<br><small class="text-muted">${item.item_code}</small></td>
                                <td class="text-end">${item.quantity_ordered}</td>
                                <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                                <td class="text-end"><strong>₱${parseFloat(item.line_total).toFixed(2)}</strong></td>
                            </tr>
                        `).join('');
                    }
                    
                    let historyHtml = '';
                    if (history && history.length > 0) {
                        historyHtml = history.map(log => `
                            <div class="alert alert-light mb-2">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <span class="badge bg-secondary">${log.old_status}</span>
                                        <i class="bi bi-arrow-right mx-2"></i>
                                        <span class="badge bg-success">${log.new_status}</span>
                                        <small class="ms-2">by ${log.changed_by || 'System'}</small>
                                    </div>
                                    <small class="text-muted">${new Date(log.changed_at).toLocaleString()}</small>
                                </div>
                                ${log.notes ? `<p class="mt-2 mb-0 small">${log.notes}</p>` : ''}
                            </div>
                        `).join('');
                    } else {
                        historyHtml = '<p class="text-muted">No status history available.</p>';
                    }
                    
                    document.getElementById('orderDetailsContent').innerHTML = `
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label text-muted">Order Number</label>
                                    <p><strong>${order.so_number}</strong></p>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label text-muted">Order Date</label>
                                    <p><strong>${new Date(order.order_date).toLocaleString()}</strong></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label text-muted">Status</label>
                                    <p><span class="badge bg-success">${order.order_status}</span></p>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label text-muted">Created By</label>
                                    <p><strong>${order.created_by}</strong></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6>Customer Information</h6>
                                <div class="alert alert-light">
                                    <p class="mb-1"><strong>Name:</strong> ${order.customer_name}</p>
                                    <p class="mb-1"><strong>Email:</strong> ${order.email}</p>
                                    <p class="mb-1"><strong>Phone:</strong> ${order.phone_number}</p>
                                    <p class="mb-0"><strong>Address:</strong> ${order.address}</p>
                                </div>
                            </div>
                        </div>
                        
                        <h6>Order Items (${items ? items.length : 0} items)</h6>
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Quantity</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                    <tr class="table-success">
                                        <td colspan="3" class="text-end"><strong>Grand Total</strong></td>
                                        <td class="text-end"><strong>₱${parseFloat(order.total_amount).toFixed(2)}</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        ${order.notes ? `
                        <h6>Order Notes</h6>
                        <div class="alert alert-info">
                            ${order.notes}
                        </div>
                        ` : ''}
                        
                        <h6>Status History</h6>
                        <div class="status-history">
                            ${historyHtml}
                        </div>
                    `;
                } else {
                    document.getElementById('orderDetailsContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> ${data.message || 'Error loading order details.'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('orderDetailsContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Network error: ${error.message}
                    </div>
                `;
            });
        }
        
        // Update order status
        function updateOrderStatus(orderId) {
            document.getElementById('updateOrderId').value = orderId;
            const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
            modal.show();
        }
        
        // Save status update
        function saveStatusUpdate() {
            const orderId = document.getElementById('updateOrderId').value;
            const newStatus = document.getElementById('newStatus').value;
            const notes = document.getElementById('statusNotes').value;
            
            if (!orderId || !newStatus) {
                alert('Please select a status');
                return;
            }
            
            const btn = document.querySelector('#updateStatusModal .btn-success');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Updating...';
            btn.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_status&order_id=${orderId}&status=${newStatus}${notes ? '&notes=' + encodeURIComponent(notes) : ''}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order status updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                alert('Network error: ' + error.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
        
        // Print order
        function printOrder(orderId) {
            window.open(`print_order.php?id=${orderId}`, '_blank');
        }
    </script>
</body>
</html>