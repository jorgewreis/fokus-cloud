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

// This is an explicit allowlist of the reviewed, non-publishing workflow, not
// a YAML parser or a heuristic based on a job name / presence of `exit 1`.
// Any semantic or textual change (except CRLF) requires review, including new
// jobs, credentials, conditions, permissive flags, and malformed YAML. Do not
// infer publication authorization from the presence of a source-sync command.
const blockedWorkflow = "name: Publication blocked - local reconstruction\n\"on\":\n  workflow_dispatch:\n    inputs:\n      baseline_sha:\n        description: Reviewed baseline full SHA (not publication permission)\n        required: true\n        type: string\npermissions:\n  contents: read\njobs:\n  publication-blocked:\n    runs-on: ubuntu-latest\n    steps:\n      - name: Fail closed before any credentials or deployment\n        run: |\n          echo 'Local reconstruction only. No publication authorization, final commit or approved CI evidence.'\n          echo 'Reviewed baseline: 26daac7c27d246eaef64bb3f0e59499376993f8f'\n          echo 'Recovery requires roll-forward or separately authorized validated backup restoration.'\n          exit 1\n";
const deployWorkflowPath = path.join(repositoryRoot, '.github/workflows/deploy.yml');
function assertPublicationBlocked(workflow) {
  assert.equal(workflow.replaceAll('\r\n', '\n'), blockedWorkflow,
    'Deployment contract changed: review publication policy before enabling any delivery test');
  return 'blocked';
}
async function deploymentMode(workflowPath = deployWorkflowPath) {
  return assertPublicationBlocked(await readFile(workflowPath, 'utf8'));
}

// Read the actual source-sync command, preserving filter order and rsync flags.
// Only remove SSH transport and replace its two endpoints with local fixtures.
// Fail closed if the workflow gains unfamiliar arguments rather than testing an
// approximation that no longer matches production.
async function deploymentArguments(runnerTemp, workflow) {
  workflow ??= await readFile(deployWorkflowPath, 'utf8');
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
  for (const page of ['/styles/index.html', '/styles/docs/index.html', '/styles/next/index.html',
    ...['projects', 'onboarding', 'records'].map(slug => `/styles/examples/${slug}.html`)]) {
    const html = await readFile(publicFile(page), 'utf8');
    const links = [...html.matchAll(/<link\b[^>]*href=["']([^"']+)["']/gi)]
      .map(match => new URL(match[1], origin + page).pathname);
    assert.ok(links.includes(PUBLISHED_CSS_PATH), `${page} must load the pinned published CSS`);
  }
  console.log(`Verified ${assets.length} Styles files and local dependencies, published CSS and MIT license hashes.`);
});

test('publication remains explicitly fail-closed independently of asset integrity', async () => {
  assert.equal(await deploymentMode(), 'blocked');
});

test('publication contract rejects missing, malformed and permissive workflows', async t => {
  const mutations = {
    empty: '',
    malformed: blockedWorkflow.replace('jobs:', 'jobs: ['),
    'wrong indentation': blockedWorkflow.replace('          exit 1', 'exit 1'),
    'successful exit': blockedWorkflow.replace('exit 1', 'exit 0'),
    'no failure': blockedWorkflow.replace('          exit 1\n', ''),
    'ignored failure': blockedWorkflow.replace('    runs-on:', '    continue-on-error: true\n    runs-on:'),
    'disabled guard': blockedWorkflow.replace('        run: |', '        if: false\n        run: |'),
    'publication flag': blockedWorkflow.replace('permissions:', 'env:\n  ALLOW_PUBLICATION: true\npermissions:'),
    'write permission': blockedWorkflow.replace('contents: read', 'contents: write'),
    'extra publishing job': blockedWorkflow + '  publish:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo publish\n',
    'sync step': blockedWorkflow + '      - name: Sync application source\n        run: rsync ./ remote:/\n',
  };
  for (const [name, workflow] of Object.entries(mutations)) {
    await t.test(name, () => assert.throws(() => assertPublicationBlocked(workflow),
      /Deployment contract changed/));
  }
  const directory = await mkdtemp(path.join(tmpdir(), 'styles-missing-workflow-'));
  try {
    await t.test('removed workflow', async () => {
      await assert.rejects(deploymentMode(path.join(directory, 'deploy.yml')), { code: 'ENOENT' });
    });
  } finally { await rm(directory, { recursive: true, force: true }); }
  assert.equal(assertPublicationBlocked(blockedWorkflow.replaceAll('\n', '\r\n')), 'blocked');
});

// Synthetic parser fixtures only: these are not an approved deploy workflow,
// do not enable a delivery mode and do not prove rsync execution or transfer.
test('retained rsync parser preserves filter order and rejects unknown arguments', async () => {
  const fixture = args => `      - name: Sync application source
        run: |
          rsync ${args} ./ "$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"
      - name: End
`;
  const flags = '-rlDzvc --delete-delay --filter="merge $RUNNER_TEMP/fokus-law-assets/protect.rules" --include="public/styles/assets/***" --exclude="vendor/" -e "ssh -p 22"';
  assert.deepEqual(await deploymentArguments('/fixture', fixture(flags)), [
    '-rlDzvc', '--delete-delay', '--filter', 'merge /fixture/fokus-law-assets/protect.rules',
    '--include', 'public/styles/assets/***', '--exclude', 'vendor/',
  ]);
  await assert.rejects(deploymentArguments('/fixture', fixture(flags + ' --unknown-publish')), /Review new deploy argument/);
  await assert.rejects(deploymentArguments('/fixture', fixture(flags.replace('--exclude="vendor/"', ''))), /vendor exclusion/);
  await assert.rejects(deploymentArguments('/fixture', fixture(flags.replace('$RUNNER_TEMP', '$UNKNOWN'))), /Unresolved deploy filter variable/);
  await assert.rejects(deploymentArguments('/fixture', blockedWorkflow), /application source sync/);
});

test('actual deploy rsync filters deliver Styles assets and still exclude vendor directories', async t => {
  if (await deploymentMode() === 'blocked') {
    t.skip('Publication is explicitly blocked; no approved source-sync command exists. No rsync transfer executed.');
    return;
  }
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
