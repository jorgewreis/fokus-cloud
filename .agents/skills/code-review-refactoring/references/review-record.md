# Registro de revisão

## Achado acionável

- **Local:** repo/base/head e menor intervalo relevante do diff.
- **Condição:** entrada/estado e caminho real que chega à mudança.
- **Contrato:** comportamento exigido, fonte e consumidores afetados.
- **Evidência:** antes/depois ou reprodução; resultado observado e limite do modelo.
- **Impacto/prioridade:** consequência e alcance demonstrados, sem severidade gratuita.
- **Ajuste mínimo:** proposta e teste que detectaria a regressão; não é implementação.

Se a prova não alcançar a conclusão, declare a hipótese e a leitura/teste que falta.
Não fabrique numeração de linha, CVE, log, resultado de CI ou benchmark.
Uma vulnerabilidade plausível exige rastrear controles antes de ser afirmada.

## Refatoração proporcional

Explique o problema de manutenção concreto e preserve o contrato observável:
assinaturas públicas, tipos, ordenação, erro, mutação, serialização, persistência,
limites de acesso e efeitos externos quando pertinentes. Use testes de caracterização
para o comportamento preservado, sem congelar um defeito conhecido como contrato.

Diferencie: correção obrigatória de defeito, melhoria autorizada e opção fora do
escopo. Prefira mudança pequena e reversível; não aprove remoção de teste por ser
“redundante” sem verificar qual comportamento ele cobre. Consulte o
[guia FEKS](../../../../docs/engineering/feks-skills-composition.md) para composição.
