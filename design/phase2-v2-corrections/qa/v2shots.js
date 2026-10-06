const { chromium } = require('playwright');
const B='http://localhost:'+(process.env.PORT||8090)+'/';
(async()=>{const b=await chromium.launch();const errs=[];
 async function page(lang,w,h,o={}){const ctx=await b.newContext({viewport:{width:w,height:h},reducedMotion:o.rm?'reduce':'no-preference',colorScheme:o.dark?'dark':'light'});const p=await ctx.newPage();p.on('pageerror',e=>errs.push(lang+w+' '+e.message));p.on('console',m=>m.type()==='error'&&errs.push(lang+w+' '+m.text()));await p.goto(B+lang+'.html',{waitUntil:'networkidle'});await p.waitForTimeout(300);return p;}
 async function at(p,sel,off){await p.evaluate(([s,o])=>{const el=document.querySelector(s);window.scrollTo(0,el.getBoundingClientRect().top+scrollY+o)},[sel,off]);await p.waitForTimeout(400);}
 async function ea(p,h,f){const t=await p.evaluate(()=>{const t=document.querySelector('.ea__track');return {top:t.getBoundingClientRect().top+scrollY,h:t.offsetHeight}});await p.evaluate(y=>window.scrollTo(0,y),t.top+(t.h-h)*f);await p.waitForTimeout(450);}
 const tag=process.argv[2]||'v2';
 let p=await page('fa',1440,900); await p.screenshot({path:`${tag}-fa-d-hero.png`});
 await at(p,'#explorer',-60); await p.screenshot({path:`${tag}-fa-d-exp.png`});
 await p.fill('#q','6208'); await p.waitForTimeout(400); await p.screenshot({path:`${tag}-fa-d-ask.png`});
 await p.fill('#q',''); await p.waitForTimeout(200); await p.click('tr[data-slug="6205"]'); await p.waitForTimeout(600); await at(p,'#explorer',330); await p.screenshot({path:`${tag}-fa-d-ws.png`});
 for(const f of [0.05,0.2,0.4,0.6,0.8]){await ea(p,900,f);await p.screenshot({path:`${tag}-fa-d-ea${f}.png`});}
 await at(p,'#contact',-60); await p.screenshot({path:`${tag}-fa-d-con.png`});
 await p.evaluate(()=>scrollTo(0,0)); await p.waitForTimeout(300); await p.fill('#q-hero','22220 E'); await p.press('#q-hero','Enter'); await p.waitForTimeout(1500); await p.screenshot({path:`${tag}-fa-d-hit.png`});
 console.log('ws bottom vs viewport', await p.evaluate(()=>[Math.round(document.querySelector('#ws').getBoundingClientRect().bottom), innerHeight]));
 await at(p,'#families',-60); await p.screenshot({path:`${tag}-fa-d-fam.png`}); await p.context().close();
 p=await page('en',1440,900); await p.screenshot({path:`${tag}-en-d-hero.png`}); await at(p,'#explorer',-60); await p.click('tr[data-slug="ucp-208"]'); await p.waitForTimeout(600); await at(p,'#explorer',330); await p.screenshot({path:`${tag}-en-d-ws.png`}); await p.context().close();
 p=await page('fa',1440,900,{dark:true}); await p.screenshot({path:`${tag}-fa-d-dark-hero.png`}); await at(p,'#explorer',-60); await p.click('tr[data-slug="30208"]'); await p.waitForTimeout(600); await at(p,'#explorer',330); await p.screenshot({path:`${tag}-fa-d-dark-ws.png`}); await p.context().close();
 p=await page('fa',1440,900,{rm:true}); await at(p,'#anatomy',140); await p.screenshot({path:`${tag}-fa-d-rm-ea.png`}); await p.context().close();
 p=await page('fa',390,844,{rm:true}); await at(p,'.ea__track',-54); await p.screenshot({path:`${tag}-fa-m-rm-ea.png`}); await p.context().close();
 p=await page('fa',390,844); await p.screenshot({path:`${tag}-fa-m-hero.png`}); await at(p,'#explorer',-54); await p.screenshot({path:`${tag}-fa-m-exp.png`});
 await p.click('tr[data-slug="22220-e"]'); await p.waitForTimeout(800); await p.screenshot({path:`${tag}-fa-m-sheet.png`}); await p.keyboard.press('Escape'); await p.waitForTimeout(400);
 for(const f of [0.25,0.55]){await ea(p,844,f);await p.screenshot({path:`${tag}-fa-m-ea${f}.png`});}
 console.log('m scrollWidth',await p.evaluate(()=>document.documentElement.scrollWidth)); await p.context().close();
 p=await page('en',390,844); await p.screenshot({path:`${tag}-en-m-hero.png`}); console.log('en m scrollWidth',await p.evaluate(()=>document.documentElement.scrollWidth)); await p.context().close();
 console.log('errors',errs.length?errs:'none'); await b.close();})();
