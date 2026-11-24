import { createRecordsDrawer } from "./records-drawer.js";

// Only an outcome code is retained across SPA mounts; no form data or server
// message is shared. Scope notices to the authenticated platform admin.
const previousOperations = new Map();
const pendingOperations = new Map();
const operationListeners = new Map();
let operationEpoch = 0;
let fingerprintKey;
function resetOperationState() {
    operationEpoch++;
    restoreGeneration++;
    finishRestore?.(false);
    previousOperations.clear();
    pendingOperations.clear();
    fingerprintKey = undefined;
    operationListeners.forEach((listeners) => listeners.forEach((listener) => listener({}, null)));
}
window.addEventListener("fokus:platform-logout", resetOperationState);
let restoreBarrier = Promise.resolve(true);
let finishRestore;
let restoreGeneration = 0;
window.addEventListener("pagehide", (event) => {
    if (!event.persisted) { resetOperationState(); return; }
    const html = document.documentElement;
    if (!html.hasAttribute("data-company-restore-visibility")) {
        html.dataset.companyRestoreVisibility = html.style.visibility;
        html.dataset.companyRestoreInert = html.inert ? "1" : "0";
    }
    html.inert = true;
    html.style.visibility = "hidden";
    window.dispatchEvent(new Event("fokus:backoffice-suspend"));
    restoreGeneration++;
    finishRestore?.(false);
    restoreBarrier = new Promise((resolve) => { finishRestore = resolve; });
});
window.addEventListener("pageshow", async (event) => {
    if (!event.persisted) return;
    const generation = restoreGeneration;
    const original = window.__backofficeAdmin;
    const snapshot = (admin) => JSON.stringify({ id: String(admin?.id || ""), role: admin?.role || "", permissions: [...(admin?.permissions || [])].sort() });
    let verified = false;
    let restoredAdmin;
    try {
        const response = await Promise.race([
            window.FokusApi.request("/backoffice/auth/me"),
            new Promise((_, reject) => setTimeout(() => reject(new Error("timeout")), 10000)),
        ]);
        verified = Boolean(original?.id && response?.admin?.id && snapshot(original) === snapshot(response.admin));
        restoredAdmin = response?.admin;
    } catch (_) { /* Fail closed when identity cannot be verified. */ }
    if (generation !== restoreGeneration) return;
    if (verified) {
        try {
            const router = window.__backofficeRouter;
            if (!router?.navigate || !router?.pageFromLocation) throw new Error("router indisponível");
            // A matching account is not proof that the browser restored the
            // same login session. Discard prior rendered data and old notices;
            // only the opaque in-flight guard survives until its outcome.
            // A restored login is not proof of the same session. Retain only
            // the opaque uncertainty barrier, never details or other outcomes.
            const unresolved = Boolean(previousOperations.get(String(original.id))?.unknown);
            previousOperations.clear();
            if (unresolved) previousOperations.set(String(original.id), { unknown: true });
            router.admin = restoredAdmin;
            window.__backofficeAdmin = restoredAdmin;
            window.dispatchEvent(new CustomEvent("fokus:backoffice-revalidated", { detail: restoredAdmin }));
            await Promise.race([
                router.navigate(router.pageFromLocation(), { history: false }),
                new Promise((_, reject) => setTimeout(() => reject(new Error("timeout")), 10000)),
            ]);
        } catch (_) { verified = false; }
    }
    if (generation !== restoreGeneration) return;
    if (!verified) {
        resetOperationState();
        finishRestore?.(false);
        window.location.reload();
        return;
    }
    const html = document.documentElement;
    html.inert = html.dataset.companyRestoreInert === "1";
    html.style.visibility = html.dataset.companyRestoreVisibility || "";
    delete html.dataset.companyRestoreInert;
    delete html.dataset.companyRestoreVisibility;
    window.dispatchEvent(new Event("fokus:backoffice-resume"));
    finishRestore?.(true);
    finishRestore = undefined;
});

