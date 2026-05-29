<?php
session_start();
require_once 'config/database.php';

// Clear remember me token from database
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // Delete token from database
    $delete_sql = "DELETE FROM user_tokens WHERE user_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $user_id);
    $delete_stmt->execute();
}

// Clear all session variables
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Clear remember me cookie (IMPORTANTE ITO!)
setcookie('remember_token', '', time() - 3600, '/');
setcookie('remember_token', '', time() - 3600, '/', '', false, true);
?>
<!DOCTYPE html>
<html>
<head>
    <script>
        // Clear all storage
        localStorage.clear();
        sessionStorage.clear();
        
        // Clear cookies including remember_token
        document.cookie.split(";").forEach(function(c) { 
            document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/"); 
        });
        
        // Specifically clear remember_token cookie
        document.cookie = "remember_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
        document.cookie = "remember_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/AMGC;";
        
        // Notify Flutter to clear session
        if (window.SaveSession) {
            window.SaveSession.postMessage(JSON.stringify({
                logout: true,
                clearSession: true,
                timestamp: Date.now()
            }));
        }
        
        // Redirect to login with cache buster para hindi mag-restore
        window.location.href = "index.php?t=" + Date.now();
    </script>
</head>
<body>
    <p>Logging out...</p>
</body>
</html>