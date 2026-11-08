# Operação autônoma de Julia

## Estado e escopo

Este pacote prepara um piloto manual, sem cron ativo, merge ou deploy automático.
Sua inclusão no repositório não comprova execução do modelo. A integração com o
executor, o orçamento e o primeiro ciclo completo precisam ser validados antes
de ativar recorrência. O executor é Codex via GitHub Actions; não é uma sessão
permanente da Dot nem herda automaticamente sua memória ou plugins do ChatGPT.

Responsabilidade: governança e operação do **Cloud**, sem mudança de runtime,
banco, catálogo ou preços da aplicação. Domínios Law, Lead e Styles permanecem
em seus repositórios; o piloto recusa contratos desses produtos.

## Autonomia autorizada

Uma tarefa aprovada permite implementar, testar, corrigir, documentar e publicar
uma branch/PR dentro dos caminhos contratados. Decisões técnicas reversíveis
seguem código e documentação sem confirmações intermediárias. Persistir até
entregar ou comprovar um impedimento; não esperar respostas opcionais.

O executor não pode alterar workflows, ferramentas de execução, instruções,
segredos, dependências, migrations, políticas de segurança, arquitetura ou
condições comerciais. Não acessa produção nem dados reais. Uma tarefa que precise
dessas mudanças deve ser planejada no ciclo diurno e autorizada separadamente.

## Fila e autorização vinculada ao conteúdo

1. Criar uma issue pelo template **Tarefa autônoma Julia**, com objetivo,
   contrato JSON único, produto `cloud`, caminhos permitidos, critérios de aceite
   e dependências (números de issues). Usar arquivos exatos ou diretórios com `/`
   final; não usar curingas nem caminhos ascendentes.
2. Obter o corpo exato da issue, preservar seus bytes e calcular SHA-256 com
   `node tools/julia/operations.mjs digest <arquivo>`. Um agente conectado pode
   preparar a tarefa e calcular o hash, mas precisa de autorização de Jorge para
   registrar a aprovação em seu nome.
3. Jorge comenta exatamente `/julia approve HASH`. O seletor confere a identidade
   `jorgewreis`, o hash do corpo atual e o último comando válido do proprietário.
   Comentários de terceiros não autorizam. `/julia revoke` revoga; editar a issue
   invalida o hash. Comandos devem estar em comentários separados, sem texto extra.
4. Dependências precisam estar fechadas. PRs não são aceitos como dependência:
   use uma issue e só a feche após a integração correspondente.

Não é necessário autorizar cada arquivo depois disso. A automação não aprova suas
próprias tarefas nem fecha issues automaticamente.

## Ciclo e continuidade

| Etapa | Comportamento |
| --- | --- |
| Selecionar | Proprietário dispara manualmente na `main`; escolhe uma issue indicada ou a mais antiga autorizada e disponível. |
| Retomar | Reutiliza `julia/issue-N` quando houver PR em rascunho e lê o checkpoint. PR fora de rascunho fica aguardando revisão. |
| Preparar | Faz merge da `main` na branch retomada, preservando histórico; conflito vira bloqueio. Instala PHP 8.4, Node 24 e dependências sem credenciais de produção. |
| Implementar | Até 180 minutos de agente; resolve o escopo e atualiza `.julia-checkpoint.md` durante o trabalho. |
| Capturar | Mesmo após falha do agente, tenta salvar checkpoint e patch limitado a 200 arquivos e 2 MiB. Arquivos novos de `tests/` são capturados apesar do ignore atual; caminhos rejeitados ficam num relatório e fora do patch. |
| Publicar | Outro runner revalida autorização, hash, main, PR, caminhos e modos; aplica o patch sem executar código proposto, publica sem force e cria/atualiza PR em rascunho. |
| Validar | Dispara explicitamente `Julia delivery checks` na branch; o `GITHUB_TOKEN` não dispara CI por simples push/abertura de PR. |
| Revisar | Examinar critérios, checkpoint, checks independentes, homologação necessária e diff. Marcar pronto para revisão somente com evidências suficientes. |

O piloto executa uma tarefa por disparo. Tarefas inválidas, não autorizadas,
dependentes ou já entregues são ignoradas em favor da próxima elegível. Não há
garantia de terminar uma funcionalidade em uma noite nem de usar toda a janela.
O timeout total do job de implementação é 210 minutos, reservando tempo para
preparação/captura. Um encerramento abrupto do runner pode impedir o upload final;
não confundir recuperação por checkpoint com garantia contra qualquer falha.

O checkpoint começa com `Julia-State: in_progress`, `Julia-State: blocked` ou
`Julia-State: ready`. PRs com estado ready/blocked saem da seleção automática;
um disparo que indique explicitamente a issue pode retomá-los. Falha de preparo
ou publicação é registrada como comentário de bloqueio na issue, permitindo que
outras tarefas avancem. Caminhos rejeitados forçam estado blocked mesmo quando
o agente declarou ready. A indicação ready é declaração local, não aceite.

Se a main avançar, o PR deixar de ser rascunho ou alguém alterar sua branch,
recusar publicação e preservar o artefato por 14 dias. Reconciliar sem sobrescrever
o trabalho diurno. Uma branch sem PR é tratada como publicação interrompida e
exige reconciliação; nunca apagá-la ou forçar push.

## Papéis e procedimentos

- `julia_coordinator`: plano, dependências, critérios, checkpoint e entrega.
- `fokus_implementer`: implementação delimitada e verificações.
- `fokus_reviewer`: revisão sem edição, com achados fundamentados.
- `.agents/skills/fokus-deliver/SKILL.md`: procedimento versionado do projeto,
  lido explicitamente pelo prompt, inclusive se não houver descoberta automática.

