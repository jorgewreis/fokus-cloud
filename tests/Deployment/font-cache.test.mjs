import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { CSS_URL, LICENSE_URL, cacheKey, allowedFontUrl, sha } from './font-cache.mjs';

test('exact reviewed CSS is allowed, changed family/subset/weights are not', () => {
    assert.ok(allowedFontUrl(CSS_URL));
    for (const url of [CSS_URL.replace('Instrument+Sans','Roboto'), CSS_URL+'&extra=1', CSS_URL.replace('latin','cyrillic')]) assert.equal(allowedFontUrl(url),false);
});
test('only configured Bunny font files and formats are allowed', () => {
    for (const weight of [400,500,600]) for (const format of ['woff','woff2']) assert.ok(allowedFontUrl(`https://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-${weight}-normal.${format}`));
    for (const url of ['http://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-400-normal.woff2','https://fonts.bunny.net.evil.test/instrument-sans/files/instrument-sans-latin-400-normal.woff2','https://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-700-normal.woff2','https://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-400-normal.woff2?secret=x']) assert.equal(allowedFontUrl(url),false);
});
test('cache keys match plugin text versus binary convention', () => {
    assert.equal(cacheKey(CSS_URL,true),sha(CSS_URL+':text').slice(0,16));
    assert.equal(cacheKey(CSS_URL),sha(CSS_URL).slice(0,16));
    assert.notEqual(cacheKey(CSS_URL),cacheKey(CSS_URL,true));
});
test('license is explicit and cannot be fetched as a font', () => {
    assert.equal(LICENSE_URL,'https://raw.githubusercontent.com/google/fonts/main/ofl/instrumentsans/OFL.txt');
    assert.equal(allowedFontUrl(LICENSE_URL),false);
});
test('offline preload blocks fetch before any network request', () => {
    const guard=new URL('./font-fetch-guard.mjs',import.meta.url).href;
    const result=spawnSync(process.execPath,['--import',guard,'--input-type=module','-e',`await fetch(${JSON.stringify(CSS_URL)})`],{encoding:'utf8'});
    assert.notEqual(result.status,0); assert.ok(result.stderr.includes('RELEASE_OFFLINE_FETCH_BLOCKED '+CSS_URL));
});
