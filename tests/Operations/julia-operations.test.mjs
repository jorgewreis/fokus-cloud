import test from 'node:test';
import assert from 'node:assert/strict';
import { digest, contract, approved, safePath, checkChanges, bundle, publish } from '../../tools/julia/operations.mjs';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';

const task = { product: 'cloud', allowedPaths: ['app/Services/', 'tests/Feature/'], acceptance: ['Isolar consultas por empresa'], dependencies: [] };
const body = `Objetivo\n\n\`\`\`json\n${JSON.stringify(task)}\n\`\`\``;
const comment = (id, text, login = 'jorgewreis') => ({ id, body: text, user: { login } });
test('autorizar somente o hash atual e a identidade do proprietário', () => {
  assert.equal(approved(body, [comment(1, `/julia approve ${digest(body)}`)], 'jorgewreis'), true);
  assert.equal(approved(body + ' editado', [comment(1, `/julia approve ${digest(body)}`)], 'jorgewreis'), false);
  assert.equal(approved(body, [comment(1, `/julia approve ${digest(body)}`, 'outsider')], 'jorgewreis'), false);
});
test('revogação e reautorização respeitam ordem dos comentários', () => {
  const comments = [comment(2, '/julia revoke'), comment(1, `/julia approve ${digest(body)}`)];
  assert.equal(approved(body, comments, 'jorgewreis'), false);
  comments.push(comment(3, `/julia approve ${digest(body)}`));
  assert.equal(approved(body, comments, 'jorgewreis'), true);
});
test('recusar escapes, governança, credenciais e caminhos não autorizados', () => {
  for (const path of ['app/../.env', '.github/workflows/deploy.yml', 'app/.env', 'docs/07-security/access.md', 'app/key.pem', 'app/AGENTS.md']) assert.equal(safePath(path), false, path);
  assert.doesNotThrow(() => checkChanges(['app/Services/Example.php', '.julia-checkpoint.md'], task));
  assert.throws(() => checkChanges(['app/ServicesOther/Example.php'], task));
  assert.throws(() => checkChanges(['routes/web.php'], task));
  assert.throws(() => checkChanges(Array(201).fill('app/Services/a.php'), task));
});
test('exigir contrato inequívoco, produto correto e critérios', () => {
  assert.deepEqual(contract(body), task);
  assert.throws(() => contract(body + '\n' + body));
  assert.throws(() => contract(body.replace('cloud', 'law')));
  assert.throws(() => contract(body.replace('app/Services/', '.github/')));
  assert.throws(() => contract('sem contrato'));
});

