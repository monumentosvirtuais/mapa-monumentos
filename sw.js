/* Monumentos Virtuais · Mapa Wi-Fi — funcionamento sem internet.
   Guarda o app, os pontos de Wi-Fi e o acervo no aparelho.
   O fundo de ruas é guardado só nas áreas que a pessoa já abriu (até TILE_MAX imagens). */
const VERSION = 'mv-wifi-v9';
const TILES = 'mv-tiles';
const DATA = 'mv-data';
const TILE_MAX = 2500;

const SHELL = [
  './',
  './index.html',
  './wifi.json',
  './wifi-recife.json',
  './manifest.webmanifest',
  './vendor/leaflet.js',
  './vendor/leaflet.css',
  './vendor/leaflet.markercluster.js',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/apple-touch-icon.png'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => ![VERSION, TILES, DATA].includes(k)).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

const isTile = u => /arcgisonline\.com\/.+\/tile\//.test(u) || /tile\.openstreetmap\.org\//.test(u);
const isSiteData = u => u.includes('/wp-json/mv3/v1/');
const isFont = u => u.startsWith('https://fonts.googleapis.com/') || u.startsWith('https://fonts.gstatic.com/');
const isSiteImage = u => /monumentosvirtuais\.ong\.br\/wp-content\/uploads\//.test(u);

async function trimTiles() {
  const c = await caches.open(TILES);
  const keys = await c.keys();
  for (let i = 0; i < keys.length - TILE_MAX; i++) await c.delete(keys[i]);
}

async function cacheFirst(req, cacheName, onStore) {
  const c = await caches.open(cacheName);
  const hit = await c.match(req);
  if (hit) return hit;
  try {
    const res = await fetch(req);
    if (res.ok || res.type === 'opaque') { c.put(req, res.clone()); if (onStore) onStore(); }
    return res;
  } catch (err) {
    return new Response('', { status: 504, statusText: 'offline' });
  }
}

async function networkFirst(req, cacheName) {
  const c = await caches.open(cacheName);
  try {
    const res = await fetch(req);
    if (res.ok) c.put(req, res.clone());
    return res;
  } catch (err) {
    const hit = await c.match(req, { ignoreSearch: false });
    if (hit) return hit;
    throw err;
  }
}

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const u = req.url;

  if (isTile(u)) { e.respondWith(cacheFirst(req, TILES, trimTiles)); return; }
  if (isSiteData(u)) { e.respondWith(networkFirst(req, DATA)); return; }
  if (isFont(u) || isSiteImage(u)) { e.respondWith(cacheFirst(req, DATA)); return; }

  if (new URL(u).origin === self.location.origin) {
    // páginas e dados do próprio mapa: tenta a rede (para receber atualizações) e usa o guardado sem internet
    e.respondWith(
      networkFirst(req, VERSION).catch(async () =>
        (await caches.match(req, { ignoreSearch: true })) ||
        (req.mode === 'navigate' ? caches.match('./index.html') : new Response('', { status: 504 })))
    );
  }
});
