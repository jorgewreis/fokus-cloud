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
processos secretos aos quais o usuário tem acesso. Todos são calculados sobre
a mesma consulta autorizada da lista, incluindo a pesquisa e a opção de
arquivados. O resumo nunca contabiliza processos secretos sem autorização.

A ficha mantém dados processuais e organização interna em cards separados,
com cabeçalho de identificação, vínculos e histórico. Formulários reutilizam
a anatomia dos diálogos de Contatos. O dashboard usa o mesmo cabeçalho, largura,
paleta e tipografia dos cards de módulos, com distribuição por classe e atalhos
para os cadastros recentes. As diferenças de conteúdo respeitam o contexto
judiciário criminal; não incluem movimentações, tarefas ou prazos.

## Cadastro e consulta oficial

O cadastro aceita CNJ completo ou os 13 primeiros dígitos. Cada unidade pode
configurar segmento e tribunal em listas com os códigos e nomes da Resolução
CNJ nº 65/2008, e informar a comarca/unidade de origem em quatro dígitos. Esses
valores são sugestões editáveis e não bloqueiam a informação dos componentes
próprios de cada processo. O CNJ é validado pelo dígito verificador antes de
salvar. Autuação e distribuição são datas opcionais.
O número é único na empresa, inclusive entre arquivados. O processo nasce Ativo,
com prioridade Normal, sigilo Público e sem responsável obrigatório.
Cartas recebidas usam a classe processual.

Classes e assuntos oficiais do CNJ ficam em catálogo global compartilhado entre
empresas. A carga inicial vem dos dados ativos do SGT; consultas Datajud podem
acrescentar códigos ausentes ao mesmo catálogo. Códigos que não constam da base
CNJ ficam em complemento privado da empresa. Os seletores exibem
`código - nome`; no processo são guardados um código de classe e uma lista de
códigos de assunto, com os nomes resolvidos pelo catálogo. A opção Outra classe
ou Outro assunto permite informar código e nome. Se o código já estiver em
qualquer catálogo aplicável, o nome existente é usado sem alterá-lo; somente
códigos novos são cadastrados.

O cadastro confirma o salvamento sem aguardar o Datajud. A consulta inicial é
enfileirada na mesma transação e executada em segundo plano; o detalhe informa
a espera e atualiza o resultado automaticamente, respeitando formulários abertos.
Também há consulta mensal. A ação manual **Consultar Datajud** só aparece se a
consulta automática não obtiver metadados oficiais; após obter dados, a ação é
ocultada. A reconsulta manual é uma alternativa de contingência e não substitui
a atualização mensal. São solicitados apenas classe, assuntos, órgão julgador e
situação oficial, quando disponível. Não são importadas movimentações e a situação não é
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
ativa e contexto contratado. Arquivados conservam a consulta manual de
contingência quando a consulta automática não tiver obtido metadados oficiais.
Consulte [Operação do Datajud](../09-operations/law-case-datajud.md).

## Organização interna

Os estados iniciais são Ativo, Pendente, Suspenso, Concluído e Arquivado.
Chefias e administradores podem criar complementos na unidade e desativar
opções não essenciais. Ativo e Arquivado são preservados porque sustentam
cadastro, reabertura e arquivamento. Desativar não apaga registros anteriores.

O responsável principal é opcional. A prioridade operacional é Normal, Alta ou
Urgente e indica a urgência interna de trabalho. Ela permanece separada dos
fundamentos de prioridade legal de tramitação, que podem ser cumulativos:
Criança ou adolescente, Pessoa idosa (60 anos ou mais), Pessoa idosa (mais de
80 anos), Réu preso, Violência doméstica e Pessoa com deficiência. Cada
fundamento pode ser removido sem alterar os demais. Etiquetas são configuradas
por unidade. Datajud não modifica estado operacional, responsável, prioridade,
etiquetas ou sigilo.

## Sigilo e acesso entre unidades

**Público** permite que usuários da empresa com acesso ao módulo consultem
processos de todas as suas unidades. Advogados e estudantes podem acompanhar
uma audiência pública pelo acesso externo individual que Audiências oferece.
Edição continua sujeita à permissão da ação. Empresas diferentes permanecem
isoladas.

**Sigiloso** permite a todos os usuários autorizados da empresa ver todos os
dados do processo. A audiência vinculada não admite acesso externo.

**Secreto** exige autorização nominal por processo, além do acesso ao módulo e
da permissão da ação, inclusive para administradores consultarem seu conteúdo.
Administrador da empresa, administrador da unidade proprietária ou chefia dessa
unidade concede e revoga autorizações. Pessoas sem autorização não veem o
registro, seus totais, relações ou histórico. Ao tornar um processo secreto, o
autor recebe uma autorização nominal. Administradores podem gerir autorizações
por identificador sem obter acesso automático ao conteúdo.

A mudança de sigilo é reservada à administração/chefia da unidade proprietária.
Audiências sigilosas ou secretas não podem emitir nem usar acesso externo.

Configurações de estados, etiquetas e complementos de papéis continuam
restritas à chefia ou administração da unidade proprietária.

## Contatos e relações

Contatos reutilizáveis recebem estes papéis padrão: advogado, autoridade
policial, defensor público, outro, parte autora, parte ré, promotor de Justiça,
vítima, testemunha da Defesa e testemunha da Denúncia. A unidade pode cadastrar
complementos próprios. O mesmo contato pode ter vários papéis ou participar de
vários processos. Os vínculos não copiam documentos ou dados pessoais.

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
