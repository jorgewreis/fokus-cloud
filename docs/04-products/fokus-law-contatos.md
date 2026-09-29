# Gestão de Contatos do Fokus Law

## Propósito

O módulo independente **Gestão de Contatos**, exibido como **Contatos**, mantém
uma base reutilizável de pessoas físicas, pessoas jurídicas, órgãos e
instituições. O módulo atende escritórios jurídicos e organizações públicas,
incluindo unidades judiciais, Ministério Público, Defensoria Pública e polícia.
Ele só fica disponível para empresas cuja assinatura ativa inclua o componente
`contatos`.

## Escopo entregue

- Listagem pesquisável, filtros por natureza PF/PJ, situação, classificação e
  tag, paginação e detalhes do cadastro.
- Criação, edição, inativação e mesclagem auditada de contatos duplicados.
- Dashboard com totais da empresa, utilização da capacidade contratada e até
  cinco atividades recentes do usuário atual.
- Cadastro independente de departamentos de uma PJ, cada qual com canais
  próprios e contabilizado como uma unidade adicional da capacidade.
- Sigla opcional para pessoas jurídicas; profissão/vínculo profissional e
  documentos ficam restritos a pessoas físicas no formulário e na ficha.
- Vínculos muitos-para-muitos entre pessoas físicas e empresas da mesma
  empresa proprietária, visíveis de forma recíproca no cadastro e na ficha.
- Busca automática de endereços pelo CEP usando ViaCEP, com preenchimento de
  logradouro, bairro, município e UF quando retornados; o usuário pode concluir
  manualmente quando a consulta não localizar o CEP ou estiver indisponível.
- Tags reutilizáveis pela empresa, com sugestão e filtro.
- Regras de compartilhamento entre empresas por destinatário e classificações.
- Endpoints próprios preparados para vínculos futuros com Processos,
  Expedições e Tarefas; esses módulos não são pré-requisito para o cadastro.

## Páginas e estados

| Página/estado | Conteúdo e ações |
| --- | --- |
| Visão geral | Totais PF, PJ, departamentos e cadastros contabilizados; até cinco contatos recentes da pessoa usuária. |
| Contatos | Busca, filtros, resultados, paginação, situação, origem compartilhada e ações permitidas. |
| Criar/editar | Formulário guiado por PF/PJ, profissões cadastradas ou novas, sigla e empresas/pessoas vinculadas, canais, endereços, departamentos, tags e campos sensíveis autorizados. |
| Detalhes | Identificação e sigla, profissões, empresas/pessoas vinculadas, tags, canais, endereços, documentos e departamentos conforme a natureza e as permissões. |
| Mesclagem | Escolha do cadastro preservado, confirmação do motivo, transferência de relações e auditoria. |
| Compartilhamento | Seleção de empresas elegíveis, classificações e campos expostos; gravação e revogação auditadas. |
| Estados da página | Carregamento, vazio, sem resultados, erro de API, capacidade indisponível e aviso de limite. |

## Composição visual

A página reaproveita o shell autenticado do Fokus Law, os controles `fs-*`, os
tokens `--fs-*` e o modal oficial `fs-modal` da versão instalada do Fokus
Styles. Os seletores `law-contact-*` em
`public/portal/assets/fokus-law-shell.css` limitam-se à composição do domínio:
grade da listagem, grupos repetíveis de endereço/canal/documento/departamento
e suas linhas aninhadas. Eles não recriam botões, campos, overlay, backdrop,
foco ou comportamento de modal do Fokus Styles.

## Natureza, profissões e nomes

PF/PJ define a natureza cadastral. Pessoa física pode acumular profissões e
vínculos profissionais selecionados do vocabulário da empresa ou adicionados
como novas especificações (por exemplo, Policial Civil ou Guarda Municipal).
Pessoa jurídica tem campo opcional de sigla e não apresenta profissão/vínculo.
Seus documentos incluem CNPJ e inscrição estadual com UF. Departamentos são
exclusivos de PJ.
Vínculos entre pessoa física e pessoa jurídica são muitos-para-muitos, ficam
restritos à mesma empresa proprietária e aparecem nos dois lados do cadastro.
Papéis processuais específicos pertencem aos vínculos com processos, não ao
contato global.

O nome é obrigatório e normalizado para formato de nome próprio no servidor.
Conectivos como “de”, “dos” e “e” permanecem minúsculos no meio do nome; a
primeira palavra recebe inicial maiúscula. Sequências em caixa alta informadas
pelo usuário são preservadas para siglas como OAB e TJBA. A normalização não
substitui a conferência de nomes oficiais.

## Campos e limites

| Campo | Limite/regra |
| --- | --- |
| Nome | Obrigatório; PF: nome da pessoa; PJ: nome fantasia ou razão social. |
| Sigla | Opcional, até 32 caracteres; apresentada ao lado do nome PJ. |
| Razão social/complemento | Opcional. |
| Vínculos empresariais | Relação muitos-para-muitos PF↔PJ entre cadastros ativos da mesma empresa; a ficha de cada lado exibe os contatos relacionados. |
| Telefones | Até quatro por contato; cada departamento PJ também aceita até quatro. |
| E-mails | Até dois por contato; cada departamento PJ também aceita até dois. |
| Endereços completos | Até dois por contato. |
| Documentos | Até quatro por contato; CPF para PF, CNPJ e inscrição estadual para PJ. CPF/CNPJ são opcionais, validados e únicos por empresa; inscrição estadual exige UF. |
| Profissões/vínculos | Uma ou mais opções cadastradas pela empresa; PF. |
| Tags | Até seis por contato; reutilizadas dentro da empresa. |
| Departamentos PJ | Sem teto funcional fixo; cada departamento consome uma unidade contratada adicional. |

