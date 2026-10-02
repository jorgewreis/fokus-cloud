# Requisitos do Fokus Law

## Escopo atual de Processos

Para a primeira entrega do Judiciário Criminal, prevalecem os
[requisitos específicos de Processos](fokus-law-gestao-processual.md): cadastro
mínimo CNJ/unidade, Datajud mensal somente para metadados, dois níveis de sigilo
e visibilidade dos públicos internos entre unidades da empresa. Tarefas,
expedições, prazos, documentos, movimentações e exportações são evoluções
futuras deste módulo, mesmo quando previstas neste documento geral.

## Identificacao

- Produto: Fokus Law.
- Contexto v1: cartorios e serventias judiciais.
- Recorte inicial: Cartorio Criminal com base reutilizavel para Cartorio Civel.
- Status: aprovado para detalhamento tecnico.
- Documento de produto: [Fokus Law](../04-products/fokus-law.md).

## Objetivo

Definir requisitos funcionais, nao funcionais, regras de negocio, permissoes,
auditoria e criterios de aceite para implementar a v1 do Fokus Law sem novas
decisoes de produto.

## Escopo

### Dentro do escopo

- processos como entidade central;
- gestao de processos com classe, assuntos/artigos, prioridades, niveis de sigilo,
  tags, autuacao, distribuicao e Datajud;
- gestao de contatos com partes, advogados, instituicoes, orgaos, enderecos e
  canais reutilizaveis por unidade;
- vinculos contextuais de contatos com processos, expedicoes e tarefas;
- expedicoes com tipos configuraveis;
- oficios como tipo de expedicao com numeracao por unidade, setor e ano;
- mandados, cartas precatorias, cartas rogatorias, cartas de ordem, editais,
  guias de execucao e atos ordinatorios como tipos de expedicao;
- cartas expedidas vinculadas a processos, sem numeracao interna propria na v1;
- cartas recebidas como classe de processo;
- tarefas e fluxos operacionais conectados a expedicoes;
- prazos e pendencias vinculados a processos e expedientes;
- perfis e permissoes por unidade;
- acesso multiunidade por vinculo explicito;
- sigilo processual transversal;
- auditoria de acoes sensiveis e operacionais;
- indicadores operacionais por unidade;
- sincronizacao automatica de dados processuais basicos via Datajud;
- suporte orientativo solicitado dentro do produto;
- habilitacao por catalogo comercial publicado e assinatura ativa.

### Fora do escopo

- Advocacia;
- financeiro e honorarios advocaticios;
- gestao completa de audiencias, pauta, sala, link e agenda de audiencias;
- geracao automatica avancada de documentos;
- integracoes automaticas alem do Datajud;
- edicao de planos, precos, vouchers, billing ou assinatura;
- gestao de usuarios internos do Backoffice.

## Requisitos funcionais

