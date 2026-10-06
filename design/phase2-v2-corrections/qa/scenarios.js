// Focused Gate 2 correction QA: identity, filters, variants, CTAs, failure recovery, focus, layout.
// Usage: NODE_PATH=$(npm root -g) node scenarios.js [port]   → prints PASS/FAIL lines and writes scenarios.json
const { chromium } = require('playwright');
const PORT = process.argv[2] || 8090, B = `http://localhost:${PORT}/`;
// ONLY=designation runs just the designation/variant checks (group 3) and writes scenarios-designation.json, leaving
// scenarios.json (the full run) untouched. PW_CHANNEL=chrome or msedge drives an installed browser instead of the bundled one.
const ONLY = process.env.ONLY || '';
const results = [];
const check = (id, lang, name, ok, detail = '') => { results.push({ id, lang, name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${id} [${lang}] ${name}${detail ? ' — ' + detail : ''}`); };
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const b = await chromium.launch(process.env.PW_CHANNEL ? { channel: process.env.PW_CHANNEL } : {});
  async function page(lang, w = 1440, h = 900, o = {}) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, isMobile: w < 900, hasTouch: w < 900, colorScheme: o.dark ? 'dark' : 'light', reducedMotion: o.rm ? 'reduce' : 'no-preference' });
    await ctx.addInitScript(() => {
      window.__copied = null;
      Object.defineProperty(navigator, 'clipboard', { value: { writeText: async t => { window.__copied = t; } }, configurable: true });
    });
    const p = await ctx.newPage();
    p.errs = [];
    p.on('pageerror', e => p.errs.push(e.message));
    if (o.route) await o.route(p);
    await p.goto(B + lang + '.html' + (o.hash || ''), { waitUntil: 'networkidle' });
    return p;
  }
  const st = p => p.evaluate(() => {
    const ws = document.querySelector('#ws'), cur = [...document.querySelectorAll('#results tr[aria-current]')].map(t => t.dataset.slug);
    return {
      state: ws.dataset.state, open: ws.classList.contains('is-open'), role: ws.getAttribute('role'), hash: location.hash,
      code: document.querySelector('#ws-code').textContent, panelHidden: document.querySelector('#ws-panel').hidden,
      wa: decodeURIComponent(document.querySelector('#ws-wa').href).replace(/[⁦-⁩]/g, ''), cur,
      match: document.querySelector('#ws-match').hidden ? '' : document.querySelector('#ws-match').textContent.replace(/[⁦-⁩]/g, ''),
      readout: [...document.querySelectorAll('#ws-main .readout b')].map(x => x.firstChild?.textContent),
      ask: document.querySelector('#ask').hidden ? '' : document.querySelector('#ask-t').textContent.replace(/[⁦-⁩]/g, ''),
      askP: document.querySelector('#ask').hidden ? '' : document.querySelector('#ask-p').textContent.replace(/[⁦-⁩]/g, ''),
      reveal: !document.querySelector('#ask-reveal').hidden, focus: document.activeElement?.id || document.activeElement?.className || document.activeElement?.tagName,
    };
  });
  const T = async p => p.evaluate(() => JSON.parse(document.querySelector('#i18n').textContent));

  // ---------- 3 · requested designation identity. A case: typed, requested designation (kept exact), match type and, when the
  // match is not exact, the listed designation it resolved to. 3t (T-1): every text gives each designation its true role; a listed
  // suffixed designation (6305-2RS, 22220 E) is never called the base series of the plain one that was asked for.
  const fillT = (tpl, o) => (tpl ?? '[missing string]').replace(/\{(\w+)\}/g, (m, k) => (k in o ? o[k] : m));   // a missing string fails the check
  async function designation(lang) {
    let p, s;
    const cases = [['6205', '6205', 'exact'], ['6205-2RS', '6205-2RS', 'variant', '6205'], ['6205-ZZ', '6205-ZZ', 'variant', '6205'],
      ['6205 2rs', '6205-2RS', 'variant', '6205'], ['6205-2RSH', '6205-2RSH', 'series', '6205'],
      ['6305', '6305', 'related', '6305-2RS'], ['6305-2RS', '6305-2RS', 'exact'], ['22220', '22220', 'related', '22220 E'],
      ['22220 E', '22220 E', 'exact'], ['22220e', '22220 E', 'exact']];
    for (const [typed, want, type, listed] of cases) {
      p = await page(lang); const t = await T(p);
      await p.fill('#q-hero', typed); await p.press('#q-hero', 'Enter'); await sleep(900);
      s = await st(p);
      await p.click('#ws [data-act="copy"]'); await sleep(100);
      const copied = await p.evaluate(() => window.__copied);
      await p.click('#ws [data-act="link"]'); await sleep(100);
      const link = decodeURIComponent(await p.evaluate(() => window.__copied));
      await p.click('#ws [data-act="inq"]'); await sleep(700);
      const f = await p.evaluate(() => ({ code: document.querySelector('#f-code').value, match: document.querySelector('#f-match').value, product: document.querySelector('#f-product').value, ctx: document.querySelector('#f-ctx').textContent.replace(/[⁦-⁩]/g, '') }));
      const typeOk = type === 'exact' ? s.match === '' : s.match.includes(want);
      check('3', lang, `“${typed}” → requested ${want} (${type}) kept through workspace, copy, link, WhatsApp, inquiry`,
        s.code === want && typeOk && copied === want && link.includes(type === 'exact' ? '#p-' : ':' + want) && s.wa.includes(want) && f.code === want && f.match === type && f.ctx.includes(want),
        JSON.stringify({ ws: s.code, copied, link: link.split('#')[1], form: f.code, match: f.match, product: f.product }));
      const texts = type === 'exact' ? { ask: '', askP: '', match: '', wa: fillT(t.wa_code, { code: want }), ctx: fillT(t.f_ctx, { req: want }) }
        : type === 'related' ? { ask: t.related_t, askP: fillT(t.related_p, { q: want, listed }), match: fillT(t.ws_match_rel, { req: want, listed }),
          wa: fillT(t.wa_related, { req: want, listed }), ctx: fillT(t.f_ctx, { req: want }) + fillT(t.f_ctx_rel, { listed }) }
        : { ask: t.series_t, askP: fillT(type === 'variant' ? t.variant_p : t.series_p, { q: want, base: listed }), match: fillT(t.ws_match, { req: want, base: listed }),
          wa: fillT(t.wa_variant, { req: want, base: listed }), ctx: fillT(t.f_ctx, { req: want }) + fillT(t.f_ctx_base, { base: listed }) };
      const got = { ask: s.ask, askP: s.askP, match: s.match, wa: s.wa.split('?text=')[1] || '', ctx: f.ctx };
      const wrong = Object.keys(texts).filter(k => got[k] !== texts[k]);
      const reversed = type === 'related' && Object.values(got).some(x => x.includes(lang === 'fa' ? 'سری پایه' : 'base series'));
      check('3t', lang, `“${typed}” → ${type}${listed ? `, listed ${listed}` : ''}: explorer, workspace, WhatsApp and inquiry name the right base / variant roles`,
        !wrong.length && !reversed && !p.errs.length, wrong.length || reversed || p.errs.length ? JSON.stringify({ wrong: wrong.map(k => [k, got[k], texts[k]]), reversed, errs: p.errs }) : '');
      await p.close();
    }
    // a row click while the query resolved to that row keeps the asked designation
    p = await page(lang);
    await p.fill('#q', '6206-zz'); await sleep(300);
    await p.click('tr[data-slug="6206"]'); await sleep(700);
    s = await st(p);
    check('3b', lang, 'Row click after typing 6206-ZZ opens 6206 with requested 6206-ZZ', s.code === '6206-ZZ' && s.hash.includes('6206-ZZ'));
    await p.close();
    // deep link with requested variant
    p = await page(lang, 1440, 900, { hash: '#p-6205:6205-ZZ' }); await sleep(800); s = await st(p);
    check('3c', lang, 'Deep link #p-6205:6205-ZZ restores the requested variant', s.code === '6205-ZZ' && s.match.includes('6205-ZZ'));
    await p.close();
    // deep link with a related request (T-1): 6305 asked, 6305-2RS listed
    p = await page(lang, 1440, 900, { hash: '#p-6305-2rs:6305' }); await sleep(800); s = await st(p);
    let t = await T(p);
    check('3d', lang, 'Deep link #p-6305-2rs:6305 restores the related request (6305 asked, 6305-2RS listed)', s.code === '6305' && s.match === fillT(t.ws_match_rel, { req: '6305', listed: '6305-2RS' }), s.match);
    await p.close();
    // a half-typed code is not presented as related to anything (T-1)
    p = await page(lang); t = await T(p);
    await p.fill('#q', '6305-2'); await sleep(300); s = await st(p);
    const partialRow = await p.evaluate(() => !document.querySelector('tr[data-slug="6305-2rs"]').hidden);
    check('3e', lang, 'Half-typed 6305-2: no series or related claim; 6305-2RS stays listed as a partial match', s.ask !== t.related_t && s.ask !== t.series_t && partialRow, s.ask);
    await p.close();
  }

  for (const lang of ['fa', 'en']) {
    if (ONLY === 'designation') { await designation(lang); continue; }
    // ---------- 2 · exact search vs filters
    let p = await page(lang); let t = await T(p);
    await p.click('[data-facet="f"][data-v="tapered"]');
    await p.fill('#q-hero', '22220 E'); await p.press('#q-hero', 'Enter'); await sleep(900);
    let s = await st(p);
    const filtersCleared = await p.evaluate(() => document.querySelector('[data-facet="f"][data-v=""]').getAttribute('aria-pressed') === 'true');
    check('2a', lang, 'Hero exact search ignores a conflicting family filter', s.code === '22220 E' && s.state === 'ready' && filtersCleared && s.cur.join() === '22220-e', JSON.stringify({ code: s.code, filtersCleared }));
    const liveTxt = await p.evaluate(() => document.querySelector('#exp-live').textContent);
    check('2a2', lang, 'Filter reset is announced', liveTxt.length > 0, liveTxt.replace(/[⁦-⁩]/g, ''));
    await p.close();

    p = await page(lang);
    await p.click('[data-facet="r"][data-v="0-20"]');
    await p.fill('#q', '22220 E'); await sleep(300);
    s = await st(p);
    check('2b', lang, 'Explorer: exact code hidden by bore filter → "hidden by filters", not "not in catalogue"', s.reveal && s.ask === t.ask_hidden.replace('{q}', '22220 E'), s.ask);
    await p.click('#ask-reveal'); await sleep(200);
    const vis = await p.evaluate(() => !document.querySelector('tr[data-slug="22220-e"]').hidden && document.querySelector('[data-facet="r"][data-v=""]').getAttribute('aria-pressed') === 'true');
    check('2b2', lang, 'Reveal action clears filters and shows the match', vis);
    await p.close();

    p = await page(lang);
    await p.click('[data-facet="v"]');
    await p.fill('#q', 'UCP 208'); await sleep(300);
    s = await st(p);
    check('2c', lang, 'Verified-only hides UCP 208 (pending) → explained as hidden, with reveal', s.reveal && s.ask.includes('UCP 208') && !s.ask.includes(t.ask_zero.split('{q}')[1].trim()), s.ask);
    await p.fill('#q-hero', 'UCP 208'); await p.press('#q-hero', 'Enter'); await sleep(900);
    s = await st(p);
    check('2c2', lang, 'Hero search for UCP 208 clears verified-only and opens it', s.code === 'UCP 208' && s.state === 'ready');
    await p.close();

    p = await page(lang);
    await p.click('[data-facet="f"][data-v="ball"]');
    await p.fill('#q', '6208'); await sleep(300); s = await st(p);
    check('2d', lang, 'Unknown code with a filter on → "not in catalogue" from the whole catalogue', s.ask === t.ask_zero.replace('{q}', '6208') && !s.reveal, s.ask);
    await p.click('[data-facet="f"][data-v="tapered"]');
    await p.fill('#q', '620'); await sleep(300); s = await st(p);
    check('2e', lang, 'Partial matches all hidden by filters → count + reveal', s.reveal && /\d|[۰-۹]/.test(s.ask), s.ask);
    await p.close();

    // ---------- 1 · identity: unknown code after another product was open
    p = await page(lang);
    await p.click('tr[data-slug="6205"]'); await sleep(700);
    await p.fill('#q', '6208'); await sleep(400);
    s = await st(p);
    check('1a', lang, '6205 open, then search 6208 → workspace, URL and highlight cleared', s.state === 'empty' && s.panelHidden && s.hash === '' && s.cur.length === 0 && s.ask.includes('6208'), JSON.stringify({ state: s.state, hash: s.hash, cur: s.cur }));
    check('1a2', lang, 'Focus stays in the search field while typing', s.focus === 'q');
    await p.close();

    // ---------- 1 · out-of-order responses
    p = await page(lang, 1440, 900, { route: async pg => { await pg.route('**/data/p/6205.json', async r => { await sleep(1500); r.continue().catch(() => {}); }); } });
    await p.click('tr[data-slug="6205"]'); await sleep(120);
    await p.click('tr[data-slug="22220-e"]'); await sleep(2300);
    s = await st(p);
    check('1b', lang, 'Late 6205 response cannot overwrite the newer 22220 E selection', s.code === '22220 E' && s.hash === '#p-22220-e' && s.cur.join() === '22220-e' && s.readout[0] === '100' && s.wa.includes('22220 E'), JSON.stringify({ code: s.code, hash: s.hash, cur: s.cur, d: s.readout[0] }));
    await p.close();

    p = await page(lang, 1440, 900, { route: async pg => { await pg.route('**/data/p/6205.json', async r => { await sleep(1500); r.continue().catch(() => {}); }); } });
    await p.click('tr[data-slug="6205"]'); await sleep(150);
    s = await st(p);
    const loadingCta = s.state === 'loading' && s.wa.includes('6205') && s.code === '6205';
    await p.fill('#q', '6208'); await sleep(1800);
    s = await st(p);
    check('1c', lang, 'Loading state shows only the intended product; a query change during loading invalidates it for good', loadingCta && s.state === 'empty' && s.panelHidden && s.hash === '', JSON.stringify({ loadingCta, state: s.state }));
    await p.close();

    await designation(lang);

    // ---------- 6 · cached reopen
    let n6205 = 0;
    p = await page(lang, 1440, 900, { route: async pg => { pg.on('request', r => { if (r.url().endsWith('/6205.json')) n6205++; }); } });
    await p.click('tr[data-slug="6205"]'); await sleep(600);
    await p.click('#ws .ws__acts [data-act="close"]'); await sleep(200);
    const afterClose = await st(p);
    await p.click('tr[data-slug="6205"]'); await sleep(400);
    s = await st(p);
    check('5a', lang, 'Cached reopen: one request, correct content, focus returned on close', n6205 === 1 && s.code === '6205' && s.state === 'ready' && afterClose.focus === 'cat__code', JSON.stringify({ requests: n6205, focusAfterClose: afterClose.focus }));
    await p.close();

    // ---------- 5 · failed JSON + Retry
    let fail = true;
    p = await page(lang, 1440, 900, { route: async pg => { await pg.route('**/data/p/6206.json', r => fail ? (fail = false, r.fulfill({ status: 500, body: 'x' })) : r.continue()); } });
    await p.click('tr[data-slug="6206"]'); await sleep(700);
    s = await st(p);
    const errUi = await p.evaluate(() => ({ retry: !!document.querySelector('#ws [data-act="retry"]'), close: !!document.querySelector('#ws-panel [data-act="close"]:not(.ws__handle)'), alert: !!document.querySelector('#ws [role="alert"]') }));
    check('5b', lang, 'Failed product JSON → error with Retry, Close and the right product CTAs', s.state === 'err-data' && errUi.retry && errUi.close && errUi.alert && s.wa.includes('6206'), JSON.stringify(errUi));
    await p.click('#ws [data-act="retry"]'); await sleep(700);
    s = await st(p);
    check('5b2', lang, 'Retry loads 6206', s.state === 'ready' && s.readout[0] === '30');
    await p.close();

    // ---------- 5 · failed dynamic import + Retry
    let modFail = true;
    p = await page(lang, 1440, 900, { route: async pg => { await pg.route(/\/assets\/ws\.js(\?.*)?$/, r => modFail ? (modFail = false, r.abort()) : r.continue()); } });
    await p.click('tr[data-slug="6205"]'); await sleep(700);
    s = await st(p);
    check('5c', lang, 'Failed module import → error state with Retry (not a dead panel)', s.state === 'err-mod' && s.code === '6205');
    await p.click('#ws [data-act="retry"]'); await sleep(900);
    s = await st(p);
    check('5c2', lang, 'Retry re-imports the module and renders 6205', s.state === 'ready' && s.readout[0] === '25');
    await p.close();

    // ---------- 5 · keyboard + focus (desktop)
    p = await page(lang);
    await p.focus('tr[data-slug="30208"] .cat__code'); await p.keyboard.press('Enter'); await sleep(600);
    s = await st(p);
    check('5d', lang, 'Desktop: Enter on a row opens the panel and moves focus to its title', s.focus === 'ws-title' && s.code === '30208');
    await p.keyboard.press('Tab'); const tab1 = await p.evaluate(() => document.activeElement.dataset.act || document.activeElement.id);
    await p.keyboard.press('Escape'); await sleep(200);
    s = await st(p);
    const back = await p.evaluate(() => document.activeElement.closest('tr')?.dataset.slug);
    check('5e', lang, 'Desktop: Escape inside the panel closes it and returns focus to the row', s.state === 'empty' && back === '30208', JSON.stringify({ firstTab: tab1, back }));
    await p.click('tr[data-slug="30208"]'); await sleep(500);
    await p.focus('#q'); await p.keyboard.press('Escape'); await sleep(150);
    s = await st(p);
    check('5f', lang, 'Desktop: Escape outside the panel leaves it open (documented contract)', s.state === 'ready');
    await p.close();

    // ---------- 4/5 · mobile sheet: CTAs, focus containment, Escape, inquiry hand-off
    for (const w of [320, 390]) {
      p = await page(lang, w, 740);
      await p.fill('#q-hero', '6205-2RS'); await p.press('#q-hero', 'Enter'); await sleep(1200);
      s = await st(p);
      const ctas = await p.evaluate(() => [...document.querySelectorAll('.ws__foot .btn')].map(a => { const r = a.getBoundingClientRect(), W = document.documentElement.clientWidth, H = innerHeight; return { t: a.textContent.trim(), ok: r.left >= 0 && r.right <= W && r.bottom <= H && r.width >= 44 && r.height >= 44 && a.scrollWidth <= a.clientWidth + 1 }; }));
      check('4a', lang, `${w}px: sheet is a dialog with three unclipped product CTAs`, s.open && s.role === 'dialog' && ctas.length === 3 && ctas.every(c => c.ok), JSON.stringify(ctas.map(c => c.t + (c.ok ? '' : ' ✗'))));
      check('5g', lang, `${w}px: initial focus on the sheet title after it is visible`, s.focus === 'ws-title');
      const inside = [];
      for (let i = 0; i < 14; i++) { await p.keyboard.press('Tab'); inside.push(await p.evaluate(() => document.querySelector('#ws').contains(document.activeElement))); }
      for (let i = 0; i < 6; i++) { await p.keyboard.press('Shift+Tab'); inside.push(await p.evaluate(() => document.querySelector('#ws').contains(document.activeElement))); }
      check('5h', lang, `${w}px: Tab and Shift+Tab stay inside the sheet`, inside.every(Boolean), `${inside.filter(Boolean).length}/${inside.length}`);
      await p.keyboard.press('Escape'); await sleep(450);
      s = await st(p);
      check('5i', lang, `${w}px: Escape closes the sheet and focus returns to the search field that opened it`, !s.open && s.state === 'empty' && s.focus === 'q', s.focus);
      // inquiry hand-off
      await p.fill('#q-hero', '6205-2RS'); await p.press('#q-hero', 'Enter'); await sleep(1200);
      await p.click('#ws [data-act="inq"]'); await sleep(1100);
      const f = await p.evaluate(() => { const r = document.querySelector('#inquiry').getBoundingClientRect(); return { code: document.querySelector('#f-code').value, ctx: document.querySelector('#f-ctx').textContent.replace(/[⁦-⁩]/g, ''), focus: document.activeElement.id, top: Math.round(r.top), open: document.querySelector('#ws').classList.contains('is-open'), inert: document.querySelector('main').inert }; });
      check('4b', lang, `${w}px: Inquiry closes the sheet, moves to the form, focuses the first empty field, keeps 6205-2RS`, !f.open && !f.inert && f.code === '6205-2RS' && f.ctx.includes('6205-2RS') && f.focus === 'f-name' && f.top >= 0 && f.top < 200, JSON.stringify(f));
      await p.close();
    }

    // ---------- 5 · mobile cached reopen, five times in a row (focus must land on the title every time)
    p = await page(lang, 390, 800);
    const foci = [];
    for (let i = 0; i < 5; i++) {
      await p.evaluate(() => document.querySelector('tr[data-slug="6205"]').scrollIntoView({ block: 'center' }));
      await p.click('tr[data-slug="6205"] .cat__code'); await sleep(700);
      foci.push((await st(p)).focus);
      await p.keyboard.press('Escape'); await sleep(450);
    }
    check('5j', lang, '390px: cached reopen ×5, focus on the sheet title every time', foci.every(f => f === 'ws-title'), foci.join(','));
    await p.close();

    // ---------- 5 · breakpoint change with a product open (desktop → phone → desktop)
    p = await page(lang);
    await p.click('tr[data-slug="6205"]'); await sleep(600);
    await p.setViewportSize({ width: 390, height: 800 }); await sleep(800);
    const toM = await st(p); const inM = await p.evaluate(() => document.querySelector('#ws').contains(document.activeElement));
    await p.setViewportSize({ width: 1440, height: 900 }); await sleep(800);
    const toD = await st(p); const inD = await p.evaluate(() => document.querySelector('#ws').contains(document.activeElement));
    await p.keyboard.press('Escape'); await sleep(300);
    const shut = await st(p);
    check('5k', lang, 'Breakpoint change keeps the product: panel → modal sheet (focus inside) → panel (focus kept), then Escape closes',
      toM.open && toM.role === 'dialog' && toM.code === '6205' && toM.state === 'ready' && inM &&
      !toD.open && toD.role === 'region' && !toD.panelHidden && toD.code === '6205' && inD && shut.state === 'empty',
      JSON.stringify({ phone: [toM.role, toM.focus], desktop: [toD.role, toD.focus], afterEsc: shut.state }));
    await p.close();
  }

  // ---------- 7/8 · layout at 320 and 390: the layout viewport is exactly the requested width (isMobile off, so a page
  // without a viewport meta cannot fall back to a 980 px layout) and nothing scrolls sideways (documentElement.clientWidth)
  if (!ONLY) for (const file of ['fa', 'en', 'system', 'admin', 'index']) for (const w of [320, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 740 } });
    const p = await ctx.newPage(); await p.goto(B + file + '.html', { waitUntil: 'networkidle' });
    const r = await p.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
    check('8', file, `${w}px: no horizontal page overflow`, r[1] === w && r[0] <= r[1], `${r[0]} / ${r[1]}`);
    await ctx.close();
  }

  // ---------- 6 · admin media / publish rule parity
  if (!ONLY) {
    const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); const p = await ctx.newPage();
    await p.goto(B + 'admin.html', { waitUntil: 'networkidle' });
    const r = await p.evaluate(() => {
      const get = id => [...document.querySelectorAll(`#${id} [data-rule]`)].map(e => e.dataset.rule + ':' + e.dataset.sev);
      return { media: get('media'), publish: get('publish'), dash: get('dashboard') };
    });
    const same = ['ALT-INF:block', 'ALT-UNUSED:info'].every(k => r.media.includes(k)) && r.publish.includes('ALT-INF:block') && !r.publish.some(x => x.startsWith('ALT-UNUSED'));
    check('6', 'admin', 'A4 Media and A7 Publish state the same alt-text rule and severity; unused media never blocks', same, JSON.stringify(r));
    await ctx.close();
  }

  const fails = results.filter(r => !r.ok);
  console.log(`\n${results.length - fails.length}/${results.length} passed`);
  require('fs').writeFileSync(ONLY ? `scenarios-${ONLY}.json` : 'scenarios.json', JSON.stringify(results, null, 1));
  await b.close();
})();
