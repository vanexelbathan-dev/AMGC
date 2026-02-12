<?php
require_once '../config/database.php';

// FETCH PICK LISTS WITH ITEMS FROM DATABASE
$picklist_query = "
    SELECT 
        pl.pick_list_id,
        pl.pick_list_number,
        pl.pick_date,
        pl.pick_status,
        pl.picked_by,
        pl.verified_by,
        pl.created_at,
        pli.pick_item_id,
        pli.quantity_to_pick,
        pli.quantity_picked,
        pli.location_bin,
        i.item_id,
        i.item_code,
        i.item_name,
        i.unit_type,
        CONCAT(u.first_name, ' ', u.last_name) as encoded_by_name
    FROM pick_lists pl
    LEFT JOIN pick_list_items pli ON pl.pick_list_id = pli.pick_list_id
    LEFT JOIN items i ON pli.item_id = i.item_id
    LEFT JOIN users u ON pl.picked_by = u.user_id
    ORDER BY pl.created_at DESC, pl.pick_list_id DESC
";
$picklist_result = $conn->query($picklist_query);
$picklist_items = $picklist_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA
$total_items = count($picklist_items);
$warehouse_ready = count(array_filter($picklist_items, fn($item) => $item['pick_status'] === 'completed'));
$in_transit = count(array_filter($picklist_items, fn($item) => $item['pick_status'] === 'in-progress'));
$delivered = count(array_filter($picklist_items, fn($item) => $item['pick_status'] === 'completed'));

// STAT CARD VALUES
$statTotalItems = $total_items;
$statWarehouseReady = $warehouse_ready;
$statInTransit = $in_transit;
$statDelivered = $delivered;

// Helper function for status badge
function getPickStatusBadge($status) {
    return match($status) {
        'open' => 'bg-warning text-dark',
        'in-progress' => 'bg-primary text-white',
        'completed' => 'bg-success text-white',
        'cancelled' => 'bg-danger text-white',
        default => 'bg-secondary text-white'
    };
}

