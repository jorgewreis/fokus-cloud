# Modelo de dados de Audiencias Law

O nucleo de Audiencias usa `law_hearings`, seus participantes, historico de
status, alertas, acessos externos e eventos externos. Todas as tabelas possuem
`company_id` e devem ser filtradas pela empresa e unidade ativa.

`law_hearings` guarda o registro operacional. `law_hearing_participants` usa
contatos reutilizaveis e snapshot minimo do nome. `law_hearing_status_history`
preserva a trilha de status. `law_hearing_external_accesses` armazena apenas o
hash do token, expiracao, revogacao e contador de acessos.

O portal externo deve retornar somente dados explicitamente autorizados e nunca
expor processo integral, documentos, contatos nao autorizados ou anotacoes.

## Contrato de concorrência da API existente

O GET de `/api/law/hearings/{id}` retorna `version`. O PATCH desse recurso e
o POST de `/api/law/hearings/{id}/status` exigem a versão inteira positiva lida
pelo cliente. Ausência ou formato inválido retorna 422, sem gravar; uma versão
desatualizada retorna 409, sem alteração nem evento de histórico.

Em 409, o cliente deve preservar a edição local e oferecer nova leitura e
reconciliação. Não reenviar automaticamente com a versão mais recente. A API,
seus dados, permissões e variantes judiciais foram preservados; ela não constitui
um módulo de Audiências para Advocacia. Não foram encontrados consumidores HTTP
nas interfaces versionadas, mas integrações externas precisam ser inventariadas
e validadas antes da liberação desse contrato.
