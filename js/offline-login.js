/**
 * OFFLINE LOGIN HANDLER - Allows login/logout while offline
 * Ito ay nag-manage ng offline authentication using IndexedDB
 */

class OfflineLoginHandler {
    constructor() {
        this.offlineManager = null;
        this.loginForm = document.getElementById('login-form');
        this.logoutBtns = document.querySelectorAll('[data-action="logout"]');
        this.init();
    }

    async init() {
        console.log('[OfflineLoginHandler] Initializing...');
        
        // Wait for offline manager to be ready
        this.waitForOfflineManager().then(() => {
            this.setupLoginHandler();
            this.setupLogoutHandlers();
            this.checkOfflineLogin();
        });
    }

    /**
     * Wait for offline manager to initialize
     */
    waitForOfflineManager() {
        return new Promise((resolve) => {
            const checkInterval = setInterval(() => {
                if (typeof offlineManager !== 'undefined' && offlineManager.db) {
                    clearInterval(checkInterval);
                    this.offlineManager = offlineManager;
                    resolve();
                }
            }, 100);
            
            // Timeout after 5 seconds
            setTimeout(() => {
                clearInterval(checkInterval);
                console.warn('[OfflineLoginHandler] Timeout waiting for offline manager');
                resolve();
            }, 5000);
        });
    }

    /**
     * Setup login form handler
     */
    setupLoginHandler() {
        if (!this.loginForm) {
            console.log('[OfflineLoginHandler] No login form found');
            return;
        }

        console.log('[OfflineLoginHandler] Setting up login handler');

        this.loginForm.addEventListener('submit', async (e) => {
            // Let normal submission happen for online mode
            // But save credentials for offline use
            const email = document.getElementById('email')?.value;
            const password = document.getElementById('password')?.value;

            if (email && password && this.offlineManager) {
                console.log('[OfflineLoginHandler] Saving login attempt offline');
                
                // Store attempted credentials temporarily
                // These will be cleared after successful online login
                localStorage.setItem('_temp_offline_email', email);
                localStorage.setItem('_temp_offline_password', password);
            }
        });
    }

    /**
     * Setup logout handlers
     */
    setupLogoutHandlers() {
        this.logoutBtns.forEach((btn) => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.handleLogout();
            });
        });

        console.log(`[OfflineLoginHandler] Setup ${this.logoutBtns.length} logout buttons`);
    }

    /**
     * Check if user can login offline
     */
    async checkOfflineLogin() {
        console.log('[OfflineLoginHandler] Checking offline login...');

        if (!this.offlineManager) {
            return;
        }

        const session = await this.offlineManager.getSession();
        
        if (session) {
            console.log('[OfflineLoginHandler] User is logged in offline:', session.username);
        }
    }

    /**
     * Perform offline login (after successful server login)
     */
    async saveLoginOffline(sessionData) {
        if (!this.offlineManager) {
            console.warn('[OfflineLoginHandler] Offline manager not ready');
            return false;
        }

        try {
            await this.offlineManager.saveSession({
                user_id: sessionData.user_id,
                username: sessionData.username || sessionData.first_name,
                role: sessionData.role,
                branch_id: sessionData.branch_id || 0
            });

            console.log('[OfflineLoginHandler] Login saved offline');
            return true;
        } catch (error) {
            console.error('[OfflineLoginHandler] Error saving offline login:', error);
            return false;
        }
    }

    /**
     * Handle logout
     */
    async handleLogout() {
        console.log('[OfflineLoginHandler] Logging out...');

        if (this.offlineManager) {
            try {
                await this.offlineManager.clearSession();
                console.log('[OfflineLoginHandler] Offline session cleared');
            } catch (error) {
                console.error('[OfflineLoginHandler] Error clearing session:', error);
            }
        }

        // Clear temp credentials
        localStorage.removeItem('_temp_offline_email');
        localStorage.removeItem('_temp_offline_password');

        // Redirect to login
        window.location.href = '/login.php';
    }

    /**
     * Check if user is logged in (online or offline)
     */
    async isLoggedIn() {
        // First check server session
        try {
            const response = await fetch('/check-session.php');
            if (response.ok) {
                return true;
            }
        } catch (error) {
            console.log('[OfflineLoginHandler] Server check failed, checking offline');
        }

        // Fallback to offline session
        if (this.offlineManager) {
            const session = await this.offlineManager.getSession();
            return session !== null;
        }

        return false;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    new OfflineLoginHandler();
});