async function fingerprintOperation(adminId, method, endpoint, body) {
    if (!globalThis.crypto?.subtle) throw new Error("Não foi possível verificar a solicitação. Recarregue a página e tente novamente.");
    fingerprintKey ||= crypto.subtle.generateKey({ name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
    const key = await fingerprintKey;
    const bytes = new TextEncoder().encode(JSON.stringify([adminId, method, endpoint, body]));
    const signature = await crypto.subtle.sign("HMAC", key, bytes);
    return Array.from(new Uint8Array(signature), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function publishPreviousOperation(adminId, outcome, epoch) {
    if (!adminId || epoch !== operationEpoch) return;
    const outcomes = { ...previousOperations.get(adminId), [outcome]: true };
    previousOperations.set(adminId, outcomes);
    operationListeners.get(adminId)?.forEach((listener) => listener(outcomes, outcome));
}

/**
 * Empresas: lista, filtros e estados create/edit/view do drawer compartilhado.
 */
export async function mount(root, context = {}) {
    // Drawers are portalled to body; never fall back to another mount's DOM.
    const owner = root.dataset.backofficeOwner;
    const ownedDrawer = root.querySelector('#company-drawer') || [...document.querySelectorAll('body > #company-drawer')].find((node) => owner && node.dataset.backofficeOwner === owner);
    const $ = (selector) => root.querySelector(selector) || (ownedDrawer?.matches(selector) ? ownedDrawer : ownedDrawer?.querySelector(selector));
    const api = context.api || window.FokusApi;
    const state = { page: 1, query: new URLSearchParams(location.search).get("q")?.trim() || "", status: "" };
    const list = $("#company-list");
    const pagination = $("#company-pagination");
    const tableSummary = $("#company-table-summary");
    const message = $("#company-message");
    const drawer = $("#company-drawer");
    const form = $("#company-form");
    const createTrigger = $("#company-create");
    const drawerController = createRecordsDrawer({ trigger: createTrigger, drawer });
    let currentCompany = null;
    let submitting = false;
    let drawerSession = 0;
    let loadSequence = 0;
    let detailSequence = 0;
    const mountRestoreGeneration = restoreGeneration;
    const mountEpoch = operationEpoch;
    const refreshStatus = $("#company-refresh-status");
    const refreshPageStatus = $("#company-refresh-page-status");
    const operationDrawerStatus = $("#company-operation-drawer-status");
    const operationPageStatus = $("#company-operation-page-status");
    let refreshError = "";
    const adminId = context.admin?.id ? String(context.admin.id) : null;
    let operationOutcome = adminId ? previousOperations.get(adminId) : null;
    const syncRefreshStatus = (drawerOpen) => {
        refreshStatus.textContent = refreshError;
        refreshPageStatus.textContent = refreshError;
        refreshStatus.hidden = !refreshError || !drawerOpen;
        refreshPageStatus.hidden = !refreshError || drawerOpen;
        const messages = [];
        if (operationOutcome?.completed) messages.push("Uma operação anterior foi concluída.");
        if (operationOutcome?.rejected) messages.push("Uma operação anterior foi rejeitada.");
        if (operationOutcome?.unknown) messages.push("Não foi possível confirmar o resultado de uma operação anterior.");
        const operationText = messages.length ? `${messages.join(" ")} Confira a lista antes de tentar novamente.` : "";
        operationDrawerStatus.querySelector("[data-company-operation-text]").textContent = operationText;
        operationPageStatus.querySelector("[data-company-operation-text]").textContent = operationText;
        operationDrawerStatus.hidden = !messages.length || !drawerOpen;
        operationPageStatus.hidden = !messages.length || drawerOpen;
    };
    const drawerShown = () => syncRefreshStatus(true);
    const drawerHidden = () => syncRefreshStatus(false);
    createTrigger.addEventListener("fs:shown", drawerShown);
    createTrigger.addEventListener("fs:hidden", drawerHidden);
    const isPageActive = () => !context.signal?.aborted && root.isConnected && mountRestoreGeneration === restoreGeneration && mountEpoch === operationEpoch;
    const formError = $("#company-form-error");
    const clearFormError = () => { formError.hidden = true; formError.textContent = ""; };
    const showFormError = (value) => {
        formError.textContent = value;
        formError.hidden = false;
        const activeModal = document.activeElement?.closest('[aria-modal="true"]');
        if (activeModal && activeModal !== drawer && !drawer.contains(activeModal)) return;
        formError.scrollIntoView({ block: "nearest" });
        formError.focus({ preventScroll: true });
    };
    const B = "/backoffice/assets/icons/";
    const icons = { edit: "Common-File-Edit--Streamline-Ultimate.png", deactivate: "Common-File-Subtract--Streamline-Ultimate.png", activate: "Common-File-Quill--Streamline-Ultimate.png", remove: "Common-File-Remove--Streamline-Ultimate.png", details: "Folder-File--Streamline-Ultimate.png" };
    const escapeHtml = (value) => String(value ?? "").replace(/[&<>'"]/g, (char) => ({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#039;","\"":"&quot;"}[char]));
    const documents = window.FokusDocuments;
    const formatDocument = (value, type) => type === "cpf" ? documents.formatCpf(value) || "-" : documents.formatCnpj(value) || "-";
    const moneyStatus = { pendente: "Pendente", ativa: "Ativa", suspensa: "Suspensa", encerrando: "Em encerramento", encerrada: "Encerrada" };
    const statusBadge = (value) => { const tone = { ativa: "success", suspensa: "warning", pendente: "info", encerrando: "warning", encerrada: "secondary" }[value] || "secondary"; return `<span class="fs-badge fs-badge-width-80 fs-badge-soft-${tone}">${escapeHtml(moneyStatus[value] || value || "Sem status")}</span>`; };
    const formatBytes = (value) => { if (value == null || value === "") return "Não disponível"; const bytes = Number(value); if (!Number.isFinite(bytes) || bytes < 0) return "Não disponível"; if (bytes < 1024) return `${bytes.toLocaleString("pt-BR")} bytes`; const units = ["KB", "MB", "GB", "TB"]; let size = bytes; let index = -1; do { size /= 1024; index += 1; } while (size >= 1024 && index < units.length - 1); return `${size.toLocaleString("pt-BR", { minimumFractionDigits: index === 0 ? 0 : 2, maximumFractionDigits: 2 })} ${units[index]}`; };
    const action = (name, id, label) => `<button class="fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action" type="button" data-company-action="${name}" data-company-id="${escapeHtml(id)}" aria-label="${label}" title="${label}"><img src="${B}${icons[name]}" alt="" /></button>`;
    const showMessage = (value, tone = "danger") => { message.textContent = value || ""; message.dataset.tone = tone; message.hidden = !value; };
    const setSummary = (summary = {}) => { $("#company-summary-total").textContent = Number(summary.total || 0).toLocaleString("pt-BR"); $("#company-summary-active").textContent = Number(summary.active || 0).toLocaleString("pt-BR"); $("#company-summary-suspended").textContent = Number(summary.suspended || 0).toLocaleString("pt-BR"); $("#company-summary-pending").textContent = Number(summary.pending || 0).toLocaleString("pt-BR"); };
    const renderPagination = (meta = {}) => { const lastPage = Math.max(1, Number(meta.last_page || 1)); const currentPage = Math.min(lastPage, Math.max(1, Number(meta.current_page || 1))); pagination.innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-company-page="${currentPage - 1}" aria-label="Página anterior" ${currentPage === 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><button class="fs-page-link" type="button" data-company-page="${currentPage}" aria-label="Página ${currentPage}" aria-current="page">${currentPage}</button></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-company-page="${currentPage + 1}" aria-label="Próxima página" ${currentPage === lastPage ? "disabled" : ""}>›</button></li></ul>`; };
    const companyActions = (company) => { const shared = [action("details", company.id, "Ver dados da empresa"), action("edit", company.id, "Editar empresa")]; const contextual = { ativa: Number(company.active_subscriptions || 0) === 0 ? [action("deactivate", company.id, "Suspender empresa")] : [], suspensa: [action("activate", company.id, "Reativar empresa"), action("remove", company.id, "Excluir empresa")] }; return [...shared, ...(contextual[company.status] || [])].join(""); };
    const render = (response) => { const rows = response.data || []; list.innerHTML = rows.length ? rows.map((company) => { const actions = companyActions(company); const type = company.document_type === "cpf" ? "CPF" : "CNPJ"; return `<tr><td class="fs-width-600" data-label="Empresa"><strong>${escapeHtml(company.legal_name || "-")}</strong><small class="fs-u-d-block fs-u-fs-sm">${escapeHtml(company.name || "Cadastro comercial")}</small></td><td class="fs-width-400" data-label="Documento"><strong>${escapeHtml(formatDocument(company.document_number, company.document_type))}</strong><small class="fs-u-d-block fs-u-fs-sm">${type}</small></td><td class="fs-width-300" data-label="Status">${statusBadge(company.status)}</td><td class="fs-width-200" data-label="Assinaturas">${Number(company.active_subscriptions || 0).toLocaleString("pt-BR")}</td><td class="fs-width-600" data-label="Administrador"><strong class="fs-u-fw-semibold">${escapeHtml(company.admin_name || "-")}</strong><small class="fs-u-d-block fs-u-fs-sm">${escapeHtml(company.admin_email || "-")}</small></td><td class="fs-width-400" data-label="Ações"><div class="fs-u-d-flex fs-u-gap-2">${actions}</div></td></tr>`; }).join("") : '<tr><td colspan="6">Nenhuma empresa encontrada.</td></tr>'; const meta = response.meta || {}; const total = Number(meta.total || 0); const currentPage = Math.max(1, Number(meta.current_page || 1)); const perPage = Number(meta.per_page || rows.length || 1); const totalPages = Math.max(1, Number(meta.last_page || 1)); const recordsOnPage = rows.length; tableSummary.textContent = total ? `Mostrando ${(currentPage - 1) * perPage + 1} a ${Math.min(currentPage * perPage, total)} de ${total} empresas` : "Nenhuma empresa encontrada"; $("#company-table-footer-summary").textContent = `Mostrando página ${currentPage} de ${totalPages} com ${recordsOnPage} registros, de um total de ${totalPages} páginas.`; renderPagination(meta); setSummary(response.summary); window.refreshFokusDataTables?.(); };
    const load = async ({ silent = false } = {}) => {
        const sequence = ++loadSequence;
        try {
            const params = new URLSearchParams({ page: String(state.page), per_page: "15" });
            if (state.query) params.set("q", state.query);
            if (state.status) params.set("status", state.status);
            const response = await api.request(`/backoffice/companies?${params}`);
            if (!isPageActive() || sequence !== loadSequence) return;
            render(response);
            refreshError = "";
            syncRefreshStatus(drawer.getAttribute("aria-hidden") === "false");
            if (!silent) showMessage("");
        } catch (error) {
            if (!isPageActive() || sequence !== loadSequence) return;
            if (!silent) {
                showMessage(error.message || "Não foi possível carregar as empresas.");
            } else {
                refreshError = "Não foi possível atualizar a lista de empresas. Use os filtros para tentar novamente.";
                syncRefreshStatus(drawer.getAttribute("aria-hidden") === "false");
            }
        }
    };
    const onPreviousOperation = (outcomes, latest) => {
        if (!isPageActive()) return;
        operationOutcome = outcomes;
        syncRefreshStatus(drawer.getAttribute("aria-hidden") === "false");
        if (latest === "completed") void load({ silent: true });
    };
    if (adminId) {
        if (!operationListeners.has(adminId)) operationListeners.set(adminId, new Set());
        operationListeners.get(adminId).add(onPreviousOperation);
    }
    const removeOperationListener = () => {
        if (!adminId) return;
        const listeners = operationListeners.get(adminId);
        listeners?.delete(onPreviousOperation);
        if (!listeners?.size) operationListeners.delete(adminId);
    };
    context.signal?.addEventListener("abort", removeOperationListener, { once: true });
    const acknowledgeOperation = () => {
        const drawerOpen = drawer.getAttribute("aria-hidden") === "false";
        const focusedNotice = operationDrawerStatus.contains(document.activeElement) || operationPageStatus.contains(document.activeElement);
        if (adminId) {
            previousOperations.delete(adminId);
            operationListeners.get(adminId)?.forEach((listener) => listener({}, null));
        } else {
            operationOutcome = null;
            syncRefreshStatus(drawerOpen);
        }
        if (focusedNotice) (drawerOpen ? $("#company-drawer-close") : createTrigger).focus({ preventScroll: true });
    };
    operationDrawerStatus.querySelector("[data-company-operation-ack]").addEventListener("click", acknowledgeOperation);
    operationPageStatus.querySelector("[data-company-operation-ack]").addEventListener("click", acknowledgeOperation);
    syncRefreshStatus(false);
    const resetForm = () => { form.reset(); form.hidden = false; $("#company-view-panel").hidden = true; form.querySelectorAll("input, select").forEach((field) => { field.disabled = false; }); $("#company-document-type").value = "cnpj"; $("#company-version").value = ""; $("#company-document-number").readOnly = false; $("#company-password-field").hidden = false; $("#company-admin-password").required = true; $("#company-form-submit").hidden = false; $("#company-form-submit").disabled = submitting; $("#company-form-cancel").textContent = "Cancelar"; $("#company-form-submit").textContent = "Cadastrar empresa"; $("#company-drawer-kicker").textContent = "EMPRESAS"; $("#company-drawer-title").textContent = "Nova empresa"; $("#company-drawer-description").textContent = "Cadastre os dados da empresa e do administrador responsável."; $("#company-name-label").textContent = "Razão social"; currentCompany = null; };
    const renderLeadUsage = (usage) => {
        const panel = $("#company-view-lead-metrics");
        const status = $("#company-view-lead-status");
        const fields = { properties: "properties_count", developments: "developments_count", contacts: "contacts_count", inquiries: "inquiries_count", users: "referenced_users_count", storage: "storage_bytes" };
        const available = usage?.available === true && usage.metrics;
        panel.hidden = !available;
        status.textContent = available ? "Dados consultados no Fokus Lead." : usage?.reason === "not_provisioned" ? "Esta empresa ainda não possui uma operação no Fokus Lead." : "Os indicadores do Fokus Lead não estão disponíveis no momento.";
        for (const [field, key] of Object.entries(fields)) {
            $("#company-view-lead-" + field).textContent = !available ? "-" : field === "storage" ? formatBytes(usage.metrics[key]) : Number(usage.metrics[key]).toLocaleString("pt-BR");
        }
    };
    const openDrawer = (company = null, mode = "edit") => { drawerSession++; clearFormError(); resetForm(); renderLeadUsage(company?.data_volume?.lead); currentCompany = company; const viewPanel = $("#company-view-panel"); drawerController.setState({ mode: company ? mode : "create", record: company }); viewPanel.hidden = true; form.hidden = false; if (company) { const readOnly = mode === "view"; $("#company-drawer-kicker").textContent = readOnly ? "Consulta" : "Editar empresa"; $("#company-drawer-title").textContent = readOnly ? "Detalhes da empresa" : "Editar dados da empresa"; $("#company-drawer-description").textContent = readOnly ? "Consulte os dados cadastrais e do administrador." : "Altere somente os dados permitidos."; $("#company-form-submit").textContent = "Salvar alterações"; if (readOnly) { form.hidden = true; viewPanel.hidden = false; $("#company-view-name").textContent = company.legal_name || "-"; $("#company-view-document").textContent = formatDocument(company.document_number, company.document_type); $("#company-view-status").innerHTML = statusBadge(company.status); $("#company-view-admin-name").textContent = company.admin?.name || "-"; $("#company-view-admin-cpf").textContent = formatDocument(company.admin?.cpf, "cpf"); $("#company-view-admin-email").textContent = company.admin?.email || "-"; $("#company-view-subscriptions").textContent = String(company.subscriptions_count ?? 0); $("#company-view-created").textContent = company.created_at ? new Date(company.created_at).toLocaleDateString("pt-BR") : "-"; const contacts = company.data_volume?.contacts || {}; const registeredContacts = contacts.registered_count ?? contacts.used; $("#company-view-contacts-used").textContent = registeredContacts == null ? "Não disponível" : `${Number(registeredContacts).toLocaleString("pt-BR")} registros`; $("#company-view-contacts-limit").textContent = contacts.limit == null ? "Não configurado" : `${Number(contacts.limit).toLocaleString("pt-BR")} registros`; $("#company-view-contacts-percentage").textContent = contacts.limit > 0 && registeredContacts != null ? `${Number((registeredContacts / contacts.limit) * 100).toLocaleString("pt-BR", { maximumFractionDigits: 1 })} %` : "-"; $("#company-view-contacts-storage-estimate").textContent = formatBytes(company.data_volume?.contact_data_size_bytes);  } else { $("#company-legal-name").value = company.legal_name || ""; $("#company-document-type").value = company.document_type; $("#company-document-number").value = formatDocument(company.document_number, company.document_type); $("#company-document-number").disabled = true; $("#company-document-type").disabled = true; $("#company-version").value = company.version; $("#company-admin-name").value = company.admin?.name || ""; $("#company-admin-cpf").value = formatDocument(company.admin?.cpf, "cpf"); $("#company-admin-email").value = company.admin?.email || ""; $("#company-password-field").hidden = true; $("#company-admin-password").required = false; $("#company-name-label").textContent = company.document_type === "cpf" ? "Nome completo" : "Razão social"; } } drawerController.show(); if (!company || mode !== "view") $(company ? "#company-legal-name" : "#company-document-number").focus(); };
    const closeDrawer = () => { detailSequence++; drawerSession++; clearFormError(); drawerController.close(); currentCompany = null; };
    const loadCompany = async (id) => {
        const sequence = ++detailSequence;
        try {
            const response = await api.request(`/backoffice/companies/${id}?view=company`, { signal: context.signal });
            return isPageActive() && sequence === detailSequence ? response.company || response : null;
        } catch (error) {
            if (isPageActive() && sequence === detailSequence && error?.name !== 'AbortError') showMessage(error.message || "Não foi possível carregar os dados da empresa.");
            return null;
        }
    };
    const validateDocument = (input, type, errorId) => { const value = type === "cpf" ? documents.formatCpf(input.value) : documents.formatCnpj(input.value); const valid = type === "cnpj" ? documents.cnpj(value) : documents.cpf(value); input.value = value; if (valid) { window.FokusForm?.clearField(input); return true; } window.FokusForm?.setFeedback(input, `${type.toUpperCase()} inválido. Confira os dígitos verificadores.`); return false; };
    const submit = async (event) => {
        event.preventDefault();
        if (submitting) return;
        clearFormError();
        if (operationOutcome?.unknown) {
            showFormError("Confira a lista e reconheça o aviso da operação anterior antes de enviar novamente.");
            return;
        }
        const drawerState = drawerController.getState();
        if (drawerState.mode === "view" || !drawerState.mode) return;
        const editId = drawerState.mode === "edit" ? String(currentCompany?.id || drawerState.record?.id || "") : "";
        if (drawerState.mode === "edit" && !editId) { showFormError("Não foi possível identificar a empresa que será atualizada."); return; }
        const editing = Boolean(editId);
        const type = $("#company-document-type").value;
        if (!editing && !validateDocument($("#company-document-number"), type, "#company-document-error")) return;
        if (!validateDocument($("#company-admin-cpf"), "cpf", "#company-admin-cpf-error")) return;
        if (!form.reportValidity()) return;
        const values = Object.fromEntries(new FormData(form));
        const endpoint = editing ? `/backoffice/companies/${editId}` : "/backoffice/companies";
        const body = editing ? { legal_name: values.legal_name, name: values.name, cpf: values.cpf, email: values.email, version: Number(values.version) } : values;
        const session = drawerSession;
        const epoch = operationEpoch;
        const restoreAtSubmission = restoreGeneration;
        const submitButton = $("#company-form-submit");
        submitting = true;
        submitButton.disabled = true;
        let signature;
        let pending;
        let registered = false;
        let requestStarted = false;
        try {
            signature = await fingerprintOperation(adminId, editing ? "PATCH" : "POST", endpoint, body);
            if (!await restoreBarrier || epoch !== operationEpoch) return;
            if (restoreAtSubmission !== restoreGeneration) {
                if (isPageActive() && session === drawerSession && drawerController.getState().mode) showFormError("Sessão restaurada. Confira os dados antes de enviar novamente.");
                return;
            }
            const pendingKey = adminId || "anonymous";
            if (!pendingOperations.has(pendingKey)) pendingOperations.set(pendingKey, new Set());
            pending = pendingOperations.get(pendingKey);
            if (pending.has(signature)) {
                if (isPageActive() && session === drawerSession && drawerController.getState().mode) showFormError("Já existe uma solicitação idêntica em andamento. Aguarde a confirmação.");
                return;
            }
            pending.add(signature);
            registered = true;
            requestStarted = true;
            await api.request(endpoint, { method: editing ? "PATCH" : "POST", body });
            if (!await restoreBarrier) return;
            if (epoch !== operationEpoch) return;
            if (restoreAtSubmission !== restoreGeneration) {
                if (adminId) publishPreviousOperation(adminId, "unknown", epoch);
                else if (isPageActive()) onPreviousOperation({ ...operationOutcome, unknown: true }, "unknown");
                return;
            }
            if (!isPageActive() || session !== drawerSession || !drawerController.getState().mode) {
                if (adminId) publishPreviousOperation(adminId, "completed", epoch);
                else if (isPageActive()) onPreviousOperation({ ...operationOutcome, completed: true }, "completed");
                return;
            }
            closeDrawer();
            showMessage(editing ? "Dados da empresa atualizados." : "Empresa criada com acesso imediato.", "success");
            await load();
        } catch (error) {
            if (!await restoreBarrier) return;
            if (epoch !== operationEpoch) return;
            if (requestStarted && restoreAtSubmission !== restoreGeneration) {
                if (adminId) publishPreviousOperation(adminId, "unknown", epoch);
                else if (isPageActive()) onPreviousOperation({ ...operationOutcome, unknown: true }, "unknown");
                return;
            }
            // Only these responses establish that the controller rejected the change.
            // A transport failure or server error may occur after the transaction committed.
            const outcome = requestStarted && ![409, 422].includes(error.status) ? "unknown" : "rejected";
            const drawerStillOpen = isPageActive() && session === drawerSession && drawerController.getState().mode && drawer.isConnected;
            if (requestStarted && (outcome === "unknown" || !drawerStillOpen)) {
                if (adminId) publishPreviousOperation(adminId, outcome, epoch);
                else if (isPageActive()) onPreviousOperation({ ...operationOutcome, [outcome]: true }, outcome);
            }
            if (drawerStillOpen) showFormError(outcome === "unknown" ? "Não foi possível confirmar o resultado. Confira a lista e reconheça o aviso antes de tentar novamente." : (error.message || "Não foi possível salvar a empresa."));
        } finally {
            if (registered) pending?.delete(signature);
            if (pending && !pending.size && pendingOperations.get(adminId || "anonymous") === pending) pendingOperations.delete(adminId || "anonymous");
            submitting = false;
            submitButton.disabled = false;
        }
    };
    $("#company-query").value = state.query;
    $("#company-filter-form").addEventListener("submit", (event) => { event.preventDefault(); const data = new FormData(event.currentTarget); state.query = data.get("q")?.trim() || ""; state.status = data.get("status") || ""; state.page = 1; load(); });
    $("#company-create").addEventListener("click", () => { detailSequence++; openDrawer(); });
    $("#company-drawer-close").addEventListener("click", closeDrawer);
    $("#company-form-cancel").addEventListener("click", closeDrawer);
    form.addEventListener("submit", submit);
    const syncIndividualCompany = () => { if ($("#company-document-type").value !== "cpf") return; $("#company-admin-name").value = $("#company-legal-name").value; $("#company-admin-cpf").value = documents.formatCpf($("#company-document-number").value); };
    $("#company-document-type").addEventListener("change", (event) => { const input = $("#company-document-number"); $("#company-name-label").textContent = event.target.value === "cpf" ? "Nome completo" : "Razão social"; input.placeholder = event.target.value === "cpf" ? "000.000.000-00" : "00.000.000/0000-00"; input.value = ""; window.FokusForm?.clearField(input); syncIndividualCompany(); });
    $("#company-document-number").addEventListener("input", (event) => { const type = $("#company-document-type").value; event.target.value = type === "cpf" ? documents.formatCpf(event.target.value) : documents.formatCnpj(event.target.value); syncIndividualCompany(); });
    $("#company-legal-name").addEventListener("input", syncIndividualCompany);
    $("#company-document-number").addEventListener("blur", (event) => { if (!event.target.readOnly && !event.target.disabled) validateDocument(event.target, $("#company-document-type").value, "#company-document-error"); });
    $("#company-admin-cpf").addEventListener("input", (event) => { event.target.value = documents.formatCpf(event.target.value); });
    $("#company-admin-cpf").addEventListener("blur", (event) => validateDocument(event.target, "cpf", "#company-admin-cpf-error"));
    pagination.addEventListener("click", (event) => { const button = event.target.closest("[data-company-page]"); if (!button) return; state.page = Number(button.dataset.companyPage); load(); });
    list.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-company-action]");
        if (!button || !isPageActive()) return;
        const id = button.dataset.companyId;
        const type = button.dataset.companyAction;
        if (type === "details" || type === "edit") {
            const company = await loadCompany(id);
            if (company && isPageActive()) { openDrawer(company, type === "details" ? "view" : "edit"); normalizeDrawer(); }
            return;
        }
        const labels = { deactivate: "desativar", activate: "reativar", remove: "remover" };
        if (!window.confirm(`Deseja ${labels[type]} esta empresa?`)) return;
        const epoch = operationEpoch;
        try {
            const endpoint = type === "remove" ? `/backoffice/companies/${id}` : `/backoffice/companies/${id}/${type}`;
            await api.request(endpoint, { method: type === "remove" ? "DELETE" : "POST" });
            if (!await restoreBarrier || epoch !== operationEpoch) return;
            if (!isPageActive()) { if (adminId) publishPreviousOperation(adminId, "unknown", epoch); return; }
            showMessage(type === "remove" ? "Empresa removida da listagem." : type === "activate" ? "Empresa reativada." : "Empresa desativada.", "success");
            await load();
        } catch (error) {
            if (!await restoreBarrier || epoch !== operationEpoch) return;
            if (!isPageActive()) { if (adminId) publishPreviousOperation(adminId, "unknown", epoch); return; }
            showMessage(error.message || "Não foi possível concluir a ação.");
        }
    });
    const normalizeDrawer = () => { if (drawer.hidden) return; const mode = drawer.dataset.mode; const typeField = $("#company-document-type"); const documentField = $("#company-document-number"); const submitButton = $("#company-form-submit"); submitButton.textContent = mode === "edit" ? "Salvar alterações" : "Cadastrar empresa"; if (mode === "edit") { $("#company-drawer-title").textContent = "Editar dados da empresa"; $("#company-drawer-description").textContent = "Altere somente os dados cadastrais e do administrador permitidos."; typeField.disabled = true; documentField.disabled = true; documentField.value = formatDocument(documentField.value, typeField.value); } if (mode === "view") { const viewDocument = $("#company-view-document"); const viewAdminCpf = $("#company-view-admin-cpf"); const viewType = documents.compact(viewDocument.textContent).length === 11 ? "cpf" : typeField.value; viewDocument.textContent = formatDocument(viewDocument.textContent, viewType); viewAdminCpf.textContent = formatDocument(viewAdminCpf.textContent, "cpf"); } };
    load();
    const summary = root.querySelector('#company-table-summary');
    const normalizeSummary = () => {
        const match = summary?.textContent.match(/^Mostrando \d+ a \d+ de (\d+) empresas$/);
        if (!match) return;
        const value = `${match[1]} registros encontrados`;
        if (summary.textContent !== value) summary.textContent = value;
    };
    const summaryObserver = summary ? new MutationObserver(normalizeSummary) : null;
    summaryObserver?.observe(root, { childList: true, subtree: true, characterData: true });
    normalizeSummary();
    context.signal?.addEventListener('abort', () => summaryObserver?.disconnect(), { once: true });
    return () => {
        operationDrawerStatus.querySelector("[data-company-operation-ack]").removeEventListener("click", acknowledgeOperation);
        operationPageStatus.querySelector("[data-company-operation-ack]").removeEventListener("click", acknowledgeOperation);
        removeOperationListener();
        createTrigger.removeEventListener("fs:shown", drawerShown);
        createTrigger.removeEventListener("fs:hidden", drawerHidden);
        summaryObserver?.disconnect();
        drawerController.dispose();
        window.disposeBackofficeRecordsPage?.(root);
    };
}
