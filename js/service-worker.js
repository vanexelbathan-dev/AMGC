/**
 * Service Worker for AMGC Offline Mode
 * Handles caching, offline detection, and request interception
 */

<<<<<<< HEAD
const CACHE_NAME = 'amgc-offline-cache-v2';
const OFFLINE_URL = '/sales/offline.html';

// Static assets to cache immediately
=======
const CACHE_NAME = 'amgc-offline-cache-v1';
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/login.php',
  '/css/style.css',
  '/css/global.css',
<<<<<<< HEAD
  '/css/sales.css',
  '/js/offline-db.js',
  '/js/offline-sync-manager.js',
  '/js/offline-ui-indicator.js',
  '/js/offline-simple.js',
  '/sales/offline.html',
  '/sales/currentinventory.php',
  '/sales/orderproduct.php',
  '/sales/sales_order.php',
  '/sales/customer.php',
  '/sales/credit_discount_request.php',
  '/sales/returnedmerchandise.php',
  '/Pictures/amgc3DLogo.png',
  '/Pictures/favicon-96x96.png',
  '/Pictures/favicon.svg',
  '/Pictures/apple-touch-icon.png',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js'
=======
  '/js/offline-db.js',
  '/js/offline-sync-manager.js',
  '/js/offline-ui-indicator.js',
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
];

/**
 * Install event - cache static assets
 */
self.addEventListener('install', (event) => {
  console.log('[Service Worker] Installing...');
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[Service Worker] Caching static assets');
<<<<<<< HEAD
      return cache.addAll(STATIC_ASSETS).catch(err => {
        console.warn('[Service Worker] Some assets failed to cache:', err);
      });
=======
      return cache.addAll(STATIC_ASSETS);
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    })
  );
  self.skipWaiting();
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
  console.log('[Service Worker] Activating...');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((cacheName) => cacheName !== CACHE_NAME)
          .map((cacheName) => caches.delete(cacheName))
      );
    })
  );
  self.clients.claim();
});

/**
 * Fetch event - intercept requests and handle offline
 */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

<<<<<<< HEAD
  // Skip non-GET requests for navigation handling
  if (request.method !== 'GET') {
    // For POST/PUT/DELETE, use network-first with offline queue
    if (url.origin === location.origin) {
      event.respondWith(handleMutationRequest(request));
    }
    return;
  }

  // Skip chrome-extension requests
  if (url.protocol === 'chrome-extension:') {
    return;
  }

  // Skip API/AJAX requests that should always go to network first
  if (url.searchParams.has('action') || 
      url.pathname.includes('get_') ||
      request.headers.get('X-Requested-With') === 'XMLHttpRequest') {
    event.respondWith(handleAPIRequest(request));
    return;
  }

  // For HTML pages (navigation) - Network first, fallback to cache, then offline page
  if (request.mode === 'navigate' || 
      (request.headers.get('accept') && 
       request.headers.get('accept').includes('text/html'))) {
    event.respondWith(handleNavigationRequest(request));
    return;
  }

  // For static assets (CSS, JS, images) - Cache first, then network
  if (url.origin === location.origin || 
      url.hostname.includes('cdn.jsdelivr.net') ||
      url.hostname.includes('cdnjs.cloudflare.com')) {
    event.respondWith(handleAssetRequest(request));
    return;
  }

  // Default: Network first
  event.respondWith(
    fetch(request).catch(() => caches.match(request))
  );
});

/**
 * Handle navigation requests (HTML pages)
 * Network first, then cache, then offline page
 */
async function handleNavigationRequest(request) {
  try {
    // Try network first
    const networkResponse = await fetch(request);
    
    // Cache the latest version for offline use
    if (networkResponse && networkResponse.status === 200) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    // Network failed, try cache
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      console.log('[SW] Serving from cache:', request.url);
      
      // Add offline header to indicate cached response
      const modifiedResponse = new Response(cachedResponse.body, {
        status: cachedResponse.status,
        statusText: cachedResponse.statusText,
        headers: new Headers(cachedResponse.headers),
      });
      modifiedResponse.headers.append('X-Offline-Cache', 'true');
      return modifiedResponse;
    }
    
    // Not in cache, show offline page
    console.log('[SW] No cache found, showing offline page');
    return caches.match(OFFLINE_URL) || offlineResponse();
  }
}

