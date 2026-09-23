# Catalogo comercial

## Objetivo

Centralizar os dados utilizados para comercializar os produtos FokusCloud. O catalogo deve ser administravel pelo backoffice e consumido pelo catalogo publico, checkout, assinaturas e vouchers.

## Hierarquia

```text
Sistema (product)
  Plano (plan)
    Funcionalidades (modules)
```

Uma funcionalidade pode participar de varios planos do mesmo sistema. A relacao e registrada em `plan_modules`.

## Sistemas

Cada sistema deve possuir:

- codigo interno estavel;
- nome exibido;
- descricao tecnica e conteudo comercial separados;
- identificacao visual;
- status;
- ordem de exibicao unica entre sistemas, com reordenacao automatica dos itens
  subsequentes quando uma posicao ja existente for escolhida;
- datas de criacao e alteracao.

Os sistemas comerciais oficiais sao:

- `law`: Fokus Law;
- `lead`: Fokus Lead.

O produto `lead` possui duas linhas comerciais identificadas nos planos:

- `one`: Fokus Lead One, para corretores independentes;
- `team`: Fokus Lead Team, para imobiliarias e grupos de corretores.

O nome exibido pode evoluir sem alterar o codigo interno.

## Planos

Cada plano deve possuir codigo, nome, descricao tecnica, conteudo comercial, sistema vinculado, funcionalidades, status, ordem de exibicao e destaque comercial opcional.

Regras:

- pertence a um unico sistema;
- deve possuir ao menos uma funcionalidade para ser publicado;
- somente pode incluir funcionalidades do proprio sistema;
- pode ser reutilizado por vouchers e assinaturas;
- seu preco-base e derivado da composicao de funcionalidades.

Planos comerciais aprovados:

| Sistema | Planos |
| --- | --- |
| Fokus Law | Advocacia, Cartorio Criminal, Cartorio Civel, Gestao de Audiencias, Gestao de Expedientes |
| Fokus Lead One | Essencial, Profissional, Avancado, Premium |
| Fokus Lead Team | Essencial, Premium |

O nome completo exibido no catalogo segue o formato `Sistema - Plano`. Exemplos:

- `Fokus Law - Advocacia`;
- `Fokus Lead One - Essencial`;
- `Fokus Lead Team - Premium`.

Os codigos tecnicos devem ser slugs estaveis, independentes do texto exibido. Exemplos: `law-advocacia`, `lead-one-essencial` e `lead-team-premium`.

## Funcionalidades

Cada funcionalidade deve possuir codigo, nome, descricao tecnica, conteudo comercial, sistema vinculado, preco mensal, status, ordem de exibicao e datas de criacao e alteracao.

Tambem podera possuir configuracao de capacidade:

- unidade de medicao;
- limite padrao;
- opcoes de capacidade;
- incremento;
- custo adicional, quando aplicavel;
- dependencias e incompatibilidades.

Uma funcionalidade pode ser marcada como disponivel para contratacao avulsa.

O catalogo do Fokus Law deve contemplar, conforme a matriz de ofertas:

- os cinco modulos comerciais: Gestao de Processos, Gestao de Contatos,
  Gestao de Expedicoes, Gestao de Tarefas e Gestao de Audiencias;
- capacidades de contexto para Advocacia, Cartorio Criminal, Cartorio Civel,
  Audiencias e Expedientes dentro desses modulos.

Prazos, Datajud, documentos, relatorios e notificacoes devem ser capacidades
internas ou variantes dos cinco modulos, e nao modulos comerciais
Law independentes.

Gestao de Audiencias aparece no menu como `Audiencias`. Suas variantes atendem
escritorios, varas criminais, varas civeis, juizados e orgaos publicos.
`audiencias_externo` e add-on dependente para acompanhamento externo por partes.

O modulo `processos` deve ser comercializado como Gestao de Processos. No menu
interno do Fokus Law, o rotulo deve ser `Processos`. Esse modulo concentra
classe processual, assuntos/artigos, prioridades, niveis de sigilo, tags,
autuacao, distribuicao, contatos/partes, Datajud e linha do tempo.

O modulo `contatos` deve ser comercializado como Gestao de Contatos. No menu
interno do Fokus Law, o rotulo deve ser `Contatos`. Esse modulo concentra
pessoas, advogados, instituicoes, orgaos, unidades externas, enderecos, canais,
partes processuais e destinatarios reutilizaveis. Nao deve ser chamado de
Agenda, pois agenda representa compromissos, prazos, audiencias e pendencias.

