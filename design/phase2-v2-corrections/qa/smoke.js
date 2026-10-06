const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const errs=[];
const ctx=await b.newContext({viewport:{width:1440,height:900}});const p=await ctx.newPage();p.on('pageerror',e=>errs.push(e.message));p.on('console',m=>m.type()==='error'&&errs.push(m.text()));
await p.goto('http://localhost:8090/fa.html',{waitUntil:'networkidle'});
await p.fill('#q-hero','6205-2RS'); await p.press('#q-hero','Enter'); await p.waitForTimeout(1200);
console.log(await p.evaluate(()=>({hash:location.hash, title:document.querySelector('#ws-code').textContent, match:document.querySelector('#ws-match').textContent, wa:decodeURIComponent(document.querySelector('#ws-wa').href), state:document.querySelector('#ws').dataset.state, ask:document.querySelector('#ask').hidden?'':document.querySelector('#ask-t').textContent})));
await p.screenshot({path:'k-ws-variant.png'});
await p.click('#wst-1'); await p.waitForTimeout(200); await p.screenshot({path:'k-ws-variant-specs.png'});
console.log('errors',errs); await b.close();})();
