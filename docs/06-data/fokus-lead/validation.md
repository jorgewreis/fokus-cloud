# Validação do modelo PostgreSQL do Fokus Lead

Data: 15/10/2026. Ambiente isolado; dados sintéticos.

## Procedimento reproduzível

O verificador usa PostgreSQL em WebAssembly via PGlite apenas como dependência
de teste. Não adiciona dependência à aplicação e não usa Supabase, credenciais,
arquivos de clientes ou o banco do Cloud/Law.

Instalar a dependência em uma pasta temporária fora do repositório:

```bash
npm install --prefix /tmp/fokus-lead-model-check @electric-sql/pglite@0.5.10 --no-audit --no-fund --ignore-scripts
LEAD_PGLITE_MODULE=/tmp/fokus-lead-model-check/node_modules/@electric-sql/pglite/dist/index.js \
  node docs/06-data/fokus-lead/validate-model.mjs
```

Versão utilizada: **PGlite 0.5.10**. Se a versão instalada for diferente, registre
o novo resultado. O script cria uma instância vazia em
memória, aplica `sql/01-...` a `sql/10-...` e encerra a instância no final.

## Resultado inicial

20 verificações passaram:

1. Execução ordenada dos dez scripts e criação das 38 tabelas.
2. RLS habilitada e forçada em todas as tabelas operacionais.
3. Ausência de dados visíveis sem contexto de empresa/usuário.
4. Carteira compartilhada somente dentro da empresa.
5. Atendimentos, proprietários e canais limitados ao corretor responsável.
6. Edição limitada aos imóveis sob responsabilidade do corretor.
7. Visão do gestor limitada à própria empresa.
8. Rejeição de vínculo de proprietário entre empresas diferentes.
9. Ofertas de venda/locação independentes e rejeição de duplicidade ativa por finalidade.
10. Rejeição de duas reservas ativas para o mesmo imóvel.
11. Rejeição de reserva cujo imóvel não corresponda à oferta/proposta.
12. Fechamento sem data de assinatura rejeitado.
13. Históricos/auditoria protegidos contra alteração, exclusão e truncamento pelo runtime.
14. Projeção pública sem campos internos, proprietários ou caminhos de arquivos.
15. Retirada de ofertas públicas quando o catálogo é desativado.
16. Admin de métricas sem acesso às tabelas operacionais.
17. Papéis `anon`, `authenticated` e `service_role` sem acesso aos schemas privados.
18. Contexto definido com `SET LOCAL` não persiste após `COMMIT`.
19. Mídias públicas exigem autorização explícita, visibilidade pública e aprovação do arquivo.
20. Corretor vê somente sua parcela da comissão, sem totais ou parcelas de colegas.

## Limites da verificação

Os testes confirmam as propriedades exercitadas do **modelo**, não o produto
completo. Não existem ainda Controllers, Policies, Actions, formulários ou jobs
implementados por esta entrega. Não foram executados PHPUnit, Pint ou build Vite,
pois nenhum arquivo PHP, frontend ou configuração de runtime foi alterado.

Ainda são necessários testes com dois clientes concorrentes, mudança imediata
de vínculo no Cloud, credenciais reais de runtime,
configuração da Data API, pipeline de arquivos, recuperação de falhas e restauração.
Rateio de comissão, aceite automático, expiração de reservas e fechamento devem
ser implementados e testados transacionalmente no backend.

## Verificação no Supabase

Projeto Fokus Lead, organização Jorge Reis Dev, região São Paulo, em 15/10/2026:

- Dez migrations aplicadas com sucesso e registradas no histórico do projeto.
- 29 tabelas em `lead` e nove em `lead_private`: todas com RLS habilitada e forçada.
- 142 políticas de acesso e três views de projeção.
- Schemas privados inacessíveis a `anon` e `authenticated`.
- Verificador de segurança: nenhum alerta.
- Seis índices de cobertura de FKs compostas acrescentados após revisão.
- Sem FKs sem cobertura no verificador final. Permanecem somente avisos de
  [índices ainda sem uso](https://supabase.com/docs/guides/database/database-linter?lint=0005_unused_index),
  compatíveis com banco recém-criado e sem carga real; não foram removidos.

`validate-live.sql` foi executado no PostgreSQL 17.11 real do projeto. O teste
cria empresas e usuários sintéticos dentro de uma única transação, concede
temporariamente a `postgres` a capacidade de assumir os três papéis de teste e
valida: isolamento de empresas; atendimentos, contatos e proprietários por
responsável; edição própria; FKs entre empresas; ausência de contexto; visão do
gestor; reservas conflitantes; assinatura obrigatória; proteção do histórico;
catálogo público; métricas administrativas sem acesso a contatos privados.

O resultado foi `PASS`. O `ROLLBACK` removeu todas as fixtures e as associações
temporárias de papéis. Resultado final: `remaining_fixture_tenants = 0` e
`persistent_runtime_membership = false`. Não executar esse arquivo como migration
nem usar dados reais para reproduzir o teste.
