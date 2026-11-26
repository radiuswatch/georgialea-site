/* ClipNow website traffic counter (2026-11-22; fixed 2026-11-25). No cookies of our own; sends the page, the referring site and ?s= tag once per page load.
   Fix 2026-11-25: Bluehost's bot check answers a first-time visitor's POST with 409 and a tiny page that sets a "humans_NNNN" cookie.
   sendBeacon can't see that answer, so first-time visitors (everyone from ads) were never counted. Now: fetch, and on that answer set the
   cookie and send once more. */
(function () {
  try { document.cookie = 'humans_21909=1; path=/; max-age=31536000; SameSite=Lax; Secure'; } catch (e) {}   // Bluehost bot check: set it up front
  var me = document.currentScript;
  try { if (navigator.webdriver || localStorage.getItem('tr-off') === '1') return; } catch (e) {}
  // Second, independent counter (2026-11-26): Cloudflare Web Analytics, to cross-check our own numbers. Main site only.
  try {
    if (location.hostname.replace(/^www\./, '') === 'clipnow.vip' && !document.querySelector('script[data-cf-beacon]')) {
      var cf = document.createElement('script'); cf.defer = true; cf.src = 'https://static.cloudflareinsights.com/beacon.min.js';
      cf.setAttribute('data-cf-beacon', '{"token": "5765c59674f2412dba7f1b978645607c"}'); document.head.appendChild(cf);
    }
  } catch (e) {}
  var q = new URLSearchParams(location.search);
  var b = JSON.stringify({ p: (me && me.getAttribute('data-p')) || location.pathname, r: document.referrer, s: q.get('s') || q.get('utm_source') || q.get('ref') || '' });
  function send(again) {
    if (!window.fetch) { try { navigator.sendBeacon && navigator.sendBeacon('/a.php', new Blob([b], { type: 'text/plain' })); } catch (e) {} return; }
    fetch('/a.php', { method: 'POST', body: b, keepalive: true, credentials: 'same-origin', headers: { 'content-type': 'text/plain' } }).then(function (r) {
      if (r.status !== 409 || again) return;
      return r.text().then(function (t) {
        var m = t.match(/document\.cookie\s*=\s*"(humans_\d+=[^";]*)/);
        if (m) { document.cookie = m[1] + '; path=/; SameSite=Lax; Secure'; send(true); }
      });
    }).catch(function () {});
  }
  send(false);
})();
