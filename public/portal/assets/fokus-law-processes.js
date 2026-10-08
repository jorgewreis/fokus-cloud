(() => {
  const UI = () => window.FokusLawRecordUI;
  const labels = { normal: 'Normal', high: 'Alta', urgent: 'Urgente', public: 'Público', confidential: 'Sigiloso', secret: 'Secreto', active: 'Ativo', pending: 'Pendente', suspended: 'Suspenso', completed: 'Concluído', archived: 'Arquivado', dependent: 'Dependência', apenso: 'Apensamento', case_class: 'Classe', case_class_code: 'Código da classe', subjects: 'Assuntos', court_name: 'Órgão julgador', court_code: 'Código do órgão', official_status_text: 'Situação oficial', official_status_code: 'Código da situação', operational_status: 'Estado operacional', operational_priority: 'Prioridade', confidentiality_level: 'Sigilo', responsible_membership_id: 'Responsável', filing_date: 'Autuação', distribution_date: 'Distribuição', datajud_sync_status: 'Consulta Datajud', company_membership_id: 'Usuário autorizado', contact_name: 'Contato', role: 'Papel', tag: 'Etiqueta', choice: 'Decisão', value: 'Valor', field: 'Campo', manual: 'Manter preenchimento', official: 'Adotar dado oficial' };
  Object.assign(labels, { error: 'Falha na consulta', synced: 'Dados atualizados', not_found: 'Sem dados públicos', law_unit_id: 'Unidade', contact_id: 'Contato', related_case_id: 'Processo relacionado', source_case_id: 'Processo de origem', contact_search: 'Pesquisa de contato', datajud_case_class: 'Classe oficial (Datajud)', datajud_subjects: 'Assuntos oficiais (Datajud)', datajud_court_name: 'Órgão julgador oficial (Datajud)', datajud_official_status_text: 'Situação oficial (Datajud)' });
  const text = (value) => value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length) ? 'Não informado' : Array.isArray(value) ? value.map((item) => item.name || String(item)).join('; ') : labels[value] || String(value);  Object.assign(labels, { datajud_result_code: 'Resultado da consulta', initial_sync_failed: 'Consulta automática não concluída', queued: 'Consulta agendada', not_configured: 'Consulta não configurada', invalid_number: 'Número CNJ incompleto', unsupported_tribunal: 'Tribunal sem consulta configurada', authentication_failed: 'Chave recusada pelo CNJ', rate_limited: 'Consultas limitadas pelo Datajud', service_busy: 'Datajud sobrecarregado', source_timeout: 'Busca não concluída pelo Datajud', partial_response: 'Resposta incompleta do Datajud', timeout: 'Tempo de espera excedido', service_unavailable: 'Falha temporária do Datajud', endpoint_not_found: 'Endereço de consulta não encontrado', request_rejected: 'Consulta recusada pelo Datajud', invalid_response: 'Resposta incompatível', connection_failed: 'Falha de conexão', secure_connection_failed: 'Falha na conexão segura', internal_error: 'Falha interna na consulta', no_metadata: 'Processo encontrado sem metadados disponíveis' });
  const date = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('pt-BR') : 'Ainda não consultado';
  const cnj = (v) => String(v).replace(/^(\d{7})(\d{2})(\d{4})(\d)(\d{2})(\d{4})$/, '$1-$2.$3.$4.$5.$6');
  const request = (path = '', options) => window.FokusApi.request(`/law/cases${path}`, options);
  let generation = 0;

  async function render(root, context, initialCaseId = null, pageView = 'module') {
    const pageTitles = { module: 'Processos', 'processes-settings': 'Configurações da unidade', 'processes-access': 'Autorizações' };
    document.title = `${pageTitles[pageView] || pageTitles.module} | Fokus Law`;
    const session = ++generation;
    const { node: $, button, input, select, field, detailSection: section, message, dialog: openDialog, bindForm, compositionChart, metricCards } = UI();
    const dialogs = new Set();
    const dialog = (...args) => { const modal = openDialog(...args); dialogs.add(modal.element); return modal; };
    const can = (code) => context.company?.role === 'admin' || (context.law_permissions || []).includes(`law.cases.${code}`);
    const active = () => generation === session && root.isConnected && body.isConnected;
    let refs; let q = ''; let includeArchived = false; let page = 1;
    let currentId = null; let detailData; let viewToken = 0;
    const apiPath = (id) => `/${encodeURIComponent(id)}`;
    let refreshTimer;
    function watchDatajud(id, token, historyPage) {
      clearTimeout(refreshTimer);
      const valid = () => active() && currentId === id && viewToken === token;
      const poll = async () => {
        if (!valid()) return;
        for (const element of dialogs) if (!element.isConnected) dialogs.delete(element);
        if (dialogs.size) { refreshTimer = setTimeout(poll, 5000); return; }
        try {
          const result = await request(`${apiPath(id)}?history_page=${historyPage}`);
          if (!valid()) return;
          if (result.case.datajud_sync_status !== 'pending') {
            for (const element of dialogs) if (!element.isConnected) dialogs.delete(element);
            if (!dialogs.size) { await detail(id, historyPage); return; }
          }
        } catch (_) { /* Keep the saved record visible while the connection recovers. */ }
        if (valid()) refreshTimer = setTimeout(poll, 5000);
      };
      refreshTimer = setTimeout(poll, 5000);
    }
    const notice = message();
    const heading = $('header', 'law-page-heading law-record-page-heading'); heading.append($('p', 'law-page-eyebrow', 'GESTÃO DE PROCESSOS'), $('h2', '', pageTitles[pageView] || pageTitles.module), $('p', 'law-page-lede', pageView === 'processes-settings' ? 'Gerencie os padrões e as opções processuais de cada unidade.' : pageView === 'processes-access' ? 'Gerencie quem pode consultar processos secretos nas unidades que você administra.' : 'Judiciário criminal · Consulte os processos da empresa e organize o trabalho de cada unidade.'));
    const headingActions = $('div', 'law-record-heading-actions');
    if (pageView === 'module') heading.append(headingActions);
    const body = $('div'); root.replaceChildren(heading, notice, body);
    const report = (error) => { if (active()) { notice.textContent = error.message || 'Não foi possível carregar os processos.'; notice.hidden = false; } };
    const action = (fn) => async (event) => {
      const control = event?.currentTarget; if (control) control.disabled = true; notice.hidden = true;
      try { await fn(event); } catch (error) { report(error); }
      finally { if (control?.isConnected) control.disabled = false; }
    };
    const references = async (unitId, search = '') => request(`/references?${new URLSearchParams({ law_unit_id: unitId, contact_search: search })}`);
    const getRefs = async (unitId) => { refs = await references(unitId); return refs; };
    const toolbar = () => $('div', 'fs-u-d-flex fs-u-flex-wrap fs-u-gap-2');
    function keyValues(values) {
      const dl = $('dl', 'law-record-facts');
      values.forEach(([key, value]) => { const line = $('div'); line.append($('dt', '', key), $('dd', '', value)); dl.append(line); }); return dl;
    }
    function paginate(container, total, size, current, callback, noun = 'processos') {
      const row = $('div', 'law-record-pagination');
      UI().renderPagination(row, { total, per_page: size, page: current }, callback, noun, report); container.append(row);
    }
    function simpleForm(title, controls, send, submitText = 'Salvar', opener = null) {
      const modal = dialog(title, opener); const form = $('form', 'law-record-editor'); const error = message();
      const group = title === 'Cadastrar processo' ? section('Dados principais', 'CNJ', 'Cadastro do processo judicial') : null;
      controls.forEach(([label, control, name, help]) => (group?.body || form).append(field(label, control, name, help)));
      if (group) form.append(group);
      form.append(error); const submit = button(submitText, null, true); submit.type = 'submit'; form.id = `law-case-form-${Date.now()}`; submit.setAttribute('form', form.id);
      modal.body.append(form); modal.footer.append(button('Cancelar', () => modal.close()), submit);
      bindForm(form, submit, error, async () => { await send(); modal.close(); }); return modal;
    }
    function metadataEditor(local, initialClass = null, initialSubjects = []) {
      const classes = [...(local.classes || [])];
      if (initialClass?.code && !classes.some((item) => item.code === initialClass.code)) classes.push({ code: initialClass.code, name: initialClass.name });
      const classSelect = select([['', 'Selecione uma classe'], ...classes.map((item) => [item.code, `${item.code} - ${item.name}`]), ['__other__', 'Outra classe']], initialClass?.code || (initialClass?.name ? '__other__' : ''));
      const classOther = $('div', 'fs-stack fs-stack-gap-2'); const classCode = input(initialClass?.code && !classes.some((item) => item.code === initialClass.code) ? initialClass.code : '', 'text', 32); classCode.placeholder = 'Código da classe'; const className = input(initialClass?.name && !classes.some((item) => item.code === initialClass.code) ? initialClass.name : '', 'text', 180); className.placeholder = 'Nome da classe';
      classOther.append(field('Número da classe', classCode), field('Nome da classe', className)); classOther.hidden = classSelect.value !== '__other__';
      classSelect.addEventListener('change', () => { classOther.hidden = classSelect.value !== '__other__'; });
      const subjectChoices = [...(local.subjects || [])];
      const subjectsSelect = select(subjectChoices.map((item) => [item.code, `${item.code} - ${item.name}`])); subjectsSelect.multiple = true; subjectsSelect.size = Math.min(6, Math.max(3, subjectChoices.length));
      const knownCodes = new Set((local.subjects || []).map((item) => item.code));
      [...subjectsSelect.options].forEach((option) => { option.selected = initialSubjects.some((item) => item.code === option.value); });
      const customHost = $('div', 'fs-stack fs-stack-gap-2');
      const addCustom = (value = null) => {
        const row = $('div', 'fs-u-d-flex fs-u-flex-wrap fs-u-gap-2'); const code = input(value?.code || '', 'text', 32); code.placeholder = 'Código do assunto'; const name = input(value?.name || '', 'text', 180); name.placeholder = 'Nome do assunto';
        row.append(code, name, button('Remover', () => row.remove())); customHost.append(row);
      };
      initialSubjects.filter((item) => !item.code || !knownCodes.has(item.code)).forEach(addCustom);
      return {
        classSelect, classOther, classCode, className, subjectsSelect, customHost,
        addCustom,
        values() {
          const chosen = classes.find((item) => item.code === classSelect.value);
          const klass = classSelect.value === '__other__' ? { code: classCode.value.trim(), name: className.value.trim() } : chosen ? { code: chosen.code, name: chosen.name } : null;
          const subjects = [...subjectsSelect.selectedOptions].map((option) => { const item = subjectChoices.find((subject) => subject.code === option.value); return item ? { code: item.code, name: item.name } : null; }).filter(Boolean);
          customHost.querySelectorAll('.fs-u-d-flex').forEach((row) => { const [code, name] = row.querySelectorAll('input'); if (code.value.trim() || name.value.trim()) subjects.push({ code: code.value.trim(), name: name.value.trim() }); });
          return { klass, subjects };
        },
      };
    }
    async function list() {
      clearTimeout(refreshTimer);
      const token = ++viewToken;
      currentId = null; notice.hidden = true; body.setAttribute('aria-busy', 'true');
      try {
        const result = await request(`?${new URLSearchParams({ q, page, include_archived: includeArchived ? '1' : '0' })}`);
        if (!active() || token !== viewToken) return;
        body.replaceChildren(); body.className = ''; headingActions.replaceChildren();
        if (can('create')) headingActions.append(button('Novo processo', action(create), true));
        const total = Number(result.pagination.total); const classes = result.summary_by_class || []; const summary = result.summary || {};
        const overview = $('section', 'law-record-overview-banner fs-card'); overview.setAttribute('aria-label', 'Resumo dos processos acessíveis');
        const copy = $('div', 'law-record-overview-copy'); copy.append($('span', 'law-record-overview-kicker', 'PAINEL PROCESSUAL'), $('h3', '', 'Seus processos, em uma visão.'), $('p', '', 'Classes judiciais, organização interna e histórico dos processos em um só lugar.'));
        const contextNote = $('div', 'law-record-capacity'); contextNote.append($('small', '', includeArchived ? 'Consulta com processos arquivados. Indicadores respeitam sua pesquisa e suas autorizações.' : 'Processos não arquivados. Indicadores respeitam sua pesquisa e suas autorizações.')); copy.append(contextNote);
        overview.append(copy, compositionChart(classes, total, 'processos')); body.append(overview);
        const metrics = $('section', 'law-record-metrics'); metrics.setAttribute('aria-label', 'Indicadores desta consulta'); metrics.append(...metricCards([
          ['Processos na consulta', total, includeArchived ? 'Inclui processos arquivados' : 'Registros não arquivados', 'violet'],
          ['Classes judiciais', classes.length, 'Distribuição dos processos', 'blue'],
          ['Unidades', summary.units || 0, 'Com processos nesta consulta', 'teal'],
          ['Processos secretos', summary.secret || 0, 'Somente os autorizados a você', 'amber'],
        ])); body.append(metrics);
        const recentCard = $('section', 'law-record-recent-card fs-card'); const recentHead = $('header', 'fs-card-header fs-u-p-3 law-record-recent-header'); recentHead.append($('h3', 'fs-card-title', 'Cadastrados recentemente'));
        const recentBody = $('div', 'fs-card-body law-record-recent'); (summary.recent || []).forEach((item) => { const link = button('', action(() => detail(item.id))); link.className = 'law-record-recent-item'; const meta = $('span', 'law-record-recent-meta'); meta.append($('span', '', 'Cadastrado'), $('time', '', new Date(String(item.created_at).replace(' ', 'T')).toLocaleDateString('pt-BR'))); link.append($('strong', '', item.case_number_formatted), meta); recentBody.append(link); });
        if (!(summary.recent || []).length) recentBody.append($('p', 'law-record-recent-empty', 'Seus processos cadastrados aparecerão aqui.')); recentCard.append(recentHead, recentBody); body.append(recentCard);
        const form = $('form', 'law-record-filters law-record-filters-search'); const search = input(q, 'search', 30); search.placeholder = 'Buscar pelo número CNJ, completo ou parcial';
        const archived = input('', 'checkbox'); archived.className = 'fs-check-input'; archived.checked = includeArchived; archived.id = `law-archived-${session}`;
        const archivedLabel = $('label', 'fs-check-label', 'Incluir arquivados'); archivedLabel.htmlFor = archived.id;
        const archivedField = $('div', 'fs-check'); archivedField.append(archived, archivedLabel);
        const submit = button('Pesquisar', null, true); submit.type = 'submit';
        const searchField = field('Pesquisar processo', search, 'q'); searchField.className = 'law-record-field';
        form.append(searchField, archivedField, submit);
        form.addEventListener('submit', (event) => { event.preventDefault(); q = search.value.trim(); includeArchived = archived.checked; page = 1; list(); });
        body.append(form);
        const wrap = $('div', 'fs-table-responsive law-record-table-wrap'); const table = $('table', 'fs-table law-record-table');
        const caption = $('caption', 'fs-u-visually-hidden', 'Processos cadastrados · registros mais recentes primeiro'); const head = $('thead'); const tr = $('tr');
        ['Número CNJ', 'Classe', 'Unidade', 'Situação oficial', 'Estado operacional', 'Sigilo', 'Ações'].forEach((label) => { const th = $('th', '', label); th.scope = 'col'; tr.append(th); }); head.append(tr);
        const tbody = $('tbody'); result.cases.forEach((item) => {
          const row = $('tr'); const numberCell = $('td'); const open = button(item.case_number_formatted, action(() => detail(item.id))); open.className = 'law-record-name'; open.setAttribute('aria-label', `Consultar processo ${item.case_number_formatted}`); numberCell.append(open); row.append(numberCell);
          [text(item.case_class), item.unit_name, text(item.official_status_text)].forEach((value) => row.append($('td', '', value)));
          const statusCell = $('td'); const tone = { active: 'success', pending: 'warning', suspended: 'warning', completed: 'info', archived: 'danger' }[item.operational_status] || 'secondary'; const statusBadge = $('span', `fs-badge fs-badge-soft-${tone} law-process-status-badge`, item.operational_status_label); statusBadge.dataset.tone = item.operational_status === 'archived' ? 'danger' : item.operational_status === 'suspended' ? 'warning' : item.operational_status === 'active' ? 'success' : ''; statusCell.append(statusBadge); row.append(statusCell);
          const privacy = $('td'); privacy.append($('span', `fs-badge fs-badge-soft-${item.confidentiality_level === 'secret' ? 'warning' : item.confidentiality_level === 'confidential' ? 'danger' : 'secondary'}`, text(item.confidentiality_level))); row.append(privacy);
          const td = $('td', 'law-record-actions'); const actionList = $('div', 'law-record-action-list'); const view = button('', action(() => detail(item.id))); view.className = 'fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action'; view.setAttribute('aria-label', `Ver detalhes do processo ${item.case_number_formatted}`); view.title = 'Ver detalhes'; const icon = $('img'); icon.src = '/backoffice/assets/icons/Folder-File--Streamline-Ultimate.png'; icon.alt = ''; view.append(icon); actionList.append(view); td.append(actionList); row.append(td); tbody.append(row);
        });
        if (!result.cases.length) { const row = $('tr'); const cell = $('td', 'fs-table-empty law-record-empty', q ? 'Nenhum processo acessível corresponde ao número pesquisado.' : 'Nenhum processo cadastrado nesta consulta. Cadastre o primeiro processo para começar.'); cell.colSpan = 7; row.append(cell); tbody.append(row); }
        table.append(caption, head, tbody); wrap.append(table); body.append(wrap);
        paginate(body, result.pagination.total, result.pagination.per_page, page, async (value) => { page = value; await list(); });
      } catch (error) { report(error); } finally { body.removeAttribute('aria-busy'); }
    }
    async function create(event) {
      if (!refs) throw new Error('Selecione uma unidade ativa no menu para cadastrar um processo.');
      const number = input('', 'text', 25); number.required = true; number.placeholder = '0000000-00.0000.0.00.0000'; number.inputMode = 'numeric';
      const unit = select(refs.units.map((item) => [item.id, item.name]), context.active_unit_id); unit.required = true;
      const filing = input('', 'date'); const distribution = input('', 'date');
      const modal = dialog('Cadastrar processo', event?.currentTarget); const form = $('form', 'law-record-editor'); const error = message(); const main = section('Dados principais', 'CNJ', 'Cadastro do processo judicial');
      main.body.append(field('Número CNJ', number, 'case_number', 'Informe o número completo ou os 13 primeiros dígitos para completar com o padrão da unidade.'), field('Unidade', unit, 'law_unit_id'), field('Data de autuação (opcional)', filing, 'filing_date'), field('Data de distribuição (opcional)', distribution, 'distribution_date'));
      const applyDefaults = (local) => { const d = local.cnj_defaults; if (number.value.replace(/\D/g, '').length === 13 && d) { const raw = number.value.replace(/\D/g, ''); number.value = cnj(raw + d.segment + d.court + d.origin); } };
      number.addEventListener('blur', () => applyDefaults(refs));
      unit.addEventListener('change', action(async () => { refs = await references(unit.value); applyDefaults(refs); }));
      form.append(main, error); form.id = `law-case-form-${Date.now()}`; const save = button('Cadastrar processo', null, true); save.type = 'submit'; save.setAttribute('form', form.id); modal.body.append(form); modal.footer.append(button('Cancelar', () => modal.close()), save);
      bindForm(form, save, error, async () => {
        const result = await request('', { method: 'POST', body: { case_number: number.value, law_unit_id: unit.value, filing_date: filing.value || null, distribution_date: distribution.value || null } });
        modal.close();
        await detail(result.case.id);
      });
    }
    async function edit(event) {
      const c = detailData.case; const local = await getRefs(c.law_unit_id); const modal = dialog('Editar processo', event?.currentTarget, 'fs-modal-xl');
      const form = $('form', 'law-record-editor'); form.id = `law-case-edit-${c.id}`; const controls = {};
      const add = (box, label, name, control, help = '') => { controls[name] = control; box.body.append(field(label, control, name, help)); };
      const operational = section('Organização interna', 'ORG');
      const statuses = local.statuses.filter((item) => item.code !== 'archived');
      if (!statuses.some((item) => item.code === c.operational_status)) statuses.push({ code: c.operational_status, label: c.operational_status_label });
      const state = select(statuses.map((item) => [item.code, item.label]), c.operational_status); state.disabled = c.operational_status === 'archived';
      add(operational, 'Estado operacional', 'operational_status', state, 'O Datajud não altera este estado. Para arquivar ou reabrir, use a ação específica.');
      add(operational, 'Prioridade operacional', 'operational_priority', select(['normal', 'high', 'urgent'].map((v) => [v, text(v)]), c.operational_priority));
      add(operational, 'Responsável principal', 'responsible_membership_id', select([['', 'Sem responsável'], ...local.members.map((v) => [v.id, v.name])], c.responsible_membership_id));
      if (detailData.can_manage_access) add(operational, 'Sigilo', 'confidentiality_level', select(local.confidentiality_levels.map((v) => [v.code, v.label]), c.confidentiality_level), 'Público: pessoas da empresa com acesso ao módulo. Sigiloso: a empresa vê tudo. Secreto: somente usuários nominados e autorizados neste processo.');
      const metadata = section('Dados processuais', 'CNJ');
      const legal = section('Prioridade processual', 'PRIO', 'Fundamentos legais de tramitação prioritária; podem ser cumulativos.');
      const legalPriorities = $('fieldset', 'fs-stack fs-stack-gap-2'); legalPriorities.append($('legend', 'fs-form-label', 'Fundamentos legais'));
      (local.procedural_priorities || []).forEach((item) => {
        const checkbox = input('', 'checkbox'); checkbox.className = 'law-record-priority-checkbox'; checkbox.value = item.code; checkbox.checked = (c.procedural_priorities || []).includes(item.code);
        const option = $('label', 'law-record-priority-option'); option.append(checkbox, $('span', '', item.label)); legalPriorities.append(option);
      });
      controls.procedural_priorities = legalPriorities;
      legal.body.append(legalPriorities, $('p', 'law-record-help', 'Selecione todos os fundamentos aplicáveis. Desmarque um fundamento para removê-lo.'));
      const classIsOfficial = (c.official_fields || []).includes('case_class'); const subjectsAreOfficial = (c.official_fields || []).includes('subjects');
      const editor = metadataEditor(local, { code: c.case_class_code, name: c.case_class }, c.subjects || []);
      if (!classIsOfficial) {
        controls.case_class_code = editor.classSelect;
        metadata.body.append(field('Classe processual', editor.classSelect, 'case_class_code', 'Selecione uma classe existente ou escolha Outra classe para informar código e nome.'), editor.classOther);
      } else metadata.body.append(keyValues([['Classe processual', text(`${c.case_class_code ? `${c.case_class_code} - ` : ''}${c.case_class || ''}`)]]));
      if (!subjectsAreOfficial) {
        controls.subjects = editor.subjectsSelect;
        metadata.body.append(field('Assuntos cadastrados', editor.subjectsSelect, 'subjects', 'Use Ctrl ou Command para selecionar mais de um assunto.'), button('Adicionar outro assunto', () => editor.addCustom()), editor.customHost);
      } else metadata.body.append(keyValues([['Assuntos', text(c.subjects)]]));
      controls._metadataEditor = editor;
      for (const [name, label] of [['court_name', 'Órgão julgador'], ['official_status_text', 'Situação oficial']]) {
        if (!(c.official_fields || []).includes(name)) {
          const statuses = ['Em andamento', 'Suspenso', 'Arquivado', 'Em grau de recurso'];
          const currentStatus = statuses.includes(c.official_status_text) ? c.official_status_text : 'Em andamento';
          add(metadata, label, name, name === 'official_status_text'
            ? select(statuses.map((status) => [status, status]), currentStatus)
            : input(c[name]));
        }
        else metadata.body.append(keyValues([[label, text(c[name])]]));
      }
      add(metadata, 'Data de autuação', 'filing_date', input(c.filing_date, 'date'));
      add(metadata, 'Data de distribuição', 'distribution_date', input(c.distribution_date, 'date'));
      metadata.body.append($('p', 'fs-u-color-secondary', 'Os campos retornados pelo Datajud ficam disponíveis para consulta. Campos ausentes podem ser preenchidos manualmente.'));
      const error = message(); form.append(operational, legal, metadata, error); modal.body.append(form);
      const save = button('Salvar alterações', null, true); save.type = 'submit'; save.setAttribute('form', form.id); modal.footer.append(button('Cancelar', () => modal.close()), save);
      bindForm(form, save, error, async () => {
        const data = { version: c.version }; Object.entries(controls).forEach(([name, control]) => {
          if (name.startsWith('_') || control.disabled) return;
          if (name === 'subjects') data[name] = controls._metadataEditor.values().subjects;
          else if (name === 'procedural_priorities') data[name] = [...control.querySelectorAll('input:checked')].map((checkbox) => checkbox.value);
          else if (name === 'case_class_code') {
            const klass = controls._metadataEditor.values().klass; data.case_class_code = klass?.code || null; data.case_class = klass?.name || null;
          }
          else data[name] = control.value || null;
        });
        await request(apiPath(c.id), { method: 'PATCH', body: data }); modal.close(); await detail(c.id);
      });
    }
    async function detail(id, historyPage = 1) {
      clearTimeout(refreshTimer);
      const token = ++viewToken;
      const changedRecord = currentId !== id;
      body.setAttribute('aria-busy', 'true');
      try {
        const result = await request(`${apiPath(id)}?history_page=${historyPage}`); const local = await references(result.case.law_unit_id);
        if (!active() || token !== viewToken) return;
        detailData = result; refs = local; currentId = id; body.replaceChildren(); body.className = 'law-record-detail-body'; headingActions.replaceChildren(); const c = result.case;
        const actions = toolbar(); actions.append(button('Voltar à lista', action(list)));
        if (can('update')) {
          actions.append(button('Editar processo', action(edit), true));
          if (!(c.official_fields || []).length) {
            const consult = button('Consultar Datajud', action(async (event) => {
              const control = event?.currentTarget;
              if (control) { control.textContent = 'Consultando Datajud…'; control.setAttribute('aria-busy', 'true'); }
              try { await request(`${apiPath(id)}/datajud`, { method: 'POST' }); await detail(id); }
              finally { if (control?.isConnected) { control.textContent = 'Consultar Datajud'; control.removeAttribute('aria-busy'); } }
            }));
            if (c.datajud_sync_status === 'pending') { consult.disabled = true; consult.textContent = 'Consulta automática pendente'; }
            actions.append(consult);
          }
        }
        const archiveAction = c.operational_status === 'archived' ? 'reopen' : 'archive';
        if (can(archiveAction)) actions.append(button(archiveAction === 'archive' ? 'Arquivar' : 'Reabrir', action((event) => {
          const reason = $('textarea', 'fs-form-control'); reason.required = true; reason.minLength = 3; reason.maxLength = 2000;
          simpleForm(archiveAction === 'archive' ? 'Arquivar processo' : 'Reabrir processo', [['Justificativa', reason, 'reason']], async () => {
            await request(`${apiPath(id)}/${archiveAction}`, { method: 'POST', body: { version: c.version, reason: reason.value.trim() } }); await detail(id);
          }, archiveAction === 'archive' ? 'Arquivar processo' : 'Reabrir processo', event?.currentTarget);
        })));
        headingActions.append(...actions.children);
        const identity = $('section', 'law-record-detail-summary'); const symbol = $('span', 'law-record-detail-avatar'); const image = $('img'); image.src = '/backoffice/assets/icons/Zip-File--Streamline-Ultimate-Regular.svg'; image.alt = ''; symbol.append(image);
        const copy = $('div', 'law-record-detail-summary-copy'); copy.append($('span', 'law-record-detail-eyebrow', 'JUDICIÁRIO CRIMINAL'), $('h3', 'law-record-detail-name', c.case_number_formatted), $('p', 'law-record-detail-legal-name', `${text(c.case_class)} · ${c.unit_name}`));
        const operationalStatus = String(c.operational_status_label || '').trim();
        const officialStatus = String(c.official_status_text || '').trim();
        const normalizeStatus = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
        const statusLabel = !operationalStatus ? officialStatus : !officialStatus || normalizeStatus(operationalStatus) === normalizeStatus(officialStatus) ? operationalStatus : `${operationalStatus} | ${officialStatus}`;
        const normalizedOperational = normalizeStatus(operationalStatus);
        const normalizedOfficial = normalizeStatus(officialStatus);
        const statusTone = c.operational_status === 'archived' || normalizedOperational.includes('arquivad') || normalizedOfficial.includes('arquivad')
          ? 'danger'
          : c.operational_status === 'suspended' || normalizedOperational.includes('suspens') || normalizedOfficial.includes('suspens') || normalizedOfficial.includes('grau de recurso')
            ? 'warning'
            : c.operational_status === 'active' || normalizedOperational.includes('ativo') ? 'success' : '';
        const badges = $('div', 'law-record-detail-chip-groups');
        if (statusLabel) { const statusBadge = $('span', 'law-record-detail-chip law-process-status-badge', statusLabel); statusBadge.dataset.tone = statusTone; badges.append(statusBadge); }
        [text(c.operational_priority), text(c.confidentiality_level)].filter(Boolean).forEach((label) => badges.append($('span', 'law-record-detail-chip', label))); copy.append(badges); identity.append(symbol, copy); body.append(identity);
        const main = section('Dados processuais', 'CNJ', 'Classe, assuntos e informações oficiais');
        main.body.append(keyValues([['Classe judicial', text(c.case_class)], ['Órgão julgador', text(c.court_name)], ['Assuntos', text(c.subjects)], ['Situação oficial', text(c.official_status_text)], ['Autuação', c.filing_date ? new Date(`${c.filing_date}T12:00:00`).toLocaleDateString('pt-BR') : 'Não informada'], ['Distribuição', c.distribution_date ? new Date(`${c.distribution_date}T12:00:00`).toLocaleDateString('pt-BR') : 'Não informada'], ['Última consulta ao Datajud', date(c.last_datajud_checked_at)]]));
        main.body.append(keyValues([['Resultado da consulta', c.datajud_sync_status === 'pending' ? 'Aguardando consulta' : text(result.datajud?.code || c.datajud_sync_status)], ['Última atualização bem-sucedida', c.last_datajud_synced_at ? date(c.last_datajud_synced_at) : 'Ainda não houve atualização pelo Datajud']]));
        const fallbackMessage = c.datajud_sync_status === 'pending' ? 'Processo cadastrado. A consulta ao Datajud será feita em segundo plano e pode levar alguns minutos. Você pode continuar trabalhando; os dados aparecerão aqui automaticamente.' : c.datajud_sync_status === 'not_found' ? 'A consulta foi concluída, mas o Datajud não disponibilizou dados públicos para este processo. Você pode preencher os campos ausentes manualmente.' : c.datajud_sync_status === 'synced' ? 'A última consulta ao Datajud foi concluída. Os campos não fornecidos pelo CNJ podem ser preenchidos manualmente.' : 'A última consulta ao Datajud falhou. Consulte o histórico para conferir o motivo ou tente novamente.';
        const feedback = $('p', `fs-alert ${['pending', 'not_found'].includes(c.datajud_sync_status) ? 'fs-alert-info' : c.datajud_sync_status === 'synced' ? 'fs-alert-success' : 'fs-alert-warning'}`, `${(c.datajud_sync_status === 'pending' ? fallbackMessage : result.datajud?.message) || fallbackMessage}${c.datajud_sync_status === 'error' ? ' O cadastro e os dados já registrados foram preservados.' : ''}`);
        feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite'); main.body.append(feedback);
        const source = $('a', 'fs-u-fs-sm', 'Metadados oficiais: CNJ · DataJud'); source.href = 'https://datajud-wiki.cnj.jus.br/api-publica/'; source.target = '_blank'; source.rel = 'noopener noreferrer'; main.body.append(source); body.append(main);
        const operational = section('Organização interna', 'ORG', 'Acompanhamento do trabalho da unidade'); operational.body.append(keyValues([['Unidade', c.unit_name], ['Estado operacional', c.operational_status_label], ['Prioridade operacional', text(c.operational_priority)], ['Prioridades processuais', (c.procedural_priorities || []).map((code) => local.procedural_priorities?.find((item) => item.code === code)?.label || code).join('; ') || 'Nenhuma'], ['Responsável principal', local.members.find((item) => item.id === c.responsible_membership_id)?.name || 'Sem responsável'], ['Sigilo', text(c.confidentiality_level)]]));
        if (c.operational_status === 'archived') operational.body.append(keyValues([['Justificativa do arquivamento', c.archive_reason]])); operational.body.append($('p', 'law-record-detail-empty', 'A situação oficial do Datajud e o estado operacional são independentes.')); body.append(operational);
        if (c.metadata_conflicts?.length) {
          const conflicts = section('Divergências com o Datajud'); conflicts.body.append($('p', '', 'O preenchimento manual foi preservado. Escolha o valor que deve permanecer em cada campo.'));
          c.metadata_conflicts.forEach((item) => {
            const box = $('div', 'fs-stack fs-stack-gap-2'); box.append(keyValues([['Campo', labels[item.field] || item.field], ['Preenchimento manual', text(item.manual_value)], ['Dado oficial', text(item.official_value)]]));
            if (can('update')) { const row = toolbar(); ['manual', 'official'].forEach((choice) => row.append(button(text(choice), action(async () => { await request(`${apiPath(id)}/conflicts/${item.id}`, { method: 'POST', body: { choice, version: c.version } }); await detail(id); })))); box.append(row); } conflicts.body.append(box);
          }); body.append(conflicts);
        }
        renderTags(result, local); renderContacts(result, local); renderRelations(result);
        if (result.can_manage_access && c.confidentiality_level === 'secret') await renderAccess(result, local);
        const history = section('Histórico do processo', 'HIS', 'Alterações internas e atualizações de metadados'); history.classList.add('law-record-section-wide');
        result.events.forEach((item) => {
          const article = $('article', 'law-record-history-entry'); article.append($('h4', '', item.title), $('p', 'law-record-detail-empty', `${date(item.created_at)} · ${item.actor_name || 'Atualização automática'}`));
          if (item.reason) article.append($('p', '', item.reason));
          const before = item.before_state || {}; const after = item.after_state || {};
          const display = (key, value) => {
            if (value === undefined || value === null) return 'Não informado';
            if (key === 'law_unit_id') return local.units.find((v) => v.id === value)?.name || 'Unidade registrada';
            if (['company_membership_id', 'responsible_membership_id'].includes(key)) return local.members.find((v) => v.id === value)?.name || 'Usuário registrado';
            if (key === 'contact_id') return local.contacts.find((v) => v.id === value)?.display_name || 'Contato registrado';
            if (['related_case_id', 'source_case_id'].includes(key)) return value === id ? c.case_number_formatted : cnj(result.relations.find((v) => v.related_case_id === value)?.case_number || 'Processo relacionado');
            return text(value);
          };
          const values = [...new Set([...Object.keys(before), ...Object.keys(after)])].map((key) => [labels[key] || key, `${display(key, before[key])} → ${display(key, after[key])}`]);
          if (values.length) article.append(keyValues(values)); history.body.append(article);
        });
        if (!result.events.length) history.body.append($('p', 'law-record-detail-empty', 'As alterações e atualizações deste processo aparecerão aqui.'));
        paginate(history.body, result.history_total, 50, result.history_page, (value) => detail(id, value), 'eventos'); body.append(history);
        if (changedRecord) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); heading.scrollIntoView({ block: 'start' }); }
        if (c.datajud_sync_status === 'pending') watchDatajud(id, token, historyPage);
      } catch (error) { report(error); } finally { body.removeAttribute('aria-busy'); }
    }
    function linkedSection(title, items, remove, add) {
      const box = section(title, 'VÍN');
      if (!items.length) box.body.append($('p', 'law-record-detail-empty', 'Nenhum vínculo registrado.'));
      items.forEach((item) => {
        const row = $('div', 'law-record-linked-row');
        if (item.case_number) {
          const content = $('div', 'law-record-linked-content');
          content.append($('span', 'law-record-linked-label', item.label));
          content.append($('span', 'law-record-linked-meta', `Classe processual: ${text(item.case_class)} · Situação oficial: ${text(item.official_status_text)}`));
          row.append(content);
        } else row.append($('span', '', item.label));
        if (can('update')) {
          const removeButton = button('', action(async () => { await remove(item); await detail(currentId); }));
          removeButton.className = 'fs-btn fs-btn-icon fs-btn-icon-plain law-process-settings-option-remove';
          removeButton.setAttribute('aria-label', `Remover ${item.label}`); removeButton.title = 'Remover';
          const icon = $('img'); icon.src = '/portal/assets/icons/Subtract-Circle--Streamline-Ultimate.png'; icon.alt = ''; icon.setAttribute('aria-hidden', 'true');
          removeButton.append(icon); row.append(removeButton);
        }
        box.body.append(row);
      });
      if (can('update') && add) box.body.append(button('Adicionar', action(add))); body.append(box); return box;
    }
    function renderTags(result, local) {
      const id = result.case.id;
      linkedSection('Etiquetas', result.tags.map((item) => ({ ...item, label: item.name })), (item) => request(`${apiPath(id)}/tags/${item.id}`, { method: 'DELETE' }), (event) => {
        const options = local.tags.filter((item) => !result.tags.some((used) => used.id === item.id));
        if (!options.length) throw new Error('Não há etiquetas disponíveis. A chefia ou o administrador pode cadastrá-las nas configurações da unidade.');
        const tag = select(options.map((v) => [v.id, v.name]));
        simpleForm('Adicionar etiqueta', [['Etiqueta', tag, 'tag_id']], async () => { await request(`${apiPath(id)}/tags`, { method: 'POST', body: { tag_id: tag.value } }); await detail(id); }, 'Adicionar etiqueta', event?.currentTarget);
      });
    }
    function renderContacts(result, local) {
      const id = result.case.id;
      linkedSection('Contatos e papéis processuais', result.contacts.map((item) => ({ ...item, label: `${item.display_name} · ${item.case_role_label}` })), (item) => request(`${apiPath(id)}/contacts/${item.id}`, { method: 'DELETE' }), (event) => {
        const search = input('', 'search', 100); const contact = select([['', 'Selecione um contato']]); contact.required = true;
        const role = select(local.roles.map((v) => [v.code, v.label])); role.required = true;
        let token = 0;
        const populate = (items) => { contact.replaceChildren(new Option('Selecione um contato', ''), ...items.map((v) => new Option(v.display_name, v.id))); }; populate(local.contacts);
        const modal = simpleForm('Vincular contato', [['Pesquisar contato', search, 'contact_search', 'Reutilize o cadastro de Contatos. Informe o nome e escolha Pesquisar.'], ['Contato', contact, 'law_contact_id'], ['Papel processual', role, 'case_role']], async () => { await request(`${apiPath(id)}/contacts`, { method: 'POST', body: { law_contact_id: contact.value, case_role: role.value } }); await detail(id); }, 'Vincular contato', event?.currentTarget);
        const find = button('Pesquisar contatos', action(async () => { const requestToken = ++token; const data = await references(result.case.law_unit_id, search.value); if (modal.element.isConnected && token === requestToken) populate(data.contacts); })); modal.body.prepend(find);
      });
    }
    function renderRelations(result) {
      const id = result.case.id;
      linkedSection('Processos relacionados', result.relations.map((item) => ({ ...item, label: `${text(item.relation_type)} · ${cnj(item.case_number)}` })), (item) => request(`${apiPath(id)}/relations/${item.id}`, { method: 'DELETE' }), (event) => {
        const number = input('', 'text', 25); number.required = true;
        const kind = select(['dependent', 'apenso'].map((v) => [v, text(v)]));
        simpleForm('Relacionar processo', [['Número CNJ relacionado', number, 'related_case_number'], ['Tipo de vínculo', kind, 'relation_type', 'Vínculo informativo. Estado, sigilo e autorizações continuam independentes.']], async () => { await request(`${apiPath(id)}/relations`, { method: 'POST', body: { related_case_number: number.value, relation_type: kind.value } }); await detail(id); }, 'Relacionar processo', event?.currentTarget);
      });
    }
    async function renderAccess(result, local) {
      const id = result.case.id; const accesses = await request(`${apiPath(id)}/access`); if (!active() || currentId !== id) return;
      const box = section('Autorizações nominais'); box.body.append($('p', '', 'Somente as pessoas autorizadas abaixo podem consultar este processo, inclusive em outras unidades.'));
      accesses.users.forEach((item) => { const row = toolbar(); row.append($('span', '', item.name), button('Revogar acesso', action(async () => {
        simpleForm(`Revogar acesso de ${item.name}?`, [], async () => { await request(`${apiPath(id)}/access/${item.id}`, { method: 'DELETE' }); body.replaceChildren(); await list(); }, 'Revogar acesso');
      }))); box.body.append(row); });
      const available = local.members.filter((v) => !accesses.users.some((a) => a.company_membership_id === v.id));
      if (available.length) box.body.append(button('Conceder acesso', action((event) => {
        const member = select(available.map((v) => [v.id, v.name])); simpleForm('Conceder autorização nominal', [['Usuário da empresa', member, 'company_membership_id']], async () => { await request(`${apiPath(id)}/access`, { method: 'POST', body: { company_membership_id: member.value } }); await detail(id); }, 'Conceder acesso', event?.currentTarget);
      }))); body.append(box);
    }
    async function configure() {
      if (!refs?.units?.length) { body.append($('p', 'fs-alert fs-alert-info', 'Selecione uma unidade ativa no menu para consultar as configurações disponíveis.')); return; }
      const unit = select(refs.units.map((v) => [v.id, v.name]), refs.selected_unit_id);
      const unitField = field('Unidade', unit); unitField.classList.add('law-process-settings-unit-field');
      const unitBox = section('Unidade ativa', 'UND'); unitBox.classList.add('law-process-settings-unit'); unitBox.body.classList.add('law-process-settings-unit-body'); unitBox.body.append(unitField);
      const host = $('div', 'law-process-settings-grid');
      body.append(unitBox, host);
      async function draw() {
        const local = await references(unit.value); refs = local; host.replaceChildren();
        if (!local.can_configure) { host.append($('p', 'fs-alert fs-alert-warning', 'Você não pode configurar esta unidade.')); return; }
        const cnjBox = section('Padrão do número CNJ', 'CNJ'); cnjBox.classList.add('law-process-settings-cnj'); cnjBox.body.classList.add('law-process-settings-cnj-body');
        const segment = select([['', 'Selecione o segmento'], ...(local.cnj_segments || []).map((item) => [item.code, `${item.code} - ${item.name}`])], local.cnj_defaults?.segment || ''); segment.required = true;
        const court = select([['', 'Selecione o tribunal']]); court.required = true;
        const updateCourts = (preferred = '') => {
          const options = (local.cnj_courts || []).filter((item) => item.segment === segment.value);
          court.replaceChildren(new Option('Selecione o tribunal', '', false, preferred === ''));
          options.forEach((item) => court.add(new Option(`${item.code} - ${item.name}`, item.code, false, item.code === preferred)));
          if (preferred && !options.some((item) => item.code === preferred)) court.value = '';
        };
        updateCourts(local.cnj_defaults?.court || '');
        segment.addEventListener('change', () => updateCourts());
        const origin = input(local.cnj_defaults?.origin || '', 'text', 4); origin.inputMode = 'numeric'; origin.placeholder = '0103'; origin.required = true; origin.pattern = '\\d{4}';
        origin.addEventListener('input', () => { origin.value = origin.value.replace(/\D/g, '').slice(0, 4); });
        const cnjForm = $('form', 'law-process-settings-cnj-form'); const cnjError = message(); const cnjSave = button('Salvar padrão CNJ', null, true); cnjSave.type = 'submit';
        cnjForm.append(field('Segmento', segment), field('Tribunal', court), field('Comarca/unidade de origem (4 dígitos)', origin, 'origin', 'Informe exatamente os quatro dígitos definidos para a comarca ou unidade de origem.'), cnjError, cnjSave);
        bindForm(cnjForm, cnjSave, cnjError, async () => { await request('/settings/cnj-defaults', { method: 'PUT', body: { law_unit_id: unit.value, segment: segment.value, court: court.value, origin: origin.value } }); await draw(); });
        cnjBox.body.append(cnjForm); host.append(cnjBox);
        for (const [type, title, values, property] of [['statuses', 'Estados operacionais', local.statuses, 'label'], ['tags', 'Etiquetas', local.tags, 'name'], ['roles', 'Complementos de papéis processuais', local.roles, 'label']]) {
          const box = section(title); box.classList.add('law-process-settings-options', `law-process-settings-${type}`); box.body.classList.add('law-process-settings-options-body');
          const list = $('div', 'law-process-settings-list');
          values.forEach((item) => {
            const row = toolbar(); row.classList.add('law-process-settings-option'); row.append($('span', 'law-process-settings-option-name', item[property]));
            if (!['active', 'archived'].includes(item.code)) {
              const remove = button('', action(async () => { await request(`/options/${type}/${item.id}`, { method: 'DELETE' }); await draw(); })); remove.className = 'fs-btn fs-btn-icon fs-btn-icon-plain law-process-settings-option-remove';
              remove.setAttribute('aria-label', `Desativar ${item[property]}`); remove.title = 'Desativar';
              const icon = document.createElement('img'); icon.src = '/portal/assets/icons/Subtract-Circle--Streamline-Ultimate.png'; icon.alt = ''; icon.setAttribute('aria-hidden', 'true');
              remove.append(icon); row.append(remove);
            }
            list.append(row);
          });
          box.body.append(list);
          const form = $('form', 'law-process-settings-add-form'); const name = input('', 'text', type === 'tags' ? 64 : 80); name.required = true; name.placeholder = type === 'tags' ? 'Ex.: Prioridade' : 'Digite um nome'; const error = message(); const save = button('Adicionar', null, true); save.type = 'submit'; form.append(field(type === 'tags' ? 'Nome da etiqueta' : 'Nome da opção', name, property), error, save); box.body.append(form);
          bindForm(form, save, error, async () => { await request(`/options/${type}`, { method: 'POST', body: { law_unit_id: unit.value, [property]: name.value.trim() } }); await draw(); }); host.append(box);
        }
      }
      unit.addEventListener('change', action(draw)); await draw();
    }
    function manageAccess() {
      const number = input('', 'text', 25); number.required = true;
      const lookup = $('form', 'fs-stack fs-stack-gap-3'); const lookupError = message(); const submit = button('Consultar autorizações', null, true); submit.type = 'submit';
      const results = $('div', 'fs-stack fs-stack-gap-3');
      lookup.append(field('Número CNJ', number, 'case_number', 'A gestão verifica sua autoridade na unidade proprietária e não abre o conteúdo do processo.'), lookupError, submit);
      body.append(lookup, results);
      async function loadAccess() {
        const found = await request(`/access-management?${new URLSearchParams({ case_number: number.value })}`);
        const local = await references(found.law_unit_id); const grants = await request(`${apiPath(found.case_id)}/access`);
        if (!active()) return;
        results.replaceChildren();
        const box = section(`Autorizações · ${number.value}`, 'ACESSO', `Unidade: ${local.units.find((unit) => unit.id === found.law_unit_id)?.name || 'Unidade do processo'}`);
        if (!grants.users.length) box.body.append($('p', 'fs-alert fs-alert-info', 'Nenhuma autorização nominal ativa para este processo.'));
        grants.users.forEach((user) => {
          const row = toolbar(); row.append($('span', '', user.name), button('Revogar', action(async () => { await request(`${apiPath(found.case_id)}/access/${user.id}`, { method: 'DELETE' }); await loadAccess(); })));
          box.body.append(row);
        });
        const members = local.members.filter((member) => !grants.users.some((granted) => granted.company_membership_id === member.id));
        if (members.length) {
          const form = $('form', 'fs-stack fs-stack-gap-2'); const member = select(members.map((item) => [item.id, item.name])); const error = message(); const save = button('Conceder acesso', null, true); save.type = 'submit';
          form.append(field('Usuário da empresa', member, 'company_membership_id'), error, save); box.body.append(form);
          bindForm(form, save, error, async () => { await request(`${apiPath(found.case_id)}/access`, { method: 'POST', body: { company_membership_id: member.value } }); await loadAccess(); });
        }
        results.append(box);
      }
      bindForm(lookup, submit, lookupError, loadAccess);
    }
    try {
      if (context.active_unit_id) await getRefs(context.active_unit_id);
      if (initialCaseId) await detail(initialCaseId);
      else if (pageView === 'processes-settings') await configure();
      else if (pageView === 'processes-access') manageAccess();
      else await list();
    } catch (error) { report(error); }
  }
  window.FokusLawProcesses = { render, openCase: (root, context, id) => render(root, context, id) };
})();