| Codigo | Requisito | Criterio de aceite |
| --- | --- | --- |
| RF-LAW-001 | O sistema deve permitir cadastrar processo como entidade central da unidade. | Um processo pode ser criado com numero, classe, assuntos/artigos, unidade, autuacao, distribuicao, situacao oficial, status operacional, prioridade, nivel de sigilo, tags, datas relevantes e observacoes. |
| RF-LAW-002 | O sistema deve exigir unidade vinculada ao processo. | Nenhum processo e salvo sem unidade ativa e autorizada para o usuario. |
| RF-LAW-003 | O sistema deve permitir classificar cartas recebidas como classe de processo. | Uma carta recebida e cadastrada/acompanhada pelo fluxo de processos e nao aparece como expediente separado. |
| RF-LAW-004 | O sistema deve permitir cadastrar contatos reutilizaveis por unidade. | A mesma pessoa, advogado, instituicao, orgao ou unidade externa pode ser reutilizada sem novo cadastro obrigatorio. |
| RF-LAW-005 | O sistema deve permitir informar papel contextual do contato em cada processo, expedicao ou tarefa. | O contato pode ter papel diferente por processo e pode ser destinatario ou referencia externa em expedicoes e tarefas. |
| RF-LAW-005A | O sistema deve separar dados oficiais e operacionais do processo. | Datajud atualiza metadados oficiais sem sobrescrever responsavel, prioridade, tags, observacoes ou status operacional. |
| RF-LAW-005B | O sistema deve permitir linha do tempo do processo. | O detalhe do processo consolida movimentacoes, tarefas, expedicoes, prazos, contatos, partes e auditoria relevante. |
| RF-LAW-006 | O sistema deve permitir criar expedicoes vinculadas a processo quando forem processuais. | Uma expedicao processual nao pode ser salva sem processo da unidade ativa. |
| RF-LAW-007 | O sistema deve controlar tipos e instancias de expedicao por unidade e setor. | Cartorio e Gabinete podem operar instancias independentes com regras proprias. |
| RF-LAW-008 | O sistema deve registrar tipo, destino, assunto, status, responsavel, datas e historico da expedicao. | A tela de expedicao exibe os dados atuais e o historico de alteracoes relevantes. |
| RF-LAW-009 | O sistema deve controlar numeracao interna quando o tipo exigir. | Oficios recebem sequencia anual por unidade e instancia em transacao. |
| RF-LAW-010 | O sistema deve permitir tipos de expedicao sem numeracao interna. | Cartas, editais, guias ou atos podem dispensar sequencia interna quando configurados assim. |
| RF-LAW-011 | O sistema deve permitir informar posteriormente numero atribuido pela comarca ou orgao de destino. | O numero externo e opcional no cadastro inicial e editavel depois por usuario autorizado quando o tipo aceitar. |
| RF-LAW-012 | O sistema deve permitir acompanhar envio, recebimento, retorno e encerramento da expedicao. | A expedicao pode ser acompanhada em fila unica com filtros por tipo, instancia e status. |
| RF-LAW-013 | O sistema deve permitir criar prazos e pendencias vinculados a processos. | Um processo pode listar prazos e pendencias associados. |
| RF-LAW-014 | O sistema deve permitir criar prazos e pendencias vinculados a expedientes. | Uma expedicao pode possuir prazo ou pendencia propria. |
| RF-LAW-015 | O sistema deve permitir atribuir responsavel, data limite, prioridade, status e alerta a prazo ou pendencia. | Prazos vencidos e a vencer aparecem em consultas e indicadores. |
| RF-LAW-015A | O sistema deve permitir receitas operacionais que conectem tarefas, expedicoes, prazos e alertas. | Uma tarefa pode gerar expedicao e uma expedicao pode gerar tarefa posterior conforme receita habilitada. |
| RF-LAW-016 | O sistema deve permitir cancelar ou inativar registros com motivo obrigatorio. | Processos, expedientes, prazos e pendencias usados no fluxo nao sao excluidos fisicamente. |
| RF-LAW-017 | O sistema deve impedir exclusao fisica de registros operacionais ja utilizados. | Uma tentativa de exclusao fisica retorna erro ou e convertida para inativacao/cancelamento auditado. |
| RF-LAW-018 | O sistema deve sincronizar dados processuais basicos no Datajud automaticamente quando processo for criado ou movimentado. | Criacao ou movimentacao agenda sincronizacao Datajud sem acao manual do usuario. |
| RF-LAW-019 | O sistema deve atualizar metadados publicos do processo com base no Datajud. | Campos sincronizaveis sao atualizados sem sobrescrever campos operacionais internos. |
| RF-LAW-020 | O sistema deve registrar divergencias relevantes entre Datajud e cadastro interno. | Divergencias ficam registradas tecnicamente e podem ser auditadas sem aparecer como alerta operacional da unidade. |
| RF-LAW-021 | O sistema deve exibir dashboard operacional por unidade. | O dashboard mostra processos ativos, expedientes pendentes, prazos vencidos/a vencer, produtividade e gargalos. |
| RF-LAW-022 | O sistema deve respeitar modulos e limites habilitados pela assinatura ativa. | Usuario sem modulo habilitado nao acessa funcionalidade restrita, mesmo tendo perfil operacional. |
| RF-LAW-023 | O sistema deve permitir alternancia entre unidades vinculadas ao mesmo usuario. | O usuario acessa apenas unidades com vinculo ativo e perfil atribuido. |
| RF-LAW-024 | O sistema deve permitir solicitacao de suporte orientativo dentro do produto. | Usuario autorizado registra categoria, descricao, prints opcionais sanitizados e metadados tecnicos automaticos. |

