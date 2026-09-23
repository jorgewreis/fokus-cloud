import { createRecordsDrawer } from "./records-drawer.js";

const PAGE_SIZE = 15;
const ICONS_PATH = "/backoffice/assets/icons/";
const ICONS = {
    view: "Folder-File--Streamline-Ultimate.png",
    edit: "Common-File-Edit--Streamline-Ultimate.png",
    pause: "Common-File-Subtract--Streamline-Ultimate.png",
    reactivate: "Common-File-Quill--Streamline-Ultimate.png",
    archive: "Tags-Minus--Streamline-Ultimate.png",
    delete: "Tags-Remove--Streamline-Ultimate.png",
};

const BENEFITS = {
    trial_free: "Assinatura gratuita",
    percentage: "Desconto percentual",
    fixed: "Desconto em valor fixo",
    commercial_credit: "Crédito na primeira cobrança",
};

const STATUS = {
    ativa: ["Ativa", "success"],
    suspensa: ["Suspensa", "warning"],
    expirada: ["Expirada", "secondary"],
    encerrada: ["Encerrada", "danger"],
};

const RESERVATION_STATUS = {
    pending: "Pendente",
    confirmed: "Confirmada",
    released: "Liberada",
    expired: "Expirada",
};

const DURATION = { d7: "7 dias", m1: "1 mês", m3: "3 meses", m6: "6 meses", a1: "1 ano" };

const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
}[character]));

const money = (value) => Number(value || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });

const date = (value, withTime = false) => {
    if (!value) return "—";
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return "—";
    return parsed.toLocaleString("pt-BR", withTime ? { dateStyle: "short", timeStyle: "short" } : { dateStyle: "short" });
};

const parseMoney = (value) => window.FokusCurrency?.parse(value) ?? null;
const formatMoneyInput = (value) => window.FokusCurrency?.format(value) ?? "";

const snapshotObject = (value) => {
    if (!value) return {};
    if (typeof value === "object") return value;
    try { return JSON.parse(value); } catch { return {}; }
};

