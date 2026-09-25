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

O alias está configurado no Cloudflare: o registro A `law` aponta para a mesma
origem de `www` com proxy ativo. A regra de redirecionamento
`Fokus Law - entrada pelo subdomínio` encaminha
`https://law.fokuscloud.com.br/*` com status 302 para
`https://www.fokuscloud.com.br/produtos/fokus-law`, preservando a query string.
Assim, o redirecionamento ocorre na borda antes de acessar o CloudPanel.

O arquivo `deploy/cloudpanel-legacy-redirects.conf` mantém uma regra de alias
para eventual roteamento direto à origem, que exige vhost HTTPS e certificado
válido para `law.fokuscloud.com.br`. O deploy Laravel não altera o DNS nem as
regras do Cloudflare; conferir ambos separadamente após mudanças de entrada.
