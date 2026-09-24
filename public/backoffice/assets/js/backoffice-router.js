/*
 * Backoffice page lifecycle.
 *
 * A page fragment is declarative HTML. Its behaviour is supplied by the page
 * registry, which gives the shell one place to cancel requests, remove
 * portalled overlays and release listeners before the next page is mounted.
 */
(() => {
    const ASSET_VERSION = "20260924-subscription-public-name-v1";

    const pages = {
        "platform-dashboard-final": { route: "painel", fragment: "platform-dashboard-final" },
        "catalog-overview": { route: "visao-geral-catalogo", fragment: "catalog-overview", module: "/backoffice/assets/js/catalog-overview-page.js", permission: "platform.catalog.manage" },
        companies: { route: "empresas", fragment: "companies", module: "/backoffice/assets/js/companies-page.js" },
        "subscription-plans": { route: "planos", fragment: "subscription-plans", module: "/backoffice/assets/js/subscription-plans-page.js", role: "superadministrador" },
        products: { route: "produtos", fragment: "products", module: "/backoffice/assets/js/products-page.js", role: "superadministrador" },
        modules: { route: "modulos", fragment: "modules", module: "/backoffice/assets/js/modules-page.js", role: "superadministrador" },
        subscriptions: { route: "assinaturas", fragment: "subscriptions", module: "/backoffice/assets/js/subscriptions-page.js" },
        pagamentos: { route: "pagamentos", fragment: "pagamentos", module: "/backoffice/assets/js/payments-page.js", permission: "platform.payments.view" },
        vouchers: { route: "vouchers", fragment: "vouchers", module: "/backoffice/assets/js/vouchers-page.js", permission: "platform.vouchers.manage" },
        users: { route: "usuarios", fragment: "users", module: "/backoffice/assets/js/users-page.js", permission: "platform.users.view" },
        "product-interests": { route: "interesses", fragment: "product-interests" },
        "ui-components": { route: "componentes", fragment: "ui-components" },
    };

    const routeAliases = {
        painel: "platform-dashboard-final",
        "visao-geral-catalogo": "catalog-overview",
        empresas: "companies",
        planos: "subscription-plans",
        catalogo: "products",
        produtos: "products",
        modulos: "modules",
        modules: "modules",
        assinaturas: "subscriptions",
        pagamentos: "pagamentos",
        billing: "pagamentos",
        vouchers: "vouchers",
        usuarios: "users",
        seguranca: "users",
        interesses: "product-interests",
        "product-interests": "product-interests",
        componentes: "ui-components",
    };

    const createAbortError = () => new DOMException("Navegação substituída.", "AbortError");

    class BackofficeRouter {
        constructor({ root, sidebarButtons, admin, onComponentsReady }) {
            this.root = root;
            this.sidebarButtons = [...sidebarButtons];
            this.admin = admin;
            this.onComponentsReady = onComponentsReady;
            this.abortController = null;
            this.disposeCurrent = null;
            this.currentPage = null;
            this.navigationId = 0;
            this.handlePopState = this.handlePopState.bind(this);
        }

        start() {
            window.addEventListener("popstate", this.handlePopState);
            return this.navigate(this.pageFromLocation(), { history: false });
        }

        stop() {
            window.removeEventListener("popstate", this.handlePopState);
            this.abortController?.abort();
            this.unmountCurrent();
        }

        pageFromLocation() {
            const route = location.pathname.split("/").filter(Boolean).pop();
            return routeAliases[route] || "platform-dashboard-final";
        }

        canAccess(page) {
            if (!page) return false;
            if (page.role && this.admin?.role !== page.role) return false;
            if (page.permission && !(window.__backofficePermissions || new Set()).has(page.permission)) return false;
            return true;
        }

        async navigate(pageId, { history: updateHistory = true } = {}) {
            const page = pages[pageId] || pages.companies;
            const allowedPage = this.canAccess(page) ? page : pages.companies;
            const allowedPageId = this.canAccess(page) ? pageId : "companies";
            const navigationId = ++this.navigationId;

            this.abortController?.abort();
            this.abortController = new AbortController();
            this.unmountCurrent();
            this.setActivePage(allowedPageId);
            this.showLoading();

            if (updateHistory && location.pathname !== `/backoffice/${allowedPage.route}`) {
                window.history.pushState({ backofficePage: allowedPageId }, "", `/backoffice/${allowedPage.route}`);
            }

            try {
                const response = await fetch(`/backoffice/pages/${allowedPage.fragment}.html?v=${ASSET_VERSION}`, {
                    signal: this.abortController.signal,
                    credentials: "same-origin",
                    cache: "no-store",
                });
                if (!response.ok) throw new Error(`Página não encontrada: ${allowedPageId}`);
                const markup = await response.text();
                if (navigationId !== this.navigationId) throw createAbortError();
                await this.mount(allowedPageId, allowedPage, markup, this.abortController.signal);
            } catch (error) {
                if (error?.name === "AbortError") return;
                if (navigationId !== this.navigationId) return;
                this.showError(allowedPageId, error);
            }
        }

        async mount(pageId, page, markup, signal) {
            const template = document.createElement("template");
            template.innerHTML = markup;
            const styles = [...template.content.querySelectorAll("link[data-page-css]")];
            const scripts = [...template.content.querySelectorAll("script")];
            styles.forEach((style) => style.remove());
            scripts.forEach((script) => script.remove());

            document.querySelectorAll("link[data-page-css]").forEach((style) => style.remove());
            styles.forEach((style) => {
                const link = document.createElement("link");
                link.rel = "stylesheet";
                link.dataset.pageCss = pageId;
                link.href = style.href;
                document.head.append(link);
            });

            this.root.replaceChildren(template.content.cloneNode(true));
            this.root.dataset.backofficePage = pageId;
            this.currentPage = pageId;
            window.FokusForm?.enhance(this.root);
            this.onComponentsReady?.(this.root);
            window.initBackofficeRecordsPage?.(this.root);

            const context = {
                admin: this.admin,
                api: window.FokusApi,
                permissions: window.__backofficePermissions || new Set(),
                router: this,
                root: this.root,
                signal,
                version: ASSET_VERSION,
            };

            if (page.module) {
                const module = await import(`${page.module}?v=${ASSET_VERSION}`);
                const disposer = await module.mount?.(this.root, context);
                this.disposeCurrent = typeof disposer === "function" ? disposer : module.unmount;
                return;
            }

            // Compatibility bridge for existing fragments. New pages use a page
            // module; this bridge keeps established API flows operational while
            // they are moved out of their historical inline fragments.
            await this.runLegacyScripts(scripts);
            // Some legacy initializers create an overlay after their first
            // mount. Run the owner-aware cleanup once more so duplicate IDs
            // cannot survive a route transition.
            window.initBackofficeRecordsPage?.(this.root);
            this.disposeCurrent = () => {};
        }

        async runLegacyScripts(scripts) {
            for (const source of scripts) {
                const script = document.createElement("script");
                [...source.attributes].forEach((attribute) => script.setAttribute(attribute.name, attribute.value));
                if (source.src) {
                    await new Promise((resolve, reject) => {
                        script.addEventListener("load", resolve, { once: true });
                        script.addEventListener("error", reject, { once: true });
                        script.src = source.src;
                        document.body.append(script);
                    });
                    script.remove();
                    continue;
                }
                script.textContent = source.textContent;
                document.body.append(script);
                script.remove();
            }
        }

        unmountCurrent() {
            if (!this.currentPage) return;
            try {
                this.disposeCurrent?.();
            } finally {
                window.disposeBackofficeRecordsPage?.(this.root);
                document.querySelectorAll("link[data-page-css]").forEach((style) => style.remove());
                this.root.replaceChildren();
                delete this.root.dataset.backofficePage;
                this.currentPage = null;
                this.disposeCurrent = null;
            }
        }

        setActivePage(pageId) {
            const catalogPages = new Set(["catalog-overview", "products", "modules", "subscription-plans"]);
            this.sidebarButtons.forEach((button) => {
                const isCatalogGroup = button.classList.contains("sidebar-group-toggle") && catalogPages.has(pageId);
                button.classList.toggle("active", button.dataset.sidebarItem === pageId || isCatalogGroup);
            });
        }

        showLoading() {
            this.root.innerHTML = '<div class="fs-alert fs-alert-info" role="status" aria-live="polite" aria-busy="true">Carregando página…</div>';
        }

        showError(pageId, error) {
            this.root.innerHTML = '<div class="fs-alert fs-alert-danger" role="alert"><p>Não foi possível abrir esta página.</p><button class="fs-btn fs-btn-outline-primary" type="button">Tentar novamente</button></div>';
            this.root.querySelector("button")?.addEventListener("click", () => this.navigate(pageId, { history: false }), { once: true });
            console.error(error);
        }

        handlePopState() {
            this.navigate(this.pageFromLocation(), { history: false });
        }
    }

    window.BackofficePageRegistry = Object.freeze(pages);
    window.BackofficeRouteAliases = Object.freeze(routeAliases);
    window.BackofficeRouter = {
        create(options) { return new BackofficeRouter(options); },
        version: ASSET_VERSION,
    };
})();
