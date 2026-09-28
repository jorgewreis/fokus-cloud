# Seguranca e permissoes do Fokus Law

## Objetivo

Definir a politica completa de acesso da v1 do Fokus Law, cobrindo unidade
ativa, perfil Law, assinatura, modulo contratado, sigilo processual, Datajud,
exportacoes, logs e auditoria operacional.

Este documento complementa:

- [Fokus Law](../04-products/fokus-law.md);
- [Requisitos do Fokus Law](../05-requirements/fokus-law.md);
- [Modelo de dados do Fokus Law](../06-data/fokus-law-data-model.md);
- [Modelo de permissoes e perfis](permission-model.md);
- [Politica de controle de acesso](access-control-policy.md).

## Principios

- Nenhum dado do Fokus Law pode ser carregado sem empresa ativa.
- Nenhum recurso Law pode ser acessado sem assinatura valida do produto.
- Nenhuma funcionalidade pode ser usada sem modulo contratado.
- Unidade juridica e fronteira operacional obrigatoria.
- Perfil Law e avaliado por unidade, nao apenas por empresa.
- Sigilo processual prevalece sobre permissao geral de modulo.
- Interface nunca substitui autorizacao no backend.
- Backoffice nao acessa dados operacionais do Fokus Law na v1.
- Logs e auditoria devem minimizar dados pessoais e dados de processo sigiloso.

## Ordem obrigatoria de autorizacao

Toda rota, action, endpoint, job acionado por usuario e consulta protegida do
Fokus Law deve validar, nesta ordem:

1. autenticacao do usuario;
2. empresa ativa selecionada;
3. assinatura/produto Fokus Law ativo;
4. unidade Law ativa;
5. vinculo Law ativo do usuario na unidade;
6. modulo contratado;
7. permissao do perfil Law;
8. regra de sigilo do registro.

Qualquer falha deve resultar em negacao. Quando a diferenca entre recurso
inexistente e recurso proibido puder facilitar enumeracao, a resposta deve ser
generica.

## Empresa ativa

Usuario autenticado sem empresa ativa selecionada deve ser bloqueado antes de
qualquer consulta Law e redirecionado para selecao de empresa ativa.

O sistema nao deve escolher automaticamente uma empresa para acessar dados
juridicos.

## Assinatura e modulo contratado

Empresa ativa sem assinatura valida do produto Fokus Law deve ter acesso ao
produto bloqueado. A tela pode informar indisponibilidade ou contratacao, mas
nao deve carregar dados operacionais Law.

Assinatura ativa sem modulo especifico contratado deve bloquear:

- rota;
- endpoint;
- action;
- dados do modulo;
- exportacao do modulo.

Esse bloqueio se aplica mesmo quando o usuario possui perfil operacional capaz
de usar a funcionalidade.

## Unidade Law e vinculo ativo

Usuario sem vinculo ativo na unidade Law selecionada deve ser bloqueado e deve
selecionar outra unidade autorizada.

Toda rota/API deve validar:

- `company_id`;
- `law_unit_id`;
- vinculo Law ativo;
- permissao necessaria.

Essa regra protege contra acesso direto por URL/API a recurso de outra unidade.
ULID ou ID dificil de adivinhar nao e controle de seguranca suficiente.

## Perfis Law

### Modelo aplicado nesta etapa

O acesso é avaliado a partir do administrador global da empresa ou do perfil
associado ao vínculo ativo do usuário com o setor selecionado. O administrador
global mantém acesso global ao Law; os perfis locais e personalizados valem
somente para seu setor. A API reavalia permissões no servidor em cada chamada.

O catálogo em `customer_permissions`, os perfis em `law_access_roles`, a
relação `law_access_role_permissions` e os vínculos `law_unit_memberships`
implementam o escopo por setor. Os perfis protegidos são `unit_admin`,
`chief_clerk`, `operator` e `viewer`; somente perfis personalizados podem ter
seu conjunto de permissões editado. Administradores locais podem conceder
apenas permissões que possuem, e nenhum perfil local pode criar ou atribuir
`unit_admin`. `chief_clerk` pode atribuir perfis aprovados sem editar suas
permissões.

Na migração, vínculos ativos `gestor` viram `chief_clerk` e `usuario` vira
`operator` em todos os setores Law ativos existentes. Setores novos recebem
somente atribuições explícitas. A administração interna do Backoffice permanece
nas tabelas e permissões `platform_*`.

