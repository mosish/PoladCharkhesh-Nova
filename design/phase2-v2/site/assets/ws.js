// Product workspace (Phase 2 V2) — loaded on first use. Desktop: docked panel. Mobile: bottom-sheet dialog.
// In production the drawing below is pre-rendered by the Publish Engine into each product's HTML;
// the prototype draws it here from the same boundary dimensions.
const d = document, html = d.documentElement;
const $ = (s, c = d) => c.querySelector(s), $$ = (s, c = d) => [...c.querySelectorAll(s)];
const ws = $('#ws'), scrim = $('#scrim'), home = d.createComment('ws-home');
const mq = matchMedia('(max-width: 899px)');
const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
const cache = new Map(), emptyHTML = ws.innerHTML;
let T, FA, L, cur, from;
ws.before(home);

const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const num = n => FA ? String(n).replace(/\d/g, x => '۰۱۲۳۴۵۶۷۸۹'[x]) : String(n);
const fmt = v => (Math.round(v * 100) / 100).toString();
const date = iso => new Intl.DateTimeFormat(FA ? 'fa-IR' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso + 'T12:00:00'));
const ico = (id, dir) => `<svg class="ico${dir ? ' ico--dir' : ''}" aria-hidden="true"><use href="#${id}"/></svg>`;
const wa = text => `https://wa.me/${T.wa}?text=${encodeURIComponent(text)}`;

export async function open(slug, row, t) {
  T = t; FA = T.lang === 'fa'; L = T.lang; from = row.querySelector('.cat__code') || row;
  $$('#results tr[aria-current]').forEach(r => r.removeAttribute('aria-current'));
  row.setAttribute('aria-current', 'true');
  history.replaceState(null, '', '#p-' + slug);
  if (mq.matches) sheetOpen();
  if (!cache.has(slug)) cache.set(slug, fetch(`data/p/${slug}.json`).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); }));
  ws.setAttribute('aria-busy', 'true');
  try { cur = await cache.get(slug); render(cur); }
  catch { cache.delete(slug); ws.innerHTML = `<div class="ws__empty"><b id="ws-title" tabindex="-1">${esc(T.err)}</b></div>`; }
  ws.removeAttribute('aria-busy');
  (mq.matches ? $('[data-act="close"]:not(.ws__handle)', ws) : $('#ws-title', ws))?.focus({ preventScroll: true });
}

