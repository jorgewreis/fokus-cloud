# Requisitos do Backoffice Fokus Cloud

## Identificacao

| Campo | Valor |
| --- | --- |
| Produto | Fokus Cloud |
| Modulo | Backoffice |
| Documento de produto | [Backoffice Fokus Cloud](../04-products/backoffice.md) |
| Status | Em definicao |

## Objetivo

Definir os requisitos necessarios para implementar a v1 do Backoffice como
centro de administracao interna da plataforma, com foco em catalogo comercial,
assinaturas, vouchers, indicadores financeiros, gestao comercial de empresas e
auditoria.

## Escopo

### Dentro do escopo

- Login interno separado, exibido no cartão administrativo da home por `/?acesso=administrativo`.
- Perfis internos `superadministrador` e `administrador_comercial`.
- Dashboard com operacao comercial, governanca, risco e indicadores
  financeiros.
- Administracao do catalogo comercial.
- Administracao de vouchers.
- Consulta e acoes controladas sobre assinaturas.
- Gestao comercial de empresas/clientes.
- Conciliacao com Mercado Pago.
- Auditoria detalhada das acoes sensiveis.

### Fora do escopo

- Alteracao de CPF, CNPJ, nome civil ou razao social.
- Gestao de usuarios clientes, vinculos e perfis do portal.
- Reset assistido de senha de clientes.
- Acesso operacional aos dados dos produtos Fokus Law ou Fokus Lead.
- Emissao fiscal.
- Configuracoes gerais da plataforma fora do dominio comercial.

## Requisitos funcionais

| Codigo | Requisito | Criterio de aceite |
| --- | --- | --- |
| RF-BO-001 | Permitir login interno no cartão administrativo da home (`/?acesso=administrativo`) com `platform_admins`, e-mail, senha e MFA obrigatorio por e-mail. | Uma conta de cliente em `users` nao autentica no Backoffice, e uma conta interna nao autentica no portal do cliente. |
| RF-BO-002 | Bloquear rigidamente conta interna apos tentativas invalidas conforme politica definida. | Conta bloqueada nao acessa o Backoffice ate desbloqueio por superadministrador, com auditoria. |
| RF-BO-003 | Permitir que superadministrador gerencie usuarios internos e perfis do Backoffice. | Administrador comercial nao consegue criar, alterar, bloquear ou desbloquear usuarios internos. |
| RF-BO-004 | Permitir que administrador comercial crie e edite produtos, planos, funcionalidades, precos e vouchers. | Produtos, funcionalidades e planos so podem ser editados em status pausado ou inativo. A edicao deixa o catalogo do produto pendente de republicacao. |
| RF-BO-005 | Permitir que superadministrador publique, pause e arquive itens comerciais. | Acoes exigem confirmacao explicita e geram auditoria. |
| RF-BO-006 | Validar dados do catalogo antes da publicacao. | Item incompleto, invalido, pausado, arquivado ou nao publicado nao aparece no catalogo publico nem no checkout. |
| RF-BO-007 | Permitir publicacao imediata do catalogo apos confirmacao explicita. | A nova versao libera o catalogo publico e o checkout apos ativacao e republicacao; enquanto houver pendencia, novas contratacoes ficam bloqueadas. |
| RF-BO-008 | Permitir consultar assinaturas, pagamentos, historico e snapshots comerciais. | A consulta exibe plano, produto, ciclo, vigencia, itens, valores, status e origem dos dados disponiveis. |
| RF-BO-009 | Permitir pausar, reativar, cancelar e trocar plano de uma assinatura. | Toda acao exige autorizacao, motivo quando afetar cliente ou cobranca, e auditoria. |
| RF-BO-010 | Aplicar upgrade imediatamente e agendar downgrade ou cancelamento para fim da vigencia. | O status da assinatura e o historico registram vigencia, tipo de mudanca e data prevista. |
| RF-BO-011 | Recalcular alteracoes de assinatura pelo catalogo publicado. | Valores enviados pela interface nao sao aceitos como fonte de preco. |
| RF-BO-012 | Permitir override manual comercial apenas ao superadministrador. | Alteracao manual de valor, ciclo, limites ou datas exige motivo, diff completo e auditoria. |
| RF-BO-013 | Permitir criar, editar, pausar, arquivar e consultar uso de vouchers. | O sistema suporta `trial_free`, `percentage`, `fixed` e `commercial_credit`. |
| RF-BO-014 | Preservar snapshot no resgate de voucher. | O snapshot registra voucher, plano, preco-base, beneficio, desconto, preco final, periodo e empresa. |
| RF-BO-015 | Permitir gestao comercial de empresas/clientes. | O Backoffice pode alterar plano, ciclo, valor final, limites, voucher, datas comerciais, observacoes e status comercial, sem alterar dados cadastrais ou pessoais. |
| RF-BO-016 | Exibir dashboard inicial com operacao comercial, governanca, risco e indicadores financeiros. | O painel mostra pendencias comerciais, eventos sensiveis, bloqueios, indicadores de assinatura e divergencias de pagamento. |
| RF-BO-017 | Integrar indicadores financeiros com Mercado Pago e dados internos. | O indicador deve diferenciar dado interno, dado do gateway e divergencia detectada. |
| RF-BO-018 | Conciliar divergencias de pagamento e recorrencia usando Mercado Pago como referencia prevalente. | Divergencias geram alerta e revisao manual; apenas superadministrador aplica correcao com origem, estado anterior, estado aplicado, motivo e auditoria. |
| RF-BO-019 | Permitir consulta de auditoria conforme perfil interno. | Superadministrador ve auditoria completa; administrador comercial ve apenas eventos comerciais sob sua permissao. |
| RF-BO-020 | Registrar auditoria detalhada para acoes sensiveis. | Evento contem operador, data, entidade, acao, motivo quando aplicavel, valores antes/depois e metadados minimos. |

