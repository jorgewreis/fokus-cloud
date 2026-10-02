# Matriz de planos e funcionalidades

## Objetivo

Esta matriz organiza os planos comerciais e suas funcionalidades por contexto
de uso. No Fokus Law, somente os cinco modulos principais sao comerciais;
capacidades especificas sao variantes internas e devem ser aprovadas antes de
alterar migrations, seeders ou registros no banco.

O catalogo deve preferir um modulo tecnico unico com variantes de contexto, regras, permissoes e limites. Uma variante comercial nao significa automaticamente um novo modulo no banco.

Legenda:

- `X`: incluido no plano sugerido;
- vazio: nao incluido por padrao, mas pode ser contratado se estiver disponivel;
- `Existente`: o modulo-base ja existe no catalogo tecnico atual;
- `Novo`: o modulo-base precisa ser cadastrado ou implementado.

Quando um modulo existente aparece com uma variante de contexto, isso significa que a base tecnica pode ser reutilizada, mas suas regras, campos, permissoes e limites ainda precisam ser configurados para aquele modelo.

## Fokus Law

O catalogo Law comercializa exclusivamente `processos`, `contatos`,
`expedicoes`, `tarefas` e `audiencias`. Os segmentos comerciais sao `advocacia` e
`setor_publico`; as variantes abaixo representam contexto e capacidade
do mesmo modulo tecnico, nao novos modulos comerciais.

### Modulos processuais e de relacionamento

| Modulo tecnico | Variante/contexto | Situacao | Advocacia | Cartorio Criminal | Cartorio Civel | Gestao de Audiencias | Gestao de Expedientes |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| `processos` | Gestao de Processos - Advocacia | Existente | X |  |  |  |  |
| `processos` | Gestao de Processos - Cartorio Criminal | Existente |  | X |  |  |  |
| `processos` | Gestao de Processos - Cartorio Civel | Existente |  |  | X |  |  |
| `processos` | Gestao de Processos - Audiencias | Existente |  |  |  | X |  |
| `processos` | Gestao de Processos - Expedientes | Existente |  |  |  |  | X |
| `contatos` | Gestao de Contatos - Cartorios | Existente |  | X | X | X | X |
| `contatos` | Gestao de Contatos - Advocacia | Existente | X |  |  |  |  |

### Gestao de Expedicoes

| Modulo tecnico | Capacidades de contexto | Advocacia | Cartorio Criminal | Cartorio Civel | Gestao de Audiencias | Gestao de Expedientes |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `expedicoes` | Oficios, mandados, cartas, editais, guias e atos ordinatorios |  | X | X |  | X |

O plano Advocacia nao inclui expedicao institucional. Portanto, `expedicoes`
nao faz parte da oferta sugerida de Advocacia e somente pode ser considerado em
uma composicao personalizada se houver uma necessidade especifica validada.

Na v1 cartoraria, cartas recebidas devem ser tratadas como classe de processo,
nao como expediente separado. Por isso, o controle de cartas recebidas deve
pertencer ao modulo `processos`, preservando classe, origem, dados processuais e
acompanhamento operacional do processo recebido.

### Instancias de expedicao por setor

O modulo de expedicoes nao deve ser tratado como uma unica fila rigida por
unidade. Uma mesma unidade cartoraria pode possuir varias instancias do modulo
`expedicoes`, uma por setor ou origem operacional, por exemplo:

- Cartorio;
- Gabinete;
- outros setores cadastrados pela unidade.

Cada instancia deve possuir, no minimo, identificacao do setor, tipos aceitos,
responsaveis, sequencia de numeracao quando aplicavel, prefixo ou serie, regras
de permissao, fluxo de aprovacao, controles e historico proprios. A matriz
indica a disponibilidade do recurso; a quantidade de instancias deve ser
configuravel conforme a unidade.

### Gestao de Tarefas

| Modulo tecnico | Capacidades de contexto | Advocacia | Cartorio Criminal | Cartorio Civel | Gestao de Audiencias | Gestao de Expedientes |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `tarefas` | Tarefas, fluxos, receitas, prazos, alertas e pendencias | X | X | X | X | X |

### Gestao de Audiencias

| Modulo tecnico | Variante/contexto | Advocacia | Cartorio Criminal | Cartorio Civel | Juizado | Orgao Publico |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `audiencias` | Gestao de Audiencias | X | X | X | X | X |
| `audiencias_externo` | Acompanhamento Externo de Audiencias |  | X | X | X | X |

As demais capacidades jurídicas, como custas, documentos, assinatura,
honorários, financeiro, presos, monitoramento, medidas, penas, relatórios,
notificações, prazos e agenda, pertencem ao contexto funcional dos cinco
módulos e não devem ser cadastradas como módulos Law independentes.

## Fokus Lead One

A linha One evolui por capacidade crescente para corretores independentes.

