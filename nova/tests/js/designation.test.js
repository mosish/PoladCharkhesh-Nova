// Runs the shared behavioural corpus against the JavaScript copy of the
// designation matcher; tests/unit/Designation/CorpusTest.php runs the same
// vectors against PHP. Fixtures only: nothing here is production catalogue data.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildIndex, InvalidDesignationIndex, key, match, requested, REQUESTED_MAX_LENGTH } from '../../assets-src/js/designation.js';

const corpus = JSON.parse(readFileSync(new URL('../fixtures/designation/corpus.json', import.meta.url), 'utf8'));
const label = (i, c) => `#${i} ${JSON.stringify(c.input ?? c.note)}`;

test('normalise', async t => {
  for (const [i, c] of corpus.normalise.entries()) {
    await t.test(label(i, c), () => assert.equal(key(c.input), c.key));
  }
});

test('requested', async t => {
  for (const [i, c] of corpus.requested.entries()) {
    await t.test(label(i, c), () => assert.deepEqual(requested(c.input), { text: c.requested, tooLong: c.too_long }));
  }
});

test('match', async t => {
  const indexes = Object.fromEntries(Object.entries(corpus.catalogues).map(([name, cat]) => [name, buildIndex(cat.index)]));
  for (const [i, c] of corpus.match.entries()) {
    await t.test(`${c.catalogue} ${label(i, c)}`, () => assert.deepEqual(match(indexes[c.catalogue], c.input), c.expect));
  }
});

test('invalid index', async t => {
  for (const [i, c] of corpus.invalid_index.entries()) {
    await t.test(label(i, c), () => assert.throws(() => buildIndex(c.index), e => e instanceof InvalidDesignationIndex && e.error === c.error));
  }
});

test('requested length limit matches the corpus', () => assert.equal(REQUESTED_MAX_LENGTH, corpus.requested_max_length));
