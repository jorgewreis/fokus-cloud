import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(projectRoot, 'node_modules/fokus-styles/dist/css/fokus.css');
const target = resolve(projectRoot, 'public/assets/css/shared/fokus.css');

await mkdir(dirname(target), { recursive: true });
const css = await readFile(source, 'utf8');
const sanitizedCss = css
    .replace(/[→←↑↓➜➝➞➤⟶⟹↗↘↙↖↕]/gu, '')
    .replace(/text-decoration:\s*underline(?:\s+dotted)?/gu, 'text-decoration: none')
    .replace(/background-image:\s*url\("data:image\/svg\+xml,[^"]+"\);/gu, 'background-image: none;')
    .replace(/,\s*\n\s*[^{}\n]*svg[^{}\n]*/giu, '')
    .replace(/^[^{}\n]*svg[^{}\n]*\{[^{}]*\}\s*/gimu, '');
const linkPolicy = `

/* Product-wide link presentation policy. */
:where(a, a:hover, a:focus-visible, a:active) {
    text-decoration: none !important;
}
`;
const directionalIconPolicy = `

/* Directional icons are intentionally disabled across published interfaces. */
.fs-tooltip-arrow,
.fs-popover-arrow,
.fs-dropdown-rich-chevron,
.fs-tree-toggle,
.fs-datatable-sort-btn::after,
.fs-accordion-button::after {
    display: none !important;
    content: none !important;
}
`;
const immutableBackofficeContract = `

/* Immutable Backoffice record-page contract. Page CSS may compose this API,
   but may not redefine controls, cards, tables, badges or overlays. */
html[data-role="admin"] {
    --fs-backoffice-panel-space: var(--fs-space-3, 16px);
    --fs-backoffice-card-space: var(--fs-space-4, 24px);
}
.backoffice-records-page {
    display: flex;
    flex-direction: column;
    gap: var(--fs-backoffice-panel-space);
    min-width: 0;
}
.backoffice-records-page .fs-card-panel > .fs-card-header,
.backoffice-records-page .fs-card-panel > .fs-card-body,
.backoffice-records-page .fs-card-panel > .fs-card-footer {
    box-sizing: border-box;
}
.backoffice-records-page .fs-filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: var(--fs-backoffice-panel-space);
}
.backoffice-records-page .fs-filter-form .fs-form-label {
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    min-width: min(100%, 180px);
    gap: var(--fs-space-1, 4px);
}
.backoffice-records-page .fs-form-label,
.backoffice-records-page .fs-form-control,
.backoffice-records-page .fs-form-select,
.backoffice-records-page textarea.fs-form-control {
    font-family: var(--fs-font-family, "Google Sans", sans-serif);
}
html[data-role="admin"] input,
html[data-role="admin"] select,
html[data-role="admin"] textarea {
    font-family: var(--fs-font-family, "Google Sans", sans-serif);
}
.backoffice-records-page .fs-form-control,
.backoffice-records-page .fs-form-select {
    box-sizing: border-box;
    min-height: 38px;
}
.backoffice-records-page .fs-filter-form .fs-input-group-subtle {
    width: auto;
}
.backoffice-records-page .fs-filter-form .fs-input-group-subtle > .fs-form-control {
    flex: 0 0 auto;
}
.backoffice-records-page textarea.fs-form-control {
    min-height: 96px;
    resize: vertical;
}
.backoffice-records-page .fs-table-responsive {
    min-width: 0;
    overflow-x: auto;
}
.fs-table-action,
.fs-table-action:hover,
.fs-table-action:focus-visible,
.fs-table-action:active {
    display: inline-flex;
    flex: 0 0 24px;
    align-items: center;
    justify-content: center;
    width: 24px;
    min-width: 24px;
    height: 24px;
    min-height: 24px;
    padding: 0;
    visibility: visible !important;
}
.fs-table-action::before { display: none !important; content: none !important; }
.fs-table-action > img { width: 20px; height: 20px; object-fit: contain; }
.backoffice-records-drawer .fs-offcanvas-body {
    min-width: 0;
}
.backoffice-records-drawer.fs-offcanvas-end,
body > .backoffice-records-drawer.fs-offcanvas-end {
    width: 450px;
    max-width: calc(100vw - 16px);
}
.backoffice-records-drawer .fs-offcanvas-body.fs-form {
    display: flex;
    flex-direction: column;
    gap: var(--fs-backoffice-panel-space);
}
.backoffice-records-drawer .fs-card-panel {
    flex: 0 0 auto;
    min-width: 0;
}
.backoffice-records-drawer .fs-card-panel > .fs-card-body {
    min-width: 0;
    overflow: visible;
}
.backoffice-records-drawer .fs-form-col {
    flex: initial;
}
.backoffice-records-drawer .fs-form-label {
    display: flex;
    flex-direction: column;
    gap: var(--fs-space-1, 4px);
    width: fit-content;
}
.backoffice-records-drawer .fs-offcanvas-footer {
    display: flex;
    flex-wrap: wrap;
    gap: var(--fs-space-2, 8px);
    justify-content: flex-end;
}
.backoffice-records-view-panel dl {
    margin: 0;
}
.backoffice-records-view-panel dt {
    color: var(--fs-color-text-secondary);
    font-weight: 600;
}
.backoffice-records-view-panel dd {
    margin: var(--fs-space-1, 4px) 0 0;
    overflow-wrap: anywhere;
}
html[data-role="admin"] .fs-alert {
    display: flex;
    flex-direction: column;
    margin: 10px !important;
    padding: 10px 20px !important;
}
html[data-role="admin"] .fs-alert[hidden] {
    display: none !important;
}
[data-backoffice-overlay="personalizations"] #personalizations-list > .fs-card {
    padding: 15px;
}
[data-backoffice-overlay="personalizations"] #personalizations-list .fs-form-label {
    margin-bottom: 10px;
}
@media (max-width: 680px) {
    .backoffice-records-page { gap: var(--fs-space-2, 8px); }
    .backoffice-records-page .fs-filter-form .fs-form-label,
    .backoffice-records-page .fs-filter-form > .fs-btn { flex-basis: 100%; }
    .backoffice-records-drawer .fs-offcanvas-footer > .fs-btn { flex: 1 1 100%; }
}
`;
const sharedPageHeaderPolicy = `

/* Compatibility contract for existing internal pages. */
.fs-page-header {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0;
}
.fs-page-header > span {
    margin-left: 7px;
    color: #ff6000;
    font-family: "Bebas Neue", sans-serif;
    font-size: 16px;
    font-weight: 400;
}
.fs-page-header > h1 {
    margin: 0;
    color: var(--fs-color-text);
    font-family: "Bebas Neue", sans-serif;
    font-size: 48px;
    font-weight: 400;
    line-height: .98;
}
.fs-page-header > p {
    margin: 0 0 0 7px;
    color: var(--fs-color-muted);
    font-family: "Google Sans", sans-serif;
}

/* Shared internal page header contract. */
.page-header {
    display: flex;
    flex-direction: row;
    align-items: flex-end;
    justify-content: space-between;
    margin: 0 20px 30px;
}
.page-header .header-title {
    display: flex;
    flex-direction: column;
}
.page-header .header-title .title-heading {
    margin-left: 2px;
    color: #ff6000;
    font-family: "Bebas Neue", sans-serif;
    font-size: 22px;
    font-weight: 400;
}
.page-header .header-title .title-page {
    margin: -16px 0;
    font-family: "Bebas Neue", sans-serif;
    font-size: 64px;
    font-weight: 500;
}
.page-header .header-title .title-description {
    margin-left: 2px;
    color: var(--fs-color-text, #102a43);
    font-family: "Google Sans", sans-serif;
    font-size: 14px;
    font-weight: 400;
    opacity: .5;
}
.fs-btn {
    padding: 0 15px;
    font-family: "Google Sans", sans-serif;
    font-size: 14px;
    font-weight: 500;
}
.fs-btn-primary {
    height: 38px;
    border: 1px solid var(--fs-color-primary, #2563eb);
    border-radius: var(--fs-radius-sm, 6px);
    background: var(--fs-color-primary, #2563eb);
    color: var(--fs-color-on-primary, #fff);
}

/* Compatibility aliases retained while older backoffice pages are migrated. */
.card-heading {
    display: flex;
    flex-direction: row;
    align-items: flex-start;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid var(--mockup-line, var(--fs-color-border, #cbd9dc));
}
.section-title {
    display: flex;
    margin: 0;
    color: var(--mockup-ink, var(--fs-color-text, #102a43));
    font-family: "Google Sans", sans-serif;
    font-size: 18px;
    font-weight: 600;
}
.section-description {
    display: flex;
    margin: 0;
    color: var(--mockup-muted, var(--fs-color-text-secondary, #64748b));
    font-family: "Google Sans", sans-serif;
    font-size: 14px;
    font-weight: 400;
}
.section-info {
    display: flex;
    color: var(--mockup-muted, var(--fs-color-text-secondary, #64748b));
    font-family: "Google Sans", sans-serif;
    font-size: 12px;
    font-weight: 400;
}
.fs-btn { border-radius: 6px; height: 38px; }
.form-label {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-end;
}
.form-label .label {
    margin-bottom: 2px;
    margin-left: 13px;
    font-family: "Syne Mono", monospace;
    font-size: 12px;
    font-weight: 500;
}
.fs-card-header.card-heading { padding: 20px; }
.fs-card-title.section-title { font-size: 18px; line-height: normal; }
.fs-card-subtitle.section-description { line-height: normal; }
.fs-form-label.form-label .label { font-size: 12px; }
.form-label .input-label,
.form-select {
    width: 100%;
    height: 38px;
    padding: 0 13px;
    border: 1px solid #cbd9dc;
    border-radius: 6px;
    outline: none;
    background: #fff;
    color: var(--mockup-ink, var(--fs-color-text, #102a43));
    font-family: "Syne Mono", monospace;
    font-size: 13px;
    font-weight: 600;
}
.form-select { padding: 0 7px; }
body { font-family: "Google Sans", sans-serif; }
.status-badge {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    width: 80px;
    height: 24px;
    padding: 0 10px;
    border-radius: 6px;
    font-family: "Syne Mono", monospace;
    font-size: 12px;
    font-weight: 600;
}

/* Shared Backoffice compositions. These are maintained in Fokus Cloud because
   they combine the installed Fokus Styles primitives with product conventions. */
@font-face {
    font-family: "Google Sans";
    src: url("/assets/fonts/google/google-sans-400.woff2") format("woff2");
    font-weight: 400;
    font-display: swap;
}
@font-face {
    font-family: "Google Sans";
    src: url("/assets/fonts/google/google-sans-600.woff2") format("woff2");
    font-weight: 600;
    font-display: swap;
}
.fs-page-layout {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--fs-space-3);
}
.fs-page-header-display {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0;
}
.fs-page-header-display > span {
    margin-left: 2px;
    color: #ff6000;
    font-family: "Bebas Neue", sans-serif;
    font-size: 1.2rem;
    font-weight: 400;
    letter-spacing: 2px;
}
.fs-page-header-display > h1 {
    margin: -15px 0 0 -1px;
    color: #252525;
    font-family: "Bebas Neue", sans-serif;
    font-size: 3.5rem;
    font-weight: 600;
    line-height: normal;
    letter-spacing: 2px;
}
.fs-page-header-display > p {
    margin: 0;
    color: #909090;
    font-family: "Google Sans", sans-serif;
    font-size: 1rem;
    font-weight: 400;
}
.fs-card-panel {
    overflow: hidden;
    border-color: #cbcbcb;
    border-radius: 8px;
    background: #fefefe;
    box-shadow: 2px 2px 10px 2px #cbcbcb60;
}
.fs-card-panel > .fs-card-header {
    align-items: flex-end;
    padding: 20px;
    border-radius: 8px 8px 0 0;
    background: #efefef;
}
.fs-card-panel .fs-card-header-title {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}
.fs-card-panel .fs-card-title {
    margin: 0;
    font-family: "Google Sans", sans-serif;
    font-size: 1rem;
    font-weight: 600;
}
.fs-card-panel .fs-card-subtitle,
.fs-card-panel .fs-card-header-info {
    margin: 0;
    color: #909090;
    font-family: "Google Sans", sans-serif;
    font-size: .8rem;
    font-weight: 400;
}
.fs-card-panel > .fs-card-footer {
    padding: 14px 24px;
    border-top-color: #cbcbcb;
    background: #fefefe;
}
.fs-filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    justify-content: flex-end;
    gap: 16px;
    padding: 20px;
}
.fs-filter-form > .fs-form-col { min-width: 0; }
.fs-filter-form .fs-form-label > span:first-child {
    margin: 0 0 0 10px;
    padding: 2px 5px;
    color: #909090;
    background: #fefefe;
    font-family: "Google Sans", sans-serif;
    font-size: .725rem;
    font-weight: 400;
}
.fs-filter-form .fs-form-control,
.fs-filter-form .fs-form-select {
    min-height: 38px;
    border-color: #cbcbcb;
    border-radius: 7px;
    color: #252525;
    background: #fefefe;
    font-family: "Google Sans", sans-serif;
    font-size: .85rem;
    font-weight: 500;
}
.fs-filter-form .fs-form-control::placeholder {
    font-family: "Google Sans", sans-serif;
    font-weight: 400;
    opacity: .4;
}
.fs-filter-form .fs-form-action { flex: 0 0 auto; }
.fs-width-200 { width: 80px; }
.fs-width-300 { width: 120px; }
.fs-width-400 { width: 180px; }
.fs-width-500 { width: 240px; }
.fs-width-600 { width: 300px; }
.fs-width-800 { width: 420px; }
.fs-input-group-subtle {
    display: flex;
    width: 100%;
    align-items: flex-end;
}
.fs-input-group-subtle > .fs-input-group-text {
    display: flex;
    flex: 0 0 38px;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0;
    border-color: #cbcbcb;
    border-right: 0;
    border-radius: 8px 0 0 8px;
    background: #eeeeee;
}
.fs-input-group-subtle > .fs-form-control {
    flex: 1 1 auto;
    height: 38px;
    min-height: 38px;
    margin-left: 0 !important;
    border-left: 0;
    border-radius: 0 8px 8px 0;
}
.fs-input-group-subtle .fs-input-group-icon { width: 22px; height: 22px; }
.fs-table-records {
    display: flex;
    flex-direction: column;
    min-width: 1220px;
    font-family: "Google Sans", sans-serif;
}
.fs-table-records thead,
.fs-table-records tbody,
.fs-table-records tr { width: 100%; }
.fs-table-records thead {
    display: flex;
    flex: 0 0 38px;
    flex-direction: column;
    height: 38px;
    border-top: 1px solid #cbcbcb;
    border-bottom: 1px solid #252525;
    background: #dcdcdc;
}
.fs-table-records thead tr,
.fs-table-records tbody tr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 52px;
    padding: 5px 0;
}
.fs-table-records thead tr { min-height: 38px; height: 38px; }
.fs-table-records th,
.fs-table-records td {
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    box-sizing: border-box;
    height: 42px;
    min-height: 42px;
    padding: 0 10px;
    border-bottom: 0;
}
.fs-table-records th:not(:first-child),
.fs-table-records td:not(:first-child) { border-left: 1px dotted #cbcbcb; }
.fs-table-records th:not(:first-child) { border-left-color: #252525; }
.fs-table-records tbody {
    display: flex;
    flex-direction: column;
    padding: 5px 0;
    border-bottom: 1px solid #cbcbcb;
}
.fs-table-records tbody tr:not(:first-child) { border-top: 1px solid #cbcbcb; }
.fs-table-records .fs-width-200 { flex-basis: 80px; }
.fs-table-records .fs-width-300 { flex-basis: 120px; }
.fs-table-records .fs-width-400 { flex-basis: 180px; }
.fs-table-records .fs-width-500 { flex-basis: 240px; }
.fs-table-records .fs-width-600 { flex-basis: 300px; }
.fs-table-records th {
    color: #252525;
    font-size: .7rem;
    font-weight: 600;
    text-transform: none;
    white-space: nowrap;
}
.fs-table-records td { color: #252525; font-size: .8rem; font-weight: 600; }
.fs-table-records td small {
    display: block;
    width: 240px;
    overflow: hidden;
    color: #909090;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.fs-table-records td strong { width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fs-badge-width-80 { width: 80px; justify-content: flex-start; }
.fs-badge-width-80.fs-badge-soft-success { --fs-badge-bg: #e5f5ee; --fs-badge-color: #247f69; --fs-badge-border: transparent; }
.fs-badge-width-80.fs-badge-soft-warning { --fs-badge-bg: #fff1dc; --fs-badge-color: #9b671f; --fs-badge-border: transparent; }
.fs-badge-width-80.fs-badge-soft-info { --fs-badge-bg: #e5f0f7; --fs-badge-color: #397493; --fs-badge-border: transparent; }
.fs-btn-icon-plain,
.fs-btn-icon-plain:hover,
.fs-btn-icon-plain:active,
.fs-btn-icon-plain:focus-visible {
    width: 24px;
    min-width: 24px;
    height: 24px;
    min-height: 24px;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
}
.fs-btn-icon-plain img { width: 20px; height: 20px; object-fit: contain; }
.fs-pagination-compact {
    gap: var(--fs-space-1, 4px);
    justify-content: flex-end;
    font-family: "Google Sans", sans-serif;
}
.fs-pagination-compact .fs-page-link {
    width: 30px;
    min-width: 30px;
    height: 30px;
    min-height: 30px;
    padding: 0;
    border-radius: var(--fs-radius-sm, 6px);
    font-family: "Google Sans", sans-serif;
}
.fs-pagination-compact .fs-page-item.is-active .fs-page-link {
    pointer-events: none;
}
.backoffice-records-page .fs-btn-primary:hover,
.fs-pagination-compact .fs-page-link:hover {
    filter: brightness(1.2);
}
.backoffice-records-page .fs-btn-primary:active,
.fs-pagination-compact .fs-page-link:active {
    filter: brightness(1.4);
}
@media (max-width: 680px) {
    .fs-page-layout { flex-direction: column; align-items: flex-start; }
    .fs-page-layout > .fs-btn { width: 100%; justify-content: center; }
    .fs-filter-form { padding: 16px; }
    .fs-card-panel > .fs-card-footer { align-items: flex-start; padding: 14px 16px; }
}
`;
await writeFile(target, `${sanitizedCss}${linkPolicy}${directionalIconPolicy}${sharedPageHeaderPolicy}${immutableBackofficeContract}`, 'utf8');
console.log(`Synced fokus-styles to ${target}`);
