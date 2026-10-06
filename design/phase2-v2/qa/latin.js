const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();
for (const [w,h] of [[390,844],[1440,900]]) {
  const ctx=await b.newContext({viewport:{width:w,height:h}});const p=await ctx.newPage();const fonts=[];
  p.on('request',r=>{if(r.url().endsWith('.woff2'))fonts.push(r.url().split('/').pop())});
  await p.goto('http://localhost:8090/fa.html',{waitUntil:'networkidle'});await p.waitForTimeout(500);
  const offenders=await p.evaluate(()=>{const out=[];const tw=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let n;
    while(n=tw.nextNode()){const t=n.textContent;if(!/[A-Za-z]/.test(t))continue;const el=n.parentElement;if(!el)continue;const cs=getComputedStyle(el);
      if(cs.display==='none'||cs.visibility==='hidden')continue; if(el.closest('[hidden],script,style,template,noscript'))continue;
      const ff=cs.fontFamily; if(/^"?JetBrains/.test(ff))continue;
      const r=el.getBoundingClientRect(); out.push([el.tagName+'.'+el.className, t.trim().slice(0,40), ff.slice(0,30), Math.round(r.top+scrollY)]);}
    // also placeholders
    document.querySelectorAll('input[placeholder]').forEach(i=>{if(/[A-Za-z]/.test(i.placeholder))out.push(['INPUT#'+i.id+' placeholder',i.placeholder,getComputedStyle(i,'::placeholder').fontFamily.slice(0,30),0])});
    return out;});
  console.log(w,'fonts',fonts); console.log(offenders.slice(0,60).map(o=>o.join(' | ')).join('\n'));
  await ctx.close();}
await b.close();})();
