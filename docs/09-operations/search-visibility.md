# Visibilidade em buscadores e respostas de IA

## Endereços oficiais

- Fokus Cloud: `https://www.fokuscloud.com.br/`
- Fokus Styles: `https://styles.fokuscloud.com.br/`
- Cada domínio publica seu próprio `/sitemap.xml` e anuncia esse endereço em `/robots.txt`.
- O endereço antigo `/sitemap-styles.xml` redireciona para o sitemap do Styles.

## Manutenção

`resources/seo/pages.json` é o inventário das seis páginas públicas. Ele alimenta os sitemaps e os títulos, descrições, URLs canônicas e dados estruturados publicados no HTML. Ao criar uma página pública, inclua-a nesse inventário, crie links navegáveis para ela e execute `npm run build`. Não inclua páginas privadas, rotas inexistentes ou produtos ainda não publicados.

`npm run seo:check` verifica se o HTML está sincronizado com o inventário e roda no GitHub Actions. `php artisan test --filter SearchDiscoveryTest` verifica os sitemaps por domínio, os redirecionamentos, a ausência de cookies nos arquivos de descoberta e o `noindex` das áreas de conta. `node tools/check-public-seo.mjs` confere a publicação real após o deploy.

Os endpoints de descoberta são servidos pelo Laravel porque os dois domínios compartilham o diretório público. Não recrie arquivos físicos `public/robots.txt`, `public/sitemap.xml` ou `public/sitemap-styles.xml`: eles sobreporiam as respostas específicas de cada domínio. No Nginx, esses endereços precisam chegar ao front controller; não podem ser interceptados por uma regra de arquivos estáticos que retorne 404. URLs físicas alternativas das páginas HTML têm a mesma canonical da página principal.

Cada URL do sitemap inclui `lastmod` com a data do commit que alterou o HTML da página. `npm run seo:sync` grava essas datas em `resources/seo/lastmod.json` a partir de `git log` num clone com histórico completo, inclusive se o HTML de trabalho foi alterado depois. Um clone raso ou um arquivo sem `.git` conserva a data já versionada, porque o limite do clone atribuiria todo arquivo ao commit da ponta. A data de extração ou de deploy não entra no sitemap. `application-quality` e a QA do Styles pedem `fetch-depth: 0` para essa verificação. O checkout de validação do deploy permanece raso porque o workflow publicado é um contrato exato. O sinal publicado é `Content-Signal: search=yes,ai-input=yes,ai-train=yes`. `resources/seo/robots.txt` não publica `Disallow: /` para GPTBot, Google-Extended, Amazonbot, ClaudeBot nem para os demais robôs de IA. O grupo `User-agent: *` continua fechando `/api/`, `/backoffice/`, `/portal/`, `/cadastro` e `/acesso`, e o controlador acrescenta a linha `Sitemap`. `noindex` não substitui autenticação nem controle de acesso.

O `robots.txt` visto em produção começa com o bloco `# BEGIN Cloudflare Managed content`, inserido pela Cloudflare antes do arquivo do repositório. Esse bloco não está no Git. Ele só deixa de ser publicado quando, no domínio, em **Security** → **Settings** → filtro **Bot traffic** → **AI Crawler and Agent access policies**, as políticas Search, Agent e Training deixam de estar em Block, Block on pages with ads ou Disallow, ou quando **Enable Bot Preference Sync** é desligado. Com o sync ligado e alguma dessas políticas restritiva, a Cloudflare continua negando GPTBot, ClaudeBot e os demais robôs do bloco gerenciado. O mesmo ajuste vale para `styles.fokuscloud.com.br` se o bloco aparecer lá. Não criar `public/robots.txt`.

Pedidos HTTP de `fokuscloud.com.br`, `www.fokuscloud.com.br` e `styles.fokuscloud.com.br` devem responder 301 para o mesmo caminho em `https://www.fokuscloud.com.br` ou `https://styles.fokuscloud.com.br`. Um diretório sem barra, como `/styles`, responde 301 para a URL HTTPS canônica já com a barra. As regras estão em `public/.htaccess` e em `deploy/cloudpanel-legacy-redirects.conf`. Em 23/11/2026 a borda Cloudflare ainda responde `http://fokuscloud.com.br/` com 301 para `https://fokuscloud.com.br/` (preserva o host, então o `www` é um segundo salto feito pela aplicação) e o backend HTTP emite `Location: http://www.fokuscloud.com.br/styles/`. Para o apex HTTP ir a `https://www` em um único salto, criar uma Redirect Rule e desligar Always Use HTTPS somente depois que a regra também cobrir o HTTP de `www` e de `styles`:

