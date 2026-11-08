# FEKS: validação das Skills, etapa 4

Data: 08/11/2026 UTC. [REI-19](https://linear.app/reis-developer/issue/REI-19).
Escopo: três Skills, referências e documentação. SHA publicado, URL do PR e resultados
remotos são registrados no PR e na tarefa, sem presumir integração.

## Baseline e preparação

Main observada: `8dd9835053b7dc986343e8cfc44b5c35bdde920a`; árvore remota completa,
1.309 entradas, sem truncamento. Apenas `AGENTS.md` com esse nome e nenhum pacote
`.agents/skills` integrado. Foram lidos AGENTS, índice docs, fronteiras, operação/fila,
perfis implementador/revisor e workflows de qualidade/validação pertinentes.

PRs #50/#51/#52 ainda draft/não integrados nos heads fixados no
[guia de composição](feks-skills-composition.md). Sem comentários/reviews na consulta
inicial. #52 tinha workflows de qualidade e validação concluídos com sucesso. Isso
não conta como validação desta etapa. Fontes anteriores foram conferidas pelos
blobs remotos; nenhum documento/Skill de #52 é copiado ou alterado.

Tarefa deduplicada em P-REI-3 e branch exclusiva diretamente da main:
`skills/rei-19-feks-architecture-quality-review-20261108`.
Staging isolado, sem modificar os checkouts concorrentes. Publicação por árvore
baseada no commit da main, preservando arquivos existentes. O Git local serve para
inspecionar somente os arquivos novos; não é um checkout completo da aplicação.

## Criação e validação estrutural

Ferramentas existentes: Python 3.12.14, PyYAML 6.0.3, Codex CLI 0.167.0-alpha.4.
Não houve instalação. Skill-creator nativo e referência openai.yaml foram lidos.

Para cada nome `software-architecture-review`, `testing-quality-engineering` e
`code-review-refactoring`, os helpers nativos executados foram:

```text
init_skill.py <nome> --path <staging>/.agents/skills --resources references
generate_openai_yaml.py <pasta> --interface display_name=... --interface short_description=... --interface default_prompt=...
quick_validate.py <pasta>
```

Os três validadores retornaram `Skill is valid!`, exit code 0. Frontmatter com nome
igual à pasta, descrições discriminantes e metadata; interface com strings,
short_description de 25–64 caracteres e default_prompt citando `$<nome>`.
Sem dependência MCP, allowed-tools ou alteração de política de invocação.

A estrutura segue a convenção de #52: um SKILL.md, agents/openai.yaml, uma referência
prática e um cenário por pacote. Mais dois documentos: 14 arquivos novos ao todo.
UTF-8, newline final, whitespace, 20 links relativos e diff aditivo foram verificados
com sucesso. Os links GitHub às fontes anteriores foram recuperados nos SHAs fixos;
OpenAI/Agent Skills foram abertos seletivamente, sem crawler de todas as âncoras.
A validação estrutural não prova decisões corretas.

## Descoberta Codex observada

Com `CODEX_HOME` temporário e o staging como cwd, executou-se:

```text
codex app-server --listen stdio://
initialize; initialized
skills/list {"cwds":["<staging>"],"forceReload":true}
```

Resposta real, resumida:

```text
software-architecture-review: scope=repo, enabled=true, caminho SKILL.md correto
 testing-quality-engineering: scope=repo, enabled=true, caminho SKILL.md correto
      code-review-refactoring: scope=repo, enabled=true, caminho SKILL.md correto
errors=[]; displayName, shortDescription e defaultPrompt corretos nas três
```

Servidor encerrado após a consulta. Nenhuma thread/turno de modelo ou chamada de API
paga foi iniciada. O comando de versão emitiu aviso de impossibilidade de criar
aliases PATH em filesystem somente leitura, mas retornou versão e exit 0; não houve
contorno ou mudança de permissões. A descoberta concluiu com sucesso.

Isto comprova parsing e enumeração nessa versão/diretório, não seleção implícita pelo
modelo, injeção completa de prompt, uso em todos os agentes ou disponibilidade em
outro checkout. Não repetimos o caminho debug prompt-input bloqueado na etapa 3.
As duas Skills de #52 não estão neste staging e não se alegou descoberta conjunta.

## Ensaios comportamentais independentes

Três avaliadores distintos receberam a respectiva Skill, referências e pedido/artefatos
fictícios, sem gabarito, suspeita ou resultado prévio. Não leram os relatórios alheios
nem este documento. Foi aplicação por leitura explícita, não seleção automática.
Usaram Python padrão em memória, sem rede, pacotes, produção ou dados reais.
Caminhos `fixture/*` são rótulos; não foi inventado commit para os modelos.

### A. software-architecture-review

**Entrada:** [cenário A](../../.agents/skills/software-architecture-review/references/synthetic-scenario.md),
contrato de revogação em até 300s, módulos no mesmo processo e proposta de réplica
diária, extração de processo e regras de domínio no Visual.

**Comportamento:** distinguiu atual descrito, proposta e prova disponível. Identificou
que em t=301/302 a réplica ainda autoriza após revogação em t=1; após falha de refresh
continua autorizando em t=86400/172800. Reconheceu consulta comum já existente, sem
inventar prova de fail-closed da implementação atual. Rejeitou promessa de escala
sem medição e transferência de regras de casos ao Visual.

**Resultado:** oito combinações tempo/refresh, 16 asserções de saídas e três
contraexemplos, total 19 aprovadas, exit 0. O verde confirma a reprodução da violação,
não aprovação da proposta. Recomendou manter o desenho atual e validar negação em
falhas; alternativas distribuídas foram condicionais, sem propor instalação/migração.

**Avaliação/limites:** identificou corretamente incompatibilidade temporal e fronteira.
Não afirmou segurança ou desempenho real. Não basta TTL=300 sem considerar propagação
relógio e falha; observação feita pelo avaliador. Modelo não inclui transporte,
persistência da réplica, autenticação entre serviços ou concorrência. Nenhuma
conclusão infundada ou recomendação incompatível foi identificada neste ensaio.

### B. testing-quality-engineering

**Entrada:** [cenário B](../../.agents/skills/testing-quality-engineering/references/synthetic-scenario.md),
contrato `now < expires_at`, relógio compartilhado e três registros de execução.

**Comportamento:** reproduziu dependência de ordem. `expired` avança relógio de 100
para 106; `fresh` depois falha. Identificou estado de fixture como causa determinística,
sem atribuir erro ao comparador `<`, sem ordenar casos, sleeps, retries ou skip.

**Resultado:** original fresh/expired: 2 passam; expired/fresh: 1 passa/1 falha;
fresh isolado: 1 passa. Total original: 4 aprovações e 1 falha. Variante experimental
separada reiniciou Clock antes de cada caso, sem mudar active ou originais: 40
asserções passaram (duas ordens, dez repetições). Fronteiras 104/105/106: 3 passaram;
comparador hipotético `<=` demonstrou sensibilidade do oráculo na igualdade.
Harness exit 0 porque esperava a reprodução da falha original; não é suíte original
verde. Comando: `python3 - <<'PY' ... PY`, exclusivamente em memória.

**Avaliação/limites:** diagnóstico correto e ajuste mínimo proposto em setup/fixture
do runner real, ainda desconhecido. Namespaces novos não são subprocessos nem runner
do produto. Correção não aplicada ao staging/aplicação. Não houve conclusão sem base,
upgrade, enfraquecimento de assert ou recomendação incompatível neste caso.

### C. code-review-refactoring

**Entrada:** [cenário C](../../.agents/skills/code-review-refactoring/references/synthetic-scenario.md),
base/head de paginação, contrato de visibilidade, consumidor UI e fixtures homogênea/mista.

**Comportamento:** demonstrou regressão ao paginar antes de filtrar. Em MIXED/A,
limite 2: offsets 0/2/4 retornam base `[a1,a2]`, `[a3]`, `[]`, contra head `[]`,
`[a1,a2]`, `[a3]`. A UI para na primeira página vazia: perde todos os resultados.
Reconheceu que filtro final permanece e não alegou vazamento entre empresas.

**Resultado:** `python -B - <<'PY' ... PY` extraiu diretamente os blocos originais
para memória. 35 asserções aprovadas, exit 0: sete casos com comparação, não mutação
e visibilidade; consumidor, página parcial, empate de ordenação e contagem de chave.
O teste existente passa em ambas, mostrando seu alcance estreito. No caso misto,
base avalia três chaves e head cinco; isso não é benchmark de tempo.

**Avaliação/limites:** regressão corretamente sustentada, prioridade alta restrita ao
modelo e ajuste mínimo de restaurar filtro antes de recorte. Nenhuma reescrita ampla,
conclusão de exposição ou ganho de desempenho sem prova. Não há ORM, rede, banco,
autorização externa ou teste de produção. Verde significa reprodução bem-sucedida,
não aceite do head fictício.

## Revisão, publicação e limitações finais

Os ensaios sustentam utilidade em um caso por Skill, não qualidade universal,
resistência a toda injeção, precisão geral ou preferência automática entre Skills.
Os três SKILL.md e referências funcionam sem exigir ferramentas externas novas.
A composição das cinco está documentada; integração e invocação conjunta não testadas.

PHP/Composer não foram localizados neste executor; suítes de aplicação não executadas
localmente. Conforme o procedimento do repo, docs isolados exigem coerência, links e
diff, sem toda suíte local por padrão. Workflows existentes foram lidos: PR pode
acionar qualidade/validação em runner isolado; Publish exige `refs/heads/main`.
Nenhum workflow foi alterado ou acionado manualmente. Checks anteriores não são
checks deste head; resultado remoto será registrado no PR/Linear após consulta.

Sem aplicação, dependências, bancos, infraestrutura, workflows, configuração global,
políticas de segurança, produção, Notion, serviços pagos, merge, deploy ou agendamentos.
Revisão final independente somente leitura: nenhum achado bloqueante nos 14 arquivos,
links/metadados e calibração das alegações. O revisor não reexecutou os ensaios.
Hashes dos blobs e compare remoto devem complementar o checkpoint da publicação.
