# Designation normalisation and matching: behavioural specification v1

Status: Phase 3 step 3, **approved by the owner 2026-10-06** with one refinement (fullwidth digits, §2 step 1).

**Frozen at that review:**
- the 64-code-point requested-designation limit;
- English-only (ASCII) case folding;
- no folding of look-alike slashes into `/`;
- invisible characters kept in the stored verbatim requested designation, and the approved invisible marks removed only from the matching key;
- V-1–V-5 unresolved as documented.

- Sources: `05` §9, §10, §17, §25; D20; E-2 (refined); E-19; E-21; the T-1 correction.
- **Executable form:** [`tests/fixtures/designation/corpus.json`](../tests/fixtures/designation/corpus.json). The PHP implementation (`app/src/Designation/`) and the JavaScript implementation (`assets-src/js/designation.js`) must both pass every vector in it.
- If this text and the corpus disagree, that is a defect. Fix both together.
- **Authority:** the server (PHP) is authoritative (E-21). The JavaScript copy exists so the explorer can respond instantly, and the server always recomputes the match.

## 1. Requested designation (kept verbatim)

`requested(raw)` gives the designation exactly as the visitor typed it, with only two changes:
- **Trimmed:** leading and trailing whitespace removed (the set in §2.6a).
- **Length-limited:** at most **64 code points**. Longer input is cut to 64 and flagged `too_long`.

Nothing else is rewritten:
- letter case, inner spacing, Persian digits and invisible marks are kept;
- the display layer isolates the text (`<bdi>` / LRI…PDI).

This text travels unchanged through the explorer, the workspace, `?r=`, the inquiry form, the WhatsApp text and the server record.

## 2. Normalised key

`key(text)` applies these steps in order. It is applied to `requested(raw)`, and to every catalogue designation, spelling and suffix token.

1. **Digits.** Persian digits U+06F0–06F9, Arabic-Indic digits U+0660–0669 and fullwidth digits U+FF10–FF19 (０–９) become ASCII `0`–`9`. This is character folding only: it adds no suffix, relationship or slash inference.
2. **Arabic letter forms.** ي (U+064A) becomes ی (U+06CC); ك (U+0643) becomes ک (U+06A9).
3. **Invisible marks removed:**
   - U+00AD soft hyphen;
   - U+061C Arabic letter mark;
   - U+200B–200F (zero-width space, ZWNJ, ZWJ, LRM, RLM);
   - U+202A–202E (bidi embeddings and overrides);
   - U+2060 word joiner;
   - U+2066–2069 (bidi isolates);
   - U+FEFF.
4. **Lower case.** ASCII `A`–`Z` only, so PHP and JavaScript behave identically. Other letters keep their case.
5. **×** (U+00D7) becomes `x`.
6. **Separators removed:**
   - a. Whitespace: U+0009–000D, U+0020, U+0085, U+00A0, U+1680, U+2000–200A, U+2028, U+2029, U+202F, U+205F, U+3000.
   - b. Hyphens and dashes: U+002D, U+2010–2015, U+2212, U+FE58, U+FE63, U+FF0D.
   - c. Underscore U+005F and full stop U+002E.

**Kept as they are:**
- `/` (U+002F) stays significant (E-19, V-3): `63/22` and `6322` are different keys.
- Look-alike slashes (U+2044, U+2215, U+FF0F) are **not** folded into `/`.
- All other punctuation (`*`, `+`, `(`, …), fullwidth Latin letters, the fullwidth solidus and non-ASCII letters are kept.

In PHP, invalid UTF-8 input is rejected with an exception and is never matched.

## 3. The designation index

The matcher reads an index of the **published** catalogue: what the Publish Engine emits and the inquiry endpoint loads. It never reads drafts.

| Element | Fields |
| --- | --- |
| Product | `id`, `designation`, `family`, `base_listed`, `variants[]` |
| Variant | `id`, `designation`, `listed` |
| Spelling | `spelling`, `target` (the id of one Product or variant) |
| `suffix_rules` | family → list of **Owner-approved** suffix tokens |