- SSL/TLS → Overview → encryption mode: **Full (strict)** quando o certificado da origem for válido. **Flexible** deixa a origem em HTTP e faz o redirect de diretório anunciar `http://`.
- Rules → Redirect Rules → Create rule. Nome: `Canonical HTTPS host`. Se `(http.host eq "fokuscloud.com.br") or (http.host eq "www.fokuscloud.com.br" and not ssl)`, redirecionar com URL dinâmica `concat("https://www.fokuscloud.com.br", http.request.uri.path)`, status 301, query string preservada. Segunda regra: se `(http.host eq "styles.fokuscloud.com.br" and not ssl)`, URL `concat("https://styles.fokuscloud.com.br", http.request.uri.path)`, status 301, query string preservada.
- SSL/TLS → Edge Certificates → Always Use HTTPS: **Off** depois que essas regras existirem. Ligado, ele redireciona o HTTP antes da Redirect Rule e o apex continua em dois saltos (`http://fokuscloud.com.br` → `https://fokuscloud.com.br` → `https://www`). Sem a Redirect Rule, deixe Always Use HTTPS **On**: ele já evita que `http://www` chegue à origem.
- Aplicar no vhost o `absolute_redirect off`, o `proxy_redirect` e o `location = /styles` de `deploy/cloudpanel-legacy-redirects.conf`. O `Location: http://` de `/styles` nasce no backend da porta 8080 (a resposta traz `x-page-speed`), não no painel da Cloudflare.

## Diagnóstico inicial em 10/09/2026

- Os sitemaps anteriores listavam somente as duas homes. O `/sitemap.xml` do Styles servia a URL do Cloud.
- Layout, Forms e as páginas de produtos não tinham canonical e descrição. Layout e Forms já tinham conteúdo HTML indexável.
- O domínio Cloud sem `www` respondia 200 em vez de redirecionar para a canonical com `www`.
- O robots.txt duplicava o bloco gerenciado pelo Cloudflare. Parte das páginas de conta não declarava `noindex`.
- O painel Cloudflare confirmou 42 acessos permitidos do Googlebot e zero malsucedidos nas últimas 24 horas; BingBot tinha 12 permitidos e 2 malsucedidos. OAI-SearchBot e PerplexityBot estavam sem bloqueio individual. Não é necessário desativar proteções gerais para resolver os problemas de conteúdo e descoberta.
- O Search Console confirmou que a home do Styles já estava indexada. Seus relatórios estavam em processamento e a propriedade Styles não tinha sitemap enviado. Um relatório ainda vazio não equivale a ausência de indexação.
- Um cliente Python recebeu 403, enquanto curl e o navegador receberam 200. Essa diferença não prova bloqueio de todos os buscadores.

## Acompanhamento após a publicação

Enviar os dois sitemaps nas propriedades correspondentes do Google Search Console e do Bing Webmaster Tools. Inspecionar as URLs canônicas e solicitar rastreamento após mudanças relevantes. Conferir a canonical escolhida pelos buscadores, erros de rastreamento, páginas descobertas e páginas efetivamente indexadas. Não repetir solicitações diariamente: o processamento depende de cada serviço.

Comparar cliques, impressões, consultas e posição por página em períodos equivalentes de 28 dias, quando houver dados suficientes. Separar consultas pela marca de buscas sobre framework CSS, layouts e formulários. Consultar Core Web Vitals quando existirem dados de campo; revisão responsiva não é uma medição de LCP, INP ou CLS.

Continuar a documentação com exemplos próprios, completos e verificáveis; publicar novas áreas apenas quando estiverem prontas. Manter a identificação e os links oficiais coerentes no site, GitHub e npm. Isso melhora a informação disponível para leitores e respostas de IA sem inventar reputação, avaliações ou funcionalidades.

Analytics mede visitas, mas não cadastra páginas no índice. SEO técnico, rastreamento permitido e dados estruturados não garantem indexação, citações de IA ou posições específicas.

## Referências oficiais

- [Requisitos técnicos do Google](https://developers.google.com/search/docs/essentials/technical)
- [Sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap)
- [URLs canônicas](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)
- [Busca generativa e SEO](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide)
- [Robots.txt gerenciado pelo Cloudflare](https://developers.cloudflare.com/bots/additional-configurations/managed-robots-txt/)
