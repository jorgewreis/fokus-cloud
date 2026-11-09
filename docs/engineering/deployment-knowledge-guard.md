# Proteção de publicação para conhecimento FEKS

## Estado e decisão

Implementação proposta na REI-20, baseada na main
`8dd9835053b7dc986343e8cfc44b5c35bdde920a`. Não está operacional na main
até aprovação, integração e verificação dos checks reais.

A própria proteção altera workflow e scripts. Seu merge segue o caminho normal
que pode publicar em produção. Adoção bloqueada até decisão explícita sobre essa
consequência; não há exceção por hash, título, mensagem, desligamento de CI ou
bypass de proteção. Nenhum deploy deve ser disparado apenas para testar.

## Auditoria dos consumidores

- `package.json` chama geração SEO, sincronização CSS, cache bust, assets Law e
  Vite. `vite.config.js` usa Tailwind v4; `resources/css/app.css` mantém descoberta
  automática de fontes. **Markdown pode alterar o CSS compilado.** Ausência de
  referência literal em código não prova ausência de consumo pelo build.
- `tools/check-backoffice-ui-contract.mjs` e `tools/create-backoffice-page.mjs`
  leem `docs/templates/backoffice-record-page.html`.
- `routes/web.php` serve páginas em `public/styles/docs`; ferramentas de showcase
  também leem/publicam exemplos Markdown. Esses caminhos nunca são dispensados.
- Runtime Laravel, manifests, migrations, scripts e workflows permanecem fora da
  lista de candidatos. O rsync existente copia documentação e Skills junto com
  o código em publicações normais; dispensar publicação de conhecimento adia essa
  cópia no servidor. A fonte canônica FEKS é o repositório, não o diretório do VPS.
- Não foram modificados consumidores, CSS, recursos de aplicação nem etapas de
  publicação. `README.md` e docs fora de `docs/engineering` continuam publicáveis.

## Classificação e prova

Candidatos: Markdown regular UTF-8 em `docs/engineering`, exceto `AGENTS.md`;
`SKILL.md`, `references/**/*.md` e `agents/openai.yaml` das cinco Skills FEKS.
Não são candidatos scripts, executáveis, symlinks, submódulos, binários, novas
Skills desconhecidas ou outros metadados. Validação adicional exige frontmatter,
nome consistente, descrição, links locais existentes e o subconjunto simples de
metadados `interface` usado pelo FEKS. Não demonstra seleção automática pelo modelo.

O classificador usa histórico completo e nomes separados por NUL. Push compara
`before..after`, incluindo todos os commits. PR classifica `merge-base..head` e
compara builds da base atual e do merge sintético GitHub cujos dois pais devem ser
exatamente base e head do evento. Renames são tratados como remoção e adição;
ambos os lados precisam qualificar. Remoções verificam modo/conteúdo anterior.

Uma impressão SHA-256 da árvore Git auditada (caminhos, modos e objetos) invalida
a dispensa quando qualquer consumidor não documental muda. Exclui somente os
arquivos de conhecimento. O classificador também é incluído: somente o valor
da única linha canônica `AUDIT_DIGEST` é normalizado para evitar autorreferência.
Todos os demais bytes, o caminho e o modo Git do classificador são auditados.
Formato ausente, duplicado ou inesperado impede a dispensa.
Ambas as árvores da comparação precisam corresponder ao registro auditado.
Mudanças futuras em aplicação, infraestrutura, workflows ou arquivos novos exigem
nova auditoria e atualização explícita desse registro para reabilitar a dispensa.
Não atualizar o digest mecanicamente apenas para fazer o check passar. Isso é
invalidação conservadora, nunca exceção para integrar a própria proteção.

Ser candidato não basta. Dois worktrees temporários isolados executam `npm ci` e
`npm run build`, com Node 24, mesmo lockfile (alterá-lo não seria candidato) e sem
segredos de produção. O snapshot SHA-256 inclui arquivos gerados e ignorados,
como `public/build` e o manifesto Law, além dos arquivos não documentais.
Modos de diretórios não documentais e symlinks também são comparados; erros de
travessia ou objetos especiais impedem a prova. Exclui
somente `.git`, dependências `node_modules` e os arquivos de conhecimento
positivamente classificados. Só igualdade completa autoriza `docs_only=true`.
Builds podem custar mais tempo que uma classificação por caminhos; essa escolha
preserva a descoberta Tailwind sem alterar configuração visual da aplicação.

Erro de Git, SHA ausente, comparação vazia, push forçado, diferença de build,
problema de rede/fontes/dependências ou formato indeterminado mantém
`docs_only=false`. Erro de infraestrutura, regressão ou estrutura que falhe o job
bloqueia o deploy dependente. Nenhuma falha autoriza dispensa. Dispatch manual
mantém publicação normal após validações. A decisão é registrada no job summary.

`Validate application` e suas etapas, `Publish Laravel application` e suas etapas,
`application-quality.yml` e demais workflows existentes são preservados.
Somente a condição/dependência de publicação ganha o classificador bem-sucedido.
O grupo `production-deploy` e `cancel-in-progress: false` são preservados.
Não há cancelamento de publicações anteriores. GitHub concurrency não oferece
fila FIFO garantida; antes de eventual adoção, o operador deve revisar execuções
pendentes e não supor que o gate cancela ou modifica workflows já iniciados.

