# Documentos legais públicos — versão 2.0

Atualização: 14 de outubro de 2026.

## Documentos e escopo

- [Termos de Uso](../../public/marketing/terms.html), publicados em `/termos-de-uso`.
- [Política de Privacidade](../../public/marketing/privacy.html), publicada em `/privacidade`.
- Versões vigentes no servidor: `App\Support\LegalDocuments`.
- Textos das versões 1.0 e 2.0 preservados em `resources/legal/versions`, com SHA-256.

A redação é original e corresponde ao modelo de software como serviço, às contas
globais, aos vínculos empresariais e às assinaturas por produto. A política
anterior abrangia apenas formulários de interesse; a nova versão também trata
cadastro, faturamento, autenticação, dados operacionais e direitos dos titulares.

O texto mantém a identificação fornecida pelo responsável: Jorge Wanderley Reis
de Menezes, CNPJ 59.013.269/0001-55, localidade Ilhéus - BA e o contato institucional
já publicado no site, `contato@fokuscloud.com.br`. A cidade/UF foi mantida por
instrução expressa do responsável, sem inventar endereço completo.

## Pesquisa e fundamentos

Documentos semelhantes consultados para identificar temas de contratação SaaS,
transparência e distribuição de responsabilidades, sem reproduzir suas cláusulas:

- [Termos do software RD Station CRM](https://legal.rdstation.com/pt/rdstation-crm-services-agreement/).
- [Aviso de Privacidade RD Station](https://legal.rdstation.com/pt/privacy-policy/).
- [Acordo de tratamento de dados RD Station](https://www.rdstation.com/legal-e-privacidade/dpa/).
- [Política de Privacidade Aurum](https://s3.sa-east-1.amazonaws.com/publico.aurum.com.br/contratos/politica-de-privacidade.pdf).

A legislação brasileira prevalece sobre práticas de outros fornecedores. Foram
consultados o [Código Civil](https://www.planalto.gov.br/ccivil_03/leis/2002/l10406compilada.htm),
a [Lei do Software](https://www.planalto.gov.br/ccivil_03/leis/l9609.htm), o
[CDC](https://www.planalto.gov.br/ccivil_03/leis/l8078compilado.htm), o
[Decreto 7.962/2013](https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2013/decreto/d7962.htm),
a [LGPD](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709compilado.htm),
o [Marco Civil](https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2014/lei/l12965.htm)
e a [regulamentação de transferências internacionais da ANPD](https://www.gov.br/anpd/pt-br/assuntos/assuntos-internacionais/transferencia-internacional-de-dados).

Foram evitados limites monetários arbitrários de responsabilidade, renúncia ao
arrependimento, exclusão absoluta por incidentes, foro obrigatório restritivo,
multas não implementadas e promessas de disponibilidade, exclusão ou exportação
que não sejam comprovadas. Não se assume que toda contratação empresarial esteja
automaticamente fora do CDC.

## Correspondência com o projeto

- Cadastro e perfil: `AuthController`, `cadastro.html` e documentação de cadastro.
- Aceites e ciência: `legal_acceptances`, versão e data; não constituem autorização
  geral para usos opcionais. Formulários de interesse mantêm finalidade específica.
- Pagamento: Mercado Pago e snapshots comerciais; o retorno do navegador não ativa
  assinatura. Regras comerciais seguem a oferta e os gestores de billing.
- Dados operacionais: organização cliente como controladora, Fokus Cloud como
  operador nas atividades por sua conta; papéis avaliados pela atividade concreta.
- Senhas: hash e consulta parcial do hash ao Pwned Passwords; sem envio da senha,
  hash completo ou CPF nessa consulta.
- Integrações: Datajud/CNJ quando habilitado; tráfego Cloudflare observado no site;
  Google Analytics condicionado à preferência específica e configuração ativa.
- Retenção de auditoria: janela operacional de 180 dias; distinta da obrigação de
  guarda de registros de acesso por seis meses prevista no Marco Civil.

## Versionamento e controles desta atualização

Formulários de cadastro e de interesse passaram a enviar a versão 2.0. O cadastro
exige manifestações explícitas de aceite e ciência e recusa versões obsoletas;
o formulário de interesse preserva seu consentimento específico e também recusa
versão obsoleta. Registros históricos não foram migrados nem reatribuídos à 2.0.

O cadastro assistido do backoffice permanece no fluxo legado, com validação de
campos de aceite e gravação da versão 1.0. Não foi reatribuído à versão 2.0: um
operador interno não pode comprovar sozinho a manifestação pessoal do administrador
cliente. É necessário um fluxo próprio para entregar os documentos atuais, coletar
a manifestação válida e registrar sua origem. O formulário atual de criação de
empresa no backoffice não contém os campos exigidos pela API; essa divergência
também deve ser resolvida nesse trabalho específico, sem fabricar aceites históricos.

Atualizar os documentos não implementa por si só um fluxo de reaceite para todas
as contas existentes, nem uma distribuição ativa da nova versão a todos os clientes.
Comunicação material e novo aceite devem ser tratados conforme a contratação e a
base legal. Esses fluxos exigem trabalho próprio antes de serem apresentados como
automáticos.

## Pontos que ainda exigem validação jurídica e operacional

1. Confirmar nome empresarial no cadastro oficial e completar endereço físico de
   divulgação para comércio eletrônico, conforme art. 2º do Decreto 7.962/2013.
   A restrição expressa do responsável a cidade/UF foi respeitada nesta publicação.
2. Formalizar inventário de fornecedores, países, locais de hospedagem e mecanismos
   efetivamente adotados nas transferências internacionais. A redação não afirma
   residência exclusiva no Brasil nem existência comprovada de cláusulas-padrão.
3. Definir tabela completa de retenção para contas, contratos, pagamentos, interesses,
   dados operacionais e backups, com rotinas de descarte e preservação legítima.
   Não foi criado prazo fictício de exclusão após cancelamento.
4. Verificar guarda de registros de acesso pelo prazo legal de seis meses: auditoria
   de 180 dias não prova cumprimento dessa obrigação distinta.
5. Formalizar tratamento de incidentes, atendimento de titulares, confirmação de
   demandas e atendimento consumerista dentro dos prazos legais, com evidência.
6. Definir necessidade de encarregado e sua divulgação conforme o regime aplicável;
   o e-mail institucional é canal de contato, não prova de designação formal.
7. Submeter os documentos à revisão de advogado com conhecimento da operação e
   confirmar que as obrigações assumidas são cumpridas na prática. Não há garantia
   de blindagem jurídica ou conformidade integral por simples publicação.

Esses pontos são pendências conhecidas e não foram substituídos por declarações de
certificação, segurança absoluta ou conformidade já comprovada nas páginas públicas.
