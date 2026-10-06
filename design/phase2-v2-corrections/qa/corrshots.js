// Before (current V2, port 8092) / after (corrected V2, port 8090) screenshots for the Gate 2 correction report.
const { chromium } = require('playwright');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch(); const errs = [];
  async function page(port, file, w, h, o = {}) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: o.dpr || 1, isMobile: w < 900, hasTouch: w < 900, colorScheme: o.dark ? 'dark' : 'light', reducedMotion: o.rm ? 'reduce' : 'no-preference' });
    const p = await ctx.newPage(); p.on('pageerror', e => errs.push(port + ' ' + e.message));
    if (o.route) await o.route(p);
    await p.goto(`http://localhost:${port}/${file}`, { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready); await sleep(200);
    return p;
  }
  const S = (p, n) => p.screenshot({ path: `c-${n}.png` });
  for (const [port, tag] of [[8092, 'before'], [8090, 'after']]) {
    // p1 · stale workspace after an unknown code
    let p = await page(port, 'fa.html', 1440, 900);
    await p.evaluate(() => document.querySelector('#explorer').scrollIntoView());
    await p.click('tr[data-slug="6205"]'); await sleep(800);
    await p.fill('#q', '6208'); await sleep(400);
    await p.evaluate(() => scrollTo(0, document.querySelector('#explorer').getBoundingClientRect().top + scrollY + 240)); await sleep(300);
    await S(p, `p1-${tag}`); await p.context().close();
    // p2 · exact code hidden by an old filter
    p = await page(port, 'fa.html', 1440, 900);
    await p.click('[data-facet="f"][data-v="tapered"]');
    await p.fill('#q', '22220 E'); await sleep(400);
    await p.evaluate(() => scrollTo(0, document.querySelector('#explorer').getBoundingClientRect().top + scrollY + 240)); await sleep(300);
    await S(p, `p2-${tag}`); await p.context().close();
    // p3 · requested variant 6205-2RS
    p = await page(port, 'fa.html', 1440, 900);
    await p.fill('#q-hero', '6205-2RS'); await p.press('#q-hero', 'Enter'); await sleep(1400);
    await S(p, `p3-${tag}`); await p.context().close();
    // p4 · mobile sheet CTAs at 320
    p = await page(port, 'fa.html', 320, 700, { dpr: 2 });
    await p.fill('#q-hero', '6205'); await p.press('#q-hero', 'Enter'); await sleep(1400);
    await S(p, `p4-${tag}`); await p.context().close();
    // p5 · English header at a true 320 px viewport
    p = await page(port, 'en.html', 320, 240, { dpr: 2 }); await S(p, `p5-${tag}`); await p.context().close();
    // p6 · mobile Exploded Assembly labels at 320
    p = await page(port, 'fa.html', 320, 700, { dpr: 2 });
    const t = await p.evaluate(() => { const t = document.querySelector('.ea__track'); return { top: t.getBoundingClientRect().top + scrollY, h: t.offsetHeight }; });
    await p.evaluate(y => scrollTo(0, y), t.top + (t.h - 700) * 0.55); await sleep(700);
    await S(p, `p6-${tag}`); await p.context().close();
    // p7 · product data failure
    p = await page(port, 'en.html', 1440, 900, { route: async pg => { await pg.route('**/data/p/6206.json', r => r.fulfill({ status: 500, body: 'x' })); } });
    await p.evaluate(() => document.querySelector('#explorer').scrollIntoView());
    await p.click('tr[data-slug="6206"]'); await sleep(900);
    await p.evaluate(() => scrollTo(0, document.querySelector('.explorer__body').getBoundingClientRect().top + scrollY - 80)); await sleep(300);
    await S(p, `p7-${tag}`); await p.context().close();
    // admin A4 / A7
    p = await page(port, 'admin.html', 1440, 900);
    for (const id of ['media', 'publish']) { const el = await p.$(`#${id} .frame-scroll`); await el.screenshot({ path: `c-adm-${id}-${tag}.png` }); }
    if (tag === 'after') { for (const id of ['editor', 'inquiries', 'dashboard']) { const el = await p.$(`#${id} .frame-scroll`); await el.screenshot({ path: `c-adm-${id}-after.png` }); } for (const id of ['variants', 'migration']) { const el = await p.$('#' + id); await el.screenshot({ path: `c-adm-${id}.png` }); } }
    await p.context().close();
  }
  // after-only states
  let p = await page(8090, 'fa.html#p-6206', 1440, 900); await sleep(900); await p.click('#wst-1'); await sleep(250); await S(p, 'a-prov-fa'); await p.context().close();
  p = await page(8090, 'en.html', 390, 844, { dpr: 2 }); await p.fill('#q-hero', '6206'); await p.press('#q-hero', 'Enter'); await sleep(1400); await p.click('#wst-1'); await sleep(250);
  await p.evaluate(() => document.querySelector('.ws__body').scrollTop = 0); await S(p, 'a-prov-en-m'); await p.context().close();
  p = await page(8090, 'fa.html', 390, 844, { dpr: 2 }); await p.fill('#q-hero', '6205-2RS'); await p.press('#q-hero', 'Enter'); await sleep(1400); await S(p, 'a-sheet-variant-fa'); await p.click('#ws [data-act="inq"]'); await sleep(1200); await S(p, 'a-inquiry-fa'); await p.context().close();
  p = await page(8090, 'fa.html', 1440, 900); await p.fill('#q', '6205-2RSH'); await sleep(400); await p.evaluate(() => scrollTo(0, document.querySelector('#explorer').getBoundingClientRect().top + scrollY + 290)); await sleep(300); await S(p, 'a-series-fa'); await p.context().close();
  p = await page(8090, 'en.html', 1440, 900, { route: async pg => { await pg.route('**/data/p/6205.json', async r => { await sleep(4000); r.continue().catch(() => {}); }); } });
  await p.evaluate(() => document.querySelector('#explorer').scrollIntoView()); await p.click('tr[data-slug="6205"]'); await sleep(500);
  await p.evaluate(() => scrollTo(0, document.querySelector('.explorer__body').getBoundingClientRect().top + scrollY - 80)); await sleep(200); await S(p, 'a-loading-en'); await p.context().close();
  p = await page(8090, 'fa.html#p-6206', 1440, 900, { dark: true }); await sleep(900); await S(p, 'a-dark-fa'); await p.context().close();
  p = await page(8090, 'en.html', 390, 844, { dpr: 2, dark: true }); await p.fill('#q-hero', '6205-ZZ'); await p.press('#q-hero', 'Enter'); await sleep(1400); await S(p, 'a-dark-en-m'); await p.context().close();
  p = await page(8090, 'fa.html', 390, 844, { dpr: 2, rm: true }); await p.fill('#q-hero', '30208'); await p.press('#q-hero', 'Enter'); await sleep(600); await S(p, 'a-rm-sheet-fa'); await p.keyboard.press('Escape'); await sleep(200);
  await p.evaluate(() => scrollTo(0, document.querySelector('.ea__track').getBoundingClientRect().top + scrollY - 54)); await sleep(300); await S(p, 'a-rm-ea-fa'); await p.context().close();
  console.log('errors', errs.length ? errs : 'none'); await b.close();
})();