## Evidência e limites

Executar:

```sh
python3 -m unittest discover -s tests/Deployment -p test_deployment_guard.py -v
python3 tools/ci/validate-knowledge.py
git diff --check
```

Regressões usam Git temporário e dados sintéticos: A docs, B Skills, C aplicação,
D misto/múltiplos commits, E configuração, F erro, merges, bases divergentes,
renames, remoções, nomes com tab/nova linha, modos executáveis, symlinks e binários.
Incluem snapshots de assets ignorados, builds simulados iguais/diferentes/falhos,
PR com consumidor novo na base e contratos das etapas/concurrency originais.
Os testes não instalam pacotes nem acessam produção. A/B são candidatos e só
obtêm dispensa final se a prova real de build também passar.

Não confundir regressão sintética com execução real de GitHub Actions, build
Vite, PHPUnit, MySQL/Redis ou descoberta nativa das Skills. Nesta implementação
local, PHP/Composer não estavam instalados e a instalação de dependências npm
foi bloqueada pelo ambiente. Esses checks permanecem no CI para comprovação no
commit publicado. Nenhuma publicação, merge, mudança de VPS ou teste com dados
reais foi realizado para demonstrar o mecanismo.


## Revalidação do ciclo de adoção do PR #55

A hipótese de que o pin inicial apontava para a árvore anterior **não foi
confirmada**. O commit publicado `4141b3e8363b3e9f1b29be7e7d4aef2506f8bf1e`
possui árvore `b508244773f76455ce01c89a8ac898c3fcd253e0`, idêntica à árvore
originalmente revisada. Com o algoritmo publicado:

- Main anterior: `39e53c4eca8a5de9d7ebace045870c54a51858481ce04949222dd11755db4d39`.
- Árvore aprovada/publicada: `2fafc14ff178547291b57a0dc4e491f44de4a4eef4deefc244d0ffe44016ac3e`, exatamente o pin publicado.

Foi identificada outra lacuna: excluir o classificador inteiro permitia que uma
alteração futura nele deixasse de invalidar o pin para commits documentais
posteriores. A normalização restrita ao literal corrige essa lacuna, sem ignorar
scripts, testes, workflows, configurações ou novos consumidores de Markdown.

A reprodução histórica da árvore publicada foi executada localmente e confirmou
os valores acima e a lacuna de autorreferência. Ela é evidência histórica, não
uma dependência permanente do CI em um commit exclusivo da branch do PR.

Os testes `AdoptionLifecycle` clonam o banco Git local em repositório temporário,
derivam a feature da main histórica durável e da árvore atual revisada, simulam
integração com merge e com commit de pai único (squash/rebase), criam commits
documentais e de Skills posteriores e mudanças operacionais separadas. Não
exigem que a branch do PR ou seu commit histórico continuem alcançáveis. Uma
regressão impede adicionar referências fixas a commits fora da main durável.
Cada cenário imprime os digests antes/depois, o pin, a classificação candidata e
a política de publicação. Não usam mocks para reproduzir árvores nem digests.
A igualdade do pin real com a árvore final aprovada, incluindo os próprios testes,
é verificada separadamente como evidência desta revisão. Não é uma obrigação
universal do teste de CI: uma alteração operacional legítima com pin desatualizado
deve manter a classificação `false` e o caminho normal de publicação.

O ciclo permanente usa uma política de auditoria **sintética** isolada, cujo pin é
calculado para sua fixture temporária. Não altera o script/pin implantado. As linhas
de evidência identificam `fingerprint_scope=synthetic-audit-fixture`, o pin real e
se ele coincide com a fixture. Essa política unitária testa a semântica de adoção,
não comprova dispensa real nem substitui reauditoria. Uma regressão executa o CLI
real com mudança operacional e pin desatualizado: saída bem-sucedida,
`docs_only=false`, seguida do próprio teste de adoção nessa árvore, sem bloqueio
artificial e sem build. A prova real de build continua sendo requisito separado.

Resultados esperados: a integração da proteção não é dispensada; docs e Skills
posteriores preservam o pin e podem avançar para prova de build; alterações
mistas ou operacionais invalidam a exceção, inclusive em commits documentais
subsequentes. Reauditar e atualizar o pin exige revisão explícita da nova árvore.

**Limite de evidência:** o ciclo Git e a elegibilidade são comprovados pelos testes,
mas a dispensa efetiva ainda exige builds reais iguais e o job bem-sucedido. Os
mocks existentes são apenas testes unitários dos ramos de erro/igualdade, não
substitutos de homologação Vite. Não foram instaladas dependências, executados
builds reais, merges remotos ou deploys nesta revisão. A ausência local de prova
de build não deve ser apresentada como dispensa comprovada em produção.

O primeiro merge continua sendo decisão separada: o push da mudança operacional
não recebe exceção, mesmo com o workflow novo na árvore resultante. Execuções
antigas já iniciadas também não são alteradas pelo PR. Manter o PR sem merge,
verificar os checks do head e obter decisão explícita sobre a publicação inicial
é a ativação segura; nunca desligar checks, forçar pin ou criar bypass temporário.
