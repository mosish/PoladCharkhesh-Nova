const { chromium } = require('playwright');
const B='http://localhost:8090/';
(async()=>{const b=await chromium.launch(); const errs=[];
 async function page(file,w,h,o={}){const ctx=await b.newContext({viewport:{width:w,height:h},reducedMotion:o.rm?'reduce':'no-preference',colorScheme:o.dark?'dark':'light',deviceScaleFactor:o.dpr||1});const p=await ctx.newPage();p.on('pageerror',e=>errs.push(file+' '+e.message));if(o.fallback)await p.addInitScript(()=>{const f=CSS.supports.bind(CSS);CSS.supports=(a,b)=>/animation-timeline/.test(a+(b||''))?false:f(a,b);});await p.goto(B+file,{waitUntil:'networkidle'});await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(300);return p;}
 async function at(p,sel,off){await p.evaluate(([s,o])=>{const el=document.querySelector(s);window.scrollTo(0,el.getBoundingClientRect().top+scrollY+o)},[sel,off]);await p.waitForTimeout(450);}
 async function ea(p,h,f){const t=await p.evaluate(()=>{const t=document.querySelector('.ea__track');return {top:t.getBoundingClientRect().top+scrollY,h:t.offsetHeight}});await p.evaluate(y=>window.scrollTo(0,y),t.top+(t.h-h)*f);await p.waitForTimeout(500);}
 const S=(p,n)=>p.screenshot({path:`v2-${n}.png`});
 let p=await page('fa.html',1440,900); await S(p,'d1-fa-hero');
 await at(p,'#explorer',-60); await S(p,'d2-fa-explorer');
 await p.fill('#q','6208'); await p.waitForTimeout(400); await S(p,'d2-fa-ask');
 await p.fill('#q',''); await p.evaluate(()=>scrollTo(0,0)); await p.waitForTimeout(300);
 await p.fill('#q-hero','22220 E'); await p.press('#q-hero','Enter'); await p.waitForTimeout(1500); await S(p,'d3-fa-workspace');
 await p.click('#wst-1'); await p.waitForTimeout(300); await S(p,'d3-fa-workspace-specs');
 for (const [f,n] of [[0.04,'1'],[0.2,'2'],[0.4,'3'],[0.6,'4'],[0.8,'5'],[0.97,'6']]) { await ea(p,900,f); await S(p,'d4-ea-'+n); }
 await at(p,'#contact',-60); await S(p,'d5-fa-contact'); await p.context().close();
 p=await page('fa.html',1440,900,{fallback:true}); await ea(p,900,.6); await S(p,'d4-ea-fallback'); await p.context().close();
 p=await page('fa.html',390,844,{dpr:2}); await S(p,'d6-m-hero'); await at(p,'#explorer',-54); await S(p,'d7-m-explorer');
 await p.fill('#q','6208'); await p.waitForTimeout(400); await at(p,'#explorer',-54); await S(p,'d7-m-ask'); await p.fill('#q',''); await p.waitForTimeout(300);
 await at(p,'#explorer',-54); await p.click('tr[data-slug="22220-e"]'); await p.waitForTimeout(900); await S(p,'d8-m-sheet'); await p.keyboard.press('Escape'); await p.waitForTimeout(500);
 for (const [f,n] of [[0.25,'a'],[0.55,'b']]) { await ea(p,844,f); await S(p,'d9-m-ea-'+n); }
 await p.context().close();
 p=await page('en.html',1440,900); await S(p,'d10-en-hero'); await at(p,'#explorer',-60); await p.click('tr[data-slug="ucp-208"]'); await p.waitForTimeout(700); await at(p,'.explorer__body',-76); await S(p,'d10-en-workspace'); await ea(p,900,.6); await S(p,'d10-en-ea'); await p.context().close();
 p=await page('en.html',390,844,{dpr:2}); await S(p,'d10-en-m-hero'); await p.context().close();
 p=await page('fa.html',1440,900,{dark:true}); await S(p,'d11-dark-hero'); await at(p,'#explorer',-60); await p.click('tr[data-slug="30208"]'); await p.waitForTimeout(700); await at(p,'.explorer__body',-76); await S(p,'d11-dark-workspace'); await ea(p,900,.6); await S(p,'d11-dark-ea'); await p.context().close();
 p=await page('en.html',390,844,{dark:true,dpr:2}); await at(p,'#explorer',-54); await S(p,'d11-dark-m-explorer'); await p.context().close();
 p=await page('fa.html',1440,900,{rm:true}); await at(p,'#anatomy',140); await S(p,'d12-rm-ea'); await p.context().close();
 p=await page('fa.html',390,844,{rm:true,dpr:2}); await at(p,'.ea__track',-54); await S(p,'d12-rm-m-ea'); await p.context().close();
 p=await page('admin.html',1440,900); for (const id of ['dashboard','products','editor','media','sections','inquiries','publish','company']) { const el=await p.$(`#${id} .frame-scroll`); await el.screenshot({path:`v2-d13-${id}.png`}); } const ph=await p.$('#mobile .phones'); await ph.screenshot({path:'v2-d13-mobile.png'}); await p.context().close();
 p=await page('system.html',1440,900); await S(p,'sys'); await p.context().close();
 console.log('errors', errs.length?errs:'none'); await b.close();})();
