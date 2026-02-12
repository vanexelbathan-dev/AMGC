<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info
    $user_id = $_SESSION['user_id'];
    $user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Driver User';
    $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'delivery';

// Handle Add Return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_return') {
    $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $item_id = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
    $return_quantity = !empty($_POST['return_qty']) ? (int)$_POST['return_qty'] : 0;
    $reason = isset($_POST['return_reason']) ? trim($_POST['return_reason']) : 'other';
    $status = 'pending'; // Force status to pending only
    $so_id = !empty($_POST['so_id']) ? (int)$_POST['so_id'] : null;
    
    // Map reason to enum
    $reason_map = [
        'Defective unit' => 'damaged',
        'Wrong Item' => 'wrong-item',
        'Damaged in shipping' => 'damaged',
        'Not as described' => 'quality',
        'Customer changed mind' => 'other',
        'Expired' => 'expired',
        'Overstock' => 'overstock'
    ];
    $reason_enum = $reason_map[$reason] ?? 'other';
    
    $rmr_number = 'RMR-' . date('Ymd') . '-' . time();
    
    if ($customer_id && $item_id && $return_quantity > 0) {
        $sql = "INSERT INTO rmr_requests (rmr_number, so_id, customer_id, item_id, return_quantity, return_reason, reason_details, rmr_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $reason_details = 'Return via sales interface';
        
        $stmt->bind_param('siiiisss', $rmr_number, $so_id, $customer_id, $item_id, $return_quantity, $reason_enum, $reason_details, $status);
        
        if ($stmt->execute()) {
            $success = 'Return request added successfully!';
            // Redirect to refresh the page and show success message
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
            exit();
        } else {
            $error = 'Error adding return: ' . $stmt->error;
        }
    } else {
        $error = 'Please fill in all required fields';
    }
}

// Handle Status Update via AJAX or Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $rmr_id = isset($_POST['rmr_id']) ? (int)$_POST['rmr_id'] : 0;
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    
    if ($rmr_id > 0 && in_array($new_status, ['pending', 'approved', 'rejected', 'processing', 'completed'])) {
        $update_sql = "UPDATE rmr_requests SET rmr_status = ? WHERE rmr_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('si', $new_status, $rmr_id);
        
        if ($update_stmt->execute()) {
            echo json_encode(['success' => true]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => $update_stmt->error]);
            exit();
        }
    }
}

