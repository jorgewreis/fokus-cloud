# Páginas de vendas e ofertas personalizadas do Fokus Law

## Percurso público

- `/` apresenta um resumo do preço mensal mínimo do Fokus Law apenas quando a
  API retorna planos ou módulo Contatos avulso publicados.
- `/produtos/fokus-law` apresenta a narrativa do produto, públicos atendidos,
  módulos e cenas de dados. Jurídico é um público atendido, não uma oferta de
  segmento adicional. Ofertas aparecem agrupadas pelos segmentos publicados.
- `/produtos/fokus-law/planos` compara planos por segmento, permite selecionar
  Gestão de Contatos avulsa, escolher módulos adicionais elegíveis e configurar
  faixas de capacidade publicadas.
- `/contratar/fokus-law` exige conta, e-mail confirmado e empresa ativa. Exibe
  a cotação mais recente do servidor e inicia o checkout do Mercado Pago.

As páginas não incorporam preços de demonstração. Catálogo ausente, pendente,
vazio ou com erro resulta em estado de indisponibilidade sem preço. A equipe
comercial deve cadastrar, revisar e publicar o produto, planos, módulos
avulsos e faixas pelo Backoffice antes da divulgação dos preços.

## Composição e preço

`POST /api/catalog/fokus-law/quote` é a cotação pública sem efeitos colaterais. Os
valores calculados no navegador servem apenas para selecionar códigos de
catálogo. A API e o checkout usam os valores da última versão comercial
publicada.

- Plano: preço-base publicado + preço integral dos módulos extras disponíveis
  para contratação avulsa + diferenças entre a faixa escolhida e a faixa-base
  incluída no plano.
- Módulos avulsos: soma dos módulos publicados que permitem contratação
  independente e suas personalizações selecionadas.
- Um módulo incluído no plano não recebe cobrança adicional como módulo extra.
- A cobrança anual corresponde a dez mensalidades segundo a regra central de
  billing.
- Dependências, incompatibilidades, plano, módulo independente e faixa mínima
  são revalidados tanto na cotação quanto no checkout/alteração.

Há uma assinatura não encerrada por empresa e produto. A contratação de
Contatos não cria uma segunda assinatura para o Fokus Law. Uma empresa com
assinatura existente segue para a gestão da composição atual; o conflito do
checkout aponta para a área de assinatura.

## Capacidade acima do catálogo

Ao selecionar capacidade acima da maior faixa publicada, a tela deixa de
oferecer um preço calculado e abre o formulário de proposta. O pedido registra
produto, segmento, módulo, capacidade desejada, maior faixa pública disponível,
contexto do pedido e consentimento. A equipe consulta os dados em
`/backoffice/interesses`; o fluxo não cria uma oferta comercial nem promete um
preço fora do catálogo.

## Continuidade de cadastro

A página de planos mantém a composição em armazenamento local sem guardar
preços como autoridade. O cadastro transmite um destino de retorno fechado
(``/contratar/fokus-law``); a confirmação de e-mail retorna à página de revisão
e o servidor recota antes de checkout. Quem já tem conta entra pelo login do
Fokus Law e retorna à revisão. Uma seleção indisponível deve ser substituída
pelas opções do catálogo vigente.

## Direção visual e acessibilidade

A página de vendas preserva a tipografia, componentes e cores atuais do Fokus
Law. A página de preços separa narrativa comercial e decisão de compra, com
seções conectadas por movimentos pontuais e parallax seletivo na visualização
de dados. Interações de preço e limites atualizam a cotação. A implementação
usa APIs nativas do navegador, pausa fora da viewport e respeita
`prefers-reduced-motion`; conteúdo e controles permanecem utilizáveis sem
animação.
