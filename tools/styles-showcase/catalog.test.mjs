import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
const base = new URL('../../public/styles/next/', import.meta.url);
test('snapshot identity, provenance and distinct published assets', async () => {
 const catalog = JSON.parse(await readFile(new URL('catalog.json', base)));
 assert.equal(catalog.schemaVersion, 1); assert.equal(catalog.channel, 'next'); assert.equal(catalog.published, false);
 assert.equal(catalog.contracts.length, 56); assert.equal(new Set(catalog.contracts.map(c => c.id)).size, 56);
 assert.equal(catalog.contracts.reduce((total,c)=>total+c.examples.length,0),56);
 const provenance = JSON.parse(await readFile(new URL('provenance.json', base)));
 assert.deepEqual(provenance.source, catalog.source);
 for (const [file, value] of Object.entries(provenance.files)) assert.equal(createHash('sha256').update(await readFile(new URL(file, base))).digest('hex'), value.sha256);
 for (const contract of catalog.contracts) {
   assert.equal(contract.status, 'partial'); assert.equal(contract.examples.length, 1); assert.ok(contract.documentation.markdown);
   for (const snippet of contract.snippets) assert.equal(snippet.executable, false);
 }
 const main = await readFile(new URL('../index.html', base), 'utf8');
 assert.ok(main.includes('/styles/vendor/fokus-2.7.0.min.css')); assert.ok(main.includes('/styles/next/'));
});
