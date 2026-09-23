import { createRecordsDrawer } from "./records-drawer.js";

const PAGE_SIZE = 15;
const STATUS_LABELS = {
    aguardando_pagamento: "Aguardando pagamento", aprovado: "Aprovado", recusado: "Recusado",
    cancelado: "Cancelado", estornado: "Estornado", em_disputa: "Em disputa",
    aberta: "Aberta", em_revisao: "Em revisão", corrigida: "Corrigida", descartada: "Descartada",
    solicitado: "Solicitado", executado: "Executado",
    approved: "Aprovado", rejected: "Recusado", cancelled: "Cancelado", refunded: "Reembolsado",
    authorized: "Autorizado", paused: "Pausado", payment_status: "Status do pagamento",
    subscription_status: "Status da assinatura", alto: "Alto", medio: "Médio", baixo: "Baixo",
};
const TONES = { aprovado: "success", ativa: "success", executado: "success", corrigida: "success", recusado: "danger", cancelado: "secondary", estornado: "secondary", descartada: "secondary", aberta: "warning", em_revisao: "info", solicitado: "warning", aprovado_reembolso: "success", alto: "danger", medio: "warning", baixo: "secondary" };
const CASE_LABELS = { cobranca_duplicada: "Cobrança duplicada", erro_tecnico: "Erro técnico de cobrança", acordo_comercial: "Acordo comercial excepcional", arrependimento_7_dias: "Arrependimento em até 7 dias" };
const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[character]));
const money = (value, currency = "BRL") => Number(value || 0).toLocaleString("pt-BR", { style: "currency", currency: currency || "BRL" });
const date = (value, withTime = false) => {
    if (!value) return "—";
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? "—" : parsed.toLocaleString("pt-BR", withTime ? { dateStyle: "short", timeStyle: "short" } : { dateStyle: "short" });
};
const label = (value) => STATUS_LABELS[value] || String(value || "Não informado").replaceAll("_", " ");
const badge = (value) => `<span class="fs-badge fs-badge-soft-${TONES[value] || "secondary"}">${escapeHtml(label(value))}</span>`;

