<?php
session_start();

// I-check kung naka-login na
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

// Database connection details
$host = 'localhost';
$dbname = 'inventory_db';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    $error = 'Database connection failed. Please contact administrator.';
    $conn = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_username = htmlspecialchars(trim($_POST['username'] ?? ''));
    $input_password = $_POST['password'] ?? '';
    
    // Check if forgot password was requested
    if (isset($_POST['forgot_password'])) {
        // Handle forgot password logic here
        // This would typically send a reset link to the user's email
        // For now, we'll show a message
        $_SESSION['reset_username'] = $input_username;
        header('Location: forgot-password.php');
        exit;
    }
    
    $valid = false;
    $user_data = null;
    
    // Check database only (demo accounts removed)
    if (isset($conn)) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$input_username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($input_password, $user['password'])) {
            $valid = true;
            $user_data = [
                'full_name' => $user['full_name'],
                'user_type' => $user['user_type'],
                'branch' => $user['branch_id'],
                'avatar' => substr($user['full_name'], 0, 2)
            ];
        }
    }
    
    if ($valid && $user_data) {
        $_SESSION['user'] = $input_username;
        $_SESSION['user_data'] = $user_data;
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
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
<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">

<style>
        :root {
            --primary-green: #44D34E;
            --secondary-green: #44D34E;
            --light-green: #d1fae5;
            --dark-green: #047857;
            --dark-color: #052A47;
            --light-color: #f9fafb;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: var(--light-color);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            color: var(--dark-color);
            line-height: 1.6;
        }
        
        /* Login Page */
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--dark-green) 0%, var(--primary-green) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            border: 1px solid var(--light-green);
            animation: fadeIn 0.5s ease-out;
        }
        
        .company-logo{
            width:110px;
            height:110px;
            border-radius:50%;
            overflow:hidden;
            margin:auto;
            margin-bottom:15px;
            border:4px solid rgba(255,255,255,0.6);
            box-shadow:0 10px 25px rgba(0,0,0,0.4);
            background:rgba(255,255,255,0.15);
            backdrop-filter:blur(10px);
        }

        .company-logo img{
            width:100%;
            height:100%;
            object-fit:cover;
        }
        
        /* Password input with eye icon */
        .password-input-group {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            z-index: 10;
        }
        
        .password-toggle:hover {
            color: var(--primary-green);
        }
        
        /* Login button - FIXED with standard Bootstrap hover */
        .login-btn {
            background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            color: white;
        }
        
        .login-btn:hover {
            background: linear-gradient(135deg, var(--secondary-green), var(--dark-green));
            color: white;
        }
        
        /* Forgot password link */
        .forgot-password {
            text-decoration: none;
            color: #000000;
            font-size: 0.9rem;
            transition: color 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .forgot-password:hover {
            color: var(--primary-green);
            text-decoration: underline;
        }
        
        /* Error message */
        .alert-danger {
            border-radius: 10px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            margin-bottom: 20px;
            animation: shake 0.5s ease-in-out;
        }
        
        /* Success message */
        .alert-success {
            border-radius: 10px;
            border: 1px solid #a7f3d0;
            background: #ecfdf5;
            color: var(--dark-green);
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        
        /* Modal styles */
        .modal-content {
            border-radius: 15px;
            border: none;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            border: none;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .reset-btn {
            background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
        }
        
        .reset-btn:hover {
            background: linear-gradient(135deg, var(--secondary-green), var(--dark-green));
            color: white;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
    </style>
</head>
<body>
    <!-- LOGIN PAGE -->
    <div class="login-container">
        <div class="login-card">
            <div class="text-center">
                <div class="company-logo">
                    <img src="Pictures/AMGCLOGO.png" alt="AMGC Logo">
                </div>

                <h2 class="mb-3 fw-bold">AMGC System</h2>
                <p class="text-muted mb-4">A. MACALINDONG GROUP OF COMPANIES</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="mb-4">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>
                        <input type="text" class="form-control" name="username" placeholder="Enter username" required 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="password-input-group">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-lock"></i>
                            </span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                        </div>
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="text-end mt-2">
                        <a href="#" class="forgot-password" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                            <i class="bi bi-question-circle"></i> Forgot Password?
                        </a>
                    </div>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-lg login-btn">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Log In
                    </button>           
                </div>
            </form>
            
            <div class="text-center mt-4">
                <p class="text-muted small">
                    <i class="bi bi-shield-check"></i> Secure login system
                </p>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">
                        <i class="bi bi-key me-2"></i>Reset Password
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Enter your username to receive password reset instructions.</p>
                    <form id="forgotPasswordForm">
                        <div class="mb-3">
                            <label for="resetUsername" class="form-label">Username</label>
                            <input type="text" class="form-control" id="resetUsername" name="resetUsername" placeholder="Enter your username" required>
                        </div>
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle me-2"></i>
                            Password reset instructions will be sent to the email associated with your account.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn reset-btn" id="submitResetRequest">
                        <i class="bi bi-send me-2"></i>Send Reset Link
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            togglePassword.addEventListener('click', function() {
                // Toggle password visibility
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Toggle eye icon
                const eyeIcon = this.querySelector('i');
                if (type === 'password') {
                    eyeIcon.classList.remove('bi-eye-slash');
                    eyeIcon.classList.add('bi-eye');
                } else {
                    eyeIcon.classList.remove('bi-eye');
                    eyeIcon.classList.add('bi-eye-slash');
                }
            });
            
            // Auto-focus on username field
            document.querySelector('input[name="username"]').focus();
            
            // Form validation
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                const username = document.querySelector('input[name="username"]').value.trim();
                const password = document.getElementById('password').value.trim();
                
                if (!username || !password) {
                    e.preventDefault();
                    alert('Please enter both username and password!');
                    return false;
                }
                
                return true;
            });
            
            // Forgot password functionality
            document.getElementById('submitResetRequest').addEventListener('click', function() {
                const username = document.getElementById('resetUsername').value.trim();
                
                if (!username) {
                    alert('Please enter your username.');
                    return;
                }
                
                // In a real application, you would send an AJAX request here
                // For now, we'll simulate the process
                
                // Show loading state
                const btn = this;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
                btn.disabled = true;
                
                // Simulate API call
                setTimeout(function() {
                    // Reset button
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal'));
                    modal.hide();
                    
                    // Show success message
                    const loginCard = document.querySelector('.login-card');
                    const successAlert = document.createElement('div');
                    successAlert.className = 'alert alert-success alert-dismissible fade show';
                    successAlert.innerHTML = `
                        <i class="bi bi-check-circle-fill me-2"></i>
                        Password reset instructions have been sent to the email associated with <strong>${username}</strong>.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `;
                    
                    // Insert after logo section
                    const logoSection = document.querySelector('.text-center');
                    logoSection.parentNode.insertBefore(successAlert, logoSection.nextSibling);
                    
                    // Clear the form
                    document.getElementById('resetUsername').value = '';
                    
                }, 1500);
            });
            
            // Clear forgot password form when modal closes
            document.getElementById('forgotPasswordModal').addEventListener('hidden.bs.modal', function () {
                document.getElementById('resetUsername').value = '';
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Tab' && document.activeElement.name === 'username') {
                    // Auto-focus password field after username
                    setTimeout(() => {
                        document.getElementById('password').focus();
                    }, 10);
                }
                
                // Enter key to submit form
                if (e.key === 'Enter' && document.activeElement.name === 'password') {
                    document.getElementById('loginForm').submit();
                }
                
                // Alt + F for forgot password
                if (e.altKey && e.key === 'f') {
                    e.preventDefault();
                    const modal = new bootstrap.Modal(document.getElementById('forgotPasswordModal'));
                    modal.show();
                }
            });
        });
    </script>
</body>
</html>