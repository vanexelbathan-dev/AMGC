<<<<<<< HEAD
'use strict';

/**
 * OFFLINE SYNC - Main offline queue system
 * Intercepts all requests when offline at stores them for later sync
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
 */

class OfflineSync {
    constructor() {
<<<<<<< HEAD
        this.db = null;
        this.isOnline = navigator.onLine;
        this.isSyncing = false;
        this.init();
    }

    async init() {
        console.log('[OfflineSync] Initializing...');
        
        // Open IndexedDB
        await this.openDB();
        
        // Setup event listeners
        this.setupListeners();
        
        // Intercept fetch immediately
        this.interceptFetch();
        
        // Intercept forms immediately
        this.interceptForms();
        
        // Show initial status
        if (!this.isOnline) {
            this.showOfflineBar();
        }
        
        console.log('[OfflineSync] Ready. Online:', this.isOnline);
    }

    /**
     * Open IndexedDB
     */
    openDB() {
        return new Promise((resolve) => {
            const req = indexedDB.open('AMGC_Offline', 1);
            
            req.onerror = () => {
                console.error('[OfflineSync] DB error:', req.error);
                resolve();
            };
            
            req.onsuccess = () => {
                this.db = req.result;
                console.log('[OfflineSync] DB opened');
                resolve();
            };
            
            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains('queue')) {
                    db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
                    console.log('[OfflineSync] Queue store created');
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                }
            };
        });
    }

<<<<<<< HEAD
    /**
     * Setup online/offline listeners
     */
    setupListeners() {
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
    }

    /**
     * Intercept all fetch calls
     */
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    interceptFetch() {
        const originalFetch = window.fetch;
        
        window.fetch = async (...args) => {
            const [resource, config] = args;
<<<<<<< HEAD
            const method = (config?.method || 'GET').toUpperCase();
            
            // Only intercept POST, PUT, DELETE when offline
            if (!this.isOnline && ['POST', 'PUT', 'DELETE'].includes(method)) {
                console.log('[OfflineSync] Fetch intercepted:', method, resource);
                
                // Queue the request
                await this.queueRequest(method, resource, config);
                
                // Return fake success response
                return new Response(
                    JSON.stringify({ success: true, message: 'Queued for sync', offline: true }),
                    { status: 202, statusText: 'Queued' }
                );
            }
            
            // Online or GET request - let it go through
            return originalFetch.apply(window, args);
        };
        
        console.log('[OfflineSync] Fetch intercepted');
    }

    /**
     * Intercept form submissions
     */
    interceptForms() {
        document.addEventListener('submit', (e) => {
            if (!this.isOnline) {
                const form = e.target;
                const method = (form.method || 'POST').toUpperCase();
                
                if (['POST', 'PUT', 'DELETE'].includes(method)) {
                    e.preventDefault();
                    
                    const formData = new FormData(form);
                    const action = form.action || window.location.href;
                    
                    this.queueRequest(method, action, { body: formData });
                    
                    this.showQueuedToast();
                    console.log('[OfflineSync] Form queued:', action);
                }
            }
        }, true);
        
        console.log('[OfflineSync] Forms intercepted');
    }

    /**
     * Queue a request for later
     */
    async queueRequest(method, url, config) {
        if (!this.db) return;
        
        const item = {
            method: method,
            url: url,
            config: config,
            timestamp: Date.now(),
            attempts: 0
        };
        
        return new Promise((resolve) => {
            const tx = this.db.transaction(['queue'], 'readwrite');
            const store = tx.objectStore('queue');
            const req = store.add(item);
            
            req.onsuccess = () => {
                console.log('[OfflineSync] Queued:', method, url);
                this.showQueuedToast();
                resolve();
            };
            
            req.onerror = () => {
                console.error('[OfflineSync] Queue failed:', req.error);
                resolve();
            };
        });
    }

    /**
     * Get all queued requests
     */
    async getQueue() {
        if (!this.db) return [];
        
        return new Promise((resolve) => {
            const tx = this.db.transaction(['queue'], 'readonly');
            const store = tx.objectStore('queue');
            const req = store.getAll();
            
            req.onsuccess = () => {
                resolve(req.result || []);
            };
            
            req.onerror = () => {
                resolve([]);
            };
        });
    }

    /**
     * Delete queued item
     */
    async deleteQueue(id) {
        if (!this.db) return;
        
        return new Promise((resolve) => {
            const tx = this.db.transaction(['queue'], 'readwrite');
            const store = tx.objectStore('queue');
            const req = store.delete(id);
            
            req.onsuccess = () => {
                resolve();
            };
            
            req.onerror = () => {
                resolve();
            };
        });
    }

    /**
     * Sync all queued requests
     */
    async syncAll() {
        if (this.isSyncing) {
            console.log('[OfflineSync] Already syncing');
            return;
        }
        
        this.isSyncing = true;
        const queue = await this.getQueue();
        
        if (queue.length === 0) {
            console.log('[OfflineSync] Queue is empty');
            this.isSyncing = false;
            return;
        }
        
        console.log('[OfflineSync] Syncing', queue.length, 'items');
        this.showSyncingBar(0, queue.length);
        
        let success = 0;
        let failed = 0;
        
        for (let i = 0; i < queue.length; i++) {
            const item = queue[i];
            
            try {
                const response = await fetch(item.url, {
                    method: item.method,
                    body: item.config?.body,
                    headers: item.config?.headers || {}
                });
                
                if (response.ok) {
                    await this.deleteQueue(item.id);
                    success++;
                    console.log('[OfflineSync] Sent:', item.method, item.url);
                } else {
                    failed++;
                }
            } catch (err) {
                console.error('[OfflineSync] Send failed:', err);
                failed++;
            }
            
            this.showSyncingBar(success + failed, queue.length);
        }
        
        this.isSyncing = false;
        this.showSyncCompleteBar(success, failed);
        console.log('[OfflineSync] Sync done. Success:', success, 'Failed:', failed);
    }

    /**
     * Handle when coming online
     */
    async handleOnline() {
        console.log('[OfflineSync] Coming online!');
        this.isOnline = true;
        
        this.showOnlineBar();
        await this.syncAll();
    }

    /**
     * Handle when going offline
     */
    handleOffline() {
        console.log('[OfflineSync] Going offline');
        this.isOnline = false;
        this.showOfflineBar();
    }

    /**
     * UI - Show offline bar
     */
    showOfflineBar() {
        this.removeBar();
        const bar = document.createElement('div');
        bar.id = 'offline-sync-bar';
        bar.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #fbbf24;
            color: #78350f;
            padding: 10px;
            text-align: center;
            font-weight: 500;
            z-index: 10000;
            border-bottom: 2px solid #f59e0b;
        `;
        bar.textContent = '⚠️ You are offline - Data will queue and send when online';
        document.body.insertBefore(bar, document.body.firstChild);
    }

    /**
     * UI - Show online bar
     */
    showOnlineBar() {
        this.removeBar();
        const bar = document.createElement('div');
        bar.id = 'offline-sync-bar';
        bar.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #10b981;
            color: white;
            padding: 10px;
            text-align: center;
            font-weight: 500;
            z-index: 10000;
            border-bottom: 2px solid #059669;
        `;
        bar.textContent = '✓ Back online!';
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        document.body.insertBefore(bar, document.body.firstChild);
        setTimeout(() => bar.remove(), 3000);
    }

