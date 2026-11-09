/* ClipNow service worker: makes the installed app open fast and work on a weak signal.
 *
 * - Pages (index, privacy, terms, refunds): network first, so updates show right away;
 *   if the network is slow (over 3.5 s) or offline, the last saved copy is shown.
 * - Icons, images and the manifest: served from the saved copy, refreshed in the background.
 * - Never touched: anything that isn't a GET, other websites, PHP (sign-in, contact form,
 *   billing, admin) and video files (they stream in pieces and are too big to save).
 *
 * Bump VERSION when this file changes so phones drop the old saved copies.
 */
const VERSION = 'clipnow-v9';
const PAGES = ['./', 'studio', 'campaigns', 'stats', 'affiliates', 'privacy.html', 'terms.html', 'refunds.html'];
const ASSETS = ['manifest.webmanifest', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'explainer-poster.jpg'];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(VERSION)
      // Saved one by one so a single missing file doesn't stop the app from installing.
      .then((c) => Promise.all([...PAGES, ...ASSETS].map((u) => c.add(new Request(u, { cache: 'reload' })).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('clipnow-') && k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

function skip(req, url) {
  return req.method !== 'GET'
    || url.origin !== self.location.origin
    || /\.php$/i.test(url.pathname)
    || /\/(api|admin)\//.test(url.pathname)
    || /\.(mp4|webm|mov|m4v)$/i.test(url.pathname)
    || req.headers.has('range');
}

function isOk(res) { return res && res.ok && res.type === 'basic'; }

/** Pages: try the network (up to 3.5 s), save what comes back, else use the saved copy. */
function page(e, url) {
  const key = url.pathname.endsWith('/') ? './' : url.pathname.split('/').pop() || './';
  const fromNet = fetch(e.request).then((res) => {
    if (isOk(res)) { const copy = res.clone(); e.waitUntil(caches.open(VERSION).then((c) => c.put(key, copy))); }
    return res;
  });
  const fromCache = () => caches.match(key, { ignoreSearch: true }).then((hit) => hit || caches.match('./'));
  return new Promise((resolve) => {
    let done = false;
    const finish = (res) => { if (res && !done) { done = true; resolve(res); } };
    fromNet.then(finish, () => fromCache().then((hit) => finish(hit || offline())));
    // Slow network: show the saved copy (if there is none, keep waiting for the network).
    setTimeout(() => fromCache().then(finish), 3500);
  });
}

/** Images and other files: saved copy first, refreshed in the background. */
function asset(e) {
  return caches.open(VERSION).then((c) => c.match(e.request).then((hit) => {
    const fresh = fetch(e.request).then((res) => { if (isOk(res)) c.put(e.request, res.clone()); return res; });
    if (hit) { e.waitUntil(fresh.catch(() => {})); return hit; }
    return fresh;
  }));
}

function offline() {
  return new Response(
    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>ClipNow · Offline</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0B0D0B;color:#E9EDE9;font:16px -apple-system,Segoe UI,Roboto,sans-serif;text-align:center;padding:24px">' +
    '<div><h1 style="font-size:22px">You\'re offline</h1><p style="color:#96A096">Connect to the internet and ClipNow will open.</p>' +
    '<p><a href="./" style="color:#31C971">Try again</a></p></div></body>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
  );
}

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (skip(e.request, url)) return;
  if (e.request.mode === 'navigate') e.respondWith(page(e, url));
  else e.respondWith(asset(e));
});
