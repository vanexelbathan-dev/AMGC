<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Function to generate unique customer code
function generateCustomerCode($conn) {
    $prefix = 'CUST-';
    $year = date('Y');
    $month = date('m');
    
    // Get the latest customer code for this year/month
    $query = "SELECT customer_code FROM customers 
              WHERE customer_code LIKE '$prefix$year$month%' 
              ORDER BY customer_code DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_code = $row['customer_code'];
        // Extract the sequence number
        $sequence = intval(substr($last_code, -4)) + 1;
    } else {
        $sequence = 1;
    }
    
    // Format: CUST-YYYYMM-XXXX
    $new_code = $prefix . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    return $new_code;
}

// Check if branch_id column exists in customers table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Handle Add Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_customer') {
        $customer_name = trim($_POST['customer_name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $latitude = trim($_POST['latitude']);
        $longitude = trim($_POST['longitude']);
        $status = 'active';
        
        // Auto-generate customer code
        $customer_code = generateCustomerCode($conn);

        if (empty($customer_name) || empty($email)) {
            $error = 'Please fill in all required fields';
        } else {
            if ($branch_column_exists) {
                // Column exists, include branch_id
                $sql = "INSERT INTO customers (customer_name, customer_code, contact_person, email, phone_number, address, city, latitude, longitude, status, branch_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ssssssssssi', $customer_name, $customer_code, $contact_person, $email, $phone, $address, $city, $latitude, $longitude, $status, $branch_id);
            } else {
                // Column doesn't exist, insert without branch_id
                $sql = "INSERT INTO customers (customer_name, customer_code, contact_person, email, phone_number, address, city, latitude, longitude, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ssssssssss', $customer_name, $customer_code, $contact_person, $email, $phone, $address, $city, $latitude, $longitude, $status);
            }
            
            if ($stmt->execute()) {
                $success = 'Customer added successfully! Customer Code: ' . $customer_code;
            } else {
                $error = 'Error adding customer: ' . $stmt->error;
            }
        }
    }
    
    // Handle Update Customer
    if (isset($_POST['action']) && $_POST['action'] === 'update_customer') {
        $customer_id = (int)$_POST['customer_id'];
        $customer_name = trim($_POST['customer_name']);
        $customer_code = trim($_POST['customer_code']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $latitude = trim($_POST['latitude']);
        $longitude = trim($_POST['longitude']);
        $status = trim($_POST['status']);

        if (empty($customer_name) || empty($customer_code) || empty($email)) {
            $error = 'Please fill in all required fields';
        } else {
            $sql = "UPDATE customers SET 
                    customer_name = ?,
                    customer_code = ?,
                    contact_person = ?,
                    email = ?,
                    phone_number = ?,
                    address = ?,
                    city = ?,
                    latitude = ?,
                    longitude = ?,
                    status = ?,
                    updated_at = NOW()
                    WHERE customer_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssssssssssi', $customer_name, $customer_code, $contact_person, $email, $phone, $address, $city, $latitude, $longitude, $status, $customer_id);
            
            if ($stmt->execute()) {
                $success = 'Customer updated successfully!';
            } else {
                $error = 'Error updating customer: ' . $stmt->error;
            }
        }
    }
}

// Get all customers - filter by branch if not admin AND if branch_id column exists
$customers = [];

if ($branch_column_exists) {
    // Branch column exists - apply filtering
    if ($view_all_branches) {
        // Admin sees all
        $query = "SELECT c.*, COUNT(so.so_id) as total_orders 
                  FROM customers c 
                  LEFT JOIN sales_orders so ON c.customer_id = so.customer_id 
                  WHERE c.status = 'active'
                  GROUP BY c.customer_id 
                  ORDER BY c.created_at DESC";
    } else {
        // Regular user sees only their branch
        $query = "SELECT c.*, COUNT(so.so_id) as total_orders 
                  FROM customers c 
                  LEFT JOIN sales_orders so ON c.customer_id = so.customer_id 
                  WHERE c.status = 'active' AND c.branch_id = $branch_id
                  GROUP BY c.customer_id 
                  ORDER BY c.created_at DESC";
    }
} else {
    // Branch column doesn't exist - show all customers
    $query = "SELECT c.*, COUNT(so.so_id) as total_orders 
              FROM customers c 
              LEFT JOIN sales_orders so ON c.customer_id = so.customer_id 
              WHERE c.status = 'active'
              GROUP BY c.customer_id 
              ORDER BY c.created_at DESC";
}

$result = $conn->query($query);
if ($result) {
    $customers = $result->fetch_all(MYSQLI_ASSOC);
}

// Get stats
$total_customers = 0;
$active_customers = 0;
$total_orders = 0;

if ($branch_column_exists) {
    if ($view_all_branches) {
        $stats_query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
                        FROM customers";
    } else {
        $stats_query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
                        FROM customers 
                        WHERE branch_id = $branch_id";
    }
} else {
    $stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
                    FROM customers";
}

$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $total_customers = $stats['total'] ?? 0;
    $active_customers = $stats['active'] ?? 0;
}

