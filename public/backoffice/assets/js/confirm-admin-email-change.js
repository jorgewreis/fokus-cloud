(() => {
    const token = new URLSearchParams(location.search).get("token") || "";
    const button = document.querySelector("#confirm-button");
    const message = document.querySelector("#confirm-message");
    const help = document.querySelector("#confirm-help");
    const showMessage = (text, tone) => {
        message.textContent = text;
        message.dataset.tone = tone;
        message.hidden = false;
    };

    if (token) history.replaceState(null, "", location.pathname);
    if (!token) {
        button.hidden = true;
        showMessage("O link não contém um token válido. Solicite uma nova alteração de e-mail ao superadministrador.", "danger");
        return;
    }

    button.addEventListener("click", async () => {
        button.disabled = true;
        button.setAttribute("aria-busy", "true");
        try {
            const result = await window.FokusApi.request("/backoffice/auth/confirm-email-change", {
                method: "POST",
                body: { token },
            });
            showMessage(result.message, "success");
            help.hidden = true;
            button.hidden = true;
        } catch (error) {
            showMessage(error.message || "Não foi possível confirmar este e-mail.", "danger");
            button.disabled = false;
        } finally {
            button.removeAttribute("aria-busy");
        }
    });
})();
