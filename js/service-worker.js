/**
 * Service Worker for AMGC Offline Mode
 * Handles caching, offline detection, and request interception
 */

const CACHE_NAME = 'amgc-offline-cache-v1';
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/login.php',
  '/css/style.css',
  '/css/global.css',
  '/js/offline-db.js',
  '/js/offline-sync-manager.js',
  '/js/offline-ui-indicator.js',
];

/**
 * Install event - cache static assets
 */
self.addEventListener('install', (event) => {
  console.log('[Service Worker] Installing...');
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[Service Worker] Caching static assets');
      return cache.addAll(STATIC_ASSETS);
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
      endpoint: new URL(request.url).pathname,
      method: request.method,
      headers: Object.fromEntries(request.headers),
      body: await request.clone().text(),
      timestamp: Date.now(),
      status: 'pending',
      retries: 0,
    };

    // Store in sync queue (will be picked up by sync manager)
    localStorage.setItem(
      `sync_queue_${requestData.id}`,
      JSON.stringify(requestData)
    );

    // Return optimistic response
    return new Response(
      JSON.stringify({
        success: true,
        message: 'Request queued for sync',
        data: { sync_id: requestData.id, offline: true },
      }),
      {
        status: 200,
        headers: {
          'Content-Type': 'application/json',
          'X-Offline-Queued': 'true',
        },
      }
    );
  }
}

/**
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
        </style>
      </head>
      <body>
        <div class="offline-container">
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
    }
  );
}

/**
 * Message handler for communication from main thread
 */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
