<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// ------------------------------------------------------------
// Check if branch_id column exists in customers table
// ------------------------------------------------------------
$branch_column_exists = false;
$check = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check && $check->num_rows > 0) {
    $branch_column_exists = true;
}

// ------------------------------------------------------------
// Helper to add branch filter to customer query
// ------------------------------------------------------------
function customerBranchFilter() {
    global $view_all_branches, $branch_id, $branch_column_exists;
    if (!$branch_column_exists || $view_all_branches) return '';
    return " AND branch_id = " . intval($branch_id);
}

// ------------------------------------------------------------
// Get active customers for dropdown
// ------------------------------------------------------------
$customers_query = "SELECT customer_id, customer_name 
                    FROM customers 
                    WHERE status = 'active' 
                    " . customerBranchFilter() . "
                    ORDER BY customer_name ASC";
$customers_result = $conn->query($customers_query);
$customers = [];
if ($customers_result) {
    $customers = $customers_result->fetch_all(MYSQLI_ASSOC);
}

// ------------------------------------------------------------
// Handle form submission
// ------------------------------------------------------------
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_request') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $request_type = $_POST['request_type'] ?? '';
    $credit_limit = !empty($_POST['credit_limit']) ? (float)$_POST['credit_limit'] : null;
    $discount_percent = !empty($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : null;
    $reason = trim($_POST['reason'] ?? '');

    // Basic validation
    if ($customer_id <= 0) {
        $error_message = 'Please select a customer.';
    } elseif (!in_array($request_type, ['credit', 'discount', 'both'])) {
        $error_message = 'Invalid request type.';
    } elseif (($request_type === 'credit' || $request_type === 'both') && ($credit_limit === null || $credit_limit <= 0)) {
        $error_message = 'Please enter a valid credit limit.';
    } elseif (($request_type === 'discount' || $request_type === 'both') && ($discount_percent === null || $discount_percent <= 0 || $discount_percent > 100)) {
        $error_message = 'Please enter a valid discount percentage (1-100).';
    } elseif (empty($reason)) {
        $error_message = 'Please provide a reason for the request.';
    } else {
        // Verify customer belongs to agent's branch (if column exists and not admin)
        if ($branch_column_exists && !$view_all_branches) {
            $check_cust = $conn->prepare("SELECT customer_id FROM customers WHERE customer_id = ? AND branch_id = ?");
            $check_cust->bind_param('ii', $customer_id, $branch_id);
            $check_cust->execute();
            $cust_result = $check_cust->get_result();
            if ($cust_result->num_rows === 0) {
                $error_message = 'Selected customer does not belong to your branch.';
            }
        }

        if (empty($error_message)) {
            // Insert request
            $insert_sql = "INSERT INTO credit_discount_requests 
                           (customer_id, agent_id, request_type, requested_credit_limit, requested_discount_percent, reason, status)
                           VALUES (?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param('iisdds', $customer_id, $user_id, $request_type, $credit_limit, $discount_percent, $reason);
            if ($stmt->execute()) {
                $success_message = 'Request submitted successfully!';
            } else {
                $error_message = 'Database error: ' . $stmt->error;
            }
        }
    }
}

// ------------------------------------------------------------
// Get recent requests by this agent
// ------------------------------------------------------------
$recent_query = "SELECT r.*, c.customer_name 
                 FROM credit_discount_requests r
                 JOIN customers c ON r.customer_id = c.customer_id
                 WHERE r.agent_id = ?
                 ORDER BY r.created_at DESC
                 LIMIT 10";
$stmt = $conn->prepare($recent_query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$recent_result = $stmt->get_result();
$recent_requests = $recent_result ? $recent_result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit / Discount Request</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/sales.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
            border: 1px solid #eef2f6;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .stat-icon {
            font-size: 2.5rem;
            margin-right: 20px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        .status-pending { background: #fff3e0; color: #e65100; }
        .status-approved { background: #e8f5e9; color: #2e7d32; }
        .status-rejected { background: #ffebee; color: #c62828; }
    </style>
</head>
<body>
    <div id="appPage">
        <!-- Sidebar (same as other pages) -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
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
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
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
                        <a class="nav-link active" href="credit_discount_request.php">
                            <i class="bi bi-pencil-square"></i>
                            <span class="nav-text">Credit/Discount Request</span>
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
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Bar -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Credit / Discount Request</h2>
                    <p>Submit a request for customer credit limit increase or discount</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Request Form -->
            <div class="card mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>New Request</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="requestForm">
                        <input type="hidden" name="action" value="submit_request">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Customer <span class="text-danger">*</span></label>
                                <select class="form-select" name="customer_id" required>
                                    <option value="">-- Select Customer --</option>
                                    <?php foreach ($customers as $cust): ?>
                                        <option value="<?php echo $cust['customer_id']; ?>">
                                            <?php echo htmlspecialchars($cust['customer_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">Request Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="request_type" id="requestType" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="credit">Credit Limit Increase</option>
                                    <option value="discount">Discount</option>
                                    <option value="both">Both Credit & Discount</option>
                                </select>
                            </div>

                            <div class="col-md-6" id="creditField" style="display: none;">
                                <label class="form-label fw-bold">Requested Credit Limit (₱)</label>
                                <input type="number" class="form-control" name="credit_limit" step="0.01" min="0" placeholder="0.00">
                                <small class="text-muted">Enter the new credit limit amount</small>
                            </div>

                            <div class="col-md-6" id="discountField" style="display: none;">
                                <label class="form-label fw-bold">Requested Discount (%)</label>
                                <input type="number" class="form-control" name="discount_percent" step="0.01" min="0" max="100" placeholder="0.00">
                                <small class="text-muted">Enter percentage (e.g., 5 for 5%)</small>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold">Reason for Request <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="reason" rows="3" required></textarea>
                                <small class="text-muted">Explain why this request is needed</small>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-send"></i> Submit Request
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Requests Table -->
            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Requests</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Type</th>
                                <th>Credit Limit</th>
                                <th>Discount (%)</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Admin Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_requests)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No requests yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_requests as $req): ?>
                                    <?php
                                        $status_class = match($req['status']) {
                                            'pending'  => 'status-pending',
                                            'approved' => 'status-approved',
                                            'rejected' => 'status-rejected',
                                            default    => 'bg-secondary text-white'
                                        };
                                        $type_label = ucfirst($req['request_type']);
                                    ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($req['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($req['customer_name']); ?></td>
                                        <td><span class="badge bg-info"><?php echo $type_label; ?></span></td>
                                        <td><?php echo $req['requested_credit_limit'] ? '₱' . number_format($req['requested_credit_limit'], 2) : '—'; ?></td>
                                        <td><?php echo $req['requested_discount_percent'] ? $req['requested_discount_percent'] . '%' : '—'; ?></td>
                                        <td><?php echo nl2br(htmlspecialchars($req['reason'])); ?></td>
                                        <td><span class="badge-status <?php echo $status_class; ?>"><?php echo ucfirst($req['status']); ?></span></td>
                                        <td><?php echo htmlspecialchars($req['admin_notes'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar functions (same as other pages)
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
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => overlay.remove(), 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
            }
        }
        function closeMobileSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            }
        }
        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const collapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                sidebar.classList.toggle('collapsed', collapsed);
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = collapsed ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = collapsed ? '80px' : '250px';
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            document.getElementById('mobileToggleBtn')?.addEventListener('click', e => { e.stopPropagation(); toggleSidebar(); });
            document.getElementById('desktopToggleBtn')?.addEventListener('click', e => { e.stopPropagation(); toggleSidebar(); });
            document.querySelectorAll('.sidebar .nav-link').forEach(l => {
                l.addEventListener('click', () => { if (window.innerWidth <= 992) closeMobileSidebar(); });
            });
            window.addEventListener('resize', initializeSidebar);
        });

        // Toggle credit/discount fields based on request type
        const requestType = document.getElementById('requestType');
        const creditField = document.getElementById('creditField');
        const discountField = document.getElementById('discountField');

        requestType.addEventListener('change', function() {
            const val = this.value;
            creditField.style.display = (val === 'credit' || val === 'both') ? 'block' : 'none';
            discountField.style.display = (val === 'discount' || val === 'both') ? 'block' : 'none';
        });

        function logout() {
            window.location.href = '../logout.php';
        }
    </script>
</body>
</html>