Os controles de confidencialidade descritos neste documento continuam sendo
política alvo do domínio Law. Autorização por registro confidencial não faz
parte desta etapa de implementação.

### Perfis e matriz alvo do produto

Os perfis Law da v1 sao:

- `unit_admin`;
- `chief_clerk`;
- `operator`;
- `viewer`.

Administrador da unidade pode criar e editar perfis personalizados dentro das
permissões que possui. Chefe/escrivão pode atribuir perfis permitidos, suspender
ou remover vínculos da unidade, mas não edita permissões dos perfis. Essas ações
devem gerar auditoria e invalidar imediatamente a autorização anterior.

O sistema nao deve permitir remover ou suspender o ultimo administrador ativo da
unidade.

Gestao de Processos e o nome comercial do modulo `processos`; no menu interno, o
rotulo deve ser `Processos`.

Gestao de Contatos e o nome comercial do modulo `contatos`; no menu interno, o
rotulo deve ser `Contatos`. Contatos vinculados a processos sigilosos devem
obedecer ao nivel de sigilo do processo no contexto de consulta.

Gestao de Audiencias e o nome comercial do modulo `audiencias`; no menu interno,
o rotulo deve ser `Audiencias`. Audiencias, participantes, alertas e acessos
externos obedecem assinatura, unidade ativa, permissao do perfil e sigilo do
processo relacionado.

## Matriz de permissoes

| Acao | `unit_admin` | `chief_clerk` | `operator` | `viewer` |
| --- | ---: | ---: | ---: | ---: |
| Acessar unidade Law | Sim | Sim | Sim | Sim |
| Gerenciar usuarios da unidade | Sim | Sim | Nao | Nao |
| Alterar perfil Law | Sim | Sim | Nao | Nao |
| Suspender/remover vinculo Law | Sim | Sim | Nao | Nao |
| Gerenciar sigilo de processo | Sim | Sim | Nao | Nao |
| Conceder/revogar acesso a processo sigiloso | Sim | Sim | Nao | Nao |
| Criar e editar processos nao sigilosos | Sim | Sim | Sim | Nao |
| Cancelar/inativar processos | Sim | Sim | Nao | Nao |
| Visualizar processos nao sigilosos | Sim | Sim | Sim | Sim |
| Criar e editar contatos | Sim | Sim | Sim | Nao |
| Inativar ou mesclar contatos | Sim | Sim | Nao | Nao |
| Consultar documentos, canais pessoais, residência e notas | Por permissão sensível | Por permissão sensível | Nao por padrão | Nao |
| Consultar contatos compartilhados recebidos | Sim | Sim | Sim | Sim |
| Configurar compartilhamento com empresas | Administrador da empresa; delegável | Conforme permissão concedida | Conforme permissão concedida | Nao |
| Vincular contatos a processos ou expedicoes | Sim | Sim | Sim | Nao |
| Criar e editar expedicoes | Sim | Sim | Sim | Nao |
| Cancelar expedicoes | Sim | Sim | Nao | Nao |
| Configurar tipos e instancias de expedicao | Sim | Sim | Nao | Nao |
| Criar e editar audiencias | Sim | Sim | Sim | Nao |
| Alterar status de audiencias | Sim | Sim | Sim | Nao |
| Gerenciar acessos externos de audiencias | Sim | Sim | Nao | Nao |
| Criar e editar prazos/pendencias | Sim | Sim | Sim | Nao |
| Concluir prazos/pendencias | Sim | Sim | Sim | Nao |
| Cancelar prazos/pendencias | Sim | Sim | Nao | Nao |
| Configurar receitas operacionais | Sim | Sim | Nao | Nao |
| Ver dashboard operacional | Sim | Sim | Sim | Sim |
| Ver auditoria operacional | Sim | Sim | Nao | Nao |
| Exportar dados visiveis | Sim | Sim | Sim | Sim |

Permissao de perfil nao ignora assinatura, modulo contratado, unidade ativa nem
sigilo processual.

As permissões do recurso `contacts` (consulta, criação, edição, inativação,
dados sensíveis, mesclagem, consulta compartilhada e gestão de compartilhamento)
só aparecem no seletor de perfis quando a assinatura ativa contém o módulo
`contatos`. As APIs repetem essa validação em cada chamada. CPF/CNPJ e demais
documentos são cifrados; consultas sem `law.contacts.sensitive.view` recebem
documentos mascarados, não recebem notas nem residência e não podem alterar
esses valores indiretamente por meio de campos mascarados. O mesmo controle se
aplica a canais pessoais. Auditoria registra a política e suas revogações na
empresa proprietária.

