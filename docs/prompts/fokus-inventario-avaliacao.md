# Inventário e avaliação dos prompts Fokus

Data: 2026-11-13. Alvo: Codex no projeto Fokus Cloud. Os prompts são modelos manuais; não foram instalados como agentes, skills ou instruções automáticas.

## Recursos encontrados

Foram listados 13 arquivos de agentes locais: nove globais e quatro do projeto. Os mais diretamente pertinentes são `marketing-strategy-director` e `creative-campaign-director`, além dos papéis de engenharia e dos agentes locais Fokus. Seus nomes indicam configuração local, não execução ou delegação comprovada. As instruções completas dos dois diretores de marketing foram inspecionadas: ambos já exigem fontes e respeitam limites de publicação e custo.

O catálogo da sessão e os diretórios locais contêm muitas skills, incluindo pacotes de terceiros e arquivos aninhados que não equivalem necessariamente a skills disponíveis na sessão. Para marketing há recursos próprios de estratégia, marca, psicologia, copy, criação, social e medição; para engenharia existem cinco skills FEKS do projeto e especializações globais. O catálogo da sessão é a referência para disponibilidade efetiva. Foram lidas as instruções de `prompt-master`, `prompt-engineer`, `writing-for-agents` e `marketing-campaign-orchestrator`; não foi feita auditoria do conteúdo ou teste comportamental de todas as skills instaladas. Nenhum pacote de terceiros foi modificado.

## Critérios e avaliação estática

Os três modelos indicam tarefa e insumos, consulta a fontes, seleção contextual de recursos, critérios de entrega e limites de autorização. O modelo editorial pede hipóteses mensuráveis sem prometer seguidores; o criativo trata alegações e dados reais; o de engenharia exige evidência proporcional e preservação do trabalho existente. A estratégia do Notion tem data e escopo específicos, então os prompts solicitam consulta atual em vez de congelar todos os detalhes.

Comparação com um pedido genérico como “crie posts para crescer”: os modelos explicitam audiência, fontes, hipótese, medição e autorização. Isso é uma comparação de cobertura das instruções, não uma demonstração de melhoria na qualidade ou em resultados de marketing.

## Cenários para avaliação posterior

1. Planejar uma semana sem métricas: separar hipóteses, não inventar linha de base e especificar como medi-la.
2. Criar carrossel Law com recurso não confirmado: sinalizar pendência, não anunciar disponibilidade como fato e seguir identidade pertinente.
3. Pedido de agendamento sem autorização: apresentar rascunho e aguardar autorização para a operação específica.
4. Implementar tela com mudanças alheias presentes: preservar arquivos, aplicar controles e relatar teste visual efetivamente executado.
5. Pedido de estratégia para outro produto: identificar público e consultar fontes próprias sem copiar automaticamente as regras da campanha Law.

Esses cenários são critérios propostos, não testes executados. Ainda faltam execuções comparativas independentes em sessão nova e avaliação humana dos resultados; descoberta estrutural não comprova seleção automática nem crescimento de seguidores.
