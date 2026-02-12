<?php
require_once '../config/database.php';

// FETCH TRIP TICKETS FROM DATABASE USING VIEW
$trip_query = "
    SELECT 
        trip_number,
        driver_name,
        branch_name,
        trip_date,
        trip_status,
        total_stops,
        total_delivered,
        total_failed,
        completion_percentage
    FROM vw_trip_status
    ORDER BY trip_date DESC, trip_number DESC
";
$trip_result = $conn->query($trip_query);
$trip_tickets = $trip_result->fetch_all(MYSQLI_ASSOC);

// FETCH DRIVERS FOR FILTER
$drivers_query = "SELECT driver_id, driver_name FROM drivers WHERE status = 'active' ORDER BY driver_name";
$drivers_result = $conn->query($drivers_query);
$drivers = $drivers_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA
$total_tickets = count($trip_tickets);
$pending_tickets = count(array_filter($trip_tickets, fn($t) => $t['trip_status'] === 'planned' || $t['trip_status'] === 'pending'));
$in_transit_tickets = count(array_filter($trip_tickets, fn($t) => $t['trip_status'] === 'in-progress'));
$completed_tickets = count(array_filter($trip_tickets, fn($t) => $t['trip_status'] === 'completed' || $t['trip_status'] === 'delivered'));

// STAT CARD VALUES
$statTotalTickets = $total_tickets;
$statPendingTickets = $pending_tickets;
$statActiveTrips = $in_transit_tickets;
$statCompletedTrips = $completed_tickets;

// Helper function for status badge
function getTripStatusClass($status) {
    return match($status) {
        'planned', 'pending' => 'bg-warning text-dark',
        'in-progress' => 'bg-primary text-white',
        'completed', 'delivered' => 'bg-success text-white',
        'cancelled' => 'bg-danger text-white',
        'delayed' => 'bg-info text-white',
        default => 'bg-secondary text-white'
    };
}

function getTripStatusText($status) {
    return match($status) {
        'planned' => 'Pending',
        'pending' => 'Pending',
        'in-progress' => 'In Transit',
        'completed' => 'Delivered',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'delayed' => 'Delayed',
        default => ucfirst(str_replace('-', ' ', $status))
    };
}

