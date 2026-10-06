// Polad Charkhesh Nova — core interactions (Phase 2 V2, Gate 2 corrections). No dependencies.
// This file owns product IDENTITY: search matching, the selected row, the URL, the workspace shell and every CTA payload.
// ws.js (code-split, loaded on first use) only fetches and renders a product body into the shell.
const d = document, html = d.documentElement;
const $ = (s, c = d) => c.querySelector(s), $$ = (s, c = d) => [...c.querySelectorAll(s)];
html.classList.replace('no-js', 'js');
const T = JSON.parse($('#i18n').textContent);
const FA = T.lang === 'fa';
const num = n => FA ? String(n).replace(/\d/g, x => '۰۱۲۳۴۵۶۷۸۹'[x]) : String(n);
const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
const SDA = window.CSS && CSS.supports('animation-timeline: view()');
const MQ = matchMedia('(max-width: 899px)');
const wa = text => `https://wa.me/${T.wa}?text=${encodeURIComponent(text)}`;
const fill = (tpl, o) => tpl.replace(/\{(\w+)\}/g, (m, k) => (k in o ? o[k] : m));
// Codes inside sentences are isolated (LRI…PDI) so 6205-2RS never reorders inside Persian text
const iso = s => '\u2066' + s + '\u2069';
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Same normalisation as the Publish Engine's search keys: Persian/Arabic digits, Arabic letter forms, separators
export const norm = s => s.replace(/[۰-۹٠-٩]/g, c => String(c.charCodeAt(0) & 15))
  .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\u200e\u200f\u2066-\u2069]/g, '').toLowerCase().replace(/×/g, 'x').replace(/[\s\-_/.]+/g, '');
