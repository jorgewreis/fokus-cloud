# Fokus Law

## Papel

Fokus Law e o produto derivado do ecossistema Fokus Cloud voltado a rotinas
jurídicas, cartorárias e de advocacia, além de organizações do Poder Público.
Os termos e dados cadastrais devem respeitar o segmento e o contexto ativos da
empresa. No módulo de Contatos, os contextos disponíveis são Advocacia /
Escritório, Poder Público / Órgão Público e Poder Público jurídico / Judiciário;
eles compartilham a base empresarial e alteram rótulos e sugestões.

Na v1, o produto deve nascer como uma solucao cartoraria para organizar e
controlar o fluxo operacional de unidades judiciais, com foco inicial em
Cartorio Criminal e base reaproveitavel para Cartorio Civel.

O Fokus Law nao substitui o core da plataforma. Identidade, empresas,
assinaturas, catalogo comercial, vouchers, billing, auditoria de plataforma e
Backoffice pertencem ao Fokus Cloud. O Fokus Law adiciona regras, dados,
permissoes e fluxos especificos do dominio juridico.

## Paleta visual do modelo publico

A pagina publica do Fokus Law usa seis cores principais. Os tokens abaixo sao
definidos em `public/marketing/products/fokus-law.css` e devem ser reutilizados
em vez de novos valores locais:

| Token | Hex | Definicao de uso |
| --- | --- | --- |
| `--law-plum` | `#2b173d` | Cor primaria escura para titulos, botoes, superficies e destaque institucional. |
| `--law-plum-deep` | `#1b1028` | Fundo mais profundo para navegacao e rodape. |
| `--law-purple` | `#7352a5` | Cor de acento para links, marcadores, bordas e estados de destaque. |
| `--law-purple-light` | `#a58bc8` | Acento claro para marca e elementos sobre fundos escuros. |
| `--law-sage` | `#718b7b` | Cor de apoio para secoes, linhas de destaque e estados positivos. |
| `--law-sage-light` | `#b5cfb9` | Variacao clara para textos, bordas e detalhes sobre fundos escuros. |

Os seis quadrados exibidos no rodape seguem essa mesma ordem. Tons de apoio
como `--law-ivory` (`#f5f0e7`) e `--law-ink` (`#241c29`) continuam reservados
para superficies e texto de leitura, mas nao fazem parte da faixa de seis
amostras do rodape.

## Objetivo da v1

O objetivo central da v1 e permitir que uma unidade cartoraria acompanhe sua
operacao diaria por meio de:

- processos como entidade central;
- expedientes e documentos vinculados aos processos;
- gestao de expedicoes;
- tarefas e fluxos operacionais;
- gestao de audiencias;
- prazos e pendencias operacionais;
- contatos, partes, advogados, instituicoes, enderecos e canais reutilizaveis;
- indicadores gerenciais da unidade.

A v1 deve privilegiar governanca operacional, rastreabilidade e reducao de
retrabalho. Quando houver conflito entre abrangencia funcional e clareza de
implementacao, o escopo cartorario definido neste documento prevalece.

## Publico principal

O publico principal da v1 sao cartorios e serventias judiciais.

O primeiro recorte funcional deve considerar Cartorio Criminal, mas os conceitos
base devem evitar acoplamento desnecessario para permitir evolucao futura para
Cartorio Civel.

## Escopo v1

Faz parte do escopo da v1:

- gestao de processos como modulo central, exibido internamente como Processos;
- gestao de contatos, exibida internamente como Contatos;
- cadastro e acompanhamento operacional de processos;
- vinculacao obrigatoria de expedientes a processos quando o expediente for
  processual;
- cadastro de contatos reutilizaveis, incluindo partes, advogados,
  instituicoes, orgaos, unidades externas, enderecos e canais;
- vinculacao de contatos a processos com papel processual contextual;
- vinculacao de contatos a expedicoes como destinatario, orgao de destino ou
  referencia externa;
- gestao de expedicoes com tipos configuraveis;
- oficios como tipo de expedicao com numeracao por unidade, setor e ano;
- mandados, cartas precatorias, cartas rogatorias, cartas de ordem, editais,
  guias de execucao e atos ordinatorios como tipos de expedicao;
