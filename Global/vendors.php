<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Vendors Management</title>
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
                <h3><i class="bi bi-globe logo-icon"></i> <span class="nav-text">Global</span></h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="vendors.php">
                            <i class="bi bi-shop"></i>
                            <span class="nav-text">Vendors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="company.php">
                            <i class="bi bi-building"></i>
                            <span class="nav-text">Company</span>
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
            <!-- VENDORS PAGE -->
            <div id="vendorsContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-shop me-2"></i>Vendors Management</h2>
                        <p>Manage all vendor and supplier information</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search vendors..." id="searchVendors">
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

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Vendor Management</h5>
                                <button class="btn btn-primary" id="addVendorBtn">
                                    <i class="bi bi-plus-circle me-1"></i> Add New Vendor
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalVendors">0</div>
                            <div class="stat-label">Total Vendors</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeVendors">0</div>
                            <div class="stat-label">Active Vendors</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="vendorsWithPO">0</div>
                            <div class="stat-label">Vendors with PO</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Vendor List</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Vendor Name</th>
                                    <th>Contact Person</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="vendorsTable">
                                <tr>
                                    <td colspan="7" class="text-center py-4">Loading vendors...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Add/Edit Vendor -->
    <div class="modal fade" id="vendorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="vendorModalLabel">Add Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="vendorForm">
                        <div class="mb-3">
                            <label for="vendorName" class="form-label">Vendor Name</label>
                            <input type="text" class="form-control" id="vendorName" required>
                        </div>
                        <div class="mb-3">
                            <label for="contactPerson" class="form-label">Contact Person</label>
                            <input type="text" class="form-control" id="contactPerson">
                        </div>
                        <div class="mb-3">
                            <label for="vendorEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="vendorEmail">
                        </div>
                        <div class="mb-3">
                            <label for="vendorPhone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="vendorPhone">
                        </div>
                        <div class="mb-3">
                            <label for="vendorAddress" class="form-label">Address</label>
                            <textarea class="form-control" id="vendorAddress" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="vendorStatus" class="form-label">Status</label>
                            <select class="form-select" id="vendorStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveVendor()">Save Vendor</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        });

        // Logout function
        function logout() {
            alert('Logging out...');
            window.location.href = '../login.php';
        }

        // Load vendors from database
        async function loadVendors() {
            try {
                const response = await fetch('api/get_vendors.php');
                const data = await response.json();
                
                if (data.success) {
                    const vendors = data.data || [];
                    displayVendors(vendors);
                    updateStats(vendors);
                } else {
                    console.log('No vendors found or error occurred');
                    displayVendors([]);
                }
            } catch (error) {
                console.error('Error loading vendors:', error);
                displayVendors([]);
            }
        }

        // Display vendors in table
        function displayVendors(vendors) {
            const tbody = document.getElementById('vendorsTable');
            
            if (vendors.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">No vendors found</td></tr>';
                return;
            }

            tbody.innerHTML = vendors.map(vendor => `
                <tr>
                    <td>${vendor.id}</td>
                    <td>${vendor.vendor_name}</td>
                    <td>${vendor.contact_person || '-'}</td>
                    <td>${vendor.email || '-'}</td>
                    <td>${vendor.phone || '-'}</td>
                    <td><span class="badge ${vendor.status === 'active' ? 'badge-success' : 'badge-warning'}">${vendor.status || 'active'}</span></td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="editVendor(${vendor.id})">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteVendor(${vendor.id})">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Update statistics
        function updateStats(vendors) {
            document.getElementById('totalVendors').textContent = vendors.length;
            const activeVendors = vendors.filter(v => v.status === 'active' || !v.status).length;
            document.getElementById('activeVendors').textContent = activeVendors;
            document.getElementById('vendorsWithPO').textContent = vendors.length > 0 ? Math.floor(vendors.length * 0.6) : 0;
        }

        // Add new vendor button
        document.getElementById('addVendorBtn').addEventListener('click', function() {
            document.getElementById('vendorForm').reset();
            document.getElementById('vendorModalLabel').textContent = 'Add Vendor';
            new bootstrap.Modal(document.getElementById('vendorModal')).show();
        });

        // Save vendor
        async function saveVendor() {
            const vendorName = document.getElementById('vendorName').value;
            const contactPerson = document.getElementById('contactPerson').value;
            const vendorEmail = document.getElementById('vendorEmail').value;
            const vendorPhone = document.getElementById('vendorPhone').value;
            const vendorAddress = document.getElementById('vendorAddress').value;
            const vendorStatus = document.getElementById('vendorStatus').value;

            if (!vendorName.trim()) {
                alert('Please enter vendor name');
                return;
            }

            try {
                const response = await fetch('api/save_vendor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        vendor_name: vendorName,
                        contact_person: contactPerson,
                        email: vendorEmail,
                        phone: vendorPhone,
                        address: vendorAddress,
                        status: vendorStatus
                    })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Vendor saved successfully');
                    bootstrap.Modal.getInstance(document.getElementById('vendorModal')).hide();
                    loadVendors();
                } else {
                    alert('Error saving vendor: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error saving vendor:', error);
                alert('Error saving vendor');
            }
        }

        // Edit vendor
        async function editVendor(id) {
            try {
                const response = await fetch(`api/get_vendor.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const vendor = data.data;
                    document.getElementById('vendorName').value = vendor.vendor_name;
                    document.getElementById('contactPerson').value = vendor.contact_person || '';
                    document.getElementById('vendorEmail').value = vendor.email || '';
                    document.getElementById('vendorPhone').value = vendor.phone || '';
                    document.getElementById('vendorAddress').value = vendor.address || '';
                    document.getElementById('vendorStatus').value = vendor.status || 'active';
                    document.getElementById('vendorModalLabel').textContent = 'Edit Vendor';
                    new bootstrap.Modal(document.getElementById('vendorModal')).show();
                }
            } catch (error) {
                console.error('Error loading vendor:', error);
            }
        }

        // Delete vendor
        async function deleteVendor(id) {
            if (!confirm('Are you sure you want to delete this vendor?')) return;

            try {
                const response = await fetch('api/delete_vendor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: id })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Vendor deleted successfully');
                    loadVendors();
                } else {
                    alert('Error deleting vendor');
                }
            } catch (error) {
                console.error('Error deleting vendor:', error);
                alert('Error deleting vendor');
            }
        }

        // Search vendors
        document.getElementById('searchVendors').addEventListener('keyup', async function(e) {
            const searchTerm = e.target.value.toLowerCase();
            try {
                const response = await fetch(`api/search_vendors.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                displayVendors(data.data || []);
            } catch (error) {
                console.error('Error searching vendors:', error);
            }
        });

        // Load vendors on page load
        loadVendors();
    </script>
</body>
</html>
