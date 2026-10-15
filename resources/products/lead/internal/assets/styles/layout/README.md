# Layout CSS

Esta pasta contém estruturas e utilitários de layout compartilhados.

- `_container.css` define larguras, gutters, safe areas e containers responsivos.
- `_flex.css` define flexbox, gaps, stacks, alinhamento e aliases de compatibilidade.
- `_gap.css` concentra os gaps e espaçamentos entre itens.
- `_stack.css` define composição vertical.
- `_cluster.css` define agrupamentos horizontais com wrapping.
- `_tokens.css` define dimensões estruturais de layout.
- `_index.css` é a entrada única da pasta.

## Aliases legados

`.d-flex`, `.col`, `.align-center`, `.justify-between` e `.gap-*` são mantidos para compatibilidade com o backoffice. Novos componentes devem preferir `.flex`, `.flex-col`, `.items-center` e `.justify-between`.

Quando houver conflito entre classes compartilhadas e estilos específicos do backoffice, o componente específico deve aplicar uma classe própria em vez de aumentar a especificidade global.

Novos componentes devem preferir o namespace `fc-`, como `.fc-container`, `.fc-flex`, `.fc-stack` e `.fc-cluster`. Os aliases sem namespace serão mantidos para compatibilidade durante a migração.
