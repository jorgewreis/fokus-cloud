# Segurança e dados

## Identificação e validação

CPF e CNPJ devem ser armazenados em formato normalizado, sem pontuação. Antes
da gravação, o sistema valida formato, quantidade de dígitos e dígitos
verificadores.

As regras de unicidade são:

- um CPF representa uma única conta de usuário;
- um CPF ou CNPJ representa uma única empresa;
- cada empresa possui um único administrador ativo.

A validação não inclui consulta à Receita Federal ou outra base governamental
nesta etapa.

## Senhas e autenticação

O login usa CPF e senha. A senha deve ter pelo menos 12 caracteres e não pode
constar em listas de senhas comuns ou comprovadamente vazadas.

Senhas nunca são armazenadas em texto puro. A aplicação usa o hash de senha
do Laravel, uma lista local de senhas comuns e a API Have I Been Pwned pelo
método *k-anonymity*: apenas os cinco primeiros caracteres do hash SHA-1 são
consultados; a senha, seu hash completo e o CPF não saem da aplicação.

## Confirmação e recuperação

| Processo | Regra |
| --- | --- |
| Confirmação de e-mail inicial | Link válido por 24 horas; obrigatório antes de escolher a assinatura. |
| Reenvio da confirmação | Permitido enquanto o e-mail estiver pendente, com controles contra abuso. |
| Recuperação de senha | Usuário informa o CPF e recebe link temporário no e-mail confirmado. |
| Alteração de e-mail | Novo e-mail só passa a valer após confirmação; endereço anterior continua ativo até então. |
| Transferência de admin | Exige senha do admin atual e aceite do novo admin por e-mail. |

Mensagens de autenticação e recuperação não devem revelar se um CPF ou e-mail
está cadastrado quando isso puder facilitar enumeração de contas.

## Privacidade e integridade

O cadastro coleta dados pessoais do administrador, incluindo CPF, nome e
e-mail. O formulário deve exigir aceite separado para Termos de Uso e Política
de Privacidade, armazenando versão, data e hora.

O documento e o nome da empresa são imutáveis depois da criação. O CPF do
usuário só pode ser alterado pelo suporte. Essas restrições preservam a
integridade dos vínculos, da assinatura e do histórico de administração.

## Auditoria

Transferências de administração e alterações de acesso devem gerar registros
de auditoria imutáveis. O histórico precisa identificar os envolvidos, o
momento da ação, a decisão sobre o antigo admin e o resultado da confirmação.

## Controles implementados

- Aplicar unicidade e o limite de um admin por empresa no banco de dados.
- Executar criação de empresa, usuário e vínculo inicial em transação única.
- Executar a transferência de admin em transação única.
- Expirar links de confirmação e recuperação no servidor.
- Proteger rotas e dados pelo contexto da empresa ativa.
- Usar MySQL/Percona 8.4 com InnoDB, IDs textuais prefixados e FKs restritivas.
- Aplicar `company_id` obrigatório, relações compostas e contexto de empresa
  obtido de sessão assinada, conforme [Modelo relacional](../06-data/relational-model.md)
  e [Isolamento e governança](../06-data/data-isolation-and-governance.md).
- Registrar eventos de criação, alteração, remoção, restauração e transferência
  de vínculos em auditoria mascarada, com retenção de 180 dias.
- Usar o contrato comum de auditoria para classificar ator e origem técnica,
  registrar motivo ou não aplicabilidade, estados anteriores/posteriores e
  expiração calculada a partir da data do evento.
- Nunca persistir em auditoria, logs ou evidências senhas, tokens, códigos MFA,
  CPF/CNPJ completo, dados de cartão ou payload bruto do provedor. Payloads e
  mensagens de erro técnicos devem ser allowlisted ou sanitizados antes de
  gravar.
- Invalidar tokens anteriores da mesma finalidade ao reenviar um link e marcar
  o token aceito em transação, impedindo reutilização concorrente.
