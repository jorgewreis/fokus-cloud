# Proposta: reconciliação e idempotência para empresas no Backoffice

**Estado: proposta de laboratório, não implementada nem autorizada para integração por este registro.** Escopo: POST e PATCH administrativos de empresa no Cloud. A solução de interface por aba não é uma garantia de idempotência do servidor. Esta proposta não autoriza executar migration, alterar dados reais ou contornar os gates de release.

## Evidência e controles existentes

- No protótipo de interface `c30245f8`, `pagehide` limpa o estado mesmo quando o navegador mantém a página no BFCache. Com Edge e servidor/proxy sintéticos, BFCache explicitamente habilitado e cabeçalho HTML controlado, uma requisição pendente retornou após `pagehide/pageshow` com `persisted=true`: não houve aviso e um segundo clique emitiu outro POST. Nenhum banco real foi usado; duplicação persistida não foi demonstrada, nem o cabeçalho servido em produção foi verificado.
- Em contraprova temporária, preservar o estado quando `event.persisted` manteve o resultado de sucesso no mesmo fluxo sintético. A alteração isolada não resolve reload, outra aba, invalidação de sessão em outra aba nem segurança do conteúdo restaurado. Uma restauração BFCache precisa bloquear interação, revalidar sessão/identidade/permissões e só então revelar o conteúdo ou redirecionar.
- O esquema declarado tem unicidade de documento da empresa, CPF e e-mail do usuário; PATCH usa versão otimista e transação. Esses controles mitigam repetições específicas, mas não reproduzem a resposta original e não constituem contrato de idempotência por requisição.
- O ensaio `tests/Deployment/probe_company_idempotency_mysql.py` cria seu próprio MySQL sem rede ou portas e com dados sintéticos. Ele verificou, via `performance_schema.data_lock_waits`, que uma disputa por chave única aguardou o commit, resultando em erro de duplicidade para o segundo inseridor e uma única mutação; quando a primeira conexão foi encerrada antes do commit, a segunda pôde adquirir a chave e confirmar. É uma prova das primitivas InnoDB, não do fluxo Laravel, replay, segurança ou produção.

## Contrato proposto

1. **Compatibilidade.** Enquanto não houver ativação verificada, requisições sem chave mantêm o contrato anterior. Para requisição com chave, falha de verificação, chave vencida, indisponibilidade da tabela ou inconsistência do registro deve interromper antes da mutação; nunca voltar silenciosamente ao caminho legado. Os clientes legados não passam a ser idempotentes.
2. **Chave com validade verificável.** Um endpoint autenticado e autorizado para o domínio emite token opaco assinado, com versão, expiração e aleatoriedade, vinculado ao administrador e ao tipo de operação. O prazo é configurável; 24 horas é um valor inicial proposto a validar. O token transita em `Idempotency-Key`, não em URL ou logs. Após expiração, o servidor recusa nova mutação com esse token mesmo que o registro tenha sido limpo; não converte uma chave antiga em operação nova. Rotação de chave deve permitir verificação de tokens ainda vigentes ou falhar fechada, sem reexecutar.
3. **Vínculo do conteúdo.** Normalizar os campos efetivamente usados, com ordem estável, versão do contrato, método, operação e alvo. Calcular no servidor um HMAC versionado do payload canônico, incluindo senha exata quando presente, com segredo de alta entropia e domínio separado. Não armazenar payload, senha, segredo, token bruto nem dados pessoais em logs. A mudança de dados com a mesma chave registrada retorna conflito. Proteger segredo e acesso à tabela; HMAC não torna dados públicos seguros se o segredo for comprometido.
4. **Atomicidade.** Criar tabela aditiva com chave única por administrador e hash do token, HMAC versionado, estado e referência mínima do resultado. A reserva da chave, a mutação de empresa e o resultado confirmado devem ser gravados na mesma transação. O segundo inseridor concorrente aguarda ou recebe uma resposta explícita de contenção; após conflito de unicidade, consultar o registro confirmado e comparar o HMAC antes de devolver o mesmo status/resultado mínimo. Rollback não pode deixar registro de sucesso; falhas de conexão/timeout não são convertidas em sucesso.
5. **Ordem.** Autenticação, `platform.companies.manage`, CSRF, validação estrutural e verificação da assinatura da chave antecedem qualquer replay. A consulta de replay confirmado deve ocorrer antes dos checks de unicidade e da consulta externa de segurança de senha; caso contrário, uma repetição do sucesso pode virar 409 ou repetir chamada externa. Para uma operação ainda não confirmada, validar o negócio e manter a verificação externa fora de locks prolongados; efetuar os checks concorrentes e a escrita na transação. Reavaliar todas as exceções para não apresentar falha posterior ao commit como rejeição definitiva.
6. **Reconciliação.** Expor consulta autenticada e autorizada por cabeçalho para um resultado mínimo, sem dados de formulário. Registro ausente pode significar requisição ainda não observada ou não commitada: responder `não observado`, nunca `não executado`. Sem resposta conclusiva, manter indeterminação; uma tentativa explícita com a mesma chave e o mesmo corpo é arbitrada pela constraint. Após reload, o cliente armazena apenas token opaco, tipo de operação e horário em `sessionStorage`, sem senha/documentos/nome/e-mail; não reenviar dados automaticamente. Se precisar de nova tentativa, exigir reentrada e recusar conteúdo divergente.
7. **Ciclo de vida.** Os avisos são reconhecíveis e não movem foco automaticamente. No logout confirmado, limpar dados de reconciliação locais e invalidar callbacks antigos; a autoridade continua no servidor. Na restauração BFCache, preservar operação pendente enquanto revalida o administrador e as permissões, sem exibir dados antigos a outra sessão. O retorno de outra aba, expiração de sessão e cancelamento de fetch devem ser testados; não presumir que `pagehide` cancela a operação no servidor.

