<?php
session_start();
require_once 'config/database.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($email) || empty($password)) {
        $error = "❌ Please enter email and password";
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
                $_SESSION['branch_id'] = isset($user['branch_id']) ? $user['branch_id'] : 0; // Add branch_id to session
                $_SESSION['logged_in'] = true;

                // Debug: Check what's being set
                error_log("Login successful for user ID: " . $user['user_id']);
                error_log("Session variables set: user_id=" . $_SESSION['user_id'] . ", branch_id=" . $_SESSION['branch_id']);

                // Redirect based on user role
                $redirect_page = getDashboardByRole($user['role']);
                header("Location: $redirect_page");
                exit;

            } else {
                $error = "❌ Wrong password";
            }

        } else {
            $error = "❌ User not found or inactive";
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
    <title>AMGC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        /* Alert Messages */
        .alert-error {
            background-color: #FEE2E2;
            border: 1px solid #FECACA;
            color: #DC2626;
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .alert-success {
            background-color: #ECFDF5;
            border: 1px solid #D1FAE5;
            color: var(--success-green);
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            flex-shrink: 0;
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
            margin-bottom: 15px; /* Reduced margin */
            width: 100%;
        }

        .mobile-logo img {
            max-width: 180px;
            height: auto;
            object-fit: contain;
            margin: 0 auto;
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
                padding: 15px 20px; /* Reduced padding */
                justify-content: flex-start;
                padding-top: 20px; /* Reduced top padding */
                height: auto;
                overflow-y: visible;
            }

            .form-header {
                margin-bottom: 20px; /* Reduced margin */
            }

            .form-header h1 {
                font-size: 28px;
                margin-bottom: 5px; /* Reduced margin */
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
                padding: 12px 16px; /* Further reduced padding */
                padding-top: 15px; /* Further reduced top padding */
            }

            .form-header h1 {
                font-size: 26px;
                margin-bottom: 4px; /* Further reduced margin */
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
                <!-- Mobile Logo - Replace with your mobile logo image -->
                <img src="Pictures/amgc3DLogo.png" alt="AMGC Logo">
            </div>

            <!-- Header -->
            <div class="form-header">
                <h1>Welcome Back!</h1>
                <p>A. MACALINDONG DEVELOPMENT CORP.</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" id="login-form" onsubmit="handleSubmit(event)">
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
                            placeholder="Email"
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
                            placeholder="Password"
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
                    <a href="#forgot">Forgot Password?</a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-primary" id="submit-btn">
                    <span id="btn-text">Login</span>
                </button>
            </form>
        </div>

        <!-- Right Column - Image -->
        <div class="illustration-column">
            <div class="image-overlay"></div>
            <!-- Desktop Logo - Replace with your desktop logo image -->
            <img src="AMGC3DLOGO.png" alt="AMGC Desktop Logo" class="desktop-logo">
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
        function handleSubmit(event) {
            const submitBtn = document.getElementById('submit-btn');
            const btnText = document.getElementById('btn-text');
            
            // Check if form is valid
            const form = event.target;
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            submitBtn.disabled = true;
            btnText.innerHTML = '<span class="spinner"></span>Processing...';
            
            // Allow form submission after showing loading state
            setTimeout(() => {
                event.target.submit();
            }, 500);
        }

        // Initialize password toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('password-toggle');
            
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
        });
    </script>
</body>
</html>