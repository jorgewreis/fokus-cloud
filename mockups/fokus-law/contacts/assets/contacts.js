(() => {
  const contacts = [
    { id: 'CT-00842', name: 'Ana Luiza Santos', initials: 'AS', kind: 'Pessoa', email: 'ana.luiza@example.test', roles: { office: 'Cliente · Parte', public: 'Requerente', judiciary: 'Parte processual' }, links: 3, related: ['8007316-42.2025.8.05.0001', '0010239-77.2023.8.05.0001'], owner: 'Marina Costa', updated: 'Hoje · 09:18', status: 'Ativo', statusKind: 'success', category: 'person', tags: ['Cível', 'Atendimento'] },
    { id: 'CT-00841', name: 'Rafael Nunes', initials: 'RN', kind: 'Pessoa', email: 'rafael.nunes@example.test', roles: { office: 'Advogado', public: 'Representante legal', judiciary: 'Advogado constituído' }, links: 8, related: ['0001842-19.2024.8.05.0001', '0006621-55.2022.8.05.0001'], owner: 'Marina Costa', updated: 'Ontem · 16:42', status: 'Ativo', statusKind: 'success', category: 'person', tags: ['Advocacia', 'Representação'] },
    { id: 'CT-00839', name: 'João Pedro Almeida', initials: 'JA', kind: 'Pessoa', email: 'joao.almeida@example.test', roles: { office: 'Parte · Cliente', public: 'Interessado', judiciary: 'Parte processual' }, links: 2, related: ['0001842-19.2024.8.05.0001'], owner: 'Luana Freitas', updated: '23 set · 14:32', status: 'Ativo', statusKind: 'success', category: 'person', tags: ['Criminal', 'Sigilo ativo'] },
    { id: 'CT-00835', name: 'Defensoria Pública da Bahia', initials: 'DP', kind: 'Instituição', email: 'contato@defensoria.example', roles: { office: 'Parte contrária', public: 'Órgão parceiro', judiciary: 'Instituição de defesa' }, links: 14, related: ['0001842-19.2024.8.05.0001', '0006621-55.2022.8.05.0001'], owner: 'Núcleo de atendimento', updated: '22 set · 11:06', status: 'Ativo', statusKind: 'success', category: 'organization', tags: ['Órgão público', 'Externo'] },
    { id: 'CT-00831', name: 'Ministério Público da Bahia', initials: 'MP', kind: 'Instituição', email: 'protocolo@mp.example', roles: { office: 'Parte contrária', public: 'Órgão externo', judiciary: 'Órgão ministerial' }, links: 21, related: ['0001842-19.2024.8.05.0001', '0010239-77.2023.8.05.0001'], owner: 'Secretaria da unidade', updated: '20 set · 15:20', status: 'Ativo', statusKind: 'success', category: 'organization', tags: ['Órgão público', 'Criminal'] },
    { id: 'CT-00827', name: 'Instituto Pericial do Estado', initials: 'IP', kind: 'Instituição', email: 'protocolo@pericia.example', roles: { office: 'Perícia técnica', public: 'Unidade técnica', judiciary: 'Setor técnico' }, links: 5, related: ['0010239-77.2023.8.05.0001'], owner: 'Rafael Nunes', updated: '18 set · 10:04', status: 'Revisar cadastro', statusKind: 'warning', category: 'organization', tags: ['Perícia', 'Prazo ativo'] }
  ];

  const audiences = {
    office: { label: 'Escritório de advocacia', short: 'Equipe Cível', unit: 'Equipe Cível · Salvador', eyebrow: 'REDE DO ESCRITÓRIO', description: 'Uma base de pessoas e organizações reutilizada nos processos da equipe.', roles: ['Cliente · Parte', 'Advogado', 'Parte · Cliente', 'Parte contrária', 'Parte contrária', 'Perícia técnica'] },
    public: { label: 'Setor do poder público', short: 'Procuradoria · Salvador', unit: 'Procuradoria Municipal · Salvador', eyebrow: 'REDE DO ÓRGÃO', description: 'Pessoas, unidades e instituições vinculadas à atuação do órgão público.', roles: ['Requerente', 'Representante legal', 'Interessado', 'Órgão parceiro', 'Órgão externo', 'Unidade técnica'] },
    judiciary: { label: 'Poder Judiciário', short: '1ª Vara Criminal', unit: '1ª Vara Criminal · Salvador', eyebrow: 'REDE DA UNIDADE JUDICIÁRIA', description: 'Partes, representantes e instituições com vínculos contextualizados à unidade.', roles: ['Parte processual', 'Advogado constituído', 'Parte processual', 'Instituição de defesa', 'Órgão ministerial', 'Setor técnico'] }
  };

  const metrics = [
    { label: 'Contatos ativos', value: '842', note: 'na base da unidade', kind: 'plum', icon: '01' },
    { label: 'Papéis cadastrados', value: '12', note: 'reutilizáveis por vínculo', kind: 'sage', icon: '02' },
    { label: 'Instituições', value: '38', note: 'na rede de relações', kind: 'amber', icon: '03' },
    { label: 'Revisar cadastro', value: '09', note: 'pendências de exemplo', kind: 'red', icon: '04' }
  ];

  let selectedId = contacts[0].id;
  let activeAudience = 'office';
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const badge = (kind, text) => `<span class="fs-badge fs-badge-soft-${kind}">${escapeHtml(text)}</span>`;
  const initialsClass = (contact) => contact.category === 'organization' ? 'contact-avatar organization-avatar' : 'contact-avatar';
  const roleText = (contact) => audiences[activeAudience].roles[contacts.indexOf(contact)];
  const metricsMarkup = () => metrics.map((item) => `<article class="metric-card metric-${item.kind}"><span class="metric-icon" aria-hidden="true">${item.icon}</span><div><p>${item.label}</p><strong>${item.value}</strong><small>${item.note}</small></div></article>`).join('');
  const stateMarkup = {
    loading: '<div class="contact-state-illustration"><span class="contact-skeleton"></span><span class="contact-skeleton short"></span><p role="status" aria-live="polite">Carregando contatos…</p></div>',
    empty: '<div class="contact-state-illustration"><span class="contact-state-icon" aria-hidden="true">⌕</span><h3>Nenhum contato encontrado</h3><p>Altere a busca ou os filtros para consultar outros registros.</p></div>',
    error: '<div class="contact-state-illustration" role="alert"><span class="contact-state-icon error-icon" aria-hidden="true">!</span><h3>Não foi possível carregar os contatos</h3><p>A consulta falhou temporariamente. Tente novamente em instantes.</p></div>'
  };

  function renderTable() {
    return contacts.map((contact) => `<tr><td><div class="contact-cell"><span class="${initialsClass(contact)}">${contact.initials}</span><span><strong>${escapeHtml(contact.name)}</strong><small>${escapeHtml(contact.kind)} · ref. ${contact.id}</small></span></div></td><td><span class="role-chip">${escapeHtml(roleText(contact))}</span><small class="contact-secondary">${escapeHtml(contact.tags.join(' · '))}</small></td><td><strong class="link-count">${String(contact.links).padStart(2, '0')}</strong><small class="contact-secondary">processos</small></td><td>${escapeHtml(contact.owner)}</td><td><strong>${escapeHtml(contact.updated)}</strong><small class="contact-secondary">última atualização</small></td><td>${badge(contact.statusKind, contact.status)}</td><td><span class="contact-row-action" aria-hidden="true">Abrir ↗</span></td></tr>`).join('');
  }

  function renderCards() {
    const groups = [{ key: 'person', title: 'Pessoas', note: 'Clientes, partes e representantes' }, { key: 'organization', title: 'Instituições e organizações', note: 'Órgãos, unidades e parceiros externos' }];
    return groups.map((group) => `<section class="relationship-group"><header><div><h3>${group.title}</h3><p>${group.note}</p></div><span>${String(contacts.filter((contact) => contact.category === group.key).length).padStart(2, '0')}</span></header><div class="relationship-cards">${contacts.filter((contact) => contact.category === group.key).map((contact) => `<article class="relationship-card"><div class="relationship-card-top"><span class="${initialsClass(contact)}">${contact.initials}</span>${badge(contact.statusKind, contact.status)}</div><p class="contact-kind">${escapeHtml(contact.kind)} · ${escapeHtml(contact.id)}</p><h4>${escapeHtml(contact.name)}</h4><span class="role-chip">${escapeHtml(roleText(contact))}</span><div class="contact-tag-list">${contact.tags.map((tag) => `<span>${escapeHtml(tag)}</span>`).join('')}</div><div class="relationship-card-footer"><span><strong>${String(contact.links).padStart(2, '0')}</strong> processos vinculados</span><span>${escapeHtml(contact.updated)}</span></div></article>`).join('')}</div></section>`).join('');
  }

  function renderWorkspaceList() {
    return contacts.map((contact) => `<button class="context-contact-item ${contact.id === selectedId ? 'is-selected' : ''}" type="button" data-contact-id="${contact.id}" aria-pressed="${contact.id === selectedId}"><span class="${initialsClass(contact)}">${contact.initials}</span><span class="context-contact-copy"><strong>${escapeHtml(contact.name)}</strong><small>${escapeHtml(roleText(contact))}</small></span><span class="context-contact-count">${String(contact.links).padStart(2, '0')}</span></button>`).join('');
  }

  function renderDetail() {
    const contact = contacts.find((item) => item.id === selectedId) ?? contacts[0];
    return `<header class="contact-detail-head"><div><p class="eyebrow">FICHA DO CONTATO</p><span class="contact-kind">${escapeHtml(contact.kind)} · ${escapeHtml(contact.id)}</span></div><span class="detail-static-action" aria-hidden="true">Editar ↗</span></header><div class="contact-identity"><span class="contact-avatar contact-avatar-large ${contact.category === 'organization' ? 'organization-avatar' : ''}">${contact.initials}</span><div><h2>${escapeHtml(contact.name)}</h2><p>${escapeHtml(contact.email)}</p></div></div><div class="detail-badges">${badge(contact.statusKind, contact.status)}<span class="role-chip">${escapeHtml(roleText(contact))}</span></div><dl class="contact-detail-fields"><div><dt>Natureza</dt><dd>${escapeHtml(contact.kind)}</dd></div><div><dt>Papéis nesta operação</dt><dd>${escapeHtml(roleText(contact))}</dd></div><div><dt>Responsável pelo vínculo</dt><dd>${escapeHtml(contact.owner)}</dd></div><div><dt>Atualização recente</dt><dd>${escapeHtml(contact.updated)}</dd></div></dl><section class="linked-processes"><div class="linked-process-heading"><div><p class="eyebrow">VÍNCULOS PROCESSUAIS</p><h3>${contact.links} processos relacionados</h3></div><span class="linked-count">${String(contact.links).padStart(2, '0')}</span></div><ul>${contact.related.map((id, index) => `<li><span class="linked-process-icon" aria-hidden="true">${index === 0 ? '▤' : '◷'}</span><span><strong>${escapeHtml(id)}</strong><small>${index === 0 ? 'Parte principal · atualizado ' : 'Referência relacionada · atualizado '}${escapeHtml(contact.updated)}</small></span></li>`).join('')}</ul></section><section class="contact-history"><p class="eyebrow">HISTÓRICO DO CONTATO</p><div><span class="history-dot"></span><span><strong>Vínculo consultado pela unidade</strong><small>${escapeHtml(contact.updated)} · registro de auditoria ilustrativo</small></span></div></section><p class="contact-privacy-note">● Dados de demonstração · acesso real dependerá do papel e das permissões.</p>`;
  }

  function updateLayout() {
    const list = document.querySelector('[data-contact-list]');
    if (!list) return;
    const layout = document.body.dataset.contactLayout;
    if (layout === 'directory-table') list.innerHTML = renderTable();
    if (layout === 'relationship-map') list.innerHTML = renderCards();
    if (layout === 'context-workspace') {
      list.innerHTML = renderWorkspaceList();
      document.querySelector('[data-contact-detail]').innerHTML = renderDetail();
      list.querySelectorAll('[data-contact-id]').forEach((button) => button.addEventListener('click', () => {
        selectedId = button.dataset.contactId;
        updateLayout();
      }));
    }
    document.querySelectorAll('[data-contact-state]').forEach((panel) => { panel.innerHTML = stateMarkup[panel.dataset.contactState]; });
  }

  function renderStateToolbar() {
    document.querySelectorAll('[data-contact-state-toolbar]').forEach((toolbar) => {
      toolbar.innerHTML = `<span>ESTADOS DA LISTA</span><div role="group" aria-label="Exemplos de estado do diretório">${[['data', 'Dados'], ['loading', 'Carregando'], ['empty', 'Vazio'], ['error', 'Erro']].map(([state, label]) => `<button type="button" class="state-button ${state === 'data' ? 'is-active' : ''}" data-contact-state-choice="${state}" aria-pressed="${state === 'data'}">${label}</button>`).join('')}</div>`;
      toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('[data-contact-state-choice]');
        if (!button) return;
        const state = button.dataset.contactStateChoice;
        document.querySelectorAll('[data-contact-list]').forEach((list) => { list.hidden = state !== 'data'; });
        document.querySelectorAll('[data-contact-state]').forEach((panel) => { panel.hidden = panel.dataset.contactState !== state; });
        document.querySelectorAll('[data-contact-state-choice]').forEach((choice) => {
          const active = choice.dataset.contactStateChoice === state;
          choice.classList.toggle('is-active', active);
          choice.setAttribute('aria-pressed', String(active));
        });
      });
    });
  }

  function renderAudienceSwitch() {
    document.querySelectorAll('[data-audience-switch]').forEach((container) => {
      container.innerHTML = `<span>PERFIL DE EXEMPLO</span><div role="group" aria-label="Contexto de personalização do Fokus Law">${[['office', 'Escritório'], ['public', 'Setor público'], ['judiciary', 'Poder Judiciário']].map(([key, label]) => `<button type="button" data-audience-choice="${key}" class="audience-choice ${activeAudience === key ? 'is-active' : ''}" aria-pressed="${activeAudience === key}">${label}</button>`).join('')}</div>`;
      container.addEventListener('click', (event) => {
        const button = event.target.closest('[data-audience-choice]');
        if (!button) return;
        activeAudience = button.dataset.audienceChoice;
        const audience = audiences[activeAudience];
        document.body.dataset.audience = activeAudience;
        document.querySelectorAll('[data-audience-choice]').forEach((choice) => {
          const active = choice.dataset.audienceChoice === activeAudience;
          choice.classList.toggle('is-active', active);
          choice.setAttribute('aria-pressed', String(active));
        });
        document.querySelectorAll('[data-unit-label]').forEach((el) => { el.innerHTML = `${escapeHtml(audience.label)}<strong>${escapeHtml(audience.unit)}</strong>`; });
        document.querySelectorAll('[data-context-short]').forEach((el) => { el.textContent = audience.short; });
        document.querySelectorAll('[data-context-eyebrow]').forEach((el) => { el.textContent = audience.eyebrow; });
        document.querySelectorAll('[data-context-description]').forEach((el) => { el.textContent = audience.description; });
        updateLayout();
      });
    });
  }

  document.querySelectorAll('[data-contact-metrics]').forEach((container) => { container.innerHTML = metricsMarkup(); });
  renderAudienceSwitch();
  renderStateToolbar();
  updateLayout();
})();
