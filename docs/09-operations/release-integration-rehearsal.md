# Ensaio isolado de integração de release

## Estado e escopo

Esta branch é **somente uma composição de ensaio**, não uma proposta para substituir
os PRs individuais nem autorização de merge/deploy. Nenhuma branch existente foi
reescrita. A main permanece `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
Coordenação: [REI-23](https://linear.app/reis-developer/issue/REI-23) e
[REI-20](https://linear.app/reis-developer/issue/REI-20).

Componentes fixados nesta composição:

| PR | Responsabilidade | Head |
| --- | --- | --- |
| [#55](https://github.com/jorgewreis/fokus-cloud/pull/55) | Classificação conservadora de conhecimento | `71e31abb1ace466f9d3ee411580d1d3a8bcc583c` |
| [#56](https://github.com/jorgewreis/fokus-cloud/pull/56) | Schema fail-closed | `8de6de496832684987302271af5112b82a5570ba` |
| [#57](https://github.com/jorgewreis/fokus-cloud/pull/57) | Confiabilidade operacional | `a8cc9729181471c9f46ccb15d51383e1aceb61ab` |
| [#58](https://github.com/jorgewreis/fokus-cloud/pull/58) | Contrato de workflow estrito | `485fe638b5fd5ac97fd28675fc00f54882e002e4` |
| [#59](https://github.com/jorgewreis/fokus-cloud/pull/59) | Identidade dinâmica Laravel | `b271260d1e9cf4778bf3304e961db73751a297f1` |

## Resultados locais e validação real

Composição dos heads #55/#56/#57 sem conflito textual. Antes da correção, 47 testes
operacionais passaram e 25/26 testes do guard passaram: a falha foi exatamente
`WorkflowContract.test_existing_job_bodies_preserved`, na comparação de `validate`.

Com a correção #58, 29/29 testes do guard e 76/76 testes Python combinados passaram.
O contrato exercita 448 subcasos: duas variantes completas aceitas e 446 mutações
rejeitadas. A comparação é integral, com 16 deltas literais revisáveis do #57;
nenhum campo é ignorado e adoção parcial não é aceita.

O endpoint #59 compõe sem alterar `deploy.yml`. Seis regressões Python de preparação
passaram. O executor local não tem PHP/Composer; não se atribuem a ele testes PHP,
Pint ou integração Laravel/MySQL. Os workflows CI existentes e o workflow dedicado
`Release identity tests` validam a árvore composta em runners descartáveis. Resultados
finais e links dos jobs devem ser lidos no checkpoint do PR para o SHA exato.

No ensaio `32404f2ab0e3da90e394425288550f7955151474`, a integração real
[Laravel/verificador](https://github.com/jorgewreis/fokus-cloud/actions/runs/42536012862/job/128900349934)
concluiu os seis testes Python, dez testes PHP/23 assertions e ambos modos de cache.
Três amostras levaram 0,965s sem caches e 0,881s com config/route cache no runner;
isso não é benchmark produtivo. O marcador final `release_identity_integration=passed`
está presente. O verificador foi usado sem alterar seus bytes.

O [schema composto](https://github.com/jorgewreis/fokus-cloud/actions/runs/42536012853/job/128900349951)
concluiu 16 cenários em PHP/MySQL descartáveis: dois positivos com exit 0 e
14 negativos com exit 1, marcadores completos de supervisor e harness presentes.
Não repetiu o falso verde herdado. [Application quality](https://github.com/jorgewreis/fokus-cloud/actions/runs/42536012861)
também concluiu success. Os checks automáticos do SHA final são registrados no PR,
sem presumir resultado de uma árvore anterior.

O CI também revelou corrida no descarte de uma fixture Git: as asserções funcionais
passaram, mas manutenção assíncrona de commit-graphs escreveu durante o cleanup.
O #58 desabilita manutenção automática somente nos repositórios descartáveis,
desde init/clone, mantendo cleanup obrigatório e todas as asserções. Duas regressões
adicionais verificam a configuração real. Três repetições locais passaram: 31/31
no #55 e 78/78 na composição. A corrida específica do runner não foi reproduzida
localmente; o CI final verifica novamente em Git/Python do runner.

Revisão adicional alinhou o reconhecimento de REQUEST_URI no entrypoint e middleware
do #59, incluindo barras externas. Isso impede que uma variante alcance o endpoint
sem passar pela sanitização pré-bootstrap. Aliases implícitos de base URL não são
parte do endpoint. A integração inclui falha de bootstrap com canário nessas variantes.

Foi reproduzido também no engine PHP 8.4 real que classes preloaded sobrevivem a
`ini_set('opcache.enable', '0')`. A versão final recusa configuração/estatísticas
de preload tanto no entrypoint, antes da identidade/autoload, quanto no guard.
Uma regressão de subprocessos comprova o mesmo snapshot válido sem preload,
a recusa após preload e desativação, e a fronteira pré-bootstrap com canários que
nunca são carregados. Os três cenários passaram localmente, inclusive em revisão
independente; com o guard anterior o teste reproduziu a aceitação incorreta.
O workflow dedicado executa novamente esses cenários no CI. Isso é evidência
do mecanismo PHP, não ensaio de workers FPM reais nem configuração de produção.

O primeiro CI do endpoint detectou preparação incompleta do SQLite sintético e
estilo Pint de `bootstrap/app.php`. Essas falhas foram corrigidas, não contornadas.
Testes ignorados após essas falhas não foram contabilizados como aprovados.

## Contrato e limites da identidade

Ver [release-runtime-identity.md](release-runtime-identity.md). O JSON é consumido
pelo verificador original do #57, com conferência do blob fixado. A integração usa
PHP/Laravel HTTP real local; transporte HTTPS permanece obrigatório na CLI real.

A evidência é da requisição atendida por um artefato consistente sob pressupostos
explícitos: raiz exclusiva imutável, build confiável e runtime sem OPcache ativo.
Não prova toda a frota, todos os workers, banco, configuração externa, Blade em
storage nem todas as funcionalidades. Runtime desconhecido/inconsistente retorna
503; o verificador falha fechado. Não foi observado nem modificado o ambiente real.

O deploy atual in-place, bootstrap inicial do endpoint, builds que reescrevem
arquivos rastreados e operação com OPcache exigem desenho/validação próprios.
Não há ativação automatizada nem bypass do preflight. A composição não está pronta
para publicação produtiva só porque os testes locais ou CI passaram.

## Reauditoria e próximo passo

O classificador e o literal `AUDIT_DIGEST` do #55 permanecem inalterados:
`1daf0709912eafccb811731f900a0f5906bf98ebf660d5f653070a0cbf453c34`.
Todos os arquivos operacionais e testes/docs novos alteram a árvore auditada.
A dispensa documental permanece desabilitada até reauditoria e aprovação específicas.
`docs_only=false` conserva o caminho normal do pipeline; não é uma autorização de
publicação nem substitui os pré-requisitos fail-closed do #57.

Antes de integrar: revisar os PRs individualmente e a árvore composta final,
confirmar CI completo sem falsos verdes, resolver a ativação inicial segura e
compatibilidade do build/runtime real, confirmar alvo/pool/instâncias/recuperação,
reauditar os consumidores protegidos e obter autorização específica para qualquer
merge e sua possível publicação. Não atualizar o pin automaticamente. Nenhuma
migration produtiva, segredo, permissão, sudoers, FPM ou custo foi alterado.