function render(p) {
  const pending = p.status !== 'verified';
  const badge = pending
    ? `<span class="badge badge--pending"><span>${esc(T.st_pending)}</span></span>`
    : `<span class="badge badge--ok"><span>${esc(T.st_ok)}</span></span>`;
  const tabs = T.tabs.map((l, i) => `<button class="tab" type="button" role="tab" id="wst-${i}" aria-controls="wsp-${i}" aria-selected="${i === 0}" tabindex="${i === 0 ? 0 : -1}">${esc(l)}</button>`).join('');
  // Readout: the boundary dimensions, readable at a glance (always Latin digits, never mirrored)
  const readout = `<div class="readout${pending ? ' is-pending' : ''}" style="--n:${p.dims.length}" role="group" aria-label="${esc(T.readout)}">${p.dims.map(r =>
    `<div><small>${esc(r.s)} · <span>${esc(r[L])}</span></small><b>${fmt(r.v)}<i>mm</i></b></div>`).join('')}</div>`;
  const dimsTable = `<table class="spec"><caption>${esc(T.dims_h)}</caption>
    <thead><tr><th scope="col">${esc(T.prop)}</th><th scope="col" class="sym">${esc(T.sym)}</th><th scope="col" class="val">mm</th></tr></thead>
    <tbody>${p.dims.map(r => `<tr><th scope="row">${esc(r[L])}</th><td class="sym">${esc(r.s)}</td><td class="val"><bdi dir="ltr">${fmt(r.v)}</bdi></td></tr>`).join('')}</tbody></table>`;
  const source = p.source
    ? `<p class="src"><span>${esc(T.src)}:</span><a href="${esc(p.source.url)}" target="_blank" rel="noopener" lang="en" dir="ltr">${esc(p.source.label)} ${ico('i-ext')}</a><span>${esc(T.checked_t.replace('{date}', date(p.checked)))}</span></p>`
    : `<p class="src"><span>${esc(T.src)}:</span><span>${esc(T.nominal)}</span></p>`;
  const pend = pending ? `<div class="pendingbox" role="note"><b>${esc(T.st_pending)}</b><span>${esc(T.pending_p)}</span></div>` : '';
  const ratings = `<div class="pendingbox" role="note"><b>${esc(T.rating_h)}</b><span>${esc(T.rating_p)}</span>
    <ul>${p.ratings.map(r => `<li>${r.s ? `<bdi dir="ltr">${esc(r.s)}</bdi> · ` : ''}${esc(r[L])}</li>`).join('')}</ul></div>`;
  const aliases = p.aliases.length ? `<p class="src"><span>${esc(T.aliases)}:</span>${p.aliases.map(x => `<span class="chip">${esc(x)}</span>`).join('')}</p>` : '';
  const code = esc(p.code);

  ws.innerHTML = `
    <button class="ws__handle" type="button" data-act="close" aria-label="${esc(T.close)}" tabindex="-1"></button>
    <div class="ws__top">
      <h3 class="ws__code" id="ws-title" tabindex="-1"><bdi dir="ltr">${code}</bdi></h3>
      <div class="ws__acts">
        <button class="btn btn--ghost btn--sm btn--icon" type="button" data-act="copy" aria-label="${esc(T.copy_code)}" title="${esc(T.copy_code)}">${ico('i-copy')}</button>
        <button class="btn btn--ghost btn--sm btn--icon" type="button" data-act="link" aria-label="${esc(T.copy_link)}" title="${esc(T.copy_link)}">${ico('i-link')}</button>
        <button class="btn btn--ghost btn--sm btn--icon" type="button" data-act="close" aria-label="${esc(T.close)}" title="${esc(T.close)}">${ico('i-close')}</button>
      </div>
      <div class="ws__meta"><span>${esc(p.fam[L])}</span>${badge}<span class="sr" aria-live="polite" id="ws-live"></span></div>
    </div>
    ${readout}
    <div class="tabs" role="tablist" aria-label="${code}">${tabs}</div>
    <div class="ws__body">
      <div role="tabpanel" id="wsp-0" aria-labelledby="wst-0">${pend}
        <figure class="dwg">${drawing(p, false)}<figcaption><span>${esc(p.code)} · SECTION</span><span>NTS</span></figcaption></figure>
        ${source}
        <p class="muted" style="font-size:.875rem">${esc(p.fam['def_' + L])}</p>
      </div>
      <div role="tabpanel" id="wsp-1" aria-labelledby="wst-1" hidden>${dimsTable}${source}${aliases}${ratings}<p class="note">${esc(T.dwg_cap)}</p></div>
      <div role="tabpanel" id="wsp-2" aria-labelledby="wst-2" hidden><div class="empty">${ico('i-doc')}<span>${esc(T.docs_empty)}</span></div></div>
    </div>
    <div class="ws__foot">
      <a class="btn" href="${wa(T.wa_code.replace('{code}', p.code))}" rel="noopener">${ico('i-chat')}${esc(T.cta_wa)}</a>
      <a class="btn btn--line btn--icon" href="tel:${T.tel}" aria-label="${esc(T.cta_call)}" title="${esc(T.cta_call)}">${ico('i-phone')}</a>
      <a class="btn btn--line btn--inq" href="#contact" data-act="inq">${esc(T.cta_form)}</a>
      <p class="ws__msg">${esc(T.ws_msg)}</p>
    </div>`;
  wire();
}

function wire() {
  const tabs = $$('.tab', ws);
  const select = (i, focus) => tabs.forEach((t, k) => {
    const on = k === i;
    t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1;
    $('#wsp-' + k, ws).hidden = !on;
    if (on && focus) t.focus();
  });
  tabs.forEach((t, i) => t.addEventListener('click', () => select(i)));
  $('.tabs', ws).addEventListener('keydown', e => {
    const i = tabs.findIndex(t => t.getAttribute('aria-selected') === 'true');
    const step = { ArrowRight: FA ? -1 : 1, ArrowLeft: FA ? 1 : -1 }[e.key];
    if (step) { e.preventDefault(); select((i + step + tabs.length) % tabs.length, true); }
    if (e.key === 'Home') { e.preventDefault(); select(0, true); }
    if (e.key === 'End') { e.preventDefault(); select(tabs.length - 1, true); }
  });
  ws.onclick = e => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    if (act === 'close') close();
    if (act === 'copy' || act === 'link') {
      const text = act === 'copy' ? cur.code : location.href;
      const done = () => { $('#ws-live', ws).textContent = T.copied; b.title = T.copied; };
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(done, () => { $('#ws-live', ws).textContent = text; });
    }
    if (act === 'inq') {
      const f = $('#f-code'); if (f) f.value = cur.code;
      if (mq.matches) close(true);
    }
  };
}

