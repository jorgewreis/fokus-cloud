# Índice de produtos dos interesses

O filtro administrativo sugere códigos de produtos do catálogo conforme `platform.catalog.manage` e códigos históricos de interesses conforme `platform.product_interests.manage`. A entrada pública continua limitada aos produtos já aceitos por seu contrato; este índice não habilita captação de novos produtos.

## Implantação em duas fases

1. Publicar o release que sincroniza os vínculos na mesma transação da gravação e aplicar sua migration. O fluxo atual pode disponibilizar o código antes de a migration terminar: enquanto qualquer tabela do índice estiver ausente, o escritor mantém a gravação original e as sugestões declaram o índice indisponível. O índice começa com `ready=false`; até a conclusão, a interface avisa que as sugestões históricas estão incompletas e permite digitar o código. Não usar o índice como fonte para decisões comerciais.
2. Comprovar que todos os processos web, workers, instâncias e importadores que possam gravar `product_interests.products` usam o novo escritor transacional. Drenar ou atualizar versões antigas antes de iniciar o backfill; confirmação apenas por subdomínio, flag ou implantação de um único nó não comprova a drenagem. Escritas SQL diretas ou importadores que não atualizem o índice não são compatíveis.
3. Após confirmar a etapa anterior e a possibilidade de retomada, executar `php artisan fokus:backfill-interest-products --confirm-drained`. O comando recusa execuções simultâneas, marca o índice indisponível, percorre IDs em lotes e bloqueia cada linha enquanto reconstrói seus vínculos. Escritores novos concorrem pela mesma linha e atualizam o índice na mesma transação. O comando só define `ready=true` após concluir o percurso; JSON ou códigos inválidos interrompem o trabalho com `ready=false`.
4. Conferir o resultado e consultar o endpoint com perfis autorizados e sem permissão de catálogo. O backfill não deve ser executado simultaneamente a outro backfill. Em interrupção abrupta, a flag `running` pode permanecer ativa: somente após comprovar que nenhum processo anterior segue executando, um operador autorizado deve limpar essa flag e reiniciar o comando. Não editar a flag apenas porque uma observação de progresso expirou.

Antes de voltar a um release que não sincronize o índice, marcar `ready=false`; repetir drenagem e backfill após restaurar o escritor compatível. Drenar requisições/escritores antes de qualquer rollback de schema: a verificação de existência não elimina corridas com DDL concorrente. O índice pode ser reconstruído a partir dos interesses e não substitui a coluna original. Não remover a tabela original nem desativar filtros existentes durante a transição. Novos caminhos de gravação devem usar a mesma transação e sincronização.

## Limites e verificações

O endpoint retorna páginas de até 20 códigos históricos e até 20 produtos do catálogo por requisição, sem `COUNT` e sem percorrer o JSON da tabela de interesses. A paginação usa offset e custo crescente em páginas profundas; os dois conjuntos são paginados separadamente e um código pode aparecer em mais de uma página. Pesquisa por prefixo e digitação manual permitem restringir a navegação. Códigos sem item de catálogo são apresentados pelo próprio código.

Para comprovar concorrência e rollback, usar apenas ambiente descartável com MySQL em rede interna isolada, banco `synthetic`, `APP_ENV=testing`, `FOKUS_INTEREST_INDEX_DISPOSABLE=1`, dependências locais e sem `.env` ou cache de configuração herdado; executar `php tests/Deployment/interest-index-concurrency.php` após migrar o banco sintético. As flags não estabelecem isolamento por si. O script valida as duas ordens de bloqueio entre reconstrução e escritor e o rollback do vínculo; não certifica importadores externos nem o processo de drenagem da produção.

### Prova sintética de BFCache real para Interesses

O roteiro `tests/Deployment/interest-real-bfcache.mjs` responde diretamente
no processo de teste usando o handler estático sintético. Não abre nem consulta
um socket de fixture, não transmite prova reutilizável e aborta requisições
externas. A origem `127.0.0.1:4196` é virtual e as respostas são entregues ao
navegador por interceptação em memória. Mesmo que outro processo ocupe ou
substitua essa porta, ele não recebe as requisições do ensaio.

O roteiro substitui `no-store` **somente no documento sintético**, para permitir
o BFCache, e simula autenticação e interesses. Ele não acessa a aplicação real,
credenciais, dados reais ou o provedor e não altera cabeçalhos de produção. As
dependências do projeto e o navegador Playwright devem estar instalados;
opcionalmente `FOKUS_TEST_BROWSER` aponta para um executável Chromium/Edge local.

Em PowerShell, a partir da raiz isolada do projeto:

```powershell
$env:FOKUS_INTEREST_BFCACHE_DISPOSABLE = '1'
foreach ($mode in @('same', 'changed', 'denied', 'double', 'double-navigation')) {
    node tests/Deployment/interest-real-bfcache.mjs $mode
    if ($LASTEXITCODE -ne 0) { break }
}
```

O ensaio exige `pageshow.persisted=true`. Ele verifica identidade mantida,
identidade alterada, resposta 401, duas restaurações com autenticações pendentes
e nova suspensão durante a remontagem. Nos cenários de recarga, o teste verifica
a solicitação de reload; o comportamento real após essa navegação depende da
autenticação do ambiente. Caso o navegador não aceite BFCache, o roteiro falha:
isso não comprova sucesso nem falha funcional da restauração. O fixture não
representa a política real de cache, a infraestrutura ou o navegador do cliente.
A substituição adversarial de listener e o bloqueio de destinos externos podem
ser verificados com `node --test tests/Deployment/interest-real-bfcache-safety.test.mjs`.