// The designation as the visitor asked for it: kept verbatim apart from digits, spacing and letter case
const asked = s => s.replace(/[۰-۹٠-٩]/g, c => String(c.charCodeAt(0) & 15)).replace(/[\u200e\u200f\u2066-\u2069]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();

/* 1 · Sheet indicator + nav state */
const sheetNow = $('#sheet-now'), navLinks = $$('.bar__nav a');
const spy = new IntersectionObserver(es => {
  for (const e of es) if (e.isIntersecting) {
    sheetNow.textContent = e.target.dataset.sheet;
    navLinks.forEach(a => a.hash === '#' + e.target.id ? a.setAttribute('aria-current', 'true') : a.removeAttribute('aria-current'));
  }
}, { rootMargin: '-40% 0px -55% 0px' });
$$('[data-sheet]').forEach(s => spy.observe(s));
$$('.menu nav a').forEach(a => a.addEventListener('click', () => { a.closest('details').open = false; }));

/* 2 · Hero bearing: rolling elements turn with scroll (CSS timeline, or this fallback) and lean toward the pointer */
const fig = $('[data-tilt]');
if (fig && !RM) {
  let raf = 0, px = 0;
  if (matchMedia('(pointer: fine)').matches) {
    $('.hero').addEventListener('pointermove', e => {
      const r = fig.getBoundingClientRect();
      px = Math.max(-1, Math.min(1, (e.clientX - (r.left + r.width / 2)) / r.width)) * 14;
      if (!raf) raf = requestAnimationFrame(() => { raf = 0; fig.style.setProperty('--pr', px.toFixed(2)); });
    });
    $('.hero').addEventListener('pointerleave', () => fig.style.setProperty('--pr', 0));
  }
  if (!SDA) {
    let on = true, r2 = 0;
    new IntersectionObserver(([e]) => { on = e.isIntersecting; }).observe(fig);
    addEventListener('scroll', () => { if (on && !r2) r2 = requestAnimationFrame(() => { r2 = 0; fig.style.setProperty('--sr', (Math.min(1, scrollY / innerHeight) * 36).toFixed(2)); }); }, { passive: true });
  }
}

/* 3 · Drawing Set fallback */
if (!SDA && !RM) {
  html.classList.add('js-io');
  const dio = new IntersectionObserver(es => es.forEach(e => {
    if (e.isIntersecting) { e.target.classList.add('is-drawn'); dio.unobserve(e.target); }
  }), { rootMargin: '0px 0px -12% 0px' });
  $$('.draw, .steps').forEach(el => dio.observe(el));
}

/* 4 · Search and matching. Rows are static HTML (crawlable); JS only classifies the query and hides rows.
   Three kinds of key per row: k = this designation (+ equivalent spellings), v = DIFFERENT designations listed as
   suffix variants of it, t = free text for partial matches. Matching always runs on the whole catalogue first. */
const qIn = $('#q'), count = $('#count'), live = $('#exp-live');
const ask = $('#ask'), askT = $('#ask-t'), askP = $('#ask-p'), askWa = $('#ask-wa'), askForm = $('#ask-form'), askReveal = $('#ask-reveal'), askOpen = $('#ask-open');
const R = $$('#results tbody tr').map(tr => ({
  tr, slug: tr.dataset.slug, code: tr.dataset.code, k: tr.dataset.k.split('|'),
  v: tr.dataset.v ? tr.dataset.v.split('|') : [], vn: tr.dataset.vn ? tr.dataset.vn.split('|') : [], t: tr.dataset.t,
  link: $('.cat__code', tr), bdi: $('.cat__code bdi', tr),
}));
const bySlug = Object.fromEntries(R.map(r => [r.slug, r]));
R.forEach(r => { r.bdi.dataset.raw = r.bdi.textContent; });
const state = { q: '', f: '', r: '', v: '' };
let match = null;

// series: an unlisted suffix on a listed designation (6205-2RSH, 6205 C3, 30208 J2); the listed one is its base series.
// related: the plain designation of a listed suffixed one (6305 for 6305-2RS, 22220 for 22220 E); the listed one is a variant
// of the request, never its base. Only the whole base part counts, so a half-typed 6305-2 matches nothing.
const SUFFIX = /^(?:[a-z]|2rs|2z)/;
const basePart = r => norm(r.code.split(/[\s\-/]/)[0]);
function classify(raw) {
  const qn = norm(raw);
  if (!qn) return null;
  let r = R.find(x => x.k.includes(qn));
  if (r) return { type: 'exact', row: r, req: r.code };
  for (const x of R) { const i = x.v.indexOf(qn); if (i >= 0) return { type: 'variant', row: x, req: x.vn[i] }; }
  const up = x => x.k.some(k => qn.startsWith(k) && SUFFIX.test(qn.slice(k.length)));
  const down = x => basePart(x) === qn && x.k.some(k => k.startsWith(qn) && SUFFIX.test(k.slice(qn.length)));
  const s = R.filter(x => up(x) || down(x));
  return s.length === 1 ? { type: up(s[0]) ? 'series' : 'related', row: s[0], req: asked(raw) } : null;
}
// WhatsApp text: the requested designation, plus what the catalogue lists when the match is not exact
const waText = (type, req, code) => type === 'exact' ? fill(T.wa_code, { code: iso(code) })
  : type === 'related' ? fill(T.wa_related, { req: iso(req), listed: iso(code) })
  : fill(T.wa_variant, { req: iso(req), base: iso(code) });

function mark(el, qn) {
  const raw = el.dataset.raw;
  el.textContent = raw;
  if (!qn) return;
  let n = '', map = [];
  for (let i = 0; i < raw.length; i++) { for (const ch of norm(raw[i])) { n += ch; map.push(i); } }
  const at = n.indexOf(qn);
  if (at < 0) return;
  const a = map[at], b = map[at + qn.length - 1] + 1, m = d.createElement('mark');
  m.textContent = raw.slice(a, b);
  el.replaceChildren(raw.slice(0, a), m, raw.slice(b));
}

function passesFilters(r) {
  const [lo, hi] = state.r ? state.r.split('-').map(Number) : [0, 0], bore = +r.tr.dataset.d;
  return (!state.f || r.tr.dataset.f === state.f) && (!state.r || (r.tr.dataset.d !== '' && bore > lo && bore <= hi)) && (!state.v || r.tr.dataset.s === 'verified');
}
const filtersOn = () => !!(state.f || state.r || state.v);

function apply() {
  const qn = norm(state.q);
  match = classify(state.q);
  let n = 0, g = 0;
  for (const r of R) {
    const hit = !qn || r.t.includes(qn) || match?.row === r;    // against the whole catalogue
    const ok = hit && passesFilters(r);                          // what the filters allow on screen
    g += hit; n += ok;
    r.tr.hidden = !ok;
    mark(r.bdi, ok ? qn : '');
  }
  count.textContent = T.count.replace('{n}', num(n)).replace('{total}', num(R.length));
  // The open workspace never outlives the query or filters that showed its product
  if (active && bySlug[active.slug].tr.hidden) closeWs({ focus: false });
  explain(n, g);
}

// One panel explains every non-trivial outcome, and never says "not in the catalogue" because of a filter
function explain(n, g) {
  const q = state.q.trim(), m = match;
  let mode = null;
  if (m) mode = m.type === 'exact' ? (m.row.tr.hidden ? 'hidden' : null) : 'series';
  else if (q) mode = !g ? 'zero' : !n ? 'hiddenN' : 'soft';
  else if (!n && filtersOn()) mode = 'none';
  ask.hidden = !mode;
  if (!mode) return;
  const req = m ? m.req : asked(q);
  const txt = {
    hidden: [fill(T.ask_hidden, { q: iso(m?.req) }), T.ask_hidden_p],
    hiddenN: [fill(T.ask_hidden_n, { q: iso(q), n: num(g) }), T.ask_hidden_p],
    none: [T.none_filters, ''],
    series: m?.type === 'related' ? [T.related_t, fill(T.related_p, { q: iso(req), listed: iso(m.row.code) })]
      : [T.series_t, fill(m?.type === 'variant' ? T.variant_p : T.series_p, { q: iso(req), base: iso(m?.row.code) })],
    zero: [fill(T.ask_zero, { q: iso(req) }), T.ask_zero_p],
    soft: [fill(T.ask_soft, { q: iso(req) }), T.ask_soft_p],
  }[mode];
  askT.textContent = txt[0]; askP.textContent = txt[1]; askP.hidden = !txt[1];
  ask.classList.toggle('ask--soft', mode === 'soft');
  askReveal.hidden = !(mode === 'hidden' || mode === 'hiddenN' || mode === 'none' || (mode === 'series' && m.row.tr.hidden));
  askOpen.hidden = mode !== 'series';
  if (mode === 'series') askOpen.textContent = fill(T.open_base, { base: iso(m.row.code) });
  const talk = mode === 'series' || mode === 'zero' || mode === 'soft';
  askWa.hidden = askForm.hidden = !talk;
  if (talk) {
    askWa.href = wa(mode === 'series' ? waText(m.type, req, m.row.code) : fill(T.wa_ask, { q: iso(req) }));
    askForm.dataset.req = req;
  }
}

function resetFilters() {
  state.f = state.r = state.v = '';
  $$('[data-facet]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.facet !== 'v' && b.dataset.v === '')));
}

let tm;
function setQ(v) { state.q = v; if (qIn.value !== v) qIn.value = v; apply(); }
// origin: 'hero' and 'chip' search the whole catalogue and clear conflicting filters; 'explorer' reveals only on request
function submit(v, origin) {
  clearTimeout(tm);
  setQ(v);
  let m = match;
  if (m && m.row.tr.hidden && origin !== 'explorer') {
    resetFilters(); apply(); m = match;
    live.textContent = fill(T.filters_reset, { q: iso(m.req) });
  }
  const show = m && !m.row.tr.hidden;
  $(show ? '.explorer__body' : '#explorer').scrollIntoView({ behavior: RM ? 'auto' : 'smooth', block: 'start' });
  // A search hands over to the explorer field: that is where focus returns when the workspace closes
  if (show) select(m.row, m.req, m.type, qIn);
}
$$('form[data-search]').forEach(f => f.addEventListener('submit', e => { e.preventDefault(); submit($('input', f).value, f.dataset.search); }));
qIn.addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(() => setQ(qIn.value), 90); });
$$('.chip[data-q]').forEach(b => b.addEventListener('click', () => { $('#q-hero').value = b.dataset.q; submit(b.dataset.q, 'chip'); }));

