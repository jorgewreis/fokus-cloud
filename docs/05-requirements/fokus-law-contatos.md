# Requisitos da Gestão de Contatos

## Requisitos funcionais

| Código | Requisito | Critério de aceite |
| --- | --- | --- |
| RF-CTT-001 | Disponibilizar o módulo apenas a empresas com Contatos contratado. | Menu, APIs e dashboard exigem assinatura ativa com o componente publicado. |
| RF-CTT-002 | Criar e consultar contatos PF ou PJ. | Natureza e nome são obrigatórios; o formulário adapta os campos à natureza. |
| RF-CTT-003 | Aplicar formatação consistente aos nomes. | Servidor normaliza iniciais, conectivos e siglas preservadas antes de gravar. |
| RF-CTT-004 | Gerenciar profissões e vínculos profissionais reutilizáveis. | PF pode selecionar profissões cadastradas ou incluir nova especificação; PJ não exibe esse campo. |
| RF-CTT-005 | Guardar canais do contato e dos departamentos PJ. | Até 4 telefones e 2 e-mails em cada escopo; validar e-mails. |
| RF-CTT-006 | Guardar endereços completos com busca de CEP. | Até dois endereços; consulta ViaCEP preenche dados disponíveis, permite preenchimento manual se falhar e exige logradouro, município e UF. |
| RF-CTT-007 | Guardar documentos PF. | Até quatro documentos por PF; CPF/CNPJ opcionais, validados, criptografados e únicos na empresa; PJ não exibe documentos. |
| RF-CTT-008 | Guardar departamentos associados à PJ. | Cada departamento tem nome e até 4 telefones e 2 e-mails próprios. |
| RF-CTT-009 | Classificar contatos com tags. | Até seis tags por contato, reutilizadas e normalizadas na empresa. |
| RF-CTT-010 | Pesquisar e filtrar contatos. | Filtros por texto, natureza, status, classificação e tag; documentos só pesquisáveis com permissão sensível. |
| RF-CTT-011 | Evitar duplicidade de CPF/CNPJ. | CPF/CNPJ repetidos na empresa retornam conflito; mesclagem é fluxo separado. |
| RF-CTT-012 | Editar e inativar contatos. | Alterações e inativação preservam auditoria e vínculos históricos. |
| RF-CTT-013 | Mesclar duplicados. | Exige permissão, mesma natureza, destino válido e motivo; transfere relações em transação. |
| RF-CTT-014 | Resumir atividade e volume no dashboard. | Exibe totais da empresa, utilização contratada e até cinco últimos contatos do usuário ativo. |
| RF-CTT-015 | Contabilizar capacidade. | Cada contato e cada departamento consome uma unidade; criação excedente é bloqueada. |
| RF-CTT-016 | Controlar acesso a dados sensíveis. | Sem a permissão, documentos são mascarados, canais pessoais/endereço residencial/notas ocultos e preservados em edição. |
| RF-CTT-017 | Compartilhar contatos entre empresas por política. | Origem escolhe destinatários ativos e classificações; regras cobrem atuais/futuros e podem ser revogadas. |
| RF-CTT-018 | Expor referência compartilhada somente para leitura. | Destino precisa de assinatura Contatos; não altera origem nem consome sua capacidade. |
| RF-CTT-019 | Restringir campos de compartilhamento. | Canais pessoais e endereços residenciais nunca são expostos; documentos exigem seleção da origem e permissão sensível no destino. |
| RF-CTT-020 | Registrar atividade e auditoria. | Ações identificam usuário, contato, tipo e data; consultas não guardam termo de busca. |
| RF-CTT-021 | Preparar APIs de referência para outros módulos. | IDs estáveis e endpoints de consulta podem ser usados futuramente por Processos, Expedições e Tarefas. |
| RF-CTT-022 | Relacionar pessoas físicas e jurídicas. | Uma PF pode se vincular a várias PJs e cada PJ a várias PFs; o vínculo é recíproco e restrito à empresa proprietária. |
| RF-CTT-023 | Identificar pessoa jurídica por sigla. | Sigla opcional é editável e aparece junto ao nome e na ficha da PJ. |

## Regras de negócio

- Empresa é proprietária da base e os setores autorizados consultam os mesmos
  contatos; `law_unit_id` não define propriedade.
- PF/PJ é natureza cadastral; classificações podem acumular.
- Sigla é opcional para PJ; profissão/vínculo e documentos são campos de PF.
- Relações PF↔PJ são muitos-para-muitos, recíprocas e internas à empresa
  proprietária; referências compartilhadas não expõem essas relações.
- Departamentos só pertencem a PJ e cada um soma um cadastro à capacidade.
- Contatos inativos continuam ocupando capacidade e preservam histórico.
- Tags não substituem classificação ou permissão.
- CPF/CNPJ devem passar validação dos dígitos e comparação por fingerprint.
- Nome, telefone e e-mail podem gerar análise manual futura, mas não bloqueiam
  o cadastro por si sós.
- Compartilhamento exige assinatura ativa de origem e destino com Contatos
  publicado. Contatos compartilhados continuam sob controle exclusivo da
  origem; revogação remove o acesso na consulta seguinte.
- Tags, notas, buscas, auditorias e vínculos internos não são compartilhados.
- Sigilo processual também deve ser aplicado ao consultar um contato pelo
  contexto do processo quando a integração for implementada.

## Permissões

| Código | Ação | Padrão |
| --- | --- | --- |
| `law.contacts.view` | Consultar lista/detalhe | Administrador, chefe/escrivão, operador, visualizador |
| `law.contacts.create` | Criar contato | Administrador, chefe/escrivão, operador |
| `law.contacts.update` | Alterar dados comuns | Administrador, chefe/escrivão, operador |
| `law.contacts.delete` | Inativar/remover | Administrador e chefe/escrivão |
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

Vínculos transacionais com Processos, Expedições e Tarefas, importação em lote,
busca externa de pessoas, notificações a terceiros, CRM e sincronização de
agendas. As APIs e os IDs ficam preparados para integração futura.
