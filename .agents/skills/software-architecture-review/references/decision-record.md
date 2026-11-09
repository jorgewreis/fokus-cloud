# Registro compacto de decisão arquitetural

Use apenas os campos que mudem a decisão, sem criar um ADR para toda tarefa.

- **Pergunta, escopo e baseline:** repo/commit, atual/alvo/proposta, autorização.
- **Mapa mínimo:** componente, responsabilidade, proprietário do dado e dependência.
- **Contrato/invariante:** comportamento exigido e fonte; cenário de falha relevante.
- **Alternativas:** atual, menor mudança e outra opção plausível; benefício, custo,
  risco, compatibilidade e evidência que faria rejeitar cada uma.
- **Decisão:** recomendação justificada; desconhecidos e condição que a faria mudar.
- **Validação:** caso, ambiente autorizado, resultado esperado derivado do contrato,
  resultado observado ou não executado, alcance e limite.
- **Evolução:** sequência reversível, rollback, observabilidade necessária e dono.

Uma chamada remota pode deslocar falhas, uma fila muda o tempo de consistência,
e um cache exige contrato de expiração/revogação. Relacione o trade-off ao requisito,
não apenas a um rótulo como “desacoplado”. Não exigir todos esses mecanismos.

No Fokus, consulte seletivamente o [guia de composição](../../../../docs/engineering/feks-skills-composition.md)
para fontes e responsabilidades. Não copie o catálogo nem assuma que PRs anteriores
foram integrados. Contratos de segurança precisam de revisão específica quando
forem o objeto da tarefa; a arquitetura sozinha não atesta isolamento.
