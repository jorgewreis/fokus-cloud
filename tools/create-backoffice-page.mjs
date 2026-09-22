import { access, copyFile, mkdir, writeFile } from 'node:fs/promises';
import { constants } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';

const args = Object.fromEntries(process.argv.slice(2).map((item) => {
    const [key, value] = item.replace(/^--/, '').split('=');
    return [key, value];
}));
const id = args.id?.trim();
const title = args.title?.trim();
const context = args.context?.trim() || 'Administração';
const description = args.description?.trim() || 'Gerencie os registros desta área.';

if (!id || !/^[a-z0-9-]+$/.test(id) || !title) {
    throw new Error('Uso: npm run backoffice:page:new -- --id=minha-pagina --title="Minha página" [--context=Administração] [--description="..."]');
}

const root = resolve(import.meta.dirname, '..');
const pageTarget = resolve(root, `public/backoffice/pages/${id}.html`);
const moduleDirectory = resolve(root, 'public/backoffice/assets/js/pages');
const moduleTarget = resolve(moduleDirectory, `${id}.js`);
await access(pageTarget, constants.F_OK).then(() => { throw new Error(`A página ${id} já existe.`); }).catch((error) => {
    if (error.code !== 'ENOENT') throw error;
});

const source = await (await import('node:fs/promises')).readFile(resolve(root, 'docs/templates/backoffice-record-page.html'), 'utf8');
const page = source
    .replaceAll('{{PAGE_ID}}', id)
    .replaceAll('{{CONTEXT}}', context)
    .replaceAll('{{TITLE}}', title)
    .replaceAll('{{DESCRIPTION}}', description);
await mkdir(moduleDirectory, { recursive: true });
await writeFile(pageTarget, page, 'utf8');
await writeFile(moduleTarget, `export function mount(root, context) {\n    root.dataset.${id.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())}Ready = 'true';\n    return () => { delete root.dataset.${id.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())}Ready; };\n}\n\nexport function unmount() {}\n`, 'utf8');
console.log(`Criados: ${pageTarget} e ${moduleTarget}. Registre a rota no BackofficePageRegistry antes de publicar.`);
