# Scaffold isolado de ativacao PHP-FPM

Nota de evolução: este documento registra o marco offline preservado. A ligação
posterior ao workflow, seu escopo e limites estão em
[FPM-WORKFLOW-INTEGRATION.md](FPM-WORKFLOW-INTEGRATION.md).

## Marco offline seguinte

`deploy_candidate.py` integra o guard a um plano de operacoes concreto derivado
do main c92ef4e. Consulte `OFFLINE-INTEGRATION.md`. Essa integracao nao liga o
workflow produtivo, nao inclui backend real e nao implementa manutencao/reabertura.
O texto abaixo descreve a interface inicial de callbacks e continua registrando
seus limites; ela nao deve ser confundida com o comportamento do main.

Base candidata: `7b9d608de5f09dc91f83b562247fc203d76ebcc8`.
Main observado pelo coordenador: `c92ef4e5ad99966a367054fe776988b9383ec68d`.
Nao houve merge, commit, publicacao, SSH real ou mudanca no servidor.

`fpm_guard.py` e um modulo Python 3 para o runner, sem CLI, destino padrao,
credenciais ou callbacks reais. `.github/workflows/deploy.yml` permanece
inalterado e deliberadamente bloqueado. O main e o VPS NAO receberam a correcao.

## Contrato e futura ligacao (nao implementada)

O futuro workflow devera invocar `run_candidate` antes de QUALQUER transferencia
ou mutacao remota, inclusive preparacao de diretorio, backup e manutencao.
Os callbacks `transfer`, `mutate`, `verify_application` e `reopen` precisam
levantar excecao para qualquer falha; retornar False ou um codigo numerico nao
e sinal de falha neste contrato. Nenhum callback real esta incluido.
Esta interface demonstra ordem e propagacao; nao substitui nem porta todo o
workflow main, nao define a estrategia de manutencao e nao homologa deploy.

O preflight exige Id exatamente php8.5-fpm.service, LoadState loaded,
ActiveState active e CanReload yes. Nao executa reload. Consulta sudo -n -ll
sem comando para verificar uma unica regra inequivoca, RunAsUsers root,
!authenticate e o comando literal /usr/bin/systemctl reload php8.5-fpm.service.
Depois consulta sudo -n -l -- com esse comando exato. Nenhuma politica sudo e
criada ou alterada. Politicas legitimas mais amplas, multiplas entradas,
formatos diferentes ou ambiguos sao rejeitados conservadoramente. O parser
foi exercitado com fixtures, nao com sudo Linux real neste computador.

Depois dos callbacks de transferencia e mutacao, verifica novamente a unidade,
executa SOMENTE sudo -n -- /usr/bin/systemctl reload php8.5-fpm.service e
confere novamente suas propriedades. Qualquer erro SSH, sudo, systemctl,
timeout ou excecao dos callbacks interrompe a sequencia. Nao ha restart,
echo de sucesso alternativo nem reabertura depois de falha. Isso nao desfaz
mutacoes anteriores. Falhas depois da transferencia exigem recuperacao
supervisionada; nenhum rollback automatico foi acrescentado.

## Limites e riscos

- O inventario manual do coordenador associa os dois fastcgi_pass observados
  a 127.0.0.1:20004, listener PHP8.5 e unidade php8.5-fpm.service. Nao comprova
  configuracao Nginx carregada nem associacao temporal atomica.
- Unidade ativa depois do reload NAO comprova OPcache renovado, requests
  servidos pela candidata ou esvaziamento dos workers antigos.
- O servico pode atender outros sites/pools; uma futura recarga pode afeta-los.
- A politica sudo pode mudar entre consulta e uso. Por isso a falha de uso
  tambem e fatal. Paths, versao e formato reais requerem validacao autorizada.
- PHP8.4 e PHP8.5.7, MySQL/Redis, migrations, navegador, suite Laravel e
  integracao produtiva nao foram revalidados por este scaffold.
- Git Bash falhou com NtCreateDirectoryObject 0xC0000022; Docker recusou acesso
  ao pipe. Nao houve tentativa por outra rota, instalacao ou mudanca de acesso.
- PHP nativo disponivel: 8.5.2. Nao e evidencia para PHP8.5.7 ou PHP8.4.

## Testes locais sem rede

`python -B tests/Deployment/test_fpm_guard.py` executa um subprocesso Python
como duplo de SSH, com dublês de sudo e systemctl e estado sintetico temporario.
Nao inicia shell, SSH, sudo, systemctl ou container reais. Os temporarios sao
removidos por TemporaryDirectory. Cobre ordem de sucesso, falhas de SSH,
unidade ausente/inativa/divergente/sem reload, senha/recusa/ambiguidade sudo,
recusa do reload, estado e consulta posteriores falhos, timeout e callbacks.

`node --test tools/styles-showcase/deployment.test.mjs` verifica tambem que o
workflow bloqueado permanece identico ao contrato. O ensaio rsync e pulado
intencionalmente porque nao existe comando de publicacao aprovado.

Nao usar este resultado como autorizacao ou prontidao para deploy.
