# Gestao de Audiencias do Fokus Law

O modulo comercial Gestao de Audiencias aparece no menu como `Audiencias`.
Ele possui variantes para o Setor Publico, com agenda, participantes,
alertas, notificacoes, status e vinculos com processos, contatos, expedicoes e
tarefas.

## Variantes

- `audiencias-vara-criminal`: pauta e fluxo de audiencias criminais.
- `audiencias-vara-civel`: pauta e fluxo de audiencias civeis.
- `audiencias-juizado`: audiencias, conciliacao e procedimentos simplificados.
- `audiencias-orgao-publico`: audiencias e compromissos institucionais.

## Acompanhamento externo

`audiencias-externo` e um add-on dependente de Gestao de Audiencias. Permite
que partes acompanhem somente a audiencia autorizada por token temporario,
com expiracao, revogacao, registro de acessos e exposicao minima de dados.
Nao permite alterar status, acessar processos, documentos ou anotacoes internas.

## Integracoes

Audiencias processuais vinculam-se a Processos; participantes reutilizam
Contatos; convocacoes e intimacoes usam Expedicoes; e preparacao, realizacao,
retorno e encerramento usam Tarefas. Cancelamentos e redesignacoes preservam
historico e podem gerar alertas e tarefas.
