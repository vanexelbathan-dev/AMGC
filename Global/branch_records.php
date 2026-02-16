<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get filter parameters
$branch_id = isset($_GET['branch']) ? $_GET['branch'] : '';
$record_type = isset($_GET['type']) ? $_GET['type'] : '';
$date_from = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['dateTo']) ? $_GET['dateTo'] : date('Y-m-d');

// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $records = [];

    // Helper function to get branch name
    function getBranchName($conn, $branch_id) {
        if (!$branch_id) return 'N/A';
        $sql = "SELECT branch_name FROM branches WHERE branch_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['branch_name'] ?? 'Unknown Branch';
    }

    // Helper function to get user name
    function getUserName($conn, $user_id) {
        if (!$user_id) return 'N/A';
        $sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['name'] ?? 'N/A';
    }

    // Load Sales Orders
    if (empty($record_type) || $record_type == 'sales_order') {
        $sql = "SELECT so.*, b.branch_name 
                FROM sales_orders so
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                WHERE DATE(so.order_date) BETWEEN ? AND ?";
        $params = [$date_from, $date_to];
        $types = "ss";
        
        if (!empty($branch_id)) {
            $sql .= " AND so.branch_id = ?";
            $params[] = $branch_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY so.order_date DESC LIMIT 500";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $branch_name = $row['branch_name'] ?? getBranchName($conn, $row['branch_id']);
            $manager_name = getUserName($conn, $row['created_by']);
            
            $records[] = [
                'id' => $row['so_id'],
                'source' => 'sales_orders',
                'record_number' => $row['so_number'],
                'branch' => $branch_name,
                'branch_id' => $row['branch_id'],
                'manager' => $manager_name,
                'type' => 'Sales Order',
                'description' => 'Sales Order #' . $row['so_number'],
                'amount' => $row['total_amount'],
                'date' => $row['order_date'],
                'status' => $row['order_status']
            ];
        }
    }

    // Load Purchase Orders
    if (empty($record_type) || $record_type == 'purchase_order') {
        $sql = "SELECT po.*, b.branch_name 
                FROM purchase_orders po
                LEFT JOIN branches b ON po.branch_id = b.branch_id
                WHERE DATE(po.order_date) BETWEEN ? AND ?";
        $params = [$date_from, $date_to];
        $types = "ss";
        
        if (!empty($branch_id)) {
            $sql .= " AND po.branch_id = ?";
            $params[] = $branch_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY po.order_date DESC LIMIT 500";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $branch_name = $row['branch_name'] ?? getBranchName($conn, $row['branch_id']);
            $manager_name = getUserName($conn, $row['created_by']);
            
            $records[] = [
                'id' => $row['po_id'],
                'source' => 'purchase_orders',
                'record_number' => $row['po_number'],
                'branch' => $branch_name,
                'branch_id' => $row['branch_id'],
                'manager' => $manager_name,
                'type' => 'Purchase Order',
                'description' => 'Purchase Order #' . $row['po_number'] . ($row['supplier_name'] ? ' - ' . $row['supplier_name'] : ''),
                'amount' => $row['total_amount'],
                'date' => $row['order_date'],
                'status' => $row['po_status']
            ];
        }
    }

    // Load Pick Lists
    if (empty($record_type) || $record_type == 'pick_list') {
        $sql = "SELECT pl.*, b.branch_name, so.so_number, d.driver_name
                FROM pick_lists pl
                LEFT JOIN branches b ON pl.branch_id = b.branch_id
                LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                WHERE DATE(pl.created_at) BETWEEN ? AND ?";
        $params = [$date_from, $date_to];
        $types = "ss";
        
        if (!empty($branch_id)) {
            $sql .= " AND pl.branch_id = ?";
            $params[] = $branch_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY pl.created_at DESC LIMIT 500";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $branch_name = $row['branch_name'] ?? getBranchName($conn, $row['branch_id']);
            $manager_name = $row['driver_name'] ?? 'N/A';
            
            $records[] = [
                'id' => $row['pick_list_id'],
                'source' => 'pick_lists',
                'record_number' => $row['pick_list_number'],
                'branch' => $branch_name,
                'branch_id' => $row['branch_id'],
                'manager' => $manager_name,
                'type' => 'Pick List',
                'description' => 'Pick List #' . $row['pick_list_number'] . ' for SO #' . ($row['so_number'] ?? 'N/A'),
                'amount' => 0,
                'date' => $row['created_at'],
                'status' => $row['pick_status']
            ];
        }
    }

    // Load RMR Requests
    if (empty($record_type) || $record_type == 'rmr') {
        $sql = "SELECT rmr.*, b.branch_name, c.customer_name, i.item_name
                FROM rmr_requests rmr
                LEFT JOIN branches b ON rmr.branch_id = b.branch_id
                LEFT JOIN customers c ON rmr.customer_id = c.customer_id
                LEFT JOIN items i ON rmr.item_id = i.item_id
                WHERE DATE(rmr.created_at) BETWEEN ? AND ?";
        $params = [$date_from, $date_to];
        $types = "ss";
        
        if (!empty($branch_id)) {
            $sql .= " AND rmr.branch_id = ?";
            $params[] = $branch_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY rmr.created_at DESC LIMIT 500";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $branch_name = $row['branch_name'] ?? getBranchName($conn, $row['branch_id']);
            $manager_name = getUserName($conn, $row['received_by']);
            
            $records[] = [
                'id' => $row['rmr_id'],
                'source' => 'rmr_requests',
                'record_number' => $row['rmr_number'],
                'branch' => $branch_name,
                'branch_id' => $row['branch_id'],
                'manager' => $manager_name,
                'type' => 'RMR Request',
                'description' => 'RMR #' . $row['rmr_number'] . ' - ' . ($row['customer_name'] ?? 'Unknown'),
                'amount' => 0,
                'date' => $row['created_at'],
                'status' => $row['rmr_status']
            ];
        }
    }

    // Sort records by date (newest first)
    usort($records, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    echo json_encode([
        'success' => true,
        'records' => $records
    ]);
    exit;
}

