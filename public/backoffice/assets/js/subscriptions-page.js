import { createRecordsDrawer } from "./records-drawer.js";

const SUBSCRIPTIONS_PER_PAGE = 15;
const ICONS_PATH = "/backoffice/assets/icons/";

const STATUS_LABELS = {
    aguardando_pagamento: "Aguardando pagamento",
    ativa: "Ativa",
    inadimplente: "Inadimplente",
    suspensa: "Suspensa",
    cancelamento_agendado: "Cancelamento agendado",
    encerrada: "Encerrada",
    cancelada: "Cancelada",
};

const PAYMENT_STATUS_LABELS = {
    aguardando_pagamento: "Aguardando pagamento",
    aprovado: "Pago",
    recusado: "Pagamento recusado",
    cancelado: "Cancelado",
    estornado: "Estornado",
    em_disputa: "Em análise",
};

const ACTION_LABELS = {
    suspensao: "Suspensão",
    reativacao: "Reativação",
    cancelamento: "Cancelamento agendado",
    cancelamento_imediato: "Encerramento imediato",
    upgrade: "Upgrade",
    downgrade: "Downgrade",
    override: "Override comercial",
};

const STATUS_TONES = {
    aguardando_pagamento: "info",
    ativa: "success",
    inadimplente: "danger",
    suspensa: "warning",
    cancelamento_agendado: "warning",
    encerrada: "danger",
    cancelada: "secondary",
    aplicada: "success",
    agendada: "info",
    falhou: "danger",
    aguardando_confirmacao: "warning",
};

const CONDITION_LABELS = {
    usage_limit: "Limite de utilização",
    plan_code: "Plano",
    segment_code: "Segmento",
    variant_code: "Configuração",
    cycle: "Ciclo",
};

const SNAPSHOT_LABELS = {
    subscription_id: "Assinatura",
    company_id: "Empresa",
    product_id: "Produto",
    product_code: "Código do produto",
    product_name: "Produto",
    plan_id: "Plano",
    plan_code: "Código do plano",
    plan_name: "Plano",
    status: "Status",
    billing_cycle: "Ciclo",
    monthly_amount: "Valor mensal",
    amount: "Valor contratado",
    current_period_starts_at: "Início da vigência",
    current_period_ends_at: "Fim da vigência",
    cancel_at: "Cancelamento em",
};

const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
}[character]));

const money = (value, currency = "BRL") => Number(value || 0).toLocaleString("pt-BR", {
    style: "currency",
    currency: currency || "BRL",
});

const formatDate = (value, withTime = false) => {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleString("pt-BR", withTime
        ? { dateStyle: "short", timeStyle: "short" }
        : { dateStyle: "short" });
};

const badge = (value, labels = STATUS_LABELS) => {
    const tone = STATUS_TONES[value] || "secondary";
    const label = labels[value] || String(value || "Não informado").replaceAll("_", " ");
    return `<span class="fs-badge fs-badge-soft-${tone}">${escapeHtml(label)}</span>`;
};

const valueForDisplay = (key, value) => {
    if (value === null || value === undefined || value === "") return "—";
    if (key === "billing_cycle" || key === "cycle") return value === "annual" ? "Anual" : value === "monthly" ? "Mensal" : String(value);
    if (["amount", "monthly_amount", "unit_price", "proration_amount", "personalization_delta", "additional_monthly_amount"].includes(key)) return money(value);
    if (key.endsWith("_at") || key.endsWith("_date")) return formatDate(value, String(value).includes("T") && String(value).length > 10);
    if (key === "status") return STATUS_LABELS[value] || String(value).replaceAll("_", " ");
    if (typeof value === "boolean") return value ? "Sim" : "Não";
    if (Array.isArray(value)) return value.map((item) => typeof item === "object" ? JSON.stringify(item) : String(item)).join(", ") || "—";
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
};

const detailList = (entries) => entries.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join("");
const publishedVersionLabel = (version) => Number(version) > 0 ? `v${Number(version)}.0` : "Não registrada";
const publicationVersionEntries = (versions = {}) => [
    ["Versão do catálogo contratada", publishedVersionLabel(versions.product_catalog_version)],
    ["Versão do plano contratado", publishedVersionLabel(versions.plan_version)],
    ["Versões dos módulos contratados", versions.module_versions && Object.keys(versions.module_versions).length
        ? Object.entries(versions.module_versions).map(([code, version]) => `${code}: ${publishedVersionLabel(version)}`).join(", ")
        : "Não registradas"],
];

