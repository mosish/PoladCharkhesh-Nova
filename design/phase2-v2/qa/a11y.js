const { chromium } = require('playwright'); const fs=require('fs');
const axe=fs.readFileSync(require.resolve('axe-core/axe.min.js'),'utf8');
(async()=>{const b=await chromium.launch();
for (const [lang,scheme] of [['fa','light'],['en','light'],['fa','dark']]){const p=await b.newPage({viewport:{width:1280,height:900},colorScheme:scheme});await p.goto('http://localhost:8090/'+lang+'.html',{waitUntil:'networkidle'});
 await p.click('tr[data-slug="ucp-208"]'); await p.waitForTimeout(500);
 await p.addScriptTag({content:axe});
 const r=await p.evaluate(async()=>{const res=await axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag21aa','best-practice']});return res.violations.map(v=>({id:v.id,impact:v.impact,n:v.nodes.length,t:v.nodes.slice(0,3).map(n=>n.target.join(' ')+' :: '+(n.failureSummary||'').split('\n')[1])}))});
 console.log('==',lang,scheme,r.length?JSON.stringify(r,null,1):'no violations');await p.close();}
// keyboard path
const k=await b.newPage({viewport:{width:1280,height:900}});await k.goto('http://localhost:8090/en.html',{waitUntil:'networkidle'});
await k.focus('#q-hero');await k.keyboard.type('22220');await k.keyboard.press('Enter');await k.waitForTimeout(900);
console.log('after Enter, focused:',await k.evaluate(()=>document.activeElement.id||document.activeElement.className), 'hash', await k.evaluate(()=>location.hash));
await k.keyboard.press('Tab');await k.keyboard.press('Tab');await k.keyboard.press('Tab');await k.keyboard.press('Tab');
console.log('tab4:',await k.evaluate(()=>document.activeElement.className+' '+document.activeElement.textContent.trim().slice(0,20)));
await k.keyboard.press('ArrowRight');console.log('arrow:',await k.evaluate(()=>document.activeElement.textContent));
// fallback path: pretend scroll timelines are unsupported
const f=await b.newPage({viewport:{width:1440,height:900}});await f.addInitScript(()=>{const o=CSS.supports.bind(CSS);CSS.supports=(a,b)=>/animation-timeline/.test(a+(b||''))?false:o(a,b);});
await f.addStyleTag?.call; await f.goto('http://localhost:8090/fa.html',{waitUntil:'networkidle'});
await f.addStyleTag({content:'.ea__stage,.bom tbody tr,.ea .balloon circle,.draw [data-d],.steps::before{animation:none!important}'});
const tr=await f.evaluate(()=>{const t=document.querySelector('.ea__track');return {top:t.getBoundingClientRect().top+scrollY,h:t.offsetHeight}});
await f.evaluate(y=>window.scrollTo(0,y),tr.top+(tr.h-900)*0.45);await f.waitForTimeout(500);
console.log('fallback --e:',await f.evaluate(()=>getComputedStyle(document.querySelector('.ea__stage')).getPropertyValue('--e')), 'js-io:',await f.evaluate(()=>document.documentElement.classList.contains('js-io')), 'row on:', await f.evaluate(()=>[...document.querySelectorAll('.bom tr.is-on')].map(t=>t.dataset.i)));
await f.screenshot({path:'s-fallback-ea.png'});
await b.close();})();
