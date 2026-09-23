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
    const drawerController = createRecordsDrawer({ trigger, drawer });
    const modal = window.FokusStyles?.Modal?.getOrCreateInstance($("#user-action-trigger"));
    let selected = null;
    let pendingAction = null;
    const esc = (value) => String(value ?? "").replace(/[&<>\x27"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\x27": "&#039;", "\"": "&quot;" })[c]);
    const labels = { plataforma: "Conta interna Fokus Cloud", empresa: "Usuário de empresa", administrador_comercial: "Administrador comercial", superadministrador: "Superadministrador", ativo: "Ativo", ativa: "Ativo", suspenso: "Suspenso", suspensa: "Suspensa", bloqueado: "Bloqueado", bloqueio_temporario: "Bloqueio temporário", desativado: "Desativado", desativada: "Desativada", bloqueada: "Bloqueada", pendente: "Pendente", cancelamento_agendado: "Cancelamento agendado", inadimplente: "Inadimplente", aguardando_pagamento: "Aguardando pagamento" };
    const status = (value) => `<span class="fs-badge fs-badge-soft-${["ativo", "ativa"].includes(value) ? "success" : ["pendente", "aguardando_pagamento"].includes(value) ? "info" : ["suspenso", "suspensa", "bloqueado", "bloqueio_temporario", "inadimplente", "cancelamento_agendado"].includes(value) ? "warning" : "secondary"}">${esc(labels[value] || value || "Sem status")}</span>`;
    const showMessage = (text, tone = "danger") => { message.textContent = text || ""; message.dataset.tone = tone; message.hidden = !text; };
    const actionButton = (action, id, label) => `<button class="fs-btn fs-btn-outline-secondary fs-btn-sm" type="button" data-user-action="${action}" data-user-id="${esc(id)}">${esc(label)}</button>`;
    const render = (response) => {
        const rows = response.data || [];
        list.innerHTML = rows.length ? rows.map((user) => {
            const actions = [actionButton("details", user.id, "Detalhes")];
            if (user.type === "plataforma" && canManage) {
                actions.unshift(actionButton("edit", user.id, "Editar"));
                if (user.status === "bloqueado" || user.status === "bloqueio_temporario") actions.push(actionButton("unblock", user.id, "Desbloquear"));
                else if (user.status === "ativo") actions.push(actionButton("block", user.id, "Bloquear"));
                if (user.status !== "desativado") actions.push(actionButton("deactivate", user.id, "Desativar"));
            }
            const detail = user.type === "plataforma" ? (labels[user.role] || user.role || "Conta interna") : `${Number(user.company_count || 0)} vínculo(s) atual(is)`;
            return `<tr data-account-type="${esc(user.type)}"><td data-label="Usuário"><strong>${esc(user.name || "-")}</strong><small class="fs-u-d-block fs-u-fs-sm">${esc(user.email || "-")}</small></td><td data-label="Tipo">${esc(labels[user.type] || user.type)}</td><td data-label="Perfil ou vínculos">${esc(detail)}</td><td data-label="Status">${status(user.status)}</td><td data-label="Ações"><div class="fs-u-d-flex fs-u-flex-wrap fs-u-gap-2">${actions.join("")}</div></td></tr>`;
        }).join("") : '<tr><td colspan="5">Nenhuma conta encontrada.</td></tr>';
        const meta = response.meta || {};
        const page = Number(meta.current_page || 1), perPage = Number(meta.per_page || 15), total = Number(meta.total || 0), last = Math.max(1, Number(meta.last_page || 1));
        $("#users-table-summary").textContent = `${total.toLocaleString("pt-BR")} conta(s)`;
        $("#users-table-footer-summary").textContent = total ? `Mostrando ${(page - 1) * perPage + 1} a ${Math.min(page * perPage, total)} de ${total} contas` : "Nenhuma conta encontrada";
        $("#users-pagination").innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-users-page="${page - 1}" aria-label="Página anterior" ${page <= 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active"><span class="fs-page-link" aria-current="page">${page} de ${last}</span></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-users-page="${page + 1}" aria-label="Próxima página" ${page >= last ? "disabled" : ""}>›</button></li></ul>`;
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
    const resetForm = () => {
        form.reset(); form.hidden = false; $("#user-view-panel").hidden = true;
        $("#user-role-field").hidden = false; $("#user-role").disabled = false;
        $("#user-email").readOnly = false; $("#user-email-change-help").hidden = true;
        $("#user-form-submit").hidden = false; $("#user-form-cancel").textContent = "Cancelar";
    };
    const openForm = (mode, record = null) => {
        resetForm(); selected = record; drawerController.setState({ mode, record });
        $("#user-drawer-kicker").textContent = mode === "invite" ? "CONVITE" : "EDIÇÃO INTERNA";
        $("#user-drawer-title").textContent = mode === "invite" ? "Convidar administrador" : "Editar conta interna";
        $("#user-drawer-description").textContent = mode === "invite" ? "Envie um convite para uma nova conta interna." : "O nome será atualizado imediatamente. A troca de e-mail exige confirmação.";
        $("#user-form-help").textContent = mode === "invite" ? "Defina o perfil da conta interna." : "A alteração de e-mail será enviada para confirmação.";
        if (mode === "edit") {
            $("#user-name").value = record.name || ""; $("#user-email").value = record.email || "";
            $("#user-email-change-help").hidden = false; $("#user-role-field").hidden = true;
        }
        drawerController.show();
        $("#user-name").focus();
    };
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
                await api.request("/backoffice/admins/invitations", { method: "POST", body: values });
                showMessage("Convite enviado.", "success");
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
