# FEKS: compor as cinco Skills sem duplicar trabalho

Etapa 4, 08/11/2026 UTC. [REI-19](https://linear.app/reis-developer/issue/REI-19).
Este guia coordena procedimentos de instruções. Não cria agente, roteador, automação,
serviço, dependência de pacote ou integração com Notion.

## Escolher pela pergunta e pelo resultado necessário

| Pergunta principal | Skill responsável | Resultado próprio |
| --- | --- | --- |
| Qual contrato/API/versão ou evidência resolve a dúvida técnica? | [engineering-research](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/.agents/skills/engineering-research/SKILL.md) | Fontes/versões, fatos/hipóteses e menor solução compatível. |
| Há falha de segurança demonstrada no fluxo autorizado? | [secure-code-audit](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/.agents/skills/secure-code-audit/SKILL.md) | Controles, achados, cobertura e limites. |
| Onde fica a responsabilidade e qual desenho atende aos contratos? | [software-architecture-review](../../.agents/skills/software-architecture-review/SKILL.md) | Decisão atual/alvo/proposta e trade-offs verificáveis. |
| Por que o teste falha ou que teste comprova o risco? | [testing-quality-engineering](../../.agents/skills/testing-quality-engineering/SKILL.md) | Diagnóstico, matriz proporcional e evidência de execução. |
| O que este diff quebra e qual a menor refatoração necessária? | [code-review-refactoring](../../.agents/skills/code-review-refactoring/SKILL.md) | Regressões por local e ajustes mínimos, sem misturar features. |

Comece com uma Skill principal. Acione outra apenas se houver uma pergunta ainda
não resolvida em seu domínio e se ela estiver disponível no checkout/ambiente.
O fato de engenharia-research mencionar arquitetura não exige carregá-la em toda
revisão arquitetural: use-a quando a decisão depender de pesquisa externa/versionada.
Não execute as cinco em sequência por padrão.

Exemplos de composição, não de roteamento já automatizado:
- Arquitetura recebe o contrato/versão confirmado por pesquisa e devolve invariantes
  e decisões. Testes transforma esses invariantes em casos, sem refazer a pesquisa.
- Review encaminha uma suspeita concreta de autorização à auditoria, junto do trecho
  e dos controles conhecidos. Não publica o mesmo achado duas vezes nem chama falta
  de teste de vulnerabilidade.
- Testes entrega comando, ambiente e reprodução a review. Review avalia se o diff
  atende ao contrato; um verde não substitui a leitura dos consumidores.

## Passagem de contexto e saída única

Compartilhe objetivo, produto, repo/base/head, autorização/exclusões, contratos,
evidências com links, testes já existentes/executados e a pergunta pendente. Não
passe uma conclusão suspeita como fato nem forneça o gabarito de um ensaio independente.
Reutilize referências por link em vez de copiar manuais ou repetir a mesma inspeção.

Um responsável consolida decisão/achados, controles preservados, cobertura e limites.
Cada alegação material aponta à fonte ou ao resultado que a sustenta. Quando duas
Skills discordarem, reconcilie contrato, versão, baseline e alcance; não faça votação.
Estado de tarefa fica no Linear, artefatos e evidências no GitHub. Conhecimento de
uma Skill não é autorização para corrigir, instalar, publicar, fazer merge ou deploy.

## Fontes seletivas e compatibilidade

Comece por instruções locais, código e testes do commit da tarefa. O
[contrato de fronteiras](https://github.com/jorgewreis/fokus-cloud/blob/8dd9835053b7dc986343e8cfc44b5c35bdde920a/docs/03-architecture/repository-boundaries.md)
é o alvo: Cloud para comum/identidade/empresas/acesso/catálogo/billing; Styles para
visual reutilizável; Law e Lead para seus domínios. Não presuma que esse alvo já
corresponde à separação física encontrada no código.

Snapshots FEKS anteriores, não estado vivo:
- [Inventário](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-technical-inventory.md) e [lacunas](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-knowledge-gap-analysis.md), PR #50 / REI-15.
- [Catálogo](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-knowledge-catalog.md), [matriz](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-technology-matrix.md) e [avaliação de fontes](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-source-evaluation.md), PR #51 / REI-16.
- [Uso das duas primeiras Skills](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/docs/engineering/feks-skills-usage.md) e [validação anterior](https://github.com/jorgewreis/fokus-cloud/blob/60dca3f97cfdfd17a97bdbc768863da591b4253d/docs/engineering/feks-skills-validation.md), PR #52 / REI-17.

Não recopie os catálogos em cada Skill. Consulte só a fonte compatível com a pergunta:
manifests/locks, CI configurado e runtime observado são colunas diferentes. Docs
latest não substituem a edição do lock; mocks/SQLite/DOM simulado não provam
provedor/MySQL/navegador real. As Skills não exigem ferramenta externa ou acesso
privado para funcionar; faltando evidência, delimitam a conclusão.

## Descoberta e uso disponíveis

Cada novo pacote está em `.agents/skills/<nome>/`, com `SKILL.md`,
`agents/openai.yaml` e duas referências carregadas conforme a tarefa. Nenhuma cópia
foi instalada globalmente, nenhum perfil de agente/configuração foi alterado.
O padrão segue o skill-creator nativo, a [especificação Agent Skills](https://agentskills.io/specification)
e a [documentação Codex](https://learn.chatgpt.com/docs/build-skills).

Num checkout que contenha a Skill, confirme seu nome no seletor `/skills` ou `$`
do cliente compatível. Um pedido explícito pode ser:

```text
Use $software-architecture-review para avaliar esta proposta em <repo/commit>.
Use $testing-quality-engineering para diagnosticar esta falha com dados sintéticos.
Use $code-review-refactoring para revisar <base..head> sem editar ou publicar.
```

São três exemplos independentes; não é necessário enviar todos juntos. Se a Skill
não aparecer, confira branch/diretório, cliente/versão, homônimos e erros de parsing.
Não altere configuração global nem instale ferramenta como contorno. Quando o
executor não incluir Skills locais no catálogo, leia explicitamente o `SKILL.md`
e as referências relevantes e registre **leitura explícita**, não descoberta automática.

- **Criada:** arquivos existem e passam validação estrutural.
- **Descoberta:** o cliente enumerou nome, caminho e metadados no checkout testado.
- **Aplicada explicitamente:** um executor leu a Skill e a usou no caso registrado.
- **Selecionada implicitamente:** o modelo decidiu invocá-la sem pedido explícito;
  requer evidência própria. O default permitido não comprova que isso ocorreu.

Não há alegação de seleção implícita validada, carga em todos os agentes ou uso futuro
garantido. Veja a [validação desta etapa](feks-skills-stage4-validation.md).

## Dependência e manutenção

A branch desta etapa parte da main `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
Na consulta inicial, #50/#51/#52 estavam draft, sem merge, nos SHAs acima. As duas
Skills de #52 não são copiadas nem incluídas neste diff; os links fixos permitem
consulta informacional. Um checkout só desta branch contém as três novas, não as
cinco. Para descoberta conjunta será necessário um checkout que legitimamente
contenha os cinco pacotes; nenhuma integração ou merge é feita por esta etapa.

Depois de mudança de requisito, versão ou fonte, reavalie somente conclusões afetadas,
registre novo SHA e mantenha exemplos explicitamente fictícios. Não automatize essa
manutenção nem modifique uma Skill vizinha para forçar seleção. Revisão e aceite são
estados distintos de integração e deploy.
