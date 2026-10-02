# Requisitos da Gestão de Contatos

## Requisitos funcionais

| Código | Requisito | Critério de aceite |
| --- | --- | --- |
| RF-CTT-001 | Disponibilizar o módulo apenas a empresas com Contatos contratado. | Menu, APIs e dashboard exigem assinatura ativa com o componente publicado. |
| RF-CTT-002 | Criar e consultar pessoas, organizações e unidades. | Pessoa exige natureza PF; organização exige natureza PJ; unidade é registro próprio sem natureza PF/PJ. |
| RF-CTT-003 | Aplicar formatação consistente aos nomes. | Servidor normaliza iniciais, conectivos e siglas preservadas antes de gravar. |
| RF-CTT-004 | Gerenciar profissões e vínculos profissionais reutilizáveis. | PF pode selecionar profissões do catálogo da empresa ou sugestões contextuais; PJ e unidade não exibem esse campo. |
| RF-CTT-005 | Guardar canais próprios para cada tipo de registro. | Até 4 telefones e 2 e-mails por pessoa, organização ou unidade; tipo e rótulo são editáveis e e-mail é validado. |
| RF-CTT-006 | Guardar endereços completos com busca de CEP. | Até dois endereços por registro; consulta ViaCEP preenche dados disponíveis, permite preenchimento manual se falhar e exige logradouro, município e UF. |
| RF-CTT-007 | Guardar documentos conforme a natureza. | Até quatro por contato; PF aceita CPF e PJ aceita CNPJ e inscrição estadual com UF obrigatória. CPF/CNPJ são validados, criptografados e únicos na empresa. |
| RF-CTT-008 | Manter unidades e hierarquia organizacional. | Organização/unidade pode ter um pai imediato da mesma empresa, pai pode ter vários filhos e níveis são ilimitados; unidade possui canais e endereço próprios. |
| RF-CTT-009 | Classificar contatos com tags. | Até seis tags por contato, reutilizadas e normalizadas na empresa. |
| RF-CTT-010 | Pesquisar e filtrar contatos. | Filtros por texto, tipo de cadastro, PF/PJ, profissão vinculada a pelo menos um contato e tag; documentos só pesquisáveis com permissão sensível. |
| RF-CTT-011 | Evitar duplicidade de CPF/CNPJ. | CPF/CNPJ repetidos na empresa retornam conflito; mesclagem é fluxo separado. |
| RF-CTT-012 | Editar e excluir contatos. | A exclusão é lógica, preserva auditoria e encerra os vínculos históricos da PF. |
| RF-CTT-013 | Mesclar duplicados. | Exige permissão, mesma natureza, destino válido e motivo; transfere relações em transação. |
| RF-CTT-014 | Resumir atividade e volume no dashboard. | Exibe totais da empresa, utilização contratada e até cinco últimos contatos do usuário ativo. |
| RF-CTT-015 | Contabilizar capacidade. | Cada registro, inclusive unidade, consome uma unidade; criação excedente é bloqueada. |
| RF-CTT-016 | Controlar acesso a dados sensíveis. | Sem a permissão, documentos são mascarados, canais pessoais/endereço residencial/notas ocultos e preservados em edição. |
| RF-CTT-017 | Compartilhar contatos entre empresas por acordo bilateral. | Compartilhamento é desativado por padrão; as duas empresas precisam configurar regras recíprocas de saída por natureza, profissão vinculada para PF e campos permitidos. |
| RF-CTT-018 | Expor referência compartilhada somente para leitura. | Destino precisa de assinatura Contatos; não altera origem nem consome sua capacidade. |
| RF-CTT-019 | Restringir campos de compartilhamento. | Canais pessoais e endereços residenciais nunca são expostos; documentos exigem seleção da origem e permissão sensível no destino. |
| RF-CTT-020 | Registrar atividade e auditoria. | Ações identificam usuário, contato, tipo e data; consultas não guardam termo de busca. |
| RF-CTT-021 | Preservar IDs estáveis dos cadastros. | Alterações de contexto e de hierarquia mantêm IDs e vínculos existentes. |
| RF-CTT-022 | Relacionar pessoas físicas e jurídicas. | Uma PF pode se vincular a várias PJs e cada PJ a várias PFs; o vínculo é recíproco e restrito à empresa proprietária. |
| RF-CTT-023 | Identificar pessoa jurídica por sigla. | Sigla opcional é editável e aparece junto ao nome e na ficha da PJ. |
| RF-CTT-024 | Registrar papéis em vínculos PF↔PJ. | Um vínculo aceita vários papéis padronizados, complemento em Outro e início/término por papel; vigência decorre das datas e não concede permissões. |
| RF-CTT-025 | Manter histórico de cargo, posto, graduação ou função no vínculo. | Cada designação aceita nome livre, sugestões reutilizáveis por empresa e período independente; várias podem estar vigentes ao mesmo tempo. |
| RF-CTT-026 | Guardar dados institucionais por tipo. | Tipo institucional tem uma opção principal e várias secundárias; CNJ, esfera, códigos e sistema emissor são opcionais e ausência gera aviso não bloqueante. |
| RF-CTT-027 | Sugerir possíveis duplicidades. | E-mail/telefone profissional ou código institucional coincidente gera sugestão; nome só reforça quando semelhante; não exibe valor, não bloqueia nem mescla automaticamente. CPF/CNPJ repetido continua conflito. |
| RF-CTT-028 | Exibir qualidade cadastral na visão geral de Contatos. | Contagens de canais ausentes e dados institucionais incompletos abrangem apenas contatos próprios ativos; análise de duplicidades é acionada sob demanda e paginada. |
| RF-CTT-029 | Configurar contexto cadastral da empresa. | Há um contexto ativo por empresa; administrador confere prévia dos rótulos/sugestões antes de trocar, sem perda ou recriação de registros e vínculos. |

