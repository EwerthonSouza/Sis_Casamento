const CACHE_NAME = 'meuevento-pro-v5';
const ASSETS_ESTATICOS = [
  '/css/estilo.css?v=18',
  '/img/logo MEP1.svg',
  '/img/LOGO MEP NAV.svg',
  '/img/icon-192.png?v=2',
  '/img/icon-512.png?v=2',
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

// Só assets estáticos do próprio site passam pelo cache. Toda página PHP
// (login, painel, dados) vai direto pra rede sempre — nunca servir
// conteúdo dinâmico/sessão do cache. Bootstrap/ícones do CDN ficam no cache
// HTTP do navegador (o jsDelivr já manda max-age de 1 ano pra URL versionada),
// e fotos enviadas (/uploads/) também, pra não inchar o cache do SW.
//
// Estratégia "stale-while-revalidate": responde na hora com a cópia guardada
// (navegação instantânea) e busca a versão nova em segundo plano pra próxima
// visita — assim um estilo.css alterado nunca fica preso no cache.
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const eEstatico = url.origin === self.location.origin
    && !url.pathname.startsWith('/uploads/')
    && /\.(css|js|png|svg|webp|jpg|jpeg|ico|woff2?|json)$/.test(url.pathname)
    && url.pathname !== '/sw.js';

  if (event.request.method !== 'GET' || !eEstatico) {
    return; // deixa o navegador tratar normalmente (rede)
  }

  event.respondWith(
    caches.open(CACHE_NAME).then((cache) =>
      cache.match(event.request).then((cached) => {
        const daRede = fetch(event.request).then((resposta) => {
          if (resposta && resposta.ok) cache.put(event.request, resposta.clone());
          return resposta;
        }).catch(() => cached);
        if (cached) {
          event.waitUntil(daRede);
          return cached;
        }
        return daRede;
      })
    )
  );
});
