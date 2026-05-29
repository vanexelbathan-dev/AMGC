/**
 * OFFLINE SYNC SYSTEM
 * 
 * Kapag offline:
 * - Nag-capture ng lahat ng form submissions at API calls
 * - Ine-store sa IndexedDB queue
 * - Shows notification na "Queued for sync"
 * 
 * Kapag back online:
 * - Automatic mag-send ng lahat na queued
 * - Shows progress
 * - Clears queue pagkatapos
 */

class OfflineSync {
    constructor() {
        this.dbName = 'AMGCOfflineDB';
        this.storeName = 'syncQueue';
        this.db = null;
        this.isOnline = navigator.onLine;
        this.isSyncing = false;

        this.init();
    }

    // Initialize IndexedDB
    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, 1);

            request.onerror = () => {
                console.error('[OfflineSync] IndexedDB failed:', request.error);
                reject(request.error);
            };

            request.onsuccess = () => {
                this.db = request.result;
                console.log('[OfflineSync] IndexedDB ready');
                resolve();
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(this.storeName)) {
                    const store = db.createObjectStore(this.storeName, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('timestamp', 'timestamp', { unique: false });
                    store.createIndex('status', 'status', { unique: false });
                    console.log('[OfflineSync] ObjectStore created');
                }
            };
        });
    }

    // Queue a request para mag-send later
    async queueRequest(method, url, data, formData = null) {
        if (!this.db) {
            console.warn('[OfflineSync] Database not ready');
            return;
        }

        const request = {
            id: null,
            method: method,
            url: url,
            data: data,
            formData: formData,
            timestamp: new Date().getTime(),
            status: 'pending',
            attempts: 0
        };

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const addRequest = store.add(request);

            addRequest.onsuccess = () => {
                console.log('[OfflineSync] Request queued - ID:', addRequest.result);
                this.showQueuedNotification();
                resolve(addRequest.result);
            };

            addRequest.onerror = () => {
                console.error('[OfflineSync] Queue failed:', addRequest.error);
                reject(addRequest.error);
            };
        });
    }

    // Get all pending requests
    async getPendingRequests() {
        if (!this.db) return [];

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readonly');
            const store = transaction.objectStore(this.storeName);
            const index = store.index('status');
            const request = index.getAll('pending');

            request.onsuccess = () => {
                resolve(request.result);
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    // Sync all queued requests
    async syncAll() {
        if (this.isSyncing) {
            console.log('[OfflineSync] Sync already in progress');
            return;
        }

        this.isSyncing = true;
        const requests = await this.getPendingRequests();

        if (requests.length === 0) {
            console.log('[OfflineSync] No requests to sync');
            this.isSyncing = false;
            return;
        }

        console.log('[OfflineSync] Starting sync of', requests.length, 'requests');
        this.showSyncingNotification(0, requests.length);

        let successCount = 0;
        let failureCount = 0;

        for (let i = 0; i < requests.length; i++) {
            const req = requests[i];
            try {
                const result = await this.sendRequest(req);
                if (result) {
                    await this.removeRequest(req.id);
                    successCount++;
                } else {
                    failureCount++;
                }
            } catch (error) {
                console.error('[OfflineSync] Request failed:', error);
                failureCount++;
            }

            this.showSyncingNotification(successCount + failureCount, requests.length);
        }

        this.isSyncing = false;
        this.showSyncCompleteNotification(successCount, failureCount);
        console.log('[OfflineSync] Sync complete. Success:', successCount, 'Failed:', failureCount);
    }

    // Send a single request
    async sendRequest(req) {
        try {
            const options = {
                method: req.method,
                headers: {
                    'X-Offline-Sync': 'true'
                }
            };

            if (req.formData) {
                options.body = req.formData;
            } else if (req.data) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(req.data);
            }

            const response = await fetch(req.url, options);

            if (response.ok) {
                console.log('[OfflineSync] Request sent successfully:', req.url);
                return true;
            } else {
                console.warn('[OfflineSync] Request failed with status:', response.status);
                return false;
            }
        } catch (error) {
            console.error('[OfflineSync] Network error:', error);
            return false;
        }
    }

    // Remove request from queue
    async removeRequest(id) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const request = store.delete(id);

            request.onsuccess = () => {
                resolve();
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    // Intercept form submissions
    interceptForms() {
        document.addEventListener('submit', (e) => {
            const form = e.target;
            
            if (!navigator.onLine) {
                e.preventDefault();
                
                const formData = new FormData(form);
                const action = form.action;
                const method = form.method || 'POST';

                this.queueRequest(method, action, null, formData);
                
                console.log('[OfflineSync] Form submission queued');
            }
        }, true);
    }

    // Intercept fetch calls
    interceptFetch() {
        const originalFetch = window.fetch;
        
        window.fetch = async (...args) => {
            const [resource, config] = args;
            const method = config?.method || 'GET';
            
            // Only intercept POST, PUT, DELETE (not GET)
            if (!navigator.onLine && ['POST', 'PUT', 'DELETE'].includes(method.toUpperCase())) {
                console.log('[OfflineSync] Fetch intercepted and queued:', method, resource);
                
                await this.queueRequest(method, resource, config?.body, null);
                
                // Return fake success response
                return new Response(JSON.stringify({ success: true, message: 'Queued for sync' }), {
                    status: 202,
                    statusText: 'Accepted'
                });
            }
            
            return originalFetch.apply(this, args);
        };
    }

    // Listen for online/offline changes
    setupNetworkListener() {
        window.addEventListener('online', () => {
            console.log('[OfflineSync] Back online! Starting sync...');
            this.isOnline = true;
            this.showOnlineNotification();
            this.syncAll();
        });

        window.addEventListener('offline', () => {
            console.log('[OfflineSync] Connection lost');
            this.isOnline = false;
            this.showOfflineNotification();
        });
    }

    // UI Notifications
    showOfflineNotification() {
        this.removeNotifications();
        const bar = document.createElement('div');
        bar.id = 'offline-status-bar';
        bar.className = 'offline-status offline';
        bar.innerHTML = `
            <div class="offline-content">
                <span>⚠️ You are offline - Data will sync when online</span>
            </div>
        `;
        document.body.insertBefore(bar, document.body.firstChild);
    }

    showOnlineNotification() {
        this.removeNotifications();
        const bar = document.createElement('div');
        bar.id = 'offline-status-bar';
        bar.className = 'offline-status online';
        bar.innerHTML = `
            <div class="offline-content">
                <span>✓ Back online!</span>
            </div>
        `;
        document.body.insertBefore(bar, document.body.firstChild);
        setTimeout(() => bar.remove(), 3000);
    }

    showQueuedNotification() {
        const existingToast = document.getElementById('offline-toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.id = 'offline-toast';
        toast.className = 'offline-toast';
        toast.innerHTML = `
            <div class="toast-content">
                <span>📋 Queued - Will send when online</span>
            </div>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    showSyncingNotification(current, total) {
        const bar = document.getElementById('offline-status-bar');
        if (bar) {
            bar.innerHTML = `
                <div class="offline-content">
                    <span>⏳ Syncing... (${current}/${total})</span>
                </div>
            `;
        }
    }

    showSyncCompleteNotification(success, failure) {
        const bar = document.getElementById('offline-status-bar');
        if (bar) {
            const message = failure === 0 
                ? `✓ All ${success} queued items synced!`
                : `⚠️ Synced: ${success}, Failed: ${failure}`;
            
            bar.innerHTML = `
                <div class="offline-content">
                    <span>${message}</span>
                </div>
            `;
            
            setTimeout(() => bar.remove(), 3000);
        }
    }

    removeNotifications() {
        const existing = document.getElementById('offline-status-bar');
        if (existing) existing.remove();
    }

    // Start everything
    async start() {
        await this.init();
        this.setupNetworkListener();
        this.interceptForms();
        this.interceptFetch();

        // Initial status
        if (navigator.onLine) {
            console.log('[OfflineSync] App started - Online');
        } else {
            console.log('[OfflineSync] App started - Offline');
            this.showOfflineNotification();
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.offlineSync = new OfflineSync();
        window.offlineSync.start();
    });
} else {
    window.offlineSync = new OfflineSync();
    window.offlineSync.start();
}
