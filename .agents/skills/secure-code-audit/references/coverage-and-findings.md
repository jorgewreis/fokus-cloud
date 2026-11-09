# Cobertura e formato dos achados

Leia conforme o fluxo auditado; não converta todas as áreas em achados automáticos.

## Matriz de investigação

| Área | Perguntas e evidências úteis |
| --- | --- |
| Autenticação e sessões | Guards, recuperação, expiração/revogação, rotação, cookies, limites de tentativa; diferenciar visitante, sessão expirada e usuário autenticado. |
| Autorização | Permissão por ação e objeto em cada entrada, inclusive API, downloads, agregações, jobs e suporte. A UI ocultar um botão não é controle de servidor. |
| Multiempresa | Empresa/unidade derivadas de contexto confiável, vínculo revalidado, escopo em queries, joins, cache, storage e jobs; testar A/B, troca de contexto, vínculo revogado e recurso relacionado. |
| APIs | Autenticação, scopes, validação/mass assignment, paginação, rate limits, exposição de campos, upload/download, SSRF e limites de payload; alcance direto além da UI. |
| Injeções | Fluxo até SQL/ORM raw, shell, templates e interpretação dinâmica; parâmetros/bindings, allowlists e contexto. Busca textual isolada não comprova caminho explorável. |
| XSS e CSRF | Encoding contextual, HTML/URL/DOM sinks, sanitização; anti-CSRF nos fluxos com cookies e métodos mutáveis, origem e exceções justificadas. SameSite/CORS não substituem toda a análise. |
| Segredos | Referências/configuração, armazenamento, logs e privilégios; nunca registrar valor de segredo. Ausência de segredo no arquivo não prova revogação no provedor. |
| Transações | Invariantes, constraints, atomicidade, concorrência/locks, retries e falha parcial. Efeito externo pode ocorrer fora da transação do banco. |
| Webhooks e replay | Assinatura sobre payload correto, origem/chave, validade temporal, ID de evento, deduplicação atômica e efeito idempotente; assinatura válida sozinha não impede replay. |
| Dependências | Lock, versão/advisory e fonte oficial, uso alcançável e mitigação. Pacote antigo ou ausência de scanner não provam vulnerabilidade; não rodar audit/install que transmita dados sem escopo. |
| Configuração | Debug, TLS, proxies/hosts confiáveis, permissões, headers e defaults efetivos por ambiente; arquivo de exemplo não prova produção. |
| Auditoria e erros | Ator/empresa/ação, trilha útil e integridade, falha segura, resposta sem dados indevidos, logs sanitizados e correlação; distinguir evento emitido de entrega ao sink. |

Para cada área relevante: escopo examinado, controle presente, teste localizado,
execução observada e lacunas. “Não aplicável” precisa de justificativa, não de silêncio.

## Registro de achado

- **Título/estado:** defeito confirmado, hipótese ou lacuna de evidência/cobertura.
- **Local/evidência:** repo, commit, arquivo/linhas, caminho de entrada e trecho
  mínimo sanitizado; incluir resultado reproduzível quando houver.
- **Controle existente:** o que protege e por que não cobre este caminho, se aplicável.
- **Pré-condições/impacto:** ator, permissão necessária, ativo, empresa/alcance,
  comportamento esperado e observado; separar efeito comprovado do potencial.
- **Severidade/confiança:** justificar probabilidade/exposição e impacto.
  Crítica: comprometimento amplo demonstrável; alta: acesso/alteração relevante
  indevidos; média: impacto limitado ou pré-condições restritivas; baixa: impacto
  reduzido. Não usar CVSS numérico sem vetor/método. Hipótese não recebe severidade
  definitiva; prioridade de investigação é distinta de gravidade confirmada.
- **Limites:** arquivos/ambientes não lidos, execução não realizada, controles
  desconhecidos e condições que refutariam a conclusão.
- **Teste:** existente e resultado, ou proposta sintética com entradas e resultado
  esperado; incluir caso permitido e negado quando houver autorização.
- **Correção mínima proposta:** controle na fronteira correta, contratos preservados,
  riscos de regressão e validação; não implementar sem autorização.

## Fontes seletivas

- [Catálogo FEKS fixado](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-knowledge-catalog.md),
  [matriz](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-technology-matrix.md)
  e [lacunas](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-knowledge-gap-analysis.md).
  Snapshots dos heads #51/#50, não integração automática. Revalide versão/commit.
- Requisito: [ASVS 5.0.0 estável](https://github.com/OWASP/ASVS/releases/tag/v5.0.0_release).
  Abra o requisito pertinente e registre versão/ID, sem inventar numeração ou afirmar conformidade.
- Implementação: [OWASP Authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html)
  e [Multi-Tenant Security](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html).
- Classificação: [MITRE CWE](https://cwe.mitre.org/data/index.html), somente após evidência da fraqueza.
- Processo: [NIST SSDF 1.1 final](https://csrc.nist.gov/pubs/sp/800/218/final).

Registre fonte, edição, seção e data da leitura. Guias vivos e catálogo orientam;
somente código/controle/teste aplicável sustenta a conclusão sobre o produto.
