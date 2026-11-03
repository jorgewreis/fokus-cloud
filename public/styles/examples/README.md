# Exemplos Fokus Styles — versão 2.7.0

Extraia o ZIP preservando `examples/` e `assets/`. Abra qualquer HTML de `examples/` no navegador. Os exemplos não precisam de Laravel, npm, fontes externas ou rede. A folha original, não modificada, `assets/fokus-2.7.0.min.css` veio do pacote npm `fokus-styles@2.7.0`; a licença MIT está no mesmo diretório.

O CSS compartilhado em `examples/composition.css` é composição e tema próprios desta demonstração, não novos componentes nativos do framework. O JavaScript dentro do HTML implementa busca, seleção ou validação local. Não há envio ou persistência de dados. Nomes, métricas e e-mails são fictícios.

O site usa os mesmos arquivos para iframe, demonstração aberta e código copiável. As fontes do sistema são intencionais nos exemplos portáveis; Google Sans/Bebas Neue são a identidade do site e não estão redistribuídas neste ZIP. A versão beta em desenvolvimento não é utilizada nem anunciada como publicada aqui.

- `projects.html`: painel e tabela, sem backend.
- `onboarding.html`: validação local, foco no primeiro campo inválido e reset.
- `records.html`: busca sem acentos, vazio e detalhes de contato.

Ao levar para produção, adapte conteúdo e estados, valide acessibilidade no contexto real e implemente persistência/autorização no backend. Estes exemplos não certificam conformidade de um produto.