export function mount(root, context = {}) {
    const $ = (selector) => root.querySelector(selector) || document.querySelector(selector);
    const api = context.api || window.FokusApi;
    const permissions = context.permissions || new Set();
    const pageSignal = context.signal;
    const tabsElement = $("#billing-tabs");
    const tabs = window.FokusStyles?.Tabs?.getOrCreateInstance(tabsElement);
    const drawerTrigger = $("#billing-drawer-trigger");
    const drawerElement = $("#billing-drawer");
    const drawer = createRecordsDrawer({ trigger: drawerTrigger, drawer: drawerElement });
    const modalTrigger = $("#billing-action-trigger");
    const modal = window.FokusStyles?.Modal?.getOrCreateInstance(modalTrigger);
    const canManageReconciliation = permissions.has?.("platform.reconciliation.manage") ?? false;
    const canRequestRefund = permissions.has?.("platform.refunds.request") ?? false;
    const canManageRefund = permissions.has?.("platform.refunds.manage") ?? false;
    const adminId = String(context.admin?.id || "");
    const state = {
        active: "payments",
        lists: {
            payments: { page: 1, filters: {}, data: [], meta: null, loaded: false, controller: null },
            reconciliation: { page: 1, filters: {}, data: [], meta: null, loaded: false, controller: null },
            refunds: { page: 1, filters: {}, data: [], meta: null, loaded: false, controller: null },
        },
        detailController: null,
        detail: null,
        lastTrigger: null,
        pendingAction: null,
    };
    const panelSelectors = { payments: "#billing-payments-panel", reconciliation: "#billing-reconciliation-panel", refunds: "#billing-refunds-panel" };
    const tabButtons = Object.fromEntries(Object.keys(panelSelectors).map((kind) => [kind, tabsElement.querySelector(`[data-fs-target="${panelSelectors[kind]}"]`)]));

    if (!permissions.has?.("platform.reconciliation.view")) {
        tabButtons.reconciliation.hidden = true;
        tabButtons.reconciliation.classList.add("is-disabled");
        tabButtons.reconciliation.setAttribute("aria-disabled", "true");
        $(panelSelectors.reconciliation).hidden = true;
    }
    if (!canRequestRefund) {
        tabButtons.refunds.hidden = true;
        tabButtons.refunds.classList.add("is-disabled");
        tabButtons.refunds.setAttribute("aria-disabled", "true");
        $(panelSelectors.refunds).hidden = true;
    }

    const showMessage = (text, tone = "success") => {
        const message = $("#billing-message");
        message.className = `fs-alert fs-alert-${tone === "success" ? "success" : "danger"}`;
        message.textContent = text || "";
        message.hidden = !text;
    };

    const currentFilters = (form) => Object.fromEntries([...new FormData(form)].map(([key, value]) => [key, String(value).trim()]).filter(([, value]) => value));
    const setFormValues = (kind) => {
        const form = $(`#billing-${kind}-filter-form`);
        for (const [key, value] of Object.entries(state.lists[kind].filters)) {
            const field = form.elements.namedItem(key);
            if (field) field.value = value;
        }
    };

    const endpoint = { payments: "/backoffice/payments", reconciliation: "/backoffice/reconciliation", refunds: "/backoffice/refunds" };
    const renderPagination = (kind) => {
        const list = state.lists[kind];
        const nav = $(`#billing-${kind}-pagination`);
        const meta = list.meta || { current_page: list.page, last_page: 1, per_page: PAGE_SIZE, total: 0 };
        const current = Number(meta.current_page || list.page);
        const last = Math.max(1, Number(meta.last_page || 1));
        const previous = current - 1;
        const next = current + 1;
        nav.innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-billing-page="${kind}:${previous}" aria-label="Página anterior" ${current <= 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><button class="fs-page-link" type="button" aria-current="page" aria-label="Página ${current}">${current}</button></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-billing-page="${kind}:${next}" aria-label="Próxima página" ${current >= last ? "disabled" : ""}>›</button></li></ul>`;
        const from = Number(meta.total) ? ((current - 1) * Number(meta.per_page)) + 1 : 0;
        const to = Math.min(current * Number(meta.per_page || PAGE_SIZE), Number(meta.total || 0));
        $(`#billing-${kind}-page-summary`).textContent = `Mostrando ${from} a ${to} de ${Number(meta.total || 0)} registros · página ${current} de ${last}`;
        $(`#billing-${kind}-summary`).textContent = `${Number(meta.total || 0)} registros`;
    };

    const paymentRows = (items) => items.map((item) => `<tr>
        <td class="fs-width-600" data-label="Empresa"><strong>${escapeHtml(item.company_name || "Empresa não informada")}</strong><small class="fs-u-d-block fs-u-fs-sm fs-u-color-secondary">${escapeHtml(item.subscription_id || "Assinatura sem vínculo")}</small></td>
        <td class="fs-width-400" data-label="Valor">${escapeHtml(money(item.amount, item.currency))}</td>
        <td class="fs-width-400" data-label="Status">${badge(item.status)}</td>
        <td class="fs-width-500" data-label="Período">${escapeHtml(date(item.billing_period_starts_at))} – ${escapeHtml(date(item.billing_period_ends_at))}</td>
        <td class="fs-width-300" data-label="Ações"><button class="fs-btn fs-btn-outline-primary fs-table-action" type="button" data-billing-detail="payments:${escapeHtml(item.id)}">Detalhes</button></td>
    </tr>`).join("");
    const reconciliationRows = (items) => items.map((item) => `<tr>
        <td class="fs-width-600" data-label="Empresa"><strong>${escapeHtml(item.company_name || "Empresa não informada")}</strong><small class="fs-u-d-block fs-u-fs-sm fs-u-color-secondary">${escapeHtml(item.company_id || "—")}</small></td>
        <td class="fs-width-400" data-label="Tipo">${escapeHtml(label(item.type))}</td>
        <td class="fs-width-400" data-label="Status local">${badge(item.internal_status)}</td>
        <td class="fs-width-500" data-label="Mercado Pago">${badge(item.mercado_pago_status)}</td>
        <td class="fs-width-300" data-label="Impacto">${badge(item.impact)}</td>
        <td class="fs-width-300" data-label="Ações"><button class="fs-btn fs-btn-outline-primary fs-table-action" type="button" data-billing-detail="reconciliation:${escapeHtml(item.id)}">Analisar</button></td>
    </tr>`).join("");
    const refundRows = (items) => items.map((item) => `<tr>
        <td class="fs-width-600" data-label="Empresa"><strong>${escapeHtml(item.company_name || "Empresa não informada")}</strong><small class="fs-u-d-block fs-u-fs-sm fs-u-color-secondary">${escapeHtml(item.id)}</small></td>
        <td class="fs-width-400" data-label="Pagamento">${escapeHtml(item.provider_payment_id || item.payment_id || "—")}</td>
        <td class="fs-width-400" data-label="Valor">${escapeHtml(money(item.amount))}</td>
        <td class="fs-width-500" data-label="Caso">${escapeHtml(CASE_LABELS[item.allowed_case] || item.allowed_case || "—")}</td>
        <td class="fs-width-300" data-label="Status">${badge(item.status)}</td>
        <td class="fs-width-300" data-label="Ações"><button class="fs-btn fs-btn-outline-primary fs-table-action" type="button" data-billing-detail="refunds:${escapeHtml(item.id)}">Detalhes</button></td>
    </tr>`).join("");
    const renderers = { payments: paymentRows, reconciliation: reconciliationRows, refunds: refundRows };

    const renderList = (kind) => {
        const list = state.lists[kind];
        const table = $(`#billing-${kind}-table`);
        const tbody = $(`#billing-${kind}-list`);
        const loading = table.querySelector("[data-fs-datatable-loading]");
        const empty = table.querySelector("[data-fs-datatable-empty]");
        const error = table.querySelector("[data-fs-datatable-error]");
        table.setAttribute("aria-busy", String(Boolean(list.loading)));
        loading.hidden = !list.loading;
        empty.hidden = list.loading || Boolean(list.error) || list.data.length > 0;
        error.hidden = !list.error;
        error.textContent = list.error || "";
        tbody.innerHTML = list.loading || list.error ? "" : renderers[kind](list.data);
        if (list.meta) renderPagination(kind);
    };

    async function load(kind, { force = false } = {}) {
        const list = state.lists[kind];
        if (!force && list.loaded) return;
        list.controller?.abort();
        const controller = new AbortController();
        list.controller = controller;
        const params = new URLSearchParams({ per_page: String(PAGE_SIZE), page: String(list.page) });
        for (const [key, value] of Object.entries(list.filters)) params.set(key, value);
        list.loading = true;
        list.error = "";
        renderList(kind);
        try {
            const response = await api.request(`${endpoint[kind]}?${params}`, { signal: controller.signal });
            if (controller.signal.aborted || pageSignal?.aborted) return;
            list.data = Array.isArray(response?.data) ? response.data : [];
            list.meta = response?.meta || { current_page: list.page, per_page: PAGE_SIZE, total: list.data.length, last_page: 1 };
            list.page = Number(list.meta.current_page || list.page);
            list.loaded = true;
        } catch (error) {
            if (error?.name === "AbortError" || controller.signal.aborted || pageSignal?.aborted) return;
            list.error = error.message || "Não foi possível carregar esta lista.";
            list.loaded = false;
        } finally {
            if (!controller.signal.aborted && !pageSignal?.aborted) {
                list.loading = false;
                renderList(kind);
            }
        }
    }

    const detailPairs = (entries) => `<dl class="fs-detail-list">${entries.map(([term, value]) => `<div><dt class="fs-form-label">${escapeHtml(term)}</dt><dd>${value}</dd></div>`).join("")}</dl>`;
    const card = (title, body) => `<section class="fs-card fs-card-panel"><div class="fs-card-header"><div class="fs-card-header-title"><h3 class="fs-card-title">${escapeHtml(title)}</h3></div></div><div class="fs-card-body">${body}</div></section>`;
    const value = (input) => escapeHtml(input === null || input === undefined || input === "" ? "—" : input);

    const paymentDetails = (item) => {
        const details = card("Pagamento", detailPairs([
            ["Empresa", value(item.company_name)], ["Status", badge(item.status)], ["Valor", escapeHtml(money(item.amount, item.currency))],
            ["Provedor", value(item.provider)], ["ID local", value(item.id)], ["ID do pagamento no Mercado Pago", value(item.provider_payment_id)],
            ["ID da assinatura", value(item.subscription_id)], ["ID da recorrência no provedor", value(item.provider_subscription_id)],
            ["Início do período", escapeHtml(date(item.billing_period_starts_at, true))], ["Fim do período", escapeHtml(date(item.billing_period_ends_at, true))],
            ["Pago em", escapeHtml(date(item.paid_at, true))], ["Registrado em", escapeHtml(date(item.created_at, true))],
        ]));
        const requestButton = canRequestRefund && item.status === "aprovado" ? `<button class="fs-btn fs-btn-outline-primary" type="button" id="billing-refund-request-toggle">Solicitar reembolso</button>` : "";
        const requestForm = canRequestRefund && item.status === "aprovado" ? `<form class="fs-form fs-u-d-flex fs-u-flex-column fs-u-gap-2" id="billing-refund-request-form" hidden>
            <label class="fs-form-label" for="billing-refund-amount"><span>Valor do reembolso</span><input class="fs-form-control" id="billing-refund-amount" name="amount" type="number" min="0.01" step="0.01" required /></label>
            <label class="fs-form-label" for="billing-refund-case"><span>Motivo permitido</span><select class="fs-form-select" id="billing-refund-case" name="allowed_case" required><option value="">Selecione</option><option value="cobranca_duplicada">Cobrança duplicada</option><option value="erro_tecnico">Erro técnico de cobrança</option><option value="acordo_comercial">Acordo comercial excepcional</option><option value="arrependimento_7_dias">Arrependimento em até 7 dias</option></select></label>
            <label class="fs-form-label" for="billing-refund-reason"><span>Justificativa</span><textarea class="fs-form-control" id="billing-refund-reason" name="reason" maxlength="1000" required></textarea></label>
            <div class="fs-alert fs-alert-danger" id="billing-refund-form-error" role="alert" hidden></div><button class="fs-btn fs-btn-primary" type="submit">Enviar solicitação</button></form>` : "";
        return `${details}${requestButton ? `<div class="fs-card fs-card-panel"><div class="fs-card-body fs-u-d-flex fs-u-flex-column fs-u-gap-3">${requestButton}${requestForm}</div></div>` : ""}`;
    };
    const reconciliationDetails = (item) => {
        const pairs = detailPairs([
            ["Empresa", value(item.company_name || item.company_id)], ["Tipo", value(label(item.type))], ["Status interno", badge(item.internal_status)],
            ["Status Mercado Pago", badge(item.mercado_pago_status)], ["Impacto", badge(item.impact)], ["Situação", badge(item.status)],
            ["Pagamento relacionado", value(item.payment_id)], ["Assinatura relacionada", value(item.subscription_id)],
            ["Aberta em", escapeHtml(date(item.opened_at, true))], ["Revisada em", escapeHtml(date(item.reviewed_at, true))],
            ["Motivo da decisão", value(item.correction_reason)],
        ]);
        const actions = [];
        if (["aberta", "em_revisao"].includes(item.status)) actions.push(`<button class="fs-btn fs-btn-outline-primary" type="button" data-billing-action="reconciliation:revisar:${escapeHtml(item.id)}">Marcar em revisão</button>`);
        if (canManageReconciliation && ["aberta", "em_revisao"].includes(item.status)) {
            actions.push(`<button class="fs-btn fs-btn-outline-secondary" type="button" data-billing-action="reconciliation:descartar:${escapeHtml(item.id)}">Descartar</button>`);
            actions.push(`<button class="fs-btn fs-btn-primary" type="button" data-billing-action="reconciliation:corrigir:${escapeHtml(item.id)}">Corrigir com status do Mercado Pago</button>`);
        }
        return `${card("Divergência", pairs)}${actions.length ? card("Ações", `<div class="fs-u-d-flex fs-u-flex-wrap fs-u-gap-2">${actions.join("")}</div>`) : ""}`;
    };
    const refundDetails = (item) => {
        const canDecide = canManageRefund && String(item.requested_by_platform_admin_id || "") !== adminId;
        const pairs = detailPairs([
            ["Empresa", value(item.company_name || item.company_id)], ["Pagamento local", value(item.payment_id)], ["Pagamento Mercado Pago", value(item.provider_payment_id)],
            ["Assinatura", value(item.subscription_id)], ["Valor", escapeHtml(money(item.amount))], ["Caso", value(CASE_LABELS[item.allowed_case] || item.allowed_case)],
            ["Status", badge(item.status)], ["Justificativa da solicitação", value(item.reason)], ["Solicitado em", escapeHtml(date(item.requested_at, true))],
            ["Solicitante", value(item.requested_by_platform_admin_id)], ["Aprovador", value(item.approved_by_platform_admin_id)],
            ["Aprovado em", escapeHtml(date(item.approved_at, true))], ["Executado em", escapeHtml(date(item.executed_at, true))], ["Recusado em", escapeHtml(date(item.refused_at, true))],
            ["ID do reembolso no provedor", value(item.provider_refund_id)],
        ]);
        const actions = [];
        if (canDecide && item.status === "solicitado") {
            actions.push(`<button class="fs-btn fs-btn-primary" type="button" data-billing-action="refunds:aprovar:${escapeHtml(item.id)}">Aprovar reembolso</button>`);
            actions.push(`<button class="fs-btn fs-btn-outline-danger" type="button" data-billing-action="refunds:recusar:${escapeHtml(item.id)}">Recusar reembolso</button>`);
        }
        if (canDecide && item.status === "aprovado") {
            actions.push(`<button class="fs-btn fs-btn-primary" type="button" data-billing-action="refunds:executar:${escapeHtml(item.id)}">Executar reembolso no Mercado Pago</button>`);
            actions.push(`<button class="fs-btn fs-btn-outline-danger" type="button" data-billing-action="refunds:recusar:${escapeHtml(item.id)}">Recusar reembolso</button>`);
        }
        const separationMessage = canManageRefund && String(item.requested_by_platform_admin_id || "") === adminId && ["solicitado", "aprovado"].includes(item.status)
            ? `<p class="fs-u-fs-sm fs-u-color-secondary">Outra pessoa administradora deve revisar e decidir esta solicitação.</p>` : "";
        return `${card("Solicitação de reembolso", pairs)}${separationMessage ? card("Segregação de aprovação", separationMessage) : ""}${actions.length ? card("Ações", `<div class="fs-u-d-flex fs-u-flex-wrap fs-u-gap-2">${actions.join("")}</div>`) : ""}`;
    };
    const detailRenderers = { payments: paymentDetails, reconciliation: reconciliationDetails, refunds: refundDetails };

    async function openDetail(kind, id, trigger) {
        state.detailController?.abort();
        state.detailController = new AbortController();
        state.detail = { kind, id };
        state.lastTrigger = trigger;
        drawer.setState({ mode: "view", record: { kind, id } });
        $("#billing-drawer-title").textContent = { payments: "Detalhes do pagamento", reconciliation: "Análise de conciliação", refunds: "Detalhes do reembolso" }[kind];
        $("#billing-drawer-description").textContent = "Consulte os dados e as ações disponíveis para este registro.";
        $("#billing-detail-loading").hidden = false;
        $("#billing-detail-error").hidden = true;
        $("#billing-detail-sections").hidden = true;
        $("#billing-detail-sections").innerHTML = "";
        drawer.show();
        const detailPath = { payments: "payments", reconciliation: "reconciliation", refunds: "refunds" }[kind];
        try {
            const item = await api.request(`/backoffice/${detailPath}/${encodeURIComponent(id)}`, { signal: state.detailController.signal });
            if (state.detailController.signal.aborted || pageSignal?.aborted || state.detail?.id !== id) return;
            state.detail.item = item;
            $("#billing-detail-loading").hidden = true;
            $("#billing-detail-sections").innerHTML = detailRenderers[kind](item);
            $("#billing-detail-sections").hidden = false;
            $("#billing-refund-request-form")?.addEventListener("submit", submitRefundRequest, { signal: pageSignal });
        } catch (error) {
            if (error?.name === "AbortError" || state.detailController.signal.aborted || pageSignal?.aborted) return;
            $("#billing-detail-loading").hidden = true;
            $("#billing-detail-error").textContent = error.message || "Não foi possível carregar os detalhes.";
            $("#billing-detail-error").hidden = false;
        }
    }

    const actionCopy = {
        revisar: ["Marcar divergência em revisão", "A ação será registrada na auditoria."],
        descartar: ["Descartar divergência", "Confirme o motivo para descartar esta divergência."],
        corrigir: ["Corrigir pelo status do Mercado Pago", "A aplicação atualizará o estado interno conforme o status confirmado e registrará a decisão."],
        aprovar: ["Aprovar solicitação de reembolso", "A aprovação autoriza a etapa de execução e será auditada."],
        recusar: ["Recusar solicitação de reembolso", "A recusa será registrada com o motivo informado."],
        executar: ["Executar reembolso no Mercado Pago", "Esta ação solicita o reembolso ao provedor e não pode ser desfeita pela aplicação."],
    };
    const actionToggle = (action, kind, id) => {
        state.pendingAction = { action, kind, id };
        const [title, description] = actionCopy[action];
        $("#billing-action-title").textContent = title;
        $("#billing-action-description").textContent = description;
        $("#billing-action-reason").value = "";
        $("#billing-action-error").hidden = true;
        $("#billing-action-submit").textContent = action === "executar" ? "Executar reembolso" : "Confirmar";
        drawer.close();
        modal?.show?.();
        queueMicrotask(() => $("#billing-action-reason").focus());
    };
    async function submitAction(event) {
        event.preventDefault();
        const pending = state.pendingAction;
        const form = event.currentTarget;
        if (!pending || !form.reportValidity()) return;
        const button = $("#billing-action-submit");
        button.disabled = true;
        try {
            await api.request(`/backoffice/${pending.kind}/${encodeURIComponent(pending.id)}`, {
                method: "PATCH", body: { action: pending.action, reason: $("#billing-action-reason").value.trim() }, signal: pageSignal,
            });
            state.pendingAction = null;
            modal?.hide?.();
            showMessage("Ação financeira concluída e registrada.");
            await load(pending.kind, { force: true });
            if (state.detail?.kind === pending.kind && state.detail.id === pending.id) await openDetail(pending.kind, pending.id, state.lastTrigger);
        } catch (error) {
            $("#billing-action-error").textContent = error.message || "Não foi possível concluir a ação.";
            $("#billing-action-error").hidden = false;
        } finally {
            button.disabled = false;
        }
    }

    async function submitRefundRequest(event) {
        event.preventDefault();
        const item = state.detail?.item;
        const form = event.currentTarget;
        if (!item || !form.reportValidity()) return;
        const values = Object.fromEntries(new FormData(form));
        const error = $("#billing-refund-form-error");
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            await api.request("/backoffice/refunds", { method: "POST", body: { payment_id: item.id, amount: Number(values.amount), allowed_case: values.allowed_case, reason: values.reason.trim() }, signal: pageSignal });
            showMessage("Solicitação de reembolso registrada para aprovação.");
            form.reset();
            form.hidden = true;
            await load("refunds", { force: true });
            tabButtons.refunds.click();
            state.active = "refunds";
            drawer.close();
            tabButtons.refunds.focus();
        } catch (requestError) {
            error.textContent = requestError.message || "Não foi possível solicitar o reembolso.";
            error.hidden = false;
        } finally {
            submit.disabled = false;
        }
    }

    const updateFilters = (kind, event) => {
        event.preventDefault();
        state.lists[kind].filters = currentFilters(event.currentTarget);
        state.lists[kind].page = 1;
        state.lists[kind].loaded = false;
        if (!state.lists[kind].loading) load(kind, { force: true });
    };
    const pageClick = (event) => {
        const button = event.target.closest("[data-billing-page]");
        if (!button || button.disabled) return;
        const [kind, page] = button.dataset.billingPage.split(":");
        state.lists[kind].page = Math.max(1, Number(page));
        load(kind, { force: true });
    };
    const detailClick = (event) => {
        const button = event.target.closest("[data-billing-detail]");
        if (!button) return;
        const [kind, id] = button.dataset.billingDetail.split(":");
        openDetail(kind, id, button);
    };
    const actionClick = (event) => {
        const button = event.target.closest("[data-billing-action]");
        if (!button) return;
        const [kind, action, id] = button.dataset.billingAction.split(":");
        actionToggle(action, kind, id);
    };
    const onTabChanged = (event) => {
        const match = Object.entries(panelSelectors).find(([, selector]) => selector === event.detail?.target);
        if (!match) return;
        state.active = match[0];
        if (!state.lists[state.active].loaded) load(state.active);
    };
    const onTabClick = (event) => {
        const tab = event.target.closest("[data-fs-target]");
        const kind = Object.keys(panelSelectors).find((key) => tabButtons[key] === tab);
        if (!kind || tab.disabled || tab.getAttribute("aria-disabled") === "true") return;
        // Keep panel visibility explicit even if the shared Tabs controller was
        // initialized before this fragment or its change event was missed.
        for (const [panelKind, selector] of Object.entries(panelSelectors)) {
            const selected = panelKind === kind;
            const panel = $(selector);
            panel.hidden = !selected;
            panel.classList.toggle("is-active", selected);
            tabButtons[panelKind].classList.toggle("is-active", selected);
            tabButtons[panelKind].setAttribute("aria-selected", String(selected));
            tabButtons[panelKind].setAttribute("tabindex", selected ? "0" : "-1");
        }
        state.active = kind;
        // Refresh on entry so out-of-band billing changes (for example applying
        // a free voucher from subscription details) appear without a reload button.
        load(kind, { force: true });
    };
    const onDrawerHidden = () => {
        state.detailController?.abort();
        state.lastTrigger?.focus?.();
    };

    for (const kind of Object.keys(panelSelectors)) {
        $(`#billing-${kind}-filter-form`).addEventListener("submit", (event) => updateFilters(kind, event), { signal: pageSignal });
        $(`#billing-${kind}-pagination`).addEventListener("click", pageClick, { signal: pageSignal });
        $(`#billing-${kind}-list`).addEventListener("click", detailClick, { signal: pageSignal });
        setFormValues(kind);
    }
    tabsElement.addEventListener("fs:tab:changed", onTabChanged, { signal: pageSignal });
    tabsElement.addEventListener("click", onTabClick, { signal: pageSignal });
    $("#billing-detail-sections").addEventListener("click", (event) => {
        actionClick(event);
        if (event.target.closest("#billing-refund-request-toggle")) $("#billing-refund-request-form").hidden = false;
    }, { signal: pageSignal });
    $("#billing-action-form").addEventListener("submit", submitAction, { signal: pageSignal });
    drawerTrigger.addEventListener("fs:hidden", onDrawerHidden, { signal: pageSignal });
    $("#billing-drawer-close").addEventListener("click", () => drawer.close(), { signal: pageSignal });

    modalTrigger.addEventListener("fs:hidden", () => {
        if (!state.pendingAction) return;
        state.pendingAction = null;
        if (state.detail) openDetail(state.detail.kind, state.detail.id, state.lastTrigger);
    }, { signal: pageSignal });
    Object.values(state.lists).forEach((list) => {
        list.loading = false;
    });
    load("payments");

    let disposed = false;
    const dispose = () => {
        if (disposed) return;
        disposed = true;
        Object.values(state.lists).forEach((list) => list.controller?.abort());
        state.detailController?.abort();
        drawer.dispose();
        modal?.dispose?.();
        tabs?.dispose?.();
        window.disposeBackofficeRecordsPage?.(root);
    };
    pageSignal?.addEventListener("abort", dispose, { once: true });
    return dispose;
}
