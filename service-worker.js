/**
 * SERVICE WORKER - AMGC Offline Mode
 * Location: /AMGC/service-worker.js
 * Scope: /AMGC/
 */

const CACHE_NAME = 'amgc-offline-v6';
const OFFLINE_PAGE = '/AMGC/sales/currentinventory.php';

// Lahat ng files na ika-cache para magamit offline
const URLS_TO_CACHE = [
    // Root pages
    '/AMGC/',
    '/AMGC/index.php',
    '/AMGC/login.php',
    
    // Sales pages - eto yung mga gusto mong ma-access offline
    '/AMGC/sales/currentinventory.php',
    '/AMGC/sales/orderproduct.php',
    '/AMGC/sales/sales_order.php',
    '/AMGC/sales/customer.php',
    '/AMGC/sales/credit_discount_request.php',
    '/AMGC/sales/returnedmerchandise.php',
    
    // CSS files
    '/AMGC/css/sales.css',
    '/AMGC/css/offline-mode.css',
    '/AMGC/css/global.css',
    '/AMGC/css/style.css',
    
    // JavaScript files
    '/AMGC/js/offline-manager.js',
    '/AMGC/js/offline-sync.js',
    '/AMGC/js/offline-login.js',
    '/AMGC/js/offline-db.js',
    '/AMGC/js/offline-sync-manager.js',
    '/AMGC/js/offline-ui-indicator.js',
    '/AMGC/js/offline-simple.js',
    '/AMGC/js/register-sw.js',
    
    // Pictures/Images
    '/AMGC/Pictures/amgc3DLogo.png',
    '/AMGC/Pictures/favicon-96x96.png',
    '/AMGC/Pictures/favicon.svg',
    '/AMGC/Pictures/apple-touch-icon.png',
    '/AMGC/Pictures/site.webmanifest',
    
    // External CDNs (optional - para magamit din offline)
    'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js'
];

// ============================================================
// INSTALL EVENT - Cache lahat ng essential files
// ============================================================
self.addEventListener('install', (event) => {
    console.log('[SW] 🚀 Installing Service Worker v6...');
    
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] 📦 Caching', URLS_TO_CACHE.length, 'files...');
                
                // Cache isa-isa para hindi mag-fail lahat kung may isang error
                return Promise.allSettled(
                    URLS_TO_CACHE.map(url => {
                        return cache.add(url).catch(err => {
                            console.warn('[SW] ⚠️ Failed to cache:', url, err.message);
                        });
                    })
                );
            })
            .then(() => {
                console.log('[SW] ✅ Caching complete!');
                return self.skipWaiting();
            })
    );
});

// ============================================================
// ACTIVATE EVENT - Clean up old caches
// ============================================================
self.addEventListener('activate', (event) => {
    console.log('[SW] 🎯 Activating Service Worker...');
    
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName !== CACHE_NAME) {
                            console.log('[SW] 🗑️ Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => {
                console.log('[SW] ✅ Activation complete!');
                return self.clients.claim();
            })
    );
});

// ============================================================
// FETCH EVENT - Serve from cache when offline
// ============================================================
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }
    
    // Skip browser extensions
    if (url.protocol === 'chrome-extension:' || url.protocol === 'moz-extension:') {
        return;
    }
    
    // Skip API/AJAX requests (may action parameter)
    if (url.searchParams.has('action') || 
        url.pathname.includes('/api/') ||
        event.request.headers.get('X-Requested-With') === 'XMLHttpRequest') {
        // Let AJAX requests go to network (handled by page's offline sync)
        return;
    }
    
    // Handle same-origin and CDN requests
    const isSameOrigin = url.origin === self.location.origin;
    const isCDN = url.hostname.includes('cdn.jsdelivr.net') || 
                  url.hostname.includes('cdnjs.cloudflare.com');
    
    if (!isSameOrigin && !isCDN) {
        return;
    }
    
    // Check if this is a navigation request (HTML page)
    const isNavigation = event.request.mode === 'navigate' || 
                         (event.request.headers.get('accept') && 
                          event.request.headers.get('accept').includes('text/html'));
    
    if (isNavigation) {
        // HTML pages: Network first, fallback to cache
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    // Cache the latest version for offline use
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, clone);
                            console.log('[SW] 💾 Cached (navigation):', url.pathname);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // Network failed - serve from cache
                    return caches.match(event.request)
                        .then((cachedResponse) => {
                            if (cachedResponse) {
                                console.log('[SW] 📦 Serving from cache:', url.pathname);
                                
                                // Add offline header para malaman ng page
                                const modifiedResponse = new Response(cachedResponse.body, {
                                    status: cachedResponse.status,
                                    statusText: cachedResponse.statusText,
                                    headers: new Headers(cachedResponse.headers)
                                });
                                modifiedResponse.headers.append('X-Offline-Mode', 'true');
                                return modifiedResponse;
                            }
                            
                            // Not in cache - try to serve the offline fallback page
                            console.log('[SW] ❌ Not in cache, serving fallback');
                            return caches.match(OFFLINE_PAGE) || caches.match('/AMGC/');
                        });
                })
        );
    } else {
        // For assets (CSS, JS, images): Cache first, then network (stale-while-revalidate)
        event.respondWith(
            caches.match(event.request)
                .then((cachedResponse) => {
                    if (cachedResponse) {
                        // Return cached immediately
                        // Fetch fresh version in background
                        fetch(event.request)
                            .then((networkResponse) => {
                                if (networkResponse && networkResponse.status === 200) {
                                    caches.open(CACHE_NAME).then((cache) => {
                                        cache.put(event.request, networkResponse);
                                        console.log('[SW] 🔄 Updated cache:', url.pathname);
                                    });
                                }
                            })
                            .catch(() => {});
                        
                        return cachedResponse;
                    }
                    
                    // Not in cache - fetch from network
                    return fetch(event.request)
                        .then((networkResponse) => {
                            // Cache for future offline use
                            if (networkResponse && networkResponse.status === 200) {
                                const clone = networkResponse.clone();
                                caches.open(CACHE_NAME).then((cache) => {
                                    cache.put(event.request, clone);
                                    console.log('[SW] 💾 Cached (asset):', url.pathname);
                                });
                            }
                            return networkResponse;
                        })
                        .catch((error) => {
                            console.warn('[SW] ⚠️ Failed to fetch asset:', url.pathname, error);
                            
                            // Return fallback for images
                            if (event.request.destination === 'image') {
                                return new Response(
                                    `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
                                        <rect fill="#e0e0e0" width="100" height="100"/>
                                        <text x="50%" y="50%" text-anchor="middle" dy=".3em" fill="#999" font-size="12">📷</text>
                                    </svg>`,
                                    { headers: { 'Content-Type': 'image/svg+xml' } }
                                );
                            }
                            
                            // Return empty for other assets
                            return new Response('', { status: 408 });
                        });
                })
        );
    }
});

