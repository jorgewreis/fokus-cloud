# Gestão de Contatos do Fokus Law

## Propósito

**Status do produto:** núcleo funcional e disponível. A base de Contatos é
compartilhada pela empresa e opera em um contexto ativo por vez: Advocacia /
Escritório (`escritorio`), Poder Público / Órgão Público (`orgao_publico`) ou
Poder Público jurídico / Judiciário (`judiciario`). Um administrador pode
alterar o contexto após conferir a prévia dos rótulos e sugestões; registros e
vínculos existentes são preservados.

O módulo independente **Gestão de Contatos**, exibido como **Contatos**, mantém
uma base reutilizável de pessoas / contatos (PF), organizações (PJ) e unidades
independentes. O módulo atende escritórios jurídicos e organizações públicas,
incluindo unidades judiciárias, Ministério Público, Defensoria Pública e polícia.
Ele só fica disponível para empresas cuja assinatura ativa inclua o componente
`contatos`.

## Escopo entregue

- Listagem pesquisável, filtros por tipo de registro, natureza PF/PJ, situação, profissão
  vinculada a pelo menos um contato e tag, paginação e detalhes do cadastro.
- Criação, edição, exclusão lógica e mesclagem auditada de contatos duplicados.
- Dashboard com totais da empresa, utilização da capacidade contratada e até
  cinco atividades recentes do usuário atual.
- Cadastro de organizações e unidades hierárquicas como registros próprios;
  cada registro pertence à mesma empresa, pode ter um pai imediato e vários
  filhos, sem limite de níveis. Unidades têm nome, canais e endereço próprios.
- Sigla opcional para pessoas jurídicas; profissão/vínculo profissional fica
  restrito a pessoas físicas. Documentos seguem os tipos permitidos para PF/PJ.
- Vínculos muitos-para-muitos entre pessoas físicas e empresas da mesma
  empresa proprietária, gerenciáveis nos dois cadastros. A ficha PF lista as
  empresas em linhas completas; a ficha PJ mostra somente o total de pessoas.
- Papéis padronizados por vínculo PF↔PJ (funcionário/colaborador, servidor
  público, representante legal, sócio, administrador/diretor, procurador ou
  outro) com períodos próprios. Cargo, posto, graduação ou função tem histórico
  independente, aceita várias designações simultâneas e vocabulário livre com
  sugestões reutilizáveis pela empresa (por exemplo, DPC, IPC, CB/PM e TEN/PM).
- Classificação principal sugerida pelo contexto e classificações secundárias;
  classificação e tipo institucional principal são editáveis. Identificadores
  institucionais, inclusive CNJ, são opcionais e a ausência gera aviso não
  bloqueante. OAB continua no documento existente.
- Sugestões de possíveis duplicidades no cadastro e análise paginada sob demanda
  na visão geral. A interface explica o tipo de correspondência sem exibir o
  valor coincidente, não bloqueia gravação e não mescla automaticamente.
- Indicadores acionáveis de contatos ativos próprios sem telefone/e-mail ou com
  dados institucionais incompletos; contatos excluídos e compartilhados ficam fora.
- Busca automática de endereços pelo CEP usando ViaCEP, com preenchimento de
  logradouro, bairro, município e UF quando retornados; o usuário pode concluir
  manualmente quando a consulta não localizar o CEP ou estiver indisponível.
- Tags reutilizáveis pela empresa, com sugestão e filtro.
- Acordos bilaterais de compartilhamento, definidos por natureza (PF/PJ),
  profissões atribuídas a contatos PF e campos autorizados.
- A exclusão de organização ou unidade exige que seus filhos sejam antes
  realocados ou excluídos, preservando a integridade da hierarquia.

## Páginas e estados

| Página/estado | Conteúdo e ações |
| --- | --- |
| Visão geral | Totais de pessoas, organizações e unidades; até cinco contatos recentes; pendências de qualidade e análise de duplicidades sob demanda. |
| Contatos | Busca, filtros, resultados, paginação, situação, origem compartilhada e ações permitidas. |
| Criar/editar | Formulário guiado por pessoa, organização ou unidade; contexto; classificações; profissões; vínculos e designações; dados institucionais; canais; endereços; hierarquia; tags e campos sensíveis autorizados. |
| Detalhes | Identificação contextual, profissões, vínculos, hierarquia, tags, canais, endereços, documentos e dados institucionais conforme a natureza e as permissões. |
| Mesclagem | Escolha do cadastro preservado, confirmação do motivo, transferência de relações e auditoria. |
| Compartilhamento | Configuração bilateral por empresa, natureza, profissão de PF e campos expostos; adesão e revogação auditadas. |
| Estados da página | Carregamento, vazio, sem resultados, erro de API, capacidade indisponível e aviso de limite. |

## Evoluções futuras

- Visões e preenchimentos rápidos específicos por variante de assinatura.
- Importação e exportação em lote após definir formato, permissões, prévia,
  proteção de dados sensíveis e tratamento de duplicidades.

## Composição visual

A página reaproveita o shell autenticado do Fokus Law, os controles `fs-*`, os
tokens `--fs-*` e o modal oficial `fs-modal` da versão instalada do Fokus
Styles. Os seletores `law-contact-*` em
`public/portal/assets/fokus-law-shell.css` limitam-se à composição do domínio:
grade da listagem, grupos repetíveis de endereço/canal/documento
e suas linhas aninhadas. Eles não recriam botões, campos, overlay, backdrop,
foco ou comportamento de modal do Fokus Styles.

