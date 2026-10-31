// Explicit, offline import of a reviewed Fokus Styles checkout. Never installs or publishes.
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
const root = resolve(import.meta.dirname, '..');
const source = process.argv[2];
if (!source) throw new Error('Usage: node tools/sync-styles-next.mjs /path/to/fokus-styles');
const files = { 'catalog.json': 'docs/generated/next/catalog.json', 'fokus.css': 'dist/css/fokus.css', 'fokus.js': 'dist/js/fokus.js' };
const buffers = Object.fromEntries(await Promise.all(Object.entries(files).map(async ([name, path]) => [name, await readFile(resolve(source, path))])));
const catalog = JSON.parse(buffers['catalog.json']);
if (catalog.schemaVersion !== 1 || catalog.channel !== 'next' || catalog.published !== false || catalog.contracts.length !== 56) throw new Error('Unexpected catalog schema/channel/count');
const target = resolve(root, 'public/styles/next'); await mkdir(target, { recursive: true });
for (const [name, bytes] of Object.entries(buffers)) await writeFile(resolve(target, name), bytes);
const provenance = { channel: 'next', published: false, source: catalog.source, files: Object.fromEntries(Object.entries(buffers).map(([name, bytes]) => [name, { source: files[name], sha256: createHash('sha256').update(bytes).digest('hex') }])) };
await writeFile(resolve(target, 'provenance.json'), JSON.stringify(provenance, null, 2) + '\n');
console.log('Copied 56 next contracts and local preview bundles; published assets unchanged.');