$$('[data-facet]').forEach(b => b.addEventListener('click', () => {
  const k = b.dataset.facet;
  if (k === 'v') { const on = b.getAttribute('aria-pressed') !== 'true'; b.setAttribute('aria-pressed', String(on)); state.v = on ? '1' : ''; }
  else { $$(`[data-facet="${k}"]`).forEach(x => x.setAttribute('aria-pressed', String(x === b))); state[k] = b.dataset.v; }
  apply();
}));
d.addEventListener('click', e => {
  const a = e.target.closest('[data-filter]');
  if (!a) return;
  const v = a.dataset.filter, b = $(`[data-facet="f"][data-v="${v}"]`);
  if (b && !b.disabled) b.click();
  else { $$('[data-facet="f"]').forEach(x => x.setAttribute('aria-pressed', String(x.dataset.v === v))); state.f = v; apply(); }
});
askReveal.addEventListener('click', () => {
  resetFilters(); apply();
  const r = match?.row || R.find(x => !x.tr.hidden);
  live.textContent = fill(T.filters_reset, { q: iso(match?.req || state.q.trim()) });
  r?.link.focus();
});
askOpen.addEventListener('click', () => {
  if (!match) return;
  if (match.row.tr.hidden) { resetFilters(); apply(); }
  select(match.row, match.req, match.type, askOpen);
});
askForm.addEventListener('click', e => {
  e.preventDefault();
  toInquiry({ req: askForm.dataset.req, slug: match?.row.slug, type: match?.type || 'none', code: match?.row.code }, false);
});