Os arquivos TOML descrevem papéis; o executor só delega se oferecer suporte real.
No modo sequencial, declarar ausência de revisão por outro agente. O procedimento
do repositório não foi instalado como skill pessoal nem altera as configurações
permanentes da Dot.

O revisor recebe instrução de não editar; isso não constitui um sandbox separado.
Os agentes herdam as permissões do processo principal. A barreira técnica de
publicação é a conferência de escopo em outro runner, não o nome do papel.

## Validação e critérios de pronto

`julia-delivery-checks.yml` roda os testes do controlador, PHPUnit, Pint nos PHP
alterados, build Vite, tokens, SEO e contrato de Backoffice, sem secrets. A
homologação existente em `security-homologation.yml` fornece MySQL/Redis e deve
ser executada e examinada para mudanças relevantes em acesso, persistência e
isolamento. O piloto não presume essa cobertura a partir de testes SQLite.

O agente registra verificações locais, mas a evidência independente é o workflow
executado na branch publicada. Testes verdes não substituem aceite funcional,
revisão de segurança ou exame no navegador para mudanças de interface.

| Estado | Evidência mínima |
| --- | --- |
| Autorizada | Contrato atual com aprovação válida do proprietário. |
| Em execução | Run com tarefa selecionada. |
| Bloqueada/parcial | Checkpoint com causa, progresso real e próximo passo; PR em rascunho ou artefato. |
| Pronta para revisão | Critérios demonstrados, checks pertinentes aprovados e cobertura/limitações explícitas. |
| Integrada | Merge efetivo; conclusão da issue após aceite. |
| Publicada | Deploy comprovado, separado do estado do PR. |

## Ativação e custos

Antes do primeiro disparo do agente:

- Integrar este pacote após revisão. `workflow_dispatch` precisa estar disponível
  na branch padrão; integrar na main aciona o deploy existente, por isso o merge
  permanece uma decisão explícita.
- Disponibilizar `JULIA_OPENAI_API_KEY` como secret dedicado, com acesso restrito;
  não copiar a chave para `.env` nem para o código. A chave é passada apenas à
  action oficial com `unprivileged-user`, usando a conta efêmera `julia` sem
  sudo; os jobs que publicam não recebem a chave. O runner administrativo
  conserva sudo apenas para preparar e recuperar a propriedade dos arquivos.
- Definir as variables `JULIA_CODEX_MODEL` e `JULIA_CODEX_VERSION` para uma versão
  validada. Ausência deve impedir o piloto, sem selecionar silenciosamente outro
  modelo ou baixar uma versão não revisada.
- Definir orçamento/alertas no projeto OpenAI e verificar limites do provedor.
  Timeout limita duração, não é teto financeiro rígido. A assinatura ChatGPT não
  comprova créditos de API para este workflow; nenhum crédito foi provisionado.
- Habilitar a criação de PRs pelo GitHub Actions nas configurações do repositório,
  se estiver desabilitada. A política organizacional pode impedir essa operação.
- Usar uma tarefa pequena e sintética, disparar o piloto e conferir branch,
  checkpoint, PR, verificações e retomada. Depois testar revogação e conflito com
  trabalho diurno, sem produção.

Somente após esse aceite, ativar cron às `02:00 UTC` (23h America/Bahia) e adaptar
a restrição de ator do seletor para o evento agendado, preservando autorização
por tarefa. O cron permanece comentado de propósito. Para parar, desabilitar o
workflow ou revogar tarefas; um run já iniciado revalida autorização ao publicar.

Não criar um lembrete que apenas peça ao usuário para iniciar o trabalho: a
recorrência deve acionar o executor validado.

No primeiro piloto, a estratégia `drop-sudo` da action oficial falhou ao tentar
restringir `/run/dbus/system_bus_socket`, antes de executar o modelo. A operação
passou a usar a estratégia oficial `unprivileged-user`, sem recorrer a `unsafe`.
Um patch vazio agora falha explicitamente e registra bloqueio, em vez de produzir
um resumo que sugira criação de PR. Falha do agente com patch recuperável força
estado blocked mesmo que seu checkpoint declare ready.

O segundo piloto confirmou acesso ao modelo, mas revelou que testes de acesso
com caminhos relativos não comprovam acesso aos caminhos absolutos sob o HOME
privado do runner. O checkout, o contrato e a saída do agente ficam agora sob
`/home/julia`, com `TMPDIR=/tmp`. O contrato é copiado como somente leitura,
enquanto o controlador continua em `/opt/julia`. O CI executa o mesmo script de
preparação com fixtures, sem chave de API, e verifica leitura, shell e escrita
antes de qualquer teste com o modelo.

O terceiro piloto comprovou criação de documento, checkpoint, PR em rascunho e
dispatch de checks, mas registrou falhas locais de subprocessos Git no sandbox.
As invocações Git do controlador não precisam de stdin: ele é explicitamente
ignorado, como nos fixtures de integração, mantendo stdout/stderr capturados.
O CI passa a executar a suíte dentro do sandbox real do Codex, sem modelo nem
chave, além dos testes no runner comum. Configurações Git usam o diretório XDG
da conta isolada, em vez de tentar ler o HOME privado do runner.

## Operação diurna

Trabalhar em branches próprias e enviar alterações ao GitHub antes da passagem
de turno. De manhã, revisar PRs e checkpoints; resolver somente decisões materiais.
Revalidar branches após qualquer avanço da main. Não reutilizar a branch Julia
para mudanças humanas enquanto a execução estiver ativa.

Fontes técnicas: [Codex GitHub Action](https://developers.openai.com/codex/github-action)
e [modo não interativo](https://developers.openai.com/codex/noninteractive), além de
[configuração de subagentes](https://developers.openai.com/codex/subagents).
