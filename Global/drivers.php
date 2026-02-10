<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Drivers</title>
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
                <h3><i class="bi bi-globe logo-icon"></i> <span class="nav-text">Global</span></h3>
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
                        <a class="nav-link" href="branch_records.php">
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
                        <a class="nav-link active" href="drivers.php">
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
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- DRIVERS PAGE -->
            <div id="driversContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-person-badge me-2"></i>All Drivers</h2>
                        <p>Manage and view all drivers across all locations</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search drivers..." id="searchDrivers">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Global Admin</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalDrivers">0</div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeDrivers">0</div>
                            <div class="stat-label">Active Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="onLeave">0</div>
                            <div class="stat-label">On Leave</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="avgRating">0/5</div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Drivers</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="statusFilter" onchange="loadDrivers()">
                                        <option value="">All Status</option>
                                        <option value="active">Active</option>
                                        <option value="on_leave">On Leave</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Location/Branch</label>
                                    <select class="form-select" id="locationFilter" onchange="loadDrivers()">
                                        <option value="">All Locations</option>
                                        <option value="warehouse">Warehouse</option>
                                        <option value="branch1">Branch 1</option>
                                        <option value="branch2">Branch 2</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">License Status</label>
                                    <select class="form-select" id="licenseFilter" onchange="loadDrivers()">
                                        <option value="">All</option>
                                        <option value="valid">Valid</option>
                                        <option value="expiring">Expiring Soon</option>
                                        <option value="expired">Expired</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Sort By</label>
                                    <select class="form-select" id="sortFilter" onchange="loadDrivers()">
                                        <option value="name">Name</option>
                                        <option value="rating">Rating</option>
                                        <option value="trips">Trips Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Driver Information</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Driver ID</th>
                                    <th>Name</th>
                                    <th>License No</th>
                                    <th>License Expiry</th>
                                    <th>Vehicle</th>
                                    <th>Status</th>
                                    <th>Rating</th>
                                    <th>Trips Completed</th>
                                    <th>Phone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="driversTable">
                                <tr>
                                    <td colspan="10" class="text-center py-4">Loading drivers...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Driver Details Modal -->
    <div class="modal fade" id="driverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Driver Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="driverDetails">
                    <!-- Details will be populated here -->
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
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        });

        // Logout function
        function logout() {
            alert('Logging out...');
            window.location.href = '../login.php';
        }

        // Load drivers
        async function loadDrivers() {
            try {
                const status = document.getElementById('statusFilter').value;
                const location = document.getElementById('locationFilter').value;
                const license = document.getElementById('licenseFilter').value;
                const sort = document.getElementById('sortFilter').value;

                const params = new URLSearchParams({
                    status: status,
                    location: location,
                    license: license,
                    sort: sort
                });

                const response = await fetch('api/get_drivers.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    displayDrivers(data.drivers || []);
                    updateDriverStats(data.stats || {});
                }
            } catch (error) {
                console.error('Error loading drivers:', error);
            }
        }

        function displayDrivers(drivers) {
            const tbody = document.getElementById('driversTable');
            
            if (drivers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4">No drivers found</td></tr>';
                return;
            }

            tbody.innerHTML = drivers.map(driver => {
                let statusBadge = 'bg-success';
                if (driver.status === 'on_leave') statusBadge = 'bg-warning';
                else if (driver.status === 'inactive') statusBadge = 'bg-secondary';

                let licenseBadge = 'bg-success';
                if (driver.license_status === 'expiring') licenseBadge = 'bg-warning';
                else if (driver.license_status === 'expired') licenseBadge = 'bg-danger';

                const ratingStars = '⭐'.repeat(Math.round(driver.rating));

                return `
                <tr>
                    <td>${driver.id}</td>
                    <td><strong>${driver.name}</strong></td>
                    <td>${driver.license_no}</td>
                    <td>
                        <span class="badge ${licenseBadge}">
                            ${driver.license_expiry}
                        </span>
                    </td>
                    <td>${driver.vehicle_id}</td>
                    <td>
                        <span class="badge ${statusBadge}">
                            ${driver.status.replace('_', ' ')}
                        </span>
                    </td>
                    <td>${ratingStars} <small>${driver.rating}/5</small></td>
                    <td>${driver.trips_completed}</td>
                    <td>${driver.phone}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="viewDriver(${driver.id})">
                            <i class="bi bi-eye"></i> View
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function updateDriverStats(stats) {
            document.getElementById('totalDrivers').textContent = stats.totalDrivers || 0;
            document.getElementById('activeDrivers').textContent = stats.activeDrivers || 0;
            document.getElementById('onLeave').textContent = stats.onLeave || 0;
            document.getElementById('avgRating').textContent = (stats.avgRating || 0).toFixed(1) + '/5';
        }

        function viewDriver(id) {
            const modal = new bootstrap.Modal(document.getElementById('driverModal'));
            const details = document.getElementById('driverDetails');
            details.innerHTML = '<p>Loading driver details...</p>';
            modal.show();
            
            fetch('api/get_driver_details.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const driver = data.driver;
                        details.innerHTML = `
                            <dl class="row">
                                <dt class="col-sm-4">Driver ID:</dt>
                                <dd class="col-sm-8">${driver.id}</dd>
                                <dt class="col-sm-4">Name:</dt>
                                <dd class="col-sm-8">${driver.name}</dd>
                                <dt class="col-sm-4">Email:</dt>
                                <dd class="col-sm-8">${driver.email}</dd>
                                <dt class="col-sm-4">Phone:</dt>
                                <dd class="col-sm-8">${driver.phone}</dd>
                                <dt class="col-sm-4">License No:</dt>
                                <dd class="col-sm-8">${driver.license_no}</dd>
                                <dt class="col-sm-4">License Expiry:</dt>
                                <dd class="col-sm-8">${driver.license_expiry}</dd>
                                <dt class="col-sm-4">Vehicle:</dt>
                                <dd class="col-sm-8">${driver.vehicle_id}</dd>
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8"><span class="badge bg-success">${driver.status}</span></dd>
                                <dt class="col-sm-4">Rating:</dt>
                                <dd class="col-sm-8">${driver.rating}/5 ⭐</dd>
                                <dt class="col-sm-4">Trips Completed:</dt>
                                <dd class="col-sm-8">${driver.trips_completed}</dd>
                                <dt class="col-sm-4">Joined Date:</dt>
                                <dd class="col-sm-8">${driver.joined_date}</dd>
                            </dl>
                        `;
                    }
                })
                .catch(error => console.error('Error loading driver details:', error));
        }

        // Search drivers
        document.getElementById('searchDrivers').addEventListener('keyup', async function(e) {
            const searchTerm = e.target.value.toLowerCase();
            try {
                const response = await fetch(`api/search_drivers.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                displayDrivers(data.drivers || []);
            } catch (error) {
                console.error('Error searching drivers:', error);
            }
        });

        // Load drivers on page load
        loadDrivers();
    </script>
</body>
</html>