// Masuk Sukses Pertama Warehouse Service Worker
const CACHE_VERSION = 'msp-wh-v2';
const OFFLINE_URL = '/masuk-sukses-pertama-warehouse/offline.html';

const STATIC_ASSETS = [
  '/masuk-sukses-pertama-warehouse/offline.html'
];

// Install: cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then(cache => {
      return cache.addAll(STATIC_ASSETS).catch(err => {
        console.warn('Cache install partial failure:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

// Activate: cleanup old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k))
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch: cache-first for static, network-first for dynamic, offline fallback for navigation
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET or different origin
  if (request.method !== 'GET' || url.origin !== location.origin) return;

  // Cache-first for static assets (CSS, JS, fonts, images)
  if (/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|eot|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then(cached => {
        return cached || fetch(request).then(response => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(CACHE_VERSION).then(cache => cache.put(request, clone));
          }
          return response;
        });
      })
    );
    return;
  }

  // Network-first for dynamic content, NO CACHE for navigation
  event.respondWith(
    fetch(request).catch(() => {
      // Offline fallback for navigation requests only
      if (request.mode === 'navigate') {
        return caches.match(OFFLINE_URL).then(cached => {
          return cached || new Response('Offline', { status: 503 });
        });
      }
      // Try cache for other requests
      return caches.match(request);
    })
  );
});
