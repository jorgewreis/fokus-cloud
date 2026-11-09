# Fontes e registro de pesquisa

Leia esta referência para selecionar fontes e montar uma conclusão rastreável.

## FEKS, dependências informacionais

Baseline da etapa 3: Cloud main `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
Antes de reaplicar uma conclusão, confira o commit da tarefa atual.

- [Inventário, PR #50](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-technical-inventory.md)
  e [lacunas](https://github.com/jorgewreis/fokus-cloud/blob/883973bb903397adb676d26ea702db907f194fa7/docs/engineering/feks-knowledge-gap-analysis.md): localizar componente e evidência existente.
- [Catálogo, PR #51](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-knowledge-catalog.md): escolher fonte pelo problema, não pela popularidade.
- [Matriz](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-technology-matrix.md)
  e [avaliação](https://github.com/jorgewreis/fokus-cloud/blob/b30cfa5f474d14660554bcbb76c24559abdc6325/docs/engineering/feks-source-evaluation.md): verificar edição e limites.

Os links são snapshots de heads não integrados na leitura de 08/11/2026 UTC.
Não há sincronização automática. Depois de uma revisão, registre o novo SHA quando
reavaliar a evidência, sem reescrever retroativamente o que foi consultado.
Se os documentos estiverem disponíveis no checkout, confirme sua identidade antes
de preferir a cópia local. Sem acesso, registre a lacuna e prossiga só com fontes
suficientes para a pergunta; catálogo não é dependência executável.

## Registro mínimo, adaptável

| Campo | Conteúdo necessário |
| --- | --- |
| Pergunta e baseline | Produto, repo/commit, escopo e critério de sucesso |
| Versões | Manifesto; lock; CI configurado; runtime observado ou desconhecido |
| Evidência | Arquivo/linhas ou URL, versão/SHA, seção, data e trecho relevante |
| Estado da fonte | Identificada; acessível; lida (qual parte); aplicada/validada |
| Conclusão | Fato; inferência com premissas; hipótese com teste de refutação |
| Decisão | Solução mínima, alternativa relevante, custo, impacto e risco |
| Validação | Ambiente, comando, resultado real, limites e próxima ação |

“Aplicada/validada” exige vínculo entre fonte, decisão, commit, ambiente e resultado;
abrir uma página ou ler um teste não basta. Uma fonte pode ser lida apenas em parte.
Manual oficial e código upstream são complementares, não provas independentes da
execução do produto. Preserve links; não copie manuais inteiros.
