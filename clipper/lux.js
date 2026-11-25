/* App Planet luxury pages: day/night switch + starfield. Needs #modeBtn/#knob and (optionally) canvas#luxStars. */
(function () {
  var root = document.documentElement, btn = document.getElementById('modeBtn'), knob = document.getElementById('knob');
  var MOON = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  var SUN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4.5" fill="currentColor"/><path d="M12 1.5v2.5M12 20v2.5M1.5 12H4M20 12h2.5M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>';
  function paint() { var light = root.getAttribute('data-mode') === 'light'; if (knob) knob.innerHTML = light ? SUN : MOON; if (btn) btn.setAttribute('aria-label', light ? 'Switch to dark mode' : 'Switch to light mode'); }
  if (btn) btn.addEventListener('click', function () { var light = root.getAttribute('data-mode') !== 'light'; if (light) root.setAttribute('data-mode', 'light'); else root.removeAttribute('data-mode'); try { localStorage.setItem('card-mode', light ? 'light' : 'dark'); } catch (e) {} paint(); });
  paint();
  var c = document.getElementById('luxStars'); if (!c || !c.getContext) return; var x = c.getContext('2d');
  function draw() { var w = c.clientWidth, h = c.clientHeight, d = window.devicePixelRatio || 1; c.width = w * d; c.height = h * d; x.setTransform(d, 0, 0, d, 0, 0);
    var s = 9; function n() { s = s * 16807 % 2147483647; return s / 2147483647; }
    for (var i = 0; i < w * h / 2600; i++) { x.globalAlpha = .2 + n() * .7; x.fillStyle = n() > .88 ? '#34c46c' : '#e9ede9'; x.beginPath(); x.arc(n() * w, n() * h, n() * 1.3 + .3, 0, 7); x.fill(); } }
  draw(); window.addEventListener('resize', draw);
})();
