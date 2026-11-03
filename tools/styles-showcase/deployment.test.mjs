import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { cp, mkdir, mkdtemp, readFile, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { rsyncProtectRules } from '../generate-fokus-law-assets.mjs';
import { PUBLISHED_ASSETS, PUBLISHED_CSS_PATH } from './published-assets.mjs';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const publicRoot = path.join(repositoryRoot, 'public');
const origin = 'https://styles.test';
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const publicFile = url => path.join(publicRoot, url.replace(/^\//, ''));

async function filesUnder(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  return (await Promise.all(entries.map(entry => entry.isDirectory()
    ? filesUnder(path.join(directory, entry.name)) : [path.join(directory, entry.name)]))).flat();
}

// Include all Styles documents, downloadable examples, next catalog/bundles and
// their local HTML/CSS dependencies (including fonts). Do not maintain a second
// hand-written asset list that could silently diverge from the page references.
async function requiredAssets() {
  const required = new Set((await filesUnder(path.join(publicRoot, 'styles')))
    .map(file => '/' + path.relative(publicRoot, file).split(path.sep).join('/')));
  for (const asset of required) {
    const extension = path.extname(asset);
    if (!['.html', '.css'].includes(extension)) continue;
    const contents = await readFile(publicFile(asset), 'utf8');
    const references = extension === '.html'
      ? [...contents.matchAll(/<(?:script|link|iframe|img|source)\b[^>]*?\b(?:src|href)=["']([^"']+)["']/gi)].map(match => match[1])
      : [...contents.matchAll(/url\(\s*["']?([^"')\s]+)["']?\s*\)|@import\s+["']([^"']+)["']/gi)].map(match => match[1] || match[2]);
    for (const reference of references) {
      const url = new URL(reference, origin + asset);
      if (url.origin !== origin) continue;
      const pathname = decodeURIComponent(url.pathname);
      assert.ok(!pathname.split('/').includes('vendor'), `${asset} references a deploy-excluded directory: ${reference}`);
      assert.ok((await stat(publicFile(pathname))).isFile(), `${asset} dependency must exist: ${reference}`);
      required.add(pathname);
    }
  }
  return [...required].sort();
}

// Read the actual source-sync command, preserving filter order and rsync flags.
// Only remove SSH transport and replace its two endpoints with local fixtures.
// Fail closed if the workflow gains unfamiliar arguments rather than testing an
// approximation that no longer matches production.
async function deploymentArguments(runnerTemp) {
  const workflow = await readFile(path.join(repositoryRoot, '.github/workflows/deploy.yml'), 'utf8');
  const step = workflow.split('      - name: Sync application source\n')[1]?.split('\n      - name: ')[0];
  assert.ok(step, 'The deploy workflow must contain its application source sync');
  const command = step.match(/^          rsync (?:[^\n]*\\\n)*[^\n]*/m)?.[0].trim();
  assert.ok(command, 'The source sync must use rsync');
  const words = command.replace(/\\\n\s*/g, ' ').match(/(?:[^\s"']+|"[^"]*"|'[^']*')+/g)
    .map(word => word.replace(/["']/g, ''));
  assert.equal(words.shift(), 'rsync');
  assert.equal(words.at(-2), './');
  assert.equal(words.at(-1), '$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/');
  words.splice(-2);
  const args = [];
  for (let index = 0; index < words.length; index++) {
    const word = words[index];
    if (word === '-e') { assert.match(words[++index], /^ssh /); continue; }
    if (/^-[rlDzvc]+$/.test(word) || word === '--delete-delay') { args.push(word); continue; }
    const filter = word.match(/^(--(?:filter|exclude|include))(?:=(.*))?$/);
    assert.ok(filter, `Review new deploy argument before changing this test: ${word}`);
    const value = (filter[2] ?? words[++index]).replaceAll('$RUNNER_TEMP', runnerTemp);
    assert.ok(!value.includes('$'), `Unresolved deploy filter variable: ${value}`);
    args.push(filter[1], value);
  }
  assert.ok(args.some((arg, index) => arg === '--exclude' && args[index + 1] === 'vendor/'),
    'The global PHP vendor exclusion must remain intact');
  return args;
}

test('Styles uses the unchanged published CSS/license and existing deployable dependencies', async () => {
  for (const [asset, sha256] of Object.entries(PUBLISHED_ASSETS)) {
    assert.equal(digest(await readFile(publicFile(asset))), sha256, `${asset}: published bytes changed`);
  }
  const assets = await requiredAssets();
  for (const page of ['/styles/index.html', '/styles/next/index.html',
    ...['projects', 'onboarding', 'records'].map(slug => `/styles/examples/${slug}.html`)]) {
    const html = await readFile(publicFile(page), 'utf8');
    const links = [...html.matchAll(/<link\b[^>]*href=["']([^"']+)["']/gi)]
      .map(match => new URL(match[1], origin + page).pathname);
    assert.ok(links.includes(PUBLISHED_CSS_PATH), `${page} must load the pinned published CSS`);
  }
  await deploymentArguments('/tmp/styles-deploy-fixture');
  console.log(`Verified ${assets.length} Styles files and local dependencies, published CSS and MIT license hashes.`);
});

test('actual deploy rsync filters deliver Styles assets and still exclude vendor directories', async t => {
  const version = spawnSync('rsync', ['--version'], { encoding: 'utf8' });
  if (version.error?.code === 'ENOENT' && !process.env.CI) {
    t.skip('rsync is unavailable locally; required in CI. Static/hash contracts are tested separately.');
    return;
  }
  assert.equal(version.status, 0, `rsync is required for this deployment test: ${version.error || version.stderr}`);
  const temporaryRoot = await mkdtemp(path.join(tmpdir(), 'styles-delivery-test-'));
  try {
    const source = path.join(temporaryRoot, 'source');
    const destination = path.join(temporaryRoot, 'destination');
    const runnerTemp = path.join(temporaryRoot, 'runner');
    await mkdir(path.join(runnerTemp, 'fokus-law-assets'), { recursive: true });
    await writeFile(path.join(runnerTemp, 'fokus-law-assets/protect.rules'), rsyncProtectRules());
    await mkdir(source);
    await mkdir(destination);
    await cp(publicRoot, path.join(source, 'public'), { recursive: true });
    const blocked = ['vendor/autoload.php', 'public/styles/vendor/fokus-2.7.0.min.css',
      'public/styles/vendor/LICENSE-fokus-styles.txt', 'node_modules/fixture.js', '.env'];
    for (const relative of blocked) {
      const file = path.join(source, relative);
      await mkdir(path.dirname(file), { recursive: true });
      await writeFile(file, 'This fixture must not be deployed.\n');
    }
    await writeFile(path.join(destination, 'obsolete-file.txt'), 'Must be deleted.\n');
    const sync = spawnSync('rsync', [...await deploymentArguments(runnerTemp), source + '/', destination + '/'], { encoding: 'utf8' });
    assert.equal(sync.status, 0, sync.error?.message || sync.stderr || sync.stdout);
    const assets = await requiredAssets();
    for (const asset of assets) {
      assert.equal(digest(await readFile(path.join(destination, 'public', asset))),
        digest(await readFile(publicFile(asset))), `Deploy must preserve ${asset}`);
    }
    for (const relative of [...blocked, 'obsolete-file.txt']) {
      await assert.rejects(stat(path.join(destination, relative)), { code: 'ENOENT' }, `${relative} must not be deployed`);
    }
    for (const [asset, sha256] of Object.entries(PUBLISHED_ASSETS)) {
      assert.equal(digest(await readFile(path.join(destination, 'public', asset))), sha256);
    }
    console.log(`Verified deployed bytes for ${assets.length} Styles files using the actual workflow filters.`);
  } finally {
    await rm(temporaryRoot, { recursive: true, force: true });
  }
});
