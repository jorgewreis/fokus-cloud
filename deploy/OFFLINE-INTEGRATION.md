# Integracao offline do plano de deploy

## Identidade e alcance

Marco posterior ao pacote v1, preservado separadamente. Base candidata:
`7b9d608de5f09dc91f83b562247fc203d76ebcc8`. Main de referencia:
`c92ef4e5ad99966a367054fe776988b9383ec68d`. Fixture consultada via GitHub oficial:
`.github/workflows/deploy.yml`, Git blob
`fb5ab45ab05f61d79e5dec2c1e4360518806a5bf`.
A fixture fica em `tests/Deployment/fixtures/main-c92ef4e-deploy.yml`, fora de
`.github/workflows`, e seu hash Git e conferido pelo teste.

O novo `deploy/deploy_candidate.py` nao possui CLI, backend padrao, subprocesso
nem cliente de rede. Seu backend e obrigatoriamente injetado. Nesta etapa o unico
backend existente e o duplo em `test_deploy_candidate.py`; nenhum comando SSH,
SCP, rsync, sudo, PHP, Composer, curl ou Docker do plano e executado.
Os comandos sao dados imutaveis em `Operation`, com argv, referencia de stdin,
referencias de segredos, timeout solicitado e classificacao de mutacao.
Backend futuro devera respeitar esses contratos; nao esta implementado aqui.

## Ordem concreta

1. Guard de unidade e politica sudo, sem reload.
2. Checksum local da migration aditiva revisada; mkdir remoto temporario com
   permissao700, copia SCP e prepublish potencialmente mutante, com limpeza
   dos arquivos temporarios somente apos sucesso.
3. Rsync de assets imutaveis e verificacao de checksums.
4. Criacao do diretorio de destino, rsync de codigo com protect.rules e todas
   exclusoes do main, seguido do diagnostico runtime.
5. Preparacao remota do Composer, execucao assincrona e polling de status/log.
6. Migrations, configuracao Datajud por stdin, optimize e tags de idade.
7. Diagnostico Datajud, probe, instalacao e verificacao do scheduler.
8. Rechecagem da unidade, unico reload php8.5-fpm.service e verificacao posterior.
9. Purge Cloudflare somente quando a zona foi fornecida explicitamente.

O primeiro efeito remoto mutante e schema.prepare, nao rsync. Todos os tres
comandos de preflight antecedem esse efeito. O plano parte de assets ja gerados
e transporte previamente configurado: nao implementa setup SSH/credenciais nem
validacao/build anteriores do main. Nao e um workflow de deploy completo.

Nao foram acrescentados maintenance, reopen, smoke ou rollback: nao constam
dessas etapas do main. Falha depois de uma mutacao nao a desfaz. Recuperacao,
retomada segura e indisponibilidade continuam assuntos pendentes. Nunca executar
este plano em producao com base na evidencia de dublês.

## Desvios conservadores explicitos

- Ativacao corrigida para php8.5-fpm.service, sem restart nem fallback de sucesso.
- Comandos SSH/SCP usam BatchMode e StrictHostKeyChecking; erros nao sao ocultos.
- Log/status Composer passam a incluir run_id/attempt, evitando reutilizacao do
  nome global do main. Status ausente retorna PENDING; vazio/malformado ou
  codigo nao-zero e fatal. No maximo45 consultas, com pausas de2s entre elas.
  Isso e limite de tentativas, nao garantia de90s de parede incluindo transporte.
- Falha do probe Datajud agora reprova o plano offline; main emitia warning.
  Este endurecimento exige revisao antes de futura ligacao produtiva.
- Scheduler e verificado pelo stdout de schedule:list, apos exigir sucesso do
  comando, em vez de depender do status de uma pipeline sem pipefail.
- Caminhos remotos exigem sintaxe conservadora e nao aceitam root/traversal;
  caminhos locais absolutos preservam espacos em argumentos separados.

Referencia stdin `reviewed-migration-checksum:<hash>:<path>` significa uma
linha sha256sum com o hash fixado e o caminho literal da migration. O backend
futuro devera materializar isso, assim como files.txt/protect.rules/checksums
de assets; os testes nao verificam arquivos de assets reais.
Datajud e Cloudflare usam somente referencias opacas de entrada, nunca valores
reais. O placeholder de token no argv de purge nao e um token e nao deve ser
enviado literalmente por um backend futuro. Ausencia de zona omite purge.
Saida de backend e validada, mas nao e impressa nem incluida em erros.

## Gate e cobertura

`tests/Deployment/linux-release.py` inclui incondicionalmente deploy-guard e
deploy-integration na lista gates. `linux-release-suite.py` nao foi alterado.
O teste extrai via AST o loop real, executa-o com run/docker falsos, injeta falha
de cada novo gate e executa o dicionario de agregacao e a condicao final reais.
Confirma passed=false, codigo nao-zero preservado e RuntimeError nos perfis
backend_only verdadeiro/falso. Nao executa Docker nem o restante do harness.

O teste de integracao cobre27 operacoes: sucesso, falha e timeout em cada uma,
zero mutacoes quando preflight falha, nenhum passo posterior ao reload falho,
Composer pendente/invalido/nao-zero/esgotado, falha da pausa, resultado backend
invalido, scheduler ausente, destinos/caminhos e filtros rsync. O teste guard
existente continua cobrindo variantes de sudo e unidade.

Comandos locais: `python -B tests/Deployment/test_fpm_guard.py`,
`python -B tests/Deployment/test_deploy_candidate.py`,
`node --test tools/styles-showcase/deployment.test.mjs`.
O workflow bloqueado e o contrato Styles permanecem byte a byte iguais ao v1.
Nenhuma destas evidencias prova sudo/systemd real, Nginx, OPcache ou publicacao.