## Niveis de sigilo processual

O modelo alvo deve usar niveis de sigilo processual:

- `public_internal`: publico para usuarios autorizados da unidade;
- `unit_restricted`: restrito a perfis operacionais da unidade;
- `case_confidential`: sigiloso com autorizacao especifica por processo;
- `enhanced_confidential`: sigilo reforcado quando a regra da unidade exigir.

Processos com nivel `case_confidential` ou `enhanced_confidential` exigem
autorizacao explicita por processo, alem de perfil e vinculo na unidade.
Contatos vinculados ao processo devem seguir a mesma restricao quando exibidos
nesse contexto.

A autorizacao deve ser registrada em `law_confidential_case_accesses`, vinculada
a processo, usuario/vinculo Law, nivel de acesso, concedente, motivo e validade
opcional.

Niveis de acesso:

- `view`: permite visualizar dados completos do processo sigiloso;
- `operate`: permite operar entidades filhas permitidas pelo perfil;
- `manage_confidentiality`: permite gerenciar acesso e sigilo quando combinado
  com perfil autorizado.

Administrador da unidade e chefe/escrivao podem conceder e revogar acesso a
qualquer processo em nivel sigiloso da unidade.

## Mascaramento de processo sigiloso

Usuarios sem autorizacao explicita podem ver processo sigiloso mascarado em
listas e buscas quando a politica do nivel permitir item mascarado.

O item mascarado pode exibir apenas:

- indicacao "processo sigiloso";
- classe generica;
- unidade;
- status operacional generico;
- datas nao sensiveis.

Nao deve exibir:

- numero completo do processo;
- contatos e partes;
- assunto;
- observacoes;
- expedientes;
- prazos e pendencias;
- responsaveis;
- dados Datajud;
- tags informativas;
- qualquer dado que identifique o caso.

## Heranca de sigilo

Partes, expedicoes, tarefas, audiencias, prazos e pendencias vinculados a processo
sigiloso herdam o sigilo do processo.

Para visualizar ou operar entidade filha de processo sigiloso, o usuario deve
possuir:

- vinculo ativo na unidade;
- modulo contratado;
- permissao do perfil para a acao;
- acesso explicito ao processo sigiloso em nivel suficiente.

## Auditoria de sigilo

Na v1, leitura de processo sigiloso nao sera auditada.

Devem ser auditadas obrigatoriamente:

- marcacao de processo como sigiloso;
- remocao de sigilo;
- alteracao de nivel de sigilo;
- concessao de acesso a processo sigiloso;
- revogacao de acesso a processo sigiloso.

Esses eventos devem registrar usuario, unidade, processo, acao, valores antes e
depois quando aplicavel, data/hora, motivo e origem tecnica minima.

## Exportacoes

Exportacao e permitida para qualquer perfil que possa visualizar os dados.

Formatos permitidos na v1:

- CSV;
- XLSX;
- PDF.

Exportacoes devem respeitar autorizacao por processo. Dados sigilosos so podem
entrar na exportacao quando o usuario possuir acesso explicito ao processo
sigiloso.

Toda exportacao deve gerar auditoria com:

- usuario;
- empresa;
- unidade;
- filtros aplicados;
- formato;
- quantidade resumida de registros;
- data/hora;
- indicacao se incluiu dados sigilosos.

## Datajud

Consulta Datajud na v1 sera automatica, sem acao manual do usuario e sem area
visivel de erro na interface operacional.

A sincronizacao deve ocorrer quando o processo for criado ou movimentado. O job
de sincronizacao deve respeitar:

- escopo por empresa e unidade;
- tentativas limitadas;
- rate limit;
- logs sanitizados;
- registro resumido em `law_datajud_syncs`;
- divergencias em `law_datajud_divergences`, quando aplicavel.

Falha do Datajud nao bloqueia criacao, edicao ou movimentacao interna de
processo.

Processos sigilosos podem gerar erro ou retorno vazio no Datajud. Nesses casos,
o sistema deve:

- executar apenas tentativas limitadas;
- registrar erro ou vazio de forma sanitizada;
- nao alterar dados internos quando nao houver retorno confiavel;
- nao criar bloqueio operacional;
- nao criar acao adicional obrigatoria para o usuario.

