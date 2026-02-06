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

<!-- Return Stats -->
<div class="row g-3 mb-4">

    <!-- Pending Requests -->
    <div class="col-md-3 mb-3">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div>
                <div class="stat-value">14</div>
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
                <div class="stat-value">32</div>
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
                <div class="stat-value">8</div>
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
                <div class="stat-value">$4,250</div>
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
                            <tr>
                                <td><span class="badge bg-light text-dark">RET-001</span></td>
                                <td>John Doe</td>
                                <td>Laptop Computer</td>
                                <td>1</td>
                                <td>Defective unit</td>
                                <td>2024-01-15</td>
                                <td><span class="badge bg-warning">Pending</span></td>
                                <td>$899.99</td>
                                <td>
                                    <button class="btn btn-sm btn-success" title="Approve" onclick="updateStatus(this, 'approved')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" title="Reject" onclick="updateStatus(this, 'rejected')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">RET-002</span></td>
                                <td>Jane Smith</td>
                                <td>Office Chair</td>
                                <td>2</td>
                                <td>Wrong size</td>
                                <td>2024-01-14</td>
                                <td><span class="badge bg-success">Approved</span></td>
                                <td>$499.98</td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">RET-003</span></td>
                                <td>ABC Corporation</td>
                                <td>Desk Lamp</td>
                                <td>5</td>
                                <td>Damaged in shipping</td>
                                <td>2024-01-13</td>
                                <td><span class="badge bg-warning">Pending</span></td>
                                <td>$174.95</td>
                                <td>
                                    <button class="btn btn-sm btn-success" title="Approve" onclick="updateStatus(this, 'approved')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" title="Reject" onclick="updateStatus(this, 'rejected')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">RET-004</span></td>
                                <td>Michael Johnson</td>
                                <td>Wireless Mouse</td>
                                <td>3</td>
                                <td>Not as described</td>
                                <td>2024-01-12</td>
                                <td><span class="badge bg-danger">Rejected</span></td>
                                <td>$0.00</td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">RET-005</span></td>
                                <td>Sarah Williams</td>
                                <td>USB-C Cable</td>
                                <td>10</td>
                                <td>Defective batch</td>
                                <td>2024-01-11</td>
                                <td><span class="badge bg-success">Approved</span></td>
                                <td>$99.90</td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">RET-006</span></td>
                                <td>XYZ Limited</td>
                                <td>Desk Organizer</td>
                                <td>4</td>
                                <td>Customer changed mind</td>
                                <td>2024-01-10</td>
                                <td><span class="badge bg-warning">Pending</span></td>
                                <td>$119.96</td>
                                <td>
                                    <button class="btn btn-sm btn-success" title="Approve" onclick="updateStatus(this, 'approved')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" title="Reject" onclick="updateStatus(this, 'rejected')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">RET-007</span></td>
                                <td>Emily Davis</td>
                                <td>Coffee Maker</td>
                                <td>1</td>
                                <td>Does not work</td>
                                <td>2024-01-09</td>
                                <td><span class="badge bg-success">Approved</span></td>
                                <td>$79.99</td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
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
                    <form id="addReturnForm">
                        <div class="mb-3">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" id="returnCustomer" required placeholder="Enter customer name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="returnProduct" required placeholder="Enter product name">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="returnQty" required min="1" placeholder="Qty">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Refund Amount</label>
                                <input type="number" class="form-control" id="returnAmount" required step="0.01" placeholder="$0.00">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason for Return</label>
                            <select class="form-select" id="returnReason" required>
                                <option value="">-- Select Reason --</option>
                                <option value="Defective unit">Defective unit</option>
                                <option value="Wrong Item">Wrong Item</option>
                                <option value="Damaged in shipping">Damaged in shipping</option>
                                <option value="Not as described">Not as described</option>
                                <option value="Customer changed mind">Customer changed mind</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="returnStatus">
                                <option value="Pending" selected>Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addNewReturn()">Add Return</button>
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

        // Add new return
        function addNewReturn() {
            const customer = document.getElementById('returnCustomer').value;
            const product = document.getElementById('returnProduct').value;
            const qty = document.getElementById('returnQty').value;
            const amount = parseFloat(document.getElementById('returnAmount').value).toFixed(2);
            const reason = document.getElementById('returnReason').value;
            const status = document.getElementById('returnStatus').value;

            if (!customer || !product || !qty || !reason) {
                alert('Please fill in all required fields');
                return;
            }

            // Generate return ID
            const returnId = 'RET-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');

            // Add row to table
            const table = document.querySelector('tbody');
            const newRow = table.insertRow(0);
            
            let statusBadge = 'bg-warning';
            if (status === 'Approved') statusBadge = 'bg-success';
            if (status === 'Rejected') statusBadge = 'bg-danger';

            newRow.innerHTML = `
                <td><span class="badge bg-light text-dark">${returnId}</span></td>
                <td>${customer}</td>
                <td>${product}</td>
                <td>${qty}</td>
                <td>${reason}</td>
                <td>${new Date().toISOString().split('T')[0]}</td>
                <td><span class="badge ${statusBadge}">${status}</span></td>
                <td>$${amount}</td>
                <td>
                    ${status === 'Pending' ? `
                        <button class="btn btn-sm btn-success" title="Approve" onclick="updateStatus(this, 'approved')">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" title="Reject" onclick="updateStatus(this, 'rejected')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    ` : `
                        <button class="btn btn-sm btn-info" title="View Details">
                            <i class="bi bi-eye"></i>
                        </button>
                    `}
                </td>
            `;

            // Reset form and close modal
            document.getElementById('addReturnForm').reset();
            bootstrap.Modal.getInstance(document.getElementById('addReturnModal')).hide();
            alert(`Return ${returnId} has been added successfully!`);
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

        // Update return status
        function updateStatus(button, action) {
            const row = button.closest('tr');
            const returnId = row.cells[0].textContent;
            let newStatus = '';
            let badgeClass = '';

            if (action === 'approved') {
                newStatus = 'Approved';
                badgeClass = 'bg-success';
            } else if (action === 'rejected') {
                newStatus = 'Rejected';
                badgeClass = 'bg-danger';
            }

            row.cells[6].innerHTML = `<span class="badge ${badgeClass}">${newStatus}</span>`;
            
            // Replace action buttons with view button
            row.cells[8].innerHTML = `
                <button class="btn btn-sm btn-info" title="View Details">
                    <i class="bi bi-eye"></i>
                </button>
            `;

            alert(`${returnId} has been ${newStatus.toLowerCase()}`);
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>
