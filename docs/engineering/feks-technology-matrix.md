# FEKS: matriz de tecnologias e compatibilidade

Data: **08/11/2026 (UTC)**. [REI-16](https://linear.app/reis-developer/issue/REI-16).
Fontes externas por ID no [catálogo](feks-knowledge-catalog.md).
Critérios e exceções na [avaliação](feks-source-evaluation.md).

## 1. Snapshot e força da evidência

| Base | Commit/estado observado | Consequência |
| --- | --- | --- |
| Cloud main | `8dd9835053b7dc986343e8cfc44b5c35bdde920a` | Base própria da etapa 2; árvore Git completa, sem truncamento. |
| Etapa 1, PR #50 | `883973bb903397adb676d26ea702db907f194fa7`, draft/não integrado | Dependência informacional por [inventário fixado](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-technical-inventory.md). Os dois docs da etapa 1 não entram neste diff. |
| Styles main | `f052b05bea070f7175a759951657d73e73f9c63b` | Árvore completa, manifests/lock/CI e docs amostrais lidos; não houve modificação nesse repo. |
| Lead, PR #25 | `198f3fe20104d9261d942c93fa22099d27da3c93`, draft/não integrado | A [descrição pública](https://github.com/jorgewreis/fokus-cloud/pull/25) relata modelo e validações anteriores em Supabase. Não reproduzidas nesta etapa; não tratar como runtime da main. |

A main avançou um commit desde a base da etapa 1 (`53afd105...`), mudando somente
`docs/09-operations/julia-night-operations.md`. Os manifests/locks relevantes foram
relidos no novo SHA; não se presumiu que o diagnóstico anterior continuava atual.

- **Manifesto:** intenção/faixa declarada, não instalação.
- **Lock:** resolução exata para reprodução; não prova execução do pacote.
- **CI configurado:** ambiente/etapas declarados no workflow, não resultado.
- **Runtime observado:** requer leitura do ambiente efetivo, separada por servidor/job.
  **Não observado para a aplicação nesta etapa.**
- **Compatibilidade documental:** confronto de requisitos, não homologação.
  Nenhuma linha equivale a aprovação para upgrade.

As fontes de cada conjunto de linhas são os arquivos fixados listados antes da tabela.
Números de patch não disponíveis são deixados como desconhecidos.

## 2. Onde cada tecnologia pertence

- **Cloud:** PHP/Laravel, identidade, empresas, acesso, catálogo e billing; frontend
  JavaScript/Blade e build Vite/Tailwind/Styles.
- **Law:** há implementação dentro da árvore Cloud, sob a mesma stack PHP/Laravel.
  Fronteira lógica de produto não comprova repositório/runtime independente.
- **Styles:** CSS/SCSS e JavaScript, declarações TypeScript, wrappers React opcionais,
  build e testes próprios.
- **Lead:** shell e contratos no Cloud; proposta PostgreSQL/Supabase no PR #25.
  Versão real do banco e serviço não aferida; não consultar infraestrutura para inferi-la.

O [contrato de fronteiras](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/docs/03-architecture/repository-boundaries.md)
descreve o modelo alvo; a árvore e os PRs determinam o que foi integrado.
Não extrair Law, integrar Lead ou duplicar serviços comuns como efeito desta matriz.

## 3. Cloud e Law: linguagem, build e dependências principais

Evidências: [composer.json](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/composer.json),
[composer.lock](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/composer.lock), [package.json](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/package.json),
[package-lock.json](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/package-lock.json),
[Application quality](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/.github/workflows/application-quality.yml) e
[Deploy/validate](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/.github/workflows/deploy.yml).

| Tecnologia | Declaração | Resolução / CI configurado | Referência aplicável e resultado da comparação |
| --- | --- | --- | --- |
| PHP | ^8.3 | Sem patch de interpretador no lock; CI 8.4 | K01/K02: 8.4 compatível com PHP ^8.3 e requisito PHP >=8.3 do PHPUnit 12.5; extensões precisam de comprovação própria. |
| Laravel | ^13.8 | v13.24.0; source `6d481710375d2aa67656922ef760cdd2b18bcfe0` | K02: docs 13.x e manifesto upstream no SHA. Não usar APIs de major diferente. |
| Composer | Não fixa binário | Framework requer composer-runtime-api ^2.2 | K07: versão de CLI não verificada; scripts de instalação podem executar código/migrations e não foram disparados. |
| JavaScript | ESM (`type: module`); scripts .mjs e frontend | Sem versão única de ECMAScript declarada | K03/K04: conferir recurso e host. Linguagem, DOM e APIs Node são contratos diferentes. |
| Node.js / npm | Cloud sem campo engines | CI Node 24; npm não fixado | K05/K06: Node 24 satisfaz engines lidos de Vite/plugin/Playwright; patch do runner não observado aqui. |
| Vite | ^8.0.0 | 8.2.4; engines ^20.19.0 ou >=22.12.0 | K08: manifesto upstream da tag coincide com lock. Não usar guia Vite 7 como especificação do build 8. |
| laravel-vite-plugin | ^3.1 | 3.2.0, mesmo requisito Node; peer Vite ^8.0.0 | K09: combinação com Vite 8.2.4 satisfaz peer declarado. Build não executado localmente. |
| Tailwind CSS | ^4.0.0 | 4.3.3 | K10: usar v4.3; suporte navegador por recurso. Presença no manifest não prova que toda UI usa utilitários Tailwind. |
| @tailwindcss/vite | ^4.0.0 | 4.3.3 | K10/K08: versão acompanha Tailwind no lock; não intercambiar plugin com configuração de v3. |
| fokus-styles | ^2.7.0 | 2.7.0 | K21: pacote agregado; não presumir identidade do artefato npm com toda main Styles. |
| PHPUnit | ^12.5.12 | 12.5.33, PHP >=8.3 | K22: manual 12.5; teste existente é evidência de intenção, resultado exige execução. |
| Laravel Pint | ^1.27 | v1.30.4, PHP ^8.2.0 | K23: CI aplica em PHP alterado; nenhum PHP alterado nesta entrega. |
| Playwright Test | ^1.57.0 | 1.63.0, Node >=20 | K24: configuração e versão de browser importam; não transportar snapshots do Styles sem análise. |

### Auxiliares PHP e de desenvolvimento

Mesmos manifests/locks acima, com finalidade e upstream nos IDs A01–A07.

| Pacote | Declarado | Fixado | Limite que orienta a consulta |
| --- | --- | --- | --- |
| laravel/tinker | ^3.0 | v3.0.2 | REPL não autoriza operação em produção. |
| fakerphp/faker | ^1.23 | v1.24.1 | Consultar major 1, não default branch 2.0; usar dados sintéticos. |
| laravel/pail | ^1.2.5 | v1.2.7 | Logs exigem contexto e sanitização. |
| laravel/pao | ^1.0.6 | v1.1.3 | Lock descreve saída otimizada para agentes; não implica integração FEKS. Tem constraints próprias de ferramentas de teste. |
| mockery/mockery | ^1.6 | 1.6.12 | Mock não comprova comportamento do provedor real. |
| nunomaduro/collision | ^8.6 | v8.9.5 | Diagnóstico CLI; distinguir erro de aplicação e apresentação do erro. |
| concurrently | ^9.0.1 | 9.2.5, Node >=18 | Script dev inicia vários processos; não foi executado. |

Não confundir bibliotecas mencionadas no `require-dev` de um pacote upstream com
dependências diretas ou ferramentas instaladas do Fokus.

## 4. Styles: construção, testes e consumo

Evidências: [package.json](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package.json),
[package-lock.json](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package-lock.json), [CI](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/.github/workflows/ci.yml),
[uso](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/docs/getting-started/usage.md) e
[acessibilidade](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/docs/guides/accessibility.md).

| Tecnologia | Declarado | Fixado / CI configurado | Compatibilidade documental e limite |
| --- | --- | --- | --- |
| Fokus Styles | 2.7.0, workspaces packages/*, MIT | Main no SHA acima; não foi baixado tarball npm | K21: contratos versionados; não promover docs/next a API estável. |
| Node.js | >=18 | CI Node 22, sem patch/digest no workflow | Mínimo do manifest não basta para desenvolver/testar. Ver restrições abaixo. |
| Sass | ^1.101.0 | 1.101.0; Node >=20.19.0 | K11: site já descreve 1.108.1; validar feature por versão. |
| PostCSS | ^8.5.16 | 8.5.16 | K12: respeitar pipeline e versões de plugins. |
| Autoprefixer | ^10.5.2 | 10.5.2 | K13: config de browsers participa do resultado. |
| cssnano | ^8.0.2 | 8.0.2; Node ^22.11.0 ou ^24.11.0 ou >=26.0 | K14: exclui Node 18/20 e outras faixas; portal corrente major 9 não define o comportamento da 8. |
| Vitest | ^4.1.9 | 4.1.9; Node ^20.0.0 ou ^22.0.0 ou >=24.0.0 | K25: usar documentação v4; site principal já v5. |
| Vite transitivo | Sem dependência direta no package raiz | 8.1.3; Node ^20.19.0 ou >=22.12.0 | K08: mesma major, patch distinto do Cloud; não atualizar manualmente transitiva. |
| Playwright Test | ^1.61.1 | 1.61.1; Node >=18 | K24: diferente de Cloud 1.63.0; browsers/test runner devem corresponder. |
| @axe-core/playwright | ^4.12.1 | 4.12.1 | K28: camada automatizada, não certificação de a11y. |
| jsdom | ^29.1.1 | 29.1.1; Node ^20.19.0 ou ^22.13.0 ou >=24.0.0 | K30: em major 22 exige pelo menos 22.13.0; DOM simulado não tem layout de browser real. |
| Stylelint | ^17.14.0 | 17.14.0; Node >=20.19.0 | K26: conferir regras, preset e sintaxe SCSS. |
| stylelint-config-standard-scss | ^17.0.0 | 17.0.0; Node >=20 | A12: preset precisa ser avaliado com o linter da mesma instalação. |
| TypeScript | ^5.9.3 | 5.9.3 | K27: `types:check` valida declarações/fixtures do projeto; não significa app Cloud em TypeScript. |
| React / React DOM | Dev ^18.3.1; peer React opcional >=18 | Ambos 18.3.1 | K29: consultar arquivo React 18; peer amplo não comprova compatibilidade React 19. |
| chokidar | ^5.0.0 | 5.0.0; Node >=20.19.0 | A08: watcher dev, não runtime de navegador. |
| esbuild | ^0.28.1 | 0.28.1; Node >=18 | A09: versão pré-1.0, conferir API/patch antes de transportar exemplo. |
| rimraf | ^6.1.3 | 6.1.3; Node 20 ou >=22 | A10: `clean` remove dist; não executado. |
| lucide-static | ^1.23.0 | 1.23.0 | A11: fonte de assets; não autoriza trocar ícones ou regras do produto. |

**Divergência confirmada:** `engines.node >=18` da raiz/pacote é mais permissivo que
a toolchain fixada. Para a linha de Node 22, somente os requisitos destacados já
elevam o piso a **22.13.0** (jsdom); cssnano exclui Node 20. Essa é comparação de
constraints selecionadas, não auditoria integral de todos os pacotes transitivos.
O CI seleciona major 22, sem provar o patch nesta leitura.

Separar três perguntas:
1. O consumidor precisa somente de CSS pronto?
2. Ele instala o pacote agregado e suas dependências?
3. Ele desenvolve, compila ou testa o repositório Styles?

O fato de CSS pronto poder ser servido no navegador não elimina o efeito dos
`engines` ao instalar o pacote agregado. O mínimo suportado de cada caminho exige
verificação própria antes de corrigir o manifest.

### Contrato de integração Cloud/Styles

O [sincronizador](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/tools/sync-fokus-styles.mjs)
lê o CSS do pacote instalado e aplica transformações/políticas do Cloud.
Logo, o CSS publicado no Cloud não é necessariamente cópia byte a byte do upstream.
O índice Styles orienta priorizar implementação/testes da versão consultada sobre
o site em caso de divergência. Esta etapa não comparou integridade do tarball npm,
artefato gerado, CDN e produção, nem executou sincronização.

## 5. Dados, cache e serviço Lead

Evidências: [database.php](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/config/database.php),
[phpunit.xml](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/phpunit.xml), [homologação](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/.github/workflows/security-homologation.yml)
e [PR #25](https://github.com/jorgewreis/fokus-cloud/pull/25).

| Tecnologia | Presença comprovada | Versão configurada/fixada | Documento a consultar / limite |
| --- | --- | --- | --- |
| MySQL | Driver e serviço do CI de homologação | Imagem mysql:8.4, sem digest/patch registrado no YAML | K15, manual 8.4. Runtime de produção não verificado. |
| Redis | Configuração cliente phpredis e serviço de CI | Imagem redis:7-alpine; versão da extensão não fixada aqui | K16, seção aplicável ao comando Redis 7. Docs latest não são baseline 7. |
| SQLite | Suite padrão em memória e migrations no CI | Versão da biblioteca SQLite usada pela extensão PHP do runner não registrada | K17. Resultado SQLite não demonstra equivalência de lock/SQL/concorrência em MySQL. |
| MariaDB | Driver configurável e menção nas referências do projeto | Sem versão efetiva comprovada | K18. Compatibilidade mínima Laravel não é declaração de suporte do fabricante nem prova de implantação. |
| PostgreSQL | Driver genérico na main; proposta específica Lead no PR #25 | Versão real não verificada | K19, edição 18 apenas como referência de estudo. Não atribuir 18 ao Lead. |
| Supabase | Proposta/relato público Lead no PR #25 | Serviço/SDK/DB não versionados pela inspeção | K20. Relato de validação anterior não comprova operação atual ou integração com main. |

Não houve conexão ao banco, leitura de credenciais, consulta de tenants reais,
migration, restore ou ensaio externo. Grants, roles, TLS, pool, RLS e isolamento
exigem evidências próprias em trabalho autorizado.

## 6. Segurança, testes e fontes de conhecimento

- S01 ASVS 5.0.0: selecionar requisitos aplicáveis e identificar explicitamente versão.
- S02/S03 Cheat Sheets: fundamentar hipóteses de autorização/multiempresa.
- S04 CWE 5.0: classificar somente fraqueza demonstrada, com justificativa de mapeamento.
- S05 SSDF 1.1: organizar evidências do processo; não alegar certificação.
- Há [testes de autenticação/isolamento](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/tests/Feature/AuthenticationAndIsolationTest.php)
  e [permissões Law](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/tests/Feature/LawPermissionsTest.php). Nesta etapa foram lidos
  trechos de setup/casos; não se revisou toda cobertura nem executou as suites.
- CI Cloud configura testes PHP, Pint sobre arquivos alterados, build, tokens,
  SEO e contrato visual. Styles configura lint, build, Vitest, tipos, docs,
  contraste, tamanho, visual e axe. Configuração não equivale a resultado.
- Não há conclusão de “zero vulnerabilidades”, cobertura integral ou ausência de
  vazamentos. Não foi feita varredura SCA/SAST nem auditoria de todos os advisories.

## 7. Lacunas que orientam uma etapa futura

| Lacuna | Por que importa | Evidência necessária antes de agir |
| --- | --- | --- |
| Runtime efetivo PHP/Node/DB/Redis/Composer/npm | Determina a edição correta e suporte | Leitura sanitizada no ambiente autorizado, com data/host e versão; não presumir produção pelo CI. |
| Engines e pacote agregado Styles | Instalação e desenvolvimento têm restrições diferentes | Matriz de consumo/build, lock completo e logs existentes; mudança de engines exige escopo separado. |
| Documentação viva versus versão fixada | Vitest/React/cssnano/Sass já mostram versões diferentes | Arquivo major, tag/commit e changelog da versão necessária; sem upgrade automático. |
| Identidade do artefato Styles 2.7.0 | Número igual não prova conteúdo idêntico | Integridade/proveniência do pacote e comparação dos artefatos sob autorização própria. |
| Lead PostgreSQL/Supabase | PR draft não prova integração da main | Reconciliar REI-14/PR #25 e evidências existentes antes de pedir qualquer consulta ou mudança no serviço. |
| Aplicação de guias de segurança | Acesso/leitura não prova controle efetivo | Requisito versionado, código, teste negativo e resultado por cenário. |

Estas lacunas são propostas de verificação, não novos compromissos de implementação.
O próximo escopo recomendado é uma matriz documental requisito/controle/teste
existente de autorização e multiempresa, sujeita a decisão específica do responsável.

## 8. Validação desta entrega

Leitura remota de arquivos e árvores Git; confronto de versões/engines; links de
fontes abertos por web/GitHub; revisão de UTF-8, whitespace, links relativos e escopo
dos três arquivos. Nenhuma dependência instalada.

No executor de documentação, comandos de leitura encontraram Git 2.52.0, Node
24.22.1 e npm 11.9.0; PHP e Composer não foram localizados no PATH. Esses números
não descrevem a aplicação. Não foram executados PHPUnit, Pint, Vite, Vitest,
Playwright, migrations, scanners ou homologação local.

Checks que o PR venha a acionar são registrados no Linear para o head exato.
Não herdam o resultado do PR #50 nem substituem validação de produção.
