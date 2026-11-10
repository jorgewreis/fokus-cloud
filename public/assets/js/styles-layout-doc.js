(() => {
  const copyButtons = document.querySelectorAll("[data-copy-target]");

  copyButtons.forEach((button) => {
    button.addEventListener("click", async () => {
      const code = document.querySelector(`#${button.dataset.copyTarget} code`);
      const status = button.closest(".layout-code-block")?.querySelector(".layout-copy-status");
      if (!code || !status) return;
      try {
        await navigator.clipboard.writeText(code.textContent);
        status.textContent = "Código copiado.";
      } catch {
        status.textContent = "Não foi possível copiar automaticamente. Selecione o código manualmente.";
      }
    });
  });

  const componentsOutput = document.querySelector("[data-components-output]");
  const componentsCode = document.querySelector("[data-components-code]");
  if (componentsOutput && componentsCode) {
    const states = {
      success: ["Publicação concluída", "O conteúdo foi publicado com sucesso."],
      warning: ["Revisão necessária", "Revise os campos antes de publicar."],
      danger: ["Publicação não concluída", "Não foi possível publicar agora."],
    };
    const updateComponents = (state) => {
      if (!states[state]) return;
      componentsOutput.className = `fs-alert fs-alert-${state}`;
      componentsOutput.querySelector(".fs-alert-heading").textContent = states[state][0];
      componentsOutput.querySelector(".fs-alert-text").textContent = states[state][1];
      const example = componentsOutput.cloneNode(true);
      example.removeAttribute("data-components-output");
      componentsCode.textContent = example.outerHTML;
      document.querySelectorAll("[data-components-state]").forEach((button) => {
        button.setAttribute("aria-pressed", String(button.dataset.componentsState === state));
      });
    };
    document.querySelectorAll("[data-components-state]").forEach((button) => {
      button.addEventListener("click", () => updateComponents(button.dataset.componentsState));
    });
    updateComponents("success");
  }

  const layoutLab = document.querySelector("[data-layout-lab]");
  if (layoutLab) {
    const stage = layoutLab.querySelector("[data-layout-stage]");
    const content = layoutLab.querySelector("[data-layout-content]");
    const mode = layoutLab.querySelector("[data-layout-mode]");
    const viewport = layoutLab.querySelector("[data-layout-viewport]");
    const density = layoutLab.querySelector("[data-layout-density]");
    const longContent = layoutLab.querySelector("[data-layout-long-content]");
    const explanation = layoutLab.querySelector("[data-layout-explanation]");
    const copy = layoutLab.querySelector("[data-layout-copy]");
    const projectDescription = layoutLab.querySelector("[data-layout-project-description]");
    const code = document.querySelector("#layout-lab-code code");
    const descriptions = {
      grid: "Grid local distribui os projetos em duas dimensões; o container e o stack preservam a leitura da página.",
      flex: "Flex mantém o painel de projetos e a próxima ação em uma relação fluida, pronta para se acomodar ao espaço disponível.",
      stack: "Stack prioriza uma leitura linear: cada decisão aparece depois da anterior, sem depender de margens arbitrárias.",
    };
    const updateLayoutLab = () => {
      const selectedMode = mode.value;
      stage.dataset.viewport = viewport.value;
      stage.dataset.density = density.value;
      stage.dataset.content = longContent.checked ? "long" : "short";
      content.dataset.mode = selectedMode;
      explanation.textContent = descriptions[selectedMode];
      copy.textContent = longContent.checked
        ? "Você tem três decisões importantes para acompanhar hoje. O conteúdo adicional continua legível porque a composição cresce com o fluxo."
        : "Você tem três decisões importantes para acompanhar hoje.";
      projectDescription.textContent = longContent.checked
        ? "Documentação viva para acelerar novas interfaces, reduzir decisões repetidas e tornar cada evolução mais fácil de revisar em equipe."
        : "Documentação viva para acelerar novas interfaces e reduzir decisões repetidas.";
      code.textContent = `<section class="fs-container">
    <div class="fs-row fs-row-cols-1 fs-row-cols-md-3">
        <article class="${selectedMode === "grid" ? "fs-u-grid fs-u-gap-3" : selectedMode === "flex" ? "fs-u-flex fs-u-gap-3" : "fs-stack fs-stack-gap-3"}">
            Projetos em destaque
        </article>
        <aside class="fs-u-max-w-full">Próxima ação</aside>
    </div>
</section>`;
    };
    [mode, viewport, density, longContent].forEach((control) => control.addEventListener("change", updateLayoutLab));
    layoutLab.querySelector("[data-layout-reset]")?.addEventListener("click", () => {
      mode.value = "grid";
      viewport.value = "desktop";
      density.value = "comfortable";
      longContent.checked = false;
      updateLayoutLab();
    });
    updateLayoutLab();
  }

  const formsLab = document.querySelector("[data-forms-lab]");
  if (formsLab) {
    const form = formsLab.querySelector("[data-forms-form]");
    const error = formsLab.querySelector("[data-forms-error]");
    const success = formsLab.querySelector("[data-forms-success]");
    const status = formsLab.querySelector("[data-forms-status]");
    const submit = formsLab.querySelector("[data-forms-submit]");
    const email = formsLab.querySelector("#lab-email");
    const emailError = formsLab.querySelector("#lab-email-error");
    const emailGroup = email.closest(".fs-input-group");
    const range = formsLab.querySelector("#lab-team-size");
    const rangeOutput = formsLab.querySelector("#lab-team-output");
    const formsCode = document.querySelector("#forms-lab-code code");
    let submissionTimer;
    const clearValidation = () => {
      window.clearTimeout(submissionTimer);
      submissionTimer = undefined;
      error.hidden = true;
      success.hidden = true;
      email.classList.remove("is-invalid");
      emailGroup.classList.remove("is-invalid");
      email.removeAttribute("aria-invalid");
      emailError.hidden = true;
      status.textContent = "";
      form.removeAttribute("aria-busy");
      submit.disabled = false;
      submit.textContent = "Criar cadastro";
    };
    const updateRange = () => {
      rangeOutput.value = `${range.value} pessoas`;
      rangeOutput.textContent = `${range.value} pessoas`;
    };
    const updateFormsCode = () => {
      const emailState = email.classList.contains("is-invalid") ? ' aria-invalid="true"' : "";
      const newsState = formsLab.querySelector("#lab-news").checked ? " checked" : "";
      formsCode.textContent = `<fieldset class="fs-form-fieldset">
    <legend>Seu espaço de trabalho</legend>
    <label class="fs-form-label" for="example-email">Email</label>
    <div class="fs-input-group${emailState ? " is-invalid" : ""}">
        <span class="fs-input-group-text" aria-hidden="true">@</span>
        <input class="fs-form-control${emailState ? " is-invalid" : ""}" id="example-email" name="email" type="email" autocomplete="email" required${emailState}${emailState ? ' aria-describedby="example-email-error"' : ""}>
    </div>${emailState ? '\n    <p class="fs-invalid-feedback" id="example-email-error">Informe um email válido.</p>' : ""}
    <label class="fs-form-label" for="example-team-size">Tamanho da equipe</label>
    <input class="fs-form-range" id="example-team-size" name="teamSize" type="range" min="1" max="50" value="${range.value}">
    <div class="fs-switch">
        <input class="fs-switch-input" id="example-news" name="news" type="checkbox"${newsState}>
        <label class="fs-switch-label" for="example-news">Receber novidades e conteúdos</label>
    </div>
</fieldset>`;
    };
    const showError = () => {
      clearValidation();
      error.hidden = false;
      email.classList.add("is-invalid");
      emailGroup.classList.add("is-invalid");
      email.setAttribute("aria-invalid", "true");
      emailError.hidden = false;
      email.focus({ preventScroll: true });
      status.textContent = "Revise os campos destacados.";
      updateFormsCode();
    };
    const fillExample = () => {
      clearValidation();
      formsLab.querySelector("#lab-name").value = "Marina Costa";
      email.value = "marina@fokus.dev";
      formsLab.querySelector("#lab-password").value = "Fokus2026!";
      formsLab.querySelector("#lab-terms").checked = true;
      formsLab.querySelector("#lab-privacy").checked = true;
      formsLab.querySelector("#lab-alerts").checked = true;
      range.value = "18";
      updateRange();
      status.textContent = "Exemplo preenchido. Agora você pode concluir o fluxo.";
      updateFormsCode();
    };
    const resetForm = () => {
      form.reset();
      range.value = "8";
      updateRange();
      clearValidation();
      status.textContent = "Demonstração restaurada.";
      updateFormsCode();
    };
    formsLab.querySelectorAll("[data-forms-demo]").forEach((button) => {
      button.addEventListener("click", () => button.dataset.formsDemo === "error" ? showError() : fillExample());
    });
    formsLab.querySelectorAll("[data-forms-reset]").forEach((button) => button.addEventListener("click", resetForm));
    range.addEventListener("input", updateRange);
    form.addEventListener("input", updateFormsCode);
    form.addEventListener("change", updateFormsCode);
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      if (!form.checkValidity() || !email.value.includes("@")) {
        showError();
        return;
      }
      clearValidation();
      form.setAttribute("aria-busy", "true");
      submit.disabled = true;
      submit.innerHTML = '<span class="fs-spinner fs-spinner-sm" aria-hidden="true"></span> Criando cadastro';
      status.textContent = "Validando informações…";
      submissionTimer = window.setTimeout(() => {
        submissionTimer = undefined;
        form.removeAttribute("aria-busy");
        success.hidden = false;
        submit.disabled = false;
        submit.textContent = "Cadastro concluído";
        status.textContent = "Cadastro concluído com sucesso.";
      }, 650);
    });
    updateRange();
    updateFormsCode();
  }

  const helpersStage = document.querySelector("[data-helpers-stage]");
  const helpersButtons = [...document.querySelectorAll("[data-helpers-gap]")];
  if (helpersStage && helpersButtons.length) {
    helpersButtons.forEach((button) => button.addEventListener("click", () => {
      const gap = button.dataset.helpersGap;
      if (!["1", "4"].includes(gap)) return;
      helpersStage.classList.remove("fs-stack-gap-1", "fs-stack-gap-3", "fs-stack-gap-4");
      helpersStage.classList.add(`fs-stack-gap-${gap}`);
      helpersButtons.forEach((control) => control.setAttribute("aria-pressed", String(control === button)));
      const code = helpersStage.querySelector("code");
      if (code) code.textContent = `<div class="fs-stack fs-stack-gap-${gap}">\n    <div class="fs-cluster fs-cluster-gap-2">...</div>\n</div>`;
    }));
  }

  const utilitiesCard = document.querySelector("[data-utilities-card]");
  const utilitiesButtons = [...document.querySelectorAll("[data-utilities-state]")];
  if (utilitiesCard && utilitiesButtons.length) {
    const states = { success: "Concluído", warning: "Em atenção", danger: "Com erro" };
    const code = utilitiesCard.closest(".styles-lab-stage")?.querySelector("code");
    const updateCode = () => { if (code) code.textContent = utilitiesCard.outerHTML; };
    utilitiesButtons.forEach((button) => button.addEventListener("click", () => {
      const state = button.dataset.utilitiesState;
      if (!Object.hasOwn(states, state)) return;
      for (const variant of Object.keys(states)) {
        utilitiesCard.classList.remove(`fs-u-bg-${variant}-subtle`, `fs-u-text-${variant}-emphasis`);
      }
      utilitiesCard.classList.add(`fs-u-bg-${state}-subtle`, `fs-u-text-${state}-emphasis`);
      const status = utilitiesCard.querySelector("[data-utilities-status]");
      status.textContent = states[state];
      status.classList.remove("fs-badge-soft-primary", ...Object.keys(states).map(variant => `fs-badge-soft-${variant}`));
      status.classList.add(`fs-badge-soft-${state}`);
      utilitiesButtons.forEach((control) => control.setAttribute("aria-pressed", String(control === button)));
      updateCode();
    }));
    updateCode();
  }

  const links = [...document.querySelectorAll(".styles-sidebar a[href^='#']")];
  const sections = links.map((link) => document.querySelector(link.getAttribute("href"))).filter(Boolean);
  const setActive = (id) => links.forEach((link) => link.classList.toggle("is-active", link.getAttribute("href") === `#${id}`));
  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver((entries) => {
      const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (visible) setActive(visible.target.id);
    }, { rootMargin: "-18% 0px -62% 0px", threshold: [0, 0.2, 0.6] });
    sections.forEach((section) => observer.observe(section));
  }
})();