export async function mount(root, context = {}) {
    const $ = (selector) => root.querySelector(selector) || document.querySelector(selector);
    const api = context.api || window.FokusApi;
    const permissions = context.permissions || new Set();
    const canPublish = permissions.has?.("platform.catalog.publish") ?? false;
    const form = $("#voucher-form");
    const list = $("#voucher-list");
    const pagination = $("#voucher-pagination");
    const table = $(".fs-datatable");
    const createTrigger = $("#voucher-create");
    const drawerElement = $("#voucher-drawer");
    const drawer = createRecordsDrawer({ trigger: createTrigger, drawer: drawerElement });
    const actionTrigger = $("#voucher-action-trigger");
    const actionModal = window.FokusStyles?.Modal?.getOrCreateInstance(actionTrigger);
    const pageSignal = context.signal;
    const state = {
        vouchers: [],
        products: [],
        page: 1,
        mode: null,
        currentVoucher: null,
        detailController: null,
        lastTrigger: null,
        pendingAction: null,
        actionTrigger: null,
        codeCustom: false,
    };
    const listen = (target, event, handler) => target?.addEventListener(event, handler, pageSignal ? { signal: pageSignal } : undefined);
    const permissionLabel = (voucher) => Number(voucher.redemptions_count || 0) === 0;

    const message = $("#voucher-message");
    const showMessage = (text, tone = "danger") => {
        message.textContent = text || "";
        message.dataset.tone = tone;
        message.hidden = !text;
    };

    const currentStatus = (voucher) => voucher.computed_status || voucher.status || "";
    const statusBadge = (value) => {
        const [label, tone] = STATUS[value] || [String(value || "Não informado"), "secondary"];
        return `<span class="fs-badge fs-badge-soft-${tone}">${escapeHtml(label)}</span>`;
    };

    const benefitLabel = (voucher) => {
        const type = voucher.discount_type;
        if (type === "trial_free") return `${BENEFITS[type]} · 100%`;
        if (type === "percentage") return `${BENEFITS[type]} · ${Number(voucher.discount_value || 0).toLocaleString("pt-BR", { maximumFractionDigits: 2 })}%`;
        if (type === "commercial_credit") return `${BENEFITS[type]} · ${money(voucher.discount_value)}`;
        if (type === "fixed") return `${BENEFITS[type]} · ${money(voucher.discount_value)}`;
        return "—";
    };

    const actionButton = (type, voucher, label) => `<button class="fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action" type="button" data-voucher-action="${type}" data-voucher-id="${escapeHtml(voucher.id)}" aria-label="${escapeHtml(label)}" title="${escapeHtml(label)}"><img src="${ICONS_PATH}${ICONS[type]}" alt="" /></button>`;

    const actionsFor = (voucher) => {
        const status = currentStatus(voucher);
        const redemptions = Number(voucher.redemptions_count || 0);
        const actions = [actionButton("view", voucher, "Ver detalhes do voucher")];
        if (permissionLabel(voucher) && voucher.status !== "encerrada") actions.push(actionButton("edit", voucher, "Editar voucher"));
        if (status === "ativa") actions.push(actionButton("pause", voucher, "Pausar voucher"));
        if (status === "suspensa") actions.push(actionButton("reactivate", voucher, "Reativar voucher"));
        if (canPublish && voucher.status !== "encerrada") {
            actions.push(actionButton("archive", voucher, "Arquivar voucher"));
            if (redemptions === 0) actions.push(actionButton("delete", voucher, "Excluir voucher"));
        }
        return actions.join("");
    };

    const filteredVouchers = () => {
        const query = $("#voucher-search").value.trim().toLocaleLowerCase("pt-BR");
        const productId = $("#voucher-product-filter").value;
        const type = $("#voucher-type-filter").value;
        const status = $("#voucher-status-filter").value;
        return state.vouchers.filter((voucher) => {
            const searchText = `${voucher.code || ""} ${voucher.name || ""} ${voucher.origin || ""} ${voucher.product_name || ""} ${voucher.plan_name || ""}`.toLocaleLowerCase("pt-BR");
            return (!query || searchText.includes(query))
                && (!productId || String(voucher.product_id || "") === productId)
                && (!type || voucher.discount_type === type)
                && (!status || currentStatus(voucher) === status);
        });
    };

    const renderPagination = (currentPage, totalPages) => {
        pagination.innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-voucher-page="${currentPage - 1}" aria-label="Página anterior" ${currentPage <= 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><button class="fs-page-link" type="button" data-voucher-page="${currentPage}" aria-label="Página ${currentPage}" aria-current="page">${currentPage}</button></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-voucher-page="${currentPage + 1}" aria-label="Próxima página" ${currentPage >= totalPages ? "disabled" : ""}>›</button></li></ul>`;
    };

    const render = () => {
        const vouchers = filteredVouchers();
        const totalPages = Math.max(1, Math.ceil(vouchers.length / PAGE_SIZE));
        state.page = Math.min(state.page, totalPages);
        const offset = (state.page - 1) * PAGE_SIZE;
        const rows = vouchers.slice(offset, offset + PAGE_SIZE);
        list.innerHTML = rows.length ? rows.map((voucher) => {
            const product = voucher.product_name || "Produto não informado";
            const plan = voucher.plan_name || "Todos os planos";
            const usage = voucher.redemption_limit
                ? `${Number(voucher.redemptions_count || 0)} / ${Number(voucher.redemption_limit)}`
                : `${Number(voucher.redemptions_count || 0)} / Sem limite`;
            return `<tr>
                <td class="fs-width-500" data-label="Voucher"><strong class="fs-u-d-block">${escapeHtml(voucher.name || "Voucher")}</strong><small class="fs-u-d-block fs-u-fs-sm fs-u-color-secondary">${escapeHtml(voucher.code || "—")}</small></td>
                <td class="fs-width-500" data-label="Produto e plano"><strong>${escapeHtml(product)}</strong><small class="fs-u-d-block fs-u-fs-sm fs-u-color-secondary">${escapeHtml(plan)}</small></td>
                <td class="fs-width-500" data-label="Benefício">${escapeHtml(benefitLabel(voucher))}${voucher.benefit_duration ? `<small class="fs-u-d-block fs-u-fs-sm fs-u-color-secondary">${escapeHtml(DURATION[voucher.benefit_duration] || voucher.benefit_duration)}</small>` : ""}</td>
                <td class="fs-width-300" data-label="Utilização">${escapeHtml(usage)}</td>
                <td class="fs-width-400" data-label="Validade">${escapeHtml(date(voucher.starts_at))}<small class="fs-u-d-block fs-u-fs-sm fs-u-color-secondary">até ${escapeHtml(date(voucher.ends_at))}</small></td>
                <td class="fs-width-300" data-label="Status">${statusBadge(currentStatus(voucher))}</td>
                <td class="fs-width-500" data-label="Ações"><div class="fs-u-d-flex fs-u-flex-wrap fs-u-gap-2">${actionsFor(voucher)}</div></td>
            </tr>`;
        }).join("") : '<tr><td colspan="7">Nenhum voucher encontrado com os filtros informados.</td></tr>';
        $("#voucher-table-summary").textContent = `${vouchers.length} de ${state.vouchers.length} vouchers carregados`;
        $("#voucher-table-footer-summary").textContent = `Mostrando página ${state.page} de ${totalPages} com ${rows.length} registros. A consulta carrega até 100 vouchers recentes.`;
        renderPagination(state.page, totalPages);
        table.setAttribute("aria-busy", "false");
        $("[data-fs-datatable-loading]").hidden = true;
        $("[data-fs-datatable-empty]").hidden = rows.length > 0;
        $("[data-fs-datatable-error]").hidden = true;
        window.refreshFokusDataTables?.(root);
    };

    const displayStatus = (voucher) => {
        const [label] = STATUS[currentStatus(voucher)] || [currentStatus(voucher) || "Não informado"];
        return label;
    };

    const setDl = (selector, pairs) => {
        $(selector).innerHTML = pairs.map(([label, value]) => `<div><dt class="fs-form-label">${escapeHtml(label)}</dt><dd>${value}</dd></div>`).join("");
    };

    const textValue = (value) => escapeHtml(value || "—");

    const reservationBadge = (reservation) => {
        const status = reservation.status || "";
        const tone = { pending: "warning", confirmed: "success", released: "secondary", expired: "secondary" }[status] || "secondary";
        return `<span class="fs-badge fs-badge-soft-${tone}">${escapeHtml(RESERVATION_STATUS[status] || status || "Não informado")}</span>`;
    };

    const redemptionCard = (redemption) => {
        const snapshot = snapshotObject(redemption.snapshot);
        const company = snapshot.company_id || redemption.company_id;
        const plan = snapshot.plan_name || snapshot.plan_code || "—";
        const benefitStart = redemption.benefit_starts_at || snapshot.benefit_starts_at;
        const benefitEnd = redemption.benefit_ends_at || snapshot.benefit_ends_at;
        return `<article class="fs-card fs-card-sm"><div class="fs-card-body fs-u-d-flex fs-u-flex-column fs-u-gap-2"><dl class="fs-detail-list">
            <div><dt class="fs-form-label">Empresa</dt><dd>${textValue(company)}</dd></div>
            <div><dt class="fs-form-label">Plano contratado</dt><dd>${textValue(plan)}</dd></div>
            <div><dt class="fs-form-label">Assinatura</dt><dd>${textValue(redemption.subscription_id)}</dd></div>
            <div><dt class="fs-form-label">Desconto confirmado</dt><dd>${escapeHtml(money(redemption.discount_amount ?? snapshot.discount_amount))}</dd></div>
            <div><dt class="fs-form-label">Período do benefício</dt><dd>${escapeHtml(date(benefitStart))} até ${escapeHtml(date(benefitEnd))}</dd></div>
            <div><dt class="fs-form-label">Confirmado em</dt><dd>${escapeHtml(date(redemption.created_at || benefitStart, true))}</dd></div>
        </dl></div></article>`;
    };

    const reservationCard = (reservation) => {
        const snapshot = snapshotObject(reservation.snapshot);
        return `<article class="fs-card fs-card-sm"><div class="fs-card-body fs-u-d-flex fs-u-flex-column fs-u-gap-2"><dl class="fs-detail-list">
            <div><dt class="fs-form-label">Estado</dt><dd>${reservationBadge(reservation)}</dd></div>
            <div><dt class="fs-form-label">Empresa</dt><dd>${textValue(reservation.company_id || snapshot.company_id)}</dd></div>
            <div><dt class="fs-form-label">Assinatura</dt><dd>${textValue(reservation.subscription_id || snapshot.subscription_id)}</dd></div>
            <div><dt class="fs-form-label">Criada em</dt><dd>${escapeHtml(date(reservation.reserved_at || reservation.created_at, true))}</dd></div>
            <div><dt class="fs-form-label">Expira em</dt><dd>${escapeHtml(date(reservation.expires_at, true))}</dd></div>
            <div><dt class="fs-form-label">Atualizada em</dt><dd>${escapeHtml(date(reservation.confirmed_at || reservation.released_at || reservation.updated_at, true))}</dd></div>
        </dl></div></article>`;
    };

    const renderDetails = (response) => {
        const voucher = response.voucher || response;
        const redemptions = response.redemptions || [];
        const reservations = response.reservations || [];
        state.currentVoucher = voucher;
        $("#voucher-drawer-title").textContent = "Detalhes do voucher";
        $("#voucher-detail-loading").hidden = true;
        $("#voucher-detail-error").hidden = true;
        $("#voucher-detail-sections").hidden = false;
        $("#voucher-view-footer").hidden = false;
        const productLabel = voucher.product_name || state.products.find((product) => product.id === voucher.product_id)?.name || "—";
        const planLabel = voucher.plan_name || "Todos os planos";
        const activeReservations = reservations.filter((reservation) => reservation.status === "pending").length;
        const usageLimit = voucher.redemption_limit ? `${redemptions.length} de ${voucher.redemption_limit}` : `${redemptions.length} de ilimitados`;
        setDl("#voucher-detail-commercial", [
            ["Código", `<span class="fs-u-text-uppercase">${textValue(voucher.code)}</span>`],
            ["Campanha", textValue(voucher.name)],
            ["Produto", textValue(productLabel)],
            ["Plano aplicável", textValue(planLabel)],
            ["Tipo de benefício", textValue(BENEFITS[voucher.discount_type] || voucher.discount_type)],
            ["Valor do benefício", textValue(benefitLabel(voucher))],
            ["Valor-base registrado", escapeHtml(voucher.base_amount === null ? "Todos os planos" : money(voucher.base_amount))],
            ["Duração", textValue(DURATION[voucher.benefit_duration] || voucher.benefit_duration)],
            ["Validade", `${escapeHtml(date(voucher.starts_at))} até ${escapeHtml(date(voucher.ends_at))}`],
            ["Status", statusBadge(voucher.computed_status || currentStatus(voucher))],
            ["Origem", textValue(voucher.origin)],
            ["Observações internas", textValue(voucher.notes)],
        ]);
        setDl("#voucher-detail-usage", [
            ["Resgates confirmados", escapeHtml(usageLimit)],
            ["Limite por empresa", textValue(voucher.redemption_limit_per_company || "Sem limite")],
            ["Reservas de checkout", escapeHtml(String(activeReservations))],
            ["Criado em", escapeHtml(date(voucher.created_at, true))],
            ["Atualizado em", escapeHtml(date(voucher.updated_at, true))],
        ]);
        $("#voucher-detail-redemptions").innerHTML = redemptions.length ? redemptions.map(redemptionCard).join("") : '<p class="fs-u-m-0 fs-u-color-secondary">Nenhum resgate confirmado.</p>';
        $("#voucher-detail-reservations").innerHTML = reservations.length ? reservations.map(reservationCard).join("") : '<p class="fs-u-m-0 fs-u-color-secondary">Nenhuma reserva de checkout registrada.</p>';
        const editable = canPublish || permissions.has?.("platform.vouchers.manage");
        $("#voucher-view-edit").hidden = !editable || Number(redemptions.length) > 0 || voucher.status === "encerrada";
        $("#voucher-view-edit").disabled = !editable || Number(redemptions.length) > 0 || voucher.status === "encerrada";
        $("#voucher-drawer-content")?.setAttribute("aria-busy", "false");
    };

    const setStatus = async (isLoading, errorText = "") => {
        const node = $("#voucher-form-error");
        node.textContent = errorText;
        node.hidden = !errorText;
        if (window.FokusForm) window.FokusForm.setLoading(form, isLoading);
        $("#voucher-form-submit").disabled = isLoading;
    };

    const resetForm = () => {
        form.reset();
        form.dataset.mode = "create";
        delete form.dataset.recordId;
        state.currentVoucher = null;
        state.mode = "create";
        state.codeCustom = false;
        state.codeSuffix = "";
        drawer.setState({ mode: "create" });
        $("#voucher-drawer-kicker").textContent = "COMERCIAL";
        $("#voucher-drawer-title").textContent = "Novo voucher";
        $("#voucher-drawer-description").textContent = "Configure a campanha e o benefício comercial.";
        $("#voucher-form-submit").textContent = "Cadastrar voucher";
        $("#voucher-view-panel").hidden = true;
        form.hidden = false;
        $("#voucher-view-footer").hidden = true;
        $("#voucher-form-error").hidden = true;
        $("#voucher-product").value = "";
        updatePlans();
        $("#voucher-duration").value = "m1";
        $("#voucher-discount-type").value = "";
        $("#voucher-start-date").value = new Date().toISOString().slice(0, 10);
        $("#voucher-end-date").min = $("#voucher-start-date").value;
        $("#voucher-company-limit").value = "1";
        syncBenefitFields();
    };

    const updatePlans = () => {
        const product = state.products.find((item) => String(item.id) === $("#voucher-product").value);
        const select = $("#voucher-plan");
        select.innerHTML = '<option value="">Selecione um plano</option>';
        $("#voucher-base-value").value = "";
        if (!product) {
            select.disabled = true;
            select.innerHTML = '<option value="">Selecione um produto</option>';
            syncBenefitFields();
            return;
        }
        const allPlans = document.createElement("option");
        allPlans.value = "__all__";
        allPlans.textContent = "Todos os planos do produto";
        select.appendChild(allPlans);
        (product.plans || []).forEach((plan) => {
            const option = document.createElement("option");
            option.value = plan.id;
            option.textContent = plan.full_name || `${product.name} — ${plan.name}`;
            option.dataset.monthly = String(plan.monthly_amount ?? 0);
            option.dataset.annual = String(plan.annual_amount ?? 0);
            select.appendChild(option);
        });
        select.disabled = !(product.plans || []).length;
        syncBenefitFields();
    };

    const calculateBaseAmount = () => {
        const planSelect = $("#voucher-plan");
        const option = planSelect.options[planSelect.selectedIndex];
        const monthly = Number(option?.dataset.monthly || 0);
        const annual = Number(option?.dataset.annual || 0);
        const duration = $("#voucher-duration").value;
        const amount = {
            d7: monthly / 30 * 7,
            m1: monthly,
            m3: monthly * 3,
            m6: monthly * 6,
            a1: annual,
        }[duration];
        $("#voucher-base-value").value = amount ? formatMoneyInput(Number(amount.toFixed(2))) : "";
    };

    const updateBenefitPreview = () => {
        const type = $("#voucher-discount-type").value;
        const base = parseMoney($("#voucher-base-value").value) || 0;
        const percent = Number($("#voucher-discount-percent").value || 0);
        const amount = parseMoney($("#voucher-discount-amount").value) || 0;
        const help = $("#voucher-benefit-help");
        if (type === "trial_free") help.textContent = base ? `O voucher cobre 100% do valor-base de ${money(base)}. A duração começa na ativação.` : "A assinatura terá 100% de desconto durante a duração selecionada.";
        else if (type === "percentage" && percent > 0 && base > 0) help.textContent = `Desconto estimado de ${money(Math.min(base, base * percent / 100))}. O valor final é recalculado no servidor.`;
        else if (["fixed", "commercial_credit"].includes(type) && amount > 0) help.textContent = type === "commercial_credit" ? `Crédito aplicado somente à primeira cobrança, limitado ao valor cobrado (${money(amount)} informado).` : `Desconto informado: ${money(amount)}. O valor aplicado será limitado ao preço da contratação.`;
        else if ($( "#voucher-plan").value === "__all__") help.textContent = "Todos os planos aceita apenas desconto percentual. Para assinatura gratuita, desconto fixo ou crédito, selecione um plano específico.";
        else help.textContent = "Selecione o produto, o plano e a duração para consultar o valor-base.";
    };

    const syncBenefitFields = () => {
        const typeSelect = $("#voucher-discount-type");
        const planSelect = $("#voucher-plan");
        const allPlans = planSelect.value === "__all__";
        ["trial_free", "fixed", "commercial_credit"].forEach((type) => {
            const option = typeSelect.querySelector(`option[value="${type}"]`);
            if (option) option.disabled = allPlans;
        });
        if (allPlans && typeSelect.value && typeSelect.value !== "percentage") typeSelect.value = "";
        const type = typeSelect.value;
        const canUseBase = Boolean(planSelect.value && !allPlans);
        $("#voucher-base-field").hidden = !canUseBase;
        $("#voucher-base-value").required = false;
        $("#voucher-percent-field").hidden = !["percentage", "trial_free"].includes(type);
        $("#voucher-amount-field").hidden = !["fixed", "trial_free", "commercial_credit"].includes(type);
        $("#voucher-discount-percent").required = type === "percentage";
        $("#voucher-discount-percent").disabled = type !== "percentage";
        $("#voucher-discount-amount").required = ["fixed", "commercial_credit"].includes(type);
        $("#voucher-discount-amount").disabled = !["fixed", "commercial_credit"].includes(type);
        $("#voucher-amount-label").textContent = type === "commercial_credit" ? "Crédito na primeira cobrança" : type === "trial_free" ? "Valor gratuito de referência" : "Desconto em valor fixo";
        if (type === "trial_free") {
            $("#voucher-discount-percent").value = "100";
            $("#voucher-discount-percent").disabled = true;
            $("#voucher-discount-amount").value = $("#voucher-base-value").value;
        } else if (type !== "percentage" && type !== "fixed" && type !== "commercial_credit") {
            $("#voucher-discount-percent").value = "";
            $("#voucher-discount-amount").value = "";
        }
        if (canUseBase && $("#voucher-duration").value) calculateBaseAmount();
        updateBenefitPreview();
    };

    const loadCatalog = async () => {
        try {
            const response = await api.request("/backoffice/catalog", { signal: pageSignal });
            if (pageSignal?.aborted) return;
            state.products = response.products || [];
            const productFilter = $("#voucher-product-filter");
            const productInput = $("#voucher-product");
            const options = state.products.map((product) => `<option value="${escapeHtml(product.id)}">${escapeHtml(product.name)}</option>`).join("");
            productFilter.insertAdjacentHTML("beforeend", options);
            productInput.innerHTML = `<option value="">Selecione um produto</option>${options}`;
            updatePlans();
        } catch (error) {
            if (error.name !== "AbortError" && !pageSignal?.aborted) {
                $("#voucher-product").innerHTML = '<option value="">Catálogo indisponível</option>';
                showMessage(error.message || "Não foi possível carregar os produtos e planos.");
            }
        }
    };

    const loadVouchers = async () => {
        table.setAttribute("aria-busy", "true");
        $("[data-fs-datatable-loading]").hidden = false;
        $("[data-fs-datatable-empty]").hidden = true;
        $("[data-fs-datatable-error]").hidden = true;
        try {
            const vouchers = await api.request("/backoffice/vouchers", { signal: pageSignal });
            if (pageSignal?.aborted) return;
            state.vouchers = Array.isArray(vouchers) ? vouchers : [];
            state.page = 1;
            render();
            showMessage("");
        } catch (error) {
            if (error.name === "AbortError" || pageSignal?.aborted) return;
            list.innerHTML = '<tr><td colspan="8">Não foi possível carregar os vouchers.</td></tr>';
            $("#voucher-table-summary").textContent = "Falha ao carregar";
            $("#voucher-table-footer-summary").textContent = "Atualize a listagem para tentar novamente.";
            $("[data-fs-datatable-loading]").hidden = true;
            $("[data-fs-datatable-error-message]").textContent = error.message || "Não foi possível carregar os vouchers.";
            $("[data-fs-datatable-error]").hidden = false;
            table.setAttribute("aria-busy", "false");
            showMessage(error.message || "Não foi possível carregar os vouchers.");
        }
    };

    const resetDrawerPanels = () => {
        $("#voucher-detail-loading").hidden = true;
        $("#voucher-detail-error").hidden = true;
        $("#voucher-detail-sections").hidden = true;
        $("#voucher-view-footer").hidden = true;
        $("#voucher-view-panel").hidden = true;
        form.hidden = false;
        $("#voucher-form-error").hidden = true;
    };

    const openCreate = () => {
        state.detailController?.abort();
        state.mode = "create";
        state.currentVoucher = null;
        resetForm();
        resetDrawerPanels();
        drawer.setState({ mode: "create" });
        drawer.show();
        requestAnimationFrame(() => $("#voucher-name").focus());
    };

    const openEdit = (voucher, trigger = null) => {
        state.detailController?.abort();
        if (trigger) state.lastTrigger = trigger;
        state.mode = "edit";
        state.currentVoucher = voucher;
        state.codeCustom = true;
        resetDrawerPanels();
        form.reset();
        form.dataset.mode = "edit";
        form.dataset.recordId = String(voucher.id);
        drawer.setState({ mode: "edit", record: voucher });
        $("#voucher-drawer-kicker").textContent = "EDITAR VOUCHER";
        $("#voucher-drawer-title").textContent = "Editar voucher";
        $("#voucher-drawer-description").textContent = "Altere as regras comerciais disponíveis para este voucher.";
        $("#voucher-form-submit").textContent = "Salvar alterações";
        $("#voucher-name").value = voucher.name || "";
        $("#voucher-code").value = voucher.code || "";
        $("#voucher-product").value = String(voucher.product_id || "");
        updatePlans();
        $("#voucher-plan").value = voucher.plan_id ? String(voucher.plan_id) : "__all__";
        $("#voucher-duration").value = voucher.benefit_duration || "m1";
        $("#voucher-discount-type").value = voucher.discount_type || "percentage";
        $("#voucher-start-date").value = voucher.starts_at ? String(voucher.starts_at).slice(0, 10) : "";
        $("#voucher-end-date").value = voucher.ends_at ? String(voucher.ends_at).slice(0, 10) : "";
        $("#voucher-end-date").min = $("#voucher-start-date").value;
        $("#voucher-total").value = voucher.redemption_limit || "";
        $("#voucher-company-limit").value = voucher.redemption_limit_per_company || "";
        $("#voucher-origin").value = voucher.origin || "";
        $("#voucher-notes").value = voucher.notes || "";
        syncBenefitFields();
        if (["fixed", "commercial_credit"].includes(voucher.discount_type)) $("#voucher-discount-amount").value = formatMoneyInput(voucher.discount_value);
        if (voucher.discount_type === "percentage") $("#voucher-discount-percent").value = String(voucher.discount_value || "");
        drawer.setState({ mode: "edit", record: voucher });
        drawer.show();
        requestAnimationFrame(() => $("#voucher-name").focus());
    };

    const openView = async (voucher, trigger = null) => {
        state.detailController?.abort();
        state.detailController = new AbortController();
        state.mode = "view";
        state.currentVoucher = voucher;
        state.lastTrigger = trigger || state.lastTrigger;
        form.hidden = true;
        $("#voucher-view-panel").hidden = false;
        $("#voucher-detail-loading").hidden = false;
        $("#voucher-detail-error").hidden = true;
        $("#voucher-detail-sections").hidden = true;
        $("#voucher-view-footer").hidden = true;
        $("#voucher-drawer-kicker").textContent = "CONSULTA COMERCIAL";
        $("#voucher-drawer-title").textContent = "Carregando voucher...";
        $("#voucher-drawer-description").textContent = "Consultando regras comerciais, resgates e reservas.";
        drawer.setState({ mode: "view", record: voucher });
        drawer.show();
        const { signal } = state.detailController;
        try {
            const response = await api.request(`/backoffice/vouchers/${encodeURIComponent(voucher.id)}`, { signal });
            if (signal.aborted || pageSignal?.aborted) return;
            renderDetails(response);
            $("#voucher-drawer-description").textContent = "Consulte as regras, o uso e o histórico operacional do voucher.";
        } catch (error) {
            if (error.name === "AbortError" || signal.aborted) return;
            $("#voucher-detail-loading").hidden = true;
            $("#voucher-detail-error").textContent = error.message || "Não foi possível carregar os detalhes do voucher.";
            $("#voucher-detail-error").hidden = false;
            $("#voucher-drawer-title").textContent = "Detalhes do voucher";
            $("#voucher-view-footer").hidden = false;
            showMessage(error.message || "Não foi possível carregar os detalhes do voucher.");
        }
    };

    const closeDrawer = () => {
        state.detailController?.abort();
        drawer.close();
        state.mode = null;
        state.currentVoucher = null;
    };

    const openActionDialog = (action, voucher, trigger) => {
        state.pendingAction = { action, voucher };
        state.actionTrigger = trigger;
        const deleting = action === "delete";
        $("#voucher-action-title").textContent = deleting ? "Excluir voucher?" : "Arquivar voucher?";
        $("#voucher-action-description").textContent = deleting
            ? "A exclusão remove o cadastro. O servidor impede essa ação quando há resgates ou reservas pendentes."
            : "O voucher será encerrado e não poderá receber novos resgates. O histórico será preservado.";
        $("#voucher-action-submit").textContent = deleting ? "Confirmar exclusão" : "Confirmar arquivamento";
        $("#voucher-action-error").hidden = true;
        $("#voucher-action-reason").value = "";
        actionModal?.show();
        requestAnimationFrame(() => $("#voucher-action-reason").focus());
    };

    const resetActionDialog = () => {
        state.pendingAction = null;
        $("#voucher-action-form").reset();
        $("#voucher-action-error").hidden = true;
        state.actionTrigger?.focus();
        state.actionTrigger = null;
    };

    const submitAction = async (event) => {
        event.preventDefault();
        if (!state.pendingAction) return;
        const actionForm = $("#voucher-action-form");
        if (!window.FokusForm?.validate(actionForm) && window.FokusForm) return;
        if (!actionForm.reportValidity()) return;
        const { action, voucher } = state.pendingAction;
        const reason = new FormData(actionForm).get("reason");
        window.FokusForm?.setLoading(actionForm, true);
        $("#voucher-action-error").hidden = true;
        $("#voucher-action-submit").disabled = true;
        try {
            const response = action === "delete"
                ? await api.request(`/backoffice/vouchers/${encodeURIComponent(voucher.id)}`, { method: "DELETE", body: { reason }, signal: pageSignal })
                : await api.request(`/backoffice/vouchers/${encodeURIComponent(voucher.id)}/archive`, { method: "POST", body: { reason }, signal: pageSignal });
            actionModal?.hide();
            await loadVouchers();
            showMessage(response?.message || (action === "delete" ? "Voucher excluído." : "Voucher arquivado."), "success");
        } catch (error) {
            $("#voucher-action-error").textContent = error.message || "Não foi possível concluir a ação.";
            $("#voucher-action-error").hidden = false;
        } finally {
            window.FokusForm?.setLoading(actionForm, false);
            $("#voucher-action-submit").disabled = false;
        }
    };

    const submitVoucher = async (event) => {
        event.preventDefault();
        const drawerState = drawer.getState();
        if (drawerState.mode === "view") return;
        const editId = drawerState.mode === "edit" ? String(state.currentVoucher?.id || drawerState.record?.id || form.dataset.recordId || "") : "";
        if (drawerState.mode === "edit" && !editId) {
            await setStatus(false, "Não foi possível identificar o voucher que será atualizado.");
            return;
        }
        if (window.FokusForm && !window.FokusForm.validate(form)) return;
        if (!form.reportValidity()) return;
        const productId = $("#voucher-product").value;
        const planId = $("#voucher-plan").value;
        const type = $("#voucher-discount-type").value;
        const percentage = Number($("#voucher-discount-percent").value || 0);
        const amount = parseMoney($("#voucher-discount-amount").value);
        const payload = {
            name: $("#voucher-name").value.trim(),
            code: $("#voucher-code").value.trim().toUpperCase(),
            product_id: productId,
            plan_id: planId === "__all__" ? null : planId,
            base_amount: planId === "__all__" ? null : parseMoney($("#voucher-base-value").value),
            discount_type: type,
            discount_value: type === "trial_free" ? 100 : type === "percentage" ? percentage : amount,
            benefit_duration: $("#voucher-duration").value,
            starts_at: $("#voucher-start-date").value,
            ends_at: $("#voucher-end-date").value,
            redemption_limit: Number($("#voucher-total").value),
            redemption_limit_per_company: Number($("#voucher-company-limit").value),
            origin: $("#voucher-origin").value.trim() || null,
            notes: $("#voucher-notes").value.trim() || null,
        };
        if (type !== "trial_free" && type !== "percentage" && (amount === null || amount <= 0)) {
            await setStatus(false, "Informe um valor de benefício maior que zero.");
            $("#voucher-discount-amount").focus();
            return;
        }
        await setStatus(true);
        try {
            const editing = Boolean(editId);
            await api.request(editing ? `/backoffice/vouchers/${encodeURIComponent(editId)}` : "/backoffice/vouchers", {
                method: editing ? "PATCH" : "POST",
                body: payload,
                signal: pageSignal,
            });
            closeDrawer();
            await loadVouchers();
            showMessage(editing ? "Voucher atualizado." : "Voucher cadastrado.", "success");
        } catch (error) {
            if (error.name === "AbortError") return;
            window.FokusForm?.mapServerErrors(form, error.errors);
            await setStatus(false, error.message || "Não foi possível salvar o voucher.");
        } finally {
            if (!pageSignal?.aborted) {
                window.FokusForm?.setLoading(form, false);
                $("#voucher-form-submit").disabled = false;
            }
        }
    };

    listen(createTrigger, "click", openCreate);
    listen($("#voucher-drawer-close"), "click", closeDrawer);
    listen($("#voucher-form-cancel"), "click", closeDrawer);
    listen($("#voucher-form"), "submit", submitVoucher);
    listen($("#voucher-name"), "input", () => {
        if (state.codeCustom) return;
        const name = $("#voucher-name").value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase();
        const prefix = name.split(/[^A-Z0-9]+/).filter(Boolean).map((part) => part.slice(0, 3)).join("").slice(0, 6);
        const base = (prefix + name.replace(/[^A-Z0-9]/g, "")).slice(0, 6).padEnd(6, "X");
        if (!name) { $("#voucher-code").value = ""; return; }
        if (!state.codeSuffix) {
            const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
            const bytes = new Uint8Array(2);
            if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
            else { bytes[0] = Math.floor(Math.random() * 256); bytes[1] = Math.floor(Math.random() * 256); }
            state.codeSuffix = [...bytes].map((byte) => alphabet[byte % alphabet.length]).join("");
        }
        $("#voucher-code").value = `${base}${state.codeSuffix}`;
    });
    listen($("#voucher-code"), "input", () => {
        state.codeCustom = true;
        $("#voucher-code").value = $("#voucher-code").value.toUpperCase().replace(/[^A-Z0-9]/g, "");
    });
    listen($("#voucher-product"), "change", () => {
        updatePlans();
        syncBenefitFields();
    });
    listen($("#voucher-plan"), "change", () => {
        syncBenefitFields();
    });
    listen($("#voucher-duration"), "change", syncBenefitFields);
    listen($("#voucher-discount-type"), "change", syncBenefitFields);
    listen($("#voucher-discount-percent"), "input", updateBenefitPreview);
    listen($("#voucher-discount-amount"), "input", updateBenefitPreview);
    listen($("#voucher-start-date"), "change", () => {
        $("#voucher-end-date").min = $("#voucher-start-date").value;
        if ($( "#voucher-end-date").value && $( "#voucher-end-date").value < $( "#voucher-start-date").value) $("#voucher-end-date").value = "";
    });
    listen($("#voucher-filter-form"), "submit", (event) => {
        event.preventDefault();
        state.page = 1;
        render();
    });
    listen($("#voucher-search"), "input", () => { state.page = 1; render(); });
    ["#voucher-product-filter", "#voucher-type-filter", "#voucher-status-filter"].forEach((selector) => listen($(selector), "change", () => { state.page = 1; render(); }));
    listen(pagination, "click", (event) => {
        const button = event.target.closest("[data-voucher-page]");
        if (!button || button.disabled) return;
        state.page = Number(button.dataset.voucherPage);
        render();
    });
    listen(list, "click", (event) => {
        const button = event.target.closest("[data-voucher-action]");
        if (!button) return;
        const voucher = state.vouchers.find((item) => String(item.id) === button.dataset.voucherId);
        if (!voucher) return;
        const action = button.dataset.voucherAction;
        if (action === "view") openView(voucher, button);
        else if (action === "edit") openEdit(voucher, button);
        else if (action === "archive" || action === "delete") openActionDialog(action, voucher, button);
        else if (action === "pause" || action === "reactivate") updateVoucherStatus(voucher, action, button);
    });
    listen($("#voucher-view-edit"), "click", () => {
        if (!state.currentVoucher) return;
        openEdit(state.currentVoucher, $("#voucher-view-edit"));
    });
    listen($("#voucher-action-form"), "submit", submitAction);
    listen(actionTrigger, "fs:hidden", resetActionDialog);
    listen(createTrigger, "fs:hidden", () => {
        if (state.lastTrigger?.isConnected) state.lastTrigger.focus();
        state.lastTrigger = null;
    });

    async function updateVoucherStatus(voucher, action, trigger) {
        const status = action === "pause" ? "suspensa" : "ativa";
        try {
            await api.request(`/backoffice/vouchers/${encodeURIComponent(voucher.id)}`, { method: "PATCH", body: { status }, signal: pageSignal });
            await loadVouchers();
            showMessage(action === "pause" ? "Voucher pausado." : "Voucher reativado.", "success");
            trigger.focus();
        } catch (error) {
            if (error.name !== "AbortError") showMessage(error.message || "Não foi possível atualizar o voucher.");
        }
    }

    listen($("#voucher-action-form"), "reset", () => { $("#voucher-action-error").hidden = true; });
    listen($("#voucher-drawer"), "fs:hidden", () => {
        state.detailController?.abort();
        state.mode = null;
        state.currentVoucher = null;
        resetDrawerPanels();
    });

    window.FokusCurrency?.bind(root);
    await Promise.all([loadCatalog(), loadVouchers()]);
    return () => {
        state.detailController?.abort();
        drawer.dispose();
        actionModal?.dispose?.();
        window.disposeBackofficeRecordsPage?.(root);
    };
}
