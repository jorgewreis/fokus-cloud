# Gestão de Processos — Judiciário Criminal

## Objetivo e contexto

O módulo técnico `processos`, apresentado como **Processos** no Fokus Law,
organiza o acompanhamento interno do Cartório Criminal. O cadastro separa dados
oficiais de decisões de trabalho, para identificar processos, atribuir
responsabilidade, priorizar providências e preservar um histórico auditável.

Esta etapa atende ao contexto contratado `judiciario` (compatível com o código legado `vara_criminal`). Advocacia e os demais
contextos continuam sujeitos a definição própria. A página reutiliza o shell do
Fokus Law e componentes Fokus Styles 2.7.0: cards, tabela, campos, botões e
diálogos. A composição de formulários e diálogos está em `law-record-ui.js`, para
uso por outras páginas do portal. O dashboard amplia a composição compartilhada
de largura dos cards no CSS do shell; as cores, a tipografia e os componentes
existentes são reutilizados, sem alteração do pacote Fokus Styles.

### Composição visual compartilhada com Contatos

Processos adota a composição aprovada de Contatos: cabeçalho com ações, painel
roxo de resumo, gráfico de composição, quatro indicadores compactos, registros
recentes, filtros, tabela e paginação. Fonte, paleta, bordas e espaçamentos vêm
do mesmo contrato `law-record-*` no shell. Indicadores e paginação também usam
os mesmos renderizadores em `law-record-ui.js`, consumidos pelos dois módulos.

O gráfico de Processos representa as classes judiciais; quando há mais de quatro
classes, as menores são agrupadas em Outras classes somente na visualização.
Os quatro indicadores mostram processos da consulta, classes, unidades e
processos restritos aos quais o usuário tem acesso. Todos são calculados sobre
a mesma consulta autorizada da lista, incluindo a pesquisa e a opção de
arquivados. O resumo nunca contabiliza processos restritos sem autorização.

A ficha mantém dados processuais e organização interna em cards separados,
com cabeçalho de identificação, vínculos e histórico. Formulários reutilizam
a anatomia dos diálogos de Contatos. O dashboard usa o mesmo cabeçalho, largura,
paleta e tipografia dos cards de módulos, com distribuição por classe e atalhos
para os cadastros recentes. As diferenças de conteúdo respeitam o contexto
judiciário criminal; não incluem movimentações, tarefas ou prazos.

## Cadastro e consulta oficial

O cadastro exige CNJ completo válido e unidade ativa da empresa. Autuação e
distribuição são datas opcionais. O número é único na empresa, inclusive entre
arquivados. O processo nasce Ativo, com prioridade Normal, sigilo Público
interno e sem responsável obrigatório. Cartas recebidas usam a classe processual.

O Datajud é consultado após salvar, mensalmente e por ação manual. São
solicitados apenas classe, assuntos, órgão julgador e situação oficial, quando
disponível. Não são importadas movimentações e a situação oficial não é
inferida delas. A ausência de retorno ou falha externa não desfaz o cadastro.

Campos oficiais retornados não admitem edição direta. Campos ausentes ficam
disponíveis para preenchimento manual. Se um valor oficial posterior divergir
de um preenchimento manual, este é preservado e o editor autorizado escolhe
qual valor manter. Os dois valores, a decisão e o autor são registrados.
Manter o preenchimento não reabre a divergência na próxima consulta idêntica;
um valor oficial novo pode gerar nova divergência.

O detalhe informa a data da última tentativa. Falhas aparecem no próprio
processo, sem fila de revisão ou notificações. A rotina diária consulta registros
não arquivados cuja última tentativa tem pelo menos um mês, respeitando assinatura
ativa e contexto contratado. Arquivados conservam a consulta manual.
Consulte [Operação do Datajud](../09-operations/law-case-datajud.md).

## Organização interna

