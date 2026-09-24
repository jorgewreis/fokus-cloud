import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const canaries = Object.values(JSON.parse(readFileSync('tests/fixtures/security-log-canaries.json', 'utf8')));
const roots = [
    'storage/logs',
    'playwright-report',
    'test-results',
    process.env.RUNNER_TEMP ? join(process.env.RUNNER_TEMP, 'security-tests.log') : '',
    process.env.RUNNER_TEMP ? join(process.env.RUNNER_TEMP, 'security-browser-tests.log') : '',
    process.env.RUNNER_TEMP ? join(process.env.RUNNER_TEMP, 'security-app.log') : '',
    process.env.GITHUB_STEP_SUMMARY ?? '',
].filter(Boolean);

function filesUnder(path) {
    try {
        const info = statSync(path);
        if (info.isFile()) return [path];
        if (! info.isDirectory()) return [];
        return readdirSync(path).flatMap((name) => filesUnder(join(path, name)));
    } catch {
        return [];
    }
}

const files = [...new Set(roots.flatMap(filesUnder))];
let found = false;
for (const path of files) {
    const content = readFileSync(path, 'utf8');
    if (canaries.some((canary) => content.includes(canary))) {
        found = true;
        break;
    }
}

if (found) {
    console.error('Security canary scan failed: raw synthetic markers were found in logs or test artifacts.');
    process.exit(1);
}

console.log(`Security canary scan passed (${files.length} log/report files inspected).`);
