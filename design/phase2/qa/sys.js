const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1440,height:900}});
const errs=[];p.on('pageerror',e=>errs.push(e.message));p.on('console',m=>m.type()==='error'&&errs.push(m.text()));
await p.goto('http://localhost:8090/system.html',{waitUntil:'networkidle'});
for(const id of ['principles','color','type','drawing','bidi','controls','perf']){await p.evaluate(i=>{const el=document.getElementById(i);window.scrollTo(0,el.getBoundingClientRect().top+scrollY-60)},id);await p.waitForTimeout(250);await p.screenshot({path:'sys-'+id+'.png'});}
console.log('overflowX', await p.evaluate(()=>document.documentElement.scrollWidth), 'errors', errs);
const m=await b.newPage({viewport:{width:390,height:844}});await m.goto('http://localhost:8090/system.html');console.log('mobile scrollWidth',await m.evaluate(()=>document.documentElement.scrollWidth));
await b.close();})();
