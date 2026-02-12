<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

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
            $sql = "INSERT INTO customers (customer_name, customer_code, contact_person, email, phone_number, address, city, latitude, longitude, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssssssssss', $customer_name, $customer_code, $contact_person, $email, $phone, $address, $city, $latitude, $longitude, $status);
            
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
        $customer_code = trim($_POST['customer_code']); // Keep existing code
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

// Get all customers
$customers = [];
$query = "SELECT c.*, COUNT(so.so_id) as total_orders 
          FROM customers c 
          LEFT JOIN sales_orders so ON c.customer_id = so.customer_id 
          WHERE c.status = 'active' 
          GROUP BY c.customer_id 
          ORDER BY c.created_at DESC";
$result = $conn->query($query);
if ($result) {
    $customers = $result->fetch_all(MYSQLI_ASSOC);
}

// Get stats
$total_customers = 0;
$active_customers = 0;
$total_orders = 0;

$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
                FROM customers";
$stats_result = $conn->query($stats_query);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $total_customers = $stats['total'];
    $active_customers = $stats['active'];
}

// Get total orders count
$orders_query = "SELECT COUNT(*) as total_orders FROM sales_orders";
$orders_result = $conn->query($orders_query);
if ($orders_result) {
    $orders_stats = $orders_result->fetch_assoc();
    $total_orders = $orders_stats['total_orders'];
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
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Leaflet CSS for Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
            
            /* Make cards 2 columns on mobile - CHANGED FROM col-md-4 TO col-md-3 for 2x2 layout */
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
            
            /* Make cards 2 columns on mobile - CHANGED FROM col-md-4 TO col-md-3 for 2x2 layout */
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
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <div class="page-title">
                    <h2><i class="bi bi-people me-2"></i>Customer Information</h2>
                    <p>Manage customer database and details</p>
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
                            <button type="button" class="btn btn-outline-secondary" onclick="geocodeAddress()">
                                <i class="bi bi-search"></i> Geocode Address
                            </button>
                        </div>
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
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

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
                if (row.cells.length < 6) return;
                const status = row.cells[5].textContent.toLowerCase();
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

        // Refresh customer code via AJAX
        function refreshCustomerCode() {
            fetch('generate_customer_code.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('customerCodePreview').innerHTML = data.code + ' <i class="bi bi-arrow-repeat refresh-code" onclick="refreshCustomerCode()" title="Generate new code"></i>';
                        document.getElementById('customerCodeInput').value = data.code;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        // Map variables
        let map;
        let marker;
        let editMap;
        let editMarker;
        let viewMap;
        let viewMarker;

        // Initialize add customer map
        function initAddCustomerMap() {
            if (document.getElementById('locationMap')) {
                // Default to Manila coordinates
                const defaultLat = 14.5995;
                const defaultLng = 120.9842;
                
                map = L.map('locationMap').setView([defaultLat, defaultLng], 13);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(map);
                
                marker = L.marker([defaultLat, defaultLng], {
                    draggable: true
                }).addTo(map);
                
                // Update input fields when marker is moved
                marker.on('dragend', function(e) {
                    const position = marker.getLatLng();
                    document.getElementById('latitudeInput').value = position.lat.toFixed(6);
                    document.getElementById('longitudeInput').value = position.lng.toFixed(6);
                });
                
                // Add click event to map to move marker
                map.on('click', function(e) {
                    marker.setLatLng(e.latlng);
                    document.getElementById('latitudeInput').value = e.latlng.lat.toFixed(6);
                    document.getElementById('longitudeInput').value = e.latlng.lng.toFixed(6);
                });
                
                // Update marker when coordinates are manually entered
                document.getElementById('latitudeInput').addEventListener('change', updateMarkerFromInputs);
                document.getElementById('longitudeInput').addEventListener('change', updateMarkerFromInputs);
            }
        }

        // Update marker position from input fields
        function updateMarkerFromInputs() {
            const lat = parseFloat(document.getElementById('latitudeInput').value);
            const lng = parseFloat(document.getElementById('longitudeInput').value);
            
            if (!isNaN(lat) && !isNaN(lng)) {
                if (map && marker) {
                    marker.setLatLng([lat, lng]);
                    map.setView([lat, lng], 13);
                }
            }
        }

        // Get current location
        function getCurrentLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        document.getElementById('latitudeInput').value = lat.toFixed(6);
                        document.getElementById('longitudeInput').value = lng.toFixed(6);
                        
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

        // Geocode address to coordinates
        function geocodeAddress() {
            const address = document.getElementById('addressInput').value;
            
            if (!address) {
                alert('Please enter an address first');
                return;
            }
            
            // Using Nominatim (OpenStreetMap's geocoding service)
            const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}&limit=1`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data && data.length > 0) {
                        const lat = parseFloat(data[0].lat);
                        const lng = parseFloat(data[0].lon);
                        
                        document.getElementById('latitudeInput').value = lat.toFixed(6);
                        document.getElementById('longitudeInput').value = lng.toFixed(6);
                        
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

        // View customer details
        function viewCustomerDetails(customerId) {
            fetch('get_customer_details.php?id=' + customerId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customer = data.customer;
                        const modal = new bootstrap.Modal(document.getElementById('viewCustomerModal'));
                        
                        document.getElementById('customerDetailsContent').innerHTML = `
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Customer Code:</strong><br>${customer.customer_code}</p>
                                    <p><strong>Name:</strong><br>${customer.customer_name}</p>
                                    <p><strong>Contact Person:</strong><br>${customer.contact_person || 'N/A'}</p>
                                    <p><strong>Email:</strong><br>${customer.email}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Phone:</strong><br>${customer.phone_number || 'N/A'}</p>
                                    <p><strong>Address:</strong><br>${customer.address || 'N/A'}</p>
                                    <p><strong>City:</strong><br>${customer.city || 'N/A'}</p>
                                    <p><strong>Status:</strong><br>
                                        <span class="badge ${customer.status === 'active' ? 'bg-success' : 'bg-danger'}">
                                            ${customer.status}
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
                            </div>
                            ` : ''}
                        `;
                        
                        modal.show();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer details');
                });
        }

        // Edit customer
        function editCustomer(customerId) {
            fetch('get_customer_details.php?id=' + customerId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const customer = data.customer;
                        const modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
                        
                        document.getElementById('editCustomerId').value = customerId;
                        
                        document.getElementById('editCustomerContent').innerHTML = `
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer Name *</label>
                                    <input type="text" class="form-control" name="customer_name" value="${customer.customer_name}" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer Code</label>
                                    <input type="text" class="form-control" name="customer_code" value="${customer.customer_code}" readonly>
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
                                    <input type="email" class="form-control" name="email" value="${customer.email}" required>
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
                        
                        modal.show();
                        initEditCustomerMap(customer);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading customer details');
                });
        }

        // Initialize edit customer map
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
                    
                    // Update input fields when marker is moved
                    editMarker.on('dragend', function(e) {
                        const position = editMarker.getLatLng();
                        document.getElementById('editLatitude').value = position.lat.toFixed(6);
                        document.getElementById('editLongitude').value = position.lng.toFixed(6);
                    });
                    
                    // Add click event to map to move marker
                    editMap.on('click', function(e) {
                        editMarker.setLatLng(e.latlng);
                        document.getElementById('editLatitude').value = e.latlng.lat.toFixed(6);
                        document.getElementById('editLongitude').value = e.latlng.lng.toFixed(6);
                    });
                    
                    // Update marker when coordinates are manually entered
                    document.getElementById('editLatitude').addEventListener('change', updateEditMarkerFromInputs);
                    document.getElementById('editLongitude').addEventListener('change', updateEditMarkerFromInputs);
                }
            }, 300);
        }

        // Update edit marker position from input fields
        function updateEditMarkerFromInputs() {
            const lat = parseFloat(document.getElementById('editLatitude').value);
            const lng = parseFloat(document.getElementById('editLongitude').value);
            
            if (!isNaN(lat) && !isNaN(lng) && editMap && editMarker) {
                editMarker.setLatLng([lat, lng]);
                editMap.setView([lat, lng], 13);
            }
        }

        // Get current location for edit form
        function getCurrentLocationForEdit() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        document.getElementById('editLatitude').value = lat.toFixed(6);
                        document.getElementById('editLongitude').value = lng.toFixed(6);
                        
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

        // Geocode address for edit form
        function geocodeAddressForEdit() {
            const address = document.querySelector('#editCustomerContent textarea[name="address"]').value;
            
            if (!address) {
                alert('Please enter an address first');
                return;
            }
            
            const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}&limit=1`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data && data.length > 0) {
                        const lat = parseFloat(data[0].lat);
                        const lng = parseFloat(data[0].lon);
                        
                        document.getElementById('editLatitude').value = lat.toFixed(6);
                        document.getElementById('editLongitude').value = lng.toFixed(6);
                        
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

        // View location on map
        function viewLocationOnMap(customerId, customerName, latitude, longitude) {
            document.getElementById('locationCustomerName').textContent = customerName;
            document.getElementById('viewLatitude').textContent = latitude;
            document.getElementById('viewLongitude').textContent = longitude;
            
            const modal = new bootstrap.Modal(document.getElementById('viewLocationModal'));
            modal.show();
            
            // Initialize map after modal is shown
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

        // Initialize add customer map when modal is shown
        document.getElementById('addCustomerModal').addEventListener('shown.bs.modal', function() {
            initAddCustomerMap();
        });

        // Clean up maps when modals are hidden
        document.getElementById('addCustomerModal').addEventListener('hidden.bs.modal', function() {
            if (map) {
                map.remove();
                map = null;
                marker = null;
            }
        });

        document.getElementById('editCustomerModal').addEventListener('hidden.bs.modal', function() {
            if (editMap) {
                editMap.remove();
                editMap = null;
                editMarker = null;
            }
        });

        document.getElementById('viewLocationModal').addEventListener('hidden.bs.modal', function() {
            if (viewMap) {
                viewMap.remove();
                viewMap = null;
                viewMarker = null;
            }
        });
    </script>
</body>
</html>