// Get total orders count
$orders_query = "SELECT COUNT(*) as total_orders FROM sales_orders";
$orders_result = $conn->query($orders_query);
if ($orders_result) {
    $orders_stats = $orders_result->fetch_assoc();
    $total_orders = $orders_stats['total_orders'] ?? 0;
}

// Generate a preview code for the modal
$preview_code = generateCustomerCode($conn);

$error = '';
$success = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer - Sales</title>
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
    <!-- Leaflet CSS for Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* Mobile responsive adjustments */
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
            
            .col-md-4 {
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
            
            .col-md-4 {
                width: 50%;
                padding-left: 6px;
                padding-right: 6px;
            }
            
            .row.g-3 {
                margin-left: -6px;
                margin-right: -6px;
            }
        }
        
        /* Map Styles */
        #locationMap {
            height: 300px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        
        .btn-view {
            background-color: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
        }
        
        .btn-view:hover {
            background-color: #bbdefb;
            transform: translateY(-2px);
        }
        
        .btn-edit {
            background-color: #f3e5f5;
            color: #7b1fa2;
            border-color: #e1bee7;
        }
        
        .btn-edit:hover {
            background-color: #e1bee7;
            transform: translateY(-2px);
        }
        
        .btn-location {
            background-color: #e8f5e9;
            color: #388e3c;
            border-color: #c8e6c9;
        }
        
        .btn-location:hover {
            background-color: #c8e6c9;
            transform: translateY(-2px);
        }
        
        /* Map Modal */
        .map-modal .modal-dialog {
            max-width: 800px;
        }
        
        #viewLocationMap {
            height: 400px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        /* Location Coordinates Input */
        .coordinates-container {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .coordinates-container .form-group {
            flex: 1;
        }
        
        .location-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .location-info {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
        
        /* Auto-generated code styling */
        .code-preview {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 10px 15px;
            font-family: monospace;
            font-size: 1.1em;
            color: #0d6efd;
            font-weight: bold;
        }
        
        .code-label {
            font-size: 0.85em;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .refresh-code {
            cursor: pointer;
            color: #0d6efd;
            margin-left: 10px;
        }
        
        .refresh-code:hover {
            color: #0a58ca;
        }
        
        /* Branch badge */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Alert for missing branch column */
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .alert-info code {
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 4px;
            color: #c7254e;
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
                        <a class="nav-link active" href="customer.php">
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
            
            <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role-sidebar">
                            <?php echo htmlspecialchars(ucfirst($user_role)); ?>
                            <?php if ($branch_column_exists): ?>
                            <?php endif; ?>
                        </span>
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
                    <h2>Customer Information</h2>
                    <p>Manage customer database and details</p>
                </div>
            </div>

            <!-- Branch Info Alert (if no branch_id column) -->
            <?php if (!$branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific customer data:
                    <br><br>
                    <code>ALTER TABLE customers ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copySQL() {
                        const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

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

            <!-- Customer Stats -->
            <div class="row g-3 mb-4">
                <!-- Total Customers -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card customers">
                        <div class="stat-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $total_customers; ?></div>
                            <div class="stat-label">Total Customers</div>
                        </div>
                    </div>
                </div>

                <!-- Active Customers -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $active_customers; ?></div>
                            <div class="stat-label">Active Customers</div>
                        </div>
                    </div>
                </div>

                <!-- Total Orders -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-bag-check"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $total_orders; ?></div>
                            <div class="stat-label">Total Orders</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter with Add Button -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search customers by name, email, phone...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                                <i class="bi bi-plus-lg"></i> Add Customer
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Customer Code</th>
                                <th>Customer Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>City</th>
                                <th>Status</th>
                                <th>Total Orders</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($customers) > 0): ?>
                                <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($customer['customer_code']); ?></span></td>
                                    <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['phone_number']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['city']); ?></td>
                                    <td>
                                        <?php
                                        $status_badge = [
                                            'active' => 'bg-success',
                                            'inactive' => 'bg-danger',
                                            'pending' => 'bg-warning'
                                        ];
                                        $status_color = $status_badge[$customer['status']] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $status_color; ?>">
                                            <?php echo ucfirst($customer['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $customer['total_orders'] ?? 0; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action btn-view" onclick="viewCustomerDetails(<?php echo $customer['customer_id']; ?>)" 
                                                    title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn-action btn-edit" onclick="editCustomer(<?php echo $customer['customer_id']; ?>)" 
                                                    title="Edit Customer">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if (!empty($customer['latitude']) && !empty($customer['longitude'])): ?>
                                                <button class="btn-action btn-location" onclick="viewLocationOnMap(<?php echo $customer['customer_id']; ?>, '<?php echo htmlspecialchars($customer['customer_name']); ?>', <?php echo $customer['latitude']; ?>, <?php echo $customer['longitude']; ?>)" 
                                                        title="View Location">
                                                    <i class="bi bi-geo-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No customers found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Customer Modal -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addCustomerForm">
                    <input type="hidden" name="action" value="add_customer">
                    <div class="modal-body">
                        <!-- Auto-generated Customer Code Display -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="code-label">Customer Code (Auto-generated)</div>
                                <div class="code-preview" id="customerCodePreview">
                                    <?php echo $preview_code; ?>
                                    <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>
                                </div>
                                <input type="hidden" name="customer_code" id="customerCodeInput" value="<?php echo $preview_code; ?>">
                                <small class="text-muted">This code will be automatically generated and assigned to the customer</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name *</label>
                                <input type="text" class="form-control" name="customer_name" required placeholder="Full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" placeholder="Contact person name">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required placeholder="customer@example.com">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone_number" placeholder="(555) 000-0000">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City</label>
                                <input type="text" class="form-control" name="city" placeholder="City">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="2" placeholder="Full address" id="addressInput"></textarea>
                        </div>
                        
                        <!-- Location Section -->
                        <div class="location-info">
                            <small><i class="bi bi-info-circle"></i> Click on the map to set the customer location, or enter coordinates manually</small>
                        </div>
                        
                        <div id="locationMap"></div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Latitude</label>
                                <input type="text" class="form-control" name="latitude" id="latitudeInput" placeholder="14.5995" value="14.5995">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Longitude</label>
                                <input type="text" class="form-control" name="longitude" id="longitudeInput" placeholder="120.9842" value="120.9842">
                            </div>
                        </div>
                        
                        <div class="location-buttons">
                            <button type="button" class="btn btn-outline-secondary" onclick="getCurrentLocation()">
                                <i class="bi bi-geo-alt"></i> Use My Location
                            </button>
                        </div>
                        
                        <?php if (!$branch_column_exists): ?>
                            <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Customer Details Modal -->
    <div class="modal fade" id="viewCustomerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customer Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="customerDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Customer Modal -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editCustomerForm">
                    <input type="hidden" name="action" value="update_customer">
                    <input type="hidden" name="customer_id" id="editCustomerId">
                    <div class="modal-body" id="editCustomerContent">
                        <!-- Content loaded via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Location Modal -->
    <div class="modal fade map-modal" id="viewLocationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customer Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 id="locationCustomerName"></h6>
                    <div id="viewLocationMap"></div>
                    <div class="location-info">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Latitude:</strong> <span id="viewLatitude"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Longitude:</strong> <span id="viewLongitude"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Leaflet JS for Maps -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // ================= SIDEBAR FUNCTIONS =================
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
        // ================= END SIDEBAR FUNCTIONS =================

        // Map variables
        let map;
        let marker;
        let editMap;
        let editMarker;
        let viewMap;
        let viewMarker;

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Customer Management page loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button
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

            // Initialize add customer map when modal is shown
            const addCustomerModal = document.getElementById('addCustomerModal');
            if (addCustomerModal) {
                addCustomerModal.addEventListener('shown.bs.modal', function() {
                    initAddCustomerMap();
                });
                
                addCustomerModal.addEventListener('hidden.bs.modal', function() {
                    if (map) {
                        map.remove();
                        map = null;
                        marker = null;
                    }
                });
            }

            // Clean up edit map when modal is hidden
            const editCustomerModal = document.getElementById('editCustomerModal');
            if (editCustomerModal) {
                editCustomerModal.addEventListener('hidden.bs.modal', function() {
                    if (editMap) {
                        editMap.remove();
                        editMap = null;
                        editMarker = null;
                    }
                });
            }

            // Clean up view map when modal is hidden
            const viewLocationModal = document.getElementById('viewLocationModal');
            if (viewLocationModal) {
                viewLocationModal.addEventListener('hidden.bs.modal', function() {
                    if (viewMap) {
                        viewMap.remove();
                        viewMap = null;
                        viewMarker = null;
                    }
                });
            }
        });

        // Setup event listeners
        function setupEventListeners() {
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

            const statusFilter = document.getElementById('statusFilter');
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        if (row.cells.length < 6) return;
                        const status = row.cells[5].textContent.toLowerCase();
                        row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
                    });
                });
            }
        }

        // Refresh customer code via AJAX
        function refreshCustomerCode() {
            fetch('generate_customer_code.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customerCodePreview = document.getElementById('customerCodePreview');
                        if (customerCodePreview) {
                            customerCodePreview.innerHTML = data.code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>';
                        }
                        const customerCodeInput = document.getElementById('customerCodeInput');
                        if (customerCodeInput) {
                            customerCodeInput.value = data.code;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        // Initialize add customer map
        function initAddCustomerMap() {
            if (document.getElementById('locationMap')) {
                const defaultLat = 14.5995;
                const defaultLng = 120.9842;
                
                map = L.map('locationMap').setView([defaultLat, defaultLng], 13);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(map);
                
                marker = L.marker([defaultLat, defaultLng], {
                    draggable: true
                }).addTo(map);
                
                marker.on('dragend', function(e) {
                    const position = marker.getLatLng();
                    const latInput = document.getElementById('latitudeInput');
                    const lngInput = document.getElementById('longitudeInput');
                    if (latInput) latInput.value = position.lat.toFixed(6);
                    if (lngInput) lngInput.value = position.lng.toFixed(6);
                });
                
                map.on('click', function(e) {
                    marker.setLatLng(e.latlng);
                    const latInput = document.getElementById('latitudeInput');
                    const lngInput = document.getElementById('longitudeInput');
                    if (latInput) latInput.value = e.latlng.lat.toFixed(6);
                    if (lngInput) lngInput.value = e.latlng.lng.toFixed(6);
                });
                
                const latInput = document.getElementById('latitudeInput');
                const lngInput = document.getElementById('longitudeInput');
                
                if (latInput) latInput.addEventListener('change', updateMarkerFromInputs);
                if (lngInput) lngInput.addEventListener('change', updateMarkerFromInputs);
            }
        }

        function updateMarkerFromInputs() {
            const latInput = document.getElementById('latitudeInput');
            const lngInput = document.getElementById('longitudeInput');
            if (!latInput || !lngInput) return;
            const lat = parseFloat(latInput.value);
            const lng = parseFloat(lngInput.value);
            if (!isNaN(lat) && !isNaN(lng) && map && marker) {
                marker.setLatLng([lat, lng]);
                map.setView([lat, lng], 13);
            }
        }

        function getCurrentLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        const latInput = document.getElementById('latitudeInput');
                        const lngInput = document.getElementById('longitudeInput');
                        if (latInput) latInput.value = lat.toFixed(6);
                        if (lngInput) lngInput.value = lng.toFixed(6);
                        if (map && marker) {
                            marker.setLatLng([lat, lng]);
                            map.setView([lat, lng], 13);
                        }
                    },
                    function(error) {
                        alert('Unable to get your location: ' + error.message);
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser');
            }
        }

        function geocodeAddress() {
            const address = document.getElementById('addressInput');
            if (!address || !address.value) {
                alert('Please enter an address first');
                return;
            }
            const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address.value)}&limit=1`;
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data && data.length > 0) {
                        const lat = parseFloat(data[0].lat);
                        const lng = parseFloat(data[0].lon);
                        const latInput = document.getElementById('latitudeInput');
                        const lngInput = document.getElementById('longitudeInput');
                        if (latInput) latInput.value = lat.toFixed(6);
                        if (lngInput) lngInput.value = lng.toFixed(6);
                        if (map && marker) {
                            marker.setLatLng([lat, lng]);
                            map.setView([lat, lng], 13);
                        }
                    } else {
                        alert('Address not found');
                    }
                })
                .catch(error => {
                    console.error('Geocoding error:', error);
                    alert('Error geocoding address');
                });
        }

        function viewCustomerDetails(customerId) {
            fetch('get_customer_details.php?id=' + customerId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customer = data.customer;
                        const modal = new bootstrap.Modal(document.getElementById('viewCustomerModal'));
                        const customerDetailsContent = document.getElementById('customerDetailsContent');
                        if (customerDetailsContent) {
                            customerDetailsContent.innerHTML = `
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Customer Code:</strong><br>${customer.customer_code || 'N/A'}</p>
                                        <p><strong>Name:</strong><br>${customer.customer_name || 'N/A'}</p>
                                        <p><strong>Contact Person:</strong><br>${customer.contact_person || 'N/A'}</p>
                                        <p><strong>Email:</strong><br>${customer.email || 'N/A'}</p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Phone:</strong><br>${customer.phone_number || 'N/A'}</p>
                                        <p><strong>Address:</strong><br>${customer.address || 'N/A'}</p>
                                        <p><strong>City:</strong><br>${customer.city || 'N/A'}</p>
                                        <p><strong>Status:</strong><br>
                                            <span class="badge ${customer.status === 'active' ? 'bg-success' : customer.status === 'inactive' ? 'bg-danger' : 'bg-warning'}">
                                                ${customer.status || 'N/A'}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                ${customer.latitude && customer.longitude ? `
                                <hr>
                                <div class="location-info">
                                    <p><strong>Location Coordinates:</strong></p>
                                    <p>Latitude: ${customer.latitude}</p>
                                    <p>Longitude: ${customer.longitude}</p>
                                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="viewLocationOnMap('${customer.customer_id}', '${customer.customer_name.replace(/'/g, "\\'")}', ${customer.latitude}, ${customer.longitude})">
                                        <i class="bi bi-map"></i> View on Map
                                    </button>
                                </div>
                                ` : ''}
                            `;
                        }
                        modal.show();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer details');
                });
        }

        function editCustomer(customerId) {
            fetch('get_customer_details.php?id=' + customerId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customer = data.customer;
                        const modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
                        const editCustomerId = document.getElementById('editCustomerId');
                        if (editCustomerId) editCustomerId.value = customerId;
                        const editCustomerContent = document.getElementById('editCustomerContent');
                        if (editCustomerContent) {
                            editCustomerContent.innerHTML = `
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Customer Name *</label>
                                        <input type="text" class="form-control" name="customer_name" value="${customer.customer_name || ''}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Customer Code</label>
                                        <input type="text" class="form-control" name="customer_code" value="${customer.customer_code || ''}" readonly>
                                        <small class="text-muted">Customer code cannot be changed</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Person</label>
                                        <input type="text" class="form-control" name="contact_person" value="${customer.contact_person || ''}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Email *</label>
                                        <input type="email" class="form-control" name="email" value="${customer.email || ''}" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Phone</label>
                                        <input type="tel" class="form-control" name="phone_number" value="${customer.phone_number || ''}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">City</label>
                                        <input type="text" class="form-control" name="city" value="${customer.city || ''}">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="address" rows="2">${customer.address || ''}</textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="active" ${customer.status === 'active' ? 'selected' : ''}>Active</option>
                                        <option value="inactive" ${customer.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                        <option value="pending" ${customer.status === 'pending' ? 'selected' : ''}>Pending</option>
                                    </select>
                                </div>
                                <div class="location-info">
                                    <small><i class="bi bi-info-circle"></i> Update location coordinates</small>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Latitude</label>
                                        <input type="text" class="form-control" name="latitude" id="editLatitude" value="${customer.latitude || '14.5995'}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Longitude</label>
                                        <input type="text" class="form-control" name="longitude" id="editLongitude" value="${customer.longitude || '120.9842'}">
                                    </div>
                                </div>
                                <div id="editLocationMap" style="height: 250px; margin-bottom: 15px; border-radius: 8px;"></div>
                                <div class="location-buttons">
                                    <button type="button" class="btn btn-outline-secondary" onclick="getCurrentLocationForEdit()">
                                        <i class="bi bi-geo-alt"></i> Use My Location
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="geocodeAddressForEdit()">
                                        <i class="bi bi-search"></i> Geocode Address
                                    </button>
                                </div>
                            `;
                        }
                        modal.show();
                        initEditCustomerMap(customer);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer details');
                });
        }

        function initEditCustomerMap(customer) {
            setTimeout(() => {
                if (document.getElementById('editLocationMap')) {
                    const lat = parseFloat(customer.latitude) || 14.5995;
                    const lng = parseFloat(customer.longitude) || 120.9842;
                    editMap = L.map('editLocationMap').setView([lat, lng], 13);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(editMap);
                    editMarker = L.marker([lat, lng], {
                        draggable: true
                    }).addTo(editMap);
                    editMarker.on('dragend', function(e) {
                        const position = editMarker.getLatLng();
                        const editLatitude = document.getElementById('editLatitude');
                        const editLongitude = document.getElementById('editLongitude');
                        if (editLatitude) editLatitude.value = position.lat.toFixed(6);
                        if (editLongitude) editLongitude.value = position.lng.toFixed(6);
                    });
                    editMap.on('click', function(e) {
                        editMarker.setLatLng(e.latlng);
                        const editLatitude = document.getElementById('editLatitude');
                        const editLongitude = document.getElementById('editLongitude');
                        if (editLatitude) editLatitude.value = e.latlng.lat.toFixed(6);
                        if (editLongitude) editLongitude.value = e.latlng.lng.toFixed(6);
                    });
                    const editLatitude = document.getElementById('editLatitude');
                    const editLongitude = document.getElementById('editLongitude');
                    if (editLatitude) editLatitude.addEventListener('change', updateEditMarkerFromInputs);
                    if (editLongitude) editLongitude.addEventListener('change', updateEditMarkerFromInputs);
                }
            }, 300);
        }

        function updateEditMarkerFromInputs() {
            const editLatitude = document.getElementById('editLatitude');
            const editLongitude = document.getElementById('editLongitude');
            if (!editLatitude || !editLongitude) return;
            const lat = parseFloat(editLatitude.value);
            const lng = parseFloat(editLongitude.value);
            if (!isNaN(lat) && !isNaN(lng) && editMap && editMarker) {
                editMarker.setLatLng([lat, lng]);
                editMap.setView([lat, lng], 13);
            }
        }

        function getCurrentLocationForEdit() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        const editLatitude = document.getElementById('editLatitude');
                        const editLongitude = document.getElementById('editLongitude');
                        if (editLatitude) editLatitude.value = lat.toFixed(6);
                        if (editLongitude) editLongitude.value = lng.toFixed(6);
                        if (editMap && editMarker) {
                            editMarker.setLatLng([lat, lng]);
                            editMap.setView([lat, lng], 13);
                        }
                    },
                    function(error) {
                        alert('Unable to get your location: ' + error.message);
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser');
            }
        }

        function geocodeAddressForEdit() {
            const addressField = document.querySelector('#editCustomerContent textarea[name="address"]');
            if (!addressField || !addressField.value) {
                alert('Please enter an address first');
                return;
            }
            const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(addressField.value)}&limit=1`;
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data && data.length > 0) {
                        const lat = parseFloat(data[0].lat);
                        const lng = parseFloat(data[0].lon);
                        const editLatitude = document.getElementById('editLatitude');
                        const editLongitude = document.getElementById('editLongitude');
                        if (editLatitude) editLatitude.value = lat.toFixed(6);
                        if (editLongitude) editLongitude.value = lng.toFixed(6);
                        if (editMap && editMarker) {
                            editMarker.setLatLng([lat, lng]);
                            editMap.setView([lat, lng], 13);
                        }
                    } else {
                        alert('Address not found');
                    }
                })
                .catch(error => {
                    console.error('Geocoding error:', error);
                    alert('Error geocoding address');
                });
        }

        function viewLocationOnMap(customerId, customerName, latitude, longitude) {
            const locationCustomerName = document.getElementById('locationCustomerName');
            const viewLatitude = document.getElementById('viewLatitude');
            const viewLongitude = document.getElementById('viewLongitude');
            if (locationCustomerName) locationCustomerName.textContent = customerName;
            if (viewLatitude) viewLatitude.textContent = latitude;
            if (viewLongitude) viewLongitude.textContent = longitude;
            const modal = new bootstrap.Modal(document.getElementById('viewLocationModal'));
            modal.show();
            setTimeout(() => {
                if (document.getElementById('viewLocationMap')) {
                    if (viewMap) {
                        viewMap.remove();
                    }
                    const lat = parseFloat(latitude);
                    const lng = parseFloat(longitude);
                    viewMap = L.map('viewLocationMap').setView([lat, lng], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(viewMap);
                    viewMarker = L.marker([lat, lng]).addTo(viewMap);
                    viewMarker.bindPopup(`<b>${customerName}</b><br>${lat.toFixed(6)}, ${lng.toFixed(6)}`).openPopup();
                }
            }, 300);
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            } else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                const addButton = document.querySelector('[data-bs-target="#addCustomerModal"]');
                if (addButton) {
                    addButton.click();
                }
            }
        });
    </script>
</body>
</html>