Telefone/e-mail pessoal, endereço residencial e notas são dados sensíveis.
Os documentos são armazenados criptografados; o fingerprint de CPF/CNPJ
é usado para deduplicação sem pesquisa em texto aberto. A API mascara os
documentos e oculta campos sensíveis para perfis sem `law.contacts.sensitive.view`.

### Consulta de CEP (ViaCEP)

O formulário consulta o ViaCEP quando o CEP contém oito dígitos. O navegador
chama o endpoint autenticado `GET /api/law/addresses/cep/{postalCode}`; o
servidor valida o formato, consulta `https://viacep.com.br/ws/{cep}/json/` com
timeouts de conexão e resposta e devolve somente CEP, logradouro, complemento,
bairro, município e UF. A rota limita chamadas por usuário. Quando a resposta
for válida, os campos retornados são preenchidos; bairro, município, UF e país
ficam bloqueados somente se bairro, município e UF vierem completos. CEP não
localizado ou indisponibilidade mantém o endereço editável e orienta o
preenchimento manual. Município, UF e logradouro continuam obrigatórios.

## Duplicidade e mesclagem

Nome, telefone e e-mail não bloqueiam cadastro. CPF/CNPJ válidos são
normalizados e verificados dentro da empresa. Ao detectar duplicidade, a API
retorna conflito para que a pessoa usuária consulte o cadastro existente; a
mesclagem é uma ação separada, exige permissão, natureza igual, motivo e
auditoria. O cadastro de destino é mantido e os vínculos e dados filhos são
transferidos sem duplicar classificações ou tags já existentes.

## Tags e capacidade

Tags pertencem à empresa e são sugeridas em cadastro e filtro. Espaços externos
são removidos e caixa é normalizada para impedir tags equivalentes com nomes
diferentes. Administradores podem acompanhar a utilização contratada no
dashboard e no módulo. O total consumido é contatos ativos ou inativos ainda
cadastrados mais departamentos vinculados. Inativar não libera capacidade;
mesclar ou remover definitivamente registros libera capacidade conforme a
contagem vigente. A criação é recusada ao exceder o limite contratado.

## Compartilhamento entre empresas

O administrador da origem define uma política para uma empresa destinatária
e seleciona uma ou mais classificações. A política vale para contatos atuais
e futuros que tenham a classificação selecionada, salvo quando o próprio
contato estiver marcado para exclusão do compartilhamento. Só empresas ativas
com assinatura ativa e componente Contatos publicado podem ser destinatárias.

O destino consulta uma referência somente leitura; não recebe cópia, não
consome sua capacidade e não ganha acesso ao histórico, tags, notas ou vínculos
da origem. Por padrão, a origem pode expor canais profissionais/institucionais;
endereços comerciais e documentos são campos opcionais da regra. Documentos
continuam condicionados à permissão sensível do destino. Canais pessoais e
endereços residenciais nunca são compartilhados. Revogar a regra ou perder a
elegibilidade da assinatura remove o acesso na próxima consulta. A auditoria
fica na empresa de origem.

## Permissões

As permissões `law.contacts.view`, `create`, `update`, `delete`,
`sensitive.view`, `merge`, `shared.view` e `share.manage` são independentes.
Elas aparecem no catálogo de perfis do Fokus Law e só têm efeito com o módulo
habilitado. Perfis padrão: administrador e chefe/escrivão recebem consulta
sensível e mesclagem; operador recebe criação/alteração de dados comuns; leitor
recebe somente consulta. `shared.view` é concedida por padrão aos perfis que
podem consultar contatos. `share.manage` fica reservada ao administrador da
empresa, que também pode delegá-la a um perfil personalizado.

## Relações com outros módulos

Processos, Expedições e Tarefas poderão referenciar o ID estável do contato em
endpoints próprios. Vínculos contextuais armazenam seu papel e respeitam o
sigilo do processo. Snapshot de expedição preserva o destinatário utilizado
na emissão. A integração operacional desses módulos é uma etapa futura.

## Critérios de aceite

- Menu e dashboard exibem Contatos somente com módulo assinado e permissão de
  consulta.
- PF/PJ personaliza o formulário; departamentos são aceitos apenas em PJ.
- PJ pode informar sigla e consultar vínculos recíprocos com várias PFs; PF pode
  consultar vínculos com várias PJs.
- CEP completo aciona a integração ViaCEP; endereço segue preenchível quando a
  consulta não localiza o CEP ou está indisponível.
- Os limites de campos, capacidade e duplicidade são validados no servidor.
- Perfis sem acesso sensível não leem nem sobrescrevem valores ocultos.
- Compartilhamento não altera o cadastro de origem e é revogável/auditável.
- Todos os endpoints verificam empresa, assinatura, permissão e propriedade.
- Interfaces funcionam em telas desktop, tablet e celular e mostram seus
  estados de carregamento, vazio e erro.
