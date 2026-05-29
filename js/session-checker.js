// Session checker for all protected pages
let sessionCheckInterval = null;

function getBaseUrl() {
    // Get the base URL dynamically
    const path = window.location.pathname;
    // Check if we're in a subdirectory
    if (path.includes('/BranchAdmin/') || 
        path.includes('/Sales/') || 
        path.includes('/Warehouse/') || 
        path.includes('/Delivery/') || 
        path.includes('/Global/')) {
        return '../';
    }
    return '/AMGC/';
}

function initSessionChecker() {
    // Clear existing interval if any
    if (sessionCheckInterval) {
        clearInterval(sessionCheckInterval);
    }
    
    const baseUrl = getBaseUrl();
    
    // Check every 30 seconds
    sessionCheckInterval = setInterval(function() {
        fetch(baseUrl + 'check_session.php')
            .then(response => response.json())
            .then(data => {
                if (!data.logged_in) {
                    showLoadingAndRedirect();
                }
            })
            .catch(error => console.error('Session check failed:', error));
    }, 30000);
}

function showLoadingAndRedirect() {
    // Clear interval to stop checking
    if (sessionCheckInterval) {
        clearInterval(sessionCheckInterval);
    }
    
    const baseUrl = getBaseUrl();
    
    // Create loading overlay
    const overlay = document.createElement('div');
    overlay.id = 'session-expired-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.95);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    `;
    
    overlay.innerHTML = `
        <div class="session-spinner" style="
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #44D34E;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        "></div>
        <p style="margin-top: 20px; color: #333; font-size: 14px;">Session expired. Redirecting to login...</p>
        <style>
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    `;
    
    document.body.appendChild(overlay);
    
    setTimeout(() => {
        window.location.href = baseUrl + 'index.php';
    }, 2000);
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSessionChecker);
} else {
    initSessionChecker();
}