const escapeHtml = (value) => String(value ?? "").replace(/[&<>'"]/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#039;", '"': "&quot;" })[character]);
const AUDIT_TIME_ZONE = "America/Bahia";
const dateTime = (value) => {
    if (!value) return "—";
    const parts = new Intl.DateTimeFormat("pt-BR", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", hourCycle: "h23", timeZone: AUDIT_TIME_ZONE }).formatToParts(new Date(value));
    const part = (type) => parts.find((item) => item.type === type)?.value || "";
    return `${part("day")}/${part("month")}/${part("year")} - ${part("hour")}:${part("minute")}`;
};
const EVENT_LABELS = {
    "customer.login_succeeded": "Login do cliente realizado", "customer.login_failed": "Falha no login do cliente", "customer.logout": "Logout do cliente",
    "backoffice.login_succeeded": "Login do administrador realizado", "backoffice.login_failed": "Falha no login do administrador", "backoffice.logout": "Logout do administrador",
    "backoffice.mfa_failed": "Falha na validação MFA", "backoffice.mfa_requested": "MFA solicitado", "backoffice.login_origin_locked": "Origem bloqueada",
    "backoffice.subscriptions_viewed": "Página de assinaturas visualizada", "backoffice.dashboard_viewed": "Painel visualizado", "backoffice.company_viewed": "Empresa visualizada",
    "backoffice.companies viewed": "Empresas visualizadas", "backoffice.companies_viewed": "Empresas visualizadas",
};
const eventLabel = (value) => {
    if (!value) return "Evento de acesso";
    if (EVENT_LABELS[value]) return EVENT_LABELS[value];
    const [domain, ...parts] = value.split(".");
    const verb = parts.pop() || "";
    const subject = parts.join(" ").replaceAll("_", " ");
    const suffix = { viewed: "visualizada", created: "criado", updated: "atualizado", deleted: "excluído", published: "publicado", paused: "pausado", activated: "ativado", login_succeeded: "realizado" }[verb];
    if (verb === "viewed") return `${domain === "backoffice" ? "Página" : "Área"} de ${subject} visualizada`;
    if (suffix) return `${subject ? `${subject[0].toUpperCase()}${subject.slice(1)} ` : ""}${suffix}`.trim();
    return `${domain === "backoffice" ? "Backoffice" : domain} · ${[...parts, verb].filter(Boolean).join(" ").replaceAll("_", " ")}`;
};
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
        const renderPagination = (meta = {}) => {
            const current = Math.max(1, Number(meta.current_page || 1));
            const last = Math.max(1, Number(meta.last_page || 1));
            pagination.innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-access-page="${current - 1}" aria-label="Página anterior" ${current <= 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><span class="fs-page-link" aria-label="Página ${current} de ${last}">${current}</span></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-access-page="${current + 1}" aria-label="Próxima página" ${current >= last ? "disabled" : ""}>›</button></li></ul>`;
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
                summary.textContent = `${meta.total || 0} registros disponíveis`;
                pageSummary.textContent = items.length ? `Mostrando ${((meta.current_page - 1) * meta.per_page) + 1}–${((meta.current_page - 1) * meta.per_page) + items.length} de ${meta.total}` : "Nenhum registro encontrado.";
                body.innerHTML = items.length ? items.map((item, index) => `<tr><td><strong>${escapeHtml(item.user)}</strong><br><small class="fs-u-color-secondary">${escapeHtml(item.email || "")} · ${escapeHtml(item.user_type === "interno" ? "Interno" : "Cliente")}</small></td><td>${escapeHtml(item.company || "—")}</td><td>${escapeHtml(item.product || "—")}</td><td>${escapeHtml(dateTime(item.created_at))}</td><td>${statusBadge(item.status)}</td><td>${escapeHtml(item.user_type === "interno" ? "Administrador interno" : "Usuário cliente")}</td><td><button class="fs-btn fs-btn-outline-primary fs-btn-sm" type="button" data-access-detail="${index}">Detalhes</button></td></tr>`).join("") : '<tr><td colspan="7">Nenhum acesso encontrado nos filtros informados.</td></tr>';
                body.querySelectorAll("[data-access-detail]").forEach((button) => button.addEventListener("click", () => showDetail(items[Number(button.dataset.accessDetail)])));
                renderPagination(meta);
                showMessage("");
            } catch (error) { if (error?.name !== "AbortError") showMessage(error.message || "Não foi possível carregar o controle de acessos."); }
        };
        const onAccessPageClick = (event) => {
            const button = event.target.closest("[data-access-page]");
            if (!button || button.disabled) return;
            state.page = Number(button.dataset.accessPage);
            load();
        };
        const submit = (event) => { event.preventDefault(); state.page = 1; state.filters = Object.fromEntries([...new FormData(filters)].filter(([, value]) => value)); load(); };
        filters.addEventListener("submit", submit);
        pagination.addEventListener("click", onAccessPageClick);
        root.querySelector("#access-clear").addEventListener("click", () => { filters.reset(); state.page = 1; state.filters = {}; load(); });
        disposers.push(() => filters.removeEventListener("submit", submit));
        disposers.push(() => pagination.removeEventListener("click", onAccessPageClick));
        await load();
    }
    if (mode === "activity") {
        const body = root.querySelector("#audit-events-body");
        const summary = root.querySelector("#audit-activity-summary");
        const pageSummary = root.querySelector("#audit-activity-page-summary");
        const pagination = root.querySelector("#audit-activity-pagination");
        const state = { page: 1 };
        const renderPagination = (meta) => {
            const current = Number(meta.current_page || 1);
            const last = Math.max(1, Number(meta.last_page || 1));
            pagination.innerHTML = `<ul class="fs-pagination fs-pagination-compact"><li class="fs-page-item"><button class="fs-page-link" type="button" data-audit-page="${current - 1}" aria-label="Página anterior" ${current <= 1 ? "disabled" : ""}>‹</button></li><li class="fs-page-item is-active" aria-current="page"><button class="fs-page-link" type="button" aria-current="page" aria-label="Página ${current}">${current}</button></li><li class="fs-page-item"><button class="fs-page-link" type="button" data-audit-page="${current + 1}" aria-label="Próxima página" ${current >= last ? "disabled" : ""}>›</button></li></ul>`;
        };
        const onPageClick = (event) => {
            const button = event.target.closest("[data-audit-page]");
            if (!button || button.disabled) return;
            state.page = Number(button.dataset.auditPage);
            load();
        };
        const load = async () => {
        try {
            const response = await api.request(`/backoffice/audit?page=${state.page}&per_page=20`, { signal: context.signal });
            const events = response.data || [];
            const meta = response.meta || {};
            const total = Number(meta.total || 0);
            const current = Number(meta.current_page || 1);
            const perPage = Number(meta.per_page || 20);
            summary.textContent = `${total} eventos registrados`;
            pageSummary.textContent = total ? `Mostrando ${((current - 1) * perPage) + 1}–${((current - 1) * perPage) + events.length} de ${total}` : "Nenhum evento recente encontrado.";
            body.innerHTML = events.map((event) => `<tr><td>${escapeHtml(eventLabel(event.action))}</td><td>${escapeHtml(event.actor_name || (event.actor_type === "system" ? "Sistema" : event.actor_type === "anonymous" ? "Não autenticado" : "Usuário não identificado"))}</td><td>${escapeHtml(dateTime(event.created_at))}</td><td>${escapeHtml(event.origin_context || event.origin_channel || "—")}</td></tr>`).join("") || '<tr><td colspan="4">Nenhum evento recente encontrado.</td></tr>';
            renderPagination(meta);
        } catch (error) { if (error?.name !== "AbortError") body.innerHTML = `<tr><td colspan="4">${escapeHtml(error.message || "Não foi possível carregar os eventos.")}</td></tr>`; }
        };
        pagination.addEventListener("click", onPageClick);
        disposers.push(() => pagination.removeEventListener("click", onPageClick));
        await load();
    }
    return () => disposers.forEach((dispose) => dispose());
}
