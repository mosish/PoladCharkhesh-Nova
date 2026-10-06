const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();
for (const lang of ['fa','en']) {const ctx=await b.newContext({viewport:{width:390,height:844}});const p=await ctx.newPage();const f=[];
p.on('requestfinished',r=>{if(r.url().endsWith('.woff2'))f.push(r.url().split('/').pop())});
await p.goto(`http://localhost:8090/${lang}.html`,{waitUntil:'networkidle'});await p.evaluate(()=>document.fonts.ready);
const w=await p.evaluate(()=>{const m=(ff,wt,t)=>{const s=document.createElement('span');s.style.cssText=`font-family:${ff};font-weight:${wt};font-size:40px;position:absolute;white-space:nowrap`;s.textContent=t;document.body.append(s);const x=s.getBoundingClientRect().width;s.remove();return Math.round(x)};
 return {vazLatin400:m('Vazirmatn',400,'WWWiiiMM'),mono400:m('"JetBrains Mono"',400,'WWWiiiMM'),sys:m('Tahoma',400,'WWWiiiMM'),vazLatin700:m('Vazirmatn',700,'WWWiiiMM'),mono600:m('"JetBrains Mono"',600,'WWWiiiMM'),vazFa700:m('Vazirmatn',700,'پولاد چرخش'),sysFa:m('Tahoma',700,'پولاد چرخش')}});
console.log(lang,f.join(' '),JSON.stringify(w));await ctx.close();}
await b.close();})();
