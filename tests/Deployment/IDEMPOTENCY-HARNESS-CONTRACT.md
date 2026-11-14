# Contrato proposto para homologar idempotência de empresas

Estado: proposta de implementação e revisão. Não altera o gate nem autoriza uso de banco real. A implementação do PR 93 continua bloqueada para chamadas públicas.

## Problema observado

No head `901e4ae`, `CompanyIdempotencyStoreTest` cria um esquema sintético mínimo e exige SQLite `:memory:`. `CompanyIdempotencyApiLabTest` executa `migrate:fresh`; `CompanyIdempotencyDisabledTest` protege a renovação do esquema. Em MySQL, ambos exigem aprovação de `CompanyIdempotencyMySqlLabGuard` antes da operação destrutiva. O bootstrap da prova isolada exige, entre outros controles, banco inicialmente vazio, UUID verificado, `/workspace` e somente interface `lo`.

O `linux-release-suite.py` da main `12546812` executa a suíte Feature com `DB_DATABASE=fokuscloud_security`, junto das demais verificações, e o runner compartilha namespace com serviços da homologação. Esse banco não satisfaz o contrato do laboratório de idempotência. A guarda não deve ser removida ou flexibilizada para fazer essa execução passar. O gate da main exige 372 casos e mínimo de 3030 asserções; o candidato combinado de interesses tem seus próprios totais e requisitos nominais. O head do PR 93 parte de um gate anterior, portanto os totais devem ser reconciliados por medição no SHA final.

## Matriz obrigatória

| Classe ou prova | SQLite em memória | MySQL isolado | Motivo |
| --- | --- | --- | --- |
| `CompanyIdempotencyStoreTest` | Todos os 17 métodos, sem alterar esquema externo | Provas de store e corridas existentes em laboratório próprio, com identidades explicitadas no manifesto | O esquema mínimo da classe é próprio; a prova SQLite não substitui locks InnoDB. |
| `CompanyIdempotencyApiLabTest` | Todos os 6 métodos | Todos os 6 métodos em processo e banco exclusivo aprovados pelo guard | Exerce criação, replay, revogação e autorização com provedor externo simulado. |
| `CompanyIdempotencyDisabledTest` | Todos os 4 métodos | Todos os 4 métodos no mesmo tipo de laboratório exclusivo, com guard aprovado | Comprova os bloqueios públicos por padrão também no motor alvo. |
| `CompanyIdempotencyApiConcurrencyTest` | Não aplicável como prova de InnoDB | Método nominal obrigatório com dois processos Laravel e banco próprio | Exige contenção real entre conexões. |
| `CompanyIdempotencyPreflightRaceTest` | Não aplicável como prova de InnoDB | Dois métodos nominais obrigatórios, com barreiras e banco próprio | Verifica que uma falha concorrente não anuncia resultado definitivo incorreto. |

As contagens são as identificadas na revisão de `901e4ae`, não uma substituição para descobrir e comparar a lista integral de identidades no candidato final. Se classes ou métodos mudarem, o manifesto e os mínimos devem ser atualizados com evidência, não por aproximação.

## Integração proposta

