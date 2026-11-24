# Exemplos Fokus Styles — versão 3.0.0

Extraia o ZIP preservando `examples/` e `assets/`. O arquivo é gerado deterministicamente a partir de todos os HTML, CSS e Markdown atuais de `examples/` e dos runtimes locais listados em `assets/`; não o edite manualmente. Abra qualquer HTML de `examples/` no navegador. Os exemplos não precisam de Laravel, npm, fontes externas ou rede. A folha original, não modificada, `assets/fokus-3.0.0.min.css` veio do pacote npm `fokus-styles@3.0.0`; a licença MIT está no mesmo diretório.

Mantenha também `assets/site-palette.css`, `assets/styles-theme.js` e `examples/composition.css`. A paleta compartilhada define as cores do site; `composition.css` usa tokens `--demo-*` e `--desk-*` para os layouts ilustrativos. Eles não são novas APIs do pacote. Os componentes `fs-*` mantêm suas cores e estados originais, inclusive o azul dos botões primários. O JavaScript dentro de cada HTML implementa busca, seleção ou validação local. Não há envio ou persistência dos dados dos formulários. Nomes, métricas e e-mails são fictícios.

O botão “Alternar tema” permite escolher claro ou escuro. O script compartilhado aplica a preferência de aparência quando disponível; se o navegador bloquear o armazenamento local, a troca ainda funciona na página aberta. A navegação para a documentação e o botão de tema aparecem no exemplo aberto diretamente. Quando incorporado pelo site, `html[data-embedded="true"]` oculta essa navegação, e a prévia acompanha o tema da página que a contém.

O site usa os mesmos arquivos para iframe, demonstração aberta e código copiável. As fontes do sistema são intencionais nos exemplos portáveis, sem dependência da fonte do aplicativo ou de CSS externo ao ZIP. Fontes de marca do site não estão redistribuídas neste ZIP. A versão beta em desenvolvimento não é utilizada nem anunciada como publicada aqui.

- `projects.html`: painel e tabela, sem backend. A composição é mais compacta para reunir navegação, indicadores, gráfico e tabela. Rótulos permanecem com pelo menos 12 px e os alvos de interação principais têm 44 px; isso não exige diminuir a escala das demais páginas.
- `onboarding.html`: validação local, foco no primeiro campo inválido e reset.
- `records.html`: busca sem acentos, vazio e detalhes de contato.

Os nove exemplos compartilham paleta, espaçamento e cantos do shell, mantendo layouts adequados a cada tarefa. Além de `projects.html`, `onboarding.html` e `records.html`, o ZIP inclui as demonstrações portáteis `cloud-datatable.html`, `confirmation-modal.html`, `cloud-offcanvas.html`, `law-preferences.html`, `law-notifications.html` e `law-select-pattern.html`. Ao levar para produção, adapte conteúdo e estados, valide acessibilidade no contexto real e implemente persistência/autorização no backend. Estes exemplos não certificam conformidade de um produto.
