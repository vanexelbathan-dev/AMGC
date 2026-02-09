<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Branch Records</title>
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
            <!-- BRANCH RECORDS PAGE -->
            <div id="recordsContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-file-text me-2"></i>Branch Records</h2>
                        <p>View all activities and transactions from branch managers</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search records..." id="searchRecords">
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
                }
            } catch (error) {
                console.error('Error loading records:', error);
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
                    <td>$${record.amount.toLocaleString()}</td>
                    <td>${new Date(record.date).toLocaleDateString()}</td>
                    <td>
                        <span class="badge ${record.status === 'completed' ? 'bg-success' : 'bg-warning'}">
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
                                <dd class="col-sm-8">$${data.record.amount.toLocaleString()}</dd>
                                <dt class="col-sm-4">Date:</dt>
                                <dd class="col-sm-8">${new Date(data.record.date).toLocaleString()}</dd>
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8"><span class="badge bg-success">${data.record.status}</span></dd>
                            </dl>
                        `;
                    }
                })
                .catch(error => console.error('Error loading record details:', error));
        }

        // Search records
        document.getElementById('searchRecords').addEventListener('keyup', async function(e) {
            const searchTerm = e.target.value.toLowerCase();
            try {
                const response = await fetch(`api/search_branch_records.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                displayRecords(data.records || []);
            } catch (error) {
                console.error('Error searching records:', error);
            }
        });

        // Load records on page load
        loadRecords();
    </script>
</body>
</html>