// Handle AJAX request to get SO details with customer info
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_so_details') {
    $so_id = isset($_GET['so_id']) ? (int)$_GET['so_id'] : 0;
    
    if ($so_id > 0) {
        // Join with customers table to get customer name
        $query = "SELECT so.*, c.customer_id, c.customer_name 
                  FROM sales_orders so
                  JOIN customers c ON so.customer_id = c.customer_id
                  WHERE so.so_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $so_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        
        // Get order items - Using quantity_ordered column
        $items_query = "SELECT soi.*, i.item_id, i.item_code, i.item_name, i.unit_price 
                       FROM sales_order_items soi
                       JOIN items i ON soi.item_id = i.item_id
                       WHERE soi.so_id = ?";
        $items_stmt = $conn->prepare($items_query);
        $items_stmt->bind_param('i', $so_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items = [];
        
        if ($items_result) {
            while ($item = $items_result->fetch_assoc()) {
                // Use quantity_ordered as the column name
                $ordered_qty = isset($item['quantity_ordered']) ? (int)$item['quantity_ordered'] : 0;
                
                $items[] = [
                    'item_id' => $item['item_id'],
                    'item_code' => $item['item_code'],
                    'item_name' => $item['item_name'],
                    'unit_price' => $item['unit_price'],
                    'quantity' => $ordered_qty,
                    'quantity_ordered' => $ordered_qty
                ];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid SO ID']);
    exit;
}

// Get all returns with item price and SO number
$returns = [];
$query = "SELECT rmr.*, c.customer_name, i.item_name, i.item_code, i.unit_price, so.so_number
          FROM rmr_requests rmr
          JOIN customers c ON rmr.customer_id = c.customer_id
          JOIN items i ON rmr.item_id = i.item_id
          LEFT JOIN sales_orders so ON rmr.so_id = so.so_id
          ORDER BY rmr.created_at DESC";
$result = $conn->query($query);
if ($result) {
    $returns = $result->fetch_all(MYSQLI_ASSOC);
}

// Get stats
$pending = 0;
$approved = 0;
$rejected = 0;
$processing = 0;
$completed = 0;
$total_refunds = 0;

$stats_query = "SELECT 
                SUM(CASE WHEN rmr_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN rmr_status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN rmr_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN rmr_status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN rmr_status = 'completed' THEN 1 ELSE 0 END) as completed,
                COALESCE(SUM(CASE WHEN rmr_status IN ('approved', 'completed') THEN return_quantity * i.unit_price ELSE 0 END), 0) as total_refunds
                FROM rmr_requests
                LEFT JOIN items i ON rmr_requests.item_id = i.item_id";
$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $pending = $stats['pending'] ?? 0;
    $approved = $stats['approved'] ?? 0;
    $rejected = $stats['rejected'] ?? 0;
    $processing = $stats['processing'] ?? 0;
    $completed = $stats['completed'] ?? 0;
    $total_refunds = $stats['total_refunds'] ?? 0;
}

// Get customers for dropdown (active customers only)
$customers_result = $conn->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name");
$customers = $customers_result ? $customers_result->fetch_all(MYSQLI_ASSOC) : [];

// Get sales orders with customer names from customers table
$so_query = "SELECT so.so_id, so.so_number, so.customer_id, so.order_date, so.total_amount, c.customer_name
             FROM sales_orders so
             JOIN customers c ON so.customer_id = c.customer_id
             WHERE order_status != 'cancelled'
             ORDER BY so.created_at DESC LIMIT 100";
$so_result = $conn->query($so_query);
$sales_orders = $so_result ? $so_result->fetch_all(MYSQLI_ASSOC) : [];

// Check for success message from redirect
$success = '';
$error = '';

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = 'Return request added successfully!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returned Merchandise - Sales</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/sales.css">
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
            <!-- Burger icon moved before logo -->
            <button class="desktop-toggle-btn" id="desktopToggleBtn">
                <i class="bi bi-list" id="toggleIcon"></i>
            </button>
                <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                <span class="nav-text">Sales</span>
        </h3>
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
                        <a class="nav-link" href="sales_order.php">
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
                        <a class="nav-link active" href="returnedmerchandise.php">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="nav-text">Returned Merchandise</span>
                        </a>
                    </li>
                </ul>
            </div>
             <!-- User Profile Section at the bottom of sidebar -->
        <div class="sidebar-footer">
            <div class="user-profile-sidebar">
                <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
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

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Returned Merchandise Requests</h2>
                    <p>Process and manage merchandise returns</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

          <!-- Return Stats -->
<div class="row g-3 mb-4">
    <div class="col">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $pending; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card processing">
            <div class="stat-icon">
                <i class="bi bi-gear"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $processing; ?></div>
                <div class="stat-label">Processing</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card complete">
            <div class="stat-icon">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $approved; ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card complete">
            <div class="stat-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $completed; ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card sales">
            <div class="stat-icon">
                <i class="bi bi-x-circle"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $rejected; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
    </div>
</div>

            <!-- Search and Filter with Add Button -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by return ID, customer name, product...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addReturnModal">
                                <i class="bi bi-plus-lg"></i> New Return
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Returns Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Return ID</th>
                                <th>SO Number</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Reason</th>
                                <th>Request Date</th>
                                <th>Status</th>
                                <th>Refund Amount</th>
                                <!-- <th>Action</th> -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($returns) > 0): ?>
                                <?php foreach ($returns as $return): ?>
                                    <?php
                                        $status_badge = match($return['rmr_status']) {
                                            'pending' => 'bg-warning',
                                            'processing' => 'bg-info',
                                            'approved' => 'bg-success',
                                            'completed' => 'bg-success',
                                            'rejected' => 'bg-danger',
                                            default => 'bg-light'
                                        };
                                        $status_label = ucfirst($return['rmr_status']);
                                        $refund_amount = $return['return_quantity'] * ($return['unit_price'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($return['rmr_number']); ?></span></td>
                                        <td><?php echo htmlspecialchars($return['so_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($return['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($return['item_name']); ?></td>
                                        <td><?php echo $return['return_quantity']; ?></td>
                                        <td><?php echo htmlspecialchars($return['return_reason']); ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($return['created_at'])); ?></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_label; ?></span></td>
                                        <td>₱<?php echo number_format($refund_amount, 2); ?></td>
                                        <!-- <td>
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($return['rmr_status'] === 'pending'): ?>
                                                    <button class="btn btn-success" title="Approve" onclick="updateStatus(<?php echo $return['rmr_id']; ?>, 'approved')">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                    <button class="btn btn-danger" title="Reject" onclick="updateStatus(<?php echo $return['rmr_id']; ?>, 'rejected')">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                    <button class="btn btn-info" title="Process" onclick="updateStatus(<?php echo $return['rmr_id']; ?>, 'processing')">
                                                        <i class="bi bi-gear"></i>
                                                    </button>
                                                <?php elseif ($return['rmr_status'] === 'processing'): ?>
                                                    <button class="btn btn-success" title="Approve" onclick="updateStatus(<?php echo $return['rmr_id']; ?>, 'approved')">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                    <button class="btn btn-danger" title="Reject" onclick="updateStatus(<?php echo $return['rmr_id']; ?>, 'rejected')">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                    <button class="btn btn-primary" title="Complete" onclick="updateStatus(<?php echo $return['rmr_id']; ?>, 'completed')">
                                                        <i class="bi bi-check-circle-fill"></i>
                                                    </button>
                                                <?php elseif ($return['rmr_status'] === 'approved'): ?>
                                                    <button class="btn btn-primary" title="Complete" onclick="updateStatus(<?php echo $return['rmr_id']; ?>, 'completed')">
                                                        <i class="bi bi-check-circle-fill"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td> -->
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No returns found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Return Modal -->
    <div class="modal fade" id="addReturnModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Return Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addReturnForm" method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                        <input type="hidden" name="action" value="add_return">
                        
                        <!-- Sales Order Selection - Required to Auto Fill -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Select Sales Order *</label>
                            <select class="form-select" name="so_id" id="so_id" required>
                                <option value="">-- Select Sales Order to Auto Fill Details --</option>
                                <?php foreach ($sales_orders as $order): ?>
                                    <option value="<?php echo $order['so_id']; ?>" 
                                            data-customer-id="<?php echo $order['customer_id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($order['customer_name']); ?>"
                                            data-order-date="<?php echo $order['order_date']; ?>"
                                            data-total="<?php echo $order['total_amount']; ?>">
                                        <?php 
                                        echo htmlspecialchars(
                                            $order['so_number'] . ' - ' . 
                                            $order['customer_name'] . ' - ' . 
                                            date('Y-m-d', strtotime($order['order_date'])) . ' - ' .
                                            '₱' . number_format($order['total_amount'], 2)
                                        ); 
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select a sales order to automatically fill customer and product details</small>
                        </div>

                        <!-- Order Information Card - Auto-filled from customers table -->
                        <div class="order-info-card" id="orderInfoCard">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="fw-bold">Order Information</h6>
                                    <p class="mb-1"><strong>SO Number:</strong> <span id="display_so_number"></span></p>
                                    <p class="mb-1"><strong>Order Date:</strong> <span id="display_order_date"></span></p>
                                    <p class="mb-1"><strong>Total Amount:</strong> ₱<span id="display_total_amount"></span></p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="fw-bold">Customer Information</h6>
                                    <p class="mb-1"><strong>Name:</strong> <span id="display_customer_name" class="customer-badge"></span></p>
                                    <input type="hidden" name="customer_id" id="customer_id">
                                </div>
                            </div>
                        </div>

                        <!-- Product Selection - Auto-filled from SO -->
                        <div class="mb-3 product-select-group" id="productSelectGroup">
                            <label class="form-label fw-bold">Product to Return *</label>
                            <select class="form-select" name="item_id" id="item_id" required disabled>
                                <option value="">-- Select Product from Order --</option>
                            </select>
                        </div>

                        <!-- Quantity and Reason - User Input -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Return Quantity *</label>
                                <input type="number" class="form-control" name="return_qty" id="return_qty" required min="1" placeholder="Enter quantity to return" disabled>
                                <small class="text-muted" id="max_qty_hint"></small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Estimated Refund</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="text" class="form-control" id="estimated_refund" readonly value="0.00">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Reason for Return *</label>
                            <select class="form-select" name="return_reason" id="return_reason" required disabled>
                                <option value="">-- Select Reason --</option>
                                <option value="Defective unit">Defective unit</option>
                                <option value="Wrong Item">Wrong Item</option>
                                <option value="Damaged in shipping">Damaged in shipping</option>
                                <option value="Not as described">Not as described</option>
                                <option value="Customer changed mind">Customer changed mind</option>
                                <option value="Expired">Expired</option>
                                <option value="Overstock">Overstock</option>
                            </select>
                        </div>

                        <!-- Status - Hidden and always pending -->
                        <input type="hidden" name="return_status" value="pending">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitReturnBtn" disabled onclick="document.getElementById('addReturnForm').submit();">
                        Add Return Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
        // ================= SIDEBAR FUNCTIONS =================
        // Toggle sidebar collapse/expand
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                // On mobile, toggle active state
                sidebar.classList.toggle('active');
                
                // Create overlay for mobile
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    
                    overlay.addEventListener('click', () => {
                        closeMobileSidebar();
                    });
                    
                    setTimeout(() => {
                        overlay.classList.add('active');
                    }, 10);
                } else {
                    // If overlay exists, toggle its active state
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => {
                            if (overlay && overlay.parentNode) {
                                overlay.remove();
                            }
                        }, 300);
                    }
                }
            } else {
                // On desktop, toggle between expanded and collapsed
                sidebar.classList.toggle('collapsed');
                
                // Store preference in localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                // Show/hide nav text
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        // Close mobile sidebar
        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.remove('active');
            
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => {
                    if (overlay.parentNode) {
                        overlay.remove();
                    }
                }, 300);
            }
        }

        // Initialize sidebar when page loads
        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            // Load saved preference from localStorage for desktop
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // On mobile, always start with closed sidebar
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        // Handle window resize for sidebar
        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                // Desktop mode - remove mobile overlay
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                // Load saved preference
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // Mobile mode - always show expanded when visible
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Returns Management page loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button - support multiple button IDs
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Setup desktop toggle button
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            // Add click listeners to sidebar links to close on mobile
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileToggleBtn') || document.getElementById('mobileMenuBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            // Add resize event listener
            window.addEventListener('resize', handleSidebarResize);

            // Setup event listeners
            setupEventListeners();
            
            // Auto-hide alerts after 5 seconds
            document.querySelectorAll('.alert').forEach(function(alert) {
                setTimeout(function() {
                    try {
                        let alertInstance = new bootstrap.Alert(alert);
                        alertInstance.close();
                    } catch(e) {
                        console.log('Alert already closed');
                    }
                }, 5000);
            });
        });

        // Setup event listeners
        function setupEventListeners() {
            // SO Selection Change - Auto Fill Everything using customers table
            const soSelect = document.getElementById('so_id');
            if (soSelect) {
                soSelect.addEventListener('change', function() {
                    const soId = this.value;
                    
                    if (soId) {
                        // Get selected option data
                        const selectedOption = this.options[this.selectedIndex];
                        const customerId = selectedOption.dataset.customerId;
                        const customerName = selectedOption.dataset.customerName;
                        const orderDate = selectedOption.dataset.orderDate;
                        const totalAmount = selectedOption.dataset.total;
                        const soNumber = selectedOption.text.split(' - ')[0];
                        
                        // Fill customer info from customers table
                        const customerIdField = document.getElementById('customer_id');
                        const displayCustomerName = document.getElementById('display_customer_name');
                        const displaySoNumber = document.getElementById('display_so_number');
                        const displayOrderDate = document.getElementById('display_order_date');
                        const displayTotalAmount = document.getElementById('display_total_amount');
                        const orderInfoCard = document.getElementById('orderInfoCard');
                        const productSelectGroup = document.getElementById('productSelectGroup');
                        const productSelect = document.getElementById('item_id');
                        const submitReturnBtn = document.getElementById('submitReturnBtn');
                        
                        if (customerIdField) customerIdField.value = customerId;
                        if (displayCustomerName) displayCustomerName.textContent = customerName;
                        if (displaySoNumber) displaySoNumber.textContent = soNumber;
                        if (displayOrderDate) displayOrderDate.textContent = orderDate;
                        if (displayTotalAmount) displayTotalAmount.textContent = parseFloat(totalAmount).toFixed(2);
                        
                        // Show order info card
                        if (orderInfoCard) orderInfoCard.classList.add('show');
                        
                        // Show loading indicator
                        if (productSelectGroup) productSelectGroup.classList.add('show');
                        if (productSelect) {
                            productSelect.innerHTML = '<option value="">Loading products...</option>';
                            productSelect.disabled = true;
                        }
                        
                        // Disable submit button while loading
                        if (submitReturnBtn) submitReturnBtn.disabled = true;
                        
                        // Fetch order items via AJAX
                        fetch(`<?php echo $_SERVER['PHP_SELF']; ?>?action=get_so_details&so_id=${soId}`)
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('Network response was not ok');
                                }
                                return response.json();
                            })
                            .then(data => {
                                if (data.success) {
                                    // Populate product dropdown
                                    if (productSelect) {
                                        productSelect.innerHTML = '<option value="">-- Select Product from Order --</option>';
                                        
                                        if (data.items && data.items.length > 0) {
                                            data.items.forEach(item => {
                                                const option = document.createElement('option');
                                                option.value = item.item_id;
                                                option.textContent = `${item.item_code} - ${item.item_name} (Ordered: ${item.quantity_ordered || item.quantity || 0}, Price: ₱${parseFloat(item.unit_price).toFixed(2)})`;
                                                option.dataset.price = item.unit_price;
                                                option.dataset.maxQty = item.quantity_ordered || item.quantity || 0;
                                                productSelect.appendChild(option);
                                            });
                                            
                                            // Enable form fields
                                            productSelect.disabled = false;
                                            const returnQty = document.getElementById('return_qty');
                                            const returnReason = document.getElementById('return_reason');
                                            if (returnQty) returnQty.disabled = false;
                                            if (returnReason) returnReason.disabled = false;
                                        } else {
                                            productSelect.innerHTML = '<option value="">No products found in this order</option>';
                                            productSelect.disabled = true;
                                        }
                                    }
                                } else {
                                    if (productSelect) {
                                        productSelect.innerHTML = '<option value="">Error loading products</option>';
                                        productSelect.disabled = true;
                                    }
                                    alert('Error: ' + (data.message || 'Failed to load order details'));
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching order details:', error);
                                if (productSelect) {
                                    productSelect.innerHTML = '<option value="">Error loading products</option>';
                                    productSelect.disabled = true;
                                }
                                alert('Error loading order details. Please try again.');
                            });
                    } else {
                        // Reset form
                        resetReturnForm();
                    }
                });
            }

            // Product Selection Change
            const itemSelect = document.getElementById('item_id');
            if (itemSelect) {
                itemSelect.addEventListener('change', function() {
                    if (this.value && this.options[this.selectedIndex]?.dataset?.maxQty) {
                        const selectedOption = this.options[this.selectedIndex];
                        const maxQty = parseInt(selectedOption.dataset.maxQty) || 0;
                        const price = parseFloat(selectedOption.dataset.price) || 0;
                        
                        // Set max quantity
                        const qtyInput = document.getElementById('return_qty');
                        const maxQtyHint = document.getElementById('max_qty_hint');
                        const submitReturnBtn = document.getElementById('submitReturnBtn');
                        
                        if (qtyInput) {
                            qtyInput.max = maxQty;
                            qtyInput.placeholder = `Max: ${maxQty}`;
                        }
                        
                        // Show max quantity hint
                        if (maxQtyHint) maxQtyHint.innerHTML = `<span class="text-primary">Maximum return quantity: ${maxQty}</span>`;
                        
                        // Clear previous quantity
                        if (qtyInput) qtyInput.value = '';
                        
                        // Enable submit button
                        if (submitReturnBtn) submitReturnBtn.disabled = false;
                        
                        // Reset refund
                        const estimatedRefund = document.getElementById('estimated_refund');
                        if (estimatedRefund) estimatedRefund.value = '0.00';
                    } else {
                        const submitReturnBtn = document.getElementById('submitReturnBtn');
                        const maxQtyHint = document.getElementById('max_qty_hint');
                        if (submitReturnBtn) submitReturnBtn.disabled = true;
                        if (maxQtyHint) maxQtyHint.innerHTML = '';
                    }
                });
            }

            // Quantity Input Change
            const returnQty = document.getElementById('return_qty');
            if (returnQty) {
                returnQty.addEventListener('input', calculateRefund);
            }

            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(filter) ? '' : 'none';
                    });
                });
            }

            // Status filter
            const statusFilter = document.getElementById('statusFilter');
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        if (row.cells.length > 1) {
                            const status = row.cells[7]?.textContent.toLowerCase() || '';
                            row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
                        }
                    });
                });
            }

            // Reset form when modal is closed
            const addReturnModal = document.getElementById('addReturnModal');
            if (addReturnModal) {
                addReturnModal.addEventListener('hidden.bs.modal', function() {
                    const soSelect = document.getElementById('so_id');
                    if (soSelect) soSelect.value = '';
                    resetReturnForm();
                });
            }

            // Prevent double form submission
            const addReturnForm = document.getElementById('addReturnForm');
            if (addReturnForm) {
                addReturnForm.addEventListener('submit', function() {
                    const submitBtn = document.getElementById('submitReturnBtn');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting...';
                    }
                });
            }
        }

        // Calculate estimated refund amount
        function calculateRefund() {
            const productSelect = document.getElementById('item_id');
            const quantity = parseInt(document.getElementById('return_qty')?.value) || 0;
            
            if (productSelect && productSelect.selectedIndex > 0 && productSelect.options[productSelect.selectedIndex]?.dataset?.price) {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const price = parseFloat(selectedOption.dataset.price) || 0;
                const maxQty = parseInt(selectedOption.dataset.maxQty) || 0;
                
                // Validate quantity
                if (quantity > maxQty) {
                    const returnQty = document.getElementById('return_qty');
                    const estimatedRefund = document.getElementById('estimated_refund');
                    if (returnQty) returnQty.value = maxQty;
                    if (estimatedRefund) estimatedRefund.value = (price * maxQty).toFixed(2);
                    alert(`Maximum return quantity is ${maxQty}`);
                } else {
                    const refund = price * quantity;
                    const estimatedRefund = document.getElementById('estimated_refund');
                    if (estimatedRefund) estimatedRefund.value = refund.toFixed(2);
                }
            }
        }

        // Reset return form
        function resetReturnForm() {
            const orderInfoCard = document.getElementById('orderInfoCard');
            const productSelectGroup = document.getElementById('productSelectGroup');
            const customerId = document.getElementById('customer_id');
            const displayCustomerName = document.getElementById('display_customer_name');
            const displaySoNumber = document.getElementById('display_so_number');
            const displayOrderDate = document.getElementById('display_order_date');
            const displayTotalAmount = document.getElementById('display_total_amount');
            const productSelect = document.getElementById('item_id');
            const returnQty = document.getElementById('return_qty');
            const returnReason = document.getElementById('return_reason');
            const estimatedRefund = document.getElementById('estimated_refund');
            const maxQtyHint = document.getElementById('max_qty_hint');
            const submitReturnBtn = document.getElementById('submitReturnBtn');
            
            if (orderInfoCard) orderInfoCard.classList.remove('show');
            if (productSelectGroup) productSelectGroup.classList.remove('show');
            
            if (customerId) customerId.value = '';
            if (displayCustomerName) displayCustomerName.textContent = '';
            if (displaySoNumber) displaySoNumber.textContent = '';
            if (displayOrderDate) displayOrderDate.textContent = '';
            if (displayTotalAmount) displayTotalAmount.textContent = '';
            
            if (productSelect) {
                productSelect.innerHTML = '<option value="">-- Select Product from Order --</option>';
                productSelect.disabled = true;
            }
            
            if (returnQty) {
                returnQty.value = '';
                returnQty.disabled = true;
            }
            
            if (returnReason) {
                returnReason.value = '';
                returnReason.disabled = true;
            }
            
            if (estimatedRefund) estimatedRefund.value = '0.00';
            if (maxQtyHint) maxQtyHint.innerHTML = '';
            if (submitReturnBtn) submitReturnBtn.disabled = true;
        }

        // Update return status - Kept for AJAX functionality but not used in UI
        function updateStatus(rmrId, newStatus) {
            if (confirm('Are you sure you want to update this return status to ' + newStatus + '?')) {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('rmr_id', rmrId);
                formData.append('status', newStatus);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error updating status: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating status. Please try again.');
                });
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + B to toggle sidebar (desktop only)
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            // Escape to close sidebar on mobile
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            // Ctrl + F to focus search
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            // Ctrl + N to add new return
            else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                const addButton = document.querySelector('[data-bs-target="#addReturnModal"]');
                if (addButton) {
                    addButton.click();
                }
            }
            // Ctrl + R to reset form
            else if (e.ctrlKey && e.key === 'r' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const soSelect = document.getElementById('so_id');
                if (soSelect) {
                    soSelect.value = '';
                    resetReturnForm();
                }
            }
        });
    </script>
</body>
</html>