
## Estado desta reconstrução

Cópia local em teste, baseada em `26daac7c27d246eaef64bb3f0e59499376993f8f`. O candidato `aa81c3c` não foi recuperado integralmente. Restauração exata de arquivos identificados e reconstrução dos demais são atividades distintas. Não homologado, não publicado e sem promessa de equivalência histórica. `LAW_TASKS_ENABLED=false` por padrão.

Escopo Advocacia: Essencial = Contatos + Processos. Avançado acrescenta Tarefas/Prazos manuais, condicionado à liberação específica. Financeiro é somente planejado. Audiências e Expedições não integram Advocacia; variantes judiciais, APIs compartilhadas, contratos e históricos são preservados. Não há nova concessão de acesso nem recomposição automática de planos legados.

## Referência anterior por segmento

O texto abaixo é referência de produto; itens além do escopo acima não constituem entrega da reconstrução Advocacia.

# Requisitos da gestao de tarefas

## Objetivo

Definir requisitos do modulo `tarefas`, cujo nome comercial deve ser
Gestao de Tarefas e cujo rotulo no menu interno deve ser Tarefas.

## Requisitos funcionais

| Codigo | Requisito | Criterio de aceite |
| --- | --- | --- |
| RF-FLX-001 | O sistema deve permitir criar tarefas operacionais. | Uma tarefa registra processo ou entidade relacionada, responsavel, prioridade, status, prazo e historico. |
| RF-FLX-002 | O sistema deve permitir receitas operacionais habilitadas por empresa ou unidade. | A unidade usa apenas receitas permitidas pelo sistema e habilitadas para sua assinatura. |
| RF-FLX-003 | O sistema deve permitir tarefa sem expedicao. | Uma pendencia interna pode ser controlada sem criar documento expedido. |
| RF-FLX-004 | O sistema deve permitir expedicao sem tarefa previa. | Um usuario autorizado pode cadastrar expedicao diretamente quando a configuracao permitir. |
| RF-FLX-005 | O sistema deve permitir tarefa que gera uma ou mais expedicoes. | A tarefa de expedir mandados pode criar uma ou varias expedicoes relacionadas. |
| RF-FLX-006 | O sistema deve permitir expedicao que gera tarefa posterior. | Expedicao com retorno, cumprimento ou conferencia cria tarefa vinculada quando a receita exigir. |
| RF-FLX-007 | O sistema deve vincular prazos e alertas a tarefas e expedicoes. | Prazo vencido, retorno pendente ou conferencia pendente aparecem na fila conforme configuracao. |
| RF-FLX-008 | O sistema deve filtrar fila por tarefa, expedicao, tipo, responsavel, prioridade, status e vencimento. | A unidade consegue operar uma fila unica sem perder a rastreabilidade documental. |
| RF-FLX-009 | O sistema deve permitir vincular contatos a tarefas. | Uma tarefa pode referenciar envolvido externo ou interessado sem substituir o responsavel interno. |

## Regras de negocio

- `tarefas` controla trabalho a cumprir.
- `expedicoes` controla documentos expedidos ou acompanhados.
- Uma receita operacional pode gerar expedicao, prazo e alerta.
- Uma tarefa nao deve duplicar campos proprios da expedicao; deve apenas
  referencia-la quando houver documento expedido.
- Uma expedicao nao deve duplicar fluxo de trabalho; deve abrir tarefa vinculada
  quando houver retorno, cumprimento ou conferencia.
- Contatos podem ser vinculados a tarefas como envolvidos externos,
  interessados ou referencias operacionais.
- Contato vinculado nao substitui responsavel interno da tarefa.
- Sigilo do processo vinculado deve restringir a tarefa e a expedicao.
- Cancelamento de tarefa nao cancela automaticamente expedicao ja criada, salvo
  regra explicita da receita.

## Permissoes

| Acao | Administrador da unidade | Chefe/Escrivao | Servidor operacional | Visualizador |
| --- | ---: | ---: | ---: | ---: |
| Criar e editar tarefas | X | X | X |  |
| Concluir tarefas proprias | X | X | X |  |
| Cancelar tarefas | X | X |  |  |
| Configurar receitas operacionais | X | X |  |  |
| Visualizar tarefas permitidas | X | X | X | X |

## Criterios de aceite

- Tarefa pode existir sem expedicao.
- Expedicao pode existir sem tarefa previa quando o tipo permitir.
- Tarefa pode gerar uma ou varias expedicoes.
- Expedicao pode gerar tarefa de retorno, cumprimento ou conferencia.
- Fila operacional mostra tarefas e expedicoes vinculadas sem duplicacao.
- Tarefas podem referenciar contatos externos sem alterar o responsavel interno.
- Cartas recebidas continuam como processos, nao como expedicoes.
