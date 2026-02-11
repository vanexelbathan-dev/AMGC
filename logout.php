<?php
// logout.php
session_start();

// Debug: Check session before destroying
error_log("Logout called. Session ID: " . session_id());

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session
session_destroy();

// Debug: After destruction
error_log("Session destroyed. Redirecting to login.");

// Redirect to login page
header("Location: login.php");
exit();
?>