# API do catalogo

## Fonte e responsabilidade

A API deve consultar o banco de dados e ser a unica fonte consumida pelo backoffice e pelo catalogo publico. Nenhum cliente deve depender de uma lista de nomes ou precos duplicada em JavaScript.

Para o Fokus Law, cada modulo retornado deve incluir `module_code`,
`segment_code`, `context_code`, `variant_code`, `capabilities`,
`dependencies` e `incompatibilities`, permitindo diferenciar a oferta para
advocacia e setor publico sem duplicar o nucleo tecnico. O contexto diferencia
Varas Criminais, Varas Civeis, Juizados, Cartorios e orgaos administrativos.

## Catalogo do backoffice

Endpoint administrativo:

```http
GET /api/backoffice/catalog
```

Requer autenticacao de administrador da plataforma.

Resposta resumida:

```json
{
  "contract_version": "0.1.0",
  "products": [
    {
      "id": "PRD...",
      "code": "law",
      "name": "Fokus Law",
      "status": "ativo",
      "published_catalog_version": 1,
      "publication_pending": false,
      "modules": [],
      "plans": [
        {
          "id": "PLN...",
          "code": "law-advocacia",
          "name": "Advocacia",
          "modules": []
        }
      ]
    }
  ]
}
```

O endpoint considera produtos, funcionalidades e planos cadastrados, agrupa as
funcionalidades do plano e calcula o valor mensal a partir da composicao
cadastrada quando o plano nao possuir preco proprio.

## Endpoints de escrita

| Endpoint | Uso |
| --- | --- |
| `POST /api/backoffice/catalog/products` e `PATCH /api/backoffice/catalog/products/{product}` | Criar e editar sistemas comerciais. |
| `POST /api/backoffice/catalog/modules` e `PATCH /api/backoffice/catalog/modules/{module}` | Criar e editar funcionalidades. |
| `POST /api/backoffice/catalog/plans` e `PATCH /api/backoffice/catalog/plans/{plan}` | Criar e editar planos. |
| `PUT /api/backoffice/catalog/plans/{plan}/modules` | Atualizar composicao do plano. |
| `POST /api/backoffice/catalog/{product}/publish` | Publicar snapshot versionado. |
| `POST /api/backoffice/catalog/{type}/{id}/pause` | Pausar item publicado. |
| `POST /api/backoffice/catalog/{type}/{id}/archive` | Arquivar item publicado. |

Criacao e edicao exigem `platform.catalog.manage`. Publicacao, pausa e
arquivamento exigem `platform.catalog.publish`.

Produtos, funcionalidades e planos so aceitam edicao quando estao pausados ou
inativos. A alteracao ou pausa marca o catalogo do produto como pendente. Depois
de ativar e publicar os itens alterados, o superadministrador publica uma nova
versao pelo endpoint do produto. Enquanto a publicacao estiver pendente, o
catalogo publico bloqueia novas contratacoes. A publicacao pode ser enviada sem
`reason`; o Backoffice registra um motivo padrao na auditoria.

## Catalogo publico

O endpoint publico retorna somente a ultima versao publicada:

```http
GET /api/catalog/{product}
```

Contrato `0.1.0`, em forma resumida:

```json
{
  "contract_version": "0.1.0",
  "published_version": 1,
  "published_at": "2026-09-02 10:00:00",
  "product": {
    "code": "law",
    "name": "Fokus Law"
  },
  "modules": [
    {
      "code": "processos-advocacia",
      "name": "Gestao de Processos para Advogados",
      "monthly_amount": 29.9
    }
  ],
  "plans": [
    {
      "code": "law-advocacia",
      "name": "Fokus Law - Advocacia",
      "module_codes": ["processos-advocacia"],
      "monthly_amount": 94.9,
      "annual_amount": 949
    }
  ]
}
```

Registros pausados, arquivados, em rascunho ou editados depois da ultima
publicacao nao sao usados pelo catalogo publico nem pelo checkout ate nova
publicacao.

Em caso de falha de leitura, a aplicacao deve retornar erro controlado e o frontend deve informar indisponibilidade. Nao e permitido usar dados simulados ou o catalogo legado como fallback.

## Cache

O snapshot versionado em `catalog_publications` e a fonte da leitura publica. Se
cache de leitura for adicionado, ele deve ser invalidado no momento da
publicacao.
## Operações administrativas do Marco 4

Planos e funcionalidades expõem DELETE físico somente sem dependências; publicações permitem DELETE apenas da versão corrente, restaurando a anterior. Vouchers expõem PATCH antes do primeiro resgate, archive e consulta detalhada de resgates/reservas. Erros de dependência são 422 explicativos. O contrato público continua 0.0.3.
