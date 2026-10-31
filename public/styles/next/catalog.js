const $ = selector => document.querySelector(selector);
const list = $('#catalog-list'), detail = $('#catalog-detail'), status = $('#catalog-status');
let catalog;
let focusSelection = false;
const element = (tag, text, parent) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; parent?.append(node); return node; };
const searchable = contract => JSON.stringify(contract).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
function codeBlock(parent, title, code, context = '') {
  const section = element('details', undefined, parent);
  element('summary', title, section);
  const pre = element('pre', undefined, section); pre.tabIndex = 0;
  element('code', code, pre);
  const button = element('button', 'Copiar código', section); button.type = 'button'; button.className = 'fs-btn';
  button.setAttribute('aria-label', `Copiar código: ${title}${context ? ' — ' + context : ''} — ${detail.querySelector('h2')?.textContent || 'catálogo'}`);
  const feedback = element('p', '', section); feedback.setAttribute('role', 'status');
  button.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(code); feedback.textContent = 'Código original copiado.'; }
    catch { const range = document.createRange(); range.selectNodeContents(pre); window.getSelection().removeAllRanges(); window.getSelection().addRange(range); feedback.textContent = 'Selecione e copie o código manualmente.'; }
  });
}
const escapeHtml = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');
function previewDocument(example) {
  const origin = location.origin;
  // Only reviewed example fixtures are executable. Historical snippets remain text.
  const js = (example.jsCode || '').replace(/import\s*\{([^}]+)\}\s*from\s*['"]fokus-styles\/js['"];?/g, 'const {$1} = FokusStyles;');
  const csp = `default-src 'none'; script-src 'unsafe-inline' ${origin}; style-src 'unsafe-inline' ${origin}; img-src data: blob: ${origin}; font-src ${origin}; connect-src 'none'; form-action 'none'; base-uri 'none'`;
  return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="${escapeHtml(csp)}"><link rel="stylesheet" href="${origin}/styles/next/fokus.css"><style>body{padding:24px;margin:0} ${example.cssCode || ''} .fs-tooltip-arrow,.fs-popover-arrow,.fs-dropdown-rich-chevron,.fs-tree-toggle,.fs-datatable-sort-btn::after,.fs-accordion-button::after{display:none!important;content:none!important}</style></head><body>${example.htmlCode || ''}<script src="${origin}/styles/next/fokus.js"></script><script>${js.replace(/<\/script/gi, '<\\/script')}</script></body></html>`;
}
function showContract() {
  if (!catalog) return;
  let id; try { id = decodeURIComponent(location.hash.slice(1)); } catch { id = ''; }
  const contract = catalog.contracts.find(item => item.id === id);
  detail.removeAttribute('aria-labelledby');
  detail.replaceChildren();
  list.querySelectorAll('a').forEach(link => { if (link.hash === '#' + encodeURIComponent(id)) link.setAttribute('aria-current', 'true'); else link.removeAttribute('aria-current'); });
  if (!contract) { element('p', id ? 'Contrato não encontrado. Escolha um item da lista.' : 'Escolha um contrato para consultar sua documentação.', detail); return; }
  const heading = element('h2', contract.title, detail);
  heading.id = 'catalog-detail-title'; heading.tabIndex = -1;
  detail.setAttribute('aria-labelledby', heading.id);
  if (focusSelection) { heading.focus(); focusSelection = false; }
  element('p', `${contract.group} · next / piloto · ${contract.status === 'partial' ? 'Cobertura parcial' : contract.status}`, detail);
  element('p', 'Não publicado. Evidência de implementação não significa cobertura completa de comportamento ou acessibilidade.', detail);
  const limitations = element('ul', undefined, detail);
  for (const limitation of contract.limitations || []) element('li', limitation, limitations);
  element('h3', 'Exemplos revisados', detail);
  if (!contract.examples?.length) element('p', 'Nenhuma demonstração executável revisada para este contrato. Consulte a documentação e os trechos abaixo.', detail);
  for (const example of contract.examples || []) {
    element('h4', example.title, detail);
    if (example.coverage) element('p', Array.isArray(example.coverage) ? example.coverage.join(' · ') : String(example.coverage), detail);
    const exampleLimitations = element('ul', undefined, detail);
    exampleLimitations.className = 'catalog-example-limitations';
    for (const limitation of example.limitations || []) element('li', limitation, exampleLimitations);
    if (contract.id === 'file-upload-advanced') element('p', 'Nesta integração, a prévia permite imagens blob: locais para miniaturas. O aviso de CSP acima é preservado da fonte; envio de arquivos e conexões de rede continuam bloqueados.', detail);
    const frame = element('iframe', undefined, detail); frame.title = `${contract.title}: ${example.title} · piloto isolado`; frame.setAttribute('sandbox', 'allow-scripts'); frame.referrerPolicy = 'no-referrer'; frame.srcdoc = previewDocument(example);
    const reset = element('button', 'Reiniciar exemplo', detail); reset.type = 'button'; reset.className = 'fs-btn'; reset.setAttribute('aria-label', `Reiniciar exemplo: ${example.title}`); reset.addEventListener('click', () => { frame.srcdoc = previewDocument(example); });
    codeBlock(detail, 'HTML original do exemplo', example.htmlCode || '', example.title);
    if (example.jsCode) codeBlock(detail, 'JavaScript original do exemplo', example.jsCode, example.title);
    if (example.cssCode) codeBlock(detail, 'CSS original do exemplo', example.cssCode, example.title);
  }
  element('h3', 'Documentação de origem', detail);
  element('p', contract.documentation.path, detail);
  codeBlock(detail, 'Ler documentação completa (Markdown)', contract.documentation.markdown);
  element('h3', 'Trechos documentais', detail);
  element('p', 'Transcrição fiel. Estes trechos históricos podem ser incompletos e não são executados automaticamente.', detail);
  for (const snippet of contract.snippets || []) codeBlock(detail, `${snippet.section || snippet.id} · ${snippet.language || 'texto'}`, snippet.code, snippet.id);
  element('h3', 'Evidências CSS', detail);
  codeBlock(detail, 'Seletores, condições e declarações', JSON.stringify(contract.cssEvidence, null, 2));
  codeBlock(detail, 'Evidência interna de runtime (não representa nova API pública)', JSON.stringify(contract.runtimeEvidence || [], null, 2));
  codeBlock(detail, 'Definições de tipos', typeof contract.typeDefinitions === 'string' ? contract.typeDefinitions : JSON.stringify(contract.typeDefinitions, null, 2));
  codeBlock(detail, 'Imports e arquivos de origem', JSON.stringify({ imports: contract.imports, sources: contract.sources }, null, 2));
}
function filter() {
  const query = normalize($('#catalog-search').value.trim()), group = $('#catalog-group').value;
  const matches = catalog.contracts.filter(item => (!group || item.group === group) && searchable(item).includes(query));
  list.replaceChildren();
  for (const item of matches) { const link = element('a', item.title, list); link.href = '#' + encodeURIComponent(item.id); if (location.hash === link.hash) link.setAttribute('aria-current', 'true'); }
  status.textContent = `${matches.length} de ${catalog.contracts.length} contratos · ${matches.reduce((total, item) => total + item.examples.length, 0)} exemplos selecionados · next / piloto.`;
  if (!matches.length) element('p', 'Nenhum resultado. Ajuste a busca ou limpe os filtros.', list);
}
async function load() {
  $('#catalog-retry').hidden = true; status.textContent = 'Carregando catálogo…';
  try {
    const response = await fetch('/styles/next/catalog.json'); if (!response.ok) throw new Error('Unavailable');
    const data = await response.json();
    if (data.schemaVersion !== 1 || data.channel !== 'next' || data.published !== false || !Array.isArray(data.contracts)) throw new Error('Invalid catalog');
    catalog = data;
    $('#provenance').textContent = `Origem: ${data.source.baselineCommit} · digest: ${data.source.digest}`;
    const groups = $('#catalog-group'); groups.replaceChildren(new Option('Todos os grupos', ''));
    for (const group of [...new Set(data.contracts.map(item => item.group))].sort()) groups.add(new Option(group, group));
    filter(); showContract();
  } catch { status.textContent = 'Não foi possível carregar o catálogo. Tente novamente ou baixe o JSON.'; $('#catalog-retry').hidden = false; }
}
list.addEventListener('click', event => {
  const link = event.target.closest('a');
  if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  if (location.hash === link.hash) { event.preventDefault(); detail.querySelector('h2')?.focus(); }
  else focusSelection = true;
});
$('#catalog-search').addEventListener('input', () => catalog && filter());
$('#catalog-group').addEventListener('change', () => catalog && filter());
$('#catalog-reset').addEventListener('click', () => { $('#catalog-search').value = ''; $('#catalog-group').value = ''; if (catalog) filter(); $('#catalog-search').focus(); });
$('#catalog-retry').addEventListener('click', load);
window.addEventListener('hashchange', showContract);
load();