## Requisitos nao funcionais

| Codigo | Categoria | Requisito | Criterio de aceite |
| --- | --- | --- | --- |
| RNF-BO-001 | Seguranca | Backoffice deve usar guard e identidade separados do portal do cliente. | Nenhuma permissao de empresa autoriza rotas `/api/backoffice`. |
| RNF-BO-002 | Integridade | Publicacao comercial deve validar relacionamentos, precos, dependencias e status. | Nao e possivel publicar composicao com item invalido ou produto divergente. |
| RNF-BO-003 | Auditoria | Eventos do Backoffice devem ser mantidos por 180 dias. | Depois do prazo, snapshots essenciais continuam preservando a verdade comercial. |
| RNF-BO-004 | Privacidade | Auditoria nao deve registrar senhas, tokens, CPF completo ou dados pessoais desnecessarios. | Eventos usam mascaramento ou metadados minimos quando houver dado sensivel. |
| RNF-BO-005 | Confiabilidade | Webhooks e conciliacoes de pagamento devem ser idempotentes. | Reprocessar evento do Mercado Pago nao duplica pagamento, assinatura ou auditoria. |
| RNF-BO-006 | Usabilidade operacional | Acoes sensiveis devem exigir confirmacao explicita. | Publicar, pausar, arquivar, cancelar, trocar plano e override nao ocorrem por clique acidental. |

## Permissoes internas

| Capacidade | Superadministrador | Administrador comercial |
| --- | --- | --- |
| Acessar Backoffice | Sim | Sim |
| Ver dashboard | Sim | Sim |
| Criar e editar catalogo | Sim | Sim |
| Publicar catalogo | Sim | Nao |
| Pausar ou arquivar catalogo publicado | Sim | Nao |
| Criar e editar vouchers | Sim | Sim |
| Pausar ou arquivar vouchers publicados | Sim | Nao |
| Consultar assinaturas | Sim | Sim |
| Pausar, reativar, cancelar ou trocar plano | Sim | Sim, sem override manual |
| Fazer override manual comercial | Sim | Nao |
| Gerenciar usuarios internos | Sim | Nao |
| Configurar seguranca do Backoffice | Sim | Nao |
| Ver auditoria completa | Sim | Nao |
| Ver auditoria comercial permitida | Sim | Sim |

## Regras de negocio

