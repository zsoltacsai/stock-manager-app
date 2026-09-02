const CACHE_NAME = 'stock-manager-shell-v1';

const SHELL_FILES = [
    'index.php', 'beszerzes.php', 'beszerzesek.php', 'termekek.php',
    'zaras.php', 'eladasok.php', 'beallitasok.php', 'beszallitok.php',
    'vasarlok.php', 'rendszerallapot.php', 'receipt.html',
    'style.css',
    'app.js', 'beszerzes.js', 'termekek.js', 'zaras.js', 'eladasok.js',
    'beszerzesek.js', 'beallitasok.js', 'beszallitok.js', 'vasarlok.js',
    'rendszerallapot.js', 'topbar.js', 'product-modal.js', 'import.js',
    'barcode-scanner.js', 'receipt.js',
    'assets/logo-default.svg', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png',
    'manifest.json',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_FILES)).catch(() => {
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((names) =>
            Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (url.pathname.includes('/api/') || url.origin !== self.location.origin) {
        return;
    }

    if (event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                return response;
            })
            .catch(() => caches.match(event.request).then((cached) => cached || caches.match('index.php')))
    );
});