function close(keepHash) {
  if (ws.classList.contains('is-open')) sheetClose();
  else { ws.innerHTML = emptyHTML; from?.focus(); }
  $$('#results tr[aria-current]').forEach(r => r.removeAttribute('aria-current'));
  if (!keepHash) history.replaceState(null, '', location.pathname + location.search);
}

/* Mobile bottom sheet: move to <body>, make the rest inert, trap focus by inertness */
const inertEls = () => [$('.proto'), $('header.bar'), $('main'), $('footer.foot'), $('.dock')].filter(Boolean);
function sheetOpen() {
  if (ws.classList.contains('is-open')) return;
  d.body.append(ws);
  ws.setAttribute('role', 'dialog'); ws.setAttribute('aria-modal', 'true');
  inertEls().forEach(el => el.inert = true);
  html.classList.add('ws-open'); d.body.style.overflow = 'hidden';
  scrim.hidden = false;
  requestAnimationFrame(() => requestAnimationFrame(() => { ws.classList.add('is-open'); scrim.classList.add('is-on'); }));
}
function sheetClose() {
  ws.classList.remove('is-open'); scrim.classList.remove('is-on');
  const done = () => {
    scrim.hidden = true; ws.removeAttribute('role'); ws.removeAttribute('aria-modal');
    inertEls().forEach(el => el.inert = false);
    html.classList.remove('ws-open'); d.body.style.overflow = '';
    home.after(ws); ws.innerHTML = emptyHTML; from?.focus({ preventScroll: true });
  };
  RM ? done() : setTimeout(done, 260);
}
scrim.addEventListener('click', () => close());
d.addEventListener('keydown', e => { if (e.key === 'Escape' && ws.classList.contains('is-open')) close(); });
mq.addEventListener('change', () => { if (!mq.matches && ws.classList.contains('is-open')) { sheetClose(); } });
let y0 = null;
ws.addEventListener('pointerdown', e => { if (ws.classList.contains('is-open') && e.target.closest('.ws__handle, .ws__head') && !e.target.closest('button:not(.ws__handle)')) y0 = e.clientY; });
ws.addEventListener('pointerup', e => { if (y0 !== null && e.clientY - y0 > 80) close(); y0 = null; });

