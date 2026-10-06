// Polad Charkhesh Nova — core interactions (Phase 2 prototype). No dependencies.
// Loaded as a deferred module; the workspace (ws.js) is imported only when a product is opened.
const d = document, html = d.documentElement;
const $ = (s, c = d) => c.querySelector(s), $$ = (s, c = d) => [...c.querySelectorAll(s)];
html.classList.replace('no-js', 'js');
const T = JSON.parse($('#i18n').textContent);
const FA = T.lang === 'fa';
const num = n => FA ? String(n).replace(/\d/g, x => '۰۱۲۳۴۵۶۷۸۹'[x]) : String(n);
const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
const SDA = window.CSS && CSS.supports('animation-timeline: view()');
const wa = text => `https://wa.me/${T.wa}?text=${encodeURIComponent(text)}`;

// Same normalisation the Publish Engine uses for search keys: Persian/Arabic digits, Arabic letter forms, separators.
export const norm = s => s.replace(/[۰-۹٠-٩]/g, c => String(c.charCodeAt(0) & 15))
  .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, '').toLowerCase().replace(/×/g, 'x').replace(/[\s\-_/.]+/g, '');

/* 1 · Sheet indicator + nav state (one observer, no scroll listener) */
const sheetNow = $('#sheet-now'), navLinks = $$('.bar__nav a');
const spy = new IntersectionObserver(es => {
  for (const e of es) if (e.isIntersecting) {
    sheetNow.textContent = e.target.dataset.sheet;
    navLinks.forEach(a => a.hash === '#' + e.target.id ? a.setAttribute('aria-current', 'true') : a.removeAttribute('aria-current'));
  }
}, { rootMargin: '-40% 0px -55% 0px' });
$$('[data-sheet]').forEach(s => spy.observe(s));
$$('.menu nav a').forEach(a => a.addEventListener('click', () => { a.closest('details').open = false; }));

/* 2 · Drawing Set fallback: browsers without scroll-driven animations draw each sheet once it enters */
if (!SDA && !RM) {
  html.classList.add('js-io');
  const dio = new IntersectionObserver(es => es.forEach(e => {
    if (e.isIntersecting) { e.target.classList.add('is-drawn'); dio.unobserve(e.target); }
  }), { rootMargin: '0px 0px -12% 0px' });
  $$('.draw, .steps').forEach(el => dio.observe(el));
}

/* 3 · Product explorer: the list is static HTML (crawlable); this only filters it */
const items = $$('#results > li'), count = $('#count'), nr = $('#noresult'), qIn = $('#q');
const famNames = Object.fromEntries($$('.fam__item').map(a => [a.dataset.filter, $('.fam__name', a).textContent]));
const state = { q: '', f: '', r: '', v: '' };
items.forEach(li => { const c = $('.row__code bdi', li); c.dataset.raw = c.textContent; });

function mark(el, qn) {
  const raw = el.dataset.raw;
  el.textContent = raw;
  if (!qn) return;
  let n = '', map = [];
  for (let i = 0; i < raw.length; i++) { const k = norm(raw[i]); for (const ch of k) { n += ch; map.push(i); } }
  const at = n.indexOf(qn);
  if (at < 0) return;
  const a = map[at], b = map[at + qn.length - 1] + 1, m = d.createElement('mark');
  m.textContent = raw.slice(a, b);
  el.replaceChildren(raw.slice(0, a), m, raw.slice(b));
}

function apply() {
  const qn = norm(state.q);
  const [lo, hi] = state.r ? state.r.split('-').map(Number) : [0, 0];
  let n = 0;
  for (const li of items) {
    const bore = +li.dataset.d;
    const ok = (!qn || li.dataset.k.includes(qn)) && (!state.f || li.dataset.f === state.f)
      && (!state.r || (li.dataset.d !== '' && bore > lo && bore <= hi)) && (!state.v || li.dataset.s === 'verified');
    li.hidden = !ok;
    if (ok) { n++; mark($('.row__code bdi', li), qn); }
  }
  count.textContent = T.count.replace('{n}', num(n)).replace('{total}', num(items.length));
  nr.hidden = n > 0;
  if (!n) {
    const what = state.q.trim() || famNames[state.f] || '—';
    $('#noresult-t').textContent = T.nr_t.replace('{q}', what);
    $('#noresult-wa').href = wa(T.wa_nr.replace('{q}', what));
  }
}

