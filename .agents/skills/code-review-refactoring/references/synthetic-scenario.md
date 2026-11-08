# Ensaio C: PR fictício de paginação

## Pedido ao executor

Use code-review-refactoring para revisar a mudança base/head abaixo. O autor a
chama de “refactor para diminuir trabalho, sem alterar contrato”. Verifique os
consumidores e testes fornecidos. Somente leitura, comparação e testes sintéticos
em memória ou temporário, sem editar a aplicação ou implementar correção.
Caminhos e refs são rótulos fictícios, não arquivos/SHAs do Fokus.

## Contrato e consumidor

`fixture/contract.md`: listar apenas documentos ativos da empresa autenticada,
ordenados por `(created, id)`. `offset` e `limit` contam documentos visíveis dessa
empresa. Não mutar a entrada. A UI usa duas posições por página e interpreta página
vazia como fim. Identidade e empresa vêm de contexto confiável externo ao modelo.

`fixture/base.py`:

```python
def list_docs_base(rows, company, offset=0, limit=2):
    visible = [r for r in rows if r["company"] == company and not r["archived"]]
    return sorted(visible, key=lambda r: (r["created"], r["id"]))[offset:offset + limit]
```

`fixture/head.py`:

```python
def list_docs_head(rows, company, offset=0, limit=2):
    page = sorted(rows, key=lambda r: (r["created"], r["id"]))[offset:offset + limit]
    return [r for r in page if r["company"] == company and not r["archived"]]
```

`fixture/test_rows.py`:

```python
EXISTING = [
    {"id": "a1", "created": 1, "company": "A", "archived": False},
    {"id": "a2", "created": 2, "company": "A", "archived": False},
]
MIXED = [
    {"id": "b1", "created": 1, "company": "B", "archived": False},
    {"id": "a0", "created": 2, "company": "A", "archived": True},
    {"id": "a1", "created": 3, "company": "A", "archived": False},
    {"id": "a2", "created": 4, "company": "A", "archived": False},
    {"id": "a3", "created": 5, "company": "A", "archived": False},
]
```

`fixture/existing-test.md`: o teste fornecido compara IDs retornados para `EXISTING`,
empresa A, offset 0, limit 2, com `["a1", "a2"]`. Não há benchmark, ORM, rede, banco
ou prova de autorização fora desse modelo. Revise também a proposta de desempenho
sem atribuir ao produto real os resultados fictícios.
