# Fokus Styles next / piloto

Snapshot separado da vitrine publicada v2.7.0. Não é release, instalação npm nem prova de cobertura completa. Os 56 contratos têm 125 exemplos no total (ao menos uma fixture mínima por contrato, com variações selecionadas) e permanecem com cobertura parcial; não há cobertura exaustiva de variantes, estados, temas ou acessibilidade manual.

A fonte canônica é `docs/generated/next/catalog.json` no repositório `jorgewreis/fokus-styles`. Não editar manualmente a cópia JSON ou os bundles. Em um checkout confiável e revisado, gerar catálogo e bundles, depois executar:

```
node tools/sync-styles-next.mjs /path/to/fokus-styles
node --test tools/styles-showcase/catalog.test.mjs
node tools/styles-showcase/catalog.dom.mjs
node tools/styles-showcase/catalog.browser.mjs
```

`provenance.json` registra o hash dos bytes importados. A documentação e os snippets são apresentados como texto, preservando o código original para cópia. Apenas fixtures `examples` revisadas são executadas no iframe com sandbox `allow-scripts`, sem `allow-same-origin`, navegação superior, envio de formulário ou conexões de rede. Miniaturas locais podem usar `blob:` apenas em `img-src`; conexões e envio de arquivos continuam bloqueados. Limitações de cada fixture são exibidas integralmente. O preview adapta imports para o bundle IIFE; a cópia mantém os imports originais.

A página principal, os exemplos existentes e o pacote ZIP continuam separados e usam o CSS publicado v2.7.0. Sem alteração de dependência npm. O snapshot não deve ser apresentado como documentação estável nem publicado automaticamente.
