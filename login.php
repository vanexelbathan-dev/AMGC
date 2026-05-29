<?php
// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

// Error reporting for debugging (remove in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = '';
$success = '';

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    // Redirect to appropriate dashboard based on existing session
    $redirect_page = getDashboardByRole($_SESSION['role']);
    if (!empty($redirect_page)) {
        header("Location: " . $redirect_page);
        exit();
    }
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please try again.";
    } else {
        
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        
        // Validate input
        if (empty($email) || empty($password)) {
            $error = "Please enter email and password";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address";
        } else {
            
            // Prepare and execute query
            $sql = "SELECT user_id, first_name, last_name, email, password_hash, role, department, branch_id, status FROM users WHERE email = ? AND status = 'active' LIMIT 1";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                error_log("Database prepare error: " . $conn->error);
                $error = "System error. Please try again later.";
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($user = $result->fetch_assoc()) {
                    
                    // Verify password
                    if (password_verify($password, $user['password_hash'])) {
                        
                        // CRITICAL: Clear existing session data
                        session_regenerate_id(true); // Generate new session ID
                        
                        // Store user data in session
                        $_SESSION['user_id'] = (int)$user['user_id'];
                        $_SESSION['first_name'] = $user['first_name'];
                        $_SESSION['last_name'] = $user['last_name'];
                        $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['role'] = strtolower(trim($user['role'])); // Normalize role
                        $_SESSION['department'] = $user['department'] ?? '';
                        $_SESSION['branch_id'] = isset($user['branch_id']) ? (int)$user['branch_id'] : 0;
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                        
                        // Debug logging
                        error_log("=== LOGIN SUCCESS ===");
                        error_log("User ID: " . $_SESSION['user_id']);
                        error_log("Email: " . $_SESSION['user_email']);
                        error_log("Role: " . $_SESSION['role']);
                        error_log("Branch ID: " . $_SESSION['branch_id']);
                        
                        // Get redirect page
                        $redirect_page = getDashboardByRole($_SESSION['role']);
                        
                        error_log("Redirecting to: " . $redirect_page);
                        
                        // Set success message
                        $_SESSION['login_success'] = "Welcome back, " . $_SESSION['first_name'] . "!";
                        
                        // Redirect with cache control headers
                        if (!empty($redirect_page)) {
                            header("Cache-Control: no-cache, no-store, must-revalidate, private");
                            header("Pragma: no-cache");
                            header("Expires: 0");
                            header("Location: " . $redirect_page);
                            exit();
                        } else {
                            $error = "No dashboard configured for your role. Please contact administrator.";
                            error_log("ERROR: No dashboard found for role: " . $_SESSION['role']);
                        }
                        
                    } else {
                        $error = "Invalid email or password";
                        error_log("Login failed: Invalid password for user: " . $email);
                    }
                    
                } else {
                    $error = "Invalid email or password";
                    error_log("Login failed: User not found or inactive: " . $email);
                }
                
                $stmt->close();
            }
        }
    }
}

/**
 * Get the appropriate dashboard page based on user role
 */