## Tipo de contato, profissões e nomes

O contexto ativo define termos, rótulos e sugestões, sem alterar os dados
salvos. O tipo de contato é sempre Pessoa física (PF) ou Pessoa jurídica (PJ).
Advocacia usa Escritório / Filial; Poder Público usa Órgão / Unidade; Judiciário
usa Órgão judiciário / Unidade judiciária. Unidade é um registro próprio,
distinto de PF e PJ, com nome, canais e endereço próprios. Organizações e
unidades formam árvores com um pai imediato por registro filho e vários filhos
por pai, limitadas à empresa proprietária.

A categoria é opcional e usa uma única seleção para descrever o cadastro.
Ela não define papéis de vínculo. Papéis como cliente, servidor, colaborador e
usuário do serviço pertencem ao vínculo entre pessoa e organização e servem
somente ao cadastro.

Para Pessoa jurídica, a categoria principal descreve a organização cadastrada:
empresa privada, instituição financeira, instituição de ensino, organização da
sociedade civil, entidade de classe, cartório extrajudicial, órgão público,
polícia, Ministério Público, Defensoria Pública, escritório de advocacia ou
outra organização. Órgão público é uma dessas opções; não representa empresas,
bancos, escolas ou as demais pessoas jurídicas. Unidade judiciária não é
categoria: é um tipo dos dados institucionais e pode receber código CNJ.

PF/PJ define a natureza cadastral. Pessoa física pode receber categorias como
parte, testemunha, perito, representante, autoridade, servidor público,
fornecedor, prestador de serviço ou colaborador, além das sugestões próprias do
contexto. Pessoa física pode acumular profissões e
vínculos profissionais selecionados do catálogo da empresa ou das sugestões do
contexto. Pessoa jurídica tem campo opcional de sigla e não apresenta
profissão/vínculo. Seus documentos incluem CNPJ e inscrição estadual com UF.
Vínculos entre pessoa física e pessoa jurídica são muitos-para-muitos, ficam
restritos à mesma empresa proprietária e aparecem nos dois lados do cadastro.

O nome é obrigatório e normalizado para formato de nome próprio no servidor.
Conectivos como “de”, “dos” e “e” permanecem minúsculos no meio do nome; a
primeira palavra recebe inicial maiúscula. Sequências em caixa alta informadas
pelo usuário são preservadas para siglas como OAB e TJBA. A normalização não
substitui a conferência de nomes oficiais.

## Campos e limites

| Campo | Limite/regra |
| --- | --- |
| Nome | Obrigatório; pessoa: nome; organização: nome fantasia ou razão social; unidade: nome da unidade. |
| Sigla | Opcional, até 32 caracteres; apresentada ao lado do nome PJ. |
| Razão social/complemento | Opcional. |
| Vínculos empresariais | Relação muitos-para-muitos PF↔PJ entre cadastros ativos da mesma empresa; a ficha PF lista as empresas e a ficha PJ exibe somente o quantitativo de pessoas vinculadas. |
| Telefones | Até quatro por registro, inclusive unidade. |
| E-mails | Até dois por registro, inclusive unidade. |
| Endereços completos | Até dois por registro, inclusive unidade. |
| Documentos | Até quatro por contato; CPF para PF, CNPJ e inscrição estadual para PJ. CPF/CNPJ são opcionais, validados e únicos por empresa; inscrição estadual exige UF. |
| Profissões/vínculos | Uma ou mais opções cadastradas pela empresa; PF. |
| Tags | Até seis por contato; reutilizadas dentro da empresa. |
| Hierarquia | Um pai imediato por organização/unidade, vários filhos por pai e níveis ilimitados; relação restrita à empresa proprietária. |

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
dashboard e no módulo. O total consumido é cada registro disponível, incluindo
unidades. Excluir libera capacidade;
mesclar ou remover definitivamente registros libera capacidade conforme a
contagem vigente. A criação é recusada ao exceder o limite contratado.

## Compartilhamento entre empresas

Por padrão, nenhuma empresa acessa contatos de outra. O compartilhamento exige
que as duas empresas configurem e mantenham políticas recíprocas. Cada empresa
define o próprio escopo de saída; a relação só fica ativa quando as duas partes
aderem e ambas têm assinatura ativa com o componente Contatos publicado.

Cada escopo pode incluir pessoas jurídicas, pessoas físicas ou ambas. Para
pessoas físicas, é obrigatório selecionar profissões vinculadas a pelo menos
um contato da empresa. Assim, a empresa pode compartilhar, por exemplo,
somente pessoas físicas com profissão “Policial Civil” e também selecionar
pessoas jurídicas. O acordo vale para contatos atuais e futuros que atendam
ao escopo, salvo quando o contato estiver marcado para exclusão.

Cada parte controla somente os dados que sua empresa disponibiliza. O destino
consulta uma referência somente leitura; não recebe cópia, não
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

## Critérios de aceite

- Menu e dashboard exibem Contatos somente com módulo assinado e permissão de
  consulta.
- Os três contextos exibem rótulos e sugestões correspondentes; a troca mostra
  prévia e preserva registros e vínculos.
- Pessoas, organizações e unidades têm formulários adequados; unidades aceitam
  um pai da mesma empresa e a hierarquia suporta vários filhos e níveis.
- Categorias de referência permanecem distintas dos papéis dos vínculos.
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
