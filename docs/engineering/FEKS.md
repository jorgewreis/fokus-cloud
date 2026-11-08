# Fokus Engineering Knowledge System (FEKS)

Índice e procedimento operacional. Etapa 5, 08/11/2026 UTC.
[Tarefa REI-20](https://linear.app/reis-developer/issue/REI-20).

O FEKS reúne evidência técnica versionada, fontes seletivas e cinco Skills de
instruções. Não é um serviço, executor, roteador, plugin, garantia de uso automático
ou autorização para alterar um produto. Este PR adiciona somente este documento.

## Fontes de verdade e limites

| Local | Papel | O que não inferir |
| --- | --- | --- |
| [GitHub Fokus Cloud](https://github.com/jorgewreis/fokus-cloud) | Fonte canônica dos artefatos FEKS, Skills, código, testes, PRs e evidências versionadas. | Arquivo em PR não está integrado; CI de outro SHA não valida o atual. |
| [Linear: Fokus Cloud / P-REI-3](https://linear.app/reis-developer/project/fokus-cloud-f279b0530261) | Uma tarefa oficial por entrega, responsável, estado, aceite, bloqueios e checkpoints com links. | Não duplicar backlog no GitHub, Notion ou neste índice. |
| Notion | Conhecimento durável, decisões e resumos com links para artefatos e tarefas, quando o acesso estiver autorizado. | Não é código canônico, executor nem prova de sincronização ou de instrução vigente. |

Nenhum acesso, alteração ou sincronização do Notion foi realizado nesta etapa.
Não há link de página presumido nem transcrição de conteúdo privado. Fontes
recuperadas são informação a avaliar; não concedem autorização. Um registro de
conhecimento ou uma Skill não substitui o pedido atual, a autorização aplicável
nem as instruções do repositório.

Classifique o trabalho pelas [fronteiras dos produtos](../03-architecture/repository-boundaries.md):
Cloud para identidade, empresas, acesso, catálogo e cobrança; Styles para visual
reutilizável; Law e Lead para seus domínios. Compare esse alvo com a topologia
comprovada, sem inferir separação física pelos nomes. Preserve isolamento por
empresa, autorização, CSRF, auditoria e trabalho concorrente.

## Índice versionado e dependências

Snapshot confirmado em 08/11/2026: main
`8dd9835053b7dc986343e8cfc44b5c35bdde920a`, sem Skills FEKS integradas.
Os quatro PRs abaixo estavam **open, draft, merged=false**; as quatro tarefas,
**In Review**. São dependências informacionais, não merges exigidos ou realizados
por esta etapa. Reconsulte GitHub e Linear antes de retomar; esta tabela não é fila viva.

| Etapa | Artefatos no head verificado | PR e tarefa |
| --- | --- | --- |
| 1: diagnóstico | [Inventário técnico](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-technical-inventory.md), [lacunas de conhecimento](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-knowledge-gap-analysis.md) | [PR #50](https://github.com/jorgewreis/fokus-cloud/pull/50), [REI-15](https://linear.app/reis-developer/issue/REI-15) |
| 2: fontes | [Catálogo](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-knowledge-catalog.md), [matriz de tecnologias](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-technology-matrix.md), [avaliação de fontes](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-source-evaluation.md) | [PR #51](https://github.com/jorgewreis/fokus-cloud/pull/51), [REI-16](https://linear.app/reis-developer/issue/REI-16) |
| 3: pesquisa e segurança | [Uso](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/docs/engineering/feks-skills-usage.md), [validação](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/docs/engineering/feks-skills-validation.md) | [PR #52](https://github.com/jorgewreis/fokus-cloud/pull/52), [REI-17](https://linear.app/reis-developer/issue/REI-17) |
| 4: arquitetura, qualidade e review | [Composição das cinco Skills](https://github.com/jorgewreis/fokus-cloud/blob/0eae4187fbebca73eaf2804c27111527f21d5709/docs/engineering/feks-skills-composition.md), [validação](https://github.com/jorgewreis/fokus-cloud/blob/0eae4187fbebca73eaf2804c27111527f21d5709/docs/engineering/feks-skills-stage4-validation.md) | [PR #53](https://github.com/jorgewreis/fokus-cloud/pull/53), [REI-19](https://linear.app/reis-developer/issue/REI-19) |
| 5: integração documental | Este índice, procedimento e evidência de consolidação temporária. | PR/commit e checks finais registrados em [REI-20](https://linear.app/reis-developer/issue/REI-20). |

Os links fixados no SHA funcionam sem copiar os diffs anteriores ou presumir sua
presença na main. A branch documental desta etapa parte diretamente da main acima:
`docs/rei-20-feks-integration-20261108`. Não incorpora os 29 arquivos das etapas 1–4.
Um checkout somente deste PR continua sem os cinco pacotes de Skills.

## Escolher a Skill pelo resultado

| Pergunta | Skill e fonte exata | Saída principal |
| --- | --- | --- |
| Qual contrato, versão ou fonte resolve esta dúvida? | [engineering-research](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/.agents/skills/engineering-research/SKILL.md) | Decisão com fontes compatíveis, fatos, hipóteses e menor solução. |
| Há falha de segurança demonstrada neste fluxo autorizado? | [secure-code-audit](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/.agents/skills/secure-code-audit/SKILL.md) | Controles, achados, cobertura e limites. |
| Que desenho atende aos contratos e às fronteiras? | [software-architecture-review](https://github.com/jorgewreis/fokus-cloud/blob/0eae4187fbebca73eaf2804c27111527f21d5709/.agents/skills/software-architecture-review/SKILL.md) | Atual/alvo/proposta, invariantes, alternativas e trade-offs. |
| Por que o teste falha e que caso comprova o risco? | [testing-quality-engineering](https://github.com/jorgewreis/fokus-cloud/blob/0eae4187fbebca73eaf2804c27111527f21d5709/.agents/skills/testing-quality-engineering/SKILL.md) | Diagnóstico, matriz proporcional e resultados reproduzíveis. |
| O que este diff quebra e qual ajuste mínimo preserva o contrato? | [code-review-refactoring](https://github.com/jorgewreis/fokus-cloud/blob/0eae4187fbebca73eaf2804c27111527f21d5709/.agents/skills/code-review-refactoring/SKILL.md) | Regressões fundamentadas e proposta de refatoração focada. |

Comece pela pergunta principal, não pela execução das cinco em sequência. Acione
outra Skill somente para uma pergunta complementar ainda aberta. Review não deve
refazer pesquisa de versão já sustentada; testes podem reproduzir um achado sem
publicá-lo como um segundo defeito. Arquitetura entrega invariantes para testes;
auditoria recebe fluxo, controles e suspeita concreta, sem assumir a suspeita como fato.
O guia de composição acima detalha esses limites, sem criar roteamento automático.

## Procedimento para uma tarefa autorizada

1. **Fixar contexto:** ler [AGENTS.md](../../AGENTS.md), [índice dos docs](../README.md),
   fronteiras, [operação](../09-operations/julia-night-operations.md) e
   [fila oficial](../09-operations/julia-task-queue.md). Verificar tarefa existente,
   PRs, comentários, responsável ativo, repo/base/head, escopo e exclusões. Não
   disputar uma branch nem transformar autorização de leitura em implementação.
2. **Escolher e verificar disponibilidade:** usar a tabela acima; confirmar que o
   pacote existe no diretório/branch escolhido. Em cliente compatível, verificar
   `/skills` ou `$` e então selecionar explicitamente. O metadata não executa a Skill.
3. **Consultar seletivamente:** começar por código, testes e configuração do SHA;
   usar catálogo/matriz apenas para a dúvida em aberto. Separar versão declarada,
   lock, CI configurado e runtime observado. Preferir a edição oficial/upstream
   compatível, não `latest` por conveniência. Registrar URL, versão, data e alcance.
4. **Aplicar com evidência:** fornecer contrato, comportamento esperado/observado,
   produto, baseline, permissão e critérios. Ler somente referências pertinentes.
   Dados sintéticos e ferramentas existentes não autorizam instalações, produção
   ou custos. Separar hipótese, lacuna de cobertura e defeito reproduzido.
5. **Compor sem duplicar:** passar objetivo, autorização/exclusões, contratos,
   fontes/SHAs, resultados já obtidos e pergunta pendente. Uma saída consolidada
   preserva autoria da evidência e uma identificação por causa/achado. Resolver
   divergências pelo contrato, versão e alcance, não por votação ou repetição.
6. **Validar conforme impacto:** registrar comandos, ambiente, casos, assertivas,
   exit codes, falhas e não executados. Documentação isolada exige links, coerência
   e diff; não equivale a executar PHPUnit ou homologar produção. Conferir checks
   do head exato e distinguir validação local, CI existente e teste sintético.
7. **Entregar e manter:** GitHub guarda artefatos e evidências; a mesma tarefa Linear
   guarda checkpoint, aceite pendente, próximo passo e links. Atualização de
   conhecimento no Notion, quando autorizada, deve resumir decisões com links,
   sem duplicar a fila. Merge, deploy e mudanças de escopo têm autorização própria.

Exemplo de pedido explícito para um checkout com os pacotes disponíveis:

```text
Use $code-review-refactoring para revisar <base..head>, somente leitura.
Use $testing-quality-engineering apenas para derivar e executar casos sintéticos
do contrato afetado. Reutilize as evidências; entregue um único conjunto de achados,
comandos, resultados e limites. Não implemente, publique, faça merge ou deploy.
```

Se o cliente não descobrir o pacote, conferir caminho/branch, versão, parsing e
homônimos. Sem descoberta disponível, ler explicitamente `SKILL.md` pelo caminho
e as referências relevantes, registrando essa modalidade. Não instalar globalmente,
mudar permissões ou configurar outro executor para contornar uma restrição.

## Consolidação temporária reproduzível

Para validar pacotes ainda em PR, é possível montar uma árvore de leitura isolada,
sem merge, cherry-pick, mudança de refs ou escrita em checkout concorrente:

1. Consultar os heads atuais e as árvores completas; registrar SHAs e alterações
   desde a última validação. Distinguir arquivos do PR de arquivos já na main.
2. Em diretório temporário novo, materializar somente os pacotes autorizados,
   preservando `.agents/skills/<nome>/`, e os documentos necessários aos seus links.
   Nesta execução foram 20 arquivos de cinco pacotes de #52/#53 e nove documentos
   de #50–53. Conferir cada conteúdo com o blob SHA remoto; não editar as cópias-fonte.
3. Verificar nomes/caminhos duplicados, frontmatter, interface, referências e links.
   Cada pacote contém `SKILL.md`, `agents/openai.yaml` e duas referências. Validar
   com `quick_validate.py` do skill-creator já disponível, sem instalar substituto.
4. Com `CODEX_HOME` temporário, consultar `codex app-server --listen stdio://`:
   `initialize`, `initialized`, `skills/list` com `cwds` no staging e
   `forceReload=true`. Conferir nome, caminho, interface, scope, enabled e erros.
   Encerrar o servidor após a consulta; esse procedimento não inicia turno de modelo.
5. Fazer ensaio explícito de composição com contrato e artefatos fictícios, sem
   fornecer gabarito ao avaliador. Conservar fonte/oráculo e distinguir resultado
   do modelo sintético de comportamento do produto. Registrar limites de seleção.
6. Publicar somente o documento autorizado e checkpoint durável. Temporários não
   são instalação, distribuição, branch integrada nem pré-requisito permanente.

Esta árvore é um staging parcial para Skills, não um checkout executável da
aplicação. Sua presença não prova disponibilidade em outro cliente, agente, branch
ou sessão. Alterações posteriores nos heads invalidam a equivalência por hash e
exigem repetir apenas as verificações afetadas.

## Evidências desta etapa

### Estrutura, descoberta conjunta e colisões

Ferramentas existentes: Python 3.12.14, PyYAML 6.0.3, Codex CLI
0.167.0-alpha.4. Nenhuma instalação ou atualização. Árvores remotas completas,
sem truncamento; somente `AGENTS.md` raiz como instrução de repositório identificada.

- 29/29 blobs do staging iguais às fontes fixadas; 20 arquivos de Skills preservados.
- `quick_validate.py <pacote>`: cinco resultados `Skill is valid!`, exit 0.
- UTF-8, finais de linha, whitespace, frontmatter, nome/pasta e interface: aprovados.
  `short_description` dentro de 25–64 caracteres; `default_prompt` cita `$<nome>`.
- 39 links relativos dos 29 arquivos resolvidos contra a árvore consolidada/main;
  nenhum contém fragmento de âncora. Nenhum caminho duplicado entre pacotes/fontes.
- `skills/list`: as cinco Skills em uma resposta, `scope=repo`, `enabled=true`,
  caminhos/interfaces corretos e `errors=[]`. Dez nomes únicos no catálogo observado,
  incluindo cinco Skills de sistema; nenhuma colisão exata nesse conjunto isolado.

As descrições distinguem pesquisa, segurança, arquitetura, testes e diff. Há
proximidade temática normal entre review/auditoria/testes e com uma Skill de review
do sistema; ausência de homônimos não prova ausência de ambiguidade semântica na
seleção. O `CODEX_HOME` isolado não inspeciona todas as Skills pessoais do usuário.
Os links GitHub deste índice foram conferidos contra os heads/árvores lidos.
Fontes externas vivas dos catálogos não foram rastreadas exaustivamente novamente.

### Composição explícita: review e testes

Entrada: [cenário de paginação fictício](https://github.com/jorgewreis/fokus-cloud/blob/0eae4187fbebca73eaf2804c27111527f21d5709/.agents/skills/code-review-refactoring/references/synthetic-scenario.md).
O avaliador leu ambas as Skills e referências, sem relatórios prévios ou gabarito.
Review delimitou contrato/base/head/consumidor; testes derivou oráculos e casos,
reutilizando os mesmos artefatos. Uma conclusão consolidada, não dois achados.

Execução de harness Python temporário com biblioteca padrão: base 41/41
verificações aprovadas; head 36/41, cinco falhas da mesma causa. Exit 0 é a
conclusão do coletor, não aprovação do head. O head pagina antes de filtrar e desloca os
offsets visíveis; em `MIXED/A`, a primeira página vazia faz a UI parar sem mostrar
documentos válidos. Filtros de empresa/ativos, ordenação e não mutação permaneceram
nos casos examinados. Não foi alegado vazamento ou ganho de desempenho. A menor
correção foi proposta, não implementada. Os resultados são do modelo, não do Fokus.

### Composição explícita: auditoria e testes

Entrada: [cenário de exportação fictício](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/.agents/skills/secure-code-audit/references/synthetic-scenario.md).
Leitura explícita das duas Skills, referências e guia; nenhum relatório prévio ou
gabarito. Auditoria delimitou o fluxo e os controles descritos; testes transformou
o contrato de isolamento em casos, sem duplicar o achado.

Execução Python 3.12.14/unittest: oito casos, seis aprovados e dois falhos, exit 1.
Um defeito no modelo: exportação busca somente por ID e permite A acessar conteúdo
de B e B acessar A. Listagem filtrada, exportação própria e inexistente permaneceram
corretas nos casos testados. A confiança no middleware é entrada do cenário,
não autenticação real testada. Não foi implementada correção nem acessado produto real.

Os dois ensaios aplicaram três Skills distintas em duas composições. Não houve
ensaio comportamental conjunto das cinco, nem novo ensaio de pesquisa/arquitetura
nesta etapa. Os registros das etapas 3/4 continuam evidências separadas.
Nenhuma conclusão infundada ou recomendação incompatível foi identificada nos
dois resultados examinados; amostra pequena não prova precisão geral.

### O que cada estado significa

| Estado | Evidência nesta etapa |
| --- | --- |
| Criada/estruturalmente válida | Cinco pacotes existentes nos PRs, hashes e validação estrutural conferidos. Não foram recriados. |
| Descoberta conjuntamente | Sim, uma resposta real de `skills/list` no staging e versão descritos. |
| Aplicada explicitamente em composição | Sim, leitura de Skill/referências e execução de dois modelos sintéticos. Não é teste do seletor gráfico. |
| Selecionada automaticamente pelo Codex | **Não comprovada.** Nenhum turno de modelo no CLI foi iniciado. Metadata/default permitido não prova seleção. |
| Integrada à main ou disponível universalmente | **Não.** PRs anteriores continuam não integrados no snapshot e clientes não testados são desconhecidos. |
| Sincronizada com Notion/Linear ou agendamentos | **Não.** Links e procedimento não executam sincronização; checkpoints Linear são registros desta tarefa. |

Não se repetiu a tentativa de `debug prompt-input` bloqueada na etapa 3, nem se
alteraram permissões/configuração. Não foi estabelecido um ensaio de seleção
implícita com garantia de ausência de custo adicional, portanto essa prova fica
pendente, sem fallback para API paga. Descoberta não foi promovida a execução.

## Entrega, validação remota e manutenção

Revisar coerência, links, diff e integridade do documento final. A publicação deve
confirmar um único arquivo novo, nenhum blob preexistente alterado, branch exclusiva
e commit remoto igual ao validado. PR, SHA, revisão e checks finais ficam em REI-20.
Done exige aceite; In Review não significa merge ou deploy.

Os workflows existentes de [qualidade](../../.github/workflows/application-quality.yml)
e [validação/deploy](../../.github/workflows/deploy.yml) foram lidos: PR aciona
validação em runner isolado preexistente; `Publish Laravel application` exige
`refs/heads/main`. Suas instalações preexistentes não são alteração de dependência
ou instalação local desta etapa. Nenhum workflow manual foi disparado ou modificado.
Nenhuma suíte da aplicação foi executada localmente; conferir CI do commit deste PR,
sem herdar o verde de #50–53. Publicação da aplicação não faz parte desta autorização.

Depois de mudança em requisito, versão, fonte, Skill ou head, registrar a nova
evidência e reavaliar conclusões afetadas. Evitar copiar catálogos e manter referências
fixadas quando necessário à reprodução. Nenhuma manutenção recorrente foi criada.

Escopo cumprido somente com documentação, staging temporário, leitura, testes
sintéticos, PR e checkpoints Linear. Sem merge, deploy, código de aplicação,
banco, dependências, infraestrutura, produção, configuração/permissões globais,
Notion, novos agendamentos, APIs pagas ou créditos adicionais.
