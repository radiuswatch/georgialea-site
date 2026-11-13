/* "Ask ClipNow": the AI help chat on the home page and the coming-soon page.
 * A round button opens a small chat panel; answers come from /api/chat.php.
 * The conversation is kept for this browser tab only (sessionStorage). */
(function(){
  var root = document.documentElement;
  // On the main site, only the Home view gets the chat (not Studio, Stats, Campaigns or Affiliates).
  if (/\bpg-/.test(root.className)) return;
  if (document.getElementById('cnChat')) return;

  var KEY = 'cn_chat', SUGGEST = ['What does ClipNow do?', 'How much does it cost?', 'How do I get paid for clips?', 'When does ClipNow launch?'];
  var hist = [];
  try { hist = JSON.parse(sessionStorage.getItem(KEY) || '[]') || []; } catch(e){ hist = []; }
  function save(){ try { sessionStorage.setItem(KEY, JSON.stringify(hist.slice(-20))); } catch(e){} }
  // The main site's tab bar is shown on phones (up to 700px wide); sit above it there.
  var tabbar = !!document.querySelector('.tabbar');

  var css = '' +
    '.cnc-btn{position:fixed;right:max(16px,env(safe-area-inset-right));bottom:calc(20px + env(safe-area-inset-bottom));z-index:70;display:flex;align-items:center;gap:8px;' +
      'background:#31C971;color:#06230F;border:0;border-radius:999px;padding:12px 18px 12px 14px;font:600 15px -apple-system,"Segoe UI",Roboto,Arial,sans-serif;cursor:pointer;box-shadow:0 8px 28px rgba(0,0,0,.45)}' +
    '.cnc-btn svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}' +
    '.cnc-btn[aria-expanded="true"]{display:none}' +
    '.cnc{position:fixed;right:max(16px,env(safe-area-inset-right));bottom:calc(20px + env(safe-area-inset-bottom));z-index:71;width:min(380px,calc(100vw - 32px));' +
      'height:min(560px,calc(100vh - 60px));height:min(560px,calc(100dvh - 60px));display:flex;flex-direction:column;background:#141714;color:#E9EDE9;border:1px solid #242923;border-radius:18px;' +
      'box-shadow:0 18px 60px rgba(0,0,0,.6);font:15px/1.5 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;overflow:hidden}' +
    '.cnc[hidden]{display:none}' +
    '.cnc-h{display:flex;align-items:center;gap:10px;padding:14px 14px 12px 16px;border-bottom:1px solid #242923}' +
    '.cnc-mark{width:30px;height:30px;border-radius:9px;background:#31C971;color:#06230F;display:grid;place-items:center;font:700 12px Consolas,Menlo,monospace;flex:none}' +
    '.cnc-h b{display:block;font-size:15px}.cnc-h small{display:block;color:#96A096;font-size:12px}' +
    '.cnc-x{margin-left:auto;background:none;border:0;color:#96A096;font-size:22px;line-height:1;cursor:pointer;padding:6px 8px;border-radius:8px}.cnc-x:hover{color:#E9EDE9;background:#1c211c}' +
    '.cnc-log{flex:1;overflow-y:auto;padding:14px 14px 6px;display:flex;flex-direction:column;gap:10px;overscroll-behavior:contain}' +
    '.cnc-m{max-width:86%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word}' +
    '.cnc-m.bot{background:#1c211c;border:1px solid #242923;align-self:flex-start;border-bottom-left-radius:4px}' +
    '.cnc-m.me{background:#31C971;color:#06230F;align-self:flex-end;border-bottom-right-radius:4px}' +
    '.cnc-m.err{background:rgba(229,72,77,.12);border:1px solid rgba(229,72,77,.35);color:#F0A6A8;align-self:flex-start}' +
    '.cnc-m a{color:#31C971;text-decoration:underline}.cnc-m.me a{color:#06230F}' +
    '.cnc-dots span{display:inline-block;width:7px;height:7px;margin-right:4px;border-radius:50%;background:#96A096;animation:cncb 1.2s infinite}' +
    '.cnc-dots span:nth-child(2){animation-delay:.15s}.cnc-dots span:nth-child(3){animation-delay:.3s}' +
    '@keyframes cncb{0%,80%,100%{opacity:.3}40%{opacity:1}}' +
    '.cnc-sug{display:flex;flex-wrap:wrap;gap:8px;padding:0 14px 10px}' +
    '.cnc-sug button{background:none;border:1px solid #2e352d;color:#E9EDE9;border-radius:999px;padding:7px 12px;font:inherit;font-size:13.5px;cursor:pointer}.cnc-sug button:hover{border-color:#31C971}' +
    '.cnc-f{display:flex;gap:8px;padding:10px;border-top:1px solid #242923}' +
    '.cnc-f textarea{flex:1;resize:none;background:#0B0D0B;color:#E9EDE9;border:1px solid #2e352d;border-radius:12px;padding:10px 12px;font:16px/1.4 inherit;font-family:inherit;max-height:110px;min-height:44px}' +
    '.cnc-f textarea:focus{outline:none;border-color:#31C971}' +
    '.cnc-f button{background:#31C971;color:#06230F;border:0;border-radius:12px;padding:0 16px;font:600 15px inherit;font-family:inherit;cursor:pointer}.cnc-f button:disabled{opacity:.5;cursor:default}' +
    '.cnc-note{color:#6B756B;font-size:11.5px;text-align:center;padding:0 12px 10px}' +
    (tabbar ? '@media (max-width:700px){.cnc-btn,.cnc{bottom:calc(86px + env(safe-area-inset-bottom))}.cnc{height:min(560px,calc(100vh - 130px));height:min(560px,calc(100dvh - 130px))}}' : '');
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  var btn = document.createElement('button');
  btn.type = 'button'; btn.className = 'cnc-btn'; btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', 'cnChat');
  btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/></svg>Ask ClipNow';

  var box = document.createElement('div');
  box.id = 'cnChat'; box.className = 'cnc'; box.hidden = true; box.setAttribute('role', 'region'); box.setAttribute('aria-label', 'Ask ClipNow chat');
  box.innerHTML = '<div class="cnc-h"><div class="cnc-mark">CN</div><div><b>Ask ClipNow</b><small>AI answers about ClipNow</small></div><button type="button" class="cnc-x" aria-label="Close chat">×</button></div>' +
    '<div class="cnc-log" aria-live="polite"></div><div class="cnc-sug"></div>' +
    '<form class="cnc-f"><textarea rows="1" maxlength="1000" placeholder="Ask a question…" aria-label="Your question"></textarea><button type="submit">Send</button></form>' +
    '<div class="cnc-note">AI can make mistakes. For account help, email helpdesk@clipnow.vip.</div>';
  document.body.appendChild(btn); document.body.appendChild(box);

  var log = box.querySelector('.cnc-log'), sug = box.querySelector('.cnc-sug'), form = box.querySelector('form'),
      ta = box.querySelector('textarea'), send = box.querySelector('.cnc-f button'), busy = false;

  /** Text with links to emails and clipnow.vip pages made clickable (everything else stays plain text). */
  function fill(el, text){
    var re = /(helpdesk@clipnow\.vip|(?:https?:\/\/)?(?:www\.)?clipnow\.vip(?:\/[\w\-.\/#?=&]*)?|youtube\.com\/verify)/g, last = 0, m;
    while ((m = re.exec(text))){
      if (m.index > last) el.appendChild(document.createTextNode(text.slice(last, m.index)));
      var a = document.createElement('a'), t = m[0].replace(/[.,]$/, '');
      a.textContent = t;
      a.href = /@/.test(t) ? 'mailto:' + t : (/^https?:/.test(t) ? t : 'https://' + t);
      if (!/^mailto:/.test(a.href) && !/clipnow\.vip/.test(a.href)) { a.target = '_blank'; a.rel = 'noopener'; }
      el.appendChild(a);
      if (t.length < m[0].length) el.appendChild(document.createTextNode(m[0].slice(t.length)));
      last = m.index + m[0].length;
    }
    if (last < text.length) el.appendChild(document.createTextNode(text.slice(last)));
  }
  function bubble(cls, text){ var d = document.createElement('div'); d.className = 'cnc-m ' + cls; fill(d, text); log.appendChild(d); log.scrollTop = log.scrollHeight; return d; }
  function render(){
    log.innerHTML = '';
    bubble('bot', 'Hi! I’m the ClipNow assistant. Ask me anything about ClipNow: what it does, plans and prices, posting, campaigns or getting paid.');
    hist.forEach(function(m){ bubble(m.role === 'user' ? 'me' : 'bot', m.content); });
    sug.innerHTML = '';
    if (!hist.length) SUGGEST.forEach(function(q){ var b = document.createElement('button'); b.type = 'button'; b.textContent = q; b.addEventListener('click', function(){ ask(q); }); sug.appendChild(b); });
  }
  function ask(q){
    q = (q || '').trim(); if (!q || busy) return;
    busy = true; send.disabled = true; sug.innerHTML = '';
    hist.push({ role:'user', content:q }); save();
    bubble('me', q); ta.value = ''; grow();
    var wait = document.createElement('div'); wait.className = 'cnc-m bot cnc-dots'; wait.setAttribute('aria-label', 'Thinking'); wait.innerHTML = '<span></span><span></span><span></span>';
    log.appendChild(wait); log.scrollTop = log.scrollHeight;
    fetch('/api/chat.php', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json' }, body:JSON.stringify({ messages:hist }) })
      .then(function(r){ return r.json().catch(function(){ return { ok:false }; }); })
      .then(function(j){
        wait.remove();
        if (j && j.ok && j.reply){ hist.push({ role:'assistant', content:j.reply }); save(); bubble('bot', j.reply); }
        else { hist.pop(); save(); bubble('err', (j && j.error) || 'Something went wrong. Please try again, or email helpdesk@clipnow.vip.'); }
      }, function(){ wait.remove(); hist.pop(); save(); bubble('err', 'You seem to be offline. Please try again.'); })
      .then(function(){ busy = false; send.disabled = false; });
  }
  function grow(){ ta.style.height = 'auto'; ta.style.height = Math.min(110, ta.scrollHeight) + 'px'; }
  function open(){ box.hidden = false; btn.setAttribute('aria-expanded', 'true'); render(); setTimeout(function(){ if (window.matchMedia && !matchMedia('(pointer:coarse)').matches) ta.focus(); }, 30); }
  function close(){ box.hidden = true; btn.setAttribute('aria-expanded', 'false'); btn.focus(); }

  btn.addEventListener('click', open);
  box.querySelector('.cnc-x').addEventListener('click', close);
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !box.hidden) close(); });
  form.addEventListener('submit', function(e){ e.preventDefault(); ask(ta.value); });
  ta.addEventListener('input', grow);
  ta.addEventListener('keydown', function(e){ if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); ask(ta.value); } });
  window.ClipNowChat = { open:open, close:close };
})();
