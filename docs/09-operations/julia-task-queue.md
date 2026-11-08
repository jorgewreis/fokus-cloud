# Fila de tarefas de Julia

A fila oficial e seus estados ficam no [Linear — Fokus Cloud](https://linear.app/reis-developer/project/fokus-cloud-f279b0530261),
time Reis Developer. Este arquivo é um índice estável, não uma cópia dos estados.
GitHub guarda código, PRs e resultados de testes; Notion pode guardar decisões e
resumos com links. Não manter a mesma lista de pendências nas três ferramentas.

## Tarefas iniciais

| Tarefa Linear | Entrega |
| --- | --- |
| [REI-5](https://linear.app/reis-developer/issue/REI-5) | Remover executor API, preservar CI e configurar operação nativa |
| [REI-6](https://linear.app/reis-developer/issue/REI-6) | Reconciliar e concluir entregas autorizadas incompletas |
| [REI-7](https://linear.app/reis-developer/issue/REI-7) | Inspecionar e corrigir falhas comprovadas |
| [REI-8](https://linear.app/reis-developer/issue/REI-8) | Conciliar documentação com código e testes |
| [REI-9](https://linear.app/reis-developer/issue/REI-9) | Validar primeira execução nativa e remover credenciais antigas |

REI-9 depende da configuração REI-5. Consultar estados e evidências atuais no Linear.
A [issue GitHub #45](https://github.com/jorgewreis/fokus-cloud/issues/45) registra
configuração e links; não controla um segundo backlog. O piloto API #39/#42 foi encerrado.

## Passagem de turno

Jorge pode registrar um pedido na conversa ou no Linear. Julia identifica a tarefa
existente antes de criar outra, transforma o pedido autorizado em critérios
verificáveis e consulta código/documentação antes de perguntar.
Ao iniciar: registrar sessão/dono, branch/PR, base e próxima ação na tarefa Linear.
Ao concluir ou bloquear: atualizar a mesma tarefa com evidências e pendências reais.
A rotina horária retoma tarefas pendentes; a de madrugada inspeciona e corrige falhas
e documentação. Se já houver execução ativa, acompanhar em vez de duplicar escrita.

## Regras de uso

- Pedidos de Jorge em 08/11/2026 autorizam inspeção, correções comprovadas,
  documentação e retomada horária, sem confirmações intermediárias no mesmo escopo.
- Nenhum consumo de API de modelos nem compra adicional de créditos.
- Não implementar funcionalidades novas sem pedido aplicável; registrar proposta.
- Usar Todo/Backlog, In Progress, In Review e Done conforme evidência. Registrar
  bloqueios com dependência e descrição; não fechar tarefa porque código foi escrito.
- Publicar checkpoints no Linear, com links para commits/PRs/checks do GitHub.
- Não atribuir a um usuário ou agente inexistente chamado Julia; o nome no título
  identifica o fluxo, não comprova delegação automática no Linear.
- Os agendamentos são do ChatGPT. A execução horária foi comprovada em 08/11/2026;
  consultar a REI-9 para a evidência atual de cada rotina e das credenciais antigas.
- Preservar a rotina independente de memória do Notion, que não foi alterada.