// Handle AJAX request for record details
if (isset($_GET['ajax_details']) && isset($_GET['id']) && isset($_GET['source'])) {
    header('Content-Type: application/json');
    
    $id = intval($_GET['id']);
    $source = $_GET['source'];
    $record = null;

    // Get record details based on source
    switch ($source) {
        case 'sales_orders':
            $sql = "SELECT so.*, b.branch_name, c.customer_name,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                    FROM sales_orders so
                    LEFT JOIN branches b ON so.branch_id = b.branch_id
                    LEFT JOIN customers c ON so.customer_id = c.customer_id
                    LEFT JOIN users u ON so.created_by = u.user_id
                    WHERE so.so_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Get items
                $items_sql = "SELECT soi.*, i.item_name, i.item_code
                             FROM sales_order_items soi
                             LEFT JOIN items i ON soi.item_id = i.item_id
                             WHERE soi.so_id = ?";
                $items_stmt = $conn->prepare($items_sql);
                $items_stmt->bind_param("i", $id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                $items_list = [];
                while ($item = $items_result->fetch_assoc()) {
                    $items_list[] = $item['quantity_ordered'] . ' x ' . ($item['item_name'] ?? 'Item #' . $item['item_id']);
                }
                
                $record = [
                    'record_number' => $row['so_number'],
                    'branch' => $row['branch_name'] ?? 'N/A',
                    'manager' => $row['created_by_name'] ?? 'N/A',
                    'type' => 'Sales Order',
                    'description' => 'Sales Order #' . $row['so_number'],
                    'amount' => $row['total_amount'],
                    'date' => $row['order_date'],
                    'status' => $row['order_status'],
                    'customer' => $row['customer_name'] ?? 'N/A',
                    'items' => implode(', ', $items_list)
                ];
            }
            break;
            
        case 'purchase_orders':
            $sql = "SELECT po.*, b.branch_name,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                    FROM purchase_orders po
                    LEFT JOIN branches b ON po.branch_id = b.branch_id
                    LEFT JOIN users u ON po.created_by = u.user_id
                    WHERE po.po_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $record = [
                    'record_number' => $row['po_number'],
                    'branch' => $row['branch_name'] ?? 'N/A',
                    'manager' => $row['created_by_name'] ?? 'N/A',
                    'type' => 'Purchase Order',
                    'description' => 'Purchase Order #' . $row['po_number'],
                    'amount' => $row['total_amount'],
                    'date' => $row['order_date'],
                    'status' => $row['po_status'],
                    'supplier' => $row['supplier_name'] ?? 'N/A',
                    'expected_delivery' => $row['expected_delivery']
                ];
            }
            break;
            
        case 'pick_lists':
            $sql = "SELECT pl.*, b.branch_name, so.so_number, d.driver_name,
                    CONCAT(u1.first_name, ' ', u1.last_name) as picked_by_name,
                    CONCAT(u2.first_name, ' ', u2.last_name) as verified_by_name
                    FROM pick_lists pl
                    LEFT JOIN branches b ON pl.branch_id = b.branch_id
                    LEFT JOIN sales_orders so ON pl.so_id = so.so_id
                    LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                    LEFT JOIN users u1 ON pl.picked_by = u1.user_id
                    LEFT JOIN users u2 ON pl.verified_by = u2.user_id
                    WHERE pl.pick_list_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $record = [
                    'record_number' => $row['pick_list_number'],
                    'branch' => $row['branch_name'] ?? 'N/A',
                    'manager' => $row['picked_by_name'] ?? $row['driver_name'] ?? 'N/A',
                    'type' => 'Pick List',
                    'description' => 'Pick List #' . $row['pick_list_number'],
                    'amount' => 0,
                    'date' => $row['created_at'],
                    'status' => $row['pick_status'],
                    'so_number' => $row['so_number'] ?? 'N/A',
                    'driver' => $row['driver_name'] ?? 'N/A',
                    'pick_date' => $row['pick_date'],
                    'verified_by' => $row['verified_by_name'] ?? 'N/A'
                ];
            }
            break;
            
        case 'rmr_requests':
            $sql = "SELECT rmr.*, b.branch_name, c.customer_name, i.item_name, i.item_code,
                    CONCAT(u.first_name, ' ', u.last_name) as received_by_name,
                    so.so_number
                    FROM rmr_requests rmr
                    LEFT JOIN branches b ON rmr.branch_id = b.branch_id
                    LEFT JOIN customers c ON rmr.customer_id = c.customer_id
                    LEFT JOIN items i ON rmr.item_id = i.item_id
                    LEFT JOIN users u ON rmr.received_by = u.user_id
                    LEFT JOIN sales_orders so ON rmr.so_id = so.so_id
                    WHERE rmr.rmr_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $record = [
                    'record_number' => $row['rmr_number'],
                    'branch' => $row['branch_name'] ?? 'N/A',
                    'manager' => $row['received_by_name'] ?? 'N/A',
                    'type' => 'RMR Request',
                    'description' => 'RMR #' . $row['rmr_number'],
                    'amount' => 0,
                    'date' => $row['created_at'],
                    'status' => $row['rmr_status'],
                    'customer' => $row['customer_name'] ?? 'N/A',
                    'item' => ($row['item_name'] ?? 'Item #' . $row['item_id']),
                    'return_quantity' => $row['return_quantity'],
                    'return_reason' => $row['return_reason'],
                    'so_number' => $row['so_number'] ?? 'N/A',
                    'disposition_type' => $row['disposition_type'] ?? 'N/A'
                ];
            }
            break;
    }

    if ($record) {
        echo json_encode(['success' => true, 'record' => $record]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
    }
    exit;
}