## Regras de negócio

- Empresa é proprietária da base e os setores autorizados consultam os mesmos
  contatos; `law_unit_id` não define propriedade.
- Contexto ativo por empresa: Advocacia/Escritório (`escritorio`), Poder Público/Órgão Público (`orgao_publico`) ou Poder Público jurídico/Judiciário (`judiciario`).
- O tipo de contato é sempre Pessoa física ou Pessoa jurídica. Os rótulos de organização e unidade variam pelo contexto, mas não substituem PF/PJ.
- Unidade é registro independente, não PF nem PJ. Organização/unidade aceita um pai imediato e vários filhos; somente a mesma empresa pode ser relacionada.
- Valores legados sem correspondência ficam preservados e marcados para revisão.
- PF/PJ é natureza cadastral. Unidade judiciária é um tipo institucional.
- Sigla é opcional para PJ; profissão/vínculo é campo de PF. CNPJ e inscrição
  estadual são documentos de PJ; a inscrição estadual exige UF.
- Relações PF↔PJ são muitos-para-muitos, recíprocas e internas à empresa
  proprietária; referências compartilhadas não expõem essas relações.
- Unidades não são departamentos embutidos: cada uma soma um cadastro à capacidade. A exclusão de um pai exige realocar ou excluir seus filhos antes.
- Exclusão lógica remove o registro da base ativa e libera capacidade, preservando a trilha de auditoria.
- Tags não substituem natureza cadastral ou permissão.
- Papéis e designações PF↔PJ guardam períodos separados; vínculos legados
  permanecem válidos sem inferir papéis ou cargos que não foram informados.
- A qualidade e duplicidades não incluem contatos excluídos nem compartilhados;
  consulta ao painel usa `law.contacts.view`, e a edição continua protegida pelas
  permissões já existentes.

## Integridade e compartilhamento

- CPF/CNPJ devem passar validação dos dígitos e comparação por fingerprint.
- Nome, telefone e e-mail podem gerar análise manual futura, mas não bloqueiam
  o cadastro por si sós.
- Compartilhamento é desativado por padrão e só ocorre com políticas ativas
  recíprocas entre as empresas, ambas com assinatura ativa de Contatos.
- Cada empresa escolhe separadamente PF/PJ e campos que disponibiliza; PF
  exige ao menos uma profissão vinculada a contato. Contatos compartilhados
  continuam sob controle da origem; a revogação de qualquer lado remove o
  acesso na consulta seguinte.
- Tags, notas, buscas, auditorias e vínculos internos não são compartilhados.

## Evoluções futuras

Importação/exportação em lote será especificada em uma evolução futura do
módulo.

## Permissões

| Código | Ação | Padrão |
| --- | --- | --- |
| `law.contacts.view` | Consultar lista/detalhe | Administrador, chefe/escrivão, operador, visualizador |
| `law.contacts.create` | Criar contato | Administrador, chefe/escrivão, operador |
| `law.contacts.update` | Alterar dados comuns | Administrador, chefe/escrivão, operador |
| `law.contacts.delete` | Excluir | Administrador e chefe/escrivão |
| `law.contacts.sensitive.view` | Consultar/alterar documentos, dados pessoais e notas | Administrador e chefe/escrivão |
| `law.contacts.merge` | Mesclar duplicados | Administrador e chefe/escrivão |
| `law.contacts.shared.view` | Consultar referências compartilhadas | Perfis que têm consulta |
| `law.contacts.share.manage` | Administrar políticas de compartilhamento | Administrador da empresa; delegável a perfil personalizado |

Permissões são concedidas por perfil customizável e suas opções só devem ser
apresentadas para assinaturas que incluem Contatos. Ser usuário administrador
da empresa não remove a checagem da assinatura do produto.

## Requisitos não funcionais e privacidade

- Dados sensíveis são protegidos em trânsito e em repouso, mascarados na
  resposta e omitidos de logs e mensagens de erro.
- As operações que sincronizam filhos ou mesclam contatos são transacionais.
- Todas as consultas limitam escopo pela empresa; compartilhamento é o único
  caminho entre empresas e segue política explícita.
- A interface apresenta estados de carregamento, sucesso, vazio, erro e limite
  de capacidade, com layout adaptado a desktop, tablet e celular.
- Não guardar os termos digitados na busca no histórico de atividade.

## Fora do escopo desta entrega

Importação em lote, busca externa de pessoas, notificações a terceiros, CRM e
sincronização de agendas.