/* 5 · Product identity + workspace shell.
   One active selection {slug, code, req, type}. Every new selection takes a token and aborts the previous request;
   a response is applied only if its token is still current, so a late answer can never overwrite a newer choice. */
const ws = $('#ws'), wsPanel = $('#ws-panel'), wsEmpty = $('#ws-empty'), wsMain = $('#ws-main'), wsTitle = $('#ws-title'),
  wsCode = $('#ws-code'), wsFam = $('#ws-fam'), wsBadge = $('#ws-badge'), wsMatch = $('#ws-match'), wsLive = $('#ws-live'),
  wsWa = $('#ws-wa'), scrim = $('#scrim'), home = d.createComment('ws-home');
ws.before(home);
let active = null, token = 0, ctrl = null, wsMod = null, modTry = 0;
const cache = new Map();
const loadWs = () => (wsMod ||= import(modTry ? `./ws.js?retry=${modTry}` : './ws.js').catch(e => { wsMod = null; modTry++; throw e; }));
const hashFor = a => '#p-' + a.slug + (a.type === 'exact' ? '' : ':' + encodeURIComponent(a.req));

function select(r, req, type, invoker) {
  const t = ++token;
  ctrl?.abort(); ctrl = new AbortController();
  active = { slug: r.slug, code: r.code, req: req || r.code, type: type || 'exact', row: r, invoker: invoker || r.link, t };
  R.forEach(x => x.tr.removeAttribute('aria-current'));
  r.tr.setAttribute('aria-current', 'true');
  history.replaceState(null, '', hashFor(active));
  // Yield first: the tap paints its feedback (row highlight) at once, the panel/sheet work runs right after (low INP)
  afterPaint(() => {
    if (t !== token) return;
    identity(active);
    body('loading');
    if (MQ.matches) sheetOpen(); else { panelOn(); wsTitle.focus({ preventScroll: true }); }
    load(t);
  });
}
const afterPaint = fn => requestAnimationFrame(() => setTimeout(fn, 0));

