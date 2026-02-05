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
    // Kapag hindi maka-connect sa database, gumamit ng demo credentials
    $demo_mode = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_username = htmlspecialchars(trim($_POST['username'] ?? ''));
    $input_password = $_POST['password'] ?? '';
    
    // Demo credentials (remove this in production)
    $demo_users = [
        'admin' => [
            'password' => 'admin123',
            'full_name' => 'Admin User',
            'user_type' => 'admin',
            'branch' => null,
            'avatar' => 'AD'
        ],
        'branch1' => [
            'password' => 'branch123',
            'full_name' => 'Juan Dela Cruz',
            'user_type' => 'branch_manager',
            'branch' => 'BR-001',
            'avatar' => 'JC'
        ],
        'branch2' => [
            'password' => 'branch123',
            'full_name' => 'Maria Santos',
            'user_type' => 'branch_manager',
            'branch' => 'BR-002',
            'avatar' => 'MS'
        ]
    ];
    
    $valid = false;
    $user_data = null;
    
    // Check demo users first
    if (isset($demo_users[$input_username]) && $demo_users[$input_username]['password'] === $input_password) {
        $valid = true;
        $user_data = $demo_users[$input_username];
    }
    // If not in demo users, check database
    elseif (isset($conn)) {
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
    <title>Inventory System - Login</title>
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
        
        .company-logo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            overflow: hidden;
            border: 5px solid white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
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
        
        /* Login button */
        .login-btn {
            background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            color: white;
            transition: none !important;
        }
        
        .login-btn:hover {
            background: linear-gradient(135deg, var(--secondary-green), var(--dark-green)) !important;
            transform: none !important;
            box-shadow: none !important;
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
        
        .demo-credentials {
            background: rgba(16, 185, 129, 0.1);
            border-radius: 8px;
            padding: 10px;
            margin-top: 15px;
            border: 1px solid var(--light-green);
            font-size: 0.85rem;
        }
        
        .demo-credentials h6 {
            color: var(--primary-green);
            font-weight: 600;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <!-- LOGIN PAGE -->
    <div class="login-container">
        <div class="login-card">
            <div class="text-center">
                <div class="company-logo">
                    <!-- Replace this with your actual logo image -->
                    <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; background: linear-gradient(135deg, var(--dark-green), var(--primary-green)); color: white; font-size: 2.5rem; font-weight: bold;">
                        INV
                    </div>
                </div>
                <h2 class="mb-3 fw-bold">Inventory System</h2>
                <p class="text-muted mb-4">Sustainable Inventory Management</p>
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
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-lg login-btn">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Log In
                    </button>
                    
                    <div class="demo-credentials">
                        <h6><i class="bi bi-info-circle"></i> Demo Credentials:</h6>
                        <div class="row">
                            <div class="col-6">
                                <small><strong>Admin:</strong> admin/admin123</small>
                            </div>
                            <div class="col-6">
                                <small><strong>Branch Manager:</strong> branch1/branch123</small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
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
            
            // Keyboard shortcut for focusing on password
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
            });
        });
    </script>
</body>
</html>