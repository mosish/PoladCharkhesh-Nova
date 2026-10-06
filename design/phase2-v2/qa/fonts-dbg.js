const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const ctx=await b.newContext({viewport:{width:390,height:844},isMobile:true});const p=await ctx.newPage();
await p.goto(`http://localhost:8090/en.html`,{waitUntil:'load'});await p.waitForTimeout(2500);
console.log(await p.evaluate(()=>[...document.fonts].map(f=>f.family+' '+f.weight+' '+f.unicodeRange.slice(0,20)+' '+f.status)));
console.log(await p.evaluate(()=>{const l=document.querySelector('.hero__lead');const cs=getComputedStyle(l);return [cs.fontFamily,cs.fontWeight,l.textContent.slice(0,40)]}));
await b.close();})();