// Get branches for filter dropdown
$branches_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// Get statistics
$active_branches_sql = "SELECT COUNT(*) as count FROM branches WHERE status = 'active'";
$active_branches_result = $conn->query($active_branches_sql);
$active_branches = $active_branches_result->fetch_assoc()['count'] ?? 0;

$total_records_sql = "SELECT 
                        (SELECT COUNT(*) FROM sales_orders) +
                        (SELECT COUNT(*) FROM purchase_orders) +
                        (SELECT COUNT(*) FROM pick_lists) +
                        (SELECT COUNT(*) FROM rmr_requests) as total";
$total_records_result = $conn->query($total_records_sql);
$total_records = $total_records_result->fetch_assoc()['total'] ?? 0;

$total_transactions_sql = "SELECT 
                            (SELECT IFNULL(SUM(total_amount), 0) FROM sales_orders WHERE order_status != 'cancelled') +
                            (SELECT IFNULL(SUM(total_amount), 0) FROM purchase_orders WHERE po_status != 'cancelled') as total";
$total_transactions_result = $conn->query($total_transactions_sql);
$total_transactions = $total_transactions_result->fetch_assoc()['total'] ?? 0;

// Get user info from session
$user_name = $_SESSION['user_name'] ?? 'Quality Control';
$user_role = $_SESSION['user_role'] ?? 'QC Officer';
$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $user_initials .= strtoupper(substr($part, 0, 1));
        }
    }
}
if (empty($user_initials)) {
    $user_initials = 'AD';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Branch Records</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
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
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php">
                            <i class="bi bi-graph-up"></i>
                            <span class="nav-text">Sales Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="branch_records.php">
                            <i class="bi bi-file-text"></i>
                            <span class="nav-text">Branch Records</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="all_items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">All Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role-sidebar"><?php echo htmlspecialchars($user_role); ?></span>
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
            <div id="recordsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Branch Records</h2>
                        <p>View all activities and transactions from branch managers</p>
                    </div>
                </div>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalRecords"><?php echo number_format($total_records); ?></div>
                            <div class="stat-label">Total Records</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="totalTransactions">₱<?php echo number_format($total_transactions, 2); ?></div>
                            <div class="stat-label">Total Transactions</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="activeBranches"><?php echo number_format($active_branches); ?></div>
                            <div class="stat-label">Active Branches</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Records</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="form-label">Branch</label>
                                    <select class="form-select" id="branchFilter" onchange="loadRecords()">
                                        <option value="">All Branches</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Record Type</label>
                                    <select class="form-select" id="recordTypeFilter" onchange="loadRecords()">
                                        <option value="">All Types</option>
                                        <option value="sales_order">Sales Order</option>
                                        <option value="purchase_order">Purchase Order</option>
                                        <option value="pick_list">Pick List</option>
                                        <option value="rmr">RMR Request</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" id="dateFromFilter" value="<?php echo $date_from; ?>" onchange="loadRecords()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" id="dateToFilter" value="<?php echo $date_to; ?>" onchange="loadRecords()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Branch Activity Log</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th style="display: none;">ID</th>
                                    <th>Branch</th>
                                    <th>Manager</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="recordsTable">
                                <tr>
                                    <td colspan="8" class="text-center py-4">Loading records...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Details Modal -->
    <div class="modal fade" id="recordModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="recordDetails">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                
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
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

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

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        async function loadRecords() {
            const tbody = document.getElementById('recordsTable');
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading records...</p></td></tr>';
            
            try {
                const branch = document.getElementById('branchFilter').value;
                const recordType = document.getElementById('recordTypeFilter').value;
                const dateFrom = document.getElementById('dateFromFilter').value;
                const dateTo = document.getElementById('dateToFilter').value;

                const params = new URLSearchParams({
                    ajax: 1,
                    branch: branch,
                    type: recordType,
                    dateFrom: dateFrom,
                    dateTo: dateTo
                });

                const response = await fetch('branch_records.php?' + params);
                const data = await response.json();
                
                if (data.success && data.records.length > 0) {
                    displayRecords(data.records);
                } else {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No records found</td></tr>';
                }
            } catch (error) {
                console.error('Error loading records:', error);
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Error loading records</td></tr>';
            }
        }

        function displayRecords(records) {
            const tbody = document.getElementById('recordsTable');
            
            tbody.innerHTML = records.map(record => {
                let statusBadge = 'bg-secondary';
                let statusText = record.status;
                
                const completed = ['completed', 'delivered', 'approved', 'received'];
                const pending = ['pending', 'draft', 'planned', 'open', 'processing'];
                const cancelled = ['cancelled', 'rejected'];
                
                if (completed.includes(record.status)) {
                    statusBadge = 'bg-success';
                } else if (pending.includes(record.status)) {
                    statusBadge = 'bg-warning';
                } else if (cancelled.includes(record.status)) {
                    statusBadge = 'bg-danger';
                }
                
                return `
                    <tr>
                        <td style="display: none;">${record.id}</td>
                        <td><strong>${escapeHtml(record.branch)}</strong></td>
                        <td>${escapeHtml(record.manager || 'N/A')}</td>
                        <td><span class="badge bg-info">${escapeHtml(record.type)}</span></td>
                        <td>${escapeHtml(record.description)}</td>
                        <td>₱${parseFloat(record.amount || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</td>
                        <td>${new Date(record.date).toLocaleDateString()}</td>
                        <td>
                            <span class="badge ${statusBadge}">
                                ${escapeHtml(statusText)}
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="viewRecord(${record.id}, '${record.source}')">
                                <i class="bi bi-eye"></i> View
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function viewRecord(id, source) {
            const modal = new bootstrap.Modal(document.getElementById('recordModal'));
            const details = document.getElementById('recordDetails');
            details.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading record details...</p></div>';
            modal.show();
            
            fetch(`branch_records.php?ajax_details=1&id=${id}&source=${source}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const record = data.record;
                        let statusBadge = 'bg-secondary';
                        
                        const completed = ['completed', 'delivered', 'approved', 'received'];
                        const pending = ['pending', 'draft', 'planned', 'open', 'processing'];
                        const cancelled = ['cancelled', 'rejected'];
                        
                        if (completed.includes(record.status)) {
                            statusBadge = 'bg-success';
                        } else if (pending.includes(record.status)) {
                            statusBadge = 'bg-warning';
                        } else if (cancelled.includes(record.status)) {
                            statusBadge = 'bg-danger';
                        }
                        
                        let additionalDetails = '';
                        if (record.type === 'Sales Order' && record.items) {
                            additionalDetails = `
                                <dt class="col-sm-4">Items:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.items)}</dd>
                                <dt class="col-sm-4">Customer:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.customer || 'N/A')}</dd>
                            `;
                        } else if (record.type === 'Purchase Order' && record.supplier) {
                            additionalDetails = `
                                <dt class="col-sm-4">Supplier:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.supplier)}</dd>
                                <dt class="col-sm-4">Expected Delivery:</dt>
                                <dd class="col-sm-8">${record.expected_delivery ? new Date(record.expected_delivery).toLocaleDateString() : 'N/A'}</dd>
                            `;
                        } else if (record.type === 'RMR Request') {
                            additionalDetails = `
                                <dt class="col-sm-4">Customer:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.customer || 'N/A')}</dd>
                                <dt class="col-sm-4">Item:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.item || 'N/A')}</dd>
                                <dt class="col-sm-4">Return Quantity:</dt>
                                <dd class="col-sm-8">${record.return_quantity || 0}</dd>
                                <dt class="col-sm-4">Return Reason:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.return_reason || 'N/A')}</dd>
                                <dt class="col-sm-4">SO Number:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.so_number || 'N/A')}</dd>
                            `;
                        } else if (record.type === 'Pick List') {
                            additionalDetails = `
                                <dt class="col-sm-4">Sales Order:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.so_number || 'N/A')}</dd>
                                <dt class="col-sm-4">Driver:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.driver || 'N/A')}</dd>
                                <dt class="col-sm-4">Pick Date:</dt>
                                <dd class="col-sm-8">${record.pick_date ? new Date(record.pick_date).toLocaleDateString() : 'N/A'}</dd>
                                <dt class="col-sm-4">Verified By:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.verified_by || 'N/A')}</dd>
                            `;
                        }
                        
                        details.innerHTML = `
                            <dl class="row">
                                <dt class="col-sm-4">Record Number:</dt>
                                <dd class="col-sm-8"><strong>${escapeHtml(record.record_number)}</strong></dd>
                                
                                <dt class="col-sm-4">Branch:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.branch)}</dd>
                                
                                <dt class="col-sm-4">Manager:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.manager || 'N/A')}</dd>
                                
                                <dt class="col-sm-4">Type:</dt>
                                <dd class="col-sm-8"><span class="badge bg-info">${escapeHtml(record.type)}</span></dd>
                                
                                <dt class="col-sm-4">Description:</dt>
                                <dd class="col-sm-8">${escapeHtml(record.description)}</dd>
                                
                                <dt class="col-sm-4">Amount:</dt>
                                <dd class="col-sm-8">₱${parseFloat(record.amount || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</dd>
                                
                                ${additionalDetails}
                                
                                <dt class="col-sm-4">Date Created:</dt>
                                <dd class="col-sm-8">${new Date(record.date).toLocaleString()}</dd>
                                
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8"><span class="badge ${statusBadge}">${escapeHtml(record.status)}</span></dd>
                            </dl>
                        `;
                    } else {
                        details.innerHTML = '<p class="text-danger">Failed to load record details.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading record details:', error);
                    details.innerHTML = '<p class="text-danger">Error loading record details.</p>';
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileToggleBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            window.addEventListener('resize', handleSidebarResize);
            
            loadRecords();
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                loadRecords();
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>