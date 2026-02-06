<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejected Delivery Advice - Delivery Management</title>
    <link rel="stylesheet" href="../css/style.css">
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
                <h3><i class="bi bi-box-seam logo-icon"></i> <span class="nav-text">Delivery</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="rejecteddelivery.php">
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
                    <h2><i class="bi bi-exclamation-circle me-2"></i>Rejected Delivery Advice</h2>
                    <p>Report and document rejected deliveries</p>
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
<!-- Rejected Delivery Stats -->
<div class="row g-3 mb-4">

    <!-- Total Rejected -->
    <div class="col-md-4 mb-3">
        <div class="stat-card sales">
            <div class="stat-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="stat-value">8</div>
                <div class="stat-label">Total Rejected</div>
            </div>
        </div>
    </div>

    <!-- Pending Resolution -->
    <div class="col-md-4 mb-3">
        <div class="stat-card pending">
            <div class="stat-icon">
                <i class="bi bi-clock"></i>
            </div>
            <div>
                <div class="stat-value">3</div>
                <div class="stat-label">Pending Resolution</div>
            </div>
        </div>
    </div>

    <!-- Resolved -->
    <div class="col-md-4 mb-3">
        <div class="stat-card inventory">
            <div class="stat-icon">
                <i class="bi bi-info-circle"></i>
            </div>
            <div>
                <div class="stat-value">5</div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>
    </div>

</div>


            <!-- Rejected Delivery Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Report Rejected Delivery</h5>
                </div>
                <div class="card-body">
                    <form id="rejectedDeliveryForm" onsubmit="submitRejectedDelivery(event)">
                        <!-- Order Information Section -->
                        <h6 class="mb-3"><i class="bi bi-box-seam me-2"></i>Order Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Order ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="rejOrderId" required placeholder="e.g., ORD-001">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Delivery Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="rejDeliveryDate" required>
                            </div>
                        </div>

                        <hr>

                        <!-- Customer Information Section -->
                        <h6 class="mb-3"><i class="bi bi-person-check me-2"></i>Customer Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="rejCustomerName" required placeholder="Full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="rejContactNumber" required placeholder="(555) 000-0000">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Delivery Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejDeliveryAddress" required rows="2" placeholder="Full delivery address"></textarea>
                        </div>

                        <hr>

                        <!-- Rejection Reason Section -->
                        <h6 class="mb-3"><i class="bi bi-exclamation-diamond me-2"></i>Rejection Reason</h6>
                        <div class="mb-3">
                            <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                            <select class="form-select" id="rejReason" required onchange="handleReasonChange()">
                                <option value="">-- Select Reason --</option>
                                <option value="Customer Not Available">Customer Not Available</option>
                                <option value="Address Not Found">Address Not Found</option>
                                <option value="Customer Refused">Customer Refused</option>
                                <option value="Wrong Address">Wrong Address</option>
                                <option value="Damaged Package">Damaged Package</option>
                                <option value="Security Concern">Security Concern</option>
                                <option value="Other">Other (Please Specify)</option>
                            </select>
                        </div>

                        <div class="mb-3" id="otherReasonDiv" style="display: none;">
                            <label class="form-label">Please Specify <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="otherReason" placeholder="Specify the rejection reason">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Detailed Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejDescription" required rows="3" placeholder="Provide detailed information about the rejection..."></textarea>
                        </div>

                        <hr>

                        <!-- Resolution Section -->
                        <h6 class="mb-3"><i class="bi bi-arrow-clockwise me-2"></i>Resolution Actions</h6>
                        <div class="mb-3">
                            <label class="form-label">Proposed Action <span class="text-danger">*</span></label>
                            <select class="form-select" id="rejAction" required>
                                <option value="">-- Select Action --</option>
                                <option value="Return to Warehouse">Return to Warehouse</option>
                                <option value="Retry Delivery">Retry Delivery</option>
                                <option value="Contact Customer">Contact Customer for Arrangement</option>
                                <option value="Hold for Pickup">Hold for Customer Pickup</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Scheduled Retry Date (if applicable)</label>
                            <input type="date" class="form-control" id="rejRetryDate">
                        </div>

                        <hr>

                        <!-- Photo Documentation Section -->
                        <h6 class="mb-3"><i class="bi bi-camera me-2"></i>Photo Documentation</h6>
                        <div class="mb-3">
                            <label class="form-label">Upload Photo of Rejected Package/Location</label>
                            <input type="file" class="form-control" id="rejPhoto" accept="image/*">
                            <small class="text-muted">Please upload a photo showing the package and/or the delivery location</small>
                        </div>

                        <hr>

                        <!-- Driver Information Section -->
                        <h6 class="mb-3"><i class="bi bi-person-badge me-2"></i>Driver Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="rejDriverName" required placeholder="Your name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="rejDriverId" required placeholder="Your driver ID">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Driver Contact Number <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="rejDriverContact" required placeholder="(555) 000-0000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="rejAdditionalNotes" rows="2" placeholder="Any additional notes or observations..."></textarea>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="rejConfirm" required>
                            <label class="form-check-label" for="rejConfirm">
                                I confirm that all information provided is accurate and true to the best of my knowledge
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>Submit Rejection Report
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Clear Form
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Set today's date as default for delivery date
        document.getElementById('rejDeliveryDate').valueAsDate = new Date();

        // Handle reason change
        function handleReasonChange() {
            const reason = document.getElementById('rejReason').value;
            const otherDiv = document.getElementById('otherReasonDiv');
            
            if (reason === 'Other') {
                otherDiv.style.display = 'block';
                document.getElementById('otherReason').required = true;
            } else {
                otherDiv.style.display = 'none';
                document.getElementById('otherReason').required = false;
            }
        }

        // Submit rejected delivery form
        function submitRejectedDelivery(event) {
            event.preventDefault();

            const orderId = document.getElementById('rejOrderId').value;
            const customerName = document.getElementById('rejCustomerName').value;
            const reason = document.getElementById('rejReason').value;
            const driverName = document.getElementById('rejDriverName').value;

            // Validate form
            if (!orderId || !customerName || !reason || !driverName) {
                alert('Please fill in all required fields');
                return;
            }

            // Show success message
            alert(`Rejection report for Order ${orderId} has been submitted successfully!\n\nDriver: ${driverName}\nCustomer: ${customerName}\nReason: ${reason}`);

            // Reset form
            document.getElementById('rejectedDeliveryForm').reset();
            
            // Reset the other reason field visibility
            document.getElementById('otherReasonDiv').style.display = 'none';
            
            // Reset date fields
            document.getElementById('rejDeliveryDate').valueAsDate = new Date();
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
