# Instruções do projeto

- Investigue primeiro o código, os testes e `docs/README.md`. Resolva decisões técnicas reversíveis dentro do escopo autorizado sem pedir confirmações intermediárias. Pergunte somente quando uma decisão material não puder ser obtida das fontes existentes; faça uma pergunta por vez, com até cinco opções e uma recomendada.
- Nunca use caracteres textuais para representar ícones ou símbolos de interface, como setas. Use imagens PNG oficiais do Streamline, da família Ultimate; para ícones pequenos, use Ultimate Bold quando aplicável.

## Arquitetura e autorização

- Classifique a tarefa: Cloud para identidade, empresas, acesso, catálogo e cobrança; Styles para visual reutilizável; Law para domínio jurídico; Lead para domínio imobiliário. Leia `docs/03-architecture/repository-boundaries.md`; não duplique serviços comuns nos produtos.
- Preserve isolamento por empresa, autorização por Policies, validação, CSRF e auditoria. Não invente preços, requisitos, dados nem resultados.
- Um pedido explícito de implementação autoriza as alterações, testes, correções, documentação e decisões técnicas necessárias para concluir aquele escopo. Não solicite autorização novamente para cada arquivo ou etapa.
- Na operação autônoma, execute as tarefas autorizadas e registradas conforme o procedimento em `docs/09-operations/julia-night-operations.md`. A autorização de uma tarefa não permite ampliar produto, escopo, custo, permissões ou acesso a produção.
- Respeite autorizações já concedidas para merge e deploy no escopo correspondente; não as solicite novamente. Sem autorização aplicável, entregue PR. Dados reais, segredos, operações destrutivas e alterações comerciais exigem autorização específica. Nunca tente contornar uma restrição do ambiente.

## Execução até a entrega

- Leia `docs/09-operations/julia-night-operations.md` e a fila oficial no Linear, indexada em `docs/09-operations/julia-task-queue.md` para execução e retomada de ponta a ponta.
- Planeje, implemente, valide, corrija falhas e revise antes de encerrar. Não termine com uma promessa de executar etapas já autorizadas.
- Preserve trabalho alheio. Use branch própria e não force push. Registre decisões e um checkpoint verificável antes de encerrar por limite ou bloqueio.
- Se uma etapa estiver bloqueada, conclua trabalho independente autorizado. Diferencie iniciado, bloqueado, pronto para revisão e integrado; código escrito não significa tarefa concluída.
- Papéis disponíveis em `.codex/agents/`: Julia coordena; o implementador desenvolve; o revisor confere sem editar. Delegue somente quando o executor oferecer subagentes e houver ganho concreto. Não alegue revisão independente se o mesmo agente apenas releu seu trabalho.
- Valide conforme o impacto: PHPUnit, Pint nos arquivos alterados, build Vite, tokens, SEO, contrato visual, migrations e MySQL/Redis quando aplicável. Não altere testes para esconder defeitos nem declare checks que não executou.
- Entregue evidências, commits/PR, critérios atendidos, limitações e pendências. A operação noturna entrega PR e checkpoint; integração é um estado posterior.

## Custos e retomadas

- Use somente recursos nativos da assinatura do ChatGPT/Codex. Não use chaves de API de modelos, executores pagos, compra de créditos ou serviços pagos adicionais. Ao atingir limite, preserve checkpoint e retome quando disponível.
- As rotinas de madrugada e de hora em hora seguem o mesmo registro de tarefa no Linear e a mesma branch; não criem execuções concorrentes para a mesma entrega. Revise instruções mais recentes antes de agir.