Building the index enforces the single designation-key namespace. These are errors:
- `duplicate_key`: two products, variants or spellings share one key. This includes a spelling that normalises to its own target's key, which is redundant.
- `duplicate_id`: two records share an id.
- `empty_key` and `empty_token`: a designation, spelling or token normalises to nothing.
- `unknown_target`: a spelling's target does not exist.
- `invalid_index`: a malformed shape.

A relationship exists **only** as a variant record or a spelling record.
- Nothing is derived from the text of a designation.
- An unresolved designation such as "22220 E" is a standalone Product (`05` §9). The matcher claims no relationship for it.
- No suffix rules are approved today (V-1), so the production `suffix_rules` is empty.

## 4. Match categories and precedence

The input is `raw`. Let `r = requested(raw)` and `k = key(r)`.

| # | Condition | Result `type` | Notes |
| --- | --- | --- | --- |
| 0 | `r` was cut | `none`, reason `too_long` | never matched on a truncated text |
| 0 | `k` is empty | `none`, reason `empty` | |
| 1 | `k` = a Product key, and `base_listed` is on | `exact_product` | |
| 2 | `k` = a variant key, and `listed` is on | `exact_variant` | |
| 3 | `k` = a spelling key | the target's type (1, 2, 4 or 5), with `via: "spelling"` | `spelling` holds the recorded spelling |
| 4 | `k` = a variant key, and `listed` is off | `unlisted_variant` | |
| 5 | `k` = a Product key, and `base_listed` is off | `related` | `listed` names the listed variants (may be empty); T-1 wording |
| 6 | `k` = a Product key + a non-empty tail made entirely of that Product family's approved tokens | `base_series` | `suffix` gives the tokens; `listed` gives the series' listed items |
| 7 | anything else | `none`, reason `unknown` | |

**Precedence.** Exact (directly or through a spelling) › unlisted variant › related › base series › none.
- Rows 1–5 are lookups of the one key namespace, so at most one can apply.
- Row 6 is tried only when the lookup misses, so a key that is a record never becomes `base_series`.

**Ambiguity (row 6).** The result is `none` with reason `ambiguous`, never a guess, when either:
- more than one Product could be the base; or
- one Product's tail can be split into approved tokens in more than one way.

**Out of scope for the matcher:**
- partial-text results ("closest results"), which stay a separate explorer mode;
- extracting a code from free text such as "بلبرینگ 6205".

## 5. Result

Both implementations return the same fields:

| Field | Meaning |
| --- | --- |
| `requested` | §1, verbatim |
| `key` | §2 |
| `type` | one of `exact_product`, `exact_variant`, `unlisted_variant`, `related`, `base_series`, `none` |
| `via` | `"spelling"` or `null` |
| `spelling` | the recorded spelling, or `null` |
| `product` | id or `null` |
| `variant` | id or `null` |
| `designation` | the catalogue's writing of the matched record: the Product's for exact_product / related / base_series, the variant's for exact_variant / unlisted_variant, `null` for none |
| `listed` | the listed items' designations, for related and base_series only: the base first if `base_listed`, then the listed variants in catalogue order; empty otherwise |
| `suffix` | the approved tokens, normalised, for base_series only; empty otherwise |
| `reason` | `empty`, `too_long`, `unknown` or `ambiguous` for none; `null` otherwise |

## 6. Deliberate differences from the Phase 2 prototype

These follow D20/E-2. The frozen prototype is unchanged.

| Case | Prototype | Production |
| --- | --- | --- |
| `/` | removed | kept (E-19) |
| A listed variant (6205-2RS) | "variant", needing confirmation | `exact_variant`, because a variant has its own identity and values (D20) |
| Unlisted suffix (6205-2RSH, 30208 J2) | `series`, guessed by a regex | `base_series` only through an Owner-approved token rule; today `none` (V-1) |
| `related` (T-1) | derived from text (6305 → 6305-2RS) | only when Product 6305 exists with `base_listed` off and 6305-2RS is recorded as its variant. With the sample as recorded (6305-2RS and 22220 E standalone), `6305` and `22220` match nothing. The corrected T-1 wording and direction are preserved for the recorded case. |
| Requested text | upper-cased, digits converted, spaces collapsed | verbatim (§1) |
