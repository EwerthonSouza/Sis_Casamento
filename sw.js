const CACHE_NAME = 'meuevento-pro-v1';
const ASSETS_ESTATICOS = [
  '/css/estilo.css',
  '/img/logo MEP1.svg',
  '/img/LOGO MEP NAV.svg',
  '/img/icon-192.png',
  '/img/icon-512.png',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS_ESTATICOS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((nomes) =>
      Promise.all(nomes.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)))
    )
  );
  self.clients.claim();
});

// Só assets estáticos passam pelo cache (cache-first). Toda página PHP
// (login, painel, dados) vai direto pra rede sempre — nunca servir
// conteúdo dinâmico/sessão do cache.
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const eEstatico = ASSETS_ESTATICOS.some((a) => url.pathname === a) || /\.(css|png|svg|webp|jpg|jpeg|woff2?)$/.test(url.pathname);

  if (event.request.method !== 'GET' || !eEstatico) {
    return; // deixa o navegador tratar normalmente (rede)
  }

  event.respondWith(
    caches.match(event.request).then((cached) => cached || fetch(event.request))
  );
});
