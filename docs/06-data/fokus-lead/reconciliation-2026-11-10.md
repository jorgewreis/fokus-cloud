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

## Composição posterior com Law integrado

Em 10/11, a main `ceaff20da3d87790ad3ceef8c554d8f9454c1631` foi
incorporada à derivação Lead sem conflitos, preservando commits e PR25 original.
Os lockfiles e todos os workflows permanecem idênticos à main; não houve
atualização adicional de dependências nem mudança do gate manual de publicação.

Validação repetida por mudança material da composição e do runtime Laravel:

- PHP 8.4.27: 351 testes e 2.834 asserções aprovados; foco Lead/CompanyAdmin:
  30 testes e 160 asserções, sem falhas.
- Composer audit do lock combinado: zero advisories e zero pacotes abandonados.
  O bloqueio dos 18 advisories relatado acima foi resolvido pela composição Law.
- Pint nos 12 arquivos PHP do delta Lead, build Vite real, tokens, SEO e contrato
  visual aprovados; teste browser de métricas Lead aprovado, sem retry.
- Contrato de proteção documental: 34 testes aprovados, incluindo classificação
  conservadora. O AUDIT_DIGEST não foi alterado por esta reconciliação.
- SQL e modelo PGlite estão inalterados desde a validação anterior; reutiliza-se
  a evidência 20/20, sem apresentá-la como execução nova ou como PostgreSQL/PDO real.
- O build convencional novamente reescreveu somente hashes CSS dos cinco HTML
  Styles. O diff gerado foi preservado separadamente como evidência local e não
  integra o commit de composição. Não houve ajuste das fontes ou contrato para
  esconder essa característica preexistente do build.

CI remoto do novo head deve ser conferido antes de marcar pronto para revisão.
Os portões reais de PostgreSQL, TLS, LOGINs, pool e provisionamento continuam
pendentes e as flags seguem desativadas. Esta composição não é um deploy.

### Correção comprovada do foco inicial

O CI da composição passou com uma intermitência recuperada por retry no drawer
de assinaturas (run 42827412477). A falha foi reproduzida sem retry sob concorrência
(19/20 passaram). Instrumentação confirmou que Offcanvas tentou focar o botão de
fechar enquanto `visibility` ainda era `hidden`, mesmo após duplo RAF.
Uma regressão determinística com atraso CSS de 250ms falhou antes da correção.

O adaptador Cloud `records-drawer.js` agora completa o foco inicial somente quando
o drawer estiver efetivamente visível, sem substituir o focus trap Styles, sem
roubar foco já atribuído internamente, e cancelando no fechamento/descarte. A busca
é limitada a dois segundos para não manter frames infinitos em CSS inválido.
A regressão cobre abertura, Escape, reabertura e fechamento durante a transição.
Após correção, 40 repetições concorrentes (20 do caso original e 20 do caso novo)
passaram com retries=0. Nenhuma dependência, timeout de teste ou asserção foi
relaxada. CI e homologação Linux do novo SHA continuam necessários; os resultados
do SHA anterior não comprovam a correção nova.
