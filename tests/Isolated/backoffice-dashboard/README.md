# Provas locais de leitura do dashboard (protótipo)

Estes scripts são um laboratório sintético para o protótipo do PR #78, não são
provas do endpoint HTTP completo nem autorização para operar em produção. Use
somente uma instância MySQL descartável, sem dados reais. `mysql-snapshot.php`
exige o banco `dashboard_bench` previamente criado e que a tabela de assinaturas esteja vazia;
`mysql-benchmark.php seed` acrescenta resgates e não é idempotente. Não execute
mais de uma geração no mesmo banco. `laravel-snapshot.php` altera linhas e grava
um resgate no banco sintético, portanto é executado apenas após a primeira prova.

Antes de executar qualquer script, crie em uma instância MySQL comprovadamente
isolada e descartável o banco `dashboard_bench` e uma tabela
`dashboard_proof_marker` com `id` inteiro primário e `marker` CHAR(32). Insira
apenas `id=1` e um marcador hexadecimal aleatório de 32 caracteres gerado para
essa instância. Obtenha o `@@server_uuid` da própria instância descartável.
Este preparo é manual; nunca o execute em servidor ou dados reais.

Defina `DASHBOARD_PROOF_ACK=synthetic-only`, `APP_ENV=testing`,
`DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`,
`DB_DATABASE=dashboard_bench`, credenciais descartáveis iguais em
`DB_USERNAME`/`DASHBOARD_PROOF_USER` e `DB_PASSWORD`/`DASHBOARD_PROOF_PASSWORD`,
`DASHBOARD_PROOF_SERVER_UUID` com o UUID verificado e `DASHBOARD_PROOF_MARKER`
com o marcador criado. `DB_URL`, `DB_SOCKET` e `APP_CONFIG_CACHE` devem estar
vazios; o checkout não pode conter `.env`, `.env.testing` nem
`bootstrap/cache/config.php`. Os scripts confirmam UUID, banco e marcador antes
de qualquer DDL ou escrita; os que usam Laravel também verificam configuração
resolvida e conexões de leitura e escrita antes de alterar dados. O bootstrap
Laravel exige aplicação montada em `/workspace` e `vendor` instalado. Execute, nessa ordem:

1. `php tests/Isolated/backoffice-dashboard/mysql-snapshot.php`
2. `php tests/Isolated/backoffice-dashboard/mysql-benchmark.php seed`
3. `php tests/Isolated/backoffice-dashboard/mysql-benchmark.php unbuffered`
4. `php tests/Isolated/backoffice-dashboard/mysql-benchmark.php buffered`
5. `php tests/Isolated/backoffice-dashboard/laravel-snapshot.php`

Uma segunda carga de resgates foi aplicada manualmente em outra assinatura no
ensaio abaixo. Os scripts são protótipos de laboratório e não integram PHPUnit.

## Checkpoint de 14/11/2026

Em MySQL 8.4 descartável, com 10.000 assinaturas sintéticas, uma consulta
unbuffered em transação READ COMMITTED observou uma gravação não confirmada da
própria transação e manteve a visão anterior a alterações confirmadas em outra
conexão durante a leitura. A mesma propriedade foi observada usando Laravel
Query Builder e cursor com consulta equivalente à do protótipo. A conexão foi
reutilizada depois e seu atributo de buffer retornou ao valor inicial. As somas
foram respectivamente 107.767 e 107.747 no conjunto de dados de cada ensaio.

Com 2.001 resgates concentrados em duas assinaturas, o resultado de 11.999
linhas transferiu 21.327.150 bytes em snapshots; o pico PHP foi 2 MiB sem
buffer e 24 MiB com buffer. Ensaios individuais levaram 0,156 s e 0,084 s,
respectivamente; não representam produção nem uma comparação estatística. O
JOIN repete snapshots e seu custo com volumes extremos continua uma limitação.

