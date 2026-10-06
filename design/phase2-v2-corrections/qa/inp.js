const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();
for (const port of (process.env.PORTS||"8091,8090").split(",").map(Number)) {
  const res=[];
  for (let run=0; run<3; run++){
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true});const p=await ctx.newPage();
  const cdp=await ctx.newCDPSession(p); await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
  await p.addInitScript(()=>{window.__ev=[];new PerformanceObserver(l=>{for(const e of l.getEntries()) if(e.interactionId) window.__ev.push([e.name,Math.round(e.duration),Math.round(e.processingEnd-e.processingStart),Math.round(e.startTime+e.duration-e.processingEnd)])}).observe({type:'event',buffered:true,durationThreshold:16});});
  await p.goto(`http://localhost:${port}/fa.html`,{waitUntil:'networkidle'}); await p.waitForTimeout(800);
  await p.evaluate(()=>document.querySelector('#explorer').scrollIntoView()); await p.waitForTimeout(500);
  const sel='tr[data-slug="6205"], .row[data-slug="6205"]';
  for (let i=0;i<3;i++){ await p.tap(sel); await p.waitForTimeout(900); await p.keyboard.press('Escape'); await p.waitForTimeout(700); }
  await p.tap('#q'); await p.keyboard.type('22220', {delay:150}); await p.waitForTimeout(600);
  res.push(await p.evaluate(()=>window.__ev)); await ctx.close();}
  console.log(port, JSON.stringify(res));
}
await b.close();})();
