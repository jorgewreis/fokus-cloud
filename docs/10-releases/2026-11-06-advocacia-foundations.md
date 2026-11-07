# Reconstrução local das fundações Advocacia

## Estado desta reconstrução

Cópia local em teste, baseada em `26daac7c27d246eaef64bb3f0e59499376993f8f`. O candidato `aa81c3c` não foi recuperado integralmente. Restauração exata de arquivos identificados e reconstrução dos demais são atividades distintas. Não homologado, não publicado e sem promessa de equivalência histórica. `LAW_TASKS_ENABLED=false` por padrão.

Escopo Advocacia: Essencial = Contatos + Processos. Avançado acrescenta Tarefas/Prazos manuais, condicionado à liberação específica. Financeiro é somente planejado. Audiências e Expedições não integram Advocacia; variantes judiciais, APIs compartilhadas, contratos e históricos são preservados. Não há nova concessão de acesso nem recomposição automática de planos legados.

## Requisitos e limites

Base imutável: 26daac7. Os ZIPs históricos foram verificados por hash/CRC; 18 arquivos são restauráveis exatamente e o coordenador faz sua integração. Os cinco harnesses exatos permanecem como evidência histórica, sem adaptação silenciosa. Os demais arquivos têm identidade nova. Os resultados históricos de 318 testes / 2853 asserções não foram reproduzidos e não são resultado desta árvore. O baseline local informado passou 253 / 2140. A disponibilidade de MySQL 8.4.13, Redis 7.4.11 e Chrome não significa aprovação dos testes do produto. Resultados atuais devem constar de relatório próprio após congelamento.

Ver `deploy/README-release-recovery.md` para barreiras de entrega e recuperação.
