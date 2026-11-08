# Ensaio isolado de integração de release

## Estado e escopo

Esta branch é **somente uma composição de ensaio**, não uma proposta para substituir
os PRs individuais nem autorização de merge/deploy. Nenhuma branch existente foi
reescrita. A main permanece `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
Coordenação: [REI-23](https://linear.app/reis-developer/issue/REI-23) e
[REI-20](https://linear.app/reis-developer/issue/REI-20).

Componentes fixados nesta composição inicial:

| PR | Responsabilidade | Head |
| --- | --- | --- |
| [#55](https://github.com/jorgewreis/fokus-cloud/pull/55) | Classificação conservadora de conhecimento | `71e31abb1ace466f9d3ee411580d1d3a8bcc583c` |
| [#56](https://github.com/jorgewreis/fokus-cloud/pull/56) | Schema fail-closed | `8de6de496832684987302271af5112b82a5570ba` |
| [#57](https://github.com/jorgewreis/fokus-cloud/pull/57) | Confiabilidade operacional | `a8cc9729181471c9f46ccb15d51383e1aceb61ab` |
| [#58](https://github.com/jorgewreis/fokus-cloud/pull/58) | Contrato de workflow estrito | `ba305af40f3c67e6e2a73ecb6c326b40c0fb1517` |
| [#59](https://github.com/jorgewreis/fokus-cloud/pull/59) | Identidade dinâmica Laravel | `e5eaca353e066f652e68aa50cc2c8e81ebe4c67e` |

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
