<?php
session_start();
require_once 'config/database.php';
header('Content-Type: application/json');

$response = ['logged_in' => false];

// Check session first
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $response = [
        'logged_in' => true,
        'user_id' => $_SESSION['user_id'],
        'user_name' => $_SESSION['user_name'],
        'role' => $_SESSION['role']
    ];
}
// Check for token in request header (for mobile)
else if (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
    
    // Get user by user_id from the token format (user_id:token)
    // The token from localStorage is in format "user_id:token"
    $parts = explode(':', $token, 2);
    if (count($parts) === 2) {
        $user_id = $parts[0];
        $raw_token = $parts[1];
        
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
            // Verify the raw token against the hashed token in database
            if (password_verify($raw_token, $user['stored_token'])) {
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
                
                $response = [
                    'logged_in' => true,
                    'user_id' => $_SESSION['user_id'],
                    'user_name' => $_SESSION['user_name'],
                    'role' => $_SESSION['role']
                ];
            }
        }
    }
}

echo json_encode($response);
?>