const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const ctx=await b.newContext({viewport:{width:1440,height:900}});const p=await ctx.newPage();const fonts=[];
p.on('request',r=>{if(r.url().endsWith('.woff2'))fonts.push(r.url().split('/').pop())});
await p.goto('http://localhost:8090/fa.html',{waitUntil:'networkidle'});
for (const s of ['6205','ucp-208','tc-25x42x7']) { await p.click(`tr[data-slug="${s}"]`); await p.waitForTimeout(600); for (const t of ['#wst-1','#wst-2','#wst-0']) { await p.click(t); await p.waitForTimeout(150);} }
const off=await p.evaluate(()=>{const out=[];const tw=document.createTreeWalker(document.querySelector('#ws'),NodeFilter.SHOW_TEXT);let n;while(n=tw.nextNode()){if(!/[A-Za-z]/.test(n.textContent))continue;const el=n.parentElement;const ff=getComputedStyle(el).fontFamily;if(/^"?JetBrains/.test(ff))continue;out.push(el.tagName+'.'+el.className+' | '+n.textContent.trim().slice(0,50));}return out;});
console.log(fonts); console.log(off.join('\n')); await b.close();})();
