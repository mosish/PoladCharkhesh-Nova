const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1440,height:900}});const errs=[];p.on('pageerror',e=>errs.push(e.message));p.on('requestfailed',r=>errs.push('FAIL '+r.url()));
await p.goto('http://localhost:8090/index.html',{waitUntil:'networkidle'});await p.screenshot({path:'h-top.png'});
for(const id of ['screens','perf','gate']){await p.evaluate(i=>{const el=document.getElementById(i);window.scrollTo(0,el.getBoundingClientRect().top+scrollY-60)},id);await p.waitForTimeout(300);await p.screenshot({path:'h-'+id+'.png'});}
const f=await b.newPage({viewport:{width:1440,height:900}});await f.goto('http://localhost:8090/fa.html',{waitUntil:'networkidle'});await f.evaluate(()=>{const el=document.getElementById('process');window.scrollTo(0,el.getBoundingClientRect().top+scrollY+150)});await f.waitForTimeout(400);await f.screenshot({path:'h-fa-pro.png'});
const m=await b.newPage({viewport:{width:390,height:844}});await m.goto('http://localhost:8090/index.html',{waitUntil:'networkidle'});console.log('hub mobile scrollWidth',await m.evaluate(()=>document.documentElement.scrollWidth));
console.log('errors',errs);await b.close();})();
