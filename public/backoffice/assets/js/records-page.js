/* Shared Backoffice records composition. Portals drawers before the overlay opens. */
(() => {
    const pageOwner = (container) => container?.dataset?.backofficePage || "legacy";

    const removeOrphanedDrawers = (container) => {
        const owner = pageOwner(container);
        document.querySelectorAll('body > .backoffice-records-drawer').forEach((drawer) => {
            if (drawer.dataset.backofficeOwner && drawer.dataset.backofficeOwner !== owner) drawer.remove();
            if (!drawer.dataset.backofficeOwner && !container?.contains(drawer)) drawer.remove();
        });
    };

    const portalDrawerFromTrigger = (trigger, container = trigger?.closest?.('#page-content')) => {
        const selector = trigger?.getAttribute('data-fs-target');
        const drawer = selector ? document.querySelector(selector) : null;
        if (drawer?.dataset.fsPortal === 'false') return;
        if (drawer?.classList.contains('backoffice-records-drawer')) {
            drawer.querySelectorAll('[id$="-view-panel"]').forEach((panel) => {
                panel.classList.add('backoffice-records-view-panel');
            });
        }
        if (drawer?.classList.contains('backoffice-records-drawer') && drawer.parentElement !== document.body) {
            drawer.dataset.backofficeOwner = pageOwner(container);
            document.body.appendChild(drawer);
        }
    };

    document.addEventListener('fs:show', (event) => {
        portalDrawerFromTrigger(event.target.closest?.('[data-fs-target]'));
    }, true);

    window.initBackofficeRecordsPage = (container = document) => {
        removeOrphanedDrawers(container);
        container.querySelectorAll?.('.backoffice-records-drawer').forEach((drawer) => {
            if (drawer.dataset.fsPortal === 'false') return;
            drawer.querySelectorAll('[id$="-view-panel"]').forEach((panel) => {
                panel.classList.add('backoffice-records-view-panel');
            });
            if (drawer.parentElement === document.body) return;
            const trigger = container.querySelector?.(`[data-fs-target="#${drawer.id}"]`);
            if (trigger) portalDrawerFromTrigger(trigger, container);
        });
        const ids = new Set();
        document.querySelectorAll('body > [id]').forEach((element) => {
            if (!ids.has(element.id)) {
                ids.add(element.id);
                return;
            }
            element.remove();
        });
    };

    window.disposeBackofficeRecordsPage = (container = document) => {
        const owner = pageOwner(container);
        document.querySelectorAll('body > .backoffice-records-drawer').forEach((drawer) => {
            if (drawer.dataset.backofficeOwner === owner || !drawer.dataset.backofficeOwner) drawer.remove();
        });
    };
})();
