<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Tickets - Branch Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Font Awesome for more icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- MOBILE MENU BUTTON -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="bi bi-list"></i>
    </button>

    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar (SAME AS CURRENT INVENTORY) -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                 <h3><img src="../Pictures/nobg.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_orders.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-x-circle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_orders.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <!-- TRIP TICKETS ACTIVE LINK -->
                    <li class="nav-item">
                        <a class="nav-link active" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>

                    <hr class="sidebar-divider">

                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-info-circle"></i>
                            <span class="nav-text">About</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-chat-left-text"></i>
                            <span class="nav-text">Feedback</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- TRIP TICKETS PAGE -->
            <div id="tripTicketsContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-ticket-perforated me-2"></i>Trip Tickets</h2>
                        <p>Manage and track trip tickets for deliveries</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search trip tickets..." id="tripSearch">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top" id="userAvatar">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top" id="userName">Admin User</span>
                                <span class="user-role-top" id="userRole">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </div>
                </div>

                <!-- Quick Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card total">
                            <i class="bi bi-ticket-perforated stat-icon"></i>
                            <div class="stat-value" id="totalTripTickets">15</div>
                            <div class="stat-label">Total Tickets</div>
                            <small class="d-block mt-2"><i class="bi bi-arrow-up-right"></i> 3 new today</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock stat-icon"></i>
                            <div class="stat-value" id="pendingTrips">4</div>
                            <div class="stat-label">Pending</div>
                            <small class="d-block mt-2">Waiting for dispatch</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value" id="activeTrips">6</div>
                            <div class="stat-label">In Transit</div>
                            <small class="d-block mt-2">Currently on delivery</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="completedTrips">5</div>
                            <div class="stat-label">Completed</div>
                            <small class="d-block mt-2">Delivered successfully</small>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-3">
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-primary" onclick="createNewTripTicket()">
                                            <i class="bi bi-plus-circle me-2"></i> New Trip Ticket
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="printTripTickets()">
                                            <i class="bi bi-printer me-2"></i> Print
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" placeholder="Search delivery ID, driver, or location..." id="searchInput">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" id="statusFilter" onchange="filterTripTickets()">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="in_transit">In Transit</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                        <option value="returned">Returned</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="finalizedFilter" onchange="filterTripTickets()">
                                        <option value="">Finalization Status</option>
                                        <option value="finalized">Finalized</option>
                                        <option value="not_finalized">Not Finalized</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Table -->
                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5><i class="bi bi-list-check me-2"></i>Trip Ticket List</h5>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary" onclick="refreshTripTickets()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                            <button class="btn btn-sm btn-outline-primary" onclick="exportTripTickets()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" class="form-check-input" id="selectAll">
                                    </th>
                                    <th>Delivery ID</th>
                                    <th>Trip Ticket ID</th>
                                    <th>Invoice ID</th>
                                    <th>Status</th>
                                    <th>Proof of Delivery</th>
                                    <th>Customer Name</th>
                                    <th>Customer Signature</th>
                                    <th>Driver's Name</th>
                                    <th>Driver's Signature</th>
                                    <th>Reason</th>
                                    <th>Location</th>
                                    <th>Encoded By</th>
                                    <th>Encoded At</th>
                                    <th>Company ID</th>
                                    <th>Is Finalized?</th>
                                    <th>Finalized At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tripTicketsTable">
                                <!-- Data will be populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <div class="table-footer d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="text-muted">
                            Showing <span id="startRow">1</span> to <span id="endRow">10</span> of <span id="totalRows">15</span> entries
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">Next</a></li>
                            </ul>
                        </nav>
                    </div>
                </div>

                <!-- Additional Cards -->
                <div class="row g-3 mt-4">
                    <div class="col-lg-6">
                        <div class="data-table">
                            <div class="table-header">
                                <h5><i class="bi bi-exclamation-triangle me-2"></i>Pending Signature</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th>Delivery ID</th>
                                            <th>Customer</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pendingSignaturesTable">
                                        <!-- Pending signatures will be populated -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="data-table">
                            <div class="table-header">
                                <h5><i class="bi bi-calendar-check me-2"></i>Recent Finalized Tickets</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th>Trip Ticket ID</th>
                                            <th>Finalized At</th>
                                            <th>Finalized By</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recentFinalizedTable">
                                        <!-- Recent finalized tickets will be populated -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Sample data for demonstration with all required columns
        const tripTickets = [
            {
                deliveryId: 'DEL-2024-00145',
                tripTicketId: 'TTK-2024-001',
                invoiceId: 'INV-2024-00123',
                status: 'delivered',
                proofOfDelivery: 'Yes',
                customerName: 'Juan Dela Cruz',
                customerSignature: 'Available',
                driverName: 'Pedro Reyes',
                driverSignature: 'Available',
                reason: 'Regular delivery',
                location: 'Makati City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-15 08:30',
                companyId: 'COMP-001',
                isFinalized: 'Yes',
                finalizedAt: '2024-01-15 16:45'
            },
            {
                deliveryId: 'DEL-2024-00146',
                tripTicketId: 'TTK-2024-002',
                invoiceId: 'INV-2024-00124',
                status: 'in_transit',
                proofOfDelivery: 'Pending',
                customerName: 'Maria Santos',
                customerSignature: 'Pending',
                driverName: 'Juan Dela Cruz',
                driverSignature: 'Completed',
                reason: 'Urgent delivery',
                location: 'Quezon City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-15 09:15',
                companyId: 'COMP-002',
                isFinalized: 'No',
                finalizedAt: ''
            },
            {
                deliveryId: 'DEL-2024-00147',
                tripTicketId: 'TTK-2024-003',
                invoiceId: 'INV-2024-00125',
                status: 'pending',
                proofOfDelivery: 'No',
                customerName: 'Robert Lim',
                customerSignature: 'N/A',
                driverName: 'Carlos Gomez',
                driverSignature: 'Pending',
                reason: 'Scheduled delivery',
                location: 'Pasig City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-15 10:00',
                companyId: 'COMP-001',
                isFinalized: 'No',
                finalizedAt: ''
            },
            {
                deliveryId: 'DEL-2024-00148',
                tripTicketId: 'TTK-2024-004',
                invoiceId: 'INV-2024-00126',
                status: 'delivered',
                proofOfDelivery: 'Yes',
                customerName: 'Ana Lopez',
                customerSignature: 'Available',
                driverName: 'Maria Santos',
                driverSignature: 'Available',
                reason: 'Return delivery',
                location: 'Mandaluyong City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-15 11:30',
                companyId: 'COMP-003',
                isFinalized: 'Yes',
                finalizedAt: '2024-01-15 17:30'
            },
            {
                deliveryId: 'DEL-2024-00149',
                tripTicketId: 'TTK-2024-005',
                invoiceId: 'INV-2024-00127',
                status: 'cancelled',
                proofOfDelivery: 'No',
                customerName: 'Michael Tan',
                customerSignature: 'N/A',
                driverName: 'Pedro Reyes',
                driverSignature: 'N/A',
                reason: 'Customer not available',
                location: 'Taguig City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-15 13:00',
                companyId: 'COMP-002',
                isFinalized: 'Yes',
                finalizedAt: '2024-01-15 14:20'
            },
            {
                deliveryId: 'DEL-2024-00150',
                tripTicketId: 'TTK-2024-006',
                invoiceId: 'INV-2024-00128',
                status: 'in_transit',
                proofOfDelivery: 'Pending',
                customerName: 'Susan Lee',
                customerSignature: 'Pending',
                driverName: 'Juan Dela Cruz',
                driverSignature: 'Completed',
                reason: 'Regular delivery',
                location: 'Parañaque City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-15 14:45',
                companyId: 'COMP-001',
                isFinalized: 'No',
                finalizedAt: ''
            },
            {
                deliveryId: 'DEL-2024-00151',
                tripTicketId: 'TTK-2024-007',
                invoiceId: 'INV-2024-00129',
                status: 'delivered',
                proofOfDelivery: 'Yes',
                customerName: 'James Wong',
                customerSignature: 'Available',
                driverName: 'Carlos Gomez',
                driverSignature: 'Available',
                reason: 'Urgent delivery',
                location: 'Muntinlupa City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-15 15:30',
                companyId: 'COMP-003',
                isFinalized: 'Yes',
                finalizedAt: '2024-01-15 18:15'
            },
            {
                deliveryId: 'DEL-2024-00152',
                tripTicketId: 'TTK-2024-008',
                invoiceId: 'INV-2024-00130',
                status: 'returned',
                proofOfDelivery: 'Yes',
                customerName: 'Lorna Santos',
                customerSignature: 'Available',
                driverName: 'Maria Santos',
                driverSignature: 'Available',
                reason: 'Wrong item delivered',
                location: 'Las Piñas City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-14 09:00',
                companyId: 'COMP-001',
                isFinalized: 'Yes',
                finalizedAt: '2024-01-14 16:30'
            },
            {
                deliveryId: 'DEL-2024-00153',
                tripTicketId: 'TTK-2024-009',
                invoiceId: 'INV-2024-00131',
                status: 'pending',
                proofOfDelivery: 'No',
                customerName: 'David Chen',
                customerSignature: 'N/A',
                driverName: 'Pedro Reyes',
                driverSignature: 'Pending',
                reason: 'Scheduled for tomorrow',
                location: 'San Juan City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-14 10:30',
                companyId: 'COMP-002',
                isFinalized: 'No',
                finalizedAt: ''
            },
            {
                deliveryId: 'DEL-2024-00154',
                tripTicketId: 'TTK-2024-010',
                invoiceId: 'INV-2024-00132',
                status: 'in_transit',
                proofOfDelivery: 'Pending',
                customerName: 'Jennifer Reyes',
                customerSignature: 'Pending',
                driverName: 'Juan Dela Cruz',
                driverSignature: 'Completed',
                reason: 'Regular delivery',
                location: 'Pasay City',
                encodedBy: 'Admin User',
                encodedAt: '2024-01-14 11:45',
                companyId: 'COMP-001',
                isFinalized: 'No',
                finalizedAt: ''
            }
        ];

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Trip Tickets Management loaded!");
            
            // Setup mobile menu toggle
            document.getElementById('mobileMenuBtn').addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('active');
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileMenuBtn');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    !mobileBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            });

            // Initialize data
            loadTripTickets();
            loadTripStats();
            loadPendingSignatures();
            loadRecentFinalized();
            
            // Setup search functionality
            document.getElementById('searchInput').addEventListener('input', filterTripTickets);
            document.getElementById('selectAll').addEventListener('change', toggleSelectAll);
        });

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'index.html';
            }
        }

        // Load trip tickets into table
        function loadTripTickets() {
            const table = document.getElementById('tripTicketsTable');
            let html = '';
            
            tripTickets.forEach(ticket => {
                const statusClass = getStatusClass(ticket.status);
                const statusText = getStatusText(ticket.status);
                const proofClass = getProofClass(ticket.proofOfDelivery);
                const customerSigClass = getSignatureClass(ticket.customerSignature);
                const driverSigClass = getSignatureClass(ticket.driverSignature);
                const finalizedClass = ticket.isFinalized === 'Yes' ? 'badge-success' : 'badge-warning';
                const finalizedText = ticket.isFinalized === 'Yes' ? 'Finalized' : 'Not Finalized';
                
                html += `
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input ticket-checkbox" value="${ticket.deliveryId}">
                        </td>
                        <td><strong>${ticket.deliveryId}</strong></td>
                        <td>${ticket.tripTicketId}</td>
                        <td>${ticket.invoiceId}</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td><span class="badge ${proofClass}">${ticket.proofOfDelivery}</span></td>
                        <td>${ticket.customerName}</td>
                        <td><span class="badge ${customerSigClass}">${ticket.customerSignature}</span></td>
                        <td>${ticket.driverName}</td>
                        <td><span class="badge ${driverSigClass}">${ticket.driverSignature}</span></td>
                        <td>${ticket.reason}</td>
                        <td>${ticket.location}</td>
                        <td>${ticket.encodedBy}</td>
                        <td>${ticket.encodedAt}</td>
                        <td>${ticket.companyId}</td>
                        <td><span class="badge ${finalizedClass}">${finalizedText}</span></td>
                        <td>${ticket.finalizedAt || '-'}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view" onclick="viewTripTicket('${ticket.deliveryId}')" title="View Details">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn-icon btn-edit" onclick="editTripTicket('${ticket.deliveryId}')" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn-icon btn-primary" onclick="finalizeTripTicket('${ticket.deliveryId}')" title="Finalize">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
        }

        // Load statistics
        function loadTripStats() {
            const total = tripTickets.length;
            const pending = tripTickets.filter(t => t.status === 'pending').length;
            const active = tripTickets.filter(t => t.status === 'in_transit').length;
            const completed = tripTickets.filter(t => t.status === 'delivered' || t.status === 'returned').length;
            
            document.getElementById('totalTripTickets').textContent = total;
            document.getElementById('pendingTrips').textContent = pending;
            document.getElementById('activeTrips').textContent = active;
            document.getElementById('completedTrips').textContent = completed;
        }

        // Load pending signatures
        function loadPendingSignatures() {
            const pendingSigs = tripTickets.filter(t => 
                t.customerSignature === 'Pending' || 
                t.driverSignature === 'Pending'
            ).slice(0, 5);
            
            const table = document.getElementById('pendingSignaturesTable');
            let html = '';
            
            pendingSigs.forEach(ticket => {
                const statusClass = getStatusClass(ticket.status);
                const statusText = getStatusText(ticket.status);
                
                html += `
                    <tr>
                        <td><strong>${ticket.deliveryId}</strong></td>
                        <td>${ticket.customerName}</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="requestSignature('${ticket.deliveryId}')">
                                <i class="bi bi-pen"></i> Request
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
        }

        // Load recent finalized tickets
        function loadRecentFinalized() {
            const recentFinalized = tripTickets
                .filter(t => t.isFinalized === 'Yes')
                .sort((a, b) => new Date(b.finalizedAt) - new Date(a.finalizedAt))
                .slice(0, 5);
            
            const table = document.getElementById('recentFinalizedTable');
            let html = '';
            
            recentFinalized.forEach(ticket => {
                const statusClass = getStatusClass(ticket.status);
                const statusText = getStatusText(ticket.status);
                const time = ticket.finalizedAt.split(' ')[1];
                
                html += `
                    <tr>
                        <td><strong>${ticket.tripTicketId}</strong></td>
                        <td>${ticket.finalizedAt}</td>
                        <td>${ticket.encodedBy}</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
        }

        // Get status class for styling
        function getStatusClass(status) {
            switch(status) {
                case 'pending': return 'badge-warning';
                case 'in_transit': return 'badge-primary';
                case 'delivered': return 'badge-success';
                case 'cancelled': return 'badge-danger';
                case 'returned': return 'badge-info';
                default: return 'badge-secondary';
            }
        }

        // Get status text
        function getStatusText(status) {
            switch(status) {
                case 'pending': return 'Pending';
                case 'in_transit': return 'In Transit';
                case 'delivered': return 'Delivered';
                case 'cancelled': return 'Cancelled';
                case 'returned': return 'Returned';
                default: return 'Unknown';
            }
        }

        // Get proof of delivery class
        function getProofClass(proof) {
            switch(proof) {
                case 'Yes': return 'badge-success';
                case 'Pending': return 'badge-warning';
                case 'No': return 'badge-danger';
                default: return 'badge-secondary';
            }
        }

        // Get signature class
        function getSignatureClass(signature) {
            switch(signature) {
                case 'Available': return 'badge-success';
                case 'Completed': return 'badge-info';
                case 'Pending': return 'badge-warning';
                case 'N/A': return 'badge-secondary';
                default: return 'badge-secondary';
            }
        }

        // Filter trip tickets based on search and filters
        function filterTripTickets() {
            const searchText = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const finalizedFilter = document.getElementById('finalizedFilter').value;
            
            const filtered = tripTickets.filter(ticket => {
                const matchesSearch = 
                    ticket.deliveryId.toLowerCase().includes(searchText) ||
                    ticket.tripTicketId.toLowerCase().includes(searchText) ||
                    ticket.invoiceId.toLowerCase().includes(searchText) ||
                    ticket.customerName.toLowerCase().includes(searchText) ||
                    ticket.driverName.toLowerCase().includes(searchText) ||
                    ticket.location.toLowerCase().includes(searchText);
                
                const matchesStatus = !statusFilter || ticket.status === statusFilter;
                const matchesFinalized = !finalizedFilter || 
                    (finalizedFilter === 'finalized' && ticket.isFinalized === 'Yes') ||
                    (finalizedFilter === 'not_finalized' && ticket.isFinalized === 'No');
                
                return matchesSearch && matchesStatus && matchesFinalized;
            });
            
            updateTripTable(filtered);
        }

        // Update table with filtered data
        function updateTripTable(filteredTickets) {
            const table = document.getElementById('tripTicketsTable');
            let html = '';
            
            filteredTickets.forEach(ticket => {
                const statusClass = getStatusClass(ticket.status);
                const statusText = getStatusText(ticket.status);
                const proofClass = getProofClass(ticket.proofOfDelivery);
                const customerSigClass = getSignatureClass(ticket.customerSignature);
                const driverSigClass = getSignatureClass(ticket.driverSignature);
                const finalizedClass = ticket.isFinalized === 'Yes' ? 'badge-success' : 'badge-warning';
                const finalizedText = ticket.isFinalized === 'Yes' ? 'Finalized' : 'Not Finalized';
                
                html += `
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input ticket-checkbox" value="${ticket.deliveryId}">
                        </td>
                        <td><strong>${ticket.deliveryId}</strong></td>
                        <td>${ticket.tripTicketId}</td>
                        <td>${ticket.invoiceId}</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td><span class="badge ${proofClass}">${ticket.proofOfDelivery}</span></td>
                        <td>${ticket.customerName}</td>
                        <td><span class="badge ${customerSigClass}">${ticket.customerSignature}</span></td>
                        <td>${ticket.driverName}</td>
                        <td><span class="badge ${driverSigClass}">${ticket.driverSignature}</span></td>
                        <td>${ticket.reason}</td>
                        <td>${ticket.location}</td>
                        <td>${ticket.encodedBy}</td>
                        <td>${ticket.encodedAt}</td>
                        <td>${ticket.companyId}</td>
                        <td><span class="badge ${finalizedClass}">${finalizedText}</span></td>
                        <td>${ticket.finalizedAt || '-'}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view" onclick="viewTripTicket('${ticket.deliveryId}')">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn-icon btn-edit" onclick="editTripTicket('${ticket.deliveryId}')">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn-icon btn-primary" onclick="finalizeTripTicket('${ticket.deliveryId}')">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
            
            // Update pagination info
            document.getElementById('startRow').textContent = '1';
            document.getElementById('endRow').textContent = filteredTickets.length;
            document.getElementById('totalRows').textContent = filteredTickets.length;
        }

        // Toggle select all checkboxes
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.ticket-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        // Action functions
        function viewTripTicket(deliveryId) {
            const ticket = tripTickets.find(t => t.deliveryId === deliveryId);
            if (ticket) {
                alert(`View Trip Ticket Details:\n
Delivery ID: ${ticket.deliveryId}
Trip Ticket ID: ${ticket.tripTicketId}
Invoice ID: ${ticket.invoiceId}
Status: ${ticket.status}
Proof of Delivery: ${ticket.proofOfDelivery}
Customer Name: ${ticket.customerName}
Customer Signature: ${ticket.customerSignature}
Driver's Name: ${ticket.driverName}
Driver's Signature: ${ticket.driverSignature}
Reason: ${ticket.reason}
Location: ${ticket.location}
Encoded By: ${ticket.encodedBy}
Encoded At: ${ticket.encodedAt}
Company ID: ${ticket.companyId}
Is Finalized: ${ticket.isFinalized}
Finalized At: ${ticket.finalizedAt || 'Not yet finalized'}`);
            }
        }

        function editTripTicket(deliveryId) {
            alert(`Edit Trip Ticket: ${deliveryId}\n\nThis would open an edit form for the selected trip ticket.`);
        }

        function finalizeTripTicket(deliveryId) {
            if (confirm(`Are you sure you want to finalize trip ticket ${deliveryId}?\n\nOnce finalized, no further changes can be made.`)) {
                alert(`Trip Ticket ${deliveryId} has been finalized!\n\nStatus updated to finalized.`);
                // In a real application, this would update the database
            }
        }

        function requestSignature(deliveryId) {
            alert(`Requesting signature for Delivery ID: ${deliveryId}\n\nSignature request has been sent to customer and driver.`);
        }

        function createNewTripTicket() {
            alert('Create New Trip Ticket\n\nThis would open a form to create a new trip ticket with all the required fields.');
        }

        function printTripTickets() {
            alert('Print Selected Trip Tickets\n\nThis would generate a printable report of selected trip tickets.');
        }

        function refreshTripTickets() {
            filterTripTickets();
            loadTripStats();
            loadPendingSignatures();
            loadRecentFinalized();
        }

        function exportTripTickets() {
            alert('Exporting trip tickets to Excel...\n\nAll trip ticket data will be exported in Excel format.');
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N for new trip ticket
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                createNewTripTicket();
            }
            // Ctrl + F for finalize
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const selected = document.querySelector('.ticket-checkbox:checked');
                if (selected) {
                    finalizeTripTicket(selected.value);
                }
            }
            // Ctrl + L for logout
            else if (e.ctrlKey && e.key === 'l') {
                e.preventDefault();
                logout();
            }
        });
    </script>
</body>
</html>