// Header, match note and every CTA come from the intended identity, so they are right even while loading or after a failure
function identity(a) {
  wsCode.textContent = a.req;
  wsFam.textContent = $('.cat__fam', a.row.tr).textContent;
  wsBadge.replaceChildren($('.badge', a.row.tr).cloneNode(true));
  wsMatch.hidden = a.type === 'exact';
  wsMatch.textContent = a.type === 'exact' ? '' : a.type === 'related' ? fill(T.ws_match_rel, { req: iso(a.req), listed: iso(a.code) })
    : fill(T.ws_match, { req: iso(a.req), base: iso(a.code) });
  wsWa.href = wa(waText(a.type, a.req, a.code));
  ws.dataset.req = a.req;
}

function body(kind) {
  ws.dataset.state = kind;
  if (kind === 'loading') {
    ws.setAttribute('aria-busy', 'true');
    wsMain.innerHTML = `<div class="ws__status" role="status"><span class="spin" aria-hidden="true"></span><span>${esc(fill(T.ws_loading, { code: iso(active.req) }))}</span></div>`;
  } else {
    ws.removeAttribute('aria-busy');
    wsMain.innerHTML = `<div class="ws__status ws__status--err" role="alert"><b>${esc(kind === 'err-mod' ? T.err_mod : T.err_data)}</b><span>${esc(T.err_p)}</span>
      <button class="btn btn--line btn--sm" type="button" data-act="retry">${esc(T.retry)}</button></div>`;
  }
}

async function load(t) {
  const a = active, signal = ctrl.signal;
  let m;
  try { m = await loadWs(); } catch { if (t === token) body('err-mod'); return; }
  if (t !== token) return;
  try {
    const p = cache.get(a.slug) || await m.fetchProduct(a.slug, signal);
    if (t !== token) return;                       // superseded while waiting: drop it
    cache.set(a.slug, p);
    m.renderBody(wsMain, p, a, T, MQ.matches);
    ws.dataset.state = 'ready'; ws.removeAttribute('aria-busy');
  } catch (e) { if (t === token && e.name !== 'AbortError') body('err-data'); }
}

function retry() {
  if (!active) return;
  wsTitle.focus({ preventScroll: true });
  const t = active.t = ++token;
  ctrl?.abort(); ctrl = new AbortController();
  body('loading');
  load(t);
}

function panelOn() {
  wsEmpty.hidden = true; wsPanel.hidden = false;
  ws.setAttribute('aria-labelledby', 'ws-title');
}
function panelOff() {
  wsMain.innerHTML = ''; wsPanel.hidden = true; wsEmpty.hidden = false;
  ws.dataset.state = 'empty'; ws.removeAttribute('aria-busy'); ws.setAttribute('aria-labelledby', 'ws-empty-t');
}

// close: ends the selection everywhere (row, URL, panel). focus:false when the user is busy elsewhere (typing a new query)
function closeWs(o = {}) {
  if (!active) return;
  ++token; ctrl?.abort();
  const inv = active.invoker;
  active = null;
  R.forEach(x => x.tr.removeAttribute('aria-current'));
  history.replaceState(null, '', location.pathname + location.search);
  const done = () => {
    panelOff();
    if (o.focus !== false) {
      const back = inv?.isConnected && inv.offsetParent ? inv : qIn;
      back.focus({ preventScroll: !MQ.matches });
    }
    o.then?.();
  };
  ws.classList.contains('is-open') ? sheetClose(done) : done();
}

