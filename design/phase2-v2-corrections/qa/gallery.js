const { chromium } = require('playwright');
const B='http://localhost:8090/';
(async()=>{const b=await chromium.launch();
 async function page(lang,w,h,o={}){const ctx=await b.newContext({viewport:{width:w,height:h},reducedMotion:o.rm?'reduce':'no-preference',colorScheme:o.dark?'dark':'light'});const p=await ctx.newPage();if(o.fallback)await p.addInitScript(()=>{const f=CSS.supports.bind(CSS);CSS.supports=(a,b)=>/animation-timeline/.test(a+(b||''))?false:f(a,b);});await p.goto(B+lang,{waitUntil:'networkidle'});await p.waitForTimeout(300);return p;}
 async function at(p,sel,off){await p.evaluate(([s,o])=>{const el=document.querySelector(s);window.scrollTo(0,el.getBoundingClientRect().top+scrollY+o)},[sel,off]);await p.waitForTimeout(400);}
 async function ea(p,h,f){const t=await p.evaluate(()=>{const t=document.querySelector('.ea__track');return {top:t.getBoundingClientRect().top+scrollY,h:t.offsetHeight}});await p.evaluate(y=>window.scrollTo(0,y),t.top+(t.h-h)*f);await p.waitForTimeout(500);}
 let p=await page('fa.html',1440,900); await p.screenshot({path:'g-fa-d-hero.png'});
 await at(p,'#explorer',-56); await p.click('.row[data-slug="6205"]'); await p.waitForTimeout(500); await at(p,'#explorer',300); await p.screenshot({path:'g-fa-d-exp.png'});
 await ea(p,900,.47); await p.screenshot({path:'g-fa-d-ea.png'});
 await at(p,'#contact',-56); await p.screenshot({path:'g-fa-d-con.png'}); await p.context().close();
 p=await page('en.html',1440,900); await at(p,'#families',-56); await p.screenshot({path:'g-en-d-fam.png'});
 await at(p,'#explorer',-56); await p.click('.row[data-slug="6205"]'); await p.waitForTimeout(500); await p.click('#wst-2'); await at(p,'#explorer',300); await p.screenshot({path:'g-en-d-exp-data.png'});
 await at(p,'#engineering',-56); await p.screenshot({path:'g-en-d-eng.png'}); await p.context().close();
 p=await page('en.html',1440,900,{dark:true}); await at(p,'#explorer',-56); await p.click('.row[data-slug="30208"]'); await p.waitForTimeout(500); await at(p,'#explorer',300); await p.screenshot({path:'g-en-d-dark-exp.png'}); await p.context().close();
 p=await page('fa.html',1440,900,{rm:true}); await at(p,'#anatomy',100); await p.screenshot({path:'g-fa-d-rm-ea.png'}); await p.context().close();
 p=await page('fa.html',1440,900,{fallback:true}); await ea(p,900,.4); await p.screenshot({path:'g-fa-d-fallback-ea.png'}); await p.context().close();
 p=await page('fa.html',390,844); await p.screenshot({path:'g-fa-m-hero.png'}); await at(p,'#explorer',-52); await p.click('.row[data-slug="22220-e"]'); await p.waitForTimeout(800); await p.screenshot({path:'g-fa-m-sheet.png'}); await p.keyboard.press('Escape'); await p.waitForTimeout(400); await ea(p,844,.5); await p.screenshot({path:'g-fa-m-ea.png'}); await p.context().close();
 p=await page('en.html',390,844); await at(p,'#explorer',120); await p.screenshot({path:'g-en-m-exp.png'}); await p.context().close();
 p=await page('system.html',1440,900); await p.screenshot({path:'g-sys.png'}); await p.context().close();
 await b.close();})();