/**
 * Handle API requests with network-first strategy
 */
async function handleAPIRequest(request) {
  try {
    // Try network first
    const response = await fetch(request.clone());
    
    // Cache successful GET responses
    if (request.method === 'GET' && response.status === 200) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    
    return response;
  } catch (error) {
    // Network failed
    if (request.method === 'GET') {
      // Try to return cached response
      const cachedResponse = await caches.match(request);
      if (cachedResponse) {
        const modifiedResponse = new Response(cachedResponse.body, {
          status: cachedResponse.status,
          statusText: cachedResponse.statusText,
          headers: new Headers(cachedResponse.headers),
        });
        modifiedResponse.headers.append('X-Offline-Cache', 'true');
        return modifiedResponse;
      }
    }
    
    // Return offline JSON response for AJAX
    return new Response(
      JSON.stringify({
        success: false,
        message: 'You are currently offline. This action will be queued.',
        offline: true
      }),
      {
        status: 200,
        headers: {
          'Content-Type': 'application/json',
          'X-Offline-Queued': 'true'
        }
      }
    );
  }
}

/**
 * Handle asset requests (CSS, JS, images) with cache-first strategy
 */
async function handleAssetRequest(request) {
  // Check cache first
  const cachedResponse = await caches.match(request);
  if (cachedResponse) {
    // Return cached response immediately
    // Fetch update in background (stale-while-revalidate)
    fetch(request).then(response => {
      if (response && response.status === 200) {
        caches.open(CACHE_NAME).then(cache => {
          cache.put(request, response);
        });
      }
    }).catch(() => {});
    
    return cachedResponse;
  }

  // Not in cache, fetch from network
  try {
    const networkResponse = await fetch(request);
    
    // Cache successful responses
    if (networkResponse && networkResponse.status === 200) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    // Network failed and not in cache
    // Return fallback for images
    if (request.destination === 'image') {
      return new Response(
        `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
          <rect fill="#e0e0e0" width="100" height="100"/>
          <text x="50%" y="50%" text-anchor="middle" dy=".3em" fill="#999" font-size="12">📷</text>
        </svg>`,
        { headers: { 'Content-Type': 'image/svg+xml' } }
      );
    }
    
    throw error;
=======
  // Skip cross-origin requests
  if (url.origin !== location.origin) {
    return;
  }

  // Handle API requests differently
  if (url.pathname.includes('.php')) {
    handleAPIRequest(event);
  } else {
    handleAssetRequest(event);
  }
});

/**
 * Handle API requests with network-first strategy
 */
function handleAPIRequest(event) {
  const { request } = event;

  // For GET requests, try network first, fall back to cache
  if (request.method === 'GET') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Cache successful responses
          if (response.status === 200) {
            const cloneResponse = response.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(request, cloneResponse);
            });
          }
          return response;
        })
        .catch(() => {
          // Return cached version on network failure
          return caches.match(request).then((cachedResponse) => {
            if (cachedResponse) {
              // Add offline header to indicate cached response
              const modifiedResponse = new Response(cachedResponse.body, {
                status: cachedResponse.status,
                statusText: cachedResponse.statusText,
                headers: new Headers(cachedResponse.headers),
              });
              modifiedResponse.headers.append('X-Offline-Cache', 'true');
              return modifiedResponse;
            }
            // Return offline page if no cache exists
            return offlineResponse();
          });
        })
    );
  } else {
    // For POST/PUT/DELETE, queue them for sync and return optimistic response
    event.respondWith(handleMutationRequest(request));
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
  }
}

/**
 * Handle mutation requests (POST, PUT, DELETE)
 */
