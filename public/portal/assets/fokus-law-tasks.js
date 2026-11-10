/* Reconstructed manual task UI. All untrusted content is rendered as text. */
(() => {
  let generation = 0;
  let dispose = () => {};
  const node = (tag, text = '', cls = '') => { const el = document.createElement(tag); el.textContent = text; el.className = cls; return el; };
  const button = (text, fn) => { const el = node('button', text, 'fs-btn fs-btn-outline-primary'); el.type = 'button'; el.addEventListener('click', fn); return el; };
  const request = (path = '', options) => window.FokusApi.request(`/law/tasks${path}`, options);
  const path = (id) => `/${encodeURIComponent(id)}`;
  const date = (value) => value ? new Date(`${String(value).slice(0, 10)}T12:00:00`).toLocaleDateString('pt-BR') : 'Sem prazo';
  async function render(root, context) {
    dispose(); const session = ++generation;
    const heading = node('header', '', 'law-page-heading'); heading.append(node('h2', 'Tarefas e prazos'), node('p', 'Organize as tarefas manuais do escritório.', 'law-page-lede'));
    const notice = node('p', '', 'fs-alert fs-alert-danger'); notice.hidden = true; notice.setAttribute('role', 'alert');
    const body = node('div', '', 'fs-stack fs-stack-gap-3'); root.replaceChildren(heading, notice, body);
    const active = () => generation === session && root.isConnected && root.contains(body);
    const can = (action) => context.company?.role === 'admin' || (context.law_permissions || []).includes(`law.tasks.${action}`);
    let token = 0, page = 1, current = null, modal = null;
    const report = (error) => { if (active()) { notice.hidden = false; notice.textContent = error.message || 'Não foi possível carregar as tarefas.'; } };
    function close() { if (modal) { const old = modal; modal = null; old.close(); old.remove(); } }
    function navigate(id, nextPage = page) {
      const url = new URL(location.href); url.searchParams.set('law_tasks_page', String(nextPage));
      if (id) url.searchParams.set('law_task', id); else url.searchParams.delete('law_task');
      history.pushState({ ...history.state, lawTasks: true }, '', url); return load(id, nextPage);
    }
    async function load(id = null, nextPage = page) {
      close(); const view = ++token; current = id; page = nextPage; notice.hidden = true;
      try {
        const result = await request(id ? path(id) : `?page=${page}&per_page=20`);
        if (!active() || view !== token) return;
        body.replaceChildren();
        if (id) detail(result); else list(result);
      } catch (error) { if (view === token) report(error); }
    }
    function list(result) {
      if (can('create')) body.append(button('Nova tarefa', () => edit()));
      const table = node('table', '', 'fs-table'); const head = node('tr'); ['Título', 'Prazo', 'Situação', 'Ações'].forEach((v) => head.append(node('th', v))); table.append(head);
      for (const task of result.data || []) { const row = node('tr'); row.append(node('td', task.title), node('td', date(task.due_on)), node('td', task.status === 'completed' ? 'Concluída' : 'Pendente')); const actions = node('td'); actions.append(button('Abrir', () => navigate(task.id))); row.append(actions); table.append(row); }
      body.append(table);
      if (!(result.data || []).length) body.append(node('p', 'Nenhuma tarefa encontrada.'));
      const navigation = node('nav'); navigation.setAttribute('aria-label', 'Paginação de tarefas');
      const previous = button('Anterior', () => navigate(null, page - 1)); previous.disabled = page <= 1;
      const next = button('Próxima', () => navigate(null, page + 1)); next.disabled = page >= (result.last_page || 1);
      const guarded = (control) => control.addEventListener('click', () => { previous.disabled = true; next.disabled = true; }, { once: true }); guarded(previous); guarded(next);
      navigation.append(previous, node('span', `Página ${page} de ${result.last_page || 1}; ${result.total || 0} tarefas`), next); body.append(navigation);
    }
    function detail(result) {
      const task = result.task; body.append(button('Voltar', () => navigate(null)), node('h3', task.title), node('p', task.description || 'Sem descrição'), node('p', date(task.due_on)), node('p', task.status === 'completed' ? 'Concluída' : 'Pendente'));
      if (can('update')) body.append(button('Editar', () => edit(task)));
      if (can('status.update')) body.append(button(task.status === 'completed' ? 'Reabrir' : 'Concluir', (event) => write(event.currentTarget, `${path(task.id)}/status`, { method: 'POST', body: { version: task.version, status: task.status === 'completed' ? 'pending' : 'completed' } }, task.id)));
      if (can('delete')) body.append(button('Excluir', () => confirmDelete(task)));
      body.append(node('h4', 'Histórico'));
      (result.events || []).forEach((event) => body.append(node('p', `${event.event_type === 'created' ? 'Tarefa criada' : event.event_type === 'deleted' ? 'Tarefa excluída' : 'Tarefa atualizada'}: ${JSON.stringify(event.after_state || {})}`)));
    }
    async function write(control, url, options, id) {
      if (control.disabled) return; control.disabled = true; const view = token;
      try { await request(url, options); if (active() && view === token) await load(id); }
      catch (error) { if (view === token) report(error); }
      finally { if (control.isConnected) control.disabled = false; }
    }
    function dialog(title) {
      close(); const el = node('dialog', '', 'fs-card law-record-editor'); el.setAttribute('aria-label', title); el.append(node('h3', title));
      const dismiss = button('Fechar', close); el.append(dismiss); el.addEventListener('cancel', () => { modal = null; el.remove(); });
      root.append(el); modal = el; el.showModal(); return el;
    }
    function confirmDelete(task) {
      const el = dialog('Excluir tarefa'); el.append(node('p', 'Excluir esta tarefa? O histórico será preservado.'));
      const confirm = button('Confirmar exclusão', async () => { if (confirm.disabled) return; confirm.disabled = true; const view = token;
        try { await request(path(task.id), { method: 'DELETE', body: { version: task.version } }); if (active() && view === token && modal === el) await navigate(null); }
        catch (error) { if (modal === el) { el.append(node('p', error.message, 'fs-alert fs-alert-danger')); confirm.disabled = false; } }
      }); el.append(confirm);
    }
    async function edit(task = null) {
      const el = dialog(task ? 'Editar tarefa' : 'Nova tarefa'); const view = token;
      const form = node('form', '', 'fs-stack fs-stack-gap-3'); el.append(form);
      const input = (type, value, max) => { const control = node('input', '', 'fs-form-control'); control.type = type; control.value = value || ''; if (max) control.maxLength = max; return control; };
      const field = (label, control) => { const wrapper = node('label', label, 'law-record-field'); wrapper.append(control); form.append(wrapper); };
      const title = input('text', task?.title, 180); title.required = true; field('Título', title);
      const description = node('textarea', '', 'fs-form-control'); description.value = task?.description || ''; description.maxLength = 5000; field('Descrição', description);
      const due = input('date', task?.due_on); field('Prazo', due);
      const responsible = node('select', '', 'fs-form-select'); responsible.append(new Option('Sem responsável', '')); field('Responsável', responsible);
      const cases = node('select', '', 'fs-form-select'); cases.append(new Option('Sem processo vinculado', '')); if (!task) field('Processo', cases);
      if (!task) {
        const search = input('text', '', 180); field('Pesquisar número do processo', search);
        let searchToken = 0;
        const searchButton = button('Pesquisar processos', async () => {
          const sequence = ++searchToken; searchButton.disabled = true;
          try {
            const refs = await request(`/references?${new URLSearchParams({ case_q: search.value })}`);
            if (!active() || view !== token || modal !== el || sequence !== searchToken) return;
            const selected = cases.value, previous = [...cases.options].find(item => item.value === selected);
            cases.replaceChildren(new Option('Sem processo vinculado', ''));
            (refs.cases?.data || []).forEach(item => cases.append(new Option(item.case_number, item.id)));
            if (selected && previous && !(refs.cases?.data || []).some(item => item.id === selected)) cases.append(previous);
            cases.value = selected;
          } catch (failure) { if (modal === el) { error.hidden = false; error.textContent = failure.message; } }
          finally { if (searchButton.isConnected) searchButton.disabled = false; }
        }); form.append(searchButton);
      }
      const error = node('p', '', 'fs-alert fs-alert-danger'); error.hidden = true; error.setAttribute('role', 'alert');
      const save = button('Salvar', () => {}); save.type = 'submit'; save.disabled = true; form.append(error, save);
      const key = crypto.randomUUID(); let busy = false;
      try {
        const refs = await request('/references'); if (!active() || view !== token || modal !== el) return;
        (refs.responsibles || []).forEach((member) => responsible.append(new Option(member.name, member.id)));
        if (task?.responsible_membership_id && !(refs.responsibles || []).some((member) => member.id === task.responsible_membership_id)) responsible.append(new Option('Responsável atual indisponível', task.responsible_membership_id));
        responsible.value = task?.responsible_membership_id || '';
        (refs.cases?.data || []).forEach((item) => cases.append(new Option(item.case_number, item.id)));
        save.disabled = false;
      } catch (failure) { if (modal === el) { error.hidden = false; error.textContent = failure.message; } }
      form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (busy || save.disabled || modal !== el) return; busy = true; save.disabled = true; error.hidden = true;
        const payload = { title: title.value, description: description.value || null, due_on: due.value || null };
        if (!task || responsible.value !== (task.responsible_membership_id || '')) payload.responsible_membership_id = responsible.value || null;
        if (task) payload.version = task.version; else { payload.request_key = key; payload.law_case_id = cases.value || null; }
        try {
          await request(task ? path(task.id) : '', { method: task ? 'PATCH' : 'POST', body: payload });
          if (active() && view === token && modal === el) { close(); await load(task?.id || null); }
        } catch (failure) { if (modal === el) { error.hidden = false; error.textContent = failure.message || 'Não foi possível salvar. Tente novamente.'; } }
        finally { busy = false; if (save.isConnected) save.disabled = false; }
      });
    }
    const popstate = () => { if (active()) { const params = new URL(location.href).searchParams; load(params.get('law_task'), Math.max(1, Number(params.get('law_tasks_page')) || 1)); } };
    window.addEventListener('popstate', popstate);
    dispose = () => { close(); ++token; window.removeEventListener('popstate', popstate); };
    const params = new URL(location.href).searchParams;
    await load(params.get('law_task'), Math.max(1, Number(params.get('law_tasks_page')) || 1));
  }
  window.FokusLawTasks = { render };
})();
