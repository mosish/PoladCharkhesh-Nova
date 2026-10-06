const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const errs = [];
  for (const [w, h, n] of [[1440, 900, 'd'], [390, 844, 'm'], [320, 700, 's']]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, isMobile: false });
    const p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
    p.on('requestfailed', r => errs.push('fail ' + r.url()));
    p.on('response', r => { if (r.status() >= 400) errs.push(r.status() + ' ' + r.url()); });
    await p.goto('http://localhost:8090/index.html', { waitUntil: 'networkidle' });
    const o = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    console.log(n, JSON.stringify(o));
    if (n === 'd') {
      for (const id of ['corrections', 'qa', 'gate']) { await p.evaluate(i => document.getElementById(i).scrollIntoView(), id); await p.waitForTimeout(300); await p.screenshot({ path: `/tmp/claude-0/hub-${id}.png` }); }
      // load all lazy images
      const imgs = await p.$$eval('#corrections img', a => a.map(i => i.getAttribute('src')));
      for (const s of imgs) { const r = await p.request.get('http://localhost:8090/' + s); if (r.status() !== 200) errs.push('img ' + s + ' ' + r.status()); }
      const el = await p.$('#corrections'); await el.screenshot({ path: '/tmp/claude-0/hub-corr-full.png' });
    }
    await ctx.close();
  }
  console.log('errors', errs.length ? errs : 'none'); await b.close();
})();
