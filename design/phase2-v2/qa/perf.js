const { chromium } = require('playwright');
const profiles = {
  'Slow 4G (1.6 Mbps, 150 ms RTT)': { down: 1.6e6/8, up: 750e3/8, lat: 150 },
  '3G-class (400 kbps, 400 ms RTT)': { down: 400e3/8, up: 400e3/8, lat: 400 },
};
(async () => {
  const b = await chromium.launch();
  const out = [];
  for (const lang of ['fa', 'en']) for (const [name, pr] of Object.entries(profiles)) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    const p = await ctx.newPage();
    const cdp = await ctx.newCDPSession(p);
    await cdp.send('Network.enable'); await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await cdp.send('Network.emulateNetworkConditions', { offline: false, downloadThroughput: pr.down, uploadThroughput: pr.up, latency: pr.lat });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    const reqs = new Map();
    cdp.on('Network.responseReceived', e => reqs.set(e.requestId, { url: e.response.url, type: e.type }));
    cdp.on('Network.loadingFinished', e => { const r = reqs.get(e.requestId); if (r) r.bytes = e.encodedDataLength; });
    await p.addInitScript(() => {
      window.__m = { lcp: 0, cls: 0, fcp: 0, ev: [] };
      new PerformanceObserver(l => { for (const e of l.getEntries()) window.__m.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
      new PerformanceObserver(l => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__m.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
      new PerformanceObserver(l => { for (const e of l.getEntries()) if (e.name === 'first-contentful-paint') window.__m.fcp = e.startTime; }).observe({ type: 'paint', buffered: true });
      new PerformanceObserver(l => { for (const e of l.getEntries()) window.__m.ev.push([e.name, Math.round(e.duration)]); }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
    });
    const t0 = Date.now();
    await p.goto('http://localhost:' + (process.env.PORT || 8090) + '/' + lang + '.html', { waitUntil: 'load', timeout: 90000 });
    const loadMs = Date.now() - t0;
    await p.waitForTimeout(1500);
    const first = [...reqs.values()];
    const sum = (arr) => arr.reduce((a, r) => a + (r.bytes || 0), 0);
    const by = t => sum(first.filter(r => r.url.match(t)));
    const m0 = await p.evaluate(() => ({ ...window.__m }));
    // interactions (INP proxy): type a code, open a product, switch tab
    await p.evaluate(() => document.querySelector('#explorer').scrollIntoView());
    await p.tap('#q'); await p.keyboard.type('6205', { delay: 120 });
    await p.waitForTimeout(400);
    await p.tap('tr[data-slug="6205"], .row[data-slug="6205"]'); await p.waitForTimeout(2500);
    await p.tap('#wst-1'); await p.waitForTimeout(600);
    const m1 = await p.evaluate(() => window.__m);
    const after = [...reqs.values()];
    out.push({ lang, profile: name, requests: first.length, total: sum(first), html: by(/\.html$/), css: by(/\.css$/), js: by(/\.js$/), fonts: by(/\.woff2$/),
      fcp: Math.round(m0.fcp), lcp: Math.round(m0.lcp), cls: +m0.cls.toFixed(3), load: loadMs,
      maxEvent: Math.max(0, ...m1.ev.map(e => e[1])), inp: Math.max(0, ...m1.ev.filter(e => /^(click|pointerdown|pointerup|keydown|keyup|keypress|input|touchstart|touchend|mousedown|mouseup)$/.test(e[0])).map(e => e[1])), events: m1.ev.filter(e=>e[1]>56), onDemandKB: +(after.slice(first.length).reduce((a,r)=>a+(r.bytes||0),0)/1024).toFixed(1), onDemand: after.slice(first.length).map(r => r.url.split('/').pop() + ':' + r.bytes) });
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
