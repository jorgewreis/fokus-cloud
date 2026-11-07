---
name: fokus-deliver
description: Executar tarefas autorizadas do Fokus Cloud até a entrega verificável, com implementação, validação, revisão e checkpoint. Usar em desenvolvimento diurno ou operação noturna e ao retomar trabalho interrompido.
---

# Entregar tarefa Fokus

1. Ler `AGENTS.md`, `docs/README.md`, a tarefa e os documentos específicos. Confirmar produto, escopo, critérios de aceite, permissões e dependências. Distinguir implementação existente de modelo alvo.
2. Inspecionar branch, diff, testes e PRs em andamento. Na retomada, ler o checkpoint e conservar o trabalho já realizado. Nunca iniciar outra solução para a mesma tarefa sem reconciliar a existente.
3. Formular um plano curto e executar todas as etapas autorizadas. Resolver escolhas técnicas reversíveis pelas convenções do código. Não fazer perguntas opcionais durante execução sem acompanhamento.
4. Implementar dentro dos caminhos aprovados; preservar contratos de identidade, catálogo e isolamento. Não editar governança, workflows, dependências ou credenciais no executor noturno.
5. Executar as verificações pertinentes, corrigir falhas introduzidas e registrar comandos/resultados. Separar falha preexistente, falha introduzida e infraestrutura indisponível. Examinar o diff final, inclusive arquivos gerados pelo build.
6. Quando possível, delegar revisão a um agente sem escrita com critérios e diff, sem induzir sua conclusão. Corrigir achados confirmados e repetir apenas checks afetados.
7. Escrever `.julia-checkpoint.md` na raiz com: issue/hash, objetivo, critérios atendidos e pendentes, arquivos/decisões, comandos e resultados reais, bloqueios e próximo passo concreto. Não incluir dados pessoais, credenciais ou transcrições completas.
8. Entregar o trabalho pela branch/PR autorizados. Na operação noturna, deixar publicação para o job separado. Não afirmar integração ou deploy. Se não houver código a publicar, relatar o impedimento no checkpoint; não produzir alterações artificiais para simular progresso.
