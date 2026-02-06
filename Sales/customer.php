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
                        <div class="user-avatar-top" id="userAvatar">AD</div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName">Admin User</span>
                            <span class="user-role-top" id="userRole">Administrator</span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="logout()">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <!-- Customer Stats -->
            <div class="row g-3 mb-4">
                <div class="col-md-4 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: #e3f2fd;">
                            <i class="bi bi-people" style="color: #1976d2;"></i>
                        </div>
                        <div>
                            <div class="stat-value">156</div>
                            <div class="stat-label">Total Customers</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: #e8f5e9;">
                            <i class="bi bi-check-circle" style="color: #388e3c;"></i>
                        </div>
                        <div>
                            <div class="stat-value">142</div>
                            <div class="stat-label">Active Customers</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background-color: #fce4ec;">
                            <i class="bi bi-graph-up" style="color: #c2185b;"></i>
                        </div>
                        <div>
                            <div class="stat-value">$487K</div>
                            <div class="stat-label">Total Revenue</div>
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
                                <th>Customer ID</th>
                                <th>Customer Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>City</th>
                                <th>Status</th>
                                <th>Total Orders</th>
                                <th>Total Spent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-001</span></td>
                                <td>John Doe</td>
                                <td>john.doe@email.com</td>
                                <td>(555) 123-4567</td>
                                <td>New York</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td>15</td>
                                <td>$3,450.00</td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-002</span></td>
                                <td>Jane Smith</td>
                                <td>jane.smith@email.com</td>
                                <td>(555) 987-6543</td>
                                <td>Los Angeles</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td>22</td>
                                <td>$5,678.50</td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-003</span></td>
                                <td>ABC Corporation</td>
                                <td>contact@abccorp.com</td>
                                <td>(555) 444-7890</td>
                                <td>Chicago</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td>45</td>
                                <td>$12,340.00</td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-004</span></td>
                                <td>Michael Johnson</td>
                                <td>m.johnson@email.com</td>
                                <td>(555) 555-5555</td>
                                <td>Houston</td>
                                <td><span class="badge bg-warning">Pending</span></td>
                                <td>3</td>
                                <td>$890.00</td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-005</span></td>
                                <td>Sarah Williams</td>
                                <td>sarah.w@email.com</td>
                                <td>(555) 222-3333</td>
                                <td>Phoenix</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td>18</td>
                                <td>$4,125.75</td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-006</span></td>
                                <td>XYZ Limited</td>
                                <td>sales@xyzlimited.com</td>
                                <td>(555) 666-7777</td>
                                <td>Philadelphia</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td>38</td>
                                <td>$8,920.00</td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-007</span></td>
                                <td>Robert Brown</td>
                                <td>robert.brown@email.com</td>
                                <td>(555) 888-9999</td>
                                <td>San Antonio</td>
                                <td><span class="badge bg-secondary">Inactive</span></td>
                                <td>8</td>
                                <td>$1,560.00</td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">CUST-008</span></td>
                                <td>Emily Davis</td>
                                <td>emily.davis@email.com</td>
                                <td>(555) 111-2222</td>
                                <td>San Diego</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td>25</td>
                                <td>$6,234.25</td>
                            </tr>
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
                <div class="modal-body">
                    <form id="addCustomerForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name</label>
                                <input type="text" class="form-control" id="customerName" required placeholder="Full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer ID</label>
                                <input type="text" class="form-control" id="customerId" placeholder="Auto-generated" disabled>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" id="customerEmail" required placeholder="customer@example.com">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="customerPhone" required placeholder="(555) 000-0000">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" id="customerCity" required placeholder="City">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="customerStatus">
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Pending">Pending</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addNewCustomer()">Add Customer</button>
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

        // Add new customer
        function addNewCustomer() {
            const name = document.getElementById('customerName').value;
            const email = document.getElementById('customerEmail').value;
            const phone = document.getElementById('customerPhone').value;
            const city = document.getElementById('customerCity').value;
            const status = document.getElementById('customerStatus').value;

            if (!name || !email || !phone || !city) {
                alert('Please fill in all required fields');
                return;
            }

            // Generate customer ID
            const custId = 'CUST-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');

            // Add row to table
            const table = document.querySelector('tbody');
            const newRow = table.insertRow(0);
            
            let statusBadge = 'bg-success';
            if (status === 'Inactive') statusBadge = 'bg-secondary';
            if (status === 'Pending') statusBadge = 'bg-warning';

            newRow.innerHTML = `
                <td><span class="badge bg-light text-dark">${custId}</span></td>
                <td>${name}</td>
                <td>${email}</td>
                <td>${phone}</td>
                <td>${city}</td>
                <td><span class="badge ${statusBadge}">${status}</span></td>
                <td>0</td>
                <td>$0.00</td>
            `;

            // Reset form and close modal
            document.getElementById('addCustomerForm').reset();
            bootstrap.Modal.getInstance(document.getElementById('addCustomerModal')).hide();
            alert(`Customer ${custId} - ${name} has been added successfully!`);
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
                const status = row.cells[5].textContent.toLowerCase();
                row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
            });
        });

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>