Detalhes de suporte orientativo e incidentes operacionais da unidade estao em
[Operacao do Fokus Law](../09-operations/fokus-law-operations.md).

## Cache e invalidacao

Permissoes podem usar cache curto por sessao/requisicao.

O cache deve ser invalidado imediatamente quando houver:

- alteracao de vinculo Law;
- alteracao de perfil Law;
- suspensao ou remocao de usuario da unidade;
- alteracao de modulo contratado;
- alteracao de assinatura;
- concessao ou revogacao de acesso a processo sigiloso;
- alteracao de sigilo de processo.

Cache nunca deve ser usado para autorizar acesso a dado sigiloso sem revalidar
as condicoes do registro.

## Logs tecnicos

Logs tecnicos do Fokus Law devem ser sanitizados e retidos por 7 dias.

Nao registrar:

- dados pessoais completos;
- conteudo de processo sigiloso;
- partes completas quando nao necessario;
- observacoes internas;
- payload bruto Datajud;
- tokens, senhas ou credenciais;
- documentos exportados.

Registrar apenas o minimo necessario:

- IDs internos;
- correlation ID;
- tipo da operacao;
- status tecnico;
- erro resumido;
- data/hora;
- origem tecnica.

## Auditoria operacional

A auditoria operacional do Fokus Law deve ser mantida por 180 dias.

Apos esse periodo, historico essencial deve permanecer nos proprios registros do
dominio quando necessario para preservar contexto operacional, como status atual,
datas de envio, encerramento, cancelamento, motivo de cancelamento e responsavel
atual.

Eventos minimos:

- alteracao de perfil Law;
- suspensao ou remocao de vinculo Law;
- criacao, edicao, cancelamento e inativacao de processo;
- marcacao/remocao de sigilo;
- concessao/revogacao de acesso sigiloso;
- criacao, edicao, cancelamento e mudanca de status de expedicao;
- configuracao de tipos e instancias de expedicao;
- criacao, edicao, cancelamento e mudanca de status de tarefa;
- configuracao de receitas operacionais;
- criacao, edicao, cancelamento e mudanca de status de prazo/pendencia;
- exportacao de dados;
- aplicacao de metadados Datajud;
- resolucao de divergencia Datajud.

## Backoffice

Backoffice nao acessa dados operacionais do Fokus Law na v1.

O Backoffice pode acessar apenas:

- informacoes comerciais;
- assinatura;
- billing;
- vouchers;
- alertas tecnicos agregados;
- auditoria da plataforma.

Dados juridicos do cliente, processos, contatos, partes, expedientes, prazos,
pendencias e conteudo sigiloso nao devem ser expostos ao Backoffice na v1.

## Testes obrigatorios

- Usuario sem empresa ativa e redirecionado antes de carregar dados Law.
- Empresa sem assinatura valida nao carrega dados Law.
- Modulo nao contratado bloqueia rota, action e dados.
- Usuario sem vinculo Law ativo nao acessa unidade.
- Usuario de uma unidade nao acessa recurso de outra por URL/API.
- Perfil sem permissao nao executa acao mesmo com modulo contratado.
- Processo sigiloso aparece mascarado para usuario sem autorizacao.
- Entidades filhas de processo sigiloso nao vazam dados em listas alternativas.
- Concessao e revogacao de acesso sigiloso sao auditadas.
- Leitura de processo sigiloso nao gera auditoria na v1.
- Exportacao respeita sigilo e registra auditoria.
- Datajud falho nao bloqueia criacao ou movimentacao interna.
- Logs nao registram payload bruto Datajud nem conteudo sigiloso.
- Alteracao de perfil, vinculo, modulo ou acesso sigiloso invalida cache.

## Criterios de aceite

- A ordem obrigatoria de autorizacao esta implementada no backend.
- A interface nao e considerada controle de seguranca.
- Sigilo processual e aplicado em processo e entidades filhas.
- Itens mascarados nao exibem dados identificadores.
- Exportacoes em CSV, XLSX e PDF respeitam permissao e sigilo.
- Datajud automatico possui tentativas limitadas e nao bloqueia operacao interna.
- Logs tecnicos sao sanitizados e retidos por 7 dias.
- Auditoria operacional e retida por 180 dias.
- Backoffice nao acessa dados operacionais do Fokus Law na v1.
