# Consulta de endereços ViaCEP no Fokus Law

## Fluxo

O formulário de contatos aguarda a entrada de oito dígitos no CEP e consulta a
rota autenticada `GET /api/law/addresses/cep/{postalCode}`. O navegador chama
somente a API da própria aplicação; o servidor consulta o serviço público
ViaCEP em `https://viacep.com.br/ws/{cep}/json/`.

O controlador aplica timeout de conexão de dois segundos e timeout total de
cinco segundos. A rota usa o throttle `law-cep-lookup` (30 consultas por
minuto) e retorna somente CEP, logradouro, complemento, bairro, município e UF.
O formato de entrada precisa conter oito dígitos. CEP inexistente retorna
`{"erro":true}`; falha ou indisponibilidade do fornecedor retorna HTTP 502.

## Comportamento do formulário

- A busca é acionada após o CEP completo, com debounce, e cancelada se o valor
  mudar durante uma consulta.
- Valores disponíveis preenchem logradouro, complemento, bairro, município e
  UF; o país recebe Brasil.
- Bairro, município, UF e país só ficam desabilitados quando a resposta contém
  bairro, município e UF válidos.
- CEP inexistente ou falha de rede mantém os campos editáveis e informa que o
  endereço pode ser preenchido manualmente.
- Logradouro, município e UF são obrigatórios no cadastro, independentemente
  do resultado da consulta.

## Limites e privacidade

O CEP é enviado ao serviço ViaCEP para obter dados postais. A integração não
consulta nem valida pessoas ou empresas e não armazena o conteúdo integral da
resposta externa. A interface não chama diretamente o domínio ViaCEP, então a
política CSP do navegador não precisa autorizar uma conexão externa.
