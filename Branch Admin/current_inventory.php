<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Inventory</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <h3><img src="../Pictures/nobg.png" alt="Logo" class="logo-icon"> <span class="nav-text">Branch Admin</span></h3>
            </div>
            
            <div class="sidebar-menu">
    <ul class="nav flex-column">
        <li class="nav-item">
            <a class="nav-link active" href="current_inventory.php">
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
            <!-- DASHBOARD -->
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-speedometer2 me-2"></i>Current Inventory</h2>
                        <p id="dashboardSubtitle">Welcome to Inventory System Demo Mode</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top" id="userAvatar">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top" id="userName">Admin User</span>
                                <span class="user-role-top" id="userRole">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Log Out
                        </button>
                    </div>
                </div>


                <!-- Quick Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card total">
                            <i class="bi bi-bar-chart stat-icon"></i>
                            <div class="stat-value" id="statMonthlySales">₱ 1.2M</div>
                            <div class="stat-label">Monthly Sales</div>
                            <small class="d-block mt-2"><i class="bi bi-arrow-up-right"></i> 12.5% from last month</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card sales">
                            <i class="bi bi-cash-coin stat-icon"></i>
                            <div class="stat-value" id="statActiveAccounts">156</div>
                            <div class="stat-label">Active Accounts</div>
                            <small class="d-block mt-2"><i class="bi bi-arrow-up-right"></i> 8 new this month</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock stat-icon"></i>
                            <div class="stat-value" id="statPendingDeliveries">12</div>
                            <div class="stat-label">Pending Deliveries</div>
                            <small class="d-block mt-2">3 require immediate attention</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="statOrderAccuracy">98%</div>
                            <div class="stat-label">Order Accuracy</div>
                            <small class="d-block mt-2">Excellent performance</small>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions" id="quickActions">
                    <div class="quick-action-btn" onclick="showPage('transactions')">
                        <i class="bi bi-plus-circle"></i>
                        <div>New Transaction</div>
                    </div>
                    <div class="quick-action-btn" onclick="showPage('purchaseOrders')">
                        <i class="bi bi-file-text"></i>
                        <div>Create PO</div>
                    </div>
                    <div class="quick-action-btn" onclick="showPage('deliveries')">
                        <i class="bi bi-truck"></i>
                        <div>Schedule Delivery</div>
                    </div>
                    <div class="quick-action-btn" onclick="showPage('sales')">
                        <i class="bi bi-graph-up-arrow"></i>
                        <div>View Reports</div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Recent Transactions</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Reference</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recentTransactions">
                                        <tr>
                                            <td>2024-01-15</td>
                                            <td><span class="badge badge-success">Sale</span></td>
                                            <td>SALE-001245</td>
                                            <td>₱ 25,000</td>
                                            <td><span class="badge badge-success">Completed</span></td>
                                        </tr>
                                        <tr>
                                            <td>2024-01-15</td>
                                            <td><span class="badge badge-info">Purchase</span></td>
                                            <td>PO-001244</td>
                                            <td>₱ 45,000</td>
                                            <td><span class="badge badge-warning">Pending</span></td>
                                        </tr>
                                        <tr>
                                            <td>2024-01-14</td>
                                            <td><span class="badge badge-primary">Delivery</span></td>
                                            <td>DEL-001243</td>
                                            <td>₱ 18,750</td>
                                            <td><span class="badge badge-info">In Transit</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="chart-container">
                            <h5>Monthly Sales Trend</h5>
                            <canvas id="dashboardChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TRANSACTIONS PAGE -->
            <div id="transactionsContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-arrow-left-right me-2"></i>Transactions</h2>
                        <p>Manage all inventory transactions</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search transactions...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top" id="transactionsUserName">Admin User</span>
                                <span class="user-role-top" id="transactionsUserRole">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Transactions Demo</h5>
                        <p class="mb-0">This page demonstrates transaction management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" placeholder="Search transactions...">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select">
                                        <option>All Types</option>
                                        <option>Stock In</option>
                                        <option>Stock Out</option>
                                        <option>Transfer</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button class="btn btn-primary w-100">Filter</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="transactionsTotal">1,245</div>
                            <div class="stat-label">Total Transactions</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sales">
                            <div class="stat-value" id="transactionsStockIn">856</div>
                            <div class="stat-label">Stock In</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="transactionsStockOut">389</div>
                            <div class="stat-label">Stock Out</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="transactionsAccuracy">98.2%</div>
                            <div class="stat-label">Accuracy Rate</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5>Transaction History</h5>
                        <button class="btn btn-primary btn-sm" id="newTransactionBtn">
                            <i class="bi bi-plus-circle me-1"></i> New Transaction
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Reference No.</th>
                                    <th>Transaction Type</th>
                                    <th>Branch</th>
                                    <th>Item Code</th>
                                    <th>Quantity</th>
                                    <th>Encoded By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsTable">
                                <!-- Transactions will be loaded dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ITEMS PAGE -->
            <div id="itemsContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-box me-2"></i>Items</h2>
                        <p>Manage inventory items and products</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search items...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Items Management Demo</h5>
                        <p class="mb-0">This page demonstrates item management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Item Management</h5>
                                <button class="btn btn-primary" id="addItemBtn">
                                    <i class="bi bi-plus-circle me-1"></i> Add New Item
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="itemsActive">156</div>
                            <div class="stat-label">Active Items</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="itemsLowStock">12</div>
                            <div class="stat-label">Low Stock</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="itemsValue">₱ 2.5M</div>
                            <div class="stat-label">Inventory Value</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="itemsCategories">45</div>
                            <div class="stat-label">Categories</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Item List</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Current Stock</th>
                                    <th>Unit Price</th>
                                    <th>Warehouse</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTable">
                                <!-- Items will be loaded dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- WAREHOUSES PAGE -->
            <div id="warehousesContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-house-door me-2"></i>Warehouses</h2>
                        <p>Manage warehouse locations and inventory</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search warehouses...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Warehouses Management Demo</h5>
                        <p class="mb-0">This page demonstrates warehouse management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="warehousesTotal">8</div>
                            <div class="stat-label">Total Warehouses</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="warehousesValue">₱ 4.2M</div>
                            <div class="stat-label">Total Inventory Value</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="warehousesCapacity">92%</div>
                            <div class="stat-label">Capacity Used</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5>Warehouse List</h5>
                        <button class="btn btn-primary btn-sm" id="addWarehouseBtn">
                            <i class="bi bi-plus-circle me-1"></i> Add Warehouse
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Warehouse ID</th>
                                    <th>Warehouse Name</th>
                                    <th>Location</th>
                                    <th>Capacity</th>
                                    <th>Current Stock</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="warehousesTable">
                                <!-- Warehouses will be loaded dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- BRANCHES PAGE -->
            <div id="branchesContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-shop me-2"></i>Branches</h2>
                        <p id="branchesSubtitle">Manage branch locations and operations</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search branches...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Branches Management Demo</h5>
                        <p class="mb-0">This page demonstrates branch management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div id="branchesContentArea">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card total">
                                <div class="stat-value">12</div>
                                <div class="stat-label">Total Branches</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card sales">
                                <div class="stat-value">₱ 8.5M</div>
                                <div class="stat-label">Total Sales</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card customers">
                                <div class="stat-value">156</div>
                                <div class="stat-label">Active Accounts</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card complete">
                                <div class="stat-value">98%</div>
                                <div class="stat-label">Operational</div>
                            </div>
                        </div>
                    </div>

                    <div class="data-table">
                        <div class="table-header d-flex justify-content-between align-items-center">
                            <h5>Branch List</h5>
                            <button class="btn btn-primary btn-sm" id="addBranchBtn">
                                <i class="bi bi-plus-circle me-1"></i> Add Branch
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table custom-table">
                                <thead>
                                    <tr>
                                        <th>Branch ID</th>
                                        <th>Branch Name</th>
                                        <th>Location</th>
                                        <th>Manager</th>
                                        <th>Contact</th>
                                        <th>Sales (Monthly)</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="branchesTable">
                                    <!-- Branches will be loaded dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SALES REPORTS PAGE -->
            <div id="salesContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-graph-up-arrow me-2"></i>Sales Reports</h2>
                        <p>Sales analytics and performance insights</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search reports...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Sales Reports Demo</h5>
                        <p class="mb-0">This page demonstrates sales reporting features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="salesMonthly">₱ 1.24M</div>
                            <div class="stat-label">Monthly Sales</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card sales">
                            <div class="stat-value" id="salesUnits">2,450</div>
                            <div class="stat-label">Units Sold</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card customers">
                            <div class="stat-value" id="salesAccounts">156</div>
                            <div class="stat-label">Active Accounts</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="salesFulfillment">98.5%</div>
                            <div class="stat-label">Order Fulfillment</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-8">
                        <div class="chart-container">
                            <h5>Monthly Sales Trend</h5>
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="chart-container">
                            <h5>Top Selling Categories</h5>
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Top Selling Items - Current Month</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th>Quantity Sold</th>
                                    <th>Sales Value</th>
                                    <th>Growth</th>
                                </tr>
                            </thead>
                            <tbody id="salesTable">
                                <!-- Sales data will be loaded dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- PURCHASE ORDERS PAGE -->
            <div id="purchaseOrdersContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-file-text me-2"></i>Purchase Orders</h2>
                        <p>Manage vendor purchase orders</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search POs...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Purchase Orders Demo</h5>
                        <p class="mb-0">This page demonstrates purchase order management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card pending">
                            <div class="stat-value" id="poPending">5</div>
                            <div class="stat-label">Pending POs</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="poTotalValue">₱ 450K</div>
                            <div class="stat-label">Total PO Value</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="poCompleted">32</div>
                            <div class="stat-label">Completed POs</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5>Purchase Orders</h5>
                        <button class="btn btn-primary btn-sm" id="createPOBtn">
                            <i class="bi bi-plus-circle me-1"></i> Create PO
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>PO ID</th>
                                    <th>Vendor</th>
                                    <th>Est. Fulfillment Date</th>
                                    <th>Mode of Delivery</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Encoded At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="poTable">
                                <!-- Purchase orders will be loaded dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- DELIVERIES PAGE -->
            <div id="deliveriesContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-truck me-2"></i>Deliveries</h2>
                        <p>Manage delivery tracking and status</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search deliveries...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Deliveries Management Demo</h5>
                        <p class="mb-0">This page demonstrates delivery management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card delivery">
                            <div class="stat-value" id="deliveriesInTransit">8</div>
                            <div class="stat-label">In Transit</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card pending">
                            <div class="stat-value" id="deliveriesPending">3</div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <div class="stat-value" id="deliveriesCompleted">45</div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <div class="stat-value" id="deliveriesOnTime">98%</div>
                            <div class="stat-label">On-Time Rate</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5>Delivery Tracking</h5>
                        <button class="btn btn-primary btn-sm" id="createDeliveryBtn">
                            <i class="bi bi-plus-circle me-1"></i> Create Delivery
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Delivery ID</th>
                                    <th>Trip Ticket ID</th>
                                    <th>Invoice ID</th>
                                    <th>Status</th>
                                    <th>Driver</th>
                                    <th>Location</th>
                                    <th>Encoded At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="deliveriesTable">
                                <!-- Deliveries will be loaded dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- COMPANIES PAGE -->
            <div id="companiesContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-building me-2"></i>Companies</h2>
                        <p id="companiesSubtitle">Manage company information and details</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search companies...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Companies Management Demo</h5>
                        <p class="mb-0">This page demonstrates company management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div id="companiesContentArea">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card total">
                                <div class="stat-value">24</div>
                                <div class="stat-label">Total Companies</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card sales">
                                <div class="stat-value">8</div>
                                <div class="stat-label">Vendors</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card customers">
                                <div class="stat-value">156</div>
                                <div class="stat-label">Customers</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card complete">
                                <div class="stat-value">12</div>
                                <div class="stat-label">Partners</div>
                            </div>
                        </div>
                    </div>

                    <div class="data-table">
                        <div class="table-header d-flex justify-content-between align-items-center">
                            <h5>Company Directory</h5>
                            <button class="btn btn-primary btn-sm" id="addCompanyBtn">
                                <i class="bi bi-plus-circle me-1"></i> Add Company
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table custom-table">
                                <thead>
                                    <tr>
                                        <th>Company ID</th>
                                        <th>Company Name</th>
                                        <th>Address</th>
                                        <th>Email</th>
                                        <th>Contact Number</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="companiesTable">
                                    <!-- Companies will be loaded dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- USERS PAGE -->
            <div id="usersContent" class="page-content">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-people me-2"></i>Users</h2>
                        <p id="usersSubtitle">Manage system users and permissions</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search users...">
                        </div>
                        
                        <div class="user-profile-top">
                            <div class="user-avatar-top">AD</div>
                            <div class="user-details-top">
                                <span class="user-name-top">Admin User</span>
                                <span class="user-role-top">Administrator</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Reset Demo
                        </button>
                    </div>
                </div>

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Users Management Demo</h5>
                        <p class="mb-0">This page demonstrates user management features. All data shown is sample data for demonstration purposes.</p>
                    </div>
                </div>

                <div id="usersContentArea">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card total">
                                <div class="stat-value">45</div>
                                <div class="stat-label">Total Users</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card sales">
                                <div class="stat-value">8</div>
                                <div class="stat-label">Administrators</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card customers">
                                <div class="stat-value">32</div>
                                <div class="stat-label">Staff Users</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card complete">
                                <div class="stat-value">5</div>
                                <div class="stat-label">Branch Admins</div>
                            </div>
                        </div>
                    </div>

                    <div class="data-table">
                        <div class="table-header d-flex justify-content-between align-items-center">
                            <h5>User Management</h5>
                            <button class="btn btn-primary btn-sm" id="addUserBtn">
                                <i class="bi bi-plus-circle me-1"></i> Add User
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table custom-table">
                                <thead>
                                    <tr>
                                        <th>User ID</th>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>User Type</th>
                                        <th>Branch</th>
                                        <th>Last Login</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTable">
                                    <!-- Users will be loaded dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Global variables
        let currentUser = 'admin'; // Set to admin for demo
        let currentUserType = 'admin';
        let currentUserBranch = null;
        let allBranches = [
            { id: 'BR-001', name: 'Main Branch', location: 'Makati City' },
            { id: 'BR-002', name: 'North Branch', location: 'Quezon City' },
            { id: 'BR-003', name: 'South Branch', location: 'Muntinlupa City' },
            { id: 'BR-004', name: 'East Branch', location: 'Pasig City' },
            { id: 'BR-005', name: 'West Branch', location: 'Manila City' }
        ];

        // User database (for demo purposes)
        const users = {
            'admin': {
                password: 'admin123',
                fullName: 'Admin User',
                userType: 'admin',
                branch: null, // Admin has access to all branches
                avatar: 'AD'
            },
            'branch1': {
                password: 'branch123',
                fullName: 'Juan Dela Cruz',
                userType: 'branch_manager',
                branch: 'BR-001', // Main Branch
                avatar: 'JC'
            },
            'branch2': {
                password: 'branch123',
                fullName: 'Maria Santos',
                userType: 'branch_manager',
                branch: 'BR-002', // North Branch
                avatar: 'MS'
            },
            'staff1': {
                password: 'staff123',
                fullName: 'Pedro Reyes',
                userType: 'staff',
                branch: 'BR-001', // Main Branch
                avatar: 'PR'
            }
        };

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Inventory System Demo Mode loaded!");
            
            // Initialize Bootstrap components
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Setup mobile menu toggle
            document.getElementById('mobileMenuBtn').addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('active');
            });
            
            // Initialize charts
            initializeCharts();
            
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

            // Update user interface
            updateUserInterface();
            
            // Load initial data
            loadInitialData();
            
            // Load all page data
            loadAllDemoData();
        });

        // Logout Function - Now just refreshes the page for demo reset
        function logout() {
            if (confirm('Reset demo to initial state?')) {
                location.reload();
            }
        }

        // Update User Interface based on user type
        function updateUserInterface() {
            const user = users[currentUser];
            
            // Update user info in header
            document.getElementById('userName').textContent = user.fullName;
            document.getElementById('userRole').textContent = user.userType === 'admin' ? 'Administrator' : 'Branch Manager';
            document.getElementById('userAvatar').textContent = user.avatar;
            
            // Update all user info sections
            document.querySelectorAll('.user-name-top').forEach(el => el.textContent = user.fullName);
            document.querySelectorAll('.user-role-top').forEach(el => el.textContent = user.userType === 'admin' ? 'Administrator' : 'Branch Manager');
            document.querySelectorAll('.user-avatar-top').forEach(el => el.textContent = user.avatar);
            
            // Show/hide restricted pages for branch managers
            const restrictedPages = ['branches', 'companies', 'users'];
            restrictedPages.forEach(page => {
                const navItem = document.getElementById(page + 'Nav');
                if (navItem) {
                    if (user.userType === 'branch_manager') {
                        navItem.style.display = 'none';
                    } else {
                        navItem.style.display = 'block';
                    }
                }
            });
        }

        // Get branch name by ID
        function getBranchName(branchId) {
            const branch = allBranches.find(b => b.id === branchId);
            return branch ? branch.name : 'Unknown Branch';
        }

        // Load all demo data
        function loadAllDemoData() {
            loadTransactionsData();
            loadBranchesData();
            loadUsersData();
        }

        // Load initial data
        function loadInitialData() {
            updateDashboardStats();
            updateRecentTransactions();
        }

        // Update dashboard statistics
        function updateDashboardStats() {
            // Mock data for demo
            document.getElementById('statMonthlySales').textContent = '₱ 1.2M';
            document.getElementById('statActiveAccounts').textContent = '156';
            document.getElementById('statPendingDeliveries').textContent = '12';
            document.getElementById('statOrderAccuracy').textContent = '98%';
        }

        // Update recent transactions
        function updateRecentTransactions() {
            const transactionsTable = document.getElementById('recentTransactions');
            
            // Mock transactions data
            const transactions = [
                { date: '2024-01-15', type: 'Sale', ref: 'SALE-001245', amount: '₱ 25,000', status: 'Completed' },
                { date: '2024-01-15', type: 'Purchase', ref: 'PO-001244', amount: '₱ 45,000', status: 'Pending' },
                { date: '2024-01-14', type: 'Delivery', ref: 'DEL-001243', amount: '₱ 18,750', status: 'In Transit' }
            ];
            
            // Update table
            let html = '';
            transactions.forEach(transaction => {
                const typeClass = transaction.type === 'Sale' ? 'badge-success' : 
                                 transaction.type === 'Purchase' ? 'badge-info' : 'badge-primary';
                const statusClass = transaction.status === 'Completed' ? 'badge-success' :
                                   transaction.status === 'Pending' ? 'badge-warning' : 'badge-info';
                
                html += `
                    <tr>
                        <td>${transaction.date}</td>
                        <td><span class="badge ${typeClass}">${transaction.type}</span></td>
                        <td>${transaction.ref}</td>
                        <td>${transaction.amount}</td>
                        <td><span class="badge ${statusClass}">${transaction.status}</span></td>
                    </tr>
                `;
            });
            
            transactionsTable.innerHTML = html;
        }

        // Show Page Function
        function showPage(pageName) {
            // Hide all page contents
            const pages = [
                'dashboard', 'transactions', 'items', 'warehouses', 
                'branches', 'sales', 'purchaseOrders', 'deliveries', 
                'companies', 'users'
            ];
            
            pages.forEach(page => {
                const pageElement = document.getElementById(page + 'Content');
                if (pageElement) {
                    pageElement.classList.remove('active');
                    pageElement.style.display = 'none';
                }
            });
            
            // Show selected page
            const selectedPage = document.getElementById(pageName + 'Content');
            if (selectedPage) {
                selectedPage.classList.add('active');
                selectedPage.style.display = 'block';
                
                // Initialize page-specific content
                if (pageName === 'sales') {
                    initializeSalesCharts();
                }
            }
            
            // Update active nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Find and activate the clicked nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                const linkText = link.textContent.toLowerCase();
                if (linkText.includes(pageName.toLowerCase())) {
                    link.classList.add('active');
                }
            });
            
            // Hide sidebar on mobile after clicking a link
            if (window.innerWidth <= 992) {
                document.getElementById('sidebar').classList.remove('active');
            }
        }

        // Load transactions data
        function loadTransactionsData() {
            // Mock data for demo
            const transactions = [
                { date: '2024-01-15 10:30', ref: 'TRX-001245', type: 'Stock In', branch: 'BR-001', item: 'ITM-005', quantity: '+50', encodedBy: 'admin' },
                { date: '2024-01-15 09:15', ref: 'TRX-001244', type: 'Stock Out', branch: 'BR-002', item: 'ITM-012', quantity: '-25', encodedBy: 'staff1' },
                { date: '2024-01-14 16:45', ref: 'TRX-001243', type: 'Stock In', branch: 'BR-001', item: 'ITM-008', quantity: '+30', encodedBy: 'admin' },
                { date: '2024-01-14 14:20', ref: 'TRX-001242', type: 'Stock Out', branch: 'BR-003', item: 'ITM-003', quantity: '-15', encodedBy: 'staff1' },
                { date: '2024-01-13 11:10', ref: 'TRX-001241', type: 'Stock In', branch: 'BR-002', item: 'ITM-007', quantity: '+20', encodedBy: 'admin' }
            ];
            
            // Update stats
            document.getElementById('transactionsTotal').textContent = transactions.length;
            document.getElementById('transactionsStockIn').textContent = transactions.filter(t => t.type === 'Stock In').length;
            document.getElementById('transactionsStockOut').textContent = transactions.filter(t => t.type === 'Stock Out').length;
            document.getElementById('transactionsAccuracy').textContent = '98.2%';
            
            // Update table
            const table = document.getElementById('transactionsTable');
            let html = '';
            
            transactions.forEach(transaction => {
                const typeClass = transaction.type === 'Stock In' ? 'badge-success' : 'badge-danger';
                const quantityClass = transaction.quantity.startsWith('+') ? 'text-success' : 'text-danger';
                
                html += `
                    <tr>
                        <td>${transaction.date}</td>
                        <td>${transaction.ref}</td>
                        <td><span class="badge ${typeClass}">${transaction.type}</span></td>
                        <td>${getBranchName(transaction.branch)}</td>
                        <td>${transaction.item}</td>
                        <td class="${quantityClass}">${transaction.quantity}</td>
                        <td>${transaction.encodedBy}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
        }

        // Load branches data
        function loadBranchesData() {
            const table = document.getElementById('branchesTable');
            let html = '';
            
            allBranches.forEach(branch => {
                html += `
                    <tr>
                        <td><strong>${branch.id}</strong></td>
                        <td>${branch.name}</td>
                        <td>${branch.location}</td>
                        <td>Juan Dela Cruz</td>
                        <td>+63 912 345 6789</td>
                        <td>₱ 2.5M</td>
                        <td><span class="badge badge-success">Active</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
        }

        // Load users data
        function loadUsersData() {
            const table = document.getElementById('usersTable');
            let html = '';
            
            Object.keys(users).forEach(username => {
                const user = users[username];
                html += `
                    <tr>
                        <td><strong>USR-${username === 'admin' ? '001' : username === 'branch1' ? '002' : '003'}</strong></td>
                        <td>${user.fullName}</td>
                        <td>${username}</td>
                        <td>${username}@company.com</td>
                        <td><span class="badge ${user.userType === 'admin' ? 'badge-primary' : 'badge-info'}">${user.userType === 'admin' ? 'Administrator' : 'Branch Manager'}</span></td>
                        <td>${user.branch ? getBranchName(user.branch) : 'All Branches'}</td>
                        <td>2024-01-15 10:30</td>
                        <td><span class="badge badge-success">Active</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view"><i class="bi bi-eye"></i></button>
                                <button class="btn-icon btn-edit"><i class="bi bi-pencil"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            table.innerHTML = html;
        }

        // Initialize Charts
        function initializeCharts() {
            // Dashboard Chart
            const dashboardCtx = document.getElementById('dashboardChart');
            if (dashboardCtx) {
                new Chart(dashboardCtx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                        datasets: [{
                            label: 'Sales (₱)',
                            data: [850000, 920000, 1010000, 980000, 1120000, 1250000],
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱ ' + (value / 1000).toFixed(0) + 'K';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Initialize Sales Charts
        function initializeSalesCharts() {
            // Sales Chart
            const salesCtx = document.getElementById('salesChart');
            if (salesCtx) {
                new Chart(salesCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                        datasets: [{
                            label: 'Sales (₱)',
                            data: [285000, 312000, 298500, 350180],
                            backgroundColor: '#10b981',
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱ ' + (value / 1000).toFixed(0) + 'K';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Category Chart
            const categoryCtx = document.getElementById('categoryChart');
            if (categoryCtx) {
                new Chart(categoryCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Electronics', 'Office Supplies', 'Furniture', 'Others'],
                        datasets: [{
                            data: [45, 25, 20, 10],
                            backgroundColor: [
                                '#10b981',
                                '#059669',
                                '#047857',
                                '#d1fae5'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            // Close sidebar on mobile when resizing to desktop
            if (window.innerWidth > 992) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + D for dashboard
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                showPage('dashboard');
            }
            // Ctrl + R for reset
            else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                logout();
            }
        });
    </script>
</body>
</html>