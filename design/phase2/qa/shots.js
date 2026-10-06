const { chromium } = require('playwright');
const B='http://localhost:8090/';
(async()=>{
 const b=await chromium.launch();
 const errs=[];
 async function page(lang,w,h,opts={}){const ctx=await b.newContext({viewport:{width:w,height:h},deviceScaleFactor:1,reducedMotion:opts.rm?'reduce':'no-preference',colorScheme:opts.dark?'dark':'light'});const p=await ctx.newPage();p.on('console',m=>{if(m.type()==='error')errs.push(lang+w+': '+m.text())});p.on('pageerror',e=>errs.push(lang+w+' PAGEERR: '+e.message));await p.goto(B+lang+'.html',{waitUntil:'networkidle'});await p.waitForTimeout(300);return p;}
 async function at(p,sel,off=0){await p.evaluate(([s,o])=>{const el=document.querySelector(s);window.scrollTo(0,el.getBoundingClientRect().top+scrollY+o)},[sel,off]);await p.waitForTimeout(350);}
 const tag=process.argv[2]||'a';
 for(const lang of ['fa','en']){
  const p=await page(lang,1440,900);
  await p.screenshot({path:`s-${tag}-${lang}-d-hero.png`});
  await at(p,'#families',-56); await p.screenshot({path:`s-${tag}-${lang}-d-fam.png`});
  await at(p,'#explorer',-56); await p.click('.row[data-slug="6205"]'); await p.waitForTimeout(500); await at(p,'#explorer',260); await p.screenshot({path:`s-${tag}-${lang}-d-exp.png`});
  await p.click('#wst-2'); await p.waitForTimeout(200); await p.screenshot({path:`s-${tag}-${lang}-d-exp-data.png`});
  const tr=await p.evaluate(()=>{const t=document.querySelector('.ea__track');return {top:t.getBoundingClientRect().top+scrollY,h:t.offsetHeight}});
  for(const f of [0.1,0.5]){await p.evaluate(y=>window.scrollTo(0,y),tr.top+(tr.h-900)*f);await p.waitForTimeout(400);await p.screenshot({path:`s-${tag}-${lang}-d-ea${f}.png`});}
  await at(p,'#engineering',-56); await p.screenshot({path:`s-${tag}-${lang}-d-eng.png`});
  await at(p,'#process',-56); await p.screenshot({path:`s-${tag}-${lang}-d-pro.png`});
  await at(p,'#contact',-56); await p.screenshot({path:`s-${tag}-${lang}-d-con.png`});
  await p.context().close();
  const m=await page(lang,390,844);
  await m.screenshot({path:`s-${tag}-${lang}-m-hero.png`});
  await at(m,'#families',-52); await m.screenshot({path:`s-${tag}-${lang}-m-fam.png`});
  await at(m,'#explorer',-52); await m.screenshot({path:`s-${tag}-${lang}-m-exp.png`});
  await m.click('.row[data-slug="22220-e"]'); await m.waitForTimeout(700); await m.screenshot({path:`s-${tag}-${lang}-m-sheet.png`});
  await m.keyboard.press('Escape'); await m.waitForTimeout(400);
  const tm=await m.evaluate(()=>{const t=document.querySelector('.ea__track');return {top:t.getBoundingClientRect().top+scrollY,h:t.offsetHeight}});
  await m.evaluate(y=>window.scrollTo(0,y),tm.top+(tm.h-844)*0.5);await m.waitForTimeout(400);await m.screenshot({path:`s-${tag}-${lang}-m-ea.png`});
  await at(m,'#contact',-52); await m.screenshot({path:`s-${tag}-${lang}-m-con.png`});
  const ov=await m.evaluate(()=>document.documentElement.scrollWidth); console.log(lang,'mobile scrollWidth',ov);
  await m.context().close();
 }
 // reduced motion + dark
 const r=await page('fa',1440,900,{rm:true}); await at(r,'#anatomy',-56); await r.screenshot({path:`s-${tag}-fa-d-rm-ea.png`}); await r.context().close();
 const dk=await page('en',1440,900,{dark:true}); await dk.screenshot({path:`s-${tag}-en-d-dark.png`}); await at(dk,'#explorer',-56); await dk.click('.row[data-slug="30208"]'); await dk.waitForTimeout(400); await at(dk,'#explorer',260); await dk.screenshot({path:`s-${tag}-en-d-dark-exp.png`}); await dk.context().close();
 console.log('errors:',errs.length?errs.join('\n'):'none');
 await b.close();
})();
