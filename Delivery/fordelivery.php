<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For Delivery - Delivery Management</title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <style>
        /* MOBILE RESPONSIVE FEATURES FOR STAT CARDS */
        
        /* Tablets and small devices (768px and below) */
        @media (max-width: 768px) {
            /* 2 COLUMN LAYOUT */
            .delivery-stats .col-md-4 {
                flex: 0 0 50%;
                max-width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
            
            /* ADJUSTED PADDING AND MARGIN */
            .delivery-stats .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
            
            .delivery-stats .stat-card {
                padding: 12px 10px;
                margin-bottom: 8px;
                min-height: 85px;
            }
            
            /* RESPONSIVE TYPOGRAPHY */
            .delivery-stats .stat-icon {
                font-size: 2rem;
                margin-right: 12px;
            }
            
            .delivery-stats .stat-value {
                font-size: 1.5rem;
                line-height: 1.2;
            }
            
            .delivery-stats .stat-label {
                font-size: 0.8rem;
                line-height: 1.1;
            }
        }
        
        /* Extra small devices (phones, 576px and below) */
        @media (max-width: 576px) {
            /* COMPACT SIZING */
            .delivery-stats .col-md-4 {
                padding-left: 6px;
                padding-right: 6px;
            }
            
            .delivery-stats .row.g-3 {
                margin-left: -6px;
                margin-right: -6px;
            }
            
            .delivery-stats .stat-card {
                padding: 10px 8px;
                min-height: 80px;
            }
            
            .delivery-stats .stat-icon {
                font-size: 1.8rem;
                margin-right: 10px;
            }
            
            .delivery-stats .stat-value {
                font-size: 1.3rem;
            }
            
            .delivery-stats .stat-label {
                font-size: 0.75rem;
            }
        }
        
        /* Very small devices (375px and below) */
        @media (max-width: 375px) {
            .delivery-stats .stat-card {
                padding: 8px 6px;
                min-height: 75px;
            }
            
            .delivery-stats .stat-icon {
                font-size: 1.6rem;
                margin-right: 8px;
            }
            
            .delivery-stats .stat-value {
                font-size: 1.2rem;
            }
            
            .delivery-stats .stat-label {
                font-size: 0.7rem;
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
                <h3><i class="bi bi-box-seam logo-icon"></i> <span class="nav-text">Delivery</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="rejecteddelivery.php">
                            <i class="bi bi-exclamation-circle"></i>
                            <span class="nav-text">Rejected Delivery Advice</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section with User Info and Logout -->
            <div class="navbar-top">
                <div class="page-title">
                    <h2><i class="bi bi-truck me-2"></i>For Delivery</h2>
                    <p>Manage and track deliveries in progress</p>
                </div>
                
                <div class="user-info-top">
                    <div class="user-profile-top">
                        <div class="user-avatar-top" id="userAvatar">AD</div>
                        <div class="user-details-top">
                            <span class="user-name-top" id="userName">Driver User</span>
                            <span class="user-role-top" id="userRole">Delivery Driver</span>
                        </div>
                    </div>
                    
                    <button class="logout-btn-top" onclick="logout()">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </div>
            </div>

            <!-- Delivery Stats - Added delivery-stats class for specific targeting -->
            <div class="row g-3 mb-4 delivery-stats">

                <!-- Total for Delivery -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="stat-value">12</div>
                            <div class="stat-label">Total for Delivery</div>
                        </div>
                    </div>
                </div>

                <!-- In Transit -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-value">5</div>
                            <div class="stat-label">In Transit</div>
                        </div>
                    </div>
                </div>

                <!-- Completed Today -->
                <div class="col-md-4 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value">28</div>
                            <div class="stat-label">Completed Today</div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by order ID, customer name, address...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Preparing">Preparing</option>
                                <option value="Packed">Packed</option>
                                <option value="En-route">En-route</option>
                                <option value="Delivered">Delivered</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trip Tickets Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Name</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge bg-light text-dark">ORD-001</span></td>
                                <td>John Doe</td>
                                <td>123 Main St, New York, NY 10001</td>
                                <td>(555) 123-4567</td>
                                <td>
                                    <small class="d-block">Laptop Computer (1)</small>
                                    <small class="d-block text-muted">Office Chair (2)</small>
                                </td>
                                <td><span class="badge bg-warning">Preparing</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('ORD-001')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" title="Mark as Packed" onclick="updateStatus(this, 'Packed')">
                                        <i class="bi bi-box-seam"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">ORD-002</span></td>
                                <td>Jane Smith</td>
                                <td>456 Oak Ave, Los Angeles, CA 90001</td>
                                <td>(555) 987-6543</td>
                                <td>
                                    <small class="d-block">Desk Lamp (5)</small>
                                    <small class="d-block text-muted">USB-C Cable (10)</small>
                                </td>
                                <td><span class="badge bg-info">Packed</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('ORD-002')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-primary" title="Mark as En-route" onclick="updateStatus(this, 'En-route')">
                                        <i class="bi bi-truck"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">ORD-003</span></td>
                                <td>ABC Corporation</td>
                                <td>789 Business Blvd, Chicago, IL 60601</td>
                                <td>(555) 444-7890</td>
                                <td>
                                    <small class="d-block">Wireless Mouse (8)</small>
                                    <small class="d-block text-muted">Desk Organizer (4)</small>
                                </td>
                                <td><span class="badge bg-primary">En-route</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('ORD-003')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" title="Mark as Delivered" onclick="showPaymentModal('ORD-003')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">ORD-004</span></td>
                                <td>Michael Johnson</td>
                                <td>321 Pine Rd, Houston, TX 77001</td>
                                <td>(555) 555-5555</td>
                                <td>
                                    <small class="d-block">Coffee Maker (1)</small>
                                    <small class="d-block text-muted">Desk Lamp (3)</small>
                                </td>
                                <td><span class="badge bg-primary">En-route</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('ORD-004')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" title="Mark as Delivered" onclick="showPaymentModal('ORD-004')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-light text-dark">ORD-005</span></td>
                                <td>Sarah Williams</td>
                                <td>654 Elm St, Phoenix, AZ 85001</td>
                                <td>(555) 222-3333</td>
                                <td>
                                    <small class="d-block">Notebook Set (5)</small>
                                </td>
                                <td><span class="badge bg-warning">Preparing</span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('ORD-005')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" title="Mark as Packed" onclick="updateStatus(this, 'Packed')">
                                        <i class="bi bi-box-seam"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Modal for Delivered Packages -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delivery Completion & Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="paymentForm">
                        <div class="alert alert-info">
                            <strong id="orderIdDisplay">ORD-001</strong> - Delivery Confirmation Required
                        </div>

                        <h6 class="mb-3">Photo Documentation</h6>
                        <div class="mb-3">
                            <label class="form-label">Upload Proof of Delivery Photo</label>
                            <input type="file" class="form-control" id="proofPhoto" accept="image/*" required>
                            <small class="text-muted">Please upload a photo showing the delivered package at the delivery location</small>
                        </div>

                        <hr>

                        <h6 class="mb-3">Payment Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" id="paymentMethod" required>
                                    <option value="">-- Select Method --</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Card">Card</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount Received</label>
                                <input type="number" class="form-control" id="amountReceived" required step="0.01" placeholder="0.00">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Reference/Transaction ID</label>
                            <input type="text" class="form-control" id="transactionRef" placeholder="e.g., Check number, transaction ID">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Customer Signature/Notes</label>
                            <textarea class="form-control" id="signatureNotes" rows="3" placeholder="Any notes from customer, signature confirmation, etc."></textarea>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmDelivery" required>
                            <label class="form-check-label" for="confirmDelivery">
                                I confirm this delivery has been completed successfully
                            </label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitPayment()">Confirm Delivery</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentOrderId = null;

        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Status filter
        document.getElementById('statusFilter').addEventListener('change', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const status = row.cells[5].textContent.toLowerCase();
                row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
            });
        });

        // View delivery details
        function viewDelivery(orderId) {
            alert('Viewing details for ' + orderId);
        }

        // Update delivery status
        function updateStatus(button, newStatus) {
            const row = button.closest('tr');
            const orderCell = row.cells[0];
            const statusCell = row.cells[5];
            const actionCell = row.cells[6];

            // Update status badge
            let badgeClass = 'bg-warning';
            if (newStatus === 'Packed') badgeClass = 'bg-info';
            if (newStatus === 'En-route') badgeClass = 'bg-primary';
            if (newStatus === 'Delivered') badgeClass = 'bg-success';

            statusCell.innerHTML = `<span class="badge ${badgeClass}">${newStatus}</span>`;

            // Update action buttons
            if (newStatus === 'Packed') {
                actionCell.innerHTML = `
                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('${orderCell.textContent}')">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-primary" title="Mark as En-route" onclick="updateStatus(this, 'En-route')">
                        <i class="bi bi-truck"></i>
                    </button>
                `;
            } else if (newStatus === 'En-route') {
                actionCell.innerHTML = `
                    <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('${orderCell.textContent}')">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-success" title="Mark as Delivered" onclick="showPaymentModal('${orderCell.textContent}')">
                        <i class="bi bi-check-lg"></i>
                    </button>
                `;
            }

            alert(`Order ${orderCell.textContent.trim()} status updated to ${newStatus}`);
        }

        // Show payment modal
        function showPaymentModal(orderId) {
            currentOrderId = orderId;
            document.getElementById('orderIdDisplay').textContent = orderId;
            document.getElementById('paymentForm').reset();
            const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
            modal.show();
        }

        // Submit payment
        function submitPayment() {
            const photo = document.getElementById('proofPhoto').value;
            const method = document.getElementById('paymentMethod').value;
            const amount = document.getElementById('amountReceived').value;
            const confirm = document.getElementById('confirmDelivery').checked;

            if (!photo || !method || !amount || !confirm) {
                alert('Please fill in all required fields');
                return;
            }

            // Mark as delivered
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const orderCell = row.cells[0];
                if (orderCell.textContent.includes(currentOrderId)) {
                    row.cells[5].innerHTML = `<span class="badge bg-success">Delivered</span>`;
                    row.cells[6].innerHTML = `
                        <button class="btn btn-sm btn-info" title="View" onclick="viewDelivery('${currentOrderId}')">
                            <i class="bi bi-eye"></i>
                        </button>
                    `;
                }
            });

            bootstrap.Modal.getInstance(document.getElementById('paymentModal')).hide();
            alert(`Delivery ${currentOrderId} completed successfully! Payment recorded.`);
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../login.php';
            }
        }
    </script>
</body>
</html>