O teste HTTP de `SubscriptionAdminTest` com MySQL iniciou em banco separado e
continuava aplicando migrations neste checkpoint. Acompanhar o processo existente
`hopeful_germain` e o arquivo local `%TEMP%/fokus-dashboard-consistent-mysql-test.log`;
não considerá-lo aprovado até haver resultado terminal. A prova de endpoint,
a revisão independente e a avaliação dos demais drivers seguem pendentes.

## Continuação: exceção e alternativa sem repetição

`laravel-cursor-exception.php` lança uma exceção sintética na primeira linha de
um cursor sem buffer, restaura o atributo e testa outra consulta e rollback na
mesma conexão. No MySQL 8.4 e Laravel do laboratório, a exceção foi observada,
o buffer restaurado e a consulta seguinte retornou 10.000 assinaturas. Esse
ensaio cobre uma exceção durante a iteração; não cobre toda falha possível do
driver ou interrupção do processo.

Para explorar o custo do JOIN, `mysql-amplify-seed.php` acrescenta 10.000
resgates sintéticos à assinatura `00000`; não é idempotente. Após essa carga,
`mysql-benchmark.php unbuffered` mede a consulta do protótipo, e
`mysql-union-benchmark.php` mede uma alternativa SQL independente com UNION
ALL, que transmite o snapshot da assinatura uma só vez. Não execute fora do
banco descartável. Os dois scripts devem usar os mesmos dados e ambiente.

Na execução local, com 10.000 assinaturas e cerca de 12.000 resgates, JOIN
transmitiu 40.695.237 bytes de snapshots em 0,226 s; UNION transmitiu
19.366.174 bytes em 0,155 s. Ambos tiveram 2 MiB de pico PHP e soma 99.970.
Na carga anterior, de cerca de 2.000 resgates, uma execução de UNION levou
0,543 s contra 0,061 s para JOIN, com menor diferença de bytes. São medições
isoladas, com cargas e aquecimento diferentes; não estabelecem qual estratégia
é melhor em produção. UNION pode exigir materialização e ordenação no servidor,
e sua equivalência em todos os drivers e no endpoint ainda não foi validada.
A alternativa permanece experimental; o controlador não foi alterado com ela.

## Custo de materialização no MySQL

`mysql-profile.php join` e `mysql-profile.php union` medem contadores da sessão
MySQL durante a leitura de consultas equivalentes. Exigem a mesma instância
sintética e variáveis `DASHBOARD_PROOF_*` acima. A prova com aproximadamente
22 mil linhas observou no JOIN zero tabelas temporárias e zero linhas ordenadas;
no UNION, uma tabela temporária em disco e 13 passes de mesclagem. Os tempos
foram 0,109 s e 0,144 s, respectivamente, em uma execução local por variante.
Não se trata de estimativa de produção e resultados dependem de índices,
configuração e volume.

Um protótipo separado em `codex/backoffice-dashboard-union-20261114` passou dois
testes HTTP SQLite (63 asserções) e uma prova Laravel/MySQL sintética com
alteração concorrente e escrita própria não confirmada. A contrapartida de
tabela temporária em disco mostra risco operacional relevante para volumes e
concorrência maiores; por ora, mantém-se o JOIN no protótipo principal. A
repetição de snapshots na rede continua uma limitação explícita. A prova HTTP
MySQL ainda é necessária para qualquer integração.

## Continuação: variantes com envio seletivo de snapshot

`mysql-sparse-profile.php correlated`, `grouped` e `forced` medem outras
consultas sintéticas, sob as mesmas variáveis de proteção. A opção `forced`
é específica do MySQL e força o índice primário. `mysql-inactive-seed.php`
acrescenta 50 mil assinaturas inativas ao banco descartável; não é idempotente.

