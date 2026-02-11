<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Tickets</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />

    <link rel="stylesheet" href="../css/current_inventory.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Font Awesome for more icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                 <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span></h3>
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
                        <a class="nav-link" href="sales_order.php">
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
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
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
            <!-- TRIP TICKETS PAGE -->
            <div id="tripTicketsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Trip Tickets</h2>
                        <p>Manage and track trip tickets for deliveries</p>
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
                                        <button class="btn btn-primary" onclick="showCreateModal()">
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

                <!-- Main Table - Organized into sections -->
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
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteSelected()">
                                <i class="bi bi-trash"></i> Delete Selected
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table">
                            <thead>
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" class="form-check-input" id="selectAll">
                                    </th>
                                    <th width="120">Delivery ID</th>
                                    <th width="120">Trip Ticket ID</th>
                                    <th width="100">Invoice ID</th>
                                    <th width="100">Status</th>
                                    <th width="100">Proof of Delivery</th>
                                    <th width="150">Customer</th>
                                    <th width="120">Customer Signature</th>
                                    <th width="120">Driver</th>
                                    <th width="120">Driver Signature</th>
                                    <th width="150">Location</th>
                                    <th width="100">Encoded By</th>
                                    <th width="100">Is Finalized?</th>
                                    <th width="80">Actions</th>
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
                                <table class="table custom-table compact-table">
                                    <thead>
                                        <tr>
                                            <th width="120">Delivery ID</th>
                                            <th>Customer</th>
                                            <th width="100">Status</th>
                                            <th width="100">Action</th>
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
                                <table class="table custom-table compact-table">
                                    <thead>
                                        <tr>
                                            <th width="120">Trip Ticket ID</th>
                                            <th width="140">Finalized At</th>
                                            <th width="120">Finalized By</th>
                                            <th width="100">Status</th>
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

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-lg-custom">
            <div class="modal-content action-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewModalLabel"><i class="bi bi-eye me-2"></i>Trip Ticket Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="ticket-details-grid" id="ticketDetails">
                        <!-- Details will be populated by JavaScript -->
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6><i class="bi bi-pen me-2"></i>Signatures</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="detail-card">
                                        <div class="detail-label">Customer Signature</div>
                                        <div class="signature-preview" id="customerSignaturePreview">
                                            <span id="customerSignatureText">Not Available</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-card">
                                        <div class="detail-label">Driver Signature</div>
                                        <div class="signature-preview" id="driverSignaturePreview">
                                            <span id="driverSignatureText">Not Available</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="bi bi-clock-history me-2"></i>Timestamps</h6>
                            <div class="detail-card">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="detail-label">Encoded At</div>
                                        <div class="detail-value" id="viewEncodedAt"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="detail-label">Finalized At</div>
                                        <div class="detail-value" id="viewFinalizedAt"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="editCurrentTicket()">Edit</button>
                    <button type="button" class="btn btn-success" onclick="finalizeCurrentTicket()">Finalize</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editModalLabel"><i class="bi bi-pencil me-2"></i>Edit Trip Ticket</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editDeliveryId" class="form-label">Delivery ID</label>
                                <input type="text" class="form-control" id="editDeliveryId" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editTripTicketId" class="form-label">Trip Ticket ID</label>
                                <input type="text" class="form-control" id="editTripTicketId">
                            </div>
                            <div class="col-md-6">
                                <label for="editStatus" class="form-label">Status</label>
                                <select class="form-select" id="editStatus">
                                    <option value="pending">Pending</option>
                                    <option value="in_transit">In Transit</option>
                                    <option value="delivered">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="returned">Returned</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editProofOfDelivery" class="form-label">Proof of Delivery</label>
                                <select class="form-select" id="editProofOfDelivery">
                                    <option value="Yes">Yes</option>
                                    <option value="Pending">Pending</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editCustomerName" class="form-label">Customer Name</label>
                                <input type="text" class="form-control" id="editCustomerName">
                            </div>
                            <div class="col-md-6">
                                <label for="editDriverName" class="form-label">Driver's Name</label>
                                <input type="text" class="form-control" id="editDriverName">
                            </div>
                            <div class="col-md-6">
                                <label for="editCustomerSignature" class="form-label">Customer Signature</label>
                                <select class="form-select" id="editCustomerSignature">
                                    <option value="Available">Available</option>
                                    <option value="Pending">Pending</option>
                                    <option value="N/A">N/A</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editDriverSignature" class="form-label">Driver's Signature</label>
                                <select class="form-select" id="editDriverSignature">
                                    <option value="Available">Available</option>
                                    <option value="Pending">Pending</option>
                                    <option value="N/A">N/A</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label for="editReason" class="form-label">Reason</label>
                                <textarea class="form-control" id="editReason" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="editLocation" class="form-label">Location</label>
                                <input type="text" class="form-control" id="editLocation">
                            </div>
                            <div class="col-md-6">
                                <label for="editIsFinalized" class="form-label">Finalization Status</label>
                                <select class="form-select" id="editIsFinalized">
                                    <option value="No">Not Finalized</option>
                                    <option value="Yes">Finalized</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveEdit()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="createModalLabel"><i class="bi bi-plus-circle me-2"></i>Create New Trip Ticket</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="createDeliveryId" class="form-label">Delivery ID *</label>
                                <input type="text" class="form-control" id="createDeliveryId" required>
                            </div>
                            <div class="col-md-6">
                                <label for="createTripTicketId" class="form-label">Trip Ticket ID *</label>
                                <input type="text" class="form-control" id="createTripTicketId" required>
                            </div>
                            <div class="col-md-6">
                                <label for="createInvoiceId" class="form-label">Invoice ID *</label>
                                <input type="text" class="form-control" id="createInvoiceId" required>
                            </div>
                            <div class="col-md-6">
                                <label for="createStatus" class="form-label">Status *</label>
                                <select class="form-select" id="createStatus" required>
                                    <option value="pending">Pending</option>
                                    <option value="in_transit">In Transit</option>
                                    <option value="delivered">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="returned">Returned</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="createCustomerName" class="form-label">Customer Name *</label>
                                <input type="text" class="form-control" id="createCustomerName" required>
                            </div>
                            <div class="col-md-6">
                                <label for="createDriverName" class="form-label">Driver's Name *</label>
                                <input type="text" class="form-control" id="createDriverName" required>
                            </div>
                            <div class="col-md-12">
                                <label for="createLocation" class="form-label">Location *</label>
                                <input type="text" class="form-control" id="createLocation" required>
                            </div>
                            <div class="col-md-12">
                                <label for="createReason" class="form-label">Reason</label>
                                <textarea class="form-control" id="createReason" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="createCompanyId" class="form-label">Company ID</label>
                                <input type="text" class="form-control" id="createCompanyId">
                            </div>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Fields marked with * are required. The ticket will be created with default signature status as "Pending".
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="createNewTripTicket()">Create Ticket</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Finalize Modal -->
    <div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="finalizeModalLabel"><i class="bi bi-exclamation-triangle me-2"></i>Finalize Trip Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to finalize this trip ticket?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> Once finalized, no further changes can be made to this trip ticket.
                    </div>
                    <div class="ticket-info mb-3 p-2 bg-light rounded">
                        <strong>Delivery ID:</strong> <span id="finalizeDeliveryId"></span><br>
                        <strong>Trip Ticket ID:</strong> <span id="finalizeTripTicketId"></span><br>
                        <strong>Customer:</strong> <span id="finalizeCustomerName"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="confirmFinalize()">Finalize Ticket</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Request Signature Modal -->
    <div class="modal fade" id="signatureModal" tabindex="-1" aria-labelledby="signatureModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="signatureModalLabel"><i class="bi bi-pen me-2"></i>Request Signature</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Send signature request for:</p>
                    <div class="ticket-info mb-3 p-3 bg-light rounded">
                        <strong>Delivery ID:</strong> <span id="signatureDeliveryId"></span><br>
                        <strong>Customer:</strong> <span id="signatureCustomerName"></span><br>
                        <strong>Driver:</strong> <span id="signatureDriverName"></span>
                    </div>
                    <div class="mb-3">
                        <label for="signatureType" class="form-label">Request Signature From</label>
                        <select class="form-select" id="signatureType">
                            <option value="both">Both Customer and Driver</option>
                            <option value="customer">Customer Only</option>
                            <option value="driver">Driver Only</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="signatureMessage" class="form-label">Additional Message (Optional)</label>
                        <textarea class="form-control" id="signatureMessage" rows="3" placeholder="Add any special instructions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-info" onclick="sendSignatureRequest()">Send Request</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Sample data for demonstration
    let tripTickets = [
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
        }
    ];

    let currentTicket = null;

    // ================= SIDEBAR FUNCTIONS =================
    // Toggle sidebar collapse/expand
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            sidebar.classList.toggle('active');
            
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
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
            });
            
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
            }
        }
    }

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

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        
        if (window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'none';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '80px';
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '250px';
                }
            }
        } else {
            sidebar.classList.remove('active');
            sidebar.classList.remove('collapsed');
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'inline-block';
            });
            
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.style.marginLeft = '0';
            }
        }
    }

    function handleSidebarResize() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        
        if (window.innerWidth > 992) {
            if (overlay) {
                overlay.remove();
            }
            sidebar.classList.remove('active');
            
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'none';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '80px';
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '250px';
                }
            }
        } else {
            sidebar.classList.remove('collapsed');
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'inline-block';
            });
            
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.style.marginLeft = '0';
            }
        }
    }
    // ================= END SIDEBAR FUNCTIONS =================

    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Trip Tickets Management loaded!");
        
        initializeSidebar();
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) {
                    closeMobileSidebar();
                }
            });
        });
        
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });

        window.addEventListener('resize', handleSidebarResize);

        loadTripTickets();
        loadTripStats();
        loadPendingSignatures();
        loadRecentFinalized();
        
        document.getElementById('searchInput').addEventListener('input', filterTripTickets);
        document.getElementById('selectAll').addEventListener('change', toggleSelectAll);
    });

    // Logout function
    function logout() {
        const modal = new bootstrap.Modal(document.createElement('div'));
        const modalDiv = document.createElement('div');
        modalDiv.className = 'modal fade';
        modalDiv.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-box-arrow-right me-2"></i>Confirm Logout</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to logout?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" onclick="confirmLogout()">Logout</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalDiv);
        modal._element = modalDiv;
        modal.show();
        
        modalDiv.addEventListener('hidden.bs.modal', function() {
            modalDiv.remove();
        });
    }

    function confirmLogout() {
        alert('Redirecting to login page...');
        // In real app: window.location.href = 'logout.php';
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
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td><span class="status-badge ${proofClass}">${ticket.proofOfDelivery}</span></td>
                    <td>${ticket.customerName}</td>
                    <td><span class="status-badge ${customerSigClass}">${ticket.customerSignature}</span></td>
                    <td>${ticket.driverName}</td>
                    <td><span class="status-badge ${driverSigClass}">${ticket.driverSignature}</span></td>
                    <td>${ticket.location}</td>
                    <td>${ticket.encodedBy}</td>
                    <td><span class="status-badge ${finalizedClass}">${finalizedText}</span></td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-outline-primary btn-icon-sm" onclick="viewTripTicket('${ticket.deliveryId}')" title="View">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-warning btn-icon-sm" onclick="editTripTicket('${ticket.deliveryId}')" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success btn-icon-sm" onclick="finalizeTripTicket('${ticket.deliveryId}')" title="Finalize">
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
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="showSignatureModal('${ticket.deliveryId}')">
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
            
            html += `
                <tr>
                    <td><strong>${ticket.tripTicketId}</strong></td>
                    <td>${ticket.finalizedAt}</td>
                    <td>${ticket.encodedBy}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                </tr>
            `;
        });
        
        table.innerHTML = html;
    }

    // Get status class for styling
    function getStatusClass(status) {
        switch(status) {
            case 'pending': return 'bg-warning text-dark';
            case 'in_transit': return 'bg-primary text-white';
            case 'delivered': return 'bg-success text-white';
            case 'cancelled': return 'bg-danger text-white';
            case 'returned': return 'bg-info text-white';
            default: return 'bg-secondary text-white';
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
            case 'Yes': return 'bg-success text-white';
            case 'Pending': return 'bg-warning text-dark';
            case 'No': return 'bg-danger text-white';
            default: return 'bg-secondary text-white';
        }
    }

    // Get signature class
    function getSignatureClass(signature) {
        switch(signature) {
            case 'Available': return 'bg-success text-white';
            case 'Completed': return 'bg-info text-white';
            case 'Pending': return 'bg-warning text-dark';
            case 'N/A': return 'bg-secondary text-white';
            default: return 'bg-secondary text-white';
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
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td><span class="status-badge ${proofClass}">${ticket.proofOfDelivery}</span></td>
                    <td>${ticket.customerName}</td>
                    <td><span class="status-badge ${customerSigClass}">${ticket.customerSignature}</span></td>
                    <td>${ticket.driverName}</td>
                    <td><span class="status-badge ${driverSigClass}">${ticket.driverSignature}</span></td>
                    <td>${ticket.location}</td>
                    <td>${ticket.encodedBy}</td>
                    <td><span class="status-badge ${finalizedClass}">${finalizedText}</span></td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-outline-primary btn-icon-sm" onclick="viewTripTicket('${ticket.deliveryId}')" title="View">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-warning btn-icon-sm" onclick="editTripTicket('${ticket.deliveryId}')" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success btn-icon-sm" onclick="finalizeTripTicket('${ticket.deliveryId}')" title="Finalize">
                                <i class="bi bi-check-circle"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        
        table.innerHTML = html;
        
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

    // ================= MODAL FUNCTIONS =================
    function viewTripTicket(deliveryId) {
        const ticket = tripTickets.find(t => t.deliveryId === deliveryId);
        if (!ticket) return;
        
        currentTicket = ticket;
        
        // Populate details grid
        const detailsGrid = document.getElementById('ticketDetails');
        detailsGrid.innerHTML = `
            <div class="detail-card">
                <div class="detail-label">Delivery ID</div>
                <div class="detail-value">${ticket.deliveryId}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Trip Ticket ID</div>
                <div class="detail-value">${ticket.tripTicketId}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Invoice ID</div>
                <div class="detail-value">${ticket.invoiceId}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Status</div>
                <div class="detail-value"><span class="status-badge ${getStatusClass(ticket.status)}">${getStatusText(ticket.status)}</span></div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Proof of Delivery</div>
                <div class="detail-value"><span class="status-badge ${getProofClass(ticket.proofOfDelivery)}">${ticket.proofOfDelivery}</span></div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Customer Name</div>
                <div class="detail-value">${ticket.customerName}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Driver's Name</div>
                <div class="detail-value">${ticket.driverName}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Location</div>
                <div class="detail-value">${ticket.location}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Reason</div>
                <div class="detail-value">${ticket.reason}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Company ID</div>
                <div class="detail-value">${ticket.companyId}</div>
            </div>
        `;
        
        // Populate signature previews
        document.getElementById('customerSignatureText').textContent = ticket.customerSignature;
        document.getElementById('driverSignatureText').textContent = ticket.driverSignature;
        
        // Set signature preview colors
        const customerPreview = document.getElementById('customerSignaturePreview');
        const driverPreview = document.getElementById('driverSignaturePreview');
        customerPreview.style.borderLeftColor = ticket.customerSignature === 'Available' ? '#28a745' : 
                                               ticket.customerSignature === 'Pending' ? '#ffc107' : '#dc3545';
        driverPreview.style.borderLeftColor = ticket.driverSignature === 'Available' ? '#28a745' : 
                                             ticket.driverSignature === 'Pending' ? '#ffc107' : '#dc3545';
        
        // Populate timestamps
        document.getElementById('viewEncodedAt').textContent = ticket.encodedAt;
        document.getElementById('viewFinalizedAt').textContent = ticket.finalizedAt || 'Not yet finalized';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('viewModal'));
        modal.show();
    }

    function editTripTicket(deliveryId) {
        const ticket = tripTickets.find(t => t.deliveryId === deliveryId);
        if (!ticket) return;
        
        currentTicket = ticket;
        
        // Populate form fields
        document.getElementById('editDeliveryId').value = ticket.deliveryId;
        document.getElementById('editTripTicketId').value = ticket.tripTicketId;
        document.getElementById('editStatus').value = ticket.status;
        document.getElementById('editProofOfDelivery').value = ticket.proofOfDelivery;
        document.getElementById('editCustomerName').value = ticket.customerName;
        document.getElementById('editDriverName').value = ticket.driverName;
        document.getElementById('editCustomerSignature').value = ticket.customerSignature;
        document.getElementById('editDriverSignature').value = ticket.driverSignature;
        document.getElementById('editReason').value = ticket.reason;
        document.getElementById('editLocation').value = ticket.location;
        document.getElementById('editIsFinalized').value = ticket.isFinalized;
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editModal'));
        modal.show();
    }

    function saveEdit() {
        if (!currentTicket) return;
        
        // Update ticket data
        currentTicket.tripTicketId = document.getElementById('editTripTicketId').value;
        currentTicket.status = document.getElementById('editStatus').value;
        currentTicket.proofOfDelivery = document.getElementById('editProofOfDelivery').value;
        currentTicket.customerName = document.getElementById('editCustomerName').value;
        currentTicket.driverName = document.getElementById('editDriverName').value;
        currentTicket.customerSignature = document.getElementById('editCustomerSignature').value;
        currentTicket.driverSignature = document.getElementById('editDriverSignature').value;
        currentTicket.reason = document.getElementById('editReason').value;
        currentTicket.location = document.getElementById('editLocation').value;
        currentTicket.isFinalized = document.getElementById('editIsFinalized').value;
        
        if (currentTicket.isFinalized === 'Yes' && !currentTicket.finalizedAt) {
            currentTicket.finalizedAt = new Date().toLocaleString('en-US', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }).replace(',', '');
        }
        
        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        
        // Refresh data
        refreshTripTickets();
        
        // Show success message
        showToast('Trip ticket updated successfully!', 'success');
    }

    function finalizeTripTicket(deliveryId) {
        const ticket = tripTickets.find(t => t.deliveryId === deliveryId);
        if (!ticket) return;
        
        currentTicket = ticket;
        
        // Populate finalize modal
        document.getElementById('finalizeDeliveryId').textContent = ticket.deliveryId;
        document.getElementById('finalizeTripTicketId').textContent = ticket.tripTicketId;
        document.getElementById('finalizeCustomerName').textContent = ticket.customerName;
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('finalizeModal'));
        modal.show();
    }

    function confirmFinalize() {
        if (!currentTicket) return;
        
        // Update ticket
        currentTicket.isFinalized = 'Yes';
        currentTicket.finalizedAt = new Date().toLocaleString('en-US', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).replace(',', '');
        
        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('finalizeModal')).hide();
        
        // Refresh data
        refreshTripTickets();
        
        // Show success message
        showToast('Trip ticket finalized successfully!', 'success');
    }

    function showCreateModal() {
        // Reset form
        document.getElementById('createForm').reset();
        
        // Set default values
        const today = new Date();
        const dateStr = today.toISOString().slice(0,10).replace(/-/g, '');
        document.getElementById('createDeliveryId').value = `DEL-${dateStr}-${String(tripTickets.length + 1).padStart(3, '0')}`;
        document.getElementById('createTripTicketId').value = `TTK-${dateStr}-${String(tripTickets.length + 1).padStart(3, '0')}`;
        document.getElementById('createInvoiceId').value = `INV-${dateStr}-${String(tripTickets.length + 1).padStart(3, '0')}`;
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('createModal'));
        modal.show();
    }

    function createNewTripTicket() {
        // Get form values
        const deliveryId = document.getElementById('createDeliveryId').value;
        const tripTicketId = document.getElementById('createTripTicketId').value;
        const invoiceId = document.getElementById('createInvoiceId').value;
        const status = document.getElementById('createStatus').value;
        const customerName = document.getElementById('createCustomerName').value;
        const driverName = document.getElementById('createDriverName').value;
        const location = document.getElementById('createLocation').value;
        const reason = document.getElementById('createReason').value;
        const companyId = document.getElementById('createCompanyId').value;
        
        // Validate required fields
        if (!deliveryId || !tripTicketId || !invoiceId || !customerName || !driverName || !location) {
            showToast('Please fill in all required fields!', 'error');
            return;
        }
        
        // Create new ticket
        const now = new Date().toLocaleString('en-US', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).replace(',', '');
        
        const newTicket = {
            deliveryId,
            tripTicketId,
            invoiceId,
            status,
            proofOfDelivery: 'Pending',
            customerName,
            customerSignature: 'Pending',
            driverName,
            driverSignature: 'Pending',
            reason,
            location,
            encodedBy: 'Admin User',
            encodedAt: now,
            companyId: companyId || 'COMP-001',
            isFinalized: 'No',
            finalizedAt: ''
        };
        
        // Add to array
        tripTickets.unshift(newTicket);
        
        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('createModal')).hide();
        
        // Refresh data
        refreshTripTickets();
        
        // Show success message
        showToast('New trip ticket created successfully!', 'success');
    }

    function showSignatureModal(deliveryId) {
        const ticket = tripTickets.find(t => t.deliveryId === deliveryId);
        if (!ticket) return;
        
        currentTicket = ticket;
        
        // Populate modal
        document.getElementById('signatureDeliveryId').textContent = ticket.deliveryId;
        document.getElementById('signatureCustomerName').textContent = ticket.customerName;
        document.getElementById('signatureDriverName').textContent = ticket.driverName;
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('signatureModal'));
        modal.show();
    }

    function sendSignatureRequest() {
        if (!currentTicket) return;
        
        const signatureType = document.getElementById('signatureType').value;
        const message = document.getElementById('signatureMessage').value;
        
        // Update ticket signatures based on type
        if (signatureType === 'both' || signatureType === 'customer') {
            currentTicket.customerSignature = 'Pending';
        }
        if (signatureType === 'both' || signatureType === 'driver') {
            currentTicket.driverSignature = 'Pending';
        }
        
        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('signatureModal')).hide();
        
        // Clear form
        document.getElementById('signatureMessage').value = '';
        
        // Refresh data
        refreshTripTickets();
        
        // Show success message
        showToast(`Signature request sent to ${signatureType === 'both' ? 'customer and driver' : signatureType}!`, 'success');
    }

    function editCurrentTicket() {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => editTripTicket(currentTicket.deliveryId), 300);
    }

    function finalizeCurrentTicket() {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => finalizeTripTicket(currentTicket.deliveryId), 300);
    }

    function deleteSelected() {
        const checkboxes = document.querySelectorAll('.ticket-checkbox:checked');
        if (checkboxes.length === 0) {
            showToast('Please select at least one ticket to delete!', 'warning');
            return;
        }
        
        if (!confirm(`Are you sure you want to delete ${checkboxes.length} selected trip ticket(s)?`)) {
            return;
        }
        
        // Get IDs to delete
        const idsToDelete = Array.from(checkboxes).map(cb => cb.value);
        
        // Filter out tickets to delete
        tripTickets = tripTickets.filter(ticket => !idsToDelete.includes(ticket.deliveryId));
        
        // Refresh data
        refreshTripTickets();
        
        // Show success message
        showToast(`${idsToDelete.length} trip ticket(s) deleted successfully!`, 'success');
    }

    // ================= UTILITY FUNCTIONS =================
    function refreshTripTickets() {
        filterTripTickets();
        loadTripStats();
        loadPendingSignatures();
        loadRecentFinalized();
        
        // Uncheck select all
        document.getElementById('selectAll').checked = false;
    }

    function printTripTickets() {
        const selected = document.querySelectorAll('.ticket-checkbox:checked');
        if (selected.length === 0) {
            showToast('Please select at least one ticket to print!', 'warning');
            return;
        }
        
        showToast(`Printing ${selected.length} trip ticket(s)...`, 'info');
        // In real app: window.print() or generate PDF
    }

    function exportTripTickets() {
        showToast('Exporting trip tickets to Excel...', 'info');
        // In real app: generate Excel file
    }

    function showToast(message, type = 'info') {
        // Create toast element
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : type === 'error' ? 'x-circle' : 'info-circle'} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        // Add to container
        const container = document.getElementById('toastContainer') || (() => {
            const div = document.createElement('div');
            div.id = 'toastContainer';
            div.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            div.style.zIndex = '9999';
            document.body.appendChild(div);
            return div;
        })();
        
        container.appendChild(toastEl);
        
        // Show toast
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
        
        // Remove after hide
        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });
    }

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
        // Ctrl + N for new trip ticket
        else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showCreateModal();
        }
        // Ctrl + F for finalize
        else if (e.ctrlKey && e.key === 'f' && currentTicket) {
            e.preventDefault();
            finalizeTripTicket(currentTicket.deliveryId);
        }
        // Ctrl + L for logout
        else if (e.ctrlKey && e.key === 'l') {
            e.preventDefault();
            logout();
        }
        // Ctrl + E for edit
        else if (e.ctrlKey && e.key === 'e' && currentTicket) {
            e.preventDefault();
            editTripTicket(currentTicket.deliveryId);
        }
    });
    </script>
</body>
</html>