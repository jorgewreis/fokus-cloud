# Tokens CSS

Este diretório contém os tokens compartilhados do sistema visual.

## Carregamento

Use `../main.css` como entrada única. Ele importa os tokens na ordem abaixo:

1. Cores
2. Espaçamento
3. Radius
4. Sombras
5. Tipografia
6. Z-index
7. Motion

Os temas são importados depois dos tokens primitivos e semânticos.

## Convenções

- Tokens primitivos descrevem escalas e valores reutilizáveis, como `--space-4` e `--color-blue-500`.
- Tokens semânticos descrevem intenção, como `--color-primary`, `--radius-card` e `--shadow-modal`.
- Prefira tokens semânticos em componentes. Use tokens primitivos apenas quando não houver uma intenção equivalente.
- Mantenha nomes em kebab-case e agrupe os tokens por domínio.
- Use `--type-*` para composições tipográficas e `--font-*`, `--text-*` apenas nos aliases de compatibilidade existentes.
- Use `--duration-*`, `--ease-*` e `--transition-*` para animações.

## Validação

Execute na raiz do projeto:

```bash
npm run tokens:check
```

O validador detecta tokens duplicados e referências a variáveis inexistentes.
