<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items - Warehouse</title>
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
                <h3><i class="bi bi-building logo-icon"></i> <span class="nav-text">Warehouse</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="warehouse.php">
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
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="pick_list_items.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
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
                    <h2><i class="bi bi-clipboard-check me-2"></i>Pick List Items</h2>
                    <p>Manage and track invoice items for shipments with trip ticket details</p>
                </div>
                
                <div class="user-info-top">
                    <div class="user-profile-top">
                        <div class="user-avatar-top" id="userAvatar">WM</div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName">Warehouse Manager</span>
                            <span class="user-role-top" id="userRole">Warehouse</span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="logout()">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <!-- Pick List Stats -->
            <div class="row g-3 mb-4">

                <!-- Total Items -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div>
                            <div class="stat-value">156</div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                </div>

                <!-- Picked -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value">128</div>
                            <div class="stat-label">Picked</div>
                        </div>
                    </div>
                </div>

                <!-- Pending -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-value">20</div>
                            <div class="stat-label">Pending Pickup</div>
                        </div>
                    </div>
                </div>

                <!-- Total Value -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card stock">
                        <div class="stat-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div>
                            <div class="stat-value">$32.5K</div>
                            <div class="stat-label">Total Value</div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by invoice or item...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Picked">Picked</option>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addPickListModal">
                                <i class="bi bi-plus-lg"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pick List Items Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item ID</th>
                                <th>Invoice #</th>
                                <th>Product Name</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                                <th>Trip Ticket</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge bg-light text-dark">PLI-001</span></td>
                                <td>INV-2025-001</td>
                                <td>Widget A</td>
                                <td>50</td>
                                <td>$45.00</td>
                                <td>$2,250.00</td>
                                <td><span class="badge bg-info">TT-001</span></td>
                                <td><span class="badge bg-success">Picked</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">PLI-002</span></td>
                                <td>INV-2025-002</td>
                                <td>Gadget B</td>
                                <td>30</td>
                                <td>$65.00</td>
                                <td>$1,950.00</td>
                                <td><span class="badge bg-info">TT-002</span></td>
                                <td><span class="badge bg-warning">Pending</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">PLI-003</span></td>
                                <td>INV-2025-003</td>
                                <td>Device C</td>
                                <td>25</td>
                                <td>$28.50</td>
                                <td>$712.50</td>
                                <td><span class="badge bg-info">TT-003</span></td>
                                <td><span class="badge bg-success">Picked</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">PLI-004</span></td>
                                <td>INV-2025-004</td>
                                <td>Tool D</td>
                                <td>15</td>
                                <td>$75.00</td>
                                <td>$1,125.00</td>
                                <td><span class="badge bg-info">TT-004</span></td>
                                <td><span class="badge bg-info">In Progress</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">PLI-005</span></td>
                                <td>INV-2025-005</td>
                                <td>Component E</td>
                                <td>12</td>
                                <td>$120.00</td>
                                <td>$1,440.00</td>
                                <td><span class="badge bg-info">TT-005</span></td>
                                <td><span class="badge bg-warning">Pending</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Pick List Item Modal -->
    <div class="modal fade" id="addPickListModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Pick List Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addPickListForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Invoice Number</label>
                                <input type="text" class="form-control" id="invoiceNo" required placeholder="e.g., INV-2025-001">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Trip Ticket ID</label>
                                <input type="text" class="form-control" id="tripTicketId" required placeholder="e.g., TT-001">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Product Name</label>
                                <input type="text" class="form-control" id="productName" required placeholder="Product name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="quantity" required placeholder="0" min="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit Price</label>
                                <input type="number" class="form-control" id="unitPrice" required placeholder="0.00" min="0" step="0.01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="itemStatus" required>
                                    <option value="">Select Status</option>
                                    <option value="Picked">Picked</option>
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addPickListItem()">Add Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Item Details Modal -->
    <div class="modal fade" id="viewItemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pick List Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-muted">Item Information</h6>
                            <p><strong>Item ID:</strong> PLI-001</p>
                            <p><strong>Product:</strong> Widget A</p>
                            <p><strong>Status:</strong> <span class="badge bg-success">Picked</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Invoice Details</h6>
                            <p><strong>Invoice Number:</strong> INV-2025-001</p>
                            <p><strong>Trip Ticket:</strong> TT-001</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <h6 class="text-muted">Quantity</h6>
                            <p class="fs-5"><strong>50 units</strong></p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Unit Price</h6>
                            <p class="fs-5"><strong>$45.00</strong></p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Total Value</h6>
                            <p class="fs-5"><strong>$2,250.00</strong></p>
                        </div>
                    </div>
                    <hr>
                    <h6 class="text-muted mb-3">Associated Trip Information</h6>
                    <p><strong>Driver:</strong> John Smith (DRV-001)</p>
                    <p><strong>Destination:</strong> New York</p>
                    <p><strong>Departure Date:</strong> 02/01/2025</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary">Print Invoice</button>
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

        // Add pick list item
        function addPickListItem() {
            const invoiceNo = document.getElementById('invoiceNo').value;
            const tripTicketId = document.getElementById('tripTicketId').value;
            const productName = document.getElementById('productName').value;
            const quantity = parseInt(document.getElementById('quantity').value);
            const unitPrice = parseFloat(document.getElementById('unitPrice').value);
            const status = document.getElementById('itemStatus').value;

            if (!invoiceNo || !tripTicketId || !productName || !quantity || !unitPrice || !status) {
                alert('Please fill in all required fields');
                return;
            }

            // Generate item ID
            const itemId = 'PLI-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');
            const totalValue = (quantity * unitPrice).toFixed(2);
            let statusBadge = 'bg-success';
            if (status === 'Pending') statusBadge = 'bg-warning';
            if (status === 'In Progress') statusBadge = 'bg-info';

            // Add row to table
            const table = document.querySelector('tbody');
            const newRow = table.insertRow(0);
            
            newRow.innerHTML = `
                <td><span class="badge bg-light text-dark">${itemId}</span></td>
                <td>${invoiceNo}</td>
                <td>${productName}</td>
                <td>${quantity}</td>
                <td>$${unitPrice.toFixed(2)}</td>
                <td>$${totalValue}</td>
                <td><span class="badge bg-info">${tripTicketId}</span></td>
                <td><span class="badge ${statusBadge}">${status}</span></td>
                <td>
                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewItemModal">
                        <i class="bi bi-eye"></i> View
                    </button>
                </td>
            `;

            // Reset form and close modal
            document.getElementById('addPickListForm').reset();
            bootstrap.Modal.getInstance(document.getElementById('addPickListModal')).hide();
            alert(`Pick List Item ${itemId} has been added successfully!`);
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
                const status = row.cells[7].textContent.toLowerCase();
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
