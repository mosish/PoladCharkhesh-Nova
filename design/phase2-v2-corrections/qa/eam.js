const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();
for (const w of [320,390]) {const ctx=await b.newContext({viewport:{width:w,height:740},deviceScaleFactor:2,isMobile:true});const p=await ctx.newPage();
await p.goto('http://localhost:8090/fa.html',{waitUntil:'networkidle'});
const t=await p.evaluate(()=>{const t=document.querySelector('.ea__track');return {top:t.getBoundingClientRect().top+scrollY,h:t.offsetHeight}});
await p.evaluate(y=>scrollTo(0,y),t.top+(t.h-740)*0.55); await p.waitForTimeout(600);
const sizes=await p.evaluate(()=>{const svg=document.querySelector('.ea__fig svg');const sc=svg.getBoundingClientRect().width/640;const f=s=>Math.round(parseFloat(getComputedStyle(document.querySelector(s)).fontSize)*sc*10)/10;return {dim:f('.ea__fig .dim-t'),balloon:f('.ea .balloon text')}});
console.log(w, 'rendered px', sizes); await p.screenshot({path:`k-ea-${w}.png`}); await ctx.close();}
await b.close();})();
