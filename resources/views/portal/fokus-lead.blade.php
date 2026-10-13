<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Fokus Lead | Área de acesso</title>
    <link rel="stylesheet" href="/assets/css/shared/fokus.css?v=20261009-select-menu-options-v1">
    <style>
        @font-face { font-family: 'Bebas Neue'; src: url('/assets/fonts/google/bebas-neue-original.woff2') format('woff2'); font-weight: 400; font-display: swap; }
        :root { color-scheme: light; --lead-navy: #102a43; --lead-terracotta: #c96f4a; --lead-cream: #f5efe7; --lead-muted: #536879; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: var(--lead-cream); color: var(--lead-navy); font: 400 16px/1.6 system-ui, sans-serif; }
        .lead-access-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1.4rem clamp(1.25rem, 6vw, 5rem); background: var(--lead-navy); color: #fff; }
        .lead-access-brand { color: #fff; font-size: .82rem; font-weight: 700; letter-spacing: .16em; text-decoration: none; }
        .lead-access-brand span { color: #e9a27f; }
        .lead-access-header a:last-child { color: #fff; font-size: .86rem; text-underline-offset: .2em; }
        .lead-access-main { width: min(100% - 2.5rem, 920px); margin: clamp(3rem, 9vh, 6rem) auto; }
        .lead-access-kicker { margin: 0 0 .65rem; color: var(--lead-terracotta); font-size: .72rem; font-weight: 700; letter-spacing: .18em; }
        .lead-access-main h1, .lead-access-panel h2 { margin: 0; font-family: 'Bebas Neue', 'Arial Narrow', sans-serif; font-weight: 400; line-height: .95; text-transform: uppercase; }
        .lead-access-main h1 { font-size: clamp(3.5rem, 8vw, 6rem); }
        .lead-access-subtitle { max-width: 650px; margin: 1rem 0 2.5rem; color: var(--lead-muted); }
        .lead-access-panel { padding: clamp(1.5rem, 4vw, 3rem); border-top: 4px solid var(--lead-terracotta); background: #fff; box-shadow: 0 18px 50px rgba(16, 42, 67, .1); }
        .lead-access-panel h2 { max-width: 680px; font-size: clamp(2.2rem, 5vw, 3.4rem); }
        .lead-access-panel p:last-of-type { max-width: 680px; color: var(--lead-muted); }
        .lead-access-panel a { display: inline-flex; margin-top: .7rem; padding: .75rem 1rem; background: var(--lead-navy); color: #fff; text-decoration: none; }
        @media (max-width: 520px) { .lead-access-header { align-items: flex-start; } .lead-access-header a:last-child { max-width: 8rem; text-align: right; } }
    </style>
</head>
<body>
    <header class="lead-access-header">
        <a class="lead-access-brand" href="/">FOKUS CLOUD <span>LEAD</span></a>
        <a href="/portal">Voltar à visão geral</a>
    </header>
    <main class="lead-access-main">
        <p class="lead-access-kicker">ÁREA DE ACESSO</p>
        <h1>Fokus Lead</h1>
        <p class="lead-access-subtitle">Vínculo confirmado para {{ $companyName }}.</p>
        <section class="lead-access-panel" aria-labelledby="lead-access-title">
            <p class="lead-access-kicker">ESPAÇO DE TRABALHO</p>
            <h2 id="lead-access-title">Uma área para acompanhar a rotina comercial.</h2>
            <p>Os módulos do Fokus Lead ainda estão em desenvolvimento. Esta área reunirá o acompanhamento de clientes, imóveis e oportunidades quando os recursos forem disponibilizados.</p>
            <a href="/portal">Voltar à visão geral</a>
        </section>
    </main>
</body>
</html>