async function handleMutationRequest(request) {
  try {
    // Try to send to server first
    const response = await fetch(request.clone());
    return response;
  } catch (error) {
    // Queue for sync and return optimistic response
    const requestData = {
      id: generateUUID(),
<<<<<<< HEAD
      url: request.url,
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
      endpoint: new URL(request.url).pathname,
      method: request.method,
      headers: Object.fromEntries(request.headers),
      body: await request.clone().text(),
      timestamp: Date.now(),
      status: 'pending',
<<<<<<< HEAD
      retries: 0
    };

    // Store in IndexedDB or localStorage (fallback)
    await storeSyncRequest(requestData);

    // Notify all clients about the queued request
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach(client => {
      client.postMessage({
        type: 'REQUEST_QUEUED',
        data: { id: requestData.id, endpoint: requestData.endpoint }
      });
    });
=======
      retries: 0,
    };

    // Store in sync queue (will be picked up by sync manager)
    localStorage.setItem(
      `sync_queue_${requestData.id}`,
      JSON.stringify(requestData)
    );
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

    // Return optimistic response
    return new Response(
      JSON.stringify({
        success: true,
        message: 'Request queued for sync',
<<<<<<< HEAD
        data: { sync_id: requestData.id, offline: true }
=======
        data: { sync_id: requestData.id, offline: true },
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
      }),
      {
        status: 200,
        headers: {
          'Content-Type': 'application/json',
<<<<<<< HEAD
          'X-Offline-Queued': 'true'
        }
=======
          'X-Offline-Queued': 'true',
        },
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
      }
    );
  }
}

/**
<<<<<<< HEAD
 * Store sync request in IndexedDB
 */
async function storeSyncRequest(requestData) {
  // Fallback to localStorage
  try {
    const syncQueue = await getSyncQueue();
    syncQueue.push(requestData);
    localStorage.setItem('amgc_sync_queue', JSON.stringify(syncQueue));
  } catch (e) {
    console.error('[SW] Failed to store sync request:', e);
  }
}

/**
 * Get sync queue from localStorage
 */
async function getSyncQueue() {
  try {
    const queue = localStorage.getItem('amgc_sync_queue');
    return queue ? JSON.parse(queue) : [];
  } catch (e) {
    return [];
  }
=======
 * Handle asset requests with cache-first strategy
 */
function handleAssetRequest(event) {
  const { request } = event;

  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }

      return fetch(request)
        .then((response) => {
          // Don't cache non-200 responses
          if (!response || response.status !== 200) {
            return response;
          }

          const cloneResponse = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, cloneResponse);
          });

          return response;
        })
        .catch(() => {
          // Return offline page
          return offlineResponse();
        });
    })
  );
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
}

/**
 * Generate UUID for sync queue
 */
function generateUUID() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

/**
<<<<<<< HEAD
 * Return offline page response
 */
