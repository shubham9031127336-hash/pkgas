var CACHE_NAME = 'prem-gas-solution-v1';
var PRECACHE_URLS = [
    '/assets/css/style.css',
    '/assets/css/chat-widget.css',
    '/assets/js/main.js',
    '/assets/js/chat-widget.js',
    '/Images/favicon.png',
];

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(PRECACHE_URLS).catch(function(err) {
                console.log('SW: precache failed for some URLs', err);
            });
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.filter(function(name) {
                    return name !== CACHE_NAME;
                }).map(function(name) {
                    return caches.delete(name);
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function(event) {
    var url = new URL(event.request.url);

    if (url.hostname !== self.location.hostname) {
        return;
    }

    if (url.pathname.startsWith('/admin/')) {
        return;
    }

    if (event.request.method !== 'GET') {
        return;
    }

    if (url.pathname === '/chat-api.php') {
        return;
    }

    // Network-first: try network, fall back to cache (offline)
    event.respondWith(
        fetch(event.request).then(function(response) {
            if (response && response.status === 200 && response.type === 'basic') {
                var clone = response.clone();
                caches.open(CACHE_NAME).then(function(cache) {
                    cache.put(event.request, clone);
                });
            }
            return response;
        }).catch(function() {
            return caches.match(event.request);
        })
    );
});