function getPickStatusText($status) {
    return match($status) {
        'open' => 'Pending',
        'in-progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function getWarehouseStatusText($status) {
    return match($status) {
        'open' => 'Pending',
        'in-progress' => 'Picking',
        'completed' => 'Ready',
        'cancelled' => 'Cancelled',
        default => 'Pending'
    };
}
?>
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
    <style>
        /* Table styles for pick list items */
        .pick-list-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .pick-list-table thead th {
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
        
        .pick-list-table tbody td {
            padding: 14px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .pick-list-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Column widths - CHECKBOX COLUMN REMOVED */
        .col-so { width: 12%; }
        .col-item-code { width: 10%; }
        .col-item-name { width: 20%; }
        .col-to-pick { width: 8%; text-align: center; }
        .col-picked { width: 8%; text-align: center; }
        .col-location { width: 10%; }
        .col-status { width: 10%; }
        .col-encoded { width: 12%; }
        .col-actions { width: 10%; text-align: center; }
        
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
            margin-bottom: 20px;
        }
        
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
            margin-left: 15px;
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

                <!-- Stats Section - WITH PROPER ICONS -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card total">
                            <i class="bi bi-boxes stat-icon"></i>
                            <div class="stat-value" id="totalItems"><?= $statTotalItems ?></div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card pending">
                            <i class="bi bi-check2-circle stat-icon"></i>
                            <div class="stat-value" id="warehouseReady"><?= $statWarehouseReady ?></div>
                            <div class="stat-label">Warehouse Ready</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value" id="inTransit"><?= $statInTransit ?></div>
                            <div class="stat-label">In Transit</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="delivered"><?= $statDelivered ?></div>
                            <div class="stat-label">Delivered</div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION - WITH SORT CONTROLS AND BUTTONS TOGETHER -->
                <div class="filter-section">
                    <div class="filter-controls">
                        <div class="sort-controls">
                            <button class="sort-btn active" onclick="sortItems('date')">By Date</button>
                            <button class="sort-btn" onclick="sortItems('so')">By SO ID</button>
                            <button class="sort-btn" onclick="sortItems('warehouse')">By Status</button>
                            <button class="sort-btn" onclick="sortItems('quantity')">By Quantity</button>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
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

                <!-- ACTION BAR - REMOVED (moved to filter section) -->

                <!-- Pick List Items Table - WITHOUT CHECKBOX COLUMN -->
                <div class="table-responsive">
                    <table class="table pick-list-table">
                        <thead>
                            <tr>
                                <!-- CHECKBOX HEADER REMOVED -->
                                <th class="col-so">SO NUMBER</th>
                                <th class="col-item-code">ITEM CODE</th>
                                <th class="col-item-name">ITEM NAME</th>
                                <th class="col-to-pick">TO PICK</th>
                                <th class="col-picked">PICKED</th>
                                <th class="col-location">LOCATION</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-encoded">ENCODED BY</th>
                                <th class="col-actions">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody id="pickListTableBody">
                            <?php 
                            $has_displayable_items = false;
                            
                            if (!empty($picklist_items)) {
                                foreach ($picklist_items as $item) {
                                    if ($item['item_id'] !== null) {
                                        $has_displayable_items = true;
                                        break;
                                    }
                                }
                            }
                            
                            if ($has_displayable_items): 
                                foreach ($picklist_items as $item): 
                                    if ($item['item_id'] === null) continue;
                            ?>
                            <tr class="pick-list-row" 
                                data-id="<?= $item['pick_item_id'] ?>"
                                data-so-id="<?= htmlspecialchars($item['pick_list_number'] ?? '') ?>"
                                data-status="<?= $item['pick_status'] ?>">
                                <!-- CHECKBOX CELL REMOVED -->
                                <td class="col-so"><?= htmlspecialchars($item['pick_list_number'] ?? 'N/A') ?></td>
                                <td class="col-item-code"><?= htmlspecialchars($item['item_code'] ?? 'N/A') ?></td>
                                <td class="col-item-name"><?= htmlspecialchars($item['item_name'] ?? 'Unknown Item') ?></td>
                                <td class="col-to-pick"><?= $item['quantity_to_pick'] ?? 0 ?></td>
                                <td class="col-picked"><?= $item['quantity_picked'] ?? 0 ?></td>
                                <td class="col-location"><?= htmlspecialchars($item['location_bin'] ?? '—') ?></td>
                                <td class="col-status">
                                    <span class="badge <?= getPickStatusBadge($item['pick_status']) ?>">
                                        <?= getWarehouseStatusText($item['pick_status']) ?>
                                    </span>
                                </td>
                                <td class="col-encoded"><?= htmlspecialchars($item['encoded_by_name'] ?? 'System') ?></td>
                                <td class="col-actions">
                                    <div class="action-buttons">
                                        <button class="table-btn btn-view" onclick="viewItem(<?= $item['pick_item_id'] ?>)" title="View">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="table-btn btn-edit" onclick="editItem(<?= $item['pick_item_id'] ?>)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="table-btn btn-delete" onclick="deleteItem(<?= $item['pick_item_id'] ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            else: 
                            ?>
                            <tr>
                                <td colspan="9" class="empty-state-table">
                                    <i class="bi bi-clipboard"></i>
                                    <h5>No Pick List Items Found</h5>
                                    <p class="text-muted">There are currently no pick list items in the database.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Add Item Card (visible kahit may laman) -->
                <div class="new-item-card mt-4" onclick="showAddItemModal()">
                    <div class="add-icon">
                        <i class="bi bi-plus-lg"></i>
                    </div>
                    <h5>Add New Pick List Item</h5>
                    <p>Click to add a new item to the pick list</p>
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
                                <input type="number" class="form-control" id="caseQty" min="0" value="0">
                            </div>
                            <div class="col-md-4">
                                <label for="innerPackQty" class="form-label">Inner Pack Quantity</label>
                                <input type="number" class="form-control" id="innerPackQty" min="0" value="0">
                            </div>
                            <div class="col-md-4">
                                <label for="pieceQty" class="form-label">Piece Quantity</label>
                                <input type="number" class="form-control" id="pieceQty" min="0" value="0">
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
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

    // ========== PICK LIST FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Pick List Items - Live Database Mode");
        
        initializeSidebar();
        
        // Mobile menu toggle
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

        // Set current date/time for modal
        const encodedAtInput = document.getElementById('encodedAt');
        if (encodedAtInput) {
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            encodedAtInput.value = formattedDateTime;
        }
    });

    function sortItems(criteria) {
        document.querySelectorAll('.sort-btn').forEach(btn => btn.classList.remove('active'));
        if (event && event.target) event.target.classList.add('active');
        alert('Sort by ' + criteria + ' - AJAX implementation needed');
    }

    function sendToWarehouse(id) {
        alert('Send to warehouse item ' + id + ' - AJAX implementation needed');
    }

    function sendBatchToWarehouse() {
        alert('Send selected items to warehouse - AJAX implementation needed');
    }

    function generateInvoice(id) {
        alert('Generate invoice for item ' + id + ' - AJAX implementation needed');
    }

    function generateBatchInvoice() {
        alert('Generate invoices for selected items - AJAX implementation needed');
    }

    function generateTripTicket(id) {
        alert('Create trip ticket for item ' + id + ' - AJAX implementation needed');
    }

    function createBatchTripTicket() {
        alert('Create trip tickets for selected items - AJAX implementation needed');
    }

    function printPickList() {
        window.print();
    }

    function exportToCSV() {
        alert('Export to CSV - AJAX implementation needed');
    }

    // CRUD Operations
    function showAddItemModal() {
        document.getElementById('modalTitle').textContent = 'Add Pick List Item';
        document.getElementById('itemForm').reset();
        document.getElementById('itemId').value = '';
        
        const encodedAtInput = document.getElementById('encodedAt');
        if (encodedAtInput) {
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            encodedAtInput.value = formattedDateTime;
        }
        
        document.getElementById('encodedBy').value = 'marinellemacalir';
        document.getElementById('companyId').value = 'ebfa67e3';
        
        new bootstrap.Modal(document.getElementById('itemModal')).show();
    }

    function viewItem(id) {
        alert('View item ' + id + ' - AJAX implementation needed');
    }

    function editItem(id) {
        alert('Edit item ' + id + ' - AJAX implementation needed');
    }

    function deleteItem(id) {
        window.itemToDelete = id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function confirmDelete() {
        alert('Delete item ' + window.itemToDelete + ' - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
    }

    function saveItem() {
        alert('Save item - AJAX implementation needed');
        bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
    }

    // Logout Function
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
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('searchItems');
            if (searchInput) searchInput.focus();
        }
    });
    </script>
</body>
</html>