function offlineResponse() {
  return new Response(
    `<!DOCTYPE html>
    <html>
      <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Offline - AMGC Sales</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
        <style>
          :root { --primary-green: #2E7D32; --dark-green: #1B5E20; }
          body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
            font-family: system-ui, sans-serif;
            margin: 0;
            padding: 20px;
          }
          .offline-container {
            max-width: 500px;
            width: 100%;
            text-align: center;
            background: white;
            border-radius: 24px;
            padding: 40px 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
          }
          .offline-icon { font-size: 80px; color: var(--primary-green); margin-bottom: 20px; }
          h1 { font-size: 28px; font-weight: 700; color: #212121; margin-bottom: 10px; }
          .offline-message { color: #666; margin-bottom: 30px; font-size: 16px; }
          .cached-pages {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin: 25px 0;
            text-align: left;
          }
          .cached-pages h5 { color: var(--primary-green); font-weight: 600; margin-bottom: 15px; }
          .cached-pages ul { list-style: none; padding: 0; margin: 0; }
          .cached-pages li { padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
          .cached-pages li:last-child { border-bottom: none; }
          .cached-pages a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #333;
            text-decoration: none;
            font-weight: 500;
          }
          .cached-pages a:hover { color: var(--primary-green); }
          .cached-pages a i { width: 24px; color: var(--primary-green); }
          .btn-retry {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
          }
          .btn-retry:hover { background: var(--dark-green); }
          .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #FF9800;
            margin-right: 8px;
            animation: pulse 2s infinite;
          }
          @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
          }
=======
 * Return offline page
 */
function offlineResponse() {
  return new Response(
    `
    <html>
      <head>
        <title>Offline</title>
        <style>
          body {
            font-family: system-ui;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background: #f5f5f5;
          }
          .offline-container {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
          }
          h1 { color: #d32f2f; margin: 0; }
          p { color: #666; margin: 10px 0 0; }
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        </style>
      </head>
      <body>
        <div class="offline-container">
<<<<<<< HEAD
          <div class="offline-icon"><i class="bi bi-wifi-off"></i></div>
          <h1>You're Offline</h1>
          <p class="offline-message">Don't worry! You can still access cached pages.</p>
          <div class="cached-pages">
            <h5><i class="bi bi-files me-2"></i>Cached Pages</h5>
            <ul>
              <li><a href="/sales/currentinventory.php"><i class="bi bi-speedometer2"></i>Dashboard</a></li>
              <li><a href="/sales/orderproduct.php"><i class="bi bi-bag"></i>Order Product</a></li>
              <li><a href="/sales/sales_order.php"><i class="bi bi-list-check"></i>Sales Orders</a></li>
              <li><a href="/sales/customer.php"><i class="bi bi-people"></i>Customers</a></li>
              <li><a href="/sales/returnedmerchandise.php"><i class="bi bi-arrow-counterclockwise"></i>Returns</a></li>
            </ul>
          </div>
          <button class="btn-retry" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i>Retry Connection
          </button>
          <p style="margin-top: 20px; font-size: 14px; color: #999;">
            <span class="status-indicator"></span>Offline Mode
          </p>
        </div>
      </body>
    </html>`,
    {
      status: 200,
      headers: { 'Content-Type': 'text/html' }
=======
          <h1>⚠️ You are Offline</h1>
          <p>Check your internet connection and try again.</p>
          <p><small>You can still access cached pages.</small></p>
        </div>
      </body>
    </html>
    `,
    {
      status: 503,
      statusText: 'Service Unavailable',
      headers: { 'Content-Type': 'text/html' },
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    }
  );
}

/**
<<<<<<< HEAD
 * Background sync event
 */
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-pending-requests') {
    event.waitUntil(syncPendingRequests());
  }
});

/**
 * Sync pending requests when back online
 */
async function syncPendingRequests() {
  try {
    const syncQueue = await getSyncQueue();
    if (syncQueue.length === 0) return;

    console.log('[SW] Syncing', syncQueue.length, 'pending requests');
    
    const successfulIds = [];
    
    for (const requestData of syncQueue) {
      try {
        const response = await fetch(requestData.url, {
          method: requestData.method,
          headers: requestData.headers,
          body: requestData.body
        });
        
        if (response.ok) {
          successfulIds.push(requestData.id);
        }
      } catch (e) {
        console.error('[SW] Failed to sync request:', requestData.id, e);
      }
    }
    
    // Remove successful requests from queue
    const remainingQueue = syncQueue.filter(r => !successfulIds.includes(r.id));
    localStorage.setItem('amgc_sync_queue', JSON.stringify(remainingQueue));
    
    // Notify clients
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach(client => {
      client.postMessage({
        type: 'SYNC_COMPLETED',
        data: { synced: successfulIds.length, remaining: remainingQueue.length }
      });
    });
    
    console.log('[SW] Sync completed. Synced:', successfulIds.length, 'Remaining:', remainingQueue.length);
  } catch (error) {
    console.error('[SW] Sync error:', error);
  }
}

/**
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
 * Message handler for communication from main thread
 */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
<<<<<<< HEAD
  
  if (event.data && event.data.type === 'GET_CACHE_STATUS') {
    event.waitUntil(
      caches.keys().then(cacheNames => {
        event.ports[0].postMessage({
          cacheName: CACHE_NAME,
          availableCaches: cacheNames
        });
      })
    );
  }
  
  if (event.data && event.data.type === 'CLEAR_CACHE') {
    event.waitUntil(
      caches.delete(CACHE_NAME).then(() => {
        event.ports[0].postMessage({ success: true });
      })
    );
  }
});

console.log('[Service Worker] Loaded!');
=======
});
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
