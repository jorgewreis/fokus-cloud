# Cenário sintético de pesquisa

Fixture inteiramente fictícia, sem conexão com versões reais do Fokus. Os artefatos
abaixo são todo o contexto disponível; os caminhos `fixture/*` são rótulos dos
trechos embutidos, não arquivos materializados. Não consulte rede nem instale ferramentas.

## Pedido do ensaio

Use engineering-research para decidir como limitar a espera a 250 milissegundos em
uma chamada de ExampleClient, sem atualizar dependências. Entregue recomendação,
fontes/versões, fatos e hipóteses, validação local segura e limites. Não edite produto.

## Artefatos de entrada

- `fixture/project.toml:1`: dependência `example-client = ">=1.0,<2.0"`.
- `fixture/lock.json:1`: versão resolvida `1.4.0`.
- `fixture/ci.yml:1`: Python `3.12`; não há log da execução.
- `fixture/caller.py:1`: `client.fetch("sample")`.
- `fixture/docs-current.md:1-2`: manual fictício da versão **2.0**, assinatura
  `fetch(key, *, timeout_ms=1000)`. Timeout em milissegundos.
- `fixture/docs-1.4.md:1-2`: manual fictício arquivado **1.4**, assinatura
  `fetch(key, *, timeout_s=1.0)`. Timeout em segundos.
- `fixture/upstream-1.4.py:1-4`: fonte fictícia identificada pela tag `v1.4.0`:

```python
def fetch(key, *, timeout_s=1.0):
    if timeout_s <= 0:
        raise ValueError("timeout_s must be positive")
    return {"key": key, "timeout_s": timeout_s}
```

O runtime instalado do produto é desconhecido. A função é um double sem rede:
executá-la demonstra somente contrato de argumentos, não temporização real.
Não há entrada ExampleClient no catálogo FEKS. As fontes fictícias fornecidas
servem apenas como evidência interna deste ensaio, nunca como autoridade externa.
