const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const errs=[];
for (const [lang,w] of [['fa',320],['fa',390],['en',320]]) {
 const ctx=await b.newContext({viewport:{width:w,height:740},deviceScaleFactor:2,isMobile:true,hasTouch:true});const p=await ctx.newPage();p.on('pageerror',e=>errs.push(e.message));
 await p.goto(`http://localhost:8090/${lang}.html`,{waitUntil:'networkidle'});
 const sw=await p.evaluate(()=>[document.documentElement.scrollWidth, document.documentElement.clientWidth]);
 await p.screenshot({path:`k-${lang}-${w}-top.png`});
 await p.fill('#q-hero','6205-2rs'); await p.press('#q-hero','Enter'); await p.waitForTimeout(1300);
 await p.screenshot({path:`k-${lang}-${w}-sheet.png`});
 const ctas=await p.evaluate(()=>[...document.querySelectorAll('.ws__foot .btn')].map(a=>{const r=a.getBoundingClientRect();return [a.textContent.trim(), Math.round(r.left), Math.round(r.right), Math.round(r.width)]}));
 console.log(lang,w,'sw/cw',sw,'focus',await p.evaluate(()=>document.activeElement.id||document.activeElement.className),ctas);
 await ctx.close();}
console.log('errors',errs);await b.close();})();
