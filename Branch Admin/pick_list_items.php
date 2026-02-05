<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List Items</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Pick List Item Cards */
        .pick-list-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .pick-list-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }
        
        .pick-list-card .so-id {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 12px;
        }
        
        .pick-list-card .item-details {
            margin-bottom: 1rem;
        }
        
        .pick-list-card .item-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 1rem;
            margin-bottom: 8px;
        }
        
        .pick-list-card .quantities {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
        }
        
        .pick-list-card .quantity-badge {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
            min-width: 70px;
        }
        
        .pick-list-card .quantity-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .pick-list-card .quantity-value {
            font-weight: 700;
            font-size: 1.25rem;
            color: #059669;
        }
        
        .pick-list-card .item-meta {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .pick-list-card .item-meta i {
            margin-right: 4px;
        }
        
        .pick-list-card .action-buttons {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-icon:hover {
            transform: scale(1.1);
        }
        
        .btn-edit {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .btn-edit:hover {
            background: rgba(59, 130, 246, 0.2);
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.2);
        }
        
        /* New Item Card */
        .new-item-card {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(16, 185, 129, 0.02));
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .new-item-card:hover {
            border-color: #10b981;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
        }
        
        .new-item-card .add-icon {
            width: 48px;
            height: 48px;
            background: rgba(16, 185, 129, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        
        .new-item-card .add-icon i {
            color: #10b981;
            font-size: 1.5rem;
        }
        
        .new-item-card h5 {
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .new-item-card p {
            color: #6b7280;
            font-size: 0.875rem;
            margin-bottom: 0;
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .modal-header {
            border-bottom: 1px solid #e5e7eb;
            padding: 1.25rem 1.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            border-top: 1px solid #e5e7eb;
            padding: 1rem 1.5rem;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .status-in-progress {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state-icon {
            width: 80px;
            height: 80px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .empty-state-icon i {
            font-size: 2rem;
            color: #9ca3af;
        }
        
        /* Sort Controls */
        .sort-controls {
            display: flex;
            gap: 8px;
            margin-bottom: 1rem;
        }
        
        .sort-btn {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: white;
            font-size: 0.875rem;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .sort-btn:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }
        
        .sort-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
    </style>
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
                <h3><i class="bi bi-box-seam logo-icon"></i> <span class="nav-text">Branch Admin</span></h3>
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
            <!-- PICK LIST ITEMS CONTENT -->
            <div class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-list-check me-2"></i>Pick List Items</h2>
                        <p>Manage pick list items for order fulfillment</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search items..." id="searchItems">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <i class="bi bi-boxes stat-icon"></i>
                            <div class="stat-value" id="totalItems">3</div>
                            <div class="stat-label">Total Items</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value" id="totalCases">108</div>
                            <div class="stat-label">Total Cases</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card delivery">
                            <i class="bi bi-cart-check stat-icon"></i>
                            <div class="stat-value" id="totalOrders">2</div>
                            <div class="stat-label">Sales Orders</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <i class="bi bi-person-check stat-icon"></i>
                            <div class="stat-value" id="uniquePicker">1</div>
                            <div class="stat-label">Picker</div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="sort-controls">
                                <button class="sort-btn active" onclick="sortItems('date')">By Date</button>
                                <button class="sort-btn" onclick="sortItems('so')">By SO ID</button>
                                <button class="sort-btn" onclick="sortItems('quantity')">By Quantity</button>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-primary">
                                    <i class="bi bi-printer me-1"></i> Print All
                                </button>
                                <button class="btn btn-outline-primary">
                                    <i class="bi bi-download me-1"></i> Export
                                </button>
                                <button class="btn btn-primary" onclick="showAddItemModal()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Item
                                </button>
                            </div>
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

                <!-- Empty State (Hidden by default) -->
                <div class="empty-state" id="emptyState" style="display: none;">
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
                                <label for="soId" class="form-label">Sales Order ID</label>
                                <input type="text" class="form-control" id="soId" required>
                            </div>
                            <div class="col-md-6">
                                <label for="itemCode" class="form-label">Item Code</label>
                                <input type="text" class="form-control" id="itemCode" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="itemName" class="form-label">Item Name/Description</label>
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
                                <label for="encodedBy" class="form-label">Encoded By</label>
                                <input type="text" class="form-control" id="encodedBy" value="marinellemacalir" required>
                            </div>
                            <div class="col-md-6">
                                <label for="encodedAt" class="form-label">Encoded At</label>
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
        // Sample data from database
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
                status: 'pending'
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
                status: 'in-progress'
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
                status: 'completed'
            }
        ];

        let itemToDelete = null;
        let currentSort = 'date';

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

            // Search functionality
            document.getElementById('searchItems').addEventListener('input', function(e) {
                filterItems(e.target.value);
            });

            // Initialize items display
            updateStats();
            renderItems();

            // Set current date/time for new items
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            document.getElementById('encodedAt').value = formattedDateTime;

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
                    document.getElementById('searchItems').focus();
                }
            });
        });

        // Render pick list items
        function renderItems(items = pickListItems) {
            const container = document.getElementById('pickListItemsContainer');
            const emptyState = document.getElementById('emptyState');
            
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
                
                const card = document.createElement('div');
                card.className = 'pick-list-card';
                card.innerHTML = `
                    <div class="action-buttons">
                        <button class="btn-icon btn-edit" onclick="editItem(${item.id})" title="Edit Item">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn-icon btn-delete" onclick="deleteItem(${item.id})" title="Delete Item">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    
                    <div class="so-id">SO: ${item.soId}</div>
                    
                    <div class="item-details">
                        <div class="item-name">${item.itemName}</div>
                        
                        <div class="quantities">
                            <div class="quantity-badge">
                                <span class="quantity-label">CS</span>
                                <span class="quantity-value">${item.caseQty}</span>
                            </div>
                            <div class="quantity-badge">
                                <span class="quantity-label">IP</span>
                                <span class="quantity-value">${item.innerPackQty}</span>
                            </div>
                            <div class="quantity-badge">
                                <span class="quantity-label">PC</span>
                                <span class="quantity-value">${item.pieceQty}</span>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="item-meta">
                                <i class="bi bi-person"></i> ${item.encodedBy} 
                                <span class="mx-2">•</span>
                                <i class="bi bi-calendar"></i> ${formatDate(item.encodedAt)}
                            </div>
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </div>
                    </div>
                `;
                
                container.appendChild(card);
            });
        }

        // Update statistics
        function updateStats() {
            const totalItems = pickListItems.length;
            const totalCases = pickListItems.reduce((sum, item) => sum + item.caseQty, 0);
            const uniqueSOs = [...new Set(pickListItems.map(item => item.soId))].length;
            const uniquePickers = [...new Set(pickListItems.map(item => item.encodedBy))].length;
            
            document.getElementById('totalItems').textContent = totalItems;
            document.getElementById('totalCases').textContent = totalCases;
            document.getElementById('totalOrders').textContent = uniqueSOs;
            document.getElementById('uniquePicker').textContent = uniquePickers;
        }

        // Show add item modal
        function showAddItemModal() {
            document.getElementById('modalTitle').textContent = 'Add Pick List Item';
            document.getElementById('itemForm').reset();
            document.getElementById('itemId').value = '';
            
            // Set current date/time
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            document.getElementById('encodedAt').value = formattedDateTime;
            document.getElementById('encodedBy').value = 'marinellemacalir';
            document.getElementById('companyId').value = 'ebfa67e3';
            
            const modal = new bootstrap.Modal(document.getElementById('itemModal'));
            modal.show();
        }

        // Edit item
        function editItem(id) {
            const item = pickListItems.find(item => item.id === id);
            if (!item) return;
            
            document.getElementById('modalTitle').textContent = 'Edit Pick List Item';
            document.getElementById('itemId').value = item.id;
            document.getElementById('soId').value = item.soId;
            document.getElementById('itemCode').value = item.itemCode;
            document.getElementById('itemName').value = item.itemName;
            document.getElementById('caseQty').value = item.caseQty;
            document.getElementById('innerPackQty').value = item.innerPackQty;
            document.getElementById('pieceQty').value = item.pieceQty;
            document.getElementById('encodedBy').value = item.encodedBy;
            document.getElementById('encodedAt').value = item.encodedAt;
            document.getElementById('companyId').value = item.companyId;
            
            const modal = new bootstrap.Modal(document.getElementById('itemModal'));
            modal.show();
        }

        // Save item (add or update)
        function saveItem() {
            const id = document.getElementById('itemId').value;
            const isEdit = !!id;
            
            const item = {
                soId: document.getElementById('soId').value.trim(),
                itemCode: document.getElementById('itemCode').value.trim(),
                itemName: document.getElementById('itemName').value.trim(),
                caseQty: parseInt(document.getElementById('caseQty').value) || 0,
                innerPackQty: parseInt(document.getElementById('innerPackQty').value) || 0,
                pieceQty: parseInt(document.getElementById('pieceQty').value) || 0,
                encodedBy: document.getElementById('encodedBy').value.trim(),
                encodedAt: document.getElementById('encodedAt').value,
                companyId: document.getElementById('companyId').value.trim(),
                status: 'pending'
            };
            
            // Validation
            if (!item.soId || !item.itemCode || !item.itemName) {
                alert('Please fill in all required fields');
                return;
            }
            
            if (isEdit) {
                // Update existing item
                const index = pickListItems.findIndex(i => i.id === parseInt(id));
                if (index !== -1) {
                    pickListItems[index] = { ...pickListItems[index], ...item };
                    showNotification('Item updated successfully', 'success');
                }
            } else {
                // Add new item
                const newId = pickListItems.length > 0 ? Math.max(...pickListItems.map(i => i.id)) + 1 : 1;
                item.id = newId;
                pickListItems.push(item);
                showNotification('Item added successfully', 'success');
            }
            
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
            
            // Update UI
            updateStats();
            renderItems();
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
                showNotification('Item deleted successfully', 'success');
                
                // Update UI
                updateStats();
                renderItems();
                
                // Close modal
                bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                itemToDelete = null;
            }
        }

        // Filter items based on search
        function filterItems(searchTerm) {
            if (!searchTerm) {
                renderItems();
                return;
            }
            
            const filtered = pickListItems.filter(item => 
                item.itemName.toLowerCase().includes(searchTerm.toLowerCase()) ||
                item.soId.toLowerCase().includes(searchTerm.toLowerCase()) ||
                item.itemCode.toLowerCase().includes(searchTerm.toLowerCase())
            );
            
            renderItems(filtered);
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
                case 'quantity':
                    sortedItems.sort((a, b) => b.caseQty - a.caseQty);
                    break;
            }
            
            renderItems(sortedItems);
        }

        // Helper functions
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

        function getStatusClass(status) {
            switch(status) {
                case 'pending': return 'status-pending';
                case 'in-progress': return 'status-in-progress';
                case 'completed': return 'status-completed';
                default: return 'status-pending';
            }
        }

        function getStatusText(status) {
            switch(status) {
                case 'pending': return 'Pending';
                case 'in-progress': return 'In Progress';
                case 'completed': return 'Completed';
                default: return 'Pending';
            }
        }

        function showNotification(message, type = 'success') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'notification';
            notification.innerHTML = `
                <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-info-circle'} me-2"></i>
                ${message}
            `;
            
            // Style the notification
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                background: ${type === 'success' ? '#10b981' : '#3b82f6'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                z-index: 1000;
                animation: slideIn 0.3s ease;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 14px;
                display: flex;
                align-items: center;
            `;
            
            document.body.appendChild(notification);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        // Logout Function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                alert('Logout functionality would redirect to login page.');
            }
        }

        // Add animation styles
        const style = document.createElement('style');
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
    </script>
</body>
</html>