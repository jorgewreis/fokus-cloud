# Operação do DJEN no módulo Processos

## Escopo

O DJEN complementa o DataJud com metadados de comunicações e publicações. A consulta é feita pelo número CNJ completo e retorna apenas dados descritivos: data de disponibilização, tribunal, órgão, classe indicada, tipo de comunicação/documento e link HTTPS quando fornecido.

O conteúdo da publicação e dados pessoais de destinatários não são armazenados. Resultados do DJEN ficam em `djen_metadata`, separados dos campos oficiais mantidos pelo DataJud e dos valores editáveis pelo usuário. Classe ou órgão podem ser sugeridos na tela quando o cadastro estiver vazio; o DJEN não altera classe, tribunal, comarca, situação processual nem calcula prazos.

O usuário pode iniciar uma consulta pelo detalhe do processo. Cadastros novos também enfileiram a consulta automaticamente. A fila `law-datajud` processa o trabalho junto às consultas DataJud. O scheduler executa `law:sync-case-djen` diariamente às 03:45, limitado a 500 processos por execução, ajustável com `--limit`. Processos ativos são atualizados no máximo uma vez por mês; falhas contam como tentativa e não apagam metadados anteriores.

## Configuração

`DJEN_API_BASE_URL` usa por padrão `https://comunicaapi.pje.jus.br/api/v1`; `DJEN_TIMEOUT` controla o limite de resposta entre 5 e 30 segundos. O endpoint público não exige credencial. Depois de alterar configuração em produção, reconstrua o cache do Laravel. Para desenvolvimento, use `php artisan schedule:work` e um worker para `law-datajud`, conforme [operação do DataJud](./law-case-datajud.md).

O estado da última tentativa fica em `djen_sync_status` (`not_requested`, `pending`, `synced`, `not_found` ou `error`), e `last_djen_checked_at` registra quando a consulta terminou. `last_djen_synced_at` só avança quando publicações são retornadas.

## Limitações

O DJEN centraliza comunicações publicadas no diário eletrônico, mas não substitui a consulta de metadados e movimentações do DataJud nem garante cobertura de todo ato processual. A ausência de publicação não prova ausência de movimentação ou de prazo. Uma publicação pode não trazer classe, órgão ou link.

O MNI do TJBA não faz parte desta integração porque o acesso foi negado. Não há consulta MNI nem tentativa de contornar o controle do tribunal. DataJud e DJEN são fontes independentes e sua disponibilidade pode variar.

O DataJud tem termos de uso próprios. Antes de oferecer comercialmente dados obtidos pela API, confirme a autorização escrita exigida pelos termos vigentes do CNJ: [termos da API Pública DataJud](https://formularios.cnj.jus.br/wp-content/uploads/2023/05/Termos-de-uso-api-publica-V1.1.pdf). A integração técnica não equivale a essa autorização.

## Referências

- [API do Diário de Justiça Eletrônico Nacional (DJEN)](https://hcomunicaapi.cnj.jus.br/swagger/index.html).
- [Orientações do CNJ sobre comunicações processuais](https://www.cnj.jus.br/programas-e-acoes/processo-judicial-eletronico-pje/comunicacoes-processuais/orientacoes-aos-tribunais/).
- [Operação do DataJud](./law-case-datajud.md).