Os estados iniciais são Ativo, Pendente, Suspenso, Concluído e Arquivado.
Chefias e administradores podem criar complementos na unidade e desativar
opções não essenciais. Ativo e Arquivado são preservados porque sustentam
cadastro, reabertura e arquivamento. Desativar não apaga registros anteriores.

O responsável principal é opcional. As prioridades são Normal, Alta e Urgente.
Etiquetas são configuradas por unidade. Datajud não modifica estado operacional,
responsável, prioridade, etiquetas ou sigilo.

## Sigilo e acesso entre unidades

**Público interno** permite consulta a partir de todas as unidades da mesma
empresa, por pessoas com acesso ao módulo. Edição depende da permissão da ação,
sem exigir vínculo com a unidade proprietária. Essa é uma exceção explícita à
regra geral de isolamento operacional por unidade.

**Restrito** exige autorização nominal por processo, além do acesso ao módulo e
da permissão da ação, inclusive para administradores consultarem seu conteúdo.
Administrador da empresa, administrador da unidade proprietária ou chefia dessa
unidade concede e revoga autorizações. A restrição vale também entre unidades.
Pessoas sem autorização não veem o registro, seus totais ou seus vínculos.

A mudança de sigilo é reservada à administração/chefia da unidade proprietária.
Ao tornar um processo restrito, o autor recebe uma autorização nominal
registrada para poder concluir a gestão dos demais acessos. Administradores
podem gerir autorizações por identificador do processo sem obter acesso
automático ao conteúdo.

Configurações de estados, etiquetas e complementos de papéis continuam
restritas à chefia ou administração da unidade proprietária.

## Contatos e relações

Contatos reutilizáveis recebem papéis padronizados: parte autora, parte ré,
vítima, investigado, acusado, advogado, defensor público, promotor de Justiça,
testemunha, perito, autoridade policial, órgão julgador e outro. A unidade pode
cadastrar complementos. O mesmo contato pode ter vários papéis ou participar
de vários processos. Os vínculos não copiam documentos ou dados pessoais.

São selecionados contatos ativos da mesma empresa, compartilhados internamente
ou locais da unidade do processo. Processos existentes podem ser relacionados
por dependência ou apensamento. Os vínculos são informativos: não propagam
estado, sigilo ou autorização. Cada registro relacionado passa por sua própria
verificação de acesso.

## Listagem, arquivamento e histórico

O dashboard reutiliza cores, formato, tipografia e componentes do card de
Contatos. O card de Processos informa total acessível não arquivado, distribuição
por classe em barras e os cinco cadastros mais recentes. Classes além das três
principais são agrupadas em Outras classes. Todas as métricas e os atalhos
respeitam autorização nominal e isolamento entre empresas.

A busca usa CNJ completo ou parcial. A lista mostra número, classe, unidade,
situação oficial, estado operacional e sigilo, ordenando pela data de cadastro
mais recente. Há paginação e resumo por classe dos registros acessíveis da
consulta. Arquivados entram quando solicitado em **Incluir arquivados**.

Arquivar e reabrir são ações próprias, com justificativa obrigatória e controle
de versão. A reabertura retorna ao estado Ativo e preserva o motivo anterior
no histórico. Editar o estado não permite contornar essas ações.

O histórico registra autor, data, antes/depois, vínculos, autorizações,
arquivamentos, reaberturas, divergências e consultas oficiais, inclusive falhas.
É paginado e não depende da retenção da auditoria geral. Não há exclusão de
processo nesta etapa.

## Fora desta etapa

Não há tarefas, expedições, prazos, pendências, documentos, movimentações
oficiais, observações livres, artigos/capitulações, receitas operacionais,
importação/exportação, notificações por e-mail, PJe ou e-SAJ. Referências gerais
a essas capacidades descrevem evolução futura, não esta entrega.

## Referências

- [Requisitos](../05-requirements/fokus-law-gestao-processual.md).
- [Modelo de dados](../06-data/law-case-management-data-model.md).
- [Segurança](../07-security/fokus-law-security-and-permissions.md).