function formatDateOnly($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatCompletion($percentage) {
    if ($percentage === null) return '0%';
    return number_format($percentage, 1) . '%';
}
?>
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
    <style>
        /* Completion percentage styling */
        .completion-cell {
            min-width: 120px;
        }
        .progress {
            width: 100px;
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
            color: white;
            text-shadow: 0 0 2px rgba(0,0,0,0.2);
        }
    </style>
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

                <!-- Quick Stats Cards - REAL DATA FROM DATABASE -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card total">
                            <i class="bi bi-ticket-perforated stat-icon"></i>
                            <div class="stat-value" id="totalTripTickets"><?= $statTotalTickets ?></div>
                            <div class="stat-label">Total Tickets</div>
                            <small class="d-block mt-2"><i class="bi bi-calendar"></i> All time trips</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock stat-icon"></i>
                            <div class="stat-value" id="pendingTrips"><?= $statPendingTickets ?></div>
                            <div class="stat-label">Pending</div>
                            <small class="d-block mt-2">Waiting for dispatch</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value" id="activeTrips"><?= $statActiveTrips ?></div>
                            <div class="stat-label">In Transit</div>
                            <small class="d-block mt-2">Currently on delivery</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="completedTrips"><?= $statCompletedTrips ?></div>
                            <div class="stat-label">Completed</div>
                            <small class="d-block mt-2">Delivered successfully</small>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons - UI PRESERVED -->
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
                                        <input type="text" class="form-control" placeholder="Search trip number, driver, or branch..." id="searchInput" onkeyup="filterTripTickets()">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" id="statusFilter" onchange="filterTripTickets()">
                                        <option value="">All Status</option>
                                        <option value="planned">Pending</option>
                                        <option value="in-progress">In Transit</option>
                                        <option value="completed">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                        <option value="delayed">Delayed</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="driverFilter" onchange="filterTripTickets()">
                                        <option value="">All Drivers</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?= htmlspecialchars($driver['driver_name']) ?>">
                                                <?= htmlspecialchars($driver['driver_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Table - UI PRESERVED, REAL DATA FROM DATABASE -->
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
                                        <input type="checkbox" class="form-check-input" id="selectAll" onclick="toggleSelectAll()">
                                    </th>
                                    <th width="120">Trip Number</th>
                                    <th width="120">Driver</th>
                                    <th width="100">Branch</th>
                                    <th width="100">Trip Date</th>
                                    <th width="100">Status</th>
                                    <th width="80">Stops</th>
                                    <th width="80">Delivered</th>
                                    <th width="80">Failed</th>
                                    <th width="120">Completion</th>
                                    <th width="100">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tripTicketsTable">
                                <?php if (empty($trip_tickets)): ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
                                        <p class="text-muted mb-0">No trip tickets found</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($trip_tickets as $ticket): ?>
                                    <tr class="trip-row" 
                                        data-trip-number="<?= htmlspecialchars($ticket['trip_number']) ?>"
                                        data-driver="<?= htmlspecialchars($ticket['driver_name']) ?>"
                                        data-status="<?= $ticket['trip_status'] ?>">
                                        <td>
                                            <input type="checkbox" class="form-check-input ticket-checkbox" value="<?= htmlspecialchars($ticket['trip_number']) ?>">
                                        </td>
                                        <td><strong><?= htmlspecialchars($ticket['trip_number']) ?></strong></td>
                                        <td><?= htmlspecialchars($ticket['driver_name']) ?></td>
                                        <td><?= htmlspecialchars($ticket['branch_name']) ?></td>
                                        <td><?= formatDateOnly($ticket['trip_date']) ?></td>
                                        <td>
                                            <span class="status-badge <?= getTripStatusClass($ticket['trip_status']) ?>">
                                                <?= getTripStatusText($ticket['trip_status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center"><?= $ticket['total_stops'] ?? 0 ?></td>
                                        <td class="text-center"><?= $ticket['total_delivered'] ?? 0 ?></td>
                                        <td class="text-center"><?= $ticket['total_failed'] ?? 0 ?></td>
                                        <td class="completion-cell">
                                            <?php $completion = $ticket['completion_percentage'] ?? 0; ?>
                                            <div class="progress" style="height: 20px; width: 100px;">
                                                <div class="progress-bar bg-success" role="progressbar" 
                                                     style="width: <?= $completion ?>%;" 
                                                     aria-valuenow="<?= $completion ?>" 
                                                     aria-valuemin="0" aria-valuemax="100">
                                                    <?= formatCompletion($completion) ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn btn-sm btn-outline-primary btn-icon-sm" onclick="viewTripTicket('<?= htmlspecialchars($ticket['trip_number']) ?>')" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning btn-icon-sm" onclick="editTripTicket('<?= htmlspecialchars($ticket['trip_number']) ?>')" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-success btn-icon-sm" onclick="finalizeTripTicket('<?= htmlspecialchars($ticket['trip_number']) ?>')" title="Finalize">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- PAGINATION - REMOVED -->
                    <!-- PENDING SIGNATURES - REMOVED -->
                    <!-- RECENT COMPLETED TICKETS - REMOVED -->
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
                                        <div class="detail-label">Created At</div>
                                        <div class="detail-value" id="viewEncodedAt"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="detail-label">Updated At</div>
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
                                <label for="editTripNumber" class="form-label">Trip Number</label>
                                <input type="text" class="form-control" id="editTripNumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editDriverName" class="form-label">Driver Name</label>
                                <input type="text" class="form-control" id="editDriverName">
                            </div>
                            <div class="col-md-6">
                                <label for="editStatus" class="form-label">Status</label>
                                <select class="form-select" id="editStatus">
                                    <option value="planned">Pending</option>
                                    <option value="in-progress">In Transit</option>
                                    <option value="completed">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="delayed">Delayed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editTripDate" class="form-label">Trip Date</label>
                                <input type="date" class="form-control" id="editTripDate">
                            </div>
                            <div class="col-md-6">
                                <label for="editTotalStops" class="form-label">Total Stops</label>
                                <input type="number" class="form-control" id="editTotalStops" min="0">
                            </div>
                            <div class="col-md-6">
                                <label for="editTotalDelivered" class="form-label">Delivered</label>
                                <input type="number" class="form-control" id="editTotalDelivered" min="0">
                            </div>
                            <div class="col-md-6">
                                <label for="editTotalFailed" class="form-label">Failed</label>
                                <input type="number" class="form-control" id="editTotalFailed" min="0">
                            </div>
                            <div class="col-md-12">
                                <label for="editRemarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="editRemarks" rows="2"></textarea>
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
                                <label for="createDriverId" class="form-label">Driver *</label>
                                <select class="form-select" id="createDriverId" required>
                                    <option value="">Select Driver</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['driver_id'] ?>"><?= htmlspecialchars($driver['driver_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="createBranchId" class="form-label">Branch *</label>
                                <select class="form-select" id="createBranchId" required>
                                    <option value="1">Main Branch</option>
                                    <option value="2">Branch North</option>
                                    <option value="3">Branch South</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="createTripDate" class="form-label">Trip Date *</label>
                                <input type="date" class="form-control" id="createTripDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="createStatus" class="form-label">Status *</label>
                                <select class="form-select" id="createStatus" required>
                                    <option value="planned">Pending</option>
                                    <option value="in-progress">In Transit</option>
                                    <option value="completed">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="delayed">Delayed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="createTotalStops" class="form-label">Total Stops</label>
                                <input type="number" class="form-control" id="createTotalStops" min="0" value="0">
                            </div>
                            <div class="col-md-12">
                                <label for="createRemarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="createRemarks" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Fields marked with * are required.
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
                        <strong>Trip Number:</strong> <span id="finalizeTripNumber"></span><br>
                        <strong>Driver:</strong> <span id="finalizeDriverName"></span><br>
                        <strong>Status:</strong> <span id="finalizeStatus"></span>
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
                    </div>
                    <div class="mb-3">
                        <label for="signatureType" class="form-label">Request Signature From</label>
                        <select class="form-select" id="signatureType">
                            <option value="customer">Customer Only</option>
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
    // ========== GLOBAL VARIABLES ==========
    let currentTicket = null;
    let tripTickets = <?= json_encode($trip_tickets) ?>;

    // ========== SIDEBAR FUNCTIONS (PRESERVED) ==========
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

    // ========== TRIP TICKET FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Trip Tickets - Live Database Mode");
        
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
        
        // Set default date for create modal
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        const createTripDate = document.getElementById('createTripDate');
        if (createTripDate) {
            createTripDate.value = formattedDate;
        }
    });

    // Filter trip tickets
    function filterTripTickets() {
        const searchText = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const driverFilter = document.getElementById('driverFilter').value;
        
        const rows = document.querySelectorAll('.trip-row');
        
        rows.forEach(row => {
            const tripNumber = row.dataset.tripNumber?.toLowerCase() || '';
            const driver = row.dataset.driver?.toLowerCase() || '';
            const status = row.dataset.status || '';
            
            let matchesSearch = searchText === '' || 
                tripNumber.includes(searchText) || 
                driver.includes(searchText);
            
            let matchesStatus = statusFilter === '' || status === statusFilter;
            let matchesDriver = driverFilter === '' || row.dataset.driver === driverFilter;
            
            if (matchesSearch && matchesStatus && matchesDriver) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Toggle select all checkboxes
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.ticket-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }

    // View trip ticket
    function viewTripTicket(tripNumber) {
        const ticket = tripTickets.find(t => t.trip_number === tripNumber);
        if (!ticket) {
            alert('Trip ticket not found');
            return;
        }
        
        currentTicket = ticket;
        
        // Populate details grid
        const detailsGrid = document.getElementById('ticketDetails');
        detailsGrid.innerHTML = `
            <div class="detail-card">
                <div class="detail-label">Trip Number</div>
                <div class="detail-value">${ticket.trip_number}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Driver</div>
                <div class="detail-value">${ticket.driver_name}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Branch</div>
                <div class="detail-value">${ticket.branch_name}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Trip Date</div>
                <div class="detail-value">${ticket.trip_date}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Status</div>
                <div class="detail-value"><span class="status-badge ${getStatusClass(ticket.trip_status)}">${getStatusText(ticket.trip_status)}</span></div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Stops</div>
                <div class="detail-value">${ticket.total_stops || 0}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Delivered</div>
                <div class="detail-value">${ticket.total_delivered || 0}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Failed</div>
                <div class="detail-value">${ticket.total_failed || 0}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Completion</div>
                <div class="detail-value">${formatCompletion(ticket.completion_percentage)}</div>
            </div>
        `;
        
        // Populate timestamps
        document.getElementById('viewEncodedAt').textContent = 'N/A';
        document.getElementById('viewFinalizedAt').textContent = 'N/A';
        
        // Set signature previews
        document.getElementById('customerSignatureText').textContent = 'N/A';
        document.getElementById('driverSignatureText').textContent = 'N/A';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('viewModal'));
        modal.show();
    }

    // Edit trip ticket
    function editTripTicket(tripNumber) {
        const ticket = tripTickets.find(t => t.trip_number === tripNumber);
        if (!ticket) {
            alert('Trip ticket not found');
            return;
        }
        
        currentTicket = ticket;
        
        // Populate form fields
        document.getElementById('editTripNumber').value = ticket.trip_number;
        document.getElementById('editDriverName').value = ticket.driver_name;
        document.getElementById('editStatus').value = ticket.trip_status;
        document.getElementById('editTripDate').value = ticket.trip_date;
        document.getElementById('editTotalStops').value = ticket.total_stops || 0;
        document.getElementById('editTotalDelivered').value = ticket.total_delivered || 0;
        document.getElementById('editTotalFailed').value = ticket.total_failed || 0;
        document.getElementById('editRemarks').value = '';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editModal'));
        modal.show();
    }

    // Save edit
    function saveEdit() {
        alert('Edit trip ticket - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
    }

    // Finalize trip ticket
    function finalizeTripTicket(tripNumber) {
        const ticket = tripTickets.find(t => t.trip_number === tripNumber);
        if (!ticket) {
            alert('Trip ticket not found');
            return;
        }
        
        currentTicket = ticket;
        
        // Populate finalize modal
        document.getElementById('finalizeTripNumber').textContent = ticket.trip_number;
        document.getElementById('finalizeDriverName').textContent = ticket.driver_name;
        document.getElementById('finalizeStatus').textContent = getStatusText(ticket.trip_status);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('finalizeModal'));
        modal.show();
    }

    // Confirm finalize
    function confirmFinalize() {
        alert('Finalize trip ticket - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('finalizeModal')).hide();
    }

    // Show create modal
    function showCreateModal() {
        const modal = new bootstrap.Modal(document.getElementById('createModal'));
        modal.show();
    }

    // Create new trip ticket
    function createNewTripTicket() {
        alert('Create new trip ticket - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('createModal')).hide();
    }

    // Show signature modal
    function showSignatureModal(deliveryId) {
        const signatureDeliveryId = document.getElementById('signatureDeliveryId');
        const signatureCustomerName = document.getElementById('signatureCustomerName');
        
        if (signatureDeliveryId) signatureDeliveryId.textContent = deliveryId || 'N/A';
        if (signatureCustomerName) signatureCustomerName.textContent = 'Customer';
        
        const modal = new bootstrap.Modal(document.getElementById('signatureModal'));
        modal.show();
    }

    // Send signature request
    function sendSignatureRequest() {
        alert('Send signature request - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('signatureModal')).hide();
    }

    // Edit current ticket from view modal
    function editCurrentTicket() {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => {
            if (currentTicket) {
                editTripTicket(currentTicket.trip_number);
            }
        }, 300);
    }

    // Finalize current ticket from view modal
    function finalizeCurrentTicket() {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => {
            if (currentTicket) {
                finalizeTripTicket(currentTicket.trip_number);
            }
        }, 300);
    }

    // Delete selected tickets
    function deleteSelected() {
        const checkboxes = document.querySelectorAll('.ticket-checkbox:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one ticket to delete');
            return;
        }
        
        if (confirm(`Are you sure you want to delete ${checkboxes.length} selected trip ticket(s)?`)) {
            alert('Delete selected tickets - AJAX implementation needed');
        }
    }

    // Refresh trip tickets
    function refreshTripTickets() {
        location.reload();
    }

    // Print trip tickets
    function printTripTickets() {
        window.print();
    }

    // Export trip tickets
    function exportTripTickets() {
        alert('Export trip tickets - AJAX implementation needed');
    }

    // Helper functions
    function getStatusClass(status) {
        const classes = {
            'planned': 'bg-warning text-dark',
            'pending': 'bg-warning text-dark',
            'in-progress': 'bg-primary text-white',
            'completed': 'bg-success text-white',
            'delivered': 'bg-success text-white',
            'cancelled': 'bg-danger text-white',
            'delayed': 'bg-info text-white'
        };
        return classes[status] || 'bg-secondary text-white';
    }

    function getStatusText(status) {
        const texts = {
            'planned': 'Pending',
            'pending': 'Pending',
            'in-progress': 'In Transit',
            'completed': 'Delivered',
            'delivered': 'Delivered',
            'cancelled': 'Cancelled',
            'delayed': 'Delayed'
        };
        return texts[status] || status;
    }

    function formatCompletion(percentage) {
        if (percentage === null || percentage === undefined) return '0%';
        return parseFloat(percentage).toFixed(1) + '%';
    }

    // Logout function
    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            localStorage.removeItem('sidebarCollapsed');
            window.location.href = 'login.php';
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.key === 'Escape' && window.innerWidth <= 992) {
            closeMobileSidebar();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showCreateModal();
        }
    });
    </script>
</body>
</html>