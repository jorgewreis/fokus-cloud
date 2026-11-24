(() => {
    const toneMap = { success: "success", warning: "warning", danger: "danger", error: "danger", info: "info", primary: "primary", secondary: "secondary" };
    const titleMap = { success: "Sucesso", warning: "Aviso", danger: "Erro", info: "Informação", primary: "Informação", secondary: "Informação" };
    const delay = 5000;
    const active = new Map();
    let suspended = false;

    const container = () => {
        let node = document.querySelector("#backoffice-toast-container");
        if (!node) {
            node = document.createElement("div");
            node.id = "backoffice-toast-container";
            node.className = "fs-toast-container fs-backoffice-toast-container";
            node.setAttribute("aria-live", "polite");
            node.setAttribute("aria-relevant", "additions");
            document.body.append(node);
        }
        return node;
    };

    const show = (value, tone = "info") => {
        const message = String(value || "").trim();
        if (!message || suspended) return;

        const variant = toneMap[tone] || "info";
        const toast = document.createElement("div");
        toast.className = `fs-toast fs-toast-${variant} fs-backoffice-toast`;
        toast.setAttribute("role", variant === "danger" ? "alert" : "status");
        toast.setAttribute("data-delay", String(delay));
        toast.setAttribute("data-toast-progress", "true");
        toast.innerHTML = `<div class="fs-toast-header"><div class="fs-toast-heading"><span class="fs-toast-title"></span></div><button class="fs-btn-close fs-toast-close" type="button" data-fs-dismiss="toast" aria-label="Fechar mensagem"></button></div><div class="fs-toast-body"></div><div class="fs-toast-progress" aria-hidden="true"></div>`;
        toast.querySelector(".fs-toast-title").textContent = titleMap[variant];
        toast.querySelector(".fs-toast-body").textContent = message;
        container().append(toast);

        const instance = new window.FokusStyles.Toast(toast);
        active.set(toast, instance);
        toast.addEventListener("fs:toast:hidden", () => {
            instance.dispose();
            active.delete(toast);
            toast.remove();
        }, { once: true });
        instance.show();
    };

    const bridge = (node) => {
        if (suspended) return;
        if (!(node instanceof HTMLElement) || !node.matches(".fs-alert[role='status'][id$='-message']")) return;
        const message = node.textContent.trim();
        if (!message) {
            delete node.dataset.fokusToastFingerprint;
            return;
        }

        const fingerprint = `${node.dataset.tone || "info"}:${message}`;
        if (node.dataset.fokusToastFingerprint === fingerprint) return;
        node.dataset.fokusToastFingerprint = fingerprint;
        show(message, node.dataset.tone || "info");
        node.hidden = true;
    };

    const bridgeAll = () => document.querySelectorAll(".fs-alert[role='status'][id$='-message']").forEach(bridge);
    window.FokusToast = { show };

    window.addEventListener("fokus:backoffice-suspend", () => {
        suspended = true;
        active.forEach((instance, toast) => {
            try { instance.dispose(); } catch (_) { /* Remove every toast even if one cleanup fails. */ }
            toast.remove();
        });
        active.clear();
        document.querySelector("#backoffice-toast-container")?.replaceChildren();
    });
    window.addEventListener("fokus:backoffice-resume", () => {
        suspended = false;
        bridgeAll();
    });

    document.addEventListener("DOMContentLoaded", () => {
        bridgeAll();
        new MutationObserver(bridgeAll).observe(document.body, {
            attributes: true,
            attributeFilter: ["data-tone", "hidden"],
            childList: true,
            characterData: true,
            subtree: true,
        });
    });
})();
