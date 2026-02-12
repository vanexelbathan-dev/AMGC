<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Branch Records</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
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
                    <!-- Burger icon moved before logo -->
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
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
                        <a class="nav-link active" href="branch_records.php">
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
                        <a class="nav-link" href="drivers.php">
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
            <!-- BRANCH RECORDS PAGE -->
            <div id="recordsContent" class="page-content active">
                <div class="navbar-top">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Branch Records</h2>
                        <p>View all activities and transactions from branch managers</p>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalRecords">0</div>
                            <div class="stat-label">Total Records</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="totalTransactions">$0</div>
                            <div class="stat-label">Total Transactions</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="activeBranches">0</div>
                            <div class="stat-label">Active Branches</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Records</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="form-label">Branch</label>
                                    <select class="form-select" id="branchFilter" onchange="loadRecords()">
                                        <option value="">All Branches</option>
                                        <option value="branch1">Branch 1</option>
                                        <option value="branch2">Branch 2</option>
                                        <option value="branch3">Branch 3</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Record Type</label>
                                    <select class="form-select" id="recordTypeFilter" onchange="loadRecords()">
                                        <option value="">All Types</option>
                                        <option value="sales_order">Sales Order</option>
                                        <option value="purchase_order">Purchase Order</option>
                                        <option value="inventory">Inventory Update</option>
                                        <option value="delivery">Delivery</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" id="dateFromFilter" onchange="loadRecords()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" id="dateToFilter" onchange="loadRecords()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


                <div class="data-table">
                    <div class="table-header">
                        <h5>Branch Activity Log</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Branch</th>
                                    <th>Manager</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="recordsTable">
                                <tr>
                                    <td colspan="9" class="text-center py-4">Loading records...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Details Modal -->
    <div class="modal fade" id="recordModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="recordDetails">
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

    // Mobile menu toggle (legacy - for compatibility)
    document.getElementById('mobileMenuBtn').addEventListener('click', function() {
        toggleSidebar();
    });

    // Logout function
    function logout() {
        alert('Logging out...');
        window.location.href = '../login.php';
    }

    // Load records
    async function loadRecords() {
        try {
            const branch = document.getElementById('branchFilter').value;
            const recordType = document.getElementById('recordTypeFilter').value;
            const dateFrom = document.getElementById('dateFromFilter').value;
            const dateTo = document.getElementById('dateToFilter').value;

            const params = new URLSearchParams({
                branch: branch,
                type: recordType,
                dateFrom: dateFrom,
                dateTo: dateTo
            });

            const response = await fetch('api/get_branch_records.php?' + params);
            const data = await response.json();
            
            if (data.success) {
                displayRecords(data.records || []);
                updateRecordStats(data.stats || {});
            } else {
                console.log('No records found');
                displayRecords([]);
                updateRecordStats({});
            }
        } catch (error) {
            console.error('Error loading records:', error);
            displayRecords([]);
            updateRecordStats({});
        }
    }

    function displayRecords(records) {
        const tbody = document.getElementById('recordsTable');
        
        if (records.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4">No records found</td></tr>';
            return;
        }

        tbody.innerHTML = records.map(record => `
            <tr>
                <td>${record.id}</td>
                <td><strong>${record.branch}</strong></td>
                <td>${record.manager}</td>
                <td><span class="badge bg-info">${record.type}</span></td>
                <td>${record.description}</td>
                <td>$${(record.amount || 0).toLocaleString()}</td>
                <td>${new Date(record.date).toLocaleDateString()}</td>
                <td>
                    <span class="badge ${record.status === 'completed' ? 'bg-success' : record.status === 'pending' ? 'bg-warning' : 'bg-danger'}">
                        ${record.status}
                    </span>
                </td>
                <td>
                    <button class="btn btn-sm btn-info" onclick="viewRecord(${record.id})">
                        <i class="bi bi-eye"></i> View
                    </button>
                </td>
            </tr>
        `).join('');
    }

    function updateRecordStats(stats) {
        document.getElementById('totalRecords').textContent = stats.totalRecords || 0;
        document.getElementById('totalTransactions').textContent = '$' + (stats.totalTransactions || 0).toLocaleString();
        document.getElementById('activeBranches').textContent = stats.activeBranches || 0;
    }

    function viewRecord(id) {
        // Load and display record details
        const modal = new bootstrap.Modal(document.getElementById('recordModal'));
        const details = document.getElementById('recordDetails');
        details.innerHTML = '<p>Loading record details...</p>';
        modal.show();
        
        // Fetch record details
        fetch('api/get_record_details.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    details.innerHTML = `
                        <dl class="row">
                            <dt class="col-sm-4">Record ID:</dt>
                            <dd class="col-sm-8">${data.record.id}</dd>
                            <dt class="col-sm-4">Branch:</dt>
                            <dd class="col-sm-8">${data.record.branch}</dd>
                            <dt class="col-sm-4">Manager:</dt>
                            <dd class="col-sm-8">${data.record.manager}</dd>
                            <dt class="col-sm-4">Type:</dt>
                            <dd class="col-sm-8">${data.record.type}</dd>
                            <dt class="col-sm-4">Description:</dt>
                            <dd class="col-sm-8">${data.record.description}</dd>
                            <dt class="col-sm-4">Amount:</dt>
                            <dd class="col-sm-8">$${(data.record.amount || 0).toLocaleString()}</dd>
                            <dt class="col-sm-4">Date:</dt>
                            <dd class="col-sm-8">${new Date(data.record.date).toLocaleString()}</dd>
                            <dt class="col-sm-4">Status:</dt>
                            <dd class="col-sm-8"><span class="badge ${data.record.status === 'completed' ? 'bg-success' : data.record.status === 'pending' ? 'bg-warning' : 'bg-danger'}">${data.record.status}</span></dd>
                        </dl>
                    `;
                } else {
                    details.innerHTML = '<p class="text-danger">Failed to load record details.</p>';
                }
            })
            .catch(error => {
                console.error('Error loading record details:', error);
                details.innerHTML = '<p class="text-danger">Error loading record details.</p>';
            });
    }

    // Search records (only if search input exists)
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchRecords');
        if (searchInput) {
            searchInput.addEventListener('keyup', async function(e) {
                const searchTerm = e.target.value.toLowerCase();
                try {
                    const response = await fetch(`api/search_branch_records.php?q=${encodeURIComponent(searchTerm)}`);
                    const data = await response.json();
                    displayRecords(data.records || []);
                } catch (error) {
                    console.error('Error searching records:', error);
                }
            });
        }
    });

    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Branch Records page loaded!");
        
        // Initialize sidebar
        initializeSidebar();
        
        // Setup mobile toggle button (if using new button)
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
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                (!mobileMenuBtn || !mobileMenuBtn.contains(event.target)) &&
                (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                (!overlay || !overlay.contains(event.target))) {
                closeMobileSidebar();
            }
        });

        // Add resize event listener
        window.addEventListener('resize', handleSidebarResize);

        // Load records on page load
        loadRecords();
        
        // Add event listeners to filters
        document.getElementById('branchFilter').addEventListener('change', loadRecords);
        document.getElementById('recordTypeFilter').addEventListener('change', loadRecords);
        document.getElementById('dateFromFilter').addEventListener('change', loadRecords);
        document.getElementById('dateToFilter').addEventListener('change', loadRecords);
        
        // Set default dates
        const today = new Date();
        const oneMonthAgo = new Date();
        oneMonthAgo.setMonth(today.getMonth() - 1);
        
        document.getElementById('dateFromFilter').value = oneMonthAgo.toISOString().split('T')[0];
        document.getElementById('dateToFilter').value = today.toISOString().split('T')[0];
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
        // Ctrl + R for refresh records
        else if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            loadRecords();
        }
        // Ctrl + F for search focus (if search input exists)
        else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('searchRecords');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });
</script>
</body>
</html>