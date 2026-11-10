<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
  <meta name="robots" content="noindex, nofollow" />
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#1b1028" />
  <title>Fokus Law | Fokus Cloud</title>
  <link rel="stylesheet" href="{{ \App\Support\FokusLawAssets::url('assets/css/shared/fokus.css') }}" />
  <link rel="stylesheet" href="{{ \App\Support\FokusLawAssets::url('marketing/products/fokus-law.css') }}" />
  <link rel="stylesheet" href="{{ \App\Support\FokusLawAssets::url('portal/assets/fokus-law-shell.css') }}" />
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
      <div class="law-nav-panel">
        <header class="law-system-context">
          <a class="law-system-brand" href="/portal" aria-label="Fokus Cloud Law, voltar ao portal"><span>FOKUS CLOUD</span><b>LAW</b></a>
          <p id="subscription-label" class="law-subscription-label">Fokus Law</p>
          <div class="law-company-context">
            <span class="law-context-caption">Sistema ativo</span>
            <button class="law-company-switch" id="company-switch" type="button" aria-haspopup="listbox" aria-expanded="false" hidden>
              <span id="company-name">—</span><img class="law-chevron" src="/backoffice/assets/icons/Arrow-Button-Down--Streamline-Ultimate-Bold.png" alt="" />
            </button>
            <strong class="law-company-name" id="company-name-static">—</strong>
            <div class="law-company-options" id="company-options" role="listbox" aria-label="Empresas disponíveis" hidden></div>
          </div>
          <div class="law-unit-context" id="unit-context" hidden>
            <label class="law-context-caption" for="law-unit-select">SETOR</label>
            <select class="law-unit-select fs-form-select" data-fs="select" data-fs-select-theme="dark" id="law-unit-select" aria-label="Setor ativo"></select>
          </div>
        </header>

        <div class="law-nav-heading">
          <h1 id="section-title">Visão geral</h1>
          <img class="law-nav-heading-icon" id="section-icon" src="/backoffice/assets/icons/Layout-Dashboard--Streamline-Ultimate-Colors.png" alt="" />
        </div>
        <nav class="law-page-navigation" id="page-navigation" aria-label="Páginas e funcionalidades">
          <div id="page-items"></div>
        </nav>

        <footer class="law-user-footer">
          <button class="law-account-toggle" id="account-toggle" type="button" aria-expanded="false" aria-controls="account-panel">
            <span class="law-user-avatar fs-avatar fs-avatar-sm" id="user-avatar" aria-hidden="true">—</span>
            <span class="law-user-meta"><strong id="user-name">—</strong><span id="user-email">—</span></span>
            <img class="law-chevron law-account-chevron" src="/backoffice/assets/icons/Arrow-Button-Down--Streamline-Ultimate-Bold.png" alt="" />
          </button>
          <div class="law-account-panel" id="account-panel" hidden>
            <a class="law-nav-item" href="/portal/fokus-law/perfil">Meu perfil</a>
            <button class="law-nav-item" id="account-preferences" type="button">Preferências</button>
            <button class="law-nav-item" id="account-settings" type="button" hidden>Configurações</button>
            <button class="law-nav-item law-account-logout" id="logout-button" type="button">Sair</button>
          </div>
        </footer>
      </div>
    </aside>

    <main class="law-main" id="law-workspace">
      <section class="law-support-notice" id="support-notice" role="status" hidden>
        <div><strong>Modo de suporte ativo</strong><span id="support-context"></span></div>
        <button class="fs-btn fs-btn-outline-primary" id="support-exit" type="button">Encerrar acesso de suporte</button>
      </section>
      <header class="law-topbar" aria-label="Barra de ferramentas">
        <button class="law-mobile-menu fs-btn fs-btn-outline-primary fs-btn-icon" id="mobile-menu-button" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="law-sidebar">
          <img src="/backoffice/assets/icons/Navigation-Menu-3--Streamline-Ultimate-Bold.png" alt="" />
        </button>
        <div class="law-search-wrap">
          <img src="/backoffice/assets/icons/Search-Bar--Streamline-Ultimate.png" alt="" />
          <label class="visually-hidden" for="global-search">Pesquisar no Fokus Law</label>
          <input class="fs-form-control" id="global-search" type="search" placeholder="Pesquisar processos, partes e contatos" autocomplete="off" aria-controls="search-state" aria-expanded="false" />
          <kbd aria-hidden="true">Ctrl K</kbd>
          <div class="law-search-state" id="search-state" role="region" aria-label="Resultados da busca" aria-live="polite" hidden></div>
        </div>
        <div class="law-toolbar-actions">
          <div class="law-popover-anchor">
            <button class="law-toolbar-button fs-btn fs-btn-outline-primary fs-btn-icon" id="notifications-button" type="button" aria-label="Notificações" aria-expanded="false" aria-controls="notifications-panel">
              <img src="/backoffice/assets/icons/Alarm-Bell--Streamline-Ultimate-Regular.png" alt="" />
            </button>
            <section class="law-popover law-notifications-panel" id="notifications-panel" aria-labelledby="notifications-title" hidden>
              <h2 id="notifications-title">Notificações</h2>
              <p>Nenhuma notificação por enquanto.</p>
              <span>Os avisos dos módulos aparecerão aqui.</span>
            </section>
          </div>

        </div>
      </header>

      <section class="law-content" id="content-region" tabindex="-1" aria-live="polite"></section>
      <footer class="law-workspace-footer"><span>Fokus Cloud Law</span><a href="/produtos/fokus-law">Ajuda sobre o produto</a></footer>
    </main>
  </div>
  <template id="law-profile-template">
    @include('portal.partials.fokus-law-profile')
  </template>
<script src="{{ \App\Support\FokusLawAssets::url('assets/js/fokus.min.js') }}"></script>
<script src="{{ \App\Support\FokusLawAssets::url('assets/js/api-client.js') }}"></script>
<script src="{{ \App\Support\FokusLawAssets::url('assets/js/brazilian-documents.js') }}"></script>
<script src="{{ \App\Support\FokusLawAssets::url('portal/assets/fokus-law-select.js') }}"></script>
<script src="{{ \App\Support\FokusLawAssets::url('portal/assets/fokus-law-access.js') }}" defer></script>
<script src="{{ \App\Support\FokusLawAssets::url('portal/assets/fokus-law-bank-catalog.js') }}" defer></script>
<script src="{{ \App\Support\FokusLawAssets::url('portal/assets/fokus-law-contacts.js') }}" defer></script>
<script src="{{ \App\Support\FokusLawAssets::url('portal/assets/law-record-ui.js') }}" defer></script>
<script src="{{ \App\Support\FokusLawAssets::url('portal/assets/fokus-law-processes.js') }}" defer></script>
<script src="{{ \App\Support\FokusLawAssets::url('portal/assets/fokus-law-shell.js') }}" defer></script>
<script src="{{ \App\Support\FokusLawAssets::url('assets/js/portal-profile.js') }}" defer></script>
</body>
</html>