const itemConditions = (conditions = {}) => Object.entries(conditions)
    .filter(([key, value]) => value !== null && value !== "" && !["module_code", "selection_mode", "context_code", "collaboration_code", "collaboration"].includes(key))
    .filter(([key, value]) => !(key === "variant_code" && value === "colaboracao"))
    .map(([key, value]) => {
        if (key === "personalizations") {
            const entries = Array.isArray(value) ? value : (value && typeof value === "object" ? Object.entries(value).map(([type_code, selection]) => ({ type_code, ...(selection && typeof selection === "object" ? selection : { value: selection }) })) : []);
            const formatted = entries.map((selection) => {
                if (!selection || typeof selection !== "object") return String(selection ?? "");
                const type = selection.type_label || selection.type_code || selection.name || "Personalização";
                const selected = selection.value ?? selection.tier_value ?? selection.tier?.value;
                const amount = selection.additional_monthly_amount ?? selection.monthly_amount;
                const parts = [selected !== undefined && selected !== null ? String(selected) : "Faixa selecionada"];
                if (amount !== undefined && amount !== null) parts.push(`${money(amount)}/mês`);
                return `${type}: ${parts.join(" · ")}`;
            }).filter(Boolean).join("; ");
            return ["Personalizações", formatted || "—"];
        }
        const formatted = key === "cycle" ? valueForDisplay(key, value)
            : Array.isArray(value) ? value.map((entry) => entry && typeof entry === "object" ? JSON.stringify(entry) : String(entry)).join(", ")
                : valueForDisplay(key, value);
        return [CONDITION_LABELS[key] || key.replaceAll("_", " "), formatted];
    });

const snapshotCard = (title, snapshot) => {
    if (!snapshot || !Object.keys(snapshot).length) return `<p class="fs-u-fs-sm fs-u-color-secondary">Snapshot não disponível.</p>`;
    const preferredKeys = Object.keys(SNAPSHOT_LABELS).filter((key) => snapshot[key] !== undefined && snapshot[key] !== null && snapshot[key] !== "");
    const known = preferredKeys.map((key) => [SNAPSHOT_LABELS[key], valueForDisplay(key, snapshot[key])]);
    const other = Object.entries(snapshot)
        .filter(([key, value]) => !SNAPSHOT_LABELS[key] && value !== null && value !== undefined && typeof value !== "object")
        .map(([key, value]) => [key.replaceAll("_", " "), valueForDisplay(key, value)]);
    const versions = snapshot.publication_versions ? publicationVersionEntries(snapshot.publication_versions) : [];
    const items = Array.isArray(snapshot.items) ? `<h5 class="fs-u-fs-sm">Itens</h5><ul>${snapshot.items.map((item) => `<li>${escapeHtml(item.name || item.module_name || "Item")} · ${escapeHtml(item.quantity ?? 1)} × ${escapeHtml(money(item.unit_price))}</li>`).join("")}</ul>` : "";
    return `<section aria-label="${escapeHtml(title)}"><h4 class="fs-u-fs-sm">${escapeHtml(title)}</h4><dl class="fs-detail-list">${detailList([...known, ...other, ...versions])}</dl>${items}</section>`;
};

