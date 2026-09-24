# Ambientes e deploy

## Ambientes previstos

| Ambiente | Uso |
| --- | --- |
| Local | Desenvolvimento e testes manuais. |
| Homologacao | Validacao antes de producao. |
| Producao | Uso real por usuarios e empresas. |

## Regras

- Registrar versoes de PHP, Node, banco e dependencias de deploy.
- Validar compatibilidade antes de atualizar dependencias centrais.
- Manter variaveis sensiveis fora do repositorio.

## A complementar

- Servidor alvo.
- Processo de deploy.
- Variaveis obrigatorias.
- Rotina de backup.

O procedimento de rotação de segredos, backup e restauração está em [Operação de segurança em produção](production-security-runbook.md). A rotina só estará comprovada após um exercício de restauração isolada com evidência registrada.
