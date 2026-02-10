<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Trip Tickets</title>
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
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">Drivers</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="trip_tickets.php">
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
            <!-- TRIP TICKETS PAGE -->
            <div id="tripsContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-ticket-perforated me-2"></i>All Trip Tickets</h2>
                        <p>Track all deliveries and trip information across all locations</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search trips..." id="searchTrips">
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
                            <div class="stat-value" id="totalTrips">0</div>
                            <div class="stat-label">Total Trips</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sales">
                            <div class="stat-value" id="completedTrips">0</div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="inProgressTrips">0</div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="delayedTrips">0</div>
                            <div class="stat-label">Delayed</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Trips</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="statusFilter" onchange="loadTrips()">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                        <option value="delayed">Delayed</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Origin/Branch</label>
                                    <select class="form-select" id="originFilter" onchange="loadTrips()">
                                        <option value="">All Origins</option>
                                        <option value="warehouse">Warehouse</option>
                                        <option value="branch1">Branch 1</option>
                                        <option value="branch2">Branch 2</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date Range</label>
                                    <input type="date" class="form-control" id="dateFilter" onchange="loadTrips()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Sort By</label>
                                    <select class="form-select" id="sortFilter" onchange="loadTrips()">
                                        <option value="date">Date</option>
                                        <option value="status">Status</option>
                                        <option value="driver">Driver Name</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Trip Ticket Information</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Trip ID</th>
                                    <th>Driver</th>
                                    <th>Origin</th>
                                    <th>Destination</th>
                                    <th>Items</th>
                                    <th>Departure</th>
                                    <th>Estimated Arrival</th>
                                    <th>Actual Arrival</th>
                                    <th>Status</th>
                                    <th>Invoice</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tripsTable">
                                <tr>
                                    <td colspan="11" class="text-center py-4">Loading trips...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row g-3 mt-4">
                    <div class="col-12">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Trip Items Detail</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th>Trip ID</th>
                                            <th>Item Name</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Total Price</th>
                                            <th>Status</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tripItemsTable">
                                        <tr>
                                            <td colspan="7" class="text-center py-4">Select a trip to view items</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Trip Details Modal -->
    <div class="modal fade" id="tripModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Trip Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="tripDetails">
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

        // Load trips
        async function loadTrips() {
            try {
                const status = document.getElementById('statusFilter').value;
                const origin = document.getElementById('originFilter').value;
                const date = document.getElementById('dateFilter').value;
                const sort = document.getElementById('sortFilter').value;

                const params = new URLSearchParams({
                    status: status,
                    origin: origin,
                    date: date,
                    sort: sort
                });

                const response = await fetch('api/get_trip_tickets.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    displayTrips(data.trips || []);
                    updateTripStats(data.stats || {});
                }
            } catch (error) {
                console.error('Error loading trips:', error);
            }
        }

        function displayTrips(trips) {
            const tbody = document.getElementById('tripsTable');
            
            if (trips.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4">No trips found</td></tr>';
                return;
            }

            tbody.innerHTML = trips.map(trip => {
                let statusBadge = 'bg-info';
                if (trip.status === 'completed') statusBadge = 'bg-success';
                else if (trip.status === 'delayed') statusBadge = 'bg-danger';
                else if (trip.status === 'in_progress') statusBadge = 'bg-warning';
                else if (trip.status === 'cancelled') statusBadge = 'bg-secondary';

                return `
                <tr>
                    <td><strong>${trip.trip_id}</strong></td>
                    <td>${trip.driver_name}</td>
                    <td>${trip.origin}</td>
                    <td>${trip.destination}</td>
                    <td>${trip.item_count}</td>
                    <td>${new Date(trip.departure).toLocaleString()}</td>
                    <td>${new Date(trip.eta).toLocaleString()}</td>
                    <td>${trip.actual_arrival ? new Date(trip.actual_arrival).toLocaleString() : '-'}</td>
                    <td>
                        <span class="badge ${statusBadge}">
                            ${trip.status.replace('_', ' ')}
                        </span>
                    </td>
                    <td>${trip.invoice_no || '-'}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="viewTrip(${trip.trip_id})">
                            <i class="bi bi-eye"></i> View
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function updateTripStats(stats) {
            document.getElementById('totalTrips').textContent = stats.totalTrips || 0;
            document.getElementById('completedTrips').textContent = stats.completedTrips || 0;
            document.getElementById('inProgressTrips').textContent = stats.inProgressTrips || 0;
            document.getElementById('delayedTrips').textContent = stats.delayedTrips || 0;
        }

        function viewTrip(tripId) {
            const modal = new bootstrap.Modal(document.getElementById('tripModal'));
            const details = document.getElementById('tripDetails');
            details.innerHTML = '<p>Loading trip details...</p>';
            modal.show();
            
            fetch('api/get_trip_details.php?id=' + tripId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const trip = data.trip;
                        details.innerHTML = `
                            <dl class="row">
                                <dt class="col-sm-4">Trip ID:</dt>
                                <dd class="col-sm-8">${trip.trip_id}</dd>
                                <dt class="col-sm-4">Driver:</dt>
                                <dd class="col-sm-8">${trip.driver_name}</dd>
                                <dt class="col-sm-4">Vehicle:</dt>
                                <dd class="col-sm-8">${trip.vehicle_id}</dd>
                                <dt class="col-sm-4">Origin:</dt>
                                <dd class="col-sm-8">${trip.origin}</dd>
                                <dt class="col-sm-4">Destination:</dt>
                                <dd class="col-sm-8">${trip.destination}</dd>
                                <dt class="col-sm-4">Departure:</dt>
                                <dd class="col-sm-8">${new Date(trip.departure).toLocaleString()}</dd>
                                <dt class="col-sm-4">ETA:</dt>
                                <dd class="col-sm-8">${new Date(trip.eta).toLocaleString()}</dd>
                                <dt class="col-sm-4">Actual Arrival:</dt>
                                <dd class="col-sm-8">${trip.actual_arrival ? new Date(trip.actual_arrival).toLocaleString() : 'Not arrived yet'}</dd>
                                <dt class="col-sm-4">Items:</dt>
                                <dd class="col-sm-8">${trip.item_count}</dd>
                                <dt class="col-sm-4">Invoice:</dt>
                                <dd class="col-sm-8">${trip.invoice_no || '-'}</dd>
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8"><span class="badge bg-success">${trip.status}</span></dd>
                                <dt class="col-sm-4">Notes:</dt>
                                <dd class="col-sm-8">${trip.notes || 'No notes'}</dd>
                            </dl>
                        `;
                    }
                })
                .catch(error => console.error('Error loading trip details:', error));
        }

        // Search trips
        document.getElementById('searchTrips').addEventListener('keyup', async function(e) {
            const searchTerm = e.target.value.toLowerCase();
            try {
                const response = await fetch(`api/search_trips.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                displayTrips(data.trips || []);
            } catch (error) {
                console.error('Error searching trips:', error);
            }
        });

        // Load trips on page load
        loadTrips();
    </script>
</body>
</html>