## Requisitos de seguranca e permissao

| Codigo | Requisito | Criterio de aceite |
| --- | --- | --- |
| RS-LAW-001 | O sistema deve isolar dados por unidade. | Consultas, listas e detalhes retornam apenas registros da unidade ativa permitida. |
| RS-LAW-002 | O sistema deve aplicar perfil por unidade. | O mesmo usuario pode ter permissoes diferentes em unidades diferentes. |
| RS-LAW-003 | O sistema deve tratar sigilo processual como regra transversal. | Processo sigiloso restringe tambem contatos vinculados, partes, expedientes, prazos, buscas, listas e indicadores detalhados. |
| RS-LAW-004 | O sistema deve permitir autorizacao especifica para acesso a processo sigiloso. | Usuario sem autorizacao nao visualiza dados identificaveis do processo sigiloso. |
| RS-LAW-005 | O sistema deve impedir que o Fokus Law altere regras comerciais da assinatura. | Nenhuma tela do produto permite mudar plano, preco, voucher ou cobranca. |
| RS-LAW-006 | O sistema deve auditar acoes sensiveis e operacionais. | Eventos definidos neste documento geram registro de auditoria com usuario, data, entidade e alteracao. |

## Matriz de permissoes

| Acao | Administrador da unidade | Chefe/Escrivao | Servidor operacional | Visualizador |
| --- | ---: | ---: | ---: | ---: |
| Gerenciar usuarios da unidade | X | X |  |  |
| Gerenciar permissoes da unidade | X | X |  |  |
| Criar e editar processos | X | X | X |  |
| Cancelar ou inativar processos | X | X |  |  |
| Gerenciar sigilo e autorizacoes | X | X |  |  |
| Criar e editar contatos | X | X | X |  |
| Inativar ou mesclar contatos | X | X |  |  |
| Vincular contatos a processos ou expedicoes | X | X | X |  |
| Criar e editar expedicoes | X | X | X |  |
| Cancelar expedicoes | X | X |  |  |
| Configurar tipos e instancias de expedicao | X | X |  |  |
| Criar e editar prazos/pendencias | X | X | X |  |
| Configurar receitas operacionais | X | X |  |  |
| Concluir prazos/pendencias proprios | X | X | X |  |
| Cancelar prazos/pendencias | X | X |  |  |
| Consultar dashboard da unidade | X | X | X | X |
| Consultar auditoria operacional | X | X |  |  |

Permissoes podem ser restringidas adicionalmente por sigilo, modulo contratado,
limite comercial ou vinculo ativo na unidade.

## Regras de negocio

### Processos

- Processo e a entidade central da v1.
- Gestao de Processos e o nome comercial do modulo.
- Processos e o rotulo do menu interno.
- Expedientes processuais devem possuir processo vinculado.
- Cartas recebidas sao classe de processo.
- O sistema deve preservar identificadores externos para integracoes futuras.
- Dados operacionais internos nao devem ser sobrescritos por integracoes.
- Classe processual, assuntos, artigos ou capitulacoes, autuacao, distribuicao e
  situacao oficial compoem a base processual.
- Status oficial e status operacional interno devem ser independentes.
- Prioridades operacionais nao substituem sigilo.
- Tags informativas nao substituem classe, prioridade, sigilo ou status.
- Niveis de sigilo devem restringir entidades filhas quando aplicavel.
- Linha do tempo deve reunir movimentacoes oficiais, tarefas, expedicoes,
  prazos, contatos, partes e auditoria relevante.
- Receitas operacionais podem sugerir tarefas ou expedicoes, mas execucoes
  dependem de regra habilitada e usuario autorizado.

### Contatos

- Gestao de Contatos e o nome comercial do modulo.
- Contatos e o rotulo do menu interno.
- O modulo nao deve ser chamado de Agenda para nao confundir com compromissos,
  prazos, audiencias e pendencias.
- Contato pertence ao contexto autorizado da empresa/unidade.
- Contato pode representar pessoa, advogado, instituicao, orgao, unidade
  externa, parte, destinatario ou referencia operacional.
- Contato pode ser reutilizado em multiplos processos, expedicoes e tarefas.
- O papel processual pertence ao vinculo entre contato e processo.
- O papel em expedicao pertence ao vinculo entre contato e expedicao ou ao
  snapshot historico da expedicao.
