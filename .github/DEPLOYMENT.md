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

## Assets do shell autenticado do Fokus Law

O shell autenticado usa a allowlist explicita de 14 arquivos em
`tools/generate-fokus-law-assets.mjs`. `npm run law:assets:prepare` gera um
manifesto deterministico em `config/fokus-law-assets.json` e copias irmas com
SHA-256 completo no nome, mantendo os arquivos originais e o mesmo diretorio
para preservar caminhos relativos de CSS. O manifesto e as copias geradas sao
artefatos locais ignorados pelo Git; `npm run dev` e `npm run build` os geram.

No deploy, a mesma geracao cria um payload temporario. O workflow transfere
somente as copias imutaveis antes de sincronizar a aplicacao e verifica seus
SHA-256 no destino. Somente depois disso o `rsync` principal envia o manifesto
e o Blade que os referencia. O filtro receiver-side protege apenas nomes das
14 fontes permitidas com um segmento hexadecimal de 64 caracteres; ele nao
exclui os originais nem outros arquivos do envio. Quando o deploy usa esse
filtro receiver-side, as versoes antigas permanecem no destino para abas
abertas e rollback. Um workflow de deploy sem o filtro pode removê-las durante
o `--delete-delay`; manter o mecanismo de retenção nos fluxos de deploy é
necessário para preservar essa garantia. Nao ha limpeza automatica dessas
copias: qualquer coleta futura precisa de uma janela de retencao revisada e
nao deve remover os arquivos originais.

As respostas autenticadas servidas pelo shell Fokus Law incluem
`Cache-Control: private, no-store` para impedir que a pagina HTML seja
armazenada em cache compartilhado.

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
