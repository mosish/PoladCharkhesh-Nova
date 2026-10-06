const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();
for (const lang of (process.argv[2]||'fa,en').split(',')){const p=await b.newPage({viewport:{width:390,height:844}});await p.goto('http://localhost:8090/'+lang+'.html',{waitUntil:'networkidle'});
const r=await p.evaluate(()=>{const W=document.documentElement.clientWidth;const out=[];for(const el of document.querySelectorAll('body *')){const b=el.getBoundingClientRect();if(b.width&&(b.right>W+1||b.left<-1)){const st=getComputedStyle(el);if(st.position==='fixed')continue;out.push(el.tagName+'.'+(el.className.baseVal??el.className)+' L'+Math.round(b.left)+' R'+Math.round(b.right)+' W'+Math.round(b.width))}}return out.slice(0,25)});
console.log(lang, r.join('\n  '));await p.close();}
await b.close();})();
