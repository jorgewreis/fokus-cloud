# B+C: implementação isolada de preparação e ativação (REI-24)

## Estado e autorização

**Implementação para revisão; não pronta para produção.** Pedido de Jorge em
09/11/2026, 10:07 UTC: scripts, testes, documentação e PR próprio, sem merge,
deploy, acesso modificador à VPS, migrations produtivas, permissões, secrets ou
serviços. A fila oficial permanece [REI-24](https://linear.app/reis-developer/issue/REI-24).
O componente pertence ao Cloud operacional; não altera domínio Law/Lead.

`tools/releases/immutable.py` fornece primitivas locais e opt-in. Não tem SSH,
rsync, instalação, SQL, comandos de serviço, alteração de cron, limpeza/retention
nem ligação ao workflow produtivo. **Não executar sobre a VPS nesta etapa.** O
workflow novo executa apenas fixtures descartáveis em PR/manual, sem secrets,
ambiente production, push em main ou jobs de publicação. Os workflows existentes
não foram editados. Merge na main ainda teria consequências produtivas pelo
workflow existente; não está autorizado.

## O que a implementação faz

1. `package`: exporta o SHA40 exato por `git archive`, confronta cada byte exportado
   com o checkout limpo e inclui vendor preparado e uma lista exata de assets
   gerados. Tar determinístico, sem timestamps variáveis, com inventário de hashes,
   tamanhos e executabilidade. Nenhum install/build é executado implicitamente.
2. `unpack`: recebe digest SHA256 de uma fonte confiável externa ao pacote. Lê uma
   única fotografia dos bytes, confere digest e todos os membros antes de criar
   destino novo. Recusa links, hardlinks, traversal, duplicatas, tipos especiais,
   modos privilegiados e limites excedidos. Grava manifesto de transporte privado
   fora da raiz, com digest do artefato. Interrupção não ativa nem reutiliza destino.
3. `link-shared`: liga somente `storage` e `.env`, já provisionados e fora da raiz.
   Nunca substitui um arquivo/link existente, copia secrets ou modifica o alvo.
4. `seal`: confere novamente os bytes do transporte; adições locais só em
   bootstrap/cache. Exige manifests `packages.php` e `services.php` já preparados.
   Executa o preparador **inalterado e fixado do PR #59**, que valida fonte, SHA,
   raiz absoluta final e manifesto runtime. Remove write bits apenas na candidata,
   sem seguir links; emite receipt externo. Não faz chown/ACL nem protege contra
   root/administrador: permissões efetivas ainda exigem evidência separada.
5. `activate`: somente sobre layout previamente provisionado e com `current`
   existente. Evidência privada vincula host, operador, raiz, base, receipt e SHA,
   com expiração. Valores desconhecidos/null bloqueiam. A evidência de scheduler também vincula host/raiz/receipt, binário PHP por hash, plataforma e ausência de descendentes. Sob locks exclusivos de
   ativação e scheduler, compara anterior esperado, integridade e mesmo device.
   Persiste latch/journal antes de `os.replace` do ponteiro. Executa o verificador
   **inalterado e fixado #57** e reconfere integridade antes de registrar verificação.
6. `rollback`: usa os mesmos controles, mais evidência explícita de compatibilidade
   de código N/N-1 e ausência de necessidade de restore de dados. Não desfaz banco,
   storage ou configurações; não escolhe nem retorna automaticamente ao anterior.
7. `schedule`: resolve current uma única vez sob lock, busca receipt por geração,
   valida a árvore e executa PHP explícito/Artisan sem shell ou saída sensível.
   O receipt e a evidência privada do PHP/scheduler acompanham a geração; o cron proposto não fixa SHA antigo.

Receipts e políticas são registros privados de um operador confiável, **não uma
assinatura criptográfica nem prova gerada automaticamente**. Um booleano não é
medição: só pode ser preenchido a partir de evidência ligada ao alvo e revisão
externa. Não existe gerador que preencha tudo com true. O template mantém null.
Um administrador que falsifique/reescreva política, receipts ou scripts está fora
da fronteira de confiança. A ferramenta não substitui aprovação humana/da plataforma.

## Layout, imutabilidade e compartilhamento

```text
<base>/releases/<tentativa-unica>/<SHA40>/   raiz final antes do selo
<base>/current -> releases/<tentativa-unica>/<SHA40>
<base>/receipts/<tentativa-unica>/<SHA40>.json
<base>/shared/storage/                    dados mutáveis
<base>/shared/configuration/<revisao>.env   configuração privada controlada
<base>/activation.lock
<base>/scheduler.lock
<base>/activation-blocked.json            existe durante switch/falha
```

A ferramenta não cria o layout produtivo nem instala locks/permissões. O basename
SHA e raiz real são exigidos pelo #59. Não mover a raiz após selo, reutilizar uma
raiz, instalar Composer/npm no ativo ou executar optimize/package:discover após
selo. O namespace de tentativa permite repetir preparo em raiz nova, sem relabel.

Storage: uploads privados/públicos, sessões em arquivo, cache/data, logs e views
compiladas permanecem mutáveis fora do código. Drivers DB/Redis, prefixes e locks
precisam ser conferidos N/N-1. Views compartilhadas têm riscos de colisão/conteúdo
antigo: devem ser validadas com os paths reais; não são atestadas pelo endpoint.
`bootstrap/cache` permanece local e selado, nunca symlink compartilhado.
Manifests de pacote/serviços devem ser produzidos na raiz final por PHP compatível,
antes do selo. Config cache pode conter secrets: não colocá-lo no pacote público.
O receipt privado só guarda hashes; não registra valores de configuração.

O runtime não pode ser root, proprietário dos arquivos/diretórios de código ou
controle, nem ter ACL de escrita. O modo read-only isolado não prova isso. O
operador de preparo pode legitimamente administrar a candidata, mas não deve
compartilhar identidade com PHP-FPM. O estado real de owner-write encontrado na
VPS é uma pendência, não foi corrigido por chmod/chown produtivo.

O configurador Datajud existente usa rename sobre `.env` e destruiria o link.
O novo tooling **não o chama**. Também não chama o instalador de cron antigo, que
fixa pwd físico. O workflow #57 continua in-place e não deve ser reapontado para
current: isso substituiria links/arquivos ou escreveria na release ativa. Não há
reconciliação silenciosa desses writers nem promessa de compatibilidade.

## Scheduler e processos

O lock é cooperativo: todos os ativadores/schedulers futuros devem utilizar o
mesmo inode pré-provisionado e protegido. Não cria exclusividade sobre cron legado,
workers externos ou chamadas administrativas. Contenção falha com exit não zero,
sem cancelar processos. Há potencial de atraso/perda de uma janela de agenda;
o operador deve observar e decidir recuperação, sem repetir jobs cegamente.

**Bloqueio concreto:** routes/console.php atual chama `runInBackground()` para
queue:work. A primitiva recusa esse candidato tanto antes do switch quanto antes
do scheduler. Um processo filho não herda magicamente o lock até terminar.
A ausência de descendentes exige evidência; detectar esse token é um controle
conservador para o defeito conhecido, não um analisador completo de PHP.
Não foi alterada a semântica da aplicação para serializar jobs nesta entrega.

Timeout/falha de scheduler não é sucesso e grava latch que bloqueia nova agenda e ativação até revisão explícita. Não há kill/restart automático de toda a
árvore de processos. Deve-se provar término dos descendentes antes de qualquer
ativação; políticas não podem reutilizar uma atestação anterior ao último job.
Workers persistentes continuam excluídos pelo #59. A troca do link não atualiza
FPM/preload/OPcache nem prova convergência de processos.

Exemplo **não instalável até homologação**, com caminhos/valores intencionalmente
não preenchidos:

```text
python3 <tooling-revisado>/immutable.py schedule --base <base-aprovada> --php <php-cli-validado> --receipt-directory <base-aprovada>/receipts --evidence-directory <evidencias-privadas-do-scheduler>
```

Não remover o cron antigo ou adicionar esse cron agora. Inventariar duplicatas,
concluir jobs antigos e aprovar a transição antes de instalar uma única entrada.

## PHP e runtime: sem equivalência presumida

A leitura real na REI-24 identificou pacotes CLI 8.4.22, FPM 8.5.7 e Nginx 1.30.3,
com evidência de pool/listener/processo convergente, não resposta HTTP final.
OPcache habilitado e realpath TTL 86400 aparecem nos INIs lidos; não constituem
estado efetivo nem comprovam ausência de preload. A VPS não foi alterada aqui.

Composer raiz declara ^8.3, mas Symfony 8.1 do lock requer **PHP >=8.4.1**;
constraints de outras dependências também importam (por exemplo Nette limita
versões suportadas). Conferir `composer check-platform-reqs --no-dev` na plataforma
do artefato e as extensões/config efetivas de **cada SAPI**. Nem 8.4.0 nem uma
versão futura presumida devem ser aceitas por simples comparação mínima.
Extensões identificadas no lock incluem ctype, dom, fileinfo, filter, hash, iconv,
json, libxml, mbstring, openssl, pcre, session e tokenizer; PDO/drivers/cache reais
exigem checagem adicional. A ferramenta não simula a resolução do Composer.

O contrato #59 recusa OPcache ativo e preload configurado/carregado. Esta entrega
não o enfraquece. Somente `verify-only` explicitamente aprovado é implementado;
necessidade de reload bloqueia com `service_action_not_implemented`. Não foi
presumido que verify-only seja adequado à VPS. Nginx/FastCGI devem fixar a raiz
física por request; a configuração observada com document_root ainda requer ensaio
adequado. PHP CLI da execução do scheduler precisa ser configurado separadamente.

## Identidade, artefato e bloqueios reais do build

Hashes SHA256 de contratos fixados (revisão exigida para atualização):

- PR #59 `b271260d1e9cf4778bf3304e961db73751a297f1`, prepare-release.py:
  `e2edc3133f20318a442fc08da15344872daaeba05aad763eda4d50a8a9c4d125`.
- PR #57 `a8cc9729181471c9f46ccb15d51383e1aceb61ab`, verify-deployment.py:
  `f84142159b72e813d950a7135d538ced93ceaaae4a3a7cd5ba951192bc31dfdf`.

A pré-validação do verificador é executada antes do switch, sem rede. A verificação
final usa HTTPS, origem/probe/nonce/SHA e múltiplas amostras do #57, sem shell,
redirecionamento aceito ou flag de bypass. HTTP verde sem identidade não é sucesso.
O endpoint comprova a requisição amostrada dentro das suas restrições, não frota,
banco, storage, configuração externa, rotas funcionais ou todos os workers.

O pacote precisa dos mesmos bytes construídos e testados, e do digest transportado
por canal confiável. O tar não prova sozinho que vendor veio do lock; exige
proveniência do builder isolado. `npm ci`, Composer com lock e verificações devem
ocorrer antes da embalagem. O profile conservador não incorpora `.env`/storage,
`public/hot`, links nem outputs executáveis arbitrários. Assets ignorados são uma
lista exata; a completude funcional é gate independente, não inferida do tar.

**Incompatibilidades comprovadas preservadas:**

- `npm run build` na main atual reescreve cinco HTML rastreados de Styles. `package`
  recusa `dirty_source`; não rotula bytes modificados com SHA antigo.
- Law gera `config/fokus-law-assets.json`, ignorado pelo Git. O #59 recusa esse
  arquivo não rastreado em config. A nova lista de extras também o recusa. Exige
  solução revisada de proveniência/idempotência; não ampliar allowlist para verde.
- Retenção de assets N/N-1, orçamento de espaço/inodes/quotas e completude dos
  manifests ainda precisam prova com artefato real. Não apagar releases antigos.

## Preparação e ativação inicial, sem execução produtiva

1. Resolver build determinístico/manifest Law e scheduler sem descendentes; rever
   os contratos afetados em entrega rastreável. Não alterar AUDIT_DIGEST automaticamente.
2. Completar evidência real privada: vínculo deploy→VPS→vhost, platform/extensões
   de CLI/FPM, OPcache/preload, raiz por request, principals/ACL, drivers, backup e
   recuperação, schema N/N-1, orçamento/retention e governança de produção.
3. Aprovar separadamente instalação do layout, permissões mínimas, configuração
   web/runtime e migração de dados compartilhados, com plano de retorno legado.
4. Construir pacote único em builder fixado; testar, guardar locks/toolchain, lista
   de arquivos e digest confiável. Transporte não é implementado nesta primitiva.
5. Descompactar na raiz final nova; vincular shared; preparar caches/manifestos
   sem tocar em serviços reais; selar; validar boot local equivalente e receipt.
6. O primeiro bootstrap ainda exige corte autorizado do vhost e endpoint. `activate`
   exige current existente e **não cria uma identidade falsa para o legado**. Não
   usar o preflight #57 como impedimento a ser contornado; ensaiar sequência completa.
7. Aprovar o merge e seu possível deploy, após bloquear a publicação in-place por
   um mecanismo revisado, nunca desligando checks. Este PR não ativa essa transição.
8. Só então preencher política privada com evidência recente e autorização exata,
   executar switch controlado e verificar endpoint/saúde/rotas/filas/serviços.

## Recuperação e matriz de riscos

| Risco | Controle implementado | Limitação / condição de liberação |
| --- | --- | --- |
| Tar adulterado/traversal | Fotografia única + digest externo + membros exatos | Proveniência do builder e transporte ainda externos |
| Fonte/build divergentes | Exportação SHA e confronto integral; falha fechada | Build real atual bloqueado, não normalizado |
| Código gravável | Write bits removidos na candidata; runtime não root/dono | ACL/grupos/deploy principal precisam observação real |
| Mistura de gerações | Ponteiro único, root canônica, receipt, locks | Web/FPM e workers fora dos locks não são magicamente coordenados |
| Falha antes do switch | Preflight/verificador/integridade/expected previous | Lixo de preparo é preservado, não reutilizado |
| Falha ou morte após switch | Latch durável anterior ao switch e journal | Current pode estar na candidata; serviço não é restaurado automaticamente |
| Scheduler sobre candidata inválida | Latch bloqueia cron e ativação normal | Chamadores antigos fora desse mecanismo devem estar desativados por operação aprovada |
| Retorno incompatível | Mesmo preflight e confirmação N/N-1, sem restore necessário | Banco destrutivo não é reversível por link |
| Evidência velha/alvo errado | Política alvo/receipt/operador/host/SHA com validade | Atestação manual confiável, não certificação automática |
| Secrets | Nenhum conteúdo .env copiado; falhas sanitizadas | Caches locais/receipts/política/journal devem permanecer privados |

Se verificação final falhar, o latch continua presente e o processo retorna não
zero; nenhuma retry automática, rollback automático ou migration ocorre. Inspecionar
current e journal sanitizado, obter estado de dados/processos, só então autorizar
rollback de código com política vinculada ao release anterior. Se banco/storage
precisarem restauração, parar: procedimento separado e autorização específica.
Se o processo morrer antes/depois do rename, a existência do latch basta para
bloquear scheduler, mesmo sem journal final. Volta de código não implica recuperação
integral do serviço. Restore retido e RTO ainda não comprovados.

## Validação e reprodução

```sh
git fetch --no-tags origin b271260d1e9cf4778bf3304e961db73751a297f1 a8cc9729181471c9f46ccb15d51383e1aceb61ab
PYTHONDONTWRITEBYTECODE=1 python3 tools/releases/test_immutable.py -v
```

São fixtures locais com Git, filesystem, locks e subprocessos reais. O preparador
#59 é realmente executado e seu digest conferido. As decisões pós-switch usam
injeção sintética do resultado do verificador para testar sucesso/falha da máquina
de estados: **não são prova de HTTP, PHP, FPM ou produção**. Preflight #57 real é
executado. O scheduler usa executável sintético, não finge execução Laravel.

Evidências detalhadas e composição: [relatório de validação](immutable-release-validation.md).
PHP/Composer não estão disponíveis neste executor; testes Laravel/FPM desta nova
integração não foram executados nem substituídos por mocks. A nova CI roda as
primitivas; os checks existentes conservam suas responsabilidades. Até completar
os gates acima, a entrega é parcial para revisão, não autorização de ativação.

### Principal do scheduler

O controlador e o processo PHP devem ter identidades diferentes. A evidência exige UID/GID não-root do runtime, correspondência ao cadastro local e acesso previamente verificado; o runtime não pode possuir código ou controles. O lançador limpa grupos suplementares e executa PHP com esse UID/GID e umask 077. Sem capacidade autorizada de troca de principal, falha sem fallback. Nenhum privilégio, sudoers ou cron produtivo é configurado por esta entrega. O ensaio sintético não comprova essa troca na VPS.
