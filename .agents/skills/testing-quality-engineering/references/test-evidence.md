# Matriz de testes e evidências

Uma linha útil contém: **risco/invariante; contrato e fonte; teste existente ou
proposto; nível e oráculo; dados/ambiente; estado de execução; resultado; limite**.
Prefira alguns casos decisivos a uma checklist de todos os tipos de teste.

## Diagnóstico mínimo

- Capture a falha original e o comando exato sem dados sensíveis.
- Compare versões/ambiente e base/head; se a base não puder rodar, marque a atribuição
  como pendente. Não culpe o PR só porque a falha apareceu depois dele.
- Controle uma variável por vez quando útil: seed, ordem, hora, locale, estado,
  rede ou concorrência. Registre repetições e resultados, não apenas a última tentativa.
- Um teste determinístico deve falhar de modo inequívoco se o contrato for violado.
  “Não lançou exceção” raramente prova resposta, estado persistido ou efeito externo.
- Se criar uma variante experimental autorizada, mantenha original e variante
  separados e descreva a mudança; não apresente teste alterado como reprodução original.

## Seleção proporcional

- Regra pura: exemplos de fronteira e tabela de decisão.
- Contrato entre módulos: erro, versão e compatibilidade do consumidor.
- Persistência: constraints/transação, isolamento, repetição e concorrência no engine
  pertinente; um double não prova locks reais.
- UI: estado final e fluxos interrompidos/repetidos, foco/teclado quando pertinentes;
  snapshot aprovado não prova acessibilidade nem comportamento completo.

Aprovar uma suíte reduz risco no alcance testado. Não soma automaticamente evidências
entre commits, engines ou ambientes. Consulte o [guia FEKS](../../../../docs/engineering/feks-skills-composition.md)
para fontes compatíveis, sem instalar ferramenta para completar esta matriz.
