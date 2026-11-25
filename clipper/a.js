/* ClipNow website traffic counter (2026-11-22; fixed 2026-11-25). No cookies of our own; sends the page, the referring site and ?s= tag once per page load.
   Fix 2026-11-25: Bluehost's bot check answers a first-time visitor's POST with 409 and a tiny page that sets a "humans_NNNN" cookie.
   sendBeacon can't see that answer, so first-time visitors (everyone from ads) were never counted. Now: fetch, and on that answer set the
   cookie and send once more. */
(function () {
  var me = document.currentScript;
  try { if (navigator.webdriver || localStorage.getItem('tr-off') === '1') return; } catch (e) {}
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
