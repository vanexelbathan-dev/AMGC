<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Handle Add Return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_return') {
    $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $item_id = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
    $return_quantity = !empty($_POST['return_qty']) ? (int)$_POST['return_qty'] : 0;
    $reason = isset($_POST['return_reason']) ? trim($_POST['return_reason']) : 'other';
    $status = isset($_POST['return_status']) ? trim($_POST['return_status']) : 'pending';
    
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
    $so_id = 1; // Default, could be improved with order selection
    
    if ($customer_id && $item_id && $return_quantity > 0) {
        $sql = "INSERT INTO rmr_requests (rmr_number, so_id, customer_id, item_id, return_quantity, return_reason, reason_details, rmr_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $reason_details = 'Return via sales interface';
        $stmt->bind_param('siiiisss', $rmr_number, $so_id, $customer_id, $item_id, $return_quantity, $reason_enum, $reason_details, $status);
        
        if ($stmt->execute()) {
            $success = 'Return request added successfully!';
        } else {
            $error = 'Error adding return: ' . $stmt->error;
        }
    } else {
        $error = 'Please fill in all required fields';
    }
}

// Get all returns
$returns = [];
$query = "SELECT rmr.*, c.customer_name, i.item_name, i.item_code
          FROM rmr_requests rmr
          JOIN customers c ON rmr.customer_id = c.customer_id
          JOIN items i ON rmr.item_id = i.item_id
          ORDER BY rmr.created_at DESC";
$result = $conn->query($query);
if ($result) {
    $returns = $result->fetch_all(MYSQLI_ASSOC);
}

// Get stats
$pending = 0;
$approved = 0;
$rejected = 0;
$total_refunds = 0;

$stats_query = "SELECT 
                SUM(CASE WHEN rmr_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN rmr_status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN rmr_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                COALESCE(SUM(CASE WHEN rmr_status = 'approved' THEN return_quantity * (SELECT unit_price FROM items WHERE item_id = rmr_requests.item_id) ELSE 0 END), 0) as total_refunds
                FROM rmr_requests";
$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $pending = $stats['pending'] ?? 0;
    $approved = $stats['approved'] ?? 0;
    $rejected = $stats['rejected'] ?? 0;
    $total_refunds = $stats['total_refunds'] ?? 0;
}

// Get customers and items for dropdowns
$customers_result = $conn->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name");
$customers = $customers_result ? $customers_result->fetch_all(MYSQLI_ASSOC) : [];

$items_result = $conn->query("SELECT item_id, item_code, item_name, unit_price FROM items WHERE status = 'active' ORDER BY item_code");
$items_list = $items_result ? $items_result->fetch_all(MYSQLI_ASSOC) : [];

$error = '';
$success = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returned Merchandise - Sales</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Mobile responsive adjustments ONLY - same as warehouse.php */
        @media (max-width: 768px) {
            .stat-card {
                padding: 12px;
                min-height: 85px;
                margin-bottom: 8px;
            }
            
            .stat-icon {
                font-size: 2rem;
                margin-right: 12px;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .stat-label {
                font-size: 0.8rem;
            }
            
            /* Make cards 2 columns on mobile */
            .col-md-3 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
            
            .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
            
            .mb-3 {
                margin-bottom: 8px !important;
            }
        }
        
        /* Extra small devices (phones, less than 576px) */
        @media (max-width: 576px) {
            .stat-card {
                min-height: 80px;
                padding: 10px;
            }
            
            .stat-icon {
                font-size: 1.8rem;
                margin-right: 10px;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .stat-label {
                font-size: 0.75rem;
            }
            
            .col-md-3 {
                width: 50%;
                padding-left: 6px;
                padding-right: 6px;
            }
            
            .row.g-3 {
                margin-left: -6px;
                margin-right: -6px;
            }
        }
    </style>
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
                    <h2><i class="bi bi-arrow-counterclockwise me-2"></i>Returned Merchandise Requests</h2>
                    <p>Process and manage merchandise returns</p>
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

    <!-- Pending Requests -->
    <div class="col-md-3 mb-3">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $pending; ?></div>
                <div class="stat-label">Pending Requests</div>
            </div>
        </div>
    </div>

    <!-- Approved -->
    <div class="col-md-3 mb-3">
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

    <!-- Rejected -->
    <div class="col-md-3 mb-3">
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

    <!-- Total Refunds -->
    <div class="col-md-3 mb-3">
        <div class="stat-card inventory">
            <div class="stat-icon">
                <i class="bi bi-cash-coin"></i>
            </div>
            <div>
                <div class="stat-value">₱<?php echo number_format($total_refunds, 2); ?></div>
                <div class="stat-label">Total Refunds</div>
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
                                <option value="Pending">Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Completed">Completed</option>
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
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Reason</th>
                                <th>Request Date</th>
                                <th>Status</th>
                                <th>Refund Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($returns) > 0): ?>
                                <?php foreach ($returns as $return): ?>
                                    <?php
                                        $status_badge = match($return['rmr_status']) {
                                            'pending' => 'bg-warning',
                                            'approved' => 'bg-success',
                                            'rejected' => 'bg-danger',
                                            'processing' => 'bg-info',
                                            default => 'bg-light'
                                        };
                                        $status_label = ucfirst($return['rmr_status']);
                                        $refund_amount = $return['return_quantity'] * ($return['unit_price'] ?? 0);
                                    ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($return['rmr_number']); ?></span></td>
                                    <td><?php echo htmlspecialchars($return['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($return['item_name']); ?></td>
                                    <td><?php echo $return['return_quantity']; ?></td>
                                    <td><?php echo htmlspecialchars($return['return_reason']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($return['created_at'])); ?></td>
                                    <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_label; ?></span></td>
                                    <td>₱<?php echo number_format($refund_amount, 2); ?></td>
                                    <td>
                                        <?php if ($return['rmr_status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-success" title="Approve" onclick="updateStatus(this, '<?php echo $return['rmr_id']; ?>', 'approved')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" title="Reject" onclick="updateStatus(this, '<?php echo $return['rmr_id']; ?>', 'rejected')">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-info" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No returns found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Return Modal -->
    <div class="modal fade" id="addReturnModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Return Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addReturnForm" method="POST">
                        <input type="hidden" name="action" value="add_return">
                        <div class="mb-3">
                            <label class="form-label">Customer *</label>
                            <select class="form-select" name="customer_id" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['customer_id']; ?>"><?php echo htmlspecialchars($customer['customer_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Product *</label>
                            <select class="form-select" name="item_id" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($items_list as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>"><?php echo htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" class="form-control" name="return_qty" required min="1" placeholder="Qty">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason for Return *</label>
                            <select class="form-select" name="return_reason" required>
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
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="return_status">
                                <option value="pending" selected>Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="processing">Processing</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('addReturnForm').submit()">Add Return</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Update return status (placeholder for future implementation)
        function updateStatus(button, rmrId, newStatus) {
            console.log('Update return ' + rmrId + ' to status: ' + newStatus);
            // This would typically send an AJAX request to update the database
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Status filter
        document.getElementById('statusFilter').addEventListener('change', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const status = row.cells[6].textContent.toLowerCase();
                row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
            });
        });

        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(function(alert) {
            setTimeout(function() {
                let alertInstance = new bootstrap.Alert(alert);
                alertInstance.close();
            }, 5000);
        });
    </script>
</body>
</html>
