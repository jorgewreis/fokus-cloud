import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const mockupRoot = resolve(projectRoot, 'mockups/fokus-law');
const publicRoot = resolve(projectRoot, 'public');
const backofficeIconsRoot = resolve(projectRoot, 'public/backoffice/assets/icons');
const googleFontsRoot = resolve(projectRoot, 'public/assets/fonts/google');
const mountPath = '/mockups/fokus-law';
const contentTypes = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg'
};

const server = createServer(async (request, response) => {
  if (request.method !== 'GET' && request.method !== 'HEAD') {
    response.writeHead(405, { Allow: 'GET, HEAD', 'X-Robots-Tag': 'noindex, nofollow' }).end('Method not allowed');
    return;
  }

  let pathname;
  try {
    pathname = decodeURIComponent(new URL(request.url ?? '/', 'http://127.0.0.1').pathname);
  } catch {
    response.writeHead(400).end('Invalid URL');
    return;
  }

  if (pathname === '/favicon.ico') {
    response.writeHead(204, { 'X-Robots-Tag': 'noindex, nofollow' }).end();
    return;
  }

  const isMockup = pathname === mountPath || pathname.startsWith(`${mountPath}/`);
  const isAsset = pathname.startsWith('/assets/');
  const isGoogleFont = pathname.startsWith('/public/assets/fonts/google/');
  const iconPrefix = pathname.startsWith('/public/backoffice/assets/icons/')
    ? '/public/backoffice/assets/icons'
    : '/backoffice/assets/icons';
  const isBackofficeIcon = pathname.startsWith(`${iconPrefix}/`);
  if (!isMockup && !isAsset && !isBackofficeIcon && !isGoogleFont) {
    response.writeHead(404, { 'X-Robots-Tag': 'noindex, nofollow' }).end('Not found');
    return;
  }
  const root = isMockup ? mockupRoot : isBackofficeIcon ? backofficeIconsRoot : isGoogleFont ? googleFontsRoot : publicRoot;
  let relative = isMockup ? pathname.slice(mountPath.length) : isBackofficeIcon ? pathname.slice(iconPrefix.length) : isGoogleFont ? pathname.slice('/public/assets/fonts/google'.length) : pathname;
  if (!relative || relative === '/' || relative.endsWith('/')) relative = `${relative}index.html`;
  let filePath = resolve(root, `.${relative}`);
  if (filePath !== root && !filePath.startsWith(`${root}${sep}`)) {
    response.writeHead(403, { 'X-Robots-Tag': 'noindex, nofollow' }).end('Forbidden');
    return;
  }

  try {
    const content = await readFile(filePath);
    response.writeHead(200, {
      'Cache-Control': 'no-store',
      'Content-Type': contentTypes[extname(filePath).toLowerCase()] ?? 'application/octet-stream',
      'X-Content-Type-Options': 'nosniff',
      'X-Robots-Tag': 'noindex, nofollow'
    });
    response.end(request.method === 'HEAD' ? undefined : content);
  } catch {
    response.writeHead(404, { 'X-Robots-Tag': 'noindex, nofollow' }).end('Not found');
  }
});

server.listen(8130, '127.0.0.1', () => {
  console.log('Fokus Law mockups: http://127.0.0.1:8130/mockups/fokus-law/');
});