1. Reconciliar PR 93 com a main e o gate de interesses já revisado, preservando exigências nominais existentes. Gerar manifesto do SHA final com as identidades e mínimos de asserções de cada método de idempotência.
2. Na execução MySQL principal, separar explicitamente as três classes da matriz, sem suprimi-las da entrega: executar a seleção SQLite em processo próprio com configuração forçada `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` e stores de sessão/cache em memória. A evidência deve identificá-la como SQLite, não como cobertura MySQL. Os demais testes MySQL permanecem obrigatórios.
3. Criar servidor MySQL descartável adicional a partir da imagem fixada pelo perfil e sem rede externa. Executar os testes de API e concorrência em outro processo no mesmo namespace, com interface somente loopback, sem arquivo `.env` ou cache de configuração, e com banco exclusivo inicialmente vazio. Antes de qualquer DDL, o bootstrap deve verificar alvo efetivo e UUID, e o guard deve repetir a verificação antes de cada `migrate:fresh`. Nunca reutilizar o banco da suíte principal para esse passo.
4. Executar API Lab, Disabled e corridas isoladas em ordem e processos que mantenham aprovação válida. Se o bootstrap exigir banco vazio no início, usar banco ou servidor novo para cada processo, ou comprovar uma sequência única que satisfaça sua regra. Não modificar a regra de vazio para contornar falhas.
5. Agregar somente resultados JUnit completos: rejeitar falhas, erros, skips, identidades ausentes ou duplicadas e asserções abaixo do mínimo nominal. Relatar contagem e engine de cada seleção, hash dos arquivos de resultado, perfil, SHA e evidência das guardas. Repetições da mesma identidade em engines distintas devem ser avaliadas separadamente; não somá-las como se fossem casos distintos da suíte principal.
6. Integrar a nova etapa ao `linux-release.py`, ao agregador e ao `release-gate.py`, com falha fechada se faltar qualquer resultado. Atualizar artefatos, inventário e documentação, preservando todos os gates de #89 e #90. A aprovação deve ser do SHA exato da combinação que se pretende integrar.

## Comandos e critérios reproduzíveis

Os comandos de implementação devem operar no runtime descartável definido pelo projeto, sem credenciais reais. A forma exata de criação do servidor adicional e a variável de bootstrap precisam ser implementadas e revisadas junto com o harness; os comandos abaixo descrevem as seleções, não devem ser executados contra um banco não aprovado:

```text
php vendor/bin/phpunit --configuration <configuracao-sqlite-em-memoria> tests/Feature/CompanyIdempotencyStoreTest.php tests/Feature/CompanyIdempotencyApiLabTest.php tests/Feature/CompanyIdempotencyDisabledTest.php
php vendor/bin/phpunit --bootstrap tests/Isolated/company-idempotency-api-mysql-bootstrap.php --configuration <configuracao-mysql-descartavel> tests/Feature/CompanyIdempotencyApiLabTest.php tests/Feature/CompanyIdempotencyDisabledTest.php
php vendor/bin/phpunit --bootstrap tests/Isolated/company-idempotency-api-mysql-bootstrap.php --configuration <configuracao-mysql-descartavel> tests/Isolated/CompanyIdempotencyApiConcurrencyTest.php tests/Isolated/CompanyIdempotencyPreflightRaceTest.php
python3 -m unittest discover -s tests/Deployment -p 'test_linux_release_harness.py' -v
python3 -m unittest discover -s tests/Deployment -p 'test_release_gate.py' -v
```

Confirmar no PHPUnit instalado a semântica de múltiplos caminhos e do bootstrap antes de adotar esses comandos. Para cada processo MySQL, provar que o alvo estava vazio antes de sua aprovação e que a conexão real permaneceu no mesmo servidor. Se a configuração em XML sobrescrever variáveis do processo, gerar configuração descartável revisada e comprovar valores efetivos.

Testes negativos obrigatórios, sem banco real, devem demonstrar rejeição antes de DDL para ausência de aprovação, UUID incorreto, banco não vazio, namespace incorreto, `DB_URL`/socket ou configuração de leitura/escrita não permitida. Testes do agregador devem rejeitar JUnit ausente, duplicado no mesmo perfil, identidade omitida, asserções reduzidas, skip, falha, engine incorreto e SHA divergente. Ao menos um teste deve comprovar que `artisan migrate:fresh` não é chamado após recusa.

## Pendências e limites

Esta proposta não foi implementada nem executada. A execução anterior positiva de MySQL do laboratório não comprova o harness combinado. Faltam orçamento de recursos para dois perfis em CI, prova de cleanup preservando evidência, revisão independente de segurança do namespace e credenciais sintéticas, teste ponta a ponta do gate e homologação em ambos os perfis. O gate deve continuar falhando até que essas evidências existam; não integrar nem ativar a funcionalidade com base apenas neste documento.
