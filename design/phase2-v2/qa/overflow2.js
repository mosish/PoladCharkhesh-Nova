const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();
for (const page of process.argv.slice(2)){const p=await b.newPage({viewport:{width:390,height:844}});await p.goto('http://localhost:8090/'+page+'.html',{waitUntil:'networkidle'});
const r=await p.evaluate(()=>{const W=document.documentElement.clientWidth;const out=[];
 const inScroller=el=>{for(let a=el.parentElement;a&&a!==document.body;a=a.parentElement){const o=getComputedStyle(a).overflowX;if(o==='auto'||o==='scroll'||o==='hidden')return true;}return false;};
 for(const el of document.querySelectorAll('body *')){const b=el.getBoundingClientRect();if(b.width&&(b.right>W+1||b.left<-1)){const st=getComputedStyle(el);if(st.position==='fixed'||inScroller(el))continue;out.push(el.tagName+'.'+(el.className.baseVal??el.className)+' L'+Math.round(b.left)+' R'+Math.round(b.right))}}
 return {sw:document.documentElement.scrollWidth, W, out:out.slice(0,10)}});
console.log(page, JSON.stringify(r));await p.close();}
await b.close();})();
