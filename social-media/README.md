# Social media — Fokus Cloud e produtos

Base de trabalho para planejar marketing e produzir conteúdo da Fokus Cloud e de seus produtos. Os prompts em `prompts/` têm como alvo o **Codex com acesso ao projeto**; copie o arquivo desejado, preencha os campos e forneça as referências disponíveis. Se outra ferramenta for usada, adapte o prompt às capacidades dela.

## Organização

- `prompts/`: modelos reutilizáveis para estratégia, planejamento, publicação, imagem, carrossel, stories, vídeo e análise.
- `produtos/`: espaços separados para Cloud, Styles e Law. Crie uma pasta adicional somente quando o produto ou a campanha forem pertinentes; a existência de uma pasta não prova que um produto esteja disponível.
- `modelos/plano-publicacao/`: estrutura a copiar para cada plano de publicação, com rascunhos, imagens aprovadas e registro de aprovação.

## Como trabalhar

1. Consulte as instruções atuais do projeto, as decisões da marca no Notion e fontes verificáveis do produto. Para Instagram do Law, consulte [Instagram | Estratégia Fokus Cloud](https://app.notion.com/p/4156af4c510981cdb148cef4166c28e8): o registro contém decisões de campanha, não comprova resultados ou publicação.
2. Defina produto, público, objetivo, canal, período, capacidade e materiais. Use os prompts conforme a tarefa; diferencie fatos, hipóteses e pendências. Não invente funcionalidades, preços, depoimentos ou métricas.
3. Copie `modelos/plano-publicacao/` para `produtos/<produto>/planos-publicacao/<AAAA-MM-DD--nome-do-plano>/`. Guarde estratégia, calendário e roteiros no próprio plano; peça aprovação antes de classificar qualquer peça como aprovada.
4. Guarde todas as imagens finais aprovadas em `imagens-aprovadas/` **do plano correspondente**, mantendo as versões de trabalho em `rascunhos/`. Registre no arquivo `aprovacoes.md` o nome exato do arquivo, data, responsável ou referência da aprovação, canal e versão. Para carrosséis, armazene todas as lâminas aprovadas e sua ordem; não substitua um arquivo aprovado sem registrar a nova versão.
5. Uma imagem aprovada para criação não comprova publicação nem autoriza agendamento, envio, anúncio ou gasto. Antes de qualquer ação externa, verifique a autorização específica aplicável e o estado efetivo da conta.

Use nomes como `2026-11-20_instagram_carrossel-contatos_lamina-01_v01.png`. Não inclua dados de clientes, credenciais, materiais sem direitos ou segredos. Para imagens grandes ou arquivos sensíveis, confirme o local de armazenamento adequado antes de adicioná-los ao Git; esta estrutura não configura armazenamento externo nem backups.

## Diretrizes e verificação

O público e as regras de um produto não são automaticamente válidos para outro. O registro do Instagram indica foco no Law para Advocacia e diretrizes próprias de formatos e identidade; consulte a versão mais recente antes de planejar. Vídeos e stories são formatos disponíveis nos modelos, não uma mudança das restrições de qualquer campanha existente.

Os arquivos são modelos editáveis, sem automação, criação de contas ou publicação. Foram revisados estruturalmente; o desempenho das estratégias e dos prompts depende de testes reais e dados observados.
