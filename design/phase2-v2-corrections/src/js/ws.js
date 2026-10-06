// Product workspace body (Phase 2 V2, Gate 2 corrections) — loaded on first use.
// nova.js owns identity, the shell (region / mobile dialog), focus and every CTA; this module only fetches and renders
// one product's body. Provenance is per value: each value shows its own status and source key, with a sources legend.
// In production the drawing is pre-rendered by the Publish Engine; the prototype draws it from the same dimensions.
const d = document;
const $ = (s, c = d) => c.querySelector(s), $$ = (s, c = d) => [...c.querySelectorAll(s)];
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = v => (Math.round(v * 100) / 100).toString();
const ico = id => `<svg class="ico" aria-hidden="true"><use href="#${id}"/></svg>`;
let L = 'fa', FA = true;

export function fetchProduct(slug, signal) {
  return fetch(`data/p/${slug}.json`, { signal }).then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
}

export function renderBody(main, p, a, T, mobile) {
  L = T.lang; FA = L === 'fa';
  const date = iso => new Intl.DateTimeFormat(FA ? 'fa-IR' : 'en-GB', { dateStyle: 'medium' }).format(new Date(iso + 'T12:00:00'));
  const src = Object.fromEntries(p.sources.map(s => [s.key, s]));
  const pendingProduct = p.status !== 'verified';
  const oneSource = p.sources.length === 1 && p.dims.every(r => r.src === p.sources[0].key) && !p.extra.length;
  // value-level provenance marker: source key for verified values, an explicit word for pending ones
  const pv = r => r.st === 'ok' && r.src
    ? `<span class="pv" aria-hidden="true">${r.src}</span><span class="sr">${esc(T.st_val_ok)}, ${esc(T.col_src)} ${r.src}</span>`
    : `<span class="pv pv--pending">${esc(T.st_val_pending)}</span>`;
  const valueRow = r => `<tr class="${r.st === 'ok' ? '' : 'is-pending'}"><th scope="row">${esc(r[L])}</th><td class="sym"><bdi dir="ltr">${esc(r.s)}</bdi></td>
    <td class="val">${r.v != null ? `<bdi dir="ltr">${fmt(r.v)}</bdi> <span class="u">${esc(r.u || 'mm')}</span>` : '—'}</td><td class="pvc">${pv(r)}</td></tr>`;
  const table = (caption, rows, note) => `<table class="spec prov"><caption>${esc(caption)}</caption>
    <thead><tr><th scope="col">${esc(T.prop)}</th><th scope="col" class="sym">${esc(T.sym)}</th><th scope="col" class="val">${esc(T.value)}</th><th scope="col" class="pvc">${esc(T.col_src)}</th></tr></thead>
    <tbody>${rows.map(valueRow).join('')}</tbody></table>${note ? `<p class="note">${esc(note)}</p>` : ''}`;
  const legend = p.sources.length
    ? `<div class="prov-legend"><b>${esc(T.prov_h)}</b><ol>${p.sources.map(s => `<li><span class="pv" aria-hidden="true">${s.key}</span><span class="sr">${esc(T.col_src)} ${s.key}:</span>
        <a href="${esc(s.url)}" target="_blank" rel="noopener" lang="en" dir="ltr">${esc(s.label)} ${ico('i-ext')}</a><span>${esc(T.checked_t.replace('{date}', date(s.checked)))}</span></li>`).join('')}</ol>
        <p class="note">${esc(T.prov_note)}</p></div>`
    : `<p class="src"><span>${esc(T.src)}:</span><span>${esc(T.nominal)}</span></p>`;
  // Overview: one scoped line when a single source covers every value, the full legend otherwise
  const scoped = oneSource
    ? `<p class="src"><span>${esc(T.dims_h)} · ${esc(T.src)} <span class="pv" aria-hidden="true">${p.sources[0].key}</span>:</span><a href="${esc(p.sources[0].url)}" target="_blank" rel="noopener" lang="en" dir="ltr">${esc(p.sources[0].label)} ${ico('i-ext')}</a><span>${esc(T.checked_t.replace('{date}', date(p.sources[0].checked)))}</span></p>`
    : legend;
  const readout = `<div class="readout" style="--n:${p.dims.length}" role="group" aria-label="${esc(T.readout)}">${p.dims.map(r =>
    `<div class="${r.st === 'ok' ? '' : 'is-pending'}"><small><bdi dir="ltr">${esc(r.s)}</bdi> · <span>${esc(r[L])}</span></small><b>${fmt(r.v)}<i>mm</i></b>${oneSource ? '' : pv(r)}</div>`).join('')}</div>`;
  const chips = (label, list) => list.length ? `<p class="src"><span>${esc(label)}:</span>${list.map(x => `<span class="chip" dir="ltr">${esc(x)}</span>`).join('')}</p>` : '';
  const tabs = T.tabs.map((l, i) => `<button class="tab" type="button" role="tab" id="wst-${i}" aria-controls="wsp-${i}" aria-selected="${i === 0}" tabindex="${i === 0 ? 0 : -1}">${esc(l)}</button>`).join('');
  main.innerHTML = `${readout}
    <div class="tabs" role="tablist" aria-label="${esc(a.req)}">${tabs}</div>
    <div class="ws__body">
      <div role="tabpanel" id="wsp-0" aria-labelledby="wst-0" tabindex="0">
        ${pendingProduct ? `<div class="pendingbox" role="note"><b>${esc(T.st_pending)}</b><span>${esc(T.pending_p)}</span></div>` : ''}
        ${p.demo ? `<p class="note note--demo">${esc(T.demo_note)}</p>` : ''}
        <figure class="dwg">${drawing(p, false, mobile ? 20 : 16)}<figcaption><span>${esc(p.code)} · SECTION</span><span>NTS</span></figcaption></figure>
        ${scoped}
        <p class="muted">${esc(p.fam['def_' + L])}</p>
      </div>
      <div role="tabpanel" id="wsp-1" aria-labelledby="wst-1" tabindex="0" hidden>
        ${table(T.dims_h, p.dims)}
        ${p.extra.length ? table(T.other_h, p.extra) : ''}
        ${table(T.rating_h, p.ratings, T.rating_note)}
        ${legend}
        ${chips(T.variants_h, p.variants)}${chips(T.spellings_h, p.spellings)}
        <p class="note">${esc(T.dwg_cap)}</p>
      </div>
      <div role="tabpanel" id="wsp-2" aria-labelledby="wst-2" tabindex="0" hidden><div class="empty">${ico('i-doc')}<span>${esc(T.docs_empty)}</span></div></div>
    </div>`;
  const tabEls = $$('.tab', main);
  const select = (i, focus) => tabEls.forEach((t, k) => {
    const on = k === i;
    t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1;
    $('#wsp-' + k, main).hidden = !on;
    if (on && focus) t.focus();
  });
  tabEls.forEach((t, i) => t.addEventListener('click', () => select(i)));
  $('.tabs', main).addEventListener('keydown', e => {
    const i = tabEls.findIndex(t => t.getAttribute('aria-selected') === 'true');
    const step = { ArrowRight: FA ? -1 : 1, ArrowLeft: FA ? 1 : -1 }[e.key];
    if (step) { e.preventDefault(); select((i + step + tabEls.length) % tabEls.length, true); }
    if (e.key === 'Home') { e.preventDefault(); select(0, true); }
    if (e.key === 'End') { e.preventDefault(); select(tabEls.length - 1, true); }
  });
}

/* Schematic section drawing from boundary dimensions (never mirrored in RTL) */
function drawing(p, big, fs0) {
  const dm = Object.fromEntries(p.dims.map(r => [r.s, r.v]));
  const W = 440, H = big ? 330 : 260, f = p.family, fs = fs0 || (big ? 13 : 12);
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
    <text class="dim-t" font-size="${fs}" x="${xD + 8}" y="${cy + fs * .35}">Ø${fmt(D)}</text>
    <text class="dim-t" font-size="${fs}" x="${xd - 8}" y="${cy + fs * .35}" text-anchor="end">Ø${fmt(d0)}</text>
    <text class="dim-t" font-size="${fs}" x="${cx}" y="${yB + fs + 6}" text-anchor="middle">${fmt(B)}</text>`;
  return `<svg class="dwg-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(label)}">
    <path class="ln-ctr" d="M${x0 - 70} ${cy}H${x1 + 70}"/>${g}${dims}</svg>`;
}
