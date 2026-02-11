<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link rel="stylesheet" href="../css/purchase_order.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar (SAME AS CURRENT INVENTORY) -->
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
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="purchase_orders.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <!-- TRIP TICKETS ACTIVE LINK -->
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
            <!-- PURCHASE ORDERS TABLE CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top - Same as Trip Tickets -->
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Purchase Orders</h2>
                        <p>Manage and track all purchase orders</p>
                    </div>
                </div>

                <!-- Stats Section -->
                <div class="stats-row">
                    <div class="stat-card total">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="totalPO">0</div>
                            <div class="stat-label">Total POs</div>
                        </div>
                    </div>
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="processingPO">0</div>
                            <div class="stat-label">Processing</div>
                        </div>
                    </div>
                    <div class="stat-card processing">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="deliveredPO">0</div>
                            <div class="stat-label">Delivered</div>
                        </div>
                    </div>
                    <div class="stat-card rejected">
                        <div class="stat-icon">
                            <i class="bi bi-arrow-return-left"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="returnedPO">0</div>
                            <div class="stat-label">Returned</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Controls -->
                <div class="filter-controls">
                    <select class="filter-select" id="filterStatus" onchange="filterTable()">
                        <option value="all">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="processing">Processing</option>
                        <option value="delivered">Delivered</option>
                        <option value="returned">Returned</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    
                    <select class="filter-select" id="filterSupplier" onchange="filterTable()">
                        <option value="all">All Suppliers</option>
                    </select>
                    
                    <select class="filter-select" id="filterMonth" onchange="filterTable()">
                        <option value="all">All Months</option>
                        <option value="1">January</option>
                        <option value="2">February</option>
                        <option value="3">March</option>
                        <option value="4">April</option>
                        <option value="5">May</option>
                        <option value="6">June</option>
                        <option value="7">July</option>
                        <option value="8">August</option>
                        <option value="9">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                    
                    <div class="filter-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" placeholder="Search PO number, supplier, or items..." onkeyup="filterTable()">
                    </div>
                    
                    <button class="btn btn-outline-primary" onclick="exportToExcel()">
                        <i class="bi bi-download me-1"></i> Export Excel
                    </button>
                    <button class="btn btn-primary" onclick="showNewPOModal()">
                        <i class="bi bi-plus-circle me-1"></i> New PO
                    </button>
                </div>

                <!-- Table Container -->
                <div class="table-container">
                    <table class="table po-table">
                        <thead>
                            <tr>
                                <th width="5%">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                                </th>
                                <th width="10%">PO No.</th>
                                <th width="15%">Supplier</th>
                                <th width="10%">Date</th>
                                <th width="10%">Items</th>
                                <th width="10%">Total Qty</th>
                                <th width="10%">Amount</th>
                                <th width="10%">Status</th>
                                <th width="15%">Expected Date</th>
                                <th width="15%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="poTableBody">
                            <!-- Table rows will be populated here -->
                        </tbody>
                    </table>
                    
                    <!-- Empty State -->
                    <div class="empty-state" id="emptyState" style="display: none;">
                        <div class="empty-state-icon">
                            <i class="bi bi-inbox"></i>
                        </div>
                        <h4>No Purchase Orders Found</h4>
                        <p class="text-muted mb-4">Try adjusting your filters or create a new purchase order</p>
                        <button class="btn btn-primary" onclick="showNewPOModal()">
                            <i class="bi bi-plus-circle me-1"></i> Create New PO
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="pagination-container">
                    <div class="pagination-info" id="paginationInfo">
                        Showing 0 of 0 entries
                    </div>
                    <nav>
                        <ul class="pagination" id="pagination">
                            <!-- Pagination will be generated here -->
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Sample data for Purchase Orders
    let purchaseOrders = [
        {
            id: 1,
            poNumber: 'PO-2024-001',
            supplierName: 'Coca-Cola Philippines',
            supplierContact: 'Juan Dela Cruz',
            poDate: '2024-01-15',
            expectedDate: '2024-01-20',
            status: 'delivered',
            totalAmount: 75000,
            items: [
                { code: 'SC', description: 'SWAKTO COKE 190MLX12', quantity: 50, unit: 'case', unitPrice: 1500, total: 75000 }
            ],
            remarks: 'Regular monthly order'
        },
        // ... (rest of your purchaseOrders array remains the same)
    ];

    let currentPage = 1;
    const itemsPerPage = 10;
    let filteredOrders = [...purchaseOrders];

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
        console.log("Purchase Orders page loaded!");
        
        // Setup mobile menu toggle
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

        // Setup search functionality
        const searchPO = document.getElementById('searchPO');
        let searchTimeout;
        
        if (searchPO) {
            searchPO.addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('searchInput').value = e.target.value;
                    filterTable();
                }, 300);
            });
        }
        
        // Load from localStorage if available
        loadFromLocalStorage();
        
        // Initialize supplier filter options
        initializeSupplierFilter();
        
        // Initialize UI
        updateStats();
        renderTable();

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

    // Initialize supplier filter dropdown
    function initializeSupplierFilter() {
        const supplierSelect = document.getElementById('filterSupplier');
        const suppliers = [...new Set(purchaseOrders.map(po => po.supplierName))];
        
        suppliers.forEach(supplier => {
            const option = document.createElement('option');
            option.value = supplier;
            option.textContent = supplier;
            supplierSelect.appendChild(option);
        });
    }

    // Render table with pagination
    function renderTable() {
        const tbody = document.getElementById('poTableBody');
        const emptyState = document.getElementById('emptyState');
        
        if (filteredOrders.length === 0) {
            tbody.innerHTML = '';
            emptyState.style.display = 'block';
            updatePaginationInfo();
            return;
        }
        
        emptyState.style.display = 'none';
        
        // Calculate pagination
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = Math.min(startIndex + itemsPerPage, filteredOrders.length);
        const currentOrders = filteredOrders.slice(startIndex, endIndex);
        
        // Clear table
        tbody.innerHTML = '';
        
        // Add rows
        currentOrders.forEach(po => {
            const totalItems = po.items ? po.items.length : 0;
            const totalQuantity = po.items ? po.items.reduce((sum, item) => sum + item.quantity, 0) : 0;
            const statusClass = getStatusClass(po.status);
            const statusText = getStatusText(po.status);
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="po-checkbox" value="${po.id}">
                </td>
                <td>
                    <strong>${po.poNumber}</strong>
                </td>
                <td>
                    <div class="fw-medium">${po.supplierName}</div>
                    <small class="text-muted">${po.supplierContact || 'No contact'}</small>
                </td>
                <td>${formatDate(po.poDate)}</td>
                <td>${totalItems}</td>
                <td>${totalQuantity}</td>
                <td>₱${po.totalAmount.toLocaleString()}</td>
                <td>
                    <span class="status-badge ${statusClass}">${statusText}</span>
                </td>
                <td>${formatDate(po.expectedDate)}</td>
                <td>
                    <div class="action-buttons">
                        <button class="table-btn btn-view" onclick="viewPO(${po.id})" title="View">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="table-btn btn-edit" onclick="editPO(${po.id})" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="table-btn btn-delete" onclick="deletePO(${po.id})" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            
            tbody.appendChild(row);
        });
        
        updatePaginationInfo();
        renderPagination();
    }

    // Filter table based on criteria
    function filterTable() {
        const statusFilter = document.getElementById('filterStatus').value;
        const supplierFilter = document.getElementById('filterSupplier').value;
        const monthFilter = document.getElementById('filterMonth').value;
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        
        filteredOrders = purchaseOrders.filter(po => {
            // Status filter
            if (statusFilter !== 'all' && po.status !== statusFilter) {
                return false;
            }
            
            // Supplier filter
            if (supplierFilter !== 'all' && po.supplierName !== supplierFilter) {
                return false;
            }
            
            // Month filter
            if (monthFilter !== 'all') {
                const poMonth = new Date(po.poDate).getMonth() + 1;
                if (poMonth !== parseInt(monthFilter)) {
                    return false;
                }
            }
            
            // Search filter
            if (searchTerm) {
                const searchText = `
                    ${po.poNumber.toLowerCase()}
                    ${po.supplierName.toLowerCase()}
                    ${po.supplierContact ? po.supplierContact.toLowerCase() : ''}
                    ${po.remarks ? po.remarks.toLowerCase() : ''}
                    ${po.items ? po.items.map(item => 
                        item.description.toLowerCase() + 
                        (item.code ? item.code.toLowerCase() : '')
                    ).join(' ') : ''}
                `;
                
                if (!searchText.includes(searchTerm)) {
                    return false;
                }
            }
            
            return true;
        });
        
        currentPage = 1; // Reset to first page
        updateStats();
        renderTable();
    }

    // Update pagination information
    function updatePaginationInfo() {
        const startIndex = (currentPage - 1) * itemsPerPage + 1;
        const endIndex = Math.min(currentPage * itemsPerPage, filteredOrders.length);
        const total = filteredOrders.length;
        
        document.getElementById('paginationInfo').textContent = 
            `Showing ${startIndex} to ${endIndex} of ${total} entries`;
    }

    // Render pagination controls
    function renderPagination() {
        const totalPages = Math.ceil(filteredOrders.length / itemsPerPage);
        const pagination = document.getElementById('pagination');
        
        pagination.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        // Previous button
        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
        prevLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${currentPage - 1})">Previous</a>`;
        pagination.appendChild(prevLi);
        
        // Page numbers
        const maxVisiblePages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
        
        if (endPage - startPage + 1 < maxVisiblePages) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const pageLi = document.createElement('li');
            pageLi.className = `page-item ${currentPage === i ? 'active' : ''}`;
            pageLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${i})">${i}</a>`;
            pagination.appendChild(pageLi);
        }
        
        // Next button
        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
        nextLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${currentPage + 1})">Next</a>`;
        pagination.appendChild(nextLi);
    }

    // Change page
    function changePage(page) {
        if (page < 1 || page > Math.ceil(filteredOrders.length / itemsPerPage)) return;
        
        currentPage = page;
        renderTable();
        
        // Scroll to top
        const tableContainer = document.querySelector('.table-container');
        if (tableContainer) {
            tableContainer.scrollIntoView({ behavior: 'smooth' });
        }
    }

    // Toggle select all checkboxes
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.po-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }

    // Get selected PO IDs
    function getSelectedPOs() {
        const checkboxes = document.querySelectorAll('.po-checkbox:checked');
        return Array.from(checkboxes).map(cb => parseInt(cb.value));
    }

    // View PO details
    function viewPO(id) {
        const po = purchaseOrders.find(p => p.id === id);
        if (!po) return;
        
        alert(`Viewing PO: ${po.poNumber}\nSupplier: ${po.supplierName}\nAmount: ₱${po.totalAmount.toLocaleString()}\nStatus: ${getStatusText(po.status)}`);
        // You can implement a modal or separate page for viewing details
    }

    // Edit PO
    function editPO(id) {
        const po = purchaseOrders.find(p => p.id === id);
        if (!po) return;
        
        alert(`Editing PO: ${po.poNumber}`);
        // You can implement edit functionality here
    }

    // Delete PO
    function deletePO(id) {
        if (!confirm('Are you sure you want to delete this purchase order?')) return;
        
        const index = purchaseOrders.findIndex(p => p.id === id);
        if (index !== -1) {
            purchaseOrders.splice(index, 1);
            saveToLocalStorage();
            showNotification('Purchase order deleted successfully', 'success');
            filterTable();
        }
    }

    // Delete selected POs
    function deleteSelectedPOs() {
        const selectedIds = getSelectedPOs();
        if (selectedIds.length === 0) {
            showNotification('No purchase orders selected', 'warning');
            return;
        }
        
        if (!confirm(`Are you sure you want to delete ${selectedIds.length} selected purchase order(s)?`)) return;
        
        purchaseOrders = purchaseOrders.filter(po => !selectedIds.includes(po.id));
        saveToLocalStorage();
        showNotification(`${selectedIds.length} purchase order(s) deleted successfully`, 'success');
        filterTable();
    }

    // Update stats
    function updateStats() {
        const totalPO = purchaseOrders.length;
        const processingPO = purchaseOrders.filter(po => po.status === 'processing').length;
        const deliveredPO = purchaseOrders.filter(po => po.status === 'delivered').length;
        const returnedPO = purchaseOrders.filter(po => po.status === 'returned').length;
        
        document.getElementById('totalPO').textContent = totalPO;
        document.getElementById('processingPO').textContent = processingPO;
        document.getElementById('deliveredPO').textContent = deliveredPO;
        document.getElementById('returnedPO').textContent = returnedPO;
    }

    // Export to Excel
    function exportToExcel() {
        try {
            const headers = ['PO_Number', 'Supplier_Name', 'PO_Date', 'Expected_Date', 
                            'Status', 'Total_Items', 'Total_Quantity', 'Total_Amount', 'Remarks'];
            
            const csvData = filteredOrders.map(po => {
                const totalItems = po.items ? po.items.length : 0;
                const totalQuantity = po.items ? po.items.reduce((sum, item) => sum + item.quantity, 0) : 0;
                
                return [
                    po.poNumber,
                    `"${po.supplierName.replace(/"/g, '""')}"`,
                    po.poDate,
                    po.expectedDate,
                    getStatusText(po.status),
                    totalItems,
                    totalQuantity,
                    po.totalAmount,
                    `"${(po.remarks || '').replace(/"/g, '""')}"`
                ];
            });
            
            const csvContent = [
                headers.join(','),
                ...csvData.map(row => row.join(','))
            ].join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            const fileName = `po_table_${new Date().toISOString().slice(0, 10)}.csv`;
            link.setAttribute('download', fileName);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification(`Exported ${filteredOrders.length} PO records`, 'success');
        } catch (error) {
            console.error('Export error:', error);
            showNotification('Failed to export data', 'warning');
        }
    }

    // Show new PO modal (placeholder)
    function showNewPOModal() {
        alert('New PO modal would open here');
        // Implement your modal logic here
    }

    // Helper functions
    function getStatusClass(status) {
        switch(status) {
            case 'draft': return 'status-draft';
            case 'processing': return 'status-processing';
            case 'delivered': return 'status-delivered';
            case 'returned': return 'status-returned';
            case 'cancelled': return 'status-cancelled';
            default: return 'status-draft';
        }
    }

    function getStatusText(status) {
        switch(status) {
            case 'draft': return 'Draft';
            case 'processing': return 'Processing';
            case 'delivered': return 'Delivered';
            case 'returned': return 'Returned';
            case 'cancelled': return 'Cancelled';
            default: return 'Draft';
        }
    }

    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function showNotification(message, type = 'success') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = 'notification';
        notification.textContent = message;
        
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

    // Data persistence
    function saveToLocalStorage() {
        try {
            localStorage.setItem('purchaseOrders', JSON.stringify(purchaseOrders));
        } catch (error) {
            console.error('Failed to save to localStorage:', error);
        }
    }

    function loadFromLocalStorage() {
        try {
            const savedPO = localStorage.getItem('purchaseOrders');
            if (savedPO) {
                const parsed = JSON.parse(savedPO);
                if (Array.isArray(parsed) && parsed.length > 0) {
                    purchaseOrders = parsed;
                }
            }
        } catch (error) {
            console.error('Failed to load from localStorage:', error);
        }
    }
</script>
</body>
</html>