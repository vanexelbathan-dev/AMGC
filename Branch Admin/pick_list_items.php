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
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* Main Container */
        .page-content.active {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Pick List Item Cards - IMPROVED LAYOUT */
        .pick-list-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            position: relative;
            min-height: 200px;
            display: flex;
            flex-direction: column;
        }
        
        .pick-list-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }
        
        /* Card Header */
        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            gap: 15px;
        }
        
        .card-left-header {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex: 1;
        }
        
        .so-id {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        /* Warehouse Action Buttons - FIXED POSITION */
        .warehouse-actions {
            display: flex;
            gap: 8px;
            flex-direction: column;
            align-items: flex-end;
            min-width: 130px;
        }
        
        .warehouse-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            width: 100%;
            justify-content: center;
        }
        
        .btn-send-warehouse {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .btn-send-warehouse:hover {
            background: rgba(59, 130, 246, 0.2);
        }
        
        .btn-generate-invoice {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .btn-generate-invoice:hover {
            background: rgba(16, 185, 129, 0.2);
        }
        
        .btn-generate-ticket {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .btn-generate-ticket:hover {
            background: rgba(245, 158, 11, 0.2);
        }
        
        .btn-disabled {
            background: #f3f4f6;
            color: #9ca3af;
            border: 1px solid #e5e7eb;
            cursor: not-allowed;
        }
        
        /* Warehouse Status Badge */
        .warehouse-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            vertical-align: middle;
        }
        
        .warehouse-pending {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        
        .warehouse-ready {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .warehouse-shipped {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }
        
        .warehouse-delivered {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        
        /* Item Details */
        .item-details {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .item-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 1rem;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .item-code {
            color: #6b7280;
            font-size: 0.875rem;
            margin-left: 5px;
        }
        
        /* Quantities Section */
        .quantities {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .quantity-badge {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 15px;
            background: #f8fafc;
            border-radius: 8px;
            min-width: 80px;
            border: 1px solid #e5e7eb;
        }
        
        .quantity-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .quantity-value {
            font-weight: 700;
            font-size: 1.25rem;
            color: #059669;
        }
        
        /* Item Meta Information */
        .item-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .item-meta {
            font-size: 0.875rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .item-meta i {
            margin-right: 4px;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
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
        
        /* Additional Info */
        .additional-info {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #6b7280;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        
        .additional-info div {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Checkbox Styling */
        .form-check-input {
            cursor: pointer;
            width: 18px;
            height: 18px;
            margin-top: 0;
        }
        
        .form-check-input:checked {
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        
        /* Progress Steps */
        .progress-steps {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 1.5rem 0;
            position: relative;
            padding: 0 20px;
        }
        
        .progress-steps::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 20px;
            right: 20px;
            height: 2px;
            background: #e5e7eb;
            z-index: 1;
        }
        
        .progress-step {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }
        
        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 2px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }
        
        .step-icon.active {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
        }
        
        .step-icon.completed {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }
        
        .step-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-align: center;
            white-space: nowrap;
        }
        
        .step-label.active {
            color: #3b82f6;
            font-weight: 600;
        }
        
        /* Batch Actions */
        .batch-actions {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: none;
        }
        
        .batch-actions-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .selected-count {
            font-weight: 600;
            color: #3b82f6;
            font-size: 1rem;
        }
        
        .batch-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* Action Bar */
        .action-bar {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .action-bar-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .action-left {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .action-right {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* Sort Controls */
        .sort-controls {
            display: flex;
            gap: 8px;
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
            white-space: nowrap;
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
        
        /* Stats Cards */
        .stat-card {
            padding: 1.25rem;
            border-radius: 12px;
            background: white;
            border: 1px solid #e5e7eb;
            height: 100%;
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #e5e7eb;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #e5e7eb;
        }
        
       .stat-card.total { background: linear-gradient(135deg, var(--dark-green), var(--primary-green)); }
        .stat-card.sales { background: linear-gradient(135deg, #059669, #10b981); }
        .stat-card.customers { background: linear-gradient(135deg, #047857, #059669); }
        .stat-card.pending { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .stat-card.delivery { background: linear-gradient(135deg, #0891b2, #22d3ee); }
        .stat-card.complete { background: linear-gradient(135deg, #065f46, #047857); }
        .stat-card.inventory { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }

        
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
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            display: none;
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
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
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
        
        .btn-outline-secondary {
            border: 1px solid #6b7280;
            color: #6b7280;
            background: white;
        }
        
        .btn-outline-secondary:hover {
            background: #6b7280;
            color: white;
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
        
        .btn-danger {
            background: #ef4444;
            color: white;
            border: 1px solid #ef4444;
        }
        
        .btn-danger:hover {
            background: #dc2626;
            border-color: #dc2626;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .page-content.active {
                padding: 15px;
            }
        }
        
        @media (max-width: 992px) {
            .progress-steps {
                flex-wrap: wrap;
                gap: 20px;
            }
            
            .progress-steps::before {
                display: none;
            }
            
            .progress-step {
                flex: 0 0 calc(33.333% - 20px);
            }
            
            .card-header-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .warehouse-actions {
                width: 100%;
                flex-direction: row;
                justify-content: flex-start;
            }
        }
        
        @media (max-width: 768px) {
            .progress-step {
                flex: 0 0 calc(50% - 20px);
            }
            
            .action-bar-content,
            .batch-actions-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-left,
            .action-right,
            .batch-buttons {
                width: 100%;
                justify-content: center;
            }
            
            .sort-controls {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .quantities {
                justify-content: center;
            }
            
            .item-meta-row {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .item-meta {
                justify-content: center;
            }
            
            .status-badge {
                align-self: center;
            }
            
            .stat-card {
                margin-bottom: 15px;
            }
        }
        
        @media (max-width: 576px) {
            .progress-step {
                flex: 0 0 100%;
            }
            
            .warehouse-actions {
                flex-direction: column;
            }
            
            .warehouse-btn {
                width: 100%;
            }
            
            .action-right .btn {
                width: 100%;
                justify-content: center;
            }
            
            .batch-buttons {
                flex-direction: column;
            }
            
            .batch-buttons .btn {
                width: 100%;
            }
            
            .sort-controls {
                flex-direction: column;
                width: 100%;
            }
            
            .sort-btn {
                width: 100%;
                text-align: center;
            }
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
                <!-- Navbar Top -->
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-list-check me-2"></i>Pick List Items</h2>
                        <p>Manage pick list items and send to warehouse for fulfillment</p>
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
                                <span class="user-role-top">Warehouse Manager</span>
                            </div>
                        </div>
                        
                        <button class="logout-btn-top" onclick="logout()">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
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

                <!-- Fulfillment Progress -->
                <div class="progress-steps" id="fulfillmentProgress">
                    <div class="progress-step">
                        <div class="step-icon completed" id="step1">
                            <i class="bi bi-cart-check"></i>
                        </div>
                        <div class="step-label active">Sales Order Created</div>
                    </div>
                    <div class="progress-step">
                        <div class="step-icon active" id="step2">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <div class="step-label active">Pick List Generated</div>
                    </div>
                    <div class="progress-step">
                        <div class="step-icon" id="step3">
                            <i class="bi bi-arrow-right-circle"></i>
                        </div>
                        <div class="step-label">Send to Warehouse</div>
                    </div>
                    <div class="progress-step">
                        <div class="step-icon" id="step4">
                            <i class="bi bi-file-text"></i>
                        </div>
                        <div class="step-label">Generate Invoice</div>
                    </div>
                    <div class="progress-step">
                        <div class="step-icon" id="step5">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="step-label">Create Trip Ticket</div>
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
            document.getElementById('searchItems').addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    filterItems(e.target.value);
                }, 300);
            });

            // Add select all functionality
            document.getElementById('selectAllItems').addEventListener('change', function(e) {
                const isChecked = e.target.checked;
                pickListItems.forEach(item => {
                    item.isSelected = isChecked;
                });
                updateSelection();
                renderItems();
            });

            // Initialize with current date/time
            const now = new Date();
            const formattedDateTime = now.toISOString().slice(0, 16);
            document.getElementById('encodedAt').value = formattedDateTime;

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
                    document.getElementById('searchItems').focus();
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
                    selectAll.checked = !selectAll.checked;
                    selectAll.dispatchEvent(new Event('change'));
                }
            });
        });

        // Enhanced renderItems function with improved layout
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
            
            document.getElementById('selectedCount').textContent = selectedCount;
            
            const batchActions = document.getElementById('batchActions');
            const selectAll = document.getElementById('selectAllItems');
            
            if (selectedCount > 0) {
                batchActions.style.display = 'block';
                selectAll.checked = selectedCount === pickListItems.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < pickListItems.length;
            } else {
                batchActions.style.display = 'none';
                selectAll.checked = false;
                selectAll.indeterminate = false;
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
            
            document.getElementById('totalItems').textContent = totalItems;
            document.getElementById('warehouseReady').textContent = warehouseReady;
            document.getElementById('inTransit').textContent = inTransit;
            document.getElementById('delivered').textContent = delivered;
        }

        // Update progress steps
        function updateProgressSteps() {
            const hasReady = pickListItems.some(item => item.warehouseStatus === 'ready');
            const hasShipped = pickListItems.some(item => item.warehouseStatus === 'shipped');
            const hasDelivered = pickListItems.some(item => item.warehouseStatus === 'delivered');
            
            document.getElementById('step3').classList.toggle('active', hasReady);
            document.getElementById('step4').classList.toggle('active', hasShipped);
            document.getElementById('step5').classList.toggle('active', hasDelivered);
            document.getElementById('step4').classList.toggle('completed', hasDelivered);
            document.getElementById('step5').classList.toggle('completed', hasDelivered);
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
            if (confirm('Are you sure you want to logout?')) {
                showNotification('Logged out successfully', 'info');
                setTimeout(() => {
                    alert('Redirecting to login page...');
                }, 1000);
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

        // Initialize app
        function initApp() {
            console.log('Pick List Management System initialized');
        }

        // Call initialization
        initApp();
    </script>
</body>
</html>