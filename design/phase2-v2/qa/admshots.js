const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const ctx=await b.newContext({viewport:{width:1440,height:900}});const p=await ctx.newPage();const errs=[];
p.on('pageerror',e=>errs.push(e.message));p.on('console',m=>m.type()==='error'&&errs.push(m.text()));p.on('requestfailed',r=>errs.push('fail '+r.url()));
await p.goto('http://localhost:8090/admin.html',{waitUntil:'networkidle'});await p.evaluate(()=>document.fonts.ready);
const tag=process.argv[2]||'adm';
await p.screenshot({path:`${tag}-top.png`});
for(const id of ['dashboard','products','editor','media','sections','inquiries','publish','company','mobile','model']){const el=await p.$('#'+id);await el.screenshot({path:`${tag}-${id}.png`});}
console.log('overflow', await p.evaluate(()=>[...document.querySelectorAll('.frame-scroll')].map(f=>f.scrollWidth-f.clientWidth)));
const m=await b.newContext({viewport:{width:390,height:844}});const q=await m.newPage();await q.goto('http://localhost:8090/admin.html',{waitUntil:'networkidle'});
console.log('mobile scrollWidth',await q.evaluate(()=>document.documentElement.scrollWidth));await q.screenshot({path:`${tag}-m.png`});
console.log('errors',errs.length?errs:'none');await b.close();})();
