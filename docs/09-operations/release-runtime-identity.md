# Identidade dinâmica de release (REI-23)

## Escopo e estado

Recurso operacional comum do **Cloud**. Não altera domínio Law/Lead, dados, permissões,
CSRF, catálogo ou cobrança. Implementação para revisão, **não habilitação produtiva**.
Nenhum merge, deploy, alteração de FPM, OPcache, servidor, segredo ou migration faz
parte desta entrega. O deploy in-place do PR #57 permanece bloqueado pelo preflight:
este endpoint não torna seguro sobrescrever a aplicação ativa.

A identidade atesta a consistência do artefato atendendo **esta requisição de probe**,
não que cada rota foi exercitada, que todos os workers foram substituídos ou que toda
a frota atende o mesmo release. `/up` conserva sua função independente de saúde.

## Contrato HTTP

`GET /_deployment/release?deploy_probe=<48 caracteres hexadecimais minúsculos>`
responde HTTP 200 somente com:

```json
{"status":"ok","release":"0123456789012345678901234567890123456789","probe":"012345678901234567890123456789012345678901234567"}
```

Os campos são exatamente `status`, `release`, `probe`; release é o SHA Git completo
minúsculo, nunca `.env`, header, parâmetro de request, ref ou arquivo SHA mutável.
O nonce é ecoado, não persistido. `Cache-Control: no-store, private, max-age=0`, JSON,
`Pragma: no-cache` e `X-Robots-Tag` são enviados também nas falhas. Nonce ausente,
array, maiúsculo, inválido, parâmetros extras ou método diferente retornam 400 com
`{"status":"invalid_probe","release":null,"probe":null}`. Identidade não confiável
retorna 503 com `{"status":"unavailable","release":null,"probe":null}`.

A fronteira no `public/index.php` captura erros de autoload, bootstrap, identidade e
respostas de erro renderizadas pelo Laravel, descarta buffers e não expõe debug,
paths, hosts, configurações nem exceções. Não lê a identidade para outros caminhos.
Falhas externas ao PHP executado, como Nginx, processo morto, parse error no próprio
entrypoint e interrupções fatais do engine, continuam responsabilidade do servidor;
o verificador rejeita respostas fora do contrato e nunca as registra.

## Vínculo entre código e identidade

1. `prepare-release.py prepare` exige HEAD SHA40 e checkout sem modificações
   rastreadas. Exporta o commit por `git archive` para diretório **novo** cujo nome é
   o SHA. Copia vendor já preparado, sem instalar dependências, e cria diretórios
   vazios de storage. Não copia `.env`, não aponta symlink ativo e não acessa servidor.
2. Após preparação de caches no destino, `seal` compara **todos** os arquivos
   rastreados com os bytes do commit de origem. Qualquer diferença bloqueia. Gera
   manifesto SHA256 dos arquivos em app/bootstrap/config/routes/vendor e PHP em
   public, incluindo caches Laravel e metadados Composer. Não aceita symlinks de
   execução. Somente `public/storage` é ignorado como link de assets, sem segui-lo.
3. Um literal PHP gerado contém SHA, raiz real absoluta e digest do manifesto.
   Esses valores são capturados pelo entrypoint **antes** de Composer e bootstrap.
   Não são obtidos de config Laravel; config:cache/route:cache não podem substituir
   a identidade. A publicação do selo é um rename local; selo existente nunca é
   refeito pelo preparador. Destino existente nunca é reutilizado por `prepare`.
4. O middleware confere raiz, SHA, manifesto, hash de cada arquivo e conjunto exato
   de arquivos, sem cache de resultado entre requests. SHA novo em diretório antigo,
   manifesto novo com captura antiga, arquivo alterado, cache adicionado ou arquivo
   removido falham fechados. Identificador isolado não basta.
5. O endpoint **recusa OPcache ativo**. Não inferimos conteúdo de opcodes pela data
   ou hash no disco. FPM/apache convencionais sem OPcache e PHP built-in server de
   teste são os únicos HTTP SAPIs considerados. CLI HTTP persistente é recusado;
   Octane/RoadRunner/Swoole/preload/workers persistentes não são suportados. Não há
   flag de ambiente para contornar essas restrições e não mudamos configuração FPM.

## Pré-requisitos operacionais ainda não implementados

