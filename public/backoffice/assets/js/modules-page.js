import { createRecordsDrawer } from "./records-drawer.js";

const approvedCatalog = {
    families: {
        law: [{ code: "processos", label: "Processos" }, { code: "contatos", label: "Contatos" }, { code: "expedicoes", label: "Expedições" }, { code: "tarefas", label: "Tarefas" }, { code: "audiencias", label: "Audiências" }],
        lead: [{ code: "pessoas", label: "Pessoas" }, { code: "imoveis", label: "Imóveis" }, { code: "empreendimentos", label: "Empreendimentos" }, { code: "leads", label: "Leads" }, { code: "funil", label: "Funil" }, { code: "website", label: "Website" }, { code: "relatorios", label: "Relatórios" }, { code: "notificacoes", label: "Notificações" }],
    },
    segments: {
        law: [{ code: "advocacia", label: "Advocacia" }, { code: "setor_publico", label: "Setor público" }],
        lead: [{ code: "one", label: "One" }, { code: "team", label: "Team" }],
    },
    contexts: {
        law: { advocacia: [{ code: "escritorio", label: "Escritório" }], setor_publico: [{ code: "judiciario", label: "Judiciário" }, { code: "orgao_publico", label: "Órgão público" }] },
        lead: { one: [{ code: "corretor_independente", label: "Corretor independente" }], team: [{ code: "imobiliaria_equipe", label: "Imobiliária/equipe" }] },
    },
};

