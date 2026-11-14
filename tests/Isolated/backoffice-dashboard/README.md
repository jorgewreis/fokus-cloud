# Provas locais de leitura do dashboard (protótipo)

Estes scripts são um laboratório sintético para o protótipo do PR #78, não são
provas do endpoint HTTP completo nem autorização para operar em produção. Use
somente uma instância MySQL descartável, sem dados reais. `mysql-snapshot.php`
cria o banco `dashboard_bench` e exige que a tabela de assinaturas esteja vazia;
`mysql-benchmark.php seed` acrescenta resgates e não é idempotente. Não execute
mais de uma geração no mesmo banco. `laravel-snapshot.php` altera linhas e grava
um resgate no banco sintético, portanto é executado apenas após a primeira prova.

Defina em ambiente local `DASHBOARD_PROOF_ACK=synthetic-only`,
`DASHBOARD_PROOF_USER` e `DASHBOARD_PROOF_PASSWORD` com credenciais descartáveis.
Os scripts usam `127.0.0.1` e o banco fixo `dashboard_bench`. A prova Laravel
exige também `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`,
`DB_DATABASE=dashboard_bench` e credenciais de teste em `DB_USERNAME` e
`DB_PASSWORD`. O script Laravel supõe aplicação montada em `/workspace` e
`vendor` instalado. Execute, nessa ordem:

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