// Mobile: a modal bottom sheet. The rest of the page is inert; Tab and Shift+Tab wrap inside the sheet.
const inertEls = () => [$('.proto'), $('header.bar'), $('main'), $('footer.foot'), $('.dock')].filter(Boolean);
const afterMove = fn => {
  let done = false;
  const end = e => { if (e.target === ws && e.propertyName === 'transform') go(); };
  const go = () => { if (done) return; done = true; ws.removeEventListener('transitionend', end); fn(); };
  ws.addEventListener('transitionend', end); setTimeout(go, 340);
};
function sheetOpen() {
  panelOn();
  if (ws.classList.contains('is-open')) { wsTitle.focus({ preventScroll: true }); return; }
  d.body.append(ws);
  ws.setAttribute('role', 'dialog'); ws.setAttribute('aria-modal', 'true');
  inertEls().forEach(el => { el.inert = true; });
  html.classList.add('ws-open'); d.body.style.overflow = 'hidden';
  scrim.hidden = false;
  requestAnimationFrame(() => requestAnimationFrame(() => {
    ws.classList.add('is-open'); scrim.classList.add('is-on');
    const focusIn = () => { if (ws.classList.contains('is-open')) wsTitle.focus({ preventScroll: true }); };
    RM ? focusIn() : afterMove(focusIn);           // focus only once the sheet is actually on screen
  }));
}
function sheetClose(done) {
  ws.classList.remove('is-open'); scrim.classList.remove('is-on');
  const fin = () => {
    scrim.hidden = true; ws.setAttribute('role', 'region'); ws.removeAttribute('aria-modal');
    inertEls().forEach(el => { el.inert = false; });
    html.classList.remove('ws-open'); d.body.style.overflow = '';
    home.after(ws);
    done?.();
  };
  RM ? fin() : afterMove(fin);
}
const tabbables = () => $$('a[href], button, input, select, textarea, [tabindex]', ws).filter(el => el.tabIndex >= 0 && !el.disabled && !el.closest('[hidden]') && el.getClientRects().length);
ws.addEventListener('keydown', e => {
  if (e.key !== 'Tab' || !ws.classList.contains('is-open')) return;
  const f = tabbables();
  if (!f.length) return;
  const first = f[0], last = f[f.length - 1], at = d.activeElement;
  if (e.shiftKey && (at === first || at === wsTitle || !ws.contains(at))) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && (at === last || !ws.contains(at))) { e.preventDefault(); first.focus(); }
});
// Escape contract: mobile sheet — always closes; desktop panel — closes when focus is inside the panel
d.addEventListener('keydown', e => {
  if (e.key !== 'Escape' || !active) return;
  if (ws.classList.contains('is-open') || ws.contains(d.activeElement)) { e.preventDefault(); closeWs(); }
});
scrim.addEventListener('click', () => closeWs());
let y0 = null;
ws.addEventListener('pointerdown', e => { if (ws.classList.contains('is-open') && e.target.closest('.ws__handle')) y0 = e.clientY; });
ws.addEventListener('pointerup', e => { if (y0 !== null && e.clientY - y0 > 60) closeWs(); y0 = null; });
// Breakpoint change with a product open: panel ⇄ sheet. Moving the node drops focus, so put it back on the title.
MQ.addEventListener('change', () => {
  if (!active) return;
  const had = ws.contains(d.activeElement);
  if (MQ.matches) sheetOpen();
  else if (ws.classList.contains('is-open')) sheetClose(() => { panelOn(); if (had) wsTitle.focus({ preventScroll: true }); });
});

ws.addEventListener('click', e => {
  const b = e.target.closest('[data-act]');
  if (!b || !active) return;
  const act = b.dataset.act;
  if (act === 'close') { e.preventDefault(); closeWs(); }
  if (act === 'retry') retry();
  if (act === 'inq') { e.preventDefault(); toInquiry(active, true); }
  if (act === 'copy' || act === 'link') {
    const text = act === 'copy' ? active.req : new URL(hashFor(active), location.href).href;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => { wsLive.textContent = T.copied; }, () => { wsLive.textContent = text; });
  }
});

$('#results').addEventListener('click', e => {
  const tr = e.target.closest('tbody tr');
  if (!tr) return;
  e.preventDefault();
  const r = bySlug[tr.dataset.slug];
  // Clicking the row that the current query resolved to keeps the designation that was asked for (e.g. 6205-2RS)
  const keep = match && match.row === r;
  select(r, keep ? match.req : r.code, keep ? match.type : 'exact', r.link);
});
$('#results').addEventListener('pointerover', () => { loadWs().catch(() => {}); }, { once: true });

