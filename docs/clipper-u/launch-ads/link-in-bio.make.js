const { chromium } = require('playwright');
const font = `@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-Black.otf);font-weight:900}
@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-ExtraBold.otf);font-weight:800}
html,body{margin:0;width:1080px;height:260px;background:transparent;font-family:I,sans-serif}`;
const html = `<style>${font}
.w{position:absolute;left:0;right:0;top:40px;display:flex;flex-direction:column;align-items:center;gap:18px}
.arr{width:0;height:0;border-left:34px solid transparent;border-right:34px solid transparent;border-bottom:42px solid #F5C451;filter:drop-shadow(0 6px 18px rgba(245,196,81,.45))}
.p{display:flex;align-items:center;gap:20px;white-space:nowrap;color:#0b1a10;background:#F5C451;font-weight:900;font-size:64px;letter-spacing:-.5px;padding:24px 52px;border-radius:999px;box-shadow:0 0 60px rgba(245,196,81,.45),0 12px 30px rgba(0,0,0,.5)}
.s{color:rgba(255,255,255,.88);font-weight:800;font-size:38px;text-shadow:0 4px 16px rgba(0,0,0,.6)}
</style><div class="w"><div class="arr"></div><div class="p">Tap the link in bio</div></div>`;
(async()=>{ const b=await chromium.launch(); const p=await b.newPage({viewport:{width:1080,height:260}});
 require('fs').writeFileSync(__dirname+'/b.html','<!doctype html><html><body>'+html+'</body></html>');
 await p.goto('file://'+__dirname+'/b.html'); await p.evaluate(()=>document.fonts.ready); await p.waitForTimeout(300);
 await p.screenshot({path:__dirname+'/link-in-bio.png',omitBackground:true}); await b.close(); })();
