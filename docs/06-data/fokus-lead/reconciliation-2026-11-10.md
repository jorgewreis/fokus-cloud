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
- CI Application quality do commit `9f99a349`: 277 testes PHP aprovados,
  2.221 asserções, Pint e todas as etapas de build/contratos aprovadas.
  Evidência: https://github.com/jorgewreis/fokus-cloud/actions/runs/42808251184
- Playwright: o teste de métricas Lead passou com dados sintéticos, incluindo
  fechamento/reabertura do drawer e troca de empresa sem métricas residuais.
  O download Playwright veio inválido; a execução usou Chromium headless
  156.0.8078.79 de pacotes Debian oficiais, extraídos em diretório temporário,
  com override de executablePath fora do repositório. Não altera o CI existente.
- `npm audit`: nenhum alerta. `composer audit --locked`: 18 alertas em sete
  pacotes já presentes no lock idêntico da main (Laravel, CommonMark, Flysystem,
  Symfony http-foundation/http-kernel/mime/routing). Há, por exemplo, o alerta
  Laravel TokenGuard GHSA-f9x3-7vq9-cph6. A correção transversal pertence à frente
  Law já em execução; este PR não concorre sobre composer.lock e não declara
  segura a versão vulnerável. Integrar a correção e repetir auditoria/suíte antes
  de qualquer aprovação de produção.
- Revisão focal independente do delta novo: nenhum defeito material identificado
  nos guards, separação de conexões, contexto transacional, flags e fronteira de
  migrations. Não equivale a revisão integral do SQL nem a prova de PDO/rede.

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
