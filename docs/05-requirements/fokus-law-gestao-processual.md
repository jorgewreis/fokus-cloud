# Requisitos de Processos — Judiciário Criminal

## Escopo autorizado

Primeira implementação para o Cartório Criminal, contexto contratado
`judiciario` (compatível com o código legado `vara_criminal`). Este contrato prevalece sobre previsões gerais de evolução.

| Código | Funcionalidade | Regra e critério de aceite |
| --- | --- | --- |
| RF-GPR-001 | Cadastrar | Exigir CNJ válido e unidade ativa; datas opcionais; impedir repetição na empresa, inclusive arquivados. |
| RF-GPR-002 | Consultar Datajud | Consulta inicial, mensal e manual; somente metadados básicos; data da última tentativa. |
| RF-GPR-003 | Tratar falhas | Preservar cadastro; liberar campos ausentes para preenchimento manual. |
| RF-GPR-004 | Resolver divergências | Preservar valor manual; mostrar ambos; editor autorizado escolhe e decisão é registrada. |
| RF-GPR-005 | Separar estados | Datajud não modifica estado operacional, responsável, prioridade, etiquetas ou sigilo. |
| RF-GPR-006 | Configurar estados | Ativo, Pendente, Suspenso, Concluído e Arquivado; complementos locais; configuração por chefia/administrador. |
| RF-GPR-007 | Atribuir | Responsável principal opcional entre vínculos ativos da empresa; permitir remover atribuição. |
| RF-GPR-008 | Priorizar | Normal, Alta e Urgente; inicialmente Normal. |
| RF-GPR-009 | Controlar sigilo | Público interno ou Restrito; restrito exige autorização nominal inclusive entre unidades. |
| RF-GPR-010 | Administrar acesso | Administrador/chefia da unidade proprietária ou administrador da empresa concede/revoga; registrar eventos. |
| RF-GPR-011 | Compartilhar internamente | Público interno visível em toda a empresa; edição depende de permissão; empresas diferentes isoladas. |
| RF-GPR-012 | Etiquetar | Etiquetas da unidade; vincular/desvincular sem modificar outras propriedades. |
| RF-GPR-013 | Relacionar | Dependência/apensamento entre registros acessíveis da mesma empresa; sem propagação. |
| RF-GPR-014 | Vincular contatos | Reutilizar contatos ativos compatíveis com a unidade; papéis padronizados e complementos locais. |
| RF-GPR-015 | Buscar | Somente CNJ, completo ou parcial; seis campos principais; mais recentes primeiro; paginação. |
| RF-GPR-016 | Resumir | Totais por classe da consulta; excluir registros sem autorização. |
| RF-GPR-017 | Arquivar/reabrir | Justificativa e versão atual obrigatórias; reabrir em Ativo; preservar histórico. |
| RF-GPR-018 | Auditar | Autor, data, antes/depois e motivos exigidos; metadados oficiais na linha do tempo; histórico paginado. |
| RF-GPR-019 | Cartas recebidas | Classe processual, sem fluxo separado. |

## Permissões e concorrência

As ações usam `law.cases.view`, `create`, `update`, `archive`, `reopen`,
`access.manage` e `configure`. Perfis padrão recebem leitura. Operador, chefia
e administrador da unidade recebem ações operacionais; Somente leitura não
recebe edição. Chefia e administrador recebem configuração e gestão de acesso.
Perfis personalizados seguem a configuração vigente.

A unidade ativa determina a permissão operacional geral. Processos públicos de
outra unidade não exigem vínculo com sua unidade proprietária. Configuração e
concessão de acesso exigem autoridade na unidade proprietária. Sigilo é
verificado no backend em cada leitura e mutação.

Edição, arquivamento, reabertura e decisão de divergência exigem versão atual.
Uma consulta oficial incrementa a versão. Alterações e respectivos eventos são
transacionais. Remover vínculos não apaga o histórico.

## Fora desta etapa

Tarefas, expedições, prazos, pendências, documentos, movimentações oficiais,
importação/exportação, notificações por e-mail, PJe e e-SAJ.
