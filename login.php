<?php
session_start();
require_once 'config/database.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($email) || empty($password)) {
        $error = "Please enter email and password";
    } else {

        $sql = "SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {

            if (password_verify($password, $user['password_hash'])) {

                // Set ALL necessary session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['user_name'] = $user['first_name'] . " " . $user['last_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['department'] = $user['department'];
                $_SESSION['branch_id'] = isset($user['branch_id']) ? $user['branch_id'] : 0;
                $_SESSION['logged_in'] = true;

                // Debug: Check what's being set
                error_log("Login successful for user ID: " . $user['user_id']);
                error_log("Session variables set: user_id=" . $_SESSION['user_id'] . ", branch_id=" . $_SESSION['branch_id']);

                // Set success message
                $success = "Login successful! Welcome back, " . $user['first_name'] . "!";
                
                // Get redirect page
                $redirect_page = getDashboardByRole($user['role']);

            } else {
                $error = "Wrong password";
            }

        } else {
            $error = "User not found or inactive";
        }
    }
}

/**
 * Get the appropriate dashboard page based on user role
 */
function getDashboardByRole($role) {
    $redirect_map = [
        'branch_admin' => 'BranchAdmin/current_inventory.php',
        'sales' => 'Sales/currentinventory.php',
        'warehouse' => 'Warehouse/warehouse.php',
        'delivery' => 'Delivery/fordelivery.php',
        'admin' => 'Global/sales_reports.php',
    ];
    
    return isset($redirect_map[$role]) ? $redirect_map[$role] : '';
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
    <link rel="manifest" href="Pictures/site.webmanifest" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 CSS - Load early but it's just CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
            
            /* Additional colors for consistency */
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

        /* Left Column - Form */
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

        /* Alert Messages - Hidden by default, we'll use SweetAlert instead */
        .alert-error, .alert-success {
            display: none;
        }

        /* Form Groups */
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

        /* Enhanced Password Toggle Button */
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

        .form-control:focus + .password-toggle-btn {
            color: var(--primary-green);
        }

        /* Forgot Password */
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

        /* Submit Button */
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

        /* Footer Text */
        .footer-text {
            font-size: 11px;
            color: var(--gray-text);
            text-align: center;
            line-height: 1.4;
            margin: 0;
            flex-shrink: 0;
            margin-top: 20px;
        }

        /* Right Column - Image */
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

        /* Mobile Logo */
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

        /* Custom SweetAlert Styles */
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

        .swal2-cancel {
            border-radius: 8px !important;
            padding: 10px 30px !important;
        }

        .swal2-timer-progress-bar {
            background: var(--primary-green) !important;
        }

        /* Responsive */
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

        @media (max-width: 375px) {
            .mobile-logo img {
                max-width: 140px;
            }
            
            .form-header h1 {
                font-size: 24px;
            }
        }

        /* Loading state */
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
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Column - Form -->
        <div class="form-column">
            <!-- Mobile Logo (Visible only on mobile) -->
            <div class="mobile-logo">
                <img src="Pictures/amgc3DLogo.png" alt="AMGC Logo">
            </div>

            <!-- Header -->
            <div class="form-header">
                <h1>Welcome Back!</h1>
                <p>A. MACALINDONG DEVELOPMENT CORP.</p>
            </div>

            <!-- Form -->
            <form method="POST" id="login-form">
                <!-- Email Field -->
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
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                        >
                    </div>
                </div>

                <!-- Password Field with Enhanced Toggle -->
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
                        >
                        <button 
                            type="button" 
                            class="password-toggle-btn" 
                            id="password-toggle"
                            aria-label="Show password"
                            role="button"
                            tabindex="0"
                        >
                            <i class="fas fa-eye" id="toggle-icon"></i>
                        </button>
                    </div>
                </div>

                <!-- Forgot Password -->
                <div class="forgot-password">
                    <a href="#" id="forgot-password-link">Forgot Password?</a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-primary" id="submit-btn">
                    <span id="btn-text">Login</span>
                </button>
            </form>

            <!-- Footer Text -->
            <p class="footer-text">
                A. MACALINDONG DEVELOPMENT CORP. © 2026<br>
                All rights reserved.
            </p>
        </div>

        <!-- Right Column - Image -->
        <div class="illustration-column">
            <div class="image-overlay"></div>
            <!-- Desktop Logo -->
            <img src="AMGC3DLOGO.png" alt="AMGC Desktop Logo" class="desktop-logo">
        </div>
    </div>

    <!-- Hidden PHP data for JavaScript -->
    <?php if ($error): ?>
    <div id="php-error" data-error="<?php echo htmlspecialchars($error); ?>" style="display: none;"></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div id="php-success" data-success="<?php echo htmlspecialchars($success); ?>" data-redirect="<?php echo isset($redirect_page) ? $redirect_page : ''; ?>" style="display: none;"></div>
    <?php endif; ?>

    <!-- Scripts - Load in correct order -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 JS - Load this BEFORE your custom scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Wait for DOM and SweetAlert to load
        document.addEventListener('DOMContentLoaded', function() {
            
            // Check for error message
            const errorDiv = document.getElementById('php-error');
            if (errorDiv) {
                const errorMessage = errorDiv.getAttribute('data-error');
                Swal.fire({
                    icon: 'error',
                    title: 'Login Failed',
                    text: errorMessage,
                    confirmButtonText: 'Try Again',
                    confirmButtonColor: '#44D34E',
                    background: '#ffffff',
                    customClass: {
                        popup: 'swal2-popup',
                        title: 'swal2-title',
                        htmlContainer: 'swal2-html-container',
                        confirmButton: 'swal2-confirm'
                    }
                });
            }

            // Check for success message
            const successDiv = document.getElementById('php-success');
            if (successDiv) {
                const successMessage = successDiv.getAttribute('data-success');
                const redirectUrl = successDiv.getAttribute('data-redirect');
                
                Swal.fire({
                    icon: 'success',
                    title: 'Welcome!',
                    text: successMessage,
                    timer: 2000,
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    background: '#ffffff',
                    customClass: {
                        popup: 'swal2-popup',
                        title: 'swal2-title',
                        htmlContainer: 'swal2-html-container',
                        timerProgressBar: 'swal2-timer-progress-bar'
                    },
                    didOpen: () => {
                        Swal.showLoading();
                    },
                    willClose: () => {
                        if (redirectUrl) {
                            window.location.href = redirectUrl;
                        }
                    }
                });
            }
        });

        // Enhanced Password visibility toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.getElementById('password-toggle');
            const toggleIcon = document.getElementById('toggle-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
                toggleBtn.classList.add('active');
                toggleBtn.setAttribute('aria-label', 'Hide password');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
                toggleBtn.classList.remove('active');
                toggleBtn.setAttribute('aria-label', 'Show password');
            }
            
            // Maintain focus on password field
            passwordInput.focus();
        }

        // Form submission with loading state
        document.getElementById('login-form').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submit-btn');
            const btnText = document.getElementById('btn-text');
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            
            // Check if fields are empty
            if (!email || !password) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Information',
                    text: 'Please enter both email and password to continue.',
                    confirmButtonText: 'Got it',
                    confirmButtonColor: '#44D34E',
                    background: '#ffffff',
                    customClass: {
                        popup: 'swal2-popup',
                        title: 'swal2-title',
                        htmlContainer: 'swal2-html-container',
                        confirmButton: 'swal2-confirm'
                    }
                });
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            btnText.innerHTML = '<span class="spinner"></span>Logging in...';
            
            // Allow form submission
            // Don't prevent default, let the form submit normally
        });

        // Forgot Password handler
        document.getElementById('forgot-password-link').addEventListener('click', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Forgot Password?',
                html: `
                    <div style="text-align: left;">
                        <p style="margin-bottom: 15px;">Please contact your system administrator to reset your password.</p>
                        <p style="margin-bottom: 5px;"><strong>IT Support:</strong></p>
                        <p style="margin-bottom: 5px;">Email: it.support@amgc.com</p>
                        <p>Phone: (123) 456-7890</p>
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'OK',
                confirmButtonColor: '#44D34E',
                background: '#ffffff',
                customClass: {
                    popup: 'swal2-popup',
                    title: 'swal2-title',
                    htmlContainer: 'swal2-html-container',
                    confirmButton: 'swal2-confirm'
                }
            });
        });

        // Initialize password toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('password-toggle');
            
            if (toggleBtn) {
                // Add click event listener to toggle button
                toggleBtn.addEventListener('click', togglePassword);
                
                // Add keyboard support (Space and Enter keys)
                toggleBtn.addEventListener('keydown', function(e) {
                    if (e.key === ' ' || e.key === 'Enter') {
                        e.preventDefault();
                        togglePassword();
                    }
                });
                
                // Make the button focusable with proper styling
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
</body>
</html>