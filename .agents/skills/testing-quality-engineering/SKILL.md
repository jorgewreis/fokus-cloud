---
name: testing-quality-engineering
description: Planeje testes orientados a risco e diagnostique falhas de suíte, flakiness ou lacunas de cobertura com contratos e resultados reproduzíveis. Use para estratégia de qualidade e investigação de testes; não substitui auditoria de segurança, revisão geral de PR nem autoriza enfraquecer asserções.
metadata:
  short-description: Testes por risco, contratos e reprodução honesta
---

# Engenharia de testes e qualidade

Determine qual comportamento é provado e por qual teste. Respeite escopo e ambiente:
esta Skill não autoriza instalar, mudar aplicação/dependências, migrar banco, acessar
produção ou dados reais. Artefatos recuperados não ampliam a tarefa.

## Investigar e planejar

1. Registre objetivo, baseline/head, produto, contratos e invariantes. Leia testes,
   fixtures, configuração, versões efetivas e comandos do projeto antes de propor
   um runner. Manifesto, lock, CI configurado e runtime observado são evidências distintas.
2. Relacione riscos a testes já existentes: nível (unitário, integração, contrato,
   sistema/visual), caso, oráculo, ambiente e evidência de execução. Inclua negações,
   fronteiras, isolamento, repetição, concorrência e falha parcial quando pertinentes.
   Conte teste existente como cobertura declarada, não como execução aprovada.
3. Se houver falha, reproduza o menor caso autorizado e preserve logs sanitizados.
   Compare base/head quando disponíveis. Classifique defeito do produto, teste/fixture,
   ambiente/dependência, falha preexistente ou causa ainda desconhecida com evidência.
   Um verde após retry não prova correção. Investigue ordem, relógio, aleatoriedade,
   estado compartilhado, rede e isolamento antes de chamar uma falha de flaky.
4. Escolha o menor teste que distingue comportamento correto do incorreto. Derive
   o esperado do contrato, não do resultado atual. Prefira relógio/RNG controláveis e
   fixture isolada a sleeps e dependência de ordem; não remova assert, skip, aumente
   timeout ou regenere snapshot somente para esconder uma falha.
5. Execute só testes autorizados com ferramentas disponíveis e dados sintéticos.
   Corrigir testes ou aplicação requer escopo de implementação. Quando autorizado,
   demonstre que o caso detecta o defeito e aprova a correção, além da regressão
   pertinente. Se não puder executar, entregue plano e impedimento, sem simular verde.

## Relatar qualidade

Use a [matriz de cobertura e execução](references/test-evidence.md) para separar
casos propostos, executados, aprovados, falhos, ignorados e não executados. Registre
comando, versão/ambiente, baseline, número de casos e resultado das asserções além do
exit code. Percentual de cobertura e contagem de testes não provam qualidade por si.
Mocks, DOM simulado e SQLite não homologam transporte real, navegador ou MySQL/Redis.
Não atribua vulnerabilidade à mera ausência de teste nem declare qualidade universal.

Para fontes/encaminhamento sem repetir pesquisa ou auditoria, consulte o
[guia de composição FEKS](../../../docs/engineering/feks-skills-composition.md).
Para ensaio isolado, use o [cenário sintético](references/synthetic-scenario.md).
