/**
 * Ciclo de vida compartilhado dos drawers de registros.
 * A página continua responsável pelos campos e regras de negócio; este módulo
 * concentra o estado create/edit/view, abertura, fechamento e descarte.
 */
export function createRecordsDrawer({ trigger, drawer }) {
    const offcanvas = trigger && drawer
        ? window.FokusStyles?.Offcanvas?.getOrCreateInstance(trigger)
        : null;
    const state = { mode: null, record: null };
    let focusFrame = null;
    const cancelPendingFocus = () => {
        if (focusFrame !== null) cancelAnimationFrame(focusFrame);
        focusFrame = null;
    };
    const focusWhenVisible = () => {
        cancelPendingFocus();
        const deadline = performance.now() + 2000;
        const focus = () => {
            focusFrame = null;
            if (!offcanvas?.isOpen || !drawer?.isConnected) return;
            if (drawer.contains(document.activeElement)) return;
            if (getComputedStyle(drawer).visibility === "visible") {
                const target = [...drawer.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                    .find((element) => element.getClientRects().length && getComputedStyle(element).visibility === "visible");
                (target || drawer).focus({ preventScroll: true });
                if (drawer.contains(document.activeElement)) return;
            }
            if (performance.now() < deadline) focusFrame = requestAnimationFrame(focus);
        };
        focusFrame = requestAnimationFrame(focus);
    };
    // A fixed number of animation frames does not guarantee CSS visibility.
    // Preserve the native focus trap; only complete its initial focus when visible.
    trigger?.addEventListener("fs:shown", focusWhenVisible);
    trigger?.addEventListener("fs:hide", cancelPendingFocus);
    const handleEscape = (event) => {
        if (event.key === "Escape" && !drawer?.hidden) close();
    };

    document.addEventListener("keydown", handleEscape);

    function setState({ mode, record = null }) {
        state.mode = mode;
        state.record = record;
        if (drawer) drawer.dataset.mode = mode;
        return { ...state };
    }

    function show() {
        offcanvas?.show();
    }

    function close() {
        cancelPendingFocus();
        offcanvas?.hide();
        state.mode = null;
        state.record = null;
    }

    function dispose() {
        close();
        document.removeEventListener("keydown", handleEscape);
        trigger?.removeEventListener("fs:shown", focusWhenVisible);
        trigger?.removeEventListener("fs:hide", cancelPendingFocus);
        offcanvas?.dispose?.();
    }

    return Object.freeze({ setState, show, close, dispose, getState: () => ({ ...state }) });
}
