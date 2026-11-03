(() => {
    const storageKey = "fokus-styles-theme";
    const root = document.documentElement;
    const validThemes = new Set(["light", "dark"]);
    const embedded = window.parent !== window;
    if (embedded) root.dataset.embedded = "true";

    const sendTheme = (frame, theme) => {
        // Only the site's portable examples share its theme. Development
        // component sandboxes deliberately retain their own framework state.
        if (frame.getAttribute("src")?.startsWith("/styles/examples/")) {
            const sandbox = (frame.getAttribute("sandbox") || "").split(/\s+/);
            if (sandbox.includes("allow-same-origin") && !sandbox.includes("allow-scripts")) {
                // Static gallery thumbnails intentionally cannot run scripts.
                // Keep that restriction and set appearance from the parent.
                const previewRoot = frame.contentDocument?.documentElement;
                if (previewRoot) {
                    previewRoot.dataset.theme = theme;
                    previewRoot.dataset.embedded = "true";
                    previewRoot.style.colorScheme = theme;
                }
                return;
            }
            frame.contentWindow?.postMessage({ type: "fokus-styles:theme", theme }, "*");
        }
    };

    const readTheme = () => {
        try {
            const savedTheme = localStorage.getItem(storageKey);
            return validThemes.has(savedTheme) ? savedTheme : (root.dataset.theme || "dark");
        } catch {
            return root.dataset.theme || "dark";
        }
    };

    const applyTheme = (theme) => {
        root.dataset.theme = theme;
        root.style.colorScheme = theme;
        document.querySelectorAll("[data-theme-toggle]").forEach((toggle) => {
            const nextTheme = theme === "dark" ? "light" : "dark";
            toggle.setAttribute("aria-label", `Ativar tema ${nextTheme === "light" ? "claro" : "escuro"}`);
            toggle.setAttribute("title", `Ativar tema ${nextTheme === "light" ? "claro" : "escuro"}`);
            toggle.setAttribute("aria-pressed", String(theme === "dark"));
        });
        document.querySelectorAll('iframe[src^="/styles/examples/"]').forEach(frame => sendTheme(frame, theme));
    };

    window.addEventListener("message", event => {
        if (embedded && event.source === window.parent && event.data?.type === "fokus-styles:theme" && validThemes.has(event.data.theme)) {
            applyTheme(event.data.theme);
        }
    });

    applyTheme(readTheme());

    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll('iframe[src^="/styles/examples/"]').forEach(frame => {
            frame.addEventListener("load", () => sendTheme(frame, root.dataset.theme));
        });
        document.querySelectorAll("[data-theme-toggle]").forEach((toggle) => {
            toggle.addEventListener("click", () => {
                const nextTheme = root.dataset.theme === "dark" ? "light" : "dark";
                try { localStorage.setItem(storageKey, nextTheme); } catch { /* theme still changes for this page */ }
                applyTheme(nextTheme);
            });
        });
        applyTheme(root.dataset.theme);
    }, { once: true });
})();
