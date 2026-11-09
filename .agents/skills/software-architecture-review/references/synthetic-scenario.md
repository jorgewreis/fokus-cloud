# Ensaio A: decisão arquitetural fictícia

## Pedido ao executor

Use software-architecture-review para avaliar a proposta abaixo. Recomende a menor
solução compatível e um plano de validação. Somente leitura e modelo sintético em
memória; não implemente a solução, acesse serviços ou consulte a aplicação Fokus.
Os caminhos são rótulos de artefatos, não arquivos reais ou evidência de produção.

## Artefatos recebidos

`fixture/requirements.md`: uma plataforma fictícia separa Núcleo (identidade,
empresa e direito de uso), Domínio (regras de casos) e Visual (componentes de UI).
Uma revogação deve impedir novas operações do Domínio em até 300 segundos.
Falha ao verificar autorização deve negar a operação. Não há meta de throughput
ou latência nem medição de disponibilidade. Nenhuma tecnologia nova foi autorizada.

`fixture/current.md`: Núcleo e Domínio são módulos no mesmo processo. O Domínio
consulta o serviço comum de autorização antes de cada operação. O teste de contrato
nega operação após revogação. Isto é um modelo fornecido, não runtime inspecionado.

`fixture/proposal.md`: extrair Domínio como processo separado. Copiar a tabela de
usuários/direitos do Núcleo para o Domínio uma vez por dia, para evitar consultas.
Visual receberia um helper com as regras jurídicas. A proposta diz que isso será
“mais escalável”, sem benchmark. Se a cópia falhar, conservar a última versão.
O orçamento e os recursos de operação para outro processo não foram informados.

`fixture/replica_model.py` (Python padrão, determinístico e sem I/O):

```python
SNAPSHOT = {"user-demo": True}
REFRESH_SECONDS = 86400
REVOKED_AT = 1

def core_allowed(t):
    return t < REVOKED_AT

def proposed_allowed(t, refresh_ok=True):
    if t >= REFRESH_SECONDS and refresh_ok:
        return core_allowed(t)
    return SNAPSHOT["user-demo"]
```

Não há implementação de transporte, autenticação de serviço, banco, cache real,
concorrência ou deploy. Avalie o que estes artefatos sustentam e o que falta.
