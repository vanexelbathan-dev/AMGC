<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Drivers</title>
    <link rel="stylesheet" href="../css/global.css">
     <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                <button class="desktop-toggle-btn" id="desktopToggleBtn">
                    <i class="bi bi-list"></i>
                </button>    
                 <img src="../Pictures/amgc3dLogo.png" alt="Logo" class="logo-icon">
                <span class="nav-text">Global</span>
            </h3>
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
             <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar">AD</div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar">Quality Control</span>
                        <span class="user-role-sidebar">QC Officer</span>
                    </div>
                </div>
                
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- DRIVERS PAGE -->
            <div id="driversContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>

                    <div class="page-title">
                        <h2>All Drivers</h2>
                        <p>Manage and view all drivers across all locations</p>
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

    <style>
        /* Mobile responsive adjustments ONLY */
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
   <script>
        // ================= SIDEBAR FUNCTIONS =================
        // Toggle sidebar collapse/expand
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                // On mobile, toggle active state
                sidebar.classList.toggle('active');
                
                // Create overlay for mobile
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
                    // If overlay exists, toggle its active state
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
                // On desktop, toggle between expanded and collapsed
                sidebar.classList.toggle('collapsed');
                
                // Store preference in localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                // Show/hide nav text
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        // Close mobile sidebar
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

        // Initialize sidebar when page loads
        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            // Load saved preference from localStorage for desktop
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // On mobile, always start with closed sidebar
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        // Handle window resize for sidebar
        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                // Desktop mode - remove mobile overlay
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                // Load saved preference
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    // Adjust main content margin
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                // Mobile mode - always show expanded when visible
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                // Adjust main content margin
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

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

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Drivers Management page loaded!");
            
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
                const mobileBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            // Add resize event listener
            window.addEventListener('resize', handleSidebarResize);

            // Load drivers on page load
            loadDrivers();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + B to toggle sidebar (desktop only)
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            // Escape to close sidebar on mobile
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            // Ctrl + R for refresh
            else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                loadDrivers();
            }
        });
    </script>
</body>
</html>