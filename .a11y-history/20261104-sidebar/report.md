# Web Accessibility Audit

An audit of web content, run on 2026-11-04. Read the executive summary for the verdict, the priorities section for what to do first, and the findings tables for detail.

## Metadata

What was audited, with what, and under which settings.

| Field | Value |
|---|---|
| Date | 2026-11-04 |
| Scope | 3 items |
| Skills run | aria-specialist, keyboard-navigator |
| Tooling | node v24.13.0, a11y-mcp-server 7.1.0 |
| Scan configuration | defaults, no config file found |
| Checks counted | 16 |

Audited:

- `public/portal/assets/fokus-law-shell.css`
- `public/portal/assets/fokus-law-shell.js`
- `resources/views/portal/fokus-law.blade.php`

## Executive summary

Pass. No critical or serious findings.

The headline numbers for this run.

| Measure | Value |
|---|---|
| Score | 100 out of 100 |
| Grade | A |
| Findings | 0 |
| Blocking (critical plus serious) | 0 |
| Verdict | Pass |

## Severity breakdown

Counts by severity, highest impact first.

| Severity | Count | What it means |
|---|---:|---|
| critical | 0 | Blocks a person from completing the task |
| serious | 0 | Major barrier with a difficult workaround |
| moderate | 0 | Real friction, workaround exists |
| minor | 0 | Noticeable but rarely blocking |

## Findings

No findings. Every check listed under "What passed" ran and came back clean.

## Remediation priorities

Nothing to remediate.

## What passed

16 checks ran and found nothing.

- A navegação possui nome acessível e a página selecionada recebe aria-current.
- Account actions use native links or buttons with ordinary Tab navigation.
- Both selected and unselected Contatos activation handlers focus the content region after rebuilding navigation.
- Closed mobile sidebar is inert; breakpoint changes synchronize inert state for desktop access.
- Collapsed module panels and closed account panels use hidden backed by an explicit display:none !important rule.
- Escape closes the account disclosure before closing mobile navigation and restores focus to the correct trigger.
- Escape fecha o painel da conta e retorna o foco ao botão que o abriu.
- Mobile sidebar Tab and Shift+Tab cycle between visible focusable endpoints; Escape provides an exit.
- Module disclosure triggers use native buttons with aria-controls and synchronized aria-expanded.
- O controle da conta usa botão nativo com aria-controls válido e aria-expanded sincronizado.
- O painel da conta usa links e botões nativos sem papéis de menu que exigiriam interação adicional por setas.
- Os grupos de módulos usam botões HTML nativos com nomes visíveis.
- Os painéis recolhidos usam hidden e a regra CSS global impede sua exibição e exposição aos leitores de tela.
- Sidebar buttons and links have visible focus outlines.
- aria-controls referencia os painéis gerados e aria-expanded acompanha sua expansão.
- Ícones decorativos possuem alt vazio ou aria-hidden.

## Needs a human

Items a skill could not decide, or could not check automatically.

- **keyboard-navigator**: Static source review only; authenticated browser keyboard interaction was not exercised.
- **keyboard-navigator**: All three findings from the first review are resolved in the reviewed source.
- **keyboard-navigator**: The account disclosure avoids role=menu, so ordinary Tab navigation is appropriate.
- **aria-specialist**: Revisão estática do código atual; anúncios e ordem de leitura não foram verificados com leitor de tela em execução.

## Next steps

1. Keep the current checks in continuous integration so this does not regress.
2. Re-audit when the interface changes materially, or quarterly.
3. Confirm the automated result with a manual screen reader and keyboard pass.

Automated checks find a minority of accessibility barriers. A passing score is a floor, not a guarantee.

## Findings data

The merged findings, embedded so the next run can compute a delta without parsing this document.

```json a11y-findings
{
  "skills": [
    "aria-specialist",
    "keyboard-navigator"
  ],
  "scope": [
    "public/portal/assets/fokus-law-shell.css",
    "public/portal/assets/fokus-law-shell.js",
    "resources/views/portal/fokus-law.blade.php"
  ],
  "findings": [],
  "passed": [
    "A navegação possui nome acessível e a página selecionada recebe aria-current.",
    "Account actions use native links or buttons with ordinary Tab navigation.",
    "Both selected and unselected Contatos activation handlers focus the content region after rebuilding navigation.",
    "Closed mobile sidebar is inert; breakpoint changes synchronize inert state for desktop access.",
    "Collapsed module panels and closed account panels use hidden backed by an explicit display:none !important rule.",
    "Escape closes the account disclosure before closing mobile navigation and restores focus to the correct trigger.",
    "Escape fecha o painel da conta e retorna o foco ao botão que o abriu.",
    "Mobile sidebar Tab and Shift+Tab cycle between visible focusable endpoints; Escape provides an exit.",
    "Module disclosure triggers use native buttons with aria-controls and synchronized aria-expanded.",
    "O controle da conta usa botão nativo com aria-controls válido e aria-expanded sincronizado.",
    "O painel da conta usa links e botões nativos sem papéis de menu que exigiriam interação adicional por setas.",
    "Os grupos de módulos usam botões HTML nativos com nomes visíveis.",
    "Os painéis recolhidos usam hidden e a regra CSS global impede sua exibição e exposição aos leitores de tela.",
    "Sidebar buttons and links have visible focus outlines.",
    "aria-controls referencia os painéis gerados e aria-expanded acompanha sua expansão.",
    "Ícones decorativos possuem alt vazio ou aria-hidden."
  ],
  "notes": [
    {
      "skill": "keyboard-navigator",
      "note": "Static source review only; authenticated browser keyboard interaction was not exercised."
    },
    {
      "skill": "keyboard-navigator",
      "note": "All three findings from the first review are resolved in the reviewed source."
    },
    {
      "skill": "keyboard-navigator",
      "note": "The account disclosure avoids role=menu, so ordinary Tab navigation is appropriate."
    },
    {
      "skill": "aria-specialist",
      "note": "Revisão estática do código atual; anúncios e ordem de leitura não foram verificados com leitor de tela em execução."
    }
  ],
  "counts": {
    "critical": 0,
    "serious": 0,
    "moderate": 0,
    "minor": 0
  },
  "components": [],
  "score": 100,
  "grade": "A",
  "penalty": 0,
  "checks": 16,
  "generated": "2026-11-04",
  "template": "web"
}
```
