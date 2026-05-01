/*
 * service-worker.js — ЭРА ЭТП
 *
 * Стратегии кэширования:
 *  - Статика (логотипы, иконки, манифест, шрифты) — cache-first.
 *  - Документы (PHP-страницы) — network-first с фолбэком на кэш и offline.html.
 *  - Запросы POST/PUT/DELETE/PATCH — никогда не кэшируем (онлайн-only).
 *
 * Версия в имени кэша. При обновлении версии старые кэши удаляются.
 */

const SW_VERSION = 'v2026-04-27-5';
const STATIC_CACHE = `era-static-${SW_VERSION}`;
const RUNTIME_CACHE = `era-runtime-${SW_VERSION}`;

const PRECACHE_URLS = [
    '/manifest.webmanifest',
    '/offline.html',
    '/logo-forsage-modified.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/apple-touch-icon.png',
    '/icons/favicon-32.png',
    '/icons/favicon-16.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((k) => k !== STATIC_CACHE && k !== RUNTIME_CACHE)
                .map((k) => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return /\.(?:png|jpg|jpeg|gif|webp|svg|ico|css|js|woff2?|ttf|eot|webmanifest)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    /* Никогда не кэшируем обработчики ставок/предложений/админки —
       у них живая логика, кэш только всё сломает. */
    if (/\/(send_|apply_|process_|admin|register_handler|login_handler)/.test(url.pathname)) {
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(req).then((cached) => cached || fetch(req).then((res) => {
                const copy = res.clone();
                caches.open(STATIC_CACHE).then((c) => c.put(req, copy));
                return res;
            }).catch(() => cached))
        );
        return;
    }

    /* Документы: network-first, fallback в кэш, потом offline.html. */
    event.respondWith(
        fetch(req).then((res) => {
            const copy = res.clone();
            caches.open(RUNTIME_CACHE).then((c) => c.put(req, copy));
            return res;
        }).catch(() =>
            caches.match(req).then((cached) => cached || caches.match('/offline.html'))
        )
    );
});
