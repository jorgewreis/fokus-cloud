# Tarefas e prazos manuais

## Estado desta reconstrução

Cópia local em teste, baseada em `26daac7c27d246eaef64bb3f0e59499376993f8f`. O candidato `aa81c3c` não foi recuperado integralmente. Restauração exata de arquivos identificados e reconstrução dos demais são atividades distintas. Não homologado, não publicado e sem promessa de equivalência histórica. `LAW_TASKS_ENABLED=false` por padrão.

Escopo Advocacia: Essencial = Contatos + Processos. Avançado acrescenta Tarefas/Prazos manuais, condicionado à liberação específica. Financeiro é somente planejado. Audiências e Expedições não integram Advocacia; variantes judiciais, APIs compartilhadas, contratos e históricos são preservados. Não há nova concessão de acesso nem recomposição automática de planos legados.

## Requisitos e limites

Datas são informadas pelo usuário. Não calcular prazo legal automaticamente, interpretar intimações como decisão jurídica, prometer notificações ou introduzir novas integrações. Título editado não deve apagar responsável indisponível; retirada deve ser explícita. Validar tenant, unidade, elegibilidade e versão antes de cada alteração.

Ver `deploy/README-release-recovery.md` para barreiras de entrega e recuperação.
