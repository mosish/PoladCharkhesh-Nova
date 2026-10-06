// axe-core scan across the states that matter for Gate 2 (WCAG 2.0/2.1 A+AA + best practices).
// Usage: NODE_PATH=$(npm root -g) node axe2.js [port]
const { chromium } = require('playwright'); const fs = require('fs');
const axe = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const PORT = process.argv[2] || 8090, B = `http://localhost:${PORT}/`;
(async () => {
  const b = await chromium.launch(), out = [];
  async function scan(name, file, o = {}) {
    const ctx = await b.newContext({ viewport: { width: o.w || 1280, height: o.h || 900 }, isMobile: (o.w || 1280) < 900, hasTouch: (o.w || 1280) < 900, colorScheme: o.dark ? 'dark' : 'light' });
    const p = await ctx.newPage();
    if (o.fail) await p.route(o.fail, r => r.fulfill({ status: 500, body: 'x' }));
    await p.goto(B + file, { waitUntil: 'networkidle' });
    if (o.act) await o.act(p);
    await p.addScriptTag({ content: axe });
    const v = await p.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'best-practice'] })).violations
      .map(v => ({ id: v.id, impact: v.impact, n: v.nodes.length, where: v.nodes.slice(0, 2).map(n => n.target.join(' ')) })));
    out.push({ name, violations: v });
    console.log(v.length ? 'FAIL' : 'PASS', name, v.length ? JSON.stringify(v) : '');
    await ctx.close();
  }
  const open = slug => async p => { await p.click(`tr[data-slug="${slug}"]`); await p.waitForTimeout(800); };
  const hero = q => async p => { await p.fill('#q-hero', q); await p.press('#q-hero', 'Enter'); await p.waitForTimeout(1300); };
  await scan('fa · desktop · workspace 6206 (mixed provenance)', 'fa.html', { act: open('6206') });
  await scan('fa · desktop · specifications tab', 'fa.html', { act: async p => { await open('6206')(p); await p.click('#wst-1'); } });
  await scan('en · desktop · variant request 6205-2RS', 'en.html', { act: hero('6205-2RS') });
  await scan('fa · desktop · dark · workspace', 'fa.html', { dark: true, act: open('30208') });
  await scan('fa · desktop · filters hide exact match', 'fa.html', { act: async p => { await p.click('[data-facet="r"][data-v="0-20"]'); await p.fill('#q', '22220 E'); await p.waitForTimeout(300); } });
  await scan('fa · desktop · product data failed (Retry)', 'fa.html', { fail: '**/data/p/6206.json', act: open('6206') });
  await scan('fa · 390 · mobile sheet open (dialog)', 'fa.html', { w: 390, h: 800, act: hero('6205-2RS') });
  await scan('en · 320 · mobile sheet open (dialog)', 'en.html', { w: 320, h: 700, act: hero('22220 E') });
  await scan('en · 390 · dark · mobile sheet', 'en.html', { w: 390, h: 800, dark: true, act: hero('UCP 208') });
  await scan('design system', 'system.html');
  await scan('admin wireframes', 'admin.html');
  await scan('review hub', 'index.html');
  fs.writeFileSync('axe2.json', JSON.stringify(out, null, 1));
  console.log(`${out.filter(x => !x.violations.length).length}/${out.length} states clean`);
  await b.close();
})();
