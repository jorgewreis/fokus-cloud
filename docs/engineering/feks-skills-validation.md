# FEKS: validação das Skills, etapa 3

Data: 08/11/2026 UTC. [REI-17](https://linear.app/reis-developer/issue/REI-17).
Escopo: dois pacotes de instruções e documentação, sem alteração de aplicação.
O SHA publicado e os checks remotos ficam no PR e no checkpoint da tarefa.

## Baseline e método

Main: `8dd9835053b7dc986343e8cfc44b5c35bdde920a`. Árvore remota completa lida:
1.309 entradas, sem truncamento; apenas `AGENTS.md` na árvore como instrução
desse nome, sem Skills anteriores. Foram lidos os perfis implementador/revisor,
índice documental, fronteiras e procedimentos de operação/fila aplicáveis.

As duas Skills foram preparadas em diretório isolado, não no checkout com mudanças
preexistentes. Publicação usa árvore baseada no SHA da main, preservando todos os
arquivos existentes. Os cinco documentos dos PRs #50/#51 foram recuperados nos
heads fixados nas referências, não tomados como já integrados.

Ferramentas disponíveis: Python 3.12.14, PyYAML 6.0.3 e Codex CLI
`0.167.0-alpha.4`. Nenhuma instalação. O skill-creator nativo foi lido;
seu gerador produziu os dois `agents/openai.yaml` e seu `quick_validate.py` retornou
`Skill is valid!` para ambas. Validador estrutural não prova qualidade comportamental.

## Estrutura, links e metadados

- Dois nomes distintos, iguais às pastas; frontmatter YAML com `name`, `description`
  e metadados válidos. Interface com strings, descrição de 25–64 caracteres e
  prompt que menciona `$engineering-research` ou `$secure-code-audit`.
- Nenhum `allowed-tools`, servidor, chave, dependência de ferramenta ou mudança de
  política. Invocação implícita mantém o default do cliente.
- Links Markdown relativos resolvidos dentro dos dez arquivos entregues;
  referências locais alcançáveis e sem placeholders de scaffold.
- Links GitHub dos cinco documentos FEKS conferidos por leitura nos SHAs fixos.
  Documentação OpenAI/Agent Skills, OWASP, CWE, NIST e release ASVS aberta por leitura
  web em 08/11/2026. Isso confirma acesso observado, não manutenção futura nem
  validação integral de cada manual ou de suas âncoras.
- UTF-8, newline final, ausência de CRLF/whitespace final e diff somente aditivo
  verificados. A validação não é um crawler geral nem uma auditoria de segredos.

## Descoberta nativa observada

Com o binário já instalado, foi iniciado `codex app-server --listen stdio://` com
`CODEX_HOME` temporário isolado. Sem iniciar thread ou turno de modelo, o teste
enviou `initialize`, `initialized` e a consulta documentada
[skills/list](https://learn.chatgpt.com/docs/app-server):

```json
{"id":2,"method":"skills/list","params":{"cwds":["<diretório isolado com as Skills>"],"forceReload":true}}
```

Resultado real, resumido:

```text
engineering-research: scope=repo, enabled=true, path=.agents/skills/engineering-research/SKILL.md
secure-code-audit: scope=repo, enabled=true, path=.agents/skills/secure-code-audit/SKILL.md
errors=[]; interface.displayName, shortDescription e defaultPrompt retornados para ambas
```

Isso comprova descoberta e parsing de metadados nessa versão e nesse diretório.
Não comprova seleção implícita, uso por todos os agentes, cliente do proprietário
ou ativação da branch em outros checkouts. O servidor foi encerrado após a leitura.

Uma tentativa adicional de `codex debug prompt-input 'Use $<nome> ...'` para cada
Skill falhou antes de renderizar o prompt: o helper de sandbox informou que o
diretório de socket do app-server precisa pertencer ao usuário e ter modo 0700.
Exit code 1; nenhuma configuração/permissão foi alterada para contornar o erro.
Logo, injeção integral de instruções por esse caminho **não foi comprovada**.
O carregamento explícito dos arquivos foi verificado nos ensaios abaixo.

## Ensaios comportamentais independentes

Cada avaliação recebeu só o `SKILL.md`, a referência pertinente e o pedido/artefatos
fictícios. Não recebeu resposta esperada ou suspeita de defeito. Usou leitura local
e Python padrão, sem rede, instalações, APIs pagas ou acesso ao produto. Caminhos
`fixture/*` nos exemplos são rótulos, não arquivos reais. Como o staging não tinha
HEAD local, não se inventou um commit para os modelos fictícios.

### engineering-research

- Leu entrada, método de evidência e cenário. Identificou manifesto `>=1,<2`, lock
  `1.4.0`, CI Python 3.12 apenas configurado e runtime do produto desconhecido.
- Recomendou `client.fetch("sample", timeout_s=0.25)`, não `timeout_ms=250` da
  documentação fictícia 2.0, sem upgrade ou edição do chamador.
- Executou a função fornecida: argumento `0.25` e default `1.0` passaram;
  `timeout_ms=250` gerou TypeError; zero e negativo geraram ValueError. Asserções
  aprovadas e processo exit 0.
- Explicitou que o double só comprova contrato de argumentos, não duração real,
  retries ou deadline total. Nenhuma fonte fictícia foi tratada como oficial real.

### secure-code-audit

- Leu entrada, matriz/achados e cenário. Extraiu o bloco Python sem modificá-lo,
  validou sintaxe e executou o modelo em memória.
- Listagens A/B, exportações próprias e IDs inexistentes: seis casos passaram.
  Exportações cruzadas A para documento B e B para documento A: dois casos violaram
  o contrato, retornando 200 e o corpo da outra empresa.
- Processo exit 0 porque o harness imprimia os resultados, inclusive FAIL;
  **não é suíte totalmente verde**. A avaliação demonstrou o defeito inserido na
  fixture e o distinguiu da ausência de teste de exportação.
- Relatório incluiu local, middleware assumido, controle de listagem, impacto de
  confidencialidade, severidade alta no modelo, limites e proposta mínima de
  consulta por ID mais empresa, sem implementar a correção.
- Não extrapolou para vulnerabilidade do Fokus, exploração de produção ou
  conformidade ASVS. Áreas sem artefatos foram marcadas não examinadas.

Os dois avaliadores não encontraram defeito bloqueante nas Skills. Após sugestões,
os exemplos explicitaram que caminhos são rótulos e ambas as Skills reforçaram a
distinção entre exit code e asserções. O fluxo/fixture não foi alterado. Um cenário
por Skill não demonstra qualidade universal, robustez contra toda injeção de prompt
ou precisão em auditoria completa. Não houve ensaio de seleção automática por modelo.

## CI e fronteira de entrega

PHP/Composer não estavam disponíveis localmente; nenhuma suíte de aplicação foi
executada aqui. O escopo documental dispensa testes de aplicação locais sem motivo
novo, conforme o procedimento do repositório. Os workflows existentes foram lidos:
PR aciona qualidade/validação; publicação exige `refs/heads/main`.
Nenhum workflow foi modificado ou disparado manualmente. Instalações preexistentes
do CI são próprias de seu runner isolado, não novas ferramentas desta entrega.

Checks dos PRs #50/#51 não contam como checks desta etapa. Consultar o head deste PR
e os jobs efetivos antes de afirmar CI aprovado. Não houve merge, deploy, acesso a
Notion, dados reais, banco, infraestrutura ou alteração de políticas/configuração global.
