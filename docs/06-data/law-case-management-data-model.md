# Modelo de dados de Processos — Judiciário Criminal

## Escopo

Implementado pela migration `2026_10_02_000100_create_law_case_management.php`.
Processos pertencem à empresa e à unidade proprietária. Empresas permanecem
isoladas. Público interno admite leitura entre unidades; Restrito exige
autorização nominal. Chaves compostas preservam empresa e, quando necessário,
unidade nas relações.

## Tabelas e contratos

| Tabela | Identidade e conteúdo |
| --- | --- |
| `law_cases` | `LCS`; empresa/unidade, CNJ, metadados, estado, prioridade, sigilo, responsável, datas, arquivamento, versão e autores. |
| `law_case_status_options` | `LSO`; código, rótulo, ordem e ativo por unidade; Ativo/Arquivado preservados. |
| `law_case_tags` | `LTG`; nome único na unidade, ativo e autor. |
| `law_case_tag_assignments` | Chave empresa/processo/etiqueta; mesma unidade; autor e data. |
| `law_case_role_options` | `LRO`; papéis padronizados e complementos, código e rótulo por unidade. |
| `law_case_contacts` | `LCV`; contato da mesma empresa, papel e rótulo histórico; único contato/papel no processo. |
| `law_case_relations` | `LCR`; origem, destino, dependência/apensamento, autor; sem propagação. |
| `law_confidential_case_accesses` | `LCA`; processo/vínculo nominal, concedente e revogação; único por vínculo/processo. |
| `law_case_metadata_conflicts` | `LCF`; campo, valor manual/oficial, resolução, autor e datas. |
| `law_case_events` | `LCE`; eventos persistentes, autor opcional, motivo, antes/depois JSON e data. |

## Cadastro e valores iniciais

Somente `case_number` (CNJ normalizado, 20 dígitos) e `law_unit_id` são exigidos
no formulário. CNJ é único por empresa, inclusive arquivados. Classe, códigos,
assuntos, órgão, situação oficial, autuação e distribuição são opcionais.

Estado inicial `active`; opções `pending`, `suspended`, `completed` e
`archived`. Prioridade `normal`, `high` ou `urgent`. Sigilo
`public_internal` ou `restricted`. Responsável opcional por
`responsible_membership_id`, necessariamente da mesma empresa.

`archive_reason`, `archived_at` e `archived_by` registram arquivamento.
Reabertura exige motivo, limpa a condição de arquivado e retorna a Ativo,
preservando eventos anteriores. `version` controla concorrência.

## Dados oficiais e divergências

`datajud_metadata` conserva últimos valores oficiais conhecidos.
`manual_metadata` conserva preenchimentos manuais preservados. Metadados
ausentes não apagam valores anteriores. São aceitos classe/código, assuntos
(lista code/name), órgão/código e situação/código, quando presentes.
Nenhuma movimentação ou resposta integral é persistida.

`datajud_sync_status` é `pending`, `synced`, `not_found` ou `error`.
`last_datajud_checked_at` marca a última tentativa e controla vencimento mensal;
`last_datajud_synced_at` marca último retorno com metadados.

Divergência aberta é atualizada quando o campo recebe valor oficial novo.
Resoluções são `manual`, `official` ou `converged` quando fontes concordam.
Decisão manual sobre o mesmo valor oficial não se repete a cada consulta.

## Relações, acesso e histórico

Contatos ativos são compartilhados na empresa ou locais da unidade do processo.
Os vínculos não copiam dados pessoais. Papéis e etiquetas desativados não são
apagados; referências e rótulos históricos permanecem. Opções iniciais são
provisionadas também para unidades novas ao consultar referências do módulo.

Relações são apresentadas nos dois processos somente quando ambos forem
acessíveis. Cada registro conserva estado, sigilo e autorizações próprios.

A autorização nominal pode ser revogada e reativada. Ela habilita acesso ao
registro; permissões gerais determinam ações disponíveis. Administrador pode
gerir autorizações sem acesso automático ao conteúdo restrito.

Eventos não dependem da retenção da auditoria geral da plataforma. A leitura
do histórico é paginada e usa o mesmo controle de sigilo do detalhe.
Tentativas de consulta oficial incrementam a versão e produzem eventos.

## Limites

Não há observações, capitulações, tarefas, expedições, prazos, pendências,
documentos, movimentações, importação ou exportação neste esquema. Previsões
gerais desses campos descrevem evolução futura.
