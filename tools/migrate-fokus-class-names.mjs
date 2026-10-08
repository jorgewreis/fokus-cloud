import { readFile, readdir, writeFile } from 'node:fs/promises';
import { extname, join } from 'node:path';

const root = join(process.cwd(), 'public');
const extensions = new Set(['.html', '.js', '.css']);
const explicit = new Map([
  ['btn', 'fs-btn'], ['btn-green', 'fs-btn-success'], ['btn-error', 'fs-btn-danger'],
  ['btn-outline', 'fs-btn-outline-primary'], ['btn-outline-error', 'fs-btn-outline-danger'], ['btn-white', 'fs-btn-outline-primary'],
  ['button', 'fs-btn'], ['button-success', 'fs-btn-success'], ['button-danger', 'fs-btn-danger'],
  ['button-outline', 'fs-btn-outline-primary'], ['form-control', 'fs-form-control'], ['form-input', 'fs-form-control'],
  ['form-select', 'fs-form-select'], ['form-textarea', 'fs-form-control'], ['form-label', 'fs-form-label'],
  ['form-row', 'fs-form-row'], ['input-group', 'fs-input-group'], ['input-group-text', 'fs-input-group-text'],
  ['pagination-item', 'fs-page-item'], ['pagination-link', 'fs-page-link'], ['container-fluid', 'fs-container-fluid'],
  ['container', 'fs-container'], ['row', 'fs-row'], ['col', 'fs-col'], ['stack', 'fs-stack'], ['cluster', 'fs-cluster'],
  ['text-center', 'fs-u-text-center'], ['text-left', 'fs-u-text-start'], ['text-right', 'fs-u-text-end'],
  ['text-uppercase', 'fs-u-text-uppercase'], ['text-lowercase', 'fs-u-text-lowercase'], ['text-capitalize', 'fs-u-text-capitalize'],
  ['text-truncate', 'fs-u-text-truncate'], ['font-normal', 'fs-u-fw-normal'], ['font-medium', 'fs-u-fw-medium'],
  ['font-semibold', 'fs-u-fw-semibold'], ['font-bold', 'fs-u-fw-bold'], ['font-extrabold', 'fs-u-fw-bold'],
  ['d-none', 'fs-u-d-none'], ['d-block', 'fs-u-d-block'], ['d-flex', 'fs-u-d-flex'], ['d-grid', 'fs-u-d-grid'],
  ['d-inline', 'fs-u-d-inline'], ['d-inline-block', 'fs-u-d-inline-block'], ['flex-row', 'fs-u-flex-row'],
  ['flex-column', 'fs-u-flex-column'], ['flex-wrap', 'fs-u-flex-wrap'], ['flex-nowrap', 'fs-u-flex-nowrap'],
  ['justify-start', 'fs-u-justify-content-start'], ['justify-center', 'fs-u-justify-content-center'],
  ['justify-end', 'fs-u-justify-content-end'], ['justify-between', 'fs-u-justify-content-between'],
  ['items-start', 'fs-u-align-items-start'], ['items-center', 'fs-u-align-items-center'], ['items-end', 'fs-u-align-items-end'],
  ['items-stretch', 'fs-u-align-items-stretch'], ['text-muted', 'fs-u-color-secondary'], ['text-primary', 'fs-u-color-primary'],
  ['text-success', 'fs-u-color-success'], ['text-danger', 'fs-u-color-danger'], ['text-warning', 'fs-u-color-warning'],
  ['text-info', 'fs-u-color-info'], ['w-full', 'fs-u-w-100'], ['w-auto', 'fs-u-w-auto'], ['mx-auto', 'fs-u-mx-auto'],
]);

const utilityPatterns = [
  [/^(btn|button)-(sm|lg)$/, 'fs-btn-$2'], [/^btn-(primary|secondary|success|danger|warning|info)$/, 'fs-btn-$1'],
  [/^btn-outline-(primary|secondary|success|danger|warning|info)$/, 'fs-btn-outline-$1'],
  [/^card-(.+)$/, 'fs-card-$1'], [/^badge-(.+)$/, 'fs-badge-$1'], [/^alert-(.+)$/, 'fs-alert-$1'],
  [/^avatar-(.+)$/, 'fs-avatar-$1'], [/^progress-(.+)$/, 'fs-progress-$1'], [/^skeleton-(.+)$/, 'fs-skeleton-$1'],
  [/^table-(.+)$/, 'fs-table-$1'], [/^tabs-(.+)$/, 'fs-tabs-$1'],
  [/^gap-([0-5])$/, 'fs-u-gap-$1'],
  [/^p-([0-5])$/, 'fs-u-p-$1'],
  [/^px-([0-5])$/, 'fs-u-px-$1'],
  [/^py-([0-5])$/, 'fs-u-py-$1'],
  [/^pt-([0-5])$/, 'fs-u-pt-$1'],
  [/^pb-([0-5])$/, 'fs-u-pb-$1'],
  [/^pl-([0-5])$/, 'fs-u-ps-$1'],
  [/^pr-([0-5])$/, 'fs-u-pe-$1'],
  [/^m-([0-5])$/, 'fs-u-m-$1'],
  [/^mx-([0-5])$/, 'fs-u-mx-$1'],
  [/^my-([0-5])$/, 'fs-u-my-$1'],
  [/^mt-([0-5])$/, 'fs-u-mt-$1'],
  [/^mb-([0-5])$/, 'fs-u-mb-$1'],
  [/^ms-([0-5])$/, 'fs-u-ms-$1'],
  [/^me-([0-5])$/, 'fs-u-me-$1'],
];

const packageCss = await readFile(join(process.cwd(), 'node_modules/fokus-styles/dist/css/fokus.css'), 'utf8');
const packageClasses = new Set([...packageCss.matchAll(/\.(fs-[A-Za-z0-9_-]+)/g)].map((match) => match[1]));

function migrateToken(token) {
  if (token.startsWith('fs-') || token.startsWith('is-')) return token;
  const explicitTarget = explicit.get(token);
  if (explicitTarget && packageClasses.has(explicitTarget)) return explicitTarget;
  for (const [pattern, replacement] of utilityPatterns) {
    const match = token.match(pattern);
    const target = match ? token.replace(pattern, replacement) : null;
    if (target && packageClasses.has(target)) return target;
  }
  const prefixed = `fs-${token}`;
  return packageClasses.has(prefixed) ? prefixed : token;
}

function migrateText(text) {
  const migrateClassList = (value) => value.split(/(\s+)/).map((part) => /^\s+$/.test(part) ? part : migrateToken(part)).join('');
  let result = text.replace(/\.(?![0-9])([A-Za-z][A-Za-z0-9_-]*)/g, (full, token) => `.${migrateToken(token)}`);
  result = result.replace(/\bclass(Name)?=(['"])(.*?)\2/g, (full, name, quote, value) => `class${name ?? ''}=${quote}${migrateClassList(value)}${quote}`);
  return result;
}

async function walk(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  for (const entry of entries) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      if (entry.name !== 'backup-css' && entry.name !== 'vendor' && entry.name !== 'build' && !(directory.endsWith('public/assets/css') && entry.name === 'shared')) await walk(path);
      continue;
    }
    if (!extensions.has(extname(entry.name))) continue;
    const before = await readFile(path, 'utf8');
    const after = migrateText(before);
    if (after !== before) {
      await writeFile(path, after, 'utf8');
      console.log(path);
    }
  }
}

await walk(root);