- cartas expedidas sem numeracao propria interna, salvo configuracao futura
  explicita por tipo;
- receitas operacionais que conectam tarefas, expedicoes, prazos e alertas;
- registro posterior opcional do numero atribuido pela comarca de destino em
  expedicoes que aceitem numero externo;
- tratamento de cartas recebidas como classe de processo;
- prazos e pendencias vinculados a processos e expedientes;
- perfis internos por unidade;
- acesso multiunidade por vinculo explicito;
- sigilo processual como regra transversal;
- auditoria de acoes sensiveis e operacionais;
- consulta e atualizacao de dados processuais basicos via Datajud;
- indicadores operacionais por unidade;
- habilitacao de modulos e limites pelo catalogo comercial publicado e pela
  assinatura ativa.

## Fora do escopo v1

Nao faz parte da v1:

- modulos de Advocacia;
- financeiro e honorarios advocaticios;
- gestao de audiencias integrais, pauta, salas, links e agenda de audiencias;
- geracao automatica avancada de documentos a partir de modelos;
- integracoes automaticas alem do Datajud;
- substituicao integral de sistemas judiciais oficiais;
- espelhamento completo de PJe, e-SAJ ou outros sistemas processuais;
- CRM amplo ou agenda pessoal fora da finalidade processual/operacional;
- edicao de dados comerciais, assinatura, plano ou cobranca dentro do produto.

Itens fora de escopo podem ser documentados como evolucao futura, mas nao devem
ser tratados como requisito da v1.

## Modulos internos da v1

### Processos

Processos sao a entidade central obrigatoria do Fokus Law v1. Contatos,
expedientes, partes, prazos, pendencias e indicadores devem se conectar ao
processo sempre que houver relacao processual.

Dados operacionais minimos:

- numero do processo;
- classe;
- assuntos, artigos ou capitulacoes;
- vara ou unidade;
- situacao processual oficial;
- situacao operacional interna;
- prioridade operacional;
- nivel de sigilo;
- tags informativas;
- dados de autuacao;
- dados de distribuicao;
- datas relevantes;
- observacoes internas.

Cartas recebidas devem ser tratadas como uma classe de processo. Elas nao devem
ser modeladas como expediente separado nem como modulo autonomo de cartas
recebidas.

O nome comercial do modulo e Gestao de Processos. No menu interno do sistema, o
rotulo deve ser `Processos`.

Dados oficiais, como classe, assunto, orgao julgador, movimentacoes publicas e
situacao oficial, devem ficar separados de dados operacionais internos, como
responsavel, prioridade, tags, observacoes e status de trabalho. A integracao
Datajud nao deve sobrescrever dados operacionais internos.

Tags informativas auxiliam filtros, filas e indicadores, mas nao substituem
classe processual, prioridade, status ou sigilo.

O controle de sigilo deve usar niveis, conforme detalhado em [Gestao de processos do Fokus Law](fokus-law-gestao-processual.md), e deve restringir entidades filhas
do processo quando aplicavel.

O detalhe do processo deve possuir linha do tempo com movimentacoes oficiais,
tarefas, expedicoes, prazos, contatos, partes e auditoria relevante.

Receitas operacionais podem sugerir tarefas ou expedicoes a partir de classe,
assunto, tag, prioridade ou situacao operacional, sem executar acoes
automaticamente quando nao houver regra habilitada e usuario autorizado.

### Contatos

Gestao de Contatos organiza pessoas, advogados, instituicoes, orgaos, unidades
externas, enderecos e canais de comunicacao reutilizaveis pela unidade.

O modulo deve aparecer comercialmente como Gestao de Contatos e, no menu
interno, apenas como `Contatos`.

O cadastro de contatos deve conter dados minimos para identificacao operacional.
O objetivo nao e criar agenda de compromissos nem CRM amplo, mas permitir
identificacao suficiente para execucao cartoraria, filtros, documentos,
indicadores e historico.

Partes processuais, advogados, testemunhas, destinatarios, orgaos de destino e
representantes sao papeis assumidos pelo contato em vinculos especificos. O
mesmo contato pode ser parte em um processo, testemunha em outro e destinatario
de uma expedicao.

