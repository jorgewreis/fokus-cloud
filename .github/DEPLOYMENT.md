# Deploy de produção

O workflow `deploy.yml` valida em push na `main` e em pull request. A publicação
só ocorre por `workflow_dispatch` na `main`, com `candidate_sha` homologado no
mesmo SHA, environment `production` e o gate `deploy/publication_gate.py`.

A publicação sela um diretório novo e troca o symlink `current`. Não há
`rsync` por cima da árvore que está servindo. Se `/_deployment/release` não
confirmar o SHA candidato, o symlink volta ao alvo anterior. Migrações de banco
não são desfeitas por esse rollback.

`DEPLOY_PATH` é a base abaixo, não o docroot:

```text
DEPLOY_PATH/current -> releases/<sha>   ou, antes da primeira publicação, o legado
DEPLOY_PATH/releases/<sha>/             código selado, imutável
DEPLOY_PATH/shared/storage/             storage mutável, com app/public
DEPLOY_PATH/shared/.env                 configuração, arquivo real
```

O docroot do Nginx é `DEPLOY_PATH/current/public`, com
`fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;`, para o
PHP enxergar a raiz física `releases/<sha>`. OPcache e preload do pool ficam
desligados: o endpoint responde 503 se qualquer um estiver ativo.

`FPM_RELOAD_MODE=reload` exige `FPM_SERVICE` com a unidade real e uma única
regra sudo, por exemplo para `php8.5-fpm.service`:

```sudoers
deployuser ALL=(root) NOPASSWD: /usr/bin/systemctl reload -- php8.5-fpm.service
```

O comando inclui `--`. Não há restart. `verify-only` deixa `FPM_SERVICE` vazio,
não chama sudo e não recicla workers; só é viável com OPcache e preload já
desligados. Um proxy ainda pode responder velho: o verificador recusa resposta
cacheada, mas só amostra três requests. Cada uma dessas consultas, e o
`verify` depois da troca, repete no máximo três vezes só erro de transporte
ou TLS, com 1s de intervalo e outro nonce no release. O prazo total continua
60s e cada tentativa espera no máximo 5s. Status HTTP, contrato, redirect e
SHA errado não são repetidos. O purge da Cloudflare também repete só falha
de transporte do `curl` (até três vezes, 20s por tentativa); resposta HTTP
de erro da API continua fatal.

A primeira publicação usa o input `bootstrap_first_sealed_release`. Sem ele, o
404 HTML do release atual bloqueia o deploy. O log precisa conter
`bootstrap_first_sealed_release=accepted reason=unsealed_release_http_404`.
A página HTML grande do legado é aceita nesse probe pelo status e pelo tipo,
sem exigir que o corpo caiba no limite. O `verify` final não aceita 404.
Nas seguintes, o input fica desligado. Um SHA que falhou deixa
`releases/<sha>` no disco; o publicador não o reaproveita.

Configure os secrets no ambiente GitHub `production`:

- `DEPLOY_HOST`: host SSH, sem protocolo.
- `DEPLOY_PORT`: porta SSH.
- `DEPLOY_USER`: usuário SSH com acesso à base publicada. Precisa de `python3`.
- `DEPLOY_PATH`: caminho absoluto da base (`releases`, `current`, `shared`).
- `DEPLOY_SSH_KEY`: chave privada do usuário de deploy, em texto puro.
- `DEPLOY_KNOWN_HOSTS`: chave pública do servidor no formato `known_hosts`.

Para obter `DEPLOY_KNOWN_HOSTS`, valide a impressão digital do servidor por um
canal confiável e então use, por exemplo, `ssh-keyscan -p PORTA -H HOST`.

Os secrets abaixo são opcionais e habilitam a limpeza do cache após o deploy:

- `CLOUDFLARE_API_TOKEN`: token com permissão de limpar o cache da zona.
- `CLOUDFLARE_ZONE_ID`: identificador da zona Cloudflare.

Nunca versione chaves privadas ou valores reais de secrets em `.env`.

O runner instala as dependências, gera os assets do Law e sela o release com a
raiz remota `DEPLOY_PATH/releases/<sha>`. No servidor, o publicador liga
`storage` e `.env` compartilhados, roda `php artisan migrate --force` na cópia
ainda inativa e só então troca `current`. Não roda `optimize` nem o instalador
antigo de cron: o cron precisa entrar em `DEPLOY_PATH/current` a cada execução,
não num SHA físico. O schema aditivo do Law continua sendo conferido em
`current` antes da transferência.

## Assets do shell autenticado do Fokus Law

O shell autenticado usa a allowlist explicita de 14 arquivos em
`tools/generate-fokus-law-assets.mjs`. `npm run law:assets:prepare` gera um
manifesto deterministico em `config/fokus-law-assets.json` e copias irmas com
SHA-256 completo no nome, mantendo os arquivos originais e o mesmo diretorio
para preservar caminhos relativos de CSS. O manifesto e as copias geradas sao
artefatos locais ignorados pelo Git; `npm run dev` e `npm run build` os geram.

No deploy, a mesma geração entra no release novo antes do selo. O manifesto é
incluído na identidade e as cópias com SHA no nome vão para `public/`. O
release anterior permanece em `releases/` e continua servindo as cópias que as
abas abertas já pediram. O publicador não apaga esses diretórios. Uma coleta
futura precisa de janela de retenção revisada e não deve remover os arquivos
originais nem o release que `current` aponta.

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
