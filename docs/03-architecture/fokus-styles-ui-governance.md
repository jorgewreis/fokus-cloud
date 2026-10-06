# Governança visual com Fokus Styles

## Objetivo

O Fokus Styles é a fonte visual oficial do Fokus Cloud. Páginas, componentes e
composições devem reutilizar sua API instalada antes de criar qualquer código
visual novo.

## Hierarquia de implementação

1. Tokens `--fs-*` e temas oficiais.
2. Componentes `fs-*`.
3. Utilitários `fs-u-*`.
4. Composições compartilhadas de componentes oficiais.
5. Complementos locais estritamente exclusivos.

Classes `fs-*`, utilitários `fs-u-*`, atributos `data-fs-*`, APIs JavaScript,
estados `is-*` e semântica HTML devem ser consultados na versão realmente
instalada em `node_modules/fokus-styles`.

## Reutilização e fronteiras

Botões, campos, selects, badges, cards, alerts, tabelas, paginação, modais,
offcanvas, dropdowns, tabs e demais componentes existentes não devem ser
duplicados no Cloud.

Uma composição utilizada por mais de uma página deve ser compartilhada. Uma
necessidade visual ou comportamental global deve ser planejada no repositório
separado `fokus-styles`, com testes, documentação, versionamento e posterior
atualização da dependência no Cloud.

`node_modules/fokus-styles` nunca é um local permanente de edição. CSS local só
é aceitável para uma necessidade exclusiva do shell, domínio ou página, com a
justificativa registrada e sem duplicação de tokens ou componentes oficiais.

## Acessibilidade, temas e estados

Todo controle deve possuir nome acessível, foco visível e semântica adequada.
Formulários devem associar labels e mensagens por `for`, `id` e
`aria-describedby`; erros devem usar `aria-invalid` e mensagem anunciável.
Overlays devem usar a API oficial para foco, Escape e retorno ao acionador.

Valide normal, hover, focus-visible, disabled, loading, erro, vazio e sucesso.
Valide tema claro/escuro quando suportado, `prefers-reduced-motion`, teclado e
viewports desktop, tablet e mobile.

## Processo

1. Auditar documentação, pacote instalado, código e consumidores.
2. Confirmar versão e localizar API equivalente.
3. Classificar como reutilização, composição, extensão global ou complemento
   exclusivo.
4. Fazer perguntas somente quando a inspeção não resolver a decisão.
5. Apresentar plano e aguardar autorização explícita.
6. Implementar preservando alterações locais e contratos existentes.
7. Executar checks, testes funcionais e validação renderizada em navegador.
8. Documentar decisões, limitações e evidências.

Os prompts reutilizáveis correspondentes estão em [`docs/prompts`](../prompts/):
o prompt-base, o de componente específico, o de elemento composto, os dois
fluxos de página e o prompt de perguntas.

O [inventário de CSS do Fokus Styles, Backoffice e Fokus Law](fokus-styles-css-inventory.md)
registra a propriedade das folhas ativas, composições do Cloud e estilos de
domínio do Law. Consulte-o antes de migrar ou remover um seletor local.

## Exemplos

Correto:

```html
<button class="fs-btn fs-btn-primary" type="button">Salvar</button>
<input class="fs-form-control" id="company-name" type="text">
```

Incorreto:

```html
<button class="btn-primary-local">Salvar</button>
<input class="input-customizado" style="border-radius: 12px">
```

O segundo caso só seria aceitável se a necessidade fosse comprovadamente
exclusiva e sua justificativa estivesse documentada.

## Documentos relacionados

- [Padrões de interface do Backoffice](backoffice-ui-patterns.md)
- [Design system de formulários](form-design-system.md)
- [Fronteiras dos repositórios](repository-boundaries.md)
