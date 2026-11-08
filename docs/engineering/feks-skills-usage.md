# FEKS: usar as Skills de pesquisa e auditoria

Etapa 3, 08/11/2026 UTC. Tarefa: [REI-17](https://linear.app/reis-developer/issue/REI-17).
São duas Skills de instruções, sem servidor, dependência de pacote ou API paga.
Este documento descreve uso; não ativa rotinas nem amplia autorização.

## Escolher e carregar

- [engineering-research](../../.agents/skills/engineering-research/SKILL.md): investigar
  uma decisão técnica antes de propor a menor solução compatível.
- [secure-code-audit](../../.agents/skills/secure-code-audit/SKILL.md): auditar um fluxo
  ou diff de segurança autorizado, relacionando controles, evidências e testes.

Cada diretório contém `SKILL.md`, `agents/openai.yaml` e duas referências carregadas
conforme necessário. Não foi criada cópia em configuração global, plugin ou MCP.

Segundo a [documentação oficial de Skills](https://learn.chatgpt.com/docs/build-skills),
Codex procura `.agents/skills` do diretório de trabalho até a raiz do repositório.
O frontmatter fornece nome/descrição; o corpo e os recursos são lidos quando usados.
No CLI/IDE, `/skills` e `$` permitem seleção explícita. Seleção implícita depende da
descrição, cliente, contexto e configuração; não é garantia de execução.
`agents/openai.yaml` fornece metadados de interface, não executa o procedimento.

Abra um checkout da branch que contém estes arquivos e confirme que os nomes
aparecem no seletor. Se não aparecerem, verifique diretório/branch, versão do cliente,
erros de carregamento e possíveis Skills homônimas antes de repetir. Não altere
configurações globais nem instale ferramentas para contornar o problema.

Quando o executor não oferecer descoberta local, peça explicitamente a leitura
do `SKILL.md` pelo caminho acima e apenas das referências pertinentes. Registre
isso como **leitura explícita**, não como integração automática ou instalação.
Uma Skill em PR não está disponível em checkouts que ainda não contêm a branch.

## Exemplos de pedidos

```text
Use $engineering-research para investigar [problema] em [repo/commit].
Compare código, lock e fonte oficial compatível. Proponha a menor solução,
com impacto, risco e validação. Nesta tarefa, somente leitura e dados sintéticos.
```

```text
Use $secure-code-audit para revisar [diff/rotas] em [repo/commit].
Rastreie autenticação, autorização e empresa até o acesso ao objeto.
Relate controles presentes, achados fundamentados e lacunas de evidência.
Não implemente correções nem faça chamadas a produção.
```

Para ensaio isolado, use o pedido e os artefatos fictícios de
[pesquisa](../../.agents/skills/engineering-research/references/synthetic-scenario.md)
ou [auditoria](../../.agents/skills/secure-code-audit/references/synthetic-scenario.md).
Resultados desses modelos não são achados nem testes da aplicação Fokus.

## Entrada e saída esperadas

Forneça objetivo, produto, repositório/commit, escopo autorizado, exclusões, evidências
disponíveis e critério de aceite. Não envie segredos ou dados reais para completar
um exemplo. Se faltar informação material, a resposta deve delimitar a conclusão.

Pesquisa devolve decisão com fontes/versões, fatos versus hipóteses, menor solução
e validação. Auditoria devolve local/evidência, controle existente, pré-condições,
impacto, severidade justificada, limites, teste e correção proposta. Falta de teste
não é vulnerabilidade e ausência de achados não é atestado de segurança.

Uma solicitação de pesquisa/auditoria não autoriza implementação, instalação,
mudança de dependência/banco/infra/política, publicação, merge ou deploy. Aplicam-se
as permissões da tarefa corrente. Acessos a fontes privadas exigem autorização
própria; esta entrega não consultou Notion.

## Dependências e manutenção

Base de implementação: main `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
Os PRs [#50](https://github.com/jorgewreis/fokus-cloud/pull/50) e
[#51](https://github.com/jorgewreis/fokus-cloud/pull/51) permaneciam draft e sem merge
na consulta inicial. Heads: `883973bb903397adb676d26ea702db907f194fa7` e
`b30cfa5f474d14660554bcbb76c24559abdc6325`, respectivamente.
As referências das Skills apontam para esses SHAs, sem copiar os cinco documentos
ou depender de sua presença na main. A dependência é informacional.

Depois de revisão das fontes, reavalie as conclusões que delas dependem e registre
novo SHA/data na tarefa; não trate um snapshot como estado atual. Linear guarda
o estado oficial, GitHub os artefatos/evidências. Não há sincronização com agentes,
catálogo, Notion, rotinas horárias ou produção.

## Verificação e limites

Veja [registro de validação](feks-skills-validation.md) para a versão do executor,
descoberta observada, ensaios e limites. A estrutura segue a
[especificação Agent Skills](https://agentskills.io/specification); a criação usou
o skill-creator nativo disponível. A especificação não garante suporte de todo cliente.
