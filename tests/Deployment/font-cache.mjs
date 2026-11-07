/** Prepare reviewed Bunny fonts online, then prove the unchanged build works offline. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '../..');
const CACHE = path.join(ROOT, 'node_modules/.cache/laravel-vite-plugin/fonts');
const REPORT = path.resolve(process.env.RELEASE_REPORT_DIR || path.join(ROOT, 'test-results/linux-release'));
export const CSS_URL = 'https://fonts.bunny.net/css2?family=Instrument+Sans:wght@400;500;600&display=swap&subset=latin';
export const LICENSE_URL = 'https://raw.githubusercontent.com/google/fonts/main/ofl/instrumentsans/OFL.txt';
export const sha = data => crypto.createHash('sha256').update(data).digest('hex');
export const cacheKey = (url, text = false) => sha(url + (text ? ':text' : '')).slice(0, 16);
export function allowedFontUrl(value) {
    const url = new URL(value);
    return value === CSS_URL || (url.origin === 'https://fonts.bunny.net' &&
        /^\/instrument-sans\/files\/instrument-sans-latin-[456]00-normal\.woff2?$/.test(url.pathname) &&
        !url.search && !url.hash && !url.username && !url.password);
}
function write(name, data) { fs.writeFileSync(path.join(REPORT, name), JSON.stringify(data, null, 2) + '\n'); }
function verify(manifest) {
    assert.equal(manifest.css_url, CSS_URL);
    assert.equal(sha(fs.readFileSync(path.join(ROOT,'node_modules/laravel-vite-plugin/dist/index.js'))),manifest.plugin_sha256);
    assert.equal(sha(fs.readFileSync(path.join(ROOT,'vite.config.js'))),manifest.vite_config_sha256);
    assert.ok(manifest.files.length >= 4, 'CSS plus at least three font variants required');
    for (const row of manifest.files) {
        assert.ok(allowedFontUrl(row.url));
        assert.equal(row.key, cacheKey(row.url, row.url === CSS_URL));
        const bytes = fs.readFileSync(path.join(CACHE, row.key));
        assert.equal(bytes.length, row.bytes); assert.equal(sha(bytes), row.sha256);
    }
    assert.deepEqual(fs.readdirSync(CACHE).sort(), manifest.files.map(row => row.key).sort());
    assert.equal(sha(fs.readFileSync(path.join(REPORT, 'instrument-sans-OFL.txt'))), manifest.license.sha256);
}
async function setup() {
    assert.equal(process.env.RELEASE_FONT_SETUP, '1', 'Only the download/setup stage may fetch fonts');
    // A warm inherited cache must not masquerade as a reproducible fresh download.
    if (fs.existsSync(CACHE)) {
        const preserved = path.join(REPORT, 'font-cache-preexisting-' + crypto.randomUUID());
        fs.renameSync(CACHE, preserved);
    }
    const originalFetch = globalThis.fetch;
    const rows = new Map();
    globalThis.fetch = async (input, options = {}) => {
        const url = String(input instanceof Request ? input.url : input);
        assert.ok(allowedFontUrl(url), 'Unreviewed font URL: ' + url);
        const response = await originalFetch(url, {...options, redirect:'error', signal:AbortSignal.timeout(60000)});
        assert.ok(response.ok, 'Font HTTP status ' + response.status);
        const bytes = Buffer.from(await response.clone().arrayBuffer());
        rows.set(url, {url, key:cacheKey(url, url === CSS_URL), bytes:bytes.length, sha256:sha(bytes)});
        return response;
    };
    try {
        const { build } = await import('vite');
        await build({root:ROOT}); // Preparatory only; never counted as the offline build gate.
    } finally { globalThis.fetch = originalFetch; }
    const response = await originalFetch(LICENSE_URL, {redirect:'error', signal:AbortSignal.timeout(60000)});
    assert.ok(response.ok, 'Official license fetch failed');
    const license = Buffer.from(await response.arrayBuffer());
    assert.ok(license.toString().includes('Copyright 2022 The Instrument Sans Project Authors'));
    assert.ok(license.toString().includes('SIL OPEN FONT LICENSE Version 1.1'));
    fs.writeFileSync(path.join(REPORT, 'instrument-sans-OFL.txt'), license);
    const manifest = {stage:'setup-download-only', css_url:CSS_URL, files:[...rows.values()].sort((a,b)=>a.key.localeCompare(b.key)),
        license:{name:'SIL Open Font License 1.1', url:LICENSE_URL, bytes:license.length, sha256:sha(license)},
        plugin_sha256:sha(fs.readFileSync(path.join(ROOT,'node_modules/laravel-vite-plugin/dist/index.js'))),
        vite_config_sha256:sha(fs.readFileSync(path.join(ROOT,'vite.config.js')))};
    verify(manifest); write('font-cache-manifest.json', manifest);
    console.log('Prepared and verified', manifest.files.length, 'font cache files');
}
function offline() {
    const manifest = JSON.parse(fs.readFileSync(path.join(REPORT, 'font-cache-manifest.json')));
    verify(manifest);
    const env = {...process.env, NODE_OPTIONS:`${process.env.NODE_OPTIONS || ''} --import=${path.join(HERE,'font-fetch-guard.mjs')}`};
    const execute = name => {
        const output = fs.openSync(path.join(REPORT, name + '.log'), 'w');
        let result;
        try { result = spawnSync('npm', ['run','build'], {cwd:ROOT, env, stdio:['ignore',output,output], timeout:300000}); }
        finally { fs.closeSync(output); }
        return result;
    };
    const held = CACHE + '.held-' + crypto.randomUUID();
    let negative;
    fs.renameSync(CACHE, held);
    try { negative = execute('font-cache-missing-negative'); }
    finally {
        // Only the newly created empty disposable cache is removed; original bytes are restored.
        if (fs.existsSync(CACHE)) { assert.equal(fs.readdirSync(CACHE).length, 0); fs.rmdirSync(CACHE); }
        fs.renameSync(held, CACHE);
    }
    const negativeLog = fs.readFileSync(path.join(REPORT,'font-cache-missing-negative.log'),'utf8');
    assert.ok(!negative.error && negative.status !== null && negative.status !== 0, 'Missing-cache build must fail');
    assert.ok(negativeLog.includes('RELEASE_OFFLINE_FETCH_BLOCKED ' + CSS_URL), 'Negative failed for unrelated reason');
    verify(manifest);
    const positive = execute('font-cache-present-offline');
    verify(manifest);
    write('font-cache-offline.json', {missing_cache_exit:negative.status, present_cache_exit:positive.status,
        fetch_blocked:true, cache_bytes_unchanged:true, files:manifest.files, build_error:positive.error?.message || null});
    assert.ok(!positive.error && positive.status === 0, 'Offline build with verified cache failed');
    console.log('PASS: missing-cache negative and complete offline build; cached bytes unchanged');
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    assert.equal(process.env.RELEASE_DISPOSABLE_TESTS, '1'); fs.mkdirSync(REPORT,{recursive:true});
    if (process.argv[2] === 'setup') await setup();
    else if (process.argv[2] === 'offline') offline();
    else throw new Error('Expected setup or offline');
}