- O cadastro deve coletar apenas dados necessarios para identificacao
  operacional e execucao do fluxo.

### Expedicoes

- Expedicoes possuem tipos configuraveis por unidade.
- Oficio e tipo de expedicao com numeracao interna obrigatoria por unidade,
  instancia e ano.
- Cartas precatoria, rogatoria e de ordem, mandados, editais, guias de execucao
  e atos ordinatorios sao tipos de expedicao.
- Cartas expedidas nao possuem numeracao propria interna na v1.
- Numero externo atribuido no destino e opcional e pode ser preenchido
  posteriormente quando o tipo aceitar.
- Cada instancia pode manter sequencia, responsaveis, permissao e historico
  independentes.
- Cancelamento exige motivo.
- Alteracao de tipo, numero, instancia, processo vinculado, destino, status,
  envio ou retorno deve ser auditada.

### Gestao de tarefas

- Tarefas representam trabalho a cumprir.
- Expedicoes representam documentos expedidos ou acompanhados.
- Uma tarefa pode existir sem expedicao.
- Uma expedicao pode existir sem tarefa previa quando o tipo permitir.
- Uma tarefa pode gerar uma ou varias expedicoes.
- Uma expedicao pode gerar tarefa de retorno, cumprimento ou conferencia.
- Receitas operacionais definem a conexao entre tarefa, expedicao, prazo e
  alerta.

### Prazos e pendencias

- Prazos e pendencias podem se vincular a processo ou expediente.
- Registros vencidos devem aparecer em listas e indicadores.
- Cancelamento ou alteracao de data limite exige auditoria.
- Alertas devem respeitar responsavel e unidade ativa.

### Datajud

- Datajud e integracao inicial da v1.
- Datajud sincroniza dados processuais basicos automaticamente quando processo
  for criado ou movimentado.
- Dados internos prevalecem para campos operacionais.
- Datajud prevalece apenas para metadados publicos sincronizados.
- Falhas no Datajud nao podem impedir o uso interno de processos ja cadastrados.
- Erros Datajud nao devem aparecer como alerta operacional nem interface
  visivel ao usuario na v1.

### Assinatura e catalogo

- O catalogo comercial publicado e a fonte oficial de modulos e limites.
- A assinatura ativa define direito de uso do produto.
- O Fokus Law deve bloquear funcionalidades nao contratadas.
- Alteracoes comerciais devem ocorrer no Backoffice/Billing, nao no Fokus Law.

## Auditoria

Eventos minimos:

- criacao, edicao, cancelamento e inativacao de processo;
- alteracao de sigilo;
- acesso a processo sigiloso;
- criacao, edicao, inativacao e mesclagem de contatos;
- vinculacao e desvinculacao de contatos/partes;
- criacao, edicao, cancelamento e mudanca de status de expedicao;
- criacao, edicao, cancelamento e mudanca de status de tarefa;
- configuracao de receitas operacionais;
- criacao, edicao, cancelamento e mudanca de status de prazo ou pendencia;
- alteracao de data limite;
- alteracao de responsavel;
- configuracao de usuarios e permissoes da unidade;
- sincronizacao Datajud que altere metadados internos;
- registro ou resolucao de divergencia Datajud;
- exportacao de dados, quando existir.

Campos minimos do evento:

- usuario;
- data e hora;
- empresa;
- unidade;
- entidade;
- identificador da entidade;
- acao;
- valores antes e depois, quando aplicavel;
- motivo, quando aplicavel;
- metadados minimos de origem.

Motivo e obrigatorio quando a acao afetar sigilo, prazo, cancelamento,
inativacao, disponibilidade do registro ou historico operacional relevante.

## Dados

Entidades alvo da v1:

- unidade juridica;
- vinculo de usuario a unidade;
- processo;
- tag processual;
- parte;
- vinculo processo-parte;
- expedicao;
- tipo de tarefa;
- receita operacional;
- prazo;
- pendencia;
- evento de auditoria;
- registro de sincronizacao Datajud;
- divergencia Datajud;
- alerta operacional.
- solicitacao de suporte.

