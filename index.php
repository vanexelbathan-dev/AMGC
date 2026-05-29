<?php
session_start();
require_once 'config/database.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = '';

// ========== REDIRECT IF ALREADY LOGGED IN ==========
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $redirect_page = getDashboardByRole($_SESSION['role']);
    header("Location: $redirect_page");
    exit;
}
// ========== END OF REDIRECT ==========

// ========== CHECK FOR REMEMBER ME COOKIE ==========
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    list($user_id, $token) = explode(':', $_COOKIE['remember_token'], 2);
    
    $sql = "SELECT u.*, ut.token as stored_token, ut.expires_at 
            FROM users u 
            JOIN user_tokens ut ON u.user_id = ut.user_id 
            WHERE u.user_id = ? AND u.status = 'active' 
            AND ut.expires_at > NOW() 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        if (password_verify($token, $user['stored_token'])) {
            // Restore session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['user_name'] = $user['first_name'] . " " . $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['branch_id'] = isset($user['branch_id']) ? $user['branch_id'] : 0;
            $_SESSION['logged_in'] = true;
            
            // Refresh the cookie expiration
            $new_expiry = time() + (86400 * 30);
            // Set cookie (30 days) - WITHOUT SameSite para sa lumang PHP version
            setcookie(
                'remember_token',
                $user_id . ':' . $token,
                $new_expiry,
                '/',           
                '',            
                false,         
                true           
            );
            
            // IMPORTANT: Redirect to dashboard immediately
            $redirect_page = getDashboardByRole($user['role']);
            header("Location: $redirect_page");
            exit;
        }
    }
}
// ========== END OF REMEMBER ME CHECK ==========

// ========== NORMAL LOGIN PROCESS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $remember_me = isset($_POST['remember_me']) ? true : false;

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
                $_SESSION['branch_id'] = isset($user['branch_id']) ? $user['branch_id'] : 0;
                $_SESSION['logged_in'] = true;

                // Handle "Remember Me" functionality
                if ($remember_me) {
                    // Generate a secure token
                    $token = bin2hex(random_bytes(64));
                    $expiry = time() + (86400 * 30); // 30 days expiration
                    
                    // Store in database
                    $user_id = $user['user_id'];
                    $token_hash = password_hash($token, PASSWORD_DEFAULT);
                    
                    // Delete any existing token for this user
                    $delete_sql = "DELETE FROM user_tokens WHERE user_id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $user_id);
                    $delete_stmt->execute();
                    
                    // Insert new token
                    $insert_sql = "INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("isi", $user_id, $token_hash, $expiry);
                    $insert_stmt->execute();
                    
                    // Set cookie (30 days) - WITHOUT SameSite para sa lumang PHP version
                    setcookie(
                        'remember_token',
                        $user_id . ':' . $token,
                        $expiry,
                        '/',
                        '',
                        false,
                        true
                    );
                    
                    // Save token to localStorage for mobile fallback
                    echo '<script>
                        localStorage.setItem("remember_user_id", "' . $user['user_id'] . '");
                        localStorage.setItem("remember_token", "' . $token . '");
                        localStorage.setItem("token_expiry", "' . $expiry . '");
                    </script>';
                }

                error_log("Login successful for user ID: " . $user['user_id']);

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
// ========== END OF LOGIN PROCESS ==========

/**
 * Get the appropriate dashboard page based on user role
 */
function getDashboardByRole($role) {
    $redirect_map = [
        'branch_admin' => 'BranchAdmin/branchdashboard.php',
        'sales' => 'Sales/currentinventory.php',
        'warehouse' => 'Warehouse/warehouse.php',
        'delivery' => 'Delivery/fordelivery.php',
        'admin' => 'Global/dashboard.php',
        'rolling' => 'Rolling/current_inventory.php',
        'super_duper_admin' => 'SuperDuperAdmin/dashboard.php',
        'motorpool' => 'Motorpool/request_handler.php',
        'warehouseman' => 'CentralizeWarehouse/encode_stock.php',
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
    <link rel="icon" type="image/png" href="Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="Pictures/favicon.svg" />
    <link rel="shortcut icon" href="Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="Pictures/site.webmanifest" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS (same as before) */
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
            .form-column > * {
                max-width: 100%;
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

        .form-check-input:checked {
            background-color: var(--primary-green);
            border-color: var(--primary-green);
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
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form method="POST" id="login-form" onsubmit="handleSubmit(event)">
                <div class="form-group">
                    <label for="email">Email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" class="form-control" placeholder="Email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Password" required>
                        <button type="button" class="password-toggle-btn" id="password-toggle" aria-label="Show password" role="button" tabindex="0">
                            <i class="fas fa-eye" id="toggle-icon"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-check" style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1" style="margin-top: 0; width: 16px; height: 16px; cursor: pointer;">
                        <label class="form-check-label" for="remember_me" style="font-size: 13px; font-weight: normal; color: var(--dark-color); cursor: pointer; margin: 0;">Remember me on this device</label>
                    </div>
                </div>
                <div class="forgot-password">
                    <a href="#forgot">Forgot Password?</a>
                </div>
                <button type="submit" class="btn-primary" id="submit-btn">
                    <span id="btn-text">Login</span>
                </button>
            </form>
        </div>
        <div class="illustration-column">
            <div class="image-overlay"></div>
            <img src="AMGC3DLOGO.png" alt="AMGC Desktop Logo" class="desktop-logo">
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
            passwordInput.focus();
        }

        function handleSubmit(event) {
            const submitBtn = document.getElementById('submit-btn');
            const btnText = document.getElementById('btn-text');
            const form = event.target;
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            submitBtn.disabled = true;
            btnText.innerHTML = '<span class="spinner"></span>Processing...';
            setTimeout(() => {
                event.target.submit();
            }, 500);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('password-toggle');
            toggleBtn.addEventListener('click', togglePassword);
            toggleBtn.addEventListener('keydown', function(e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    togglePassword();
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const savedEmail = localStorage.getItem('remembered_email');
            if (savedEmail) {
                const emailInput = document.getElementById('email');
                if (emailInput && !emailInput.value) {
                    emailInput.value = savedEmail;
                    const rememberCheckbox = document.getElementById('remember_me');
                    if (rememberCheckbox) rememberCheckbox.checked = true;
                }
            }
            const form = document.getElementById('login-form');
            if (form) {
                form.addEventListener('submit', function() {
                    const rememberCheckbox = document.getElementById('remember_me');
                    if (rememberCheckbox && rememberCheckbox.checked) {
                        const email = document.getElementById('email').value;
                        localStorage.setItem('remembered_email', email);
                    } else {
                        localStorage.removeItem('remembered_email');
                    }
                });
            }
        });
    </script>
</body>
</html>