| Codigo | Regra |
| --- | --- |
| RN-BO-001 | O Backoffice e interno ao Fokus Cloud e nao pode usar perfis de empresa como autoridade de acesso. |
| RN-BO-002 | O catalogo publicado e a fonte oficial para catalogo publico, checkout e recalculo de assinatura. |
| RN-BO-003 | Publicacao, pausa e arquivamento final de itens comerciais sao exclusivos do superadministrador. |
| RN-BO-004 | Motivo e obrigatorio em acoes que afetem cliente, cobranca ou disponibilidade publica. |
| RN-BO-005 | Upgrade vale imediatamente; downgrade e cancelamento ficam agendados para o fim da vigencia. |
| RN-BO-006 | Mercado Pago e a referencia prevalente em divergencias de pagamento e recorrencia, mas correcao interna exige revisao manual. |
| RN-BO-007 | Override manual de valor, ciclo, limites ou datas comerciais e excecao exclusiva do superadministrador. |
| RN-BO-008 | Dados cadastrais e pessoais de clientes nao sao alterados na v1 do Backoffice. |
| RN-BO-009 | Auditoria expira em 180 dias, mas snapshots essenciais de assinatura e resgate permanecem. |

## Dados envolvidos

- `platform_admins`;
- produtos;
- planos;
- funcionalidades;
- composicoes de planos;
- vouchers;
- resgates de vouchers;
- empresas;
- assinaturas;
- itens de assinatura;
- pagamentos;
- eventos de auditoria;
- dados de conciliacao do Mercado Pago.
- alertas de divergencia.

## Eventos auditaveis

Devem gerar auditoria:

- login interno bem-sucedido e tentativas invalidas relevantes;
- bloqueio e desbloqueio de conta interna;
- criacao, edicao, publicacao, pausa e arquivamento de item comercial;
- alteracao de preco, composicao, limite, dependencia ou status;
- criacao, edicao, pausa, arquivamento e uso de voucher;
- pausa, reativacao, cancelamento e troca de plano em assinatura;
- override manual comercial;
- divergencia e ajuste de conciliacao com Mercado Pago;
- alteracao de usuario interno, perfil ou configuracao de seguranca.

## Criterios minimos de teste

- Conta de cliente nao acessa `/backoffice` nem `/api/backoffice`.
- Conta interna nao aparece no portal do cliente.
- Administrador comercial nao gerencia usuarios internos nem seguranca.
- Administrador comercial cria e edita catalogo, mas nao publica, pausa ou arquiva item publicado.
- Superadministrador publica catalogo com confirmacao e auditoria.
- Catalogo publico e checkout ignoram item nao publicado, pausado, arquivado ou invalido.
- Upgrade de assinatura aplica imediatamente; downgrade e cancelamento ficam agendados.
- Alteracao de assinatura recalcula pelo catalogo publicado.
- Override manual so funciona para superadministrador, com motivo, diff e auditoria.
- Voucher `trial_free`, `percentage`, `fixed` e `commercial_credit` preserva snapshot no resgate.
- Divergencia com Mercado Pago gera alerta, exige revisao manual e permite correcao apenas por superadministrador.
- Administrador comercial ve apenas auditoria comercial permitida.
- Auditoria nao registra senha, token, CPF completo ou dado pessoal desnecessario.

## Dependencias

- [Backoffice Fokus Cloud](../04-products/backoffice.md)
- [Portais e governanca](../03-architecture/portals-and-governance.md)
- [Politica de controle de acesso](../07-security/access-control-policy.md)
- [Modelo de permissoes e perfis](../07-security/permission-model.md)
- [Catalogo comercial](../08-commercial/catalog.md)
- [API do catalogo](../08-commercial/catalog-api.md)
- [Vouchers](../08-commercial/vouchers.md)
- [Cadastro de empresa e assinatura](../08-commercial/registration-and-subscription.md)
- [Billing e conciliacao com Mercado Pago](../08-commercial/billing-and-reconciliation.md)
- [Modelo relacional](../06-data/relational-model.md)
- [Modelo de dados do Backoffice e Billing](../06-data/backoffice-and-billing-data-model.md)

## Criterio de pronto

A v1 do Backoffice estara suficientemente especificada quando um implementador
conseguir criar rotas, telas, permissoes, validacoes, auditoria e integracoes
necessarias sem decidir regras de produto adicionais.