| Modulo tecnico | Variante/contexto | Situacao | Essencial | Profissional | Avancado | Premium |
| --- | --- | --- | ---: | ---: | ---: | ---: |
| `pessoas` | Gestao de Pessoas - Corretor | Existente | X | X | X | X |
| `imoveis` | Gestao de Imoveis - Corretor | Existente | X | X | X | X |
| `empreendimentos` | Gestao de Empreendimentos - Corretor | Existente |  | X | X | X |
| `leads` | Gestao de Leads - Corretor | Novo |  | X | X | X |
| `funil` | Funil de Vendas - Corretor | Existente |  | X | X | X |
| `website` | Integracao com Website | Existente |  | X | X | X |
| `relatorios` | Relatorios de Transacoes | Existente |  |  | X | X |
| `whatsapp` | Integracao com WhatsApp | Existente |  |  |  | X |
| `notificacoes` | Notificacoes Comerciais | Novo | X | X | X | X |

## Fokus Lead Team

A linha Team deve possuir recursos de colaboracao e governanca de equipes, alem dos modulos comerciais compartilhados com o One.

| Modulo tecnico | Variante/contexto | Situacao | Team Essencial | Team Premium |
| --- | --- | --- | ---: | ---: |
| `pessoas` | Gestao de Pessoas - Equipe | Existente | X | X |
| `imoveis` | Gestao de Imoveis - Equipe | Existente | X | X |
| `empreendimentos` | Gestao de Empreendimentos - Equipe | Existente | X | X |
| `leads` | Gestao de Leads - Equipe | Novo |  | X |
| `funil` | Funil de Vendas - Equipe | Existente | X | X |
| `website` | Integracao com Website | Existente | X | X |
| `relatorios` | Relatorios de Transacoes | Existente |  | X |
| `whatsapp` | Integracao com WhatsApp | Existente |  | X |
| `portal_imoveis` | Portal de Imoveis | Novo |  | X |
| `equipes` | Gestao de Equipes | Novo | X | X |
| `colaboracao` | Colaboracao entre Corretores | Novo | X | X |
| `permissoes` | Permissoes por Funcao | Novo | X | X |
| `distribuicao_leads` | Distribuicao de Leads | Novo |  | X |
| `visao_gerencial` | Visao Gerencial | Novo |  | X |
| `filiais` | Gestao de Filiais | Novo |  | X |
| `relatorios_gerenciais` | Relatorios Gerenciais | Novo | X | X |
| `notificacoes` | Notificacoes de Equipe | Novo | X | X |

## Composicao personalizada

Uma composicao personalizada pode combinar apenas os cinco modulos Law,
respeitando contexto, dependencias, limites e permissoes. Capacidades internas
nao criam planos ou modulos comerciais permanentes.

## Regras de modelagem

- `processos`, `contatos`, `expedicoes`, `tarefas` e `audiencias` sao os unicos modulos tecnicos comerciais do Law;
- `processos` deve ser comercializado como Gestao de Processos e exibido internamente como Processos;
- `contatos` deve ser comercializado como Gestao de Contatos e exibido internamente como Contatos;
- partes processuais, advogados, instituicoes, orgaos e destinatarios devem ser papeis de contatos, nao modulos tecnicos separados;
- `expedicoes` deve permitir varias instancias por unidade e setor, com tipos, numeracao e controles independentes;
- oficios, mandados, cartas precatorias, cartas rogatorias, cartas de ordem, editais, guias de execucao e atos ordinatorios devem ser tipos do modulo `expedicoes`;
- cartas expedidas nao devem possuir numeracao propria interna na v1 cartoraria, salvo evolucao futura por tipo;
- cartas recebidas devem ser modeladas como classe de processo, nao como modulo tecnico separado de expediente na v1;
- guias de execucao comum e penal devem ser tipos ou capacidades de `expedicoes`, com campos, fluxos e regras proprios quando necessario;
- os nomes comerciais devem incluir Gestao de Audiencias; nos menus internos, usar apenas Contatos, Expedicoes, Tarefas e Audiencias;
- `Gestao de Prazos e Intimacoes` e `Controle de Prazos` devem compartilhar o mesmo nucleo tecnico, salvo se houver regras de negocio realmente distintas;
- `Relatorios` e `Relatorios Gerenciais` devem compartilhar o mesmo nucleo e variar por tipos de relatorio e permissao;
- uma variante externa de Audiencias deve possuir permissao de consulta sem permitir alteracoes indevidas;
- regras de prazo de Editais devem ser configuraveis por contexto `criminal` ou `civel`;
- a variante de Contatos para Advocacia deve priorizar clientes e advogados, enquanto a variante cartoraria deve priorizar partes processuais, orgaos e destinatarios;
- limites ajustados pelo cliente devem ser escolhidos entre opcoes cadastradas e recalcular o preco no backend.

## Pendencias antes da implementacao

- aprovar ou ajustar a inclusao de cada linha marcada com `X`;
- confirmar se `Custas e Recolhimentos` sera exclusivo do Civel ou compartilhado com Advocacia;
- definir limites e opcoes de capacidade de todos os modulos;
- definir as regras de prazo criminal e civel;
- detalhar campos e permissoes do acesso externo a Audiencias;
- cadastrar as funcionalidades marcadas como `Novo` antes de criar os planos;
- atualizar seeders, migrations, API, checkout e catalogo publico somente apos a aprovacao final.
