<?php
require_once '../config/database.php';

// FETCH RMR REQUESTS FROM DATABASE WITH JOINS
$rmr_query = "
    SELECT 
        r.rmr_id,
        r.rmr_number,
        r.so_id,
        r.return_quantity,
        r.return_reason,
        r.reason_details,
        r.rmr_status,
        r.received_date,
        r.inspector_name,
        r.inspection_type,
        r.disposition_type,
        r.created_at,
        r.updated_at,
        c.customer_name,
        c.customer_id,
        i.item_id,
        i.item_code,
        i.item_name,
        i.unit_price,
        i.unit_type,
        CONCAT(u.first_name, ' ', u.last_name) as received_by_name
    FROM rmr_requests r
    JOIN customers c ON r.customer_id = c.customer_id
    JOIN items i ON r.item_id = i.item_id
    LEFT JOIN users u ON r.received_by = u.user_id
    ORDER BY r.created_at DESC, r.rmr_id DESC
";
$rmr_result = $conn->query($rmr_query);
$rmr_requests = $rmr_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA
$total_rmr = count($rmr_requests);
$pending_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'pending'));
$processing_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'processing'));
$approved_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'approved'));
$rejected_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'rejected'));
$resolved_rmr = count(array_filter($rmr_requests, fn($r) => $r['rmr_status'] === 'resolved'));

// STAT CARD VALUES
$statTotalRMR = $total_rmr;
$statPendingRMR = $pending_rmr;
$statProcessingRMR = $processing_rmr;
$statApprovedRMR = $approved_rmr;

// Helper function for status badge
function getRMRStatusClass($status) {
    return match($status) {
        'pending' => 'status-pending',
        'processing' => 'status-processing',
        'approved' => 'status-approved',
        'rejected' => 'status-rejected',
        'resolved' => 'status-resolved',
        default => 'status-pending'
    };
}

function getRMRStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'processing' => 'Processing',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'resolved' => 'Resolved',
        default => ucfirst($status)
    };
}

function getReturnReasonClass($reason) {
    return match($reason) {
        'damaged' => 'reason-damaged',
        'expired' => 'reason-expired',
        'wrong-item' => 'reason-wrong-item',
        'quality' => 'reason-quality',
        'overstock' => 'reason-overstock',
        'other' => 'reason-other',
        default => 'reason-other'
    };
}

function getReturnReasonText($reason) {
    return match($reason) {
        'damaged' => 'Damaged',
        'expired' => 'Expired',
        'wrong-item' => 'Wrong Item',
        'quality' => 'Quality Issue',
        'overstock' => 'Overstock',
        'other' => 'Other',
        default => ucfirst($reason)
    };
}

function getUnitText($unit) {
    return match($unit) {
        'case' => 'CS',
        'inner-pack' => 'IP',
        'piece' => 'PC',
        'box' => 'BX',
        'carton' => 'CTN',
        default => strtoupper(substr($unit, 0, 2))
    };
}

function getDispositionText($disposition) {
    return match($disposition) {
        'credit' => 'Credit to Customer',
        'refund' => 'Cash Refund',
        'replacement' => 'Replacement',
        'disposal' => 'Destroy Item',
        'return-to-supplier' => 'Return to Supplier',
        default => $disposition ? ucfirst(str_replace('-', ' ', $disposition)) : ''
    };
}