test('capturar teste novo ignorado e preservar trabalho autorizado ao rejeitar outro arquivo', () => {
  const oldCwd = process.cwd(), dir = mkdtempSync(join(tmpdir(), 'julia-bundle-'));
  const git = (...args) => execFileSync('git', args, { cwd: dir, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  try {
    git('init', '-q'); git('config', 'user.name', 'Test'); git('config', 'user.email', 'test@example.invalid');
    writeFileSync(join(dir, '.gitignore'), 'tests/\n'); git('add', '.gitignore'); git('commit', '-qm', 'base');
    mkdirSync(join(dir, 'tests/Feature'), { recursive: true });
    writeFileSync(join(dir, 'tests/Feature/NewTest.php'), '<?php // new test\n');
    writeFileSync(join(dir, 'outside.txt'), 'must not publish');
    writeFileSync(join(dir, '.julia-checkpoint.md'), 'Julia-State: in_progress\n');
    // Artefatos fora do checkout, como nos jobs reais.
    const external = mkdtempSync(join(tmpdir(), 'julia-input-'));
    try {
      const taskFile = join(external, 'task.json');
      writeFileSync(taskFile, JSON.stringify({ body, headSha: git('rev-parse', 'HEAD') }));
      process.chdir(dir); bundle(taskFile, join(external, 'out'));
      const patch = readFileSync(join(external, 'out/change.patch'), 'utf8');
      assert.match(patch, /NewTest.php/); assert.match(patch, /julia-checkpoint/); assert.doesNotMatch(patch, /outside.txt/);
      assert.deepEqual(JSON.parse(readFileSync(join(external, 'out/scope-report.json'))).rejected, ['outside.txt']);
      assert.match(readFileSync(join(external, 'out/checkpoint.md'), 'utf8'), /in_progress/);
    } finally { rmSync(external, { recursive: true, force: true }); }
  } finally { process.chdir(oldCwd); rmSync(dir, { recursive: true, force: true }); }
});

test('publicar com main avançada preserva ancestralidade e dispara checks explícitos', async () => {
  const oldCwd = process.cwd(), originalFetch = globalThis.fetch;
  const env = { ...process.env }, dir = mkdtempSync(join(tmpdir(), 'julia-publish-'));
  const remote = join(dir, 'remote.git'), work = join(dir, 'work');
  mkdirSync(work);
  const git = (...args) => execFileSync('git', args, { cwd: work, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  try {
    git('init', '-q', '--bare', remote); git('init', '-q', '-b', 'main');
    git('config', 'user.name', 'Test'); git('config', 'user.email', 'test@example.invalid');
    mkdirSync(join(work, 'app/Services'), { recursive: true });
    writeFileSync(join(work, 'app/Services/Base.php'), '<?php // base\n');
    git('add', '.'); git('commit', '-qm', 'base'); git('remote', 'add', 'origin', remote); git('push', '-q', 'origin', 'main');
    git('switch', '-qc', 'julia/issue-1');
    writeFileSync(join(work, '.julia-checkpoint.md'), 'Julia-State: in_progress\n');
    git('add', '.'); git('commit', '-qm', 'checkpoint'); const headSha = git('rev-parse', 'HEAD');
    git('push', '-q', 'origin', 'HEAD'); git('switch', '-q', 'main');
    writeFileSync(join(work, 'day.txt'), 'daytime change'); git('add', '.'); git('commit', '-qm', 'daytime');
    const mainSha = git('rev-parse', 'HEAD'); git('push', '-q', 'origin', 'main');
    git('switch', '-q', 'julia/issue-1'); git('merge', '--no-edit', mainSha);
    const baseline = git('rev-parse', 'HEAD');
    writeFileSync(join(work, 'app/Services/New.php'), '<?php // implementation\n');
    writeFileSync(join(work, '.julia-checkpoint.md'), 'Julia-State: ready\n');
    const record = { number: 1, title: 'Test', body, hash: digest(body), task, branch: 'julia/issue-1', mainSha, headSha, pr: 2 };
    const input = join(dir, 'task.json'), artifacts = join(dir, 'artifacts'); writeFileSync(input, JSON.stringify(record));
    process.chdir(work); bundle(input, artifacts, baseline);
    git('reset', '--hard', headSha); git('clean', '-fd');
    const calls = []; let revoked = true, mainMoved = false;
    process.env.GITHUB_REPOSITORY = 'jorgewreis/fokus-cloud'; process.env.GH_TOKEN = 'synthetic-not-a-credential';
    globalThis.fetch = async (url, options = {}) => {
      const path = url.replace('https://api.github.com/repos/jorgewreis/fokus-cloud/', ''); calls.push({ path, options });
      let data;
      if (path === 'issues/1') data = { number: 1, state: 'open', body };
      else if (path.startsWith('issues/1/comments')) data = revoked ? [comment(1, '/julia revoke')] : [comment(1, `/julia approve ${digest(body)}`)];
      else if (path === 'git/ref/heads/main') data = { object: { sha: mainMoved ? 'different-main' : mainSha } };
      else if (path === 'pulls/2') data = { state: 'open', draft: true, head: { sha: headSha } };
      else if (path.endsWith('/dispatches')) return { ok: true, status: 204 };
      else throw new Error(`Unexpected API ${path}`);
      return { ok: true, status: 200, json: async () => data };
    };
    await assert.rejects(publish(input, join(artifacts, 'change.patch')), /Autorização revogada/);
    assert.equal(git('rev-parse', 'HEAD'), headSha);
    revoked = false; mainMoved = true;
    await assert.rejects(publish(input, join(artifacts, 'change.patch')), /Main avançou/);
    assert.equal(git('rev-parse', 'HEAD'), headSha);
    mainMoved = false;
    await publish(input, join(artifacts, 'change.patch'));
    git('merge-base', '--is-ancestor', mainSha, 'HEAD'); git('merge-base', '--is-ancestor', headSha, 'HEAD');
    assert.equal(readFileSync(join(work, 'day.txt'), 'utf8'), 'daytime change');
    assert.equal(git('rev-parse', 'HEAD'), git('rev-parse', 'refs/remotes/origin/julia/issue-1'));
    assert.ok(calls.some(c => c.path.endsWith('/dispatches') && JSON.parse(c.options.body).ref === 'julia/issue-1'));
    assert.match(JSON.parse(calls.find(c => c.path === 'pulls/2' && c.options.method === 'PATCH').options.body).body, /julia-state:ready/);
  } finally {
    globalThis.fetch = originalFetch; process.env = env; process.chdir(oldCwd); rmSync(dir, { recursive: true, force: true });
  }
});