O plano de Advocacia nao inclui expedicao ou recebimento institucional. O
modulo `expedicoes` e direcionado aos modelos cartorarios e de expedientes,
podendo ser considerado apenas em uma oferta personalizada validada.

Em unidades cartorarias, `expedicoes` pode possuir varias instancias por setor,
como Cartorio e Gabinete. Cada instancia deve manter tipos aceitos, numeracao,
serie, responsaveis, permissoes, fluxo e controles independentes.

Na v1 cartoraria do Fokus Law, o modulo comercial Gestao de Expedicoes
(`expedicoes`) representa oficios, mandados,
cartas expedidas, editais, guias de execucao e atos ordinatorios em nucleo
tecnico unico. Oficios exigem numeracao interna por unidade, instancia e ano.
Outros tipos podem ou nao exigir numero interno, numero externo, retorno,
cumprimento ou conferencia conforme configuracao permitida pelo sistema.
Cartas recebidas nao devem ser tratadas como expediente separado na v1, pois
correspondem a uma classe de processo dentro do modulo `processos`.

Guias de execucao comum e penal, editais criminais e editais civeis devem ser
tratados como tipos ou capacidades configuraveis do modulo `expedicoes`, nao
como modulos comerciais autonomos.

O modulo `tarefas` deve ser comercializado como Gestao de Tarefas. O
termo fluxo ou receita operacional descreve a configuracao interna e nao deve
ser usado como nome principal da oferta.

Nos menus internos do Fokus Law, os rotulos devem ser `Processos`, `Contatos`,
`Expedicoes` e `Tarefas`.

Quando duas denominacoes forem variacoes do mesmo recurso, a modelagem deve preferir um modulo tecnico unico com capacidades ou contextos comerciais diferentes. Isso evita duplicacao e mantem preco, dependencias e limites centralizados.

## Precos

Os valores sao armazenados em BRL com duas casas decimais e exibidos como `R$ 0,00`.

O preco mensal de um plano e calculado pela soma dos precos mensais das funcionalidades vinculadas. Descontos comerciais devem ser configuraveis por ciclo:

- mensal;
- anual.

O preco aplicado a uma assinatura existente nao deve ser alterado retroativamente quando o catalogo mudar. A contratacao deve guardar um snapshot dos valores utilizados.

## Status e publicacao

O ciclo comercial de sistemas, planos e funcionalidades e:

- `ativo`: disponivel para comercializacao;
- `pausado`: temporariamente indisponivel;
- `arquivado`: retirado da comercializacao, preservando historico.

O estado de publicacao deve ser tratado separadamente do ciclo comercial,
permitindo rascunho, publicacao, pausa e arquivamento. Somente a versao
publicada e comercializavel fica disponivel no catalogo publico. Agendamento
de publicacao permanece fora da `0.1`.

Cada publicacao gera snapshot versionado por produto em `catalog_publications`.
Edicoes administrativas posteriores nao alteram o catalogo publico ate nova
publicacao.

Planos e modulos possuem sua propria `published_version`, incrementada em cada
publicacao explicita. A publicacao do produto gera `published_catalog_version`
e congela no snapshot as versoes dos planos e modulos incluidos. Publicar um
plano ou modulo prepara sua versao e marca o catalogo do produto como pendente;
somente a acao manual de publicar o catalogo do produto torna o conjunto
disponivel para novas contratacoes. A publicacao inicial tambem deve estar
disponivel quando ainda nao existe snapshot.

Cada nova assinatura registra no snapshot comercial a versao do catalogo, do
plano e de cada modulo contratado. O campo `subscriptions.version` continua
representando a revisao operacional do contrato, incrementada quando a
assinatura e alterada; contratos historicos sem referencias de versao devem
ser exibidos como nao registrados, sem inferir a versao atual.

## Ofertas sugeridas e personalizadas

Cada plano aprovado e uma oferta sugerida. O cliente pode alterar limites dentro das opcoes permitidas e pode montar uma oferta personalizada com funcionalidades ativas e compativeis. A oferta personalizada nao cria automaticamente um novo plano no catalogo.

## Regras do catalogo publico

O catalogo publico deve consultar a API e exibir somente registros:

- ativos;
- publicados;
- completos e validos;
- pertencentes a composicoes permitidas.

Se a consulta ao banco/API falhar, o sistema nao deve exibir precos ou opcoes simuladas. Deve informar indisponibilidade e registrar o erro.
