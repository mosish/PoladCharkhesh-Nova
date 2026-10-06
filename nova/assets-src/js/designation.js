// Designation normalisation and matching for the explorer
// (docs/designation-matching.md). A mirror of app/src/Designation/*.php; both
// run tests/fixtures/designation/corpus.json. The server's match is
// authoritative (E-21): this copy only gives the explorer an instant answer.

export const REQUESTED_MAX_LENGTH = 64; // code points

// Steps 1, 2 and 5: digits (Persian, Arabic-Indic, fullwidth), Arabic letter forms, the multiplication sign.
const MAP = new Map([
  ...[...'۰۱۲۳۴۵۶۷۸۹'].map((c, i) => [c, String(i)]),
  ...[...'٠١٢٣٤٥٦٧٨٩'].map((c, i) => [c, String(i)]),
  ...[...'０１２３４５６７８９'].map((c, i) => [c, String(i)]),
  ['ي', 'ی'], ['ك', 'ک'], ['×', 'x'],
]);
const MAPPED = /[۰-۹٠-٩０-９يك×]/gu;

// Whitespace (step 6a); also what requested() trims.
const WS = '\\u{0009}-\\u{000D}\\u{0020}\\u{0085}\\u{00A0}\\u{1680}\\u{2000}-\\u{200A}\\u{2028}\\u{2029}\\u{202F}\\u{205F}\\u{3000}';
// Step 3 (invisible marks) and step 6 (whitespace, hyphens and dashes, underscore, full stop).
const REMOVE = new RegExp(
  '[\\u{00AD}\\u{061C}\\u{200B}-\\u{200F}\\u{202A}-\\u{202E}\\u{2060}\\u{2066}-\\u{2069}\\u{FEFF}' + WS
    + '\\u{002D}\\u{2010}-\\u{2015}\\u{2212}\\u{FE58}\\u{FE63}\\u{FF0D}\\u{005F}\\u{002E}]',
  'gu',
);
const TRIM = new RegExp(`^[${WS}]+|[${WS}]+$`, 'gu');

// ASCII-only lower case (step 4), so the result equals PHP's strtolower.
const asciiLower = s => s.replace(/[A-Z]/g, c => String.fromCharCode(c.charCodeAt(0) + 32));

/** The normalised key; "/" is never touched (E-19). */
export const key = text => asciiLower(text.replace(MAPPED, c => MAP.get(c))).replace(REMOVE, '');

/** The designation as typed: trimmed and length-limited, never rewritten. */
export function requested(raw) {
  const points = [...raw.replace(TRIM, '')];
  return points.length > REQUESTED_MAX_LENGTH
    ? { text: points.slice(0, REQUESTED_MAX_LENGTH).join(''), tooLong: true }
    : { text: points.join(''), tooLong: false };
}

export class InvalidDesignationIndex extends Error {
  constructor(error, detail) {
    super(`${error}: ${detail}`);
    this.error = error;
  }
}

const fail = (error, detail) => { throw new InvalidDesignationIndex(error, detail); };
const isObject = v => v !== null && typeof v === 'object' && !Array.isArray(v);
const list = (data, field) => (Array.isArray(data[field]) ? data[field] : fail('invalid_index', `${field} must be a list`));
const str = (data, field) => (typeof data[field] === 'string' ? data[field] : fail('invalid_index', `${field} must be a string`));
const bool = (data, field) => (typeof data[field] === 'boolean' ? data[field] : fail('invalid_index', `${field} must be true or false`));
const obj = (value, where) => (isObject(value) ? value : fail('invalid_index', `${where} must be an object`));
const designationKey = text => key(text) || fail('empty_key', `"${text}" normalises to nothing`);

/**
 * Builds the index from its published form. Same validation, in the same
 * order, as DesignationIndex::fromArray().
 */
