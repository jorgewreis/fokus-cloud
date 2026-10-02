# Operação do Datajud no módulo Processos

## Configuração

Configure `DATAJUD_API_KEY` no ambiente com a chave pública vigente publicada
pelo CNJ. Não registre seu conteúdo em código ou logs. `DATAJUD_API_BASE_URL` usa
por padrão `https://api-publica.datajud.cnj.jus.br`. Reconstrua o cache de
configuração após alterar variáveis de um ambiente que utilize esse cache.

O cliente permite até 10 segundos para conexão e 30 segundos para consulta,
como a integração de referência do ios1vcrime. Os limites são ajustáveis por
`DATAJUD_CONNECT_TIMEOUT` (2 a 15 segundos) e `DATAJUD_TIMEOUT` (5 a 60 segundos).
A ausência de
configuração não impede cadastro e preenchimento manual. A rotina automática
encerra sem consultas e informa a situação no console; tentativas manuais
registram a falha na linha do tempo do processo.

Na publicação, o secret `DATAJUD_API_KEY` do ambiente `production` no GitHub
Actions é enviado por entrada padrão ao script `deploy/configure-datajud.php`,
antes de reconstruir o cache do Laravel. A chave não aparece em argumentos,
logs ou arquivos versionados. Se o secret não for fornecido, o script preserva
a configuração existente; o diagnóstico posterior exige uma chave configurada.
Quando o CNJ substituir a chave pública, atualize esse secret e os ambientes
locais e publique novamente.

No PHP local, falhas de transporte com código 60 indicam problema na validação
de certificados. Configure `curl.cainfo` e `openssl.cafile` no `php.ini` com um
pacote confiável de certificados e reinicie o processo PHP que atende a
aplicação. Não desabilite a validação HTTPS para contornar esse problema.

O comando `php artisan law:datajud-status` confere a presença da chave sem
exibi-la. Com `--probe`, faz uma consulta vazia (`match_none`, `size: 0`) ao
endpoint TJBA para conferir autenticação e resposta, sem números ou dados de
processos reais. O deploy registra esse diagnóstico; uma falha externa gera
aviso e não impede a publicação das demais correções.

## Consulta e mensagens

A consulta combina `match` e `term` pelo número CNJ completo. O tribunal é
determinado pelo próprio número; não há tentativa em outro tribunal. Uma
resposta que informe um número diferente é rejeitada. São solicitados somente
os metadados desta etapa, sem movimentações.

As respostas de cadastro e consulta manual incluem `datajud.status`,
`datajud.code` e `datajud.message`. O detalhe recupera o resultado da última
consulta diretamente do histórico autorizado, independentemente da página de
histórico exibida. Nenhuma migration adicional é necessária.

A interface informa separadamente ausência de configuração, chave recusada
(401/403), limite de consultas (429), tempo excedido, falha de conexão, falha
temporária do serviço, falha de validação HTTPS, endereço inexistente e resposta incompatível. O motivo
e o código são registrados no histórico. Mensagens não incluem a chave,
cabeçalhos, corpo bruto da resposta ou detalhes internos das exceções.

O botão mostra “Consultando Datajud…” e permanece desabilitado durante a
requisição. O detalhe distingue a última tentativa da última atualização
bem-sucedida. Uma falha preserva os dados existentes. Ausência de resultado
público não é tratada como confirmação de sigilo. Resultados parciais indicam
que campos não fornecidos podem continuar sem informação; divergências com
preenchimentos manuais continuam sujeitas à decisão do usuário.

## Atualização mensal

O scheduler executa `law:sync-case-datajud` diariamente às 03:15, no fuso da
aplicação. Consulta processos não arquivados cuja última tentativa tem pelo
menos um mês, com assinatura ativa e módulo de Processos publicado no contexto
`judiciario` (compatível com o código legado `vara_criminal`). O limite é de 500 consultas por execução, ajustável por
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