function formatDate($dateTimeStr) {
    if (!$dateTimeStr) return '';
    $date = new DateTime($dateTimeStr);
    return $date->format('M d, Y H:i');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bad Orders</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/bad_orders.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Table styles for RMR */
        .rmr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .rmr-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            padding: 14px 12px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }
        
        .rmr-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .rmr-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Column widths - CHECKBOX COLUMN REMOVED */
        .col-rmr { width: 12%; }
        .col-customer { width: 15%; }
        .col-item { width: 18%; }
        .col-qty { width: 8%; text-align: center; }
        .col-amount { width: 12%; text-align: right; }
        .col-reason { width: 12%; }
        .col-status { width: 10%; }
        .col-received { width: 12%; }
        .col-actions { width: 13%; text-align: center; }
        
        .empty-state-table {
            text-align: center;
            padding: 40px 20px;
            background-color: white;
            border-radius: 8px;
        }
        
        .empty-state-table i {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 16px;
        }
        
        .empty-state-table h5 {
            color: #495057;
            margin-bottom: 8px;
        }
        
        .empty-state-table p {
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-processing { background-color: #cce5ff; color: #004085; }
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-resolved { background-color: #d1ecf1; color: #0c5460; }
        
        .return-reason {
            display: inline-block;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 4px;
        }
        
        .reason-damaged { background-color: #f8d7da; color: #721c24; }
        .reason-expired { background-color: #fff3cd; color: #856404; }
        .reason-wrong-item { background-color: #d1ecf1; color: #0c5460; }
        .reason-quality { background-color: #cce5ff; color: #004085; }
        .reason-overstock { background-color: #e2d5f2; color: #533f7c; }
        .reason-other { background-color: #e9ecef; color: #495057; }
        
        /* Filter section layout */
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 25px;
            padding: 16px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .filter-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sort-controls {
            display: flex;
            gap: 5px;
        }
        
        .sort-btn {
            background: white;
            border: 1px solid #ced4da;
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 4px;
            color: #495057;
            cursor: pointer;
        }
        
        .sort-btn.active {
            background-color: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }
        
        .sort-btn:hover {
            background-color: #e9ecef;
        }
        
        .sort-btn.active:hover {
            background-color: #0b5ed7;
        }
        
        .action-bar {
            margin-bottom: 20px;
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
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span>
                </h3>
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
                        <a class="nav-link active" href="bad_orders.php">
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
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
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
            <!-- BAD ORDERS CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2><i class="bi bi-recycle me-2"></i>Bad Orders</h2>
                        <p>Manage Returned Merchandise Requests (RMR)</p>
                    </div>
                </div>

                <!-- Stats Section -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-box-seam stat-icon"></i>
                            <div class="stat-value"><?= $statTotalRMR ?></div>
                            <div class="stat-label">Total RMR</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value"><?= $statPendingRMR ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card processing">
                            <i class="bi bi-gear stat-icon"></i>
                            <div class="stat-value"><?= $statProcessingRMR ?></div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card approved">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $statApprovedRMR ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION - WITH SORT CONTROLS AND BUTTONS TOGETHER -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="sort-controls">
                            <button class="sort-btn active" onclick="sortRMR('date')">By Date</button>
                            <button class="sort-btn" onclick="sortRMR('status')">By Status</button>
                            <button class="sort-btn" onclick="sortRMR('reason')">By Reason</button>
                            <button class="sort-btn" onclick="sortRMR('quantity')">By Quantity</button>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-outline-primary" onclick="printRMRReport()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button class="btn btn-outline-primary" onclick="exportRMRToCSV()">
                            <i class="bi bi-download me-1"></i> Export
                        </button>
                    </div>
                </div>

                <!-- ACTION BAR - REMOVED (moved to filter section) -->

                <!-- RMR Table - WITHOUT CHECKBOX COLUMN -->
                <div class="table-responsive">
                    <table class="table rmr-table">
                        <thead>
                            <tr>
                                <!-- CHECKBOX HEADER REMOVED -->
                                <th class="col-rmr">RMR NUMBER</th>
                                <th class="col-customer">CUSTOMER</th>
                                <th class="col-item">ITEM</th>
                                <th class="col-qty">QTY</th>
                                <th class="col-amount">TOTAL AMOUNT</th>
                                <th class="col-reason">REASON</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-received">RECEIVED DATE</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="rmrTableBody">
                            <?php if (empty($rmr_requests)): ?>
                            <tr>
                                <td colspan="9" class="empty-state-table">
                                    <i class="bi bi-inbox"></i>
                                    <h5>No Returned Merchandise Requests</h5>
                                    <p class="text-muted">RMR requests are created by the Sales team.</p>
                                    <p class="text-muted">No requests available at this time.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($rmr_requests as $rmr): 
                                    $totalAmount = $rmr['return_quantity'] * $rmr['unit_price'];
                                ?>
                                <tr class="rmr-row" 
                                    data-id="<?= $rmr['rmr_id'] ?>"
                                    data-rmr-number="<?= htmlspecialchars($rmr['rmr_number']) ?>"
                                    data-status="<?= $rmr['rmr_status'] ?>">
                                    <!-- CHECKBOX CELL REMOVED -->
                                    <td class="col-rmr"><strong><?= htmlspecialchars($rmr['rmr_number']) ?></strong></td>
                                    <td class="col-customer"><?= htmlspecialchars($rmr['customer_name']) ?></td>
                                    <td class="col-item">
                                        <?= htmlspecialchars($rmr['item_name']) ?>
                                        <small class="d-block text-muted"><?= htmlspecialchars($rmr['item_code']) ?></small>
                                    </td>
                                    <td class="col-qty"><?= $rmr['return_quantity'] ?> <?= getUnitText($rmr['unit_type']) ?></td>
                                    <td class="col-amount">₱<?= number_format($totalAmount, 2) ?></td>
                                    <td class="col-reason">
                                        <span class="return-reason <?= getReturnReasonClass($rmr['return_reason']) ?>">
                                            <?= getReturnReasonText($rmr['return_reason']) ?>
                                        </span>
                                    </td>
                                    <td class="col-status">
                                        <span class="status-badge <?= getRMRStatusClass($rmr['rmr_status']) ?>">
                                            <?= getRMRStatusText($rmr['rmr_status']) ?>
                                        </span>
                                    </td>
                                    <td class="col-received"><?= formatDate($rmr['received_date']) ?></td>
                                    <td class="col-actions">
                                        <div class="action-buttons">
                                            <?php if ($rmr['rmr_status'] === 'pending'): ?>
                                                <button class="table-btn btn-process" onclick="processRMR(<?= $rmr['rmr_id'] ?>)" title="Process">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                            <?php elseif ($rmr['rmr_status'] === 'processing'): ?>
                                                <button class="table-btn btn-approve" onclick="showApprovalModal(<?= $rmr['rmr_id'] ?>, 'approve')" title="Approve">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                                <button class="table-btn btn-reject" onclick="showApprovalModal(<?= $rmr['rmr_id'] ?>, 'reject')" title="Reject">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="table-btn btn-view" onclick="viewRMR(<?= $rmr['rmr_id'] ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Process RMR Modal -->
    <div class="modal fade" id="processRMRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Process RMR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Process the selected RMR for quality inspection?</p>
                    <div class="mb-3">
                        <label class="form-label">Inspector Name *</label>
                        <input type="text" class="form-control" id="inspectorName" value="Quality Control Dept" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Inspection Type *</label>
                        <select class="form-select" id="inspectionType" required>
                            <option value="visual">Visual Inspection</option>
                            <option value="functional">Functional Test</option>
                            <option value="lab">Laboratory Test</option>
                            <option value="sample">Sample Testing</option>
                        </select>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        This will change RMR status to "Processing".
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmProcessRMR()">Start Processing</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve/Reject Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approvalModalTitle">Approve RMR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="approvalMessage">Approve the selected RMR for credit/refund?</p>
                    <div class="mb-3">
                        <label class="form-label">Disposition *</label>
                        <select class="form-select" id="dispositionType">
                            <option value="credit">Credit to Customer</option>
                            <option value="refund">Cash Refund</option>
                            <option value="replacement">Replacement Item</option>
                            <option value="disposal">Destroy Item</option>
                            <option value="return-to-supplier">Return to Supplier</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Approved Amount *</label>
                        <input type="number" class="form-control" id="approvedAmount" min="0" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Approval Notes</label>
                        <textarea class="form-control" id="approvalNotes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirmApproval('approve')">Approve</button>
                    <button type="button" class="btn btn-danger" onclick="confirmApproval('reject')">Reject</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View RMR Details Modal -->
    <div class="modal fade" id="viewRMRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">RMR Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="rmrDetailsContent">
                    <!-- RMR details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printRMRDetails()">
                        <i class="bi bi-printer me-1"></i> Print RMR
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let selectedRMR = null;
    let currentSort = 'date';
    
    // ========== SIDEBAR FUNCTIONS ==========
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            sidebar.classList.toggle('active');
            if (!document.querySelector('.sidebar-overlay')) {
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', closeMobileSidebar);
                setTimeout(() => overlay.classList.add('active'), 10);
            }
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
            });
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('active');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        }
    }

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth > 992) {
            const savedCollapsed = localStorage.getItem('sidebarCollapsed');
            if (savedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
            }
        }
    }

    // ========== RMR FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Bad Orders - Live Database Mode");
        
        initializeSidebar();
        
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            if (isMobile) {
                sidebar.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                }
            } else {
                toggleSidebar();
            }
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) closeMobileSidebar();
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
    });

    function sortRMR(criteria) {
        currentSort = criteria;
        document.querySelectorAll('.sort-btn').forEach(btn => btn.classList.remove('active'));
        if (event && event.target) event.target.classList.add('active');
        alert('Sort by ' + criteria + ' - AJAX implementation needed');
    }

    function processRMR(id) {
        selectedRMR = id;
        new bootstrap.Modal(document.getElementById('processRMRModal')).show();
    }

    function confirmProcessRMR() {
        alert('Process RMR ' + selectedRMR + ' - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('processRMRModal')).hide();
        selectedRMR = null;
    }

    function showApprovalModal(id, action) {
        selectedRMR = id;
        document.getElementById('approvalModalTitle').textContent = action === 'approve' ? 'Approve RMR' : 'Reject RMR';
        document.getElementById('approvalMessage').textContent = action === 'approve' 
            ? 'Approve the selected RMR for credit/refund?' 
            : 'Reject the selected RMR?';
        new bootstrap.Modal(document.getElementById('approvalModal')).show();
    }

    function confirmApproval(action) {
        alert((action === 'approve' ? 'Approve' : 'Reject') + ' RMR ' + selectedRMR + ' - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
        selectedRMR = null;
    }

    function viewRMR(id) {
        alert('View RMR ' + id + ' - AJAX implementation needed');
    }

    function printRMRDetails() {
        alert('Print RMR Details - AJAX implementation needed');
    }

    function processBatchRMR() {
        alert('Process batch RMR - AJAX implementation needed');
    }

    function approveBatchRMR() {
        alert('Approve batch RMR - AJAX implementation needed');
    }

    function rejectBatchRMR() {
        alert('Reject batch RMR - AJAX implementation needed');
    }

    function printRMRReport() {
        window.print();
    }

    function exportRMRToCSV() {
        alert('Export RMR to CSV - AJAX implementation needed');
    }

    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            localStorage.removeItem('sidebarCollapsed');
            window.location.href = 'login.php';
        }
    }
    </script>
</body>
</html>