## Migração, release e testes obrigatórios

- Acrescentar tabela e índices por migration reversível em laboratório, com tempo de retenção, expiração e limpeza coordenados ao prazo dos tokens assinados. Não apagar registros ainda válidos; não usar `down` destrutivo em produção com operações registradas. Versionar chave de HMAC/assinatura e definir recuperação em rotação antes do deploy.
- O contrato atual de `tests/Deployment/linux-upgrade.py` fixa cinco migrations candidatas nomeadas. Uma nova migration exige atualização revisada do manifesto e dos testes de upgrade, interrupção, restore e identidade; não enfraquecer o gate nem presumir ambiente de produção compatível.
- Entregar backend e migration aditivos antes do frontend. Uma capability por nó isolado não prova que todos os nós aceitam a chave: confirmar cutover atômico ou roteamento homogêneo e a identidade do release antes de ativar o cliente. Compatibilizar workers, cache de assets e rollback; versão antiga que ignora o cabeçalho não pode receber cliente que confia na proteção. Enquanto isso, manter o cliente anterior e não anunciar idempotência.
- Em Laravel + MySQL descartável: mesmo token/corpo sequencial e concorrente, mesmo token/corpo diferente, tokens diferentes, administradores e permissões distintos, CSRF, PATCH com versão obsoleta, validação 422, conflito 409, timeout/408/500, morte antes do commit e perda da resposta após commit, deadlock/lock timeout, expiração, limpeza, rotação e restore. Verificar uma única alteração e evento de auditoria, resposta de replay e ausência de payload sensível na tabela/logs.
- Em navegador com dados sintéticos: BFCache com evento `persisted`, reload, nova aba, logout/login, sessão expirada, múltiplas operações distintas, confirmação e foco, falhas de rede e servidor antigo/sem migration. O teste deve observar requisições reais do laboratório e usar barreiras de sincronização, não apenas espera fixa. Não afirmar leitor de tela, integração real ou produção a partir de mocks.

## Limites desta prova

A branch deste registro não adiciona migration, endpoint ou lógica de idempotência ao produto. O teste MySQL prova apenas locks e transações sobre tabelas sintéticas. A proposta exige revisão independente de segurança, contrato e migração, implementação e validação no stack real antes de qualquer integração. A correção BFCache isolada é insuficiente como solução geral.

### Reproduzir as provas de laboratório

Os dois programas abaixo são opt-in e não integram a suíte automática. Execute somente em cópias isoladas sem segredos e com dependências já instaladas. O primeiro altera o cabeçalho HTML **apenas no proxy sintético** e compara o handler exato do checkout informado com uma substituição em memória; ele não altera o código nem testa headers de produção. Requer Playwright e navegador locais. O bloqueio por DNS do navegador não é isolamento de rede no sistema operacional.

```powershell
$env:COMPANY_BFCACHE_DISPOSABLE = '1'
$env:COMPANY_BFCACHE_LAB_ROOT = 'D:\caminho\checkout-isolado'
$env:COMPANY_PLAYWRIGHT_ROOT = 'D:\caminho\checkout-com-playwright'
$env:COMPANY_BFCACHE_BROWSER = 'C:\caminho\navegador.exe'
node tests/Deployment/probe_company_bfcache.mjs
```

Na execução de referência: Edge `158.0.4370.16`, hash SHA-256 da fonte `89f45e73016edc82eacdd2c12862e6f2b317e8b4fb61cb977201bb4bd0a489a3`, BFCache observado em ambos os cenários; baseline emitiu dois POSTs sintéticos e o protótipo um. O segundo programa exige ID completo `sha256:` de imagem MySQL 8 já existente, Docker e confirmação explícita. Cria um container próprio `--network none`, sem portas, com banco em tmpfs, e o remove ao final; não usa banco existente.

```powershell
$env:COMPANY_IDEMPOTENCY_DISPOSABLE = '1'
$env:COMPANY_IDEMPOTENCY_MYSQL_IMAGE = 'sha256:<id-completo-da-imagem-local>'
python tests/Deployment/probe_company_idempotency_mysql.py
```

Resultado observado: concorrente bloqueado até commit recebeu erro 1062 e havia uma única empresa; após encerrar a conexão que detinha reserva não commitada, o concorrente adquiriu a chave e concluiu. A identificação de espera consultou `performance_schema.data_lock_waits`. A senha e os registros da prova são sintéticos. O resultado não valida o serviço proposto nem modifica o gate de release.
