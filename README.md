<div align="center">

# Fokus Cloud

### Tecnologia para transformar operações complexas em experiências mais claras.

Plataforma modular que conecta produtos digitais especializados a uma base comum de identidade, empresas, acesso e evolução comercial.

[Conheça o ecossistema](https://www.fokuscloud.com.br/) · [Explore os produtos](https://www.fokuscloud.com.br/produtos) · [Fale com a equipe](mailto:contato@fokuscloud.com.br)

**Projeto ativo · versão `0.0.4-alpha.1` · produto em evolução**

</div>

<p align="center">
  <a href="https://www.fokuscloud.com.br/produtos/fokus-law">
    <img src="public/marketing/products/assets/fokus-law-hero.png" alt="Identidade visual do Fokus Law, produto jurídico do ecossistema Fokus Cloud" width="100%" />
  </a>
</p>

---

## O que é o Fokus Cloud

O Fokus Cloud é a plataforma que dá estrutura a uma família de produtos digitais. Sua arquitetura reúne recursos compartilhados — como contas, empresas, permissões e assinaturas — para que cada produto possa se concentrar nas necessidades reais de seu setor.

O ecossistema combina tecnologia de plataforma, ferramentas para desenvolvimento web e soluções verticais para operações jurídicas e imobiliárias. Cada frente evolui de acordo com seu estágio de implementação; esta página distingue o que já está disponível do que ainda está em desenvolvimento.

## Explore o ecossistema

| Produto | Para quem e para quê | Situação atual |
| --- | --- | --- |
| [**Fokus Law**](https://www.fokuscloud.com.br/produtos/fokus-law) | Gestão jurídica modular para organizações públicas, equipes jurídicas e escritórios de advocacia. | **Área pública e Gestão de Contatos disponíveis.** Os demais módulos estão em evolução e serão liberados conforme sua implementação e publicação. |
| [**Fokus Styles**](https://styles.fokuscloud.com.br/) | Framework CSS com tokens, temas, componentes e documentação para criar interfaces web consistentes. | **Framework e documentação públicos.** Distribuído separadamente sob licença MIT. |
| [**Fokus Lead**](https://www.fokuscloud.com.br/produtos/fokus-lead) | Conceito de gestão imobiliária para organizar clientes, leads, imóveis e oportunidades comerciais. | **Descoberta e planejamento do produto.** A página pública permite conhecer a proposta e registrar interesse; não representa disponibilidade dos módulos. |

### Fokus Law: organize relações e informação jurídica

O Fokus Law foi concebido para aproximar cadastros, equipes e rotinas jurídicas em uma experiência modular, com atenção a contexto, permissões e rastreabilidade. **A Gestão de Contatos é o núcleo funcional disponível hoje**: permite organizar pessoas, empresas e instituições, seus vínculos profissionais, documentos, canais e endereços.

A página pública apresenta os segmentos atendidos, a proposta dos módulos e as ofertas comerciais publicadas. Quando houver planos ativos no catálogo, visitantes podem comparar configurações e consultar preços atualizados. Módulos descritos como planejados não devem ser entendidos como funcionalidades já liberadas.

[Conheça o Fokus Law](https://www.fokuscloud.com.br/produtos/fokus-law) · [Consulte planos e ofertas](https://www.fokuscloud.com.br/produtos/fokus-law/planos)

### Fokus Styles: consistência para interfaces web

O Fokus Styles reúne uma base reutilizável para desenvolvimento de interfaces: tokens visuais, temas, componentes, estilos de layout e formulários, além de documentação pública. É um projeto independente dentro do ecossistema e possui licença MIT própria.

[Acesse o Fokus Styles](https://styles.fokuscloud.com.br/) · [Consulte o repositório](https://github.com/jorgewreis/fokus-styles)

### Fokus Lead: produto imobiliário em descoberta

O Fokus Lead explora uma experiência para corretores e equipes imobiliárias acompanharem clientes, imóveis e oportunidades em um contexto comercial conectado. A página apresenta a visão do produto e recebe manifestações de interesse para orientar sua evolução. Os módulos e planos ainda não são anunciados como disponíveis.

[Conheça a proposta do Fokus Lead](https://www.fokuscloud.com.br/produtos/fokus-lead)

## O que já está pronto

- **Presença pública do ecossistema:** site institucional, catálogo de produtos e páginas próprias para Fokus Law, Fokus Styles e Fokus Lead.
- **Fokus Law:** cadastro e acesso à plataforma, jornada pública de contratação e Gestão de Contatos funcional, com dados de pessoas e organizações, vínculos, documentos, endereços, canais de comunicação e controles de acesso.
- **Ofertas do Fokus Law:** consulta de planos e composição baseada no catálogo publicado. A disponibilidade de preços depende de ofertas vigentes; a página não deve exibir valores de demonstração como se fossem comerciais.
- **Fokus Styles:** framework CSS e documentação publicados em projeto separado, com licença MIT.
- **Fokus Lead:** página de apresentação e formulário público para registro de interesse. A solução funcional segue em descoberta e planejamento.
- **Base compartilhada:** recursos de identidade, empresas, permissões e assinaturas que sustentam a evolução integrada dos produtos.

Os recursos dos demais módulos jurídicos — como Gestão de Processos, Expedições, Tarefas e Audiências — fazem parte da direção modular do Fokus Law, mas sua descrição ou presença no catálogo não significa que estejam liberados para uso. A página do produto informa o estágio de cada módulo.

## Tecnologia

| Camada | Tecnologias |
| --- | --- |
| Aplicação e API | PHP 8.3+ e Laravel 13 |
| Dados | MySQL ou MariaDB |
| Interfaces | HTML, CSS e JavaScript; Vite e Tailwind CSS no fluxo de desenvolvimento |
| Qualidade e entrega | PHPUnit, verificações automatizadas e GitHub Actions |

## Acompanhe ou converse com a equipe

Este repositório oferece visibilidade sobre a implementação e a evolução técnica do Fokus Cloud. Para conhecer as experiências públicas, acesse [fokuscloud.com.br](https://www.fokuscloud.com.br/). Para dúvidas sobre licenciamento, parcerias ou interesse comercial, escreva para [contato@fokuscloud.com.br](mailto:contato@fokuscloud.com.br).

## Desenvolvimento local

O projeto requer PHP compatível com a aplicação, Composer, Node.js com npm e MySQL ou MariaDB. Em ambiente local configurado:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Para iniciar os serviços de desenvolvimento previstos no Composer, use:

```bash
composer run dev
```

## Navegação do repositório

| Caminho | Conteúdo |
| --- | --- |
| `app/`, `routes/` | Aplicação Laravel e rotas web/API. |
| `resources/`, `public/` | Views, interfaces públicas e assets dos produtos. |
| `database/` | Migrações, seeders e estrutura de dados. |
| `docs/04-products/` | Visão e escopo dos produtos. |
| `docs/08-commercial/` | Ofertas, planos, cadastro e regras comerciais. |
| `docs/` | Documentação técnica, de produto, segurança e operação. |

Para começar pela documentação, consulte o [índice](docs/README.md), a [visão do projeto](docs/01-overview/project-overview.md) e a [arquitetura do sistema](docs/03-architecture/system-architecture.md).

## Licença e uso

O código deste repositório está sob **licença proprietária**. A publicação no GitHub permite consulta e avaliação nos limites descritos em [`LICENSE.md`](LICENSE.md); não concede autorização para uso comercial, redistribuição, hospedagem ou criação de derivados. O Fokus Styles é uma exceção independente e segue a licença MIT de seu próprio repositório.

---

<div align="center">

**Fokus Cloud** · uma base comum para produtos que evoluem juntos.

</div>
