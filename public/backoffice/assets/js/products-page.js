import { createRecordsDrawer } from "./records-drawer.js";

/**
 * Produtos: lista, filtros e estados create/edit/view do drawer compartilhado.
 */
export async function mount(root, context = {}) {
    const $ = (selector) => root.querySelector(selector) || document.querySelector(selector);
    const api = context.api || window.FokusApi;
    const form = $("#product-form");
    const list = $("#product-list");
    const pagination = $("#product-pagination");
    const drawerElement = $("#product-drawer");
    const createTrigger = $("#product-new");
    const drawer = createRecordsDrawer({ trigger: createTrigger, drawer: drawerElement });
    const actionModal = window.FokusStyles?.Modal?.getOrCreateInstance($("#product-action-trigger"));
    const state = { products: [], page: 1, mode: "create", productId: null, pendingAction: null };
    const pageSize = 15;
    const iconsPath = "/backoffice/assets/icons/";
    const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#039;" }[character]));
    const showMessage = (text, tone = "danger") => { const node = $("#products-message"); node.textContent = text || ""; node.dataset.tone = tone; node.hidden = !text; };
    const action = (type, product, label, icon) => `<button class="fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action" type="button" data-product-action="${type}" data-product-id="${escapeHtml(product.id)}" aria-label="${label}" title="${label}"><img src="${iconsPath}${icon}" alt="" /></button>`;
    const filteredProducts = () => {
        const query = $("#product-search").value.trim().toLowerCase();
        const status = $("#product-status-filter").value;
        return state.products.filter((product) => (!query || `${product.name} ${product.code}`.toLowerCase().includes(query)) && (!status || product.status === status));
    };
    const statusBadge = (status) => {
        const tone = { ativo: "success", pausado: "warning" }[status] || "secondary";
        const label = { ativo: "Ativo", pausado: "Pausado" }[status] || status || "Sem status";
        return `<span class="fs-badge fs-badge-width-80 fs-badge-soft-${tone}">${escapeHtml(label)}</span>`;
    };
    const productActions = (product) => {
        const lifecycle = product.status === "ativo"
            ? action("pause", product, "Pausar produto", "Common-File-Subtract--Streamline-Ultimate.png") + (product.publication_pending ? action("publish", product, "Publicar nova versão do catálogo", "File-Code-2--Streamline-Ultimate.png") : "")
            : action("activate", product, "Ativar produto", "Common-File-Check--Streamline-Ultimate.png") + action("delete", product, "Excluir produto", "Common-File-Remove--Streamline-Ultimate.png");
        const edit = ["pausado", "inativo"].includes(product.status) ? action("edit", product, "Editar produto", "Common-File-Edit--Streamline-Ultimate.png") : "";
        return `${action("view", product, "Ver detalhes do produto", "Folder-File--Streamline-Ultimate.png")}${edit}${lifecycle}`;
    };
    const renderPagination = (currentPage, totalPages) => {
        pagination.innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-product-page="${currentPage - 1}" aria-label="Página anterior" ${currentPage === 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><button class="fs-page-link" type="button" data-product-page="${currentPage}" aria-label="Página ${currentPage}" aria-current="page">${currentPage}</button></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-product-page="${currentPage + 1}" aria-label="Próxima página" ${currentPage === totalPages ? "disabled" : ""}>›</button></li></ul>`;
    };
    const render = () => {
        const products = filteredProducts();
        const totalPages = Math.max(1, Math.ceil(products.length / pageSize));
        state.page = Math.min(state.page, totalPages);
        const offset = (state.page - 1) * pageSize;
        const rows = products.slice(offset, offset + pageSize);
        list.innerHTML = rows.length ? rows.map((product) => `<tr><td class="fs-width-600" data-label="Produto"><strong>${escapeHtml(product.name)}</strong><small class="fs-u-d-block fs-u-fs-sm">${escapeHtml(product.code)}</small></td><td class="fs-width-300" data-label="Status">${statusBadge(product.status)}</td><td class="fs-width-400" data-label="Planos cadastrados"><strong>${(product.plans || []).length}</strong></td><td class="fs-width-400" data-label="Ações"><div class="fs-u-d-flex fs-u-gap-2">${productActions(product)}</div></td></tr>`).join("") : '<tr><td colspan="4">Nenhum produto encontrado.</td></tr>';
        $("#product-table-summary").textContent = `${products.length} registros encontrados`;
        $("#product-table-footer-summary").textContent = `Mostrando página ${state.page} de ${totalPages} com ${rows.length} registros, de um total de ${totalPages} páginas.`;
        renderPagination(state.page, totalPages);
        window.refreshFokusDataTables?.(root);
    };
    const setDescription = (selector, value, emptyText) => { $(selector).textContent = String(value || "").trim() || emptyText; };
    const resetDrawerState = () => {
        state.mode = "create";
        state.productId = null;
        drawer.setState({ mode: "create" });
        form.reset();
        form.elements.namedItem("code").disabled = false;
        form.elements.namedItem("display_order").disabled = true;
        $("#product-display-order-field").hidden = true;
        form.hidden = false;
        $("#product-view-panel").hidden = true;
        $("#product-drawer-kicker").textContent = "CATÁLOGO";
        $("#product-drawer-title").textContent = "Novo produto";
        $("#product-drawer-description").textContent = "Cadastre os dados de identificação e disponibilidade do produto.";
        $("#product-form-submit").textContent = "Cadastrar produto";
        ["#product-view-name", "#product-view-code", "#product-view-status"].forEach((selector) => { $(selector).textContent = "-"; });
        $("#product-view-plans").textContent = "0";
        $("#product-view-version").textContent = "—";
        $("#product-view-publication").textContent = "—";
        setDescription("#product-view-technical-description", "", "Nenhuma descrição técnica informada.");
        setDescription("#product-view-commercial-content", "", "Nenhuma descrição comercial informada.");
    };
    const openCreate = () => { resetDrawerState(); drawer.setState({ mode: "create" }); drawer.show(); $("#product-code").focus(); };
    const openEdit = (product) => {
        resetDrawerState();
        state.mode = "edit";
        state.productId = product.id;
        drawer.setState({ mode: "edit", record: product });
        const order = form.elements.namedItem("display_order");
        const currentPosition = state.products.findIndex((item) => item.id === product.id) + 1;
        order.innerHTML = Array.from({ length: state.products.length }, (_, index) => `<option value="${index + 1}">${index + 1}</option>`).join("");
        order.value = String(Math.max(1, currentPosition));
        order.disabled = false;
        $("#product-display-order-field").hidden = false;
        $("#product-drawer-kicker").textContent = "EDITAR PRODUTO";
        $("#product-drawer-title").textContent = "Editar dados do produto";
        $("#product-drawer-description").textContent = "Altere a identificação, a ordem e as descrições permitidas.";
        $("#product-form-submit").textContent = "Salvar alterações";
        ["code", "name", "technical_description", "commercial_content"].forEach((key) => { form.elements.namedItem(key).value = product[key] ?? ""; });
        form.elements.namedItem("code").disabled = true;
        drawer.show();
        $("#product-name").focus();
    };
    const openView = (product) => {
        resetDrawerState();
        state.mode = "view";
        state.productId = product.id;
        drawer.setState({ mode: "view", record: product });
        form.hidden = true;
        $("#product-view-panel").hidden = false;
        $("#product-drawer-kicker").textContent = "CONSULTA";
        $("#product-drawer-title").textContent = "Detalhes do produto";
        $("#product-drawer-description").textContent = "Consulte os dados, as descrições e os vínculos do produto.";
        $("#product-view-name").textContent = product.name || "-";
        $("#product-view-code").textContent = product.code || "-";
        $("#product-view-status").innerHTML = statusBadge(product.status);
        $("#product-view-plans").textContent = String((product.plans || []).length);
        $("#product-view-version").textContent = Number(product.published_catalog_version) > 0 ? `v${Number(product.published_catalog_version)}.0` : "—";
        $("#product-view-publication").textContent = product.publication_pending ? "Republicação pendente" : "Atualizada";
        setDescription("#product-view-technical-description", product.technical_description, "Nenhuma descrição técnica informada.");
        setDescription("#product-view-commercial-content", product.commercial_content, "Nenhuma descrição comercial informada.");
        drawer.show();
    };
    const closeDrawer = () => { drawer.close(); resetDrawerState(); };
    const load = async () => {
        try {
            const response = await api.request("/backoffice/catalog/products");
            if (context.signal?.aborted) return;
            state.products = response.products || [];
            render();
            showMessage("");
        } catch (error) {
            if (!context.signal?.aborted) showMessage(error.message || "Não foi possível carregar os produtos.");
        }
    };
    const runAction = async (product, type) => {
        const endpoint = type === "publish" ? `/backoffice/catalog/${product.id}/publish` : type === "pause" ? `/backoffice/catalog/products/${product.id}/pause` : type === "activate" ? `/backoffice/catalog/products/${product.id}/activate` : `/backoffice/catalog/products/${product.id}`;
        try {
            await api.request(endpoint, { method: type === "delete" ? "DELETE" : "POST" });
            await load();
            showMessage(type === "publish" ? "Nova versão do catálogo publicada." : type === "pause" ? "Produto pausado." : type === "activate" ? "Produto ativado. Publique a nova versão do catálogo." : "Produto excluído.", "success");
        } catch (error) {
            showMessage(error.message || "Não foi possível concluir a ação.");
        }
    };
    const submit = async (event) => {
        event.preventDefault();
        if (window.FokusForm && !window.FokusForm.validate(form)) return;
        const drawerState = drawer.getState();
        if (drawerState.mode === "view") return;
        const editId = drawerState.mode === "edit" ? String(state.productId || drawerState.record?.id || "") : "";
        if (drawerState.mode === "edit" && !editId) { showMessage("Não foi possível identificar o produto que será atualizado."); return; }
        const editing = Boolean(editId);
        const payload = Object.fromEntries(new FormData(form));
        if (editing) payload.display_order = Number(payload.display_order);
        else delete payload.display_order;
        try {
            await api.request(editing ? `/backoffice/catalog/products/${editId}` : "/backoffice/catalog/products", { method: editing ? "PATCH" : "POST", body: payload });
            closeDrawer();
            await load();
            showMessage("Produto salvo. Ative e publique a nova versão do catálogo.", "success");
        } catch (error) {
            window.FokusForm?.mapServerErrors(form, error.errors);
            showMessage(error.message || "Não foi possível salvar o produto.");
        }
    };

    createTrigger.addEventListener("click", openCreate);
    $("#product-drawer-close").addEventListener("click", closeDrawer);
    $("#product-form-cancel").addEventListener("click", closeDrawer);
    form.addEventListener("submit", submit);
    $("#product-filter-form").addEventListener("submit", (event) => { event.preventDefault(); state.page = 1; render(); });
    $("#product-search").addEventListener("input", () => { state.page = 1; render(); });
    $("#product-status-filter").addEventListener("change", () => { state.page = 1; render(); });
    pagination.addEventListener("click", (event) => { const button = event.target.closest("[data-product-page]"); if (!button || button.disabled) return; state.page = Number(button.dataset.productPage); render(); });
    list.addEventListener("click", (event) => {
        const button = event.target.closest("[data-product-action]");
        if (!button) return;
        const product = state.products.find((item) => String(item.id) === button.dataset.productId);
        if (!product) return;
        const type = button.dataset.productAction;
        if (type === "view") openView(product);
        else if (type === "edit" && ["pausado", "inativo"].includes(product.status)) openEdit(product);
        else if (["pause", "publish"].includes(type)) {
            state.pendingAction = { product, type };
            $("#product-action-title").textContent = type === "publish" ? "Publicar nova versão" : "Pausar produto";
            $("#product-action-description").textContent = type === "publish" ? "A publicação gera uma nova versão do catálogo e libera o produto para novas contratações." : "O catálogo do produto ficará indisponível até a nova publicação.";
            $("#product-action-submit").textContent = type === "publish" ? "Publicar catálogo" : "Confirmar pausa";
            actionModal?.show();
        }
        else runAction(product, type);
    });
    $("#product-action-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!state.pendingAction) return;
        const { product, type } = state.pendingAction;
        state.pendingAction = null;
        actionModal?.hide();
        await runAction(product, type);
    });

    await load();
    return () => {
        drawer.dispose();
        actionModal?.dispose?.();
        window.disposeBackofficeRecordsPage?.(root);
    };
}
