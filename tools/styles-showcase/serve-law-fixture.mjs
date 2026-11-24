// Loopback-only, read-only server for the REI-54 fixture; no project-wide file serving.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';

const files = new Map([
  ['/', [new URL('./fixtures/law-pagination.html', import.meta.url), 'text/html; charset=utf-8']],
  ['/cloud-datatable.html', [new URL('./fixtures/cloud-datatable.html', import.meta.url), 'text/html; charset=utf-8']],
  ['/law-select.html', [new URL('./fixtures/law-select.html', import.meta.url), 'text/html; charset=utf-8']],
  ['/law-record-ui.js', [new URL('../../public/portal/assets/law-record-ui.js', import.meta.url), 'text/javascript; charset=utf-8']],
  ['/law-select.js', [new URL('../../public/portal/assets/fokus-law-select.js', import.meta.url), 'text/javascript; charset=utf-8']],
  ['/fokus-3.0.0.min.css', [new URL('../../public/styles/assets/fokus-3.0.0.min.css', import.meta.url), 'text/css; charset=utf-8']],
  ['/fokus-3.0.0.min.js', [new URL('../../public/styles/assets/fokus-3.0.0.min.js', import.meta.url), 'text/javascript; charset=utf-8']],
]);
createServer(async (request, response) => {
  const entry = files.get(new URL(request.url, 'http://localhost').pathname);
  if (request.method !== 'GET' || !entry) { response.writeHead(404).end(); return; }
  try { const bytes = await readFile(entry[0]); response.writeHead(200, { 'Content-Type': entry[1], 'Cache-Control': 'no-store' }).end(bytes); }
  catch { response.writeHead(500).end(); }
}).listen(8765, '127.0.0.1', () => console.log('Fixture: http://127.0.0.1:8765/'));