/* Schematic section drawing from boundary dimensions (never mirrored in RTL) */
function drawing(p, big) {
  const dm = Object.fromEntries(p.dims.map(r => [r.s, r.v]));
  const W = 440, H = big ? 330 : 260, f = p.family, fs = big ? 13 : 12;
  const label = `${p.code} — ${p.fam[L]}`;
  if (f === 'unit') {
    const s = big ? 5 : 4, ox = W / 2 - 32 * s, oy = 14, cx = ox + 32 * s, cy = oy + 25 * s, r = 5 * s;
    return `<svg class="dwg-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(label)}">
      <g style="color:var(--line)"><use href="#g-unit" x="${ox}" y="${oy}" width="${64 * s}" height="${48 * s}"/></g>
      <path class="ln-ctr" d="M${cx - r - 30} ${cy}H${cx + r + 30}M${cx} ${cy - r - 30}V${cy + r + 30}"/>
      <path class="ln-dim" d="M${cx - r} ${cy - r - 16}H${cx + r}" marker-start="url(#ar)" marker-end="url(#ar)"/>
      <path class="ln-dim" d="M${cx - r} ${cy}V${cy - r - 20}M${cx + r} ${cy}V${cy - r - 20}"/>
      <text class="dim-t" x="${cx}" y="${cy - r - 22}" text-anchor="middle" font-size="${fs}">Ø${fmt(dm.d)}</text></svg>`;
  }
  const D = dm.D, d0 = dm.d ?? dm.Fw, B = dm.B ?? dm.T ?? dm.C ?? dm.H ?? dm.b;
  const s = Math.min((H - 92) / D, (big ? 170 : 140) / B);
  const cx = W / 2 - 6, cy = (H - 44) / 2 + 4;
  const y = r => cy - r * s, m = v => 2 * cy - v;
  const w = B * s, x0 = cx - w / 2, x1 = cx + w / 2, t = (D - d0) / 2, rO = D / 2, rI = d0 / 2;
  const P = pts => pts.map(q => q.map(v => v.toFixed(1)).join(' ')).join(' L');
  const both = (pts, cls) => `<path class="${cls}" d="M${P(pts)}Z"/><path class="${cls}" d="M${P(pts.map(([a, b]) => [a, m(b)]))}Z"/>`;
  const rect = (xa, xb, ra, rb) => [[xa, y(ra)], [xb, y(ra)], [xb, y(rb)], [xa, y(rb)]];
  let g = '';
  if (f === 'ball') {
    g += both(rect(x0, x1, rO, rO - .32 * t), 'fill-sec') + both(rect(x0, x1, rI + .32 * t, rI), 'fill-sec');
    const rb = Math.min(.24 * t, .42 * B) * s, rp = (rO + rI) / 2;
    g += `<circle class="fill-paper" cx="${cx}" cy="${y(rp)}" r="${rb}"/><circle class="fill-paper" cx="${cx}" cy="${m(y(rp))}" r="${rb}"/>`;
  } else if (f === 'cylindrical') {
    const k = .13 * w;
    g += both([[x0, y(rO)], [x1, y(rO)], [x1, y(rO - .42 * t)], [x1 - k, y(rO - .42 * t)], [x1 - k, y(rO - .3 * t)], [x0 + k, y(rO - .3 * t)], [x0 + k, y(rO - .42 * t)], [x0, y(rO - .42 * t)]], 'fill-sec');
    g += both(rect(x0 + k + 2, x1 - k - 2, rO - .3 * t, rI + .3 * t), 'fill-paper') + both(rect(x0, x1, rI + .3 * t, rI), 'fill-sec');
  } else if (f === 'tapered') {
    const cupY = x => y(rO - .38 * t) + (y(rO - .22 * t) - y(rO - .38 * t)) * (x - x0) / w;
    const coneY = x => y(rI + .26 * t) + (y(rI + .42 * t) - y(rI + .26 * t)) * (x - x0) / w;
    const xa = x0 + .12 * w, xb = x1 - .12 * w;
    g += both([[x0, y(rO)], [x1, y(rO)], [x1, y(rO - .22 * t)], [x0, y(rO - .38 * t)]], 'fill-sec');
    g += both([[x0, y(rI)], [x1, y(rI)], [x1, y(rI + .42 * t)], [x0, y(rI + .26 * t)]], 'fill-sec');
    g += both([[xa, cupY(xa) + 1.5], [xb, cupY(xb) + 1.5], [xb, coneY(xb) - 1.5], [xa, coneY(xa) - 1.5]], 'fill-paper');
  } else if (f === 'spherical') {
    const top = `M${x0} ${y(rO)}H${x1}V${y(rO - .3 * t)}Q${cx} ${y(rO - .12 * t)} ${x0} ${y(rO - .3 * t)}Z`;
    const bot = `M${x0} ${m(y(rO))}H${x1}V${m(y(rO - .3 * t))}Q${cx} ${m(y(rO - .12 * t))} ${x0} ${m(y(rO - .3 * t))}Z`;
    g += `<path class="fill-sec" d="${top}"/><path class="fill-sec" d="${bot}"/>`;
    g += both([[x0, y(rI)], [x1, y(rI)], [x1, y(rI + .26 * t)], [cx + .06 * w, y(rI + .26 * t)], [cx + .06 * w, y(rI + .4 * t)], [cx - .06 * w, y(rI + .4 * t)], [cx - .06 * w, y(rI + .26 * t)], [x0, y(rI + .26 * t)]], 'fill-sec');
    const rp = rI + .58 * t, rx = .2 * w, ry = .15 * t * s;
    for (const [sx, a] of [[-1, -12], [1, 12]]) {
      const ex = cx + sx * .23 * w;
      g += `<ellipse class="fill-paper" cx="${ex}" cy="${y(rp)}" rx="${rx}" ry="${ry}" transform="rotate(${a} ${ex} ${y(rp)})"/>`;
      g += `<ellipse class="fill-paper" cx="${ex}" cy="${m(y(rp))}" rx="${rx}" ry="${ry}" transform="rotate(${-a} ${ex} ${m(y(rp))})"/>`;
    }
  } else if (f === 'needle') {
    const k = .07 * w;
    g += both([[x0, y(rO)], [x1, y(rO)], [x1, y(rO - .55 * t)], [x1 - k, y(rO - .55 * t)], [x1 - k, y(rO - .2 * t)], [x0 + k, y(rO - .2 * t)], [x0 + k, y(rO - .55 * t)], [x0, y(rO - .55 * t)]], 'fill-sec');
    g += both(rect(x0 + k + 3, x1 - k - 3, rO - .2 * t, rI), 'fill-paper');
    g += `<path class="ln-thin" d="M${x0 - 34} ${y(rI)}H${x1 + 34}M${x0 - 34} ${m(y(rI))}H${x1 + 34}"/>`;
  } else if (f === 'thrust') {
    const k = .32 * w;
    g += both(rect(x0, x0 + k, rO - .12 * t, rI), 'fill-sec') + both(rect(x1 - k, x1, rO, rI + .12 * t), 'fill-sec');
    const rb = Math.min(.2 * w / s, .3 * t) * s, rp = (rO + rI) / 2;
    g += `<circle class="fill-paper" cx="${cx}" cy="${y(rp)}" r="${rb}"/><circle class="fill-paper" cx="${cx}" cy="${m(y(rp))}" r="${rb}"/>`;
  } else if (f === 'seal') {
    g += both([[x0, y(rO)], [x1, y(rO)], [x1, y(rO - .16 * t)], [x0 + .2 * w, y(rO - .16 * t)], [x0 + .2 * w, y(rI + .42 * t)], [x0, y(rI + .42 * t)]], 'fill-sec');
    g += both([[x0 + .38 * w, y(rO - .16 * t)], [x1 - .02 * w, y(rO - .16 * t)], [x1 - .1 * w, y(rI)], [x0 + .5 * w, y(rI + .14 * t)]], 'fill-tint');
    const sx = x1 - .2 * w, sr = Math.max(2.5, .07 * t * s);
    g += `<circle class="ln-obj" cx="${sx}" cy="${y(rI + .3 * t)}" r="${sr}"/><circle class="ln-obj" cx="${sx}" cy="${m(y(rI + .3 * t))}" r="${sr}"/>`;
    g += `<path class="ln-thin" d="M${x0 - 34} ${y(rI)}H${x1 + 34}M${x0 - 34} ${m(y(rI))}H${x1 + 34}"/>`;
  }
  const xD = x1 + 44, xd = x0 - 40, yB = m(y(rO)) + 26;
  const dims = `<path class="ln-dim" d="M${x1 + 4} ${y(rO)}H${xD + 6}M${x1 + 4} ${m(y(rO))}H${xD + 6}M${x0 - 4} ${y(rI)}H${xd - 6}M${x0 - 4} ${m(y(rI))}H${xd - 6}M${x0} ${m(y(rO)) + 4}V${yB + 6}M${x1} ${m(y(rO)) + 4}V${yB + 6}"/>
    <path class="ln-dim" d="M${xD} ${y(rO)}V${m(y(rO))}M${xd} ${y(rI)}V${m(y(rI))}M${x0} ${yB}H${x1}" marker-start="url(#ar)" marker-end="url(#ar)"/>
    <text class="dim-t" font-size="${fs}" x="${xD + 8}" y="${cy + 4}">Ø${fmt(D)}</text>
    <text class="dim-t" font-size="${fs}" x="${xd - 8}" y="${cy + 4}" text-anchor="end">Ø${fmt(d0)}</text>
    <text class="dim-t" font-size="${fs}" x="${cx}" y="${yB + 18}" text-anchor="middle">${fmt(B)}</text>`;
  return `<svg class="dwg-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(label)}">
    <path class="ln-ctr" d="M${x0 - 70} ${cy}H${x1 + 70}"/>${g}${dims}</svg>`;
}
