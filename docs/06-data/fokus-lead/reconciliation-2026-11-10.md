# Reconciliação do PR 25 em 10/11/2026

## Escopo e base

REI-14. O head original `198f3fe20104d9261d942c93fa22099d27da3c93`
permanece preservado. A branch derivada `codex/rei14-lead-reconcile-20261110`
incorpora a main `9ecb136c` (195 commits após a base antiga), sem conflitos Git.
Não houve merge em main, deploy, alteração remota de banco ou uso de dados reais.

O Cloud continua responsável por identidade, vínculo, assinatura e consulta
administrativa agregada. O SQL documentado é modelo exclusivo do Lead, fora das
migrations Laravel. Esta reconciliação não move regras imobiliárias para o core,
não cria CRUD, provisionamento ou credenciais e mantém as duas flags desativadas.
A compatibilidade observada dos contratos não equivale à homologação de produção.

## Evidências locais

- PGlite 0.5.10: 20 verificações aprovadas, aplicando os dez scripts em banco
  efêmero com dados sintéticos; 38 tabelas com RLS habilitada e forçada.
- Build Vite aprovado; tokens aprovados (avisos de classes sobrepostas existentes),
  SEO aprovado para 15 páginas e contrato visual aprovado para 19 páginas.
- Testes acrescentados para recusa de transaction pooler 6543, CA inexistente,
  driver diferente de PostgreSQL e runtime desativado antes de abrir conexão.
- Pint normalizado nos arquivos PHP tocados, incluindo estilo preexistente do
  controller administrativo. Essas mudanças de formatação não alteram regras.
- PHP/PHPUnit e auditoria de dependências: consultar o checkpoint e CI do commit
  publicado; não considerar esta seção declaração de aprovação dessas etapas.
- Homologação Playwright não executada: o download oficial do Chromium retornou
  conteúdo inválido (assinatura ZIP ausente). Teste existente preservado.

O build altera hashes de cache em páginas Styles existentes; esses artefatos foram
descartados, sem incorporar alterações de outras frentes à entrega Lead.

## Portões externos ainda obrigatórios

Não foram testados rede real, TLS, LOGINs restritos, sessões concorrentes ou pool.
A validação PGlite não substitui PostgreSQL/Supabase via PDO. Também não foram
reconsultados dados operacionais do projeto Supabase nem aplicadas migrations.

Antes de ativação: credenciais LOGIN sem privilégios administrativos, CA válida
com `verify-full`, conexão direta ou session pooler 5432, provisionamento
idempotente de referências Cloud, testes de duas empresas/corretores e gestores,
rollback e ausência de contexto residual, validação dos acessos de métricas e
homologação em navegador. Seguir `laravel-integration.md`; não habilitar flags
como solução para indisponibilidade. Uploads e suporte operacional do Lead
continuam fora deste escopo. Merge exige decisão explícita posterior.
