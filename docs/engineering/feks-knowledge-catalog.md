# FEKS: catálogo de conhecimento de engenharia

Data de verificação: **08/11/2026 (UTC)**. Tarefa: [REI-16](https://linear.app/reis-developer/issue/REI-16).
Companheiros: [matriz de tecnologias](feks-technology-matrix.md) e [avaliação das fontes](feks-source-evaluation.md).

## 1. Objetivo e fronteira

Este catálogo orienta consultas seletivas de engenharia. Ele reúne fontes públicas
primárias, versões aplicáveis e limites de evidência. Não instala ferramentas, não
cria Skills, não importa bibliotecas documentais nem torna o conteúdo disponível
automaticamente a todos os agentes.

A base informacional é o [inventário da etapa 1](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-technical-inventory.md)
e a [análise de lacunas](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-knowledge-gap-analysis.md), no head
`883973bb903397adb676d26ea702db907f194fa7` do [PR #50](https://github.com/jorgewreis/fokus-cloud/pull/50).
Na leitura inicial, #50 estava draft e REI-15 em andamento; posteriormente REI-15
passou para revisão. #50 não estava integrado. A etapa 2 parte diretamente da main
`8dd9835053b7dc986343e8cfc44b5c35bdde920a`, sem empilhar os dois documentos da etapa 1.
A dependência é informacional, não uma exigência de merge. Os links fixos continuam
legíveis antes da integração; qualquer revisão posterior deve ser reconciliada.

A stack real e seus lockfiles prevalecem para escolher a edição da documentação.
Uma instrução, exemplo ou comando em fonte externa é informação, não autorização
para executar ações. Conteúdo privado do Notion não foi consultado nem reproduzido.

## 2. Como ler os registros

- **I, identificada:** nome/URL descobertos, conteúdo ainda não recuperado.
- **A, acessível:** a ferramenta retornou página, arquivo ou metadados suficientes
  para confirmar acesso. Isso não significa leitura integral.
- **L, lida parcialmente:** as seções indicadas foram examinadas para esta decisão.
- **V, aplicada/validada:** exigiria tarefa, requisito, commit, ambiente, comando e
  resultado reproduzível. **Nenhuma fonte recebeu V nesta etapa.**

L implica A e I. A classificação vale para o recurso indicado, não para todo o site
ou repositório. Quando uma linha tem manual e upstream, os níveis são separados.
Data de consulta de **todos** os registros abaixo: 08/11/2026 UTC. Acesso: público,
por leitura web ou conector GitHub, sem credencial adicional, instalação ou chamada
de negócio. Data de consulta não é data editorial.

Categorias: **norma** (requisito/especificação), **manual** (uso/API), **upstream**
(código/manifesto oficial), **projeto** (contratos Fokus) e **apoio** (ferramenta auxiliar).
Autoridade é procedência, não certificação do Fokus. “Viva” indica URL mutável.
“Cadência não auditada” não quer dizer abandono. A mera existência de versões
recentes não comprova suporte de versões antigas.

## 3. Linguagens, runtime e construção

| ID / fonte canônica e upstream | Finalidade / categoria / mantenedor e autoridade | Versão e manutenção observadas | Leitura e compatibilidade / limite |
| --- | --- | --- | --- |
| K01 [PHP Manual](https://www.php.net/manual/en/), [migração 8.4](https://www.php.net/manual/en/migration84.php), [php-src](https://github.com/php/php-src) | Linguagem, extensões e incompatibilidades. Manual/upstream; PHP Group, primária. | Manual vivo; guia específico 8.3 para 8.4. Cadência não auditada. | L: índice e alerta de incompatibilidades; upstream A. Cloud declara PHP ^8.3 e CI 8.4; não inferir recursos de versões posteriores nem runtime instalado. |
| K02 [Laravel 13](https://laravel.com/framework/docs/13.x), [releases](https://laravel.com/framework/docs/13.x/releases), [manifesto fixado](https://github.com/laravel/framework/blob/6d481710375d2aa67656922ef760cdd2b18bcfe0/composer.json) | Framework, suporte e requisitos. Manual/upstream; Laravel, primária. | Docs 13.x vivas; framework v13.24.0 no lock e source SHA fixado. Página informa segurança até 17/03/2028 para major 13. | L: suporte e requisitos PHP ^8.3/composer-runtime-api ^2.2. Docs major não são snapshot do patch; não comprovam a configuração local. |
| K03 [ECMA-262](https://tc39.es/ecma262/), [tc39/ecma262](https://github.com/tc39/ecma262) | Semântica da linguagem. Norma/upstream; Ecma/TC39, autoridade normativa. | Página exibiu draft ECMAScript 2027, datado 08/11/2026; edição viva, não suporte universal. | L: escopo do draft; upstream A. Confirmar suporte por recurso no Node/navegador; APIs DOM e Node não fazem parte de toda a linguagem. |
| K04 [MDN JavaScript](https://developer.mozilla.org/en-US/docs/Web/JavaScript), [mdn/content](https://github.com/mdn/content) | Guia e descoberta de APIs/compatibilidade. Manual; MDN/Mozilla e comunidade, referência editorial, não norma. | Conteúdo vivo; sem versão única de “JavaScript”. Cadência não auditada. | L: índice de linguagem/Web APIs; upstream A. Complementa K03; não substitui a especificação nem testes nos navegadores-alvo. |
| K05 [Node 24 API](https://nodejs.org/docs/latest-v24.x/api/), [nodejs/node](https://github.com/nodejs/node) | Runtime e APIs ESM/CommonJS. Manual/upstream; Node.js/OpenJS, primária. | Página v24.22.2; URL acompanha patches. CI Cloud seleciona major 24, Styles major 22. | L: índice/API; upstream A. Não atribuir patch da página ao CI. Consultar major 22 se a tarefa for no Styles. |
| K06 [npm docs](https://docs.npmjs.com/), [npm/cli](https://github.com/npm/cli) | CLI, lockfile e semver. Manual/upstream; npm/GitHub, primária. | Docs vivas; CLI do projeto não fixada nos manifests lidos. | L: índice; upstream A. Node major não fixa npm. Conferir CLI e lockfileVersion antes de aplicar comandos. |
| K07 [Composer docs](https://getcomposer.org/doc/), [composer/composer](https://github.com/composer/composer) | Dependências PHP, plataforma e constraints. Manual/upstream; Composer, primária. | Docs vivas; patch instalado não verificado. | L: índice de CLI/schema/plataforma; upstream A. composer-runtime-api ^2.2 é contrato de API, não evidência de versão instalada do binário. |
| K08 [Vite guide](https://vite.dev/guide/), [Vite 8.2.4](https://github.com/vitejs/vite/blob/v8.2.4/packages/vite/package.json) | Build e dev server. Manual/upstream; equipe Vite, primária. | Guia corrente referente à geração 8; tag 8.2.4/manifesto conferidos. | L: requisitos e manifesto. Engines ^20.19.0 ou >=22.12.0; major Node 24 compatível no nível declarado. Styles usa Vite transitivo 8.1.3, não o patch Cloud. |
| K09 [Laravel Vite](https://laravel.com/framework/docs/13.x/vite), [plugin 3.2.0](https://github.com/laravel/vite-plugin/blob/v3.2.0/package.json) | Integração de assets Laravel. Manual/upstream; Laravel, primária. | Docs 13.x vivas; plugin 3.2.0 fixado. | L: integração/manifesto; peer Vite ^8.0.0 e mesmo requisito Node de K08. Compatibilidade declarada não é build executado. |
| K10 [Tailwind compatibility](https://tailwindcss.com/docs/compatibility), [Tailwind 4.3.3](https://github.com/tailwindlabs/tailwindcss/blob/v4.3.3/packages/tailwindcss/package.json) | Utilitários CSS e suporte de navegador. Manual/upstream; Tailwind Labs, primária. | Portal v4.3; pacote e plugin Vite 4.3.3 no Cloud. | L: manifesto/compatibilidade. Base v4: Chrome 111, Safari 16.4, Firefox 128; utilitários podem exigir mais. Não aplicar práticas de Tailwind 3 nem confundir com o pipeline Sass de Styles. |
| K11 [Sass docs](https://sass-lang.com/documentation/), [dart-sass](https://github.com/sass/dart-sass) | SCSS e compilação Styles. Manual/upstream; equipe Sass, primária. | Portal declara Dart Sass 1.108.1; Styles fixa 1.101.0. Repo não arquivado; cadência não auditada. | L: índice e avisos de compatibilidade; upstream A. Ler indicador de versão de cada recurso, sem atualizar o pacote para acompanhar a página. |
| K12 [PostCSS](https://postcss.org/), [postcss/postcss](https://github.com/postcss/postcss) | Transformações CSS/pipeline. Manual/upstream; equipe PostCSS, primária. | Site vivo; Styles fixa 8.5.16. Repo não arquivado; cadência não auditada. | L: visão geral; upstream A. API/plugin exato deve ser confrontado com lock e build.mjs. |
| K13 [Autoprefixer](https://github.com/postcss/autoprefixer) | Prefixos de navegador. Upstream/apoio; PostCSS, primária. | Styles 10.5.2; repo não arquivado, branch main mutável. | L: introdução; versão do código lido não fixada. Configuração de browsers e dados de compatibilidade importam, não só semver. |
| K14 [cssnano](https://cssnano.github.io/cssnano/), [upstream](https://github.com/cssnano/cssnano) | Minificação CSS. Manual/upstream; equipe cssnano, primária. | Portal exibiu 9.9.0; lock Styles 8.0.2. Repo não arquivado. | L: finalidade e presets; upstream A. Não aplicar defaults da major 9 à 8. Engines do lock 8.0.2 são mais restritivos que o manifesto Styles. |

## 4. Bancos e serviços

| ID / fonte canônica e upstream | Finalidade / categoria / mantenedor e autoridade | Versão/manutenção | Leitura e compatibilidade / limite |
| --- | --- | --- | --- |
| K15 [MySQL 8.4 Manual](https://dev.mysql.com/doc/refman/8.4/en/), [mysql-server](https://github.com/mysql/mysql-server) | SQL, transações e engine. Manual/upstream; Oracle/MySQL, primária. | Major 8.4; manual declara cobertura até 8.4.13 e possível conteúdo ainda não lançado. Upstream não arquivado. | L: escopo do manual; upstream A por metadados. CI usa mysql:8.4, patch/digest não fixados. Não tratar MySQL e MariaDB como equivalentes. |
| K16 [Redis docs](https://redis.io/docs/latest/), [redis/redis](https://github.com/redis/redis) | Cache, sessão, comandos e operação. Manual/upstream; Redis, primária. | Portal latest multiversão/multiproduto; CI redis:7-alpine. Repo não arquivado, default unstable. | L: índice; upstream A. Selecionar comando/versão 7 efetiva antes de usar comportamento novo; patch/driver phpredis não observados no runtime. |
| K17 [SQLite docs](https://sqlite.org/docs.html), [Fossil canônico](https://www.sqlite.org/src/doc/trunk/README.md) | SQL/PRAGMA e particularidades dos testes. Manual/upstream; projeto SQLite, primária. | Docs e trunk vivos; versão da biblioteca SQLite usada pela extensão PHP do runner não registrada aqui. | L: índice/README. SQLite em memória não reproduz todo comportamento MySQL/Redis ou PostgreSQL. GitHub não é necessário para toda fonte upstream. |
| K18 [MariaDB Server docs](https://mariadb.com/docs/server/), [MariaDB/server](https://github.com/MariaDB/server) | Comparação de driver/engine. Manual/upstream; MariaDB e comunidade, primária. | Portal vivo sem edição selecionada; versão de implantação não comprovada. | L: índice; upstream A. Há driver configurável no Cloud, sem evidência de uso efetivo. Não herdar garantias do MySQL. |
| K19 [PostgreSQL 18: RLS](https://www.postgresql.org/docs/18/ddl-rowsecurity.html), [postgres/postgres](https://github.com/postgres/postgres) | Segurança por linha e privilégios. Manual/upstream; PostgreSQL Global Development Group, primária. | Edição 18 fixada no URL; referência de estudo, não versão atribuída ao Lead. | L: RLS, proprietário, BYPASSRLS e exceções; upstream A. Verificar major real, roles, grants e schema antes de aplicar. |
| K20 [Supabase RLS](https://supabase.com/docs/guides/database/postgres/row-level-security), [supabase/supabase](https://github.com/supabase/supabase) | Integração PostgreSQL, grants/policies e testes. Manual/upstream; Supabase, primária. | Docs vivas; versão do serviço/SDK/DB Lead não verificada. | L: grants, políticas, pgTAP e bypass; upstream A. PR #25 não integrado. A descrição relata validações anteriores, não reproduzidas no FEKS; não consultar projeto real para completar este catálogo. |

## 5. Fokus Styles e ferramentas principais de qualidade

| ID / fonte canônica e upstream | Finalidade / categoria / mantenedor e autoridade | Versão/manutenção | Leitura e compatibilidade / limite |
| --- | --- | --- | --- |
| K21 [índice Styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/docs/README.md), [uso](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/docs/getting-started/usage.md), [acessibilidade](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/docs/guides/accessibility.md), [manifesto](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package.json) | Contratos CSS/JS/tokens e a11y. Projeto/upstream; Jorge Reis/Fokus Styles, fonte primária local. | Snapshot f052b05b, pacote declarado 2.7.0. Documentos fixados por SHA; não seguir docs/next por padrão. | L: índices, APIs gerais, a11y, manifesto e CI. Cloud consome npm 2.7.0 e transforma CSS; igualdade com árvore completa Styles não comprovada. |
| K22 [PHPUnit 12.5](https://docs.phpunit.de/en/12.5/), [requisitos](https://docs.phpunit.de/en/12.5/installation.html), [upstream](https://github.com/sebastianbergmann/phpunit) | Testes PHP. Manual/upstream; Sebastian Bergmann, primária. | Manual 12.5 atualizado em 07/11/2026; lock 12.5.33; repo não arquivado. | L: índice/requisito PHP 8.3; upstream A. Testes do repositório e configuração determinam cobertura real; manual não comprova execução. |
| K23 [Laravel Pint 13.x](https://laravel.com/framework/docs/13.x/pint), [upstream](https://github.com/laravel/pint) | Estilo PHP. Manual/upstream; Laravel, primária. | Lock 1.30.4; página major Laravel viva; repo não arquivado. | L: presets/regras; upstream A. Exemplos atuais podem acrescentar recursos e instalações fora do escopo. Usar versão/configuração local; não formatar aplicação nesta etapa. |
| K24 [Playwright configuração](https://playwright.dev/docs/test-configuration), [upstream](https://github.com/microsoft/playwright) | Browser tests, fixtures, projetos e relatórios. Manual/upstream; Microsoft/Playwright, primária. | Docs vivas; Cloud 1.63.0 e Styles 1.61.1; repo não arquivado. | L: opções principais; upstream A. Browser binário, SO, configuração e versão do pacote são parte da prova. Nenhum navegador foi instalado ou teste executado localmente. |
| K25 [Vitest 4](https://v4.vitest.dev/guide/), [upstream](https://github.com/vitest-dev/vitest) | Testes JS Styles. Manual/upstream; equipe Vitest, primária. | Arquivo major 4, lock 4.1.9; portal não versionado já trata v5. Repo não arquivado. | L: requisitos e configuração; upstream A. Usar v4; Node >=20 do guia não substitui restrições mais fortes do restante da toolchain. |
| K26 [Stylelint](https://stylelint.io/user-guide/get-started/), [upstream](https://github.com/stylelint/stylelint) | Análise SCSS/CSS. Manual/upstream; equipe Stylelint, primária. | Lock 17.14.0; docs vivas e repo não arquivado. | L: introdução/configuração; upstream A. Conferir preset SCSS 17 e Node >=20.19.0 no lock. Não executar o instalador sugerido pela página. |
| K27 [TypeScript 5.9](https://www.typescriptlang.org/docs/handbook/release-notes/typescript-5-9.html), [upstream](https://github.com/microsoft/TypeScript) | Tipos e compatibilidade de declarações Styles. Manual/upstream; Microsoft, primária. | Release notes 5.9; lock 5.9.3; portal também lista 6.0. Repo não arquivado. | L: índice/edição; upstream A. Ler tsconfig e API usada antes de recomendar flags. Não inferir frontend Cloud em TypeScript por esta dependência do Styles. |
| K28 [axe-core](https://github.com/dequelabs/axe-core), [wrapper Playwright](https://github.com/dequelabs/axe-core-npm), [WAI-ARIA APG](https://www.w3.org/WAI/ARIA/apg/) | Acessibilidade automatizada e padrões de interação. Upstream/guia; Deque e W3C/WAI, primárias em seus escopos. | Wrapper 4.12.1 no Styles; APG vivo, sem release congelada aqui; wrapper não arquivado. | A: metadados dos repos; L: finalidade/índice APG e limite de axe no guia Styles. Auditoria automática não prova experiência com teclado/leitor de tela nem conformidade integral WCAG. |
| K29 [React 18](https://18.react.dev/), [política de versões](https://react.dev/versions), [upstream](https://github.com/facebook/react) | Wrappers/fixtures Styles. Manual/upstream; equipe React, primária. | Arquivo 18.3.1; portal corrente 19.3; repo não arquivado. | L: edição/visão geral; upstream A. Peer opcional >=18 não prova testes em toda major futura. Não concluir que Cloud usa React. |
| K30 [jsdom](https://github.com/jsdom/jsdom) | DOM simulado em testes JS. Upstream/apoio; equipe jsdom, primária. | Lock 29.1.1; main viva, repo não arquivado. | A: metadados/descrição. Leitura técnica detalhada pendente; jsdom não substitui navegador real. Engines incluem Node 22 >=22.13.0. |

## 6. Auxiliares identificados nos manifests/locks

Todos os upstreams desta tabela responderam por metadados GitHub e estavam **não
arquivados**. Nível **A** para o upstream, **L** apenas para manifesto/lock Fokus;
código/API upstream ainda não lidos. Manutenção: cadência e suporte da versão
**não auditados**. Compatibilidade: consultar tag/source do lock antes de aplicar,
sem recomendar adoção adicional. Não é inventário completo de dependências transitivas.

| ID | Pacote / versão fixada | Mantenedor e fonte primária / categoria | Finalidade e limite |
| --- | --- | --- | --- |
| A01 | fakerphp/faker 1.24.1 | [FakerPHP/Faker](https://github.com/FakerPHP/Faker), equipe FakerPHP; apoio | Dados sintéticos de teste; default branch 2.0 difere da major fixada 1. |
| A02 | mockery/mockery 1.6.12 | [mockery/mockery](https://github.com/mockery/mockery), equipe Mockery; apoio | Doubles/mocks PHP; não prova integração externa real. |
| A03 | nunomaduro/collision 8.9.5 | [nunomaduro/collision](https://github.com/nunomaduro/collision), mantenedores Collision; apoio | Diagnóstico de erros em CLI; logs podem conter dados a sanitizar. |
| A04 | laravel/tinker 3.0.2 | [laravel/tinker](https://github.com/laravel/tinker), Laravel; apoio | REPL; existência não autoriza comandos mutáveis ou produção. |
| A05 | laravel/pail 1.2.7 | [laravel/pail](https://github.com/laravel/pail), Laravel; apoio | Observação de logs em desenvolvimento; não ler segredos/dados reais. |
| A06 | laravel/pao 1.1.3 | [laravel/pao](https://github.com/laravel/pao), Laravel; apoio | Saída de testes otimizada para agentes segundo lock; não cria agente nem garante integração FEKS. |
| A07 | concurrently 9.2.5 | [open-cli-tools/concurrently](https://github.com/open-cli-tools/concurrently), Open CLI Tools; apoio | Orquestra processos dev; script pode iniciar serviços e escrever arquivos. |
| A08 | chokidar 5.0.0 | [paulmillr/chokidar](https://github.com/paulmillr/chokidar), mantenedores Chokidar; apoio | Observação de arquivos; Node >=20.19.0 no lock. |
| A09 | esbuild 0.28.1 | [evanw/esbuild](https://github.com/evanw/esbuild), Evan Wallace/projeto; apoio | Ferramenta de build no Styles; presença não implica uso pelo pipeline principal Cloud. |
| A10 | rimraf 6.1.3 | [isaacs/rimraf](https://github.com/isaacs/rimraf), mantenedores rimraf; apoio | Limpeza de artefatos; operação destrutiva de arquivos, não executar nesta etapa. |
| A11 | lucide-static 1.23.0 | [lucide-icons/lucide](https://github.com/lucide-icons/lucide), comunidade Lucide; apoio | Assets de ícones do Styles; presença no lock não substitui contrato visual/política do produto. |
| A12 | stylelint-config-standard-scss 17.0.0 | [stylelint-scss/config](https://github.com/stylelint-scss/stylelint-config-standard-scss), equipe stylelint-scss; apoio | Regras SCSS; observar compatibilidade com Stylelint e plugins. |

Os scripts próprios de tokens, SEO, contrato visual, contraste e tamanho têm como
fonte primária o repositório Fokus no commit examinado. Não há manual externo que
substitua seus critérios. Os [scripts Cloud](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/package.json) e
[scripts Styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package.json) foram lidos; implementações não foram todas auditadas.

## 7. Segurança e desenvolvimento seguro

| ID / fonte | Finalidade / categoria / autoridade | Versão/manutenção | Leitura, aplicabilidade e limite |
| --- | --- | --- | --- |
| S01 [OWASP ASVS](https://asvs.owasp.org/), [release 5.0.0](https://github.com/OWASP/ASVS/releases/tag/v5.0.0_release), [upstream](https://github.com/OWASP/ASVS) | Requisitos verificáveis de segurança de aplicações. Norma de verificação; OWASP, primária. | Estável 5.0.0, 30/05/2025. Tag latest corresponde a Bleeding Edge, não à estável. | L: overview, versão e metadados; requisitos completos não lidos. Usar IDs com versão em futura matriz, sem alegar conformidade/certificação. |
| S02 [Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html), [upstream](https://github.com/OWASP/CheatSheetSeries) | Orientações de autorização. Guia; OWASP, primária. | Página viva, sem data editorial/release confirmadas. | L: least privilege, deny by default, permissão a cada request. Comparar com serviço/middleware real, sem supor que o produto usa Policies Laravel. |
| S03 [Multi-Tenant Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html) | Isolamento de tenant em dados/cache/jobs. Guia; OWASP, mesmo upstream de S02. | Página viva; fixar commit em futura aplicação concreta. | L: riscos e contexto de tenant; índice das demais áreas. Tenant informado pelo cliente não basta como autorização. Nenhum cenário cruzado foi executado nesta etapa. |
| S04 [MITRE CWE](https://cwe.mitre.org/data/index.html), [CWE-862](https://cwe.mitre.org/data/definitions/862.html) | Classificar fraquezas, não fazer diagnóstico automático. Taxonomia; MITRE/HSSEDI, primária. | Índice exibiu CWE List 5.0; data de lançamento não confirmada. Catálogo oficial, sem substituto GitHub adotado. | L: índice e descrição/limites de mapeamento de CWE-862. Exige evidência concreta antes de atribuir fraqueza ao Fokus; CWE não é CVE nem prova de exploração. |
| S05 [NIST SSDF](https://csrc.nist.gov/projects/ssdf), [SP 800-218 final](https://csrc.nist.gov/pubs/sp/800/218/final) | Organizar práticas/evidências do ciclo seguro. Framework; NIST, primária institucional. | SSDF 1.1, fevereiro de 2022, publicação final observada. | L: metadados, abstract, overview e grupos PO/PS/PW/RV; PDF integral não auditado. Adaptar ao risco; não converter em selo de conformidade. |

ASVS define o que verificar; Cheat Sheets ajudam a pensar a implementação; CWE
classifica a fraqueza comprovada; SSDF organiza o processo que produz evidências.
São complementares. Não somar contagens como uma pontuação de segurança.

## 8. Roteiro de consulta seletiva

1. Confirmar a tarefa autorizada, seu estado no Linear e o componente Cloud,
   Styles, Law ou Lead. Ler o checkout/commit correto e instruções locais aplicáveis.
2. Fixar a pergunta: por exemplo, comportamento de autorização, API de componente,
   compatibilidade de build ou semântica transacional. Não carregar todos os manuais.
3. Consultar a [matriz](feks-technology-matrix.md) e distinguir manifesto, lock,
   CI e runtime. Se faltar versão relevante, marcar a lacuna antes de escolher edição.
4. Abrir uma fonte primária aplicável e somente sua seção necessária. Usar upstream
   na tag/source SHA para ambiguidades, breaking changes ou divergência do portal vivo.
5. Confrontar o conhecimento com código, configuração e teste existentes no Fokus.
   Não converter exemplos de terceiros em instruções de instalação, acesso ou publicação.
6. Registrar fonte/versão/seção/data, conclusão curta, limites e nível I/A/L.
   Só marcar V com evidência reproduzível da tarefa e autorização correspondente.
7. Se houver conflito material, registrar as duas evidências e a condição de resolução.
   Não atualizar dependência ou ampliar acesso para “ajustar” o catálogo.
8. Salvar o resultado e próximo passo na tarefa Linear existente; GitHub guarda o
   artefato técnico. Não manter backlog paralelo nem presumir sincronização com Notion.

Nenhuma rotina, indexador, servidor MCP, extensão ou integração automática foi
criada. Reconsulta seletiva é um procedimento proposto, dependente de ferramentas,
permissões e contexto disponíveis em cada execução.