É obrigatório manter cada raiz **imutável e exclusivamente endereçada**, não
reutilizá-la nem escrever enquanto atende requests. A checagem não cria proteção
filesystem, assinatura, sandbox ou transação sobre todos os includes PHP. Não prova
segurança contra administrador que altera arquivos concorrente/adversarialmente.
Hashes verificam consistência no instante observado; não eliminam TOCTOU. A ativação
segura deve trocar a raiz completa, preservar o binding real do entrypoint e drenar
workers anteriores. Tal procedimento exige desenho/validação próprios e autorização
separada. Um lock cooperativo de leitura não permitiria concluir que rsync/git pull
está coordenado, portanto não automatizamos essa troca.

Também não há atestado criptográfico de fornecedor: vendor é confiado ao build
Composer a partir do lock, e o manifesto passa a conferir seus bytes. Ele não prova
que um vendor previamente comprometido corresponde ao pacote publicado. CI instala
via `composer.lock`; preparação exige autoload presente. A proveniência do build e
isolamento dos artefatos são requisitos externos, não garantias inventadas.

Configuração dinâmica externa, `.env`, banco, Redis, storage, Blade compilado em
storage e assets não PHP não são cobertos pela identidade. Cache bootstrap é
coberto e deve ser preparado **antes** do selo. Artefatos não podem ser movidos para
outro caminho após o selo; a raiz absoluta faz parte do vínculo.

Este protocolo inicial também recusa builds que reescrevem arquivos rastreados
(como determinadas preparações de assets Law). Não permite simplesmente rotular
outputs diferentes com o SHA de entrada. Esses builds precisam de proveniência
específica em extensão posterior; não enfraquecer `seal` para fazê-los passar.

## Custo e abuso

Cada probe faz verificação integral, não apenas lê o SHA. Limites: manifesto de
4 MiB, até 20.000 arquivos e 128 MiB de bytes, janela de validação de 2 segundos.
Um lock local **não bloqueante** permite uma validação por raiz por vez; concorrentes
recebem 503. Isso não é lock de deploy nem garantia absoluta de tempo de I/O do SO.
Não armazenamos resultado verificado entre requests. Um limite excedido falha fechado.
O custo real aparece em `elapsed_seconds` no CI (três amostras). Antes de exposição
produtiva exige-se avaliar custo/latência do artefato real e rate limit de borda;
esta entrega não instala nem altera tal configuração. Nonce não é autenticação.

## Preparação offline e testes

Em checkout limpo com dependências já preparadas, e caminho de destino ainda inexistente:

```sh
SHA=$(git rev-parse HEAD)
ROOT=/caminho/offline/releases/$SHA
python3 tools/ci/prepare-release.py prepare --source "$PWD" --root "$ROOT"
# Opcional, com ambiente sintético/local correto, sem produzir writes em serviços:
(cd "$ROOT" && php artisan config:cache && php artisan route:cache)
python3 tools/ci/prepare-release.py seal --source "$PWD" --root "$ROOT"
```

Não executar esses exemplos sobre releases ativos. Não há comando de ativação.

```sh
python3 tools/ci/test-prepare-release.py
php artisan test tests/Feature/Deployment tests/Unit/Deployment
python3 tools/ci/test-release-identity.py
```

O workflow `Release identity tests`, somente PR/manual, roda PHP 8.4 real com
Composer instalado a partir do lock, testes PHP, preparação sintética Python e
integração HTTP Laravel local. O job existente Application quality permanece dono
do Pint e suíte geral. Não altera deploy.yml ou servidores.

A integração usa **sem reimplementar/afrouxar** o verificador PR #57 no commit
`a8cc9729181471c9f46ccb15d51383e1aceb61ab`, blob
`1a3275aa5619b1651a2d77d3e51c3d1d8b9c778d`. O workflow busca esse commit; se o arquivo
já existe na composição, exige o mesmo blob. Divergência bloqueia até revisão
explícita do contrato. A ausência do commit local é dependência de teste, não skip.
HTTP sem TLS é usado **apenas** no listener 127.0.0.1 do teste pela construção interna
de Configuration; a CLI produtiva do verificador mantém HTTPS obrigatório.

Casos: release correto/stale/ausente/inválido, nonce inválido, corrupção/remoção,
alteração isolada do identificador, APP_DEBUG com tentativas de vazamento antes do
bootstrap, arquivos/caches extras, caches config/routes reais, OPcache realmente
habilitado recusado, conexão indisponível. Fault injection sobre respostas HTTP reais
exercita replay de cache, instâncias inconsistentes, HTTP não 200, cache header e
campos extras. São amostras sintéticas locais, não testes FPM reais nem evidência de
produção/frota. Sem PHP/Composer no executor local, somente testes Python e inspeção
estática podem ser declarados; PHP, Pint e integração aguardam CI.
