---
name: Tarefa autônoma Julia
about: Delimitar uma entrega para execução autônoma com autorização versionada.
title: "[Julia] "
---

## Objetivo

Descreva o problema, o comportamento esperado e as restrições. Não inclua dados reais.

## Contrato

```json
{
  "product": "cloud",
  "allowedPaths": ["docs/09-operations/exemplo.md"],
  "acceptance": ["Substituir pelo resultado verificável esperado"],
  "dependencies": []
}
```

Use arquivos exatos ou diretórios terminados em `/`. Não use curingas.
`dependencies` contém números de issues que precisam estar fechadas.
O piloto aceita somente `cloud`; tarefas dos produtos devem ir ao repositório correto.

## Autorização

Após finalizar o texto, obter o SHA-256 do corpo com `node tools/julia/operations.mjs digest <arquivo-do-corpo>`.
Jorge deve registrar um comentário separado, com exatamente `/julia approve HASH`.
Alterar o corpo invalida a autorização. `/julia revoke` revoga a autorização.
