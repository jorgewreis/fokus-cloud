# Publicação e recuperação controladas

## Nota de estado: derivação de CI de 2026-11-07

O conteúdo histórico abaixo registra o marco local anterior à integração do CI (2026-11-06/07). As referências a árvore sem Git, CI desabilitado e ausência de execução remota descrevem aquele marco, não o estado de uma revisão posterior.

Esta derivação preserva o histórico Git e prepara validação manual da revisão exata, com auditoria obrigatória e perfis de compatibilidade e do runtime alvo. Somente os resultados associados ao commit final demonstram quais gates foram executados e aprovados. Autorização de trabalho não substitui aprovação técnica dos gates. O deploy continua bloqueado; a estratégia `forward-fix-only` e o smoke restrito a loopback permanecem inalterados.

## Registro histórico do marco local

## Gate do mesmo commit e base explícita

Nesta reconstrução local, `deploy.yml` falha deliberadamente antes de qualquer publicação; `security-homologation.yml` é rascunho com job desabilitado. Não foram executados remotamente. Um resultado verde de outro SHA não serve como homologação. O futuro fluxo deverá exigir testes completos na mesma revisão e falhar diante de ausência, cancelamento ou erro.

A origem aprovada para este ensaio é `26daac7c27d246eaef64bb3f0e59499376993f8f`. Esse é o SHA registrado na revisão, não uma consulta atual ao servidor de produção. O harness Git histórico exige `baseline_sha`, existência e ancestralidade. Ele não foi executado nesta árvore sem Git. O novo `release-gate.py` valida diretórios, manifestos e evidências da base explícita, sem inferir um commit pai. Não atribui SHA Git à candidata. Antes de uma futura publicação, o operador precisa confirmar que a origem real ainda corresponde ao plano. Divergência exige novo plano e ensaio.

O ensaio usa runtime completo da base e banco descartável com fixtures sintéticas, aplica as migrations da candidata, confere os dados e repete a aplicação antes de qualquer teste que limpe o banco. MySQL/Redis e navegador continuam obrigatórios no ambiente de homologação. SQLite e testes DOM são evidências complementares, não substitutos desses gates. Os registros devem conter SHAs, versão dos serviços, comandos e resultados, sem credenciais nem dados reais.

## Estratégia vigente: manutenção, não troca atômica

Antes do rsync de código ativo, Laravel entra em manutenção com resposta pré-renderizada e solicita reinício gracioso das filas. Storage permanece excluído do rsync, preservando a manutenção durante Composer, migrations, caches e reload obrigatório de PHP-FPM. Agendamentos Laravel normais não executam em manutenção. Serviços externos e jobs marcados para executar em manutenção precisam ser suspensos pelo operador. `queue:restart` não comprova que jobs longos terminaram.

Depois das verificações internas, a manutenção é encerrada para smoke HTTPS de `/up`, página de acesso e página do produto. O smoke não usa credenciais ou dados reais e não certifica rotinas autenticadas. Se falhar ou for cancelado após entrar em manutenção, o job tenta reativar a manutenção e reprova. Há pequena janela entre abertura e smoke. Falha de SSH pode impedir o fechamento: verificar externamente e intervir. Falha no reload não é ignorada.

A migration prepublish antiga é aditiva e fixada por checksum; não é garantia genérica para migrations futuras. Releases versionadas com troca atômica continuam uma evolução separada, dependente de aprovação e validação da infraestrutura. Não alterar automaticamente a raiz do site.

## Antes de aprovar uma publicação

1. Confirmar o SHA realmente instalado, a origem do ensaio e o candidato final; nenhum resultado desta cópia local autoriza publicação.
2. Conferir cobertura MySQL/Redis, concorrência, navegador e compatibilidade dos consumidores da API de Audiências preservada. Manter `LAW_TASKS_ENABLED=false` enquanto não houver liberação específica.
3. Revisar a compatibilidade de cada migration e registrar os dados preservados ou descartados. Ter backup cifrado verificado é salvaguarda, não autorização de restauração.
4. Agendar manutenção e verificar efetivamente a suspensão dos escritores, sincronizadores, scheduler externo e jobs longos. Registrar como serão retomados.
5. Guardar evidências do SHA candidato e um plano de correção adiante. Não anunciar rollback de código como mecanismo seguro desta entrega.

