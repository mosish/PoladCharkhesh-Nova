const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();for(const [l,w] of [['fa',1440],['en',390]]){const p=await b.newPage({viewport:{width:w,height:900}});await p.goto('http://localhost:8090/'+l+'.html',{waitUntil:'networkidle'});
const el=await p.$('#process .steps');await el.scrollIntoViewIfNeeded();await p.waitForTimeout(300);await p.evaluate(()=>window.scrollBy(0,250));await p.waitForTimeout(400);await el.screenshot({path:'st-'+l+'.png'});}await b.close();})();
