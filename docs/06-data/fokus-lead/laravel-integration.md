# Integração inicial do Laravel com o Fokus Lead

Estado em 15/10/2026: código preparado no branch `feat/lead-postgresql-model`,
publicado para revisão após autorização explícita do usuário, sem ativação em produção. O projeto Supabase exclusivo
`yukmvgkojmpzmsynlyxn` já contém o modelo. Nenhuma credencial LOGIN da aplicação
foi criada ou configurada por esta entrega.

## Conexões e contexto

O banco padrão do Cloud continua definido por `DB_CONNECTION`. `lead` e
`lead_metrics` usam exclusivamente variáveis `LEAD_*` de `.env.example`, sem
fallback para `DB_URL` ou credenciais do core. Ambas exigem PostgreSQL, TLS
`verify-full` e arquivo CA legível em `LEAD_DB_SSLROOTCERT`. Usar conexão direta
ou Session pooler na porta 5432; a porta 6543 é recusada neste adaptador.

`LeadDatabase::transaction` revalida no core o usuário verificado/ativo, empresa
ativa, vínculo e assinatura utilizável. A empresa vem da sessão do servidor;
a capacidade de gestor vem do vínculo atual. Campos enviados pelo cliente não
alteram esse contexto. A Policy da ação ainda precisa autorizar a operação
específica antes da chamada. Não foram criadas rotas CRUD imobiliárias.

O adaptador inicia uma transação, rejeita LOGIN superuser/BYPASSRLS ou dono dos
schemas Lead, assume `lead_runtime` com `SET LOCAL ROLE`, injeta o contexto com
`set_config(..., true)` parametrizado e desconecta ao terminar, inclusive em falha.
As Policies e as regras transacionais do produto continuam necessárias: RLS não
substitui autorização de campos, auditoria nem máquina de estados do negócio.

O suporte existente do Law é bloqueado neste adaptador. A implementação futura
de suporte do Lead exige consentimento específico da empresa, escopo, expiração
e auditoria; um administrador da plataforma não ganha acesso operacional por
consultar métricas.

## Métricas no administrador

O detalhe da empresa consulta somente `lead_admin.company_usage`, com filtro de
empresa, via `lead_metrics`/`lead_admin_metrics` e transação somente leitura. A
resposta expõe contagens e tamanho dos arquivos registrados. Não consulta contatos,
propostas, proprietários ou documentos. Conexão ausente ou indisponível gera métricas
nulas; empresa ainda não provisionada tem estado próprio, sem zeros fabricados.
A autorização existente `platform.companies.view` permanece obrigatória.

A tela separa os volumes de Law e Lead e limpa os números ao mudar de empresa.
O comando `php artisan fokus:lead-check EMP-...` verifica a projeção para uma empresa
existente no core. Sem provisionamento no Lead ele informa esse estado e retorna
falha; não cria referências fictícias.

## Ativação pelo operador

1. Criar dois LOGINs exclusivos sem privilégios administrativos ou propriedade de
   objetos: um somente membro de `lead_runtime`, outro somente de
   `lead_admin_metrics`. Usar `NOINHERIT`, `NOBYPASSRLS`, sem `CREATEROLE`, `CREATEDB`
   ou associações adicionais. Conceder associação com capacidade de `SET ROLE`;
   não usar o usuário `postgres`, chave de API ou `service_role` como credencial PDO.
2. Guardar senhas no gerenciador de segredos da hospedagem. Preencher separadamente
   host/porta/database/username/password de runtime e métricas. Para Session pooler,
   usar exatamente o username indicado para o LOGIN/projeto, sem adivinhá-lo.
   Instalar o certificado CA obtido do painel Supabase e informar o caminho local.
3. Disponibilizar PHP 8.4 compatível com o lock atual e extensão `pdo_pgsql`.
   Ativar as flags `LEAD_DB_ENABLED` e `LEAD_METRICS_DB_ENABLED` somente após validar
   os dois LOGINs. Limpar/recriar cache de configuração conforme o deploy existente.
4. Implementar o provisionamento idempotente das referências canônicas do Cloud
   no Lead por processo restrito. O runtime não pode inserir `tenant_refs` nem
   inventar usuários/vínculos. Ainda não há provisionamento automático nesta entrega.
5. Validar por conexão real da aplicação: TLS, papel efetivo, isolamento entre duas
   empresas, escopo de corretor/gestor, falha/rollback, acessos simultâneos e ausência
   de contexto residual. As verificações locais com mocks não comprovam rede/pool.
6. Configurar servidor próprio de arquivos após definir endpoint/protocolo e
   credenciais por canal seguro. `LEAD_FILES_DISK` fica vazio até essa definição;
   nenhuma integração de upload/download foi ativada.

Não executar as migrations do core/Law nesta conexão. Nenhuma alteração de dados
ou schema foi feita no projeto Supabase do Cloud por esta integração.

## Validação desta entrega

Os testes de integração cobrem contexto derivado do servidor, revogação de vínculo,
assinatura e verificação de e-mail, recusa de suporte indevido, tentativa de troca
de empresa, credencial privilegiada, configuração TLS insegura, limpeza da conexão
em falha e projeção administrativa. O teste do leitor usa SQLite somente para
validar seleção/serialização; não simula políticas RLS do PostgreSQL. A validação
nativa das políticas já está documentada em `validation.md` e `validate-live.sql`.

A publicação do branch no repositório público foi autorizada pelo usuário.
A ativação em produção permanece pendente de revisão, credenciais e deploy.

Resultado local: 23 testes PHP aprovados (119 asserções), build Vite aprovado,
sintaxe JavaScript e contrato visual das
19 páginas aprovados. O teste de navegador foi escrito, mas sua execução ficou
bloqueada porque o download do Chromium retornou um arquivo inválido neste
ambiente. Não há validação visual concluída para a nova seção do drawer.
