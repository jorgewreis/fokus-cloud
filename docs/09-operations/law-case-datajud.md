# Operação do Datajud no módulo Processos

## Configuração

Configure `DATAJUD_API_KEY` no ambiente com a chave pública vigente publicada
pelo CNJ. Não registre seu conteúdo em código ou logs. `DATAJUD_API_BASE_URL` usa
por padrão `https://api-publica.datajud.cnj.jus.br`. Reconstrua o cache de
configuração após alterar variáveis de um ambiente que utilize esse cache.

O cliente limita conexão a 3 segundos e consulta a 8 segundos. A ausência de
configuração não impede cadastro e preenchimento manual. A rotina automática
encerra sem consultas e informa a situação no console; tentativas manuais
registram a falha na linha do tempo do processo.

## Atualização mensal

O scheduler executa `law:sync-case-datajud` diariamente às 03:15, no fuso da
aplicação. Consulta processos não arquivados cuja última tentativa tem pelo
menos um mês, com assinatura ativa e módulo de Processos publicado no contexto
`vara_criminal`. O limite é de 500 consultas por execução, ajustável por
`--limit` até 5.000. Excedentes continuam vencidos para as próximas execuções.

A rotina agendada usa `withoutOverlapping`. Falhas externas contam como
tentativa e são registradas no processo. Não há fila de revisão nem
notificação. A ação manual permite nova consulta antes do vencimento mensal,
inclusive para processos arquivados acessíveis.

## Limites da fonte

O Datajud pode não retornar processos ou campos. São solicitados somente
metadados permitidos; não há movimentações nem persistência da resposta
integral. A situação oficial não é inferida de movimentos e admite
preenchimento manual quando não fornecida. O detalhe exibe atribuição
CNJ/DataJud e data da última consulta.

## Referências oficiais

- [Acesso e chave pública](https://datajud-wiki.cnj.jus.br/api-publica/acesso/).
- [Endpoints](https://datajud-wiki.cnj.jus.br/api-publica/endpoints/).
- [Glossário](https://datajud-wiki.cnj.jus.br/api-publica/glossario/).