O detalhamento do modulo esta em [Gestao de contatos do Fokus Law](fokus-law-contatos.md).

### Expedicoes

Expedicoes sao documentos expedidos e controlados pela unidade juridica.

Na v1, oficios, mandados, cartas precatorias, cartas rogatorias, cartas de
ordem, editais, guias de execucao e atos ordinatorios sao tipos de expedicao,
conforme detalhado em [Controle de expedicoes do Fokus Law](fokus-law-expedicoes.md).

Cada expedicao deve registrar, no minimo:

- unidade;
- setor ou instancia de expedicao;
- tipo;
- processo vinculado quando a expedicao for processual;
- numero interno quando o tipo exigir;
- destino ou destinatario;
- assunto;
- status;
- data de expedicao ou assinatura;
- data de envio;
- data de retorno, quando houver;
- numero externo atribuido no destino, quando aplicavel;
- responsavel;
- historico.

A numeracao deve ser configuravel por tipo e instancia. Oficios devem possuir
numeracao interna obrigatoria por unidade, instancia e ano. Cartas expedidas nao
devem possuir numeracao interna propria na v1, mas podem receber posteriormente
numero atribuido pela comarca ou orgao de destino.

Expedicoes podem ser criadas diretamente quando o tipo permitir ou geradas por
tarefas e fluxos operacionais.

### Gestao de tarefas

Gestao de Tarefas controla o trabalho a cumprir na unidade. Fluxos e receitas
operacionais sao conceitos internos desse modulo.

Uma tarefa pode existir sem expedicao, gerar uma ou mais expedicoes, acompanhar
uma expedicao existente ou ser criada a partir de uma expedicao que exija
retorno, cumprimento ou conferencia.

As receitas operacionais que conectam tarefas, expedicoes, prazos e alertas
estao detalhadas em [Gestao de tarefas do Fokus Law](fokus-law-tarefas-fluxos.md).

### Nomenclatura comercial e interna

Os nomes comerciais dos modulos devem ser:

- Gestao de Processos;
- Gestao de Contatos;
- Gestao de Expedicoes;
- Gestao de Tarefas.

No menu interno do sistema, os rotulos devem ser simples:

- Processos;
- Contatos;
- Expedicoes;
- Tarefas.

Fluxos e receitas operacionais sao conceitos tecnicos internos da Gestao de
Tarefas e nao devem aparecer como nome principal do modulo para o cliente.

### Prazos e pendencias

Prazos e pendencias devem poder ser vinculados a processos e expedientes.

Cada prazo ou pendencia deve registrar:

- entidade relacionada;
- responsavel;
- data limite, quando aplicavel;
- status;
- prioridade;
- alertas;
- historico.

O controle deve permitir identificar prazos vencidos, prazos a vencer,
pendencias abertas, responsaveis e gargalos operacionais.

### Indicadores gerenciais

O painel gerencial da v1 deve apresentar indicadores operacionais por unidade.

Indicadores minimos:

- processos ativos;
- expedientes pendentes;
- prazos vencidos;
- prazos a vencer;
- produtividade por usuario;
- produtividade por setor;
- gargalos por tipo de expediente, status ou responsavel.

Indicadores detalhados devem respeitar sigilo processual e permissoes do usuario
logado.

## Perfis e permissoes

Os perfis internos da unidade na v1 sao:

- administrador da unidade;
- chefe ou escrivao;
- servidor operacional;
- visualizador.

Administrador da unidade e chefe/escrivao podem configurar usuarios e permissoes
dentro da unidade.

Cada usuario deve acessar apenas as unidades as quais foi explicitamente
vinculado. O mesmo usuario pode possuir perfis diferentes em unidades
diferentes.

Permissoes comerciais, assinatura, plano, voucher, billing e Backoffice nao
devem ser gerenciados dentro do Fokus Law.

## Sigilo processual

Sigilo processual deve ser regra transversal da v1.

Processos sigilosos devem ser visiveis apenas para usuarios autorizados na
unidade. A restricao deve se aplicar tambem a:

