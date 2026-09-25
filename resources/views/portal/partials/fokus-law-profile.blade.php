<div class="law-profile-page">
  <header class="law-profile-heading">
    <p class="law-page-eyebrow">CONTA E ACESSO</p>
    <h2>Meu perfil</h2>
    <p class="law-page-lede">Revise seus dados pessoais e mantenha as credenciais de acesso atualizadas.</p>
  </header>

  <div id="profile-loading" class="law-profile-loading" role="status" aria-live="polite">Carregando seus dados…</div>
  <div id="profile-support-notice" class="law-support-notice" role="status" hidden>
    <div><strong>Acesso de suporte ativo</strong><span>Seu perfil está somente para leitura. Encerre o acesso de suporte pela faixa no topo para editar seus dados.</span></div>
  </div>

  <div id="profile-sections" class="law-profile-sections" hidden>
    <section class="law-profile-card" aria-labelledby="personal-title">
      <div class="law-profile-card-heading">
        <div><p class="law-profile-eyebrow">Informações da conta</p><h3 id="personal-title">Dados pessoais</h3></div>
        <span class="law-profile-badge">Titular</span>
      </div>
      <form id="personal-form" class="law-profile-form" novalidate>
        <label class="law-profile-field law-profile-field-wide"><span>Nome completo</span><input class="fs-form-control fs-width-700" id="profile-name" name="name" autocomplete="name" maxlength="255" required /></label>
        <label class="law-profile-field"><span>Telefone <small>opcional</small></span><input class="fs-form-control fs-width-400" id="profile-phone" name="phone" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="16" placeholder="(00) 00000-0000" aria-describedby="phone-help" /><small id="phone-help">Número brasileiro com DDD. Ainda não verificado; usado apenas como contato.</small></label>
        <label class="law-profile-field"><span>CPF</span><input class="fs-form-control fs-width-400" id="profile-cpf" type="text" inputmode="numeric" readonly aria-describedby="cpf-help" /><small id="cpf-help">Por segurança, a alteração do CPF é feita pelo suporte Fokus Cloud.</small></label>
        <div class="law-profile-form-footer"><p class="law-profile-feedback" id="personal-status" role="status" aria-live="polite"></p><button class="law-profile-button fs-btn fs-btn-outline-primary" type="submit">Salvar dados pessoais</button></div>
      </form>
    </section>

    <section class="law-profile-card" aria-labelledby="email-title">
      <div class="law-profile-card-heading">
        <div><p class="law-profile-eyebrow">Confirmação necessária</p><h3 id="email-title">E-mail</h3></div>
        <span id="email-verified" class="law-profile-badge"></span>
      </div>
      <p class="law-profile-current">E-mail atual <strong id="current-email">—</strong></p>
      <form id="email-form" class="law-profile-form" novalidate>
        <label class="law-profile-field law-profile-field-wide"><span>Novo endereço de e-mail</span><input class="fs-form-control fs-width-700" name="email" type="email" autocomplete="email" maxlength="255" required /></label>
        <label class="law-profile-field"><span>Senha atual</span><input class="fs-form-control fs-width-400" name="current_password" type="password" autocomplete="current-password" required /></label>
        <div class="law-profile-form-footer"><p class="law-profile-feedback" id="email-status" role="status" aria-live="polite"></p><button class="law-profile-button fs-btn fs-btn-outline-primary" type="submit">Enviar confirmação</button></div>
      </form>
      <p class="law-profile-footnote">O endereço atual continua ativo até você confirmar o link enviado ao novo e-mail. Também avisaremos o endereço atual.</p>
    </section>

    <section class="law-profile-card" aria-labelledby="security-title">
      <div class="law-profile-card-heading">
        <div><p class="law-profile-eyebrow">Proteção do acesso</p><h3 id="security-title">Segurança</h3></div>
      </div>
      <form id="security-form" class="law-profile-form" novalidate>
        <label class="law-profile-field"><span>Senha atual</span><input class="fs-form-control fs-width-400" name="current_password" type="password" autocomplete="current-password" required /></label>
        <div class="law-profile-password-row">
          <label class="law-profile-field"><span>Nova senha</span><input class="fs-form-control fs-width-400" name="password" type="password" autocomplete="new-password" minlength="12" required aria-describedby="password-help" /></label>
          <label class="law-profile-field"><span>Confirme a nova senha</span><input class="fs-form-control fs-width-400" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required /></label>
        </div>
        <small id="password-help">Use pelo menos 12 caracteres. Senhas comuns ou encontradas em vazamentos serão recusadas.</small>
        <div class="law-profile-form-footer"><p class="law-profile-feedback" id="security-status" role="status" aria-live="polite"></p><button class="law-profile-button fs-btn fs-btn-outline-primary" type="submit">Alterar senha</button></div>
      </form>
      <p class="law-profile-footnote">A alteração encerra suas outras sessões e mantém este acesso ativo.</p>
    </section>
  </div>
</div>
