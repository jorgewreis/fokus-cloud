# FEKS: avaliação e uso responsável das fontes

Data: **08/11/2026 (UTC)**. Tarefa: [REI-16](https://linear.app/reis-developer/issue/REI-16).
Referências: [catálogo](feks-knowledge-catalog.md) e [matriz de tecnologias](feks-technology-matrix.md).

## 1. Conclusão

As fontes selecionadas são adequadas para orientar consultas pontuais, desde que
a edição corresponda ao componente e à versão efetiva. O principal risco encontrado
é usar documentação oficial atual como se descrevesse a versão fixada do Fokus.

Há exemplos concretos: Vitest atual já v5 versus lock 4.1.9; React atual 19.3 versus
fixtures 18.3.1; cssnano atual 9.9.0 versus lock 8.0.2; Sass atual 1.108.1 versus
lock 1.101.0. Oficialidade não elimina incompatibilidade temporal.

O resultado desta etapa é um catálogo humano e versionado. Não há integração
automática, base vetorial, indexador, servidor MCP, Skill nova, extensão ou rotina
criada. Nenhum guia foi aplicado para mudar a aplicação ou validar sua segurança.

## 2. Critérios de seleção

| Critério | Pergunta de decisão | Tratamento nesta etapa |
| --- | --- | --- |
| Procedência | A organização mantém a tecnologia, norma ou conteúdo? | Priorizar fabricante/projeto upstream. MDN é complemento editorial; não tratar como norma ECMA. |
| Identidade e versão | A edição/tag/commit corresponde ao lock/runtime? | Usar docs major ou source SHA quando possível. Registrar desconhecido sem inventar versão. |
| Manutenção | Há edição pública, release/arquivo e sinal de suporte? | Distinguir portal vivo, arquivo e norma final. Metadado “não arquivado” não prova cadência, suporte ou ausência de falhas. |
| Pertinência | A fonte responde à pergunta do componente atual? | Separar Cloud, Styles, Law atual e Lead proposto. Descartar adoção de tecnologias só por popularidade. |
| Evidência | Foi identificada, acessada, parcialmente lida ou aplicada? | Níveis I/A/L/V explícitos no catálogo; nenhuma aplicação V. |
| Reprodutibilidade | Outra pessoa consegue reencontrar a referência? | Permalinks de arquivos Fokus, versões/SHAs e data de consulta. URLs vivas sinalizadas. |
| Restrições | Há licença, paywall, dados privados ou efeito de execução? | Conteúdo público por leitura; nenhum download massivo/cópia integral, login novo, ferramenta ou serviço pago. |
| Custo de manutenção | O registro duplica guia ou estado de tarefa? | Indexar e resumir limites; GitHub para evidência, Linear para estado. Não replicar documentação inteira. |

Não se atribuiu nota numérica de qualidade ou “confiança 100%”. Uma fonte pode ser
normativa e inaplicável à versão instalada; outra pode ser acessível, mas insuficiente
para a decisão. São avaliações separadas.

## 3. Resultado por família de fontes

| Família | Autoridade e ponto forte | Principal risco | Decisão |
| --- | --- | --- | --- |
| PHP/Laravel e upstreams fixados | Primárias, requisitos e contrato de versão | Extrapolar major/patch ou ignorar extensões/configuração | Adotar docs PHP aplicáveis e Laravel 13; usar source SHA para dúvidas do framework. |
| ECMA-262/MDN/Node | Norma da linguagem, guia de plataforma e API de runtime complementares | Confundir draft ECMA com recurso implementado, DOM com Node | Consultar a camada correta e compatibilidade por recurso. |
| npm/Composer | Ferramentas definem semver, lock e scripts | Confundir versão de pacote com versão da CLI; executar scripts de exemplos | Ler CLI/plataforma correspondente; nenhum comando de instalação executado. |
| Vite/Tailwind/plugin Laravel | Manifestos oficiais de versões exatas acessíveis | Usar config de major anterior, ignorar browser target ou peer dependency | Combinação declarada coerente no Cloud; ainda não é homologação. |
| Styles e toolchain | Contratos e locks do próprio projeto fixados por SHA | Tratar site/next/npm/main como artefatos iguais; engines raiz permissivos | Separar consumo e desenvolvimento; usar referência da versão e verificar artefato quando necessário. |
| MySQL/Redis/SQLite/MariaDB | Manuais dos próprios engines | Transferir semântica transacional/locks ou docs latest para outra versão | Usar engine/major real; SQLite CI não substitui MySQL ou PostgreSQL. |
| PostgreSQL/Supabase | Fontes primárias para RLS e serviço | Atribuir versão 18 ao Lead, ignorar roles que bypassam RLS ou confundir PR com produção | Referência para investigação posterior; runtime/schema não verificados. |
| Ferramentas de teste/análise | Manuais e upstreams de ferramentas presentes | Presença do pacote parecer prova de cobertura ou de execução | Relacionar à configuração e a casos/resultados concretos. |
| OWASP ASVS/Cheat Sheets | Requisitos e orientações especializadas complementares | “Checklist cumprido” sem evidência; latest não estável | ASVS 5.0.0 estável, leitura seletiva; IDs com versão em futura aplicação. |
| MITRE CWE | Vocabulário e critérios de classificação | Chamar lacuna de evidência de vulnerabilidade, ou confundir CWE com CVE | Classificar somente após demonstrar condição pertinente. |
| NIST SSDF | Estrutura de processo, evidências e gestão de risco | Tratar framework como certificação ou lista mecânica | Usar SSDF 1.1 final como organização de trabalho, sem alegar aderência. |

## 4. Comparações verificadas e consequências

### 4.1. Portal atual versus edição aplicável

- **Vitest:** [portal corrente](https://vitest.dev/guide/) mostra migração para 5.0 e
  requisito Node >=22.12.0. O [arquivo v4](https://v4.vitest.dev/guide/) identifica
  explicitamente major 4 e requisito base Node >=20. O Styles fixa 4.1.9.
  Escolha: arquivo v4; requisitos mais restritivos de outros pacotes continuam valendo.
- **React:** [política/índice de versões](https://react.dev/versions) mostra 19.3
  como corrente e [arquivo 18](https://18.react.dev/) como 18.3.1.
  Escolha: arquivo 18 para fixtures atuais; peer >=18 não equivale a teste de 19.
- **cssnano:** [portal](https://cssnano.github.io/cssnano/) exibiu 9.9.0;
  lock Styles fixa 8.0.2. Escolha: upstream da versão necessária antes de mudar
  opções/presets, mantendo a diferença explícita.
- **Sass:** [manual](https://sass-lang.com/documentation/) declara cobertura do
  release corrente 1.108.1; lock 1.101.0. Escolha: conferir os indicadores de versão
  por recurso, em vez de copiar o exemplo mais novo.
- **TypeScript:** a página de release 5.9 foi escolhida para 5.9.3, embora o
  portal também liste 6.0. Não inferir compatibilidade de flags da major seguinte.
- **ASVS:** [5.0.0_release](https://github.com/OWASP/ASVS/releases/tag/v5.0.0_release)
  corresponde à estável. A tag latest consultada no catálogo de releases se descreve
  como Bleeding Edge. Escolha: 5.0.0, sem contar conteúdo de desenvolvimento como norma final.
- **ECMA-262:** a edição viva se identificou como draft 2027 em 08/11/2026.
  Escolha: verificar recurso/edição e suporte real, sem afirmar adoção automática.

### 4.2. Requisitos declarados versus ambiente

O [lock Styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package-lock.json) contém requisitos que superam
`engines.node >=18` do [manifesto](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/package.json):
cssnano 8.0.2 admite `^22.11.0 || ^24.11.0 || >=26.0`;
jsdom 29.1.1 exige, na linha 22, pelo menos 22.13.0;
Vite transitivo 8.1.3 exige, na linha 22, pelo menos 22.12.0.

O [CI Styles](https://github.com/jorgewreis/fokus-styles/blob/f052b05bea070f7175a759951657d73e73f9c63b/.github/workflows/ci.yml) configura Node 22.
Isso pode selecionar um patch compatível, mas a mera major no YAML não registra
qual patch executou. Nem Node 18 nem Node 20 satisfazem todas as constraints
destacadas. Esta é uma divergência documental de mínimo, não diagnóstico de
falha do CI nem autorização para editar engines/lock.

No Cloud, manifesto e upstream de Vite 8.2.4/plugin 3.2.0 concordam em
`^20.19.0 || >=22.12.0`; Node 24 configurado é compatível nesse nível.
Laravel v13.24.0 e PHPUnit 12.5.33 exigem PHP >=8.3 em suas constraints,
enquanto o CI configura 8.4. Extensões, configuração e execução ainda precisam de prova.

### 4.3. Documento do fabricante versus comportamento do produto

[MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/) cobre a major do serviço
de CI, mas declara que pode documentar recursos ainda não lançados. O tag
`mysql:8.4` não fixa patch/digest. [Redis latest](https://redis.io/docs/latest/)
abrange produtos/versões além de Redis 7; não deve ser usado sem conferir a versão do comando.

[PostgreSQL RLS 18](https://www.postgresql.org/docs/18/ddl-rowsecurity.html)
distingue proprietário, superuser e BYPASSRLS. O [guia Supabase](https://supabase.com/docs/guides/database/postgres/row-level-security)
distingue grants de policies. Essas referências ajudam a formular perguntas, mas
não revelam qual role, policy, pool ou versão existe no Lead.

A descrição pública do [PR #25](https://github.com/jorgewreis/fokus-cloud/pull/25)
relata modelo aplicado e validações anteriores. Esta etapa não as repetiu nem negou
sua existência. O PR continuava draft; sua integração com main e o estado atual do
serviço são questões separadas.

## 5. Duplicatas, aliases e curadoria

- Os URLs antigos `laravel.com/docs/13.x/...` redirecionam para
  `laravel.com/framework/docs/13.x/...`. O catálogo conserva o destino observado,
  evitando dois registros do mesmo manual.
- Manual e upstream do mesmo projeto são **complementos de um registro**, não duas
  validações independentes da mesma alegação. Manifesto upstream dá contrato de
  versão; manual explica uso; teste local verifica a aplicação concreta.
- OWASP Authorization e Multi-Tenant compartilham CheatSheetSeries, mas têm escopos
  diferentes. O upstream não foi duplicado como novo framework.
- PostgreSQL e Supabase não são duplicatas: engine e serviço/configuração são camadas
  diferentes. Não atribuir a licença ou versão do monorepo a todo o serviço hospedado.
- ASVS, CWE e SSDF não competem pela mesma função. Não contar requisitos/práticas/
  fraquezas em um único “score de segurança”.
- O índice Styles, site publicado, docs/next e pacote npm não foram fundidos.
  O snapshot Git fornece a evidência reproduzível; identidade do pacote requer prova própria.
- SQLite possui upstream canônico em Fossil. CWE e NIST possuem portais institucionais.
  Não inventar um repositório GitHub “oficial” para padronizar a lista.
- Auxiliares constam uma vez, ligados ao lock e mantenedor. Esta etapa não pretende
  catalogar todo pacote transitivo, todos os plugins de CI ou toda API integrada.
- Linear mantém o estado oficial. Os documentos são snapshots técnicos, não outra
  fila de tarefas nem réplica de um guia privado.

## 6. Verificação de acesso e limites de leitura

| Grupo | Resultado observado | O que não comprova |
| --- | --- | --- |
| Cloud/Styles | Árvores completas e arquivos selecionados retornados pelo GitHub, fixados por SHA | Runtime, instalação, cobertura total ou deploy. |
| Laravel/Vite/plugin/Tailwind | Manifestos upstream fixados recuperados pelo GitHub | Ausência de bugs, build aprovado ou pacote instalado em servidor. |
| Manuais oficiais e guias de segurança | Páginas públicas abriram; seções/índices descritos no catálogo foram lidos | Leitura integral, retenção automática por agentes ou aplicação ao Fokus. |
| Upstreams auxiliares | Metadados retornados, repositórios não arquivados | Código/API auditados, suporte da versão fixada ou atividade contínua. |
| Edições React 18/Vitest 4/PHPUnit 12.5/PostgreSQL 18 | Páginas de edição explicitamente acessíveis | Compatibilidade universal de cada patch ou versão real do ambiente. |
| URLs vivas/latest/default branch | Acessíveis na data da inspeção | Imutabilidade ou equivalência futura com este snapshot. |

Alguns URLs GitHub de tags/árvores não foram recuperados pela ferramenta web
(`Failed to fetch restricted URL`). O conector GitHub já autorizado retornou os
manifestos versionados Laravel/Vite/plugin/Tailwind. As árvores específicas
MySQL 8.4 e Redis 7.4 não foram validadas como fontes versionadas; o catálogo usa o
manual e upstream raiz, cujo acesso por metadados foi confirmado. Não se concluiu
inexistência de versão a partir da falha da ferramenta.

A página histórica publicada de CWE 5.0 não abriu pela ferramenta; índice oficial
e CWE-862 abriram. Por isso o catálogo registra versão exibida no índice, sem
data de publicação inventada nem alegação de leitura do arquivo histórico completo.

A avaliação não é um crawler: não se verificou cada link interno dos manuais,
cada âncora externa, status HTTP bruto nem disponibilidade futura. Permalinks
Fokus são conferidos contra árvores Git; fontes externas foram abertas pelas
ferramentas disponíveis. “Acessível” é resultado de recuperação, não uptime garantido.

## 7. Direitos, conteúdo e publicação

Os documentos FEKS são sínteses originais e índices de links, sem reprodução
integral de manuais, código upstream, base de CWE ou documentação privada.

- [ASVS licença](https://github.com/OWASP/ASVS/blob/master/LICENSE.md) e
  [CheatSheetSeries licença](https://github.com/OWASP/CheatSheetSeries/blob/master/LICENSE.md):
  CC BY-SA 4.0 observada. Reuso substancial exige preservar as condições aplicáveis.
- [CWE termos](https://cwe.mitre.org/about/termsofuse.html): condições de uso/copyright
  MITRE lidas; não tratar o catálogo como domínio público.
- [PostgreSQL COPYRIGHT](https://github.com/postgres/postgres/blob/master/COPYRIGHT)
  e [Supabase LICENSE](https://github.com/supabase/supabase/blob/master/LICENSE)
  foram consultados. A licença Apache 2.0 do repo Supabase não abrange automaticamente
  cada componente ou termos do serviço hospedado.
- [PHPUnit manual](https://docs.phpunit.de/en/12.5/) informa CC BY 3.0.
  A licença de documentação pode ser diferente da licença de código.
- Condições de reprodução de todas as outras fontes não foram auditadas integralmente.
  Link e resumo curto não autorizam importar corpus, copiar exemplos extensos ou
  redistribuir pacotes. Uma futura incorporação deve revisar licença e atribuição.

Não houve chamada Notion, publicação de dados privados, segredos, dados de tenants,
transcrição de guias privados ou consulta de produção. A disponibilidade pública
de um upstream não autoriza executar seus scripts, conceder permissões ou instalar
dependências.

## 8. Protocolo mínimo para uma futura aplicação

Para cada decisão técnica, registrar na tarefa existente:

1. Pergunta e componente, autorização e commit-base.
2. Versão declarada, resolução lock, CI e runtime conhecido ou desconhecido.
3. Fonte primária, seção, versão/commit e data da consulta.
4. Conclusão limitada ao problema, alternativas e incompatibilidades.
5. Evidência do código/configuração/teste já existente.
6. Se autorizado a validar, ambiente sintético, comando real e resultado/artefato.
7. Nível I/A/L/V. V deve indicar o cenário validado; nunca “framework inteiro aprendido”.
8. Limite restante e próximo passo, sem criar outra fila/automação por padrão.

Revisar o registro quando o lock, runtime ou requisito mudar, quando uma referência
não abrir ou quando uma decisão depender de página viva. Isso é recomendação de
procedimento, não agendamento automático. Não instalar nem comprar uma ferramenta
só para tornar um link acessível.

## 9. Checkpoint e próxima etapa

A entrega deve conter exatamente:
- `docs/engineering/feks-knowledge-catalog.md`
- `docs/engineering/feks-technology-matrix.md`
- `docs/engineering/feks-source-evaluation.md`

Branch própria: `docs/rei-16-feks-knowledge-20261108`, baseada em
`8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
O PR aponta para main e usa permalinks de #50, sem transportar seus commits/arquivos.
Main/heads e o diff devem ser reconferidos antes da publicação, preservando trabalho concorrente.

Validações documentais: UTF-8, finais de linha, whitespace, estrutura Markdown,
referências relativas, caminhos de permalinks Fokus, consistência dos números
com manifests/locks e diff remoto restrito aos três arquivos. Nenhum binário de
lint novo foi instalado.

Os workflows [Application quality](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/.github/workflows/application-quality.yml)
e [Deploy](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/.github/workflows/deploy.yml) foram lidos antes de publicar.
A criação do PR pode acionar as instalações/testes já definidos no CI isolado.
Não foi criada ou instalada ferramenta por esta entrega, nem disparado workflow
manual. O job Publish Laravel application exige `refs/heads/main`; nenhum merge
ou push em main é autorizado por este trabalho.

Commit, URL do PR, checks do head exato, pendências e resultado da revisão ficam em
REI-16. Testes de aplicação não foram executados localmente; nenhum deploy ou
homologação de produto foi realizado.

**Próxima etapa recomendada:** escolher um fluxo de autorização/multiempresa e
produzir uma matriz documental de requisito versionado, controle implementado,
teste existente e evidência de execução já disponível. Objetivo: reduzir a lacuna
entre fonte e aplicação antes de propor mudanças. Impacto: rastreabilidade.
Riscos: confundir ausência de prova com falha, trabalhar sobre SHA antigo ou
publicar dados privados. Validação: links exatos, cenários e limites explícitos.
Não inclui implementação, novas Skills, instalação, automação, merge ou deploy;
qualquer ampliação exige escopo e autorização próprios.
