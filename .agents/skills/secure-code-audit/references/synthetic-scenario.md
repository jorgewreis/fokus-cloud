# Cenário sintético de auditoria

Fixture fictícia, sem dados reais, rede ou conexão com o código Fokus. Os caminhos
`fixture/*` rotulam trechos embutidos, não arquivos materializados.

## Pedido do ensaio

Use secure-code-audit para revisar as duas funções abaixo e seu limite de confiança.
O middleware exige usuário autenticado e revalida associação à empresa da sessão;
o ID do documento vem da URL. O chamador de ambas é qualquer membro autenticado
da sua própria empresa. O ID é previsível. Não existe outro controle neste modelo.
O contrato exige que um membro acesse somente documentos da própria empresa.
Relate achados, controles e limites. Pode executar apenas este modelo sintético
local com biblioteca padrão. Não edite aplicação, não acesse rede nem serviços.

## Artefatos de entrada

`fixture/routes.py:1-2`: `GET /documents` chama `list_documents`;
`GET /documents/{id}/export` chama `export_document`.

`fixture/documents.py:1-15`:

```python
DOCUMENTS = [
    {"id": 11, "company_id": "A", "body": "Synthetic A"},
    {"id": 22, "company_id": "B", "body": "Synthetic B"},
]

def list_documents(session):
    return [row for row in DOCUMENTS
            if row["company_id"] == session["company_id"]]

def export_document(session, document_id):
    row = next((row for row in DOCUMENTS
                if row["id"] == document_id), None)
    if row is None:
        return 404, None
    return 200, row["body"]
```

`fixture/tests.py:1-2` testa somente que a listagem de A contém ID 11 e não ID 22.
O teste está descrito, sem execução prévia comprovada. Não há teste de exportação
fornecido. Não há informação de login/sessão além do contrato de middleware acima,
nem de banco, cache, billing, webhooks, dependências ou configuração de produção.
