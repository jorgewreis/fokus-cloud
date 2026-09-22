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
        offcanvas?.hide();
        state.mode = null;
        state.record = null;
    }

    function dispose() {
        close();
        document.removeEventListener("keydown", handleEscape);
        offcanvas?.dispose?.();
    }

    return Object.freeze({ setState, show, close, dispose, getState: () => ({ ...state }) });
}
