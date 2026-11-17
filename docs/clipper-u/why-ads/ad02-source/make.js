const { chromium } = require('playwright'); const S = process.env.S, D = process.env.D;
const font = `@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-ExtraBold.otf);font-weight:800}
@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-Black.otf);font-weight:900}
@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-SemiBold.otf);font-weight:600}
@font-face{font-family:I;src:url(file:///usr/share/fonts/opentype/inter/Inter-Medium.otf);font-weight:500}
html,body{margin:0;width:1080px;height:1920px;background:transparent;font-family:I,sans-serif}`;
const pages = {
 'title.png': `<style>${font} .t{position:absolute;left:60px;right:60px;top:300px;text-align:center;color:#F5C451;font-weight:900;font-size:128px;line-height:1.02;letter-spacing:-1px;
   -webkit-text-stroke:10px #0b1a10;paint-order:stroke fill;text-shadow:0 10px 30px rgba(0,0,0,.55);transform:rotate(-3deg)}</style><div class="t">WHY AREN'T<br>YOU CLIPPING?</div>`,
};
(async()=>{ const b = await chromium.launch(); const p = await b.newPage({viewport:{width:1080,height:1920}});
  for (const [f, html] of Object.entries(pages)) { const hf = `${S}/gfx/${f}.html`; require('fs').writeFileSync(hf, `<!doctype html><html><body>${html}</body></html>`);
    await p.goto('file://' + hf); await p.evaluate(()=>document.fonts.ready); await p.waitForTimeout(500);
    await p.screenshot({path:`${S}/gfx/${f}`, omitBackground: !/s4|endcard/.test(f)}); console.log('made', f); }
  await b.close(); })();
