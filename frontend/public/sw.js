/**
 * HRIS Service Worker
 *
 * Strategi cache:
 *   1. App shell (HTML, JS, CSS, fonts, icons) — cache-first dgn fallback network.
 *   2. API GET — network-first, fallback ke cache (read-only). 7 hari max age.
 *   3. API non-GET (POST/PUT/DELETE) — bypass cache. Kalau offline, queue di
 *      IndexedDB (`hris-pending-mutations`) supaya bisa replay otomatis saat online lagi.
 *
 * Activation: skipWaiting + clients.claim agar SW baru langsung ambil alih.
 *
 * Versioning: bump CACHE_VERSION setiap deploy supaya cache lama auto-invalidated.
 */

const CACHE_VERSION = 'v1.0.0';
const SHELL_CACHE = `hris-shell-${CACHE_VERSION}`;
const API_CACHE = `hris-api-${CACHE_VERSION}`;
const API_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000; // 7 hari

const SHELL_URLS = [
  '/',
  '/index.html',
  '/manifest.webmanifest',
  '/favicon.svg',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(SHELL_CACHE);
      try { await cache.addAll(SHELL_URLS); } catch (_) { /* index.html ok dulu */ }
      self.skipWaiting();
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Hapus cache versi lama
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((k) => k.startsWith('hris-') && ! k.endsWith(CACHE_VERSION))
          .map((k) => caches.delete(k))
      );
      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Skip non-GET, cross-origin, atau dev HMR
  if (url.origin !== self.location.origin && ! url.pathname.startsWith('/api/')) return;
  if (url.pathname.startsWith('/@vite/') || url.pathname.startsWith('/@id/')) return;

  // API request
  if (url.pathname.includes('/api/')) {
    if (req.method === 'GET') {
      event.respondWith(networkFirstApi(req));
    } else {
      // Mutating request: kalau offline, queue
      event.respondWith(handleMutation(req));
    }
    return;
  }

  // Navigation request → return cached app shell offline
  if (req.mode === 'navigate') {
    event.respondWith(navigationHandler(req));
    return;
  }

  // Static asset: cache-first
  event.respondWith(cacheFirst(req));
});

async function cacheFirst(req) {
  const cache = await caches.open(SHELL_CACHE);
  const hit = await cache.match(req);
  if (hit) return hit;
  try {
    const res = await fetch(req);
    if (res && res.ok && res.type === 'basic') {
      cache.put(req, res.clone());
    }
    return res;
  } catch (e) {
    return new Response('Offline', { status: 503 });
  }
}

async function navigationHandler(req) {
  try {
    const res = await fetch(req);
    return res;
  } catch (e) {
    const cache = await caches.open(SHELL_CACHE);
    return (await cache.match('/index.html')) || new Response('Offline', { status: 503 });
  }
}

async function networkFirstApi(req) {
  const cache = await caches.open(API_CACHE);
  try {
    const res = await fetch(req);
    if (res && res.ok) {
      const cloned = res.clone();
      const body = await cloned.blob();
      const headers = new Headers(cloned.headers);
      headers.set('x-sw-cached-at', String(Date.now()));
      const cachedRes = new Response(body, {
        status: cloned.status, statusText: cloned.statusText, headers,
      });
      cache.put(req, cachedRes);
    }
    return res;
  } catch (e) {
    const hit = await cache.match(req);
    if (hit) {
      const cachedAt = parseInt(hit.headers.get('x-sw-cached-at') || '0', 10);
      if (Date.now() - cachedAt < API_MAX_AGE_MS) {
        // Tambah header X-From-Cache supaya client tau ini hasil cache
        const headers = new Headers(hit.headers);
        headers.set('x-from-cache', '1');
        const body = await hit.blob();
        return new Response(body, { status: hit.status, headers });
      }
    }
    return new Response(
      JSON.stringify({ status: 'error', message: 'Offline. Tidak ada cache tersedia.' }),
      { status: 503, headers: { 'content-type': 'application/json' } }
    );
  }
}

async function handleMutation(req) {
  try {
    return await fetch(req);
  } catch (e) {
    // Offline → queue ke IndexedDB
    try {
      const body = await req.clone().text();
      await queueMutation({
        url: req.url,
        method: req.method,
        headers: serializeHeaders(req.headers),
        body,
        ts: Date.now(),
      });
      // Kasih tau client agar bisa show toast "akan di-sync nanti"
      return new Response(
        JSON.stringify({
          status: 'queued',
          message: 'Permintaan dikirim saat online (offline mode)',
        }),
        { status: 202, headers: { 'content-type': 'application/json', 'x-sw-queued': '1' } }
      );
    } catch (err) {
      return new Response(
        JSON.stringify({ status: 'error', message: 'Gagal queue mutation: ' + err.message }),
        { status: 503, headers: { 'content-type': 'application/json' } }
      );
    }
  }
}

function serializeHeaders(h) {
  const out = {};
  for (const [k, v] of h.entries()) out[k] = v;
  return out;
}

// IndexedDB queue helper
const DB_NAME = 'hris-pending-mutations';
const STORE = 'queue';

function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (! db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function queueMutation(item) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).add(item);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

async function getQueue() {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readonly');
    const req = tx.objectStore(STORE).getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(req.error);
  });
}

async function deleteFromQueue(id) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

// Replay queue saat online (Background Sync API kalau didukung, atau message dari client)
async function flushQueue() {
  const items = await getQueue();
  let success = 0, failed = 0;
  for (const it of items) {
    try {
      const res = await fetch(it.url, {
        method: it.method,
        headers: it.headers,
        body: it.body || undefined,
      });
      if (res.ok) {
        await deleteFromQueue(it.id);
        success++;
      } else {
        // 4xx errors: drop dari queue supaya tidak retry forever
        if (res.status >= 400 && res.status < 500) {
          await deleteFromQueue(it.id);
        }
        failed++;
      }
    } catch (e) {
      failed++; // network error → keep in queue
    }
  }
  // Notify all clients
  const clients = await self.clients.matchAll();
  for (const c of clients) {
    c.postMessage({ type: 'queue-flushed', success, failed, remaining: items.length - success });
  }
  return { success, failed };
}

self.addEventListener('sync', (event) => {
  if (event.tag === 'hris-flush-queue') {
    event.waitUntil(flushQueue());
  }
});

// Manual flush trigger dari client
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'flush-queue') {
    event.waitUntil(flushQueue());
  }
  if (event.data && event.data.type === 'skip-waiting') {
    self.skipWaiting();
  }
});

// ─── Web Push ──────────────────────────────────────────────────────
self.addEventListener('push', (event) => {
  if (! event.data) return;
  let data = {};
  try { data = event.data.json(); } catch (_) { data = { title: 'HRIS', body: event.data.text() }; }
  const title = data.title || 'HRIS Notifikasi';
  const options = {
    body: data.body || '',
    icon: data.icon || '/icons/icon-192.png',
    badge: data.badge || '/icons/icon-192.png',
    tag: data.tag,
    data: { url: data.url || '/' },
    requireInteraction: data.requireInteraction || false,
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then((clients) => {
      // Cek apakah ada window yg sama
      for (const c of clients) {
        if (c.url.includes(url) && 'focus' in c) return c.focus();
      }
      // Buka window baru
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});