- partes;
- contatos vinculados;
- expedientes;
- prazos;
- pendencias;
- buscas;
- listas;
- indicadores detalhados;
- exportacoes, quando existirem.

Indicadores agregados podem considerar processos sigilosos apenas quando nao
revelarem dados identificaveis indevidos.

## Auditoria

O Fokus Law deve auditar acoes sensiveis e operacionais.

Eventos auditaveis minimos:

- criacao, edicao, cancelamento e inativacao de processo;
- alteracao de sigilo;
- criacao, edicao, cancelamento e mudanca de status de expedicao;
- criacao, edicao, cancelamento e mudanca de status de prazo ou pendencia;
- vinculacao e desvinculacao de contatos/partes;
- alteracao de permissoes na unidade;
- acesso a processo sigiloso;
- exportacao de dados, quando existir.

Cada evento deve registrar usuario, data, unidade, entidade, acao, valores antes
e depois quando aplicavel, motivo quando a acao afetar historico operacional,
sigilo, prazo ou disponibilidade do registro.

## Integracao com Datajud

O Datajud deve ser a integracao inicial da v1.

Seu papel e permitir consulta e atualizacao de dados processuais basicos, sem
substituir o cadastro operacional interno do Fokus Law.

Regras:

- dados internos prevalecem para campos operacionais;
- Datajud prevalece apenas para metadados processuais publicos sincronizados;
- campos operacionais nao devem ser sobrescritos automaticamente pelo Datajud;
- divergencias relevantes devem ser registradas;
- falhas de consulta devem ser tratadas sem bloquear o uso interno do processo;
- identificadores externos devem ser preservados para sincronizacao futura.

Exemplos de campos operacionais internos:

- responsavel;
- status operacional;
- prazos internos;
- pendencias;
- observacoes internas;
- expedientes;
- permissoes e sigilo interno.

Exemplos de metadados sincronizaveis:

- numero CNJ;
- classe;
- assunto;
- orgao julgador;
- tribunal;
- movimentacoes publicas disponiveis;
- data de ultima atualizacao externa.

## Relacao com catalogo e assinatura

Modulos e limites do Fokus Law devem ser habilitados pelo catalogo comercial
publicado e pela assinatura ativa da empresa ou unidade.

O produto nao deve decidir internamente preco, plano, voucher, desconto ou
condicao comercial. Essas regras pertencem ao catalogo, billing e Backoffice do
Fokus Cloud.

O Fokus Law deve apenas consultar o direito de uso concedido pela assinatura
ativa, respeitando:

- produto contratado;
- plano;
- modulos habilitados;
- limites contratados;
- vigencia;
- status da assinatura.

## Evolucao

Evolucoes esperadas apos a v1:

- Cartorio Civel completo;
- gestao completa de audiencias;
- novos tipos de expedicao;
- documentos e modelos;
- automacoes de expedientes;
- integracoes adicionais com sistemas judiciais;
- modulos de Advocacia;
- prazos e intimacoes para Advocacia;
- agenda, tarefas, clientes, honorarios e financeiro advocaticio.

Essas evolucoes devem respeitar a regra de pertencimento da plataforma: recursos
comuns ficam no Fokus Cloud; regras juridicas ficam no Fokus Law.

## Criterios de aceite do produto

- O Fokus Law v1 esta descrito como produto juridico derivado, nao como core da
  plataforma.
- A v1 esta limitada a cartorios/serventias, com foco inicial em Cartorio
  Criminal e base reutilizavel para Civel.
- Processos sao definidos como entidade central e como modulo comercial Gestao
  Processual.
- Contatos, expedicoes, tarefas, prazos, pendencias e partes possuem papel
  claro.
- Cartas recebidas sao tratadas como classe de processo, nao como expediente.
- Audiencias, Advocacia, financeiro/honorarios e geracao avancada de documentos
  ficam fora da v1.
- Sigilo, auditoria, perfis e acesso multiunidade estao definidos.
- Datajud tem papel claro e nao substitui os dados operacionais internos.
- O documento nao contradiz catalogo, assinaturas, Backoffice ou arquitetura da
  plataforma.
