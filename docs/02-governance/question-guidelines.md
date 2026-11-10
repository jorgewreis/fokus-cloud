# Diretrizes de perguntas e decisoes

## Regra principal

Quando for necessario fazer perguntas ao responsavel pelo projeto, as perguntas devem ser feitas uma de cada vez.

Investigar primeiro código e documentação. Em tarefa com implementação já
autorizada, resolver decisões técnicas reversíveis dentro do escopo sem novas
confirmações. Na [operação autônoma](../09-operations/julia-night-operations.md),
registrar decisões materiais sem resposta como bloqueios e concluir o trabalho
independente possível; não interromper por perguntas opcionais.

## Formato das perguntas

Cada pergunta deve apresentar ate 5 respostas sugeridas, com uma resposta marcada como recomendada.

Quando houver mais de uma resposta tecnicamente forte, as 2 melhores opcoes devem ser explicadas com mais profundidade, indicando por que podem ser ideais para o Fokus Cloud.

## Limite por ciclo

Um ciclo de levantamento nao deve ultrapassar 20 perguntas. Se forem necessarias mais decisoes, criar um novo ciclo com objetivo claro.

## Criterios para recomendar uma resposta

A resposta recomendada deve considerar:

- arquitetura correta para longo prazo;
- manutencao e clareza;
- seguranca e isolamento de dados;
- compatibilidade com Fokus Cloud, Fokus Law e Fokus Lead;
- impacto no banco de dados;
- impacto comercial, quando houver;
- custo futuro de mudanca.

## Modelo de pergunta

```text
Pergunta 1: Qual deve ser o escopo inicial do modulo?

A. Apenas a funcionalidade minima para validar o fluxo.
B. Funcionalidade completa do modulo.
C. Base tecnica com dados, permissoes e telas principais. (Recomendado)
D. Apenas prototipo visual.
E. Adiar o modulo ate concluir outra etapa.
```

## A complementar

Registrar aqui ajustes no metodo de perguntas conforme o projeto evoluir.
