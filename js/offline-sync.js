'use strict';

/**
 * OFFLINE SYNC - Main offline queue system
 * Intercepts all requests when offline at stores them for later sync
 */

class OfflineSync {
    constructor() {
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
                }
            };
        });
    }

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
    interceptFetch() {
        const originalFetch = window.fetch;
        
        window.fetch = async (...args) => {
            const [resource, config] = args;
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
        document.body.insertBefore(bar, document.body.firstChild);
        setTimeout(() => bar.remove(), 3000);
    }

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
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

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
