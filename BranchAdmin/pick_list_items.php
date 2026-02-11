<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/pick_list_items.css">
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
                        <a class="nav-link active" href="pick_list_items.php">
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
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
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
            <!-- PICK LIST ITEMS CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <!-- MOBILE MENU BUTTON -->
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Pick List Items</h2>
                        <p>Manage pick list items and send to warehouse for fulfillment</p>
                    </div>
                    </div>
                </div>

                <!-- Stats Section -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-boxes stat-icon"></i>
                            <div class="stat-value" id="totalItems">3</div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value" id="warehouseReady">1</div>
                            <div class="stat-label">Warehouse Ready</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value" id="inTransit">1</div>
                            <div class="stat-label">In Transit</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="delivered">1</div>
                            <div class="stat-label">Delivered</div>
                        </div>
                    </div>
                </div>

                <!-- Batch Actions -->
                <div class="batch-actions" id="batchActions">
                    <div class="batch-actions-content">
                        <div>
                            <span class="selected-count" id="selectedCount">0</span> items selected
                        </div>
                        <div class="batch-buttons">
                            <button class="btn btn-sm btn-outline-primary" onclick="sendBatchToWarehouse()">
                                <i class="bi bi-arrow-right-circle me-1"></i> Send to Warehouse
                            </button>
                            <button class="btn btn-sm btn-outline-primary" onclick="generateBatchInvoice()">
                                <i class="bi bi-file-text me-1"></i> Generate Invoices
                            </button>
                            <button class="btn btn-sm btn-outline-primary" onclick="createBatchTripTicket()">
                                <i class="bi bi-truck me-1"></i> Create Trip Tickets
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
                                <input class="form-check-input" type="checkbox" id="selectAllItems">
                                <label class="form-check-label" for="selectAllItems">
                                    Select All
                                </label>
                            </div>
                            <div class="sort-controls">
                                <button class="sort-btn active" onclick="sortItems('date')">By Date</button>
                                <button class="sort-btn" onclick="sortItems('so')">By SO ID</button>
                                <button class="sort-btn" onclick="sortItems('warehouse')">By Status</button>
                                <button class="sort-btn" onclick="sortItems('quantity')">By Quantity</button>
                            </div>
                        </div>
                        
                        <div class="action-right">
                            <button class="btn btn-outline-primary" onclick="printPickList()">
                                <i class="bi bi-printer me-1"></i> Print
                            </button>
                            <button class="btn btn-outline-primary" onclick="exportToCSV()">
                                <i class="bi bi-download me-1"></i> Export
                            </button>
                            <button class="btn btn-primary" onclick="showAddItemModal()">
                                <i class="bi bi-plus-circle me-1"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pick List Items Container -->
                <div id="pickListItemsContainer">
                    <!-- Items will be loaded here by JavaScript -->
                </div>

                <!-- Add Item Card -->
                <div class="new-item-card" onclick="showAddItemModal()">
                    <div class="add-icon">
                        <i class="bi bi-plus-lg"></i>
                    </div>
                    <h5>Add New Pick List Item</h5>
                    <p>Click to add a new item to the pick list</p>
                </div>

                <!-- Empty State -->
                <div class="empty-state" id="emptyState">
                    <div class="empty-state-icon">
                        <i class="bi bi-clipboard"></i>
                    </div>
                    <h4>No Pick List Items</h4>
                    <p class="text-muted mb-4">Add your first pick list item to get started</p>
                    <button class="btn btn-primary" onclick="showAddItemModal()">
                        <i class="bi bi-plus-circle me-1"></i> Add First Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Item Modal -->
    <div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Pick List Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm">
                        <input type="hidden" id="itemId">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="soId" class="form-label">Sales Order ID *</label>
                                <input type="text" class="form-control" id="soId" required>
                            </div>
                            <div class="col-md-6">
                                <label for="itemCode" class="form-label">Item Code *</label>
                                <input type="text" class="form-control" id="itemCode" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="itemName" class="form-label">Item Name/Description *</label>
                                <input type="text" class="form-control" id="itemName" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="caseQty" class="form-label">Case Quantity</label>
                                <input type="number" class="form-control" id="caseQty" min="0" value="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="innerPackQty" class="form-label">Inner Pack Quantity</label>
                                <input type="number" class="form-control" id="innerPackQty" min="0" value="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="pieceQty" class="form-label">Piece Quantity</label>
                                <input type="number" class="form-control" id="pieceQty" min="0" value="0" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="encodedBy" class="form-label">Encoded By *</label>
                                <input type="text" class="form-control" id="encodedBy" value="marinellemacalir" required>
                            </div>
                            <div class="col-md-6">
                                <label for="encodedAt" class="form-label">Encoded At *</label>
                                <input type="datetime-local" class="form-control" id="encodedAt" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="companyId" class="form-label">Company ID</label>
                                <input type="text" class="form-control" id="companyId" value="ebfa67e3">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveItem()">Save Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this item? This action cannot be undone.</p>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This will permanently remove the item from the pick list.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Item</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Send to Warehouse Modal -->
    <div class="modal fade" id="warehouseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Send to Warehouse</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to send selected items to the warehouse?</p>
                    <div class="mb-3">
                        <label class="form-label">Warehouse Location *</label>
                        <select class="form-select" id="warehouseLocation" required>
                            <option value="main">Main Warehouse - Quezon City</option>
                            <option value="north">North Warehouse - Novaliches</option>
                            <option value="south">South Warehouse - Alabang</option>
                            <option value="east">East Warehouse - Marikina</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Priority *</label>
                        <select class="form-select" id="warehousePriority" required>
                            <option value="normal">Normal</option>
                            <option value="high">High Priority</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        Warehouse staff will be notified and items will be prepared for picking.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmSendToWarehouse()">Send to Warehouse</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Invoice Modal -->
    <div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Generate Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="invoice-preview" id="invoicePreview">
                        <!-- Invoice content will be generated here -->
                    </div>
                    <div class="truck-selection" id="truckSelection">
                        <h6 class="mb-3">Select Delivery Truck:</h6>
                        <div id="availableTrucks">
                            <!-- Truck options will be loaded here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-outline-primary" onclick="printInvoice()">
                        <i class="bi bi-printer me-1"></i> Print Invoice
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="downloadInvoicePDF()">
                        <i class="bi bi-download me-1"></i> Download PDF
                    </button>
                    <button type="button" class="btn btn-primary" onclick="confirmGenerateInvoice()">
                        Generate Invoice & Trip Ticket
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
   <script>
    // Enhanced sample data with warehouse status
    let pickListItems = [
        {
            id: 1,
            soId: 'a72f2a82',
            itemCode: 'SC',
            itemName: 'SWAKTO COKE 190MLX12',
            caseQty: 3,
            innerPackQty: 0,
            pieceQty: 0,
            encodedBy: 'marinellemacalir',
            encodedAt: '2026-01-29T17:21',
            companyId: 'ebfa67e3',
            status: 'pending',
            warehouseStatus: 'pending',
            isSelected: false,
            pricePerCase: 1500,
            invoiceNumber: '',
            tripTicketNumber: '',
            assignedTruck: '',
            totalAmount: 4500
        },
        {
            id: 2,
            soId: 'ca45cfde',
            itemCode: 'COBRA YLW',
            itemName: 'COBRA YELLOW 290MLX12',
            caseQty: 100,
            innerPackQty: 0,
            pieceQty: 0,
            encodedBy: 'marinellemacalir',
            encodedAt: '2026-01-26T08:47',
            companyId: 'ebfa67e3',
            status: 'in-progress',
            warehouseStatus: 'ready',
            isSelected: false,
            pricePerCase: 2800,
            invoiceNumber: 'INV-2024-002',
            tripTicketNumber: '',
            assignedTruck: '',
            totalAmount: 280000
        },
        {
            id: 3,
            soId: 'ca45cfde',
            itemCode: 'FRASCO',
            itemName: 'GINEBRA FRASCO 700MLX12',
            caseQty: 5,
            innerPackQty: 0,
            pieceQty: 0,
            encodedBy: 'marinellemacalir',
            encodedAt: '2026-01-26T08:47',
            companyId: 'ebfa67e3',
            status: 'completed',
            warehouseStatus: 'shipped',
            isSelected: false,
            pricePerCase: 4200,
            invoiceNumber: 'INV-2024-001',
            tripTicketNumber: 'TT-2024-001',
            assignedTruck: 'TRUCK-001',
            totalAmount: 21000
        }
    ];

    let itemToDelete = null;
    let currentSort = 'date';
    let selectedItems = [];
    let selectedTruck = null;

    // Available trucks for delivery
    const availableTrucks = [
        { id: 1, number: 'TRUCK-001', driver: 'Juan Dela Cruz', status: 'available', capacity: '1000 cases' },
        { id: 2, number: 'TRUCK-002', driver: 'Pedro Santos', status: 'available', capacity: '800 cases' },
        { id: 3, number: 'TRUCK-003', driver: 'Miguel Reyes', status: 'busy', capacity: '1200 cases' },
        { id: 4, number: 'TRUCK-004', driver: 'Carlos Gomez', status: 'available', capacity: '1500 cases' }
    ];

    // Toggle sidebar collapse/expand on desktop
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const isMobile = window.innerWidth <= 992;
        
        if (isMobile) {
            // On mobile, use the existing hamburger functionality
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
                overlay.remove();
            }, 300);
        }
    }

    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Pick List Items page loaded!");
        
        // Setup mobile menu toggle - UPDATED VERSION
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
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
                }
            } else {
                // On desktop, toggle sidebar collapse
                toggleSidebar();
            }
        });
        
        // Add event listener for desktop toggle button
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation(); // Prevent event bubbling
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

        // Search functionality with debounce
        let searchTimeout;
        const searchInput = document.getElementById('searchItems');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    filterItems(e.target.value);
                }, 300);
            });
        }

        // Add select all functionality
        const selectAllCheckbox = document.getElementById('selectAllItems');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function(e) {
                const isChecked = e.target.checked;
                pickListItems.forEach(item => {
                    item.isSelected = isChecked;
                });
                updateSelection();
                renderItems();
            });
        }

        // Initialize with current date/time
        const encodedAtInput = document.getElementById('encodedAt');
        if (encodedAtInput) {
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            encodedAtInput.value = formattedDateTime;
        }

        // Load from localStorage if available
        loadFromLocalStorage();
        
        // Initialize UI
        updateStats();
        renderItems();
        updateProgressSteps();

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + N for new item
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                showAddItemModal();
            }
            // Ctrl + F for focus search
            else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchItems');
                if (searchInput) searchInput.focus();
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
                const selectAll = document.getElementById('selectAllItems');
                if (selectAll) {
                    selectAll.checked = !selectAll.checked;
                    selectAll.dispatchEvent(new Event('change'));
                }
            }
            // Ctrl + R for reset
            else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                logout();
            }
            // Ctrl + B to toggle sidebar (desktop only)
            else if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
        });

        // Load sidebar preference from localStorage
        const savedCollapsed = localStorage.getItem('sidebarCollapsed');
        if (savedCollapsed === 'true' && window.innerWidth > 992) {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.add('collapsed');
            // Hide all nav-text when collapsed
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'none';
            });
        } else {
            // Show all nav-text by default when expanded
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'inline-block';
            });
        }
    });

    // Handle window resize
    window.addEventListener('resize', function() {
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
                // Hide all nav-text when collapsed
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'none';
                });
            } else {
                sidebar.classList.remove('collapsed');
                // Show all nav-text when expanded
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
            }
        } else {
            // Mobile mode - always show expanded
            sidebar.classList.remove('collapsed');
            // Show all nav-text on mobile
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'inline-block';
            });
        }
    });

    // Enhanced renderItems function with improved layout
    function renderItems(items = pickListItems) {
        const container = document.getElementById('pickListItemsContainer');
        const emptyState = document.getElementById('emptyState');
        
        if (!container || !emptyState) return;
        
        if (items.length === 0) {
            container.style.display = 'none';
            emptyState.style.display = 'block';
            return;
        }
        
        container.style.display = 'block';
        emptyState.style.display = 'none';
        container.innerHTML = '';
        
        items.forEach(item => {
            const statusClass = getStatusClass(item.status);
            const statusText = getStatusText(item.status);
            const warehouseClass = getWarehouseClass(item.warehouseStatus);
            const warehouseText = getWarehouseText(item.warehouseStatus);
            
            const card = document.createElement('div');
            card.className = 'pick-list-card';
            card.innerHTML = `
                <div class="card-header-row">
                    <div class="card-left-header">
                        <input type="checkbox" class="form-check-input item-checkbox" 
                               ${item.isSelected ? 'checked' : ''} 
                               onchange="toggleItemSelection(${item.id}, this.checked)">
                        <div class="so-id">SO: ${item.soId}</div>
                        <span class="warehouse-status ${warehouseClass}">${warehouseText}</span>
                    </div>
                    
                    <div class="warehouse-actions">
                        ${getWarehouseActions(item)}
                    </div>
                </div>
                
                <div class="item-details">
                    <div class="item-name">
                        ${item.itemName}
                        <span class="item-code">(${item.itemCode})</span>
                    </div>
                    
                    <div class="quantities">
                        <div class="quantity-badge">
                            <span class="quantity-label">Cases</span>
                            <span class="quantity-value">${item.caseQty}</span>
                        </div>
                        <div class="quantity-badge">
                            <span class="quantity-label">Inner Packs</span>
                            <span class="quantity-value">${item.innerPackQty}</span>
                        </div>
                        <div class="quantity-badge">
                            <span class="quantity-label">Pieces</span>
                            <span class="quantity-value">${item.pieceQty}</span>
                        </div>
                    </div>
                    
                    ${getAdditionalInfo(item)}
                    
                    <div class="item-meta-row">
                        <div class="item-meta">
                            <span><i class="bi bi-person"></i> ${item.encodedBy}</span>
                            <span><i class="bi bi-calendar"></i> ${formatDate(item.encodedAt)}</span>
                        </div>
                        <span class="status-badge ${statusClass}">${statusText}</span>
                    </div>
                </div>
            `;
            
            container.appendChild(card);
        });
    }

    // Get warehouse action buttons based on status
    function getWarehouseActions(item) {
        let buttons = '';
        
        if (item.warehouseStatus === 'pending') {
            buttons = `
                <button class="warehouse-btn btn-send-warehouse" onclick="sendToWarehouse(${item.id})">
                    <i class="bi bi-arrow-right-circle"></i> Send to WH
                </button>
            `;
        } else if (item.warehouseStatus === 'ready') {
            buttons = `
                <button class="warehouse-btn btn-generate-invoice" onclick="generateInvoice(${item.id})">
                    <i class="bi bi-file-text"></i> Generate Invoice
                </button>
            `;
        } else if (item.warehouseStatus === 'shipped') {
            buttons = `
                <button class="warehouse-btn btn-generate-ticket" onclick="generateTripTicket(${item.id})">
                    <i class="bi bi-truck"></i> Create Trip Ticket
                </button>
            `;
        } else if (item.warehouseStatus === 'delivered') {
            buttons = `
                <button class="warehouse-btn btn-disabled">
                    <i class="bi bi-check-circle"></i> Delivered
                </button>
            `;
        }
        
        return buttons;
    }

    // Get additional information for items
    function getAdditionalInfo(item) {
        let info = '';
        
        if (item.invoiceNumber || item.tripTicketNumber || item.assignedTruck) {
            info = '<div class="additional-info">';
            
            if (item.invoiceNumber) {
                info += `<div><i class="bi bi-file-text"></i> Invoice: ${item.invoiceNumber}</div>`;
            }
            
            if (item.tripTicketNumber) {
                info += `<div><i class="bi bi-ticket-perforated"></i> Trip Ticket: ${item.tripTicketNumber}</div>`;
            }
            
            if (item.assignedTruck) {
                info += `<div><i class="bi bi-truck"></i> Truck: ${item.assignedTruck}</div>`;
            }
            
            info += '</div>';
        }
        
        return info;
    }

    // Send single item to warehouse
    function sendToWarehouse(id) {
        const item = pickListItems.find(item => item.id === id);
        if (!item) return;
        
        item.warehouseStatus = 'ready';
        item.status = 'in-progress';
        saveToLocalStorage();
        updateStats();
        renderItems();
        updateProgressSteps();
        showNotification('Item sent to warehouse successfully', 'success');
    }

    // Send batch to warehouse
    function sendBatchToWarehouse() {
        const selected = pickListItems.filter(item => item.isSelected && item.warehouseStatus === 'pending');
        if (selected.length === 0) {
            showNotification('Please select items that are pending to send to warehouse', 'warning');
            return;
        }
        
        const modal = new bootstrap.Modal(document.getElementById('warehouseModal'));
        modal.show();
    }

    // Confirm send to warehouse
    function confirmSendToWarehouse() {
        const location = document.getElementById('warehouseLocation').value;
        const priority = document.getElementById('warehousePriority').value;
        
        pickListItems.forEach(item => {
            if (item.isSelected && item.warehouseStatus === 'pending') {
                item.warehouseStatus = 'ready';
                item.status = 'in-progress';
            }
        });
        
        saveToLocalStorage();
        updateStats();
        renderItems();
        updateProgressSteps();
        clearSelection();
        
        bootstrap.Modal.getInstance(document.getElementById('warehouseModal')).hide();
        showNotification(`${selectedItems.length} items sent to ${location} warehouse (${priority} priority)`, 'success');
    }

    // Generate invoice for single item
    function generateInvoice(id) {
        const item = pickListItems.find(item => item.id === id);
        if (!item) return;
        
        // Generate invoice number
        const invoiceNumber = `INV-${new Date().getFullYear()}-${String(Math.floor(Math.random() * 1000)).padStart(3, '0')}`;
        item.invoiceNumber = invoiceNumber;
        item.totalAmount = item.caseQty * item.pricePerCase;
        
        // Show invoice modal with preview
        showInvoiceModal([item]);
    }

    // Generate batch invoice
    function generateBatchInvoice() {
        const selected = pickListItems.filter(item => item.isSelected && item.warehouseStatus === 'ready');
        if (selected.length === 0) {
            showNotification('Please select items that are ready for invoicing', 'warning');
            return;
        }
        
        // Generate invoice numbers and calculate totals
        selected.forEach((item, index) => {
            item.invoiceNumber = `INV-${new Date().getFullYear()}-${String(Math.floor(Math.random() * 1000) + index).padStart(3, '0')}`;
            item.totalAmount = item.caseQty * item.pricePerCase;
        });
        
        showInvoiceModal(selected);
    }

    // Show invoice modal
    function showInvoiceModal(items) {
        // Load available trucks
        loadAvailableTrucks();
        
        // Generate invoice preview
        generateInvoicePreview(items);
        
        const modal = new bootstrap.Modal(document.getElementById('invoiceModal'));
        modal.show();
    }

    // Load available trucks
    function loadAvailableTrucks() {
        const container = document.getElementById('availableTrucks');
        if (!container) return;
        
        container.innerHTML = '';
        
        availableTrucks.forEach(truck => {
            const truckItem = document.createElement('div');
            truckItem.className = `truck-item ${truck.status === 'busy' ? 'disabled' : ''}`;
            truckItem.innerHTML = `
                <div class="form-check me-3">
                    <input class="form-check-input" type="radio" name="truckSelect" 
                           id="truck${truck.id}" value="${truck.id}" 
                           ${truck.status === 'busy' ? 'disabled' : ''}
                           onchange="selectTruck(${truck.id})">
                </div>
                <div class="truck-info">
                    <div class="truck-number">${truck.number}</div>
                    <div class="truck-driver">Driver: ${truck.driver} • Capacity: ${truck.capacity}</div>
                </div>
                <div class="truck-status ${truck.status === 'available' ? 'truck-available' : 'truck-busy'}">
                    ${truck.status === 'available' ? 'Available' : 'On Delivery'}
                </div>
            `;
            container.appendChild(truckItem);
        });
    }

    // Select truck
    function selectTruck(truckId) {
        selectedTruck = availableTrucks.find(truck => truck.id === truckId);
    }

    // Generate invoice preview
    function generateInvoicePreview(items) {
        const container = document.getElementById('invoicePreview');
        if (!container) return;
        
        container.style.display = 'block';
        
        let totalAmount = 0;
        let itemsHtml = '';
        
        items.forEach(item => {
            const amount = item.caseQty * item.pricePerCase;
            totalAmount += amount;
            
            itemsHtml += `
                <tr>
                    <td>${item.itemCode}</td>
                    <td>${item.itemName}</td>
                    <td>${item.caseQty}</td>
                    <td>₱${item.pricePerCase.toLocaleString()}</td>
                    <td>₱${amount.toLocaleString()}</td>
                </tr>
            `;
        });
        
        container.innerHTML = `
            <div class="invoice-header">
                <div>
                    <div class="invoice-title">INVOICE</div>
                    <div class="invoice-number">Invoice #: ${items[0].invoiceNumber}</div>
                </div>
                <div>
                    <div><strong>Date:</strong> ${new Date().toLocaleDateString()}</div>
                    <div><strong>Sales Order:</strong> ${items[0].soId}</div>
                </div>
            </div>
            
            <div class="invoice-details">
                <div>
                    <h6>Bill To:</h6>
                    <p>Customer Name<br>
                    Customer Address<br>
                    City, Country</p>
                </div>
                <div>
                    <h6>Ship To:</h6>
                    <p>Delivery Address<br>
                    City, Country</p>
                </div>
            </div>
            
            <table class="invoice-items">
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th>Description</th>
                        <th>Quantity (CS)</th>
                        <th>Unit Price</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
            
            <div class="invoice-total">
                Total Amount: ₱${totalAmount.toLocaleString()}
            </div>
        `;
    }

    // Print invoice
    function printInvoice() {
        const invoiceContent = document.getElementById('invoicePreview').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Invoice Print</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .invoice-preview { margin: 0; padding: 0; }
                </style>
            </head>
            <body>
                ${invoiceContent}
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    // Download invoice as PDF (simulated)
    function downloadInvoicePDF() {
        showNotification('PDF download feature would require additional libraries', 'info');
    }

    // Confirm generate invoice
    function confirmGenerateInvoice() {
        if (!selectedTruck) {
            showNotification('Please select a delivery truck', 'warning');
            return;
        }
        
        const selected = pickListItems.filter(item => item.isSelected);
        selected.forEach(item => {
            item.warehouseStatus = 'shipped';
            item.assignedTruck = selectedTruck.number;
            
            // Generate trip ticket number
            item.tripTicketNumber = `TT-${new Date().getFullYear()}-${String(Math.floor(Math.random() * 1000)).padStart(3, '0')}`;
        });
        
        saveToLocalStorage();
        updateStats();
        renderItems();
        updateProgressSteps();
        clearSelection();
        
        bootstrap.Modal.getInstance(document.getElementById('invoiceModal')).hide();
        showNotification(`Invoice generated and trip ticket created for ${selectedTruck.number}`, 'success');
    }

    // Create trip ticket for single item
    function generateTripTicket(id) {
        const item = pickListItems.find(item => item.id === id);
        if (!item) return;
        
        item.warehouseStatus = 'delivered';
        item.status = 'completed';
        
        saveToLocalStorage();
        updateStats();
        renderItems();
        updateProgressSteps();
        showNotification('Trip ticket created and delivery marked as complete', 'success');
    }

    // Create batch trip tickets
    function createBatchTripTicket() {
        const selected = pickListItems.filter(item => item.isSelected && item.warehouseStatus === 'shipped');
        
        if (selected.length === 0) {
            showNotification('Please select items that have been shipped', 'warning');
            return;
        }
        
        selected.forEach(item => {
            item.warehouseStatus = 'delivered';
            item.status = 'completed';
        });
        
        saveToLocalStorage();
        updateStats();
        renderItems();
        updateProgressSteps();
        clearSelection();
        showNotification(`${selected.length} trip tickets created and marked as delivered`, 'success');
    }

    // Toggle item selection
    function toggleItemSelection(id, isSelected) {
        const item = pickListItems.find(item => item.id === id);
        if (item) {
            item.isSelected = isSelected;
            updateSelection();
        }
    }

    // Update selection state
    function updateSelection() {
        selectedItems = pickListItems.filter(item => item.isSelected);
        const selectedCount = selectedItems.length;
        
        const selectedCountElement = document.getElementById('selectedCount');
        if (selectedCountElement) {
            selectedCountElement.textContent = selectedCount;
        }
        
        const batchActions = document.getElementById('batchActions');
        const selectAll = document.getElementById('selectAllItems');
        
        if (batchActions) {
            if (selectedCount > 0) {
                batchActions.style.display = 'block';
                if (selectAll) {
                    selectAll.checked = selectedCount === pickListItems.length;
                    selectAll.indeterminate = selectedCount > 0 && selectedCount < pickListItems.length;
                }
            } else {
                batchActions.style.display = 'none';
                if (selectAll) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
            }
        }
    }

    // Clear selection
    function clearSelection() {
        pickListItems.forEach(item => {
            item.isSelected = false;
        });
        updateSelection();
        renderItems();
    }

    // Update enhanced stats
    function updateStats() {
        const totalItems = pickListItems.length;
        const warehouseReady = pickListItems.filter(item => item.warehouseStatus === 'ready').length;
        const inTransit = pickListItems.filter(item => item.warehouseStatus === 'shipped').length;
        const delivered = pickListItems.filter(item => item.warehouseStatus === 'delivered').length;
        
        const totalItemsElement = document.getElementById('totalItems');
        const warehouseReadyElement = document.getElementById('warehouseReady');
        const inTransitElement = document.getElementById('inTransit');
        const deliveredElement = document.getElementById('delivered');
        
        if (totalItemsElement) totalItemsElement.textContent = totalItems;
        if (warehouseReadyElement) warehouseReadyElement.textContent = warehouseReady;
        if (inTransitElement) inTransitElement.textContent = inTransit;
        if (deliveredElement) deliveredElement.textContent = delivered;
    }

    // Update progress steps
    function updateProgressSteps() {
        const hasReady = pickListItems.some(item => item.warehouseStatus === 'ready');
        const hasShipped = pickListItems.some(item => item.warehouseStatus === 'shipped');
        const hasDelivered = pickListItems.some(item => item.warehouseStatus === 'delivered');
        
        const step3 = document.getElementById('step3');
        const step4 = document.getElementById('step4');
        const step5 = document.getElementById('step5');
        
        if (step3) step3.classList.toggle('active', hasReady);
        if (step4) {
            step4.classList.toggle('active', hasShipped);
            step4.classList.toggle('completed', hasDelivered);
        }
        if (step5) {
            step5.classList.toggle('active', hasDelivered);
            step5.classList.toggle('completed', hasDelivered);
        }
    }

    // Sort items
    function sortItems(criteria) {
        currentSort = criteria;
        
        // Update active button
        document.querySelectorAll('.sort-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
        
        let sortedItems = [...pickListItems];
        
        switch(criteria) {
            case 'date':
                sortedItems.sort((a, b) => new Date(b.encodedAt) - new Date(a.encodedAt));
                break;
            case 'so':
                sortedItems.sort((a, b) => a.soId.localeCompare(b.soId));
                break;
            case 'warehouse':
                sortedItems.sort((a, b) => {
                    const statusOrder = { 'pending': 0, 'ready': 1, 'shipped': 2, 'delivered': 3 };
                    return statusOrder[a.warehouseStatus] - statusOrder[b.warehouseStatus];
                });
                break;
            case 'quantity':
                sortedItems.sort((a, b) => b.caseQty - a.caseQty);
                break;
        }
        
        renderItems(sortedItems);
    }

    // Filter items based on search
    function filterItems(searchTerm) {
        if (!searchTerm) {
            renderItems();
            updateProgressSteps();
            return;
        }
        
        const filtered = pickListItems.filter(item => 
            item.itemName.toLowerCase().includes(searchTerm.toLowerCase()) ||
            item.soId.toLowerCase().includes(searchTerm.toLowerCase()) ||
            item.itemCode.toLowerCase().includes(searchTerm.toLowerCase()) ||
            item.encodedBy.toLowerCase().includes(searchTerm.toLowerCase()) ||
            (item.invoiceNumber && item.invoiceNumber.toLowerCase().includes(searchTerm.toLowerCase())) ||
            (item.tripTicketNumber && item.tripTicketNumber.toLowerCase().includes(searchTerm.toLowerCase()))
        );
        
        renderItems(filtered);
        updateProgressSteps();
    }

    // Warehouse status helper functions
    function getWarehouseClass(status) {
        switch(status) {
            case 'pending': return 'warehouse-pending';
            case 'ready': return 'warehouse-ready';
            case 'shipped': return 'warehouse-shipped';
            case 'delivered': return 'warehouse-delivered';
            default: return 'warehouse-pending';
        }
    }

    function getWarehouseText(status) {
        switch(status) {
            case 'pending': return 'Pending';
            case 'ready': return 'Warehouse Ready';
            case 'shipped': return 'In Transit';
            case 'delivered': return 'Delivered';
            default: return 'Pending';
        }
    }

    // Get status class for CSS
    function getStatusClass(status) {
        switch(status) {
            case 'pending': return 'status-pending';
            case 'in-progress': return 'status-in-progress';
            case 'completed': return 'status-completed';
            default: return 'status-pending';
        }
    }

    // Get status text for display
    function getStatusText(status) {
        switch(status) {
            case 'pending': return 'Pending';
            case 'in-progress': return 'In Progress';
            case 'completed': return 'Completed';
            default: return 'Pending';
        }
    }

    // Format date for display
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

    // Show add item modal
    function showAddItemModal() {
        const modalTitle = document.getElementById('modalTitle');
        const encodedAtInput = document.getElementById('encodedAt');
        const encodedByInput = document.getElementById('encodedBy');
        const companyIdInput = document.getElementById('companyId');
        
        if (modalTitle) modalTitle.textContent = 'Add Pick List Item';
        
        const itemForm = document.getElementById('itemForm');
        if (itemForm) itemForm.reset();
        
        const itemIdInput = document.getElementById('itemId');
        if (itemIdInput) itemIdInput.value = '';
        
        // Set current date/time
        if (encodedAtInput) {
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            encodedAtInput.value = formattedDateTime;
        }
        
        if (encodedByInput) encodedByInput.value = 'marinellemacalir';
        if (companyIdInput) companyIdInput.value = 'ebfa67e3';
        
        const modal = new bootstrap.Modal(document.getElementById('itemModal'));
        modal.show();
    }

    // Edit item
    function editItem(id) {
        const item = pickListItems.find(item => item.id === id);
        if (!item) return;
        
        const modalTitle = document.getElementById('modalTitle');
        const itemIdInput = document.getElementById('itemId');
        const soIdInput = document.getElementById('soId');
        const itemCodeInput = document.getElementById('itemCode');
        const itemNameInput = document.getElementById('itemName');
        const caseQtyInput = document.getElementById('caseQty');
        const innerPackQtyInput = document.getElementById('innerPackQty');
        const pieceQtyInput = document.getElementById('pieceQty');
        const encodedByInput = document.getElementById('encodedBy');
        const encodedAtInput = document.getElementById('encodedAt');
        const companyIdInput = document.getElementById('companyId');
        
        if (modalTitle) modalTitle.textContent = 'Edit Pick List Item';
        if (itemIdInput) itemIdInput.value = item.id;
        if (soIdInput) soIdInput.value = item.soId;
        if (itemCodeInput) itemCodeInput.value = item.itemCode;
        if (itemNameInput) itemNameInput.value = item.itemName;
        if (caseQtyInput) caseQtyInput.value = item.caseQty;
        if (innerPackQtyInput) innerPackQtyInput.value = item.innerPackQty;
        if (pieceQtyInput) pieceQtyInput.value = item.pieceQty;
        if (encodedByInput) encodedByInput.value = item.encodedBy;
        if (encodedAtInput) encodedAtInput.value = item.encodedAt;
        if (companyIdInput) companyIdInput.value = item.companyId;
        
        const modal = new bootstrap.Modal(document.getElementById('itemModal'));
        modal.show();
    }

    // Delete item confirmation
    function deleteItem(id) {
        itemToDelete = id;
        const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }

    // Confirm delete
    function confirmDelete() {
        if (itemToDelete) {
            pickListItems = pickListItems.filter(item => item.id !== itemToDelete);
            saveToLocalStorage();
            showNotification('Item deleted successfully', 'success');
            
            // Update UI
            updateStats();
            renderItems();
            updateProgressSteps();
            
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
            itemToDelete = null;
        }
    }

    // Save item (add or update)
    function saveItem() {
        const itemIdInput = document.getElementById('itemId');
        const soIdInput = document.getElementById('soId');
        const itemCodeInput = document.getElementById('itemCode');
        const itemNameInput = document.getElementById('itemName');
        const caseQtyInput = document.getElementById('caseQty');
        const innerPackQtyInput = document.getElementById('innerPackQty');
        const pieceQtyInput = document.getElementById('pieceQty');
        const encodedByInput = document.getElementById('encodedBy');
        const encodedAtInput = document.getElementById('encodedAt');
        const companyIdInput = document.getElementById('companyId');
        
        if (!soIdInput || !itemCodeInput || !itemNameInput || !encodedByInput || !encodedAtInput || !companyIdInput) return;
        
        const id = itemIdInput.value;
        const isEdit = !!id;
        
        const item = {
            soId: soIdInput.value.trim(),
            itemCode: itemCodeInput.value.trim(),
            itemName: itemNameInput.value.trim(),
            caseQty: parseInt(caseQtyInput.value) || 0,
            innerPackQty: parseInt(innerPackQtyInput.value) || 0,
            pieceQty: parseInt(pieceQtyInput.value) || 0,
            encodedBy: encodedByInput.value.trim(),
            encodedAt: encodedAtInput.value,
            companyId: companyIdInput.value.trim(),
            status: 'pending',
            warehouseStatus: 'pending',
            isSelected: false,
            pricePerCase: Math.floor(Math.random() * 5000) + 1000,
            invoiceNumber: '',
            tripTicketNumber: '',
            assignedTruck: '',
            totalAmount: 0
        };
        
        // Validation
        const errors = validateItem(item);
        if (errors.length > 0) {
            showNotification(errors.join(', '), 'warning');
            return;
        }
        
        // Calculate total amount
        item.totalAmount = item.caseQty * item.pricePerCase;
        
        if (isEdit) {
            // Update existing item
            const index = pickListItems.findIndex(i => i.id === parseInt(id));
            if (index !== -1) {
                // Preserve existing data
                const existingItem = pickListItems[index];
                pickListItems[index] = { 
                    ...existingItem, 
                    ...item,
                    // Don't overwrite these fields
                    warehouseStatus: existingItem.warehouseStatus,
                    invoiceNumber: existingItem.invoiceNumber,
                    tripTicketNumber: existingItem.tripTicketNumber,
                    assignedTruck: existingItem.assignedTruck,
                    pricePerCase: existingItem.pricePerCase,
                    totalAmount: existingItem.totalAmount,
                    isSelected: existingItem.isSelected
                };
                showNotification('Item updated successfully', 'success');
            }
        } else {
            // Add new item
            const newId = pickListItems.length > 0 ? Math.max(...pickListItems.map(i => i.id)) + 1 : 1;
            item.id = newId;
            pickListItems.push(item);
            showNotification('Item added successfully', 'success');
        }
        
        saveToLocalStorage();
        
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('itemModal'));
        if (modal) modal.hide();
        
        // Update UI
        updateStats();
        renderItems();
        updateProgressSteps();
    }

    // Item validation
    function validateItem(item) {
        const errors = [];
        
        if (!item.soId || item.soId.trim() === '') {
            errors.push('Sales Order ID is required');
        }
        
        if (!item.itemCode || item.itemCode.trim() === '') {
            errors.push('Item Code is required');
        }
        
        if (!item.itemName || item.itemName.trim() === '') {
            errors.push('Item Name is required');
        }
        
        if (item.caseQty < 0 || item.innerPackQty < 0 || item.pieceQty < 0) {
            errors.push('Quantities cannot be negative');
        }
        
        if (item.caseQty === 0 && item.innerPackQty === 0 && item.pieceQty === 0) {
            errors.push('At least one quantity must be greater than 0');
        }
        
        if (!item.encodedBy || item.encodedBy.trim() === '') {
            errors.push('Encoder name is required');
        }
        
        if (!item.encodedAt) {
            errors.push('Encoding date/time is required');
        }
        
        return errors;
    }

    // Print pick list
    function printPickList() {
        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Pick List - ${new Date().toLocaleDateString()}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h1 { color: #333; margin-bottom: 10px; }
                    .print-date { color: #666; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                    th { background-color: #f5f5f5; font-weight: bold; }
                    .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
                    .pending { background-color: #fff3cd; color: #856404; }
                    .ready { background-color: #cce5ff; color: #004085; }
                    .shipped { background-color: #d4edda; color: #155724; }
                    .delivered { background-color: #d1ecf1; color: #0c5460; }
                    .summary { margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; }
                    .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <h1>Pick List Report</h1>
                <div class="print-date">Generated: ${new Date().toLocaleString()}</div>
                <table>
                    <thead>
                        <tr>
                            <th>SO ID</th>
                            <th>Item Code</th>
                            <th>Item Description</th>
                            <th>Cases</th>
                            <th>Inner Packs</th>
                            <th>Pieces</th>
                            <th>Warehouse Status</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${pickListItems.map(item => `
                            <tr>
                                <td>${item.soId}</td>
                                <td>${item.itemCode}</td>
                                <td>${item.itemName}</td>
                                <td>${item.caseQty}</td>
                                <td>${item.innerPackQty}</td>
                                <td>${item.pieceQty}</td>
                                <td><span class="status ${item.warehouseStatus}">${getWarehouseText(item.warehouseStatus)}</span></td>
                                <td>${getStatusText(item.status)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                <div class="summary">
                    <h3>Summary</h3>
                    <p><strong>Total Items:</strong> ${pickListItems.length}</p>
                    <p><strong>Total Cases:</strong> ${pickListItems.reduce((sum, item) => sum + item.caseQty, 0)}</p>
                    <p><strong>Warehouse Ready:</strong> ${pickListItems.filter(item => item.warehouseStatus === 'ready').length}</p>
                    <p><strong>In Transit:</strong> ${pickListItems.filter(item => item.warehouseStatus === 'shipped').length}</p>
                    <p><strong>Delivered:</strong> ${pickListItems.filter(item => item.warehouseStatus === 'delivered').length}</p>
                </div>
                <div class="footer">
                    <p>Pick List Management System | Printed by: ${pickListItems[0]?.encodedBy || 'System'}</p>
                </div>
            </body>
            </html>
        `;
        
        const printWindow = window.open('', '_blank');
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.print();
    }

    // Export to CSV
    function exportToCSV() {
        try {
            const headers = ['SO_ID', 'Item_Code', 'Item_Name', 'Case_Qty', 'InnerPack_Qty', 'Piece_Qty', 
                            'Status', 'Warehouse_Status', 'Invoice_Number', 'Trip_Ticket', 'Assigned_Truck',
                            'Encoded_By', 'Encoded_At', 'Company_ID', 'Total_Amount'];
            
            const csvData = pickListItems.map(item => [
                item.soId,
                item.itemCode,
                `"${item.itemName.replace(/"/g, '""')}"`,
                item.caseQty,
                item.innerPackQty,
                item.pieceQty,
                getStatusText(item.status),
                getWarehouseText(item.warehouseStatus),
                item.invoiceNumber || 'N/A',
                item.tripTicketNumber || 'N/A',
                item.assignedTruck || 'N/A',
                item.encodedBy,
                new Date(item.encodedAt).toISOString(),
                item.companyId,
                item.totalAmount || '0'
            ]);
            
            const csvContent = [
                headers.join(','),
                ...csvData.map(row => row.join(','))
            ].join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            const fileName = `pick_list_export_${new Date().toISOString().slice(0, 10)}.csv`;
            link.setAttribute('download', fileName);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification(`Exported ${pickListItems.length} items to CSV`, 'success');
        } catch (error) {
            console.error('Export error:', error);
            showNotification('Failed to export data', 'warning');
        }
    }

    // Show notification to user
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

    // Logout Function
    function logout() {
        if (confirm('Reset demo to initial state?')) {
            localStorage.removeItem('sidebarCollapsed');
            localStorage.removeItem('pickListItems');
            location.reload();
        }
    }

    // Data persistence functions
    function saveToLocalStorage() {
        try {
            localStorage.setItem('pickListItems', JSON.stringify(pickListItems));
        } catch (error) {
            console.error('Failed to save to localStorage:', error);
        }
    }

    function loadFromLocalStorage() {
        try {
            const savedItems = localStorage.getItem('pickListItems');
            if (savedItems) {
                const parsed = JSON.parse(savedItems);
                if (Array.isArray(parsed) && parsed.length > 0) {
                    pickListItems = parsed;
                }
            }
        } catch (error) {
            console.error('Failed to load from localStorage:', error);
        }
    }
</script>
</body>
</html>