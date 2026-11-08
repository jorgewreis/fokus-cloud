import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, lstatSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export const digest = body => createHash('sha256').update(body).digest('hex');
const roots = ['app/', 'resources/', 'public/', 'routes/', 'tests/', 'docs/'];
const denied = ['docs/02-governance/', 'docs/03-architecture/', 'docs/07-security/', 'docs/08-commercial/'];
export function safePath(path) {
  return typeof path === 'string' && /^[a-zA-Z0-9_./-]+$/.test(path)
    && !path.split('/').some(p => p === '..' || p.startsWith('.'))
    && roots.some(root => path.startsWith(root)) && !denied.some(root => path.startsWith(root))
    && !/(^|\/)(AGENTS(?:\.override)?\.md|SKILL\.md)$/.test(path)
    && !/\.(pem|key)$/.test(path);
}
export function contract(body) {
  const blocks = [...body.matchAll(/```json\s*\n([\s\S]*?)\n```/g)];
  if (blocks.length !== 1) throw new Error('Exigir exatamente um contrato JSON.');
  const task = JSON.parse(blocks[0][1]);
  if (task.product !== 'cloud') throw new Error('O piloto aceita somente o produto cloud.');
  if (!Array.isArray(task.allowedPaths) || !task.allowedPaths.length || task.allowedPaths.length > 30
    || !task.allowedPaths.every(safePath)) throw new Error('Caminhos fora da política do piloto.');
  if (!Array.isArray(task.acceptance) || !task.acceptance.length
    || !task.acceptance.every(s => typeof s === 'string' && s.trim().length > 5)) throw new Error('Critérios de aceite ausentes.');
  if (!Array.isArray(task.dependencies) || !task.dependencies.every(n => Number.isSafeInteger(n) && n > 0)) throw new Error('Dependências inválidas.');
  return task;
}
export function approved(body, comments, owner) {
  const commands = comments.filter(c => c.user?.login === owner &&
    (/^\/julia approve [a-f0-9]{64}$/.test(c.body.trim()) || c.body.trim() === '/julia revoke'));
  commands.sort((a, b) => a.id - b.id);
  return commands.at(-1)?.body.trim() === `/julia approve ${digest(body)}`;
}
export function checkChanges(paths, task) {
  if (paths.length > 200) throw new Error('Limite de 200 arquivos excedido.');
  for (const path of paths) {
    if (path === '.julia-checkpoint.md') continue;
    if (!safePath(path) || !task.allowedPaths.some(p => path === p || (p.endsWith('/') && path.startsWith(p)))) {
      throw new Error(`Alteração fora do escopo: ${path}`);
    }
  }
}
export function requestedIssue(eventName, event, actor, owner, ref, manualIssue = '') {
  if (actor !== owner || ref !== 'refs/heads/main') throw new Error('Execução restrita ao proprietário na main.');
  if (eventName === 'workflow_dispatch') return manualIssue;
  if (eventName !== 'issue_comment' || event.action !== 'created'
    || event.comment?.body !== '/julia run' || event.issue?.pull_request
    || !Number.isSafeInteger(event.issue?.number) || event.issue.number < 1) {
    throw new Error('Comando de execução inválido.');
  }
  return String(event.issue.number);
}
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] }).trim();
async function api(path, options = {}) {
  const token = process.env.GH_TOKEN;
  if (!token) throw new Error('GH_TOKEN ausente.');
  const response = await fetch(`https://api.github.com/repos/${process.env.GITHUB_REPOSITORY}/${path}`, {
    ...options, headers: { Authorization: `Bearer ${token}`, Accept: 'application/vnd.github+json', 'Content-Type': 'application/json',
      'X-GitHub-Api-Version': '2022-11-28', ...options.headers },
  });
  if (!response.ok) throw new Error(`GitHub ${response.status} em ${path.split('?')[0]}`);
  return response.status === 204 ? null : response.json();
}
async function pages(path) {
  const all = [];
  for (let page = 1; page <= 100; page++) {
    const items = await api(`${path}${path.includes('?') ? '&' : '?'}per_page=100&page=${page}`);
    all.push(...items);
    if (items.length < 100) return all;
  }
  throw new Error('Paginação excedida; recusar seleção incompleta.');
}
const output = (key, value) => {
  if (process.env.GITHUB_OUTPUT) writeFileSync(process.env.GITHUB_OUTPUT, `${key}=${value}\n`, { flag: 'a' });
};
async function authorized(issue) {
  const owner = process.env.GITHUB_REPOSITORY.split('/')[0];
  if (issue.state !== 'open' || issue.pull_request || issue.body?.length > 30000) return false;
  return approved(issue.body ?? '', await pages(`issues/${issue.number}/comments`), owner);
}
async function prepare() {
  const owner = process.env.GITHUB_REPOSITORY.split('/')[0];
  const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
  const requested = requestedIssue(process.env.GITHUB_EVENT_NAME, event, process.env.GITHUB_ACTOR,
    owner, process.env.GITHUB_REF, process.env.JULIA_ISSUE);
  if (requested && !/^\d+$/.test(requested)) throw new Error('Número de issue inválido.');
  const issues = requested ? [await api(`issues/${requested}`)] : await pages('issues?state=open&sort=created&direction=asc');
  for (const issue of issues) {
    if (!await authorized(issue)) continue;
    let task;
    try { task = contract(issue.body); } catch (error) { console.log(`Issue #${issue.number}: ${error.message}`); continue; }
    const deps = await Promise.all(task.dependencies.map(n => api(`issues/${n}`)));
    if (deps.some(d => d.state !== 'closed' || d.pull_request)) continue;
    const branch = `julia/issue-${issue.number}`;
    const prs = await api(`pulls?state=open&head=${owner}:${branch}`);
    if (prs.some(pr => !pr.draft)) continue; // Aguardar revisão; não reabrir trabalho entregue.
    if (!requested && prs.some(pr => /<!-- julia-state:(ready|blocked) -->/.test(pr.body ?? ''))) continue;
    if (!requested && (await pages(`issues/${issue.number}/comments`)).some(c =>
      c.user?.login === 'github-actions[bot]' && c.body.startsWith(`<!-- julia-blocked:${digest(issue.body)} -->`))) continue;
    const main = await api('git/ref/heads/main');
    let head = main.object.sha;
    if (prs.length) head = prs[0].head.sha;
    // Branch sem PR pode ser publicação interrompida; nunca sobrescrever.
    if (!prs.length) {
      const refs = await api(`git/matching-refs/heads/${branch}`);
      if (refs.some(r => r.ref === `refs/heads/${branch}`)) {
        console.log(`Issue #${issue.number}: branch sem PR; reconciliar manualmente.`); continue;
      }
    }
    const record = { number: issue.number, title: issue.title, body: issue.body, hash: digest(issue.body),
      task, branch, mainSha: main.object.sha, headSha: head, pr: prs[0]?.number ?? null };
    mkdirSync('julia-input', { recursive: true });
    writeFileSync('julia-input/task.json', JSON.stringify(record, null, 2));
    output('selected', 'true'); output('head', head); output('main', main.object.sha); output('number', issue.number);
    console.log(`Selecionada issue #${issue.number}${record.pr ? ' para retomada' : ''}.`);
    return;
  }
  output('selected', 'false'); console.log('Nenhuma tarefa autorizada e disponível.');
}
export function bundle(input, destination, baseline) {
  const record = JSON.parse(readFileSync(input, 'utf8'));
  const task = contract(record.body);
  // Capturar também arquivos novos, sem incluir ignorados/dependências/credenciais.
  const untracked = git('ls-files', '--others', '--exclude-standard', '-z').split('\0').filter(Boolean);
  // Este repositório ignora tests/ globalmente; não perder testes novos autorizados.
  const ignoredTests = git('ls-files', '--others', '--ignored', '--exclude-standard', '-z', '--', 'tests/').split('\0').filter(Boolean);
  baseline ??= record.headSha;
  const modified = git('diff', '--name-only', baseline, '-z').split('\0').filter(Boolean);
  const paths = [...new Set([...untracked, ...ignoredTests, ...modified])];
  const accepted = [], rejected = [];
  for (const path of paths) {
    try {
      checkChanges([path], task);
      if (existsSync(path) && lstatSync(path).isSymbolicLink()) throw new Error('Symlink recusado');
      accepted.push(path);
    } catch { rejected.push(path); }
  }
  mkdirSync(destination, { recursive: true });
  writeFileSync(resolve(destination, 'scope-report.json'), JSON.stringify({ accepted, rejected }, null, 2));
  if (existsSync('.julia-checkpoint.md') && !lstatSync('.julia-checkpoint.md').isSymbolicLink()) {
    writeFileSync(resolve(destination, 'checkpoint.md'), readFileSync('.julia-checkpoint.md'));
  }
  checkChanges(accepted, task);
  // Esvaziar somente o index do runner efêmero; alterações fora de escopo não são publicadas.
  git('reset', baseline, '--', '.');
  if (accepted.length) git('add', '-f', '--', ...accepted);
  const patch = execFileSync('git', ['diff', '--cached', '--binary', baseline], { maxBuffer: 2 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] });
  writeFileSync(resolve(destination, 'change.patch'), patch);
}
export async function publish(input, patchFile) {
  const record = JSON.parse(readFileSync(input, 'utf8'));
  const issue = await api(`issues/${record.number}`);
  if (!await authorized(issue) || digest(issue.body) !== record.hash) throw new Error('Autorização revogada ou tarefa alterada.');
  const main = await api('git/ref/heads/main');
  if (main.object.sha !== record.mainSha) throw new Error('Main avançou durante a execução; preservar artefato e reconciliar.');
  if (record.pr) {
    const pr = await api(`pulls/${record.pr}`);
    if (pr.state !== 'open' || !pr.draft || pr.head.sha !== record.headSha) throw new Error('PR alterado durante a execução.');
  }
  const patch = readFileSync(patchFile);
  if (!patch.length) {
    throw new Error('Sem alterações a publicar; consultar relatório do agente. O job de falha registrará o bloqueio.');
  }
  if (patch.length > 2 * 1024 * 1024) throw new Error('Patch excede 2 MiB.');
  git('config', 'user.name', 'Julia Fokus'); git('config', 'user.email', 'julia@users.noreply.github.com');
  // Atualizar a base preservando a ancestralidade da branch existente; nunca rebase/force.
  git('-c', 'core.hooksPath=/dev/null', 'merge', '--no-edit', record.mainSha);
  git('apply', '--check', '--index', patchFile);
  git('apply', '--index', patchFile);
  const paths = git('diff', '--cached', '--name-only', '-z').split('\0').filter(Boolean);
  checkChanges(paths, contract(issue.body));
  checkChanges(git('diff', '--cached', '--name-only', record.mainSha, '-z').split('\0').filter(Boolean), contract(issue.body));
  const modes = git('diff', '--cached', '--raw');
  if (/\b(120000|160000)\b/.test(modes)) throw new Error('Symlink/submódulo recusado.');
  // Não executar código da proposta no job que possui token de escrita.
  git('-c', 'core.hooksPath=/dev/null', 'commit', '-m', `chore: checkpoint Julia issue #${record.number}`);
  git('push', 'origin', `HEAD:refs/heads/${record.branch}`); // Sem force; conflitos preservam o trabalho alheio.
  const run = `https://github.com/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}`;
  const checkpoint = existsSync('.julia-checkpoint.md') ? readFileSync('.julia-checkpoint.md', 'utf8') : '';
  const scopeReport = resolve(patchFile, '..', 'scope-report.json');
  const rejected = existsSync(scopeReport) ? JSON.parse(readFileSync(scopeReport, 'utf8')).rejected : [];
  const outcomeFile = resolve(patchFile, '..', 'agent-outcome.txt');
  const agentFailed = existsSync(outcomeFile) && readFileSync(outcomeFile, 'utf8').trim() !== 'success';
  const state = rejected.length || agentFailed ? 'blocked' : checkpoint.match(/^Julia-State: (ready|blocked|in_progress)$/m)?.[1] ?? 'blocked';
  const body = `<!-- julia-state:${state} -->\nImplementação autônoma referente à #${record.number}.\n\nContrato autorizado: \`${record.hash}\`.\n\nEstado declarado pelo agente: ${state}; este PR permanece em rascunho até revisão e checks independentes.\n\n[Execução e artefatos](${run}).\n\nNão houve merge nem deploy. A issue permanece aberta até integração e aceite.`;
  if (record.pr) await api(`pulls/${record.pr}`, { method: 'PATCH', body: JSON.stringify({ body }) });
  else await api('pulls', { method: 'POST', body: JSON.stringify({ title: `[Julia] ${record.title}`.slice(0, 240),
    head: record.branch, base: 'main', draft: true, body }) });
  await api('actions/workflows/julia-delivery-checks.yml/dispatches', {
    method: 'POST', body: JSON.stringify({ ref: record.branch }),
  });
  console.log(`Checkpoint publicado em ${record.branch}; aguardando revisão.`);
}
async function block(input) {
  const record = JSON.parse(readFileSync(input, 'utf8'));
  const issue = await api(`issues/${record.number}`);
  if (!await authorized(issue) || digest(issue.body) !== record.hash) return;
  const url = `https://github.com/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}`;
  await api(`issues/${record.number}/comments`, { method: 'POST', body: JSON.stringify({
    body: `<!-- julia-blocked:${record.hash} -->\n\nExecução bloqueada por falha de infraestrutura, preparação ou publicação. [Consultar execução e artefatos](${url}). Nenhuma conclusão funcional foi presumida. Retomar explicitamente pelo número da issue após corrigir a causa.`,
  }) });
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [command, ...args] = process.argv.slice(2);
  try {
    if (command === 'digest') console.log(digest(readFileSync(args[0], 'utf8')));
    else if (command === 'prepare') await prepare();
    else if (command === 'bundle') bundle(...args);
    else if (command === 'publish') await publish(...args);
    else if (command === 'block') await block(...args);
    else throw new Error('Comando esperado: digest, prepare, bundle ou publish.');
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}
