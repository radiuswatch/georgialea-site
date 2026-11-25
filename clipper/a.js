/* ClipNow website traffic counter (2026-11-22). No cookies; sends the page, the referring site and ?s= tag once per page load. */
(function () {
  var me = document.currentScript;
  try { if (navigator.webdriver || localStorage.getItem('tr-off') === '1') return; } catch (e) {}
  var q = new URLSearchParams(location.search);
  var b = JSON.stringify({ p: (me && me.getAttribute('data-p')) || location.pathname, r: document.referrer, s: q.get('s') || q.get('utm_source') || q.get('ref') || '' });
  try { if (navigator.sendBeacon && navigator.sendBeacon('/a.php', new Blob([b], { type: 'text/plain' }))) return; } catch (e) {}
  try { fetch('/a.php', { method: 'POST', body: b, keepalive: true, headers: { 'content-type': 'text/plain' } }); } catch (e) {}
})();