/** Módulos: catálogo, publicação e personalizações sobre o contrato compartilhado. */
export async function mount(root, context = {}) {
    const $ = (selector) => root.querySelector(selector) || document.querySelector(selector);
    const api = context.api || window.FokusApi;
    const form = $("#module-form");
    const drawerElement = $("#module-drawer");
    const drawer = createRecordsDrawer({ trigger: $("#module-new"), drawer: drawerElement });
    const personalizationsPanel = $("#module-personalizations-drawer");
    const personalizationsList = $("#personalizations-list");
    const personalizationsTrigger = $("#module-personalizations-open");
    const personalizationsDrawer = window.FokusStyles?.Offcanvas?.getOrCreateInstance(personalizationsTrigger);
    const destructiveModal = window.FokusStyles?.Modal?.getOrCreateInstance($("#module-destructive-trigger"));
    const state = { catalog: { products: [], options: {} }, modules: [], editing: null, pending: null, personalizations: [], page: 1 };
    const pageSize = 15;
    const field = (name) => form.elements.namedItem(name);
    const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#039;" }[character]));
    const money = (value) => Number(value || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
    const labels = { ativo: "Ativo", inativo: "Inativo", rascunho: "Rascunho", pausado: "Pausado", arquivado: "Arquivado", publicado: "Publicado" };
    const showMessage = (text, tone = "danger") => { const node = $("#modules-message"); node.textContent = text || ""; node.dataset.tone = tone; node.hidden = !text; };
    const selectedValues = (selector) => [...$(selector).selectedOptions].map((option) => option.value).filter(Boolean);
    const selectValues = (selector, values = []) => { const selected = new Set(values); [...$(selector).options].forEach((option) => { option.selected = selected.has(option.value); }); };
    const productById = (id) => state.catalog.products.find((product) => String(product.id) === String(id));
    const catalogProductCode = (code) => ({ "fokus-law": "law", "fokus-lead": "lead" }[code] || code);
    const catalogOptionsFor = (collection, productCode, fallbackKey) => collection?.[productCode]?.length
        ? collection[productCode]
        : collection?.[catalogProductCode(productCode)]?.length
            ? collection[catalogProductCode(productCode)]
            : approvedCatalog[fallbackKey]?.[catalogProductCode(productCode)] || [];
    const badge = (value) => {
        const normalized = String(value || "").toLowerCase();
        const tone = normalized === "ativo" || normalized === "publicado" ? "success" : normalized === "pausado" ? "warning" : normalized === "arquivado" ? "danger" : "secondary";
        return `<span class="fs-badge fs-badge-width-80 fs-badge-soft-${tone}">${escapeHtml(value || "-")}</span>`;
    };
    const allModules = () => state.catalog.products.flatMap((product) => (product.modules || []).map((module) => ({ ...module, product_name: product.name, product_code: product.code, linked_plans: (product.plans || []).filter((plan) => (plan.modules || []).some((item) => item.id === module.id)) })));
    const statusLabel = (module) => labels[module.status] || module.status || "-";
    const publicationLabel = (module) => labels[module.publication_state] || module.publication_state || "-";
    const currentOptionState = () => ({ module_code: field("module_code").value, segments: selectedValues("#module-segments"), context_code: field("context_code").value, capability_codes: selectedValues("#module-capabilities"), dependency_ids: selectedValues("#module-dependencies"), incompatibility_ids: selectedValues("#module-incompatibilities") });
    const fillOptions = (module = {}) => {
        const product = productById(field("product_id").value);
        const productCode = product?.code || "";
        const catalogCode = catalogProductCode(productCode);
        const families = catalogOptionsFor(state.catalog.options.families, productCode, "families");
        field("module_code").innerHTML = '<option value="">Selecione uma família</option>' + families.map((item) => `<option value="${escapeHtml(item.code)}">${escapeHtml(item.label)}</option>`).join("") + '<option value="outro">Outro</option>';
        field("module_code").value = module.module_code || "";
        $("#module-code-custom-wrap").hidden = field("module_code").value !== "outro";
        $("#module-segments").innerHTML = catalogOptionsFor(state.catalog.options.segments, productCode, "segments").map((item) => `<option value="${escapeHtml(item.code)}">${escapeHtml(item.label)}</option>`).join("");
        selectValues("#module-segments", module.segments);
        const segments = selectedValues("#module-segments");
        const contexts = segments.flatMap((segment) => state.catalog.options.contexts?.[productCode]?.[segment] || state.catalog.options.contexts?.[catalogCode]?.[segment] || approvedCatalog.contexts?.[catalogCode]?.[segment] || []);
        field("context_code").innerHTML = '<option value="">Selecione um contexto</option>' + contexts.filter((item, index, items) => items.findIndex((candidate) => candidate.code === item.code) === index).map((item) => `<option value="${escapeHtml(item.code)}">${escapeHtml(item.label)}</option>`).join("");
        field("context_code").value = module.context_code || "";
        const capabilities = state.catalog.options.capabilities?.[productCode]?.[field("module_code").value] || state.catalog.options.capabilities?.[catalogCode]?.[field("module_code").value] || [];
        const savedCapabilities = module.capability_items || (module.capability_codes || []).map((code, index) => ({ code, name: module.capabilities?.[index] || code }));
        const missingCapabilities = savedCapabilities.filter((item) => !capabilities.some((candidate) => candidate.code === item.code));
        $("#module-capabilities").innerHTML = [...capabilities, ...missingCapabilities].map((item) => `<option value="${escapeHtml(item.code)}">${escapeHtml(item.label || item.name || item.code)}${item.optional ? " (opcional)" : ""}</option>`).join("") + '<option value="outro">Outro</option>';
        selectValues("#module-capabilities", module.capability_codes);
        $("#module-capabilities-custom-wrap").hidden = !selectedValues("#module-capabilities").includes("outro");
        const siblings = state.modules.filter((item) => item.product_id === field("product_id").value && item.id !== module.id);
        const siblingOptions = siblings.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)} — ${escapeHtml(item.code)}</option>`).join("");
        $("#module-dependencies").innerHTML = siblingOptions;
        $("#module-incompatibilities").innerHTML = siblingOptions;
        selectValues("#module-dependencies", module.dependency_ids);
        selectValues("#module-incompatibilities", module.incompatibility_ids);
    };
    const renderPagination = (pages) => {
        $("#module-pagination").innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-module-page="${state.page - 1}" aria-label="Página anterior" ${state.page === 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><button class="fs-page-link" type="button" data-module-page="${state.page}" aria-label="Página ${state.page}" aria-current="page">${state.page}</button></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-module-page="${state.page + 1}" aria-label="Próxima página" ${state.page === pages ? "disabled" : ""}>›</button></li></ul>`;
    };
    const render = () => {
        const query = $("#module-query").value.trim().toLowerCase();
        const product = $("#module-product-filter").value;
        const status = $("#module-status-filter").value;
        const rows = state.modules.filter((module) => (!query || [module.name, module.code, module.module_code, module.context_code, module.product_name, ...(module.segments || [])].some((value) => String(value || "").toLowerCase().includes(query))) && (!product || module.product_id === product) && (!status || module.status === status));
        const pages = Math.max(1, Math.ceil(rows.length / pageSize));
        state.page = Math.min(Math.max(state.page, 1), pages);
        const visible = rows.slice((state.page - 1) * pageSize, state.page * pageSize);
        const action = (type, label, file, module, danger = false) => `<button class="fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action${danger ? " fs-btn-danger" : ""}" type="button" data-module-action="${type}" data-module-id="${escapeHtml(module.id)}" aria-label="${label}" title="${label}"><img src="/backoffice/assets/icons/${file}" alt="" /></button>`;
        const actions = (module) => {
            const canPublish = context.permissions?.has("platform.catalog.publish") || window.__backofficePermissions?.has("platform.catalog.publish");
            const items = [action("view", "Ver detalhes do módulo", "Folder-File--Streamline-Ultimate.png", module), action("edit", "Editar módulo", "Common-File-Edit--Streamline-Ultimate.png", module)];
            if (module.status === "rascunho" && canPublish) items.push(action("activate", "Ativar módulo", "Common-File-Check--Streamline-Ultimate.png", module), action("archive", "Arquivar módulo", "File-Code-Remove--Streamline-Ultimate.png", module));
            else if (module.status === "ativo" && module.publication_state === "publicado" && canPublish) items.push(action("pause", "Pausar publicação", "File-Code-Subtract--Streamline-Ultimate.png", module));
            else if (module.status === "ativo" && canPublish) items.push(action("publish", "Publicar módulo", "File-Code-2--Streamline-Ultimate.png", module));
            else if (module.status !== "arquivado" && canPublish) items.push(action("activate", "Reativar módulo", "Common-File-Check--Streamline-Ultimate.png", module));
            if (module.status === "arquivado" && canPublish) items.push(action("delete", "Excluir módulo", "Common-File-Remove--Streamline-Ultimate.png", module, true));
            return items.join("");
        };
        $("#module-list").innerHTML = visible.length ? visible.map((module) => `<tr><td class="fs-width-600" data-label="Produto"><strong>${escapeHtml(module.product_name)}</strong><small class="fs-u-d-block fs-u-fs-sm">${escapeHtml([module.module_code, ...(module.segments || []), module.context_code].filter(Boolean).join(" / ") || "Contexto não informado")}</small></td><td class="fs-width-600" data-label="Módulo e funcionalidade"><strong>${escapeHtml(module.name)}</strong><small class="fs-u-d-block fs-u-fs-sm module-capability-list">${escapeHtml((module.capabilities || []).join(", ") || "Sem funcionalidades descritas")}</small></td><td class="fs-width-300" data-label="Preço mensal">${money(module.monthly_price)}</td><td class="fs-width-300" data-label="Status">${badge(statusLabel(module))}</td><td class="fs-width-300" data-label="Publicação">${badge(publicationLabel(module))}</td><td class="fs-width-400" data-label="Ações"><div class="fs-u-d-flex fs-u-gap-2">${actions(module)}</div></td></tr>`).join("") : '<tr><td colspan="6">Nenhum módulo encontrado com os filtros informados.</td></tr>';
        $("#module-table-summary").textContent = `${rows.length} registros encontrados`;
        $("#module-table-footer-summary").textContent = `Mostrando página ${state.page} de ${pages} com ${visible.length} registros, de um total de ${pages} páginas.`;
        renderPagination(pages);
        window.refreshFokusDataTables?.(root);
    };
    const renderPersonalizations = () => {
        const types = state.catalog.options.personalization_types || [];
        personalizationsList.innerHTML = state.personalizations.map((item, index) => `<div class="fs-card fs-u-mb-3" data-index="${index}"><div class="fs-card-body"><label class="fs-form-label fs-u-mt-3"><span class="fs-u-ml-2">Tipo</span><select class="fs-form-select fs-width-500 p-type" data-index="${index}">${types.map((type) => `<option value="${escapeHtml(type.code)}"${type.code === item.type_code ? " selected" : ""}>${escapeHtml(type.label)}</option>`).join("")}</select></label><label class="fs-form-label fs-u-mt-3"><span class="fs-u-ml-2">Obrigatória</span><select class="fs-form-select fs-width-300 p-required" data-index="${index}"><option value="1"${item.required ? " selected" : ""}>Sim</option><option value="0"${!item.required ? " selected" : ""}>Não</option></select></label><label class="fs-form-label fs-u-mt-3"><span class="fs-u-ml-2">Ativa</span><select class="fs-form-select fs-width-300 p-active" data-index="${index}"><option value="1"${item.active !== false ? " selected" : ""}>Sim</option><option value="0"${item.active === false ? " selected" : ""}>Não</option></select></label><p class="fs-form-label fs-u-mt-3"><span class="fs-u-ml-2">Faixas</span></p>${item.tiers.map((tier, tierIndex) => `<div class="fs-form-row fs-u-mb-2"><input class="fs-form-control fs-width-300 p-value" data-index="${index}" data-tier="${tierIndex}" type="number" min="1" value="${escapeHtml(tier.value)}" placeholder="Quantidade" /><input class="fs-form-control fs-width-400 p-price" data-index="${index}" data-tier="${tierIndex}" type="number" min="0" step="0.01" value="${escapeHtml(tier.additional_monthly_amount)}" placeholder="Acréscimo mensal" /><button class="fs-btn fs-btn-outline-primary p-tier-remove" type="button" data-index="${index}" data-tier="${tierIndex}">Remover</button></div>`).join("")}<div class="fs-u-d-flex fs-u-flex-wrap fs-u-gap-2"><button class="fs-btn fs-btn-outline-primary p-tier-add" type="button" data-index="${index}">Adicionar faixa</button><button class="fs-btn fs-btn-danger p-remove" type="button" data-index="${index}">Remover personalização</button></div></div></div>`).join("");
    };
    const updatePersonalizationsSummary = () => { $("#module-personalizations-summary").innerHTML = state.personalizations.length ? state.personalizations.map((item) => `<div>${escapeHtml(item.type_code)} · ${item.required ? "Obrigatória" : "Opcional"} · ${item.tiers.length} faixa(s)</div>`).join("") : "Nenhuma personalização configurada."; };
    const resetForm = () => {
        form.reset();
        field("product_id").innerHTML = '<option value="">Selecione um produto</option>' + state.catalog.products.map((product) => `<option value="${escapeHtml(product.id)}">${escapeHtml(product.name)}</option>`).join("");
        if (state.catalog.products[0]) field("product_id").value = state.catalog.products[0].id;
        field("display_order").innerHTML = "";
        field("display_order").disabled = true;
        $("#module-edit-controls").hidden = true;
        $("#module-form-submit").textContent = "Cadastrar módulo";
        $("#module-view-panel").hidden = true;
        form.hidden = false;
        state.personalizations = [];
        fillOptions();
        updatePersonalizationsSummary();
    };
    const fillForm = (module = null) => {
        resetForm();
        if (module) {
            field("product_id").value = module.product_id;
            field("name").value = module.name || "";
            field("module_code").value = module.module_code || "";
            field("context_code").value = module.context_code || "";
            field("technical_description").value = module.technical_description || "";
            field("commercial_content").value = module.commercial_content || "";
            field("monthly_price").value = window.FokusCurrency?.format(module.monthly_price) || "";
            field("price_is_estimate").value = module.price_is_estimate ? "1" : "0";
            const productModules = state.modules.filter((item) => String(item.product_id) === String(module.product_id));
            const currentPosition = productModules.findIndex((item) => String(item.id) === String(module.id)) + 1;
            field("display_order").innerHTML = Array.from({ length: productModules.length }, (_, index) => `<option value="${index + 1}">${index + 1}</option>`).join("");
            field("display_order").value = String(Math.max(1, currentPosition));
            field("display_order").disabled = false;
            field("featured").value = module.featured ? "1" : "0";
            $("#module-edit-controls").hidden = false;
            state.personalizations = (module.personalizations || []).map((item) => ({ type_code: item.type_code, required: Boolean(item.required), active: item.active !== false, tiers: (item.tiers || []).map((tier) => ({ value: tier.value, additional_monthly_amount: tier.additional_monthly_amount, active: tier.active !== false })) }));
            fillOptions(module);
            updatePersonalizationsSummary();
        }
    };
    const detailList = (items, get = (item) => item) => items?.length ? `<ul>${items.map((item) => `<li>${escapeHtml(get(item))}</li>`).join("")}</ul>` : '<p class="fs-u-color-secondary">Nenhum item informado.</p>';
    const showDetails = (module) => {
        $("#module-view-panel").innerHTML = `<div class="fs-card"><div class="fs-card-header"><h3 class="fs-card-title">Dados do módulo</h3></div><div class="fs-card-body"><dl><div><dt class="fs-form-label">Produto</dt><dd>${escapeHtml(module.product_name)}</dd></div><div><dt class="fs-form-label">Nome</dt><dd>${escapeHtml(module.name)}</dd></div><div><dt class="fs-form-label">Código público</dt><dd>${escapeHtml(module.code)}</dd></div><div><dt class="fs-form-label">Família técnica</dt><dd>${escapeHtml(module.module_code || "-")}</dd></div><div><dt class="fs-form-label">Segmentos / contexto</dt><dd>${escapeHtml([...(module.segments || []), module.context_code].filter(Boolean).join(" / ") || "-")}</dd></div><div><dt class="fs-form-label">Status</dt><dd>${badge(statusLabel(module))}</dd></div></dl></div></div><div class="fs-card"><div class="fs-card-header"><h3 class="fs-card-title">Funcionalidades e personalizações</h3></div><div class="fs-card-body"><h4>Funcionalidades</h4>${detailList(module.capabilities)}<h4>Personalizações</h4>${detailList(module.personalizations, (item) => `${item.type_label} · ${item.unit} · ${item.required ? "obrigatória" : "opcional"} · ${item.tiers.length} faixa(s)`)}</div></div><div class="fs-card"><div class="fs-card-header"><h3 class="fs-card-title">Regras e planos</h3></div><div class="fs-card-body"><h4>Dependências</h4>${detailList(module.dependencies, (item) => item.name)}<h4>Incompatibilidades</h4>${detailList(module.incompatibilities, (item) => item.name)}<h4>Planos vinculados</h4>${detailList(module.linked_plans, (item) => item.full_name || item.name)}</div></div>`;
        form.hidden = true;
        $("#module-view-panel").hidden = false;
    };
    const setDrawerHeader = (kicker, title, description) => { $("#module-drawer-kicker").textContent = kicker; $("#module-drawer-title").textContent = title; $("#module-drawer-description").textContent = description; };
    const openCreate = () => { state.editing = null; fillForm(); drawer.setState({ mode: "create" }); setDrawerHeader("CATÁLOGO", "Novo módulo", "Cadastre um componente comercial e suas funcionalidades."); drawer.show(); field("name").focus(); };
    const openEdit = (module) => { state.editing = module.id; fillForm(module); drawer.setState({ mode: "edit", record: module }); setDrawerHeader("EDITAR MÓDULO", "Editar dados do módulo", "Altere os dados comerciais, técnicos e as regras permitidas."); $("#module-form-submit").textContent = "Salvar alterações"; drawer.show(); field("name").focus(); };
    const openView = (module) => { showDetails(module); drawer.setState({ mode: "view", record: module }); setDrawerHeader("CONSULTA", "Detalhes do módulo", "Consulte os dados, regras, publicação e vínculos do módulo."); drawer.show(); };
    const closeDrawer = () => drawer.close();
    const load = async () => {
        try {
            state.catalog = await api.request("/backoffice/catalog");
            if (context.signal?.aborted) return;
            state.catalog.options = state.catalog.options || {};
            state.modules = allModules();
            $("#module-product-filter").innerHTML = '<option value="">Todos os produtos</option>' + state.catalog.products.map((product) => `<option value="${escapeHtml(product.id)}">${escapeHtml(product.name)}</option>`).join("");
            resetForm();
            render();
            showMessage("");
        } catch (error) {
            if (!context.signal?.aborted) showMessage(error.message || "Não foi possível carregar os módulos.");
        }
    };

    $("#module-new").addEventListener("click", openCreate);
    $("#module-drawer-close").addEventListener("click", closeDrawer);
    $("#module-form-cancel").addEventListener("click", closeDrawer);
    $("#module-filter-form").addEventListener("submit", (event) => { event.preventDefault(); state.page = 1; render(); });
    $("#module-query").addEventListener("input", () => { state.page = 1; render(); });
    $("#module-product-filter").addEventListener("change", () => { state.page = 1; render(); });
    $("#module-status-filter").addEventListener("change", () => { state.page = 1; render(); });
    field("product_id").addEventListener("change", () => fillOptions());
    field("module_code").addEventListener("change", () => { const values = currentOptionState(); values.module_code = field("module_code").value; fillOptions(values); });
    $("#module-segments").addEventListener("change", () => { const values = currentOptionState(); fillOptions(values); });
    $("#module-capabilities").addEventListener("change", () => { $("#module-capabilities-custom-wrap").hidden = !selectedValues("#module-capabilities").includes("outro"); });
    $("#module-capabilities-custom").addEventListener("keydown", (event) => {
        if (event.key !== "Enter") return;
        event.preventDefault();
        const name = event.currentTarget.value.trim();
        if (!name) return;
        const code = `custom_${name.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "")}`;
        if (!$("#module-capabilities").querySelector(`option[value="${CSS.escape(code)}"]`)) $("#module-capabilities").add(new Option(name, code, true, true), $("#module-capabilities").options.length - 1);
        event.currentTarget.value = "";
    });
    personalizationsTrigger.addEventListener("click", () => { renderPersonalizations(); personalizationsDrawer?.show(); });
    $("#module-personalizations-close").addEventListener("click", () => personalizationsDrawer?.hide());
    $("#personalizations-save").addEventListener("click", () => { updatePersonalizationsSummary(); personalizationsDrawer?.hide(); });
    $("#personalization-add").addEventListener("click", () => { state.personalizations.push({ type_code: state.catalog.options.personalization_types?.[0]?.code || "", required: true, active: true, tiers: [{ value: 1, additional_monthly_amount: 0, active: true }] }); renderPersonalizations(); });
    personalizationsList.addEventListener("click", (event) => { const control = event.target.closest(".p-remove, .p-tier-add, .p-tier-remove"); if (!control) return; const index = Number(control.dataset.index); const item = state.personalizations[index]; if (!item) return; if (control.classList.contains("p-remove")) state.personalizations.splice(index, 1); if (control.classList.contains("p-tier-add")) item.tiers.push({ value: 1, additional_monthly_amount: 0, active: true }); if (control.classList.contains("p-tier-remove")) item.tiers.splice(Number(control.dataset.tier), 1); renderPersonalizations(); });
    personalizationsList.addEventListener("change", (event) => { const index = Number(event.target.dataset.index); const item = state.personalizations[index]; if (!item) return; if (event.target.classList.contains("p-type")) item.type_code = event.target.value; if (event.target.classList.contains("p-required")) item.required = event.target.value === "1"; if (event.target.classList.contains("p-active")) item.active = event.target.value === "1"; if (event.target.classList.contains("p-value")) item.tiers[Number(event.target.dataset.tier)].value = Number(event.target.value); if (event.target.classList.contains("p-price")) item.tiers[Number(event.target.dataset.tier)].additional_monthly_amount = Number(event.target.value); });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (window.FokusForm && !window.FokusForm.validate(form)) return;
        const payload = { product_id: field("product_id").value, name: field("name").value, module_code: field("module_code").value, module_code_custom_name: field("module_code_custom_name").value, segments: selectedValues("#module-segments"), context_code: field("context_code").value || null, monthly_price: window.FokusCurrency?.parse(field("monthly_price").value) ?? 0, price_is_estimate: field("price_is_estimate").value === "1", technical_description: field("technical_description").value, commercial_content: field("commercial_content").value, capability_codes: selectedValues("#module-capabilities").filter((item) => item !== "outro"), dependency_ids: selectedValues("#module-dependencies"), incompatibility_ids: selectedValues("#module-incompatibilities"), personalizations: state.personalizations };
        if (state.editing) { payload.display_order = Number(field("display_order").value || 0); payload.featured = field("featured").value === "1"; }
        try { await api.request(state.editing ? `/backoffice/catalog/modules/${state.editing}` : "/backoffice/catalog/modules", { method: state.editing ? "PATCH" : "POST", body: payload }); closeDrawer(); await load(); showMessage("Módulo salvo com sucesso.", "success"); } catch (error) { window.FokusForm?.mapServerErrors(form, error.errors); showMessage(error.message || "Não foi possível salvar o módulo."); }
    });
    $("#module-list").addEventListener("click", async (event) => {
        const control = event.target.closest("[data-module-action]");
        if (!control) return;
        const module = state.modules.find((item) => String(item.id) === control.dataset.moduleId);
        if (!module) return;
        const type = control.dataset.moduleAction;
        if (type === "view") return openView(module);
        if (type === "edit") return openEdit(module);
        if (type === "archive" || type === "delete") { state.pending = { type, module }; destructiveModal?.show(); return; }
        try { const endpoint = type === "publish" ? "publish" : type === "pause" ? "pause" : "activate"; await api.request(`/backoffice/catalog/modules/${module.id}/${endpoint}`, { method: "POST" }); await load(); showMessage("Status do módulo atualizado.", "success"); } catch (error) { showMessage(error.message || "Não foi possível atualizar o módulo."); }
    });
    $("#module-pagination").addEventListener("click", (event) => { const button = event.target.closest("[data-module-page]"); if (!button || button.disabled) return; state.page = Number(button.dataset.modulePage); render(); });
    $("#module-destructive-form").addEventListener("submit", async (event) => { event.preventDefault(); if (!state.pending) return; const { type, module } = state.pending; const reason = new FormData(event.currentTarget).get("reason"); try { await api.request(type === "delete" ? `/backoffice/catalog/modules/${module.id}` : `/backoffice/catalog/modules/${module.id}/archive`, { method: type === "delete" ? "DELETE" : "POST", body: { reason } }); destructiveModal?.hide(); await load(); showMessage("Ação concluída.", "success"); } catch (error) { showMessage(error.message || "Não foi possível concluir a ação."); } });

    await load();
    return () => {
        personalizationsDrawer?.dispose?.();
        destructiveModal?.dispose?.();
        drawer.dispose();
        window.disposeBackofficeRecordsPage?.(root);
    };
}
