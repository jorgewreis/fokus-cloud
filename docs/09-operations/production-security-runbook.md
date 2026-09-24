# Operação de segurança em produção

## Responsáveis e segredos

Nenhum valor deve aparecer neste documento, em commits, logs de Actions ou tickets. O responsável registra no cofre a data, a versão, os consumidores e o próximo prazo de cada segredo.

| Segredo | Responsável | Consumidores | Rotação |
| --- | --- | --- | --- |
| `APP_KEY` | Infraestrutura e backend | Cookies, sessões e dados cifrados pelo Laravel | A cada 12 meses ou imediatamente após suspeita de exposição. Usar `APP_PREVIOUS_KEYS` durante a transição. |
| `MERCADO_PAGO_ACCESS_TOKEN` | Financeiro e backend | Cliente HTTP do gateway | A cada 90 dias ou após incidente; validar checkout e conciliação em homologação antes da troca. |
| `MERCADO_PAGO_WEBHOOK_SECRET` | Financeiro e backend | Assinatura de webhooks | A cada 90 dias ou após incidente. O segredo anterior pode ficar temporariamente em `MERCADO_PAGO_WEBHOOK_PREVIOUS_SECRETS`. |
| `FOKUS_USAGE_INGESTION_SECRET` | Backend e responsáveis pelas integrações | Envio de snapshots de uso | A cada 90 dias ou após incidente. Sobreposição temporária via `FOKUS_USAGE_INGESTION_PREVIOUS_SECRETS`. |
| Credenciais de e-mail e AWS | Infraestrutura | Envio de MFA, confirmação e recuperação | A cada 90 dias ou conforme o provedor; validar mensagens antes de revogar a credencial antiga. |
| SSH de deploy e token Cloudflare | Infraestrutura | GitHub Actions | A cada 90 dias ou após incidente; instalar a credencial nova, executar deploy de prova e revogar a antiga. |

### Procedimento de rotação

1. Criar a credencial nova no provedor/cofre, com acesso mínimo necessário e sem imprimi-la em terminal compartilhado ou Actions.
2. Aplicar a credencial nova em homologação e verificar login, MFA, checkout, webhook, snapshots de uso e e-mail conforme o segredo afetado.
3. Para HMAC de webhook ou integração de uso, registrar o valor antigo no campo `*_PREVIOUS_SECRETS`, implantar o valor novo como principal e então alterar o emissor. Manter a sobreposição somente pelo período de retries previsto; remover o valor antigo e provar que ele passa a ser rejeitado.
4. Para `APP_KEY`, incluir a chave antiga em `APP_PREVIOUS_KEYS`, instalar a nova `APP_KEY`, recriar o cache de configuração e verificar leitura de dados cifrados e sessões. Remover chaves antigas somente após confirmar que os dados persistidos foram recifrados ou não dependem delas. [Laravel documenta a rotação com `APP_PREVIOUS_KEYS`](https://laravel.com/framework/docs/13.x/encryption#gracefully-rotating-encryption-keys).
5. Registrar data, responsável, testes, impacto nas sessões, rollback e momento de revogação no cofre ou registro operacional protegido. Nunca copiar o valor para o registro.

## Sessões e suporte

Em produção, o cookie de sessão usa o prefixo `__Host-`, é limitado ao host, exige HTTPS e usa o driver `database` para revogação. A mudança do nome do cookie encerra sessões antigas; usuários e administradores devem autenticar novamente. O acesso de suporte termina após 30 minutos absolutos, na próxima requisição ou pelo comando agendado `fokus:expire-support-sessions`, além da saída manual.

Após qualquer alteração de sessão, conferir em homologação o login dos dois guards, CSRF, logout, troca de senha, revogação de outra sessão e expiração do suporte. Em produção, conferir apenas os atributos dos cookies e respostas não mutáveis sem expor seus valores.

## Backup e restauração

**Meta inicial:** ponto de recuperação de até 24 horas (RPO) e retorno em até 4 horas (RTO). O responsável por infraestrutura mantém backup diário do banco, `storage/app` e material necessário para reconstruir `.env`/configuração NGINX em cofre separado do servidor. Os arquivos devem ser cifrados, ter acesso restrito, retenção mínima de 30 dias e cópia fora do host de produção.

Antes de clientes reais e depois a cada trimestre:

1. Selecionar um backup recente e registrar identificador, horário e tamanho sem abrir dados pessoais no log.
2. Restaurar o banco em uma instância ou banco **isolado**, com credenciais próprias e saída de rede limitada. Restaurar arquivos necessários sem substituir produção.
3. Verificar conclusão sem erros, versão do esquema, contagem agregada das tabelas críticas (`users`, `companies`, `subscriptions`, `payments`, `platform_audit_events`) e integridade referencial. Não registrar linhas individuais ou documentos nos resultados.
4. Subir a aplicação isolada com segredos de teste e executar fluxos sintéticos de login e consulta; bloquear o envio real de e-mail e chamadas reais ao gateway.
5. Registrar tempo de backup, idade do ponto restaurado, duração da restauração, resultado, responsável e descarte seguro do ambiente isolado. Se RPO/RTO não forem atendidos, corrigir a rotina antes de liberar clientes.

O workflow `production-backup.yml` executa diariamente às 03:17 UTC e sob demanda. Ele usa `FOKUS_BACKUP_PASSPHRASE` do ambiente `production` para cifrar o dump consistente do MySQL e `storage/app` antes de armazená-los como artefato do GitHub Actions por 30 dias. O valor precisa ser gerado com alta entropia e guardado no cofre da equipe: o GitHub não permite recuperar seu valor depois. O próprio job decifra o artefato temporário em um MySQL isolado no runner, confere as tabelas críticas e extrai o armazenamento. Os artefatos enviados são apenas os arquivos cifrados e um manifesto SHA-256. Conferir o resultado diário; falhas de execução ou ausência de artefato são incidentes de backup.

Para uma restauração operacional, baixar um artefato de execução bem-sucedida, verificar `sha256sum --check manifest.sha256` e decifrá-lo com a senha do cofre em um host isolado. Depois, importar o dump em um banco vazio, extrair `storage-app.tar.gz`, seguir as verificações acima e medir o tempo completo. Nunca apontar esse procedimento ao banco de produção sem um plano de recuperação aprovado. O exercício automático prova que o backup recém-gerado é legível; o exercício trimestral com um artefato retido prova também a retenção e a recuperação operacional.

O workflow `production-security-inspect.yml` lê a configuração efetiva e os recursos disponíveis sem exibir valores de segredos.

## Headers e roteamento

O arquivo `deploy/cloudpanel-legacy-redirects.conf` contém as regras NGINX do vhost HTTPS para headers de arquivos estáticos e passagem das rotas `/backoffice/` ao Laravel. Após aplicar a configuração ativa, executar `nginx -t`, recarregar NGINX e verificar GET anônimo em `/backoffice/` (redirecionamento) e `/api/backoffice/auth/me` (401). Conferir HSTS, CSP, `nosniff`, anti-frame e referrer em página, API, erro, redirecionamento e arquivo estático. O middleware Laravel cobre respostas geradas pela aplicação.

A CSP atual restringe origens, objetos, base, formulários e enquadramento, mas ainda permite scripts inline porque as páginas existentes os utilizam. Migrar esses scripts para arquivos externos ou hashes/nonces antes de remover `unsafe-inline`; validar a política no navegador em todas as páginas públicas, portal e Backoffice.
