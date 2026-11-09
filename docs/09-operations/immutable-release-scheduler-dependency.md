# Dependência: supervisão do scheduler por geração

Estado: proposta específica, não implementada nem validada na VPS. Relacionada à REI-24 e ao PR #61, head 16efd7d5c7ca41b15641c4c4af58ff1fedfa1eae. Nenhuma alteração de aplicação, cron ou serviços executada.

## Evidências do código

- `routes/console.php:9-10`: worker `queue:work law-datajud --queue=law-datajud --stop-when-empty --max-time=50 --timeout=70 --tries=1`, everyMinute, withoutOverlapping(5), runInBackground.
- `config/queue.php`: conexão database law-datajud com retry_after=120, after_commit=false. `app/Jobs/SyncLawCaseDatajud.php`: timeout=70, tries=1, failOnTimeout=true. A execução usa dados de clientes; não foi executada nesta inspeção.
- Outras tarefas do scheduler são síncronas. Remover runInBackground altera a concorrência e pode atrasar tarefas posteriores. Não é correção aceitável apenas para satisfazer o bloqueio estático.
- `tools/releases/immutable.py`: scheduler mantém lock enquanto espera somente o processo schedule:run; o subprocesso inicia nova sessão. O teste textual runInBackground bloqueia o caso conhecido, mas não é prova geral de ausência de descendentes (comandos podem criar outros processos).
- Ensaio real sintético Python/fork: pai encerrou com exit 0 antes de 0,5s; filho ainda não havia terminado e escreveu seu marcador depois. Demonstra que o sucesso do processo principal não comprova drenagem. Não é ensaio Laravel/FPM ou systemd.

## Menor migração recomendada

Manter runInBackground e os contratos funcionais/queue existentes. Adicionar, em entrega separada, um supervisor de geração que execute o ciclo inteiro em grupo de processos controlado pelo sistema operacional. Recomenda-se avaliar cgroup v2 por unidade transitória do systemd já existente, sem novo serviço externo. Uso de PID/PPID ou process group isoladamente não comprova drenagem após double-fork, reparenting ou setsid. Não configurar unidade, delegação, privilégios ou cron nesta entrega.

1. Resolver current uma única vez sob lock global e vincular SHA, raiz física, receipt, boot ID e identificador de execução ao registro durável.
2. Antes do disparo, persistir estado in_flight e criar a unidade/cgroup com identidade runtime não proprietária dos controles. Impedir migração/escape dos filhos para outro cgroup. A disponibilidade e autorização desses mecanismos precisam de prova real.
3. Manter lock de geração até o cgroup ficar comprovadamente vazio, além do resultado principal. `Type=exec`/exit do main PID não bastam. Avaliar `ExitType=cgroup` somente se suportado pela versão instalada e testar sua interação com erro do processo principal.
4. As tarefas background devem produzir resultado individual por mecanismo Laravel apropriado, preservando schedule:finish/mutex. Drenagem do cgroup demonstra término, não sucesso: um filho pode falhar sem propagar status ao pai. Correlacionar resultado explícito de cada evento background à execução/SHA e tratar resultado ausente como desconhecido/falha. Não guardar argumentos contendo segredos ou dados reais.
5. O controlador não pode iniciar novo ciclo enquanto execução anterior estiver ativa. A entrada única cron futura usa o wrapper; desativar cron legado é mudança produtiva separada. Manter mutex Laravel em cache compartilhado, com chaves estáveis entre releases. `withoutOverlapping` não dá garantia exatamente-uma-vez após crash/retry; integridade/idempotência dos jobs é responsabilidade adicional.
6. Ativação toma lock e confirma ausência de cgroups ativos de qualquer geração, ausência de execução in_flight/resultado pendente e ausência de workers/cron legados fora do supervisor. A evidência booleana expirada ou simples varredura momentânea de /proc não substitui esse controle. Bloquear se inventário incompleto.
7. Timeout/falha/interrupção do controlador mantém latch durável e bloqueia ativação/novo ciclo. Não tratar lock liberado por morte do pai como sucesso. Não matar automaticamente jobs ou forçar unlock. Recuperação consulta grupo, filhos e resultados; somente depois de drenagem comprovada e revisão do estado de fila/mutex autoriza retomada explícita. Retorno de código não restaura transações interrompidas.

## Testes de aceite da dependência

- Duas gerações e duas tentativas concorrentes: somente uma execução aceita; filhos usam caminho físico do mesmo SHA.
- Background normal: main termina primeiro, ativação continua bloqueada até filho terminar e resultado individual confirmado.
- Filho falha, timeout, double-fork/setsid, controlador morto: sem falso sucesso; latch preservado; novo ciclo/ativação recusados.
- Reboot/PID reutilizado, journal incompleto, grupo ausente porém resultado ausente: estado desconhecido bloqueado.
- Cron legado/worker externo encontrado: bloqueio explícito.
- Sem capacidade de cgroup/UID ou acesso de leitura necessário: fail-closed.
- PHP/Laravel e backend de fila/cache reais isolados: contratos withoutOverlapping, schedule:finish, retry_after/timeout, falha do job e ausência de duplicação no cenário controlado. Não alegar exatamente-uma-vez em geral.
- Retomada após falha só com evidência completa, sem limpar mutex por conveniência.

## Limitação e decisão

A arquitetura proposta mantém comportamento background, mas requer código de integração Laravel para resultados dos eventos e mecanismo de supervisão verificado. Não cabe liberar o PR #61 removendo seu guard antes disso. Formalizar como dependência separada e manter a ativação recusada. O ambiente deste ensaio não comprova suporte/privilegios systemd/cgroup da VPS; nenhuma alteração de permissões ou serviço foi tentada.

Registro: [REI-26](https://linear.app/reis-developer/issue/REI-26/supervisionar-scheduler-e-descendentes-por-geracao), dependência da REI-24.