Dados sensiveis devem ser minimizados, protegidos por permissao e omitidos de
logs tecnicos sempre que nao forem necessarios.

## Interfaces

Telas minimas:

- selecao de unidade ativa;
- dashboard da unidade;
- lista de processos;
- detalhe do processo;
- cadastro/edicao de processo;
- linha do tempo do processo;
- gestao de tags, prioridades e sigilo;
- contatos;
- contatos e partes do processo;
- lista e detalhe de expedicoes;
- configuracao de tipos e instancias de expedicao;
- lista e detalhe de tarefas;
- configuracao de receitas operacionais;
- lista de prazos e pendencias;
- usuarios e permissoes da unidade;
- auditoria operacional;
- solicitacao de suporte.

Endpoints e rotas devem sempre validar:

- usuario autenticado;
- empresa ativa;
- unidade ativa;
- vinculo ativo na unidade;
- perfil necessario;
- modulo contratado;
- sigilo processual.

## Requisitos nao funcionais

| Codigo | Requisito | Criterio de aceite |
| --- | --- | --- |
| RNF-LAW-001 | O sistema deve manter isolamento multiempresa e multiunidade. | Testes demonstram que usuarios sem vinculo nao acessam dados de outra unidade. |
| RNF-LAW-002 | O sistema deve preservar rastreabilidade operacional. | Acoes sensiveis possuem evento de auditoria consultavel. |
| RNF-LAW-003 | O sistema deve degradar graciosamente quando Datajud falhar. | Falha externa exibe mensagem clara e nao corrompe dados internos. |
| RNF-LAW-004 | O sistema deve evitar duplicacao desnecessaria de dados pessoais. | Contatos reutilizaveis usam vinculos contextuais em vez de cadastros repetidos por processo ou expedicao. |
| RNF-LAW-005 | O sistema deve validar limites contratados no backend. | Requisicoes diretas nao conseguem ultrapassar modulo ou limite da assinatura. |
| RNF-LAW-006 | O sistema deve proteger dados sigilosos em listas e indicadores. | Usuario nao autorizado nao consegue inferir dados identificaveis de processo sigiloso. |

## Criterios de aceite da v1

- Processos podem ser cadastrados, consultados, editados, cancelados/inativados
  e usados como eixo central.
- Gestao de Processos e o nome comercial, com Processos como rotulo interno.
- Gestao de Contatos e o nome comercial, com Contatos como rotulo interno.
- Processos possuem classe, assuntos/artigos, prioridades, niveis de sigilo,
  tags, autuacao e distribuicao.
- Status oficial e status operacional interno sao independentes.
- Linha do tempo consolida movimentacoes, tarefas, expedicoes, prazos,
  contatos, partes e auditoria relevante.
- Cartas recebidas sao tratadas como classe de processo.
- Expedicoes possuem tipos e instancias configuraveis.
- Oficios possuem numeracao por unidade, instancia e ano.
- Mandados, editais, guias e atos ordinatorios sao tipos de expedicao.
- Cartas expedidas exigem processo vinculado e nao possuem numeracao interna na v1.
- Numero externo de destino da expedicao e opcional e posterior quando o tipo aceitar.
- Tarefas e fluxos podem gerar ou acompanhar expedicoes conforme receita
  operacional.
- Contatos podem representar partes, advogados, instituicoes, orgaos,
  destinatarios e referencias externas.
- Partes sao papeis processuais de contatos reutilizaveis entre processos da
  mesma unidade.
- Prazos e pendencias podem ser vinculados a processos e expedientes.
- Perfis por unidade respeitam a matriz de permissoes.
- Usuario acessa apenas unidades vinculadas explicitamente.
- Sigilo restringe dados em detalhes, listas, buscas, indicadores e exportacoes.
- Auditoria cobre as acoes sensiveis e operacionais definidas.
- Datajud atualiza apenas metadados publicos sincronizaveis.
- Dados operacionais internos prevalecem sobre Datajud.
- Dashboard operacional apresenta os indicadores minimos.
- Suporte orientativo pode ser solicitado dentro do produto sem expor dados
  juridicos ao Backoffice.
- Modulos e limites respeitam catalogo publicado e assinatura ativa.
- O Fokus Law nao altera plano, preco, voucher, cobranca ou assinatura.
