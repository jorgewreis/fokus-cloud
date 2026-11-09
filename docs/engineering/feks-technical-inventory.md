# FEKS: inventário técnico inicial

Data da inspeção: 08/11/2026 (UTC). Escopo: diagnóstico documental, sem implementação.
A tarefa e o checkpoint oficial estão em [REI-15](https://linear.app/reis-developer/issue/REI-15).
A priorização está em [Análise de lacunas](feks-knowledge-gap-analysis.md).

## 1. Método e limites da evidência

- **Declarado:** texto de documentação, configuração ou manifesto. Não prova instalação nem disponibilidade.
- **Fixado:** versão exata presente em lockfile, ainda sem provar a versão instalada em qualquer servidor.
- **Verificado por leitura:** arquivo, árvore, ref ou resultado consultado no GitHub.
- **Execução observada:** resultado de CI já existente ou comando de leitura realizado nesta etapa.
- **Não verificado:** falta de acesso, execução ou evidência suficiente. Não equivale a falha.

Foram consultadas árvores Git completas, sem truncamento, manifests, lockfiles, rotas,
controles de acesso, testes selecionados, documentação e workflows. A leitura de código
é amostral; este inventário não é um pentest, certificação de segurança, auditoria integral
de cada endpoint nem validação comercial dos produtos.

Não foram instaladas ferramentas ou dependências, executadas migrations, feitas chamadas
a provedores de negócio, lidos segredos ou dados reais, nem realizadas mudanças de aplicação,
banco, infraestrutura, merge ou deploy. Os resultados de CI abaixo já existiam antes do PR FEKS.
A publicação deste PR poderá acionar as validações normais existentes do repositório, sem alterar seu fluxo.

## 2. Repositórios e fronteiras reais

| Frente | Evidência observada | Interpretação segura |
| --- | --- | --- |
| Cloud | [jorgewreis/fokus-cloud](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/README.md), main `53afd105641bae66375ccb24ba1bd2e092cefec1`, árvore com 1.025 arquivos | Aplicação Laravel, core comercial, hub público e implementação atual de partes Law/Lead. |
| Styles | [jorgewreis/fokus-styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package.json), main `f052b05bea070f7175a759951657d73e73f9c63b`, árvore com 4.616 arquivos | Framework visual independente, licença MIT, workspaces de CSS/JS/ícones/React/CLI. |
| Law | [rotas](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/routes/api.php), controllers, services, migrations e testes Law no Cloud | Não tratar a separação lógica de domínio como separação física já concluída. A consulta a `jorgewreis/fokus-law` retornou 404 no acesso atual. Isso não prova inexistência. |
| Lead | [LeadShellController](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/app/Http/Controllers/Api/LeadShellController.php) e [PR #25](https://github.com/jorgewreis/fokus-cloud/pull/25), aberto em draft | Há shell autenticado no Cloud e proposta de integração separada ainda não integrada. A consulta ao nome `jorgewreis/fokus-lead` retornou 404. Uma fonte separada acessível é privada e documental; seu conteúdo não é transcrito neste PR público. |

O documento de [fronteiras](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/03-architecture/repository-boundaries.md)
declara quatro repositórios independentes e atribui identidade, empresas, catálogo,
assinaturas e billing ao Cloud; visual reutilizável ao Styles; regras jurídicas ao Law;
regras imobiliárias ao Lead. A árvore observada não comprova quatro aplicações independentes.
Isto é uma divergência de descrição/topologia, não autorização para extrair código.

O [README](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/README.md) distingue Contatos Law disponível, outros módulos em evolução
e Lead em descoberta. A presença de controllers de Processos, Audiências e sincronização
não comprova liberação comercial, habilitação do catálogo ou homologação de todos os fluxos.

### Manifests

- [Cloud `fokus-project.json`](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/fokus-project.json): `cloud`, `platform`,
  versão `0.0.4-alpha.1`, status `active`, consumo de Styles e provisão de serviços comuns.
- Styles: `package.json` declara `2.7.0`; não foi encontrado `fokus-project.json` na árvore fixada.
- A exigência de manifesto por repositório e validação em CI está declarada nas fronteiras.
  Nos oito workflows Cloud e nos workflows CI/Release Styles lidos, não foi localizada
  uma etapa explícita que valide esse manifesto.
- A URL de schema declarada no manifesto Cloud não foi validada nesta etapa.
  Não presumir que exista um contrato executável só porque há uma chave `$schema`.
- Law e Lead: identidade/versão instalada independente não comprovada.

## 3. Linguagens, versões e ferramentas

### Cloud e Law atualmente contido nele

Fontes: [Composer](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/composer.json), [Composer lock](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/composer.lock),
[npm](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/package.json) e [npm lock](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/package-lock.json).

| Item | Declaração | Versão fixada / evidência | Instalada no ambiente da aplicação |
| --- | --- | --- | --- |
| PHP | `^8.3` | CI configura PHP 8.4; upstream Laravel 13.24.0 também requer `^8.3` | Não consultada |
| Laravel | `^13.8` | `v13.24.0` | Não consultada |
| Tinker | `^3.0` | `v3.0.2` | Não consultada |
| PHPUnit | `^12.5.12` | `12.5.33` | Não consultada |
| Laravel Pint | `^1.27` | `v1.30.4` | Não consultada |
| Node.js / npm | Sem `engines` no package Cloud | CI configura Node 24; npm não fixado nesse manifest | Não consultada |
| Vite | `^8.0.0` | `8.2.4` | Não consultada |
| Laravel Vite plugin | `^3.1` | `3.2.0` | Não consultada |
| Tailwind CSS / plugin Vite | `^4.0.0` | Ambos `4.3.3` | Não consultada |
| Playwright Test | `^1.57.0` | `1.63.0` | Não consultada |
| Fokus Styles consumido | `^2.7.0` | `2.7.0` | Não consultada |

Há PHP/Blade, JavaScript ESM, HTML/CSS, SQL em migrations e shell nos scripts de operação.
A versão de produto `0.0.4-alpha.1` não é a versão do Laravel.

### Styles

Fontes: [package](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package.json) e [lockfile](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package-lock.json).

| Item | Declaração / fixação observada |
| --- | --- |
| Pacote | `fokus-styles@2.7.0`, MIT; pacote agregado com workspaces `packages/*` |
| Linguagens | SCSS/CSS, JavaScript ESM, declarações TypeScript e wrappers React |
| Node | Manifesto `>=18`; CI configura Node 22 |
| Build | Sass `1.101.0`, PostCSS `8.5.16`, Autoprefixer `10.5.2`, cssnano `8.0.2` no lock |
| Testes | Vitest `4.1.9`, Playwright `1.61.1`, axe Playwright `4.12.1`, jsdom `29.1.1` no lock |
| Análise | Stylelint `17.14.0`, TypeScript `5.9.3` no lock |
| React | Peer opcional `>=18`; fixtures/dependências de desenvolvimento React e React DOM `18.3.1` |

O mínimo declarado Node `>=18` não representa o mínimo efetivo da toolchain de
desenvolvimento: o lock declara Node `>=20.19.0` para Stylelint e Chokidar, e
`^20.19.0 || ^22.13.0 || >=24.0.0` para jsdom. Isso não demonstra defeito no
consumo de CSS por uma aplicação; exige distinguir consumo do pacote e construção/testes do repositório.

### Executor desta inspeção

Comandos de versão somente leitura retornaram Git `2.52.0`, Node `24.22.1`,
npm `11.9.0` e Python `3.12.14`. PHP e Composer não estavam disponíveis no PATH.
Não havia `node_modules/` nem `vendor/` na raiz de trabalho da inspeção.
Esses dados descrevem somente este executor, não o computador do proprietário,
a instalação de outro checkout nem a produção. Nenhuma suíte de aplicação foi executada localmente nesta etapa.

## 4. Arquitetura, dados e integrações

### Core, autorização e integridade

- [Configuração de banco](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/config/database.php) contempla drivers Laravel;
  README aponta MySQL/MariaDB. PHPUnit padrão usa SQLite em memória; homologação usa MySQL 8.4 e Redis 7.
- A árvore Cloud contém 79 arquivos de migration. Existência de migration não prova aplicação em produção.
- [EnsureCompanyContext](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/app/Http/Middleware/EnsureCompanyContext.php)
  deriva empresa ativa da sessão e revalida vínculo ativo do usuário.
- [LawAuthorizationService](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/app/Services/LawAuthorizationService.php)
  restringe empresa/unidade e permissões associadas aos módulos da assinatura.
  As [rotas](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/routes/api.php) usam middleware de contexto, permissões e transação de casos.
- Não há diretório `app/Policies` na árvore inspecionada. O controle lido é customizado
  por middleware/serviço. A palavra “Policies” nas diretrizes não comprova uso de classes Laravel Policy.
  Ausência dessas classes, isoladamente, não prova ausência de autorização.
- [Bootstrap](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/bootstrap/app.php) compartilha sessão web e distingue endpoints
  de webhook/ingestão das proteções CSRF de navegador. Assinatura, segredo, replay,
  limites e testes desses endpoints precisam ser avaliados como contratos próprios.

### Integrações

| Integração | Evidência | O que não foi comprovado |
| --- | --- | --- |
| Cloud → Styles | [sincronizador](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tools/sync-fokus-styles.mjs) lê CSS do pacote npm; build também prepara assets Law e cache-busting | Igualdade do pacote publicado com todo o estado atual da main Styles; runtime instalado |
| Mercado Pago | [cliente](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/app/Services/MercadoPagoClient.php), [config](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/config/services.php), webhook/reconciliação/refund e testes | Transação real ou sandbox externo, credenciais válidas, ausência de cobrança |
| Datajud / DJEN | Clientes, jobs, comandos, rotas e [testes Datajud](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Unit/LawDatajudClientTest.php) / [DJEN](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Unit/LawDjenClientTest.php) | Disponibilidade externa, configuração efetiva e dados sincronizados em produção |
| ViaCEP | [contrato documentado](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/03-architecture/fokus-law-viacep-address-lookup.md) e teste de consulta em LawContactsTest | Disponibilidade externa; nenhuma consulta com dados reais foi feita |
| E-mail / serviços opcionais | `config/services.php` declara Postmark, Resend, SES e Slack; GA4 e ingestão de uso também configuráveis | Estar configurado não prova contratação, credenciais, ativação ou uso |
| Lead | Shell valida usuário, empresa, vínculo e assinatura; [PR #25](https://github.com/jorgewreis/fokus-cloud/pull/25) propõe PostgreSQL/Supabase separado | PR draft não é código integrado, deploy nem validação atual de produção |

Os testes de integração lidos usam `Http::fake`. O nome “Sandbox” de uma classe não
comprova exercício na sandbox do provedor. Não foram feitas chamadas externas de negócio.

## 5. Testes, análise e segurança

Contagem por arquivos na árvore, não por casos ou asserções:

| Frente | Inventário observado |
| --- | --- |
| Cloud | 5 arquivos Unit e 21 Feature `*Test.php`; 2 Deployment; 2 Frontend; 5 browser |
| Styles | 41 arquivos unitários, 20 specs visuais, 2 specs de acessibilidade e 3 de tipos |

Há evidências de testes para:
- [autenticação e isolamento](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/AuthenticationAndIsolationTest.php):
  vínculo de empresa, login, assinatura Lead e documentos legais;
- [permissões Law](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/LawPermissionsTest.php):
  API direta, unidade ativa, retirada de módulo e prevenção de escalada local;
- [contatos Law](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/LawContactsTest.php):
  opt-in bilateral de compartilhamento, dados sensíveis cifrados, omissão de campos,
  fusão sem descarte silencioso e casos sigilosos;
- [hardening](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/ProductionSecurityHardeningTest.php):
  CSRF, limites, rotação de segredo com sobreposição, cookies/sessões e checkout órfão;
- [billing](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/tests/Feature/BillingSandboxTest.php):
  assinatura inválida, idempotência de webhook e tolerância;
- Datajud/DJEN: paginação, rejeição de resposta parcial, processo diferente e falha de página posterior.

Esses exemplos não constituem matriz completa de cobertura. Não se calculou cobertura
de linhas nem se afirmou que todas as combinações empresa/unidade/objeto/ação estão testadas.

O Cloud tem Pint, build Vite, tokens, SEO, contrato visual, Playwright, scan de canários
e suites SQLite/MySQL/Redis. O Styles tem lint SCSS, build, Vitest, tipos, consistência
de docs, contraste, orçamento de tamanho, regressão visual e axe.
Não foram encontrados jobs dedicados de SAST, SCA contínua ou varredura de segredos
nos workflows lidos; isso não exclui recursos configurados fora desses arquivos.

### Execuções remotas já existentes

| Evidência consultada | Resultado observado | Limite |
| --- | --- | --- |
| [Cloud validate/deploy 42514012016](https://github.com/jorgewreis/fokus-cloud/actions/runs/42514012016), SHA Cloud acima | `Validate application` e `Publish Laravel application`: success | Execução anterior ao FEKS, não realizada por esta etapa; não substitui teste de todas as jornadas em produção |
| [Cloud security 42514011996](https://github.com/jorgewreis/fokus-cloud/actions/runs/42514011996), mesmo SHA | Job MySQL/Redis success; steps de browser, scan e enforcement concluídos | Não comprova gateway externo, sink remoto ou corte de credenciais |
| [Styles CI 41505250378](https://github.com/jorgewreis/fokus-styles/actions/runs/41505250378), SHA Styles acima | Job build: success | Não comprova publicação npm do estado completo desse commit |
| [Backup 42470621270](https://github.com/jorgewreis/fokus-cloud/actions/runs/42470621270), SHA `c92ef4e5ad99966a367054fe776988b9383ec68d` | Execução agendada: success | Metadados consultados, sem baixar/abrir backup ou medir recuperação operacional |

Não chamar backup de inexistente: há workflow e uma execução bem-sucedida observada.
O exercício de recuperação a partir de artefato retido, RPO/RTO e revogação de segredos
exigem evidências próprias, conforme o [runbook](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/production-security-runbook.md).

## 6. Infraestrutura e consequências de publicação

| Workflow | Gatilho lido | Efeito relevante |
| --- | --- | --- |
| [application-quality](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/application-quality.yml) | PR e manual | Instala dependências declaradas em runner isolado, testa/builda; sem deploy |
| [deploy](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/deploy.yml) | Push main, PR e manual | Validação no PR; job de produção exige `github.ref == 'refs/heads/main'` |
| [security-homologation](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/security-homologation.yml) | PR/push main com filtros de arquivos, ou manual | MySQL/Redis efêmeros e testes sintéticos; docs FEKS não casam com os filtros |
| [styles-showcase-qa](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/styles-showcase-qa.yml) | PR com filtros | QA visual, sem deploy; docs FEKS não casam com os filtros |
| [law-schema-preflight](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/law-schema-preflight.yml) | PR com filtros, ou manual | Teste MySQL isolado; docs FEKS não casam com os filtros |
| [production-backup](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/production-backup.yml) | Agenda 03:17 UTC ou manual | Backup cifrado e restore isolado; não acionado por PR |
| [production-security-inspect](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/production-security-inspect.yml) / [datajud-diagnostics](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/.github/workflows/datajud-diagnostics.yml) | Manual | Acesso à produção; não executados nesta etapa |

Deploy existente usa SSH/rsync, Composer no servidor, migrations, scheduler, PHP-FPM e
purge Cloudflare opcional. Nenhuma dessas ações integra o escopo FEKS.
Não há instalação nova pelo FEKS; as instalações de dependências em CI são etapas
preexistentes do fluxo normal de PR.

No Styles, [CI](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/.github/workflows/ci.yml) roda em push main/PR/manual.
[Release](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/.github/workflows/release.yml) publica o pacote agregado npm em tags
`v*.*.*`, com checagem de versão e provenance. Nenhuma tag ou release foi criada.

## 7. Documentação, conhecimento e agentes

- [docs/README.md](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/README.md) organiza arquitetura, produtos, requisitos,
  dados, segurança, comercial, operação e releases.
- [testing-and-quality](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/testing-and-quality.md) ainda lista
  “A complementar” para testes que já possuem arquivos; falta rastreabilidade requisito → controle → teste → execução.
- [environments-and-deploy](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/environments-and-deploy.md)
  permanece genérico, embora workflows e runbook detalhem operação.
- [AGENTS.md](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/AGENTS.md) e [operação nativa](https://github.com/jorgewreis/fokus-cloud/blob/53afd105641bae66375ccb24ba1bd2e092cefec1/docs/09-operations/julia-night-operations.md)
  descrevem método, autorização por escopo, fontes e retomada.
- A árvore Cloud contém quatro perfis TOML em `.codex/agents/`: coordenador Julia,
  implementador, revisor e alinhador visual. Arquivos de perfil não provam execução,
  revisão independente, isolamento ou disponibilidade de todos os recursos.
- Não foram encontrados arquivos `SKILL.md` nas árvores Cloud/Styles fixadas.
  Isso não equivale à ausência de skills no catálogo da sessão, no computador do responsável
  ou em fontes externas. Consulta de catálogo e leitura de skills estavam disponíveis;
  nenhuma instalação foi feita.
- O Agent Operating Guide externo foi informado/consultado anteriormente como versão
  `0.0.6`, ainda “não verificado”. Não houve nova chamada Notion nesta etapa.
  Seu inventário declarado não comprova conteúdo atualizado, instalação nem operacionalidade.
  Conteúdo privado do guia não é reproduzido neste repositório público.
- GitHub foi utilizado para evidência técnica e Linear para estado/checkpoint.
  Não foi criado backlog duplicado nem alterado o estado de REI-5 a REI-9.

### Acesso a fontes oficiais e externas

Leituras verificadas nesta etapa:
- [Laravel 13: autorização](https://laravel.com/framework/docs/13.x/authorization).
- [PHP: versões suportadas](https://www.php.net/supported-versions.php).
- [OWASP: segurança multi-tenant](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html).
- [GitHub Actions: eventos](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows).
- [Laravel framework v13.24.0, composer.json](https://github.com/laravel/framework/blob/v13.24.0/composer.json), por leitura do repositório upstream.

Acesso à documentação não comprova domínio técnico nem compatibilidade da aplicação.
Estas fontes são referências a comparar com o código, não instruções para executar
mudanças. Consultas a advisories/CVEs e auditoria atual de dependências não foram
executadas nesta etapa; não há conclusão de “zero vulnerabilidades”.

## 8. Resultado e próximo passo

O inventário separa implementação, intenção arquitetural, versões fixadas, configuração
de CI e runtime não verificado. O diff FEKS deve conter exclusivamente este arquivo
e a [análise de lacunas](feks-knowledge-gap-analysis.md).

Próximo passo recomendado: revisar a matriz de autorização/isolamento e reconciliar
fronteiras e contratos documentais antes de propor alterações. A etapa 2 exige escopo
e aprovação específicos para qualquer código, ferramenta, dependência, credencial,
banco ou operação externa. Publicar o PR não autoriza merge nem deploy.
