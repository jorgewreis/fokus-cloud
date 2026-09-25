# Deploy de produção

O workflow `deploy.yml` publica a aplicacao Laravel quando há um push na
branch `main` ou quando e executado manualmente pela aba **Actions**.

Configure os secrets no ambiente GitHub `production`:

- `DEPLOY_HOST`: host SSH, sem protocolo.
- `DEPLOY_PORT`: porta SSH.
- `DEPLOY_USER`: usuário SSH com acesso ao diretório publicado.
- `DEPLOY_PATH`: caminho absoluto do diretório público no servidor.
- `DEPLOY_SSH_KEY`: chave privada do usuário de deploy, em texto puro.
- `DEPLOY_KNOWN_HOSTS`: chave pública do servidor no formato `known_hosts`.

Para obter `DEPLOY_KNOWN_HOSTS`, valide a impressão digital do servidor por um
canal confiável e então use, por exemplo, `ssh-keyscan -p PORTA -H HOST`.

Os secrets abaixo são opcionais e habilitam a limpeza do cache após o deploy:

- `CLOUDFLARE_API_TOKEN`: token com permissão de limpar o cache da zona.
- `CLOUDFLARE_ZONE_ID`: identificador da zona Cloudflare.

Nunca versione chaves privadas ou valores reais de secrets em `.env`.

O deploy limpa caches gerados em `bootstrap/cache`, executa
`composer install`, `php artisan migrate --force` e `php artisan optimize` no
servidor. O diretorio `resources/views` deve existir mesmo quando a aplicacao
servir HTML estatico por `public/`, pois o cache de views do Laravel valida
esse caminho durante a otimizacao.

## Alias de acesso do Fokus Law

`https://law.fokuscloud.com.br` é um endereço de entrada para o mesmo app
publicado em `www.fokuscloud.com.br`; ele encaminha o usuário para
`/produtos/fokus-law`, onde o login abre o shell autenticado em
`/portal/fokus-law`. A sessão permanece no domínio canônico `www`.

Para ativar o alias em produção, a equipe de infraestrutura precisa:

1. Criar no DNS um registro `law` apontando para o mesmo destino público de
   `www` (A/AAAA para a origem ou CNAME proxied para `www`, conforme a zona).
2. Adicionar `law.fokuscloud.com.br` ao vhost HTTPS do CloudPanel e emitir um
   certificado TLS válido para esse hostname.
3. Aplicar a regra do alias no início do bloco HTTPS em
   `deploy/cloudpanel-legacy-redirects.conf`, executar `nginx -t` e recarregar
   o NGINX.
4. Confirmar que `https://law.fokuscloud.com.br/` responde com redirecionamento
   para `https://www.fokuscloud.com.br/produtos/fokus-law`.

O deploy Laravel e o purge Cloudflare descritos acima não criam registros DNS,
aliases de vhost nem certificados; esses passos são necessários antes que o
subdomínio possa responder.
