(() => {
  const node = (tag, className = '', text = '') => {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (text) item.textContent = text;
    return item;
  };
  const request = (path, options) => window.FokusApi.request(path, options);
  const profileLabel = (role) => ({ unit_admin: 'Administrador do setor', chief_clerk: 'Chefe / Escrivão', operator: 'Operador', viewer: 'Somente leitura' })[role] || role;
  const statusLabel = (status) => ({ ativo: 'Ativo', pendente: 'Convite pendente', suspenso: 'Suspenso', removido: 'Removido' })[status] || status;

  async function render(context, region, success = '') {
    document.title = 'Usuários, perfis e permissões | Fokus Law';
    region.replaceChildren();
    const heading = node('header', 'law-users-heading');
    heading.append(node('p', 'law-page-eyebrow', 'ACESSO POR SETOR'), node('h2', '', 'Usuários, perfis e permissões'), node('p', 'law-page-lede', 'Defina em quais setores cada pessoa atua e quais ações seu perfil pode executar.'));
    const feedback = node('p', 'law-users-feedback', success); feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite'); if (success) feedback.dataset.state = 'success';
    region.append(heading, feedback);
    const units = (context.units || []).filter((unit) => unit.status === 'ativo');
    const unitId = context.active_unit_id;
    if (!units.length) {
      const empty = node('section', 'law-profile-card law-users-empty'); empty.append(node('h3', '', 'Nenhum setor disponível'), node('p', '', 'Crie um setor ou solicite que o admin da empresa atribua seu acesso antes de convidar pessoas.')); region.append(empty); return;
    }
    if (!unitId) { region.append(node('p', 'law-profile-loading', 'Selecione um setor no menu lateral para administrar usuários e perfis.')); return; }

    let roles = [];
    try { roles = (await request(`/law/access/roles?law_unit_id=${encodeURIComponent(unitId)}`)).roles || []; }
    catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível carregar os perfis deste setor.'; return; }
    const roleById = new Map(roles.map((role) => [role.id, role]));
    const assignable = roles.filter((role) => role.assignable);
    const canManageUsers = context.permissions.manage_company_users;
    const canManageRoles = context.permissions.manage_law_roles;
    const layout = node('div', 'law-users-layout');

    if (canManageUsers) {
      const invite = node('section', 'law-profile-card law-users-invite');
      invite.append(node('h3', '', 'Convidar pessoa'), node('p', '', 'Escolha um perfil para cada setor antes de enviar o convite.'));
      const form = node('form', 'law-profile-form law-users-form'); form.noValidate = true;
      [['name', 'Nome completo', 'text'], ['cpf', 'CPF', 'text'], ['email', 'E-mail', 'email']].forEach(([name, labelText, type]) => {
        const label = node('label', 'law-profile-field law-users-field'); label.htmlFor = `invite-${name}`; label.append(node('span', '', labelText));
        const input = node('input', 'fs-form-control'); input.id = `invite-${name}`; input.name = name; input.type = type; input.required = true;
        if (name === 'cpf') { input.inputMode = 'numeric'; input.maxLength = 14; input.placeholder = '000.000.000-00'; }
        if (name === 'email') input.autocomplete = 'email'; label.append(input); form.append(label);
      });
      const assignmentSet = node('fieldset', 'law-access-assignments'); assignmentSet.append(node('legend', '', 'Setores e perfis'));
      const selections = new Map();
      units.forEach((unit) => {
        const row = node('div', 'law-access-assignment'); const label = node('label');
        const checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.value = unit.id; label.append(checkbox, node('span', '', unit.name));
        const select = node('select', 'fs-form-select'); select.setAttribute('aria-label', `Perfil para ${unit.name}`); select.disabled = true;
        row.append(label, select); assignmentSet.append(row); selections.set(unit.id, { checkbox, select });
        checkbox.addEventListener('change', async () => {
          select.replaceChildren(); select.disabled = !checkbox.checked; if (!checkbox.checked) return;
          try {
            const result = unit.id === unitId ? { roles } : await request(`/law/access/roles?law_unit_id=${encodeURIComponent(unit.id)}`);
            const options = (result.roles || []).filter((role) => role.assignable);
            options.forEach((role) => select.append(new Option(role.name, role.id)));
            if (!options.length) select.append(new Option('Nenhum perfil atribuível', ''));
          } catch (error) { select.append(new Option(error.message || 'Falha ao carregar perfis', '')); }
        });
      });
      form.append(assignmentSet);
      const submit = node('button', 'fs-btn fs-btn-primary', 'Enviar convite'); submit.type = 'submit'; submit.style.alignSelf = 'center'; form.append(submit); invite.append(form); layout.append(invite);
      form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (!form.reportValidity()) return;
        const cpfField = form.elements.namedItem('cpf'); const cpf = cpfField.value.replace(/\D/g, '');
        if (!window.FokusLawUserCpfValid?.(cpf)) { feedback.dataset.state = 'error'; feedback.textContent = 'Informe um CPF válido com 11 dígitos.'; cpfField.focus(); return; }
        const law_assignments = [...selections].filter(([, item]) => item.checkbox.checked).map(([selectedUnit, item]) => ({ unit_id: selectedUnit, role_id: item.select.value }));
        if (!law_assignments.length || law_assignments.some((assignment) => !assignment.role_id)) { feedback.dataset.state = 'error'; feedback.textContent = 'Selecione ao menos um setor e um perfil válido para cada setor.'; return; }
        submit.disabled = true; submit.textContent = 'Enviando convite…';
        try { const payload = Object.fromEntries(new FormData(form)); await request('/portal/users', { method: 'POST', body: { ...payload, cpf, law_assignments } }); await render(context, region, 'Convite enviado. Os acessos serão ativados quando a pessoa aceitar.'); }
        catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível enviar o convite.'; }
        finally { submit.disabled = false; submit.textContent = 'Enviar convite'; }
      });
    }

    const roleCard = node('section', 'law-profile-card law-users-profiles');
    roleCard.append(node('h3', '', `Perfis de ${context.active_unit?.name || 'setor'}`), node('p', '', 'Os perfis padrão são protegidos. Perfis personalizados usam somente permissões que você possui.'));
    const roleList = node('div', 'law-custom-role-list');
    roles.forEach((role) => {
      const item = node('article', 'law-custom-role'); const copy = node('div');
      copy.append(node('strong', '', role.name), node('small', '', role.is_system ? `Perfil padrão · ${profileLabel(role.code)}` : `${role.permissions.length} permissões`)); item.append(copy);
      if (canManageRoles && !role.is_system) { const edit = node('button', 'fs-btn fs-btn-outline-primary', 'Editar'); edit.type = 'button'; edit.addEventListener('click', () => roleEditor(role)); item.append(edit); }
      roleList.append(item);
    });
    roleCard.append(roleList);
    if (canManageRoles) { const create = node('button', 'fs-btn fs-btn-outline-primary', 'Criar perfil personalizado'); create.type = 'button'; create.addEventListener('click', () => roleEditor()); roleCard.append(create); }
    layout.append(roleCard); region.append(layout);

    async function roleEditor(role = null) {
      let catalog;
      try { catalog = (await request(`/law/access/permissions?law_unit_id=${encodeURIComponent(unitId)}`)).permissions || []; }
      catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível carregar as permissões.'; return; }
      region.querySelector('.law-role-editor')?.remove();
      const form = node('form', 'law-role-editor law-profile-card'); form.append(node('h3', '', role ? `Editar ${role.name}` : 'Novo perfil personalizado'));
      const nameLabel = node('label', 'law-profile-field'); nameLabel.append(node('span', '', 'Nome do perfil'));
      const name = node('input', 'fs-form-control'); name.value = role?.name || ''; name.maxLength = 100; name.required = true; nameLabel.append(name); form.append(nameLabel);
      const fieldset = node('fieldset', 'law-role-permissions'); fieldset.append(node('legend', '', 'Permissões disponíveis'));
      const selected = new Set(role?.permissions || []);
      catalog.filter((permission) => permission.granted_to_actor).forEach((permission) => { const label = node('label'); const input = node('input'); input.type = 'checkbox'; input.value = permission.code; input.checked = selected.has(permission.code); label.append(input, node('span', '', permission.description)); fieldset.append(label); });
      form.append(fieldset); const error = node('p', 'law-users-feedback'); error.setAttribute('role', 'alert'); form.append(error);
      const save = node('button', 'fs-btn fs-btn-primary', 'Salvar perfil'); save.type = 'submit'; const cancel = node('button', 'fs-btn fs-btn-outline-secondary', 'Cancelar'); cancel.type = 'button'; cancel.addEventListener('click', () => form.remove()); form.append(save, cancel); region.append(form); name.focus();
      form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (!form.reportValidity()) return;
        const permission_codes = [...fieldset.querySelectorAll('input:checked')].map((input) => input.value);
        if (!permission_codes.length) { error.textContent = 'Selecione ao menos uma permissão.'; return; }
        save.disabled = true;
          try { await request(role ? `/law/access/roles/${encodeURIComponent(role.id)}` : '/law/access/roles', { method: role ? 'PATCH' : 'POST', body: { law_unit_id: unitId, name: name.value, permission_codes, ...(role ? { version: role.version } : {}) } }); await render(context, region, 'Perfil salvo.'); }
        catch (failure) { error.textContent = failure.message || 'Não foi possível salvar o perfil.'; save.disabled = false; }
      });
    }

    const listCard = node('section', 'law-profile-card law-users-list-card');
    const listHeader = node('div', 'law-profile-card-heading law-users-list-heading'); listHeader.append(node('h3', '', 'Pessoas com acesso ao setor'));
    if (context.permissions.transfer_admin) { const transfer = node('a', 'fs-btn fs-btn-outline-primary law-users-transfer', 'Transferir administração'); transfer.href = '/portal/transferir-administracao'; listHeader.append(transfer); }
    const listStatus = node('p', 'law-profile-loading', 'Carregando usuários…'); listStatus.setAttribute('role', 'status'); listStatus.setAttribute('aria-live', 'polite');
    const userList = node('div', 'law-users-list'); userList.setAttribute('aria-busy', 'true'); listCard.append(listHeader, listStatus, userList); region.append(listCard);
    async function updateAccess(user, law, changes, button) {
      button.disabled = true; feedback.textContent = 'Salvando alteração…'; feedback.dataset.state = '';
      try { await request(`/portal/users/${encodeURIComponent(user.id)}/law-access`, { method: 'PUT', body: { law_unit_id: unitId, ...(law ? { version: law.version } : {}), ...changes } }); await render(context, region, law ? 'Acesso atualizado.' : 'Acesso concedido.'); }
      catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível atualizar o acesso.'; button.disabled = false; }
    }
    try {
      const users = await request('/portal/users'); userList.replaceChildren();
      if (!users.length) listStatus.textContent = 'Nenhuma pessoa possui vínculo com este setor.';
      else {
        listStatus.hidden = true;
        users.forEach((user) => {
          const law = (user.law_memberships || []).find((item) => item.unit_id === unitId);
          const card = node('article', 'law-users-row'); const details = node('div', 'law-users-row-details');
          const identity = node('div', 'law-users-identity'); identity.append(node('h4', '', user.name || 'Usuário'), node('p', '', user.email || 'E-mail não informado'));
          const metadata = node('dl', 'law-users-metadata');
          [[ 'Perfil neste setor', law?.role_name || (user.role === 'admin' ? 'Admin global' : 'Sem atribuição') ], [ 'Situação', statusLabel(law?.status || user.status) ]].forEach(([labelText, value]) => { const field = node('div', 'law-users-meta-item'); field.append(node('dt', '', labelText), node('dd', '', value)); metadata.append(field); });
          details.append(identity, metadata); card.append(details);
          if (canManageUsers && !law && user.role !== 'admin') {
            const actions = node('div', 'law-users-row-actions'); const select = node('select', 'fs-form-select'); select.setAttribute('aria-label', `Perfil de ${user.name} neste setor`);
            assignable.forEach((role) => select.append(new Option(role.name, role.id)));
            const grant = node('button', 'fs-btn fs-btn-outline-primary', 'Conceder acesso'); grant.type = 'button'; grant.disabled = !select.value;
            grant.addEventListener('click', () => updateAccess(user, null, { law_access_role_id: select.value }, grant)); actions.append(select, grant); card.append(actions);
          } else if (canManageUsers && law && user.role !== 'admin') {
            const actions = node('div', 'law-users-row-actions'); const select = node('select', 'fs-form-select'); select.setAttribute('aria-label', `Perfil de ${user.name}`);
            assignable.forEach((role) => select.append(new Option(role.name, role.id, false, role.id === law.role_id)));
            const save = node('button', 'fs-btn fs-btn-outline-primary', 'Salvar perfil'); save.type = 'button'; save.disabled = !select.value || select.value === law.role_id;
            select.addEventListener('change', () => { save.disabled = select.value === law.role_id; }); save.addEventListener('click', () => updateAccess(user, law, { law_access_role_id: select.value }, save)); actions.append(select, save);
            const state = node('button', 'fs-btn fs-btn-outline-secondary', law.status === 'removido' ? 'Restaurar acesso' : law.status === 'suspenso' ? 'Reativar acesso' : 'Suspender acesso'); state.type = 'button';
            state.addEventListener('click', async () => {
              if (law.status === 'removido') { state.disabled = true; try { await request(`/portal/users/${encodeURIComponent(user.id)}/law-access/restore`, { method: 'POST', body: { law_unit_id: unitId, version: law.version } }); await render(context, region, 'Acesso restaurado.'); } catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message; state.disabled = false; } }
              else updateAccess(user, law, { status: law.status === 'suspenso' ? 'ativo' : 'suspenso' }, state);
            }); actions.append(state);
            const remove = node('button', 'fs-btn fs-btn-outline-primary', 'Remover do setor'); remove.type = 'button'; remove.addEventListener('click', () => { if (window.confirm(`Remover ${user.name} do setor ${context.active_unit?.name || ''}?`)) updateAccess(user, law, { status: 'removido' }, remove); }); actions.append(remove); card.append(actions);
            if (user.status === 'pendente' && context.permissions.transfer_admin) {
              const cancelInvite = node('button', 'fs-btn fs-btn-outline-primary law-users-remove-button', 'Cancelar convite'); cancelInvite.type = 'button';
              cancelInvite.addEventListener('click', async () => {
                if (!window.confirm(`Cancelar o convite pendente para ${user.name}? A pessoa não poderá aceitá-lo.`)) return;
                cancelInvite.disabled = true;
                try { await request(`/portal/users/${encodeURIComponent(user.id)}`, { method: 'PATCH', body: { status: 'removido', version: user.version } }); await render(context, region, 'Convite cancelado. A pessoa não poderá aceitar nem acessar a empresa.'); }
                catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível cancelar o convite.'; cancelInvite.disabled = false; }
              }); actions.append(cancelInvite);
            }
          }
          userList.append(card);
        });
      }
      userList.setAttribute('aria-busy', 'false');
    } catch (error) { listStatus.dataset.state = 'error'; listStatus.textContent = error.message || 'Não foi possível carregar os usuários.'; userList.setAttribute('aria-busy', 'false'); }
  }
  async function renderTransfer(context, region) {
    document.title = 'Transferir administração | Fokus Law'; region.replaceChildren();
    const heading = node('header', 'law-users-heading'); heading.append(node('p', 'law-page-eyebrow', 'ADMINISTRAÇÃO DA EMPRESA'), node('h2', '', 'Transferir administração'), node('p', 'law-page-lede', 'A nova pessoa se tornará o único admin da empresa após aceitar o convite por e-mail.')); region.append(heading);
    if (!context.permissions.transfer_admin) { region.append(node('p', 'law-users-feedback', 'Somente o admin da empresa pode iniciar uma transferência.')); return; }
    const grid = node('div', 'law-transfer-grid'); const card = node('section', 'law-profile-card law-transfer-card');
    card.append(node('h3', '', 'Escolher novo admin'), node('p', '', 'O sucessor precisa ter vínculo ativo e e-mail confirmado. A transferência só é concluída após o aceite.'));
    const form = node('form', 'law-profile-form');
    const personLabel = node('label', 'law-profile-field'); personLabel.append(node('span', '', 'Novo admin'));
    const person = node('select', 'fs-form-select'); person.required = true; person.append(new Option('Carregando pessoas…', '')); personLabel.append(person); form.append(personLabel);
    const passwordLabel = node('label', 'law-profile-field'); passwordLabel.append(node('span', '', 'Sua senha atual'));
    const password = node('input', 'fs-form-control'); password.type = 'password'; password.autocomplete = 'current-password'; password.required = true; passwordLabel.append(password); form.append(passwordLabel);
    const keepLabel = node('label', 'law-transfer-keep'); const keep = node('input'); keep.type = 'checkbox'; keep.value = '1'; keepLabel.append(keep, node('span', '', 'Manter meu acesso como operador nos setores ativos após a transferência.')); form.append(keepLabel);
    const feedback = node('p', 'law-users-feedback'); feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite'); form.append(feedback);
    const submit = node('button', 'fs-btn fs-btn-primary', 'Enviar pedido de aceite'); submit.type = 'submit'; form.append(submit); card.append(form); grid.append(card);
    const historyCard = node('section', 'law-profile-card law-transfer-history'); historyCard.append(node('h3', '', 'Histórico de administração'));
    const history = node('div', 'law-users-list'); history.setAttribute('aria-busy', 'true'); history.append(node('p', 'law-profile-loading', 'Carregando histórico…')); historyCard.append(history); grid.append(historyCard); region.append(grid);
    try {
      const [users, events] = await Promise.all([request('/portal/users'), request('/portal/audit-history?scope=admin-transfer')]);
      person.replaceChildren(new Option('Selecione uma pessoa', ''));
      users.filter((user) => user.role !== 'admin' && user.status === 'ativo' && user.email_verified_at).forEach((user) => person.append(new Option(`${user.name} — ${user.email}`, user.id)));
      if (person.options.length === 1) person.options[0].textContent = 'Nenhum membro elegível para receber a administração';
      history.replaceChildren(); history.setAttribute('aria-busy', 'false');
      if (!events.length) history.append(node('p', 'law-profile-loading', 'Nenhuma transferência registrada.'));
      const operationNames = { admin_transfer_requested: 'Transferência iniciada', admin_transfer_accepted: 'Transferência concluída', admin_transfer_declined: 'Transferência recusada' };
      const membersById = new Map(users.map((user) => [String(user.id), user]));
      const readDetails = (raw) => { try { return typeof raw === 'string' ? JSON.parse(raw) : (raw || {}); } catch { return {}; } };
      events.forEach((entry) => {
        const member = membersById.get(String(entry.entity_id)); const name = member?.name || 'pessoa selecionada';
        const details = readDetails(entry.after_masked); let description;
        if (entry.operation === 'admin_transfer_requested') {
          const access = details.keep_previous_access ? 'Seu acesso será mantido como operador nos setores ativos.' : 'Seu acesso será removido após a conclusão.';
          description = `Pedido enviado para ${name}. Aguardando aceite. ${access}`;
        } else if (entry.operation === 'admin_transfer_accepted') {
          const retained = Boolean(details.previous_access_kept);
          description = `A administração foi transferida para ${name}. ${retained ? 'O acesso anterior foi mantido como operador nos setores ativos.' : 'O acesso anterior foi removido.'}`;
        } else {
          description = `${name} recusou a transferência de administração.`;
        }
        const row = node('article', 'law-transfer-event');
        const date = new Date(entry.created_at);
        row.append(node('strong', '', operationNames[entry.operation] || 'Evento de administração'), node('span', '', Number.isNaN(date.getTime()) ? '' : date.toLocaleString('pt-BR')), node('p', '', description)); history.append(row);
      });
    } catch (error) { history.replaceChildren(node('p', 'law-users-feedback', error.message || 'Não foi possível carregar o histórico.')); history.setAttribute('aria-busy', 'false'); }
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); if (!form.reportValidity() || !person.value) return;
      submit.disabled = true; submit.textContent = 'Enviando…'; feedback.textContent = '';
      try { const result = await request('/portal/transfer-admin', { method: 'POST', body: { membership_id: person.value, password: password.value, keep_previous_access: keep.checked } }); feedback.dataset.state = 'success'; feedback.textContent = result.message || 'Enviamos o pedido de aceite.'; form.reset(); }
      catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível iniciar a transferência.'; }
      finally { submit.disabled = false; submit.textContent = 'Enviar pedido de aceite'; }
    });
  }
  window.FokusLawAccessUsers = { render, renderTransfer };
})();