export function buildIndex(data) {
  const root = obj(data, 'index');
  const byKey = new Map();
  const products = [];
  const byId = new Map();
  const seenIds = new Set();
  const claimId = id => { if (seenIds.has(id)) fail('duplicate_id', `id "${id}" is used more than once`); seenIds.add(id); };
  const claimKey = (k, record) => { if (byKey.has(k)) fail('duplicate_key', `key "${k}" is used more than once`); byKey.set(k, record); };

  list(root, 'products').forEach((raw, i) => {
    const p = obj(raw, `products[${i}]`);
    const id = str(p, 'id');
    claimId(id);
    const designation = str(p, 'designation');
    const productKey = designationKey(designation);
    const variants = list(p, 'variants').map((rawVariant, j) => {
      const v = obj(rawVariant, `products[${i}].variants[${j}]`);
      const variantId = str(v, 'id');
      claimId(variantId);
      const variantDesignation = str(v, 'designation');
      return { kind: 'variant', id: variantId, productId: id, designation: variantDesignation, key: designationKey(variantDesignation), listed: bool(v, 'listed') };
    });
    const product = { kind: 'product', id, designation, key: productKey, family: str(p, 'family'), baseListed: bool(p, 'base_listed'), variants };
    claimKey(productKey, product);
    variants.forEach(v => claimKey(v.key, v));
    products.push(product);
    byId.set(id, product);
  });
  products.forEach(p => p.variants.forEach(v => byId.set(v.id, v)));

  list(root, 'spellings').forEach((raw, i) => {
    const s = obj(raw, `spellings[${i}]`);
    const spelling = str(s, 'spelling');
    const targetId = str(s, 'target');
    const target = byId.get(targetId) ?? fail('unknown_target', `spelling "${spelling}" targets unknown id "${targetId}"`);
    const k = designationKey(spelling);
    claimKey(k, { kind: 'spelling', spelling, key: k, target });
  });

  const suffixTokens = new Map();
  for (const [family, tokens] of Object.entries(obj(root.suffix_rules, 'suffix_rules'))) {
    if (!Array.isArray(tokens)) fail('invalid_index', `suffix_rules.${family} must be a list`);
    const keys = new Set();
    for (const token of tokens) {
      if (typeof token !== 'string') fail('invalid_index', `suffix_rules.${family} must contain strings`);
      const tokenKey = key(token);
      if (tokenKey === '') fail('empty_token', `suffix token "${token}" normalises to nothing`);
      keys.add(tokenKey);
    }
    suffixTokens.set(family, [...keys]);
  }

  return { byKey, products, suffixTokens };
}

// The listed items as named to the visitor: the base first when base_listed is on,
// then the listed variants in catalogue order.
const listedDesignations = p => [...(p.baseListed ? [p.designation] : []), ...p.variants.filter(v => v.listed).map(v => v.designation)];

const result = (requestedText, k, type, fields = {}) => ({
  requested: requestedText, key: k, type, via: null, spelling: null, product: null, variant: null,
  designation: null, listed: [], suffix: [], reason: null, ...fields,
});

function resolve(requestedText, k, target, spelling) {
  const via = spelling === null ? null : 'spelling';
  if (target.kind === 'variant') {
    return result(requestedText, k, target.listed ? 'exact_variant' : 'unlisted_variant',
      { via, spelling, product: target.productId, variant: target.id, designation: target.designation });
  }
  // base_listed alone says whether the plain Product designation is listed.
  return target.baseListed
    ? result(requestedText, k, 'exact_product', { via, spelling, product: target.id, designation: target.designation })
    : result(requestedText, k, 'related', { via, spelling, product: target.id, designation: target.designation, listed: listedDesignations(target) });
}

// Ways to write `tail` as approved tokens, stopping at two (two means ambiguous).
function splits(tail, tokens) {
  const ways = new Array(tail.length + 1);
  ways[tail.length] = [[]];
  for (let i = tail.length - 1; i >= 0; i--) {
    const found = [];
    outer: for (const token of tokens) {
      if (!tail.startsWith(token, i)) continue;
      for (const rest of ways[i + token.length]) {
        found.push([token, ...rest]);
        if (found.length === 2) break outer;
      }
    }
    ways[i] = found;
  }
  return ways[0];
}

function baseSeries(index, requestedText, k) {
  const candidates = [];
  for (const product of index.products) {
    if (k.length <= product.key.length || !k.startsWith(product.key)) continue;
    const tokens = index.suffixTokens.get(product.family) ?? [];
    if (tokens.length === 0) continue;
    const found = splits(k.slice(product.key.length), tokens);
    if (found.length > 0) candidates.push([product, found]);
  }
  if (candidates.length === 0) return result(requestedText, k, 'none', { reason: 'unknown' });
  const [product, found] = candidates[0];
  if (candidates.length > 1 || found.length > 1) return result(requestedText, k, 'none', { reason: 'ambiguous' });
  return result(requestedText, k, 'base_series',
    { product: product.id, designation: product.designation, listed: listedDesignations(product), suffix: found[0] });
}

/**
 * Classifies a requested designation. Precedence: exact (directly or through a
 * spelling) › unlisted variant › related › base series › none.
 */
export function match(index, raw) {
  const req = requested(raw);
  const k = key(req.text);
  if (req.tooLong) return result(req.text, k, 'none', { reason: 'too_long' });
  if (k === '') return result(req.text, k, 'none', { reason: 'empty' });
  const record = index.byKey.get(k);
  if (record?.kind === 'spelling') return resolve(req.text, k, record.target, record.spelling);
  if (record) return resolve(req.text, k, record, null);
  return baseSeries(index, req.text, k);
}
