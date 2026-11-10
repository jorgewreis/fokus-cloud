import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const FOKUS_LAW_ASSETS = Object.freeze([
    'assets/css/shared/fokus.css',
    'marketing/products/fokus-law.css',
    'portal/assets/fokus-law-shell.css',
    'assets/js/fokus.min.js',
    'assets/js/api-client.js',
    'assets/js/brazilian-documents.js',
    'portal/assets/fokus-law-select.js',
    'portal/assets/fokus-law-access.js',
    'portal/assets/fokus-law-bank-catalog.js',
    'portal/assets/fokus-law-contacts.js',
    'portal/assets/law-record-ui.js',
    'portal/assets/fokus-law-processes.js',
        'portal/assets/fokus-law-tasks.js',
    'portal/assets/fokus-law-shell.js',
    'assets/js/portal-profile.js',
]);

const hexPattern = '[0-9a-f]'.repeat(64);

export function versionedAssetPath(source, digest) {
    const extension = path.posix.extname(source);
    const basename = path.posix.basename(source, extension);
    const directory = path.posix.dirname(source);
    const filename = `${basename}.${digest}${extension}`;
    return directory === '.' ? filename : `${directory}/${filename}`;
}

export function rsyncProtectRules() {
    return FOKUS_LAW_ASSETS.map((source) => {
        const extension = path.posix.extname(source);
        const basename = path.posix.basename(source, extension);
        const directory = path.posix.dirname(source);
        const pattern = `${basename}.${hexPattern}${extension}`;
        return `P /public/${directory === '.' ? '' : `${directory}/`}${pattern}`;
    }).join('\n') + '\n';
}

function parseArguments(argv) {
    const options = { sourceRoot: process.cwd(), outputRoot: process.cwd(), stagingDir: null };
    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];
        const option = argument.startsWith('--') ? argument.slice(2) : null;
        if (!option || !['source-root', 'output-root', 'staging-dir'].includes(option)) {
            throw new Error(`Unknown argument: ${argument}`);
        }
        const value = argv[index + 1];
        if (!value || value.startsWith('--')) {
            throw new Error(`Missing value for --${option}`);
        }
        const optionNames = {
            'source-root': 'sourceRoot',
            'output-root': 'outputRoot',
            'staging-dir': 'stagingDir',
        };
        options[optionNames[option]] = path.resolve(value);
        index += 1;
    }
    return options;
}

async function writeAtomic(destination, bytes) {
    await mkdir(path.dirname(destination), { recursive: true });
    const temporary = `${destination}.tmp-${process.pid}`;
    await writeFile(temporary, bytes);
    await rename(temporary, destination);
}

async function ensureImmutableFile(destination, bytes, digest) {
    await mkdir(path.dirname(destination), { recursive: true });
    try {
        const current = await readFile(destination);
        if (createHash('sha256').update(current).digest('hex') !== digest) {
            throw new Error(`Refusing to replace a different file at immutable path ${destination}`);
        }
        return;
    } catch (error) {
        if (error?.code !== 'ENOENT') throw error;
    }
    await writeAtomic(destination, bytes);
}

export async function generateFokusLawAssets({ sourceRoot, outputRoot, stagingDir = null }) {
    const templatePath = path.join(sourceRoot, 'resources/views/portal/fokus-law.blade.php');
    const template = await readFile(templatePath, 'utf8');
    const references = [...template.matchAll(/FokusLawAssets::url\('([^']+)'\)/g)].map((match) => match[1]);
    const expectedReferences = [...FOKUS_LAW_ASSETS].sort();
    if (references.length !== FOKUS_LAW_ASSETS.length || [...references].sort().some((item, index) => item !== expectedReferences[index])) {
        throw new Error('The Fokus Law Blade references do not match the explicit 14-asset allowlist.');
    }

    if (stagingDir) {
        await mkdir(stagingDir, { recursive: true });
        if ((await readdir(stagingDir)).length > 0) {
            throw new Error(`Staging directory must be empty: ${stagingDir}`);
        }
    }

    const entries = {};
    const payloadFiles = [];
    const checksums = [];

    for (const source of FOKUS_LAW_ASSETS) {
        const bytes = await readFile(path.join(sourceRoot, 'public', source));
        const digest = createHash('sha256').update(bytes).digest('hex');
        const versioned = versionedAssetPath(source, digest);
        const publicPath = path.join(outputRoot, 'public', ...versioned.split('/'));
        const stagingPath = stagingDir ? path.join(stagingDir, 'public', ...versioned.split('/')) : null;

        await ensureImmutableFile(publicPath, bytes, digest);
        if (stagingPath) await ensureImmutableFile(stagingPath, bytes, digest);

        entries[source] = { path: versioned, sha256: digest };
        payloadFiles.push(`public/${versioned}`);
        checksums.push(`${digest}  public/${versioned}`);
    }

    const manifest = `${JSON.stringify({ version: 1, algorithm: 'sha256', assets: entries }, null, 2)}\n`;
    await writeAtomic(path.join(outputRoot, 'config/fokus-law-assets.json'), manifest);

    if (stagingDir) {
        await writeAtomic(path.join(stagingDir, 'files.txt'), `${payloadFiles.join('\n')}\n`);
        await writeAtomic(path.join(stagingDir, 'checksums.sha256'), `${checksums.join('\n')}\n`);
        await writeAtomic(path.join(stagingDir, 'protect.rules'), rsyncProtectRules());
    }

    return { manifest: JSON.parse(manifest), files: payloadFiles };
}

async function main() {
    const options = parseArguments(process.argv.slice(2));
    await generateFokusLawAssets(options);
    process.stdout.write(`Prepared ${FOKUS_LAW_ASSETS.length} content-addressed Fokus Law assets.\n`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => {
        process.stderr.write(`${error.message}\n`);
        process.exitCode = 1;
    });
}
