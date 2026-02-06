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
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Main Container */
        .page-content.active {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .po-table {
            width: 100%;
            margin-bottom: 0;
        }
        
        .po-table th {
            background-color: #f8fafc;
            color: #374151;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 12px 16px;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .po-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.875rem;
            vertical-align: middle;
        }
        
        .po-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .po-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        /* Status Badge */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .status-draft {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }
        
        .status-processing {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .status-delivered {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        .status-returned {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        .status-cancelled {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 6px;
        }
        
        .table-btn {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-view {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .btn-view:hover {
            background: rgba(59, 130, 246, 0.2);
        }
        
        .btn-edit {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .btn-edit:hover {
            background: rgba(245, 158, 11, 0.2);
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.2);
        }
        
        /* Filter Controls */
        .filter-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            background: white;
            color: #374151;
            min-width: 150px;
        }
        
        .filter-search {
            flex: 1;
            max-width: 300px;
            position: relative;
        }
        
        .filter-search input {
            width: 100%;
            padding: 8px 12px 8px 36px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .filter-search i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        
        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .total .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .pending .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .processing .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        .rejected .stat-icon {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-top: 1px solid #e5e7eb;
            background: #f8fafc;
        }
        
        .pagination-info {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            padding: 6px 12px;
            font-size: 0.875rem;
            border: 1px solid #d1d5db;
            color: #3b82f6;
            margin: 0 2px;
        }
        
        .page-link:hover {
            background: #f3f4f6;
        }
        
        .page-item.active .page-link {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
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
        
        /* Buttons */
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
            border: 1px solid #3b82f6;
        }
        
        .btn-primary:hover {
            background: #2563eb;
            border-color: #2563eb;
        }
        
        .btn-outline-primary {
            border: 1px solid #3b82f6;
            color: #3b82f6;
            background: white;
        }
        
        .btn-outline-primary:hover {
            background: #3b82f6;
            color: white;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .navbar-top {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .user-info-top {
                justify-content: space-between;
            }
            
            .search-box {
                min-width: auto;
                flex: 1;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .po-table {
                min-width: 800px;
            }
            
            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-search {
                max-width: 100%;
            }
            
            .pagination-container {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .user-info-top {
                flex-direction: column;
                align-items: stretch;
            }
            
            .user-profile-top {
                justify-content: center;
                padding-top: 10px;
                border-top: 1px solid #e5e7eb;
            }
        }
    </style>
</head>
<body>
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
            <!-- PURCHASE ORDERS TABLE CONTENT -->
            <div class="page-content active">
                <!-- Navbar Top - Same as Trip Tickets -->
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-box me-2"></i>Purchase Orders</h2>
                        <p>Manage and track all purchase orders</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search purchase orders..." id="searchPO">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top" id="userAvatar">PO</div>
                            <div class="user-details-top">
                                <span class="user-name-top" id="userName">Purchasing Dept</span>
                                <span class="user-role-top" id="userRole">Purchasing Officer</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
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
            {
                id: 2,
                poNumber: 'PO-2024-002',
                supplierName: 'San Miguel Corporation',
                supplierContact: 'Maria Santos',
                poDate: '2024-01-16',
                expectedDate: '2024-01-22',
                status: 'processing',
                totalAmount: 168000,
                items: [
                    { code: 'PALE PILSEN', description: 'SAN MIGUEL PALE PILSEN 330MLX24', quantity: 50, unit: 'case', unitPrice: 1800, total: 90000 },
                    { code: 'REDHORSE', description: 'RED HORSE 500MLX12', quantity: 30, unit: 'case', unitPrice: 3200, total: 96000 },
                    { code: 'FRASCO', description: 'GINEBRA FRASCO 700MLX12', quantity: 20, unit: 'case', unitPrice: 4200, total: 84000 }
                ],
                remarks: 'Weekly restocking order'
            },
            {
                id: 3,
                poNumber: 'PO-2024-003',
                supplierName: 'Asia Brewery Inc.',
                supplierContact: 'Pedro Reyes',
                poDate: '2024-01-10',
                expectedDate: '2024-01-15',
                status: 'returned',
                totalAmount: 54000,
                items: [
                    { code: 'COBRA YLW', description: 'COBRA YELLOW 290MLX12', quantity: 30, unit: 'case', unitPrice: 2800, total: 84000 }
                ],
                remarks: 'Returned due to quality issues'
            },
            {
                id: 4,
                poNumber: 'PO-2024-004',
                supplierName: 'Nestle Philippines',
                supplierContact: 'Ana Lopez',
                poDate: '2024-01-12',
                expectedDate: '2024-01-17',
                status: 'delivered',
                totalAmount: 45000,
                items: [
                    { code: 'NESCAFE', description: 'NESCAFE CLASSIC 50GX48', quantity: 25, unit: 'case', unitPrice: 1800, total: 45000 }
                ],
                remarks: 'Coffee products restock'
            },
            {
                id: 5,
                poNumber: 'PO-2024-005',
                supplierName: 'PepsiCo Philippines',
                supplierContact: 'Robert Lim',
                poDate: '2024-01-18',
                expectedDate: '2024-01-23',
                status: 'processing',
                totalAmount: 67200,
                items: [
                    { code: 'PEPSI 1.5L', description: 'PEPSI 1.5L PET', quantity: 40, unit: 'case', unitPrice: 1680, total: 67200 }
                ],
                remarks: 'Soft drinks replenishment'
            },
            {
                id: 6,
                poNumber: 'PO-2024-006',
                supplierName: 'Universal Robina Corp',
                supplierContact: 'Susan Tan',
                poDate: '2024-01-14',
                expectedDate: '2024-01-19',
                status: 'delivered',
                totalAmount: 36000,
                items: [
                    { code: 'ROYAL', description: 'ROYAL TRU-ORANGE 240MLX24', quantity: 80, unit: 'inner-pack', unitPrice: 450, total: 36000 }
                ],
                remarks: 'Juice drinks order'
            },
            {
                id: 7,
                poNumber: 'PO-2024-007',
                supplierName: 'Coca-Cola Philippines',
                supplierContact: 'Juan Dela Cruz',
                poDate: '2024-01-20',
                expectedDate: '2024-01-25',
                status: 'processing',
                totalAmount: 126000,
                items: [
                    { code: 'SPRITE 500ML', description: 'SPRITE 500MLX24', quantity: 60, unit: 'case', unitPrice: 2100, total: 126000 }
                ],
                remarks: 'Additional order for promotion'
            },
            {
                id: 8,
                poNumber: 'PO-2024-008',
                supplierName: 'San Miguel Corporation',
                supplierContact: 'Maria Santos',
                poDate: '2024-01-08',
                expectedDate: '2024-01-13',
                status: 'returned',
                totalAmount: 96000,
                items: [
                    { code: 'GIN BTL', description: 'GINEBRA SAN MIGUEL 350MLX24', quantity: 40, unit: 'case', unitPrice: 2400, total: 96000 }
                ],
                remarks: 'Returned due to wrong item'
            },
            {
                id: 9,
                poNumber: 'PO-2024-009',
                supplierName: 'Asia Brewery Inc.',
                supplierContact: 'Pedro Reyes',
                poDate: '2024-01-22',
                expectedDate: '2024-01-27',
                status: 'draft',
                totalAmount: 89600,
                items: [
                    { code: 'COBRA RED', description: 'COBRA RED 290MLX12', quantity: 32, unit: 'case', unitPrice: 2800, total: 89600 }
                ],
                remarks: 'Draft order for review'
            },
            {
                id: 10,
                poNumber: 'PO-2024-010',
                supplierName: 'Nestle Philippines',
                supplierContact: 'Ana Lopez',
                poDate: '2024-01-19',
                expectedDate: '2024-01-24',
                status: 'delivered',
                totalAmount: 31500,
                items: [
                    { code: 'MILO', description: 'MILO 300GX24', quantity: 35, unit: 'case', unitPrice: 900, total: 31500 }
                ],
                remarks: 'Milo stock replenishment'
            },
            {
                id: 11,
                poNumber: 'PO-2024-011',
                supplierName: 'PepsiCo Philippines',
                supplierContact: 'Robert Lim',
                poDate: '2024-01-21',
                expectedDate: '2024-01-26',
                status: 'cancelled',
                totalAmount: 50400,
                items: [
                    { code: '7UP 1.5L', description: '7UP 1.5L PET', quantity: 30, unit: 'case', unitPrice: 1680, total: 50400 }
                ],
                remarks: 'Cancelled due to overstock'
            },
            {
                id: 12,
                poNumber: 'PO-2024-012',
                supplierName: 'Universal Robina Corp',
                supplierContact: 'Susan Tan',
                poDate: '2024-01-24',
                expectedDate: '2024-01-29',
                status: 'processing',
                totalAmount: 43200,
                items: [
                    { code: 'COKE 1.5L', description: 'COCA-COLA 1.5L PET', quantity: 80, unit: 'case', unitPrice: 540, total: 43200 }
                ],
                remarks: 'Weekly restocking'
            }
        ];

        let currentPage = 1;
        const itemsPerPage = 10;
        let filteredOrders = [...purchaseOrders];

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Setup search functionality
            const searchPO = document.getElementById('searchPO');
            let searchTimeout;
            
            searchPO.addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    document.getElementById('searchInput').value = e.target.value;
                    filterTable();
                }, 300);
            });
            
            // Load from localStorage if available
            loadFromLocalStorage();
            
            // Initialize supplier filter options
            initializeSupplierFilter();
            
            // Initialize UI
            updateStats();
            renderTable();
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
            document.querySelector('.table-container').scrollIntoView({ behavior: 'smooth' });
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

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                showNotification('Logged out successfully', 'info');
                setTimeout(() => {
                    alert('Redirecting to login page...');
                }, 1000);
            }
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