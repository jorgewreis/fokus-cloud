# REI-54: notificações no shell Law

Levantamento estático do candidato Cloud; não é validação visual nem comprovação da versão implantada. O Cloud fixa `fokus-styles` 2.7.0 no lockfile; para comparação documental, foi consultado `docs/components/notification-center.md` do checkout local Styles 2.7.0, revisão `6213475e05b3d273ac8fd5d11155a98388ed442a`, sem alteração local nesse arquivo. A documentação desse checkout não comprova que seu comportamento seja idêntico ao bundle servido no Cloud.

## Uso e contrato observados

| Aspecto | Fonte e comportamento |
| --- | --- |
| Gatilho e painel | `resources/views/portal/fokus-law.blade.php:83` declara botão com classes `fs-btn fs-btn-outline-primary fs-btn-icon`, nome acessível e `aria-controls`; a `section` inicialmente oculta referencia `notifications-title` em `aria-labelledby`. O ícone é decorativo (`alt=""`). |
| Abertura e fechamento | `public/portal/assets/fokus-law-shell.js:1461` alterna `hidden` e `aria-expanded`; clique externo e Escape fecham em `:1634` e `:1650`. Retorno de foco e interação real com teclado ainda precisam ser exercitados. |
| Dados e permissão | `fokus-law-shell.js:1490` instala o acionamento e só chama o carregamento quando `context.permissions.manage_settings` é verdadeiro. A atualização após carregar assinatura tem a mesma condição em `:1160`. A política de autorização do servidor não foi auditada neste recorte. |
| API e estados | `fokus-law-shell.js:1725` consulta `FokusApi.request('/law/notifications')`. O botão usa a contagem de não lidas no nome; o indicador visual exibe no máximo `99+`. Lista vazia repõe mensagem; lista preenchida cria botões com título, mensagem e data; `read_at` ausente marca `data-unread`. Não há estado explícito de carregamento ou mensagem de erro nesse trecho; a captura de erro retorna silenciosamente. |
| Ação | Ao ativar um item, o código tenta `PATCH /law/notifications/{id}/read` e, mesmo se a requisição falhar, tenta navegar para `payload.href` iniciado por `/`, ou usa a rota padrão de assinatura. Isso descreve a fonte, sem comprovar persistência, segurança de navegação ou resultado no backend. |
| Nome do painel | O candidato preserva o ID do título ao recriar os filhos. `tests/Frontend/law-notifications-label.test.mjs` cobre lista vazia e preenchida com DOM simulado e API fictícia; não mede nome acessível computado ou tecnologia assistiva. |

## Contrato de servidor inspecionado

As rotas em `routes/api.php` exigem autenticação, contexto de empresa e permissões distintas: `law.notifications.view` no GET e `law.notifications.update` no PATCH. Em `app/Http/Controllers/Api/LawNotificationController.php`, o GET filtra empresa ativa, usuário autenticado e registros não resolvidos; retorna até 20 registros mais recentes e conta não lidas somente nesse conjunto. O PATCH aplica os mesmos filtros, atualiza as datas e responde 204 quando há atualização ou 404 quando não há registro elegível. Esta é uma leitura estática, não uma comprovação de isolamento em execução.

Há um possível desalinhamento de disponibilidade: o shell só carrega notificações com `manage_settings`, enquanto `LawAuthorizationService.php` concede a permissão de leitura também a perfis não administradores, sujeita às regras de vínculo e setor. O produtor localizado em `LawUsageMeter.php` cria alertas de capacidade para administradores; portanto essa diferença, por si só, não demonstra perda de notificações para outros perfis. `LawPermissionsTest.php` prevê GET permitido e PATCH negado para um perfil de consulta; não prova o comportamento visual desses perfis, a persistência ou o isolamento entre empresas. Não se presume a intenção de produto nem se altera a autorização a partir dessa observação.

## Aparência e responsabilidade

`public/portal/assets/fokus-law-shell.css:143` limita o popover a `min(320px, calc(100vw - 32px))`; a superfície branca, a borda e a sombra são regras do shell. Em `:703`, o contador usa cores literais `#b42318` e `#fff`; o item usa `--law-shell-ink`, `--law-shell-muted` e fundo literal `#faf7ff` para não lido. Esses valores e o dimensionamento são observações de código: contraste, foco, corte e aparência responsiva não foram medidos.

A composição, os dados, permissão, navegação e indicação de leitura pertencem ao Law; os estilos e primitivas `fs-btn` são consumidos do Styles. Não foi identificado nesse fluxo o uso da API JavaScript `NotificationCenter` do Styles.

## Comparação com a documentação Styles

O documento Styles consultado descreve inicialização por `data-fs="notification-center"`, `push`, `remove`, `clear`, histórico em memória ou `localStorage`, e abertura que marca todo o histórico como lido. Também descreve Toast, eventos e tokens `--fs-color-surface`, `--fs-color-subtle`, `--fs-color-danger`, `--fs-radius-md` e `--fs-shadow-md`. O Law observado consulta o servidor e tenta marcar um item ao ativá-lo; seu painel usa a composição e tokens locais descritos acima. Esses contratos não são intercambiáveis sem avaliar dados, permissões e comportamento.

A adaptação local `public/styles/examples/law-notifications.html` demonstra listas preenchida e vazia com dados fictícios, sem API, persistência ou navegação; suas cores seguem a vitrine, não são prova de fidelidade à tela Law. `tools/styles-showcase/law-notifications.test.mjs` verifica interação e foco em DOM simulado. Ainda faltam revisão em navegador dos estados, largura estreita, temas, contraste, teclado e foco; a página não reproduz erro de rede nem permissão. Também falta confrontar o exemplo com a tela autenticada autorizada e verificar o contrato real do backend; nenhuma dessas evidências é substituída por inspeção estática ou teste DOM.
