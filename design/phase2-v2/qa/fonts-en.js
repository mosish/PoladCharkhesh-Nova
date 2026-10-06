const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();
for (const port of [8091,8090]) for (const lang of ['en','fa']) {const ctx=await b.newContext({viewport:{width:390,height:844},isMobile:true});const p=await ctx.newPage();const f=[];
p.on('requestfinished',async r=>{if(r.url().endsWith('.woff2')){const s=await r.sizes();f.push(r.url().split('/').pop()+':'+s.responseBodySize)}});
await p.goto(`http://localhost:${port}/${lang}.html`,{waitUntil:'load'});await p.waitForTimeout(1500);console.log(port,lang,f.join(' '));await ctx.close();}
await b.close();})();
