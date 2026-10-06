const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const ctx=await b.newContext({viewport:{width:1440,height:900}});const p=await ctx.newPage();let step='load';
p.on('request',r=>{if(r.url().includes('latin'))console.log('latin requested during',step)});
await p.goto('http://localhost:8090/fa.html',{waitUntil:'networkidle'});
const scan=async()=>p.evaluate(()=>{const out=[];const tw=document.createTreeWalker(document.querySelector('#ws'),NodeFilter.SHOW_TEXT);let n;while(n=tw.nextNode()){if(!/[A-Za-z]/.test(n.textContent))continue;const el=n.parentElement;const ff=getComputedStyle(el).fontFamily;if(/^"?JetBrains/.test(ff))continue;out.push(el.tagName+'.'+el.className+' | '+n.textContent.trim().slice(0,50));}return out;});
for (const s of ['6205','ucp-208','tc-25x42x7']) { step='open '+s; await p.click(`tr[data-slug="${s}"]`); await p.waitForTimeout(700); console.log(s, await scan()); for (const t of ['#wst-1','#wst-2']) { step=s+' '+t; await p.click(t); await p.waitForTimeout(300); console.log(step, await scan()); } }
await b.close();})();