Com aproximadamente 12 mil resgates e 10 mil assinaturas ativas, a subconsulta
correlacionada transferiu 19,4 MB, mas levou 14,9 s. O agrupamento prévio
levou 0,166 s e exigiu uma tabela temporária e 13 mesclagens; ao forçar índice
primário, levou 0,065 s, com tabela temporária em memória e sem ordenação no
ensaio. Após acrescentar 50 mil inativas, JOIN levou 0,126 s e transferiu
40,7 MB; agrupamento forçado levou 0,097 s e transferiu 19,4 MB. Em ambos, o
pico PHP foi 2 MiB e a soma coincidiu. São medições individuais, sem modelo de
produção e sem comprovação da equivalência do endpoint ou de outros drivers.
O agrupamento cobre todos os resgates, inclusive históricos; forçar plano por
um único experimento pode degradar outras distribuições. Mantém-se o JOIN até
que a decisão seja fundamentada em cargas, índices e drivers representativos.

## Resultado HTTP MySQL após retomada

O processo inicial encerrou e foi removido automaticamente sem preservar sua
saída final; não há resultado recuperável daquela execução. Para nova prova,
foram comparados os nomes das 84 migrations presentes no banco sintético
`dashboard_test` com os arquivos da branch e conferidas as tabelas
`subscriptions` e `platform_admins` vazias. Um bootstrap temporário, restrito
a `APP_ENV=testing`, host `127.0.0.1`, banco `dashboard_test` e aceite explícito
de reutilização sintética, marcou o esquema existente como migrado. O próprio
`RefreshDatabase` iniciou transação; a infraestrutura de teste semeou os dados.
Não foram refeitas as migrations nessa segunda execução.

No contêiner `fokus-dashboard-http-reuse-20261114`, Laravel 13.40.0, PHP
8.4.27 e MySQL 8.4, os testes HTTP de agregação e voucher concluíram com
**2 testes e 64 asserções**, saída zero e duração de 26,531 s. A saída permanece
consultável com `docker logs fokus-dashboard-http-reuse-20261114` enquanto o
contêiner existir. O vendor empregado veio do ensaio da atualização Laravel,
não do lockfile original deste protótipo. O bootstrap e a configuração PHPUnit
utilizados estão nos arquivos temporários locais `phpunit.dashboard-reuse-bootstrap.php`
e `phpunit.dashboard-reuse.xml`; não contêm credenciais. O resultado não prova
a suíte completa, concorrência por HTTP ou validação de produção.

## Proteção do laboratório

`laravel-guard-negative.php` exercita quatorze configurações proibidas sem acesso
a banco: URL, socket, caminho de cache, host, porta, driver, banco, ambiente,
credencial, UUID e marcador inválidos e arquivos `.env`, `.env.testing` e cache de configuração.
A proteção exige confirmação explícita e não transforma um servidor real em
ambiente descartável; a escolha e preparação da instância continuam a cargo de
quem executa.

Na revisão desta proteção, as quatorze rejeições foram exercitadas localmente
antes da conexão; cada um dos nove scripts também recusou uma `DB_URL` hostil.
A prova positiva da nova proteção contra MySQL permanece pendente: a instância
descartável não ficou disponível e o Docker deixou de responder durante a
tentativa. Os ensaios registrados acima são anteriores a esta alteração.

Os testes negativos usam apenas diretório temporário próprio, com nomes
aleatórios e criação exclusiva; eles não criam nem removem `.env` ou cache no
checkout. `laravel-guard-collision.php` inicia dois processos concorrentes para
tentar criar o mesmo arquivo e verifica exatamente um vencedor, sem sobrescrita.

## Identidade no gate de homologação

O teste HTTP `SubscriptionAdminTest::test_dashboard_aggregates_active_subscriptions_in_one_snapshot`
registrou em JUnit **1 teste e 6 asserções** em SQLite sintético com PHP 8.5.2;
essa medição define o mínimo por identidade e eleva o inventário desta branch
para 373 testes e 3.036 asserções mínimas. A verificação MySQL inclui também a
asserção específica de restauração do buffer. Os controles negativos exigem
a identidade e rejeitam sua ausência, renomeação, duplicação e redução de
asserções, além de rejeitar totais anteriores ou insuficientes. Esta é uma
medição direcionada, não uma execução completa do gate Linux. O PR #90 altera
o mesmo inventário: se integrado, os acréscimos de ambas as entregas devem ser
compostos e medidos novamente sobre o candidato resultante.
