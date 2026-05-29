/**
 * OFFLINE MANAGER - Manages all offline functionality
 * Ito ay ang brain ng offline system - handles storage at sync
 */

class OfflineManager {
    constructor() {
        this.dbName = 'AMGC_OfflineDB';
        this.dbVersion = 1;
        this.db = null;
        this.isOnline = navigator.onLine;
        this.init();
    }

    /**
     * Initialize ang offline system
     */
    async init() {
        console.log('[OfflineManager] Initializing...');
        
        // Setup database
        await this.openDatabase();
        
        // Listen for online/offline changes
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
        
        // Check connection status regularly
        setInterval(() => this.checkConnection(), 5000);
        
<<<<<<< HEAD
        // Track page navigation to cache pages
        this.setupPageTracking();
        
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        console.log('[OfflineManager] Initialized');
    }

    /**
<<<<<<< HEAD
     * Setup page navigation tracking to cache all visited pages
     */
    setupPageTracking() {
        // Save current page HTML when it loads
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.saveCurrentPage());
        } else {
            this.saveCurrentPage();
        }
        
        // Intercept link clicks to save pages
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (link && link.href) {
                const url = new URL(link.href);
                if (url.origin === window.location.origin) {
                    // This is a same-origin link - it will be handled by service worker
                }
            }
        });
    }

    /**
     * Save current page for offline access
     */
    async saveCurrentPage() {
        try {
            const url = window.location.pathname + window.location.search;
            const html = document.documentElement.outerHTML;
            await this.savePage(url, html);
            console.log('[OfflineManager] Current page cached');
        } catch (error) {
            console.warn('[OfflineManager] Could not cache page:', error);
        }
    }

    /**
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
     * Open IndexedDB
     */
    openDatabase() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.dbVersion);

            request.onerror = () => {
                console.error('[OfflineManager] DB Error:', request.error);
                reject(request.error);
            };

            request.onsuccess = () => {
                this.db = request.result;
                console.log('[OfflineManager] Database opened');
                resolve();
            };

            request.onupgradeneeded = (e) => {
                const db = e.target.result;
                
                // Create stores
                if (!db.objectStoreNames.contains('session')) {
                    db.createObjectStore('session', { keyPath: 'key' });
                }
                
                if (!db.objectStoreNames.contains('pages')) {
                    db.createObjectStore('pages', { keyPath: 'url' });
                }
                
                if (!db.objectStoreNames.contains('pending_requests')) {
                    db.createObjectStore('pending_requests', { keyPath: 'id', autoIncrement: true });
                }
                
                console.log('[OfflineManager] Object stores created');
            };
        });
    }

    /**
     * Save session data locally
     * @param {object} sessionData - User session data
     */
    async saveSession(sessionData) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['session'], 'readwrite');
            const store = transaction.objectStore('session');
            
            const data = {
                key: 'current_user',
                user_id: sessionData.user_id,
                username: sessionData.username,
                role: sessionData.role,
                branch_id: sessionData.branch_id,
                timestamp: new Date().getTime()
            };
            
            const request = store.put(data);
            
            request.onsuccess = () => {
                console.log('[OfflineManager] Session saved locally');
                resolve();
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get session data from local storage
     */
    async getSession() {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['session'], 'readonly');
            const store = transaction.objectStore('session');
            const request = store.get('current_user');

            request.onsuccess = () => {
                resolve(request.result || null);
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Clear session data
     */
    async clearSession() {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['session'], 'readwrite');
            const store = transaction.objectStore('session');
            const request = store.clear();

            request.onsuccess = () => {
                console.log('[OfflineManager] Session cleared');
                resolve();
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Save a page for offline access
     * @param {string} url - Page URL
     * @param {string} html - Page HTML
     */
    async savePage(url, html) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pages'], 'readwrite');
            const store = transaction.objectStore('pages');
            
            const pageData = {
                url: url,
                html: html,
                timestamp: new Date().getTime()
            };
            
            const request = store.put(pageData);
            
            request.onsuccess = () => {
                console.log('[OfflineManager] Page saved:', url);
                resolve();
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get a saved page
     * @param {string} url - Page URL
     */
    async getPage(url) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pages'], 'readonly');
            const store = transaction.objectStore('pages');
            const request = store.get(url);

            request.onsuccess = () => {
                resolve(request.result || null);
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Store a pending API request
     * @param {object} requestData - Request details
     */
    async savePendingRequest(requestData) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pending_requests'], 'readwrite');
            const store = transaction.objectStore('pending_requests');
            
            const data = {
                url: requestData.url,
                method: requestData.method,
                data: requestData.data,
                timestamp: new Date().getTime()
            };
            
            const request = store.add(data);
            
            request.onsuccess = () => {
                console.log('[OfflineManager] Request queued for later');
                resolve(request.result);
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get all pending requests
     */
    async getPendingRequests() {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pending_requests'], 'readonly');
            const store = transaction.objectStore('pending_requests');
            const request = store.getAll();

            request.onsuccess = () => {
                resolve(request.result || []);
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Delete a pending request
     */
    async deletePendingRequest(id) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pending_requests'], 'readwrite');
            const store = transaction.objectStore('pending_requests');
            const request = store.delete(id);

            request.onsuccess = () => {
                resolve();
            };
            
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Check if connection is available
     */
    async checkConnection() {
        try {
            const response = await fetch('/index.php', { 
                method: 'HEAD',
                no_cors: true 
            });
            
            if (this.isOnline === false) {
                this.handleOnline();
            }
            this.isOnline = true;
        } catch (error) {
            if (this.isOnline === true) {
                this.handleOffline();
            }
            this.isOnline = false;
        }
    }

    /**
     * Handle online event
     */
    handleOnline() {
        console.log('[OfflineManager] Connection restored!');
        this.isOnline = true;
        document.body.classList.remove('offline');
        document.body.classList.add('online');
        
        // Show notification
        this.showNotification('Connection restored!', 'success');
        
        // Trigger sync
        window.dispatchEvent(new CustomEvent('app:online'));
    }

    /**
     * Handle offline event
     */
    handleOffline() {
        console.log('[OfflineManager] Connection lost!');
        this.isOnline = false;
        document.body.classList.remove('online');
        document.body.classList.add('offline');
        
        // Show notification
        this.showNotification('You are offline - Read-only mode', 'warning');
        
        // Trigger event
        window.dispatchEvent(new CustomEvent('app:offline'));
    }

    /**
     * Show notification
     */
    showNotification(message, type = 'info') {
        // Check if notification element exists
        let notif = document.getElementById('offline-notification');
        if (!notif) {
            notif = document.createElement('div');
            notif.id = 'offline-notification';
            document.body.appendChild(notif);
        }
        
        notif.className = `offline-notification ${type}`;
        notif.textContent = message;
        notif.style.display = 'block';
        
        if (type !== 'warning') {
            setTimeout(() => {
                notif.style.display = 'none';
            }, 3000);
        }
    }

    /**
     * Check if user is logged in offline
     */
    async isLoggedInOffline() {
        const session = await this.getSession();
        return session !== null;
    }

    /**
     * Get offline user data
     */
    async getOfflineUser() {
        return await this.getSession();
    }
}

// Initialize on page load
let offlineManager;
document.addEventListener('DOMContentLoaded', () => {
    offlineManager = new OfflineManager();
});
