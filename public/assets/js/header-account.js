(() => {
  const userMenus = document.querySelectorAll("[data-header-user]");
  const clientAccessButtons = document.querySelectorAll("[data-header-client-access]");
  const contactButtons = document.querySelectorAll("[data-header-contact]");
  const backofficeAccessButtons = document.querySelectorAll("[data-header-backoffice-access]");

  if (!userMenus.length && !backofficeAccessButtons.length) return;

  const displayName = (name) => {
    const parts = (name || "").trim().split(/\s+/).filter(Boolean);
    if (parts.length < 2) return parts[0] || "";
    return `${parts[0]} ${parts[parts.length - 1]}`;
  };

  const maskedCpf = (cpf) => {
    const digits = String(cpf || "").replace(/\D/g, "");
    if (digits.length !== 11) return "Conta Fokus Cloud";
    return `CPF •••.•••.***-${digits.slice(-2)}`;
  };

  const toggleVisibility = (elements, visible) => {
    elements.forEach((element) => {
      if (visible && element.hidden) element.hidden = false;
      if (visible) element.style.display = "";
      requestAnimationFrame(() => element.classList.toggle("is-visible", visible));
      if (!visible) {
        window.setTimeout(() => {
          if (!element.classList.contains("is-visible")) element.hidden = true;
        }, 700);
      }
    });
  };

  const setupClientAccess = () => {
    if (!clientAccessButtons.length) return null;

    document.body.insertAdjacentHTML("beforeend", `
      <div class="access-modal" data-client-modal hidden>
        <div class="access-modal-backdrop" data-client-modal-close></div>
        <section class="access-modal-card" role="dialog" aria-modal="true" aria-labelledby="client-modal-title">
          <button class="access-modal-close" data-client-modal-close type="button" aria-label="Fechar acesso à conta">×</button>
          <div class="card-heading">
            <p class="section-kicker">Acesso à plataforma</p>
            <h2 class="fs-card-title" id="client-modal-title">Entre na sua conta.</h2>
            <p class="card-description">Use seu CPF ou CNPJ e sua senha para continuar.</p>
          </div>
          <form data-client-login>
            <label class="form-field"><span class="fs-form-label" data-client-document-label>CPF ou CNPJ</span><input class="fs-form-control field-size-md" data-client-document name="document" inputmode="text" autocomplete="username" maxlength="18" required></label>
            <label class="form-field"><span class="fs-form-label">Senha</span><input class="fs-form-control field-size-md" name="password" type="password" autocomplete="current-password" required></label>
            <div class="login-actions"><button class="fs-btn fs-btn-success" type="submit">Entrar</button><a class="fs-btn fs-btn-outline-primary" href="/cadastro">Criar conta</a></div>
          </form>
          <p class="form-message" data-client-message role="status"></p>
          <a class="auth-recovery-link" href="/recuperar-senha">Esqueci minha senha</a>
        </section>
      </div>
    `);

    const modal = document.querySelector("[data-client-modal]");
    const login = modal.querySelector("[data-client-login]");
    const documentInput = modal.querySelector("[data-client-document]");
    const documentLabel = modal.querySelector("[data-client-document-label]");
    const message = modal.querySelector("[data-client-message]");
    let opener = null;
    let csrfToken = null;

    const compact = (value) => String(value || "").toUpperCase().replace(/[^0-9A-Z]/g, "").slice(0, 14);
    const validCpf = (value) => window.FokusDocuments?.cpf(value) || (value.length === 11 && !/^(\d)\1+$/.test(value) && [9, 10].every((length) => {
      const total = [...value.slice(0, length)].reduce((sum, digit, index) => sum + Number(digit) * (length + 1 - index), 0);
      return (total * 10) % 11 % 10 === Number(value[length]);
    }));
    const validCnpj = (value) => window.FokusDocuments?.cnpj(value) || (value.length === 14 && !/^(.)\1+$/.test(value) && [[12, 5], [13, 6]].every(([length, factor]) => {
      const total = [...value.slice(0, length)].reduce((sum, character) => {
        const result = sum + (character.charCodeAt(0) - 48) * factor;
        factor = factor === 2 ? 9 : factor - 1;
        return result;
      }, 0);
      return (total % 11 < 2 ? 0 : 11 - total % 11) === Number(value[length]);
    }));
    const formatCpf = (value) => value.slice(0, 11).replace(/^(\d{3})(\d)/, "$1.$2").replace(/^(\d{3})\.(\d{3})(\d)/, "$1.$2.$3").replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d{1,2})$/, "$1.$2.$3-$4");
    const formatCnpj = (value) => value.slice(0, 14).replace(/^([0-9A-Z]{2})([0-9A-Z])/, "$1.$2").replace(/^([0-9A-Z]{2})\.([0-9A-Z]{3})([0-9A-Z])/, "$1.$2.$3").replace(/^([0-9A-Z]{2})\.([0-9A-Z]{3})\.([0-9A-Z]{3})([0-9A-Z])/, "$1.$2.$3/$4").replace(/^([0-9A-Z]{2})\.([0-9A-Z]{3})\.([0-9A-Z]{3})\/([0-9A-Z]{4})([0-9]{1,2})$/, "$1.$2.$3/$4-$5");
    const updateDocument = (format = false) => {
      const value = compact(documentInput.value);
      const documentType = value.length > 11 || /[A-Z]/.test(value) ? "cnpj" : "cpf";
      const isValid = documentType === "cpf" ? validCpf(value) : validCnpj(value);
      documentLabel.textContent = isValid ? documentType.toUpperCase() : "CPF ou CNPJ";
      documentInput.value = format ? (documentType === "cpf" ? formatCpf(value) : formatCnpj(value)) : value;
    };
    const request = async (path, body) => {
      if (!csrfToken) {
        const response = await fetch("/api/csrf-token", { credentials: "same-origin" });
        csrfToken = (await response.json()).token;
      }
      const response = await fetch(`/api${path}`, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
        body: JSON.stringify(body),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload?.message || "Não foi possível concluir a solicitação.");
      return payload;
    };
    const showMessage = (text, type = "") => {
      message.textContent = text;
      message.classList.toggle("is-error", type === "error");
      message.classList.toggle("is-success", type === "success");
    };
    const close = () => {
      modal.hidden = true;
      document.body.style.overflow = "";
      login.reset();
      updateDocument();
      showMessage("");
      opener?.focus();
    };
    const open = (button) => {
      opener = button;
      modal.hidden = false;
      document.body.style.overflow = "hidden";
      documentInput.focus();
    };

    clientAccessButtons.forEach((button) => button.addEventListener("click", (event) => {
      event.preventDefault();
      open(button);
    }));
    modal.querySelectorAll("[data-client-modal-close]").forEach((button) => button.addEventListener("click", close));
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !modal.hidden) close();
    });
    documentInput.addEventListener("input", () => updateDocument(true));
    documentInput.addEventListener("blur", () => updateDocument(true));
    login.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        updateDocument(true);
        const data = Object.fromEntries(new FormData(login));
        const normalized = compact(data.document);
        const documentType = normalized.length > 11 || /[A-Z]/.test(normalized) ? "cnpj" : "cpf";
        if (!(documentType === "cpf" ? validCpf(normalized) : validCnpj(normalized))) {
          documentInput.setAttribute("aria-invalid", "true");
          return showMessage("Informe um CPF ou CNPJ válido, com os dígitos verificadores corretos.", "error");
        }
        documentInput.removeAttribute("aria-invalid");
        data.document = normalized;
        const result = await request("/auth/login", data);
        if (!result.user.email_verified) return window.location.assign("/verificar-email");
        if (!result.companies.length) return showMessage("Sua conta não possui uma empresa ativa. Solicite o vínculo ao administrador da empresa.", "error");
        window.location.assign(result.companies.length > 1 ? "/portal/empresas" : "/portal");
      } catch (error) {
        showMessage(error.message, "error");
      }
    });

    return open;
  };

  const setupBackofficeAccess = () => {
    if (!backofficeAccessButtons.length) return null;

    document.body.insertAdjacentHTML("beforeend", `
      <div class="access-modal" data-backoffice-modal hidden>
        <div class="access-modal-backdrop" data-backoffice-modal-close></div>
        <section class="access-modal-card" role="dialog" aria-modal="true" aria-labelledby="backoffice-modal-title">
          <button class="access-modal-close" data-backoffice-modal-close type="button" aria-label="Fechar acesso administrativo">×</button>
          <div class="card-heading">
            <p class="section-kicker">Acesso restrito</p>
            <h2 class="fs-card-title" id="backoffice-modal-title">ADMIN</h2>
            <p class="card-description">Login administrativo destinado exclusivamente a usuários autorizados da Fokus Cloud.</p>
          </div>
          <form data-backoffice-login>
            <label class="form-field"><span class="fs-form-label">E-mail</span><input class="fs-form-control field-size-md" name="email" type="email" autocomplete="username" required></label>
            <label class="form-field"><span class="fs-form-label">Senha</span><input class="fs-form-control field-size-md" name="password" type="password" autocomplete="current-password" required></label>
            <div class="login-actions"><button class="fs-btn fs-btn-danger" type="submit">Continuar</button></div>
          </form>
          <form data-backoffice-mfa hidden aria-hidden="true">
            <div class="card-heading">
              <p class="section-kicker">Código enviado</p>
              <p class="card-description">Informe o código de seis dígitos enviado ao seu e-mail para concluir o acesso.</p>
            </div>
            <label class="form-field"><span class="fs-form-label">Código</span><input class="fs-form-control field-size-sm" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
            <div class="login-actions"><button class="fs-btn fs-btn-danger" type="submit">Validar acesso</button></div>
          </form>
          <p class="form-message" data-backoffice-message role="status"></p>
        </section>
      </div>
    `);

    const modal = document.querySelector("[data-backoffice-modal]");
    const login = modal.querySelector("[data-backoffice-login]");
    const mfa = modal.querySelector("[data-backoffice-mfa]");
    const message = modal.querySelector("[data-backoffice-message]");
    let opener = null;
    let csrfToken = null;

    const request = async (path, body) => {
      if (!csrfToken) {
        const response = await fetch("/api/csrf-token", { credentials: "same-origin" });
        csrfToken = (await response.json()).token;
      }
      const response = await fetch(`/api${path}`, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
        body: JSON.stringify(body),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload?.message || "Não foi possível concluir a solicitação.");
      return payload;
    };

    const showMessage = (text, type = "") => {
      message.textContent = text;
      message.classList.toggle("is-error", type === "error");
      message.classList.toggle("is-success", type === "success");
    };

    const reset = () => {
      login.hidden = false;
      mfa.hidden = true;
      mfa.setAttribute("aria-hidden", "true");
      login.reset();
      mfa.reset();
      showMessage("");
    };

    const close = () => {
      modal.hidden = true;
      document.body.style.overflow = "";
      reset();
      opener?.focus();
    };

    const open = (button) => {
      opener = button;
      modal.hidden = false;
      document.body.style.overflow = "hidden";
      modal.querySelector("input[name=email]").focus();
    };

    backofficeAccessButtons.forEach((button) => button.addEventListener("click", (event) => {
      event.preventDefault();
      open(button);
    }));

    modal.querySelectorAll("[data-backoffice-modal-close]").forEach((button) => button.addEventListener("click", close));
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !modal.hidden) close();
    });

    login.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        const response = await request("/backoffice/auth/login", Object.fromEntries(new FormData(login)));
        if (!response.mfa_required) throw new Error("Não foi possível iniciar a verificação por código.");
        login.hidden = true;
        mfa.hidden = false;
        mfa.setAttribute("aria-hidden", "false");
        showMessage("");
        mfa.elements.code.focus();
      } catch (error) {
        showMessage(error.message, "error");
      }
    });

    mfa.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        await request("/backoffice/auth/verify-mfa", Object.fromEntries(new FormData(mfa)));
        window.location.assign("/backoffice");
      } catch (error) {
        showMessage(error.message, "error");
      }
    });

    return open;
  };

  const syncAccount = () => {
    fetch("/api/auth/me", {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((data) => {
        const name = displayName(data?.user?.name);
        userMenus.forEach((menu) => {
          const label = menu.querySelector("[data-header-user-name]");
          const accountDocument = menu.querySelector("[data-header-user-document]");
          if (label) label.textContent = name || "Conta";
          if (accountDocument) accountDocument.textContent = maskedCpf(data?.user?.cpf);
        });
        toggleVisibility(userMenus, Boolean(name));
        toggleVisibility(clientAccessButtons, !name);
        toggleVisibility(contactButtons, !name);
      })
      .catch(() => {
        toggleVisibility(userMenus, false);
        toggleVisibility(clientAccessButtons, true);
        toggleVisibility(contactButtons, true);
      });
  };

  userMenus.forEach((menu) => {
    const toggle = menu.querySelector("[data-header-user-toggle]");
    const options = menu.querySelector("[data-header-user-options]");
    const logout = menu.querySelector("[data-header-user-logout]");
    toggle?.addEventListener("click", () => {
      const open = options?.hidden;
      userMenus.forEach((other) => {
        const otherOptions = other.querySelector("[data-header-user-options]");
        other.classList.remove("is-open");
        if (otherOptions) otherOptions.hidden = true;
      });
      if (options) options.hidden = !open;
      menu.classList.toggle("is-open", Boolean(open));
      toggle.setAttribute("aria-expanded", String(Boolean(open)));
    });
    logout?.addEventListener("click", async () => {
      logout.disabled = true;
      try {
        const csrfResponse = await fetch("/api/csrf-token", { credentials: "same-origin" });
        const { token } = await csrfResponse.json();
        const response = await fetch("/api/auth/logout", { method: "POST", credentials: "same-origin", headers: { Accept: "application/json", "X-CSRF-TOKEN": token } });
        if (!response.ok) throw new Error("Não foi possível encerrar a sessão.");
        window.location.assign("/");
      } catch (_) {
        logout.disabled = false;
      }
    });
  });

  document.addEventListener("click", (event) => {
    if (event.target.closest("[data-header-user]")) return;
    userMenus.forEach((menu) => {
      const options = menu.querySelector("[data-header-user-options]");
      const toggle = menu.querySelector("[data-header-user-toggle]");
      menu.classList.remove("is-open");
      if (options) options.hidden = true;
      toggle?.setAttribute("aria-expanded", "false");
    });
  });

  const openClientAccess = setupClientAccess();
  const openBackofficeAccess = setupBackofficeAccess();
    const accessTarget = new URLSearchParams(window.location.search).get("acesso");
  if (accessTarget === "cliente" || accessTarget === "administrativo") {
    const opener = accessTarget === "cliente" ? clientAccessButtons[0] : backofficeAccessButtons[0];
    const open = accessTarget === "cliente" ? openClientAccess : openBackofficeAccess;
    if (open && opener) {
      const url = new URL(window.location.href);
      url.searchParams.delete("acesso");
      window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
      window.requestAnimationFrame(() => open(opener));
    }
  }
  if (userMenus.length || clientAccessButtons.length || contactButtons.length) {
    syncAccount();
    window.addEventListener("pageshow", syncAccount);
    window.addEventListener("focus", syncAccount);
    window.addEventListener("fokus:session-changed", syncAccount);
  }
})();