let tm;
function setQ(v, submit) {
  state.q = v;
  if (qIn.value !== v) qIn.value = v;
  apply();
  if (!submit) return;
  $('#explorer').scrollIntoView({ behavior: RM ? 'auto' : 'smooth', block: 'start' });
  const vis = items.filter(li => !li.hidden), qn = norm(v);
  const exact = vis.find(li => li.dataset.k.split('|').includes(qn));
  if (qn && (exact || vis.length === 1)) openP($('.row', exact || vis[0]));
}
$$('form[data-search]').forEach(f => f.addEventListener('submit', e => { e.preventDefault(); setQ($('input', f).value, true); }));
qIn.addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(() => setQ(qIn.value, false), 90); });
$$('.chip[data-q]').forEach(b => b.addEventListener('click', () => { $('#q-hero').value = b.dataset.q; setQ(b.dataset.q, true); }));

$$('.facet').forEach(b => b.addEventListener('click', () => {
  const k = b.dataset.facet;
  if (k === 'v') { const on = b.getAttribute('aria-pressed') !== 'true'; b.setAttribute('aria-pressed', String(on)); state.v = on ? '1' : ''; }
  else { $$(`.facet[data-facet="${k}"]`).forEach(x => x.setAttribute('aria-pressed', String(x === b))); state[k] = b.dataset.v; }
  apply();
}));
d.addEventListener('click', e => {
  const a = e.target.closest('[data-filter]');
  if (!a) return;
  const v = a.dataset.filter, b = $(`.facet[data-facet="f"][data-v="${v}"]`);
  if (b) b.click();
  else { $$('.facet[data-facet="f"]').forEach(x => x.setAttribute('aria-pressed', 'false')); state.f = v; apply(); }
});

/* 4 · Product workspace (code-split) */
let wsMod;
const loadWs = () => (wsMod ||= import('./ws.js'));
function openP(a) { loadWs().then(m => m.open(a.dataset.slug, a, T)); }
$('#results').addEventListener('click', e => { const a = e.target.closest('.row'); if (!a) return; e.preventDefault(); openP(a); });
$('#results').addEventListener('pointerover', loadWs, { once: true });
if (location.hash.startsWith('#p-')) {
  const a = $(`.row[data-slug="${CSS.escape(location.hash.slice(3))}"]`);
  if (a) { $('#explorer').scrollIntoView(); openP(a); }
}

/* 5 · Exploded Assembly fallback driver (only where scroll timelines are missing) */
const track = $('.ea__track'), stage = $('.ea__stage');
if (track && !SDA && !RM) {
  const rows = $$('.bom tbody tr'), balls = $$('.ea .balloon');
  const ease = x => x < .5 ? 2 * x * x : 1 - (-2 * x + 2) ** 2 / 2;
  let on = false, raf = 0;
  const tick = () => {
    raf = 0;
    const r = track.getBoundingClientRect(), span = r.height - innerHeight;
    const p = Math.min(1, Math.max(0, -r.top / span));
    const e = p < .26 ? ease(p / .26) : p < .8 ? 1 : 1 - ease((p - .8) / .2);
    stage.style.setProperty('--e', e.toFixed(3));
    const i = p >= .26 && p < .782 ? Math.floor((p - .26) / .087) : -1;
    rows.forEach((tr, k) => tr.classList.toggle('is-on', k === i));
    balls.forEach(g => g.classList.toggle('is-on', +g.dataset.i === i + 1));
  };
  new IntersectionObserver(([en]) => { on = en.isIntersecting; if (on) tick(); }).observe(track);
  addEventListener('scroll', () => { if (on && !raf) raf = requestAnimationFrame(tick); }, { passive: true });
}

/* 6 · Inquiry form (prototype: validates, never sends) */
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

/* 7 · Small things */
$$('[data-lang-switch]').forEach(a => a.addEventListener('click', () => { a.href = a.getAttribute('href').split('#')[0] + location.hash; }));
$('[data-focus-search]').addEventListener('click', () => setTimeout(() => qIn.focus({ preventScroll: true }), RM ? 0 : 400));
