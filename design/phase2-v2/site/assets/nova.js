// Polad Charkhesh Nova — core interactions (Phase 2 V2). No dependencies.
// The workspace (ws.js) is imported only when a product is opened.
const d = document, html = d.documentElement;
const $ = (s, c = d) => c.querySelector(s), $$ = (s, c = d) => [...c.querySelectorAll(s)];
html.classList.replace('no-js', 'js');
const T = JSON.parse($('#i18n').textContent);
const FA = T.lang === 'fa';
const num = n => FA ? String(n).replace(/\d/g, x => '۰۱۲۳۴۵۶۷۸۹'[x]) : String(n);
const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
const SDA = window.CSS && CSS.supports('animation-timeline: view()');
const wa = text => `https://wa.me/${T.wa}?text=${encodeURIComponent(text)}`;

// Same normalisation as the Publish Engine's search keys: Persian/Arabic digits, Arabic letter forms, separators
export const norm = s => s.replace(/[۰-۹٠-٩]/g, c => String(c.charCodeAt(0) & 15))
  .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, '').toLowerCase().replace(/×/g, 'x').replace(/[\s\-_/.]+/g, '');

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

/* 4 · Product explorer: rows are static HTML (crawlable); this only filters them */
const rows = $$('#results tbody tr'), count = $('#count'), qIn = $('#q');
const ask = $('#ask'), askT = $('#ask-t'), askP = $('#ask-p'), askWa = $('#ask-wa');
const famNames = Object.fromEntries($$('.fam__item').map(a => [a.dataset.filter, $('.fam__name', a).textContent]));
const state = { q: '', f: '', r: '', v: '' };
rows.forEach(tr => { const c = $('.cat__code bdi', tr); c.dataset.raw = c.textContent; });

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

function apply() {
  const qn = norm(state.q), q = state.q.trim();
  const [lo, hi] = state.r ? state.r.split('-').map(Number) : [0, 0];
  let n = 0, exact = false;
  for (const tr of rows) {
    const bore = +tr.dataset.d;
    const ok = (!qn || tr.dataset.k.includes(qn)) && (!state.f || tr.dataset.f === state.f)
      && (!state.r || (tr.dataset.d !== '' && bore > lo && bore <= hi)) && (!state.v || tr.dataset.s === 'verified');
    tr.hidden = !ok;
    if (ok) { n++; mark($('.cat__code bdi', tr), qn); if (qn && tr.dataset.k.split('|').includes(qn)) exact = true; }
  }
  count.textContent = T.count.replace('{n}', num(n)).replace('{total}', num(rows.length));
  // Keep the query and hand it to a person whenever the exact code is not listed
  const what = q || (n ? '' : famNames[state.f] || '');
  const show = (qn && !exact) || (!n && what);
  ask.hidden = !show;
  if (show) {
    ask.classList.toggle('ask--soft', n > 0);
    askT.innerHTML = '';
    const tpl = n > 0 ? T.ask_soft : T.ask_zero, i = tpl.indexOf('{q}'), b = d.createElement('b');
    b.className = 'ask__q'; b.textContent = what;
    askT.append(tpl.slice(0, i), b, tpl.slice(i + 3));
    askP.hidden = n > 0;
    askWa.href = wa(T.wa_ask.replace('{q}', what));
    askWa.dataset.q = what;
  }
}
$('#ask-form').addEventListener('click', () => { const f = $('#f-code'); if (f) f.value = askWa.dataset.q || ''; });

let tm;
function setQ(v, submit) {
  state.q = v;
  if (qIn.value !== v) qIn.value = v;
  apply();
  if (!submit) return;
  const vis = rows.filter(tr => !tr.hidden), qn = norm(v);
  const hit = qn && (vis.find(tr => tr.dataset.k.split('|').includes(qn)) || (vis.length === 1 && vis[0]));
  // A direct hit lands on results + workspace (whole panel, actions included); otherwise on the explorer header and its ask panel
  $(hit ? '.explorer__body' : '#explorer').scrollIntoView({ behavior: RM ? 'auto' : 'smooth', block: 'start' });
  if (hit) openP(hit);
}
$$('form[data-search]').forEach(f => f.addEventListener('submit', e => { e.preventDefault(); setQ($('input', f).value, true); }));
qIn.addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(() => setQ(qIn.value, false), 90); });
$$('.chip[data-q]').forEach(b => b.addEventListener('click', () => { $('#q-hero').value = b.dataset.q; setQ(b.dataset.q, true); }));

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

/* 5 · Product workspace (code-split) */
let wsMod;
const loadWs = () => (wsMod ||= import('./ws.js'));
function openP(tr) { loadWs().then(m => m.open(tr.dataset.slug, tr, T)); }
$('#results').addEventListener('click', e => { const tr = e.target.closest('tbody tr'); if (!tr) return; e.preventDefault(); openP(tr); });
$('#results').addEventListener('pointerover', loadWs, { once: true });
if (location.hash.startsWith('#p-')) {
  const tr = $(`#results tr[data-slug="${CSS.escape(location.hash.slice(3))}"]`);
  if (tr) { $('#explorer').scrollIntoView(); openP(tr); }
}

/* 6 · Exploded Assembly: one progress value --p drives all six stages; this is the fallback driver */
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

/* 7 · Inquiry form (prototype: validates, never sends) */
const form = $('#inquiry');
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

/* 8 · Small things */
$$('[data-lang-switch]').forEach(a => a.addEventListener('click', () => { a.href = a.getAttribute('href').split('#')[0] + location.hash; }));
$('[data-focus-search]').addEventListener('click', () => setTimeout(() => qIn.focus({ preventScroll: true }), RM ? 0 : 400));
