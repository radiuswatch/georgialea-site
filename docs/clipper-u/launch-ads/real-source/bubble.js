const { chromium } = require('playwright');
const font = `@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-Black.otf);font-weight:900}
html,body{margin:0;width:1080px;height:1920px;background:transparent;font-family:I,sans-serif}`;
const html = `<style>${font}
.b{position:absolute;left:110px;right:110px;top:1190px;background:#fff;border-radius:46px;padding:34px 40px;text-align:center;color:#0b1a10;font-weight:900;font-size:66px;line-height:1.08;letter-spacing:-1px;box-shadow:0 18px 40px rgba(0,0,0,.45),0 0 0 6px rgba(49,201,113,.9)}
.b em{font-style:normal;color:#1fa65a}
.b:after{content:"";position:absolute;left:150px;top:-44px;border-left:26px solid transparent;border-right:40px solid transparent;border-bottom:50px solid #fff}
</style><div class="b">You know <em>ClipNow</em><br>is live?</div>`;
(async()=>{ const b=await chromium.launch(); const p=await b.newPage({viewport:{width:1080,height:1920}});
 require('fs').writeFileSync(__dirname+'/bubble.html','<!doctype html><html><body>'+html+'</body></html>');
 await p.goto('file://'+__dirname+'/bubble.html'); await p.evaluate(()=>document.fonts.ready); await p.waitForTimeout(300);
 await p.screenshot({path:__dirname+'/bubble.png',omitBackground:true}); await b.close(); })();
