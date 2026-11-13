# Plano de auditoria e remediação — checkpoint

Estado: reconhecimento iniciado; auditoria, diagnóstico, plano de correções específico, implementação e reauditoria ainda não concluídos. Este plano organiza a continuação sem presumir defeitos ou requisitos ausentes.

1. **Reconhecer e delimitar:** reconciliar instruções, trabalho existente, Linear e fontes de requisitos; mapear rotas, módulos, integrações, testes e fronteiras dos produtos. Aceite: mapa rastreável e classificação de cobertura.
2. **Auditar por risco:** segurança e isolamento; backend e persistência; frontend, acessibilidade e interfaces renderizadas; CI e infraestrutura configurada. Aceite: cada suspeita tem evidência, gatilho, impacto, confiança e referência ao requisito; registrar lacunas separadamente.
3. **Planejar correções:** priorizar defeitos comprovados, dependências, arquivos, riscos e testes; relacionar tarefas já existentes no Linear. Aceite: critérios objetivos e sequência revisável, sem duplicar fila.
4. **Implementar e validar:** corrigir no escopo autorizado preservando alterações de terceiros; testar regressões e verificar visualmente quando aplicável. Aceite: evidência reproduzível e revisão do diff para cada correção.
5. **Reauditar e entregar:** reexecutar verificações afetadas, atualizar estados e distinguir limitações de resultados. Aceite: checkpoint, evidências e PR quando aplicável.

Responsável atual: coordenador desta sessão. Subagentes solicitados não tiveram ferramentas de leitura; só atribuir a eles trabalho efetivo após confirmar acesso. Skills escolhidas até agora: `code-audit`, `autonomous-task-execution` e `principal-software-architect`; carregar especializações adicionais conforme os fluxos examinados.

Próxima ação: reproduzir A-001 no Linux previsto pelo CI, estabelecer ambiente isolado com controle de chamadas externas, mapear caminhos críticos e investigar os avisos de seletores; não tratar como regressão sem reprodução. Preservar os arquivos alheios e não reutilizar a branch do PR de prompts.
