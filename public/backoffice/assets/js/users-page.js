import { createRecordsDrawer } from "./records-drawer.js";

export async function mount(root, context = {}) {
    const $ = (selector) => root.querySelector(selector) || document.querySelector(selector);
    const api = context.api || window.FokusApi;
    const state = { page: 1, query: new URLSearchParams(location.search).get("q")?.trim() || "" };
    const list = $("#users-list");
    const drawer = $("#user-drawer");
    const trigger = $("#user-drawer-trigger");
    const form = $("#user-form");
    const message = $("#users-message");
    const canManage = context.admin?.role === "superadministrador" && (window.__backofficePermissions || new Set()).has("platform.security.manage");
    const isSuperadmin = context.admin?.role === "superadministrador";
    const iconBase = "/backoffice/assets/icons/";
    const actionIcons = {
        details: "Single-Man-Actions-Text--Streamline-Ultimate.png",
        edit: "Single-Man-Actions-Edit-1--Streamline-Ultimate.png",
        block: "Single-Man-Actions-Subtract--Streamline-Ultimate.png",
        deactivate: "Single-Neutral-Actions-Remove--Streamline-Ultimate.png",
        unblock: "Single-Man-Actions-Key--Streamline-Ultimate.png",
    };
    const drawerController = createRecordsDrawer({ trigger, drawer });
    const modal = window.FokusStyles?.Modal?.getOrCreateInstance($("#user-action-trigger"));
    let selected = null;
    let pendingAction = null;
    const esc = (value) => String(value ?? "").replace(/[&<>\x27"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\x27": "&#039;", "\"": "&quot;" })[c]);
    const labels = { plataforma: "Usuário interno - FokusCloud", empresa: "Usuário Externo - Assinatura", administrador_comercial: "Administrador comercial", superadministrador: "Superadministrador", Administrador: "Administrador", Gestor: "Gerente", Usuário: "Usuário comum", ativo: "Ativo", ativa: "Ativo", active: "Ativo", bloqueado: "Bloqueado", bloqueada: "Bloqueado", bloqueio_temporario: "Bloqueado", blocked: "Bloqueado", suspenso: "Suspenso", suspensa: "Suspenso", suspended: "Suspenso", pendente: "Pendente", pending: "Pendente", desativado: "Encerrado", desativada: "Encerrado", encerrado: "Encerrado", encerrada: "Encerrado", closed: "Encerrado", cancelamento_agendado: "Cancelamento agendado", inadimplente: "Inadimplente", aguardando_pagamento: "Aguardando pagamento" };
    const status = (value) => { const tone = ["ativo", "ativa", "active"].includes(value) ? "success" : ["pendente", "pending"].includes(value) ? "info" : ["bloqueado", "bloqueada", "bloqueio_temporario", "blocked", "suspenso", "suspensa", "suspended"].includes(value) ? "warning" : "secondary"; return `<span class="fs-badge fs-badge-soft-${tone}">${esc(labels[value] || value || "Sem status")}</span>`; };
    const showMessage = (text, tone = "danger") => { message.textContent = text || ""; message.dataset.tone = tone; message.hidden = !text; };
    const actionButton = (action, id, label) => `<button class="fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action${action === "deactivate" ? " fs-btn-danger" : ""}" type="button" data-user-action="${action}" data-user-id="${esc(id)}" aria-label="${esc(label)}" title="${esc(label)}"><img src="${iconBase}${actionIcons[action]}?v=20260923-user-management-law-context-v1" alt=""></button>`;
    const render = (response) => {
        const rows = response.data || [];
        list.innerHTML = rows.length ? rows.map((user) => {
            const actions = [actionButton("details", user.id, "Ver detalhes")];
            if (user.type === "plataforma" && canManage) {
                actions.push(actionButton("edit", user.id, "Editar"));
                if (user.status === "bloqueado" || user.status === "bloqueio_temporario") actions.push(actionButton("unblock", user.id, "Desbloquear"));
                else if (user.status === "ativo") actions.push(actionButton("block", user.id, "Bloquear"));
                if (user.status !== "desativado") actions.push(actionButton("deactivate", user.id, "Desativar"));
            }
            const rawProfile = user.profile || user.role || "";
            const profile = rawProfile ? rawProfile.split(" / ").map((item) => labels[item] || item).join(" / ") : (user.type === "plataforma" ? "Sem perfil" : "Sem perfil atual");
            const companyNames = (user.company_names || []).map((name) => String(name).trim()).filter(Boolean);
            const profileSubline = user.type === "empresa" ? (companyNames.length ? companyNames.join(" · ") : "Sem empresa vinculada") : "";
            return `<tr data-account-type="${esc(user.type)}"><td class="fs-width-600" data-label="Usuário"><strong>${esc(user.name || "-")}</strong><small class="fs-u-d-block fs-u-fs-sm">${esc(user.email || "-")}</small></td><td class="fs-width-400" data-label="Tipo">${esc(labels[user.type] || user.type)}</td><td class="fs-width-500" data-label="Perfil ou vínculos"><strong>${esc(profile)}</strong>${profileSubline ? `<small class="fs-u-d-block fs-u-fs-sm">${esc(profileSubline)}</small>` : ""}</td><td class="fs-width-300" data-label="Status">${status(user.status)}</td><td class="fs-width-400" data-label="Ações"><div class="fs-u-d-flex fs-u-flex-wrap fs-u-gap-2">${actions.join("")}</div></td></tr>`;
        }).join("") : '<tr><td colspan="5">Nenhuma conta encontrada.</td></tr>';
        const meta = response.meta || {};
        const page = Number(meta.current_page || 1), perPage = Number(meta.per_page || 15), total = Number(meta.total || 0), last = Math.max(1, Number(meta.last_page || 1));
        $("#users-table-summary").textContent = `${total.toLocaleString("pt-BR")} conta(s)`;
        $("#users-table-footer-summary").textContent = total ? `Mostrando ${(page - 1) * perPage + 1} a ${Math.min(page * perPage, total)} de ${total} contas` : "Nenhuma conta encontrada";
        $("#users-pagination").innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-users-page="${page - 1}" aria-label="Página anterior" ${page === 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><button class="fs-page-link" type="button" data-users-page="${page}" aria-label="Página ${page}" aria-current="page">${page}</button></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-users-page="${page + 1}" aria-label="Próxima página" ${page === last ? "disabled" : ""}>›</button></li></ul>`;
        window.refreshFokusDataTables?.();
    };
    const load = async () => {
        try {
            const params = new URLSearchParams({ page: String(state.page), per_page: "15" });
            if (state.query) params.set("q", state.query);
            render(await api.request(`/backoffice/directory/users?${params}`));
            showMessage("");
        } catch (error) { showMessage(error.message || "Não foi possível carregar os usuários."); }
    };
    let companiesLoaded = false;
    const setAccountType = (type) => {
        const isExternal = type === "empresa";
        $("#user-external-fields").hidden = !isExternal;
        $("#user-internal-role-field").hidden = isExternal;
        $("#user-role").disabled = isExternal;
        [$("#user-cpf"), $("#user-company"), $("#user-external-role")].forEach((field) => { field.disabled = !isExternal; field.required = isExternal; });
        $("#user-form-submit").disabled = isExternal && !companiesLoaded;
        $("#user-form-data-title").textContent = isExternal ? "Vínculo com empresa" : "Dados da conta interna";
        $("#user-form-submit").textContent = isExternal ? "Enviar convite externo" : "Enviar convite interno";
        if (isExternal && !companiesLoaded) loadCompanies();
    };
    const loadCompanies = async () => {
        const select = $("#user-company");
        select.innerHTML = '<option value="">Carregando empresas...</option>';
        try {
            const response = await api.request("/backoffice/directory/companies");
            const companies = response.data || [];
            select.innerHTML = '<option value="">Selecione a empresa e o sistema</option>' + companies.map((company) => `<option value="${esc(company.id)}">${esc(company.label)}</option>`).join("");
            select.disabled = companies.length === 0;
            $("#user-form-submit").disabled = companies.length === 0 && $("#user-account-type").value === "empresa";
            if (!companies.length) showMessage("Não há empresas ativas com assinatura ativa do Fokus Law para receber um vínculo.");
            else { companiesLoaded = true; showMessage(""); }
        } catch (error) {
            select.innerHTML = '<option value="">Não foi possível carregar as empresas</option>';
            $("#user-form-submit").disabled = $("#user-account-type").value === "empresa";
            showMessage(error.message || "Não foi possível carregar as empresas ativas.");
        }
    };
    const resetForm = () => {
        form.reset(); form.hidden = false; $("#user-view-panel").hidden = true;
        $("#user-account-type-field").hidden = false; $("#user-account-type").value = "plataforma"; setAccountType("plataforma");
        $("#user-email").readOnly = false; $("#user-email-change-help").hidden = true;
        $("#user-form-submit").hidden = false; $("#user-form-cancel").textContent = "Cancelar";
    };
    const openForm = (mode, record = null) => {
        resetForm(); selected = record; drawerController.setState({ mode, record });
        $("#user-drawer-kicker").textContent = mode === "invite" ? "NOVO USUÁRIO" : "EDIÇÃO INTERNA";
        $("#user-drawer-title").textContent = mode === "invite" ? "Novo usuário" : "Editar conta interna";
        $("#user-drawer-description").textContent = mode === "invite" ? "Convide uma conta interna ou vincule um usuário a uma empresa." : "O nome será atualizado imediatamente. A troca de e-mail exige confirmação.";
        $("#user-form-help").textContent = mode === "invite" ? "Escolha o tipo de conta e informe os dados necessários." : "A alteração de e-mail será enviada para confirmação.";
        if (mode === "edit") {
            $("#user-account-type-field").hidden = true;
            $("#user-name").value = record.name || ""; $("#user-email").value = record.email || "";
            $("#user-email-change-help").hidden = false; $("#user-internal-role-field").hidden = true;
        }
        drawerController.show();
        $("#user-name").focus();
    };
    $("#user-account-type").addEventListener("change", (event) => setAccountType(event.target.value));
    $("#user-cpf").addEventListener("input", (event) => { event.target.value = window.FokusDocuments?.formatCpf(event.target.value) || event.target.value; });
    const card = (title, content) => `<section class="fs-card fs-card-panel"><div class="fs-card-header"><div class="fs-card-header-title"><h3 class="fs-card-title">${esc(title)}</h3></div></div><div class="fs-card-body">${content}</div></section>`;
    const field = (label, value) => `<div><dt class="fs-u-fs-sm fs-u-color-secondary">${esc(label)}</dt><dd class="fs-u-m-0">${esc(value || "-")}</dd></div>`;
    const openDetails = (record) => {
        resetForm(); selected = record; form.hidden = true; $("#user-view-panel").hidden = false;
        drawerController.setState({ mode: "view", record });
        $("#user-drawer-kicker").textContent = "CONSULTA"; $("#user-drawer-title").textContent = record.name || "Detalhes do usuário";
        $("#user-drawer-description").textContent = labels[record.type] || "Dados da conta";
        let content = card("Dados da conta", `<dl class="fs-u-d-grid fs-u-gap-3">${field("Nome", record.name)}${field("E-mail", record.email)}${field("Tipo", labels[record.type] || record.type)}${field("Status", labels[record.status] || record.status)}${record.type === "plataforma" ? field("Perfil interno", labels[record.role] || record.role) : ""}${record.last_login_at ? field("Último acesso", new Date(record.last_login_at).toLocaleString("pt-BR")) : ""}${record.pending_email ? field("E-mail aguardando confirmação", record.pending_email) : ""}${record.cpf ? field("CPF", window.FokusDocuments?.formatCpf(record.cpf) || record.cpf) : ""}</dl>`);
        if (record.type === "empresa") {
            const memberships = record.memberships || [];
            content += memberships.length ? memberships.map((membership) => {
                const plans = membership.subscriptions || [];
                const planHtml = plans.length ? `<h4>Assinaturas da empresa</h4><ul>${plans.map((plan) => `<li><strong>${esc(plan.plan_name)}</strong> · ${esc(plan.product_name)} · ${esc(labels[plan.status] || plan.status)} · ${esc(plan.billing_cycle === "monthly" ? "Mensal" : plan.billing_cycle === "annual" ? "Anual" : plan.billing_cycle || "")}</li>`).join("")}</ul>` : '<p class="fs-u-fs-sm">Nenhuma assinatura atual da empresa.</p>';
                return card(membership.company_name, `<dl class="fs-u-d-grid fs-u-gap-3">${field("Papel", membership.role)}${field("Status do vínculo", labels[membership.status] || membership.status)}</dl>${planHtml}`);
            }).join("") : card("Vínculos atuais", "Este usuário não possui vínculos atuais com empresas.");
        }
        $("#user-view-panel").innerHTML = content;
        drawerController.show();
    };
    const openUser = async (id, type, mode) => {
        try {
            const record = await api.request(`/backoffice/directory/users/${encodeURIComponent(type)}/${encodeURIComponent(id)}`);
            if (mode === "edit") openForm(mode, record); else openDetails(record);
        } catch (error) { showMessage(error.message || "Não foi possível consultar esta conta."); }
    };
    const actionLabels = { block: ["Bloquear conta", "A conta perderá acesso ao Backoffice."], unblock: ["Desbloquear conta", "A conta poderá voltar a acessar o Backoffice."], deactivate: ["Desativar conta", "A conta interna será desativada."] };
    const submitAction = async (event) => {
        event.preventDefault();
        if (!pendingAction || !$("#user-action-form").reportValidity()) return;
        const { action, id } = pendingAction;
        try {
            await api.request(`/backoffice/admins/${encodeURIComponent(id)}/${action}`, { method: "POST", body: { reason: $("#user-action-reason").value.trim() } });
            modal?.hide?.(); showMessage("Ação de segurança concluída.", "success"); pendingAction = null; await load();
        } catch (error) { showMessage(error.message || "Não foi possível concluir a ação."); }
    };
    const submitForm = async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const values = Object.fromEntries(new FormData(form));
        const mode = drawerController.getState().mode;
        try {
            if (mode === "invite") {
                if (values.account_type === "empresa") {
                    await api.request("/backoffice/directory/users", { method: "POST", body: { name: values.name, email: values.email, cpf: values.cpf, company_id: values.company_id, role: values.role } });
                    showMessage("Convite enviado ao usuário externo.", "success");
                } else {
                    await api.request("/backoffice/admins/invitations", { method: "POST", body: { name: values.name, email: values.email, role: values.role } });
                    showMessage("Convite enviado à conta interna.", "success");
                }
            } else {
                const result = await api.request(`/backoffice/admins/${encodeURIComponent(selected.id)}/profile`, { method: "PATCH", body: { name: values.name, email: values.email } });
                showMessage(result.message || "Dados atualizados.", "success");
            }
            drawerController.close(); await load();
        } catch (error) { showMessage(error.message || "Não foi possível salvar os dados."); }
    };
    $("#users-query").value = state.query;
    if (!canManage) $("#user-invite").hidden = true;
    $("#users-filter-form").addEventListener("submit", (event) => { event.preventDefault(); state.query = $("#users-query").value.trim(); state.page = 1; load(); });
    $("#user-invite").addEventListener("click", () => openForm("invite"));
    $("#user-drawer-close").addEventListener("click", () => drawerController.close());
    $("#user-form-cancel").addEventListener("click", () => drawerController.close());
    form.addEventListener("submit", submitForm);
    $("#users-pagination").addEventListener("click", (event) => { const button = event.target.closest("[data-users-page]"); if (!button || button.disabled) return; state.page = Number(button.dataset.usersPage); load(); });
    list.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-user-action]"); if (!button) return;
        const { userAction, userId } = button.dataset;
        if (userAction === "details" || userAction === "edit") { await openUser(userId, button.closest("tr").dataset.accountType, userAction === "edit" ? "edit" : "view"); return; }
        if (!actionLabels[userAction]) return;
        pendingAction = { action: userAction, id: userId };
        $("#user-action-title").textContent = actionLabels[userAction][0]; $("#user-action-description").textContent = actionLabels[userAction][1]; $("#user-action-reason").value = ""; modal?.show?.(); $("#user-action-reason").focus();
    });
    $("#user-action-form").addEventListener("submit", submitAction);
    load();
    context.signal?.addEventListener("abort", () => { drawerController.dispose(); modal?.dispose?.(); }, { once: true });
    return () => { drawerController.dispose(); modal?.dispose?.(); window.disposeBackofficeRecordsPage?.(root); };
}
