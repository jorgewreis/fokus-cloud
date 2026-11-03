# QA da vitrine Styles (sem deploy)

- DOM: `STYLES_JSDOM=/caminho/jsdom/lib/api.js node tools/styles-showcase/test-dom.mjs` (ou instale jsdom em ambiente de teste separado).
- Browser: instale as devDependencies do projeto e o Chromium Playwright; execute `npx playwright test --config tools/styles-showcase/playwright.config.mjs` na raiz. O servidor estático fica limitado a `public/`, porta 8874.
- Esse comando captura a implementação real em 1440, 1165, 768 e 390px, claro/escuro, testa overflow e tipografia computada. Verifique visualmente as imagens antes de aprovar publicação; screenshot gerado não equivale a aprovação visual automática.
- A suíte também verifica cópia exata de cada HTML, abertura por teclado, foco após fechar, validação e reset, busca sem acentos, estado vazio e detalhes.
- Os testes DOM cobrem ainda resposta fora de ordem, fechamento com requisição pendente e falha HTTP.
- Axe e revisão manual de contraste/zoom/leitor de tela ainda são necessárias para uma declaração de conformidade. Não existe declaração automática de WCAG.

A alteração não inclui workflow de deploy, merge ou publicação npm. O framework do ZIP é exatamente o npm 2.7.0, não o código beta em revisão.
