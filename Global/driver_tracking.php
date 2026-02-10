<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Driver Tracking</title>
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
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- DRIVER TRACKING PAGE -->
            <div id="trackingContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-geo-alt me-2"></i>Driver Tracking</h2>
                        <p>Track all drivers' locations, routes, and trip tickets in real-time</p>
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
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalDrivers">0</div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeDrivers">0</div>
                            <div class="stat-label">Active On Delivery</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="completedTrips">0</div>
                            <div class="stat-label">Completed Today</div>
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
                                    <label class="form-label">Driver Name</label>
                                    <input type="text" class="form-control" id="driverNameFilter" onchange="loadTracking()" placeholder="Filter by name">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Location</label>
                                    <select class="form-select" id="locationFilter" onchange="loadTracking()">
                                        <option value="">All Locations</option>
                                        <option value="warehouse">Warehouse</option>
                                        <option value="branch1">Branch 1</option>
                                        <option value="branch2">Branch 2</option>
                                        <option value="in_transit">In Transit</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Trip Ticket</label>
                                    <input type="text" class="form-control" id="tripTicketFilter" onchange="loadTracking()" placeholder="Filter by trip ID">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="statusFilter" onchange="loadTracking()">
                                        <option value="">All Status</option>
                                        <option value="active">Active</option>
                                        <option value="idle">Idle</option>
                                        <option value="off_duty">Off Duty</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="data-table">
                            <div class="table-header">
                                <h5><i class="bi bi-map"></i> Live Driver Map</h5>
                            </div>
                            <div id="driverMap" style="width: 100%; height: 500px; border-radius: 8px; overflow: hidden;"></div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Live Driver Locations</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Driver ID</th>
                                    <th>Name</th>
                                    <th>Current Location</th>
                                    <th>Current Trip</th>
                                    <th>Destination</th>
                                    <th>Status</th>
                                    <th>Last Update</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="driversTable">
                                <tr>
                                    <td colspan="8" class="text-center py-4">Loading driver data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row g-3 mt-4">
                    <div class="col-12">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Trip Details</h5>
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
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tripsTable">
                                        <tr>
                                            <td colspan="9" class="text-center py-4">Loading trip data...</td>
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
            .col-md-4 {
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
            
            .col-md-4 {
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

    <!-- Leaflet CSS and JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let map;
        let markers = {};
        let popups = {};
        let userLocation = null;

        // Create truck icon SVG for Leaflet
        const truckIcon = L.divIcon({
            html: `
                <div style="background: #FF6B35; border: 2px solid white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.3); transform: rotate(-45deg);">
                    <i class="bi bi-truck" style="color: white; font-size: 20px; transform: rotate(45deg);"></i>
                </div>
            `,
            className: 'truck-marker',
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -20]
        });

        // Initialize map with Leaflet
        function initMap() {
            map = L.map('driverMap').setView([40.7128, -74.0060], 12); // Default to NYC

            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);

            // Try to get user's current location
            if (navigator.geolocation) {
                navigator.geolocation.watchPosition(
                    (position) => {
                        const { latitude, longitude } = position.coords;
                        userLocation = { lat: latitude, lng: longitude };
                        map.setView([latitude, longitude], 12);
                    },
                    (error) => {
                        console.log('[v0] Geolocation error:', error.message);
                    },
                    { enableHighAccuracy: true, maximumAge: 10000, timeout: 5000 }
                );
            }
        }

        // Create or update marker for driver with geolocation
        function updateMarker(driver) {
            const lat = parseFloat(driver.latitude);
            const lng = parseFloat(driver.longitude);
            
            if (!isNaN(lat) && !isNaN(lng)) {
                if (markers[driver.id]) {
                    // Update existing marker position
                    markers[driver.id].setLatLng([lat, lng]);
                    // Update popup content
                    const popupContent = createPopupContent(driver);
                    markers[driver.id].setPopupContent(popupContent);
                } else {
                    // Create new marker
                    const marker = L.marker([lat, lng], { icon: truckIcon })
                        .addTo(map)
                        .bindPopup(createPopupContent(driver))
                        .on('click', () => {
                            marker.openPopup();
                        });

                    markers[driver.id] = marker;
                }
            }
        }

        // Create popup content for marker
        function createPopupContent(driver) {
            return `
                <div style="min-width: 200px; font-family: Arial; font-size: 12px;">
                    <strong style="font-size: 14px;">${driver.name}</strong><br>
                    <i class="bi bi-ticket"></i> Trip: <strong>${driver.current_trip || 'None'}</strong><br>
                    <i class="bi bi-geo-alt"></i> Location: ${driver.current_location}<br>
                    <i class="bi bi-map-pin"></i> Destination: ${driver.destination || 'N/A'}<br>
                    <span style="display: inline-block; margin-top: 8px; padding: 4px 8px; background: #28a745; color: white; border-radius: 4px; font-size: 11px;">
                        ${driver.status.replace('_', ' ').toUpperCase()}
                    </span><br>
                    <button onclick="focusOnDriver(${driver.id})" style="margin-top: 8px; padding: 6px 12px; background: #FF6B35; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;">View Details</button>
                </div>
            `;
        }

        // Focus map on specific driver
        function focusOnDriver(driverId) {
            if (markers[driverId]) {
                const marker = markers[driverId];
                const latlng = marker.getLatLng();
                map.setView([latlng.lat, latlng.lng], 16);
                marker.openPopup();
                // Highlight the driver row in the table
                document.querySelectorAll('#driversTable tr').forEach(row => row.classList.remove('table-active'));
                document.querySelector(`tr[data-driver-id="${driverId}"]`)?.classList.add('table-active');
            }
        }

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

        // Load tracking data
        async function loadTracking() {
            try {
                const driverName = document.getElementById('driverNameFilter').value;
                const location = document.getElementById('locationFilter').value;
                const tripTicket = document.getElementById('tripTicketFilter').value;
                const status = document.getElementById('statusFilter').value;

                const params = new URLSearchParams({
                    driverName: driverName,
                    location: location,
                    tripTicket: tripTicket,
                    status: status
                });

                const response = await fetch('api/get_driver_tracking.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    displayDrivers(data.drivers || []);
                    displayTrips(data.trips || []);
                    updateTrackingStats(data.stats || {});
                } else {
                    console.log('No data found');
                }
            } catch (error) {
                console.error('Error loading tracking data:', error);
            }
        }

        function displayDrivers(drivers) {
            const tbody = document.getElementById('driversTable');
            
            if (drivers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No drivers found</td></tr>';
                return;
            }

            tbody.innerHTML = drivers.map(driver => {
                let statusBadge = 'bg-success';
                if (driver.status === 'idle') statusBadge = 'bg-warning';
                else if (driver.status === 'off_duty') statusBadge = 'bg-secondary';

                // Update map markers
                updateMarker(driver);

                return `
                <tr data-driver-id="${driver.id}">
                    <td>${driver.id}</td>
                    <td><strong>${driver.name}</strong></td>
                    <td>
                        <i class="bi bi-geo-alt"></i> ${driver.current_location}
                    </td>
                    <td>${driver.current_trip || '-'}</td>
                    <td>${driver.destination || '-'}</td>
                    <td>
                        <span class="badge ${statusBadge}">
                            ${driver.status.replace('_', ' ')}
                        </span>
                    </td>
                    <td>${new Date(driver.last_update).toLocaleTimeString()}</td>
                    <td>
                        <button class="btn btn-sm btn-warning" onclick="focusOnDriver(${driver.id})" title="View on Map">
                            <i class="bi bi-geo-alt-fill"></i> View
                        </button>
                        <button class="btn btn-sm btn-info" onclick="viewDriver(${driver.id})">
                            <i class="bi bi-eye"></i> Details
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function displayTrips(trips) {
            const tbody = document.getElementById('tripsTable');
            
            if (trips.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4">No trips found</td></tr>';
                return;
            }

            tbody.innerHTML = trips.map(trip => {
                let statusBadge = 'bg-info';
                if (trip.status === 'completed') statusBadge = 'bg-success';
                else if (trip.status === 'delayed') statusBadge = 'bg-danger';

                return `
                <tr>
                    <td><strong>${trip.trip_id}</strong></td>
                    <td>${trip.driver_name}</td>
                    <td>${trip.origin}</td>
                    <td>${trip.destination}</td>
                    <td>${trip.item_count} items</td>
                    <td>${new Date(trip.departure).toLocaleString()}</td>
                    <td>${new Date(trip.eta).toLocaleString()}</td>
                    <td>
                        <span class="badge ${statusBadge}">
                            ${trip.status}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="viewTrip(${trip.trip_id})">
                            <i class="bi bi-eye"></i> View
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function updateTrackingStats(stats) {
            document.getElementById('totalDrivers').textContent = stats.totalDrivers || 0;
            document.getElementById('activeDrivers').textContent = stats.activeDrivers || 0;
            document.getElementById('completedTrips').textContent = stats.completedTrips || 0;
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
                                <dt class="col-sm-4">License No:</dt>
                                <dd class="col-sm-8">${driver.license_no}</dd>
                                <dt class="col-sm-4">Vehicle:</dt>
                                <dd class="col-sm-8">${driver.vehicle_id}</dd>
                                <dt class="col-sm-4">Current Location:</dt>
                                <dd class="col-sm-8">
                                    <i class="bi bi-geo-alt"></i> ${driver.current_location}
                                </dd>
                                <dt class="col-sm-4">Current Trip:</dt>
                                <dd class="col-sm-8">${driver.current_trip || 'None'}</dd>
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8"><span class="badge bg-success">${driver.status}</span></dd>
                                <dt class="col-sm-4">Trips Completed Today:</dt>
                                <dd class="col-sm-8">${driver.trips_completed}</dd>
                            </dl>
                        `;
                    }
                })
                .catch(error => console.error('Error loading driver details:', error));
        }

        function viewTrip(tripId) {
            alert('Trip details for trip: ' + tripId);
            // Implement trip details view
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

        // Initialize map on page load
        window.addEventListener('load', function() {
            initMap();
            loadTracking();
        });

        // Auto-refresh tracking data every 30 seconds
        setInterval(loadTracking, 30000);
    </script>
</body>
</html>