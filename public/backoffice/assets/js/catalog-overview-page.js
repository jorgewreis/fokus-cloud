const STATUS_ORDER = ["ativo", "rascunho", "inativo", "pausado", "arquivado"];
const STATUS_LABELS = { ativo: "Ativos", rascunho: "Rascunhos", inativo: "Inativos", pausado: "Pausados", arquivado: "Arquivados" };
const STATUS_ITEM_LABELS = { ativo: "Ativo", rascunho: "Rascunho", inativo: "Inativo", pausado: "Pausado", arquivado: "Arquivado", sem_situacao: "Sem situação" };

const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[character]));
const statusLabel = (status) => STATUS_ITEM_LABELS[status] || (status ? String(status).replaceAll("_", " ") : "Situação não informada");
const statusBadge = (status) => {
    const tone = { ativo: "success", publicado: "success", pausado: "warning", rascunho: "secondary", inativo: "secondary", arquivado: "danger" }[status] || "secondary";
    return `<span class="fs-badge fs-badge-soft-${tone}">${escapeHtml(statusLabel(status))}</span>`;
};

export async function mount(root, context = {}) {
    const $ = (selector) => root.querySelector(selector);
    const api = context.api || window.FokusApi;
    const router = context.router;
    const canOpenCatalogManagement = context.admin?.role === "superadministrador";
    const statusNodes = { products: $("#catalog-products-statuses"), modules: $("#catalog-modules-statuses"), plans: $("#catalog-plans-statuses") };
    let requestId = 0;

    const renderStatusSummary = (items, target, totalTarget) => {
        const counts = new Map();
        items.forEach((item) => {
            const status = String(item.status || "sem_situacao").toLowerCase();
            counts.set(status, (counts.get(status) || 0) + 1);
        });
        $(totalTarget).textContent = String(items.length);
        const orderedStatuses = [...STATUS_ORDER.filter((status) => counts.has(status)), ...[...counts.keys()].filter((status) => !STATUS_ORDER.includes(status)).sort()];
        if (target === "products") {
            const pending = items.filter((item) => Boolean(item.publication_pending) || (item.status === "ativo" && Number(item.published_catalog_version || 0) === 0)).length;
            $("#catalog-products-publication").textContent = pending
                ? `${pending} ${pending === 1 ? "produto aguarda" : "produtos aguardam"} publicação`
                : "Nenhuma publicação pendente";
            const distribution = $("#catalog-products-distribution");
            distribution.setAttribute("aria-label", items.length
                ? `Situação dos produtos: ${orderedStatuses.map((status) => `${counts.get(status)} ${statusLabel(status)}`).join(", ")}`
                : "Nenhum produto cadastrado");
            distribution.innerHTML = items.length
                ? orderedStatuses.map((status) => `<span class="catalog-overview-distribution-segment catalog-overview-distribution-${STATUS_ORDER.includes(status) ? status : "sem_situacao"}" style="width:${(counts.get(status) / items.length) * 100}%" title="${escapeHtml(statusLabel(status))}: ${counts.get(status)}"></span>`).join("")
                : '<span class="catalog-overview-distribution-empty"></span>';
        }
        statusNodes[target].innerHTML = orderedStatuses.length
            ? orderedStatuses.map((status) => `<span class="catalog-overview-status" role="listitem">${escapeHtml(statusLabel(status))}: <strong>${counts.get(status)}</strong></span>`).join("")
            : '<span class="catalog-overview-status" role="listitem">Nenhum cadastrado</span>';
    };

    const renderPending = (products) => {
        const isPending = (product) => Boolean(product.publication_pending) || (product.status === "ativo" && Number(product.published_catalog_version || 0) === 0);
        const pendingCount = products.filter(isPending).length;
        const orderedProducts = [...products].sort((left, right) => Number(isPending(right)) - Number(isPending(left)) || String(left.name || "").localeCompare(String(right.name || ""), "pt-BR"));
        $("#catalog-pending-count").textContent = `${pendingCount} pendentes`;
        $("#catalog-pending-list").innerHTML = orderedProducts.map((product) => {
            const version = Number(product.published_catalog_version || 0);
            const publication = product.publication_pending
                ? (version > 0 ? "Republicação pendente" : "Publicação inicial pendente")
                : version > 0 ? "Publicado" : isPending(product) ? "Publicação inicial pendente" : "Não publicado";
            const publicationTone = isPending(product) ? "warning" : version > 0 ? "success" : "secondary";
            return `<tr>
                <td><span class="catalog-overview-product"><strong>${escapeHtml(product.name || "Produto sem nome")}</strong><small>${escapeHtml(product.code || "Código não informado")}</small></span></td>
                <td>${statusBadge(product.status)}</td>
                <td><span class="fs-badge fs-badge-soft-${publicationTone}">${escapeHtml(publication)}</span></td>
                <td>${version > 0 ? `v${version}.0` : "—"}</td>
                <td>${isPending(product) && canOpenCatalogManagement ? `<button class="fs-btn fs-btn-outline-primary fs-btn-sm" type="button" data-catalog-nav="products" aria-label="Gerenciar produto ${escapeHtml(product.name || product.code || "")}">Abrir Produtos</button>` : "—"}</td>
            </tr>`;
        }).join("");
        $("#catalog-pending-empty").hidden = products.length > 0;
    };

    const renderHistory = (publications) => {
        const latest = [...publications].sort((left, right) => {
            const dateDifference = new Date(right.published_at || 0).getTime() - new Date(left.published_at || 0).getTime();
            return (Number.isFinite(dateDifference) ? dateDifference : 0) || Number(right.version || 0) - Number(left.version || 0);
        }).slice(0, 5);
        $("#catalog-history-count").textContent = `${latest.length} ${latest.length === 1 ? "publicação" : "publicações"}`;
        $("#catalog-history-list").innerHTML = latest.map((publication) => {
            const parsed = new Date(publication.published_at);
            const validDate = publication.published_at && !Number.isNaN(parsed.getTime());
            const formattedDate = validDate ? parsed.toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" }) : "Data não informada";
            return `<tr>
                <td><strong>${escapeHtml(publication.product_name || "Produto removido")}</strong></td>
                <td>v${escapeHtml(publication.version || "—")}.0</td>
                <td>${validDate ? `<time datetime="${escapeHtml(parsed.toISOString())}">${escapeHtml(formattedDate)}</time>` : escapeHtml(formattedDate)}</td>
                <td>${escapeHtml(publication.published_by || "Responsável não informado")}</td>
                <td class="catalog-overview-reason">${escapeHtml(publication.reason || "Motivo não informado")}</td>
            </tr>`;
        }).join("");
        $("#catalog-history-empty").hidden = latest.length > 0;
    };

    const showError = (message) => {
        $("#catalog-overview-error-message").textContent = message || "Não foi possível carregar o catálogo.";
        $("#catalog-overview-error").hidden = false;
        $("#catalog-overview-loading").hidden = true;
        $("#catalog-overview-content").hidden = true;
    };

    const load = async () => {
        const currentRequest = ++requestId;
        $("#catalog-overview-error").hidden = true;
        $("#catalog-overview-content").hidden = true;
        $("#catalog-overview-loading").hidden = false;
        try {
            const response = await api.request("/backoffice/catalog", { signal: context.signal });
            if (context.signal?.aborted || currentRequest !== requestId) return;
            const products = Array.isArray(response.products) ? response.products : [];
            const modules = products.flatMap((product) => Array.isArray(product.modules) ? product.modules : []);
            const plans = products.flatMap((product) => Array.isArray(product.plans) ? product.plans : []);
            renderStatusSummary(products, "products", "#catalog-products-total");
            renderStatusSummary(modules, "modules", "#catalog-modules-total");
            renderStatusSummary(plans, "plans", "#catalog-plans-total");
            renderPending(products);
            renderHistory(Array.isArray(response.publications) ? response.publications : []);
            $("#catalog-overview-loading").hidden = true;
            $("#catalog-overview-content").hidden = false;
        } catch (error) {
            if (context.signal?.aborted || currentRequest !== requestId) return;
            showError(error.message);
        }
    };

    root.querySelectorAll("[data-catalog-nav]").forEach((button) => { button.hidden = !canOpenCatalogManagement; });
    root.addEventListener("click", (event) => {
        const button = event.target.closest("[data-catalog-nav]");
        if (!button || !root.contains(button)) return;
        router?.navigate(button.dataset.catalogNav);
    }, { signal: context.signal });
    $("#catalog-overview-retry").addEventListener("click", load, { signal: context.signal });
    await load();
}