function getDashboardByRole($role) {
    // Normalize role to lowercase
    $role = strtolower(trim($role));
    
    $redirect_map = [
        'admin' => 'Global/sales_reports.php',
        'branch_admin' => 'BranchAdmin/current_inventory.php',
        'sales' => 'Sales/currentinventory.php',
        'warehouse' => 'Warehouse/warehouse.php',
        'delivery' => 'Delivery/fordelivery.php',
        'super_admin' => 'Global/admin_dashboard.php',
        'manager' => 'Global/manager_dashboard.php',
    ];
    
    // Check if file exists
    if (isset($redirect_map[$role])) {
        $file_path = $redirect_map[$role];
        
        // Verify the file exists
        if (!file_exists($file_path)) {
            error_log("WARNING: Dashboard file not found: " . $file_path . " for role: " . $role);
            // Return default dashboard or empty
            return '';
        }
        
        return $file_path;
    }
    
    error_log("ERROR: No dashboard mapping found for role: " . $role);
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMGC - Login</title>
    <link rel="icon" type="image/png" href="Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="Pictures/favicon.svg" />
    <link rel="shortcut icon" href="Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="/manifest.json" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/css/offline-mode.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-green: #44D34E;
            --secondary-green: #44D34E;
            --light-green: #d1fae5;
            --dark-green: #047857;
            --success-green: #44D34E;
            --warning-green: #fbbf24;
            --danger-green: #f87171;
            --info-green: #22d3ee;
            --dark-color: #052A47;
            --light-color: #f9fafb;
            --sidebar-width: 260px;
            --gray-text: #666666;
            --border-color: #E0E0E0;
        }

        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #FFFFFF;
            color: var(--dark-color);
        }

        .login-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            height: 100vh;
            gap: 0;
            overflow: hidden;
        }

        .form-column {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 30px;
            width: 100%;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .form-column > * {
            width: 100%;
            max-width: 420px;
        }

        .form-header {
            margin-bottom: 30px;
            text-align: center;
        }

        .form-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .form-header p {
            color: var(--gray-text);
            font-size: 14px;
            margin-bottom: 0;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 12px;
            color: var(--gray-text);
            pointer-events: none;
            font-size: 14px;
        }

        .form-control {
            border: 1px solid var(--border-color);
            padding: 9px 44px 9px 38px !important;
            border-radius: 8px;
            font-size: 13px;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1);
            outline: none;
        }

        .password-toggle-btn {
            position: absolute;
            right: 8px;
            background: none;
            border: none;
            color: var(--gray-text);
            cursor: pointer;
            padding: 6px;
            transition: all 0.3s ease;
            font-size: 14px;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 6px;
        }

        .password-toggle-btn:hover {
            color: var(--primary-green);
            background-color: rgba(68, 211, 78, 0.1);
        }

        .password-toggle-btn:active {
            transform: scale(0.95);
        }

        .password-toggle-btn i {
            position: static !important;
        }

        .password-toggle-btn.active {
            color: var(--primary-green);
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 14px;
            flex-shrink: 0;
        }

        .forgot-password a {
            color: var(--primary-green);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: opacity 0.3s ease;
        }

        .forgot-password a:hover {
            opacity: 0.8;
        }

        .btn-primary {
            width: 100%;
            padding: 11px;
            background-color: var(--primary-green);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 14px;
            flex-shrink: 0;
        }

        .btn-primary:hover {
            background-color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(68, 211, 78, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .footer-text {
            font-size: 11px;
            color: var(--gray-text);
            text-align: center;
            line-height: 1.4;
            margin: 0;
            flex-shrink: 0;
            margin-top: 20px;
        }

        .illustration-column {
            background: linear-gradient(135deg, rgba(68, 211, 78, 0.1) 0%, #f0fff2 50%, rgba(68, 211, 78, 0.05) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .illustration-column .desktop-logo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 2;
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(68, 211, 78, 0.1) 0%, rgba(68, 211, 78, 0.05) 100%);
            z-index: 1;
        }

        .mobile-logo {
            display: none;
            text-align: center;
            margin-bottom: 15px;
            width: 100%;
        }

        .mobile-logo img {
            max-width: 180px;
            height: auto;
            object-fit: contain;
            margin: 0 auto;
        }

        .swal2-popup {
            border-radius: 12px !important;
            padding: 20px !important;
        }

        .swal2-title {
            color: var(--dark-color) !important;
            font-size: 20px !important;
        }

        .swal2-html-container {
            color: var(--gray-text) !important;
            font-size: 14px !important;
        }

        .swal2-confirm {
            background-color: var(--primary-green) !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 10px 30px !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
        }

        .swal2-confirm:hover {
            background-color: var(--dark-green) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(68, 211, 78, 0.3) !important;
        }

        .swal2-timer-progress-bar {
            background: var(--primary-green) !important;
        }

        @media (max-width: 1024px) {
            .login-container {
                grid-template-columns: 1fr 0.8fr;
            }
        }

        @media (max-width: 768px) {
            .login-container {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
                height: 100vh;
            }

            .illustration-column {
                display: none;
            }

            .mobile-logo {
                display: block;
            }

            .form-column {
                padding: 15px 20px;
                justify-content: flex-start;
                padding-top: 20px;
                height: auto;
                overflow-y: visible;
            }

            .form-header {
                margin-bottom: 20px;
            }

            .form-header h1 {
                font-size: 28px;
                margin-bottom: 5px;
            }

            .form-header p {
                font-size: 13px;
            }

            .form-column > * {
                max-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .form-column {
                padding: 12px 16px;
                padding-top: 15px;
            }

            .form-header h1 {
                font-size: 26px;
                margin-bottom: 4px;
            }

            .form-header p {
                font-size: 14px;
            }

            .footer-text {
                font-size: 11px;
            }

            .mobile-logo img {
                max-width: 160px;
            }
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        
        .alert {
            border-radius: 8px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="form-column">
            <div class="mobile-logo">
                <img src="Pictures/amgc3DLogo.png" alt="AMGC Logo">
            </div>

            <div class="form-header">
                <h1>Welcome Back!</h1>
                <p>A. MACALINDONG DEVELOPMENT CORP.</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <form method="POST" id="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control" 
                            placeholder="Enter your email"
                            required
                            autocomplete="email"
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button 
                            type="button" 
                            class="password-toggle-btn" 
                            id="password-toggle"
                            aria-label="Show password"
                            role="button"
                            tabindex="0"
                        >
                            <i class="fas fa-eye-slash" id="toggle-icon"></i>
                        </button>
                    </div>
                </div>

                <div class="forgot-password">
                    <a href="#" id="forgot-password-link">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-primary" id="submit-btn">
                    <span id="btn-text">Login</span>
                </button>
            </form>

            <p class="footer-text">
                A. MACALINDONG DEVELOPMENT CORP. © 2026<br>
                All rights reserved.
            </p>
        </div>

        <div class="illustration-column">
            <div class="image-overlay"></div>
            <img src="AMGC3DLOGO.png" alt="AMGC Desktop Logo" class="desktop-logo">
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle error messages with SweetAlert
            <?php if ($error && empty($_POST)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Login Failed',
                text: <?php echo json_encode($error); ?>,
                confirmButtonText: 'Try Again',
                confirmButtonColor: '#44D34E',
                background: '#ffffff'
            });
            <?php endif; ?>
            
            // Handle success messages
            <?php if (isset($_SESSION['login_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Welcome!',
                text: <?php echo json_encode($_SESSION['login_success']); ?>,
                timer: 2000,
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                background: '#ffffff'
            });
            <?php unset($_SESSION['login_success']); ?>
            <?php endif; ?>
        });

        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggle-icon');
            const toggleBtn = document.getElementById('password-toggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
                toggleBtn.classList.add('active');
                toggleBtn.setAttribute('aria-label', 'Hide password');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
                toggleBtn.classList.remove('active');
                toggleBtn.setAttribute('aria-label', 'Show password');
            }
            
            passwordInput.focus();
        }

        document.getElementById('login-form').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submit-btn');
            const btnText = document.getElementById('btn-text');
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            
            if (!email || !password) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Information',
                    text: 'Please enter both email and password to continue.',
                    confirmButtonText: 'Got it',
                    confirmButtonColor: '#44D34E',
                    background: '#ffffff'
                });
                return;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Email',
                    text: 'Please enter a valid email address.',
                    confirmButtonText: 'Got it',
                    confirmButtonColor: '#44D34E',
                    background: '#ffffff'
                });
                return;
            }
            
            submitBtn.disabled = true;
            btnText.innerHTML = '<span class="spinner"></span>Logging in...';
        });

        document.getElementById('forgot-password-link').addEventListener('click', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Forgot Password?',
                html: `
                    <div style="text-align: left;">
                        <p style="margin-bottom: 15px;">Please contact your system administrator to reset your password.</p>
                        <p style="margin-bottom: 5px;"><strong>IT Support:</strong></p>
                        <p style="margin-bottom: 5px;">Email: it.support@amgc.com</p>
                        <p>Phone: (02) 1234-5678</p>
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'OK',
                confirmButtonColor: '#44D34E',
                background: '#ffffff'
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('password-toggle');
            
            if (toggleBtn) {
                toggleBtn.addEventListener('click', togglePassword);
                toggleBtn.addEventListener('keydown', function(e) {
                    if (e.key === ' ' || e.key === 'Enter') {
                        e.preventDefault();
                        togglePassword();
                    }
                });
                toggleBtn.addEventListener('focus', function() {
                    this.style.outline = '2px solid var(--primary-green)';
                    this.style.outlineOffset = '2px';
                });
                toggleBtn.addEventListener('blur', function() {
                    this.style.outline = 'none';
                });
            }
        });
    </script>

    <!-- Offline Support Scripts -->
    <script src="/js/offline-manager.js"></script>
    <script src="/js/offline-sync.js"></script>
    <script src="/js/offline-login.js"></script>
    
    <!-- Register Service Worker for offline support -->
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js')
                .then(reg => {
                    console.log('[ServiceWorker] Registered successfully');
                })
                .catch(err => {
                    console.warn('[ServiceWorker] Registration failed:', err);
                });
        }
    </script>
</body>
</html>
