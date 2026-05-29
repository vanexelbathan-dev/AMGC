/**
 * SERVICE WORKER - Handles offline caching
 * Ito ay nag-intercept ng requests at nag-save ng pages para offline access
 */

const CACHE_NAME = 'amgc-offline-v1';
const OFFLINE_PAGE = '/offline.html';

// List ng lahat ng URLs na dapat i-cache agad (precache) - LAHAT NG FILES
const URLS_TO_CACHE = [
    // Main entry pages
    '/',
    '/login.php',
    '/logout.php',
    
    // Offline support JS files
    '/js/offline-manager.js',
    '/js/offline-sync.js',
    '/js/offline-login.js',
    
    // CSS files - lahat
    '/css/global.css',
    '/css/style.css',
    '/css/offline-mode.css',
    '/css/bad_orders.css',
    '/css/warehouse.css',
    '/css/del_trip_tickets.css',
    '/css/purchase_order.css',
    '/css/pick_list_items.css',
    '/css/fordelivery.css',
    '/css/rejecteddelivery.css',
    '/css/delivery.css',
    '/css/sales.css',
    '/css/current_inventory.css',
    '/css/sales_order.css',
    
    // Sales module - lahat ng pages
    '/Sales/customer.php',
    '/Sales/sales_order.php',
    '/Sales/currentinventory.php',
    '/Sales/orderproduct.php',
    '/Sales/returnedmerchandise.php',
    '/Sales/credit_discount_request.php',
    '/Sales/generate_customer_code.php',
    '/Sales/get_customer_details.php',
    
    // Warehouse module - lahat ng pages
    '/Warehouse/warehouse.php',
    '/Warehouse/pick_list_items.php',
    '/Warehouse/drivers.php',
    '/Warehouse/currentinventory.php',
    '/Warehouse/add_inventory.php',
    '/Warehouse/update_inventory.php',
    '/Warehouse/purchase_order.php',
    '/Warehouse/add_driver.php',
    '/Warehouse/get_driver_details.php',
    '/Warehouse/get_item_details.php',
    '/Warehouse/get_pick_item_details.php',
    
    // Delivery module - lahat ng pages
    '/Delivery/fordelivery.php',
    '/Delivery/trip_tickets.php',
    '/Delivery/rejecteddelivery.php',
    '/Delivery/order_details.php',
    '/Delivery/get_delivery_details.php',
    '/Delivery/get_delivery_items.php',
    '/Delivery/get_trip_details.php',
    '/Delivery/submit_rejected_delivery.php',
    '/Delivery/update_delivery.php',
    '/Delivery/update_delivery_status.php',
    '/Delivery/update_order_status.php',
    
    // Branch Admin module - lahat ng pages
    '/BranchAdmin/sales_order.php',
    '/BranchAdmin/purchase_order.php',
    '/BranchAdmin/drivers.php',
    '/BranchAdmin/current_inventory.php',
    '/BranchAdmin/pick_list_items.php',
    '/BranchAdmin/trip_tickets.php',
    '/BranchAdmin/bad_orders.php',
    '/BranchAdmin/supplier.php',
    '/BranchAdmin/approve_credit_requests.php',
    
    // Global/API handlers - lahat ng pages
    '/Global/drivers.php',
    '/Global/trip_tickets.php',
    '/Global/all_items.php',
    '/Global/sales_reports.php',
    '/Global/branch_records.php',
    '/Global/check_driver_role.php',
    '/Global/save_driver_location.php',
    '/Global/gps_shutdown.php',
    '/Global/update_location.php',
    '/Global/driver_heartbeat.php',
    '/Global/driver_tracking_data.php',
    '/Global/get_locations.php',
    '/Global/driver_tracking.php',
    '/Global/gps_location_update.php',
    '/Global/gps_shift_start.php',
    '/Global/location_verification.php',
    '/Global/get_driver_data.php',
    '/Global/update_driver_location.php',
    '/Global/get_driver_status.php',
    
    // Images/Assets
    '/AMGC3DLOGO.png',
    
    // Manifest
    '/manifest.json'
];

/**
 * Install event - cache all essential files at once
 */
self.addEventListener('install', (event) => {
    console.log('[ServiceWorker] Installing... Precaching all files');
    
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[ServiceWorker] Opening cache and adding all files');
            
            // Try to cache all files, if some fail, cache the ones that succeed
            return Promise.allSettled(
                URLS_TO_CACHE.map(url => 
                    cache.add(url).catch(err => {
                        console.warn('[ServiceWorker] Failed to cache:', url, err.message);
                    })
                )
            ).then(() => {
                console.log('[ServiceWorker] Precaching complete');
            });
        }).catch(err => {
            console.error('[ServiceWorker] Cache open failed:', err);
        })
    );
    
    self.skipWaiting();
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
    console.log('[ServiceWorker] Activating...');
    
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[ServiceWorker] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    
    self.clients.claim();
});

/**
 * Fetch event - Network first, then cache as fallback
 */
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Only handle GET requests
    if (request.method !== 'GET') {
        return;
    }

    // Skip non-same-origin requests
    if (url.origin !== self.location.origin) {
        return;
    }

    // For HTML pages: Network first, then cache
    if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    // Cache successful responses
                    if (response && response.status === 200) {
                        const responseToCache = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(request, responseToCache);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // Network failed, try cache
                    return caches.match(request)
                        .then((cached) => {
                            if (cached) {
                                console.log('[ServiceWorker] Serving cached:', request.url);
                                return cached;
                            }
                            // No cache available, return offline page
                            return caches.match(OFFLINE_PAGE) || 
                                   new Response('Offline', { status: 503 });
                        });
                })
        );
    } else {
        // For other resources: Cache first, then network
        event.respondWith(
            caches.match(request)
                .then((cached) => {
                    if (cached) {
                        return cached;
                    }
                    return fetch(request)
                        .then((response) => {
                            if (response && response.status === 200) {
                                const responseToCache = response.clone();
                                caches.open(CACHE_NAME).then((cache) => {
                                    cache.put(request, responseToCache);
                                });
                            }
                            return response;
                        })
                        .catch(() => new Response('Offline', { status: 503 }));
                })
        );
    }
});

/**
 * Message handler - for manual cache control
 */
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
