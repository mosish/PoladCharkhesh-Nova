// Product page (/p/<slug>/): the content is complete in the HTML. With a request in the URL (?r=<designation>,
// 05 §17) this explains the match with the shared matcher — the same wording as the explorer — and points at the
// listed item it resolved to. The canonical URL stays the Product page.
import { buildIndex, match } from './designation.js';

const d = document, $ = s => d.querySelector(s);
d.documentElement.classList.replace('no-js', 'js');
const T = JSON.parse($('#i18n').textContent);
const fill = (tpl, o) => tpl.replace(/\{(\w+)\}/g, (m, k) => (k in o ? o[k] : m));
const iso = s => '⁦' + s + '⁩';
const isoList = list => list.map(iso).join(T.list_sep);

const req = new URLSearchParams(location.search).get('r');
const page = $('.pp'), note = $('#pp-match');
if (req && page && note) {
  const m = match(buildIndex(JSON.parse($('#dindex').textContent)), req);
  const slug = page.dataset.slug, here = m.product === 'p:' + slug, R = iso(m.requested);
  let text = '';
  if (here && m.type === 'exact_variant') {
    if (m.via === 'spelling') text = fill(T.spelling_note, { q: R, code: iso(m.designation) });
    d.getElementById('v-' + m.variant.slice(2))?.scrollIntoView();
  } else if (here && m.type === 'exact_product') {
    if (m.via === 'spelling') text = fill(T.spelling_note, { q: R, code: iso(m.designation) });
  } else if (here && m.type === 'unlisted_variant') {
    text = fill(T.ws_match_unlisted, { req: R, product: iso($('#pp-title').textContent.trim()) });
  } else if (here && m.type === 'related') {
    text = m.listed.length ? fill(T.ws_match_rel, { req: R, listed: isoList(m.listed) }) : fill(T.ws_match_rel_none, { req: R });
  } else if (here && m.type === 'base_series') {
    text = fill(T.ws_match, { req: R, base: iso(m.designation) });
  } else {
    // The request does not resolve to this Product: say so, never imply a relationship.
    text = fill(T.ask_zero, { q: R });
  }
  if (text) { note.textContent = text; note.hidden = false; }
}
