<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Tickets - Warehouse</title>
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
                        <a class="nav-link active" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
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
                    <h2><i class="bi bi-ticket me-2"></i>Trip Tickets</h2>
                    <p>Track and manage delivery trip tickets with driver and invoice information</p>
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

<!-- Trip Tickets Stats -->
<div class="row g-3 mb-4">

    <!-- Total Trips -->
    <div class="col-md-3 mb-3">
        <div class="stat-card inventory">
            <div class="stat-icon">
                <i class="bi bi-ticket"></i>
            </div>
            <div>
                <div class="stat-value">48</div>
                <div class="stat-label">Total Trips</div>
            </div>
        </div>
    </div>

    <!-- Completed -->
    <div class="col-md-3 mb-3">
        <div class="stat-card sales">
            <div class="stat-icon">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="stat-value">35</div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
    </div>

    <!-- In Transit -->
    <div class="col-md-3 mb-3">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-arrow-right-circle"></i>
            </div>
            <div>
                <div class="stat-value">8</div>
                <div class="stat-label">In Transit</div>
            </div>
        </div>
    </div>

    <!-- Pending -->
    <div class="col-md-3 mb-3">
        <div class="stat-card delivery">
            <div class="stat-icon">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <div>
                <div class="stat-value">5</div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>

</div>


            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by ticket ID, driver, or destination...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Completed">Completed</option>
                                <option value="In Transit">In Transit</option>
                                <option value="Pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addTicketModal">
                                <i class="bi bi-plus-lg"></i> New Trip Ticket
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trip Tickets Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket ID</th>
                                <th>Driver Name</th>
                                <th>Destination</th>
                                <th>Departure Date</th>
                                <th>Invoice #</th>
                                <th>Items Count</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge bg-light text-dark">TT-001</span></td>
                                <td>John Smith</td>
                                <td>New York</td>
                                <td>02/01/2025</td>
                                <td>INV-2025-001</td>
                                <td>12</td>
                                <td><span class="badge bg-success">Completed</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">TT-002</span></td>
                                <td>Sarah Jones</td>
                                <td>Boston</td>
                                <td>02/03/2025</td>
                                <td>INV-2025-002</td>
                                <td>8</td>
                                <td><span class="badge bg-warning">In Transit</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">TT-003</span></td>
                                <td>Mike Davis</td>
                                <td>Philadelphia</td>
                                <td>02/04/2025</td>
                                <td>INV-2025-003</td>
                                <td>15</td>
                                <td><span class="badge bg-secondary">Pending</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">TT-004</span></td>
                                <td>Jessica White</td>
                                <td>Chicago</td>
                                <td>02/02/2025</td>
                                <td>INV-2025-004</td>
                                <td>10</td>
                                <td><span class="badge bg-success">Completed</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">TT-005</span></td>
                                <td>David Brown</td>
                                <td>Houston</td>
                                <td>02/05/2025</td>
                                <td>INV-2025-005</td>
                                <td>7</td>
                                <td><span class="badge bg-warning">In Transit</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
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

    <!-- Add Trip Ticket Modal -->
    <div class="modal fade" id="addTicketModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Trip Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addTicketForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver Name</label>
                                <input type="text" class="form-control" id="driverName" required placeholder="Select or enter driver name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver ID</label>
                                <input type="text" class="form-control" id="driverId" required placeholder="e.g., DRV-001">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Destination</label>
                                <input type="text" class="form-control" id="destination" required placeholder="Delivery destination">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Departure Date</label>
                                <input type="date" class="form-control" id="departureDate" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Invoice Number</label>
                                <input type="text" class="form-control" id="invoiceNo" required placeholder="e.g., INV-2025-001">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Invoice Amount</label>
                                <input type="number" class="form-control" id="invoiceAmount" required placeholder="0.00" min="0" step="0.01">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" placeholder="Additional notes..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addNewTicket()">Create Ticket</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Trip Ticket Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-muted">Ticket Information</h6>
                            <p><strong>Ticket ID:</strong> TT-001</p>
                            <p><strong>Status:</strong> <span class="badge bg-success">Completed</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Driver Information</h6>
                            <p><strong>Driver Name:</strong> John Smith</p>
                            <p><strong>Driver ID:</strong> DRV-001</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-muted">Delivery Details</h6>
                            <p><strong>Destination:</strong> New York</p>
                            <p><strong>Departure Date:</strong> 02/01/2025</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Invoice Information</h6>
                            <p><strong>Invoice #:</strong> INV-2025-001</p>
                            <p><strong>Amount:</strong> $2,450.00</p>
                        </div>
                    </div>
                    <hr>
                    <h6 class="text-muted mb-3">Items in Trip</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Widget A</td>
                                    <td>50</td>
                                    <td>$45.00</td>
                                    <td>$2,250.00</td>
                                </tr>
                                <tr>
                                    <td>Gadget B</td>
                                    <td>4</td>
                                    <td>$50.00</td>
                                    <td>$200.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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

        // Add new trip ticket
        function addNewTicket() {
            const driverName = document.getElementById('driverName').value;
            const destination = document.getElementById('destination').value;
            const departureDate = document.getElementById('departureDate').value;
            const invoiceNo = document.getElementById('invoiceNo').value;

            if (!driverName || !destination || !departureDate || !invoiceNo) {
                alert('Please fill in all required fields');
                return;
            }

            // Generate ticket ID
            const ticketId = 'TT-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');

            // Add row to table
            const table = document.querySelector('tbody');
            const newRow = table.insertRow(0);
            
            newRow.innerHTML = `
                <td><span class="badge bg-light text-dark">${ticketId}</span></td>
                <td>${driverName}</td>
                <td>${destination}</td>
                <td>${departureDate}</td>
                <td>${invoiceNo}</td>
                <td>0</td>
                <td><span class="badge bg-secondary">Pending</span></td>
                <td>
                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
                        <i class="bi bi-eye"></i> View
                    </button>
                </td>
            `;

            // Reset form and close modal
            document.getElementById('addTicketForm').reset();
            bootstrap.Modal.getInstance(document.getElementById('addTicketModal')).hide();
            alert(`Trip Ticket ${ticketId} has been created successfully!`);
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

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>
