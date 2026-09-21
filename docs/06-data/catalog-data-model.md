# Modelo de dados do catalogo

## Tabelas principais

### `products`

Representa os sistemas comercializados.

Campos principais:

- `id`: identificador prefixed ULID;
- `code`: codigo tecnico unico;
- `name`: nome exibido;
- `active`: indicador atual de atividade;
- `status`: estado operacional;
- `published_catalog_version`: ultima versao técnica do catálogo, mantida para
  preservar o snapshot público; não representa publicação do produto;
- descricoes tecnica e comercial;
- ordem de exibicao unica entre produtos, com reordenacao automatica em caso
  de colisao;
- `created_at` e `updated_at`.

### `plans`

Representa os planos de cada sistema.

- `product_id` referencia `products.id`;
- `code` e unico dentro do sistema;
- `name` e o nome exibido.
- `technical_description` e `commercial_content` separam operacao e oferta;
- `status` e `publication_state` separam disponibilidade operacional e
  publicacao;
- `display_order`, `featured` e `monthly_amount` controlam exibicao e preco
  configurado.

Para os planos do produto `lead`, deve existir um campo de linha/segmento com os valores `one` ou `team`. O codigo completo recomendado inclui a linha, por exemplo `lead-one-essencial`.

A combinacao `product_id` + `code` e unica.

### `modules`

Representa funcionalidades vendaveis ou componiveis.

- `product_id` referencia o sistema;
- `code` e unico dentro do sistema;
- `module_code` identifica o nucleo tecnico (`processos`, `contatos`,
  `expedicoes` ou `tarefas`);
- `segment_code` identifica o publico (`advocacia` ou `setor_publico`);
- `context_code` identifica o ambiente operacional;
- `variant_code` identifica a combinacao comercial estavel;
- `name` e o nome exibido;
- `monthly_price` e o preco mensal em BRL.
- `publication_state`, ordem, destaque, capacidade, dependencias e
  incompatibilidades permitem validar a publicacao.

### `plan_modules`

Tabela associativa entre planos e funcionalidades. Possui chave primaria composta por `plan_id` e `module_id`.

As chaves estrangeiras garantem que a relacao exista e que a exclusao de um plano ou funcionalidade nao deixe associacoes orfas.

### `catalog_publications`

Representa um snapshot técnico do catálogo por produto.

- `product_id` referencia o sistema;
- `version` e sequencial dentro do produto;
- `snapshot` guarda produto, funcionalidades, planos, precos e composicoes;
- `published_by_platform_admin_id` identifica o administrador responsável por
  gerar a versão técnica;
- `reason` registra a justificativa;
- `published_at` registra a data efetiva.

## Integridade

O backend deve validar:

- plano pertencente ao sistema informado;
- funcionalidade pertencente ao mesmo sistema do plano;
- ao menos uma funcionalidade em plano publicavel;
- precos nao negativos;
- codigos unicos;
- dependencias e incompatibilidades;
- exclusao fisica somente quando nao houver vinculos.

## Historico comercial

Alteracoes de preco, composicao e limites nao devem modificar o historico de assinaturas ou resgates. Esses registros devem armazenar os valores aplicados no evento.

O catalogo mantem versoes publicadas em `catalog_publications` para consulta
historica. A restauracao automatica de uma versao anterior nao faz parte da
regra aprovada.

## Evolucao estrutural necessaria

O Marco 3 adiciona ou normaliza:

- descricao tecnica e conteudo comercial;
- status comercial e estado de publicacao;
- ordem de exibicao e destaque;
- linha comercial do Lead;
- precos e descontos por ciclo;
- limites, unidades e opcoes de capacidade;
- dependencias e incompatibilidades;
- capacidades comerciais para diferenciar usos do mesmo modulo, quando aplicavel;
- tipos e instancias operacionais por setor para `expedicoes`, com sequencias de numeracao independentes quando aplicavel;
- guias de execucao comum e penal como tipos ou capacidades de `expedicoes`;
- versoes publicadas.

Agendamento de publicacao permanece fora da `0.1`.

## Carga inicial

`database/seeders/DatabaseSeeder.php` contem a carga inicial de sistemas, funcionalidades, planos e associacoes. Ele nao deve ser tratado como a interface definitiva de manutencao comercial quando o gerenciamento pelo backoffice estiver disponivel.
