const { chromium } = require('playwright'); const S = process.env.S, D = process.env.D;
const font = `@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-ExtraBold.otf);font-weight:800}
@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-Black.otf);font-weight:900}
@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-SemiBold.otf);font-weight:600}
@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-Medium.otf);font-weight:500}
html,body{margin:0;width:1080px;height:1920px;background:transparent;font-family:I,sans-serif}`;
const slam = (txt, color, stroke, size) => `<style>${font} .t{position:absolute;left:50px;right:50px;top:170px;text-align:center;color:${color};font-weight:900;font-size:${size}px;line-height:1.0;letter-spacing:-1px;
   -webkit-text-stroke:11px ${stroke};paint-order:stroke fill;text-shadow:0 10px 30px rgba(0,0,0,.55);transform:rotate(-3deg)}</style><div class="t">${txt}</div>`;
const end = (tag) => `<style>${font} body{background:url(file://${D}/end.png) center/cover}
   .live{position:absolute;left:50%;top:420px;transform:translateX(-50%);white-space:nowrap;font-weight:900;font-size:40px;letter-spacing:.12em;color:#06230F;background:#31C971;border-radius:999px;padding:14px 34px;box-shadow:0 0 50px rgba(49,201,113,.55)}
   .live i{display:inline-block;width:18px;height:18px;border-radius:50%;background:#06230F;margin-right:16px;vertical-align:3px}
   .cta{position:absolute;left:50%;top:1420px;transform:translateX(-50%);white-space:nowrap;color:#fff;font-weight:800;font-size:56px;padding:22px 46px;border:3px solid #31C971;border-radius:999px;background:rgba(15,59,35,.55)}
   .cta b{color:#F5C451}
   .tag{position:absolute;left:60px;right:60px;top:1585px;text-align:center;color:#F5C451;font-weight:900;font-size:50px;line-height:1.15;letter-spacing:-.5px}
   .fine{position:absolute;left:90px;right:90px;bottom:90px;text-align:center;color:rgba(233,237,233,.62);font-weight:500;font-size:26px;line-height:1.45}</style>
   <div class="live"><i></i>NOW LIVE</div>
   <div class="cta">Start your <b>7-day free trial</b></div>
   <div class="tag">${tag}</div>
   <div class="fine">Earnings depend on campaigns and views and aren't guaranteed.<br>Only clip content you have permission to use.</div>`;
const pages = {
 'live.png': slam('CLIPNOW<br>IS LIVE!', '#31C971', '#06230F', 150),
 'tag-a.png': slam('CLIP YOUR<br>HEART AWAY.', '#F5C451', '#0b1a10', 132),
 'tag-b.png': slam('THE TIME<br>TO CLIP<br>IS NOW.', '#F5C451', '#0b1a10', 132),
 'tag-c.png': slam('CLIPPING<br>MADE EASY.<br><span style="font-size:96px;color:#31C971;-webkit-text-stroke-color:#06230F">EVEN YOU<br>CAN DO IT.</span>', '#F5C451', '#0b1a10', 120),
 'end-a.png': end('Clip Your Heart Away.'),
 'end-b.png': end('The Time to Clip Is Now.'),
 'end-c.png': end('Clipping Made Easy.<br>Even You Can Do It.'),
};
(async()=>{ const b = await chromium.launch(); const p = await b.newPage({viewport:{width:1080,height:1920}});
  for (const [f, html] of Object.entries(pages)) { const hf = `${S}/gfx/${f}.html`; require('fs').writeFileSync(hf, `<!doctype html><html><body>${html}</body></html>`);
    await p.goto('file://' + hf); await p.evaluate(()=>document.fonts.ready); await p.waitForTimeout(400);
    await p.screenshot({path:`${S}/gfx/${f}`, omitBackground: !/^end/.test(f)}); console.log('made', f); }
  await b.close(); })();
