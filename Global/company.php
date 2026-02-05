<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Company Management</title>
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
                        <a class="nav-link" href="vendors.php">
                            <i class="bi bi-shop"></i>
                            <span class="nav-text">Vendors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="company.php">
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
            <!-- COMPANY PAGE -->
            <div id="companyContent" class="page-content active">
                <div class="navbar-top">
                    <div class="page-title">
                        <h2><i class="bi bi-building me-2"></i>Company Management</h2>
                        <p>Manage all company information and details</p>
                    </div>
                    
                    <div class="user-info-top">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search companies..." id="searchCompany">
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

                <!-- Demo Mode Info -->
                <div class="demo-info-card mb-4">
                    <div class="demo-info-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="demo-info-content">
                        <h5>Company Management</h5>
                        <p class="mb-0">Manage company information, contacts, and addresses from the global database.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Companies</h5>
                                <button class="btn btn-primary" id="addCompanyBtn">
                                    <i class="bi bi-plus-circle me-1"></i> Add New Company
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalCompanies">0</div>
                            <div class="stat-label">Total Companies</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeCompanies">0</div>
                            <div class="stat-label">Active Companies</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="companiesWithContact">0</div>
                            <div class="stat-label">With Contact Info</div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Company List</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Company Name</th>
                                    <th>Address</th>
                                    <th>Email</th>
                                    <th>Contact No.</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="companyTable">
                                <tr>
                                    <td colspan="6" class="text-center py-4">Loading companies...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Add/Edit Company -->
    <div class="modal fade" id="companyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="companyModalLabel">Add Company</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="companyForm">
                        <div class="mb-3">
                            <label for="companyName" class="form-label">Company Name</label>
                            <input type="text" class="form-control" id="companyName" required>
                        </div>
                        <div class="mb-3">
                            <label for="companyAddress" class="form-label">Address</label>
                            <textarea class="form-control" id="companyAddress" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="companyEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="companyEmail">
                        </div>
                        <div class="mb-3">
                            <label for="companyContact" class="form-label">Contact No.</label>
                            <input type="text" class="form-control" id="companyContact">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveCompany()">Save Company</button>
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

        // Load companies from database
        async function loadCompanies() {
            try {
                const response = await fetch('api/get_companies.php');
                const data = await response.json();
                
                if (data.success) {
                    const companies = data.data || [];
                    displayCompanies(companies);
                    updateStats(companies);
                } else {
                    console.log('No companies found or error occurred');
                    displayCompanies([]);
                }
            } catch (error) {
                console.error('Error loading companies:', error);
                displayCompanies([]);
            }
        }

        // Display companies in table
        function displayCompanies(companies) {
            const tbody = document.getElementById('companyTable');
            
            if (companies.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">No companies found</td></tr>';
                return;
            }

            tbody.innerHTML = companies.map(company => `
                <tr>
                    <td>${company.id}</td>
                    <td>${company.company_name}</td>
                    <td>${company.address || '-'}</td>
                    <td>${company.email || '-'}</td>
                    <td>${company.contact_no || '-'}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="editCompany(${company.id})">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteCompany(${company.id})">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Update statistics
        function updateStats(companies) {
            document.getElementById('totalCompanies').textContent = companies.length;
            document.getElementById('activeCompanies').textContent = companies.length;
            document.getElementById('companiesWithContact').textContent = companies.filter(c => c.contact_no).length;
        }

        // Add new company button
        document.getElementById('addCompanyBtn').addEventListener('click', function() {
            document.getElementById('companyForm').reset();
            document.getElementById('companyModalLabel').textContent = 'Add Company';
            new bootstrap.Modal(document.getElementById('companyModal')).show();
        });

        // Save company
        async function saveCompany() {
            const companyName = document.getElementById('companyName').value;
            const companyAddress = document.getElementById('companyAddress').value;
            const companyEmail = document.getElementById('companyEmail').value;
            const companyContact = document.getElementById('companyContact').value;

            if (!companyName.trim()) {
                alert('Please enter company name');
                return;
            }

            try {
                const response = await fetch('api/save_company.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        company_name: companyName,
                        address: companyAddress,
                        email: companyEmail,
                        contact_no: companyContact
                    })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Company saved successfully');
                    bootstrap.Modal.getInstance(document.getElementById('companyModal')).hide();
                    loadCompanies();
                } else {
                    alert('Error saving company: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error saving company:', error);
                alert('Error saving company');
            }
        }

        // Edit company
        async function editCompany(id) {
            try {
                const response = await fetch(`api/get_company.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const company = data.data;
                    document.getElementById('companyName').value = company.company_name;
                    document.getElementById('companyAddress').value = company.address || '';
                    document.getElementById('companyEmail').value = company.email || '';
                    document.getElementById('companyContact').value = company.contact_no || '';
                    document.getElementById('companyModalLabel').textContent = 'Edit Company';
                    new bootstrap.Modal(document.getElementById('companyModal')).show();
                }
            } catch (error) {
                console.error('Error loading company:', error);
            }
        }

        // Delete company
        async function deleteCompany(id) {
            if (!confirm('Are you sure you want to delete this company?')) return;

            try {
                const response = await fetch('api/delete_company.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: id })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Company deleted successfully');
                    loadCompanies();
                } else {
                    alert('Error deleting company');
                }
            } catch (error) {
                console.error('Error deleting company:', error);
                alert('Error deleting company');
            }
        }

        // Search companies
        document.getElementById('searchCompany').addEventListener('keyup', async function(e) {
            const searchTerm = e.target.value.toLowerCase();
            try {
                const response = await fetch(`api/search_companies.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                displayCompanies(data.data || []);
            } catch (error) {
                console.error('Error searching companies:', error);
            }
        });

        // Load companies on page load
        loadCompanies();
    </script>
</body>
</html>
