<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Driver Tracking</title>
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
            <!-- DRIVER TRACKING PAGE -->
            <div id="trackingContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>

                    <div class="page-title">
                        <h2>Driver Tracking</h2>
                        <p>Track all drivers' locations, routes, and trip tickets in real-time</p>
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
    <!-- Leaflet CSS and JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>

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
        const searchInput = document.getElementById('searchDrivers');
        if (searchInput) {
            searchInput.addEventListener('keyup', async function(e) {
                const searchTerm = e.target.value.toLowerCase();
                try {
                    const response = await fetch(`api/search_drivers.php?q=${encodeURIComponent(searchTerm)}`);
                    const data = await response.json();
                    displayDrivers(data.drivers || []);
                } catch (error) {
                    console.error('Error searching drivers:', error);
                }
            });
        }

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Driver Tracking page loaded!");
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button - support both button IDs
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function(e) {
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
                const mobileBtn = document.getElementById('mobileToggleBtn') || document.getElementById('mobileMenuBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            // Add resize event listener
            window.addEventListener('resize', handleSidebarResize);

            // Initialize map and load tracking data
            initMap();
            loadTracking();
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
                loadTracking();
            }
        });

        // Auto-refresh tracking data every 30 seconds
        setInterval(loadTracking, 30000);
    </script>
</body>
</html>