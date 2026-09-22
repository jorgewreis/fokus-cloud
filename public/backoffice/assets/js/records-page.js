/* Shared Backoffice records composition. Portals drawers before the overlay opens. */
(() => {
    const removeOrphanedDrawers = (container) => {
        document.querySelectorAll('body > .backoffice-records-drawer').forEach((drawer) => {
            if (!container?.contains(drawer)) drawer.remove();
        });
    };

    const portalDrawerFromTrigger = (trigger) => {
        const selector = trigger?.getAttribute('data-fs-target');
        const drawer = selector ? document.querySelector(selector) : null;
        if (drawer?.dataset.fsPortal === 'false') return;
        if (drawer?.classList.contains('backoffice-records-drawer')) {
            drawer.querySelectorAll('[id$="-view-panel"]').forEach((panel) => {
                panel.classList.add('backoffice-records-view-panel');
            });
        }
        if (drawer?.classList.contains('backoffice-records-drawer') && drawer.parentElement !== document.body) {
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
            if (trigger) portalDrawerFromTrigger(trigger);
        });
    };
})();
