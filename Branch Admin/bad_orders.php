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
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/bad_orders.css">
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
                <h3><img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span></h3>
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
                            <i class="bi bi-x-circle"></i>
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
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- BAD ORDERS CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-x-circle me-2"></i>Bad Orders</h2>
                        <p>Manage Returned Merchandise Requests (RMR)</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search RMR..." id="searchRMR">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Quality Control</span>
                                <span class="user-role-top">QC Officer</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </div>
                </div>

                <!-- Demo Info Card -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Returned Merchandise Request (RMR) System</h5>
                        <p class="mb-0">Process and manage returned items from customers. Track status from receipt to resolution.</p>
                    </div>
                </div>

                <!-- Stats Section -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-box-seam stat-icon"></i>
                            <div class="stat-value" id="totalRMR">8</div>
                            <div class="stat-label">Total RMR</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value" id="pendingRMR">3</div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card processing">
                            <i class="bi bi-gear stat-icon"></i>
                            <div class="stat-value" id="processingRMR">2</div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card approved">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="approvedRMR">3</div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                </div>

                <!-- Batch Actions -->
                <div class="batch-actions" id="batchActions">
                    <div class="batch-actions-content">
                        <div>
                            <span class="selected-count" id="selectedCount">0</span> RMR selected
                        </div>
                        <div class="batch-buttons">
                            <button class="btn btn-sm btn-outline-primary" onclick="processBatchRMR()">
                                <i class="bi bi-gear me-1"></i> Start Processing
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="approveBatchRMR()">
                                <i class="bi bi-check-circle me-1"></i> Approve Selected
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="rejectBatchRMR()">
                                <i class="bi bi-x-circle me-1"></i> Reject Selected
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">
                                <i class="bi bi-x-circle me-1"></i> Clear Selection
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div class="action-bar">
                    <div class="action-bar-content">
                        <div class="action-left">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllRMR">
                                <label class="form-check-label" for="selectAllRMR">
                                    Select All
                                </label>
                            </div>
                            <div class="sort-controls">
                                <button class="sort-btn active" onclick="sortRMR('date')">By Date</button>
                                <button class="sort-btn" onclick="sortRMR('status')">By Status</button>
                                <button class="sort-btn" onclick="sortRMR('reason')">By Reason</button>
                                <button class="sort-btn" onclick="sortRMR('quantity')">By Quantity</button>
                            </div>
                        </div>
                        
                        <div class="action-right">
                            <button class="btn btn-outline-primary" onclick="printRMRReport()">
                                <i class="bi bi-printer me-1"></i> Print Report
                            </button>
                            <button class="btn btn-outline-primary" onclick="exportRMRToCSV()">
                                <i class="bi bi-download me-1"></i> Export
                            </button>
                            <button class="btn btn-primary" onclick="showNewRMRModal()">
                                <i class="bi bi-plus-circle me-1"></i> New RMR
                            </button>
                        </div>
                    </div>
                </div>

                <!-- RMR Container -->
                <div id="rmrContainer">
                    <!-- RMR items will be loaded here by JavaScript -->
                </div>

                <!-- Add RMR Card -->
                <div class="new-bad-order-card" onclick="showNewRMRModal()">
                    <div class="add-icon">
                        <i class="bi bi-plus-lg"></i>
                    </div>
                    <h5>Create New RMR</h5>
                    <p>Click to create a new Returned Merchandise Request</p>
                </div>

                <!-- Empty State -->
                <div class="empty-state" id="emptyState">
                    <div class="empty-state-icon">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h4>No Returned Merchandise Requests</h4>
                    <p class="text-muted mb-4">Create your first RMR to get started</p>
                    <button class="btn btn-primary" onclick="showNewRMRModal()">
                        <i class="bi bi-plus-circle me-1"></i> Create First RMR
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- New RMR Modal -->
    <div class="modal fade" id="newRMRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Returned Merchandise Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="rmrForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="salesOrderNo" class="form-label">Sales Order No. *</label>
                                <input type="text" class="form-control" id="salesOrderNo" required>
                            </div>
                            <div class="col-md-6">
                                <label for="customerName" class="form-label">Customer Name *</label>
                                <input type="text" class="form-control" id="customerName" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="itemCode" class="form-label">Item Code *</label>
                                <input type="text" class="form-control" id="itemCode" required>
                            </div>
                            <div class="col-md-6">
                                <label for="itemName" class="form-label">Item Name *</label>
                                <input type="text" class="form-control" id="itemName" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="returnQuantity" class="form-label">Return Quantity *</label>
                                <input type="number" class="form-control" id="returnQuantity" min="1" required>
                            </div>
                            <div class="col-md-4">
                                <label for="unitType" class="form-label">Unit Type *</label>
                                <select class="form-select" id="unitType" required>
                                    <option value="case">Case</option>
                                    <option value="inner-pack">Inner Pack</option>
                                    <option value="piece">Piece</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="unitPrice" class="form-label">Unit Price *</label>
                                <input type="number" class="form-control" id="unitPrice" min="0" step="0.01" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="returnReason" class="form-label">Return Reason *</label>
                                <select class="form-select" id="returnReason" required>
                                    <option value="">Select Reason</option>
                                    <option value="damaged">Damaged/Defective</option>
                                    <option value="expired">Expired Product</option>
                                    <option value="wrong-item">Wrong Item Delivered</option>
                                    <option value="quality">Quality Issues</option>
                                    <option value="overstock">Customer Overstock</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="reasonDetails" class="form-label">Reason Details *</label>
                                <textarea class="form-control" id="reasonDetails" rows="3" required></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="receivedBy" class="form-label">Received By *</label>
                                <input type="text" class="form-control" id="receivedBy" value="marinellemacalir" required>
                            </div>
                            <div class="col-md-6">
                                <label for="receivedDate" class="form-label">Received Date *</label>
                                <input type="datetime-local" class="form-control" id="receivedDate" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="remarks" class="form-label">Additional Remarks</label>
                                <textarea class="form-control" id="remarks" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveRMR()">Create RMR</button>
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
                        This will change RMR status to "Processing" and assign to quality inspector.
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
                            <option value="credit">Credit to Customer Account</option>
                            <option value="refund">Cash Refund</option>
                            <option value="replacement">Replacement Item</option>
                            <option value="destroy">Destroy Item</option>
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
        // Sample data for RMR (Returned Merchandise Request)
        let rmrs = [
            {
                id: 1,
                rmrNumber: 'RMR-2024-001',
                salesOrderNo: 'SO-2024-001245',
                customerName: 'Juan Dela Cruz',
                itemCode: 'SC',
                itemName: 'SWAKTO COKE 190MLX12',
                returnQuantity: 5,
                unitType: 'case',
                unitPrice: 1500,
                totalAmount: 7500,
                returnReason: 'damaged',
                reasonDetails: 'Bottles arrived broken during delivery',
                status: 'pending',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-15T14:30',
                dateCreated: '2024-01-15T14:30',
                isSelected: false,
                inspector: '',
                inspectionDate: '',
                disposition: '',
                approvalDate: '',
                approvalNotes: ''
            },
            {
                id: 2,
                rmrNumber: 'RMR-2024-002',
                salesOrderNo: 'SO-2024-001244',
                customerName: 'Maria Santos',
                itemCode: 'COBRA YLW',
                itemName: 'COBRA YELLOW 290MLX12',
                returnQuantity: 2,
                unitType: 'case',
                unitPrice: 2800,
                totalAmount: 5600,
                returnReason: 'expired',
                reasonDetails: 'Product near expiration date',
                status: 'processing',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-14T10:15',
                dateCreated: '2024-01-14T10:15',
                isSelected: false,
                inspector: 'Quality Control Dept',
                inspectionDate: '2024-01-14T15:30',
                disposition: '',
                approvalDate: '',
                approvalNotes: ''
            },
            {
                id: 3,
                rmrNumber: 'RMR-2024-003',
                salesOrderNo: 'SO-2024-001243',
                customerName: 'ABC Corporation',
                itemCode: 'FRASCO',
                itemName: 'GINEBRA FRASCO 700MLX12',
                returnQuantity: 1,
                unitType: 'case',
                unitPrice: 4200,
                totalAmount: 4200,
                returnReason: 'wrong-item',
                reasonDetails: 'Wrong product variant delivered',
                status: 'approved',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-13T09:45',
                dateCreated: '2024-01-13T09:45',
                isSelected: false,
                inspector: 'Quality Control Dept',
                inspectionDate: '2024-01-13T14:20',
                disposition: 'credit',
                approvalDate: '2024-01-14T11:00',
                approvalNotes: 'Approved for credit to customer account',
                approvedAmount: 4200
            },
            {
                id: 4,
                rmrNumber: 'RMR-2024-004',
                salesOrderNo: 'SO-2024-001242',
                customerName: 'XYZ Enterprises',
                itemCode: 'REDHORSE',
                itemName: 'RED HORSE 500MLX12',
                returnQuantity: 3,
                unitType: 'case',
                unitPrice: 3200,
                totalAmount: 9600,
                returnReason: 'damaged',
                reasonDetails: 'Water damage to packaging',
                status: 'pending',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-12T16:20',
                dateCreated: '2024-01-12T16:20',
                isSelected: false,
                inspector: '',
                inspectionDate: '',
                disposition: '',
                approvalDate: '',
                approvalNotes: ''
            },
            {
                id: 5,
                rmrNumber: 'RMR-2024-005',
                salesOrderNo: 'SO-2024-001241',
                customerName: 'John Smith',
                itemCode: 'PALE PILSEN',
                itemName: 'SAN MIGUEL PALE PILSEN 330MLX24',
                returnQuantity: 10,
                unitType: 'case',
                unitPrice: 1800,
                totalAmount: 18000,
                returnReason: 'quality',
                reasonDetails: 'Product taste not consistent',
                status: 'processing',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-11T13:45',
                dateCreated: '2024-01-11T13:45',
                isSelected: false,
                inspector: 'Quality Control Dept',
                inspectionDate: '2024-01-12T09:15',
                disposition: '',
                approvalDate: '',
                approvalNotes: ''
            },
            {
                id: 6,
                rmrNumber: 'RMR-2024-006',
                salesOrderNo: 'SO-2024-001240',
                customerName: 'DEF Supermarket',
                itemCode: 'ROYAL',
                itemName: 'ROYAL TRU-ORANGE 240MLX24',
                returnQuantity: 8,
                unitType: 'inner-pack',
                unitPrice: 450,
                totalAmount: 3600,
                returnReason: 'expired',
                reasonDetails: 'Expired product returned',
                status: 'approved',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-10T11:30',
                dateCreated: '2024-01-10T11:30',
                isSelected: false,
                inspector: 'Quality Control Dept',
                inspectionDate: '2024-01-11T10:45',
                disposition: 'refund',
                approvalDate: '2024-01-12T14:30',
                approvalNotes: 'Approved for cash refund',
                approvedAmount: 3600
            },
            {
                id: 7,
                rmrNumber: 'RMR-2024-007',
                salesOrderNo: 'SO-2024-001239',
                customerName: 'GHI Store',
                itemCode: 'COKE 1.5L',
                itemName: 'COCA-COLA 1.5L PET',
                returnQuantity: 15,
                unitType: 'piece',
                unitPrice: 85,
                totalAmount: 1275,
                returnReason: 'damaged',
                reasonDetails: 'Bottles leaking',
                status: 'rejected',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-09T15:20',
                dateCreated: '2024-01-09T15:20',
                isSelected: false,
                inspector: 'Quality Control Dept',
                inspectionDate: '2024-01-10T11:00',
                disposition: 'rejected',
                approvalDate: '2024-01-11T09:45',
                approvalNotes: 'Rejected - damage caused by improper handling',
                approvedAmount: 0
            },
            {
                id: 8,
                rmrNumber: 'RMR-2024-008',
                salesOrderNo: 'SO-2024-001238',
                customerName: 'JKL Mart',
                itemCode: 'SPRITE 500ML',
                itemName: 'SPRITE 500MLX24',
                returnQuantity: 4,
                unitType: 'case',
                unitPrice: 2100,
                totalAmount: 8400,
                returnReason: 'other',
                reasonDetails: 'Customer ordered wrong product',
                status: 'approved',
                receivedBy: 'marinellemacalir',
                receivedDate: '2024-01-08T10:00',
                dateCreated: '2024-01-08T10:00',
                isSelected: false,
                inspector: 'Quality Control Dept',
                inspectionDate: '2024-01-09T14:30',
                disposition: 'replacement',
                approvalDate: '2024-01-10T16:15',
                approvalNotes: 'Approved for replacement with correct product',
                approvedAmount: 0
            }
        ];

        let selectedRMR = null;
        let currentSort = 'date';
        let selectedItems = [];

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
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

            // Search functionality with debounce
            let searchTimeout;
            document.getElementById('searchRMR').addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    filterRMR(e.target.value);
                }, 300);
            });

            // Add select all functionality
            document.getElementById('selectAllRMR').addEventListener('change', function(e) {
                const isChecked = e.target.checked;
                rmrs.forEach(rmr => {
                    rmr.isSelected = isChecked;
                });
                updateSelection();
                renderRMR();
            });

            // Initialize with current date/time
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            document.getElementById('receivedDate').value = formattedDateTime;

            // Load from localStorage if available
            loadFromLocalStorage();
            
            // Initialize UI
            updateStats();
            renderRMR();

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + N for new RMR
                if (e.ctrlKey && e.key === 'n') {
                    e.preventDefault();
                    showNewRMRModal();
                }
                // Ctrl + F for focus search
                else if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    document.getElementById('searchRMR').focus();
                }
                // Escape to close modals
                else if (e.key === 'Escape') {
                    const openModals = document.querySelectorAll('.modal.show');
                    if (openModals.length > 0) {
                        const modal = bootstrap.Modal.getInstance(openModals[0]);
                        if (modal) modal.hide();
                    }
                }
                // Ctrl + A to select all
                else if (e.ctrlKey && e.key === 'a') {
                    e.preventDefault();
                    const selectAll = document.getElementById('selectAllRMR');
                    selectAll.checked = !selectAll.checked;
                    selectAll.dispatchEvent(new Event('change'));
                }
            });
        });

        // Render RMR items
        function renderRMR(items = rmrs) {
            const container = document.getElementById('rmrContainer');
            const emptyState = document.getElementById('emptyState');
            
            if (items.length === 0) {
                container.style.display = 'none';
                emptyState.style.display = 'block';
                return;
            }
            
            container.style.display = 'block';
            emptyState.style.display = 'none';
            container.innerHTML = '';
            
            items.forEach(rmr => {
                const statusClass = getStatusClass(rmr.status);
                const statusText = getStatusText(rmr.status);
                const reasonClass = getReasonClass(rmr.returnReason);
                const reasonText = getReasonText(rmr.returnReason);
                
                const card = document.createElement('div');
                card.className = 'bad-order-card';
                card.innerHTML = `
                    <div class="card-header-row">
                        <div class="card-left-header">
                            <input type="checkbox" class="form-check-input item-checkbox" 
                                   ${rmr.isSelected ? 'checked' : ''} 
                                   onchange="toggleRMRSelection(${rmr.id}, this.checked)">
                            <div class="rmr-number">${rmr.rmrNumber}</div>
                            <span class="return-reason ${reasonClass}">${reasonText}</span>
                        </div>
                        
                        <div class="bad-order-actions">
                            ${getRMRActions(rmr)}
                        </div>
                    </div>
                    
                    <div class="item-details">
                        <div class="item-name">
                            ${rmr.itemName}
                            <span class="item-code">(${rmr.itemCode})</span>
                        </div>
                        
                        <div class="quantities">
                            <div class="quantity-badge">
                                <span class="quantity-label">Return Qty</span>
                                <span class="quantity-value">${rmr.returnQuantity} ${getUnitText(rmr.unitType)}</span>
                            </div>
                            <div class="quantity-badge">
                                <span class="quantity-label">Unit Price</span>
                                <span class="quantity-value">₱${rmr.unitPrice.toLocaleString()}</span>
                            </div>
                            <div class="quantity-badge">
                                <span class="quantity-label">Total Amount</span>
                                <span class="quantity-value">₱${rmr.totalAmount.toLocaleString()}</span>
                            </div>
                        </div>
                        
                        <div class="return-details">
                            <div><strong>Customer:</strong> ${rmr.customerName}</div>
                            <div><strong>Sales Order:</strong> ${rmr.salesOrderNo}</div>
                            <div><strong>Reason:</strong> ${rmr.reasonDetails}</div>
                        </div>
                        
                        ${getAdditionalRMRInfo(rmr)}
                        
                        <div class="item-meta-row">
                            <div class="item-meta">
                                <span><i class="bi bi-person"></i> ${rmr.receivedBy}</span>
                                <span><i class="bi bi-calendar"></i> ${formatDate(rmr.receivedDate)}</span>
                            </div>
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </div>
                    </div>
                `;
                
                container.appendChild(card);
            });
        }

        // Get RMR action buttons based on status
        function getRMRActions(rmr) {
            let buttons = '';
            
            if (rmr.status === 'pending') {
                buttons = `
                    <button class="bad-order-btn btn-process" onclick="processRMR(${rmr.id})">
                        <i class="bi bi-gear"></i> Process
                    </button>
                    <button class="bad-order-btn btn-view" onclick="viewRMR(${rmr.id})">
                        <i class="bi bi-eye"></i> View
                    </button>
                `;
            } else if (rmr.status === 'processing') {
                buttons = `
                    <button class="bad-order-btn btn-approve" onclick="showApprovalModal(${rmr.id}, 'approve')">
                        <i class="bi bi-check-circle"></i> Approve
                    </button>
                    <button class="bad-order-btn btn-reject" onclick="showApprovalModal(${rmr.id}, 'reject')">
                        <i class="bi bi-x-circle"></i> Reject
                    </button>
                    <button class="bad-order-btn btn-view" onclick="viewRMR(${rmr.id})">
                        <i class="bi bi-eye"></i> View
                    </button>
                `;
            } else {
                buttons = `
                    <button class="bad-order-btn btn-completed">
                        <i class="bi bi-check-circle"></i> ${rmr.status === 'approved' ? 'Approved' : 'Rejected'}
                    </button>
                    <button class="bad-order-btn btn-view" onclick="viewRMR(${rmr.id})">
                        <i class="bi bi-eye"></i> View
                    </button>
                `;
            }
            
            return buttons;
        }

        // Get additional RMR information
        function getAdditionalRMRInfo(rmr) {
            let info = '';
            
            if (rmr.inspector || rmr.approvalDate || rmr.disposition) {
                info = '<div class="additional-info">';
                
                if (rmr.inspector) {
                    info += `<div><i class="bi bi-person-check"></i> Inspector: ${rmr.inspector}</div>`;
                }
                
                if (rmr.approvalDate) {
                    info += `<div><i class="bi bi-calendar-check"></i> ${rmr.status === 'approved' ? 'Approved' : 'Rejected'}: ${formatDate(rmr.approvalDate)}</div>`;
                }
                
                if (rmr.disposition && rmr.status === 'approved') {
                    info += `<div><i class="bi bi-arrow-repeat"></i> Disposition: ${getDispositionText(rmr.disposition)}</div>`;
                }
                
                if (rmr.approvedAmount && rmr.status === 'approved') {
                    info += `<div><i class="bi bi-cash-coin"></i> Approved Amount: ₱${rmr.approvedAmount.toLocaleString()}</div>`;
                }
                
                info += '</div>';
            }
            
            return info;
        }

        // Show new RMR modal
        function showNewRMRModal() {
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            document.getElementById('receivedDate').value = formattedDateTime;
            document.getElementById('receivedBy').value = 'marinellemacalir';
            
            const modal = new bootstrap.Modal(document.getElementById('newRMRModal'));
            modal.show();
        }

        // Save new RMR
        function saveRMR() {
            const rmr = {
                salesOrderNo: document.getElementById('salesOrderNo').value.trim(),
                customerName: document.getElementById('customerName').value.trim(),
                itemCode: document.getElementById('itemCode').value.trim(),
                itemName: document.getElementById('itemName').value.trim(),
                returnQuantity: parseInt(document.getElementById('returnQuantity').value) || 0,
                unitType: document.getElementById('unitType').value,
                unitPrice: parseFloat(document.getElementById('unitPrice').value) || 0,
                returnReason: document.getElementById('returnReason').value,
                reasonDetails: document.getElementById('reasonDetails').value.trim(),
                receivedBy: document.getElementById('receivedBy').value.trim(),
                receivedDate: document.getElementById('receivedDate').value,
                remarks: document.getElementById('remarks').value.trim(),
                status: 'pending',
                isSelected: false
            };
            
            // Validation
            const errors = validateRMR(rmr);
            if (errors.length > 0) {
                showNotification(errors.join(', '), 'warning');
                return;
            }
            
            // Calculate total amount
            rmr.totalAmount = rmr.returnQuantity * rmr.unitPrice;
            
            // Generate RMR number
            const newId = rmrs.length > 0 ? Math.max(...rmrs.map(r => r.id)) + 1 : 1;
            rmr.id = newId;
            rmr.rmrNumber = `RMR-${new Date().getFullYear()}-${String(newId).padStart(3, '0')}`;
            rmr.dateCreated = new Date().toISOString();
            
            rmrs.push(rmr);
            saveToLocalStorage();
            
            bootstrap.Modal.getInstance(document.getElementById('newRMRModal')).hide();
            showNotification('RMR created successfully', 'success');
            
            updateStats();
            renderRMR();
        }

        // Validate RMR
        function validateRMR(rmr) {
            const errors = [];
            
            if (!rmr.salesOrderNo) errors.push('Sales Order No. is required');
            if (!rmr.customerName) errors.push('Customer Name is required');
            if (!rmr.itemCode) errors.push('Item Code is required');
            if (!rmr.itemName) errors.push('Item Name is required');
            if (rmr.returnQuantity <= 0) errors.push('Return Quantity must be greater than 0');
            if (rmr.unitPrice <= 0) errors.push('Unit Price must be greater than 0');
            if (!rmr.returnReason) errors.push('Return Reason is required');
            if (!rmr.reasonDetails) errors.push('Reason Details are required');
            if (!rmr.receivedBy) errors.push('Received By is required');
            if (!rmr.receivedDate) errors.push('Received Date is required');
            
            return errors;
        }

        // Process single RMR
        function processRMR(id) {
            selectedRMR = id;
            const modal = new bootstrap.Modal(document.getElementById('processRMRModal'));
            modal.show();
        }

        // Confirm process RMR
        function confirmProcessRMR() {
            const inspector = document.getElementById('inspectorName').value;
            const inspectionType = document.getElementById('inspectionType').value;
            
            const rmr = rmrs.find(r => r.id === selectedRMR);
            if (rmr) {
                rmr.status = 'processing';
                rmr.inspector = inspector;
                rmr.inspectionDate = new Date().toISOString();
                rmr.inspectionType = inspectionType;
                
                saveToLocalStorage();
                showNotification('RMR processing started', 'success');
            }
            
            updateStats();
            renderRMR();
            bootstrap.Modal.getInstance(document.getElementById('processRMRModal')).hide();
            selectedRMR = null;
        }

        // Show approval modal
        function showApprovalModal(id, action) {
            selectedRMR = id;
            const rmr = rmrs.find(r => r.id === id);
            
            if (rmr) {
                document.getElementById('approvalModalTitle').textContent = 
                    action === 'approve' ? 'Approve RMR' : 'Reject RMR';
                document.getElementById('approvalMessage').textContent = 
                    action === 'approve' 
                    ? 'Approve the selected RMR for credit/refund?' 
                    : 'Reject the selected RMR?';
                document.getElementById('approvedAmount').value = rmr.totalAmount;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
            modal.show();
        }

        // Confirm approval/rejection
        function confirmApproval(action) {
            const disposition = document.getElementById('dispositionType').value;
            const approvedAmount = parseFloat(document.getElementById('approvedAmount').value) || 0;
            const approvalNotes = document.getElementById('approvalNotes').value.trim();
            
            const rmr = rmrs.find(r => r.id === selectedRMR);
            if (rmr) {
                rmr.status = action === 'approve' ? 'approved' : 'rejected';
                rmr.disposition = action === 'approve' ? disposition : 'rejected';
                rmr.approvedAmount = action === 'approve' ? approvedAmount : 0;
                rmr.approvalNotes = approvalNotes;
                rmr.approvalDate = new Date().toISOString();
                
                saveToLocalStorage();
                showNotification(`RMR ${action === 'approve' ? 'approved' : 'rejected'} successfully`, 'success');
            }
            
            updateStats();
            renderRMR();
            bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
            selectedRMR = null;
        }

        // View RMR details
        function viewRMR(id) {
            const rmr = rmrs.find(r => r.id === id);
            if (!rmr) return;
            
            const content = document.getElementById('rmrDetailsContent');
            content.innerHTML = `
                <div class="rmr-details">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6>RMR Information</h6>
                            <p><strong>RMR Number:</strong> ${rmr.rmrNumber}</p>
                            <p><strong>Status:</strong> <span class="${getStatusClass(rmr.status)}">${getStatusText(rmr.status)}</span></p>
                            <p><strong>Date Created:</strong> ${formatDate(rmr.dateCreated)}</p>
                        </div>
                        <div class="col-md-6">
                            <h6>Customer Information</h6>
                            <p><strong>Customer:</strong> ${rmr.customerName}</p>
                            <p><strong>Sales Order No:</strong> ${rmr.salesOrderNo}</p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-12">
                            <h6>Item Details</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Item Code</th>
                                            <th>Item Description</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Total Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>${rmr.itemCode}</td>
                                            <td>${rmr.itemName}</td>
                                            <td>${rmr.returnQuantity} ${getUnitText(rmr.unitType)}</td>
                                            <td>₱${rmr.unitPrice.toLocaleString()}</td>
                                            <td>₱${rmr.totalAmount.toLocaleString()}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-12">
                            <h6>Return Information</h6>
                            <p><strong>Return Reason:</strong> <span class="${getReasonClass(rmr.returnReason)}">${getReasonText(rmr.returnReason)}</span></p>
                            <p><strong>Reason Details:</strong> ${rmr.reasonDetails}</p>
                            <p><strong>Received By:</strong> ${rmr.receivedBy}</p>
                            <p><strong>Received Date:</strong> ${formatDate(rmr.receivedDate)}</p>
                            ${rmr.remarks ? `<p><strong>Remarks:</strong> ${rmr.remarks}</p>` : ''}
                        </div>
                    </div>
                    
                    ${rmr.inspector ? `
                    <div class="row mb-3">
                        <div class="col-12">
                            <h6>Quality Inspection</h6>
                            <p><strong>Inspector:</strong> ${rmr.inspector}</p>
                            <p><strong>Inspection Date:</strong> ${formatDate(rmr.inspectionDate)}</p>
                            ${rmr.inspectionType ? `<p><strong>Inspection Type:</strong> ${rmr.inspectionType}</p>` : ''}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${rmr.approvalDate ? `
                    <div class="row">
                        <div class="col-12">
                            <h6>Disposition</h6>
                            <p><strong>Status:</strong> ${rmr.status === 'approved' ? 'Approved' : 'Rejected'}</p>
                            <p><strong>Disposition:</strong> ${getDispositionText(rmr.disposition)}</p>
                            ${rmr.approvedAmount > 0 ? `<p><strong>Approved Amount:</strong> ₱${rmr.approvedAmount.toLocaleString()}</p>` : ''}
                            <p><strong>Approval Date:</strong> ${formatDate(rmr.approvalDate)}</p>
                            ${rmr.approvalNotes ? `<p><strong>Approval Notes:</strong> ${rmr.approvalNotes}</p>` : ''}
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('viewRMRModal'));
            modal.show();
        }

        // Print RMR details
        function printRMRDetails() {
            const content = document.getElementById('rmrDetailsContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>RMR Details Print</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h5 { color: #333; margin-bottom: 20px; }
                        .rmr-details { margin: 0; padding: 0; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f5f5f5; }
                    </style>
                </head>
                <body>
                    ${content}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Batch process RMR
        function processBatchRMR() {
            const selected = rmrs.filter(r => r.isSelected && r.status === 'pending');
            if (selected.length === 0) {
                showNotification('Please select pending RMRs to process', 'warning');
                return;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('processRMRModal'));
            modal.show();
        }

        // Batch approve RMR
        function approveBatchRMR() {
            const selected = rmrs.filter(r => r.isSelected && r.status === 'processing');
            if (selected.length === 0) {
                showNotification('Please select processing RMRs to approve', 'warning');
                return;
            }
            
            selectedRMR = null; // Indicate batch mode
            showApprovalModal(null, 'approve');
        }

        // Batch reject RMR
        function rejectBatchRMR() {
            const selected = rmrs.filter(r => r.isSelected && r.status === 'processing');
            if (selected.length === 0) {
                showNotification('Please select processing RMRs to reject', 'warning');
                return;
            }
            
            selectedRMR = null; // Indicate batch mode
            showApprovalModal(null, 'reject');
        }

        // Toggle RMR selection
        function toggleRMRSelection(id, isSelected) {
            const rmr = rmrs.find(r => r.id === id);
            if (rmr) {
                rmr.isSelected = isSelected;
                updateSelection();
            }
        }

        // Update selection state
        function updateSelection() {
            selectedItems = rmrs.filter(r => r.isSelected);
            const selectedCount = selectedItems.length;
            
            document.getElementById('selectedCount').textContent = selectedCount;
            
            const batchActions = document.getElementById('batchActions');
            const selectAll = document.getElementById('selectAllRMR');
            
            if (selectedCount > 0) {
                batchActions.style.display = 'block';
                selectAll.checked = selectedCount === rmrs.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < rmrs.length;
            } else {
                batchActions.style.display = 'none';
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }
        }

        // Clear selection
        function clearSelection() {
            rmrs.forEach(rmr => {
                rmr.isSelected = false;
            });
            updateSelection();
            renderRMR();
        }

        // Update stats
        function updateStats() {
            const totalRMR = rmrs.length;
            const pendingRMR = rmrs.filter(r => r.status === 'pending').length;
            const processingRMR = rmrs.filter(r => r.status === 'processing').length;
            const approvedRMR = rmrs.filter(r => r.status === 'approved').length;
            const rejectedRMR = rmrs.filter(r => r.status === 'rejected').length;
            
            document.getElementById('totalRMR').textContent = totalRMR;
            document.getElementById('pendingRMR').textContent = pendingRMR;
            document.getElementById('processingRMR').textContent = processingRMR;
            document.getElementById('approvedRMR').textContent = approvedRMR;
        }

        // Sort RMR
        function sortRMR(criteria) {
            currentSort = criteria;
            
            // Update active button
            document.querySelectorAll('.sort-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            let sortedRMR = [...rmrs];
            
            switch(criteria) {
                case 'date':
                    sortedRMR.sort((a, b) => new Date(b.dateCreated) - new Date(a.dateCreated));
                    break;
                case 'status':
                    const statusOrder = { 'pending': 0, 'processing': 1, 'approved': 2, 'rejected': 3 };
                    sortedRMR.sort((a, b) => statusOrder[a.status] - statusOrder[b.status]);
                    break;
                case 'reason':
                    const reasonOrder = { 'damaged': 0, 'expired': 1, 'wrong-item': 2, 'quality': 3, 'other': 4 };
                    sortedRMR.sort((a, b) => reasonOrder[a.returnReason] - reasonOrder[b.returnReason]);
                    break;
                case 'quantity':
                    sortedRMR.sort((a, b) => b.returnQuantity - a.returnQuantity);
                    break;
            }
            
            renderRMR(sortedRMR);
        }

        // Filter RMR
        function filterRMR(searchTerm) {
            if (!searchTerm) {
                renderRMR();
                return;
            }
            
            const filtered = rmrs.filter(rmr => 
                rmr.rmrNumber.toLowerCase().includes(searchTerm.toLowerCase()) ||
                rmr.customerName.toLowerCase().includes(searchTerm.toLowerCase()) ||
                rmr.itemName.toLowerCase().includes(searchTerm.toLowerCase()) ||
                rmr.itemCode.toLowerCase().includes(searchTerm.toLowerCase()) ||
                rmr.salesOrderNo.toLowerCase().includes(searchTerm.toLowerCase()) ||
                rmr.reasonDetails.toLowerCase().includes(searchTerm.toLowerCase())
            );
            
            renderRMR(filtered);
        }

        // Helper functions
        function getStatusClass(status) {
            switch(status) {
                case 'pending': return 'status-pending';
                case 'processing': return 'status-processing';
                case 'approved': return 'status-approved';
                case 'rejected': return 'status-rejected';
                case 'completed': return 'status-completed';
                default: return 'status-pending';
            }
        }

        function getStatusText(status) {
            switch(status) {
                case 'pending': return 'Pending';
                case 'processing': return 'Processing';
                case 'approved': return 'Approved';
                case 'rejected': return 'Rejected';
                case 'completed': return 'Completed';
                default: return 'Pending';
            }
        }

        function getReasonClass(reason) {
            switch(reason) {
                case 'damaged': return 'reason-damaged';
                case 'expired': return 'reason-expired';
                case 'wrong-item': return 'reason-wrong-item';
                case 'quality': return 'reason-quality';
                case 'other': return 'reason-other';
                default: return 'reason-other';
            }
        }

        function getReasonText(reason) {
            switch(reason) {
                case 'damaged': return 'Damaged';
                case 'expired': return 'Expired';
                case 'wrong-item': return 'Wrong Item';
                case 'quality': return 'Quality Issue';
                case 'other': return 'Other';
                default: return 'Other';
            }
        }

        function getUnitText(unit) {
            switch(unit) {
                case 'case': return 'CS';
                case 'inner-pack': return 'IP';
                case 'piece': return 'PC';
                default: return unit;
            }
        }

        function getDispositionText(disposition) {
            switch(disposition) {
                case 'credit': return 'Credit to Customer';
                case 'refund': return 'Cash Refund';
                case 'replacement': return 'Replacement';
                case 'destroy': return 'Destroy Item';
                case 'return-to-supplier': return 'Return to Supplier';
                case 'rejected': return 'Rejected';
                default: return disposition;
            }
        }

        function formatDate(dateTimeStr) {
            const date = new Date(dateTimeStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function showNotification(message, type = 'success') {
            // Remove existing notifications
            document.querySelectorAll('.notification').forEach(notif => notif.remove());
            
            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'notification';
            notification.innerHTML = `
                <i class="bi ${type === 'success' ? 'bi-check-circle' : 
                              type === 'warning' ? 'bi-exclamation-triangle' : 
                              'bi-info-circle'} me-2"></i>
                ${message}
            `;
            
            // Style the notification
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                background: ${type === 'success' ? '#10b981' : 
                             type === 'warning' ? '#f59e0b' : 
                             '#3b82f6'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                animation: slideIn 0.3s ease;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 14px;
                display: flex;
                align-items: center;
                max-width: 400px;
            `;
            
            document.body.appendChild(notification);
            
            // Add animation styles
            if (!document.querySelector('#notification-styles')) {
                const style = document.createElement('style');
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideIn {
                        from {
                            transform: translateX(100%);
                            opacity: 0;
                        }
                        to {
                            transform: translateX(0);
                            opacity: 1;
                        }
                    }
                    @keyframes slideOut {
                        from {
                            transform: translateX(0);
                            opacity: 1;
                        }
                        to {
                            transform: translateX(100%);
                            opacity: 0;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Print RMR report
        function printRMRReport() {
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>RMR Report - ${new Date().toLocaleDateString()}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #333; margin-bottom: 10px; }
                        .print-date { color: #666; margin-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                        th { background-color: #f5f5f5; font-weight: bold; }
                        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
                        .pending { background-color: #fff3cd; color: #856404; }
                        .processing { background-color: #cce5ff; color: #004085; }
                        .approved { background-color: #d4edda; color: #155724; }
                        .rejected { background-color: #f8d7da; color: #721c24; }
                        .summary { margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; }
                        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                    </style>
                </head>
                <body>
                    <h1>Returned Merchandise Request (RMR) Report</h1>
                    <div class="print-date">Generated: ${new Date().toLocaleString()}</div>
                    <table>
                        <thead>
                            <tr>
                                <th>RMR No.</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rmrs.map(rmr => `
                                <tr>
                                    <td>${rmr.rmrNumber}</td>
                                    <td>${formatDate(rmr.dateCreated)}</td>
                                    <td>${rmr.customerName}</td>
                                    <td>${rmr.itemName}</td>
                                    <td>${rmr.returnQuantity}</td>
                                    <td>₱${rmr.totalAmount.toLocaleString()}</td>
                                    <td>${getReasonText(rmr.returnReason)}</td>
                                    <td><span class="status ${rmr.status}">${getStatusText(rmr.status)}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    <div class="summary">
                        <h3>Summary</h3>
                        <p><strong>Total RMR:</strong> ${rmrs.length}</p>
                        <p><strong>Pending:</strong> ${rmrs.filter(r => r.status === 'pending').length}</p>
                        <p><strong>Processing:</strong> ${rmrs.filter(r => r.status === 'processing').length}</p>
                        <p><strong>Approved:</strong> ${rmrs.filter(r => r.status === 'approved').length}</p>
                        <p><strong>Rejected:</strong> ${rmrs.filter(r => r.status === 'rejected').length}</p>
                        <p><strong>Total Amount:</strong> ₱${rmrs.reduce((sum, rmr) => sum + rmr.totalAmount, 0).toLocaleString()}</p>
                    </div>
                    <div class="footer">
                        <p>Returned Merchandise Request System | Printed by: Quality Control Dept</p>
                    </div>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.print();
        }

        // Export RMR to CSV
        function exportRMRToCSV() {
            try {
                const headers = ['RMR_Number', 'Date_Created', 'Sales_Order_No', 'Customer_Name', 'Item_Code', 'Item_Name', 
                                'Return_Quantity', 'Unit_Type', 'Unit_Price', 'Total_Amount', 'Return_Reason', 'Reason_Details',
                                'Status', 'Received_By', 'Received_Date', 'Inspector', 'Inspection_Date', 'Disposition', 
                                'Approved_Amount', 'Approval_Date', 'Approval_Notes'];
                
                const csvData = rmrs.map(rmr => [
                    rmr.rmrNumber,
                    new Date(rmr.dateCreated).toISOString(),
                    rmr.salesOrderNo,
                    rmr.customerName,
                    rmr.itemCode,
                    `"${rmr.itemName.replace(/"/g, '""')}"`,
                    rmr.returnQuantity,
                    rmr.unitType,
                    rmr.unitPrice,
                    rmr.totalAmount,
                    getReasonText(rmr.returnReason),
                    `"${rmr.reasonDetails.replace(/"/g, '""')}"`,
                    getStatusText(rmr.status),
                    rmr.receivedBy,
                    new Date(rmr.receivedDate).toISOString(),
                    rmr.inspector || '',
                    rmr.inspectionDate ? new Date(rmr.inspectionDate).toISOString() : '',
                    getDispositionText(rmr.disposition) || '',
                    rmr.approvedAmount || '0',
                    rmr.approvalDate ? new Date(rmr.approvalDate).toISOString() : '',
                    `"${(rmr.approvalNotes || '').replace(/"/g, '""')}"`
                ]);
                
                const csvContent = [
                    headers.join(','),
                    ...csvData.map(row => row.join(','))
                ].join('\n');
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                const fileName = `rmr_report_${new Date().toISOString().slice(0, 10)}.csv`;
                link.setAttribute('download', fileName);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                showNotification(`Exported ${rmrs.length} RMR records to CSV`, 'success');
            } catch (error) {
                console.error('Export error:', error);
                showNotification('Failed to export data', 'warning');
            }
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                showNotification('Logged out successfully', 'info');
                setTimeout(() => {
                    alert('Redirecting to login page...');
                }, 1000);
            }
        }

        // Data persistence
        function saveToLocalStorage() {
            try {
                localStorage.setItem('badOrdersRMR', JSON.stringify(rmrs));
            } catch (error) {
                console.error('Failed to save to localStorage:', error);
            }
        }

        function loadFromLocalStorage() {
            try {
                const savedRMR = localStorage.getItem('badOrdersRMR');
                if (savedRMR) {
                    const parsed = JSON.parse(savedRMR);
                    if (Array.isArray(parsed) && parsed.length > 0) {
                        rmrs = parsed;
                    }
                }
            } catch (error) {
                console.error('Failed to load from localStorage:', error);
            }
        }

        // Demo info card styling
        const style = document.createElement('style');
        style.textContent = `
            .demo-info-card {
                background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));
                border: 1px solid rgba(245, 158, 11, 0.2);
                border-radius: 12px;
                padding: 1.25rem;
                margin-bottom: 1.5rem;
                display: flex;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .demo-info-icon {
                background: rgba(245, 158, 11, 0.2);
                border-radius: 8px;
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            
            .demo-info-icon i {
                color: #d97706;
                font-size: 1.25rem;
            }
            
            .demo-info-content h5 {
                color: #d97706;
                margin-bottom: 0.25rem;
                font-size: 1rem;
            }
            
            .demo-info-content p {
                color: #6b7280;
                font-size: 0.875rem;
                margin-bottom: 0;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>