function fromHash() {
  const h = location.hash;
  if (!h.startsWith('#p-')) { if (active) closeWs({ focus: false }); return; }
  const i = h.indexOf(':'), r = bySlug[h.slice(3, i < 0 ? undefined : i)];
  if (!r) return;
  let req = r.code, type = 'exact';
  if (i > 0) { const m = classify(decodeURIComponent(h.slice(i + 1))); if (m && m.row === r) ({ req, type } = m); }
  if (active && active.slug === r.slug && active.req === req) return;
  if (r.tr.hidden) { resetFilters(); apply(); }
  $('.explorer__body').scrollIntoView();
  select(r, req, type);
}
addEventListener('hashchange', fromHash);
if (location.hash.startsWith('#p-')) fromHash();

/* 6 · Inquiry: the form receives the exact requested designation plus the identity the Admin will match on */
const form = $('#inquiry'), fCode = $('#f-code'), fProduct = $('#f-product'), fMatch = $('#f-match'), fCtx = $('#f-ctx');
function toInquiry(a, fromWs) {
  fCode.value = a.req || '';
  fProduct.value = a.slug || ''; fMatch.value = a.type || 'none';
  fCtx.textContent = fill(T.f_ctx, { req: iso(a.req) }) + (a.type === 'variant' || a.type === 'series' ? fill(T.f_ctx_base, { base: iso(a.code) })
    : a.type === 'related' ? fill(T.f_ctx_rel, { listed: iso(a.code) }) : '');
  fCtx.hidden = !a.req;
  // Lazily rendered sections settle their real height as they come into view, so the jump is re-aimed for a few frames
  const go = () => {
    const first = $$('[required]', form).find(x => !x.value.trim()) || $('#f-msg');
    let n = 0;
    const aim = () => { form.scrollIntoView({ block: 'start' }); if (++n < 5) requestAnimationFrame(aim); else first.focus({ preventScroll: true }); };
    aim();
  };
  if (fromWs && MQ.matches) closeWs({ focus: false, then: go }); else go();
}
// Editing the code by hand detaches the form from the product it was opened for
fCode.addEventListener('input', () => { fProduct.value = ''; fMatch.value = ''; fCtx.hidden = true; });

/* 7 · Exploded Assembly: one progress value --p drives all six stages; this is the fallback driver */
const track = $('.ea__track'), stage = $('.ea__stage');
if (track && !SDA && !RM) {
  let on = false, raf = 0;
  const tick = () => {
    raf = 0;
    const r = track.getBoundingClientRect(), span = r.height - innerHeight;
    stage.style.setProperty('--p', Math.min(1, Math.max(0, -r.top / span)).toFixed(4));
  };
  new IntersectionObserver(([en]) => { on = en.isIntersecting; if (on) tick(); }).observe(track);
  addEventListener('scroll', () => { if (on && !raf) raf = requestAnimationFrame(tick); }, { passive: true });
}

/* 8 · Inquiry form validation (prototype: validates, never sends) */
form.addEventListener('submit', e => {
  e.preventDefault();
  let first = null;
  $$('[required]', form).forEach(inp => {
    const f = inp.closest('.field'), bad = !inp.value.trim();
    let m = $('.err', f);
    f.classList.toggle('is-err', bad);
    inp.setAttribute('aria-invalid', String(bad));
    if (bad && !m) { m = d.createElement('span'); m.className = 'err'; m.id = inp.id + '-err'; m.textContent = T.req; f.append(m); inp.setAttribute('aria-describedby', m.id); }
    if (!bad && m) { m.remove(); inp.removeAttribute('aria-describedby'); }
    if (bad && !first) first = inp;
  });
  if (first) first.focus(); else $('#form-ok').hidden = false;
});

/* 9 · Small things */
$$('[data-lang-switch]').forEach(a => a.addEventListener('click', () => { a.href = a.getAttribute('href').split('#')[0] + location.hash; }));
$('[data-focus-search]').addEventListener('click', () => setTimeout(() => qIn.focus({ preventScroll: true }), RM ? 0 : 400));