export function mount(root, context = {}) {
    const $ = (selector) => root.querySelector(selector) || document.querySelector(selector);
    const api = context.api || window.FokusApi;
    const form = $("#subscription-change-form");
    const actionFooter = $("#subscription-action-footer");
    const list = $("#subscription-list");
    const pagination = $("#subscription-pagination");
    const table = root.querySelector(".fs-table-responsive");
    const loadingState = $("[data-fs-datatable-loading]");
    const emptyState = $("[data-fs-datatable-empty]");
    const tableError = $("[data-fs-datatable-error]");
    const drawerElement = $("#subscription-drawer");
    const drawerTrigger = $("#subscription-drawer-trigger");
    const drawer = createRecordsDrawer({ trigger: drawerTrigger, drawer: drawerElement });
    const createTrigger = $("#subscription-create-trigger");
    const createDrawer = createRecordsDrawer({ trigger: createTrigger, drawer: $("#subscription-create-drawer") });
    const confirmTrigger = $("#subscription-confirm-trigger");
    const confirmDialog = $("#subscription-confirm-dialog");
    const confirmModal = window.FokusStyles?.Modal?.getOrCreateInstance(confirmTrigger);
    const pageAbort = new AbortController();
    const listeners = new AbortController();
    const state = {
        page: Number(new URLSearchParams(location.search).get("page")) || 1,
        query: new URLSearchParams(location.search).get("q")?.trim() || "",
        status: new URLSearchParams(location.search).get("status") || "",
        productId: new URLSearchParams(location.search).get("product_id") || "",
        current: null,
        plans: [],
        products: [],
        listController: null,
        detailController: null,
        catalogController: null,
        lastTriggerId: null,
        pendingAction: null,
        saving: false,
        checkoutProducts: [],
        optionsController: null,
        creating: false,
    };

    const canOverride = context.permissions?.has?.("platform.commercial.override")
        ?? Boolean(context.permissions?.includes?.("platform.commercial.override"));
    const setMessage = (text, tone = "danger") => {
        const message = $("#subscription-message");
        message.textContent = text || "";
        message.dataset.tone = tone;
        message.hidden = !text;
    };
    const setTableError = (text) => {
        tableError.querySelector("[data-fs-datatable-error-message]").textContent = text;
        tableError.hidden = !text;
    };
    const requestScope = (slot) => {
        state[slot]?.abort();
        const controller = new AbortController();
        const abortOnPageDispose = () => controller.abort();
        pageAbort.signal.addEventListener("abort", abortOnPageDispose, { once: true });
        state[slot] = controller;
        return {
            signal: controller.signal,
            done: () => {
                pageAbort.signal.removeEventListener("abort", abortOnPageDispose);
                if (state[slot] === controller) state[slot] = null;
            },
        };
    };

    const renderPagination = (meta = {}) => {
        const lastPage = Math.max(1, Number(meta.last_page) || 1);
        const currentPage = Math.min(lastPage, Math.max(1, Number(meta.current_page) || 1));
        pagination.innerHTML = `<ul class="fs-pagination fs-pagination-compact" aria-label="Páginas de assinaturas">
            <li class="fs-page-item"><button class="fs-page-link" type="button" data-subscription-page="${currentPage - 1}" aria-label="Página anterior" ${currentPage <= 1 ? "disabled" : ""}>‹</button></li>
            <li class="fs-page-item is-active" aria-current="page"><span class="fs-page-link" aria-label="Página ${currentPage} de ${lastPage}">${currentPage}</span></li>
            <li class="fs-page-item"><button class="fs-page-link" type="button" data-subscription-page="${currentPage + 1}" aria-label="Próxima página" ${currentPage >= lastPage ? "disabled" : ""}>›</button></li>
        </ul>`;
    };

    const renderList = (response) => {
        const rows = response.data || [];
        const meta = response.meta || {};
        const currentPage = Math.max(1, Number(meta.current_page) || 1);
        const lastPage = Math.max(1, Number(meta.last_page) || 1);
        const perPage = Number(meta.per_page) || SUBSCRIPTIONS_PER_PAGE;
        const total = Number(meta.total) || 0;
        state.page = currentPage;
        list.innerHTML = rows.length ? rows.map((subscription) => `<tr>
            <td class="fs-width-600" data-label="Empresa"><strong>${escapeHtml(subscription.company_name || "—")}</strong></td>
            <td class="fs-width-400" data-label="Produto">${escapeHtml(subscription.product_name || "—")}</td>
            <td class="fs-width-400" data-label="Plano">${escapeHtml(subscription.plan_name || "—")}</td>
            <td class="fs-width-500" data-label="Status">${badge(subscription.status)}</td>
            <td class="fs-width-400" data-label="Vigência">${escapeHtml(formatDate(subscription.current_period_ends_at))}</td>
            <td class="fs-width-300 cell-value" data-label="Valor"><strong>${escapeHtml(money(subscription.amount))}</strong>${subscription.has_active_free_benefit && subscription.base_amount !== null ? `<small class="fs-u-d-block fs-u-fs-sm">Preço-base: ${escapeHtml(money(subscription.base_amount))}</small>` : ""}</td>
            <td class="fs-width-400" data-label="Ações"><div class="fs-u-d-flex fs-u-gap-2"><button class="fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action" type="button" data-subscription-id="${escapeHtml(subscription.id)}" aria-label="Ver detalhes da assinatura de ${escapeHtml(subscription.company_name || "empresa")}" title="Ver detalhes da assinatura"><img src="${ICONS_PATH}Folder-File--Streamline-Ultimate.png" alt="" /></button></div></td>
        </tr>`).join("") : "";
        $("#subscription-table-summary").textContent = `${total.toLocaleString("pt-BR")} ${total === 1 ? "assinatura encontrada" : "assinaturas encontradas"}`;
        const from = total ? (currentPage - 1) * perPage + 1 : 0;
        const to = Math.min(currentPage * perPage, total);
        $("#subscription-table-footer-summary").textContent = total
            ? `Mostrando ${from} a ${to} de ${total} assinaturas · Página ${currentPage} de ${lastPage}.`
            : "Nenhuma assinatura encontrada.";
        emptyState.hidden = Boolean(rows.length);
        loadingState.hidden = true;
        setTableError("");
        table.setAttribute("aria-busy", "false");
        renderPagination(meta);
        window.refreshFokusDataTables?.(root);
    };

    const loadList = async () => {
        const scope = requestScope("listController");
        const params = new URLSearchParams({ page: String(state.page), per_page: String(SUBSCRIPTIONS_PER_PAGE) });
        if (state.query) params.set("q", state.query);
        if (state.status) params.set("status", state.status);
        if (state.productId) params.set("product_id", state.productId);
        list.innerHTML = "";
        pagination.innerHTML = "";
        emptyState.hidden = true;
        setTableError("");
        loadingState.hidden = false;
        table.setAttribute("aria-busy", "true");
        $("#subscription-table-summary").textContent = "Carregando assinaturas...";
        $("#subscription-table-footer-summary").textContent = "Carregando resultados...";
        try {
            const response = await api.request(`/backoffice/subscriptions?${params}`, { signal: scope.signal });
            if (!scope.signal.aborted) renderList(response);
        } catch (error) {
            if (error.name !== "AbortError") {
                loadingState.hidden = true;
                table.setAttribute("aria-busy", "false");
                $("#subscription-table-summary").textContent = "Não foi possível carregar as assinaturas.";
                $("#subscription-table-footer-summary").textContent = "Tente carregar os resultados novamente.";
                setTableError(error.message || "Não foi possível carregar as assinaturas.");
                setMessage(error.message || "Não foi possível carregar as assinaturas.");
            }
        } finally {
            scope.done();
        }
    };

    const loadCatalog = async () => {
        const scope = requestScope("catalogController");
        try {
            const catalog = await api.request("/backoffice/catalog", { signal: scope.signal });
            if (scope.signal.aborted) return;
            state.products = catalog.products || [];
            state.plans = state.products.flatMap((product) => (product.plans || []).map((plan) => ({ ...plan, product_name: product.name })));
            $("#subscription-product").insertAdjacentHTML("beforeend", state.products.map((product) => `<option value="${escapeHtml(product.id)}">${escapeHtml(product.name || product.code)}</option>`).join(""));
            const targetPlan = $("#subscription-target-plan");
            targetPlan.innerHTML = '<option value="">Selecione um plano</option>' + state.plans.map((plan) => `<option value="${escapeHtml(plan.id)}">${escapeHtml(plan.product_name)} · ${escapeHtml(plan.full_name || plan.name)}</option>`).join("");
            $("#subscription-product").value = state.productId;
            $("#subscription-status").value = state.status;
        } catch (error) {
            if (error.name !== "AbortError") {
                $("#subscription-product").disabled = true;
                $("#subscription-product").innerHTML = '<option value="">Produtos indisponíveis</option>';
                $("#subscription-target-plan").innerHTML = '<option value="">Planos indisponíveis</option>';
                setMessage(error.message || "Não foi possível carregar os produtos e planos.");
            }
        } finally {
            scope.done();
        }
    };

    const loadCheckoutOptions = async () => {
        const scope = requestScope("optionsController");
        const query = $("#subscription-company-search").value.trim();
        const companySelect = $("#subscription-create-company");
        const selectedCompany = companySelect.value;
        companySelect.innerHTML = '<option value="">Carregando empresas...</option>';
        try {
            const options = await api.request(`/backoffice/subscriptions/checkout-options?q=${encodeURIComponent(query)}`, { signal: scope.signal });
            if (scope.signal.aborted) return;
            state.checkoutProducts = options.products || [];
            const catalogNotice = $("#subscription-create-catalog-notice");
            catalogNotice.textContent = options.catalog_message || "";
            catalogNotice.hidden = !options.catalog_message;
            companySelect.innerHTML = '<option value="">Selecione uma empresa</option>' + (options.companies || []).map((company) => `<option value="${escapeHtml(company.id)}">${escapeHtml(company.legal_name)}</option>`).join("");
            if ([...companySelect.options].some((option) => option.value === selectedCompany)) companySelect.value = selectedCompany;
            const productSelect = $("#subscription-create-product");
            const selectedProduct = productSelect.value;
            productSelect.innerHTML = `<option value="">${state.checkoutProducts.length ? "Selecione um produto" : "Nenhuma oferta publicada disponível"}</option>` + state.checkoutProducts.map((product) => `<option value="${escapeHtml(product.code)}">${escapeHtml(product.name)}</option>`).join("");
            productSelect.value = selectedProduct;
            updateCheckoutPlans();
        } catch (error) {
            if (error.name !== "AbortError") {
                companySelect.innerHTML = '<option value="">Empresas indisponíveis</option>';
                $("#subscription-create-error").textContent = error.message || "Não foi possível carregar as opções de contratação.";
                $("#subscription-create-error").hidden = false;
            }
        } finally {
            scope.done();
        }
    };

    const updateCheckoutAmount = () => {
        const product = state.checkoutProducts.find((item) => item.code === $("#subscription-create-product").value);
        const plan = product?.plans?.find((item) => item.code === $("#subscription-create-plan").value);
        $("#subscription-create-amount").textContent = plan
            ? `Valor publicado: ${money($("#subscription-create-cycle").value === "annual" ? plan.annual_amount : plan.monthly_amount)}. O servidor confirma o preço no checkout.`
            : "Selecione um plano para consultar o valor publicado. O servidor confirma o preço no checkout.";
    };

    const updateCheckoutPlans = () => {
        const select = $("#subscription-create-plan");
        const previous = select.value;
        const product = state.checkoutProducts.find((item) => item.code === $("#subscription-create-product").value);
        const plans = product?.plans || [];
        select.innerHTML = `<option value="">${plans.length ? "Selecione um plano" : "Nenhum plano publicado disponível"}</option>` + plans.map((plan) => `<option value="${escapeHtml(plan.code)}">${escapeHtml(plan.name)}</option>`).join("");
        if ([...select.options].some((option) => option.value === previous)) select.value = previous;
        updateCheckoutAmount();
    };

    const onCreateSubmit = async (event) => {
        event.preventDefault();
        if (state.creating || !event.currentTarget.reportValidity()) return;
        state.creating = true;
        const submit = $("#subscription-create-submit");
        submit.disabled = true;
        $("#subscription-create-error").hidden = true;
        $("#subscription-create-success").hidden = true;
        try {
            const body = Object.fromEntries(new FormData(event.currentTarget));
            const response = await api.request("/backoffice/subscriptions/checkout", {
                method: "POST", body, headers: { "Idempotency-Key": crypto.randomUUID() }, signal: pageAbort.signal,
            });
            const success = $("#subscription-create-success");
            success.replaceChildren(document.createTextNode(`Assinatura ${response.subscription_id} criada e aguardando pagamento. `));
            if (response.checkout_url) {
                const link = document.createElement("a");
                link.href = response.checkout_url;
                link.target = "_blank";
                link.rel = "noopener noreferrer";
                link.textContent = "Abrir checkout do Mercado Pago";
                success.append(link);
            }
            success.hidden = false;
            setMessage("Assinatura criada e checkout gerado. A ativação depende da confirmação do pagamento.", "success");
            await loadList();
        } catch (error) {
            if (error.name !== "AbortError") {
                $("#subscription-create-error").textContent = error.message || "Não foi possível gerar o checkout.";
                $("#subscription-create-error").hidden = false;
            }
        } finally {
            state.creating = false;
            submit.disabled = false;
        }
    };

    const onFreeVoucherSubmit = async (event) => {
        event.preventDefault();
        if (!state.current || !event.currentTarget.reportValidity()) return;
        const subscriptionId = state.current.id;
        const submit = $("#subscription-free-voucher-submit");
        submit.disabled = true;
        $("#subscription-free-voucher-error").hidden = true;
        try {
            const code = String(new FormData(event.currentTarget).get("voucher_code") || "").trim();
            const result = await api.request(`/backoffice/subscriptions/${encodeURIComponent(subscriptionId)}/free-voucher`, {
                method: "POST", body: { voucher_code: code }, signal: pageAbort.signal,
            });
            setMessage(`${result.message} Benefício até ${formatDate(result.benefit_ends_at)}.`, "success");
            await Promise.all([loadDetails(subscriptionId), loadList()]);
        } catch (error) {
            if (error.name !== "AbortError") {
                $("#subscription-free-voucher-error").textContent = error.message || "Não foi possível ativar o voucher.";
                $("#subscription-free-voucher-error").hidden = false;
            }
        } finally {
            submit.disabled = false;
        }
    };

    const renderDetails = (subscription) => {
        state.current = subscription;
        $("#subscription-free-voucher-form").hidden = subscription.status !== "aguardando_pagamento";
        $("#subscription-free-voucher-form").reset();
        $("#subscription-free-voucher-error").hidden = true;
        $("#subscription-drawer-title").textContent = "Detalhes da assinatura";
        $("#subscription-drawer-description").textContent = `${subscription.company_name || "Empresa"} · ${subscription.product_name || "Assinatura"}`;
        $("#subscription-detail-summary").textContent = `${subscription.plan_name || "Plano não informado"} · ${STATUS_LABELS[subscription.status] || subscription.status} · ${valueForDisplay("billing_cycle", subscription.billing_cycle)} · ${money(subscription.amount)}`;
        const publicationVersions = subscription.commercial_snapshot?.publication_versions || {};
        const fields = [
            ["Número da assinatura", subscription.id],
            ["Revisão do contrato", `r${Number(subscription.version) || 1}`],
            ["Empresa", subscription.company_name],
            ["Produto", subscription.product_name],
            ["Plano contratado", subscription.plan_name],
            ...publicationVersionEntries(publicationVersions),
            ["Status", STATUS_LABELS[subscription.status] || subscription.status],
            ["Ciclo", valueForDisplay("billing_cycle", subscription.billing_cycle)],
            ["Valor contratado", money(subscription.amount)],
            ["Valor mensal", subscription.monthly_amount === null ? "—" : money(subscription.monthly_amount)],
            ...(subscription.has_active_free_benefit ? [["Preço-base", money(subscription.base_amount)], ["Preço-base mensal", money(subscription.base_monthly_amount)]] : []),
            ["Início da vigência", formatDate(subscription.current_period_starts_at)],
            ["Fim da vigência", formatDate(subscription.current_period_ends_at)],
            ["Cancelamento agendado", formatDate(subscription.cancel_at)],
        ];
        $("#subscription-detail-data").innerHTML = detailList(fields);

        const items = subscription.items || [];
        $("#subscription-detail-items").innerHTML = items.length ? `<div class="fs-u-d-flex fs-u-flex-column fs-u-gap-2">${items.map((item) => {
            const conditions = itemConditions(item.conditions || {});
            const basePrice = item.base_unit_price === null || item.base_unit_price === undefined ? "" : `<small class="fs-u-d-block">Preço-base: ${escapeHtml(money(item.base_unit_price))}</small>`;
            return `<article class="fs-card"><div class="fs-card-header fs-u-d-flex fs-u-justify-content-between fs-u-align-items-center fs-u-gap-2"><h4 class="fs-card-title">${escapeHtml(item.name || "Item contratado")}</h4><span class="fs-badge fs-badge-soft-secondary">${escapeHtml(item.quantity)} × ${escapeHtml(money(item.unit_price))}${basePrice}</span></div><div class="fs-card-body"><dl class="fs-detail-list">${detailList([["Quantidade", item.quantity], ["Valor unitário", money(item.unit_price)], ...conditions])}</dl></div></article>`;
        }).join("")}</div>` : '<p class="fs-u-fs-sm fs-u-color-secondary">Nenhum item contratado foi informado.</p>';

        const payments = subscription.payments || [];
        $("#subscription-detail-payments").innerHTML = payments.length ? payments.map((payment) => `<article class="fs-card"><div class="fs-card-header fs-u-d-flex fs-u-flex-wrap fs-u-justify-content-between fs-u-align-items-center fs-u-gap-2"><h4 class="fs-card-title">${escapeHtml(money(payment.amount, payment.currency))}</h4>${badge(payment.status, PAYMENT_STATUS_LABELS)}</div><div class="fs-card-body"><dl class="fs-detail-list">${detailList([
            ["Provedor", payment.provider || "—"],
            ["Criado em", formatDate(payment.created_at, true)],
            ["Pago em", formatDate(payment.paid_at, true)],
            ["Período da cobrança", `${formatDate(payment.billing_period_starts_at)} a ${formatDate(payment.billing_period_ends_at)}`],
        ])}</dl></div></article>`).join("") : '<p class="fs-u-fs-sm fs-u-color-secondary">Nenhum pagamento vinculado à assinatura.</p>';

        const history = subscription.history || [];
        $("#subscription-detail-history").innerHTML = history.length ? history.map((change) => `<article class="fs-card"><div class="fs-card-header fs-u-d-flex fs-u-flex-wrap fs-u-justify-content-between fs-u-align-items-center fs-u-gap-2"><div><h4 class="fs-card-title">${escapeHtml(ACTION_LABELS[change.type] || String(change.type || "Alteração").replaceAll("_", " "))}</h4><p class="fs-card-subtitle">${escapeHtml(formatDate(change.created_at, true))}</p></div>${badge(change.status)}</div><div class="fs-card-body fs-u-d-flex fs-u-flex-column fs-u-gap-2"><dl class="fs-detail-list">${detailList([
            ["Motivo", change.reason || "Sem motivo registrado"],
            ["Vigência da alteração", formatDate(change.effective_at, true)],
            ["Cobrança proporcional", money(change.proration_amount)],
        ])}</dl><details><summary>Consultar snapshots comerciais</summary><div class="fs-u-d-flex fs-u-flex-column fs-u-gap-2 fs-u-mt-2">${snapshotCard("Antes da alteração", change.before_snapshot)}${snapshotCard("Depois da alteração", change.after_snapshot)}</div></details></div></article>`).join("") : '<p class="fs-u-fs-sm fs-u-color-secondary">Nenhuma alteração comercial registrada.</p>';

        $("#subscription-detail-sections").hidden = false;
        form.hidden = false;
        actionFooter.hidden = false;
        $("#subscription-detail-loading").hidden = true;
        $("#subscription-detail-error").hidden = true;
        $("#subscription-drawer-content").setAttribute("aria-busy", "false");
        form.reset();
        $("#subscription-target-fields").hidden = true;
        $("#subscription-override-fields").hidden = true;
        $("#subscription-target-plan").required = false;
        $("#subscription-form-error").hidden = true;
        $("#subscription-action-submit").disabled = false;
        drawer.setState({ mode: "view", record: subscription });
    };

    const loadDetails = async (subscriptionId) => {
        const scope = requestScope("detailController");
        $("#subscription-detail-sections").hidden = true;
        $("#subscription-detail-loading").hidden = false;
        $("#subscription-detail-error").hidden = true;
        $("#subscription-drawer-content").setAttribute("aria-busy", "true");
        $("#subscription-drawer-title").textContent = "Carregando assinatura...";
        try {
            const subscription = await api.request(`/backoffice/subscriptions/${encodeURIComponent(subscriptionId)}`, { signal: scope.signal });
            if (scope.signal.aborted) return false;
            renderDetails(subscription);
            return true;
        } catch (error) {
            if (error.name !== "AbortError") {
                $("#subscription-detail-loading").hidden = true;
                $("#subscription-detail-error").textContent = error.message || "Não foi possível carregar os detalhes da assinatura.";
                $("#subscription-detail-error").hidden = false;
                $("#subscription-drawer-content").setAttribute("aria-busy", "false");
                setMessage(error.message || "Não foi possível carregar os detalhes da assinatura.");
            }
            return false;
        } finally {
            scope.done();
        }
    };

    const showDrawer = () => drawer.show();
    const restoreFocus = () => {
        if (!state.lastTriggerId) return;
        const trigger = [...root.querySelectorAll("[data-subscription-id]")].find((button) => button.dataset.subscriptionId === state.lastTriggerId);
        trigger?.focus();
    };

    const updateActionFields = () => {
        const action = $("#subscription-action").value;
        const targetFields = $("#subscription-target-fields");
        const overrideFields = $("#subscription-override-fields");
        const usesTarget = ["upgrade", "downgrade"].includes(action);
        targetFields.hidden = !usesTarget;
        overrideFields.hidden = action !== "override" || !canOverride;
        $("#subscription-target-plan").required = usesTarget;
        $("#subscription-cycle").required = usesTarget;
        $("#subscription-override-amount").required = action === "override" && canOverride;
    };

    const actionBody = (data) => {
        const action = data.get("action");
        const body = { action, reason: String(data.get("reason") || "").trim() };
        if (["upgrade", "downgrade"].includes(action)) {
            body.target_plan_id = data.get("target_plan_id");
            body.billing_cycle = data.get("billing_cycle");
        }
        if (action === "override") {
            body.override = {
                monthly_amount: Number(data.get("override_monthly_amount")),
                billing_cycle: data.get("override_billing_cycle"),
            };
        }
        return body;
    };

    const executeAction = async () => {
        if (!state.current || !state.pendingAction || state.saving) return;
        state.saving = true;
        const submit = $("#subscription-action-submit");
        submit.disabled = true;
        submit.setAttribute("aria-busy", "true");
        $("#subscription-form-error").hidden = true;
        try {
            const subscriptionId = state.current.id;
            const response = await api.request(`/backoffice/subscriptions/${encodeURIComponent(subscriptionId)}`, {
                method: "PATCH",
                body: state.pendingAction,
                signal: pageAbort.signal,
            });
            state.pendingAction = null;
            confirmModal?.hide?.();
            setMessage(response?.message || "Ação comercial registrada.", "success");
            await Promise.all([loadDetails(subscriptionId), loadList()]);
        } catch (error) {
            if (error.name !== "AbortError") {
                $("#subscription-form-error").textContent = error.message || "Não foi possível registrar a ação comercial.";
                $("#subscription-form-error").hidden = false;
                setMessage(error.message || "Não foi possível registrar a ação comercial.");
            }
        } finally {
            state.saving = false;
            submit.disabled = false;
            submit.removeAttribute("aria-busy");
        }
    };

    const onFilterSubmit = (event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        state.query = String(data.get("q") || "").trim();
        state.status = String(data.get("status") || "");
        state.productId = String(data.get("product_id") || "");
        state.page = 1;
        const params = new URLSearchParams(location.search);
        for (const [key, value] of [["q", state.query], ["status", state.status], ["product_id", state.productId]]) {
            value ? params.set(key, value) : params.delete(key);
        }
        params.delete("page");
        const queryString = params.toString();
        history.replaceState(history.state, "", `${location.pathname}${queryString ? `?${queryString}` : ""}${location.hash}`);
        loadList();
    };

    const onListClick = async (event) => {
        const button = event.target.closest("[data-subscription-id]");
        if (!button || !list.contains(button)) return;
        state.lastTriggerId = button.dataset.subscriptionId;
        showDrawer();
        await loadDetails(state.lastTriggerId);
    };

    const onPageClick = (event) => {
        const button = event.target.closest("[data-subscription-page]");
        if (!button || button.disabled) return;
        state.page = Number(button.dataset.subscriptionPage) || 1;
        loadList();
    };

    const onActionChange = () => updateActionFields();
    const onActionSubmit = (event) => {
        event.preventDefault();
        if (!state.current || state.saving) return;
        updateActionFields();
        if (window.FokusForm && !window.FokusForm.validate(form)) return;
        if (!form.reportValidity()) return;
        const data = new FormData(form);
        const action = String(data.get("action") || "");
        if (action === "override" && !canOverride) {
            $("#subscription-form-error").textContent = "Seu perfil não pode executar override comercial.";
            $("#subscription-form-error").hidden = false;
            return;
        }
        state.pendingAction = actionBody(data);
        if (action === "cancelamento_imediato") {
            $("#subscription-confirm-reason").textContent = `Motivo informado: ${state.pendingAction.reason}`;
            confirmModal?.show?.();
            return;
        }
        executeAction();
    };

    const onConfirm = () => executeAction();
    const onConfirmHidden = () => $("#subscription-action-submit").focus();
    const onDrawerHidden = () => restoreFocus();
    const onReset = () => {
        queueMicrotask(() => {
            $("#subscription-target-fields").hidden = true;
            $("#subscription-override-fields").hidden = true;
            $("#subscription-target-plan").required = false;
            $("#subscription-cycle").required = false;
            $("#subscription-override-amount").required = false;
            $("#subscription-form-error").hidden = true;
        });
    };

    $("#subscription-query").value = state.query;
    $("#subscription-status").value = state.status;
    const overrideOption = $("#subscription-override-option");
    if (overrideOption) {
        overrideOption.hidden = !canOverride;
        overrideOption.disabled = !canOverride;
    }

    $("#subscription-filter-form").addEventListener("submit", onFilterSubmit, { signal: listeners.signal });
    $("#subscription-create-open").addEventListener("click", () => {
        $("#subscription-create-form").reset();
        $("#subscription-create-error").hidden = true;
        $("#subscription-create-success").hidden = true;
        createDrawer.setState({ mode: "create" });
        createDrawer.show();
        loadCheckoutOptions();
        queueMicrotask(() => $("#subscription-company-search").focus());
    }, { signal: listeners.signal });
    let companySearchTimer;
    $("#subscription-company-search").addEventListener("input", () => {
        clearTimeout(companySearchTimer);
        companySearchTimer = setTimeout(loadCheckoutOptions, 250);
    }, { signal: listeners.signal });
    $("#subscription-create-product").addEventListener("change", updateCheckoutPlans, { signal: listeners.signal });
    $("#subscription-create-plan").addEventListener("change", updateCheckoutAmount, { signal: listeners.signal });
    $("#subscription-create-cycle").addEventListener("change", updateCheckoutAmount, { signal: listeners.signal });
    $("#subscription-create-form").addEventListener("submit", onCreateSubmit, { signal: listeners.signal });
    $("#subscription-free-voucher-form").addEventListener("submit", onFreeVoucherSubmit, { signal: listeners.signal });
    createTrigger.addEventListener("fs:hidden", () => $("#subscription-create-open").focus(), { signal: listeners.signal });
    form.addEventListener("submit", onActionSubmit, { signal: listeners.signal });
    list.addEventListener("click", onListClick, { signal: listeners.signal });
    pagination.addEventListener("click", onPageClick, { signal: listeners.signal });
    $("#subscription-action").addEventListener("change", onActionChange, { signal: listeners.signal });
    form.addEventListener("reset", onReset, { signal: listeners.signal });
    $("#subscription-confirm-submit").addEventListener("click", onConfirm, { signal: listeners.signal });
    confirmTrigger.addEventListener("fs:hidden", onConfirmHidden, { signal: listeners.signal });
    drawerTrigger.addEventListener("fs:hidden", onDrawerHidden, { signal: listeners.signal });

    const abortOnRouterNavigation = () => pageAbort.abort();
    context.signal?.addEventListener("abort", abortOnRouterNavigation, { once: true });

    loadCatalog().then(() => {
        if (!pageAbort.signal.aborted) loadList();
    });

    return () => {
        context.signal?.removeEventListener("abort", abortOnRouterNavigation);
        pageAbort.abort();
        listeners.abort();
        clearTimeout(companySearchTimer);
        createDrawer.dispose();
        drawer.dispose();
        confirmModal?.dispose?.();
        window.disposeBackofficeRecordsPage?.(root);
    };
}
