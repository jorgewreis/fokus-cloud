<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
  <meta name="robots" content="noindex, nofollow" />
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#1b1028" />
  <title>Fokus Law | Fokus Cloud</title>
  <link rel="stylesheet" href="/assets/css/shared/fokus.css?v=20260923-support-mode-v1" />
  <link rel="stylesheet" href="/marketing/products/fokus-law.css?v=20260924-law-access-choice-v1" />
  <link rel="stylesheet" href="/portal/assets/fokus-law-shell.css?v=20260925-law-subscription-v1" />
</head>
<body class="fokus-law-shell-page">
  <a class="law-shell-skip" href="#law-workspace">Pular para o conteúdo principal</a>
  <div id="shell-loading" class="shell-loading" role="status" aria-live="polite">
    <span class="fs-spinner" aria-hidden="true"></span>
    <span>Carregando o Fokus Law…</span>
  </div>

  <div class="law-shell" id="law-shell" data-initial-page="{{ $initialPage ?? 'overview' }}" hidden>
    <div class="law-mobile-scrim" id="mobile-scrim" hidden></div>
    <aside class="law-sidebar" id="law-sidebar" aria-label="Navegação do Fokus Law">
      <nav class="law-rail" aria-label="Grupos e módulos">
        <a class="law-mark" href="/portal" aria-label="Fokus Cloud, voltar ao portal">
          <span aria-hidden="true">F</span>
        </a>
        <div class="law-rail-items" id="rail-items"></div>
        <div class="law-rail-spacer"></div>
        <a class="law-rail-link" href="/produtos/fokus-law" aria-label="Sobre o Fokus Law" title="Sobre o Fokus Law">
          <img src="/backoffice/assets/icons/Programming-User-Chat--Streamline-Ultimate.png" alt="" />
        </a>
      </nav>

      <div class="law-nav-panel">
        <header class="law-system-context">
          <a class="law-system-brand" href="/portal" aria-label="Fokus Cloud Law, voltar ao portal"><span>FOKUS CLOUD</span><b>LAW</b></a>
          <p id="subscription-label" class="law-subscription-label">Fokus Law</p>
          <div class="law-company-context">
            <span class="law-context-caption">Sistema ativo</span>
            <button class="law-company-switch" id="company-switch" type="button" aria-haspopup="listbox" aria-expanded="false" hidden>
              <span id="company-name">—</span><span aria-hidden="true">⌄</span>
            </button>
            <strong class="law-company-name" id="company-name-static">—</strong>
            <div class="law-company-options" id="company-options" role="listbox" aria-label="Empresas disponíveis" hidden></div>
          </div>
          <div class="law-unit-context" id="unit-context" hidden>
            <label class="law-context-caption" for="law-unit-select">SETOR</label>
            <select class="law-unit-select" id="law-unit-select" aria-label="Setor ativo"></select>
          </div>
        </header>

        <div class="law-nav-heading">
          <h1 id="section-title">Visão geral</h1>
          <img class="law-nav-heading-icon" id="section-icon" src="/backoffice/assets/icons/Layout-Dashboard--Streamline-Ultimate.png" alt="" />
        </div>
        <nav class="law-page-navigation" id="page-navigation" aria-label="Páginas e funcionalidades">
          <div id="page-items"></div>
        </nav>

        <footer class="law-user-footer">
          <div class="law-user-avatar" id="user-avatar" aria-hidden="true">—</div>
          <div class="law-user-meta"><strong id="user-name">—</strong><span id="user-email">—</span></div>
          <a class="law-profile-link" href="/portal/fokus-law/perfil" aria-label="Meu perfil" title="Meu perfil">
            <img src="/backoffice/assets/icons/Settings-User--Streamline-Ultimate.png" alt="" />
          </a>
        </footer>
      </div>
    </aside>

    <main class="law-main" id="law-workspace">
      <section class="law-support-notice" id="support-notice" role="status" hidden>
        <div><strong>Modo de suporte ativo</strong><span id="support-context"></span></div>
        <button class="fs-btn fs-btn-secondary" id="support-exit" type="button">Encerrar acesso de suporte</button>
      </section>
      <header class="law-topbar" aria-label="Barra de ferramentas">
        <button class="law-mobile-menu fs-btn fs-btn-secondary fs-btn-icon" id="mobile-menu-button" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="law-sidebar">
          <span aria-hidden="true">☰</span>
        </button>
        <div class="law-search-wrap">
          <img src="/backoffice/assets/icons/Search-Bar--Streamline-Ultimate.png" alt="" />
          <label class="visually-hidden" for="global-search">Pesquisar no Fokus Law</label>
          <input class="fs-form-control" id="global-search" type="search" placeholder="Pesquisar no Fokus Law" autocomplete="off" aria-describedby="search-hint" />
          <kbd aria-hidden="true">Ctrl K</kbd>
          <div class="law-search-state" id="search-state" role="status" hidden>A busca global ficará disponível quando os módulos forem habilitados.</div>
        </div>
        <div class="law-toolbar-actions">
          <div class="law-popover-anchor">
            <button class="law-toolbar-button fs-btn fs-btn-secondary fs-btn-icon" id="notifications-button" type="button" aria-label="Notificações" aria-expanded="false" aria-controls="notifications-panel">
              <img src="/backoffice/assets/icons/Alarm-Bell--Streamline-Ultimate-Regular.png" alt="" />
            </button>
            <section class="law-popover law-notifications-panel" id="notifications-panel" aria-labelledby="notifications-title" hidden>
              <h2 id="notifications-title">Notificações</h2>
              <p>Nenhuma notificação por enquanto.</p>
              <span>Os avisos dos módulos aparecerão aqui.</span>
            </section>
          </div>
          <button class="law-exit-button fs-btn fs-btn-outline-primary" id="logout-button" type="button">
            <img src="/backoffice/assets/icons/User-Logout--Streamline-Ultimate-Regular.png" alt="" /><span>Sair</span>
          </button>
        </div>
      </header>

      <section class="law-content" id="content-region" tabindex="-1" aria-live="polite"></section>
      <footer class="law-workspace-footer"><span>Fokus Cloud Law</span><a href="/produtos/fokus-law">Ajuda sobre o produto</a></footer>
    </main>
  </div>
  <template id="law-profile-template">
    @include('portal.partials.fokus-law-profile')
  </template>
  <script src="/assets/js/fokus.min.js?v=20260916-fokus-styles-2.7.0"></script>
  <script src="/assets/js/api-client.js"></script>
  <script src="/portal/assets/fokus-law-shell.js?v=20260925-law-subscription-v1" defer></script>
  <script src="/assets/js/portal-profile.js?v=20260925-profile-v4" defer></script>
</body>
</html>
