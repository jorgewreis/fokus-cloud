# Confiabilidade operacional do deploy

## Estado desta entrega

Investigação iniciada em 08/11/2026. Tarefa [REI-22](https://linear.app/reis-developer/issue/REI-22), relacionada às REI-20/REI-21. Base auditada: `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.

**Preparação em branch, não prontidão para produção.** Nenhum merge, deploy, migration produtiva, comando no servidor, mudança de permissões, restart ou restauração produtiva foi executado nesta tarefa. Nenhuma credencial foi lida. O endpoint dinâmico descrito abaixo ainda não existe no código auditado: sua implementação, provisionamento e validação precisam de escopo e autorização próprios. A ausência dele bloqueia o pipeline proposto **antes do SSH e da primeira migration**, não depois de transferir arquivos.

Esta entrega modifica apenas workflow, scripts operacionais, testes sintéticos e documentação. Não altera comportamento de negócio, schema, dependências, cron de backups, política de disparo do deploy ou configuração ativa de produção.

## Evidências e causas

### Falso verde da recarga

No [deploy de 08/11, job 128838073605](https://github.com/jorgewreis/fokus-cloud/actions/runs/42515472141/job/128838073605), às 19:35:41.758 e 19:35:41.766 UTC, duas tentativas produziram `sudo: a password is required`:

```sh
ssh ... "$DEPLOY_USER@$DEPLOY_HOST" \
  "sudo -n systemctl reload clp-php-fpm.service || sudo -n systemctl restart clp-php-fpm.service" \
  || echo "PHP-FPM reload requires server-side sudo; continuing deployment."
```

`sudo -n` não permite pedir senha. O contexto não tinha autorização sudo utilizável sem senha para esses comandos; isso não prova ausência de todas as regras sudo nem qual regra específica exigiu a senha. O fallback tentou restart após recusa de autorização, não após comprovar que restart era necessário. O `echo` externo retornou zero e substituiu a falha do SSH. `set -e` não interrompe uma lista OR dessa forma. O verde comprova que o workflow terminou, não que houve reload.

- `DEPLOY_USER` é o usuário SSH escolhido; seu nome real está mascarado. A [inspeção histórica](https://github.com/jorgewreis/fokus-cloud/actions/runs/36037287070) também imprime `ssh_user=***` e `app_path=***`.
- `clp-php-fpm.service` é a unidade solicitada pelo script. **Não foi comprovado que seja o serviço/pool/socket que atende o vhost.**
- PHP CLI 8.4 e Composer 2.9.5 constam nos logs. CLI não comprova versão/configuração do PHP-FPM web.
- `php artisan optimize` prepara caches Laravel; não é prova de invalidação do OPcache do pool web. `opcache_reset()` no CLI tampouco comprovaria invalidação no FPM.
- Reload pode reler configuração/recriar workers conforme a unidade; restart é uma operação distinta, potencialmente mais disruptiva. Não é necessário reiniciar PHP após todo deploy por definição. A necessidade depende do pool, `opcache.validate_timestamps`, `opcache.revalidate_freq`, preload, realpath cache, workers longos e modelo de publicação efetivo.

**Correção preparada:** `deploy/reload-runtime.sh` exige política explícita `reload` ou `verify-only`. Em `reload`, executa somente `sudo -n systemctl reload -- UNIDADE_VALIDADA`, propaga o status não zero e não tenta restart. Em `verify-only`, não chama sudo/systemctl e registra que não houve ação; não afirma limpar OPcache. Falha de uma recarga declarada necessária bloqueia a conclusão, mesmo que HTTP responda 200. Nunca escolher `verify-only` apenas para esconder uma recarga negada.

Não foi concedido privilégio. Eventual operação futura precisa primeiro identificar o serviço efetivo e demonstrar a necessidade; se uma permissão estreita for necessária, deve passar por revisão e autorização separadas. Sudo administrativo amplo não é solução proposta.

### Destino e identidade

O workflow usa referências a `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`. Referência no YAML **não comprova existência ou valor de secret**. O plugin disponível não expõe metadados administrativos de secrets; a página de settings no navegador retornou 404 com Sign in, sem sessão autenticada. Nomes existentes, datas de atualização, escopos e política efetiva de aprovação do environment não foram obtidos.

A consulta somente leitura ao Hostinger confirma VPS 1053508 running e template declarado Ubuntu 24.04 with CloudPanel. O IPv4 desse inventário corresponde aos registros DNS A da raiz/www/Law/Lead/Styles. Essa correlação não identifica o destino secreto do Actions nem prova qual vhost e release atendem uma requisição. Os registros operacionais Hostinger não fornecem ligação a `DEPLOY_HOST` ou ao commit publicado.

`StrictHostKeyChecking=yes` valida a chave contra `known_hosts` configurado; não prova que essa chave pertence à VPS inventariada independentemente. Não foram impressos IPs privados, chaves ou valores dos secrets.

**Verificação futura, ainda não implementada:** o responsável obtém fingerprint SSH por canal independente do secret; vincula-o ao inventário, usuário de deploy, identificação opaca do destino, vhost e document root. Um preflight autorizado compara o alvo efetivo a esse registro, antes de mudanças, emitindo apenas match/mismatch e referência da evidência. Fingerprint, DNS ou hostname isolados não demonstram o conjunto. Nenhum arquivo de identidade, permissão ou serviço foi criado no servidor.

### Publicação parcial

O workflow atual sincroniza no diretório ativo antes de instalar dependências, migrar e preparar caches. Não é publicação atômica; falha após rsync pode deixar arquivos novos com dependências/banco/caches antigos. A migration aditiva pré-publicação também é uma mutação anterior ao sync. Esta entrega não converte o deploy para releases atômicos nem inventa rollback automático.

O novo resumo distingue cada fase e marca publicação **não integralmente verificada** quando qualquer fase obrigatória falha, fica ignorada ou não tem resultado. O resumo não desfaz mudanças. Uma falha após início de mutação exige preservar evidências e avaliar estado parcial, não repetir o workflow ou reverter banco automaticamente.

## Protocolo de publicação preparado

Gatilhos, concorrência `production-deploy`, `cancel-in-progress: false`, environment `production`, job Validate application e checks existentes são preservados. PRs executam validação, não publicação. Os testes novos entram no job existente sem remover PHPUnit, SEO, tokens, contrato ou regressão visual.

| Fase | Evidência e comportamento |
| --- | --- |
| Pré-condições | Configuração explícita validada; `/up` e contrato dinâmico do release atual comprovados antes de qualquer SSH |
| SSH | Conexão usando known_hosts configurado; não substitui reconciliação independente de inventário |
| Schema pré-publicação | Helper e migration existentes preservados; depende da correção separada #56 para eliminar seu falso verde conhecido |
| Assets imutáveis | Transferência seguida de checksums já existentes |
| Arquivos | rsync concluído, sem afirmar ativação do PHP |
| Runtime CLI | Verificações existentes de PHP/Composer/arquivos, sem inferir FPM |
| Dependências | Composer conclui e seu status precisa ser zero |
| Migrations | `php artisan migrate` precisa concluir sem erro; não implica reversibilidade |
| Caches | `php artisan optimize` precisa concluir; não é OPcache |
| Serviços | Política explícita; falha real bloqueia; verify-only é registrado como nenhuma recarga solicitada |
| Datajud | Configuração requerida; probe de serviço externo continua consultivo e identificado como tal |
| CDN | Purge opcional existente, quando configurado; não é prova de release |
| Saúde e release | `/up` 200 e resposta dinâmica exata para SHA candidato e nonce, três amostras consecutivas, TLS válido, sem redirect |
| Resumo | Sempre executado; distingue success/failure/cancelled/skipped/unknown e não transforma skipped em sucesso |

### Configuração necessária, não aplicada

Variáveis propostas no environment `production`:

- `DEPLOY_PUBLIC_BASE_URL`: origem HTTPS canônica exata, sem usuário/senha/query/fragmento.
- `DEPLOY_RELEASE_URL`: endpoint HTTPS dinâmico, mesma origem, sem credenciais/query/fragmento.
- `FPM_RELOAD_MODE`: `reload` ou `verify-only`, após investigação do runtime.
- `FPM_SERVICE`: unidade explícita permitida pelo script quando modo reload; não preencher por suposição.
- `EXPECTED_RELEASE`: preenchido pelo workflow com `github.sha`, não por configuração manual.

Nenhuma variável foi criada, nenhum valor de secret foi alterado e nenhum novo acesso foi concedido. O script aceita apenas argumentos/configuração limitados e não imprime URLs, payloads, headers ou exceções remotas. Não segue redirects nem desabilita TLS. Erros de transporte, status inesperado, corpo inválido, cache ou identidade divergente falham fechados.

### Identificação dinâmica proposta, não implementada

Já existe health `/up` em `bootstrap/app.php`. Ele pode demonstrar boot Laravel, mas não o commit executado, nem sozinho disponibilidade do banco/todos os fluxos. Não há identificação de release apropriada no bootstrap/rotas auditados. Não usar versão de catálogo comercial como versão do código.

Contrato proposto para um endpoint dinâmico seguro, em alteração de aplicação separadamente autorizada:

```json
{"status":"ok","release":"<SHA Git completo de 40 caracteres>","probe":"<nonce recebido na query deploy_probe>"}
```

O endpoint deve responder HTTP 200, `Content-Type: application/json` e `Cache-Control: no-store`, somente esses três campos, sem ambiente, caminhos, versões de dependências, nomes internos ou dados pessoais. O nonce aleatório muda a cada consulta e impede aceitar JSON estático ou resposta de cache. O SHA deverá estar ligado ao código realmente carregado no caminho dinâmico, não lido de um arquivo marcador que rsync acabou de copiar. Endpoint novo não deve expor debug, causar mutações ou consultar dados pessoais. Revisar abuso/limites antes de publicação.

Um sidecar estático, arquivo `.git/HEAD`, variável mutável ou arquivo JSON recém-transferido pode identificar arquivos no disco e ainda coexistir com PHP antigo no OPcache. Não é contrato suficiente. O mecanismo deve ser validado sob o pool/OPcache real e considerar configuração cacheada, preload e workers longos. Um release immutable/atomic pode facilitar isso, mas exige desenho adicional, não está implementado aqui.

O `probe-current` confirma que esse contrato **já está ativo** antes de mutações, aceitando qualquer SHA válido do release atual. O `verify` final compara o SHA candidato exato. Três amostras consecutivas reduzem falsos positivos de roteamento/caching, mas não provam todos os workers, backends ou rotas. A origem verificada e o destino SSH ainda precisam ser vinculados por evidência independente.

Até essa preparação ser aprovada, implementada e testada, o pipeline proposto interrompe intencionalmente no preflight. Não mudar variáveis para contornar o bloqueio nem autorizar merge com deploy automático apenas porque os testes sintéticos passaram.

## Backups: diagnóstico do intervalo

Workflow histórico e atual: cron `17 3 * * *`, diário às 03:17 UTC. Foram observadas 45 execuções agendadas, uma por dia entre 25/09 e 08/11: 44 success, uma failure.

A falha de [28/09, run 36405176792](https://github.com/jorgewreis/fokus-cloud/actions/runs/36405176792), job 108872078691, ocorreu no primeiro export via SSH:

- 09:42:46 UTC: início do pipeline SSH/export, gzip e GPG.
- 09:44:59.360 UTC: `Connection timed out` para host/porta mascarados.
- 09:44:59.364 UTC: código de saída 255.
- `set -euo pipefail` propagou corretamente a falha. Restore e upload skipped; limpeza da passphrase executada.

Causa comprovada da ausência de backup nesse dia: **falha de execução/conectividade SSH**, não retenção ou falha de restore. A causa infraestrutural do timeout (rede, host, firewall, SSH) não foi comprovada; não atribuir à Hostinger sem identificar o destino efetivo e correlacionar logs. O histórico Hostinger consultado não mostra ação nessa data que explique o evento.

| Referência | Timestamp UTC |
| --- | --- |
| [27/09, run 36308640653](https://github.com/jorgewreis/fokus-cloud/actions/runs/36308640653), criação | 09:12:41 |
| Upload desse artefato finalizado | 09:13:24.176806 |
| [29/09, run 36551324677](https://github.com/jorgewreis/fokus-cloud/actions/runs/36551324677), criação | 09:46:28 |
| Upload desse artefato finalizado | 09:47:09.783111 |

Intervalo entre criação dos runs verdes: **48h33m47s**. Entre uploads finalizados: aproximadamente **48h33m45,6s**. Isso não mede o instante transacional exato dos dumps. O último intervalo de criação entre sucessos observado foi 24h13m49s.

Há atraso observável entre cron 03:17 e criação às 09:12/09:42/09:46; sua causa interna no GitHub não foi determinada. Não há dia sem disparo nesse intervalo. Atrasos variáveis contribuem para intervalos acima de 24h mesmo quando não há falha. Retenção de 30 dias explica expiração posterior, não o intervalo histórico de sucessos. **Cron não alterado.** Investigar conectividade e observabilidade antes de ajustar periodicidade; eventual timeout/retry e alerta de idade exigem desenho próprio para evitar repetição cega e nova dependência operacional.

## Matriz de recuperação

| Componente | Evidência disponível | Limite/condição para recuperar |
| --- | --- | --- |
| Banco | Dump MySQL com single transaction, gzip/GPG, manifesto; importação isolada do backup novo e leitura agregada de cinco tabelas | Confirmar engines/consistência, ledger/schema e ponto compatível. Não baixamos nem abrimos dados reais nesta tarefa. Restore pode perder alterações posteriores |
| Persistência | Backup cifrado de `storage/app`, extração isolada registrada | Não cobre automaticamente outros discos/mounts. Diretório ausente gera tar vazio aceito. Banco/storage são capturados separadamente, sem snapshot conjunto comprovado |
| Código | Git e lockfiles disponíveis em main e commits anteriores | Reconstrução depende de runtimes/registries/artefatos. Git não inclui vendor nem garante build recuperável |
| Configuração | Config PHP, `.env.example`, fragmento NGINX e scheduler versionados | `.env`, APP_KEY e NGINX efetivo não estão no backup Actions. Cofre, versões e acesso precisam ser comprovados sem armazenar valores |
| Release anterior | Objetos Git anteriores existem, inclusive `b3d441ae81136f7c985b4a6e0eb96f4e5ca63026` | Nenhum release completo/vendor/diretório anterior ou release GitHub foi comprovado. Sem troca atômica automática |
| Migrations irreversíveis | Histórico versionado permite revisão | Revert Git não reverte banco. Definir compatibilidade backward/forward, estratégia de reparo e perda aceitável antes de restore aprovado |
| Credenciais | Consumidores e rotação documentados no runbook de segurança | Confirmar custódia de APP_KEY/chaves anteriores, backup passphrase, DB, SSH e integrações. Não regenerar APP_KEY para fingir recuperação de dados cifrados |
| Dependências operacionais | SSH, PHP/FPM, webserver, DB, cron/filas, DNS/CDN e serviços externos identificados como dependências | Mapear versões/configuração reais e capacidade disponível. Contas, grants e instalações novas exigem aprovação pertinente |
| Backup VPS | Hostinger lista 02/11 18:29:26 e 26/10 01:25:06; ações concluídas 18:42:02 e 01:35:36 | Existência não comprova consistência/restore funcional. `restore_time:2300` é metadata, não RTO medido |
| Tempo | Meta documental RPO até 24h/RTO até 4h | Histórico não garante RPO24h. Tempo de job/restore de dump não mede recuperação operacional completa; RTO ainda não comprovado |

[Backup de 08/11](https://github.com/jorgewreis/fokus-cloud/actions/runs/42470621270): checksums, importação no MySQL isolado e extração passaram; artefato 13613582019, 619446 bytes, expiração 08/12. Isso demonstra recuperação **parcial**, não boot da aplicação, login, configuração completa, integridade referencial plena ou acesso ao cofre. MySQL 8.4.13 observado é do runner de restore, não comprova versão do banco de produção.

### Procedimento proporcional, sem execução em produção

1. Interromper novas publicações, preservar logs e estado parcial. Não cancelar/reexecutar run legado apenas por badge queued: a auditoria anterior comprovou attempt/job success.
2. Identificar destino, commit anterior e ponto de backup compatível com schema e storage. Documentar perda potencial desde o ponto escolhido. Recuperação de código somente pode bastar quando compatibilidade de schema/dados for demonstrada.
3. Confirmar inventário e disponibilidade da custódia: passphrase do backup, APP_KEY/chaves anteriores, DB e configuração efetiva, sem copiar valores para tickets/logs. Ausência de material essencial bloqueia promessa de recuperação.
4. Em ambiente isolado já autorizado e sem custos novos, validar checksum de artefato retido antes de decifrar. Usar credenciais próprias e saída externa bloqueada. Não apontar ferramentas a produção. Restaurar banco vazio e persistência, reconstruir fonte/dependências pelos locks e configuração específica do isolamento.
5. Verificar schema/ledger, integridade referencial, contagens agregadas e relação banco/storage. Subir app isolado, testar fluxos sintéticos de login/consulta; impedir e-mails, pagamentos, webhooks e tarefas externas reais.
6. Medir da obtenção do material ao boot/validação completos; registrar início/fim de cada etapa, RPO real, restrições e responsável. O exercício sintético desta branch não substitui exercício com artefato real retido e autorização adequada.
7. Só propor intervenção em produção após avaliar compatibilidade, perda de dados, impacto e janela. Restore de banco, ajustes de serviço, permissões ou deploy precisam da autorização correspondente. Não aplicar `migrate:rollback` nem `git revert` como recuperação universal.

## Compatibilidade e integração

- #56 continua separado e necessário: esta branch não altera `deploy/prepublish-law-schema.php` nem os testes/correções de schema. Sem #56, o falso verde daquele helper permanece na base auditada. Não interpretar o novo resumo como auditor do conteúdo de cada helper.
- #55 altera o mesmo workflow para classificação conservadora. Preservar `classify`, dependências `needs`, condição docs_only e todos seus checks quando integrar; esta entrega não remove nem recalibra o fingerprint.
- O fingerprint pinado de #55 deve continuar divergente após mudança de workflow/script; isso mantém caminho normal de publicação, não docs_only. Não atualizar o pin automaticamente para fazer passar.
- Composição isolada desta branch com #55 (`71e31abb1ace466f9d3ee411580d1d3a8bcc583c`) e #56 (`8de6de496832684987302271af5112b82a5570ba`) aplicou ambos patches sem conflito textual. Os 47 testes operacionais passaram; validação documental de #55 retornou zero erros; gate/classify/needs foram preservados.
- **Incompatibilidade de teste identificada:** 25 dos 26 testes de #55 passaram na composição. `WorkflowContract.test_existing_job_bodies_preserved` compara os corpos inteiros de validate/deploy com a main histórica e falha com as adições/correções autorizadas aqui. A integração conjunta ainda não está verde. Não removemos, alteramos nem enfraquecemos esse teste/PR para esconder o resultado.
- Antes de integração conjunta, revisar separadamente esse contrato: conservar a prova dos comandos, ordem e checks preexistentes; admitir somente as novas fases operacionais e a substituição específica do reload defeituoso. Não substituir por um teste que aceite qualquer workflow nem atualizar fingerprint automaticamente. A adaptação deve ter teste negativo mostrando que remoção/alteração de check obrigatório continua falhando.
- O ensaio não fez merge de branches remotas e não executou aplicação PHP/MySQL da composição neste executor. Aprovação desta preparação não autoriza integrar #55/#56 nem publicar.
- A sequência operacional depende de comprovar destino/serviço/política/release/recuperação e autorização expressa. Merge em main dispara o deploy existente e não é uma etapa neutra.

## Verificação e limitações

Testes em `tests/Deployment/test_operational_*.py` exercitam contratos sem produção: configuração ausente/host/URL inválidos; sucesso dinâmico; HTTP/redirect/JSON/cache/nonce/release incorretos; recarga negada e propagação de status; resumo por fase; recuperação de fixture sintética retida, corrupção, falta de configuração e incompatibilidade após migration destrutiva.

A recuperação sintética usa SQLite, tar, diretórios temporários e boot de um programa Python mínimo recuperado; não restaura a aplicação Laravel nem suas dependências; não é teste de dump MySQL produtivo, cifra real, cofre, OPcache/FPM real nem medição de RTO. Testes do verificador não instalam endpoint. CI normal permanece responsável pelas regressões existentes da aplicação. Validação local: 47 testes operacionais aprovados, incluindo HTTPS loopback com CA temporária e teste da etapa SSH que preserva 1/42/255. YAML e sintaxe Bash dos 31 blocos run passaram; `git diff --check`, tokens, SEO (15 páginas) e contrato visual (19 páginas) passaram. O teste de assets confirmou manifesto/14 arquivos; a parte rsync foi ignorada porque o executável está ausente. PHP/Composer não estão instalados neste executor; PHPUnit/build/visual completos dependem dos runners existentes do CI. Resultados remotos e SHA exato ficam no PR e checkpoint da REI-22.

**Ainda dependem de acesso/intervenção/autorização:** metadados e política efetiva GitHub production; vínculo independente do alvo; leitura controlada de vhost/pool/OPcache e schema atual; desenho/aprovação do endpoint; custódia/configuração/artefato recuperável completos; exercício isolado real autorizado; eventual permissão mínima para ação necessária; qualquer merge/deploy ou alteração produtiva. Nenhuma dessas condições é considerada satisfeita pelo teste sintético ou badge verde.
