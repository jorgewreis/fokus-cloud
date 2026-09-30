const escapeHtml = (value) => String(value ?? "").replace(/[&<>'"]/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#039;", '"': "&quot;" })[character]);
const dateTime = (value) => value ? new Date(value).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" }) : "—";
const eventLabel = (value) => ({ "customer.login_succeeded": "Login do cliente", "customer.login_failed": "Falha no login do cliente", "customer.logout": "Logout do cliente", "backoffice.login_succeeded": "Login do administrador", "backoffice.login_failed": "Falha no login do administrador", "backoffice.logout": "Logout do administrador", "backoffice.mfa_failed": "Falha na validação MFA", "backoffice.mfa_requested": "MFA solicitado", "backoffice.login_origin_locked": "Origem bloqueada" }[value] || value || "Evento de acesso");
const statusBadge = (value) => value === "success" ? '<span class="fs-badge fs-badge-soft-success">Sucesso</span>' : '<span class="fs-badge fs-badge-soft-danger">Falha</span>';

export async function mount(root, context = {}) {
    const api = context.api || window.FokusApi;
    const mode = root.querySelector("[data-audit-mode]")?.dataset.auditMode;
    const disposers = [];
    root.querySelectorAll("[data-audit-nav]").forEach((button) => {
        const handler = () => context.router?.navigate(button.dataset.auditNav);
        button.addEventListener("click", handler);
        disposers.push(() => button.removeEventListener("click", handler));
    });
    if (mode === "access") {
        const body = root.querySelector("#access-body");
        const summary = root.querySelector("#access-summary");
        const pageSummary = root.querySelector("#access-page-summary");
        const pagination = root.querySelector("#access-pagination");
        const filters = root.querySelector("#access-filters");
        const message = root.querySelector("#access-message");
        const state = { page: 1, filters: {} };
        const showMessage = (text, tone = "danger") => { message.textContent = text || ""; message.dataset.tone = tone; message.hidden = !text; };
        const renderPagination = (meta) => {
            pagination.replaceChildren();
            for (let page = 1; page <= (meta?.last_page || 1); page += 1) {
                if ((meta?.last_page || 1) <= 1) break;
                const button = document.createElement("button");
                button.type = "button";
                button.className = `fs-pagination-button${page === meta.current_page ? " is-active" : ""}`;
                button.textContent = String(page);
                button.addEventListener("click", () => { state.page = page; load(); });
                pagination.append(button);
            }
        };
        const showDetail = (item) => {
            const detail = root.querySelector("#access-detail");
            root.querySelector("#access-detail-body").innerHTML = `<dl class="fs-detail-list"><div><dt>Usuário</dt><dd>${escapeHtml(item.user)}<br><small>${escapeHtml(item.email || "")}</small></dd></div><div><dt>Empresa</dt><dd>${escapeHtml(item.company || "Não identificada")}</dd></div><div><dt>Produto</dt><dd>${escapeHtml(item.product || "Não identificado")}</dd></div><div><dt>Evento</dt><dd>${escapeHtml(eventLabel(item.event))}</dd></div><div><dt>Data e hora</dt><dd>${escapeHtml(dateTime(item.created_at))}</dd></div><div><dt>IP</dt><dd>${escapeHtml(item.ip_address || "Não informado")}</dd></div><div><dt>Navegador/dispositivo</dt><dd>${escapeHtml(item.user_agent || "Não informado")}</dd></div></dl>`;
            window.FokusStyles?.Offcanvas?.getOrCreateInstance(detail)?.show();
        };
        const load = async () => {
            try {
                const params = new URLSearchParams({ page: String(state.page), per_page: "20", ...state.filters });
                const response = await api.request(`/backoffice/audit/access-control?${params}`, { signal: context.signal });
                const items = response.data || [];
                const meta = response.meta || {};
                summary.textContent = `${meta.total || 0} registros disponíveis · retenção de ${meta.retention_days || 30} dias`;
                pageSummary.textContent = items.length ? `Mostrando ${((meta.current_page - 1) * meta.per_page) + 1}–${((meta.current_page - 1) * meta.per_page) + items.length} de ${meta.total}` : "Nenhum registro encontrado.";
                body.innerHTML = items.length ? items.map((item, index) => `<tr><td><strong>${escapeHtml(item.user)}</strong><br><small class="fs-u-color-secondary">${escapeHtml(item.email || "")} · ${escapeHtml(item.user_type === "interno" ? "Interno" : "Cliente")}</small></td><td>${escapeHtml(item.company || "—")}</td><td>${escapeHtml(item.product || "—")}</td><td>${escapeHtml(dateTime(item.created_at))}</td><td>${statusBadge(item.status)}</td><td>${escapeHtml(item.user_type === "interno" ? "Administrador interno" : "Usuário cliente")}</td><td><button class="fs-btn fs-btn-outline-secondary fs-btn-sm" type="button" data-access-detail="${index}">Detalhes</button></td></tr>`).join("") : '<tr><td colspan="7">Nenhum acesso encontrado nos filtros informados.</td></tr>';
                body.querySelectorAll("[data-access-detail]").forEach((button) => button.addEventListener("click", () => showDetail(items[Number(button.dataset.accessDetail)])));
                renderPagination(meta);
                showMessage("");
            } catch (error) { if (error?.name !== "AbortError") showMessage(error.message || "Não foi possível carregar o controle de acessos."); }
        };
        const submit = (event) => { event.preventDefault(); state.page = 1; state.filters = Object.fromEntries([...new FormData(filters)].filter(([, value]) => value)); load(); };
        filters.addEventListener("submit", submit);
        root.querySelector("#access-clear").addEventListener("click", () => { filters.reset(); state.page = 1; state.filters = {}; load(); });
        disposers.push(() => filters.removeEventListener("submit", submit));
        await load();
    }
    if (mode === "activity") {
        const body = root.querySelector("#audit-events-body");
        try {
            const events = await api.request("/backoffice/audit", { signal: context.signal });
            body.innerHTML = (events || []).slice(0, 20).map((event) => `<tr><td>${escapeHtml(event.action)}</td><td>${escapeHtml(event.actor_type || "Sistema")}</td><td>${escapeHtml(dateTime(event.created_at))}</td><td>${escapeHtml(event.origin_context || event.origin_channel || "—")}</td></tr>`).join("") || '<tr><td colspan="4">Nenhum evento recente encontrado.</td></tr>';
        } catch (error) { if (error?.name !== "AbortError") body.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message || "Não foi possível carregar os eventos.")}</td></tr>`; }
    }
    return () => disposers.forEach((dispose) => dispose());
}
