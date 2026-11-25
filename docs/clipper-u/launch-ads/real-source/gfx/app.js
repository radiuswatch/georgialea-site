const { chromium } = require('playwright'); const R='/tmp/claude-0/-home-user-georgialea-site/449620df-09ad-5551-9222-45e9645e29f9/scratchpad/real', A='/tmp/claude-0/-home-user-georgialea-site/449620df-09ad-5551-9222-45e9645e29f9/scratchpad/ad11';
(async()=>{ const b=await chromium.launch(); const p=await b.newPage({viewport:{width:1080,height:1920}});
 for (const n of ['app-r','app-s','app-t']) {
  const html=`<!doctype html><html><body style="margin:0;width:1080px;height:1920px;background:url(file://${R}/gfx/${n}-bg.png) center/cover">
   <div style="position:absolute;left:150px;top:640px;width:780px;height:1688px;border-radius:96px;background:#0b0d0b;padding:22px;box-sizing:border-box;box-shadow:0 0 0 6px #2a2f2a,0 0 120px 30px rgba(49,201,113,.45),0 40px 80px rgba(0,0,0,.6)">
   <div style="width:100%;height:100%;border-radius:76px;overflow:hidden;background:#0b0d0b url(file://${A}/ui-clips.png) top center/100% auto no-repeat"></div>
   <div style="position:absolute;left:50%;top:44px;width:200px;height:56px;margin-left:-100px;border-radius:30px;background:#000"></div></div></body></html>`;
  require('fs').writeFileSync(`${R}/gfx/${n}.html`, html); await p.goto('file://'+R+'/gfx/'+n+'.html'); await p.waitForTimeout(400);
  await p.screenshot({path:`${R}/gfx/${n}.png`}); console.log('made', n); }
 await b.close(); })();
