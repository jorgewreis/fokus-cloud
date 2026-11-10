# Fluxo de trabalho processual

## Estado desta reconstrução

Cópia local em teste, baseada em `26daac7c27d246eaef64bb3f0e59499376993f8f`. O candidato `aa81c3c` não foi recuperado integralmente. Restauração exata de arquivos identificados e reconstrução dos demais são atividades distintas. Não homologado, não publicado e sem promessa de equivalência histórica. `LAW_TASKS_ENABLED=false` por padrão.

Escopo Advocacia: Essencial = Contatos + Processos. Avançado acrescenta Tarefas/Prazos manuais, condicionado à liberação específica. Financeiro é somente planejado. Audiências e Expedições não integram Advocacia; variantes judiciais, APIs compartilhadas, contratos e históricos são preservados. Não há nova concessão de acesso nem recomposição automática de planos legados.

## Requisitos e limites

Toda alteração deve manter isolamento por empresa/unidade, autorização por capacidade, controle de versão e histórico. Identidade DJEN usa fingerprint do texto integral normalizado antes do resumo; metadados antigos não herdam automaticamente revisão. Não armazenar inteiro teor só para deduplicar. Fonte pública não reduz restrição interna.

Ver `deploy/README-release-recovery.md` para barreiras de entrega e recuperação.
