import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFile, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { FOKUS_LAW_ASSETS, generateFokusLawAssets, versionedAssetPath } from '../../tools/generate-fokus-law-assets.mjs';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'fokus-law-asset-test-'));

try {
    const outputRoot = path.join(temporaryRoot, 'generated');
    const stagingDir = path.join(temporaryRoot, 'staging');
    await mkdir(stagingDir);

    const { manifest, files } = await generateFokusLawAssets({
        sourceRoot: repositoryRoot,
        outputRoot,
        stagingDir,
    });

    assert.equal(Object.keys(manifest.assets).length, 14);
    assert.equal(files.length, 14);
    assert.equal((await readFile(path.join(outputRoot, 'config/fokus-law-assets.json'), 'utf8')).endsWith('\n'), true);
    assert.equal((await readFile(path.join(stagingDir, 'protect.rules'), 'utf8')).trim().split('\n').length, 14);
    for (const source of FOKUS_LAW_ASSETS) {
        const entry = manifest.assets[source];
        assert.match(entry.sha256, /^[a-f0-9]{64}$/);
        assert.equal(entry.path, versionedAssetPath(source, entry.sha256));
        assert.deepEqual(
            await readFile(path.join(outputRoot, 'public', ...entry.path.split('/'))),
            await readFile(path.join(stagingDir, 'public', ...entry.path.split('/'))),
        );
    }

    const rsync = spawnSync('rsync', ['--version'], { encoding: 'utf8' });
    if (rsync.error?.code === 'ENOENT') {
        if (process.env.CI) {
            throw new Error('rsync is required in CI to verify immutable asset retention.');
        }
        process.stdout.write('SKIP: rsync executable is not available; generated manifests and assets were verified.\n');
    } else {
        assert.equal(rsync.status, 0, rsync.stderr);
        const sourceRoot = path.join(temporaryRoot, 'sync-source');
        const destinationRoot = path.join(temporaryRoot, 'sync-destination');
        const logical = 'portal/assets/fokus-law-contacts.js';
        const current = manifest.assets[logical];
        const currentName = `public/${current.path}`;
        const oldName = `public/portal/assets/fokus-law-contacts.${'a'.repeat(64)}.js`;
        const malformedDigestName = `public/portal/assets/fokus-law-contacts.${'a'.repeat(63)}g.js`;
        const unlistedAssetName = `public/portal/assets/fokus-law-custom.${'b'.repeat(64)}.js`;

        for (const relative of [currentName, 'public/portal/assets/fokus-law-contacts.js']) {
            await mkdir(path.dirname(path.join(sourceRoot, relative)), { recursive: true });
        }
        await copyFile(path.join(stagingDir, 'public', ...current.path.split('/')), path.join(sourceRoot, currentName));
        await writeFile(path.join(sourceRoot, 'public/portal/assets/fokus-law-contacts.js'), 'updated original source\n');

        for (const [relative, content] of [
            [oldName, 'old immutable asset\n'],
            [malformedDigestName, 'malformed digest must be deleted\n'],
            [unlistedAssetName, 'unlisted immutable-looking asset must be deleted\n'],
            ['public/portal/assets/ordinary-obsolete.css', 'ordinary obsolete file\n'],
            ['public/portal/assets/fokus-law-contacts.js', 'old original source\n'],
        ]) {
            const destination = path.join(destinationRoot, relative);
            await mkdir(path.dirname(destination), { recursive: true });
            await writeFile(destination, content);
        }

        const protectRules = path.join(stagingDir, 'protect.rules');
        const sync = spawnSync('rsync', [
            '-r', '--delete-delay', '--filter', `merge ${protectRules}`,
            `${sourceRoot}${path.sep}`, `${destinationRoot}${path.sep}`,
        ], { encoding: 'utf8' });
        assert.equal(sync.status, 0, sync.stderr || sync.stdout);

        assert.equal((await readFile(path.join(destinationRoot, oldName), 'utf8')), 'old immutable asset\n');
        assert.deepEqual(
            await readFile(path.join(destinationRoot, currentName)),
            await readFile(path.join(stagingDir, ...currentName.split('/'))),
        );
        assert.equal(await exists(path.join(destinationRoot, malformedDigestName)), false);
        assert.equal(await exists(path.join(destinationRoot, unlistedAssetName)), false);
        assert.equal(await exists(path.join(destinationRoot, 'public/portal/assets/ordinary-obsolete.css')), false);
        assert.equal(await readFile(path.join(destinationRoot, 'public/portal/assets/fokus-law-contacts.js'), 'utf8'), 'updated original source\n');
    }

    process.stdout.write('PASS: deterministic manifest and 14 staged content-addressed assets verified.\n');
} finally {
    await rm(temporaryRoot, { recursive: true, force: true });
}

async function exists(file) {
    try {
        await readFile(file);
        return true;
    } catch (error) {
        if (error.code === 'ENOENT') return false;
        throw error;
    }
}
