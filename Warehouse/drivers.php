<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drivers - Warehouse</title>
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
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="drivers.php">
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
                    <h2><i class="bi bi-person-badge me-2"></i>Driver Management</h2>
                    <p>Manage driver information and track delivery performance</p>
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

            <!-- Driver Stats -->
            <div class="row g-3 mb-4">

                <!-- Total Drivers -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card drivers">
                        <div class="stat-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div>
                            <div class="stat-value">15</div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                    </div>
                </div>

                <!-- Active Drivers -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value">12</div>
                            <div class="stat-label">Active Drivers</div>
                        </div>
                    </div>
                </div>

                <!-- Trips Completed -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card trips">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="stat-value">145</div>
                            <div class="stat-label">Trips Completed</div>
                        </div>
                    </div>
                </div>

                <!-- Avg Rating -->
                <div class="col-md-3 mb-3">
                    <div class="stat-card stock">
                        <div class="stat-icon">
                            <i class="bi bi-star"></i>
                        </div>
                        <div>
                            <div class="stat-value">4.6/5</div>
                            <div class="stat-label">Avg Rating</div>
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
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by name or driver ID...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="On Leave">On Leave</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addDriverModal">
                                <i class="bi bi-plus-lg"></i> Add Driver
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Drivers Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Driver ID</th>
                                <th>Driver Name</th>
                                <th>Phone</th>
                                <th>License #</th>
                                <th>Vehicle</th>
                                <th>Trips</th>
                                <th>Status</th>
                                <th>Rating</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge bg-light text-dark">DRV-001</span></td>
                                <td>John Smith</td>
                                <td>(555) 123-4567</td>
                                <td>DL-789456</td>
                                <td>Van-001 (Ford Transit)</td>
                                <td>32</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td><i class="bi bi-star-fill text-warning"></i> 4.8</td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">DRV-002</span></td>
                                <td>Sarah Jones</td>
                                <td>(555) 234-5678</td>
                                <td>DL-987654</td>
                                <td>Van-002 (Ford Transit)</td>
                                <td>28</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td><i class="bi bi-star-fill text-warning"></i> 4.5</td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">DRV-003</span></td>
                                <td>Mike Davis</td>
                                <td>(555) 345-6789</td>
                                <td>DL-456789</td>
                                <td>Truck-001 (Freightliner)</td>
                                <td>45</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td><i class="bi bi-star-fill text-warning"></i> 4.7</td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">DRV-004</span></td>
                                <td>Jessica White</td>
                                <td>(555) 456-7890</td>
                                <td>DL-321654</td>
                                <td>Van-003 (Mercedes Sprinter)</td>
                                <td>35</td>
                                <td><span class="badge bg-success">Active</span></td>
                                <td><i class="bi bi-star-fill text-warning"></i> 4.6</td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">DRV-005</span></td>
                                <td>David Brown</td>
                                <td>(555) 567-8901</td>
                                <td>DL-654321</td>
                                <td>Truck-002 (Volvo)</td>
                                <td>38</td>
                                <td><span class="badge bg-warning">On Leave</span></td>
                                <td><i class="bi bi-star-fill text-warning"></i> 4.4</td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">DRV-006</span></td>
                                <td>Robert Martinez</td>
                                <td>(555) 678-9012</td>
                                <td>DL-159753</td>
                                <td>Van-004 (Ford Transit)</td>
                                <td>12</td>
                                <td><span class="badge bg-secondary">Inactive</span></td>
                                <td><i class="bi bi-star-fill text-warning"></i> 4.2</td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal">
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

    <!-- Add Driver Modal -->
    <div class="modal fade" id="addDriverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Driver</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addDriverForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver Name</label>
                                <input type="text" class="form-control" id="driverName" required placeholder="Full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="driverPhone" required placeholder="(555) 000-0000">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Number</label>
                                <input type="text" class="form-control" id="licenseNo" required placeholder="e.g., DL-123456">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Expiry Date</label>
                                <input type="date" class="form-control" id="licenseExpiry" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vehicle Assignment</label>
                                <select class="form-select" id="vehicleAssignment" required>
                                    <option value="">Select Vehicle</option>
                                    <option value="Van-001 (Ford Transit)">Van-001 (Ford Transit)</option>
                                    <option value="Van-002 (Mercedes Sprinter)">Van-002 (Mercedes Sprinter)</option>
                                    <option value="Truck-001 (Freightliner)">Truck-001 (Freightliner)</option>
                                    <option value="Truck-002 (Volvo)">Truck-002 (Volvo)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="driverStatus" required>
                                    <option value="Active" selected>Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="On Leave">On Leave</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <input type="text" class="form-control" id="driverAddress" required placeholder="Street address">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="addNewDriver()">Add Driver</button>
                </div>
            </div>
        </div>
    </modal>

    <!-- View Driver Details Modal -->
    <div class="modal fade" id="viewDriverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Driver Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <h6 class="text-muted">Personal Information</h6>
                            <p><strong>Driver Name:</strong> John Smith</p>
                            <p><strong>Driver ID:</strong> DRV-001</p>
                            <p><strong>Phone:</strong> (555) 123-4567</p>
                            <p><strong>Address:</strong> 123 Main St, New York, NY 10001</p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Current Status</h6>
                            <p class="mb-2"><span class="badge bg-success">Active</span></p>
                            <p><strong>Rating:</strong> <i class="bi bi-star-fill text-warning"></i> 4.8/5</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted">License Information</h6>
                            <p><strong>License #:</strong> DL-789456</p>
                            <p><strong>Expiry Date:</strong> 05/15/2027</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Vehicle Assignment</h6>
                            <p><strong>Vehicle:</strong> Van-001</p>
                            <p><strong>Type:</strong> Ford Transit</p>
                        </div>
                    </div>
                    <hr>
                    <h6 class="text-muted mb-3">Performance Statistics</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="bg-light p-3 rounded text-center">
                                <p class="text-muted mb-1">Total Trips</p>
                                <p class="fs-5 fw-bold">32</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-light p-3 rounded text-center">
                                <p class="text-muted mb-1">Completed</p>
                                <p class="fs-5 fw-bold">31</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-light p-3 rounded text-center">
                                <p class="text-muted mb-1">On-Time %</p>
                                <p class="fs-5 fw-bold">96%</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning">Edit Profile</button>
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

        // Add new driver
        function addNewDriver() {
            const driverName = document.getElementById('driverName').value;
            const phone = document.getElementById('driverPhone').value;
            const licenseNo = document.getElementById('licenseNo').value;
            const vehicle = document.getElementById('vehicleAssignment').value;
            const status = document.getElementById('driverStatus').value;

            if (!driverName || !phone || !licenseNo || !vehicle || !status) {
                alert('Please fill in all required fields');
                return;
            }

            // Generate driver ID
            const driverId = 'DRV-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');
            const rating = (Math.random() * 0.8 + 4.0).toFixed(1); // Random rating between 4.0 and 4.8
            let statusBadge = 'bg-success';
            if (status === 'Inactive') statusBadge = 'bg-secondary';
            if (status === 'On Leave') statusBadge = 'bg-warning';

            // Add row to table
            const table = document.querySelector('tbody');
            const newRow = table.insertRow(0);
            
            newRow.innerHTML = `
                <td><span class="badge bg-light text-dark">${driverId}</span></td>
                <td>${driverName}</td>
                <td>${phone}</td>
                <td>${licenseNo}</td>
                <td>${vehicle}</td>
                <td>0</td>
                <td><span class="badge ${statusBadge}">${status}</span></td>
                <td><i class="bi bi-star-fill text-warning"></i> ${rating}</td>
                <td>
                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewDriverModal">
                        <i class="bi bi-eye"></i> View
                    </button>
                </td>
            `;

            // Reset form and close modal
            document.getElementById('addDriverForm').reset();
            bootstrap.Modal.getInstance(document.getElementById('addDriverModal')).hide();
            alert(`Driver ${driverId} - ${driverName} has been added successfully!`);
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
