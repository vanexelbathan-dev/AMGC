<?php
/**
 * OFFLINE HELPER - PHP utilities para sa offline support
 * Simple functions na gagamit sa PHP files para mag-integrate ng offline
 */

/**
 * Get offline script tags to include sa pages
 * Gamitin ito bago mag-close ng </body> tag
 * 
 * @return string HTML script tags
 */
function getOfflineScripts() {
    return '
    <!-- Offline Support Scripts -->
    <script src="/js/offline-bar.js"></script>
    <script src="/js/offline-manager.js"></script>
    <script src="/js/offline-sync.js"></script>
    <script src="/js/offline-login.js"></script>
    
    <!-- Service Worker Registration -->
    <script>
        if ("serviceWorker" in navigator) {
            navigator.serviceWorker.register("/service-worker.js")
                .then(reg => console.log("[SW] Registered"))
                .catch(err => console.warn("[SW] Failed:", err));
        }
    </script>
    ';
}

/**
 * Get offline CSS link
 * Gamitin ito sa <head> section
 * 
 * @return string HTML link tag
 */
function getOfflineCSS() {
    return '<link rel="stylesheet" href="/css/offline-mode.css">';
}

/**
 * Check if service worker is supported
 * 
 * @return bool
 */
function isServiceWorkerSupported() {
    return true; // JavaScript only, but this helps with PHP logic
}

/**
 * Get offline initialization script
 * Ito ay mag-register ng service worker
 * Gamitin ito sa login.php after login success
 * 
 * @return string JavaScript code
 */
function getServiceWorkerInitScript() {
    return "
    <script>
        // Register service worker para sa offline support
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js')
                .then(reg => {
                    console.log('[Init] Service Worker registered:', reg);
                })
                .catch(err => {
                    console.warn('[Init] Service Worker registration failed:', err);
                });
        }
    </script>
    ";
}

/**
 * Output connection status HTML
 * Ito ay magpapakita ng online/offline status sa page
 * Lagyan ito sa navbar/header
 * 
 * @return string HTML
 */
function getConnectionStatusHTML() {
    return '
    <div class="connection-status online" id="connection-status-indicator">
        <span>Online</span>
    </div>
    
    <script>
        // Update status indicator
        function updateStatusIndicator() {
            const indicator = document.getElementById("connection-status-indicator");
            if (!indicator) return;
            
            if (navigator.onLine) {
                indicator.className = "connection-status online";
                indicator.innerHTML = "<span>Online</span>";
            } else {
                indicator.className = "connection-status offline";
                indicator.innerHTML = "<span>Offline</span>";
            }
        }
        
        window.addEventListener("online", updateStatusIndicator);
        window.addEventListener("offline", updateStatusIndicator);
        
        // Check on page load
        document.addEventListener("DOMContentLoaded", updateStatusIndicator);
    </script>
    ';
}

/**
 * Emit offline login data sa JavaScript
 * Ito ay gagamitin ng offline-login.js para ma-save ang session
 * Gamitin ito after successful login sa PHP
 * 
 * @param array $userData User session data
 * @return string JavaScript code
 */
function emitOfflineLoginData($userData) {
    $userData = [
        'user_id' => isset($userData['user_id']) ? $userData['user_id'] : 0,
        'username' => isset($userData['user_name']) ? $userData['user_name'] : (isset($userData['username']) ? $userData['username'] : ''),
        'role' => isset($userData['role']) ? $userData['role'] : '',
        'branch_id' => isset($userData['branch_id']) ? $userData['branch_id'] : 0
    ];
    
    $json = json_encode($userData);
    
    return "
    <script>
        // Save login offline after successful server login
        document.addEventListener('DOMContentLoaded', async function() {
            // Wait for offline manager
            let retries = 0;
            const interval = setInterval(async function() {
                if (typeof offlineManager !== 'undefined' && offlineManager.db) {
                    clearInterval(interval);
                    
                    const sessionData = {$json};
                    console.log('[OfflineLogin] Saving session:', sessionData);
                    
                    try {
                        await offlineManager.saveSession(sessionData);
                        console.log('[OfflineLogin] Session saved successfully');
                    } catch (error) {
                        console.error('[OfflineLogin] Error saving session:', error);
                    }
                }
                
                retries++;
                if (retries > 50) {
                    clearInterval(interval);
                    console.warn('[OfflineLogin] Timeout waiting for offline manager');
                }
            }, 100);
        });
    </script>
    ";
}

/**
 * Create offline-ready form wrapper
 * Ito ay mag-wrap ng forms na may offline detection
 * 
 * @param string $formContent HTML ng form
 * @param bool $allowOffline Whether form should work offline (true para sa login, false para sa data operations)
 * @return string Complete form HTML
 */
function wrapFormWithOfflineDetection($formContent, $allowOffline = false) {
    if ($allowOffline) {
        // For login forms - allow offline operation
        return "
        <div class='form-wrapper' data-offline-enabled='true'>
            {$formContent}
        </div>
        ";
    } else {
        // For other forms - show warning when offline
        return "
        <div class='form-wrapper' data-offline-enabled='false'>
            <div class='offline-warning-banner'>
                ⚠️ You are in offline mode. Data operations (save, submit, delete) are not available right now.
            </div>
            {$formContent}
        </div>
        ";
    }
}
?>
