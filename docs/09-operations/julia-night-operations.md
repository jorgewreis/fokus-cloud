# Operação autônoma de Julia

## Objetivo e execução nativa

Julia coordena entregas autorizadas do Fokus Cloud com os recursos nativos da
assinatura ChatGPT/Codex, sem chave ou cobrança de API de modelos, compra de créditos
ou contratação de serviços adicionais. Os workflows e scripts do executor anterior
por API foram removidos. As rotinas são agendadas no ChatGPT, não no GitHub Actions.
Arquivos neste repositório documentam o método; não iniciam agentes por si mesmos.

A execução depende de ferramentas, permissões, ambiente e limites da assinatura.
Não prometer processamento contínuo 24 horas. Nunca usar API paga como fallback.
Se um ambiente não estiver disponível, registrar o impedimento com precisão e
continuar somente o trabalho independente acessível. Não simular testes ou deploy.

## Agendamentos nativos configurados

| Rotina | Agenda em America/Bahia | Finalidade |
| --- | --- | --- |
| Julia — manutenção de madrugada | Diária, referência 03h em janela flexível | Inspecionar, corrigir, validar e conciliar documentação |
| Julia — concluir pendências | A cada hora, no minuto 20 | Retomar entregas incompletas e acompanhar CI |

Criação e habilitação confirmadas em 08/11/2026. As primeiras tentativas das duas
rotinas falharam com erro genérico do agendador. A rotina horária concluiu uma rodada
em 08/11/2026, das 15h37 às 15h39 (America/Bahia), com acesso efetivo ao Linear e ao
GitHub e checkpoints publicados. Essa evidência valida a execução horária, mas não
substitui a validação independente da manutenção de madrugada nem comprova detalhes
do ambiente herdado que o agendador não informa.
O estado oficial das tarefas fica no [Linear — Fokus Cloud](https://linear.app/reis-developer/project/fokus-cloud-f279b0530261).
A [issue GitHub #45](https://github.com/jorgewreis/fokus-cloud/issues/45) guarda links e evidências da configuração.
A rotina de memória do Notion permanece independente e não foi alterada.

## Fontes e autorização

Ler `AGENTS.md`, `docs/README.md`, esta página, a
[fila de tarefas](julia-task-queue.md), issues/PRs e checkpoints atuais.
Instruções recentes de Jorge prevalecem sobre a fila. Pedidos de implementação e
a autorização das rotinas cobrem inspeção e correção de defeitos comprovados,
testes necessários e documentação correspondente. Não requerer `/julia approve`,
hash, orçamento ou confirmação por arquivo. Não implementar funcionalidades novas
apenas porque parecem úteis: registrar como proposta quando não autorizadas.

Classificar Cloud/Styles/Law/Lead antes de agir. Preservar isolamento multiempresa,
Policies, CSRF, auditoria e catálogo. Não copiar regras de produtos para o core.
Usar dados sintéticos. Alterações comerciais, operações destrutivas e acesso a dados
reais não estão incluídos na rotina. Reutilizar autorização expressa de merge/deploy
quando ela cobrir a entrega; caso contrário, entregar PR pronto para revisão.
Um push em main dispara o deploy existente: considerar essa consequência antes de integrar.

## Rotina de madrugada

1. Reconciliar tarefas pendentes e PRs com a main atual; priorizar falhas confirmadas,
   testes quebrados, segurança e entregas autorizadas interrompidas.
2. Inspecionar logs de CI acessíveis, diffs, testes e documentação pertinentes.
   Não fazer varredura invasiva em produção nem consultar dados reais.
3. Reproduzir defeitos com dados sintéticos antes de corrigir. Escolher a menor
   correção sustentável, preservar contratos e adicionar teste de regressão quando útil.
4. Validar conforme impacto: PHPUnit/Laravel, Pint nos PHP alterados, build Vite,
   tokens, SEO, contratos visuais e MySQL/Redis/migrations quando aplicável.
   Para documentação isolada, verificar links, coerência e diff; não exigir toda
   a suíte sem motivo. Distinguir falha preexistente, introduzida e de infraestrutura.
5. Corrigir documentação divergente do código validado; atualizar índices e fila.
6. Publicar branch/PR e checkpoint; informar resultados comprovados e próximo passo.

A rotina pode continuar uma tarefa enquanto houver capacidade. Não multiplicar
execuções nem criar alterações artificiais para demonstrar atividade.

## Rotina de hora em hora

Revisar pendências, PRs, CI e checkpoints de entregas autorizadas. Primeiro verificar
se já há execução ativa da mesma tarefa. Se houver, acompanhar a execução existente;
não iniciar outro implementador nem disputar a mesma branch.
Retomar a pendência de maior prioridade que tenha próxima ação executável; acompanhar
CI, corrigir falhas introduzidas e concluir critérios faltantes. Se estiver bloqueada,
verificar se o bloqueio mudou e avançar outra tarefa independente autorizada.
Não repetir a mesma tentativa sem evidência nova. Não fazer perguntas opcionais.
Se não houver progresso ou bloqueio novo, não enviar notificação horária.

## Coordenação e persistência

Uma única tarefa no Linear é a fonte oficial do estado de cada entrega. Vincular
issue/PR e registrar na tarefa a sessão/dono, branch, commit-base e último checkpoint
antes de modificar arquivos. Inspecionar
atividade e comentários recentes a cada retomada. Quando não for possível confirmar
exclusividade, acompanhar/registrar a pendência e evitar escrita concorrente.
Essa coordenação é um procedimento operacional, não um lock distribuído automático.

Usar `.codex/agents/` quando o ambiente oferecer subagentes: Julia coordena,
implementador executa e revisor examina o diff sem escrita. Delegar somente trabalho
independente útil; Julia verifica resultados e continua responsável pela entrega.
Arquivos TOML não significam agentes em execução e não impõem isolamento técnico.

Preservar alterações alheias; usar branch própria e nunca force push. Ao parar por
limite, salvar e publicar checkpoint na tarefa Linear com links para issue/PR; um arquivo local isolado não basta.
Não depender da permanência deste checkout entre execuções.

## Estado e conclusão

Usar os estados existentes do time Reis Developer: Todo/Backlog para pendente,
In Progress para execução, In Review para revisão e Done apenas após o aceite.
Bloqueios são dependências e descrição explícita na tarefa; não inventar um estado
Blocked que o time não possui. Merge e deploy têm evidências separadas.

| Estado | Evidência necessária |
| --- | --- |
| pendente | Pedido autorizado, objetivo e critérios registrados |
| em_execucao | Sessão/dono, branch, base e próxima ação registrados |
| bloqueada | Causa comprovada, tentativa e condição para retomar |
| pronta_para_revisao | Critérios atendidos, diff revisado e checks reais publicados |
| integrada | Commit na main; deploy confirmado separadamente quando aplicável |

O checkpoint deve conter: tarefa e autorização, produto/escopo, branch/PR/commit,
critérios atendidos e faltantes, comandos/resultados, decisões, bloqueios e próxima ação.
Nunca guardar segredos, dados pessoais ou transcrições completas. Não chamar de
concluída uma implementação sem validação ou uma publicação cujo CI ainda não terminou.

Notificar conclusão, falha relevante ou decisão indispensável; agrupar atualizações.
Ao solicitar ajuda, informar somente o dado/ação que falta e o efeito no trabalho.

## Limpeza do executor anterior

Foram retirados os workflows `julia-night.yml` e `julia-delivery-checks.yml`, o
controlador `tools/julia/`, seus testes exclusivos e o procedimento de autorização
por hash. O job de qualidade da aplicação, sem API, foi preservado em `application-quality.yml`
(PHPUnit, Pint, build, tokens, SEO e contrato). O CI/deploy normal também foi preservado.
Credenciais e variáveis do executor antigo não são necessárias. Sua remoção no
provedor deve ser verificada separadamente; não afirmar revogação de uma chave
apenas porque seu consumidor foi removido. Histórico Git e execuções antigas são
registros históricos, não componentes ativos.
