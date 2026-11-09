# Ensaio B: testes fictícios dependentes da ordem

## Pedido ao executor

Use testing-quality-engineering para diagnosticar os resultados da suíte abaixo e
propor a menor correção com cobertura adequada. Pode executar o código em memória
ou diretório temporário isolado usando Python padrão. Não edite os artefatos originais,
a aplicação Fokus ou o contrato. Não instale pacotes, durma ou use rede.
Os caminhos são rótulos de arquivos fictícios; não existe commit de produto associado.

## Contrato e artefatos

`fixture/contract.md`: uma concessão está ativa somente quando `now < expires_at`.
No instante exato da expiração já está inativa. Cada caso deve representar um ensaio
independente, sem depender da ordem de execução dos outros casos.

`fixture/test_grants.py`:

```python
class Clock:
    def __init__(self):
        self.now = 100
    def advance(self, seconds):
        self.now += seconds

clock = Clock()

def active(expires_at):
    return clock.now < expires_at

def test_fresh():
    assert active(105) is True

def test_expired():
    clock.advance(6)
    assert active(105) is False
```

`fixture/runs.txt`:
- Processo novo, `test_fresh`, depois `test_expired`: 2 passaram.
- Processo novo, `test_expired`, depois `test_fresh`: 1 passou, `test_fresh` falhou.
- Processo novo, somente `test_fresh`: 1 passou.

Os registros são entradas fictícias para confrontar com a execução. Não há dados
reais, implementação de persistência, scheduler ou integração. O runtime do produto
não foi fornecido. Apresente causa sustentada, correção proposta e o que permanece
sem prova; não altere `active` apenas para fazer a suíte passar.