## Falha: corrigir e avançar

A decisão aprovada para esta entrega é **correção adiante**, mantendo o esquema expandido e os dados. O código antigo `26daac7` não é artefato de recuperação homologado: o ensaio sintético comprovou redução do sigilo efetivo ao sincronizar fonte pública sobre restrição interna alta. A revisão também identificou caminho antigo de reconcessão automática de acesso revogado. Não reativar esse runtime sobre o banco migrado.

1. Manter a aplicação fechada e confirmar a suspensão dos escritores. Se a atualização foi parcial, não executar automaticamente o código antigo nem retomar as filas.
2. Registrar SHA/arquivos efetivamente instalados, ledger e esquema real das migrations, etapa que falhou e logs sanitizados. Divergência entre ledger e esquema exige diagnóstico; não apagar entradas do ledger para forçar sucesso.
3. Preparar uma nova candidata corrigida no ambiente isolado, preservar o esquema expandido e os dados e reproduzir o estado da falha com dados sintéticos. Uma nova correção fora deste pacote precisa de aprovação específica.
4. Homologar essa candidata, incluindo fonte pública sobre sigilo alto, níveis legados/desconhecidos, revogação preservada, ausência de concessões automáticas, isolamento e histórico. Verificar o retorno dos sincronizadores sem redução de proteção.
5. Somente após autorização de publicação, instalar a correção adiante, validar esquema/dados, dependências e caches, recarregar PHP-FPM e executar as verificações internas. Reabrir e retomar escritores de forma supervisionada, registrando o smoke e o SHA final.

Não executar `migrate:rollback` como recuperação de produção. Não preparar ou instalar um hotfix do código antigo, nem restaurar backup, dentro desta aprovação. Se não houver correção adiante comprovada, permanecer em manutenção e solicitar decisão; isso pode prolongar indisponibilidade, mas evita reabrir com proteção inferior.

## Reversão em banco descartável

O `down` é testado apenas para diagnóstico e ensaio controlado. A migration de triagem retira o índice antes de sua coluna e valida o estado estrutural antes de remover a tabela de revisões. Testes de ida, volta, interrupção e repetição conferem também o ledger. Não há promessa de atomicidade entre as quatro migrations ou de DDL transacional em SQLite/MySQL.

A reversão das quatro migrations apaga deliberadamente triagens e providências, tarefas e seus eventos, lotes de importação, permissões introduzidas e campos de proveniência/projeção. Ela não recupera a segurança do runtime antigo e não é uma operação sem perda de dados. Contatos cifrados e os campos preexistentes devem permanecer íntegros nos ensaios. Estados parciais conhecidos só podem ser retomados após conferência; estado desconhecido exige intervenção.

Esta cópia não executou publicação, mudança de infraestrutura nem recuperação em produção. Não afirmar recuperação automática, ausência de downtime ou homologação de etapas bloqueadas pelo ambiente.

## Reconstrução local, não recuperação integral

A base é 26daac7c27d246eaef64bb3f0e59499376993f8f. O candidato histórico aa81c3c foi perdido. Dezoito arquivos foram recuperados byte a byte; o restante é reconstrução com identidade própria. Não declarar equivalência ao candidato histórico nem repetição dos 318 testes antigos. O baseline local foi informado como 253 testes / 2140 asserções; resultados novos precisam de logs próprios.

O verificador local exige exatamente quatro novas migrations, evidência de cada grupo de esquema, integridade dos campos antigos e ciphertext, idempotência, recuperação adiante, falha controlada, concorrência, navegador, Redis e build. Consistência dos logs não substitui revisão independente de suas assertivas. Sua saída nunca autoriza publicação.

Restauração de backup é alternativa futura somente com autorização específica, restauração isolada validada de banco/storage/APP_KEY, reconciliação de escritas posteriores e segurança comportamental comprovada. Restaurar somente código antigo sobre esquema novo não é recuperação segura. Esta preparação não executa nem certifica restauração.