// ============================================================
// MESSAGE EVENT - Communication with pages
// ============================================================
self.addEventListener('message', (event) => {
    console.log('[SW] 📨 Received message:', event.data);
    
    if (!event.data) return;
    
    // Skip waiting (force update)
    if (event.data.type === 'SKIP_WAITING') {
        console.log('[SW] ⏩ Skipping waiting...');
        self.skipWaiting();
    }
    
    // Get cache info
    if (event.data.type === 'GET_CACHE_INFO') {
        caches.open(CACHE_NAME).then((cache) => {
            cache.keys().then((keys) => {
                const cachedUrls = keys.map(req => new URL(req.url).pathname);
                event.ports[0].postMessage({
                    cacheName: CACHE_NAME,
                    cachedCount: keys.length,
                    cachedUrls: cachedUrls
                });
            });
        });
    }
    
    // Clear cache
    if (event.data.type === 'CLEAR_CACHE') {
        caches.delete(CACHE_NAME).then(() => {
            console.log('[SW] 🗑️ Cache cleared');
            event.ports[0].postMessage({ success: true });
        });
    }
    
    // Add URL to cache
    if (event.data.type === 'CACHE_URL' && event.data.url) {
        caches.open(CACHE_NAME).then((cache) => {
            cache.add(event.data.url)
                .then(() => {
                    console.log('[SW] 💾 Manually cached:', event.data.url);
                    event.ports[0].postMessage({ success: true });
                })
                .catch((err) => {
                    event.ports[0].postMessage({ success: false, error: err.message });
                });
        });
    }
    
    // Get list of cached pages (HTML only)
    if (event.data.type === 'GET_CACHED_PAGES') {
        caches.open(CACHE_NAME).then((cache) => {
            cache.keys().then((keys) => {
                const pages = keys
                    .map(req => new URL(req.url).pathname)
                    .filter(path => 
                        path.endsWith('.php') || 
                        path.endsWith('.html') || 
                        path === '/AMGC/' ||
                        path === '/AMGC'
                    )
                    .sort();
                
                event.ports[0].postMessage({ pages: pages });
            });
        });
    }
});

// ============================================================
// SYNC EVENT - Background sync (for offline actions)
// ============================================================
self.addEventListener('sync', (event) => {
    console.log('[SW] 🔄 Background sync event:', event.tag);
    
    if (event.tag === 'sync-pending-orders') {
        event.waitUntil(syncPendingOrders());
    } else if (event.tag === 'sync-pending-returns') {
        event.waitUntil(syncPendingReturns());
    } else if (event.tag === 'sync-all') {
        event.waitUntil(syncAllPending());
    }
});

// Sync pending orders
async function syncPendingOrders() {
    console.log('[SW] 📤 Syncing pending orders...');
    
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach(client => {
        client.postMessage({
            type: 'SYNC_PENDING_ORDERS',
            message: 'Please sync pending orders'
        });
    });
}

// Sync pending returns
async function syncPendingReturns() {
    console.log('[SW] 📤 Syncing pending returns...');
    
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach(client => {
        client.postMessage({
            type: 'SYNC_PENDING_RETURNS',
            message: 'Please sync pending returns'
        });
    });
}

// Sync all pending items
async function syncAllPending() {
    console.log('[SW] 📤 Syncing all pending items...');
    
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach(client => {
        client.postMessage({
            type: 'SYNC_ALL',
            message: 'Please sync all pending items'
        });
    });
}

// ============================================================
// PUSH EVENT - For notifications (optional)
// ============================================================
self.addEventListener('push', (event) => {
    console.log('[SW] 📬 Push notification received');
    
    const title = 'AMGC Sales';
    const options = {
        body: event.data ? event.data.text() : 'New notification',
        icon: '/AMGC/Pictures/favicon-96x96.png',
        badge: '/AMGC/Pictures/favicon-96x96.png'
    };
    
    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] 👆 Notification clicked');
    
    event.notification.close();
    
    event.waitUntil(
        clients.matchAll({ type: 'window' }).then((clientList) => {
            // If a window is already open, focus it
            for (const client of clientList) {
                if (client.url.includes('/AMGC/') && 'focus' in client) {
                    return client.focus();
                }
            }
            // Otherwise, open a new window
            if (clients.openWindow) {
                return clients.openWindow('/AMGC/sales/currentinventory.php');
            }
        })
    );
});

console.log('[SW] ✅ Service Worker v6 loaded and ready!');
console.log('[SW] 📍 Scope:', self.registration.scope);
console.log('[SW] 📦 Will cache:', URLS_TO_CACHE.length, 'files');