const CACHE_NAME = 'vetlink-static-v3';
const OFFLINE_URL = './offline.html';
const STATIC_ASSETS = [
  './',
  './index.php?page=login',
  './offline.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/js/pwa-register.js',
  './assets/img/logo-square.png',
  './assets/img/logo-w.png',
  './assets/pwa/152X152.png',
  './assets/pwa/192x192.png',
  './assets/pwa/512X512.png',
  './assets/pwa/1024X1024.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  const isApiRequest = url.pathname.includes('/api/');
  const isPhpRequest = url.pathname.endsWith('.php') || url.pathname.endsWith('/index.php');
  const isDynamicPage = isApiRequest || isPhpRequest || url.searchParams.has('page');

  if (isDynamicPage) {
    event.respondWith(
      fetch(request, { cache: 'no-store' }).catch(async () => {
        if (request.mode === 'navigate') {
          return caches.match(OFFLINE_URL);
        }
        throw new Error('Dynamic request failed');
      })
    );
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          return cached || caches.match(OFFLINE_URL);
        })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(request, { cache: 'no-store' }).then((response) => {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        return response;
      });
    })
  );
});
