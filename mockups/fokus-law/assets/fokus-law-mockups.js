(() => {
  const processes = [
    { id: '0001842-19.2024.8.05.0001', client: 'João Pedro Almeida', opposing: 'Ministério Público do Estado da Bahia', court: 'TJBA · 1ª Vara Criminal de Salvador', owner: 'Marina Costa', phase: 'Instrução', due: 'Vencido há 2 dias', dueKind: 'overdue', priority: 'Urgente', status: 'Aguardando audiência', statusKind: 'warning', movement: 'Juntada de resposta à acusação', movementAt: '22 set · 14:32', className: 'Ação penal · Procedimento ordinário', sigiloso: true },
    { id: '8007316-42.2025.8.05.0001', client: 'Ana Luiza Santos', opposing: 'Ministério Público do Estado da Bahia', court: 'TJBA · 1ª Vara Criminal de Salvador', owner: 'Rafael Nunes', phase: 'Concluso', due: 'Hoje · 16h', dueKind: 'today', priority: 'Alta', status: 'Concluso para decisão', statusKind: 'primary', movement: 'Conclusão para despacho', movementAt: '24 set · 09:18', className: 'Inquérito policial', sigiloso: true },
    { id: '0010239-77.2023.8.05.0001', client: 'Carlos Eduardo Lima', opposing: 'Defensoria Pública do Estado da Bahia', court: 'TJBA · 1ª Vara Criminal de Salvador', owner: 'Marina Costa', phase: 'Sentença', due: 'Amanhã · 12h', dueKind: 'soon', priority: 'Alta', status: 'Prazo em andamento', statusKind: 'warning', movement: 'Intimação expedida às partes', movementAt: '23 set · 16:05', className: 'Ação penal · Tribunal do Júri', sigiloso: false },
    { id: '8002941-03.2024.8.05.0001', client: 'Beatriz Oliveira Rocha', opposing: 'Ministério Público do Estado da Bahia', court: 'TJBA · 1ª Vara Criminal de Salvador', owner: 'Luana Freitas', phase: 'Execução', due: '29 set · 18h', dueKind: 'upcoming', priority: 'Normal', status: 'Em andamento', statusKind: 'success', movement: 'Certidão de cumprimento juntada', movementAt: '23 set · 11:47', className: 'Execução penal', sigiloso: true },
    { id: '0006621-55.2022.8.05.0001', client: 'Marcos Vinícius Santos', opposing: 'Defensoria Pública do Estado da Bahia', court: 'TJBA · 1ª Vara Criminal de Salvador', owner: 'Rafael Nunes', phase: 'Recurso', due: '02 out · 18h', dueKind: 'upcoming', priority: 'Normal', status: 'Aguardando manifestação', statusKind: 'secondary', movement: 'Recurso recebido no tribunal', movementAt: '22 set · 10:21', className: 'Ação penal · Procedimento sumário', sigiloso: false }
  ];

  const metrics = [
    { label: 'Processos ativos', value: '1.248', note: 'na unidade', kind: 'plum', icon: '01' },
    { label: 'Prazos vencidos', value: '12', note: '1 na amostra', kind: 'red', icon: '02' },
    { label: 'Próximos 7 dias', value: '38', note: 'em acompanhamento', kind: 'amber', icon: '03' },
    { label: 'Sigilo ativo', value: '06', note: 'acessos controlados', kind: 'sage', icon: '04' }
  ];

  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const badge = (kind, value) => `<span class="fs-badge fs-badge-soft-${kind}">${escapeHtml(value)}</span>`;
  const priority = (value) => `<span class="priority-label priority-${value.toLowerCase()}"><i></i>${escapeHtml(value)}</span>`;
  const metricsMarkup = () => metrics.map((metric) => `<article class="metric-card metric-${metric.kind}"><span class="metric-icon" aria-hidden="true">${metric.icon}</span><div><p>${metric.label}</p><strong>${metric.value}</strong><small>${metric.note}</small></div></article>`).join('');
  const commonMetadata = (item) => `<span class="process-number">${escapeHtml(item.id)}</span><span class="process-class">${escapeHtml(item.className)}</span>`;

  const renderClassic = () => processes.map((item) => `<tr><td><strong class="cell-primary">${commonMetadata(item)}</strong><small>${escapeHtml(item.court)}</small></td><td><strong>${escapeHtml(item.client)}</strong><small>Parte contrária: ${escapeHtml(item.opposing)}</small></td><td><strong>${escapeHtml(item.owner)}</strong><small>Fase: ${escapeHtml(item.phase)}</small></td><td><strong class="due-text due-${item.dueKind}">${escapeHtml(item.due)}</strong><small>${priority(item.priority)}</small></td><td>${badge(item.statusKind, item.status)}${item.sigiloso ? '<small class="confidential">● Sigilo</small>' : ''}</td><td><strong>${escapeHtml(item.movement)}</strong><small>${escapeHtml(item.movementAt)}</small></td><td><span class="row-action" aria-hidden="true">Abrir ↗</span></td></tr>`).join('');

  const renderTopnav = () => processes.map((item, index) => `<article class="top-process-card"><div class="card-index">${String(index + 1).padStart(2, '0')}</div><div class="top-process-main"><div class="record-heading"><div>${commonMetadata(item)}</div>${badge(item.statusKind, item.status)}</div><div class="record-parties"><strong>${escapeHtml(item.client)}</strong><span>parte autora</span><i aria-hidden="true">×</i><strong>${escapeHtml(item.opposing)}</strong><span>parte contrária</span></div><div class="record-footer"><span><small>TRIBUNAL / UNIDADE</small>${escapeHtml(item.court)}</span><span><small>RESPONSÁVEL · FASE</small>${escapeHtml(item.owner)} · ${escapeHtml(item.phase)}</span><span><small>MOVIMENTAÇÃO · ${escapeHtml(item.movementAt)}</small>${escapeHtml(item.movement)}</span></div></div><div class="top-process-deadline"><small>PRÓXIMO PRAZO</small><strong class="due-text due-${item.dueKind}">${escapeHtml(item.due)}</strong>${priority(item.priority)}${item.sigiloso ? '<span class="confidential">● Sigilo</span>' : ''}<span class="row-action" aria-hidden="true">Detalhes ↗</span></div></article>`).join('');

  const renderCommand = () => processes.map((item, index) => `<article class="command-item ${index === 0 ? 'is-selected' : ''}"><div class="command-item-top"><strong class="process-number">${escapeHtml(item.id)}</strong>${badge(item.statusKind, item.status)}</div><p class="command-client">${escapeHtml(item.client)} <span>vs.</span> ${escapeHtml(item.opposing)}</p><div class="command-item-meta"><span>${escapeHtml(item.court)}</span><span>${escapeHtml(item.owner)} · ${escapeHtml(item.phase)}</span></div><div class="command-item-bottom"><span class="due-text due-${item.dueKind}">${escapeHtml(item.due)}</span>${priority(item.priority)}<span class="movement-short">${escapeHtml(item.movement)} · ${escapeHtml(item.movementAt)}</span></div></article>`).join('');

  const renderWorkspace = () => {
    const groups = [
      { title: 'Vencidos', key: 'overdue', hint: 'Exigem acompanhamento' },
      { title: 'Hoje e amanhã', key: 'today', hint: 'Prazos imediatos' },
      { title: 'Próximos dias', key: 'upcoming', hint: 'Em monitoramento' }
    ];
    return groups.map((group) => {
      const items = processes.filter((item) => item.dueKind === group.key || (group.key === 'today' && item.dueKind === 'soon') || (group.key === 'upcoming' && item.dueKind === 'upcoming'));
      if (!items.length) return '';
      return `<section class="priority-group"><header><div><h3>${group.title}</h3><p>${group.hint}</p></div><span>${String(items.length).padStart(2, '0')}</span></header><div class="priority-stack">${items.map((item) => `<article class="workspace-record"><div class="workspace-deadline due-${item.dueKind}"><small>PRÓXIMO PRAZO</small><strong>${escapeHtml(item.due)}</strong><span>${priority(item.priority)}</span></div><div class="workspace-record-main"><div class="record-heading"><strong class="process-number">${escapeHtml(item.id)}</strong>${badge(item.statusKind, item.status)}</div><p class="workspace-parties"><strong>${escapeHtml(item.client)}</strong><span>Parte contrária</span>${escapeHtml(item.opposing)}</p><div class="workspace-record-meta"><span>${escapeHtml(item.court)}</span><span>${escapeHtml(item.owner)} · ${escapeHtml(item.phase)}</span><span>${escapeHtml(item.className)}</span></div><p class="workspace-movement"><span>ÚLTIMA MOVIMENTAÇÃO</span>${escapeHtml(item.movement)} · ${escapeHtml(item.movementAt)}</p></div><span class="row-action" aria-hidden="true">Abrir ↗</span></article>`).join('')}</div></section>`;
    }).join('');
  };

  const renderEditorial = () => processes.map((item, index) => `<article class="editorial-record"><span class="editorial-index">${String(index + 1).padStart(2, '0')}</span><div class="editorial-case"><div class="editorial-case-heading"><span class="process-number">${escapeHtml(item.id)}</span>${badge(item.statusKind, item.status)}</div><p class="editorial-parties"><strong>${escapeHtml(item.client)}</strong><span>contra</span>${escapeHtml(item.opposing)}</p><small>${escapeHtml(item.className)} · ${escapeHtml(item.court)}</small></div><div class="editorial-case-owner"><span>RESPONSÁVEL</span><strong>${escapeHtml(item.owner)}</strong><small>Fase: ${escapeHtml(item.phase)}</small></div><div class="editorial-case-deadline"><span>PRÓXIMO PRAZO</span><strong class="due-text due-${item.dueKind}">${escapeHtml(item.due)}</strong>${priority(item.priority)}${item.sigiloso ? '<small class="confidential">● Acesso sigiloso</small>' : ''}</div><div class="editorial-case-movement"><span>MOVIMENTAÇÃO RECENTE · ${escapeHtml(item.movementAt)}</span><strong>${escapeHtml(item.movement)}</strong><span class="row-action" aria-hidden="true">Ver processo ↗</span></div></article>`).join('');

  const renderSelected = () => {
    const item = processes[0];
    return `<div class="selected-card-heading"><p class="eyebrow">PROCESSO EM DESTAQUE</p><span class="selected-star" aria-hidden="true">✦</span></div><span class="process-number">${escapeHtml(item.id)}</span><h3>${escapeHtml(item.client)}</h3><p class="selected-opposing">contra ${escapeHtml(item.opposing)}</p><div class="selected-tags">${badge(item.statusKind, item.status)}${priority(item.priority)}</div><dl><div><dt>Tribunal</dt><dd>${escapeHtml(item.court)}</dd></div><div><dt>Responsável</dt><dd>${escapeHtml(item.owner)}</dd></div><div><dt>Fase</dt><dd>${escapeHtml(item.phase)}</dd></div><div><dt>Próximo prazo</dt><dd class="due-text due-${item.dueKind}">${escapeHtml(item.due)}</dd></div><div><dt>Classe</dt><dd>${escapeHtml(item.className)}</dd></div><div><dt>Sigilo</dt><dd>${item.sigiloso ? 'Acesso controlado' : 'Sem restrição especial'}</dd></div></dl><div class="selected-movement"><span>ÚLTIMA MOVIMENTAÇÃO · ${escapeHtml(item.movementAt)}</span><strong>${escapeHtml(item.movement)}</strong></div><div class="selected-audit">● Acesso a dados sigilosos registrado em auditoria</div>`;
  };

  const renderStates = () => ({
    loading: '<div class="state-illustration loading-illustration"><span class="loading-bar"></span><span class="loading-bar short"></span><span class="loading-label" role="status" aria-live="polite">Carregando processos…</span></div>',
    empty: '<div class="state-illustration empty-illustration"><span class="state-icon" aria-hidden="true">⌕</span><h3>Nenhum processo encontrado</h3><p>Altere os filtros ou a busca para consultar outros registros.</p><span class="fs-btn fs-btn-outline-primary state-action">Limpar filtros</span></div>',
    error: '<div class="state-illustration error-illustration" role="alert"><span class="state-icon" aria-hidden="true">!</span><div><h3>Não foi possível carregar a lista</h3><p>Ocorreu uma falha temporária na consulta. Tente novamente em instantes.</p></div><span class="fs-btn fs-btn-outline-primary state-action">Tentar novamente</span></div>'
  });

  const layout = document.body.dataset.layout;
  const list = document.querySelector('[data-process-list]');
  if (!list || !layout) return;
  document.querySelectorAll('[data-metrics]').forEach((container) => { container.innerHTML = metricsMarkup(); });
  const renderers = { classic: renderClassic, topnav: renderTopnav, command: renderCommand, workspace: renderWorkspace, editorial: renderEditorial };
  list.innerHTML = renderers[layout]();
  if (layout === 'command') document.querySelector('[data-selection-summary]').innerHTML = renderSelected();
  const stateExamples = renderStates();
  document.querySelectorAll('[data-state-panel]').forEach((panel) => { panel.innerHTML = stateExamples[panel.dataset.statePanel]; });

  const toolbar = document.querySelector('[data-state-toolbar]');
  if (toolbar) {
    toolbar.innerHTML = `<span>VER ESTADO DA LISTA</span><div role="group" aria-label="Exemplos visuais de estado">${[['data', 'Dados'], ['loading', 'Carregando'], ['empty', 'Vazio'], ['error', 'Erro']].map(([state, label]) => `<button type="button" class="state-button ${state === 'data' ? 'is-active' : ''}" data-state-choice="${state}" aria-pressed="${state === 'data'}">${label}</button>`).join('')}</div>`;
    toolbar.addEventListener('click', (event) => {
      const button = event.target.closest('[data-state-choice]');
      if (!button) return;
      const state = button.dataset.stateChoice;
      list.hidden = state !== 'data';
      document.querySelectorAll('[data-state-panel]').forEach((panel) => { panel.hidden = panel.dataset.statePanel !== state; });
      toolbar.querySelectorAll('[data-state-choice]').forEach((choice) => {
        const active = choice === button;
        choice.classList.toggle('is-active', active);
        choice.setAttribute('aria-pressed', String(active));
      });
    });
  }
})();
