# Fila de tarefas de Julia

Coordenação canônica: [issue #45](https://github.com/jorgewreis/fokus-cloud/issues/45).

Fonte operacional compartilhada entre o trabalho diurno de Jorge e as rotinas de Julia.
Atualizar a issue/PR canônica antes deste resumo; não manter estados contraditórios.
Não usar esta lista como autorização para criar funcionalidades não solicitadas.

| Ordem | Tarefa | Escopo e aceite | Estado inicial |
| --- | --- | --- | --- |
| 1 | Remover executor de modelos por API | Remover workflows, controlador, testes exclusivos e autorizações por hash; preservar CI normal e documentar modo nativo | em_execucao |
| 2 | Reconciliar pendências autorizadas | Revisar issues/PRs e checkpoints abertos; registrar objetivo, autorização, critérios faltantes e próxima ação; arquivar piloto API substituído | pendente |
| 3 | Inspecionar falhas e corrigir regressões | Examinar CI e código ligado às pendências; reproduzir com dados sintéticos; corrigir defeitos comprovados e validar; entregar PR | pendente |
| 4 | Conciliar documentação | Comparar documentos das áreas tocadas com código/testes; corrigir divergências comprovadas e índices, sem inventar funcionalidades ou status | pendente |
| 5 | Retomar entregas interrompidas | Verificação horária: continuar critérios pendentes da tarefa autorizada existente e atualizar checkpoint, sem duplicar execução | recorrente |

## Passagem de turno

Jorge pode registrar um pedido diretamente na conversa ou em issue. Julia transforma
o pedido em tarefa verificável, identifica critérios e consulta decisões existentes
antes de perguntar. Ao iniciar: registrar dono/sessão, branch/PR, base e critérios.
Ao terminar ou bloquear: publicar evidências, estado e próxima ação no mesmo registro.
A rotina de madrugada executa as ordens 2–4; a horária reconcilia e retoma a ordem 5.

## Registro inicial

- Autorização: pedidos de Jorge em 08/11/2026 para remover o executor por API,
  criar documentação e rotinas de madrugada de inspeção/correção e retomada horária.
- Restrição absoluta: nenhum consumo de API de modelos nem compra adicional de créditos.
- Agendamentos são recursos externos a este arquivo; confirmar criação no ChatGPT.
- Preservar a rotina existente de memória do Notion; ela tem outro objetivo.
