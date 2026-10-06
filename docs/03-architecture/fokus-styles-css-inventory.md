# Inventário de CSS do Fokus Styles, Backoffice e Fokus Law

Este inventário registra a propriedade e a função das folhas visuais no Cloud
e no shell Law. Ele orienta onde corrigir uma divergência e deve ser atualizado
quando o carregamento ou a responsabilidade de um arquivo mudar.

## Fonte oficial do Fokus Styles

| Arquivo ou origem | Classificação | Regra |
| --- | --- | --- |
| `node_modules/fokus-styles` | Pacote instalado | Cloud fixa a faixa `^2.7.0`; lockfile e pacote instalado resolvem `2.7.0`. Confirme a versão carregada antes de usar uma API. |
| [`fokus.css`](../../public/assets/css/shared/fokus.css) | CSS compilado e sincronizado | Gerado por `tools/sync-fokus-styles.mjs`. Não editar diretamente; corrigir no pacote ou no contrato consumidor e sincronizar. |
| Repositório `fokus-styles`, tag [`v2.7.0`](https://github.com/jorgewreis/fokus-styles/tree/v2.7.0) | Fonte canônica da versão instalada | Mudanças genéricas de componentes, tokens ou estados pertencem ao CSS-fonte do pacote. O checkout antigo denominado `FokusCloud - Styles` aponta para outro repositório (`clarus-css`) e não representa essa versão. |

## Backoffice do Cloud

| Caminho | Classificação | Escopo permitido |
| --- | --- | --- |
| `public/backoffice/assets/css/main.css` e `base/`, `themes/`, `layout/`, `utilities/`, `responsive/` | Shell e compatibilidade do Backoffice | Organização da área administrativa, adaptação de tema e layout. Não duplicar a anatomia de componentes `fs-*`. |
| `public/backoffice/assets/css/components/components.css` | Composição compartilhada do Cloud | Carrega `drawer-form.css` e `toast.css`. Contratos reutilizados por páginas Backoffice devem continuar centralizados aqui ou nas composições correspondentes. |
| `public/backoffice/assets/css/components/drawer-form.css` | Composição compartilhada de registros | Anatomia do drawer e da página de registros do Backoffice; manter hooks de produto e estilos do contrato compartilhado, sem copiar a implementação do offcanvas oficial. |
| `public/backoffice/assets/css/components/toast.css` | Composição compartilhada do Cloud | Apresentação das notificações do shell; comportamento e semântica seguem a API Fokus Styles. |
| `public/backoffice/assets/css/pages/*.css` | Complemento de página | Layout e conteúdo exclusivos da página. Seletores de página não devem redefinir componentes comuns. |
| `public/backoffice/assets/css/components/refinements.css` | Legado sem carregamento confirmado | Não aparece no grafo de imports de `main.css` nem nos entrypoints HTML pesquisados nesta auditoria. Não usar como referência de comportamento ativo sem confirmar novo consumidor. |
| `public/backoffice/assets/css/components/buttons.css`, `cards.css` e `form-admin.css` | Folhas específicas ou legadas | `form-admin.css` é carregado por `ativar.html` e `confirmar-email.html`; as demais não aparecem no grafo principal atual. Antes de alterá-las como padrão do Backoffice, confirme consumidores e ordem de carregamento. |

## Shell Fokus Law integrado ao Cloud

| Caminho ou seletor | Classificação | Escopo permitido |
| --- | --- | --- |
| `resources/views/portal/fokus-law.blade.php` | Entrada do shell | Carrega o CSS oficial sincronizado antes do CSS próprio do Law. Preservar a ordem de cascata e atualizar o cache-buster quando mudar qualquer asset referenciado. |
| `public/portal/assets/fokus-law-shell.css`, classes `.law-*` | Shell e composições de domínio Law | Navegação, estrutura jurídica, contexto da unidade e composições exclusivas do produto. Não recriar botões, campos, cards ou overlays oficiais. |
| `.fokus-law-shell-page` e variáveis `--fs-*` | Tema do produto | Adaptam as primitivas oficiais à paleta Law e à tipografia Google Sans. Cores de marca podem variar; estados, foco, semântica e APIs do componente devem continuar oficiais. |
| Seletores `.law-* .fs-*` | Complemento contextual | Podem definir dimensões ou layout de uma composição Law. Se repetidos em outros produtos ou substituindo estado/API oficial, promover para token, variante ou composição compartilhada. |
| `public/marketing/products/fokus-law.css` | Página pública de marketing | Identidade e narrativa públicas do produto; não é a folha do app autenticado. |

## Critério de alinhamento entre produtos

Backoffice e Law carregam o mesmo `fokus.css` da versão `2.7.0` e utilizam as
classes oficiais para controles comuns. O alinhamento deve conservar essa
anatomia e o contrato de estados do componente, usando tokens de tema para
preservar as paletas e a tipografia de cada produto. Uma diferença de cor
semântica entre marcas não é, isoladamente, uma divergência visual a corrigir.

Ao encontrar um seletor local dirigido a `.fs-*`, confirme primeiro seu
consumidor e compare seu efeito com a API da versão instalada. Mantenha ajustes
de layout específicos do domínio; substitua propriedades de estado, foco,
hover ou active pela API oficial quando ela já cobrir a necessidade.