<<<<<<< HEAD
    /**
     * UI - Show syncing bar
     */
    showSyncingBar(current, total) {
        const bar = document.getElementById('offline-sync-bar');
        if (bar) {
            bar.textContent = `⏳ Syncing... (${current}/${total})`;
        }
    }

    /**
     * UI - Show sync complete
     */
    showSyncCompleteBar(success, failed) {
        const bar = document.getElementById('offline-sync-bar');
        if (bar) {
            if (failed === 0) {
                bar.textContent = `✓ All ${success} items synced!`;
                bar.style.background = '#10b981';
            } else {
                bar.textContent = `⚠️ Synced: ${success}, Failed: ${failed}`;
                bar.style.background = '#ef4444';
            }
            setTimeout(() => bar.remove(), 3000);
        }
    }

    /**
     * UI - Show queued toast
     */
    showQueuedToast() {
        const existing = document.getElementById('offline-queued-toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.id = 'offline-queued-toast';
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #3b82f6;
            color: white;
            padding: 12px 16px;
            border-radius: 6px;
            font-weight: 500;
            z-index: 9999;
        `;
        toast.textContent = '📋 Queued - Will send when online';
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

<<<<<<< HEAD
    /**
     * Remove status bar
     */
    removeBar() {
        const bar = document.getElementById('offline-sync-bar');
        if (bar) bar.remove();
    }
}

// Start on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.offlineSync = new OfflineSync();
    });
} else {
    window.offlineSync = new OfflineSync();
}

console.log('[OfflineSync] Script loaded');
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
